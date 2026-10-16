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
		return {
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
			groupsK: 6,
			view: 'dendrogram',
			langFilter: '',
			focus: '',
			svg: '',
			copied: false,

			/** The current model (re-read by the page when `version` changes). */
			m() { return this.version >= 0 ? model : null; },
			hasData(id) { return this.version >= 0 && !!data[id]; },

			async init() {
				const st = this.decodeShare(boot.s);
				try {
					const r = await this.post('state', {});
					this.settings = r.settings;
					this.cannotCount = r.cannot_count || '';
					this.features = r.features;
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

			async post(op, body) {
				const res = await fetch(`index.php?action=typology&op=${encodeURIComponent(op)}`, {
					method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
				});
				const j = await res.json();
				if (!j.ok) throw new Error(j.error || 'error');
				return j;
			},

			/** The selected features, one by one (each counted once on the server, then kept). */
			async loadSelected(force) {
				for (const id of this.selected) {
					if (data[id] && !force) continue;
					// this server cannot count: only what was counted before (said once, above the views)
					if (this.cannotCount && !(this.features[id] && this.features[id].kept)) continue;
					this.status[id] = 'counting';
					try {
						const r = await this.post(force ? 'recompute' : 'feature', { id });
						data[id] = r.counts || {};
						this.status[id] = 'done';
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
					this.status[id] = 'done';
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

			label(code) { const n = langName(code); return n === code ? code : `${n} (${code})`; },

			draw() {
				const m = this.m();
				if (!m || m.langs.length < 2) { this.svg = ''; return; }
				const opts = { labels: m.langs.map((l) => this.label(l)), codes: m.langs, groups: m.groups, width: 900, highlight: this.focus };
				if (this.view === 'dendrogram') this.svg = V().dendrogram(m.tree, opts);
				else if (this.view === 'space') { if (!m.coords) m.coords = M().mds(m.dist.D); this.svg = V().space(m.coords, opts); }
				else if (this.view === 'matrix') this.svg = V().matrix(m.dist, m.order, opts);
				else this.svg = '';
			},

			onSvgClick(ev) {
				const t = ev.target && ev.target.closest && ev.target.closest('[data-lang]');
				if (t) this.setFocus(t.getAttribute('data-lang'));
			},
			setFocus(code) { this.focus = this.focus === code ? '' : code; this.draw(); },

			// ── the feature table and the focus panel ──
			tableLangs() { return this.m() ? this.m().order.map((i) => this.m().langs[i]) : []; },
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
				return { f: this.selected, n: this.minN, m: this.minFeatures, k: this.groupsK, v: this.view, l: this.langFilter || undefined, x: this.focus || undefined };
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
	};
})();
