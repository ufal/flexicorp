/**
 * Quantitative analysis tab (frequency distribution, future collocations / keyness / corpus stats).
 * Merged into flexicorpApp via flexicorpFreqsExtend (see flexicorp.js). This file holds the Stats state;
 * the methods are in fc_<id>.js (FLEXICORP_FREQS_PARTS), loaded before flexicorp.js.
 */
const FLEXICORP_FREQS_PARTS = ['stats_fields', 'scope', 'stats_corpus', 'stats_freq', 'stats_tabs', 'stats_coll', 'stats_other'];

window.flexicorpFreqsExtend = function flexicorpFreqsExtend() {
	const _freqsState = {
		/** 'table' | 'bar' | 'vbar' | 'vbar-error' | 'stacked-bar' | 'stacked-vbar' | 'line' | 'radar' | 'polar' | 'pie' | 'doughnut' */
		frequencyVizMode: 'table',
		frequencyTableSearch: '',
		frequencyTableSort: { col: null, asc: true },
		/** Compare-table metric columns (multi-select, keep at least one): count|pct|nipm|ripm */
		frequencyCompareMetricCols: [],
		/** Charts only: 'relative' (IPM when available, else % of hit set) vs 'absolute' (raw counts). Default relative. */
		frequencyChartValueScale: 'relative',
		/** Charts only: ordering of category slices/bars. */
		frequencyChartOrder: 'size',
		frequencyChartInstance: null,
		/** Bumped on destroy so pending rAF (resize / scheduled render) never touches a torn-down Chart. */
		_frequencyChartEpoch: 0,

		/** Same modes as frequency; Y/radius uses the primary association measure (first in API order) or Obs. */
		collocationVizMode: 'table',
		collocationChartMetricKey: '',
		collocationChartInstance: null,
		_collocationChartEpoch: 0,
		_collocationMeasureRerunTimer: null,
		/** Generic "Other" table visualizations. */
		otherVizMode: 'table',
		otherChartXColumn: '',
		otherChartYColumn: '',
		otherChartInstance: null,
		_otherChartEpoch: 0,
		/** Guards explicit corpus-info refresh calls from duplicate clicks/effects. */
		corpusInfoRefreshInFlight: false,
		/** After a successful frequency run, hide field/limit/run like Search's collapsed query (use Change to edit). */
		frequencyShowSetupForm: true,
		/** Cached syntax-highlighted HTML for Stats snippets (scope / aggregation). */
		statsSnippetHighlightCache: {},
		statsSnippetHighlightPending: {},
		/** Mutable Search Scope store from ttFlexicorpFns.searchScope.createSearchScopeStore (plain object). */
		statsSearchScopeStore: null,
		_statsSearchScopeSig: '',
		/** Previous active-id set before temporary Collocations single-query mode. */
		_collocationScopeRestoreActiveIds: null,
	};
	const _parts = window.ttFlexicorpFreqsParts || {};
	return Object.assign(_freqsState, ...FLEXICORP_FREQS_PARTS.map((id) => {
		if (typeof _parts[id] === 'function') return _parts[id]();
		console.error('[flexicorp] fc_' + id + '.js did not register window.ttFlexicorpFreqsParts.' + id + ' (check 404 / script order / CSP).');
		return {};
	}));
};
