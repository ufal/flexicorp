/**
 * TEITOK action `custom_layers` (custom_layers.php): the interface, an Alpine component.
 * Attributes (each with its values) and tasks (a query, the attributes to classify its lines with,
 * optionally a sample) are kept in the user's Users/<user>/custom_layers.xml; values are stored per
 * item (document + token ids of the labelled unit: the whole match, or one named query token).
 * Hits come from flexicorp's search request (index.php?action=flexicorp, run=query).
 */
(function () {
	const MAX_LINES = 2000;   // lines loaded per task (a sample is drawn from these)
	const PAGE = 200;   // flexicorp/FQS return at most 200 hits per page

	/** Deterministic pseudo-random numbers (mulberry32): the same sample for the same seed. */
	function rng(seed) {
		let a = (Number(seed) || 1) >>> 0;
		return () => {
			a = (a + 0x6D2B79F5) >>> 0;
			let t = a;
			t = Math.imul(t ^ (t >>> 15), t | 1);
			t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
			return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
		};
	}

	/** n of the items, chosen with the seed, in their original order. */
	function sample(items, n, seed) {
		if (!n || n >= items.length) return items.slice();
		const r = rng(seed);
		const idx = items.map((_, i) => i);
		for (let i = idx.length - 1; i > 0; i -= 1) {
			const j = Math.floor(r() * (i + 1));
			[idx[i], idx[j]] = [idx[j], idx[i]];
		}
		return idx.slice(0, n).sort((a, b) => a - b).map((i) => items[i]);
	}

	/** Token ids of the unit in a flexicorp hit: the match, or the tokens of one named query token. */
	function unitTokens(hit, unit) {
		if (unit && Array.isArray(hit.groups) && Array.isArray(hit.tokens)) {
			const g = hit.groups.find((x) => x && x.name === unit);
			if (g) {
				const ids = hit.tokens.filter((t) => t && t.group === g.index && t.id).map((t) => t.id);
				if (ids.length) return ids;
			}
		}
		const hm = hit.highlight_map || {};
		if (Array.isArray(hm.match) && hm.match.length) return hm.match;
		return (hit.tokens || []).map((t) => t && t.id).filter(Boolean);
	}

	/** Names of the query's tokens (`v:[…]`), for "Label on". */
	function queryTokenNames(query) {
		const out = [];
		const re = /(?:^|[\s;(>])([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\[/g;
		let m;
		while ((m = re.exec(String(query || '')))) if (!out.includes(m[1])) out.push(m[1]);
		return out;
	}

	/**
	 * The query with a condition added to one token: the named unit's `[…]` (else the first token):
	 * v:[lemma="feel"] + child [deprel="obj"] → v:[lemma="feel" & child [deprel="obj"]]. Quotes and
	 * nested brackets are skipped. '' when no token is found.
	 */
	function addCondition(query, unit, cond) {
		const q = String(query || '');
		let start = -1;
		if (unit) {
			const m = new RegExp(`(?:^|[\\s;(>])${unit}\\s*:\\s*\\[`).exec(q);
			if (m) start = m.index + m[0].length - 1;
		}
		if (start < 0) {
			let inQ = false;
			for (let i = 0; i < q.length; i += 1) {
				if (q[i] === '\\') { i += 1; continue; }
				if (q[i] === '"') inQ = !inQ;
				else if (!inQ && q[i] === '[') { start = i; break; }
			}
		}
		if (start < 0) return '';
		let depth = 0, inQ = false;
		for (let i = start; i < q.length; i += 1) {
			const c = q[i];
			if (c === '\\') { i += 1; continue; }
			if (c === '"') { inQ = !inQ; continue; }
			if (inQ) continue;
			if (c === '[') depth += 1;
			else if (c === ']') {
				depth -= 1;
				if (depth === 0) {
					const inner = q.slice(start + 1, i).trim();
					return q.slice(0, start + 1) + (inner ? `${inner} & ${cond}` : cond) + q.slice(i);
				}
			}
		}
		return '';
	}

	const itemKey = (doc, corresp) => `${doc}\u0001${corresp}`;

	window.ttCustomLayersApp = function (boot) {
		return {
			boot: boot || {},
			attributes: [],
			tasks: [],
			docs: {},
			shared: [],
			view: 'work',        // work | attribute | task
			attrEd: null,
			taskEd: null,
			taskId: '',
			rows: [],
			totalHits: 0,
			suggestions: {},     // item key → attr → value
			others: {},          // item key → [{user, attr, value}]
			cursor: 0,
			activeAttr: 0,
			show: 'all',
			busy: false,
			loadingHits: false,
			error: '',

			async init() {
				window.addEventListener('keydown', (e) => this.onKey(e));
				await this.loadState();
				if (this.boot.task && this.tasks.some((t) => t.id === this.boot.task)) await this.openTask(this.boot.task);
				else if (this.boot.query && this.boot.user) this.newTask(this.boot.query);
			},

			async api(op, body, write) {
				const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
				if (write) headers['X-Custom-Layers-Token'] = this.boot.token || '';
				const r = await fetch(`index.php?action=custom_layers&op=${encodeURIComponent(op)}`, { method: 'POST', headers, body: JSON.stringify(body || {}) });
				let j = null;
				try { j = await r.json(); } catch (_) { j = { ok: false, error: `the server answered ${r.status}` }; }
				if (!j.ok) throw new Error(j.error || 'request failed');
				return j;
			},

			applyState(j) {
				this.attributes = j.attributes || [];
				this.tasks = j.tasks || [];
				this.docs = j.docs || {};
				this.shared = j.shared || [];
			},

			async loadState() {
				if (!this.boot.user) return;
				try { this.applyState(await this.api('state')); } catch (e) { this.error = e.message; }
			},

			get task() {
				return this.tasks.find((t) => t.id === this.taskId) || null;
			},
			attribute(key) {
				return this.attributes.find((a) => a.key === key) || null;
			},
			/** The task's attributes, in its order. */
			taskAttributes() {
				const t = this.task;
				return t ? t.attributes.map((k) => this.attribute(k)).filter(Boolean) : [];
			},
			sharedToAdd() {
				return this.shared.filter((s) => !this.attributes.some((a) => a.key === s.key));
			},

			// ── attributes ──
			newAttribute() {
				this.attrEd = { create: true, key: '', display: '', description: '', shared: true,
					values: [{ key: '', display: '', description: '' }, { key: '', display: '', description: '' }],
					prefill: { if: '', then: '', else: '' } };
				this.view = 'attribute';
			},
			editAttribute(a) {
				this.attrEd = { create: false, key: a.key, display: a.display, description: a.description, shared: !!a.shared,
					values: a.values.map((v) => ({ ...v })), prefill: { if: '', then: '', else: '', ...(a.prefill || {}) } };
				this.view = 'attribute';
			},
			async saveAttribute() {
				// a value's key: kept once given, else from its name (as custom_layers_store.php does)
				const keyOf = (v) => (v.key || String(v.display || '').trim()).replace(/[\s"<>&]+/g, '_').slice(0, 40);
				const values = this.attrEd.values.filter((v) => String(v.display || v.key || '').trim()).map((v) => ({ ...v, key: keyOf(v) }));
				const toKey = (x) => { const v = this.attrEd.values.find((y) => y.key === x || y.display === x); return v ? keyOf(v) : x; };
				const p = this.attrEd.prefill || {};
				const prefill = { if: p.if || '', then: p.then ? toKey(p.then) : '', else: p.else ? toKey(p.else) : '' };
				await this.write('save_attribute', { ...this.attrEd, values, prefill });
				if (!this.error) this.view = this.taskEd ? 'task' : 'work';
			},
			async addReference(s) {
				await this.write('add_reference', { owner: s.owner, key: s.key });
			},

			// ── tasks ──
			newTask(query) {
				this.taskEd = { create: true, id: '', display: '', query: query || '', unit: '', attributes: [], sample: null };
				this.view = 'task';
			},
			editTask() {
				const t = this.task;
				this.taskEd = { create: false, id: t.id, display: t.display, query: t.query, unit: t.unit, attributes: t.attributes.slice(), sample: t.sample || null };
				this.view = 'task';
			},
			async saveTask() {
				const ed = this.taskEd;
				await this.write('save_task', { ...ed, sample: ed.sample > 0 ? ed.sample : 0 });
				if (this.error) return;
				const id = ed.id || (this.tasks.find((t) => t.display === ed.display) || {}).id;
				this.taskEd = null;
				this.view = 'work';
				if (id) await this.openTask(id);
			},
			async write(op, body) {
				this.busy = true;
				this.error = '';
				try { this.applyState(await this.api(op, body, true)); } catch (e) { this.error = e.message; } finally { this.busy = false; }
			},

			async openTask(id) {
				this.taskId = id;
				this.view = 'work';
				this.rows = [];
				this.suggestions = {};
				this.others = {};
				if (!this.task) return;
				await this.loadHits();
				await Promise.all([this.loadSuggestions(), this.loadOthers()]);
			},

			/** All hits of a query (up to MAX_LINES), through flexicorp's search request. */
			async fetchHits(query) {
				const all = [];
				let total = null;   // known once the engine's count is in (the first page may come before it)
				for (let guard = 0; all.length < MAX_LINES && guard < 50; guard += 1) {
					const start = all.length;
					const fd = new FormData();
					const set = { action: 'flexicorp', run: 'query', active_tab: 'search', query, start, kwic_limit: PAGE,
						view_mode: 'kwic', context_scope: 'window', window: 8, context_format: 'text', record: 0, ajax: 1 };
					for (const k of Object.keys(set)) fd.set(k, String(set[k]));
					const r = await fetch('index.php?action=flexicorp', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
					const j = await r.json();
					const s = j.search || {};
					const err = s.response && (s.response.error || (Array.isArray(s.response.errors) && s.response.errors[0]));
					if (err) throw new Error(String(err).split('\n')[0]);
					const page = Array.isArray(s.hits) ? s.hits : [];
					const res = (s.response && s.response.result) || {};
					const t = [res.total, res.page && res.page.total, res.job && res.job.total].map(Number).find((x) => Number.isFinite(x) && x > 0);
					if (t) total = t;
					all.push(...page);
					if (!page.length || (total !== null && all.length >= total)) break;
				}
				return { hits: all, total: total !== null ? total : all.length };
			},

			async loadHits() {
				const t = this.task;
				this.loadingHits = true;
				this.error = '';
				try {
					const { hits, total } = await this.fetchHits(t.query);
					this.totalHits = total;
					const chosen = t.sample ? sample(hits, t.sample, t.seed) : hits;
					this.rows = chosen.map((h, idx) => {
						const ids = unitTokens(h, t.unit);
						const corresp = `#${ids.join(' #')}`;
						const doc = String(h.doc_id || '');
						const ctx = h.context || {};
						return { idx, doc, corresp, tokens: ids.join(' '), key: itemKey(doc, corresp), left: ctx.left || '', match: ctx.match || '', right: ctx.right || '' };
					});
				} catch (e) {
					this.error = `Loading the lines failed: ${e.message}`;
				} finally {
					this.loadingHits = false;
				}
				this.cursor = 0;
				this.activeAttr = 0;
			},

			/** Pre-fill: for an attribute with a rule, the lines matching the condition get `then`, the others `else`. */
			async loadSuggestions() {
				const t = this.task;
				const out = {};
				for (const a of this.taskAttributes()) {
					const p = a.prefill;
					if (!p || !p.if || (!p.then && !p.else)) continue;
					const q = addCondition(t.query, t.unit, p.if);
					if (!q) continue;
					try {
						const { hits } = await this.fetchHits(q);
						const yes = new Set(hits.map((h) => itemKey(String(h.doc_id || ''), `#${unitTokens(h, t.unit).join(' #')}`)));
						for (const r of this.rows) {
							const v = yes.has(r.key) ? p.then : p.else;
							if (v) (out[r.key] = out[r.key] || {})[a.key] = v;
						}
					} catch (e) {
						this.error = `Pre-filling ${a.display} failed: ${e.message}`;
					}
				}
				this.suggestions = out;
			},

			async loadOthers() {
				try {
					const o = (await this.api('others', { task: this.taskId })).others || {};
					const out = {};
					for (const doc of Object.keys(o)) for (const c of Object.keys(o[doc])) out[itemKey(doc, c)] = o[doc][c];
					this.others = out;
				} catch (_) { this.others = {}; }
			},

			value(row, key) {
				const d = this.docs[row.doc];
				return (d && d[row.corresp] && d[row.corresp][key]) || '';
			},
			suggestion(row, key) {
				const s = this.suggestions[row.key];
				return (s && s[key]) || '';
			},
			othersText(row) {
				const o = this.others[row.key];
				if (!o || !o.length) return '';
				const names = Object.fromEntries(this.taskAttributes().map((a) => [a.key, a.display]));
				return o.map((x) => `${x.user}: ${names[x.attr] || x.attr} ${x.value}`).join(' · ');
			},
			complete(row) {
				return this.taskAttributes().every((a) => this.value(row, a.key));
			},

			async setValues(row, values) {
				const prev = { ...((this.docs[row.doc] || {})[row.corresp] || {}) };
				const doc = { ...(this.docs[row.doc] || {}) };
				const span = { ...prev };
				for (const [k, v] of Object.entries(values)) { if (v) span[k] = v; else delete span[k]; }
				doc[row.corresp] = span;
				this.docs = { ...this.docs, [row.doc]: doc };
				try {
					await this.api('label', { doc: row.doc, tokens: row.tokens, values }, true);
				} catch (e) {
					this.docs = { ...this.docs, [row.doc]: { ...doc, [row.corresp]: prev } };
					this.error = `Not saved: ${e.message}`;
				}
			},
			setValue(row, key, val) {
				this.cursor = row.idx;
				return this.setValues(row, { [key]: this.value(row, key) === val ? '' : val });
			},
			acceptSuggestions(row) {
				const vals = {};
				for (const a of this.taskAttributes()) {
					const s = this.suggestion(row, a.key);
					if (s && !this.value(row, a.key)) vals[a.key] = s;
				}
				return Object.keys(vals).length ? this.setValues(row, vals) : null;
			},

			visibleRows() {
				if (this.show === 'open') return this.rows.filter((r) => !this.complete(r));
				if (this.show === 'done') return this.rows.filter((r) => this.complete(r));
				return this.rows;
			},
			statusLine() {
				const done = this.rows.filter((r) => this.complete(r)).length;
				const t = this.task;
				const of = t && t.sample
					? `a sample of ${this.rows.length} from ${Math.min(this.totalHits, MAX_LINES).toLocaleString()} lines`
					: `${this.rows.length.toLocaleString()} lines${this.totalHits > this.rows.length ? ` (the first of ${this.totalHits.toLocaleString()})` : ''}`;
				return `${done} of ${of} done`;
			},

			onKey(e) {
				if (this.view !== 'work' || !this.rows.length) return;
				const tag = (e.target && e.target.tagName) || '';
				if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.ctrlKey || e.metaKey || e.altKey) return;
				const attrs = this.taskAttributes();
				const vis = this.visibleRows();
				const pos = Math.max(0, vis.findIndex((r) => r.idx === this.cursor));
				const move = (d) => {
					const next = vis[Math.min(vis.length - 1, Math.max(0, pos + d))];
					if (!next) return;
					this.cursor = next.idx;
					this.activeAttr = 0;
					this.$nextTick(() => {
						const tr = document.querySelector('.tt-cl-current');
						if (tr && tr.scrollIntoView) tr.scrollIntoView({ block: 'nearest' });
					});
				};
				const row = vis[pos];
				if (e.key === 'ArrowDown' || e.key === 'j') { move(1); e.preventDefault(); return; }
				if (e.key === 'ArrowUp' || e.key === 'k') { move(-1); e.preventDefault(); return; }
				if (e.key === 'Tab' && attrs.length > 1) { this.activeAttr = (this.activeAttr + (e.shiftKey ? attrs.length - 1 : 1)) % attrs.length; e.preventDefault(); return; }
				if (!row) return;
				if (e.key === 'Enter') { this.acceptSuggestions(row); move(1); e.preventDefault(); return; }
				const a = attrs[this.activeAttr];
				if (!a) return;
				if (e.key === '0') { this.setValues(row, { [a.key]: '' }); e.preventDefault(); return; }
				if (/^[1-9]$/.test(e.key)) {
					const v = a.values[Number(e.key) - 1];
					if (!v) return;
					e.preventDefault();
					this.setValues(row, { [a.key]: v.key });
					// next attribute of this line, or the next line after the last one
					if (this.activeAttr < attrs.length - 1) this.activeAttr += 1;
					else move(1);
				}
			},

			queryTokenNames,
			exportUrl() {
				return this.task ? `index.php?action=custom_layers&op=export&task=${encodeURIComponent(this.task.id)}` : '#';
			},
		};
	};

	// for tests (node)
	window.ttCustomLayersLib = { sample, unitTokens, queryTokenNames, addCondition, rng };
})();
