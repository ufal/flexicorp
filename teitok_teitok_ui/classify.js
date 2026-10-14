/**
 * TEITOK action `classify` (classify.php): the interface. An Alpine component; layers are stored
 * by classify_store.php, hits come from flexicorp's search request (index.php?action=flexicorp,
 * run=query), so flexicorp's search settings and engines apply. A line's key is its document and
 * the token ids of the labelled unit (the whole match, or one named query token).
 */
(function () {
	const MAX_LINES = 2000;   // lines loaded per layer (a sample is drawn from these)
	const PAGE = 250;

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
		const re = /(?:^|[\s;(])([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\[/g;
		let m;
		while ((m = re.exec(String(query || '')))) if (!out.includes(m[1])) out.push(m[1]);
		return out;
	}

	window.ttClassifyApp = function (boot) {
		return {
			boot: boot || {},
			layers: [],
			layerId: '',
			layer: null,
			editor: null,
			hits: [],
			rows: [],
			mine: {},       // item key → this user's label
			others: {},     // item key → [labels by others]
			show: 'all',
			cursor: 0,
			busy: false,
			loadingHits: false,
			error: '',
			totalHits: 0,

			async init() {
				window.addEventListener('keydown', (e) => this.onKey(e));
				await this.loadLayers();
				if (this.boot.layer) await this.openLayer(this.boot.layer);
				else if (this.boot.query && this.boot.user) this.newLayer(this.boot.query);
			},

			async api(op, body, write) {
				const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
				if (write) headers['X-Classify-Token'] = this.boot.token || '';
				const r = await fetch(`index.php?action=classify&op=${encodeURIComponent(op)}`, { method: 'POST', headers, body: JSON.stringify(body || {}) });
				let j = null;
				try { j = await r.json(); } catch (_) { j = { ok: false, error: `the server answered ${r.status}` }; }
				if (!j.ok) throw new Error(j.error || 'request failed');
				return j;
			},

			async loadLayers() {
				try {
					this.layers = (await this.api('list')).layers || [];
				} catch (e) {
					this.error = e.message;
				}
			},

			async openLayer(id) {
				this.error = '';
				this.editor = null;
				if (!id) { this.layer = null; this.layerId = ''; return; }
				try {
					this.layer = (await this.api('get', { layer: id })).layer;
					this.layerId = id;
					this.indexItems();
					this.hits = [];
					this.rows = [];
					if (this.layer.query) await this.loadHits();
				} catch (e) {
					this.error = e.message;
				}
			},

			indexItems() {
				this.mine = {};
				this.others = {};
				for (const it of this.layer.items || []) {
					const key = `${it.doc}\u0001${it.tokens}`;
					if (it.by === this.boot.user) this.mine[key] = it.label;
					else (this.others[key] = this.others[key] || []).push(it.label);
				}
			},

			newLayer(query) {
				this.editor = { create: true, name: '', description: '', query: query || (this.layer && this.layer.query) || this.boot.query || '',
					unit: '', sampleSize: null, shared: true, labels: [{ code: '', name: '', description: '' }, { code: '', name: '', description: '' }] };
			},

			editLayer() {
				const l = this.layer;
				this.editor = { create: false, id: l.id, name: l.name, description: l.description, query: l.query, unit: l.unit,
					sampleSize: l.sample ? l.sample.size : null, shared: !!l.shared, labels: l.labels.map((x) => ({ ...x })) };
			},

			async saveLayer() {
				const ed = this.editor;
				this.busy = true;
				this.error = '';
				try {
					const old = this.layer && !ed.create ? this.layer.sample : null;
					const seed = old && old.seed ? old.seed : Math.floor(Math.random() * 1e9) + 1;
					const labels = ed.labels.filter((l) => String(l.code || '').trim())
						.map((l, i) => ({ ...l, key: i < 9 ? String(i + 1) : '' }));
					const body = { create: ed.create, id: ed.id || '', name: ed.name, description: ed.description, query: ed.query,
						unit: ed.unit, shared: ed.shared, labels, sample: ed.sampleSize > 0 ? { size: ed.sampleSize, seed } : null };
					const layer = (await this.api('save', body, true)).layer;
					await this.loadLayers();
					await this.openLayer(layer.id);
				} catch (e) {
					this.error = e.message;
				} finally {
					this.busy = false;
				}
			},

			/** The layer's lines: its query's hits (up to MAX_LINES), or its sample of them. */
			async loadHits() {
				const l = this.layer;
				if (!l || !l.query) return;
				this.loadingHits = true;
				this.error = '';
				const all = [];
				try {
					for (let start = 0; start < MAX_LINES; start += PAGE) {
						const fd = new FormData();
						const set = { action: 'flexicorp', run: 'query', active_tab: 'search', query: l.query, start, kwic_limit: PAGE,
							view_mode: 'kwic', context_scope: 'window', window: 8, context_format: 'text', record: 0, ajax: 1 };
						for (const k of Object.keys(set)) fd.set(k, String(set[k]));
						const r = await fetch('index.php?action=flexicorp', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
						const j = await r.json();
						const s = j.search || {};
						const err = s.response && (s.response.error || (Array.isArray(s.response.errors) && s.response.errors[0]));
						if (err) throw new Error(String(err).split('\n')[0]);
						const page = Array.isArray(s.hits) ? s.hits : [];
						const res = s.response && s.response.result;
						this.totalHits = res && Number.isFinite(Number(res.total)) ? Number(res.total) : all.length + page.length;
						all.push(...page);
						if (page.length < PAGE || all.length >= this.totalHits) break;
					}
				} catch (e) {
					this.error = `Loading the lines failed: ${e.message}`;
				} finally {
					this.loadingHits = false;
				}
				this.hits = l.sample && l.sample.size ? sample(all, l.sample.size, l.sample.seed) : all;
				this.rows = this.hits.map((h, idx) => {
					const ctx = h.context || {};
					const tokens = unitTokens(h, l.unit).join(' ');
					return { idx, doc: String(h.doc_id || ''), tokens, key: `${h.doc_id}\u0001${tokens}`,
						left: ctx.left || '', match: ctx.match || '', right: ctx.right || '' };
				});
				this.cursor = 0;
			},

			visibleRows() {
				if (this.show === 'open') return this.rows.filter((r) => !this.mine[r.key]);
				if (this.show.startsWith('l:')) {
					const code = this.show.slice(2);
					return this.rows.filter((r) => this.mine[r.key] === code);
				}
				return this.rows;
			},

			myLabel(row) {
				return this.mine[row.key] || '';
			},

			otherLabels(row) {
				const o = this.others[row.key];
				return o && o.length ? o.join(' ') : '';
			},

			statusLine() {
				const done = this.rows.filter((r) => this.mine[r.key]).length;
				const of = this.layer.sample && this.layer.sample.size
					? `a sample of ${this.rows.length} from ${Math.min(this.totalHits, MAX_LINES).toLocaleString()} lines`
					: `${this.rows.length.toLocaleString()} lines${this.totalHits > this.rows.length ? ` (the first of ${this.totalHits.toLocaleString()})` : ''}`;
				return `${done} of ${of} labelled by you`;
			},

			async setLabel(row, code) {
				const prev = this.mine[row.key] || '';
				if (code) this.mine[row.key] = code; else delete this.mine[row.key];
				this.mine = { ...this.mine };
				try {
					await this.api('label', { layer: this.layer.id, doc: row.doc, tokens: row.tokens, label: code }, true);
				} catch (e) {
					if (prev) this.mine[row.key] = prev; else delete this.mine[row.key];
					this.mine = { ...this.mine };
					this.error = `Not saved: ${e.message}`;
				}
			},

			onKey(e) {
				if (!this.layer || this.editor || !this.rows.length) return;
				const tag = (e.target && e.target.tagName) || '';
				if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.ctrlKey || e.metaKey || e.altKey) return;
				const vis = this.visibleRows();
				const pos = Math.max(0, vis.findIndex((r) => r.idx === this.cursor));
				const move = (d) => {
					const next = vis[Math.min(vis.length - 1, Math.max(0, pos + d))];
					if (next) {
						this.cursor = next.idx;
						this.$nextTick(() => {
							const tr = document.querySelector('.tt-classify-current');
							if (tr && tr.scrollIntoView) tr.scrollIntoView({ block: 'nearest' });
						});
					}
				};
				if (e.key === 'ArrowDown' || e.key === 'j') { move(1); e.preventDefault(); return; }
				if (e.key === 'ArrowUp' || e.key === 'k') { move(-1); e.preventDefault(); return; }
				const row = vis[pos];
				if (!row) return;
				if (e.key === '0') { this.setLabel(row, ''); e.preventDefault(); return; }
				if (/^[1-9]$/.test(e.key)) {
					const l = this.layer.labels[Number(e.key) - 1];
					if (!l) return;
					this.setLabel(row, l.code);
					e.preventDefault();
					move(1);   // the next line (also when a filter hides the one just labelled)
				}
			},

			queryTokenNames,

			exportUrl() {
				return this.layer ? `index.php?action=classify&op=export&layer=${encodeURIComponent(this.layer.id)}` : '#';
			},
		};
	};

	// for tests (node)
	window.ttClassifyLib = { sample, unitTokens, queryTokenNames, rng };
})();
