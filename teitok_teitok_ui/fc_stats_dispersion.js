/**
 * flexicorp TEITOK UI: Stats: the dispersion view of a frequency by text — where the hits fall within
 * each text (fc_chart_dispersion.js). It needs positions, which the frequency table does not have, so
 * it asks pando for them with two frequency requests of its own (not stored in the view state):
 *   t:<text> :: t.id="…|…"; freq by t.id, t.cpos, tcnt(t)  where the texts with most hits start, and their length
 *   <query>; freq by cpos                                 the hits' corpus positions (up to 20,000)
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
/** `text_id`, `match.text_id`, `a.text_id` → the matching position field (`cpos`, `match.cpos`, `a.cpos`); '' otherwise. */
window.ttFlexicorpFreqsParts._dispersionCposField = function (field) {
	const m = String(field || '').trim().match(/^(?:([A-Za-z_][\w-]*)\.)?text_id$/);
	if (!m) return '';
	return m[1] ? `${m[1]}.cpos` : 'cpos';
};
window.ttFlexicorpFreqsParts.stats_dispersion = function () {
	const cposField = window.ttFlexicorpFreqsParts._dispersionCposField;
	const MAX_TEXTS = 40;     // texts drawn: the ones with most hits
	const MAX_HITS = 20000;   // positions fetched
	const charts = () => (typeof window !== 'undefined' && window.ttFlexicorpCharts) || {};
	const escapeRe = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/"/g, '\\"');

	return {
		/** Frequencies by text on pando (one field: text_id), without compared queries. */
		frequencyDispersionAvailable() {
			if (this.frequencyHasCompareQueries() || !charts().dispersion) return false;
			const be = String((this.settings && this.settings.backend) || '').toLowerCase();
			if (be !== 'pando' && be !== 'flexicorp-pando') return false;
			const fields = this.frequencyFieldsArray();
			return fields.length === 1 && cposField(fields[0]) !== '';
		},

		async frequencyDispersionFetch(query, fields, limit, positions) {
			const fns = window.ttFlexicorpFns;
			if (!fns || typeof fns.runFlexicorpAjaxFetch !== 'function') throw new Error('flexicorp is not loaded');
			const fd = new FormData();
			fd.set('action', this.action || 'flexicorp');
			const s = this.settings || {};
			fd.set('backend', s.backend ? String(s.backend) : 'pando');
			if (s.queryEngine) fd.set('query_engine', String(s.queryEngine));
			if (s.queryLanguage) fd.set('query_language', String(s.queryLanguage));
			if (s.corpusFormat) fd.set('corpus_format', String(s.corpusFormat));
			fd.set('query', query);
			for (const f of fields) fd.append('freq_field[]', f);
			fd.set('freq_limit', String(limit));
			if (positions) fd.set('freq_rows', 'positions');   // more than the 200 rows of a frequency table
			fd.set('active_tab', 'frequency');
			fd.set('run', 'freq');
			fd.set('record', '0');
			fd.set('ajax', '1');
			const out = await fns.runFlexicorpAjaxFetch(fd, null);
			if (!out || !out.response || !out.response.ok || out.parseError) throw new Error('the request failed');
			const f = out.state && out.state.frequency ? out.state.frequency : {};
			const resp = f.response || {};
			const errs = [].concat(resp.error || [], Array.isArray(resp.errors) ? resp.errors : []).filter((e) => e && String(e).trim());
			if (errs.length) throw new Error(String(errs[0]).split('\n')[0]);
			return (Array.isArray(f.rows) ? f.rows : []).map((r) => this.frequencyRowValuesArray(r));
		},

		/**
		 * The texts with most hits (from the frequency table), where they start and how long they are,
		 * and the hits' positions (`freq by cpos`: one field, so no per-text limit), assigned to the
		 * texts by range. Cached per query and field.
		 */
		async frequencyDispersionData() {
			const field = this.frequencyFieldsArray()[0];
			const query = typeof this.statsBaseQueryText === 'function' ? String(this.statsBaseQueryText() || '').trim() : '';
			const key = `${query}\u0001${field}`;
			const cur = this.frequencyDispersion;
			if (cur && cur.key === key) return cur;
			const state = { key, loading: true, error: '', texts: [], hits: 0, truncated: false, textsTotal: 0 };
			this.frequencyDispersion = state;
			try {
				const rows = this.frequencyChartRowsBase();
				state.textsTotal = rows.length;
				const top = rows.map((r) => String(this.frequencyRowValuesArray(r)[0] || '')).filter(Boolean).slice(0, MAX_TEXTS);
				if (top.length) {
					const ext = await this.frequencyDispersionFetch(`t:<text> :: t.id="${top.map(escapeRe).join('|')}"`,
						['t.id', 't.cpos', 'tcnt(t)'], MAX_TEXTS * 2, false);
					const where = new Map(ext.map(([id, start, len]) => [id, { start: Number(start), length: Number(len) }]));
					const texts = top.filter((t) => where.has(t)).map((t) => ({
						label: t.replace(/^xmlfiles\//, '').replace(/\.xml$/, ''), id: t, start: where.get(t).start, length: where.get(t).length, hits: [],
					}));
					const pos = await this.frequencyDispersionFetch(query, [cposField(field)], MAX_HITS, true);
					const byStart = texts.slice().sort((a, b) => a.start - b.start);
					for (const [p] of pos) {
						const x = Number(p);
						if (!Number.isFinite(x)) continue;
						let lo = 0, hi = byStart.length - 1, hit = null;   // the text starting at or before x
						while (lo <= hi) {
							const mid = (lo + hi) >> 1;
							if (byStart[mid].start <= x) { hit = byStart[mid]; lo = mid + 1; } else hi = mid - 1;
						}
						if (hit && x < hit.start + hit.length) hit.hits.push(x);
					}
					state.texts = texts;
					state.hits = pos.length;
					state.truncated = pos.length >= MAX_HITS;
				}
			} catch (err) {
				state.error = err && err.message ? err.message : String(err);
			} finally {
				state.loading = false;
			}
			if (this.frequencyDispersion === state) this.frequencyDispersion = { ...state };   // Alpine sees the result
			return this.frequencyDispersion;
		},

		async renderFrequencyDispersion(box) {
			box.textContent = 'Finding where the hits are…';
			const st = await this.frequencyDispersionData();
			if (this.frequencyVizMode !== 'dispersion' || !box.isConnected) return;
			box.textContent = '';
			if (st.error) {
				box.textContent = `Could not get the positions: ${st.error}`;
				return;
			}
			this.frequencySvgInfo = charts().dispersion(box, st.texts, { width: box.clientWidth || 900 });
		},

		frequencyDispersionNote() {
			const st = this.frequencyDispersion;
			if (!st || st.loading || st.error) return '';
			const shown = st.textsTotal > st.texts.length ? `the ${st.texts.length} texts with most hits (of ${st.textsTotal.toLocaleString()})` : 'each text';
			return `One bar per text, ${shown}, with a mark per hit at its place in the text; above, all of them together.`
				+ (st.truncated ? ` Based on the first ${st.hits.toLocaleString()} hits.` : '');
		},
	};
};
