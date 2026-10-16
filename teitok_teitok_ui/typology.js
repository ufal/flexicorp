/**
 * typology.php's page (Alpine component ttTypologyApp): loads the features (typology_engine.php counts
 * each once and keeps it), builds the languages' profiles and distances (typology_math.js), and shows
 * them (typology_views.js): dendrogram, similarity space, similarity matrix, feature table. A language
 * clicked: its nearest languages and what sets it apart. The state goes into share links (?s=…).
 */
/* global Alpine */
(function () {
	'use strict';
	const M = () => window.ttTypologyMath;
	const V = () => window.ttTypologyViews;
	const PANDO = { b: 'pando', e: 'pando', l: 'pando-cql', f: 'pando' };
	const b64 = (s) => btoa(unescape(encodeURIComponent(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	const unb64 = (s) => decodeURIComponent(escape(atob(s.replace(/-/g, '+').replace(/_/g, '/'))));
	const names = (() => { try { return new Intl.DisplayNames(['en'], { type: 'language' }); } catch (_) { return null; } })();
	const langName = (code) => {
		try { const n = names && names.of(code); if (n && n.toLowerCase() !== String(code).toLowerCase()) return n; } catch (_) { /* not a code */ }
		return code;
	};
	const GROUPS = { order: 'Word order', clause: 'Clauses', morphology: 'Morphology' };

	window.ttTypologyApp = function (boot) {
		// counts and the computed model stay outside Alpine's reactive state (clustering and MDS loop over
		// them many times); `version` tells the page when they changed
		const data = {};       // featureId → lang → value → count
		let model = null;      // { ids, prof, langs, dist, tree, order, groups, coords }
		const app = {
			boot,
			loading: true,
			error: '',
			cannotCount: '',
			settings: { lang: 'text_langcode', min_n: 30 },
			admin: !!boot.admin,
			features: {},
			version: 0,
			status: {},          // featureId → '' | 'counting' | 'error: …' | 'done'
			selected: [],
			minN: 30,
			minFeatures: 8,
			minFromShare: false,
			sortKey: '',        // the feature table's sort column ('' = the dendrogram's order)
			sortDesc: false,
			groupsK: 6,
			view: 'dendrogram',
			langFilter: '',
			focus: '',
			svg: '',
			copied: false,

			/** The server's state: features (with origin and program), attribute names, hidden ones. */
			applyState(r) {
				this.settings = r.settings || this.settings;
				this.cannotCount = r.cannot_count || '';
				this.features = r.features || {};
				this.attributes = r.attributes || {};
				this.corpusAttributes = r.corpus_attributes || {};
				this.myAttributes = r.my_attributes || {};
				this.hidden = r.hidden || [];
				this.names = r.names || {};
				this.selected = this.selected.filter((id) => this.features[id]);
			},
			attributes: {},
			corpusAttributes: {},
			myAttributes: {},
			hidden: [],
			names: {},          // language code → name (kept for the user or the corpus)
			namesOpen: false,
			namesEdit: {},
			originLabel(f) { return { corpus: 'corpus', mine: 'mine' }[f.origin] || ''; },

			/** The current model (re-read by the page when `version` changes). */
			m() { return this.version >= 0 ? model : null; },
			hasData(id) { return this.version >= 0 && !!data[id]; },

			async init() {
				const st = this.decodeShare(boot.s);
				try {
					const r = await this.post('state', {});
					this.applyState(r);
					this.minN = r.settings.min_n || 30;
					const ids = Object.keys(this.features);
					this.selected = st && Array.isArray(st.f) ? st.f.filter((id) => this.features[id]) : ids;
					if (st) {
						if (st.n) this.minN = Number(st.n);
						if (st.m) { this.minFeatures = Number(st.m); this.minFromShare = true; }
						if (st.k) this.groupsK = Number(st.k);
						if (st.v) this.view = String(st.v);
						if (st.l) this.langFilter = String(st.l);
						if (st.x) this.focus = String(st.x);
						if (st.o) { const o = String(st.o); this.sortDesc = o.startsWith('-'); this.sortKey = o.replace(/^-/, ''); }
					}
				} catch (e) {
					this.error = String(e.message || e);
					this.loading = false;
					return;
				}
				this.loading = false;
				await this.loadSelected();
				this.$watch('view', () => this.draw());
			},

			/** A request to the page's server side; `write`: with the page's token (keeping features). */
			async post(op, body, write) {
				const headers = { 'Content-Type': 'application/json' };
				if (write) headers['X-Typology-Token'] = boot.token || '';
				const res = await fetch(`index.php?action=typology&op=${encodeURIComponent(op)}`, {
					method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(body),
				});
				const j = await res.json();
				if (!j.ok) throw new Error(j.error || 'error');
				return j;
			},

			/**
			 * The selected features: everything already counted in one request, drawn once; then the ones
			 * still to count, one by one (each counted once on the server, then kept).
			 */
			async loadSelected(force) {
				const keptIds = this.selected.filter((id) => !data[id] && this.features[id] && this.features[id].kept);
				if (keptIds.length && !force) {
					try {
						const r = await this.post('kept', { ids: keptIds });
						for (const [id, k] of Object.entries(r.features || {})) {
							data[id] = k.counts || {};
							this.status[id] = k.missing ? 'n/a: ' + k.missing : 'done';
						}
					} catch (_) { /* each one on its own below */ }
					this.rebuild();
				}
				for (const id of this.selected) {
					if (data[id] && !force) continue;
					// this server cannot count: only what was counted before (said once, above the views)
					if (this.cannotCount && !(this.features[id] && this.features[id].kept)) continue;
					this.status[id] = 'counting';
					try {
						const r = await this.post(force ? 'recompute' : 'feature', { id });
						data[id] = r.counts || {};
						this.status[id] = r.missing ? 'n/a: ' + r.missing : 'done';
						this.features[id].kept = true;
						this.features[id].seconds = r.seconds;
					} catch (e) {
						const msg = String(e.message || e);
						this.status[id] = msg.startsWith('not annotated') ? 'n/a: ' + msg : 'error: ' + msg;
					}
					this.rebuild();
				}
				// without a share link's minimum: a share of the features this corpus has (8 of 19 on UD; fewer
				// where, as in NTREX, there are no morphological features)
				if (!this.minFromShare) {
					const have = this.selected.filter((x) => data[x] && Object.keys(data[x]).length).length;
					this.minFeatures = Math.max(3, Math.min(8, Math.ceil(have * 0.6)));
				}
				this.rebuild();
			},

			async recompute(id) {
				delete data[id];
				this.status[id] = 'counting';
				try {
					const r = await this.post('recompute', { id });
					data[id] = r.counts || {};
					this.status[id] = r.missing ? 'n/a: ' + r.missing : 'done';
				} catch (e) {
					this.status[id] = 'error: ' + (e.message || e);
				}
				this.rebuild();
			},

			featureGroups() {
				const out = {};
				for (const f of Object.values(this.features)) (out[f.group] = out[f.group] || []).push(f);
				return Object.entries(out).map(([g, fs]) => ({ key: g, label: GROUPS[g] || g, features: fs }));
			},
			isSelected(id) { return this.selected.includes(id); },
			toggle(id) {
				this.selected = this.isSelected(id) ? this.selected.filter((x) => x !== id) : this.selected.concat([id]);
				this.loadSelected();
			},
			countingNow() { return Object.entries(this.status).filter(([, s]) => s === 'counting').map(([id]) => this.features[id] && this.features[id].title); },

			/** The languages: those in the data with enough features, or the ones the filter names. */
			candidateLangs() {
				const all = new Set();
				// texts without a language value ('_', '') are not a language
				for (const id of this.selected) for (const l of Object.keys(data[id] || {})) if (l !== '_' && l.trim() !== '') all.add(l);
				const wanted = this.langFilter.split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean);
				return (wanted.length ? wanted.filter((l) => all.has(l)) : Array.from(all)).sort();
			},

			rebuild() {
				const ids = this.selected.filter((id) => data[id]);
				if (!ids.length) { model = null; this.version += 1; this.svg = ''; return; }
				const prof = M().profiles(this.features, data, this.candidateLangs(), ids, this.minN);
				const langs = Object.keys(prof).filter((l) => ids.filter((id) => prof[l][id] && prof[l][id].dims).length >= Math.min(this.minFeatures, ids.length));
				const dist = M().distances(prof, langs, ids, Math.max(1, Math.min(3, ids.length)));
				const tree = langs.length > 1 ? M().upgma(dist.D) : null;
				const order = tree ? M().leafOrder(tree) : langs.map((_, i) => i);
				const groups = tree ? M().cutTree(tree, Math.max(1, Math.min(this.groupsK, langs.length))) : {};
				const coords = this.view === 'space' && langs.length > 1 ? M().mds(dist.D) : null;
				model = { ids, prof, langs, dist, tree, order, groups, coords };
				this.version += 1;
				this.draw();
			},

			/** A language's label: a name kept for it, else the browser's name for the code, else the code. */
			label(code) {
				const kept = this.names && this.names[code];
				const n = kept || langName(code);
				return n === code ? code : `${n} (${code})`;
			},
			hasName(code) { return !!(this.names && this.names[code]) || langName(code) !== code; },

			// ── what the views say about themselves (on the page and in downloads) ──
			viewTitle() {
				const m = this.m();
				const corpus = (document.title || '').trim();
				return `Typological similarity of ${m ? m.langs.length : 0} languages${corpus ? ' — ' + corpus : ''}`;
			},
			featureLine() {
				const m = this.m();
				if (!m) return '';
				const t = m.ids.map((id) => (this.features[id] ? this.features[id].title + (this.features[id].wals ? ` (WALS ${this.features[id].wals})` : '') : id));
				return `${t.length} features: ` + (t.length > 8 ? t.slice(0, 8).join('; ') + `; and ${t.length - 8} more` : t.join('; '));
			},
			methodLine() {
				const base = 'Distance: per feature the difference of two languages\' values (shares, inventory sizes; 0–1), averaged over the features both have (0 = the same profile)';
				if (this.view === 'dendrogram') return `${base}; clustered with UPGMA (average linkage), ${this.groupsK} colour groups.`;
				if (this.view === 'space') return `${base}; placed by classical multidimensional scaling: closer = more similar, the axes have no unit.`;
				return `${base}; languages in the dendrogram's order.`;
			},
			caption() {
				return [this.viewTitle(), this.featureLine(), this.methodLine(),
					`At least ${this.minFeatures} features per language, ${this.minN} hits per feature. Counted from the corpus's annotation (typology lens, TEITOK).`];
			},

			draw() {
				const m = this.m();
				if (!m || m.langs.length < 2) { this.svg = ''; return; }
				this.svg = this.viewSvg(this.view, false);
			},

			/** A view as SVG: on the page, or ($file) standalone at a fixed width for downloads. */
			viewSvg(view, file) {
				const m = this.m();
				if (!m || m.langs.length < 2) return '';
				const opts = { labels: m.langs.map((l) => this.label(l)), codes: m.langs, groups: m.groups, width: 900,
					highlight: file ? '' : this.focus, caption: this.caption(), fixedWidth: !!file };
				if (view === 'dendrogram') return V().dendrogram(m.tree, Object.assign(opts, { axis: 'distance (mean difference over the shared features)' }));
				if (view === 'space') {
					if (!m.coords) m.coords = M().mds(m.dist.D);
					return V().space(m.coords, Object.assign(opts, { axis: 'MDS dimension 1 (no unit)', axis2: 'MDS dimension 2 (no unit)' }));
				}
				if (view === 'matrix') return V().matrix(m.dist, m.order, Object.assign(opts, { axis: 'distance (darker = more similar)' }));
				return '';
			},

			// ── downloads (typology_export.js) ──
			fileBase() {
				const folder = (window.location.pathname.split('/').filter(Boolean).slice(-2, -1)[0] || 'corpus').replace(/[^A-Za-z0-9_-]/g, '');
				return `typology-${folder}`;
			},
			async download(kind) {
				const m = this.m();
				const X = window.ttTypologyExport;
				if (!m || !X) return;
				const names = m.langs.map((l) => this.label(l));
				const base = this.fileBase();
				try {
					if (kind === 'svg') X.svg(`${base}-${this.view}.svg`, this.viewSvg(this.view, true));
					else if (kind === 'png') await X.png(`${base}-${this.view}.png`, this.viewSvg(this.view, true));
					else if (kind === 'newick') X.text(`${base}.nwk`, X.newick(m.tree, names) + '\n', 'text/plain');
					else if (kind === 'distances') {
						X.text(`${base}-distances.csv`, X.csv(['language', ...names], m.dist.D.map((row, i) => [names[i], ...row.map((d, j) => (i === j ? 0 : d === null ? '' : d.toFixed(4)))])), 'text/csv');
					} else if (kind === 'space') {
						if (!m.coords) m.coords = M().mds(m.dist.D);
						X.text(`${base}-space.csv`, X.csv(['code', 'language', 'group', 'x', 'y'], m.langs.map((l, i) => [l, names[i], (m.groups[i] ?? 0) + 1, m.coords[i][0].toFixed(5), m.coords[i][1].toFixed(5)])), 'text/csv');
					} else if (kind === 'table') {
						const head = ['code', 'language'];
						for (const id of m.ids) { const t = this.features[id].title; head.push(t, `${t}: detail`, `${t}: n`); }
						const rows = m.langs.map((l) => {
							const r = [l, this.label(l)];
							for (const id of m.ids) { const v = this.cell(l, id); r.push(v && v.dims ? v.label : '', v ? (v.dims ? v.detail : v.missing) : '', v ? v.n : ''); }
							return r;
						});
						X.text(`${base}-features.csv`, X.csv(head, rows), 'text/csv');
					}
				} catch (e) {
					this.error = String(e.message || e);
				}
			},

			// ── language names ──
			openNames() {
				const m = this.m();
				const codes = m ? m.langs.slice() : [];
				codes.sort((a, b) => (this.hasName(a) - this.hasName(b)) || a.localeCompare(b));
				this.namesEdit = Object.fromEntries(codes.map((c) => [c, (this.names && this.names[c]) || '']));
				this.namesOpen = true;
			},
			namesMissing() { const m = this.m(); return m ? m.langs.filter((l) => !this.hasName(l)).length : 0; },
			async saveNames(scope) {
				try {
					const r = await this.post('save_names', { scope, names: this.namesEdit }, true);
					this.applyState(r);
					this.namesOpen = false;
					this.draw();
				} catch (e) {
					this.error = String(e.message || e);
				}
			},

			onSvgClick(ev) {
				const t = ev.target && ev.target.closest && ev.target.closest('[data-lang]');
				if (t) this.setFocus(t.getAttribute('data-lang'));
			},
			setFocus(code) { this.focus = this.focus === code ? '' : code; this.draw(); },

			// ── the feature table and the focus panel ──
			/** The table's rows: in the dendrogram's order, or sorted by a column (sortKey: 'lang' or a feature id). */
			tableLangs() {
				const m = this.m();
				if (!m) return [];
				const rows = m.order.map((i) => m.langs[i]);
				if (!this.sortKey) return rows;
				const dir = this.sortDesc ? -1 : 1;
				const key = (l) => {
					if (this.sortKey === 'lang') return [0, this.label(l).toLowerCase(), 0];
					const v = this.cell(l, this.sortKey);
					if (!v || !v.dims) return null;   // no value: always last
					const f = this.features[this.sortKey];
					if (f.kind === 'order' || f.kind === 'share') return [0, String(v.label).toLowerCase(), -Math.max(...v.dims)];   // category, then how dominant
					return [Number.parseFloat(v.label) || 0, '', -v.n];   // inventory size, bundles
				};
				return rows.map((l) => [l, key(l)]).sort((a, b) => {
					if (!a[1] || !b[1]) return (a[1] ? 0 : 1) - (b[1] ? 0 : 1);
					for (let i = 0; i < 3; i += 1) {
						if (a[1][i] < b[1][i]) return -dir;
						if (a[1][i] > b[1][i]) return dir;
					}
					return 0;
				}).map(([l]) => l);
			},
			/** A header clicked: sort by it, then the other way, then back to the dendrogram's order. */
			sortBy(key) {
				if (this.sortKey !== key) { this.sortKey = key; this.sortDesc = false; }
				else if (!this.sortDesc) this.sortDesc = true;
				else { this.sortKey = ''; this.sortDesc = false; }
			},
			sortMark(key) { return this.sortKey === key ? (this.sortDesc ? ' ▼' : ' ▲') : ''; },
			cell(lang, id) { return (this.m() && this.m().prof[lang] && this.m().prof[lang][id]) || null; },
			cellText(lang, id) { const v = this.cell(lang, id); return v ? (v.dims ? v.label : '—') : ''; },
			cellTitle(lang, id) {
				const v = this.cell(lang, id);
				if (!v) return '';
				return v.dims ? `${v.detail} (n = ${v.n})` : v.missing;
			},
			cellStyle(lang, id) {
				const v = this.cell(lang, id);
				if (!v || !v.dims) return 'color:#999';
				const f = this.features[id];
				if (f.kind === 'order' || f.kind === 'share') {
					const i = f.variants.findIndex((x) => x.name === v.top);
					const strength = Math.max(...v.dims);
					return `background:${V().colour(i)}${Math.round(40 + strength * 140).toString(16).padStart(2, '0')}`;
				}
				return `background:rgba(0,114,178,${(0.12 + 0.7 * v.dims[0]).toFixed(2)})`;
			},
			/** The hits behind a cell: the dominant variant's query (or the counted tokens) in that language. */
			cellLink(lang, id) {
				const v = this.cell(lang, id);
				const f = this.features[id];
				if (!v || !f) return '#';
				const within = ` within ${this.settings.lang}="${lang}"`;
				const withinQ = (q) => { const i = q.indexOf(' :: '); return i < 0 ? q + within : q.slice(0, i) + within + q.slice(i); };
				if (f.kind === 'order' || f.kind === 'share') {
					const variant = f.variants.find((x) => x.name === v.top) || f.variants[0];
					return this.searchLink(withinQ(variant.query));
				}
				const field = f.kind === 'inventory' ? `feats/${f.feat}` : 'feats';
				return this.distributionLink(`[upos="${f.pos}"]${within}`, field);
			},
			searchLink(q) { return `index.php?action=flexicorp&viz=${b64(JSON.stringify({ s: 'fvs1', b: PANDO, q: { q }, u: { t: 'search' } }))}`; },
			distributionLink(q, field) {
				return `index.php?action=flexicorp&viz=${b64(JSON.stringify({ s: 'fvs1', b: PANDO, q: { q }, u: { t: 'frequency', s: 'freq' },
					f: { f: field, ff: [field], l: 100, vm: 'table', cs: 'absolute', co: 'size', to: { asc: true }, m: ['count'] } }))}`;
			},
			focusNearest() {
				const m = this.m();
				if (!m || !this.focus) return [];
				const i = m.langs.indexOf(this.focus);
				return M().nearest(m.dist, i, 8).map(([j, d, k]) => ({ code: m.langs[j], d: d.toFixed(3), shared: k }));
			},
			/** What sets the focus language apart from its nearest neighbour (or the language compared). */
			focusDifferences() {
				const m = this.m();
				const near = this.focusNearest()[0];
				if (!m || !this.focus || !near) return { other: '', rows: [] };
				const a = m.prof[this.focus], b = m.prof[near.code];
				const rows = M().differences(a, b, m.ids).slice(0, 6).filter(([, d]) => d > 0.05)
					.map(([id, d]) => {
						// the same label (both SVO, both 4 cases): show the shares that differ
						const same = a[id].label === b[id].label;
						return { id, title: this.features[id].title, d: d.toFixed(2), a: same ? a[id].detail : a[id].label, b: same ? b[id].detail : b[id].label };
					});
				return { other: near.code, rows };
			},

			// ── share links ──
			shareState() {
				return { f: this.selected, n: this.minN, m: this.minFeatures, k: this.groupsK, v: this.view, l: this.langFilter || undefined, x: this.focus || undefined,
					o: this.sortKey ? (this.sortDesc ? '-' : '') + this.sortKey : undefined };
			},
			decodeShare(s) {
				if (!s) return null;
				try { return JSON.parse(unb64(s)); } catch (_) { return null; }
			},
			shareUrl() {
				const u = new URL(window.location.href);
				u.search = '';
				u.searchParams.set('action', 'typology');
				u.searchParams.set('s', b64(JSON.stringify(this.shareState())));
				return u.toString();
			},
			async copyShare() {
				try { await navigator.clipboard.writeText(this.shareUrl()); this.copied = true; setTimeout(() => { this.copied = false; }, 2000); } catch (_) { window.prompt('Share link', this.shareUrl()); }
			},
		};
		// the feature editor (typology_editor.js): its state and methods on the same component
		const ctx = {
			post: (op, body, write) => app.post.call(app, op, body, write),
			forget: (id) => { delete data[id]; },
			forgetAll: () => { for (const k of Object.keys(data)) delete data[k]; },
		};
		return window.ttTypologyEditor ? Object.assign(app, window.ttTypologyEditor(ctx)) : app;
	};
})();
