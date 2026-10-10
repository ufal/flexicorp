/** First <p>...</p> → lead (below Hit limit); rest → intro box. Matches tt_flexicorp_split_search_intro_lead_body() in flexicorp.php. */
function flexicorpSplitSearchIntroHtml(html) {
	const s = typeof html === 'string' ? html.trim() : '';
	if (!s) return { lead: '', body: '' };
	const re = /^(\s*<p(?:\s[^>]*)?>[\s\S]*?<\/p>)/i;
	const m = s.match(re);
	if (!m) return { lead: '', body: s };
	const lead = m[1].trim();
	const body = s.slice(m[0].length).trim();
	return { lead, body };
}

/** Parts of the root component in fc_<id>.js, in mixing order (flexicorp.php loads them before this file). */
const FLEXICORP_CORE_PARTS = ['viewstate', 'backend', 'statsrouting', 'kwicview', 'requests', 'highlight', 'querystore', 'documents', 'hitdetail', 'hits'];

function flexicorpApp() {
	/** Mirrors tt_flexicorp_default_search_intro_html() when #flexicorp-initial-state omits searchIntroHtml (legacy shell / failed encode fallback). */
	const DEFAULT_SEARCH_INTRO_LEAD =
		'<p>Enter a query in the search syntax supported by your selected engine. Available syntax depends on your corpus configuration.</p>';
	const DEFAULT_SEARCH_INTRO_BODY =
		'<p>For <strong>CWB-style CQL</strong>, a typical pattern is to search for a word form or lemma; for example, words <em>starting with the letter a</em> often use a prefix pattern where your dialect supports regular expressions.</p>' +
		'<p><strong>Example</strong> buttons below (if configured by the corpus administrator) follow your currently selected engine and corpus setup.</p>';

	const _flexicorpCore = {
		action: 'flexicorp',
		projectRoot: '',
		backendOverrides: {
			blacklab_url: '',
			blacklab_corpus: '',
			blacklab_user: '',
			blacklab_password: '',
			blacklab_field: '',
		},
		isAdmin: false,
		/** The corpus's entry in corpus lists (admins): { description, descriptionRequired, editUrl } */
		corpusListing: null,
		attributeCatalog: {},
		debugMode: false,
		activeTab: 'search',
		/** Last tab the user explicitly chose via setTab (survives in-flight AJAX applyState). */
		_userActiveTab: null,
		/** Bumped on every setTab so late AJAX responses do not steal navigation. */
		_tabNavToken: 0,
		/** Nested panel inside Stats: freq | coll | other | dcoll | corpus | dynamic module ids (maps, contrast, …).
		 * 'other' is a generic raw-table view for aggregation ops without a dedicated panel
		 * (count, dist, dcoll, keyness — last two routed through 'other' for now). */
		statsSubTab: 'freq',
		/** Keep Stats "Queries" subtab hidden until user enters edit flow. */
		statsQueriesTabVisible: false,
		/** Registered by JS installers (advanced_maps.js, advanced_contrast.js, …). */
		statsModuleRegistry: [],
		_statsModuleInstallersRan: false,
		settingsOpen: false,
		statusDetailsOpen: false,
		corporaOpen: false,
		corporaLoading: false,
		corporaTitle: '',
		corporaError: '',
		corporaList: [],
		corporaTargetCombo: null,
		reindexTargetComboId: '',
		reindexTargetBackends: [],
		reindexSelection: {},
		helpOpen: false,
		rawOpen: false,
		rawTitle: '',
		rawBody: '',
		loading: {
			backend: false,
			documents: false,
			search: false,
			frequency: false,
			collocation: false,
			reindex: false,
		},
		availableBackends: ['pando', 'cqp'],
		availableQueryEngines: [],
		backendStatus: {},
		backendCombos: [],
		flexicorpDocsUrl: '',
		docMetaLabels: {},
		settings: {
			backend: 'cqp',
			queryEngine: 'cqp',
			queryLanguage: 'cwb-cql',
			corpusFormat: 'cwb',
			groupHitsBySentence: true,
			docsLimit: 10000,
			kwicLimit: 25,
			kwicWindow: 10,
			freqLimit: 100,
			// Mirrored from PHP: minimal default; TEITOK getset flexicorp/context_scope_regions
			contextScopeRegionAllowlist: ['s', 'u', 'lb', 'l', 'p', 'seg'],
			/** Relative weights for KWIC <colgroup> column widths (normalized to %). */
			kwicColWeights: { ctx: 7, left: 36, match: 10, right: 36, alLeft: 12, alMatch: 14, alRight: 12 },
		},
		settingsDraft: {
			backend: 'cqp',
			queryLanguage: 'cwb-cql',
			corpusFormat: 'cwb',
			groupHitsBySentence: true,
			docsLimit: 10000,
			kwicLimit: 25,
			kwicWindow: 10,
			freqLimit: 100,
			contextScopeRegionAllowlist: ['s', 'u', 'lb', 'l', 'p', 'seg'],
			kwicColWeights: { ctx: 7, left: 36, match: 10, right: 36, alLeft: 12, alMatch: 14, alRight: 12 },
		},
		status: {},
		info: {},
		reindex: {},
		listDocs: {},
		documents: [],
		documentsTotal: 0,
		docLookup: {},
		documentsUi: {
			filter: '',
			perPage: 20,
			visibleCount: 20,
		},
		search: {
			ran: false,
			query: '',
			field: 'lemma',
			value: '',
			contextFormat: 'xml',
			contextScope: 's',
			viewMode: 'table',
			window: 10,
			limit: 100,
			start: 0,
			response: {},
			hits: [],
			resultType: 'hits',
			tableColumns: [],
			tableRows: [],
			queryHighlightHtml: '',
			queryHighlightError: '',
			queryHighlightDebounceId: null,
			queryHighlightEnabled: false,
			/** When false after a completed search, the full query form is hidden; use "Change" to show it again. */
			showQueryForm: true,
		},
		/** Canonical raw search payload kept client-side; visible views are rebuilt from this store. */
		searchRaw: {
			hits: [],
			tableColumns: [],
			tableRows: [],
		},
		/** Signature of the request that produced searchRaw (context format/scope/query/etc). */
		searchRawSignature: '',
		/** Internal request flag: true only while a "show more" append request is in flight. */
		_pendingSearchAppend: false,
		// Bumped for every new result (not for "Show more"): the result rows' x-for keys carry it,
		// so a new search builds fresh rows instead of reusing the previous result's (Alpine kept
		// their aligned-target content when only the index-based keys matched).
		searchRenderGen: 0,
		/** Display mode selected by the user while the current search request is in flight. */
		_pendingSearchUiViewMode: '',
		/** True until user explicitly changes display mode; allows scope-based defaults. */
		searchViewModeAuto: true,
		/** Keep default hit limit adaptive to viewport until user picks one manually. */
		searchLimitAuto: true,
		_autoSearchLimitLast: 0,
		_autoSearchLimitResizeTimer: null,
		rawKwicDelimiter: '--%%%--',
		frequency: {
			ran: false,
			field: '',
			/** Multi-field frequency: checked field keys (Stats → Frequency checkboxes; POST as freq_field[]). */
			formFields: [],
			limit: 25,
			response: {},
			rows: [],
		},
		/** Pando-style collocations (window, coll-by attribute, association measures). */
		collocation: {
			ran: false,
			field: 'lemma',
			/** Optional: which named token `coll (on t) …` measures from (discontinuous / with queries). Hit set is the Stats search scope. */
			anchorToken: '',
			left: 5,
			right: 5,
			minFreq: 1,
			maxItems: 100,
			stoplist: 0,
			/** Allowed ids: logdice, mi, mi3, tscore, ll, dice — order preserved for API / charts (first = primary). */
			measureKeys: ['logdice'],
			matches: null,
			response: {},
			rows: [],
		},
		/**
		 * Generic raw-table aggregation result for ops without a dedicated panel:
		 * count, dist, dcoll, keyness. Hydrated from PHP state.other; rendered by
		 * the "Other" Stats subtab as a labelled raw table (no charts/visualizations).
		 */
		other: {
			ran: false,
			operation: '',
			columns: [],
			rows: [],
			total: null,
			response: null,
		},
		responses: {},
		debugEntries: [],
		docOpen: {},
		/** Region type names from project `xidx/region_types.tbl` (filled by server). */
		xidxRegionTypes: [],
		/** Server snapshot for backend/corpus resolution (see flexicorp.php bootstrapDiag). */
		bootstrapDiag: null,
		settingsSelectedComboId: '',
		/** Session-scoped recent queries for the active query_language (CQL dialect). */
		recentQueries: [],
		/** All dialect buckets from PHP session: { [queryLanguage: string]: string[] } */
		recentQueriesByDialect: {},
		/** Stored named TEITOK queries keyed by query language. */
		storedQueriesByDialect: {},
		/**
		 * This corpus's recent queries, newest first, from browser storage (fc_querystore.js):
		 * [{qid, text, ql, at}]; null = not loaded, or storage unavailable (then the session lists).
		 */
		recentQueriesLocal: null,
		/** Sanitized HTML from getset flexicorp/search_intro_html; intro box (everything after the first <p>). */
		searchIntroHtml: '',
		/** Admin-only: heuristic mismatch between xmlfiles/, pando/, xidx/ (from PHP). */
		adminPandoIndexHint: null,
		adminPandoIndexHintDismissed: false,
		/** First <p> of the search intro (hint below Hit limit). */
		searchIntroLeadHtml: '',
		/** { query, label }[] from getset flexicorp/search_example_queries */
		searchExampleQueries: [],
		/** Optional display-name overrides keyed by backend id. */
		backendDisplayNames: {},
		/** Lightweight query-builder config shipped by PHP bootstrap. */
		queryBuilderConfig: {},
		backendOptions: {
			flexi: {
				label: 'flexi',
				description: 'Native flexicorp indexed backend reading corpus files directly.',
			},
			cqp: {
				label: 'Corpus WorkBench',
				description: 'Compatibility backend delegating queries to the legacy CQP/CWB toolchain.',
			},
			manatee: {
				label: 'Manatee',
				description: 'Manatee corpus manager backend.',
			},
			pando: {
				label: 'pando',
				description: 'Pando corpus index; queries use the flexicorp-pando adapter.',
			},
			'flexicorp-pando': {
				label: 'flexicorp-pando',
				description: 'Direct flexicorp-pando adapter backend (daemon/API path).',
			},
			blacklab: {
				label: 'BlackLab (BCQL)',
				description: 'BlackLab Server backend using BCQL over a TEITOK-derived BlackLab index.',
			},
			clickhouse: {
				label: 'ClickHouse (SQL)',
				description: 'Direct ClickHouse backend for daemon status, SQL inspection, and management operations.',
			},
			clickql: {
				label: 'ClickQL (ClickHouse)',
				description: 'Query-oriented ClickHouse backend that translates clickCQL and related query languages to SQL before execution.',
			},
			teitokxml: {
				label: 'TEITOK XML documents',
				description: 'Document-list utility backend over TEITOK XML files and doclist.sqlite.',
			},
		},
		queryLanguageOptions: {
			'cwb-cql': {
				id: 'cwb-cql',
				short: 'CWB/CQL',
				full: 'CWB/IMS Corpus Query Language (CQL)',
			},
			manatee: {
				id: 'manatee',
				short: 'Manatee CQL',
				full: 'Manatee Corpus Query Language',
			},
			'manatee-cql': {
				id: 'manatee-cql',
				short: 'Manatee CQL',
				full: 'Manatee Corpus Query Language',
			},
			bcql: {
				id: 'bcql',
				short: 'BCQL',
				full: 'BlackLab Corpus Query Language (BCQL)',
			},
			corpusql: {
				id: 'corpusql',
				short: 'CorpusQL',
				full: 'BlackLab CorpusQL',
			},
			pmltq: {
				id: 'pmltq',
				short: 'PML-TQ',
				full: 'PML Tree Query (PML-TQ)',
			},
			clickcql: {
				id: 'clickcql',
				short: 'clickCQL',
				full: 'clickCQL (ClickHouse-oriented CQL)',
			},
			clickql: {
				id: 'clickql',
				short: 'clickCQL',
				full: 'clickCQL (ClickHouse-oriented CQL)',
			},
			sql: {
				id: 'sql',
				short: 'SQL',
				full: 'Structured Query Language (SQL)',
			},
		},
		queryLanguageHelpDocs: {
			'cwb-cql': 'help-cwb-cql',
			cwb: 'help-cwb-cql',
			cql: 'help-cwb-cql',
			bcql: 'help-bcql',
			corpusql: 'help-bcql',
			'manatee-cql': 'help-manatee-cql',
			manatee: 'help-manatee-cql',
			clickcql: 'help-clickcql',
			clickql: 'help-clickcql',
			'pando-cql': 'help-pando-cql',
			pmltq: 'help-pmltq',
			clickpmltq: 'help-pmltq',
		},
		externalEngines: [],
		loadedSections: {
			overview: false,
			documents: false,
			search: false,
			frequency: false,
		},
		_hydrated: false,
		_hasAppliedInitialState: false,
		visualizationShareUrl: '',
		visualizationShareOpen: false,
		visualizationShareStatus: '',
		isFullscreen: false,

		// Fallback stats helpers so Alpine expressions stay valid
		// even if the optional freqs extension script is missing.
		frequencyRows() {
			return Array.isArray(this.frequency && this.frequency.rows) ? this.frequency.rows : [];
		},

		collocationRows() {
			const c = this.collocation;
			if (!c) return [];
			if (Array.isArray(c.rows) && c.rows.length) return c.rows;
			let r = c.response && c.response.result;
			for (let i = 0; i < 3 && r && typeof r === 'object'; i += 1) {
				if (Array.isArray(r.collocates)) return r.collocates;
				if (r.result && typeof r.result === 'object') {
					r = r.result;
					continue;
				}
				break;
			}
			return [];
		},

		/** Rows to inspect for optional metric columns (% / IPM / subcorpus size). */
		frequencyMetricSampleRows() {
			const f = this.frequency;
			if (!f) return [];
			// Prefer state.frequency.rows first — same array the template iterates. Some backends
			// (e.g. Pando) also set result.table.rows without pct/ipm; preferring table.* hid columns
			// even when f.rows carried the metrics.
			if (Array.isArray(f.rows) && f.rows.length) return f.rows;
			const rr = f.response && f.response.result;
			if (rr && rr.table && Array.isArray(rr.table.rows) && rr.table.rows.length) return rr.table.rows;
			if (rr && Array.isArray(rr.rows) && rr.rows.length) return rr.rows;
			if (rr && Array.isArray(rr.items) && rr.items.length) return rr.items;
			return [];
		},

		frequencyDistributionMetaLine() {
			const f = this.frequency || {};
			return 'field=' + (f.field || '')
				+ (f.returned !== null && f.returned !== undefined ? ' · returned ' + f.returned : '')
				+ (f.total !== null && f.total !== undefined ? ' · total ' + f.total : '');
		},

		/** Denominator for % column: total hits in the distribution (backend) or sum of shown rows. */
		frequencyRelativeBase() {
			const total = Number(this.frequency && this.frequency.total);
			if (Number.isFinite(total) && total > 0) return total;
			const rowsTotal = this.frequencyRowsTotal();
			if (rowsTotal > 0) return rowsTotal;
			return null;
		},

		/** Corpus token count for IPM when rows omit ipm (matches flexicorp.php augment + search meta fallbacks). */
		frequencyCorpusTokensForIpm() {
			const r = this.frequency && this.frequency.response && this.frequency.response.result;
			const tryNum = (obj, keys) => {
				if (!obj || typeof obj !== 'object') return null;
				for (let i = 0; i < keys.length; i++) {
					const k = keys[i];
					if (obj[k] == null || obj[k] === '') continue;
					const n = Number(obj[k]);
					if (Number.isFinite(n) && n > 0) return n;
				}
				return null;
			};
			let n = tryNum(r, ['corpus_tokens', 'tokens_count', 'corpus_size', 'size', 'tokens', 'n_tokens']);
			if (n !== null) return n;
			if (r && r.info && typeof r.info === 'object') {
				n = tryNum(r.info, ['corpus_tokens', 'tokens_count', 'corpus_size', 'size', 'tokens', 'n_tokens']);
				if (n !== null) return n;
			}
			const infoResult = this.info && this.info.result && typeof this.info.result === 'object' ? this.info.result : null;
			if (infoResult) {
				n = tryNum(infoResult, ['tokens_count', 'corpus_size', 'size', 'tokens', 'n_tokens', 'corpus_tokens']);
				if (n !== null) return n;
			}
			return null;
		},

		frequencyHasProvidedPct() {
			const rows = this.frequencyMetricSampleRows();
			if (!rows.length) return false;
			if (
				rows.some((row) => {
					const v = row && (row.pct ?? row.percent ?? row.percentage);
					if (v === null || v === undefined || v === '') return false;
					return Number.isFinite(Number(v));
				})
			) {
				return true;
			}
			const base = this.frequencyRelativeBase();
			if (base === null || base <= 0) return false;
			return rows.some((row) => {
				const c = row && (row.count ?? row.freq ?? row.n);
				if (c == null || c === '') return false;
				return Number.isFinite(Number(c)) && Number(c) >= 0;
			});
		},

		frequencyHasProvidedIpm() {
			const rows = this.frequencyMetricSampleRows();
			if (!rows.length) return false;
			if (
				rows.some((row) => {
					const v = row && (row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm);
					if (v === null || v === undefined || v === '') return false;
					const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
					return Number.isFinite(n);
				})
			) {
				return true;
			}
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct === null || ct <= 0) return false;
			return rows.some((row) => {
				const c = row && (row.count ?? row.freq ?? row.n);
				if (c == null || c === '') return false;
				return Number.isFinite(Number(c)) && Number(c) >= 0;
			});
		},

		frequencyUsesSubcorpusIpm() {
			const result = this.frequency && this.frequency.response && this.frequency.response.result;
			return !!(result && result.per_subcorpus_ipm);
		},

		frequencyHasProvidedSubcorpusSize() {
			return this.frequencyMetricSampleRows().some((row) => {
				const v = row && row.subcorpus_size;
				if (v === null || v === undefined || v === '') return false;
				return Number.isFinite(Number(v));
			});
		},

		frequencyRowPercent(row) {
			const raw = row && (row.pct ?? row.percent ?? row.percentage);
			let n = Number(raw);
			if (Number.isFinite(n)) return n.toFixed(2);
			const base = this.frequencyRelativeBase();
			const count = row && (row.count ?? row.freq ?? row.n);
			const c = Number(count);
			if (base !== null && base > 0 && Number.isFinite(c) && c >= 0) {
				return ((c / base) * 100).toFixed(2);
			}
			return '';
		},

		formatIpmDisplay(value, opts = null) {
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			if (typeof fns.formatIpmDisplay === 'function') {
				return fns.formatIpmDisplay(value, opts);
			}
			const n = Number(value);
			if (!Number.isFinite(n)) return '';
			if (Math.abs(n) >= 100) return String(Math.round(n));
			return String(Number(n.toFixed(Math.abs(n) >= 10 ? 1 : 2)));
		},

		frequencyRowIpm(row) {
			if (!row || typeof row !== 'object') return '';
			const c = Number(row.count ?? row.freq ?? row.n);
			const countOpt = Number.isFinite(c) && c >= 0 ? c : null;
			const raw = row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm;
			if (raw !== null && raw !== undefined && raw !== '') {
				const n = typeof raw === 'number' ? raw : Number(String(raw).replace(/,/g, ''));
				if (Number.isFinite(n)) return this.formatIpmDisplay(n, { count: countOpt });
			}
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) {
				return this.formatIpmDisplay((c / ct) * 1000000, { count: c, base: ct });
			}
			return '';
		},

		frequencyRowSubcorpusSize(row) {
			const raw = row && row.subcorpus_size;
			const n = Number(raw);
			return Number.isFinite(n) ? String(Math.round(n)) : '';
		},

		frequencyRowsTotal() {
			return this.frequencyRows().reduce((sum, row) => {
				const n = Number(row && (row.count ?? row.freq ?? row.n ?? 0));
				return sum + (Number.isFinite(n) && n > 0 ? n : 0);
			}, 0);
		},

		frequencyRestCount() {
			const total = Number(this.frequency && this.frequency.total);
			if (!Number.isFinite(total) || total <= 0) return 0;
			const rest = total - this.frequencyRowsTotal();
			if (rest <= 0) return 0;
			return Math.max(0, Math.round(rest));
		},

		frequencyHasRestBucket() {
			return this.frequencyRestCount() > 0;
		},

		/** % of all hits not shown in the top-N rows (denominator = frequency.total). */
		frequencyRestRowPercent() {
			const rest = this.frequencyRestCount();
			if (rest <= 0) return '';
			const total = Number(this.frequency && this.frequency.total);
			if (Number.isFinite(total) && total > 0) {
				return ((rest / total) * 100).toFixed(2);
			}
			return '';
		},

		/** IPM for the aggregated “others” count (same basis as frequencyRowIpm). */
		frequencyRestRowIpm() {
			const rest = this.frequencyRestCount();
			if (rest <= 0) return '';
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct !== null && ct > 0) {
				return this.formatIpmDisplay((rest / ct) * 1000000, { count: rest, base: ct });
			}
			return '';
		},

		init() {
			const el = document.getElementById('flexicorp-initial-state');
			const loadingEl = document.getElementById('flexicorp-loading');
			if (!el) return;
			// Expose instance for small inline HTML handlers (eye-candy buttons).
			// Keep window.flexicorpApp as the factory function used by x-data.
			window.flexicorpAppInstance = this;
			try {
				const state = JSON.parse(el.textContent || '{}');
				const missingIntroKey = !Object.prototype.hasOwnProperty.call(state, 'searchIntroHtml');
				const missingLeadKey = !Object.prototype.hasOwnProperty.call(state, 'searchIntroLeadHtml');
				this.applyState(state);
				this.fcQueryStoreLoad();
				// URL-based visualization snapshot import (MVP).
				const fromLink = this.loadVisualizationSnapshotFromLocation();
				if (!fromLink) this.fcQueryStorePrefillLastQuery();
				// If JSON omitted or cleared intro but PHP rendered HTML into the template, adopt it (stale lang templates, partial AJAX).
				if (!String(this.searchIntroHtml || '').trim()) {
					const introBody = document.querySelector('#flexicorp-root .flexicorp-search-intro__body');
					if (introBody && introBody.innerHTML.trim()) {
						this.searchIntroHtml = introBody.innerHTML;
					}
				}
				if (!String(this.searchIntroLeadHtml || '').trim()) {
					const introLead = document.querySelector('#flexicorp-root .flexicorp-search-intro-lead');
					if (introLead && introLead.innerHTML.trim()) {
						this.searchIntroLeadHtml = introLead.innerHTML.trim();
					}
				}
				if (missingIntroKey && !String(this.searchIntroHtml || '').trim()) {
					this.searchIntroHtml = DEFAULT_SEARCH_INTRO_BODY;
					if (missingLeadKey && !String(this.searchIntroLeadHtml || '').trim()) {
						this.searchIntroLeadHtml = DEFAULT_SEARCH_INTRO_LEAD;
					}
				}
				// applyStoredFlexicorpSelection runs inside applyState (before persist).
				// So force-reload sends backend/query_engine/query_language in the URL, not only session.
				this.syncFlexicorpSelectionToUrl();
			} catch (err) {
				this.rawTitle = 'Failed to parse initial state';
				this.rawBody = err && err.message ? err.message : String(err);
				this.rawOpen = true;
			}
			if (loadingEl) loadingEl.remove();
			this.ensureSearchShareButton();
			this.updateSearchShareButtonVisibility();
			this.syncFullscreenState();
			this.initTeitokTokEditClicks();
			if (typeof document !== 'undefined' && !this._fullscreenListenerBound) {
				const onFs = () => this.syncFullscreenState();
				document.addEventListener('fullscreenchange', onFs);
				document.addEventListener('webkitfullscreenchange', onFs);
				this._fullscreenListenerBound = true;
			}
			setTimeout(() => {
				// Only fetch overview when on that tab; avoid request when on Debug so logs stay visible
				if (this.activeTab === 'overview') {
					this.ensureSectionLoaded('overview', 'backend');
				} else if (this.activeTab === 'documents') {
					this.ensureSectionLoaded('documents', 'documents');
				}
			}, 0);
			// Initial syntax highlight when backend supports it and there is a query
			if (this.queryHighlightSupported() && this.search && this.search.query) {
				this.scheduleQueryHighlight();
			}
			if (typeof this.initQueryBuilderModule === 'function') {
				this.initQueryBuilderModule();
			}

			// Defer DataTables initialisation for the documents table (TEITOK/EasyCorp global JS must be loaded).
			if (this.isSmallCorpus()) {
				setTimeout(() => this.initDocumentsDataTable(), 0);
			}
			// Lightweight viewport-aware default for hit limit (until manually changed).
			this.applyAutoSearchLimit({ force: true });
			if (typeof window !== 'undefined' && window.addEventListener) {
				window.addEventListener('resize', () => {
					if (this._autoSearchLimitResizeTimer) {
						clearTimeout(this._autoSearchLimitResizeTimer);
					}
					this._autoSearchLimitResizeTimer = setTimeout(() => {
						this.applyAutoSearchLimit();
						this.scheduleAlignedTableHeightSync();
					}, 180);
				});
			}
			this.scheduleAlignedTableHeightSync();
		},

		scheduleAlignedTableHeightSync() {
			if (this._alignedTableHeightSyncTimer) {
				clearTimeout(this._alignedTableHeightSyncTimer);
			}
			this._alignedTableHeightSyncTimer = setTimeout(() => {
				this._alignedTableHeightSyncTimer = null;
				if (this.$nextTick) {
					this.$nextTick(() => this.syncAlignedTableSlotHeights());
				} else {
					this.syncAlignedTableSlotHeights();
				}
			}, 0);
		},

		syncAlignedTableSlotHeights() {
			if (!this.searchHasAlignedHits() || !this.isSearchViewMode('table')) return;
			const root = this.$root || document;
			const rows = Array.from(root.querySelectorAll('.flexicorp-results-table tbody tr'));
			rows.forEach((tr) => {
				const wrappers = Array.from(tr.querySelectorAll('td > .flexicorp-aligned-table-cell'));
				if (!wrappers.length) return;
				const slotRows = wrappers.map((w) => Array.from(w.querySelectorAll(':scope > .flexicorp-aligned-table-cell-row')));
				const slotCount = slotRows.reduce((max, r) => Math.max(max, r.length), 0);
				if (slotCount <= 0) return;
				slotRows.forEach((r) => r.forEach((el) => {
					el.style.minHeight = '';
					el.style.height = '';
					el.style.verticalAlign = 'top';
				}));
				for (let i = 0; i < slotCount; i += 1) {
					let maxH = 0;
					for (let wi = 0; wi < slotRows.length; wi += 1) {
						const el = slotRows[wi][i];
						if (!el) continue;
						const h = el.getBoundingClientRect ? el.getBoundingClientRect().height : 0;
						if (h > maxH) maxH = h;
					}
					if (maxH <= 0) continue;
					const px = `${Math.ceil(maxH)}px`;
					for (let wi = 0; wi < slotRows.length; wi += 1) {
						const el = slotRows[wi][i];
						if (!el) continue;
						el.style.minHeight = px;
						el.style.height = px;
					}
				}
			});
		},

		getTeitokScriptsBase() {
			const base = (typeof window.teitokScripts === 'string' && window.teitokScripts.trim())
				? window.teitokScripts.trim().replace(/\/+$/, '')
				: '/teitok/TEITOK/Scripts';
			return base;
		},

		ensureAudioControlLoaded() {
			if (this._audioControlPromise) return this._audioControlPromise;
			this._audioControlPromise = new Promise((resolve) => {
				if (typeof window.playpart === 'function') {
					resolve(true);
					return;
				}
				// Ensure a minimal audio element exists (TEITOK's audiocontrol expects #track).
				if (!document.getElementById('track')) {
					const wrap = document.createElement('div');
					wrap.style.display = 'none';
					wrap.innerHTML = '<audio id="track" data-flexicorp-track="1" controls></audio>';
					document.body.appendChild(wrap);
				}
				const src = `${this.getTeitokScriptsBase()}/audiocontrol.js`;
				const s = document.createElement('script');
				s.src = src;
				s.async = true;
				s.onload = () => resolve(typeof window.playpart === 'function');
				s.onerror = () => resolve(false);
				document.head.appendChild(s);
			});
			return this._audioControlPromise;
		},

		/** Tab-local + URL fallback when PHP/embed still shows legacy default "cqp" but user chose Pando. */
		_flexicorpSelectionStorageKey: 'flexicorp.selection.v1',

		setTab(tab) {
			const prevTab = this.activeTab;
			this.activeTab = tab;
			this._userActiveTab = tab;
			this._tabNavToken = (this._tabNavToken || 0) + 1;
			if (tab === 'frequency') {
				// Stats-tab default: corpus overview only when no executed base query exists.
				// Once a base query ran, clicking "Stats" should open Frequency by default.
				const hasBaseQuery = !!(
					this.search &&
					this.search.ran &&
					typeof this.statsSearchHasHits === 'function' &&
					this.statsSearchHasHits()
				);
				if (!hasBaseQuery) {
					if (!this.statsSubTab || this.statsSubTab === 'freq') this.statsSubTab = 'corpus';
				} else if (!this.statsSubTab || this.statsSubTab === 'corpus') {
					this.statsSubTab = 'freq';
				}
			}
			if (
				typeof this.destroyFrequencyChart === 'function' &&
				prevTab === 'frequency' &&
				tab !== 'frequency'
			) {
				this.destroyFrequencyChart();
			}
			if (
				typeof this.destroyCollocationChart === 'function' &&
				prevTab === 'frequency' &&
				tab !== 'frequency'
			) {
				this.destroyCollocationChart();
			}
			// Only fetch when switching to overview; switching to debug just shows existing entries (no request = no reload).
			if (tab === 'overview') {
				this.ensureSectionLoaded('overview', 'backend');
			}
			if (tab === 'documents') {
				this.ensureSectionLoaded('documents', 'documents');
			}
			if (
				tab === 'frequency' &&
				this.statsSubTab === 'corpus' &&
				typeof this.ensureCorpusStatsLoaded === 'function'
			) {
				this.ensureCorpusStatsLoaded();
			}
			// When switching to the Documents tab on small corpora, ensure DataTables is initialised.
			if (tab === 'documents' && this.isSmallCorpus()) {
				this.$nextTick
					? this.$nextTick(() => this.initDocumentsDataTable())
					: setTimeout(() => this.initDocumentsDataTable(), 0);
			}
			this.syncFlexicorpSelectionToUrl();
		},

		showDebugTab() {
			return !!(this.isAdmin && this.debugMode);
		},

		appendClientDebugEntry(entry) {
			if (!this.showDebugTab()) return;
			const next = Array.isArray(this.debugEntries) ? this.debugEntries.slice() : [];
			next.push(entry);
			this.debugEntries = next;
		},

		buildDebugEntryFromFormData(formData, err) {
			const safeFields = {};
			let backend = '';
			let operation = '';
			if (formData && typeof formData.forEach === 'function') {
				formData.forEach((value, key) => {
					const lowerKey = String(key || '').toLowerCase();
					if (lowerKey.includes('password')) return;
					const textValue = typeof value === 'string' ? value : String(value);
					safeFields[key] = textValue;
				});
				backend = String(formData.get('backend') || '');
				operation = String(formData.get('run') || formData.get('operation') || 'request');
			}
			const message = err && err.message ? err.message : String(err);
			return {
				time: new Date().toLocaleTimeString(),
				backend,
				operation,
				ok: false,
				command: '',
				raw: '',
				errors: [message],
				warnings: [],
				result: { request: safeFields, client_error: message },
			};
		},

		reloadPage() {
			window.location.reload();
		},

		syncFullscreenState() {
			if (typeof document === 'undefined') {
				this.isFullscreen = false;
				return;
			}
			this.isFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);
		},

		fullscreenButtonLabel() {
			return this.isFullscreen ? 'Exit fullscreen' : 'Fullscreen';
		},

		async toggleFullscreen() {
			if (typeof document === 'undefined') return;
			const doc = document;
			const root = doc.getElementById('flexicorp-root') || doc.documentElement;
			const active = !!(doc.fullscreenElement || doc.webkitFullscreenElement);
			try {
				if (active) {
					if (doc.exitFullscreen) await doc.exitFullscreen();
					else if (doc.webkitExitFullscreen) doc.webkitExitFullscreen();
				} else if (root && root.requestFullscreen) {
					await root.requestFullscreen();
				} else if (root && root.webkitRequestFullscreen) {
					root.webkitRequestFullscreen();
				}
			} catch (_err) {
				/* ignore fullscreen errors (permissions/user gesture/browser policy). */
			}
			this.syncFullscreenState();
		},

		debugViewModeLog(stage, extra) {
			/* debug logging disabled */
		},

		installTokinfoDebugHooks() {
			/* debug wrappers disabled */
		},

		logAbiendoloSearchPayload(searchObj, stage = 'applyState') {
			/* debug logging disabled */
		},

		refreshTeitokResultBehavior() {
			const reinit = () => {
				const mtxt = document.getElementById('mtxt');
				if (!mtxt || typeof window.formify !== 'function') return;
				// tokview.js inline handlers may reference bare `tid`; ensure it exists globally.
				if (typeof window.tid === 'undefined') window.tid = '';
				// Facsimile beside tokens is suppressed by stripping bbox/facs/baseline in getRenderedXmlContext
				// (and CSS hides stray imgs). We still run formify for TEITOK orthography toggles (pform/form/nform).
				window.formified = false;
				window.formify();
			};
			if (this.$nextTick) {
				this.$nextTick(reinit);
			} else {
				setTimeout(reinit, 0);
			}
		},

		teitokUsername() {
			if (typeof window === 'undefined') return '';
			return String(window.username || '').trim();
		},

		/**
		 * TEITOK tokview.js opens action=tokedit on TOK click when username is set, using
		 * cid=jumpid and tid=element.id. Flexicorp rewrites DOM ids (rN_…) for uniqueness and
		 * often lacks tr[@tid], so that path never works. Capture clicks and open tokedit with
		 * the real TEITOK xml:id (data-flexicorp-orig-id) and nearest hit cid.
		 */
		initTeitokTokEditClicks() {
			if (typeof document === 'undefined' || this._tokEditClickBound) return;
			this._tokEditClickBound = true;
			const root = document.getElementById('flexicorp-root');
			if (root && this.teitokUsername()) {
				root.classList.add('flexicorp-can-tokedit');
			}
			document.addEventListener(
				'click',
				(evt) => {
					if (!this.teitokUsername()) return;
					let el = evt.target;
					if (!el || el.nodeType !== 1) {
						el = el && el.parentElement ? el.parentElement : null;
					}
					while (el && el !== document.body) {
						const tag = String(el.tagName || '').toUpperCase();
						if (tag === 'TOK' || tag === 'DTOK' || tag === String(window.mwenode || 'mtok').toUpperCase()) {
							break;
						}
						el = el.parentElement;
					}
					if (!el || !el.closest) return;
					if (
						!el.closest(
							'#mtxt .flexicorp-hit, .flexicorp-results-table, .flexicorp-kwic-table, .flexicorp-hit-xml, .flexicorp-kwic-xml, .flexicorp-kwic-match-xml, .flexicorp-hit-raw-rendered',
						)
					) {
						return;
					}
					const tokId = this.resolveTeitokTokenIdForEdit(el);
					const cid = this.resolveTeitokDocCidForEdit(el);
					if (!tokId || !cid) return;
					evt.preventDefault();
					evt.stopPropagation();
					const url = new URL('index.php', window.location.href);
					url.searchParams.set('action', 'tokedit');
					url.searchParams.set('cid', cid);
					url.searchParams.set('tid', tokId);
					window.open(url.toString(), 'edit');
				},
				true,
			);
		},

		resolveTeitokTokenIdForEdit(tokEl) {
			if (!tokEl || !tokEl.getAttribute) return '';
			const orig = String(tokEl.getAttribute('data-flexicorp-orig-id') || '').trim();
			if (orig) return orig;
			const raw = String(tokEl.getAttribute('id') || '').trim();
			if (!raw) return '';
			// Strip flexicorp uniqueness prefix (r12_w-35 → w-35).
			return raw.replace(/^r\d+_/, '');
		},

		resolveTeitokDocCidForEdit(tokEl) {
			if (!tokEl || !tokEl.closest) return String(window.tid || '').trim();
			const host = tokEl.closest('[tid], [data-flexicorp-cid]');
			if (host) {
				const fromHost = String(host.getAttribute('tid') || host.getAttribute('data-flexicorp-cid') || '').trim();
				if (fromHost) return fromHost;
			}
			return String(window.tid || '').trim();
		},

		async ensureSectionLoaded(section, loadingKey) {
			if (!section || (this.loadedSections && this.loadedSections[section])) return;
			const formData = this.buildCommonRequestData();
			formData.set('run', '');
			formData.set('active_tab', section === 'documents' ? 'documents' : 'overview');
			await this.submitAjaxData(formData, loadingKey || 'backend');
			if (section === 'documents' && this.isSmallCorpus()) {
				this.$nextTick
					? this.$nextTick(() => this.initDocumentsDataTable())
					: setTimeout(() => this.initDocumentsDataTable(), 0);
			}
		},

		openSettings() {
			this.ensureKwicColWeightsShape();
			this.settingsDraft = Object.assign({}, this.settings);
			if (this.settings.kwicColWeights && typeof this.settings.kwicColWeights === 'object') {
				this.settingsDraft.kwicColWeights = Object.assign({}, this.settings.kwicColWeights);
			}
			this.settingsOpen = true;
		},

		closeSettings() {
			this.settingsOpen = false;
		},

		openHelp() {
			this.helpOpen = true;
		},

		closeHelp() {
			this.helpOpen = false;
		},

		openResponse(key, title) {
			const payload = this.responses && this.responses[key] ? this.responses[key] : null;
			if (!payload) return;
			const parts = [];
			if (payload.command) parts.push(`Command:\n${payload.command}`);
			if (payload.error) parts.push(`Error:\n${payload.error}`);
			if (Array.isArray(payload.errors) && payload.errors.length) parts.push(`Errors:\n${payload.errors.join('\n')}`);
			if (Array.isArray(payload.warnings) && payload.warnings.length) parts.push(`Warnings:\n${payload.warnings.join('\n')}`);
			if (payload.result !== undefined && payload.result !== null) parts.push(`Result:\n${this.formatMaybeObject(payload.result)}`);
			if (payload.raw) parts.push(`Raw response:\n${payload.raw}`);
			this.rawTitle = title || 'Raw response';
			this.rawBody = parts.join('\n\n');
			this.rawOpen = true;
		},

		closeRaw() {
			this.rawOpen = false;
		},

		hasRaw(key) {
			const payload = this.responses && this.responses[key] ? this.responses[key] : null;
			return !!(payload && (payload.raw || payload.command));
		},

		entries(obj) {
			if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return [];
			return Object.keys(obj).map(key => ({ key, value: obj[key] }));
		},

		formatMaybeObject(value) {
			if (value === null || value === undefined) return '';
			if (typeof value === 'string') return value;
			try {
				return JSON.stringify(value, null, 2);
			} catch (_) {
				return String(value);
			}
		},
	};
	// Extensions must register window.flexicorp{Search,Freqs}Extend before flexicorp.js runs
	// (all three use `defer`, which preserves source order). If a file 404s or fails to parse,
	// warn but don't crash the whole app — the other tabs stay usable. Runtime errors inside
	// the extend functions propagate: a broken Stats factory should fail loud, not silently.
	if (typeof window.flexicorpSearchExtend !== 'function') {
		console.warn('[flexicorp] flexicorp_search.js did not register window.flexicorpSearchExtend (check 404 / script order / CSP).');
	}
	if (typeof window.flexicorpFreqsExtend !== 'function') {
		console.warn('[flexicorp] flexicorp_freqs.js did not register window.flexicorpFreqsExtend (check 404 / script order / CSP). Stats tab will throw Alpine errors until it loads.');
	}
	if (typeof window.flexicorpFqsExtend !== 'function') {
		console.warn('[flexicorp] flexicorp_fqs.js did not register window.flexicorpFqsExtend (check 404 / script order / CSP).');
	}
	if (typeof window.flexicorpQuerybuilderExtend !== 'function') {
		console.warn('[flexicorp] flexicorp_querybuilder.js did not register window.flexicorpQuerybuilderExtend (check 404 / script order / CSP).');
	}
	const _searchExt = typeof window.flexicorpSearchExtend === 'function' ? window.flexicorpSearchExtend() : {};
	const _freqsExt = typeof window.flexicorpFreqsExtend === 'function' ? window.flexicorpFreqsExtend() : {};
	const _fqsExt = typeof window.flexicorpFqsExtend === 'function' ? window.flexicorpFqsExtend() : {};
	const _qbExt = typeof window.flexicorpQuerybuilderExtend === 'function' ? window.flexicorpQuerybuilderExtend() : {};
	// flexicorp_freqs.js implements compare-queries (row.queries[Q].pct/ipm) and single-row metrics.
	// Do NOT override frequencyHasProvidedPct / frequencyRowPercent / etc. with _flexicorpCore here:
	// the core helpers only look at top-level row.pct — multi-query Pando rows have metrics under
	// row.queries only, so relative-mode columns and charts would stay hidden (only subcorpus_size visible).
	const _freqTableColHelpers = {
		frequencyMetricSampleRows: _flexicorpCore.frequencyMetricSampleRows,
		frequencyHasProvidedSubcorpusSize: _flexicorpCore.frequencyHasProvidedSubcorpusSize,
		frequencyRowSubcorpusSize: _flexicorpCore.frequencyRowSubcorpusSize,
		frequencyUsesSubcorpusIpm: _flexicorpCore.frequencyUsesSubcorpusIpm,
	};
	// Most methods live in fc_*.js (window.ttFlexicorpCoreParts) since the module split; they are
	// mixed in right after _flexicorpCore and before the extensions, the place they had inside it.
	const _coreParts = window.ttFlexicorpCoreParts || {};
	const _coreBags = FLEXICORP_CORE_PARTS.map((id) => {
		if (typeof _coreParts[id] === 'function') return _coreParts[id]();
		console.error('[flexicorp] fc_' + id + '.js did not register window.ttFlexicorpCoreParts.' + id + ' (check 404 / script order / CSP).');
		return {};
	});
	return Object.assign({}, _flexicorpCore, ..._coreBags, _searchExt, _freqsExt, _fqsExt, _qbExt, _freqTableColHelpers);
}

// Explicit global bindings (some TEITOK shells or CSP contexts do not hoist function declarations onto window).
// Keep a stable factory alias so templates can recover even if window.flexicorpApp was polluted by older bundles.
window.flexicorpAppFactory = flexicorpApp;
window.flexicorpApp = flexicorpApp;

/**
 * Builds the root Alpine state object. Registered as Alpine.data('flexicorpRoot', …) on alpine:init
 * so the template can use x-data="flexicorpRoot" — bare globals (window / globalThis) are not
 * reliably visible inside Alpine's x-data evaluator, which caused "is not a function" / empty $data.
 */
function flexicorpRootData() {
	try {
		if (typeof window.flexicorpApp === 'function') {
			return window.flexicorpApp();
		}
		if (typeof window.flexicorpAppFactory === 'function') {
			return window.flexicorpAppFactory();
		}
		if (window.flexicorpApp && typeof window.flexicorpApp === 'object') {
			return window.flexicorpApp;
		}
		console.error(
			'[flexicorp] flexicorpApp is missing — flexicorp.js did not run (404, parse error, or script order: load flexicorp.js before Alpine).'
		);
		return {};
	} catch (e) {
		console.error('[flexicorp] flexicorpApp() threw', e);
		throw e;
	}
}

document.addEventListener('alpine:init', () => {
	if (typeof Alpine === 'undefined' || typeof Alpine.data !== 'function') {
		console.error('[flexicorp] Alpine loaded without Alpine.data — wrong Alpine build?');
		return;
	}
	Alpine.data('flexicorpRoot', () => flexicorpRootData());
});
