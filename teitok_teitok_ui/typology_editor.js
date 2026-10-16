/**
 * typology.php's feature editor, mixed into the page's component (typology.js): open a feature
 * (built-in, the corpus's or one's own) and change its queries, start a new one from a template, try
 * it before keeping it, keep it for oneself or (admins) for the corpus, hide built-in ones, give the
 * corpus's attribute names (upos / deprel / feats), export and import a feature set as JSON.
 * window.ttTypologyEditor(ctx) → the state and methods to merge; ctx gives post() and the data store.
 */
(function () {
	'use strict';

	const TEMPLATES = {
		order: { kind: 'order', group: 'order', title: '', wals: '', note: '',
			variants: [{ name: 'AB', query: 'h:[upos="NOUN"] > d:[deprel="amod"] :: d < h' }, { name: 'BA', query: 'h:[upos="NOUN"] > d:[deprel="amod"] :: h < d' }] },
		share: { kind: 'share', group: 'clause', title: '', wals: '', note: '',
			variants: [{ name: 'with', query: '[upos="VERB" & child [deprel="obj"]]' }, { name: 'without', query: '[upos="VERB" & not child [deprel="obj"]]' }] },
		inventory: { kind: 'inventory', group: 'morphology', title: '', wals: '', note: '', pos: 'NOUN', feat: 'Case' },
		bundles: { kind: 'bundles', group: 'morphology', title: '', wals: '', note: '', pos: 'VERB' },
	};
	const KINDS = {
		order: 'Competing orders (the dominant one, as WALS)',
		share: 'Alternatives (their shares)',
		inventory: 'Inventory (values of a feature in use)',
		bundles: 'Richness (feature bundles, features per token)',
	};
	const clone = (o) => JSON.parse(JSON.stringify(o));

	window.ttTypologyEditor = function (ctx) {
		return {
			editing: null,          // the definition being edited (a copy)
			editingOrigin: '',      // builtin | corpus | mine | new
			editMsg: '',
			trial: null,            // { languages, rows: [[lang, label]], seconds, missing } after "Try"
			trialBusy: false,
			attrsOpen: false,
			attrsEdit: { upos: '', deprel: '', feats: '' },
			kinds: KINDS,
			importMsg: '',

			canKeep() { return !!this.boot.user; },
			startNew(kind) {
				this.editing = Object.assign({ id: '' }, clone(TEMPLATES[kind || 'order']));
				this.editingOrigin = 'new';
				this.editMsg = '';
				this.trial = null;
			},
			edit(id) {
				const f = this.features[id];
				if (!f) return;
				this.editing = { id, title: f.title, group: f.group, kind: f.kind, wals: f.wals || '', note: f.note || '',
					variants: (f.variants || []).map((v) => ({ name: v.name, query: v.query })), pos: f.pos || '', feat: f.feat || '' };
				this.editingOrigin = f.origin || 'builtin';
				this.editMsg = f.origin === 'builtin' ? 'A built-in feature: keeping a changed version makes it yours (or, for admins, the corpus\'s); the built-in one stays as it is.' : '';
				this.trial = null;
			},
			setKind(kind) {
				const t = clone(TEMPLATES[kind]);
				this.editing.kind = kind;
				if ((kind === 'order' || kind === 'share') && !(this.editing.variants || []).length) this.editing.variants = t.variants;
				if (kind === 'inventory' || kind === 'bundles') { this.editing.pos = this.editing.pos || t.pos; if (kind === 'inventory') this.editing.feat = this.editing.feat || t.feat; }
			},
			addVariant() { this.editing.variants.push({ name: '', query: '' }); },
			removeVariant(i) { this.editing.variants.splice(i, 1); },
			closeEditor() { this.editing = null; this.trial = null; this.editMsg = ''; },
			definition() {
				const e = this.editing;
				const d = { id: e.id, title: e.title, group: e.group, kind: e.kind, wals: e.wals, note: e.note };
				if (e.kind === 'order' || e.kind === 'share') d.variants = e.variants.map((v) => [v.name, v.query]);
				else { d.pos = e.pos; if (e.kind === 'inventory') d.feat = e.feat; }
				return d;
			},

			/** Count the definition as it stands (with the user's limits) and show the first languages. */
			async tryFeature() {
				this.trialBusy = true;
				this.editMsg = '';
				this.trial = null;
				try {
					const def = this.definition();
					if (!def.id) def.id = 'trial';
					const r = await ctx.post('feature', { def });
					const feat = Object.assign({}, def, { variants: (def.variants || []).map(([name, query]) => ({ name, query })) });
					const langs = Object.keys(r.counts || {}).filter((l) => l !== '_' && l.trim() !== '');
					const rows = langs
						.map((l) => [l, window.ttTypologyMath.featureValue(feat, r.counts[l], this.minN)])
						.filter(([, v]) => v.dims)
						.sort((a, b) => b[1].n - a[1].n);
					this.trial = { languages: langs.length, usable: rows.length, rows: rows.slice(0, 12).map(([l, v]) => [l, v.label, v.n]),
						seconds: r.seconds, missing: r.missing || '', program: r.program || '' };
				} catch (e) {
					this.editMsg = String(e.message || e);
				}
				this.trialBusy = false;
			},

			async keep(scope) {
				this.editMsg = '';
				try {
					const r = await ctx.post('save_feature', { scope, def: this.definition() }, true);
					const id = this.editing.id.toLowerCase();
					this.applyState(r);
					ctx.forget(id);
					if (!this.selected.includes(id)) this.selected = this.selected.concat([id]);
					this.closeEditor();
					await this.loadSelected();
				} catch (e) {
					this.editMsg = String(e.message || e);
				}
			},
			async remove(scope) {
				const id = this.editing.id;
				if (!window.confirm(this.editingOrigin === 'builtin' ? `Hide “${this.editing.title}”?` : `Delete “${this.editing.title}”?`)) return;
				try {
					const r = await ctx.post('delete_feature', { scope, id }, true);
					this.applyState(r);
					ctx.forget(id);
					this.selected = this.selected.filter((x) => x !== id || this.features[x]);
					this.closeEditor();
					await this.loadSelected();
				} catch (e) {
					this.editMsg = String(e.message || e);
				}
			},
			async unhide(id, scope) {
				try {
					const r = await ctx.post('unhide', { scope, id }, true);
					this.applyState(r);
					if (!this.selected.includes(id)) this.selected = this.selected.concat([id]);
					await this.loadSelected();
				} catch (e) {
					this.error = String(e.message || e);
				}
			},

			// ── the corpus's attribute names ──
			openAttributes() {
				const a = this.attributes || {};
				this.attrsEdit = { upos: a.upos || '', deprel: a.deprel || '', feats: a.feats || '' };
				this.attrsOpen = true;
			},
			async saveAttributes(scope) {
				try {
					const r = await ctx.post('save_attributes', { scope, attributes: this.attrsEdit }, true);
					this.applyState(r);
					ctx.forgetAll();
					this.attrsOpen = false;
					await this.loadSelected();
				} catch (e) {
					this.error = String(e.message || e);
				}
			},

			// ── export / import ──
			exportFeatures() {
				const out = { attributes: this.attributes || {}, features: {} };
				for (const [id, f] of Object.entries(this.features)) {
					if (f.origin === 'builtin') continue;
					out.features[id] = { title: f.title, group: f.group, kind: f.kind, wals: f.wals, note: f.note,
						variants: (f.variants || []).map((v) => [v.name, v.query]), pos: f.pos, feat: f.feat };
				}
				const a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([JSON.stringify(out, null, 2)], { type: 'application/json' }));
				a.download = 'typology-features.json';
				a.click();
				setTimeout(() => URL.revokeObjectURL(a.href), 1000);
			},
			async importFeatures(ev) {
				const file = ev.target.files && ev.target.files[0];
				ev.target.value = '';
				if (!file) return;
				this.importMsg = '';
				let j;
				try { j = JSON.parse(await file.text()); } catch (_) { this.importMsg = 'not a JSON file'; return; }
				let n = 0;
				const errors = [];
				for (const [id, f] of Object.entries((j && j.features) || {})) {
					try {
						const r = await ctx.post('save_feature', { scope: 'mine', def: Object.assign({ id }, f) }, true);
						this.applyState(r);
						if (!this.selected.includes(id)) this.selected = this.selected.concat([id]);
						n += 1;
					} catch (e) {
						errors.push(`${id}: ${e.message || e}`);
					}
				}
				this.importMsg = `${n} feature${n === 1 ? '' : 's'} added to yours` + (errors.length ? `; not added: ${errors.join('; ')}` : '');
				await this.loadSelected();
			},
		};
	};
})();
