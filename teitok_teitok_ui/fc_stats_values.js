/**
 * flexicorp TEITOK UI: Stats: corpus overview value lists.
 * The vocabulary size of an attribute in the corpus overview opens the attribute's values with their
 * counts (most frequent first, filterable, more on request); each value searches the corpus for it.
 * Values come from an ordinary frequency request (`[]` counted by the attribute) sent on its own, so
 * the search and the Frequency results on the page stay as they are.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_values = function () {
	const PAGE = 100;
	// the value as a quoted literal: regex metacharacters and quotes escaped
	const literal = (v) => String(v).replace(/[\\.^$|?*+()[\]{}"]/g, '\\$&');
	return {
		corpusValuesIsOpen(attr) {
			return !!attr && this.corpusValues.attr === attr;
		},
		/** Panel under a table: '' for token attributes, the region name for region attributes. */
		corpusValuesShownFor(region) {
			return !!this.corpusValues.attr && this.corpusValues.region === String(region || '');
		},
		corpusValuesToggle(attr, label, region, vocab) {
			if (this.corpusValuesIsOpen(attr)) {
				this.corpusValues.attr = '';
				return;
			}
			const n = Number(String(vocab == null ? '' : vocab).replace(/[^\d]/g, ''));
			this.corpusValues = {
				attr: String(attr), label: String(label || attr), region: String(region || ''),
				vocab: Number.isFinite(n) && n > 0 ? n : null,
				rows: [], total: null, limit: PAGE, loading: false, error: '', filter: '', serverFilter: '',
			};
			this.corpusValuesFetch();
		},
		/** Region attributes are named region_attr; the attribute's own name within the region. */
		corpusValuesRegionAttr() {
			const { attr, region } = this.corpusValues;
			return region && attr.startsWith(region + '_') ? attr.slice(region.length + 1) : attr;
		},
		corpusValuesIsPando() {
			return String((this.settings && this.settings.queryLanguage) || '').trim() === 'pando-cql';
		},
		/** The query whose hits are counted: all tokens, or those whose value matches the filter. */
		corpusValuesBaseQuery() {
			const { attr, region, serverFilter } = this.corpusValues;
			if (!serverFilter) return '[]';
			// case-insensitive: pando-CQL takes (?i) in a pattern (%c is for literal values only), CQP %c
			const pat = this.corpusValuesIsPando() ? `"(?i).*${literal(serverFilter)}.*"` : `".*${literal(serverFilter)}.*"%c`;
			return region ? `[] :: match.${attr}=${pat}` : `[${attr}=${pat}]`;
		},
		/** A search for one value: tokens with it, or (region attributes) the regions with it. */
		corpusValuesQueryFor(value) {
			const { attr, region } = this.corpusValues;
			const v = `"${literal(value)}"`;
			if (!region) return `[${attr}=${v}]`;
			if (this.corpusValuesIsPando()) return `r:<${region} ${this.corpusValuesRegionAttr()}=${v}>`;
			return `<${region}> [] :: match.${attr}=${v}`;
		},
		async corpusValuesFetch() {
			const cv = this.corpusValues;
			const attr = cv.attr;
			if (!attr || !window.ttFlexicorpFns || typeof window.ttFlexicorpFns.runFlexicorpAjaxFetch !== 'function') return;
			const fd = new FormData();
			fd.set('action', this.action || 'flexicorp');
			const s = this.settings || {};
			fd.set('backend', s.backend ? String(s.backend) : 'cqp');
			if (s.queryEngine) fd.set('query_engine', String(s.queryEngine));
			if (s.queryLanguage) fd.set('query_language', String(s.queryLanguage));
			if (s.corpusFormat) fd.set('corpus_format', String(s.corpusFormat));
			fd.set('query', this.corpusValuesBaseQuery());
			fd.append('freq_field[]', attr);
			fd.set('freq_limit', String(cv.limit));
			fd.set('active_tab', 'frequency');
			fd.set('run', 'freq');
			fd.set('record', '0');
			fd.set('ajax', '1');
			cv.loading = true;
			cv.error = '';
			try {
				const out = await window.ttFlexicorpFns.runFlexicorpAjaxFetch(fd, null);
				if (this.corpusValues !== cv) return;   // another list was opened meanwhile
				if (!out || !out.response || !out.response.ok || out.parseError) throw new Error('the request failed');
				const f = out.state && out.state.frequency ? out.state.frequency : {};
				const resp = f.response || {};
				const errs = [].concat(resp.error || [], Array.isArray(resp.errors) ? resp.errors : []).filter((e) => e && String(e).trim());
				if (errs.length) throw new Error(String(errs[0]).split('\n')[0]);
				cv.rows = Array.isArray(f.rows) ? f.rows.map((r) => ({ value: String(r.value ?? r.key ?? ''), count: Number(r.count) || 0 })) : [];
				cv.total = Number.isFinite(Number(f.total)) ? Number(f.total) : null;
			} catch (err) {
				if (this.corpusValues === cv) cv.error = err && err.message ? err.message : String(err);
			} finally {
				cv.loading = false;
			}
		},
		/** Loaded values matching the filter box. */
		corpusValuesVisibleRows() {
			const needle = String(this.corpusValues.filter || '').trim().toLowerCase();
			const rows = this.corpusValues.rows || [];
			return needle ? rows.filter((r) => r.value.toLowerCase().includes(needle)) : rows;
		},
		/** All values are loaded (so the filter box filters the full list). */
		corpusValuesComplete() {
			const cv = this.corpusValues;
			if (cv.serverFilter) return cv.rows.length < cv.limit;
			if (cv.vocab != null) return cv.rows.length >= cv.vocab;
			return cv.rows.length < cv.limit;
		},
		corpusValuesMore() {
			this.corpusValues.limit += PAGE;
			this.corpusValuesFetch();
		},
		/** Look the filter text up in all values of the attribute, not only the loaded ones. */
		corpusValuesFilterAll() {
			const cv = this.corpusValues;
			cv.serverFilter = String(cv.filter || '').trim();
			cv.limit = PAGE;
			this.corpusValuesFetch();
		},
		corpusValuesClearFilter() {
			const cv = this.corpusValues;
			const wasServer = !!cv.serverFilter;
			cv.filter = '';
			cv.serverFilter = '';
			if (wasServer) {
				cv.limit = PAGE;
				this.corpusValuesFetch();
			}
		},
		/** Share of the corpus (tokens); the request's own total only counts the filtered values. */
		corpusValuesShare(row) {
			const r = typeof this.corpusStatsInfoResult === 'function' ? this.corpusStatsInfoResult() : null;
			const size = r && typeof this.corpusStatsNumberFromKeys === 'function'
				? this.corpusStatsNumberFromKeys(r, ['tokens_count', 'corpus_size', 'size', 'n_tokens', 'corpus_tokens', 'tokens'])
				: null;
			const t = size || (this.corpusValues.serverFilter ? null : this.corpusValues.total);
			if (!t) return '';
			const p = (100 * row.count) / t;
			return p >= 0.1 ? `${p.toFixed(1)}%` : '<0.1%';
		},
		corpusValuesSummary() {
			const cv = this.corpusValues;
			const unit = cv.region ? 'tokens in the matching regions' : 'tokens';
			const shown = cv.rows.length.toLocaleString();
			const of = cv.vocab != null && !cv.serverFilter ? ` of ${cv.vocab.toLocaleString()}` : '';
			const filt = cv.serverFilter ? ` containing "${cv.serverFilter}"` : '';
			return `${shown}${of} values${filt}, most frequent first; counts are ${unit}.`;
		},
		corpusValuesSearch(value) {
			if (!this.search) return;
			this.search.query = this.corpusValuesQueryFor(value);
			this.search.field = '';
			this.search.value = '';
			if (typeof this.scheduleQueryHighlight === 'function') this.scheduleQueryHighlight();
			if (typeof this.setTab === 'function') this.setTab('search');
			this.submitSearchRequest();
		},
	};
};
