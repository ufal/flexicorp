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

function flexicorpApp() {
	/** Mirrors tt_flexicorp_default_search_intro_html() when #flexicorp-initial-state omits searchIntroHtml (legacy shell / failed encode fallback). */
	const DEFAULT_SEARCH_INTRO_LEAD =
		'<p>Enter a query in the search syntax supported by your selected engine. Available syntax depends on your corpus configuration.</p>';
	const DEFAULT_SEARCH_INTRO_BODY =
		'<p>For <strong>CWB-style CQL</strong>, a typical pattern is to search for a word form or lemma; for example, words <em>starting with the letter a</em> often use a prefix pattern where your dialect supports regular expressions.</p>' +
		'<p><strong>Example</strong> buttons below (if configured by the corpus administrator) follow your currently selected engine and corpus setup.</p>';

	/**
	 * Memo for values derived from one search result, kept outside the Alpine component so that
	 * filling it does not trigger re-renders. Keys are the raw (unproxied) hits array or hit object;
	 * a new search, or a further page, replaces the hits array, so entries expire with it.
	 * Without it the results table rebuilt the merged hit list (re-parsing every XML fragment)
	 * for each x-show/x-bind that asked for it: about 50 times for 25 aligned rows.
	 */
	const _fcRaw = (o) => (o && typeof window !== 'undefined' && window.Alpine && typeof window.Alpine.raw === 'function') ? window.Alpine.raw(o) : o;
	const _fcMergedHitsMemo = new WeakMap();
	const _fcSentenceIdMemo = new WeakMap();

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
				// URL-based visualization snapshot import (MVP).
				this.loadVisualizationSnapshotFromLocation();
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

		_base64UrlEncodeUnicode(str) {
			try {
				const utf8 = encodeURIComponent(String(str)).replace(/%([0-9A-F]{2})/g, (_, p1) =>
					String.fromCharCode(parseInt(p1, 16))
				);
				return btoa(utf8).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
			} catch (_) {
				return '';
			}
		},

		_base64UrlDecodeUnicode(str) {
			try {
				const s = String(str || '').replace(/-/g, '+').replace(/_/g, '/');
				const pad = s.length % 4 === 0 ? '' : '='.repeat(4 - (s.length % 4));
				const bin = atob(s + pad);
				let pct = '';
				for (let i = 0; i < bin.length; i += 1) {
					pct += '%' + bin.charCodeAt(i).toString(16).padStart(2, '0');
				}
				return decodeURIComponent(pct);
			} catch (_) {
				return '';
			}
		},

		buildVisualizationSnapshotPayload() {
			const scopeQueries = this.statsSearchScopeStore && Array.isArray(this.statsSearchScopeStore.queries)
				? this.statsSearchScopeStore.queries.map((q) => ({
					id: q && q.id != null ? String(q.id) : '',
					name: q && q.name != null ? String(q.name) : '',
					assignId: q && q.assignId != null ? String(q.assignId) : '',
					query: q && q.query != null ? String(q.query) : '',
					active: !(q && q.active === false),
				}))
				: [];
			return {
				schema: 'flexicorp-viz-snapshot',
				version: 1,
				created_at: new Date().toISOString(),
				settings: {
					backend: this.settings && this.settings.backend ? String(this.settings.backend) : '',
					query_engine: this.settings && this.settings.queryEngine ? String(this.settings.queryEngine) : '',
					query_language: this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage) : '',
					corpus_format: this.settings && this.settings.corpusFormat ? String(this.settings.corpusFormat) : '',
				},
				ui: {
					active_tab: this.activeTab || 'search',
					stats_subtab: this.statsSubTab || 'freq',
				},
				search: {
					query: this.search && this.search.query ? String(this.search.query) : '',
					field: this.search && this.search.field ? String(this.search.field) : '',
					value: this.search && this.search.value ? String(this.search.value) : '',
					context_format: this.search && this.search.contextFormat ? String(this.search.contextFormat) : '',
					context_scope: this.search && this.search.contextScope ? String(this.search.contextScope) : '',
					view_mode: this.search && this.search.viewMode ? String(this.search.viewMode) : '',
					window: this.search && this.search.window != null ? Number(this.search.window) : null,
					limit: this.search && this.search.limit != null ? Number(this.search.limit) : null,
					// hits loaded so far ("Show more"): the link loads as many again
					loaded: typeof this.searchLoadedItemCount === 'function' ? this.searchLoadedItemCount() : null,
				},
				scope: {
					queries: scopeQueries,
				},
				frequency: {
					field: this.frequency && this.frequency.field ? String(this.frequency.field) : '',
					form_fields: this.frequency && Array.isArray(this.frequency.formFields) ? this.frequency.formFields.map((x) => String(x)) : [],
					limit: this.frequency && this.frequency.limit != null ? Number(this.frequency.limit) : null,
					viz_mode: this.frequencyVizMode != null ? String(this.frequencyVizMode) : '',
					chart_type: this.frequencyChartType != null ? String(this.frequencyChartType) : '',
					chart_scale: this.frequencyChartValueScale != null ? String(this.frequencyChartValueScale) : '',
					chart_order: this.frequencyChartOrder != null ? String(this.frequencyChartOrder) : '',
					table_search: this.frequencyTableSearch != null ? String(this.frequencyTableSearch) : '',
					table_sort: this.frequencyTableSort && typeof this.frequencyTableSort === 'object' ? this.frequencyTableSort : null,
					compare_metric_cols: Array.isArray(this.frequencyCompareMetricCols) ? this.frequencyCompareMetricCols.map((x) => String(x)) : [],
				},
				collocation: {
					field: this.collocation && this.collocation.field ? String(this.collocation.field) : '',
					anchor_token: this.collocation && this.collocation.anchorToken ? String(this.collocation.anchorToken) : '',
					left: this.collocation && this.collocation.left != null ? Number(this.collocation.left) : null,
					right: this.collocation && this.collocation.right != null ? Number(this.collocation.right) : null,
					min_freq: this.collocation && this.collocation.minFreq != null ? Number(this.collocation.minFreq) : null,
					max_items: this.collocation && this.collocation.maxItems != null ? Number(this.collocation.maxItems) : null,
					stoplist: this.collocation && this.collocation.stoplist != null ? Number(this.collocation.stoplist) : null,
					measure_keys: this.collocation && Array.isArray(this.collocation.measureKeys) ? this.collocation.measureKeys.map((x) => String(x)) : [],
					viz_mode: this.collocationVizMode != null ? String(this.collocationVizMode) : '',
					chart_metric: this.collocationChartMetricKey != null ? String(this.collocationChartMetricKey) : '',
				},
				dcoll: this.dcollAdv && typeof this.dcollAdv === 'object' ? {
					anchor_token: String(this.dcollAdv.anchorToken || ''),
					relation: String(this.dcollAdv.relation || ''),
					field: String(this.dcollAdv.field || ''),
					min_freq: this.dcollAdv.minFreq != null ? Number(this.dcollAdv.minFreq) : null,
					max_items: this.dcollAdv.maxItems != null ? Number(this.dcollAdv.maxItems) : null,
					stoplist: this.dcollAdv.stoplist != null ? Number(this.dcollAdv.stoplist) : null,
					measure_keys: Array.isArray(this.dcollAdv.measureKeys) ? this.dcollAdv.measureKeys.map((x) => String(x)) : [],
					viz_mode: String(this.dcollAdvVizMode || ''),
					chart_metric: String(this.dcollAdvChartMetricKey || ''),
				} : null,
				contrast: this.afContrastVizMode !== undefined ? {
					fields: Array.isArray(this.afKeynessFields) ? this.afKeynessFields.map((x) => String(x)) : [],
					score_field: String(this.afKeynessScoreField || ''),
					viz_mode: String(this.afContrastVizMode || ''),
					metric: String(this.afContrastMetric || ''),
					volcano_limit: this.afContrastVolcanoPointLimit != null ? Number(this.afContrastVolcanoPointLimit) : null,
				} : null,
				other: {
					viz_mode: this.otherVizMode != null ? String(this.otherVizMode) : '',
					chart_x_column: this.otherChartXColumn != null ? String(this.otherChartXColumn) : '',
					chart_y_column: this.otherChartYColumn != null ? String(this.otherChartYColumn) : '',
				},
				maps: this.mapsVizMode == null ? null : {
					viz_mode: String(this.mapsVizMode || ''),
					map_mode: String(this.mapsMapMode || ''),
					chart_mode: String(this.mapsChartMode || ''),
					point_viz_mode: String(this.mapsPointVizMode || ''),
					region_metric: String(this.mapsRegionMetric || ''),
					color_mode: String(this.mapsRegionColorMode || ''),
					compare_scale: String(this.mapsCompareScaleMode || ''),
					dataset: String(this.mapsBoundaryDatasetKey || ''),
					area: this.mapsSelectedAreaKey && this.mapsSelectedAreaKey !== '__default__' ? String(this.mapsSelectedAreaKey) : '',
					agg_level: String(this.geoAggLevel || ''),
					custom_region_field: String(this.geoCustomRegionField || ''),
					point_limit: this.mapsPointLimit != null ? Number(this.mapsPointLimit) : null,
					table_search: String(this.mapsTableSearch || ''),
					table_sort: this.mapsTableSort && typeof this.mapsTableSort === 'object' ? this.mapsTableSort : null,
					view: typeof this.mapsCurrentView === 'function' && this.mapsVizMode === 'map' ? this.mapsCurrentView() : null,
				},
				documents: this.documentsUi && typeof this.documentsUi === 'object' ? {
					filter: String(this.documentsUi.filter || ''),
					per_page: this.documentsUi.perPage != null ? Number(this.documentsUi.perPage) : null,
					visible: this.documentsUi.visibleCount != null ? Number(this.documentsUi.visibleCount) : null,
				} : null,
			};
		},

		_compactPruneValue(value) {
			if (value == null) return null;
			if (Array.isArray(value)) {
				const arr = value
					.map((v) => this._compactPruneValue(v))
					.filter((v) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0));
				return arr.length ? arr : null;
			}
			if (typeof value === 'object') {
				const out = {};
				Object.keys(value).forEach((k) => {
					const v = this._compactPruneValue(value[k]);
					if (v === null || v === '') return;
					if (Array.isArray(v) && v.length === 0) return;
					if (typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0) return;
					out[k] = v;
				});
				return Object.keys(out).length ? out : null;
			}
			return value;
		},

		_compactVisualizationSnapshotPayload(fullPayload) {
			const p = fullPayload && typeof fullPayload === 'object' ? fullPayload : {};
			const compact = {
				s: 'fvs1',
				b: {
					b: p.settings && p.settings.backend,
					e: p.settings && p.settings.query_engine,
					l: p.settings && p.settings.query_language,
					f: p.settings && p.settings.corpus_format,
				},
				u: {
					t: p.ui && p.ui.active_tab,
					s: p.ui && p.ui.stats_subtab,
				},
				q: {
					q: p.search && p.search.query,
					f: p.search && p.search.field,
					v: p.search && p.search.value,
					cf: p.search && p.search.context_format,
					cs: p.search && p.search.context_scope,
					vm: p.search && p.search.view_mode,
					w: p.search && p.search.window,
					l: p.search && p.search.limit,
					n: p.search && p.search.loaded > (p.search.limit || 0) ? p.search.loaded : null,
				},
				dc: p.ui && p.ui.active_tab === 'documents' && p.documents ? {
					f: p.documents.filter, pp: p.documents.per_page, v: p.documents.visible,
				} : null,
				sc: {
					q: Array.isArray(p.scope && p.scope.queries)
						? p.scope.queries.map((r) => ({
							i: r && r.id,
							n: r && r.name,
							a: r && r.assignId,
							q: r && r.query,
							x: r && r.active === false ? 0 : 1,
						}))
						: [],
				},
				f: {
					f: p.frequency && p.frequency.field,
					ff: p.frequency && p.frequency.form_fields,
					l: p.frequency && p.frequency.limit,
					vm: p.frequency && p.frequency.viz_mode,
					ct: p.frequency && p.frequency.chart_type,
					cs: p.frequency && p.frequency.chart_scale,
					co: p.frequency && p.frequency.chart_order,
					ts: p.frequency && p.frequency.table_search,
					to: p.frequency && p.frequency.table_sort,
					m: p.frequency && p.frequency.compare_metric_cols,
				},
				c: {
					f: p.collocation && p.collocation.field,
					l: p.collocation && p.collocation.left,
					r: p.collocation && p.collocation.right,
					mf: p.collocation && p.collocation.min_freq,
					mi: p.collocation && p.collocation.max_items,
					sl: p.collocation && p.collocation.stoplist,
					mk: p.collocation && p.collocation.measure_keys,
					vm: p.collocation && p.collocation.viz_mode,
					cm: p.collocation && p.collocation.chart_metric,
				},
				// module states only when the link opens on that module
				d: p.ui && p.ui.stats_subtab === 'advanced_dcoll' && p.dcoll ? {
					a: p.dcoll.anchor_token, r: p.dcoll.relation, f: p.dcoll.field,
					mf: p.dcoll.min_freq, mi: p.dcoll.max_items, sl: p.dcoll.stoplist,
					mk: p.dcoll.measure_keys, vm: p.dcoll.viz_mode, cm: p.dcoll.chart_metric,
				} : null,
				k: p.ui && p.ui.stats_subtab === 'contrast' && p.contrast ? {
					f: p.contrast.fields, sf: p.contrast.score_field, vm: p.contrast.viz_mode,
					m: p.contrast.metric, vl: p.contrast.volcano_limit,
				} : null,
				o: {
					vm: p.other && p.other.viz_mode,
					x: p.other && p.other.chart_x_column,
					y: p.other && p.other.chart_y_column,
				},
				// maps state only when the link opens on Maps (keeps other links short)
				mp: p.ui && p.ui.stats_subtab === 'maps' && p.maps ? {
					vm: p.maps.viz_mode,
					mm: p.maps.map_mode,
					cm: p.maps.chart_mode,
					pv: p.maps.point_viz_mode,
					rm: p.maps.region_metric,
					co: p.maps.color_mode,
					cs: p.maps.compare_scale,
					ds: p.maps.dataset,
					ar: p.maps.area,
					al: p.maps.agg_level,
					cr: p.maps.custom_region_field,
					pl: p.maps.point_limit,
					ts: p.maps.table_search,
					to: p.maps.table_sort,
					v: p.maps.view,
				} : null,
			};
			return this._compactPruneValue(compact) || { s: 'fvs1' };
		},

		_expandVisualizationSnapshotPayload(payload) {
			const p = payload && typeof payload === 'object' ? payload : null;
			if (!p || p.s !== 'fvs1') return payload;
			const expanded = {
				schema: 'flexicorp-viz-snapshot',
				version: 1,
				created_at: new Date().toISOString(),
				settings: {
					backend: p.b && p.b.b ? String(p.b.b) : '',
					query_engine: p.b && p.b.e ? String(p.b.e) : '',
					query_language: p.b && p.b.l ? String(p.b.l) : '',
					corpus_format: p.b && p.b.f ? String(p.b.f) : '',
				},
				ui: {
					active_tab: p.u && p.u.t ? String(p.u.t) : '',
					stats_subtab: p.u && p.u.s ? String(p.u.s) : '',
				},
				search: {
					query: p.q && p.q.q ? String(p.q.q) : '',
					field: p.q && p.q.f ? String(p.q.f) : '',
					value: p.q && p.q.v ? String(p.q.v) : '',
					context_format: p.q && p.q.cf ? String(p.q.cf) : '',
					context_scope: p.q && p.q.cs ? String(p.q.cs) : '',
					view_mode: p.q && p.q.vm ? String(p.q.vm) : '',
					window: p.q && p.q.w != null ? Number(p.q.w) : null,
					limit: p.q && p.q.l != null ? Number(p.q.l) : null,
					loaded: p.q && p.q.n != null ? Number(p.q.n) : null,
				},
				documents: p.dc && typeof p.dc === 'object' ? {
					filter: p.dc.f ? String(p.dc.f) : '',
					per_page: p.dc.pp != null ? Number(p.dc.pp) : null,
					visible: p.dc.v != null ? Number(p.dc.v) : null,
				} : null,
				scope: {
					queries: Array.isArray(p.sc && p.sc.q)
						? p.sc.q.map((r, idx) => ({
							id: r && r.i ? String(r.i) : `q${idx + 1}`,
							name: r && r.n ? String(r.n) : '',
							assignId: r && r.a ? String(r.a) : '',
							query: r && r.q ? String(r.q) : '',
							active: !(r && Number(r.x) === 0),
						}))
						: [],
				},
				frequency: {
					field: p.f && p.f.f ? String(p.f.f) : '',
					form_fields: Array.isArray(p.f && p.f.ff) ? p.f.ff.map((x) => String(x)) : [],
					limit: p.f && p.f.l != null ? Number(p.f.l) : null,
					viz_mode: p.f && p.f.vm ? String(p.f.vm) : '',
					chart_type: p.f && p.f.ct ? String(p.f.ct) : '',
					chart_scale: p.f && p.f.cs ? String(p.f.cs) : '',
					chart_order: p.f && p.f.co ? String(p.f.co) : '',
					table_search: p.f && p.f.ts ? String(p.f.ts) : '',
					table_sort: p.f && p.f.to && typeof p.f.to === 'object' ? p.f.to : null,
					compare_metric_cols: Array.isArray(p.f && p.f.m) ? p.f.m.map((x) => String(x)) : [],
				},
				collocation: {
					field: p.c && p.c.f ? String(p.c.f) : '',
					left: p.c && p.c.l != null ? Number(p.c.l) : null,
					right: p.c && p.c.r != null ? Number(p.c.r) : null,
					min_freq: p.c && p.c.mf != null ? Number(p.c.mf) : null,
					max_items: p.c && p.c.mi != null ? Number(p.c.mi) : null,
					stoplist: p.c && p.c.sl != null ? Number(p.c.sl) : null,
					measure_keys: Array.isArray(p.c && p.c.mk) ? p.c.mk.map((x) => String(x)) : [],
					viz_mode: p.c && p.c.vm ? String(p.c.vm) : '',
					chart_metric: p.c && p.c.cm ? String(p.c.cm) : '',
				},
				dcoll: p.d && typeof p.d === 'object' ? {
					anchor_token: p.d.a ? String(p.d.a) : '',
					relation: p.d.r ? String(p.d.r) : '',
					field: p.d.f ? String(p.d.f) : '',
					min_freq: p.d.mf != null ? Number(p.d.mf) : null,
					max_items: p.d.mi != null ? Number(p.d.mi) : null,
					stoplist: p.d.sl != null ? Number(p.d.sl) : null,
					measure_keys: Array.isArray(p.d.mk) ? p.d.mk.map((x) => String(x)) : [],
					viz_mode: p.d.vm ? String(p.d.vm) : '',
					chart_metric: p.d.cm ? String(p.d.cm) : '',
				} : null,
				contrast: p.k && typeof p.k === 'object' ? {
					fields: Array.isArray(p.k.f) ? p.k.f.map((x) => String(x)) : [],
					score_field: p.k.sf ? String(p.k.sf) : '',
					viz_mode: p.k.vm ? String(p.k.vm) : '',
					metric: p.k.m ? String(p.k.m) : '',
					volcano_limit: p.k.vl != null ? Number(p.k.vl) : null,
				} : null,
				other: {
					viz_mode: p.o && p.o.vm ? String(p.o.vm) : '',
					chart_x_column: p.o && p.o.x ? String(p.o.x) : '',
					chart_y_column: p.o && p.o.y ? String(p.o.y) : '',
				},
				maps: p.mp && typeof p.mp === 'object' ? {
					viz_mode: p.mp.vm ? String(p.mp.vm) : '',
					map_mode: p.mp.mm ? String(p.mp.mm) : '',
					chart_mode: p.mp.cm ? String(p.mp.cm) : '',
					point_viz_mode: p.mp.pv ? String(p.mp.pv) : '',
					region_metric: p.mp.rm ? String(p.mp.rm) : '',
					color_mode: p.mp.co ? String(p.mp.co) : '',
					compare_scale: p.mp.cs ? String(p.mp.cs) : '',
					dataset: p.mp.ds ? String(p.mp.ds) : '',
					area: p.mp.ar ? String(p.mp.ar) : '',
					agg_level: p.mp.al ? String(p.mp.al) : '',
					custom_region_field: p.mp.cr ? String(p.mp.cr) : '',
					point_limit: p.mp.pl != null ? Number(p.mp.pl) : null,
					table_search: p.mp.ts ? String(p.mp.ts) : '',
					table_sort: p.mp.to && typeof p.mp.to === 'object' ? p.mp.to : null,
					view: p.mp.v && typeof p.mp.v === 'object' ? p.mp.v : null,
				} : null,
			};
			return expanded;
		},

		applyVisualizationSnapshotPayload(payload) {
			const p = payload && typeof payload === 'object' ? payload : null;
			if (!p) return false;
			const s = p.settings && typeof p.settings === 'object' ? p.settings : {};
			if (this.settings && typeof this.settings === 'object') {
				if (s.backend) this.settings.backend = String(s.backend);
				if (s.query_engine) this.settings.queryEngine = String(s.query_engine);
				if (s.query_language) this.settings.queryLanguage = String(s.query_language);
				if (s.corpus_format) this.settings.corpusFormat = String(s.corpus_format);
			}
			const ui = p.ui && typeof p.ui === 'object' ? p.ui : {};
			if (ui.active_tab) this.activeTab = String(ui.active_tab);
			if (ui.stats_subtab) this.statsSubTab = String(ui.stats_subtab);
			const search = p.search && typeof p.search === 'object' ? p.search : {};
			if (this.search && typeof this.search === 'object') {
				if (search.query != null) this.search.query = String(search.query);
				if (search.field != null) this.search.field = String(search.field);
				if (search.value != null) this.search.value = String(search.value);
				if (search.context_format != null) this.search.contextFormat = String(search.context_format);
				if (search.context_scope != null) this.search.contextScope = String(search.context_scope);
				if (search.view_mode != null) this.search.viewMode = String(search.view_mode);
				if (search.window != null && Number.isFinite(Number(search.window))) this.search.window = Number(search.window);
				if (search.limit != null && Number.isFinite(Number(search.limit))) this.search.limit = Number(search.limit);
				this._snapshotWantedLoaded = search.loaded != null && Number.isFinite(Number(search.loaded)) ? Number(search.loaded) : 0;
			}
			const docs = p.documents && typeof p.documents === 'object' ? p.documents : null;
			if (docs && this.documentsUi && typeof this.documentsUi === 'object') {
				if (docs.filter) this.documentsUi.filter = String(docs.filter);
				if (docs.per_page != null && Number.isFinite(docs.per_page)) this.documentsUi.perPage = docs.per_page;
				if (docs.visible != null && Number.isFinite(docs.visible)) this.documentsUi.visibleCount = docs.visible;
			}
			const scope = p.scope && typeof p.scope === 'object' ? p.scope : {};
			if (
				scope &&
				Array.isArray(scope.queries) &&
				this.statsSearchScopeStore &&
				Array.isArray(this.statsSearchScopeStore.queries)
			) {
				this.statsSearchScopeStore.queries = scope.queries.map((q, idx) => ({
					id: q && q.id ? String(q.id) : `q${idx + 1}`,
					name: q && q.name ? String(q.name) : '',
					assignId: q && q.assignId ? String(q.assignId) : '',
					query: q && q.query ? String(q.query) : '',
					active: !(q && q.active === false),
				}));
			}
			const freq = p.frequency && typeof p.frequency === 'object' ? p.frequency : {};
			if (this.frequency && typeof this.frequency === 'object') {
				if (freq.field != null) this.frequency.field = String(freq.field);
				if (Array.isArray(freq.form_fields)) this.frequency.formFields = freq.form_fields.map((x) => String(x));
				if (freq.limit != null && Number.isFinite(Number(freq.limit))) this.frequency.limit = Number(freq.limit);
			}
			if (freq.viz_mode != null && this.frequencyVizMode != null) this.frequencyVizMode = String(freq.viz_mode);
			if (freq.chart_type != null && this.frequencyChartType != null) this.frequencyChartType = String(freq.chart_type);
			if (freq.chart_scale != null && this.frequencyChartValueScale != null) this.frequencyChartValueScale = String(freq.chart_scale);
			if (freq.chart_order != null && this.frequencyChartOrder != null) this.frequencyChartOrder = String(freq.chart_order);
			if (freq.table_search != null && this.frequencyTableSearch != null) this.frequencyTableSearch = String(freq.table_search);
			if (freq.table_sort && typeof freq.table_sort === 'object' && this.frequencyTableSort && typeof this.frequencyTableSort === 'object') {
				this.frequencyTableSort = {
					col: typeof freq.table_sort.col === 'string' ? freq.table_sort.col : '',
					asc: !!freq.table_sort.asc,
				};
			}
			if (Array.isArray(freq.compare_metric_cols) && Array.isArray(this.frequencyCompareMetricCols)) {
				this.frequencyCompareMetricCols = freq.compare_metric_cols.map((x) => String(x));
			}
			const coll = p.collocation && typeof p.collocation === 'object' ? p.collocation : {};
			if (this.collocation && typeof this.collocation === 'object') {
				if (coll.field != null) this.collocation.field = String(coll.field);
				if (coll.anchor_token != null) this.collocation.anchorToken = String(coll.anchor_token);
				if (coll.left != null && Number.isFinite(Number(coll.left))) this.collocation.left = Number(coll.left);
				if (coll.right != null && Number.isFinite(Number(coll.right))) this.collocation.right = Number(coll.right);
				if (coll.min_freq != null && Number.isFinite(Number(coll.min_freq))) this.collocation.minFreq = Number(coll.min_freq);
				if (coll.max_items != null && Number.isFinite(Number(coll.max_items))) this.collocation.maxItems = Number(coll.max_items);
				if (coll.stoplist != null && Number.isFinite(Number(coll.stoplist))) this.collocation.stoplist = Number(coll.stoplist);
				if (Array.isArray(coll.measure_keys)) this.collocation.measureKeys = coll.measure_keys.map((x) => String(x));
			}
			if (coll.viz_mode != null && this.collocationVizMode != null) this.collocationVizMode = String(coll.viz_mode);
			if (coll.chart_metric && this.collocationChartMetricKey !== undefined) this.collocationChartMetricKey = String(coll.chart_metric);
			const dcoll = p.dcoll && typeof p.dcoll === 'object' ? p.dcoll : null;
			if (dcoll && this.dcollAdv && typeof this.dcollAdv === 'object') {
				if (dcoll.anchor_token) this.dcollAdv.anchorToken = String(dcoll.anchor_token);
				if (dcoll.relation) this.dcollAdv.relation = String(dcoll.relation);
				if (dcoll.field) this.dcollAdv.field = String(dcoll.field);
				if (dcoll.min_freq != null && Number.isFinite(Number(dcoll.min_freq))) this.dcollAdv.minFreq = Number(dcoll.min_freq);
				if (dcoll.max_items != null && Number.isFinite(Number(dcoll.max_items))) this.dcollAdv.maxItems = Number(dcoll.max_items);
				if (dcoll.stoplist != null && Number.isFinite(Number(dcoll.stoplist))) this.dcollAdv.stoplist = Number(dcoll.stoplist);
				if (Array.isArray(dcoll.measure_keys) && dcoll.measure_keys.length) this.dcollAdv.measureKeys = dcoll.measure_keys.map((x) => String(x));
				if (dcoll.viz_mode) this.dcollAdvVizMode = String(dcoll.viz_mode);
				if (dcoll.chart_metric) this.dcollAdvChartMetricKey = String(dcoll.chart_metric);
			}
			const contrast = p.contrast && typeof p.contrast === 'object' ? p.contrast : null;
			if (contrast && this.afContrastVizMode !== undefined) {
				if (Array.isArray(contrast.fields) && contrast.fields.length) {
					this.afKeynessFields = contrast.fields.map((x) => String(x));
					this.afKeynessField = this.afKeynessFields[0];
				}
				if (contrast.score_field) this.afKeynessScoreField = String(contrast.score_field);
				if (contrast.viz_mode) this.afContrastVizMode = String(contrast.viz_mode);
				if (contrast.metric) this.afContrastMetric = String(contrast.metric);
				if (contrast.volcano_limit != null && Number.isFinite(Number(contrast.volcano_limit))) this.afContrastVolcanoPointLimit = Number(contrast.volcano_limit);
			}
			const other = p.other && typeof p.other === 'object' ? p.other : {};
			if (other.viz_mode != null && this.otherVizMode != null) this.otherVizMode = String(other.viz_mode);
			if (other.chart_x_column != null && this.otherChartXColumn != null) this.otherChartXColumn = String(other.chart_x_column);
			if (other.chart_y_column != null && this.otherChartYColumn != null) this.otherChartYColumn = String(other.chart_y_column);
			const maps = p.maps && typeof p.maps === 'object' ? p.maps : null;
			if (maps && this.mapsVizMode != null) {
				if (maps.viz_mode) this.mapsVizMode = String(maps.viz_mode);
				if (maps.map_mode) this.mapsMapMode = maps.map_mode === 'regions' ? 'regions' : 'points';
				if (maps.chart_mode) this.mapsChartMode = String(maps.chart_mode);
				if (maps.point_viz_mode) this.mapsPointVizMode = String(maps.point_viz_mode);
				if (maps.region_metric) this.mapsRegionMetric = String(maps.region_metric);
				if (maps.color_mode) this.mapsRegionColorMode = String(maps.color_mode);
				if (maps.compare_scale) this.mapsCompareScaleMode = String(maps.compare_scale);
				if (maps.dataset) this.mapsBoundaryDatasetKey = String(maps.dataset);
				if (maps.area) this.mapsSelectedAreaKey = String(maps.area);
				if (maps.agg_level) this.geoAggLevel = String(maps.agg_level);
				if (maps.custom_region_field) this.geoCustomRegionField = String(maps.custom_region_field);
				if (maps.point_limit != null && Number.isFinite(Number(maps.point_limit))) this.mapsPointLimit = Number(maps.point_limit);
				if (maps.table_search) this.mapsTableSearch = String(maps.table_search);
				if (maps.table_sort && typeof maps.table_sort === 'object') {
					this.mapsTableSort = { col: String(maps.table_sort.col || 'count'), asc: !!maps.table_sort.asc };
				}
				if (maps.view && Array.isArray(maps.view.center)) this.mapsPendingView = { center: maps.view.center.map(Number), zoom: Number(maps.view.zoom) };
			}
			this.ensureStatsSubTabAllowed();
			// eslint-disable-next-line no-console
			console.log('[flexicorp][viz] applied snapshot state', {
				backend: this.settings && this.settings.backend,
				queryEngine: this.settings && this.settings.queryEngine,
				queryLanguage: this.settings && this.settings.queryLanguage,
				corpusFormat: this.settings && this.settings.corpusFormat,
				activeTab: this.activeTab,
				statsSubTab: this.statsSubTab,
				searchQuery: this.search && this.search.query,
				scopeQueries: this.statsSearchScopeStore && Array.isArray(this.statsSearchScopeStore.queries)
					? this.statsSearchScopeStore.queries
					: [],
				frequency: {
					field: this.frequency && this.frequency.field,
					formFields: this.frequency && this.frequency.formFields,
					vizMode: this.frequencyVizMode,
					chartScale: this.frequencyChartValueScale,
					chartOrder: this.frequencyChartOrder,
					compareMetricCols: this.frequencyCompareMetricCols,
				},
			});
			return true;
		},

		buildVisualizationSnapshotUrl() {
			const payload = this.buildVisualizationSnapshotPayload();
			const compactPayload = this._compactVisualizationSnapshotPayload(payload);
			const encoded = this._base64UrlEncodeUnicode(JSON.stringify(payload));
			const compactEncoded = this._base64UrlEncodeUnicode(JSON.stringify(compactPayload));
			const chosen = compactEncoded && compactEncoded.length < encoded.length ? compactEncoded : encoded;
			if (!encoded) return '';
			const cur = new URL(window.location.href);
			const u = new URL(cur.origin + cur.pathname);
			const actionParam = (cur.searchParams.get('action') || '').trim();
			u.searchParams.set('action', actionParam || 'flexicorp');
			u.searchParams.set('viz', chosen);
			// eslint-disable-next-line no-console
			console.log('[flexicorp][viz] snapshot sizes', {
				full: encoded.length,
				compact: compactEncoded ? compactEncoded.length : 0,
				chosen: chosen.length,
			});
			return u.toString();
		},

		shareVisualizationSnapshot() {
			const url = this.buildVisualizationSnapshotUrl();
			if (!url) {
				window.alert('Could not build visualization URL.');
				return;
			}
			this.visualizationShareUrl = url;
			this.visualizationShareStatus = '';
			if (this.activeTab === 'frequency') {
				this.visualizationShareOpen = true;
				return;
			}
			if (this.canNativeShareVisualization()) {
				void this.nativeShareVisualization();
				return;
			}
			void this.copyVisualizationShareUrl();
		},

		closeVisualizationShare() {
			this.visualizationShareOpen = false;
			this.visualizationShareStatus = '';
		},

		canNativeShareVisualization() {
			return !!(navigator && typeof navigator.share === 'function');
		},

		async copyVisualizationShareUrl() {
			const url = this.visualizationShareUrl || this.buildVisualizationSnapshotUrl();
			if (!url) {
				this.visualizationShareStatus = 'Could not build visualization URL.';
				return;
			}
			this.visualizationShareUrl = url;
			if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
				try {
					await navigator.clipboard.writeText(url);
					this.visualizationShareStatus = 'Link copied.';
					return;
				} catch (_) {
					/* fallback below */
				}
			}
			window.prompt('Copy visualization URL', url);
			this.visualizationShareStatus = 'Copy dialog opened.';
		},

		async nativeShareVisualization() {
			const url = this.visualizationShareUrl || this.buildVisualizationSnapshotUrl();
			if (!url) {
				this.visualizationShareStatus = 'Could not build visualization URL.';
				return;
			}
			this.visualizationShareUrl = url;
			if (!this.canNativeShareVisualization()) {
				this.visualizationShareStatus = 'Native share is not available in this browser.';
				return;
			}
			try {
				await navigator.share({
					title: 'flexicorp visualization',
					text: 'Shared flexicorp visualization',
					url,
				});
				this.visualizationShareStatus = 'Shared.';
			} catch (_) {
				// User-cancel is fine; keep silent-ish.
				this.visualizationShareStatus = 'Share canceled.';
			}
		},

		emailVisualizationShare() {
			const url = this.visualizationShareUrl || this.buildVisualizationSnapshotUrl();
			if (!url) {
				this.visualizationShareStatus = 'Could not build visualization URL.';
				return;
			}
			this.visualizationShareUrl = url;
			const subject = encodeURIComponent('flexicorp visualization');
			const body = encodeURIComponent(url);
			window.location.href = `mailto:?subject=${subject}&body=${body}`;
		},

		searchViewModeShareEligible() {
			const mode = this.normalizeSearchViewMode(this.search && this.search.viewMode);
			return mode === 'kwic' || mode === 'grouped' || mode === 'anchor_kwic' || mode === 'table';
		},

		canShareSearchSnapshot() {
			if (this.activeTab !== 'search') return false;
			if (!this.search || !this.search.ran) return false;
			return this.searchViewModeShareEligible();
		},

		/** @deprecated Share lives in the Search result title row (template); kept as no-ops for init callers. */
		ensureSearchShareButton() {},
		updateSearchShareButtonVisibility() {},

		loadVisualizationSnapshotFromPrompt() {
			const raw = window.prompt('Paste visualization URL (or viz token)');
			if (!raw) return;
			const token = this.extractVisualizationTokenFromText(raw);
			if (!token) {
				window.alert('No viz token found.');
				return;
			}
			const decoded = this._base64UrlDecodeUnicode(token);
			if (!decoded) {
				window.alert('Could not decode viz token.');
				return;
			}
			let payload = null;
			try {
				payload = JSON.parse(decoded);
			} catch (_) {
				payload = null;
			}
			payload = this._expandVisualizationSnapshotPayload(payload);
			// eslint-disable-next-line no-console
			console.log('[flexicorp][viz] decoded snapshot payload (prompt)', payload);
			if (!payload || !this.applyVisualizationSnapshotPayload(payload)) {
				window.alert('Could not apply visualization snapshot.');
				return;
			}
			this.runVisualizationSnapshotQuery();
			window.alert('Visualization snapshot loaded. Query execution started.');
		},

		extractVisualizationTokenFromText(raw) {
			const s = String(raw || '').trim();
			if (!s) return '';
			try {
				const u = new URL(s);
				const q = u.searchParams.get('viz');
				if (q && q.trim()) return q.trim();
				if (u.hash && u.hash.startsWith('#viz=')) return u.hash.slice(5).trim();
			} catch (_) {
				/* not a URL, continue */
			}
			if (s.startsWith('viz=')) return s.slice(4).trim();
			return s;
		},

		loadVisualizationSnapshotFromLocation() {
			try {
				const u = new URL(window.location.href);
				let token = (u.searchParams.get('viz') || '').trim();
				if (!token && u.hash && u.hash.startsWith('#viz=')) {
					token = u.hash.slice(5).trim();
				}
				if (!token) return false;
				const decoded = this._base64UrlDecodeUnicode(token);
				if (!decoded) return false;
				let payload = JSON.parse(decoded);
				payload = this._expandVisualizationSnapshotPayload(payload);
				// eslint-disable-next-line no-console
				console.log('[flexicorp][viz] decoded snapshot payload (url)', payload);
				const ok = this.applyVisualizationSnapshotPayload(payload);
				if (ok) this.runVisualizationSnapshotQuery();
				return ok;
			} catch (_) {
				return false;
			}
		},

		runVisualizationSnapshotQuery() {
			const q = this.search && typeof this.search.query === 'string' ? this.search.query.trim() : '';
			const v = this.search && typeof this.search.value === 'string' ? this.search.value.trim() : '';
			if (!q && !v) return false;
			// The search answer switches to the Search tab; a link to Stats (Maps, Frequency, …)
			// goes back there once the hits are in, since Stats subtabs need hits.
			const wantedTab = String(this.activeTab || '').trim();
			const wantedSub = String(this.statsSubTab || '').trim();
			// eslint-disable-next-line no-console
			const vlog = (...args) => console.log('[flexicorp][viz]', ...args);
			const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
			const loadMore = async () => {
				// as many hits as were on screen ("Show more" pages), at most 20 extra requests
				const want = Number(this._snapshotWantedLoaded || 0);
				this._snapshotWantedLoaded = 0;
				for (let i = 0; i < 20 && want > this.searchLoadedItemCount(); i += 1) {
					const before = this.searchLoadedItemCount();
					await this.submitSearchRequest({ append: true });
					if (this.searchLoadedItemCount() <= before) break;
				}
			};
			// Stats subtabs need hits; any search in flight (also one the page started itself) must
			// have landed first.
			const waitForHits = async () => {
				for (let i = 0; i < 120; i += 1) {
					if (!this.isLoading('search') && typeof this.statsSearchHasHits === 'function' && this.statsSearchHasHits()) return true;
					await sleep(250);
				}
				return false;
			};
			const reopen = async () => {
				if (!wantedTab || wantedTab === 'search') return;
				// subtab first, so the Stats tab does not open (and load) Corpus stats
				if (wantedTab === 'frequency' && wantedSub) this.statsSubTab = wantedSub;
				if (typeof this.setTab === 'function') this.setTab(wantedTab);
				if (wantedTab !== 'frequency' || !wantedSub) return;
				for (let i = 0; i < 3 && this.statsSubTab !== wantedSub; i += 1) {
					if (typeof this.setStatsSubTab === 'function') this.setStatsSubTab(wantedSub);
					if (this.statsSubTab !== wantedSub) await sleep(300);
				}
				if (typeof this.setStatsSubTab === 'function') this.setStatsSubTab(wantedSub);   // also schedules the subtab's loaders
				vlog('reopen: tab', this.activeTab, 'subtab', this.statsSubTab, '(wanted', wantedTab, wantedSub + ')');
				if (this.statsSubTab !== wantedSub) {
					// eslint-disable-next-line no-console
					console.warn('[flexicorp][viz] could not reopen Stats subtab', wantedSub, 'now on', this.statsSubTab,
						'hits:', typeof this.statsSearchHasHits === 'function' ? this.statsSearchHasHits() : '?',
						'modules:', typeof this.availableStatsModules === 'function' ? this.availableStatsModules().map((m) => m.id) : '?');
					return;
				}
				// the analysis the link was made from, with the restored settings
				if (wantedSub === 'freq' && typeof this.submitFrequencyFromButton === 'function') this.submitFrequencyFromButton();
				else if (wantedSub === 'coll' && typeof this.submitCollocationFromButton === 'function') this.submitCollocationFromButton();
				else if (wantedSub === 'advanced_dcoll' && typeof this.submitDcollAdvRun === 'function') this.submitDcollAdvRun();
				else if (wantedSub === 'contrast' && typeof this.submitAfKeynessRun === 'function' && !(typeof this.afKeynessRunDisabled === 'function' && this.afKeynessRunDisabled())) this.submitAfKeynessRun();
				if (wantedSub === 'maps' && typeof this.setMapsVizMode === 'function') {
					const mapMode = this.mapsMapMode;
					this.setMapsVizMode(this.mapsVizMode);
					if (this.mapsVizMode === 'map' && typeof this.setMapsMapMode === 'function') this.setMapsMapMode(mapMode);
				}
			};
			setTimeout(async () => {
				try {
					if (this.isLoading('search')) {
						vlog('restore: a search is already running; waiting for it');
					} else {
						vlog('restore: running the search');
						await this.submitSearchRequest({ append: false });
					}
					const hits = await waitForHits();
					vlog('restore: hits', hits, 'loaded', this.searchLoadedItemCount());
					await loadMore();
					await reopen();
				} catch (err) {
					// eslint-disable-next-line no-console
					console.warn('[flexicorp][viz] restore failed', err);
				}
			}, 0);
			return true;
		},

		/** Parse a JSON blob from storage; never throws. */
		_parseFlexicorpSelectionSnapshot(raw) {
			if (!raw) return null;
			try {
				const s = JSON.parse(raw);
				return s && typeof s === 'object' ? s : null;
			} catch (_) {
				return null;
			}
		},

		/** Last known selection: sessionStorage, then localStorage (private mode / blocked session), then current URL. */
		readFlexicorpSelectionSnapshot() {
			const key = this._flexicorpSelectionStorageKey;
			try {
				const s = this._parseFlexicorpSelectionSnapshot(sessionStorage.getItem(key));
				if (s) return s;
			} catch (_) {}
			try {
				const s = this._parseFlexicorpSelectionSnapshot(localStorage.getItem(key));
				if (s) return s;
			} catch (_) {}
			return this.flexicorpSelectionSnapshotFromUrl();
		},

		flexicorpSelectionSnapshotFromUrl() {
			try {
				const url = new URL(window.location.href);
				const qe = (url.searchParams.get('query_engine') || '').trim();
				const be = (url.searchParams.get('backend') || '').trim();
				const ql = (url.searchParams.get('query_language') || '').trim();
				const cf = (url.searchParams.get('corpus_format') || '').trim();
				if (!qe && !be && !ql && !cf) return null;
				return {
					backend: be,
					queryEngine: qe,
					queryLanguage: ql,
					corpusFormat: cf,
				};
			} catch (_) {
				return null;
			}
		},

		/** Persists to PHP $_SESSION['flexicorp_teitok'] (works even when browser storage is blocked). */
		saveFlexicorpSelectionToServer() {
			try {
				if (!this.settings || typeof this.settings !== 'object') return;
				const signature = JSON.stringify({
					backend: this.settings.backend != null ? String(this.settings.backend) : '',
					queryEngine: this.settings.queryEngine != null ? String(this.settings.queryEngine) : '',
					queryLanguage: this.settings.queryLanguage != null ? String(this.settings.queryLanguage) : '',
					corpusFormat: this.settings.corpusFormat != null ? String(this.settings.corpusFormat) : '',
				});
				if (this._saveSelectionInFlight && this._saveSelectionInFlight === signature) return;
				if (this._lastSavedSelectionSignature && this._lastSavedSelectionSignature === signature) return;
				this._saveSelectionInFlight = signature;
				const fd = new FormData();
				fd.set('ajax', '1');
				fd.set('save_selection', '1');
				fd.set('action', this.action || 'flexicorp');
				fd.set('backend', this.settings.backend != null ? String(this.settings.backend) : '');
				fd.set('query_engine', this.settings.queryEngine != null ? String(this.settings.queryEngine) : '');
				fd.set('query_language', this.settings.queryLanguage != null ? String(this.settings.queryLanguage) : '');
				fd.set('corpus_format', this.settings.corpusFormat != null ? String(this.settings.corpusFormat) : '');
				const url = this.getRequestUrl();
				void fetch(url, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				})
					.then((resp) => {
						if (resp && resp.ok) this._lastSavedSelectionSignature = signature;
					})
					.catch(() => {})
					.finally(() => {
						if (this._saveSelectionInFlight === signature) this._saveSelectionInFlight = '';
					});
			} catch (_) {}
		},

		persistFlexicorpSelectionToSessionStorage() {
			try {
				if (!this.settings || typeof this.settings !== 'object') return;
				const key = this._flexicorpSelectionStorageKey;
				const json = JSON.stringify({
					backend: this.settings.backend,
					queryEngine: this.settings.queryEngine,
					queryLanguage: this.settings.queryLanguage,
					corpusFormat: this.settings.corpusFormat,
				});
				try {
					sessionStorage.setItem(key, json);
				} catch (_) {}
				try {
					localStorage.setItem(key, json);
				} catch (_) {}
				this.saveFlexicorpSelectionToServer();
			} catch (_) {}
		},

		applyStoredFlexicorpSelection() {
			try {
				const s = this.readFlexicorpSelectionSnapshot();
				if (!s) return;
				const snapBackend = s.backend ? String(s.backend) : '';
				let eng = s.queryEngine ? String(s.queryEngine) : '';
				if (!eng && (snapBackend === 'pando' || snapBackend === 'flexicorp-pando')) eng = 'pando';
				const be = this.settings && this.settings.backend ? String(this.settings.backend) : '';
				const srvEng = this.settings && this.settings.queryEngine ? String(this.settings.queryEngine) : '';
				// Stored Pando selection wins over a server-side CQP default.
				if (eng === 'pando' && (be === 'cqp' || be === 'flexi' || be === '')) {
					this.settings.backend = 'pando';
					this.settings.queryEngine = 'pando';
					this.settings.queryLanguage = s.queryLanguage ? String(s.queryLanguage) : 'pando-cql';
					this.settings.corpusFormat = s.corpusFormat ? String(s.corpusFormat) : 'pando';
					return;
				}
				// Prefer native manatee backend — do not route through deprecated flexi.
				if (eng === 'manatee' && (be === 'cqp' || be === 'flexi' || be === '')) {
					this.settings.backend = 'manatee';
					this.settings.queryEngine = 'manatee';
					this.settings.queryLanguage = s.queryLanguage ? String(s.queryLanguage) : 'manatee-cql';
					this.settings.corpusFormat = s.corpusFormat ? String(s.corpusFormat) : 'manatee';
					return;
				}
				// Stale flexi+pando snapshot → real pando backend.
				if ((be === 'flexi' || be === 'pando') && srvEng === 'cqp' && eng === 'pando') {
					this.settings.backend = 'pando';
					this.settings.queryEngine = 'pando';
					this.settings.queryLanguage = s.queryLanguage ? String(s.queryLanguage) : 'pando-cql';
					this.settings.corpusFormat = s.corpusFormat ? String(s.corpusFormat) : 'pando';
				}
				// Drop deprecated flexi sticky when the server already chose a primary engine.
				if (snapBackend === 'flexi' && (be === 'pando' || be === 'cqp' || be === 'manatee')) {
					return;
				}
			} catch (_) {}
		},

		/** Keep backend / engine / dialect in the URL so force-reload matches session (belt-and-suspenders). */
		syncFlexicorpSelectionToUrl() {
			try {
				const url = new URL(window.location.href);
				if (this.activeTab) url.searchParams.set('active_tab', this.activeTab);
				if (this.settings && this.settings.backend) url.searchParams.set('backend', this.settings.backend);
				if (this.settings && this.settings.queryLanguage) url.searchParams.set('query_language', this.settings.queryLanguage);
				if (this.settings && this.settings.corpusFormat) url.searchParams.set('corpus_format', this.settings.corpusFormat);
				if (
					this.settings &&
					this.settings.queryEngine &&
					(this.settings.backend === 'flexi' ||
						this.settings.backend === 'pando' ||
						this.settings.backend === 'flexicorp-pando')
				) {
					url.searchParams.set('query_engine', this.settings.queryEngine);
				}
				window.history.replaceState({}, '', url.toString());
			} catch (_) {}
		},

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

		getBackendLabel(backend) {
			const item = this.backendOptions[backend];
			return item ? item.label : (backend || '');
		},

		getBackendDisplayLabel(backend) {
			const key = String(backend || '').trim();
			if (!key) return '';
			const keyLc = key.toLowerCase();
			const defaults = {
				pmltq: 'PML Tree Query',
				pando: 'Pando',
				'flexicorp-pando': 'Pando',
				cqp: 'CWB/CQP',
				manatee: 'Manatee',
				blacklab: 'BlackLab',
				clickhouse: 'ClickHouse',
				clickql: 'ClickQL',
			};
			const map = this.backendDisplayNames && typeof this.backendDisplayNames === 'object'
				? this.backendDisplayNames
				: {};
			if (map[key] != null && String(map[key]).trim() !== '') return String(map[key]).trim();
			if (map[keyLc] != null && String(map[keyLc]).trim() !== '') return String(map[keyLc]).trim();
			if (defaults[keyLc]) return defaults[keyLc];
			return this.getBackendLabel(key);
		},

		getBackendDescription(backend) {
			const item = this.backendOptions[backend];
			return item ? item.description : '';
		},

		getQueryLanguageHelpUrl(queryLanguage) {
			if (!queryLanguage) return '';
			const key = String(queryLanguage || '').toLowerCase();
			const map = this.queryLanguageHelpDocs || {};
			const action = typeof map[key] === 'string' ? map[key] : '';
			return action ? `index.php?action=${encodeURIComponent(action)}` : '';
		},

		/** Build href for combo help_url: flexicorp docs (when set) or TEITOK action. */
		helpHref(helpUrl) {
			if (!helpUrl) return '#';
			// Doc path (e.g. install-manatee-bindings.md) → flexicorp docs base or relative docs/
			if (String(helpUrl).indexOf('install-manatee-bindings') !== -1 || helpUrl.endsWith('.md')) {
				const base = (this.flexicorpDocsUrl || '').replace(/\/$/, '');
				return base ? base + '/' + helpUrl : 'docs/' + helpUrl;
			}
			return 'index.php?action=' + encodeURIComponent(helpUrl);
		},

		getExecutionEngineLabel(engine) {
			if (engine && String(engine).trim()) return String(engine).trim();
			if (this.settings && this.settings.queryEngine && String(this.settings.queryEngine).trim()) {
				return String(this.settings.queryEngine).trim();
			}
			if (this.settings && this.settings.corpusFormat && String(this.settings.corpusFormat).trim()) {
				return String(this.settings.corpusFormat).trim();
			}
			return '';
		},

		/** True when the active execution path is CWB/CQP (not Pando, Manatee, etc.). */
		isCwbBackend(settings) {
			const s = settings || this.settings || {};
			const backend = String(s.backend || '').toLowerCase();
			const ql = String(s.queryLanguage || '').toLowerCase();
			const cf = String(s.corpusFormat || '').toLowerCase();
			if (backend === 'cqp') return true;
			if (
				backend === 'flexi' &&
				(ql === 'cwb-cql' || ql === 'cwb' || ql === 'cql' || cf === 'cwb')
			) {
				return true;
			}
			return false;
		},

		/** Human-friendly title for the currently selected search tool (backend + query language). */
		currentToolTitle() {
			if (!this.settings) return 'Corpus search';
			const backend = String(this.settings.backend || '').trim();
			const label = this.getBackendDisplayLabel(backend);
			return label || 'Corpus search';
		},

		queryLanguageShort(code) {
			if (!code) return '';
			const key = String(code).toLowerCase();
			const map = this.queryLanguageOptions || {};
			const meta = map[key];
			return meta && meta.short ? meta.short : code;
		},

		getQueryLanguageHelpTitle(queryLanguage, engine) {
			const ql = String(queryLanguage || '').trim();
			const resolvedEngine = this.getExecutionEngineLabel(engine);
			if (!ql && !resolvedEngine) return '';
			if (ql && resolvedEngine) return `The ${ql} query will be executed on the ${resolvedEngine} database.`;
			if (ql) return `Help for ${ql}.`;
			return `Queries will be executed on ${resolvedEngine}.`;
		},

		/** Backend name to pass as reindex_backends (CLI expects cqp/manatee/clickhouse, not corpusFormat cwb). */
		reindexBackendForCombo(combo) {
			if (!combo) return combo && combo.backend;
			return (combo.corpusFormat === 'cwb' ? 'cqp' : (combo.corpusFormat || combo.backend));
		},

		selectedReindexBackends() {
			const list = this.backendCombinationList();
			return [...new Set(
				list
					.filter((c) => c && this.reindexSelection[c.id] && this.canReindexBackend(c))
					.map((c) => this.reindexBackendForCombo(c))
			)];
		},

		/** Build a list of logical (backend, query-language, corpus-format) combinations from state.backendCombos. */
		backendCombinationList() {
			const combos = Array.isArray(this.backendCombos) ? this.backendCombos : [];
			return combos.map((c) => {
				const isCurrent =
					this.settings &&
					this.settings.backend === c.backend &&
					this.settings.queryLanguage === c.queryLanguage &&
					this.settings.corpusFormat === c.corpusFormat;
				return {
					...(c && typeof c === 'object' ? c : {}),
					id: c.id,
					backend: c.backend,
					queryLanguage: c.queryLanguage,
					corpusFormat: c.corpusFormat,
					available: !!c.available,
					reason: c.reason || '',
					isCurrent,
				};
			});
		},

		/** Current (backend, query language, corpus format) row from backendCombos; used for engine-specific Stats tabs. */
		currentBackendComboRecord() {
			const combos = this.backendCombinationList();
			let hit = combos.find((c) => c && c.isCurrent);
			// flexi+pando-cql+pando often resolves to backend=pando server-side, but the client can
			// briefly have flexi + pando dialect with no matching combo id → hide Collocations / caps.
			if (!hit && this.settings) {
				const ql = this.settings.queryLanguage;
				const cf = this.settings.corpusFormat;
				hit = combos.find(
					(c) => c && c.available && c.queryLanguage === ql && c.corpusFormat === cf,
				);
			}
			if (!hit && this.settings && this.settings.queryEngine === 'pando') {
				hit = combos.find((c) => c && c.available && c.id === 'pando:pando-cql:pando');
			}
			return hit || null;
		},

		_statsCap(name) {
			const c = this.currentBackendComboRecord();
			const cap = c && c.capabilities && typeof c.capabilities === 'object' ? c.capabilities : {};
			return !!cap[name];
		},

		statsCapabilityKeyness() {
			return this._statsCap('stats_keyness');
		},

		/** Context object passed to Stats module `isAvailable()` (maps, contrast, …). */
		statsModuleInstallContext() {
			const c = typeof this.currentBackendComboRecord === 'function' ? this.currentBackendComboRecord() : null;
			const cap = c && c.capabilities && typeof c.capabilities === 'object' ? c.capabilities : {};
			const hasGeo = !!(cap && cap.hasGeo);
			let hasDeps = false;
			try {
				const isDeprel = (s) => String(s || '').trim().toLowerCase() === 'deprel';
				const catalog = this.attributeCatalog && typeof this.attributeCatalog === 'object'
					? this.attributeCatalog
					: {};
				const catLabels = catalog.searchable_labels && typeof catalog.searchable_labels === 'object'
					? catalog.searchable_labels
					: {};
				const catDisplay = catalog.searchable_display_labels && typeof catalog.searchable_display_labels === 'object'
					? catalog.searchable_display_labels
					: {};
				const catalogHasDeprel =
					Object.prototype.hasOwnProperty.call(catLabels, 'deprel')
					|| Object.prototype.hasOwnProperty.call(catDisplay, 'deprel');
				const infoResult = this.info && this.info.result && typeof this.info.result === 'object'
					? this.info.result
					: null;
				if (infoResult) {
					const scanList = (arr) => {
						if (!Array.isArray(arr)) return false;
						for (let i = 0; i < arr.length; i += 1) {
							const v = arr[i];
							if (typeof v === 'string' && isDeprel(v)) return true;
							if (v && typeof v === 'object' && (isDeprel(v.name) || isDeprel(v.id) || isDeprel(v.key))) return true;
						}
						return false;
					};
					const scanAssocKeys = (obj) => {
						if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return false;
						const keys = Object.keys(obj);
						for (let i = 0; i < keys.length; i += 1) {
							if (isDeprel(keys[i])) return true;
						}
						return false;
					};
					// Strict gating: only trust current corpus info payload.
					// Do not use frequency.fieldLabels here (can be stale/cross-corpus).
					hasDeps = !!(
						scanList(infoResult.attributes)
						|| scanList(infoResult.pattributes)
						|| scanList(infoResult.positional_attributes)
						|| scanAssocKeys(infoResult.pattributes)
						|| scanAssocKeys(infoResult.positional_attributes)
					);
				}
				if (!hasDeps && catalogHasDeprel) hasDeps = true;
			} catch (_) {}

			// Generic scope-derived info for module gating (e.g. Contrast needs 2 active named queries).
			// Also include last computed operation from server-hydrated state so modules
			// can make the tab visible even if scope gating would otherwise hide it.
			let operation = '';
			try {
				if (this.other && typeof this.other.operation === 'string' && this.other.operation.trim()) {
					operation = this.other.operation;
				} else if (this.frequency && typeof this.frequency.operation === 'string' && this.frequency.operation.trim()) {
					operation = this.frequency.operation;
				} else if (this.collocation && typeof this.collocation.operation === 'string' && this.collocation.operation.trim()) {
					operation = this.collocation.operation;
				}
			} catch (_) {}

			let activeNamedQueryCount = 0;
			try {
				const ss = (window && window.ttFlexicorpFns && window.ttFlexicorpFns.searchScope)
					? window.ttFlexicorpFns.searchScope
					: null;
				const store = this.statsSearchScopeStore;
				if (ss && store && Array.isArray(store.queries)) {
					activeNamedQueryCount = ss.activeNamedAssignIds(store.queries).length;
				}
			} catch (_) {}

			return {
				backend: String((this.settings && this.settings.backend) || '').trim(),
				queryLanguage: String((this.settings && this.settings.queryLanguage) || '').trim(),
				corpusFormat: String((this.settings && this.settings.corpusFormat) || '').trim(),
				queryEngine: String((this.settings && this.settings.queryEngine) || '').trim(),
				capabilities: cap,
				hasGeo,
				hasDeps,
				operation,
				activeNamedQueryCount,
			};
		},

		registerStatsModule(definition) {
			if (!definition || typeof definition !== 'object') return;
			const id = String(definition.id || '').trim();
			if (!id) return;
			const label = String(definition.label || id).trim();
			const description = String(definition.description || '').trim();
			const rawOps =
				Array.isArray(definition.ingestsOperations)
					? definition.ingestsOperations
					: (Array.isArray(definition.operations)
						? definition.operations
						: (Array.isArray(definition.handlesOperations) ? definition.handlesOperations : []));
			const ops = Array.isArray(rawOps)
				? rawOps
					.map((o) => (o == null ? '' : String(o)))
					.map((s) => s.trim().toLowerCase())
					.filter(Boolean)
				: [];
			const isAvailable =
				typeof definition.isAvailable === 'function' ? definition.isAvailable : () => true;
			const reg = Array.isArray(this.statsModuleRegistry) ? this.statsModuleRegistry : [];
			const idx = reg.findIndex((m) => m && m.id === id);
			const entry = { id, label, description, isAvailable, operations: ops };
			if (idx >= 0) reg[idx] = entry;
			else reg.push(entry);
			this.statsModuleRegistry = reg;
		},

		statsOperationFromState(state) {
			if (!state || typeof state !== 'object') return '';
			const otherOp = state.other && typeof state.other.operation === 'string' ? state.other.operation : '';
			if (otherOp.trim()) return otherOp;
			const freqOp = state.frequency && typeof state.frequency.operation === 'string' ? state.frequency.operation : '';
			if (freqOp.trim()) return freqOp;
			const collOp = state.collocation && typeof state.collocation.operation === 'string' ? state.collocation.operation : '';
			if (collOp.trim()) return collOp;
			return '';
		},

		statsSubTabForOperationName(operationName) {
			const op = operationName == null ? '' : String(operationName).trim().toLowerCase();
			if (!op) return '';
			// Built-in panels.
			if (op === 'freq' || op === 'group') return 'freq';
			if (op === 'coll') return 'coll';
			// Module panels.
			const reg = Array.isArray(this.statsModuleRegistry) ? this.statsModuleRegistry : [];
			for (let i = 0; i < reg.length; i += 1) {
				const m = reg[i];
				if (!m || !m.id) continue;
				const ops = Array.isArray(m.operations) ? m.operations : [];
				if (ops.includes(op)) return m.id;
			}
			// Anything else: raw table under "Other".
			return 'other';
		},

		availableStatsModules() {
			const ctx = this.statsModuleInstallContext();
			const reg = Array.isArray(this.statsModuleRegistry) ? this.statsModuleRegistry : [];
			return reg.filter((m) => {
				if (!m || !m.id) return false;
				try {
					return !!m.isAvailable(ctx);
				} catch (_) {
					return false;
				}
			});
		},

		activeStatsModule() {
			const id = String(this.statsSubTab || '').trim();
			if (!id) return null;
			const mods = this.availableStatsModules();
			return mods.find((m) => m.id === id) || null;
		},

		/**
		 * Run shared module installers (same hook as advanced_freqs) against the main flexicorp app.
		 * Each installer may Object.assign methods/state and register a subtab via registerStatsModule.
		 */
		installFlexicorpStatsModuleExtensions() {
			if (this._statsModuleInstallersRan) return;
			this._statsModuleInstallersRan = true;
			if (!Array.isArray(this.statsModuleRegistry)) this.statsModuleRegistry = [];
			const a = Array.isArray(window.ttFlexicorpModuleInstallers) ? window.ttFlexicorpModuleInstallers : [];
			const b = Array.isArray(window.ttAdvancedFreqsModuleInstallers) ? window.ttAdvancedFreqsModuleInstallers : [];
			const seen = new Set();
			const installers = [];
			for (let j = 0; j < a.length; j += 1) {
				const fn = a[j];
				if (typeof fn !== 'function' || seen.has(fn)) continue;
				seen.add(fn);
				installers.push(fn);
			}
			for (let j = 0; j < b.length; j += 1) {
				const fn = b[j];
				if (typeof fn !== 'function' || seen.has(fn)) continue;
				seen.add(fn);
				installers.push(fn);
			}
			const register = this.registerStatsModule.bind(this);
			for (let i = 0; i < installers.length; i += 1) {
				const fn = installers[i];
				if (typeof fn !== 'function') continue;
				try {
					fn(this, register);
				} catch (err) {
					try {
						console.warn('[flexicorp] Stats module installer failed', err);
					} catch (_) {}
				}
			}
		},

		statsCapabilityCollocations() {
			return this._statsCap('stats_collocations');
		},

		statsCapabilityDepCollocations() {
			return this._statsCap('stats_dep_collocations');
		},

		/**
		 * The "Other" subtab is data-driven, not engine-driven: it appears whenever the
		 * server hydrated state.other.ran (i.e. the last query was an aggregation we don't
		 * have a first-class panel for — count, dist, dcoll, keyness). It carries the
		 * operation name plus a raw table.
		 */
		statsCapabilityOther() {
			return !!(this.other && this.other.ran);
		},

		/** If the active Stats sub-tab is not offered for this engine, fall back to Frequency. */
		ensureStatsSubTabAllowed() {
			const prev = this.statsSubTab;
			const dynIds =
				typeof this.availableStatsModules === 'function'
					? this.availableStatsModules().map((m) => m.id)
					: [];
			if (!this.search || !this.search.ran) {
				this.statsSubTab = 'corpus';
			} else if (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits()) {
				if (prev && !['corpus', 'queries'].includes(prev)) {
					this.statsSubTab = 'corpus';
				}
			} else {
				// Legacy Keyness subtab removed; prefer Contrast module when available.
				if (prev === 'keyness') {
					if (dynIds.includes('contrast')) this.statsSubTab = 'contrast';
					else this.statsSubTab = this.statsCapabilityOther() ? 'other' : 'freq';
				}
				else if (
					prev &&
					!['freq', 'coll', 'other', 'corpus', 'queries'].includes(prev) &&
					!dynIds.includes(prev)
				) {
					this.statsSubTab = this.statsCapabilityOther() ? 'other' : 'freq';
				} else if (prev === 'coll' && !this.statsCapabilityCollocations()) this.statsSubTab = 'freq';
				else if (prev === 'dcoll') {
					if (dynIds.includes('advanced_dcoll')) this.statsSubTab = 'advanced_dcoll';
					else this.statsSubTab = this.statsCapabilityOther() ? 'other' : 'freq';
				}
				else if (prev === 'other' && !this.statsCapabilityOther()) this.statsSubTab = 'freq';
			}
			if (
				prev === 'coll' &&
				this.statsSubTab !== 'coll' &&
				typeof this.destroyCollocationChart === 'function'
			) {
				this.destroyCollocationChart();
			}
			if (
				prev === 'freq' &&
				this.statsSubTab !== 'freq' &&
				typeof this.destroyFrequencyChart === 'function'
			) {
				this.destroyFrequencyChart();
			}
			if (
				this.statsSubTab === 'freq' &&
				prev !== 'freq' &&
				typeof this.scheduleFrequencyChartRender === 'function'
			) {
				const run = () => this.scheduleFrequencyChartRender();
				if (this.$nextTick) this.$nextTick(run);
				else setTimeout(run, 0);
			}
		},

		currentBackendQueryLanguageOptions() {
			const combos = this.backendCombinationList().filter(
				(c) =>
					c &&
					c.available &&
					c.backend !== 'flexi' &&
					c.backend !== 'clickql' &&
					c.backend !== 'clickhouse'
			);
			const seen = new Set();
			const unique = [];
			combos.forEach((combo) => {
				const queryLanguage = combo && combo.queryLanguage ? String(combo.queryLanguage) : '';
				const corpusFormat = combo && combo.corpusFormat ? String(combo.corpusFormat) : '';
				const optionId = `${queryLanguage}::${corpusFormat}`;
				if (!queryLanguage || seen.has(optionId)) return;
				seen.add(optionId);
				unique.push({
					optionId,
					queryLanguage,
					corpusFormat,
				});
			});
			const counts = {};
			unique.forEach((item) => {
				const key = `${item.backend}::${item.queryLanguage}`;
				counts[key] = (counts[key] || 0) + 1;
			});
			return unique.map((item) => ({
				...item,
				label:
					counts[`${item.backend}::${item.queryLanguage}`] > 1
						? `${item.queryLanguage} (${item.corpusFormat})`
						: item.queryLanguage,
			}));
		},

		hasMultipleQueryLanguageOptions() {
			return this.currentBackendQueryLanguageOptions().length > 1;
		},

		currentQueryLanguageOptionId() {
			if (this.settingsSelectedComboId) return this.settingsSelectedComboId;
			const currentBackend = this.settings && this.settings.backend ? String(this.settings.backend) : '';
			const currentQueryLanguage = this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage) : '';
			const currentCorpusFormat = this.settings && this.settings.corpusFormat ? String(this.settings.corpusFormat) : '';
			const combos = this.backendCombinationList().filter(
				(c) =>
					c &&
					c.available &&
					c.backend !== 'flexi' &&
					c.backend !== 'clickql' &&
					c.backend !== 'clickhouse'
			);
			const exactCombo = combos.find(
				(c) =>
					c.backend === currentBackend &&
					c.queryLanguage === currentQueryLanguage &&
					c.corpusFormat === currentCorpusFormat
			);
			if (exactCombo && exactCombo.id) return exactCombo.id;
			return combos.length && combos[0].id ? combos[0].id : '';
		},

		useCurrentBackendQueryLanguage(optionId) {
			// Preserve for older controls that still use queryLanguage/corpusFormat-only options
			const selected = this.currentBackendQueryLanguageOptions().find((opt) => opt.optionId === optionId);
			if (!selected) return;
			this.settings.queryLanguage = selected.queryLanguage;
			this.settings.corpusFormat = selected.corpusFormat;
			if (this.settings.backend === 'flexi') {
				if (selected.corpusFormat === 'manatee') this.settings.queryEngine = 'manatee';
				else if (selected.corpusFormat === 'cwb') this.settings.queryEngine = 'cqp';
				else if (selected.corpusFormat === 'pando') {
					this.settings.queryEngine = 'pando';
					this.settings.backend = 'pando';
				}
			} else if (this.settings.backend === 'pando' || this.settings.backend === 'flexicorp-pando') {
				this.settings.queryEngine = 'pando';
			}
			if (this.search && this.search.queryHighlightEnabled) {
				if (this.queryHighlightSupported()) this.scheduleQueryHighlight();
				else {
					this.search.queryHighlightHtml = '';
					this.search.queryHighlightError = '';
				}
			}
		},

		useSettingsBackendCombination(comboId) {
			if (!comboId) return;
			this.settingsSelectedComboId = comboId;
			const combos = this.backendCombinationList();
			const combo = combos.find((c) => c && c.id === comboId);
			if (!combo) return;
			this.useBackendCombination(combo);
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

		applyState(state) {
			if (!state || typeof state !== 'object') return;
			const initial = !this._hydrated;
			const loaded = state.loadedSections && typeof state.loadedSections === 'object' ? state.loadedSections : {};
			this.action = state.action || this.action;
			this.projectRoot = state.projectRoot || '';
			this.backendOverrides = Object.assign({}, this.backendOverrides, state.backendOverrides || {});
			this.isAdmin = !!state.isAdmin;
			this.corpusListing = state.corpusListing && typeof state.corpusListing === 'object' ? state.corpusListing : null;
			this.noshowFields = Array.isArray(state.noshowFields) ? state.noshowFields : [];
			this.attributeCatalog = state.attributeCatalog && typeof state.attributeCatalog === 'object'
				? state.attributeCatalog
				: {};
			this.debugMode = !!state.debugMode;
			if (Object.prototype.hasOwnProperty.call(state, 'bootstrapDiag')) {
				this.bootstrapDiag =
					state.bootstrapDiag && typeof state.bootstrapDiag === 'object' ? state.bootstrapDiag : null;
				try {
					const bdPlain =
						state.bootstrapDiag && typeof state.bootstrapDiag === 'object'
							? JSON.parse(JSON.stringify(state.bootstrapDiag))
							: null;
					if (bdPlain) {
						if (this.debugMode) {
							console.info('[flexicorp] bootstrapDiag', bdPlain);
						}
						if (bdPlain.backendSelectionError) {
							console.warn('[flexicorp] backendSelectionError — search/freq may be skipped server-side', bdPlain);
						}
					}
				} catch (e) {
					/* ignore */
				}
			}
			const _prevTab = this.activeTab;
			// Corpus-info probes always post active_tab=frequency for the Stats load path; they
			// must not navigate the shell. Same for any response that should only refresh data.
			const preserveClientTab = !!(state && (state.corpusInfoProbe || state.preserveClientTab));
			if (!preserveClientTab) {
				this.activeTab = state.activeTab || this.activeTab || 'search';
			}
			if (
				typeof this.destroyFrequencyChart === 'function' &&
				!initial &&
				_prevTab === 'frequency' &&
				this.activeTab !== 'frequency'
			) {
				this.destroyFrequencyChart();
			}
			if (
				typeof this.destroyCollocationChart === 'function' &&
				!initial &&
				_prevTab === 'frequency' &&
				this.activeTab !== 'frequency'
			) {
				this.destroyCollocationChart();
			}
			if (Array.isArray(state.availableBackends)) this.availableBackends = state.availableBackends;
			if (Array.isArray(state.availableQueryEngines)) this.availableQueryEngines = state.availableQueryEngines;
			if (state.backendStatus && typeof state.backendStatus === 'object') this.backendStatus = state.backendStatus;
			if (Array.isArray(state.backendCombos)) this.backendCombos = state.backendCombos;
			if (typeof this.applyFqsState === 'function') this.applyFqsState(state);
			if (typeof state.flexicorpDocsUrl === 'string') this.flexicorpDocsUrl = state.flexicorpDocsUrl;
			if (Array.isArray(state.externalEngines)) this.externalEngines = state.externalEngines;
			if (state.docMetaLabels && typeof state.docMetaLabels === 'object') this.docMetaLabels = state.docMetaLabels;
			const _prevBackend = this.settings && this.settings.backend;
			const _prevQl = this.settings && this.settings.queryLanguage;
			this.settings = Object.assign({}, this.settings, state.settings || {});
			// Native backends: align query_engine with backend so hidden POST fields do not stay on pando
			// after switching to manatee/cqp (server also enforces; this fixes client before next submit).
			{
				const b = this.settings.backend;
				if (b === 'manatee') this.settings.queryEngine = 'manatee';
				else if (b === 'cqp') this.settings.queryEngine = 'cqp';
				else if (b === 'pando' || b === 'flexicorp-pando') this.settings.queryEngine = 'pando';
				else if (b === 'clickql') this.settings.queryEngine = 'clickql';
				else if (b === 'clickhouse') this.settings.queryEngine = 'clickhouse';
				else if (b === 'blacklab') this.settings.queryEngine = 'blacklab';
			}
			if (
				typeof this.destroyFrequencyChart === 'function' &&
				!initial &&
				(_prevBackend !== this.settings.backend || _prevQl !== this.settings.queryLanguage)
			) {
				this.destroyFrequencyChart();
			}
			if (
				typeof this.destroyCollocationChart === 'function' &&
				!initial &&
				(_prevBackend !== this.settings.backend || _prevQl !== this.settings.queryLanguage)
			) {
				this.destroyCollocationChart();
			}
			this.settingsDraft = Object.assign({}, this.settings);
			if (this.settings.kwicColWeights && typeof this.settings.kwicColWeights === 'object') {
				this.settingsDraft.kwicColWeights = Object.assign({}, this.settings.kwicColWeights);
			}
			this.documentsUi = Object.assign({}, this.documentsUi, state.documentsUi || {});
			this.documentsUi.perPage = this.normalizePositiveInt(this.documentsUi.perPage, 20);
			this.documentsUi.visibleCount = this.normalizePositiveInt(this.documentsUi.visibleCount, this.documentsUi.perPage);
			this.loadedSections = Object.assign({}, this.loadedSections || {}, loaded);
			if (initial || loaded.overview) {
				this.status = state.status || {};
				this.info = state.info || {};
			}
			if (initial || loaded.documents) {
				this.listDocs = state.listDocs || {};
				this.documents = this.normalizeDocuments(Array.isArray(state.documents) ? state.documents : []);
				this.documentsTotal = Number.isFinite(state.documentsTotal) ? state.documentsTotal : this.documents.length;
				this.buildDocumentLookup();
				this.resetDocumentsVisible();
			}
			if (initial || loaded.search) {
				const prevRawHits = this.searchRaw && Array.isArray(this.searchRaw.hits) ? this.searchRaw.hits : [];
				const prevRawCols = this.searchRaw && Array.isArray(this.searchRaw.tableColumns) ? this.searchRaw.tableColumns : [];
				const prevRawRows = this.searchRaw && Array.isArray(this.searchRaw.tableRows) ? this.searchRaw.tableRows : [];
				const nextSearch = Object.assign({}, this.search, state.search || {});
				const nextHits = Array.isArray(nextSearch.hits) ? nextSearch.hits : [];
				const nextCols = Array.isArray(nextSearch.tableColumns) ? nextSearch.tableColumns : [];
				const nextRows = Array.isArray(nextSearch.tableRows) ? nextSearch.tableRows : [];
				const nextSignature = this.searchSignatureFor(nextSearch);
				const appendMode = !initial && !!this._pendingSearchAppend;
				const incomingSearchCall = nextSearch && nextSearch.response ? nextSearch.response : {};
				const appendAllowed =
					appendMode
					&& !this.callHasErrors(incomingSearchCall)
					&& !!this.searchRawSignature
					&& this.searchRawSignature === nextSignature;
				const mergedHits = appendAllowed ? prevRawHits.concat(nextHits) : nextHits;
				const mergedRows = appendAllowed ? prevRawRows.concat(nextRows) : nextRows;
				const mergedCols = appendAllowed ? (nextCols.length ? nextCols : prevRawCols) : nextCols;
				if (!appendAllowed) this.searchRenderGen += 1;
				this.searchRaw = {
					hits: mergedHits,
					tableColumns: mergedCols,
					tableRows: mergedRows,
				};
				nextSearch.hits = mergedHits;
				nextSearch.tableColumns = mergedCols;
				nextSearch.tableRows = mergedRows;
				nextSearch.start = appendAllowed ? mergedHits.length : 0;
				// Keep search summary ("X of Y hits/rows") in sync after append.
				// Backend returns `result.returned` for the requested page size; on append we need
				// the merged client-side count instead of the last page size.
				if (appendAllowed && nextSearch.response && nextSearch.response.result && typeof nextSearch.response.result === 'object') {
					if (this.searchHasTableResults(nextSearch)) {
						nextSearch.response.result.returned = mergedRows.length;
					} else {
						nextSearch.response.result.returned = mergedHits.length;
					}
				}
				const pendingUiMode = this.normalizeSearchViewMode(this._pendingSearchUiViewMode);
				if (pendingUiMode) {
					nextSearch.viewMode = pendingUiMode;
				}
				const scope = this.normalizeContextScopeValue(nextSearch.contextScope);
				const vm = this.normalizeSearchViewMode(nextSearch.viewMode);
				if ((initial || this.searchViewModeAuto) && (scope === 'window' || scope === 'tok') && (vm === 'grouped' || vm === 'table')) {
					nextSearch.viewMode = 'kwic';
				}
				this.search = nextSearch;
				this.logAbiendoloSearchPayload(this.search, 'applyState:merged-search');
				this.debugViewModeLog('applyState:after-merge', {
					initial: !!initial,
					pendingUiMode: this._pendingSearchUiViewMode || '',
					receivedViewMode: this.normalizeSearchViewMode(state && state.search && state.search.viewMode),
				});
				this.searchRawSignature = nextSignature;
				if (this.search.window == null || this.search.window < 1) {
					this.search.window = this.defaultKwicWindow();
				}
				this.applyAutoSearchLimit({ force: initial });
				if (this.search.ran) {
					// Keep the query editor open when backend validation/execution returns errors,
					// so users can correct and rerun immediately.
					this.search.showQueryForm = this.callHasIssues(this.search.response) ? true : false;
					if (this.search.query) this.scheduleQueryHighlight();
				}
				if (typeof this.statsSearchScopeRehydrateFromSearch === 'function') {
					try {
						this.statsSearchScopeRehydrateFromSearch({ initial: !!initial });
					} catch (_) {}
				}
				this.scheduleAlignedTableHeightSync();
			}
			if (initial || loaded.frequency) {
				this.frequency = Object.assign({}, this.frequency, state.frequency || {});
				this.collocation = Object.assign({}, this.collocation, state.collocation || {});
				// state.other holds the generic raw-table payload for aggregation operations
				// without a first-class panel (count, dist, dcoll, keyness). The PHP routing
				// guarantees only one of frequency / collocation / other is "ran" per query.
				this.other = Object.assign({}, this.other || { ran: false }, state.other || {});
				const c = this.collocation;
				if (c) {
					let nextKeys = null;
					if (Array.isArray(c.measureKeys) && c.measureKeys.length) {
						nextKeys = c.measureKeys;
					} else if (typeof c.measures === 'string' && c.measures.trim()) {
						nextKeys = c.measures
							.split(',')
							.map((s) => s.trim())
							.filter(Boolean);
					}
					if (typeof this.normalizeCollocationMeasureKeys === 'function') {
						this.collocation.measureKeys = this.normalizeCollocationMeasureKeys(nextKeys);
					} else {
						this.collocation.measureKeys = nextKeys && nextKeys.length ? nextKeys : ['logdice'];
					}
				}
				if (typeof this.frequencyShowSetupForm !== 'undefined') {
					const fr = this.frequency;
					if (fr && fr.ran) {
						if (typeof this.callHasIssues === 'function' && this.callHasIssues(fr.response)) {
							this.frequencyShowSetupForm = true;
						} else if (Array.isArray(fr.rows) && fr.rows.length > 0) {
							this.frequencyShowSetupForm = false;
						} else {
							this.frequencyShowSetupForm = true;
						}
					} else {
						this.frequencyShowSetupForm = true;
					}
				}
				if (typeof this.onFrequencyStateApplied === 'function') {
					setTimeout(() => this.onFrequencyStateApplied(), 0);
				}
				if (typeof this.onCollocationStateApplied === 'function') {
					setTimeout(() => this.onCollocationStateApplied(), 0);
				}
			}
			// Query-driven "Other" routing can arrive even when the request is primarily a
			// Search payload (loaded.frequency === false). Hydrate `other` eagerly so
			// ensureStatsSubTabAllowed() sees statsCapabilityOther() and does not bounce
			// the server hint (`statsSubTabHint: other`) back to Frequency.
			if (state.other && typeof state.other === 'object') {
				this.other = Object.assign({}, this.other || { ran: false }, state.other);
			}
			const nextResponses = Object.assign({}, this.responses || {});
			const responseMap = state.responses && typeof state.responses === 'object' ? state.responses : {};
			if (initial || loaded.overview) {
				this.reindex = state.reindex || {};
				nextResponses.status = responseMap.status || {};
				nextResponses.info = responseMap.info || {};
				nextResponses.reindex = responseMap.reindex || {};
			}
			if (initial || loaded.documents) {
				nextResponses.documents = responseMap.documents || {};
			}
			if (initial || loaded.search) {
				nextResponses.search = responseMap.search || {};
			}
			if (initial || loaded.frequency) {
				nextResponses.frequency = responseMap.frequency || {};
				nextResponses.collocation = responseMap.collocation || {};
			}
			this.responses = nextResponses;
			this.debugEntries = Array.isArray(state.debugEntries) ? state.debugEntries : [];
			if (Array.isArray(state.debugAppend) && state.debugAppend.length && this.showDebugTab()) {
				const merged = this.debugEntries.slice();
				state.debugAppend.forEach((e) => merged.push(e));
				this.debugEntries = merged;
			}
			if (Array.isArray(state.xidxRegionTypes)) this.xidxRegionTypes = state.xidxRegionTypes;
			if (state && Object.prototype.hasOwnProperty.call(state, 'adminPandoIndexHint')) {
				const next = state.adminPandoIndexHint;
				const prev = this.adminPandoIndexHint;
				const prevKey = prev && typeof prev === 'object' ? `${prev.code || ''}|${prev.message || ''}` : '';
				const nextKey = next && typeof next === 'object' ? `${next.code || ''}|${next.message || ''}` : '';
				if (initial || prevKey !== nextKey) this.adminPandoIndexHintDismissed = false;
				this.adminPandoIndexHint = next;
			}
			if (Array.isArray(state.recentQueries)) this.recentQueries = state.recentQueries;
			if (state.recentQueriesByDialect && typeof state.recentQueriesByDialect === 'object') {
				this.recentQueriesByDialect = state.recentQueriesByDialect;
			}
			if (state.storedQueriesByDialect && typeof state.storedQueriesByDialect === 'object') {
				this.storedQueriesByDialect = state.storedQueriesByDialect;
			}
			if (state && Object.prototype.hasOwnProperty.call(state, 'searchIntroHtml')) {
				this.searchIntroHtml = state.searchIntroHtml == null ? '' : String(state.searchIntroHtml);
			}
			if (state && Object.prototype.hasOwnProperty.call(state, 'searchIntroLeadHtml')) {
				this.searchIntroLeadHtml = state.searchIntroLeadHtml == null ? '' : String(state.searchIntroLeadHtml);
			} else if (this.searchIntroHtml) {
				const sp = flexicorpSplitSearchIntroHtml(this.searchIntroHtml);
				this.searchIntroLeadHtml = sp.lead;
				this.searchIntroHtml = sp.body;
			}
			if (Array.isArray(state.searchExampleQueries)) this.searchExampleQueries = state.searchExampleQueries;
			if (state.queryBuilder && typeof state.queryBuilder === 'object') {
				this.queryBuilderConfig = Object.assign({}, state.queryBuilder);
			}
			if (state.backendDisplayNames && typeof state.backendDisplayNames === 'object') {
				this.backendDisplayNames = Object.assign({}, state.backendDisplayNames);
			}

			// Keep track of the currently active (backend, queryLanguage, corpusFormat) combo id
			const combos = Array.isArray(this.backendCombos) ? this.backendCombos : [];
			const currentBackend = this.settings && this.settings.backend;
			const currentQL = this.settings && this.settings.queryLanguage;
			const currentFmt = this.settings && this.settings.corpusFormat;
			const currentCombo = combos.find(
				(c) =>
					c &&
					c.backend === currentBackend &&
					c.queryLanguage === currentQL &&
					c.corpusFormat === currentFmt
			);
			this.settingsSelectedComboId = currentCombo && currentCombo.id ? currentCombo.id : '';

			// Pick the Stats subtab from the latest server state.
			// Order: explicit dedicated action → server-supplied hint (PHP statsSubTabHint,
			// driven by flexicorp result.operation) → "ran" payloads → soft default after any
			// base query (Frequency, not Corpus stats, so a Stats click lands on the
			// query-scoped view). Users who explicitly chose Keyness / Coll / D-coll keep their
			// pick. The 'other' subtab is reachable only via server hint or its own ran flag —
			// no dedicated action button posts it directly.
			if (state.action === 'freq') {
				this.statsSubTab = 'freq';
			} else if (state.action === 'coll') {
				this.statsSubTab = 'coll';
			} else if (typeof state.statsSubTabHint === 'string' && state.statsSubTabHint !== '') {
				this.statsSubTab = state.statsSubTabHint;
			} else if (state.other && state.other.ran) {
				this.statsSubTab = 'other';
			} else if (state.collocation && state.collocation.ran) {
				this.statsSubTab = 'coll';
			} else if (state.frequency && state.frequency.ran) {
				this.statsSubTab = 'freq';
			} else if (
				state.search && state.search.ran
				&& typeof this.statsSearchHasHits === 'function'
				&& this.statsSearchHasHits()
				&& (this.statsSubTab === 'corpus' || !this.statsSubTab)
			) {
				this.statsSubTab = 'freq';
			}

			// Load Stats subtabs (maps, contrast, …) before validating statsSubTab so isAvailable() sees current backend/corpus.
			if (typeof this.installFlexicorpStatsModuleExtensions === 'function') {
				this.installFlexicorpStatsModuleExtensions();
			}
			// Let modules ingest routed state payloads (keyness now hydrates from search payload).
			if (typeof this.afContrastHydrateFromState === 'function') {
				try {
					this.afContrastHydrateFromState(state);
				} catch (_) {}
			} else if (typeof this.afContrastHydrateFromOtherState === 'function') {
				// Backward-compatible fallback for older contrast module code.
				try {
					const otherState = state.other && typeof state.other === 'object' ? state.other : this.other;
					this.afContrastHydrateFromOtherState(otherState);
				} catch (_) {}
			}
			if (typeof this.dcollAdvHydrateFromState === 'function') {
				try {
					this.dcollAdvHydrateFromState(state);
				} catch (_) {}
			}
			if (typeof this.ensureStatsSubTabAllowed === 'function') {
				this.ensureStatsSubTabAllowed();
			}
			// Operation-driven routing: use backend JSON `operation` to choose the Stats subtab.
			// Modules declare which operations they can ingest (e.g. Contrast → `keyness`).
			try {
				const op = this.statsOperationFromState(state);
				const desired = this.statsSubTabForOperationName(op);
				if (desired && desired !== this.statsSubTab) {
					this.statsSubTab = desired;
					this.ensureStatsSubTabAllowed();
				}
			} catch (_) {}
			// Query-driven aggregation/association responses should surface in Stats immediately.
			// If routing picked a stats subtab (freq/coll/other), keep the user out of Search
			// even when state.activeTab still comes in as "search" from legacy paths.
			// Skip when preserveClientTab (e.g. corpus-info probe) or the user already navigated away.
			if (
				!preserveClientTab
				&& (state.action === 'query' || state.action === 'kwic')
				&& this.statsSubTab && this.statsSubTab !== 'corpus'
			) {
				this.activeTab = 'frequency';
			}
			if (initial && !this._userActiveTab) {
				this._userActiveTab = this.activeTab;
			}
			try {
				const q = state && state.search && typeof state.search.query === 'string' ? state.search.query : '';
				const aggParts =
					typeof this.statsAggregationPartsFromQuery === 'function'
						? this.statsAggregationPartsFromQuery(q)
						: [];
				if (aggParts.length && typeof console !== 'undefined' && typeof console.log === 'function') {
					console.log('[flexicorp:stats-routing]', {
						action: state && state.action ? state.action : '',
						statsSubTabHint: state && state.statsSubTabHint ? state.statsSubTabHint : '',
						selectedStatsSubTab: this.statsSubTab,
						aggParts,
					});
				}
			} catch (_) {}

			const isFirstStateApply = !this._hasAppliedInitialState;
			this._hydrated = true;
			this.ensureValidContextScope();
			this.ensureValidSearchViewMode();
			this._syncSearchViewWhenNoContextScope();
			if (initial || loaded.search) this.refreshTeitokResultBehavior();
			this.updateReindexOverviewWatcher();
			// Reconcile tab-local selection only once during first hydration.
			// Running this on every AJAX apply can override a deliberate engine change
			// (e.g. user selects CWB/CQP, stale stored "pando" snapshot flips it back).
			if (isFirstStateApply) {
				this.applyStoredFlexicorpSelection();
			}
			// Optional runtime overrides set by host page scripts.
			if (typeof window !== 'undefined' && window.flexicorpBackendDisplayNames && typeof window.flexicorpBackendDisplayNames === 'object') {
				this.backendDisplayNames = Object.assign({}, this.backendDisplayNames || {}, window.flexicorpBackendDisplayNames);
			}
			// Do not persist selection on generic state applies. Selection persistence is handled
			// explicitly by user-initiated selection-change handlers.
			this.updateSearchShareButtonVisibility();
			if (typeof this.onQueryBuilderStateApplied === 'function') {
				this.onQueryBuilderStateApplied(state);
			}
			this._hasAppliedInitialState = true;
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

		normalizePositiveInt(value, fallback) {
			const num = parseInt(value, 10);
			return Number.isFinite(num) && num > 0 ? num : fallback;
		},

		/** Tokens on each side of the hit; server default from getset flexicorp/kwic_window (PHP) is 10. */
		defaultKwicWindow() {
			const w = this.settings && this.settings.kwicWindow;
			const n = parseInt(w, 10);
			return Number.isFinite(n) && n > 0 ? n : 10;
		},

		currentContextScopeKey() {
			return this.normalizeContextScopeValue(this.search && this.search.contextScope);
		},

		kwicScopeUsesTokenWindow() {
			const scope = this.currentContextScopeKey();
			return scope === 'window' || scope === 'tok';
		},

		ensureKwicColWeightsShape() {
			const d = { ctx: 7, left: 36, match: 10, right: 36, alLeft: 12, alMatch: 14, alRight: 12 };
			if (!this.settings || typeof this.settings !== 'object') return;
			if (!this.settings.kwicColWeights || typeof this.settings.kwicColWeights !== 'object') {
				this.settings.kwicColWeights = Object.assign({}, d);
			} else {
				Object.keys(d).forEach((k) => {
					const v = Number(this.settings.kwicColWeights[k]);
					if (!Number.isFinite(v) || v < 1) this.settings.kwicColWeights[k] = d[k];
				});
			}
		},

		/** Percent widths for <colgroup> (always 7 cols to match thead); sums to ~100. */
		kwicColumnPercentages() {
			this.ensureKwicColWeightsShape();
			const w = this.settings && this.settings.kwicColWeights ? this.settings.kwicColWeights : {};
			const aligned = typeof this.searchHasAlignedHits === 'function' && this.searchHasAlignedHits();
			const keys = ['ctx', 'left', 'match', 'right', 'alLeft', 'alMatch', 'alRight'];
			const raw = keys.map((k) => {
				if (!aligned && (k === 'alLeft' || k === 'alMatch' || k === 'alRight')) return 0.001;
				return Math.max(1, Number(w[k]) || 1);
			});
			const sum = raw.reduce((a, b) => a + b, 0);
			if (sum <= 0) return [7, 36, 10, 36, 0.001, 0.001, 0.001];
			return raw.map((x) => (x / sum) * 100);
		},

		activeKwicWindowSize() {
			return this.normalizePositiveInt(this.search && this.search.window, this.defaultKwicWindow());
		},

		normalizeContextScopeValue(value) {
			let text = String(value || '').trim().toLowerCase();
			if (text.startsWith('<') && text.endsWith('>') && text.length > 2) {
				text = text.slice(1, -1).trim().toLowerCase();
			}
			const aliases = {
				sentence: 's',
				sent: 's',
				line: 'l',
				verse: 'l',
				paragraph: 'p',
				para: 'p',
				document: 'text',
				doc: 'text',
				'token-window': 'window',
				'tok-window': 'window',
				kwic: 'window',
			};
			return aliases[text] || text;
		},

		contextScopeSupportsTokenWindow() {
			// Keep the traditional fallback available across backends: users expect
			// context-window even when structural scopes (sentence/paragraph/...) exist.
			return true;
		},

		currentResultCapabilities() {
			const result = this.search && this.search.response && this.search.response.result && typeof this.search.response.result === 'object'
				? this.search.response.result
				: null;
			const infoResult = this.info && this.info.result && typeof this.info.result === 'object'
				? this.info.result
				: null;
			return {
				supportsXmlContext: result && typeof result.supports_xml_context === 'boolean'
					? result.supports_xml_context
					: (infoResult && typeof infoResult.supports_xml_context === 'boolean' ? infoResult.supports_xml_context : true),
				supportsDocumentLinks: result && typeof result.supports_document_links === 'boolean'
					? result.supports_document_links
					: (infoResult && typeof infoResult.supports_document_links === 'boolean' ? infoResult.supports_document_links : true),
			};
		},

		currentBackendSupportsXmlContext() {
			return !!this.currentResultCapabilities().supportsXmlContext;
		},

		currentBackendSupportsDocumentLinks() {
			return !!this.currentResultCapabilities().supportsDocumentLinks;
		},

		contextScopeLabel(value) {
			const key = this.normalizeContextScopeValue(value);
			const labels = {
				window: 'context-window',
				s: 'sentence',
				u: 'utterance (<u>)',
				p: 'paragraph',
				text: 'document',
				l: 'line (verse line / <l>)',
				lb: 'line break (<lb/>)',
				quote: 'quote',
				seg: 'segment',
			};
			return labels[key] || key;
		},

		/**
		 * Annotation / metadata names that are not struct regions (noise in /info lists). Structural
		 * options come from the server allowlist (settings.contextScopeRegionAllowlist), default
		 * s u lb l p seg — override in TEITOK: getset flexicorp/context_scope_regions.
		 */
		contextScopeRegionNoiseBlocklist() {
			return new Set([
				'gloss',
				'lemma',
				'lemmagloss',
				'form',
				'pform',
				'nform',
				'norm',
				'word',
				'orth',
				'phon',
				'pos',
				'msd',
				'feats',
				'upos',
				'xpos',
				'deprel',
				'dep',
				'head',
				'join',
				'syn',
				'sense',
				'trans',
				'transl',
				'lang',
				'xml_lang',
				'hand',
				'resp',
				'cert',
				'style',
				'rend',
				'cite',
				'ana',
				'corresp',
				'function',
				'class',
				'subtype',
				'type',
				'ns',
				't',
				'teiheader',
				'tei',
				'facsimile',
				'surface',
				'facs',
				'bbox',
				'baseline',
			]);
		},

		/** Normalized ids in allowlist (matches flexicorp.php tt_flexicorp_context_scope_region_allowlist_resolved). */
		contextScopeRegionAllowlistSet() {
			const raw = this.settings && this.settings.contextScopeRegionAllowlist;
			const list =
				Array.isArray(raw) && raw.length
					? raw
					: ['s', 'u', 'lb', 'l', 'p', 'seg'];
			return new Set(
				list
					.map((x) => {
						const n = this.normalizeContextScopeValue(x);
						return n && n !== 'window' && n !== 'tok' ? n : null;
					})
					.filter((x) => x)
			);
		},

		isContextScopeRegionKey(region) {
			const r = this.normalizeContextScopeValue(region);
			if (!r || r === 'window' || r === 'tok') return false;
			if (!/^[a-z][a-z0-9_-]{0,31}$/.test(r)) return false;
			if (this.contextScopeRegionNoiseBlocklist().has(r)) return false;
			return this.contextScopeRegionAllowlistSet().has(r);
		},

		collectContextRegions() {
			const out = new Set();
			const addRegion = (raw) => {
				const region = this.normalizeContextScopeValue(raw);
				if (!region || region === 'window' || region === 'tok') return;
				if (!this.isContextScopeRegionKey(region)) return;
				out.add(region);
			};
			const infoResult = this.info && this.info.result && typeof this.info.result === 'object' ? this.info.result : null;
			if (infoResult && Array.isArray(infoResult.structures)) {
				infoResult.structures.forEach((st) => {
					if (st && st.name) addRegion(st.name);
				});
			}
			const byRegion = infoResult && infoResult.sattributes_by_region && typeof infoResult.sattributes_by_region === 'object'
				? infoResult.sattributes_by_region
				: null;
			if (byRegion) Object.keys(byRegion).forEach(addRegion);
			const flatLists = [];
			if (infoResult && Array.isArray(infoResult.struct_attributes)) flatLists.push(infoResult.struct_attributes);
			if (infoResult && Array.isArray(infoResult.sattributes)) flatLists.push(infoResult.sattributes);
			if (infoResult && Array.isArray(infoResult.native_structures)) flatLists.push(infoResult.native_structures);
			flatLists.forEach((items) => {
				items.forEach((item) => {
					const text = String(item || '').trim();
					if (!text) return;
					if (text.includes('_')) addRegion(text.split('_', 1)[0]);
					else addRegion(text);
				});
			});
			if (Array.isArray(this.xidxRegionTypes) && this.xidxRegionTypes.length) {
				this.xidxRegionTypes.forEach((rt) => {
					const region = this.normalizeContextScopeValue(rt);
					if (!region || region === 'window' || region === 'tok') return;
					if (!this.isContextScopeRegionKey(region)) return;
					out.add(region);
				});
			}
			const ordered = Array.from(out);
			ordered.sort((a, b) => {
				const order = { s: 0, u: 1, seg: 2, l: 3, lb: 4, p: 5, text: 6, quote: 7 };
				const ao = Object.prototype.hasOwnProperty.call(order, a) ? order[a] : 99;
				const bo = Object.prototype.hasOwnProperty.call(order, b) ? order[b] : 99;
				return ao - bo || a.localeCompare(b);
			});
			return ordered;
		},

		/** Pando (native or flexi+queryEngine pando): when there is no context-scope dropdown, API default is token scope, not CWB window. */
		executionUsesPandoContextDefault() {
			const b = String((this.settings && this.settings.backend) || '').toLowerCase();
			if (b === 'pando' || b === 'flexicorp-pando') return true;
			return String((this.settings && this.settings.queryEngine) || '').toLowerCase() === 'pando';
		},

		contextScopeOptions() {
			const regions = this.collectContextRegions();
			const options = [];
			// Token window is a first-class option whenever the backend can use CWB / Pando window scope,
			// not a fallback that appears only when no regions qualify.
			if (this.contextScopeSupportsTokenWindow()) {
				options.push({ value: 'window', label: this.contextScopeLabel('window') });
			}
			regions.forEach((region) => {
				options.push({ value: region, label: this.contextScopeLabel(region) });
			});
			if (!options.length && this.contextScopeSupportsTokenWindow()) {
				options.push({ value: 'window', label: this.contextScopeLabel('window') });
			}
			return options;
		},

		preferredContextScopeValue() {
			const options = this.contextScopeOptions();
			if (!options.length) {
				if (this.executionUsesPandoContextDefault()) return 'tok';
				return 'window';
			}
			const current = this.normalizeContextScopeValue(this.search && this.search.contextScope);
			if (current && options.some((opt) => opt.value === current)) return current;
			if (options.some((opt) => opt.value === 's')) return 's';
			if (options.some((opt) => opt.value === 'l')) return 'l';
			if (options.some((opt) => opt.value === 'lb')) return 'lb';
			if (options.some((opt) => opt.value === 'window')) return 'window';
			return options[0].value;
		},

		ensureValidContextScope() {
			if (!this.search || typeof this.search !== 'object') return;
			const before = this.normalizeContextScopeValue(this.search.contextScope);
			if (!this.currentBackendSupportsXmlContext() && this.search.contextFormat === 'xml') {
				this.search.contextFormat = 'text';
			}
			const normalized = this.normalizeContextScopeValue(this.search.contextScope);
			const options = this.contextScopeOptions();
			if (normalized && options.some((opt) => opt.value === normalized)) {
				this.search.contextScope = normalized;
				return;
			}
			this.search.contextScope = this.preferredContextScopeValue();
			const after = this.normalizeContextScopeValue(this.search.contextScope);
			if (before !== after) {
				const vm = this.normalizeSearchViewMode(this.search.viewMode);
				const preferred = this.preferredSearchViewModeForCurrentScope();
				if ((preferred === 'kwic' && (vm === 'grouped' || vm === 'table')) || (preferred === 'table' && vm === 'kwic')) {
					this.search.viewMode = preferred;
				}
			}
		},

		/**
		 * When there is no structural context scope to choose, Table / Grouped views have nothing
		 * to show in the "context" sense — default to KWIC. This covers two cases:
		 *   (a) opts is empty (e.g. Pando with only text/quote in xidx, blocked), and
		 *   (b) opts contains only token-window scopes ('window'/'tok'), e.g. a CQP corpus
		 *       with no sentence/utterance regions ("s-less"). In that situation 'window' is
		 *       always present as a first-class option, so opts.length > 0 even though there
		 *       is no real structural region to group by — Group / Table still cannot render
		 *       meaningfully and KWIC must be the default.
		 * Must run after ensureValidSearchViewMode so the latter does not restore "table" from
		 * the saved state.
		 */
		_syncSearchViewWhenNoContextScope() {
			if (!this.search || typeof this.search !== 'object') return;
			const opts = this.contextScopeOptions();
			const hasStructuralScope = opts.some((o) => o && o.value !== 'window' && o.value !== 'tok');
			if (hasStructuralScope) return;
			const vm = this.normalizeSearchViewMode(this.search.viewMode);
			if (vm === 'table' || vm === 'grouped') {
				this.search.viewMode = 'kwic';
			}
		},

		normalizeSearchViewMode(value) {
			const text = String(value || '').trim().toLowerCase();
			if (text === 'anchor-kwic') return 'anchor_kwic';
			return text || 'table';
		},

		searchViewModeOptions() {
			const options = [
				{ value: 'grouped', label: 'Grouped context' },
				{ value: 'table', label: 'Table' },
				{ value: 'kwic', label: 'KWIC' },
			];
			if (this.searchSupportsAnchorKwic() || this.normalizeSearchViewMode(this.search && this.search.viewMode) === 'anchor_kwic') {
				options.push({ value: 'anchor_kwic', label: 'Anchor KWIC' });
			}
			return options;
		},

		preferredSearchViewModeForCurrentScope() {
			if (this.shouldAutoPreferGroupedAlignedView()) return 'grouped';
			const scope = this.normalizeContextScopeValue(this.search && this.search.contextScope);
			if (scope === 'window' || scope === 'tok') return 'kwic';
			return 'table';
		},

		shouldAutoPreferGroupedAlignedView() {
			// Sentence / XML-region aligned targets (alias : <region>) benefit from grouped rows.
			if (this.queryUsesStructuralAlignedTarget()) return true;
			if (!this.searchHasAlignedHits()) return false;
			const hits = Array.isArray(this.search && this.search.hits) ? this.search.hits : [];
			if (!hits.length) return false;
			// Typical parallel/bitext queries have exactly one companion per source row.
			// Prefer KWIC/table unless at least one source row fans out to multiple targets.
			for (let i = 0; i < hits.length; i += 1) {
				const hit = hits[i];
				if (!hit || typeof hit !== 'object') continue;
				const companions = this.getGroupedAlignedCompanions(hit);
				if (companions.length > 1) return true;
			}
			return false;
		},

		onContextScopeChanged() {
			if (!this.search || typeof this.search !== 'object') return;
			this.debugViewModeLog('onContextScopeChanged:before');
			this.ensureValidContextScope();
			const vm = this.normalizeSearchViewMode(this.search.viewMode);
			const preferred = this.preferredSearchViewModeForCurrentScope();
			// Scope switch defaults: token-window -> KWIC; structural region -> Table; many aligned -> Grouped.
			if (vm !== preferred) {
				this.search.viewMode = preferred;
			}
			// Scope change should re-enable sensible defaults for that scope.
			this.searchViewModeAuto = true;
			this.debugViewModeLog('onContextScopeChanged:after');
		},

		onSearchViewModeChanged(event) {
			this.searchViewModeAuto = false;
			this.debugViewModeLog('onSearchViewModeChanged:user', {
				eventTrusted: event && Object.prototype.hasOwnProperty.call(event, 'isTrusted') ? !!event.isTrusted : null,
				value: this.normalizeSearchViewMode(this.search && this.search.viewMode),
			});
			this.updateSearchShareButtonVisibility();
			this.scheduleAlignedTableHeightSync();
		},

		ensureValidSearchViewMode() {
			if (!this.search || typeof this.search !== 'object') return;
			this.debugViewModeLog('ensureValidSearchViewMode:before');
			const normalized = this.normalizeSearchViewMode(this.search.viewMode);
			const options = this.searchViewModeOptions();
			if (options.some((opt) => opt.value === normalized)) {
				this.search.viewMode = normalized;
				if (this.searchViewModeAuto) {
					const preferred = this.preferredSearchViewModeForCurrentScope();
					if (options.some((opt) => opt.value === preferred) && normalized !== preferred) {
						this.search.viewMode = preferred;
					}
				}
				this.debugViewModeLog('ensureValidSearchViewMode:after-existing');
				return;
			}
			const preferred = this.preferredSearchViewModeForCurrentScope();
			this.search.viewMode = options.some((opt) => opt.value === preferred) ? preferred : 'table';
			this.debugViewModeLog('ensureValidSearchViewMode:after-fallback');
		},

		isLoading(key) {
			return !!(this.loading && this.loading[key]);
		},

		isBusy() {
			return Object.values(this.loading || {}).some(Boolean);
		},

		currentRunValue() {
			if (this.activeTab === 'search' && ((this.search && this.search.query) || (this.search && this.search.value))) {
				return 'query';
			}
			if (this.activeTab === 'frequency' && this.frequency && this.frequency.field) {
				return 'freq';
			}
			return '';
		},

		/** Whether the current query language has syntax highlighting support. */
		queryHighlightSupported() {
			if (!this.settings) return false;
			const queryLanguage = String(this.settings.queryLanguage || '').toLowerCase();
			return [
				'cwb-cql',
				'cwb',
				'cql',
				'manatee-cql',
				'manatee',
				'pando-cql',
				'pando',
				'clickcql',
				'clickql',
				'bcql',
				'corpusql',
				'pmltq',
				'clickpmltq',
			].includes(queryLanguage);
		},

		/** Build URL for highlight-only AJAX request (returns raw flexicorp highlight JSON). */
		getHighlightRequestUrl(snippet) {
			const url = new URL(window.location.href);
			url.searchParams.set('action', this.action || 'flexicorp');
			url.searchParams.set('ajax', '1');
			url.searchParams.set('highlight_snippet', typeof snippet === 'string' ? snippet : (this.search && this.search.query) || '');
			url.searchParams.set('backend', this.settings && this.settings.backend ? this.settings.backend : 'cqp');
			if (this.settings && this.settings.queryLanguage) {
				url.searchParams.set('query_language', this.settings.queryLanguage);
			}
			if (this.settings && this.settings.corpusFormat) {
				url.searchParams.set('corpus_format', this.settings.corpusFormat);
			}
			if (this.backendOverrides && this.backendOverrides.blacklab_url) url.searchParams.set('blacklab_url', this.backendOverrides.blacklab_url);
			if (this.backendOverrides && this.backendOverrides.blacklab_corpus) url.searchParams.set('blacklab_corpus', this.backendOverrides.blacklab_corpus);
			if (this.backendOverrides && this.backendOverrides.blacklab_user) url.searchParams.set('blacklab_user', this.backendOverrides.blacklab_user);
			if (this.backendOverrides && this.backendOverrides.blacklab_password) url.searchParams.set('blacklab_password', this.backendOverrides.blacklab_password);
			if (this.backendOverrides && this.backendOverrides.blacklab_field) url.searchParams.set('blacklab_field', this.backendOverrides.blacklab_field);
			url.searchParams.set('highlight_format', 'html');
			if (this.settings.queryEngine) {
				url.searchParams.set('query_engine', this.settings.queryEngine);
			}
			if (url.searchParams.get('debug') === '1') url.searchParams.set('debug', '1');
			return url.toString();
		},

		/** Debounce delay (ms) before running highlight – only after a short pause in typing, like clickcql. */
		queryHighlightDebounceMs: 500,

		scheduleQueryHighlight() {
			if (!this.queryHighlightSupported() || !this.search) return;
			const q = this.search.query || '';
			if (!q) return;
			/* Overlay needs the checkbox; results summary can still use highlight after a search (search.ran). */
			if (!this.search.queryHighlightEnabled && !this.search.ran) return;
			/* Show current query as plain black text until the debounced fetch returns */
			this.search.queryHighlightHtml = this.escapeHtmlForPre(q);
			this.search.queryHighlightError = '';
			if (this.search.queryHighlightDebounceId) clearTimeout(this.search.queryHighlightDebounceId);
			const delay = typeof this.queryHighlightDebounceMs === 'number' && this.queryHighlightDebounceMs > 0 ? this.queryHighlightDebounceMs : 500;
			this.search.queryHighlightDebounceId = setTimeout(() => {
				this.search.queryHighlightDebounceId = null;
				this.fetchQueryHighlight();
			}, delay);
		},

		async fetchQueryHighlight() {
			if (!this.queryHighlightSupported() || !this.search) return;
			if (!this.search.queryHighlightEnabled && !this.search.ran) {
				this.search.queryHighlightHtml = '';
				this.search.queryHighlightError = '';
				return;
			}
			const snippet = this.search.query || '';
			const url = this.getHighlightRequestUrl(snippet);
			const applyHighlightHtml = (html) => {
				this.search.queryHighlightHtml = html;
			};
			try {
				const response = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
				const data = await response.json().catch(() => null);
				if (!data) {
					applyHighlightHtml(this.escapeHtmlForPre(snippet));
					this.search.queryHighlightError = '';
					return;
				}
				let result = (data.done && data.done.result) ? data.done.result : (data.result != null ? data.result : null);
				if ((result == null || typeof result !== 'object') && typeof data === 'object' && (data.html != null || data.spans != null || data.markup != null)) {
					result = data;
				}
				const resultObj = result && typeof result === 'object' ? result : null;
				this.search.queryHighlightError = resultObj ? this.getHighlightParseError(resultObj) : '';
				if (resultObj) {
					const errorRange = this.getHighlightErrorRange(resultObj, snippet);
					const tokens = Array.isArray(resultObj.tokens) ? resultObj.tokens : null;
					const spans = Array.isArray(resultObj.spans) ? resultObj.spans : null;
					if (errorRange && tokens && tokens.length && typeof tokens[0].text === 'string' && tokens[0].kind != null) {
						applyHighlightHtml(this.buildHighlightHtmlFromTokens(tokens, errorRange, snippet));
						return;
					}
					if (errorRange && spans && spans.length) {
						applyHighlightHtml(this.buildHighlightHtmlFromSpans(snippet, spans, errorRange));
						return;
					}
					if (errorRange) {
						applyHighlightHtml(this.buildPlainHighlightHtml(snippet, errorRange));
						return;
					}
					const html = typeof resultObj.html === 'string' && resultObj.html.length > 0 ? resultObj.html
						: (typeof resultObj.markup === 'string' && resultObj.markup.length > 0 ? resultObj.markup
							: (typeof resultObj.output === 'string' && resultObj.output.length > 0 ? resultObj.output : null));
					if (html) {
						if (this.highlightHtmlMatchesSource(snippet, html)) {
							applyHighlightHtml(html);
							return;
						}
						// Backend returned highlight HTML but it does not match the source query.
						// Try token/span fallback only when backend provided complete coverage of
						// the original query; otherwise keep plain text to avoid half-highlights.
						if (
							tokens &&
							tokens.length &&
							typeof tokens[0].text === 'string' &&
							tokens[0].kind != null &&
							this.highlightTokensMatchSource(snippet, tokens)
						) {
							applyHighlightHtml(this.buildHighlightHtmlFromTokens(tokens, null, snippet));
							return;
						}
						if (spans && spans.length && this.highlightSpansMatchSource(snippet, spans)) {
							applyHighlightHtml(this.buildHighlightHtmlFromSpans(snippet, spans));
							return;
						}
						applyHighlightHtml(this.escapeHtmlForPre(snippet));
						return;
					}
					if (tokens && tokens.length && typeof tokens[0].text === 'string' && tokens[0].kind != null) {
						applyHighlightHtml(this.buildHighlightHtmlFromTokens(tokens, null, snippet));
						return;
					}
					if (spans && spans.length) {
						applyHighlightHtml(this.buildHighlightHtmlFromSpans(snippet, spans));
						return;
					}
				}
				if (typeof data.done === 'string' && data.done.length > 0) {
					applyHighlightHtml(data.done);
					this.search.queryHighlightError = '';
					return;
				}
				applyHighlightHtml(this.escapeHtmlForPre(snippet));
				this.search.queryHighlightError = '';
			} catch (err) {
				applyHighlightHtml(this.escapeHtmlForPre(snippet));
				this.search.queryHighlightError = '';
			}
		},

		highlightHtmlMatchesSource(sourceQuery, highlightHtml) {
			const normalize = (text) => String(text || '').replace(/\r\n/g, '\n').trim();
			const source = normalize(sourceQuery);
			if (!source) return true;
			const probe = document.createElement('div');
			probe.innerHTML = typeof highlightHtml === 'string' ? highlightHtml : '';
			const highlighted = normalize(probe.textContent || '');
			return highlighted === source;
		},

		highlightTokensMatchSource(sourceQuery, tokens) {
			if (typeof sourceQuery !== 'string') return false;
			if (!Array.isArray(tokens) || !tokens.length) return false;
			const joined = tokens.map((t) => (t && typeof t.text === 'string' ? t.text : String(t && t.text != null ? t.text : ''))).join('');
			return joined === sourceQuery;
		},

		highlightSpansMatchSource(sourceQuery, spans) {
			if (typeof sourceQuery !== 'string') return false;
			if (!Array.isArray(spans) || !spans.length) return false;
			let last = 0;
			for (const span of spans) {
				const start = Number(span && span.start);
				const end = Number(span && span.end);
				if (!Number.isFinite(start) || !Number.isFinite(end)) return false;
				if (start < 0 || end < start || end > sourceQuery.length) return false;
				if (start < last) return false;
				last = end;
			}
			return true;
		},

		getHighlightParseError(resultObj) {
			if (!resultObj || typeof resultObj !== 'object') return '';
			const raw = typeof resultObj.parse_error === 'string' ? resultObj.parse_error.trim() : '';
			if (!raw) return '';
			const errorType = typeof resultObj.parse_error_type === 'string' ? resultObj.parse_error_type.trim().toLowerCase() : '';
			const line = Number.isFinite(resultObj.parse_error_line) ? Number(resultObj.parse_error_line) : null;
			const column = Number.isFinite(resultObj.parse_error_column) ? Number(resultObj.parse_error_column) : null;
			const where = (line !== null && column !== null)
				? ` at line ${line}, column ${column}`
				: (line !== null ? ` at line ${line}` : '');
			if (errorType === 'syntax') return `Syntax error${where}: ${raw}`;
			return `Query error${where}: ${raw}`;
		},

		escapeHtmlForPre(text) {
			if (typeof text !== 'string') return '';
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		escapeHtmlForAttr(text) {
			// Attribute-safe escaping for strings we embed into HTML snippets.
			// We use the same escaping strategy as for <pre> since it escapes quotes as entities too.
			return this.escapeHtmlForPre(typeof text === 'string' ? text : '');
		},

		getHighlightErrorRange(resultObj, text) {
			if (!resultObj || typeof resultObj !== 'object') return null;
			const start = Number.isFinite(resultObj.parse_error_offset) ? Number(resultObj.parse_error_offset) : null;
			if (start === null || start < 0) return null;
			const textLength = typeof text === 'string' ? text.length : 0;
			const explicitEnd = Number.isFinite(resultObj.parse_error_end_offset) ? Number(resultObj.parse_error_end_offset) : null;
			let end = explicitEnd;
			if (end === null || end < start) {
				end = start < textLength ? start + 1 : start;
			}
			return {
				start,
				end,
			};
		},

		renderHighlightedSegment(text, className, segmentStart, errorRange) {
			const safeText = this.escapeHtmlForPre(text);
			if (!errorRange) {
				return `<span class="${className}">${safeText}</span>`;
			}
			const segmentEnd = segmentStart + text.length;
			const start = Number(errorRange.start);
			const end = Number(errorRange.end);
			if (!Number.isFinite(start) || !Number.isFinite(end)) {
				return `<span class="${className}">${safeText}</span>`;
			}
			if (end > start && start <= segmentStart && end >= segmentEnd && text.length > 0) {
				return `<span class="${className} pegerror">${safeText}</span>`;
			}
			if (start === end) {
				if (start < segmentStart || start > segmentEnd) {
					return `<span class="${className}">${safeText}</span>`;
				}
				const local = Math.max(0, Math.min(text.length, start - segmentStart));
				const before = this.escapeHtmlForPre(text.slice(0, local));
				const after = this.escapeHtmlForPre(text.slice(local));
				return `<span class="${className}">${before}<span class="flexicorp-hl-error-marker" aria-hidden="true"></span>${after}</span>`;
			}
			if (end <= segmentStart || start >= segmentEnd) {
				return `<span class="${className}">${safeText}</span>`;
			}
			const localStart = Math.max(0, start - segmentStart);
			const localEnd = Math.min(text.length, end - segmentStart);
			const before = this.escapeHtmlForPre(text.slice(0, localStart));
			const marked = this.escapeHtmlForPre(text.slice(localStart, localEnd));
			const after = this.escapeHtmlForPre(text.slice(localEnd));
			return `<span class="${className}">${before}<span class="flexicorp-hl-error">${marked}</span>${after}</span>`;
		},

		buildPlainHighlightHtml(text, errorRange, segmentStart = 0) {
			return this.renderHighlightedSegment(typeof text === 'string' ? text : '', 'flexicorp-hl-token', segmentStart, errorRange);
		},

		/** Build highlight HTML from flexicorp highlight API tokens: [{ text, kind }, ...]. */
		buildHighlightHtmlFromTokens(tokens, errorRange = null, originalSnippet = '') {
			if (!Array.isArray(tokens) || !tokens.length) return '';
			const parts = [];
			let offset = 0;
			const nextNonSpaceIndex = (idx) => {
				for (let i = idx + 1; i < tokens.length; i += 1) {
					const nt = tokens[i];
					const txt = typeof nt.text === 'string' ? nt.text : String(nt && nt.text != null ? nt.text : '');
					if (!/^\s*$/.test(txt)) return i;
				}
				return -1;
			};
			const keywordSet = new Set(['keyness', 'freq', 'count', 'dist', 'coll', 'dcoll', 'by', 'match', 'group', 'sort', 'tabulate', 'show', 'size']);
			for (let idx = 0; idx < tokens.length; idx += 1) {
				const t = tokens[idx];
				const text = typeof t.text === 'string' ? t.text : String(t.text ?? '');
				const kind = (t.kind != null && t.kind !== '') ? String(t.kind) : 'token';
				let safeClass = kind.replace(/[^a-z0-9_-]/gi, '') || 'token';
				const trimmed = text.trim();
				const low = trimmed.toLowerCase();
				if (trimmed) {
					if (keywordSet.has(low)) {
						safeClass = 'keyword';
					} else if (/^[A-Z][A-Z0-9_]*$/.test(trimmed)) {
						// Named-query variables (A, B, FOCUS, ...)
						safeClass = 'variable';
					} else if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(trimmed)) {
						const ni = nextNonSpaceIndex(idx);
						if (ni >= 0) {
							const ntext = typeof tokens[ni].text === 'string' ? tokens[ni].text : String(tokens[ni].text ?? '');
							if (ntext === '(') safeClass = 'function';
						}
					}
				}
				parts.push(this.renderHighlightedSegment(text, 'flexicorp-hl-' + safeClass, offset, errorRange));
				offset += text.length;
			}
			if (errorRange && errorRange.start === errorRange.end && errorRange.start === offset) {
				parts.push('<span class="flexicorp-hl-error-marker" aria-hidden="true"></span>');
			}
			if (typeof originalSnippet === 'string' && offset < originalSnippet.length) {
				const remainder = originalSnippet.slice(offset);
				parts.push(this.renderHighlightedSegment(remainder, 'flexicorp-hl-token', offset, errorRange));
			}
			return parts.join('');
		},

		buildHighlightHtmlFromSpans(text, spans, errorRange = null) {
			if (typeof text !== 'string' || !Array.isArray(spans) || !spans.length) return this.escapeHtmlForPre(text);
			const parts = [];
			let last = 0;
			for (const span of spans) {
				const start = Number(span.start);
				const end = Number(span.end);
				if (start > last) parts.push(this.buildPlainHighlightHtml(text.slice(last, start), errorRange, last));
				const cls = span.class || span.type || 'token';
				const segment = text.slice(start, end);
				parts.push(this.renderHighlightedSegment(segment, 'flexicorp-hl-' + String(cls).replace(/[^a-z0-9_-]/gi, ''), start, errorRange));
				last = end;
			}
			if (last < text.length) parts.push(this.renderHighlightedSegment(text.slice(last), 'flexicorp-hl-token', last, errorRange));
			if (errorRange && errorRange.start === errorRange.end && errorRange.start === text.length) {
				parts.push('<span class="flexicorp-hl-error-marker" aria-hidden="true"></span>');
			}
			return parts.join('');
		},

		/** HTML to show in the query highlight overlay (highlighted or plain escaped). Only used when highlight is enabled. */
		queryHighlightDisplayHtml() {
			if (!this.search || !this.search.queryHighlightEnabled) return '';
			if (this.search.queryHighlightHtml) return this.search.queryHighlightHtml;
			return this.escapeHtmlForPre(this.search.query ? this.search.query : '');
		},

		/** HTML for the collapsed query line: highlighted if available, otherwise escaped plain query. Always returns something when there is a query. */
		collapsedQueryDisplayHtml() {
			if (!this.search) return '';
			if (this.search.queryHighlightHtml) return this.search.queryHighlightHtml;
			return this.escapeHtmlForPre(this.search.query ? this.search.query : '');
		},

		/** HTML for the query in the results summary: same highlight as the query box when available. */
		searchSummaryQueryPlainHtml() {
			if (!this.search) return '';
			const q = this.search.query ? this.search.query : '';
			if (!q) return '';
			if (this.queryHighlightSupported() && this.search.queryHighlightHtml) {
				const probe = document.createElement('div');
				probe.innerHTML = String(this.search.queryHighlightHtml);
				const normalizeChars = (text) => String(text || '')
					.replace(/\r\n/g, '\n')
					.replace(/\s+/g, '');
				const highlightedText = normalizeChars(probe.textContent || '');
				const sourceText = normalizeChars(q);
				// Keep colorized summary unless highlight output is clearly truncated.
				const minLen = Math.floor(sourceText.length * 0.8);
				if (highlightedText === sourceText || highlightedText.length >= minLen) {
					return this.search.queryHighlightHtml;
				}
			}
			return this.escapeHtmlForPre(q);
		},

		syncQueryHighlightScroll(evtOrEl) {
			const textareaEl = evtOrEl && evtOrEl.target ? evtOrEl.target : evtOrEl;
			if (!textareaEl || textareaEl.tagName !== 'TEXTAREA') return;
			const wrap = textareaEl.closest('.flexicorp-query-wrap');
			const pre = wrap && wrap.querySelector('.flexicorp-query-highlight');
			if (pre) pre.scrollTop = textareaEl.scrollTop;
		},

		getRequestUrl() {
			const cur = new URL(window.location.href);
			const actionPick = cur.searchParams.get('action') || this.action || 'flexicorp';
			if (
				typeof window !== 'undefined' &&
				window.ttFlexicorpFns &&
				typeof window.ttFlexicorpFns.flexicorpAjaxPostUrl === 'function'
			) {
				try {
					return window.ttFlexicorpFns.flexicorpAjaxPostUrl(null, actionPick);
				} catch (_) {}
			}
			try {
				const url = new URL('index.php', window.location.href);
				url.searchParams.set('action', actionPick);
				const debug = cur.searchParams.get('debug');
				if (debug !== null && debug !== '') url.searchParams.set('debug', debug);
				const subc = cur.searchParams.get('subc');
				if (subc !== null && subc !== '') url.searchParams.set('subc', subc);
				return url.toString();
			} catch (_) {
				const url = new URL(window.location.href);
				const debug = url.searchParams.get('debug');
				const action = url.searchParams.get('action') || this.action;
				const subc = url.searchParams.get('subc');
				url.search = '';
				if (action) url.searchParams.set('action', action);
				if (debug) url.searchParams.set('debug', debug);
				if (subc) url.searchParams.set('subc', subc);
				return url.toString();
			}
		},

		async submitAjaxForm(form, loadingKey) {
			if (!form) return;
			const formData = new FormData(form);
			formData.set('ajax', '1');
			return this.submitAjaxData(formData, loadingKey);
		},

		async submitAjaxData(formData, loadingKey, options) {
			if (!formData) return;
			formData.set('ajax', '1');
			const opts = options && typeof options === 'object' ? options : {};
			const tabNavTokenAtStart = this._tabNavToken || 0;
			const ignoreActiveTab = !!opts.ignoreActiveTab;
			if (loadingKey === 'search') {
				this._pendingSearchAppend = !!opts.appendSearch;
			}
			this.loading[loadingKey] = true;
			try {
				let state = null;
				if (
					typeof window !== 'undefined' &&
					window.ttFlexicorpFns &&
					typeof window.ttFlexicorpFns.runFlexicorpAjaxFetch === 'function'
				) {
					const out = await window.ttFlexicorpFns.runFlexicorpAjaxFetch(
						formData,
						this.$root || null
					);
					if (!out || !out.response || !out.response.ok) {
						const status = out && out.response ? out.response.status : 'network';
						throw new Error(`Request failed with ${status}`);
					}
					if (out.parseError) throw out.parseError;
					state = out.state;
				} else {
					const response = await fetch(this.getRequestUrl(), {
						method: 'POST',
						body: formData,
						headers: { 'X-Requested-With': 'XMLHttpRequest' },
					});
					if (!response.ok) throw new Error(`Request failed with ${response.status}`);
					state = await response.json();
				}
				if (ignoreActiveTab && state && typeof state === 'object') {
					state.preserveClientTab = true;
				}
				this.applyState(state);
				// Late responses must not undo a tab the user chose while the request was in flight
				// (classic case: Stats → Corpus info probe returns after clicking Engines).
				if (
					ignoreActiveTab
					|| (this._tabNavToken || 0) !== tabNavTokenAtStart
				) {
					if (this._userActiveTab) {
						this.activeTab = this._userActiveTab;
						if (typeof this.syncFlexicorpSelectionToUrl === 'function') {
							this.syncFlexicorpSelectionToUrl();
						}
					}
				}
			} catch (err) {
				const debugEntry = this.buildDebugEntryFromFormData(formData, err);
				this.appendClientDebugEntry(debugEntry);
				if (loadingKey === 'reindex') {
					this.reindex = {
						ok: false,
						error: debugEntry.errors[0] || 'Request failed',
						errors: debugEntry.errors || [],
						warnings: [],
						result: debugEntry.result || null,
					};
				}
				this.rawTitle = 'Request failed';
				this.rawBody = err && err.message ? err.message : String(err);
				this.rawOpen = true;
			} finally {
				this.loading[loadingKey] = false;
				if (loadingKey === 'search') {
					this._pendingSearchAppend = false;
					this._pendingSearchUiViewMode = '';
				}
			}
		},

		searchPageSize() {
			return this.normalizePositiveInt(this.search && this.search.limit, 25);
		},

		searchSignatureFor(searchLike) {
			const s = searchLike && typeof searchLike === 'object' ? searchLike : (this.search || {});
			const cfg = this.settings && typeof this.settings === 'object' ? this.settings : {};
			const bits = [
				`b=${cfg.backend || ''}`,
				`qe=${cfg.queryEngine || ''}`,
				`ql=${cfg.queryLanguage || ''}`,
				`cf=${cfg.corpusFormat || ''}`,
				`q=${s.query || ''}`,
				`f=${s.field || ''}`,
				`v=${s.value || ''}`,
				`fmt=${s.contextFormat || ''}`,
				`scope=${s.contextScope || ''}`,
				`win=${this.normalizePositiveInt(s.window, this.defaultKwicWindow())}`,
				`merge=${cfg.groupHitsBySentence === false ? 0 : 1}`,
			];
			return bits.join('|');
		},

		searchLoadedItemCount() {
			const rows = this.searchRaw && Array.isArray(this.searchRaw.tableRows) ? this.searchRaw.tableRows : [];
			if (rows.length) return rows.length;
			const hits = this.searchRaw && Array.isArray(this.searchRaw.hits) ? this.searchRaw.hits : [];
			return hits.length;
		},

		submitSearchRequest({ append = false } = {}) {
			const signature = this.searchSignatureFor(this.search || {});
			const pageSize = this.searchPageSize();
			const canAppend = append && signature !== '' && signature === this.searchRawSignature;
			const start = canAppend ? this.searchLoadedItemCount() : 0;
			const uiViewMode = this.normalizeSearchViewMode(this.search && this.search.viewMode);
			this.debugViewModeLog('submitSearchRequest:before', {
				append: !!append,
				canAppend: !!canAppend,
				start,
				uiViewMode,
			});
			this.search.start = start;
			this._pendingSearchUiViewMode = uiViewMode;
			const formData = this.buildCommonRequestData();
			formData.set('active_tab', 'search');
			formData.set('run', 'query');
			// Canonical fetch mode should follow context scope (token windows need KWIC-sized payloads).
			formData.set('view_mode', this.canonicalSearchRequestViewMode());
			this.debugViewModeLog('submitSearchRequest:request', {
				requestViewMode: this.canonicalSearchRequestViewMode(),
			});
			formData.set('start', String(start));
			formData.set('kwic_limit', String(pageSize));
			return this.submitAjaxData(formData, 'search', { appendSearch: canAppend });
		},

		canonicalSearchRequestViewMode() {
			const scope = this.normalizeContextScopeValue(this.search && this.search.contextScope);
			if (scope === 'window' || scope === 'tok') return 'kwic';
			return 'table';
		},

		getEventForm(event) {
			if (!event) return null;
			if (event.currentTarget && event.currentTarget.tagName === 'FORM') return event.currentTarget;
			if (event.target && event.target.tagName === 'FORM') return event.target;
			if (event.target && typeof event.target.closest === 'function') return event.target.closest('form');
			return null;
		},

		getElementForm(element) {
			if (!element || typeof element.closest !== 'function') return null;
			return element.closest('form');
		},


		hasLockedReindexBackend() {
			const combos = Array.isArray(this.backendCombos) ? this.backendCombos : [];
			return combos.some((c) => c && c.reindex && c.reindex.locked);
		},

		async refreshOverviewIfReindexing() {
			if (!this.hasLockedReindexBackend()) {
				if (this._reindexOverviewTimerId) {
					clearInterval(this._reindexOverviewTimerId);
					this._reindexOverviewTimerId = null;
				}
				return;
			}
			const formData = this.buildCommonRequestData();
			formData.set('active_tab', 'overview');
			formData.set('run', '');
			await this.submitAjaxData(formData, 'backend');
		},

		updateReindexOverviewWatcher() {
			const hasLocked = this.hasLockedReindexBackend();
			if (hasLocked && !this._reindexOverviewTimerId) {
				this._reindexOverviewTimerId = setInterval(() => {
					this.refreshOverviewIfReindexing();
				}, 10000);
			} else if (!hasLocked && this._reindexOverviewTimerId) {
				clearInterval(this._reindexOverviewTimerId);
				this._reindexOverviewTimerId = null;
			}
		},

		buildCommonRequestData() {
			const formData = new FormData();
			formData.set('action', this.action || 'flexicorp');
			formData.set('backend', this.settings.backend || 'cqp');
			// Always send query_engine when the UI has one. PHP resolves FQS routing and sticky session
			// from this; omitting it for cqp/manatee left $_REQUEST['query_engine'] empty so the server
			// fell back to last_query_engine (e.g. pando) while the header showed manatee-cql.
			if (this.settings.queryEngine) {
				formData.set('query_engine', this.settings.queryEngine);
			}
			if (this.settings.queryLanguage) {
				formData.set('query_language', this.settings.queryLanguage);
			}
			if (this.settings.corpusFormat) {
				formData.set('corpus_format', this.settings.corpusFormat);
			}
			if (this.backendOverrides && this.backendOverrides.blacklab_url) formData.set('blacklab_url', this.backendOverrides.blacklab_url);
			if (this.backendOverrides && this.backendOverrides.blacklab_corpus) formData.set('blacklab_corpus', this.backendOverrides.blacklab_corpus);
			if (this.backendOverrides && this.backendOverrides.blacklab_user) formData.set('blacklab_user', this.backendOverrides.blacklab_user);
			if (this.backendOverrides && this.backendOverrides.blacklab_password) formData.set('blacklab_password', this.backendOverrides.blacklab_password);
			if (this.backendOverrides && this.backendOverrides.blacklab_field) formData.set('blacklab_field', this.backendOverrides.blacklab_field);
			formData.set('docs_limit', String(this.settings.docsLimit || 20));
			formData.set('docs_per_page', String(this.documentsUi.perPage || 20));
			formData.set('docs_filter', this.documentsUi.filter || '');
			let outgoingQuery = this.search.query || '';
			if (
				typeof this.isCwbBackend === 'function' &&
				this.isCwbBackend(this.settings) &&
				typeof globalThis !== 'undefined' &&
				globalThis.FlexicorpCwb &&
				typeof globalThis.FlexicorpCwb.normalizeOutgoingQuery === 'function'
			) {
				outgoingQuery = globalThis.FlexicorpCwb.normalizeOutgoingQuery(outgoingQuery, this.settings);
			}
			formData.set('query', outgoingQuery);
			formData.set('field', this.search.field || '');
			formData.set('value', this.search.value || '');
			formData.set('context_format', this.search.contextFormat || 'text');
			formData.set(
				'context_scope',
				this.search.contextScope
					|| (this.executionUsesPandoContextDefault() ? 'tok' : 'window')
			);
			formData.set('view_mode', this.search.viewMode || 'table');
			formData.set('group_hits_by_sentence', this.settings && this.settings.groupHitsBySentence === false ? '0' : '1');
			formData.set(
				'window',
				String(
					this.search && this.search.window != null && this.search.window > 0
						? this.search.window
						: this.defaultKwicWindow()
				)
			);
			const outgoingScope = this.normalizeContextScopeValue(this.search && this.search.contextScope);
			const outgoingFormat = String(this.search && this.search.contextFormat ? this.search.contextFormat : 'text').toLowerCase();
			// Request fragment extraction by cpos window span when scope is token-window XML.
			// This prevents backend fragment requests from collapsing to a single match token (e.g. dtok hits).
			if ((outgoingScope === 'window' || outgoingScope === 'tok') && outgoingFormat === 'xml') {
				formData.set('flexicorp_fragment_kwic_cpos_span', '1');
			}
			formData.set('kwic_limit', String(this.search.limit || 25));
			formData.set('start', String(this.search.start || 0));
			if (Array.isArray(this.frequency.formFields) && this.frequency.formFields.length > 0) {
				this.frequency.formFields.forEach(f => formData.append('freq_field[]', f));
			} else if (Array.isArray(this.frequency.fields) && this.frequency.fields.length > 0) {
				this.frequency.fields.forEach(f => formData.append('freq_field[]', f));
			} else {
				formData.set('freq_field', this.frequency.field || '');
			}
			formData.set('freq_limit', String(this.frequency.limit || 100));
			if (this.collocation) {
				formData.set('coll_field', this.collocation.field || 'lemma');
				formData.set(
					'coll_anchor_token',
					this.collocation.anchorToken != null ? String(this.collocation.anchorToken).trim() : ''
				);
				formData.set('coll_left', String(this.collocation.left != null ? this.collocation.left : 5));
				formData.set('coll_right', String(this.collocation.right != null ? this.collocation.right : 5));
				formData.set('coll_min_freq', String(this.collocation.minFreq != null ? this.collocation.minFreq : 1));
				formData.set('coll_max_items', String(this.collocation.maxItems != null ? this.collocation.maxItems : 100));
				formData.set('coll_stoplist', String(this.collocation.stoplist != null ? this.collocation.stoplist : 0));
				let mk =
					typeof this.normalizeCollocationMeasureKeys === 'function'
						? this.normalizeCollocationMeasureKeys(this.collocation.measureKeys)
						: (Array.isArray(this.collocation.measureKeys) ? this.collocation.measureKeys : ['logdice']);
				// Single comma-separated field: reliable for POST; PHP also accepts coll_measures[] arrays.
				formData.set('coll_measures', mk.join(','));
			}
			formData.set('active_tab', this.activeTab || 'search');
			return formData;
		},

		searchLimitOptions() {
			const presets = [10, 25, 50, 100, 200];
			const current = this.normalizePositiveInt(this.search && this.search.limit, 25);
			if (!presets.includes(current)) presets.push(current);
			return presets.sort((a, b) => a - b);
		},

		computeAdaptiveSearchLimit() {
			const fallback = this.normalizePositiveInt(
				(this.settings && this.settings.kwicLimit) || (this.search && this.search.limit),
				25
			);
			if (typeof window === 'undefined' || !Number.isFinite(window.innerHeight)) return fallback;
			// Approximate space available for rows after toolbar/form/meta blocks.
			const availablePx = Math.max(320, Number(window.innerHeight) - 300);
			// Slightly relaxed compact-row estimate (header + line-height + vertical paddings).
			const rowPx = 30;
			let rows = Math.floor(availablePx / rowPx);
			rows = Math.max(20, Math.min(120, rows));
			// Keep controls tidy with 5-step increments.
			const rounded = Math.round(rows / 5) * 5;
			return Math.max(20, rounded);
		},

		applyAutoSearchLimit({ force = false } = {}) {
			if (!this.search || typeof this.search !== 'object') return;
			if (!force && !this.searchLimitAuto) return;
			// Avoid changing visible result count mid-session after a run.
			if (this.search.ran) return;
			const next = this.computeAdaptiveSearchLimit();
			if (!Number.isFinite(next) || next < 1) return;
			this.search.limit = next;
			this._autoSearchLimitLast = next;
		},

		onSearchLimitChanged(event) {
			const raw = event && event.target ? event.target.value : (this.search && this.search.limit);
			const next = this.normalizePositiveInt(raw, this.searchPageSize());
			this.search.limit = next;
			this.searchLimitAuto = false;
		},

		onSearchWindowChanged(event) {
			if (!this.search || typeof this.search !== 'object') return;
			const raw = event && event.target ? event.target.value : this.search.window;
			const next = this.normalizePositiveInt(raw, this.defaultKwicWindow());
			const prev = this.normalizePositiveInt(this.search.window, this.defaultKwicWindow());
			this.search.window = next;
			if (next === prev) return;
			// Window-size changes affect payload shape; rerun so backend returns matching token context.
			if (this.search.ran && !this.isLoading('search') && (this.search.query || this.search.value)) {
				this.submitSearchRequest({ append: false });
			}
		},

		/** Recent queries for the active query_language (CQL dialect); updates when dialect changes. */
		recentQueriesList() {
			const ql = this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage) : '';
			const m = this.recentQueriesByDialect || {};
			if (ql && Array.isArray(m[ql]) && m[ql].length) return m[ql];
			if (Array.isArray(this.recentQueries) && this.recentQueries.length) return this.recentQueries;
			return [];
		},

		/**
		 * Normalize query text for recent-list deduplication (must match PHP
		 * tt_flexicorp_normalize_recent_query_text): collapse Unicode whitespace runs
		 * so entries that differ only by spaces/newlines/NBSP map to one slot.
		 */
		normalizeRecentQueryText(s) {
			let t = typeof s === 'string' ? s : String(s || '');
			if (t.charCodeAt(0) === 0xfeff) t = t.slice(1);
			t = t.trim();
			if (!t) return '';
			t = t.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
			t = t.replace(/\s+/g, ' ').trim();
			return t;
		},

		/** Same as recentQueriesList but order-preserving dedupe by normalized string (session list). */
		recentQueriesListDeduped() {
			const list = this.recentQueriesList();
			if (!Array.isArray(list) || !list.length) return [];
			const seen = new Set();
			const out = [];
			for (let i = 0; i < list.length; i++) {
				const raw = typeof list[i] === 'string' ? list[i] : String(list[i] || '');
				const k = this.normalizeRecentQueryText(raw);
				if (!k || seen.has(k)) continue;
				seen.add(k);
				out.push(k);
			}
			return out;
		},

		shortQueryOptionLabel(q) {
			const s = typeof q === 'string' ? q : String(q || '');
			return s.length > 100 ? s.slice(0, 97) + '…' : s;
		},

		teitokQueryManagerType() {
			const ql = this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage).trim() : '';
			return ql || 'cqp';
		},

		storedQueriesManagerUrl() {
			const t = encodeURIComponent(this.teitokQueryManagerType());
			return `index.php?action=querymng&type=${t}`;
		},

		/**
		 * Intro + example queries (corpus / getlang / default). Hidden in the collapsed summary view
		 * (after a search when the query form is closed); shown again when the user clicks "Change".
		 */
		showSearchIntroPanel() {
			const html = typeof this.searchIntroHtml === 'string' ? this.searchIntroHtml.trim() : '';
			const ex = Array.isArray(this.searchExampleQueries) ? this.searchExampleQueries : [];
			if (!(html || ex.length)) return false;
			if (!this.search) return false;
			if (this.search.ran && !this.search.showQueryForm) return false;
			return true;
		},

		/** Short “Enter a query…” line below Hit limit (outside the textarea / intro box). */
		showSearchIntroLeadFoot() {
			const lead = typeof this.searchIntroLeadHtml === 'string' ? this.searchIntroLeadHtml.trim() : '';
			if (!lead) return false;
			if (!this.search) return false;
			if (this.search.ran && !this.search.showQueryForm) return false;
			return true;
		},
		noQueryBackendAvailable() {
			const engines = Array.isArray(this.availableQueryEngines) ? this.availableQueryEngines : [];
			return engines.length === 0 || !String(this.settings && this.settings.queryEngine ? this.settings.queryEngine : '').trim();
		},
		searchUnavailableMessage() {
			return 'Search not available: no query backend is configured for this corpus.';
		},

		/** When query engine dropdown changes: flexi updates queryEngine; cqp/manatee switches backend and submits. */
		onQueryEngineChange(event) {
			const value = event && event.target ? event.target.value : '';
			if (!value) return;
			if (this.settings.backend === 'flexi') {
				this.settings.queryEngine = value;
				if (value === 'pando') {
					this.settings.backend = 'pando';
					this.settings.queryLanguage = 'pando-cql';
					this.settings.corpusFormat = 'pando';
				} else if (value === 'manatee') {
					this.settings.queryLanguage = 'manatee-cql';
					this.settings.corpusFormat = 'manatee';
				} else if (value === 'cqp') {
					this.settings.queryLanguage = 'cwb-cql';
					this.settings.corpusFormat = 'cwb';
				}
				this.syncFlexicorpSelectionToUrl();
				this.persistFlexicorpSelectionToSessionStorage();
			} else if (this.settings.backend === 'cqp' || this.settings.backend === 'manatee') {
				if (value === 'pando') {
					this.settings.backend = 'pando';
					this.settings.queryEngine = 'pando';
					this.settings.queryLanguage = 'pando-cql';
					this.settings.corpusFormat = 'pando';
					this.syncFlexicorpSelectionToUrl();
					this.persistFlexicorpSelectionToSessionStorage();
				} else if (value === 'manatee') {
					this.settings.backend = 'manatee';
					this.settings.queryEngine = 'manatee';
					this.settings.queryLanguage = 'manatee-cql';
					this.settings.corpusFormat = 'manatee';
					this.syncFlexicorpSelectionToUrl();
					this.persistFlexicorpSelectionToSessionStorage();
				} else {
					this.settings.backend = value;
					this.settings.queryEngine = value;
					this.submitBackendFromButton();
				}
			}
		},

		/** When backend selector changes in the toolbar, pick a sensible (available) combination for it. */
		onBackendChanged() {
			const backend = this.settings && this.settings.backend ? this.settings.backend : 'cqp';
			const combos = (Array.isArray(this.backendCombos) ? this.backendCombos : []).filter(
				(c) => c.backend === backend && c.available
			);
			if (combos.length) {
				const first = combos[0];
				this.settings.queryLanguage = first.queryLanguage;
				this.settings.corpusFormat = first.corpusFormat;
				if (backend === 'pando' || backend === 'flexicorp-pando') this.settings.queryEngine = 'pando';
				else if (backend === 'cqp' || backend === 'manatee' || backend === 'blacklab' || backend === 'clickql' || backend === 'clickhouse') this.settings.queryEngine = backend;
			}
		},

		/** From overview: switch to a full (backend, query-language, corpus-format) combination and run Compare (Details). */
		useBackendCombination(combo) {
			if (!this.canUseBackendCombination(combo)) return;
			this.settings.backend = combo.backend;
			this.settings.queryLanguage = combo.queryLanguage;
			this.settings.corpusFormat = combo.corpusFormat;
			if (this.settings.backend === 'flexi' && this.settings.queryEngine) {
				// keep flexi queryEngine (cqp/manatee) as-is
			} else if (this.settings.backend === 'pando' || this.settings.backend === 'flexicorp-pando') {
				this.settings.queryEngine = 'pando';
			} else if (['cqp', 'manatee'].includes(this.settings.backend)) {
				this.settings.queryEngine = this.settings.backend;
			} else if (['blacklab', 'clickql', 'clickhouse'].includes(this.settings.backend)) {
				this.settings.queryEngine = this.settings.backend;
			}
			this.submitBackendFromButton();
		},

		/** Backends that share the same underlying storage (reindexing one affects all). */
		reindexAffectsBackend(targetBackend, comboOrBackend) {
			const comboBackend = typeof comboOrBackend === 'object' && comboOrBackend !== null
				? this.reindexBackendForCombo(comboOrBackend) : comboOrBackend;
			if (targetBackend === comboBackend) return true;
			const chGroup = ['clickhouse', 'clickql'];
			return chGroup.includes(targetBackend) && chGroup.includes(comboBackend);
		},

		isReindexingCombo(combo) {
			if (!combo) return false;
			const targets = Array.isArray(this.reindexTargetBackends) ? this.reindexTargetBackends : [];
			const targetAffectsCombo = targets.some((b) => this.reindexAffectsBackend(b, combo));
			const loading = !!(this.loading && this.loading.reindex);
			const polling =
				!!(this.reindex && this.reindex.result && this.reindex.result.indexer && this.reindex.result.indexer.job_id);
			const immediate = targetAffectsCombo && (loading || polling);
			const resultBackends = this.reindex && this.reindex.result && this.reindex.result.reindex_backends;
			const enqueued =
				!!resultBackends &&
				Array.isArray(resultBackends) &&
				resultBackends.some((b) => this.reindexAffectsBackend(b, combo)) &&
				(this.reindex.result.status === 'enqueued' || !!(this.reindex.result.indexer && this.reindex.result.indexer.enqueued));
			const locked = !!(combo.reindex && combo.reindex.locked);
			return immediate || enqueued || locked;
		},

		comboStatusLabel(combo) {
			if (this.isReindexingCombo(combo)) return 'Reindexing';
			return combo && combo.available ? 'Available' : 'Unavailable';
		},

		comboReasonText(combo) {
			if (this.isReindexingCombo(combo)) return 'Reindex currently running for this backend.';
			return combo && combo.reason ? combo.reason : '';
		},

		reindexProgress() {
			const p = this.reindex && this.reindex.result && this.reindex.result.progress;
			return p && typeof p === 'object' ? p : null;
		},

		reindexProgressPct() {
			const p = this.reindexProgress();
			if (!p || p.pct == null) return null;
			const n = Number(p.pct);
			if (!Number.isFinite(n)) return null;
			return Math.max(0, Math.min(100, n));
		},

		reindexProgressLabel() {
			const p = this.reindexProgress();
			if (!p) return '';
			const parts = [];
			if (p.phase) parts.push(String(p.phase));
			if (p.manatee_current) {
				parts.push(String(p.manatee_current));
			}
			if (p.manatee_done != null && p.manatee_total != null) {
				parts.push(`mkstats ${p.manatee_done}/${p.manatee_total}`);
			}
			if (p.files_done != null && p.files_total != null) {
				parts.push(`${p.files_done}/${p.files_total} files`);
			}
			if (p.bytes_done != null && p.bytes_total != null) {
				parts.push(`${p.bytes_done}/${p.bytes_total} bytes`);
			}
			return parts.join(' - ');
		},

		isFqsReindexResultMessage() {
			const result = this.reindex && this.reindex.result && typeof this.reindex.result === 'object'
				? this.reindex.result
				: null;
			if (!result) return false;
			return String(result.source || '').toLowerCase() === 'fqs';
		},

		canUseBackendCombination(combo) {
			return !!(combo && combo.available && !combo.isCurrent && !this.isReindexingCombo(combo));
		},

		canChooseCorpora(combo) {
			// Corpora switcher disabled: would allow access to other corpora on the same BlackLab server.
			return false;
		},

		canReindexBackend(combo) {
			return !!(
				this.isAdmin &&
				combo &&
				combo.capabilities &&
				combo.capabilities.reindex &&
				(combo.reindexAvailable !== false)
			);
		},

		selectedBlacklabCorpusId() {
			return this.backendOverrides && this.backendOverrides.blacklab_corpus ? String(this.backendOverrides.blacklab_corpus) : '';
		},

		closeCorpora() {
			this.corporaOpen = false;
			this.corporaLoading = false;
			this.corporaError = '';
			this.corporaTargetCombo = null;
		},

		async openBackendCorpora(combo) {
			if (this.isReindexingCombo(combo)) return;
			this.corporaOpen = true;
			this.corporaLoading = true;
			this.corporaError = '';
			this.corporaList = [];
			this.corporaTargetCombo = combo || null;
			this.corporaTitle = `Available corpora for ${this.getBackendLabel('blacklab')}`;
			const formData = this.buildCommonRequestData();
			formData.set('backend', 'blacklab');
			formData.set('ajax', '1');
			formData.set('list_corpora', '1');
			if (combo && combo.queryLanguage) formData.set('query_language', combo.queryLanguage);
			if (combo && combo.corpusFormat) formData.set('corpus_format', combo.corpusFormat);
			try {
				let payload = null;
				if (
					typeof window !== 'undefined' &&
					window.ttFlexicorpFns &&
					typeof window.ttFlexicorpFns.runFlexicorpAjaxFetch === 'function'
				) {
					const out = await window.ttFlexicorpFns.runFlexicorpAjaxFetch(
						formData,
						this.$root || null
					);
					if (!out || !out.response || !out.response.ok) {
						const status = out && out.response ? out.response.status : 'network';
						throw new Error(`Request failed with ${status}`);
					}
					if (out.parseError) throw out.parseError;
					payload = out.state;
				} else {
					const response = await fetch(this.getRequestUrl(), {
						method: 'POST',
						body: formData,
						headers: { 'X-Requested-With': 'XMLHttpRequest' },
					});
					if (!response.ok) throw new Error(`Request failed with ${response.status}`);
					payload = await response.json();
				}
				const errors = []
					.concat(payload && payload.error ? [payload.error] : [])
					.concat(Array.isArray(payload && payload.errors) ? payload.errors : []);
				if (errors.length) {
					this.corporaError = errors.join(' ');
					return;
				}
				const result = payload && payload.result && typeof payload.result === 'object' ? payload.result : {};
				this.corporaList = Array.isArray(result.corpora) ? result.corpora : [];
			} catch (err) {
				this.corporaError = err && err.message ? err.message : String(err);
			} finally {
				this.corporaLoading = false;
			}
		},

		chooseBlacklabCorpus(corpusId) {
			const target = String(corpusId || '').trim();
			if (!target) return;
			this.backendOverrides.blacklab_corpus = target;
			this.settings.backend = 'blacklab';
			this.settings.queryLanguage = (this.corporaTargetCombo && this.corporaTargetCombo.queryLanguage) ? this.corporaTargetCombo.queryLanguage : 'bcql';
			this.settings.corpusFormat = (this.corporaTargetCombo && this.corporaTargetCombo.corpusFormat) ? this.corporaTargetCombo.corpusFormat : 'blacklab';
			this.corporaOpen = false;
			this.submitBackendFromButton();
		},

		/** Poll reindex job status (BlackLab queue) until done or failed. Updates this.reindex and clears reindexTargetComboId when finished. */
		async pollReindexStatusUntilFinished(jobId) {
			const delayMs = 2500;
			const maxPolls = 240; // ~10 min
			for (let i = 0; i < maxPolls; i++) {
				const formData = new FormData();
				formData.set('action', this.action || 'flexicorp');
				formData.set('ajax', '1');
				formData.set('reindex_status', '1');
				formData.set('reindex_job_id', jobId);
				if (this.projectRoot) formData.set('projectRoot', this.projectRoot);
				try {
					let data = null;
					if (
						typeof window !== 'undefined' &&
						window.ttFlexicorpFns &&
						typeof window.ttFlexicorpFns.runFlexicorpAjaxFetch === 'function'
					) {
						const out = await window.ttFlexicorpFns.runFlexicorpAjaxFetch(
							formData,
							this.$root || null
						);
						if (!out || !out.response || !out.response.ok || out.parseError) continue;
						data = out.state;
					} else {
						const response = await fetch(this.getRequestUrl(), { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
						if (!response.ok) continue;
						data = await response.json();
					}
					const status = data && data.result && data.result.status ? data.result.status : '';
					const progress = data && data.result && data.result.progress ? data.result.progress : null;
					const source = data && data.result && data.result.source ? String(data.result.source) : 'background';
					if (source === 'fqs' && typeof this.onFqsReindexFeedback === 'function') {
						this.onFqsReindexFeedback(data && data.result ? data.result : null);
					}
					const reindexBackends = (data && data.result && Array.isArray(data.result.reindex_backends))
						? data.result.reindex_backends
						: ((this.reindex && this.reindex.result && Array.isArray(this.reindex.result.reindex_backends)) ? this.reindex.result.reindex_backends : []);
					const backendLabel = reindexBackends.length
						? ` for ${reindexBackends.map((b) => this.getBackendLabel(String(b || ''))).join(', ')}`
						: '';
					const durationSec = (data && data.result && data.result.duration_sec != null) ? Number(data.result.duration_sec) : null;
					const durationLabel = (durationSec != null && Number.isFinite(durationSec) && durationSec >= 0)
						? ` in ${durationSec.toFixed(durationSec >= 10 ? 0 : 1)}s`
						: '';
					const flexBin = data && data.result && data.result.flexencoder_binary ? String(data.result.flexencoder_binary) : '';
					const sourceLabel = source ? ` via ${source}` : '';
					const jobLabel = jobId ? ` (job ${jobId})` : '';
					if (status === 'pending' && progress) {
						const pct = progress.pct != null ? String(progress.pct) : '';
						const phase = progress.phase ? String(progress.phase) : '';
						const msg = pct !== ''
							? `Background reindex${jobLabel}${backendLabel}${sourceLabel}: ${pct}%${phase ? ` (${phase})` : ''}`
							: (phase !== ''
								? `Background reindex${jobLabel}${backendLabel}${sourceLabel}: ${phase}`
								: `Background reindex${jobLabel}${backendLabel}${sourceLabel} running…`);
						this.reindex = {
							...(this.reindex || {}),
							ok: true,
							error: '',
							errors: [],
							warnings: [],
							result: {
								...(this.reindex && this.reindex.result ? this.reindex.result : {}),
								message: msg,
								progress,
								source,
								job_id: jobId,
								reindex_backends: reindexBackends,
								duration_sec: durationSec,
								flexencoder_binary: flexBin || (this.reindex && this.reindex.result ? this.reindex.result.flexencoder_binary : ''),
							},
						};
					}
					if (status === 'done') {
						const binNote = flexBin ? ` (flexencoder: ${flexBin})` : '';
						this.reindex = {
							ok: true,
							error: '',
							errors: [],
							warnings: [],
							result: {
								message: `Background reindex${jobLabel}${backendLabel}${sourceLabel} completed${durationLabel}.${binNote}`,
								stdout: data.result.stdout,
								stderr: data.result.stderr,
								source,
								job_id: jobId,
								progress,
								reindex_backends: reindexBackends,
								duration_sec: durationSec,
								flexencoder_binary: flexBin,
							},
						};
						this.reindexTargetComboId = '';
						return;
					}
					if (status === 'failed') {
						const rawErr = data.result && data.result.error ? data.result.error : 'Reindex failed.';
						if (source === 'fqs' && typeof this.appendClientDebugEntry === 'function') {
							this.appendClientDebugEntry({
								time: new Date().toLocaleTimeString(),
								backend: 'fqs',
								operation: 'reindex_status',
								ok: false,
								command: '',
								raw: '',
								errors: [String(rawErr)],
								warnings: [],
								result: data && data.result ? data.result : {},
							});
						}
						this.reindex = {
							ok: false,
							error: `Background reindex${jobLabel}${backendLabel}${sourceLabel} failed${durationLabel}: ${rawErr}`,
							errors: [`Background reindex${jobLabel}${backendLabel}${sourceLabel} failed${durationLabel}: ${rawErr}`],
							warnings: [],
							result: { ...(data.result || {}), source, job_id: jobId, progress, reindex_backends: reindexBackends, duration_sec: durationSec, flexencoder_binary: flexBin },
						};
						this.reindexTargetComboId = '';
						return;
					}
				} catch (_) { /* ignore poll errors, retry */ }
				await new Promise(r => setTimeout(r, delayMs));
			}
			this.reindex = { ok: false, error: 'Reindex status poll timed out.', errors: ['Timed out waiting for job.'], warnings: [], result: null };
			this.reindexTargetComboId = '';
		},

		async runBackendReindex(combo) {
			if (!this.canReindexBackend(combo)) return;
			const reindexBackend = this.reindexBackendForCombo(combo);
			const backendLabel = this.getBackendLabel(combo.backend);
			const target = combo.backend === 'blacklab' && this.selectedBlacklabCorpusId()
				? ` for corpus ${this.selectedBlacklabCorpusId()}`
				: '';
			const ok = window.confirm(`Run reindex for ${backendLabel}${target}?`);
			if (!ok) return;
			const listingDescription = this.askListingDescription();
			if (listingDescription === null) return;
			const formData = new FormData();
			if (listingDescription) formData.set('corpus_description', listingDescription);
			formData.set('action', this.action || 'flexicorp');
			formData.set('backend', combo.backend);
			formData.set('reindex_backend', combo.backend);
			formData.append('reindex_backends[]', reindexBackend);
			if (combo.backend === 'blacklab') formData.set('blacklab_reindex_engine', 'docker');
			if (this.backendOverrides && this.backendOverrides.blacklab_url) formData.set('blacklab_url', this.backendOverrides.blacklab_url);
			if (this.backendOverrides && this.backendOverrides.blacklab_corpus) formData.set('blacklab_corpus', this.backendOverrides.blacklab_corpus);
			if (this.backendOverrides && this.backendOverrides.blacklab_user) formData.set('blacklab_user', this.backendOverrides.blacklab_user);
			if (this.backendOverrides && this.backendOverrides.blacklab_password) formData.set('blacklab_password', this.backendOverrides.blacklab_password);
			if (this.backendOverrides && this.backendOverrides.blacklab_field) formData.set('blacklab_field', this.backendOverrides.blacklab_field);
			formData.set('active_tab', 'overview');
			formData.set('run', 'reindex');
			this.reindex = {};
			if (this.responses && typeof this.responses === 'object') {
				this.responses.reindex = {};
			}
			this.reindexTargetComboId = combo.id || '';
			this.reindexTargetBackends = [reindexBackend];
			try {
				await this.submitAjaxData(formData, 'reindex');
				const idx = this.reindex && this.reindex.result && this.reindex.result.indexer;
				const enqueued = !!(this.reindex && this.reindex.ok && idx && idx.enqueued && idx.job_id);
				if (enqueued) {
					await this.pollReindexStatusUntilFinished(this.reindex.result.indexer.job_id);
				}
			} finally {
				this.reindexTargetComboId = '';
				this.reindexTargetBackends = [];
			}
		},

		/**
		 * Indexing puts the corpus in the corpus list, which shows its description: when it
		 * has none, ask for one. Returns the text, '' when not needed, null when cancelled.
		 */
		askListingDescription() {
			const cl = this.corpusListing;
			if (!cl || !cl.descriptionRequired || (cl.description && cl.description.trim())) return '';
			const text = window.prompt(
				'This corpus has no description yet. Indexing lists it in the corpus list, which shows '
				+ 'what it is: write one or two sentences about it (you can change it later as the page "description").'
			);
			if (text === null) return null;
			if (!text.trim()) {
				window.alert('Indexing needs a short description of the corpus.');
				return null;
			}
			cl.description = text.trim();
			return text.trim();
		},

		toggleReindexSelection(combo) {
			if (!this.canReindexBackend(combo)) return;
			const id = combo && combo.id;
			if (!id) return;
			this.reindexSelection = { ...this.reindexSelection, [id]: !this.reindexSelection[id] };
		},

		async runReindexSelected() {
			const backends = this.selectedReindexBackends();
			if (!backends || backends.length === 0) return;
			const label = backends.map((b) => this.getBackendLabel(b)).join(', ');
			const ok = window.confirm(`Run reindex for selected backends (${label})? This will use flexencoder once for CQP/ClickHouse, then BlackLab separately if selected.`);
			if (!ok) return;
			const listingDescription = this.askListingDescription();
			if (listingDescription === null) return;
			const formData = this.buildCommonRequestData();
			if (listingDescription) formData.set('corpus_description', listingDescription);
			formData.set('active_tab', 'overview');
			formData.set('run', 'reindex');
			backends.forEach((b) => formData.append('reindex_backends[]', b));
			this.reindex = {};
			if (this.responses && typeof this.responses === 'object') this.responses.reindex = {};
			this.reindexTargetComboId = 'selected';
			this.reindexTargetBackends = [...backends];
			try {
				await this.submitAjaxData(formData, 'reindex');
				const idxSel = this.reindex && this.reindex.result && this.reindex.result.indexer;
				const enqueuedSel = !!(this.reindex && this.reindex.ok && idxSel && idxSel.enqueued && idxSel.job_id);
				if (enqueuedSel) {
					await this.pollReindexStatusUntilFinished(this.reindex.result.indexer.job_id);
				}
				this.reindexSelection = {};
			} finally {
				this.reindexTargetComboId = '';
				this.reindexTargetBackends = [];
			}
		},

		/** From overview: run status/info for a combination, then show status details in a popup, without changing current selection. */
		async useBackendStatus(combo) {
			if (!combo || this.isReindexingCombo(combo)) return;
			// First optimization step: when FQS is available and the requested combo is already
			// the current one, reuse loaded status/info instead of issuing another PHP round-trip.
			const sameAsCurrent =
				!!combo.isCurrent
				|| (
					String(combo.backend || '') === String(this.settings && this.settings.backend ? this.settings.backend : '')
					&& String(combo.queryLanguage || '') === String(this.settings && this.settings.queryLanguage ? this.settings.queryLanguage : '')
					&& String(combo.corpusFormat || '') === String(this.settings && this.settings.corpusFormat ? this.settings.corpusFormat : '')
				);
			if (typeof this.isFqsAvailable === 'function' && this.isFqsAvailable() && sameAsCurrent) {
				this.statusDetailsOpen = true;
				return;
			}
			// Build a request for the chosen combination, but keep the current settings unchanged.
			const formData = this.buildCommonRequestData();
			formData.set('backend', combo.backend);
			if (combo.queryLanguage) formData.set('query_language', combo.queryLanguage);
			if (combo.corpusFormat) formData.set('corpus_format', combo.corpusFormat);
			formData.set('active_tab', 'overview');
			// We are interested in status/info only, not running search or quantitative (freq) requests.
			formData.set('run', '');
			await this.submitAjaxData(formData, 'backend');
			// After the AJAX call updates state, open the structured status dialog.
			this.statusDetailsOpen = true;
		},

		submitBackend(event) {
			this.submitAjaxForm(this.getEventForm(event), 'backend');
		},

		submitBackendFromButton(element) {
			const formData = this.buildCommonRequestData();
			formData.set('active_tab', this.activeTab || 'overview');
			const run = this.currentRunValue();
			if (run) formData.set('run', run);
			this.submitAjaxData(formData, 'backend');
		},

		submitDocuments(event) {
			this.submitAjaxForm(this.getEventForm(event), 'documents');
		},

		submitSearch(event) {
			this.submitSearchRequest({ append: false });
		},

		/** Store the current query via TEITOK's query manager (querymng.php). */
		storeCurrentQueryToTeitok() {
			const form = document.getElementById('flexicorp-teitok-store-query');
			if (!form) return;
			const t = encodeURIComponent(this.teitokQueryManagerType());
			form.setAttribute('action', `index.php?action=querymng&type=${t}&act=save`);
			const textarea = form.querySelector('textarea[name="query"]');
			if (!textarea) return;
			let q = '';
			if (this.search && typeof this.search.query === 'string' && this.search.query.trim()) {
				q = this.search.query.trim();
			} else {
				const field = (this.search && typeof this.search.field === 'string' ? this.search.field : 'lemma').trim();
				const value = (this.search && typeof this.search.value === 'string' ? this.search.value : '').trim();
				if (field && value) {
					const safe = value.replace(/"/g, '\\"');
					q = `[${field}="${safe}"]`;
				}
			}
			if (!q) return;
			textarea.value = q;
			form.submit();
		},

		submitSearchFromButton(element) {
			this.submitSearchRequest({ append: false });
		},

		canShowMoreSearch() {
			if (!this.search || !this.search.ran || this.callHasIssues(this.search.response)) return false;
			if (this.searchHasTableResults()) {
				// Non-concordance/table results are routed to Stats; Search no longer paginates these.
				return false;
			}
			const hits = Array.isArray(this.search.hits) ? this.search.hits : [];
			const result = this.search.response && this.search.response.result && typeof this.search.response.result === 'object'
				? this.search.response.result
				: null;
			const total = result && Number.isFinite(result.total) ? Number(result.total) : null;
			if (total !== null) return hits.length < total;
			const limit = this.normalizePositiveInt(this.search.limit, 25);
			return hits.length >= limit;
		},

		showMoreSearch() {
			this.submitSearchRequest({ append: true });
		},

		callMessages(call) {
			if (!call || typeof call !== 'object') return [];
			const msgs = [];
			if (call.error) msgs.push(call.error);
			if (Array.isArray(call.errors)) msgs.push(...call.errors);
			if (Array.isArray(call.warnings)) msgs.push(...call.warnings);
			return msgs;
		},

		callHasIssues(call) {
			return this.callMessages(call).length > 0;
		},

		/**
		 * Messages of the search call, with query syntax errors shown as such: the
		 * engine's parser message, the query, and a hint for a bare word, instead of the
		 * raw backend error with its request payload (which reads like a corpus failure).
		 * The payload stays in the debug panel.
		 */
		searchCallMessages() {
			const query = String((this.search && this.search.query) || '').trim();
			const seen = new Set();
			const out = [];
			for (const msg of this.callMessages(this.search && this.search.response)) {
				const shown = this.formatQuerySyntaxError(String(msg), query) || String(msg);
				if (!seen.has(shown)) {
					seen.add(shown);
					out.push(shown);
				}
			}
			return out;
		},

		/** A query syntax error message (pando or CQP parser) made readable, or '' if `msg` is not one. */
		formatQuerySyntaxError(msg, query) {
			let text = msg.split(/\n\nRequest payload:/)[0].trim();
			// FQS may pass the engine's JSON answer as the error text
			if (text.startsWith('{')) {
				try {
					const j = JSON.parse(text);
					const e = j && (j.error || j.message);
					if (typeof e === 'string') text = e.trim();
					else if (e && typeof e.message === 'string') text = e.message.trim();
				} catch (_) { /* not JSON */ }
			}
			const syntax = /^(Unexpected|Unterminated|Unclosed|Expected|Invalid number|Unknown (attribute|flag|function|show target|command)|Unsupported)\b/.test(text)
				|| /\bsyntax error\b/i.test(text)
				|| /\bat position \d+|\bnear offset \d+/.test(text);
			if (!syntax) return '';
			let out = `Query syntax error: ${text}`;
			if (query) out += `\nYour query: ${query}`;
			// a bare word (or words) without CQL syntax: show the CQL for it
			if (/^[\p{L}\p{N}'’-]+(\s+[\p{L}\p{N}'’-]+)*$/u.test(query)) {
				const words = query.split(/\s+/).map((w) => `"${w.replace(/"/g, '\\"')}"`).join(' ');
				const first = query.split(/\s+/)[0];
				out += `\nWords need quotes in CQL: ${words}, or name the attribute: [form="${first}"].`;
			}
			return out;
		},

		/** True for failed calls / backend errors only (not warnings). Used when UI should stay usable with warnings (e.g. frequency chart toolbar). */
		callHasErrors(call) {
			if (!call || typeof call !== 'object') return false;
			if (call.error) return true;
			if (Array.isArray(call.errors) && call.errors.length) return true;
			return false;
		},

		toggleDoc(id) {
			if (!id) return;
			this.docOpen[id] = !this.docOpen[id];
		},

		isDocOpen(id) {
			return !!this.docOpen[id];
		},

		joinTokens(value) {
			if (Array.isArray(value)) return value.join(' ');
			return value || '';
		},

		normalizeDocuments(docs) {
			if (!Array.isArray(docs)) return [];
			return docs
				.filter((doc) => doc && typeof doc === 'object')
				.map((doc, idx) => {
					const meta = (doc.meta && typeof doc.meta === 'object' && !Array.isArray(doc.meta)) ? doc.meta : {};
					const rawId = doc.id ?? '';
					const rawTitle = doc.title ?? '';
					const stableId = rawId !== '' && rawId !== null && rawId !== undefined ? String(rawId) : '';
					const stableTitle = rawTitle !== '' && rawTitle !== null && rawTitle !== undefined ? String(rawTitle) : '';
					return Object.assign({}, doc, {
						id: rawId,
						title: stableTitle,
						meta,
						__rowKey: `doc:${idx}:${stableId || stableTitle || 'missing'}`,
					});
				});
		},

		buildDocumentLookup() {
			const lookup = {};
			(this.documents || []).forEach(doc => {
				if (!doc || typeof doc !== 'object') return;
				[doc.id, this.normalizeDocId(doc.id)].forEach(key => {
					if (key && !lookup[key]) lookup[key] = doc;
				});
			});
			this.docLookup = lookup;
		},

		normalizeDocId(value) {
			if (!value) return '';
			return String(value)
				.replace(/^xmlfiles\//, '')
				.replace(/\.xml$/i, '')
				.replace(/^\/+/, '');
		},

		getDocument(docId) {
			const normalized = this.normalizeDocId(docId);
			return this.docLookup[normalized] || this.docLookup[docId] || null;
		},

		getDocumentTooltip(docId) {
			const doc = this.getDocument(docId);
			if (!doc || typeof doc !== 'object') return this.normalizeDocId(docId) || '';
			const lines = [];
			Object.keys(doc).forEach(key => {
				const value = doc[key];
				if (value === null || value === undefined || value === '') return;
				if (Array.isArray(value)) lines.push(`${key}: ${value.join(', ')}`);
				else if (typeof value !== 'object') lines.push(`${key}: ${value}`);
			});
			return lines.join('\n');
		},

		getDocumentLabel(doc) {
			if (!doc || typeof doc !== 'object') return '(untitled)';
			const raw = doc.title || doc.id || '(untitled)';
			// Strip common TEITOK prefixes and .xml extension for display only.
			let label = String(raw);
			if (label.startsWith('xmlfiles/')) label = label.slice('xmlfiles/'.length);
			if (label.toLowerCase().endsWith('.xml')) label = label.slice(0, -4);
			return label || '(untitled)';
		},

		getDocumentSearchText(doc) {
			if (!doc || typeof doc !== 'object') return '';
			return [doc.id || '', doc.title || ''].join(' ').toLowerCase();
		},

		/** Compute a stable list of metadata keys across all documents (excluding id/title). */
		documentMetaKeys() {
			const seen = new Set();
			const docs = this.documents || [];
			for (const doc of docs) {
				const meta = (doc && typeof doc === 'object' && doc.meta && typeof doc.meta === 'object') ? doc.meta : null;
				if (!meta) continue;
				for (const key of Object.keys(meta)) {
					if (key === 'id' || key === 'title') continue;
					seen.add(key);
				}
			}
			return Array.from(seen).sort();
		},

		getMetaLabel(key) {
			if (!key) return '';
			const map = this.docMetaLabels || {};
			return map[key] || key;
		},

		getDocMetaValue(doc, key) {
			if (!doc || typeof doc !== 'object' || !key) return '';
			const meta = doc.meta && typeof doc.meta === 'object' ? doc.meta : {};
			const value = meta[key];
			if (value === undefined || value === null) return '';
			if (Array.isArray(value)) return value.join(', ');
			if (typeof value === 'object') {
				try {
					return JSON.stringify(value);
				} catch {
					return String(value);
				}
			}
			return String(value);
		},

		/** Initialise DataTables (or TEITOK's equivalent) on the documents table, once. */
		initDocumentsDataTable() {
			if (!this.isSmallCorpus()) return;
			const table = document.getElementById('flexicorp-docs-table');
			if (!table) return;
			// Alpine owns this table's DOM via x-for. Third-party table enhancers that
			// clone/reorder rows can break Alpine's keyed rendering, so keep them
			// opt-in only.
			if (table.dataset.flexicorpEnableDatatable !== '1') return;
			// Avoid re-initialisation if a DataTable is already attached.
			if (table.dataset.flexicorpDatatableInitialised === '1') return;

			// Prefer TEITOK/EasyCorp's global helpers when available.
			try {
				if (window.initDataTablesFor && typeof window.initDataTablesFor === 'function') {
					window.initDataTablesFor('#flexicorp-docs-table');
					table.dataset.flexicorpDatatableInitialised = '1';
					return;
				}
				if (window.$ && typeof window.$ === 'function' && window.$.fn && window.$.fn.DataTable) {
					window.$('#flexicorp-docs-table').DataTable();
					table.dataset.flexicorpDatatableInitialised = '1';
				}
			} catch (e) {
				// Swallow errors: the table will remain a plain HTML table if DataTables is not available.
			}
		},

		// Treat corpora up to 10k docs as "small" for client-side filtering.
		isSmallCorpus() {
			const total = Number.isFinite(this.documentsTotal) ? this.documentsTotal : (this.documents || []).length;
			return total <= 10000;
		},

		getFilteredDocuments() {
			// For large corpora, skip client-side filtering to avoid loading everything into the browser.
			if (!this.isSmallCorpus()) return this.documents || [];
			const needle = (this.documentsUi.filter || '').trim().toLowerCase();
			if (!needle) return this.documents;
			return (this.documents || []).filter(doc => this.getDocumentSearchText(doc).includes(needle));
		},

		getVisibleDocuments() {
			// For small corpora, expose all documents so DataTables (or similar)
			// can handle client-side pagination, sorting and searching.
			if (this.isSmallCorpus()) {
				return this.getFilteredDocuments();
			}
			// For large corpora, keep a simple client-side window over the loaded page.
			return this.getFilteredDocuments().slice(0, this.documentsUi.visibleCount);
		},

		getVisibleDocumentsCount() {
			return this.getVisibleDocuments().length;
		},

		resetDocumentsVisible() {
			const filteredCount = this.getFilteredDocuments().length;
			const perPage = this.normalizePositiveInt(this.documentsUi.perPage, 20);
			this.documentsUi.visibleCount = Math.min(filteredCount, perPage);
		},

		updateDocumentsPerPage() {
			this.documentsUi.perPage = this.normalizePositiveInt(this.documentsUi.perPage, 20);
			this.resetDocumentsVisible();
		},

		documentSummaryText() {
			const shown = this.getVisibleDocumentsCount();
			const total = Number.isFinite(Number(this.documentsTotal)) ? Number(this.documentsTotal) : (this.documents || []).length;
			if (!Number.isFinite(shown) || shown <= 0) return '';
			if (!Number.isFinite(total) || total <= 0) return `Showing ${shown}`;
			return `Showing ${shown} of ${total}`;
		},

		canLoadMoreDocuments() {
			const filteredCount = this.getFilteredDocuments().length;
			if (this.documentsUi.visibleCount < filteredCount) return true;
			// For large corpora, ignore client-side filter when deciding whether more documents exist server-side.
			const hasFilter = this.isSmallCorpus() && (this.documentsUi.filter || '').trim();
			return !hasFilter && this.documents.length < this.documentsTotal;
		},

		async loadMoreDocuments() {
			const filteredCount = this.getFilteredDocuments().length;
			const nextVisible = this.documentsUi.visibleCount + this.documentsUi.perPage;
			if (this.documentsUi.visibleCount < filteredCount) {
				this.documentsUi.visibleCount = Math.min(filteredCount, nextVisible);
				return;
			}
			// For large corpora, treat filter as client-side only and still allow loading more pages.
			const hasFilter = this.isSmallCorpus() && (this.documentsUi.filter || '').trim();
			if (hasFilter || this.documents.length >= this.documentsTotal) return;
			const formData = this.buildCommonRequestData();
			formData.set('active_tab', 'documents');
			formData.set('docs_limit', String(Math.min(this.documentsTotal, this.documents.length + this.documentsUi.perPage)));
			await this.submitAjaxData(formData, 'documents');
			// After appending a new page, keep visible count in sync with loaded rows.
			const loadedCount = Array.isArray(this.documents) ? this.documents.length : 0;
			this.documentsUi.visibleCount = Math.min(this.documentsTotal || loadedCount, Math.max(nextVisible, loadedCount));
		},

		getDocumentUrlById(id) {
			if (!id) return '';
			const url = new URL('index.php', window.location.href);
			url.searchParams.set('action', 'file');
			url.searchParams.set('cid', id);
			return url.toString();
		},

		getDocumentUrl(doc) {
			if (!doc || typeof doc !== 'object') return '';
			if (!this.currentBackendSupportsDocumentLinks()) return '';
			return this.getDocumentUrlById(doc.id || '');
		},

		/** True if string looks like tt-cwb-ridx input (path, tabs, numbers, token id) rather than XML. */
		isRidxStyleContext(str) {
			if (typeof str !== 'string' || !str.trim()) return false;
			const line = str.trim();
			// Single line: path.xml + tab + digits + tab + digits + tab + token id (e.g. w-1127)
			if (line.indexOf('\n') >= 0) return false;
			if (!/\.xml\t\d+\t\d+\t[\w-]+/.test(line)) return false;
			// Do not treat as XML if it has no angle brackets
			if (/<[\w/]/.test(line)) return false;
			return true;
		},

		/** Extract a single string from hit.context (may be object with .data or .xml). */
		getContextString(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const c = hit.context;
			if (typeof c === 'string') return c;
			if (c && typeof c === 'object' && typeof c.data === 'string') return c.data;
			if (c && typeof c === 'object' && typeof c.xml === 'string') return c.xml;
			if (typeof hit.context_data === 'string') return hit.context_data;
			return '';
		},

		/**
		 * True when the hit carries TEITOK / fragment XML for rich display (tok, highlights, facs, …).
		 * Plain flexicorp-pando { left, match, right } without XML must stay false so we do not skip XML.
		 */
		hitHasTeitokXmlContext(hit) {
			if (!hit || typeof hit !== 'object') return false;
			const looksXml = (s) =>
				typeof s === 'string' &&
				s.trim() &&
				(s.includes('<tok') ||
					s.includes('<dtok') ||
					s.includes('<mtok') ||
					s.includes('<seg') ||
					s.includes('<s ') ||
					s.includes('<s>'));
			if (looksXml(hit.fragment)) return true;
			if (looksXml(hit.context_xml)) return true;
			if (looksXml(hit.content)) return true;
			if (typeof hit.context === 'string' && looksXml(hit.context)) return true;
			const c = hit.context;
			if (c && typeof c === 'object') {
				if (looksXml(c.data)) return true;
				if (looksXml(c.xml)) return true;
			}
			if (looksXml(hit.context_text)) return true;
			if (looksXml(hit.context_data)) return true;
			return false;
		},

		/** flexicorp-pando plain KWIC object (no fragment) — one line for table / fallback only. */
		getPlainPandoKwicLine(hit) {
			const c = hit && hit.context;
			if (!c || typeof c !== 'object') return '';
			if (typeof c.data === 'string' && c.data.trim()) return '';
			if (typeof c.xml === 'string' && c.xml.trim()) return '';
			if (typeof c.left !== 'string' && typeof c.match !== 'string' && typeof c.right !== 'string') return '';
			const a = typeof c.left === 'string' ? c.left : '';
			const b = typeof c.match === 'string' ? c.match : '';
			const d = typeof c.right === 'string' ? c.right : '';
			return [a, b, d].join(' ').replace(/\s+/g, ' ').trim();
		},

		/** Plain surface string from a backend token object (Pando JSON, etc.). */
		hitTokenSurfaceText(tok) {
			if (!tok || typeof tok !== 'object') return '';
			const keys = ['lemma', 'word', 'form', 'surface', 'text', 'norm'];
			for (let i = 0; i < keys.length; i++) {
				const k = keys[i];
				if (typeof tok[k] === 'string' && tok[k].trim()) return tok[k].trim();
			}
			return '';
		},

		/** Token id for matching highlight_map / KWIC (aligned with flexicorp.php). */
		hitTokenIdString(tok) {
			if (!tok || typeof tok !== 'object') return '';
			if (tok.tuid != null && String(tok.tuid).trim() !== '') return String(tok.tuid).trim();
			const id = tok.id != null ? String(tok.id).trim() : '';
			if (id !== '' && id !== '_') return id;
			return '';
		},

		/** One line of plain text from hit.tokens when no XML fragment is present. */
		hitTokensToPlainLine(tokens) {
			if (!Array.isArray(tokens) || !tokens.length) return '';
			const parts = [];
			for (let i = 0; i < tokens.length; i++) {
				const s = this.hitTokenSurfaceText(tokens[i]);
				if (s) parts.push(s);
			}
			return parts.join(' ');
		},

		/** When fragment/raw/context are empty but tokens[] carries surfaces (flexicorp-pando without xidx XML). */
		getHitContextSyntheticFromTokens(hit) {
			if (!hit || typeof hit !== 'object' || !Array.isArray(hit.tokens) || !hit.tokens.length) return '';
			return this.hitTokensToPlainLine(hit.tokens);
		},

		getHitContext(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const finalize = (raw) => this.normalizeContextArtifacts(typeof raw === 'string' ? raw : '');
			const fmt = (this.search && this.search.contextFormat) || 'xml';
			// Prefer format-specific field so "raw" (text) shows plain text like the server would after fragment_to_text,
			// even when the last search was XML-only (cached hit.context.format === 'xml').
			if (fmt === 'text') {
				const toRawDisplay = (s) => {
					const t = typeof s === 'string' ? s : '';
					if (!t.trim()) return '';
					const decoded = this.decodeHtmlEntities(t);
					if (this.isRidxStyleContext(decoded)) return decoded;
					return this.fragmentToText(decoded);
				};
				if (typeof hit.context_text === 'string' && hit.context_text.trim()) {
					return finalize(toRawDisplay(hit.context_text));
				}
				const c = hit.context;
				if (c && typeof c === 'object' && String(c.format || '').toLowerCase() === 'xml' && typeof c.data === 'string' && c.data.trim()) {
					return finalize(toRawDisplay(c.data));
				}
				let raw = this.getContextString(hit) || hit.content || hit.context_xml || hit.fragment || '';
				if (!String(raw).trim()) {
					const plain = this.getPlainPandoKwicLine(hit);
					if (plain) raw = plain;
				}
				if (!String(raw).trim()) {
					const synth = this.getHitContextSyntheticFromTokens(hit);
					if (synth) raw = synth;
				}
				return finalize(toRawDisplay(raw));
			}
			if (fmt === 'xml') {
				// Backend may put XML in context.data (nested) or content; prefer explicit context.data
				const nested = this.getContextString(hit);
				const candidates = [nested, hit.context_xml, hit.content, hit.context_text, hit.context_data, hit.fragment];
				if (typeof hit.context === 'string') candidates.unshift(hit.context);
				for (const raw of candidates) {
					if (typeof raw !== 'string' || !raw) continue;
					if (this.isRidxStyleContext(raw)) continue;
					return finalize(raw);
				}
				// Aligned side may only provide plain KWIC fields (left/match/right) without XML.
				const plainParts = this.getPlainContextPartsFromHit(hit);
				if (plainParts) {
					const joined = [plainParts.left, plainParts.match, plainParts.right]
						.map((s) => (typeof s === 'string' ? s.trim() : ''))
						.filter((s) => s.length > 0)
						.join(' ');
					if (joined) return finalize(joined);
				}
				// Backend may only provide ridx-style line (e.g. tt-cwb-xidx not run); use it so we show something
				let ridxFallback = '';
				const manateePlain = this.manateeHitPlainLine(hit);
				if (manateePlain) {
					ridxFallback = manateePlain;
				}
				if (!String(ridxFallback).trim()) {
					ridxFallback =
						hit.raw ||
						hit.context_xml ||
						(typeof hit.context === 'string' ? hit.context : '') ||
						hit.content ||
						hit.context_text ||
						hit.fragment ||
						'';
				}
				if (!String(ridxFallback).trim()) {
					const synth = this.getHitContextSyntheticFromTokens(hit);
					if (synth) ridxFallback = synth;
				}
				if (!String(ridxFallback).trim()) {
					const plain = this.getPlainPandoKwicLine(hit);
					if (plain) ridxFallback = plain;
				}
				return finalize(ridxFallback);
			}
			let raw =
				hit.content ||
				this.getContextString(hit) ||
				hit.context_xml ||
				hit.context_text ||
				(typeof hit.context === 'string' ? hit.context : '') ||
				hit.fragment ||
				'';
			if (!String(raw).trim()) {
				const plain = this.getPlainPandoKwicLine(hit);
				if (plain) raw = plain;
			}
			if (!String(raw).trim()) {
				const synth = this.getHitContextSyntheticFromTokens(hit);
				if (synth) raw = synth;
			}
			return finalize(raw);
		},

		normalizeContextArtifacts(str) {
			if (typeof str !== 'string' || !str) return str;
			// Common mixed-decoding artifact for non-breaking spaces in TEITOK XML.
			return str.replace(/\u00c2\u00a0/g, '\u00a0');
		},

		/** Decode HTML entities so encoded XML (e.g. &lt;tok&gt;) can be parsed or shown as plain text */
		decodeHtmlEntities(str) {
			if (typeof str !== 'string' || !str) return str;
			if (!/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(str)) return str;
			let out = str;
			let prev = null;
			let guard = 0;
			while (prev !== out && guard++ < 8) {
				prev = out;
				const textarea = document.createElement('textarea');
				textarea.innerHTML = out;
				out = this.normalizeContextArtifacts(textarea.value);
				if (!/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(out)) break;
			}
			return out;
		},

		/**
		 * Plain text from a TEITOK XML fragment (matches flexicorp.teitok_context.fragment_to_text).
		 * Used when Display is "raw" (text) but the last search still has XML in context.data.
		 */
		fragmentToText(fragment) {
			if (typeof fragment !== 'string' || !fragment.trim()) return '';
			const wrapped = `<root>${fragment}</root>`;
			try {
				const parser = new DOMParser();
				const xmlDoc = parser.parseFromString(wrapped, 'application/xml');
				if (xmlDoc.querySelector('parsererror')) throw new Error('parse');
				const root = xmlDoc.documentElement;
				if (!root) return '';
				const parts = [];
				const walk = (node) => {
					if (node.nodeType === Node.TEXT_NODE) {
						const t = (node.textContent || '').trim();
						if (t) parts.push(t);
					} else if (node.childNodes) {
						node.childNodes.forEach(walk);
					}
				};
				walk(root);
				return parts.join(' ');
			} catch (_) {
				const plain = fragment.replace(/<[^>]+>/g, ' ');
				return plain.split(/\s+/).filter(Boolean).join(' ');
			}
		},

		/** Context string for the text-format &lt;pre&gt;: decoded so user sees readable text, not entity codes */
		getDisplayableTextContext(hit) {
			if (this.kwicScopeUsesTokenWindow()) {
				const parts = this.getKwicParts(hit);
				if (parts) {
					const merged = [parts.left, parts.match, parts.right]
						.map((s) => (typeof s === 'string' ? s.trim() : ''))
						.filter((s) => s.length > 0)
						.join(' ');
					if (merged) return this.decodeHtmlEntities(merged);
				}
			}
			return this.decodeHtmlEntities(this.getHitContext(hit));
		},

		isEngineKwicRaw(str) {
			return typeof str === 'string' && str.includes(this.rawKwicDelimiter || '--%%%--');
		},

		parseEngineKwicRaw(str) {
			if (!this.isEngineKwicRaw(str)) return null;
			const delim = this.rawKwicDelimiter || '--%%%--';
			const parts = String(str).split(delim);
			if (parts.length < 3) return null;
			return {
				left: this.normalizeContextArtifacts(parts[0] || ''),
				match: this.normalizeContextArtifacts(parts[1] || ''),
				right: this.normalizeContextArtifacts(parts.slice(2).join(delim) || ''),
				isXml: false,
			};
		},

		getRenderedRawHit(hit) {
			const plain = this.manateeHitPlainLine(hit);
			if (plain) {
				const doc = this.getHitDocLabel(hit);
				const line = doc ? `${doc}: ${plain}` : plain;
				return `<span class="flexicorp-hit-plain-match">${this.escapeHtmlForPre(line)}</span>`;
			}
			const raw = hit && typeof hit === 'object' ? String(hit.raw || '') : '';
			if (!raw) return '';
			if (this.hitLooksLikeManateeEngine(hit) && raw.includes('\t')) {
				const parts = raw.split('\t').filter((p) => String(p).trim() !== '');
				if (parts.length >= 2) {
					const matchText = parts[parts.length - 1];
					const docId = parts[0];
					const line = docId && matchText ? `${docId}: ${matchText}` : matchText || raw;
					return `<span class="flexicorp-hit-plain-match">${this.escapeHtmlForPre(line)}</span>`;
				}
			}
			const parsed = this.parseEngineKwicRaw(raw);
			if (!parsed) {
				return `<span class="flexicorp-hit-kwic-raw">${this.escapeHtmlForPre(raw)}</span>`;
			}
			return [
				'<span class="flexicorp-hit-kwic-raw">',
				`<span class="flexicorp-hit-kwic-raw-left">${this.escapeHtmlForPre(parsed.left)}</span>`,
				`<span class="flexicorp-hit-kwic-raw-match flexicorp-hit-match">${this.escapeHtmlForPre(parsed.match)}</span>`,
				`<span class="flexicorp-hit-kwic-raw-right">${this.escapeHtmlForPre(parsed.right)}</span>`,
				'</span>',
			].join('');
		},

		getHitTokenId(hit) {
			if (!hit || typeof hit !== 'object') return '';
			// highlight_map.default.tok_ids carries TEITOK xml:ids when the backend resolved them;
			// hit.toks are surface strings from Manatee lexicon — prefer ids for jmp= / TEITOK links.
			const hmIds =
				hit.highlight_map &&
				hit.highlight_map.default &&
				Array.isArray(hit.highlight_map.default.tok_ids)
					? hit.highlight_map.default.tok_ids.filter((t) => t != null && String(t).trim() !== '')
					: [];
			if (hmIds.length) return String(hmIds[0]);
			if (Array.isArray(hit.toks) && hit.toks.length) return hit.toks[0];
			if (hit.tok_id) return hit.tok_id;
			if (hit.token_id) return hit.token_id;
			return '';
		},

		getHitTokenIds(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const hmIds =
				hit.highlight_map &&
				hit.highlight_map.default &&
				Array.isArray(hit.highlight_map.default.tok_ids)
					? hit.highlight_map.default.tok_ids.filter((t) => t != null && String(t).trim() !== '')
					: [];
			if (hmIds.length) return hmIds.map((t) => String(t)).join(', ');
			if (Array.isArray(hit.toks) && hit.toks.length) return hit.toks.join(', ');
			const one = this.getHitTokenId(hit);
			return one || '';
		},

		getHitDocCid(hit) {
			if (!hit || typeof hit !== 'object') return '';
			// Keep raw TEITOK cid for showdocinfo/tokview hover lookups.
			// Normalized ids are for UI labels and local doc-lookup only.
			return String(hit.doc_id || '').trim();
		},

		getHitDocLabel(hit) {
			if (!hit || typeof hit !== 'object') return '';
			return this.normalizeDocId(hit.doc_id || '') || (hit.doc_id || '');
		},

		getHitDocUrl(hit) {
			if (!hit || typeof hit !== 'object' || !hit.doc_id) return '';
			if (!this.currentBackendSupportsDocumentLinks()) return '';
			const url = new URL('index.php', window.location.href);
			url.searchParams.set('action', 'file');
			url.searchParams.set('cid', hit.doc_id);
			const tokId = this.getHitTokenId(hit);
			if (tokId) url.searchParams.set('jmp', tokId);
			return url.toString();
		},

		_getHitEyeCandyCache(hit) {
			if (!hit || typeof hit !== 'object') return null;
			if (!hit.__flexicorpEyeCandy) hit.__flexicorpEyeCandy = {};
			if (!hit.__flexicorpEyeCandyCacheKey) {
				const docId = this.getHitDocCid(hit) || hit.doc_id || '';
				const sid = this.getHitSentenceIdForNavigation(hit);
				const fmt = (hit && hit.context && hit.context.format) ? hit.context.format : '';
				hit.__flexicorpEyeCandyCacheKey = `${docId}::${sid}::${fmt}::${this.getHitTokenIds(hit)}`;
			}
			return hit.__flexicorpEyeCandy;
		},

		getHitSentenceId(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const ctx = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const locator = ctx && ctx.locator && typeof ctx.locator === 'object' ? ctx.locator : null;
			return String(hit.sentence_id ?? (locator && locator.sentence_id) ?? '').trim();
		},

		/**
		 * flexicorp-pando (and similar) often put the sentence region id on token rows (e.g. s_id) while
		 * hit.sentence_id is empty — CWB tabulate instead fills sentence_id on the hit.
		 */
		getHitSentenceRegionIdFromTokens(hit) {
			if (!hit || typeof hit !== 'object' || !Array.isArray(hit.tokens) || !hit.tokens.length) return '';
			const keys = ['s_id', 'sentence_id', 'sid'];
			const tryTok = (tok) => {
				if (!tok || typeof tok !== 'object') return '';
				for (let i = 0; i < keys.length; i++) {
					const k = keys[i];
					if (tok[k] == null) continue;
					const v = String(tok[k]).trim();
					if (v !== '' && v !== '_') return v;
				}
				return '';
			};
			const matchIds = this.getHitMatchIds(hit);
			const want = new Set((matchIds || []).map((x) => String(x)));
			if (want.size) {
				for (let i = 0; i < hit.tokens.length; i++) {
					const tok = hit.tokens[i];
					const tid = this.hitTokenIdString(tok);
					if (!tid || !want.has(tid)) continue;
					const s = tryTok(tok);
					if (s) return s;
				}
			}
			for (let i = 0; i < hit.tokens.length; i++) {
				const s = tryTok(hit.tokens[i]);
				if (s) return s;
			}
			return '';
		},

		/**
		 * First sentence-like element in parsed XML: &lt;s&gt;, &lt;seg&gt;, or &lt;sentence&gt;.
		 * Uses localName/nodeName so both plain &lt;s&gt; and prefixed names like &lt;tei:s&gt; work.
		 */
		_flexicorpFirstSentenceLikeElement(xmlRoot) {
			if (!xmlRoot || !xmlRoot.getElementsByTagName) return null;
			const wanted = new Set(['s', 'seg', 'sentence']);
			const all = xmlRoot.getElementsByTagName('*');
			for (let i = 0; i < all.length; i++) {
				const el = all[i];
				if (!el) continue;
				const local = String(el.localName || '').toLowerCase();
				if (local && wanted.has(local)) return el;
				const node = String(el.nodeName || '').toLowerCase();
				const bare = node.includes(':') ? node.split(':').pop() : node;
				if (bare && wanted.has(bare)) return el;
			}
			return null;
		},

		/**
		 * Sentence/region id from the TEITOK XML fragment when tokens omit s_id (e.g. some Pando attrs).
		 * Picks the first sentence-like wrapper: &lt;s&gt;, &lt;seg&gt;, or &lt;sentence&gt; with s_id or id.
		 */
		getHitSentenceRegionIdFromXmlContext(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const candidates = [
				hit.fragment,
				hit.context_xml,
				hit.context_data,
				this.getContextString(hit),
				hit.content,
				typeof hit.context === 'string' ? hit.context : '',
			];
			let raw = '';
			for (let i = 0; i < candidates.length; i++) {
				const c = candidates[i];
				if (typeof c === 'string' && c.trim() && /[</]/.test(c)) {
					raw = c;
					break;
				}
			}
			if (!raw && hit.context && typeof hit.context === 'object') {
				const d = hit.context.data;
				const x = hit.context.xml;
				if (typeof d === 'string' && d.trim() && /[</]/.test(d)) raw = d;
				else if (typeof x === 'string' && x.trim() && /[</]/.test(x)) raw = x;
			}
			if (!raw || !raw.trim()) return '';
			if (this.isRidxStyleContext(raw)) return '';
			let toParse = raw;
			if (/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(toParse)) {
				toParse = this.decodeHtmlEntities(toParse);
			}
			const tryReadSidFromElement = (el) => {
				if (!el || !el.getAttribute) return '';
				let sid = (el.getAttribute('s_id') || el.getAttribute('id') || '').trim();
				if (sid && sid !== '_') return sid;
				if (el.getAttributeNS) {
					const xid = (el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id') || '').trim();
					if (xid && xid !== '_') return xid;
				}
				return '';
			};
			try {
				const parser = new DOMParser();
				const xmlDoc = parser.parseFromString(`<flexicorp-root>${toParse}</flexicorp-root>`, 'application/xml');
				if (xmlDoc.querySelector('parsererror')) return '';
				const root = xmlDoc.documentElement;
				if (root) {
					const el = this._flexicorpFirstSentenceLikeElement(root);
					if (el) {
						const sid = tryReadSidFromElement(el);
						if (sid) return sid;
					}
					const anySid = root.querySelector('[s_id]');
					if (anySid && anySid.getAttribute) {
						const v = (anySid.getAttribute('s_id') || '').trim();
						if (v && v !== '_') return v;
					}
				}
				return '';
			} catch (_) {
				/* ignore */
			}
			return '';
		},

		/**
		 * Walk from matched token nodes up to a sentence-like wrapper and read &lt;s&gt;/&lt;seg&gt; @id / @s_id.
		 * Needed when the fragment omits a leading &lt;s&gt; sibling but matched tokens are nested under &lt;s&gt;.
		 */
		getHitSentenceIdFromXmlAncestorsOfMatch(hit) {
			if (!this.isXmlContext() || !this.hitHasStructuredContext(hit)) return '';
			let container = null;
			try {
				container = this.buildContextContainer(hit);
			} catch (_) {
				return '';
			}
			if (!container || !container.querySelector || container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
				return '';
			}
			const wanted = new Set(['s', 'seg', 'sentence']);
			const matchIds = this.getHitMatchIds(hit);
			const idSet = this._expandMatchIdSet(new Set(Array.isArray(matchIds) ? matchIds : []));
			const tokNodes = this.xmlContextTokenElements(container);
			const seeds = idSet.size
				? tokNodes.filter((n) => {
						const ids = this.xmlElementStableIds(n);
						return ids.some((id) => idSet.has(id));
					})
				: tokNodes;
			const readSidFromEl = (el) => {
				if (!el || !el.getAttribute) return '';
				let sid = (el.getAttribute('s_id') || el.getAttribute('id') || '').trim();
				if (sid && sid !== '_') return sid;
				if (el.getAttributeNS) {
					const xid = (el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id') || '').trim();
					if (xid && xid !== '_') return xid;
				}
				return '';
			};
			const walkUp = (start) => {
				let el = start;
				while (el && el !== container) {
					const local = String(el.localName || '').toLowerCase();
					const bare = String(el.nodeName || '').toLowerCase();
					const bareTail = bare.includes(':') ? bare.split(':').pop() : bare;
					if (wanted.has(local) || wanted.has(bareTail)) {
						const sid = readSidFromEl(el);
						if (sid) return sid;
					}
					el = el.parentElement;
				}
				return '';
			};
			for (let i = 0; i < seeds.length; i += 1) {
				const sid = walkUp(seeds[i]);
				if (sid) return sid;
			}
			const allTok = this.xmlContextTokenElements(container);
			const firstTok = allTok[0] || null;
			return firstTok ? walkUp(firstTok) : '';
		},

		/** Last resort: pull sentence id from raw XML text when DOM parsing misses (parallel slices, escaped markup). */
		_flexicorpExtractSentenceIdRegexFromContext(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const candidates = [
				hit.fragment,
				hit.context_xml,
				hit.context_data,
				this.getContextString(hit),
				hit.content,
				typeof hit.context === 'string' ? hit.context : '',
			];
			if (hit.context && typeof hit.context === 'object') {
				const d = hit.context.data;
				const x = hit.context.xml;
				if (typeof d === 'string') candidates.push(d);
				if (typeof x === 'string') candidates.push(x);
			}
			const reList = [
				/<s\b[^>]*?\bid\s*=\s*["']([^"']+)["']/i,
				/<s\b[^>]*?\bs_id\s*=\s*["']([^"']+)["']/i,
				/<seg\b[^>]*?\bid\s*=\s*["']([^"']+)["']/i,
				/<seg\b[^>]*?\bs_id\s*=\s*["']([^"']+)["']/i,
			];
			for (let i = 0; i < candidates.length; i += 1) {
				const raw = candidates[i];
				if (typeof raw !== 'string' || !raw.trim()) continue;
				let text = raw;
				if (/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(text)) {
					text = this.decodeHtmlEntities(text);
				}
				for (let r = 0; r < reList.length; r += 1) {
					const m = text.match(reList[r]);
					if (m && m[1]) {
						const v = String(m[1]).trim();
						if (v && v !== '_') return v;
					}
				}
			}
			return '';
		},

		/** Sentence id for navigation (deptree, etc.): CWB fields first, then token s_id (Pando), then XML &lt;s&gt;/&lt;seg&gt;. */
		getHitSentenceIdForNavigation(hit) {
			const rawHit = hit && typeof hit === 'object' ? _fcRaw(hit) : null;
			const memoKey = this.isXmlContext() ? 'xml' : 'plain';
			if (rawHit) {
				const m = _fcSentenceIdMemo.get(rawHit);
				if (m && m.key === memoKey) return m.value;
			}
			const value = this._getHitSentenceIdForNavigationUncached(hit);
			if (rawHit) _fcSentenceIdMemo.set(rawHit, { key: memoKey, value });
			return value;
		},

		_getHitSentenceIdForNavigationUncached(hit) {
			const a = this.getHitSentenceId(hit);
			if (a) return a;
			const b = this.getHitSentenceRegionIdFromTokens(hit);
			if (b) return b;
			const c = this.getHitSentenceRegionIdFromXmlContext(hit);
			if (c) return c;
			const d = this.getHitSentenceIdFromXmlAncestorsOfMatch(hit);
			if (d) return d;
			return this._flexicorpExtractSentenceIdRegexFromContext(hit);
		},

		getHitAudioInfo(hit) {
			const cache = this._getHitEyeCandyCache(hit);
			if (cache && cache.audio !== undefined) return cache.audio;
			// Best-effort: derive from structured XML fragment.
			let out = null;
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				const container = this.buildContextContainer(hit);
				// Skip if context is fallback <pre> (ridx line / non-XML).
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					const matchIds = this.getHitMatchIds(hit);
					const idSet = this._expandMatchIdSet(new Set(matchIds));

					// 1) Media URL: <media>/<audio>/<video>, <recording>, chunk_url (e.g. ParCzech), or utterance / ancestors.
					let url = '';
					const media = container.querySelector('media, audio, video');
					if (media) {
						url = (media.getAttribute('url') || media.getAttribute('src') || media.getAttribute('href') || '').trim();
					}
					if (!url) {
						const rec = container.querySelector('recording');
						if (rec) url = (rec.getAttribute('url') || rec.getAttribute('target') || '').trim();
					}
					if (!url) {
						const chunkEl = container.querySelector('[chunk_url], u[chunk_url]');
						if (chunkEl) url = (chunkEl.getAttribute('chunk_url') || '').trim();
					}
					if (!url) {
						const uMedia = container.querySelector('u[url], u[src], u[href]');
						if (uMedia) {
							url = (uMedia.getAttribute('url') || uMedia.getAttribute('src') || uMedia.getAttribute('href') || '').trim();
						}
					}

					// 2) start/end: often on <u> (oral/TEI) or another wrapper, not on <tok>.
					let start = null;
					let end = null;
					const readTimes = (el) => {
						if (!el || !el.getAttribute) return null;
						const sr = el.getAttribute('start');
						const er = el.getAttribute('end');
						if (sr === null || er === null || sr === '' || er === '') return null;
						const s = Number(sr);
						const e = Number(er);
						if (!Number.isFinite(s) || !Number.isFinite(e) || e < s) return null;
						return { start: s, end: e };
					};
					const seeds = this.xmlContextTokenElements(container).filter(
						(n) => n && n.getAttribute && (idSet.size ? idSet.has(this.xmlElementId(n)) : true)
					);
					const walkSeeds = seeds.length ? seeds : this.xmlContextTokenElements(container);
					for (const t of walkSeeds) {
						let el = t;
						while (el && el !== container) {
							const times = readTimes(el);
							if (times) {
								start = times.start;
								end = times.end;
								break;
							}
							if (!url) {
								const au = (el.getAttribute('url') || el.getAttribute('src') || el.getAttribute('href') || '').trim();
								if (au) url = au;
							}
							el = el.parentElement;
						}
						if (start != null && end != null) break;
					}
					if (start == null || end == null) {
						const uSpan = container.querySelector('u[start][end]');
						const times = readTimes(uSpan);
						if (times) {
							start = times.start;
							end = times.end;
						}
					}
					// Match-span timing on tokens (oral corpora): first matched token @start, last @end.
					if (start == null && end == null && seeds.length > 0) {
						const firstTok = seeds[0];
						const lastTok = seeds[seeds.length - 1];
						const sr = firstTok.getAttribute('start');
						const er = lastTok.getAttribute('end');
						if (sr !== null && er !== null && sr !== '' && er !== '') {
							const s = Number(sr);
							const e = Number(er);
							if (Number.isFinite(s) && Number.isFinite(e) && e >= s) {
								start = s;
								end = e;
							}
						}
					}

					if (!url && walkSeeds[0]) {
						let el = walkSeeds[0];
						while (el && el !== container) {
							const au = (el.getAttribute('url') || el.getAttribute('src') || el.getAttribute('href') || '').trim();
							if (au) {
								url = au;
								break;
							}
							el = el.parentElement;
						}
					}

					const hasTimes = Number.isFinite(start) && Number.isFinite(end) && end >= start;
					const hasUrl = typeof url === 'string' && url.trim() !== '';
					if (hasUrl || hasTimes) {
						out = {
							url: hasUrl ? url.trim() : '',
							start: hasTimes ? start : null,
							end: hasTimes ? end : null,
						};
					}
				}
			}
			if (cache) cache.audio = out;
			return out;
		},

		/**
		 * Parse tuid from raw context XML when the DOM slice omits an opening <s> or namespaced tags
		 * confuse querySelector (mirrors flexicorp_pando extract_scope_tuid_from_xml_open_tags).
		 */
		extractScopeTuidFromContextString(raw) {
			if (typeof raw !== 'string' || !raw.trim()) return '';
			let ctx = raw;
			if (/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(ctx)) {
				ctx = this.decodeHtmlEntities(ctx);
			}
			let best = '';
			const tags = ['<s', '<seg', '<u'];
			for (let ti = 0; ti < tags.length; ti += 1) {
				const tag = tags[ti];
				let p = 0;
				for (;;) {
					p = ctx.indexOf(tag, p);
					if (p < 0) break;
					const gt = ctx.indexOf('>', p);
					if (gt < 0) break;
					const open = ctx.slice(p, gt + 1);
					for (let qi = 0; qi < 2; qi += 1) {
						const quote = qi === 0 ? '"' : "'";
						const needle = `tuid=${quote}`;
						let k = 0;
						for (;;) {
							k = open.indexOf(needle, k);
							if (k < 0) break;
							const v0 = k + needle.length;
							const v1 = open.indexOf(quote, v0);
							if (v1 > v0) {
								const val = open.slice(v0, v1).trim();
								if (val && val !== '_') best = val;
							}
							k = v1 < 0 ? k + needle.length : v1 + 1;
						}
					}
					p = gt + 1;
				}
			}
			// Prefixed TEI: <tei:s ... tuid="..."> (and :seg / :u)
			const prefRe = /<[^>/\s:]+\s*:\s*(s|seg|u)\b[^>]*\btuid\s*=\s*"([^"]+)"/gi;
			let pm;
			for (;;) {
				pm = prefRe.exec(ctx);
				if (!pm) break;
				const val = String(pm[2] || '').trim();
				if (val && val !== '_') best = val;
			}
			return best;
		},

		/** Set `localStorage.setItem('flexicorp_debug_alignment_tuid','1')` or `window.FLEXICORP_DEBUG_ALIGNMENT_TUID = true`, then reload. */
		_alignmentTuidDebugEnabled() {
			try {
				return (
					(typeof window !== 'undefined' && window.FLEXICORP_DEBUG_ALIGNMENT_TUID === true) ||
					localStorage.getItem('flexicorp_debug_alignment_tuid') === '1'
				);
			} catch (_) {
				return typeof window !== 'undefined' && window.FLEXICORP_DEBUG_ALIGNMENT_TUID === true;
			}
		},

		_logAlignmentTuidDebug(hit, step, extra) {
			if (!this._alignmentTuidDebugEnabled()) return;
			try {
				const base = {
					step,
					doc_id: hit && hit.doc_id,
					tokenIds: hit && typeof this.getHitTokenIds === 'function' ? this.getHitTokenIds(hit) : '',
					matchIds: hit && typeof this.getHitMatchIds === 'function' ? this.getHitMatchIds(hit) : [],
					isXmlContext: typeof this.isXmlContext === 'function' ? this.isXmlContext() : '',
					supportsXmlContext:
						typeof this.currentBackendSupportsXmlContext === 'function' ? this.currentBackendSupportsXmlContext() : '',
					hitHasStructuredContext:
						hit && typeof this.hitHasStructuredContext === 'function' ? this.hitHasStructuredContext(hit) : '',
					contextFormat: this.search && this.search.contextFormat,
					contextScope: this.search && this.search.contextScope,
					kwicScopeUsesTokenWindow:
						typeof this.kwicScopeUsesTokenWindow === 'function' ? this.kwicScopeUsesTokenWindow() : '',
					mergedHit: !!(hit && hit.__flexicorpMergedHits),
					mergedCount: hit && hit.__flexicorpMergedHitCount,
				};
				console.log('[flexicorp:alignment-tuid]', Object.assign(base, extra || {}));
			} catch (e) {
				console.warn('[flexicorp:alignment-tuid] log failed', e);
			}
		},

		_truncXmlDbg(s, max) {
			const t = typeof s === 'string' ? s : '';
			const n = Number(max) > 40 ? Number(max) : 220;
			if (t.length <= n) return t;
			return `${t.slice(0, n)}…[${t.length} chars]`;
		},

		/**
		 * Sentence / utterance / token alignment id from context XML: prefer <s>/<seg>/<u> @tuid,
		 * else @tuid on the matched tok/dtok/mtok/w (TEITOK often puts tuid on tokens only).
		 */
		getHitEnclosingScopeTuidFromXmlContext(hit) {
			const assembled = this._assembledKwicXmlContextForHit(hit);
			const rawCtx = this.getHitContext(hit) || '';
			const fromStr =
				this.extractScopeTuidFromContextString(assembled) || this.extractScopeTuidFromContextString(rawCtx);
			this._logAlignmentTuidDebug(hit, 'enclosingScope:strings', {
				assembledLen: assembled.length,
				rawCtxLen: rawCtx.length,
				fromStrExtract: fromStr || '(empty)',
				assembledHead: this._truncXmlDbg(assembled, 200),
				rawCtxHead: this._truncXmlDbg(rawCtx, 200),
			});
			if (!this.isXmlContext() || !this.hitHasStructuredContext(hit)) {
				this._logAlignmentTuidDebug(hit, 'enclosingScope:earlyReturn', {
					reason: !this.isXmlContext() ? '!isXmlContext' : '!hitHasStructuredContext',
					returning: fromStr || '(empty)',
				});
				return fromStr;
			}
			try {
				const candidates = [];
				if (assembled.trim()) candidates.push(assembled);
				if (rawCtx.trim()) candidates.push(rawCtx);
				const seen = new Set();
				for (let ci = 0; ci < candidates.length; ci += 1) {
					const ctx = candidates[ci];
					if (!ctx || seen.has(ctx)) continue;
					seen.add(ctx);
					const container = this.buildContextContainer(hit, ctx);
					const preFb = !!(container && container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback'));
					if (!container || preFb) {
						this._logAlignmentTuidDebug(hit, 'enclosingScope:skipCandidate', {
							candidateIndex: ci,
							preFallback: preFb,
							ctxHead: this._truncXmlDbg(ctx, 160),
						});
						continue;
					}
					const matchIds = this.getHitMatchIds(hit);
					const idSet = this._expandMatchIdSet(new Set(Array.isArray(matchIds) ? matchIds : []));
					const seeds = this.xmlContextTokenElements(container).filter((n) => {
						if (!n || !n.getAttribute) return false;
						if (!idSet.size) return true;
						const stable = this.xmlElementStableIds(n);
						const withTuid = String(n.getAttribute('tuid') || '').trim();
						if (withTuid && idSet.has(withTuid)) return true;
						return stable.some((id) => idSet.has(id));
					});
					const walkSeeds = seeds.length ? seeds : this.xmlContextTokenElements(container);
					this._logAlignmentTuidDebug(hit, 'enclosingScope:domWalk', {
						candidateIndex: ci,
						matchIds,
						idSetSize: idSet.size,
						seedsCount: seeds.length,
						walkSeedsCount: walkSeeds.length,
						tokenNodesInContainer: this.xmlContextTokenElements(container).length,
					});
					for (let si = 0; si < walkSeeds.length; si += 1) {
						let tokenTuid = '';
						let scopeTuid = '';
						let el = walkSeeds[si];
						while (el && el !== container) {
							const ln = (el.localName || el.tagName || '').toLowerCase();
							const tu = el.getAttribute && String(el.getAttribute('tuid') || '').trim();
							if (tu && tu !== '_') {
								if ((ln === 's' || ln === 'seg' || ln === 'u') && !scopeTuid) scopeTuid = tu;
								else if ((ln === 'tok' || ln === 'dtok' || ln === 'mtok' || ln === 'w') && !tokenTuid) tokenTuid = tu;
							}
							el = el.parentElement;
						}
						if (scopeTuid) {
							this._logAlignmentTuidDebug(hit, 'enclosingScope:foundScopeTuid', { scopeTuid, seedIndex: si });
							return scopeTuid;
						}
						if (tokenTuid) {
							this._logAlignmentTuidDebug(hit, 'enclosingScope:foundTokenTuid', { tokenTuid, seedIndex: si });
							return tokenTuid;
						}
					}
				}
				this._logAlignmentTuidDebug(hit, 'enclosingScope:fallbackFromStr', { returning: fromStr || '(empty)' });
				return fromStr;
			} catch (err) {
				this._logAlignmentTuidDebug(hit, 'enclosingScope:catch', { err: err && err.message ? err.message : String(err) });
				return fromStr;
			}
		},

		getHitDependencyTreeInfo(hit) {
			const cache = this._getHitEyeCandyCache(hit);
			if (cache && cache.deptree !== undefined) return cache.deptree;
			let out = null;
			const cid = hit && hit.doc_id ? String(hit.doc_id) : '';
			const sidResolved = this.getHitSentenceIdForNavigation(hit);
			const hasDepsInTokens =
				hit &&
				Array.isArray(hit.tokens) &&
				hit.tokens.some((t) => {
					if (!t || typeof t !== 'object') return false;
					const h = t.head != null ? String(t.head).trim() : '';
					const d = t.deprel != null ? String(t.deprel).trim() : '';
					return (h !== '' && h !== '_') || (d !== '' && d !== '_');
				});
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				const container = this.buildContextContainer(hit);
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					const hasDeps = !!container.querySelector('tok[head], tok[deprel], dtok[head], dtok[deprel], mtok[head], mtok[deprel]');
					// Pando: sentence id often only on token rows (s_id), not on hit.sentence_id / XML <s>.
					if (hasDeps && cid) out = { cid, sid: sidResolved || '' };
				}
			}
			// Plain Pando JSON (no XML fragment): still offer tree when token rows carry deps + s_id.
			if (!out && cid && hasDepsInTokens) {
				out = { cid, sid: sidResolved || '' };
			}
			if (cache) cache.deptree = out;
			return out;
		},

		/** Shared token uid for parallel alignment (TEITOK depalign). */
		getHitAlignmentTuidForDepalign(hit) {
			if (!hit || typeof hit !== 'object') return '';
			this._logAlignmentTuidDebug(hit, 'depalign:entry', {});
			if (hit.tuid != null && String(hit.tuid).trim()) {
				const v = String(hit.tuid).trim();
				this._logAlignmentTuidDebug(hit, 'depalign:hit.tuid', { value: v });
				return v;
			}
			const scopeFromXml = this.getHitEnclosingScopeTuidFromXmlContext(hit);
			if (scopeFromXml) {
				this._logAlignmentTuidDebug(hit, 'depalign:scopeFromXml', { value: scopeFromXml });
				return scopeFromXml;
			}
			const matchIds = this.getHitMatchIds(hit);
			const want = new Set((Array.isArray(matchIds) ? matchIds : []).map((x) => String(x)));
			if (Array.isArray(hit.tokens)) {
				for (let i = 0; i < hit.tokens.length; i += 1) {
					const tok = hit.tokens[i];
					if (!tok || typeof tok !== 'object') continue;
					const tid = this.hitTokenIdString(tok);
					if (!want.size || (tid && want.has(tid))) {
						const tu = tok.tuid != null ? String(tok.tuid).trim() : '';
						if (tu) {
							this._logAlignmentTuidDebug(hit, 'depalign:tokens.matchedRow', { index: i, tid, tuid: tu });
							return tu;
						}
					}
				}
				for (let j = 0; j < hit.tokens.length; j += 1) {
					const tok = hit.tokens[j];
					if (!tok || typeof tok !== 'object') continue;
					const tu = tok.tuid != null ? String(tok.tuid).trim() : '';
					if (tu) {
						this._logAlignmentTuidDebug(hit, 'depalign:tokens.anyRow', { index: j, tuid: tu });
						return tu;
					}
				}
			}
			this._logAlignmentTuidDebug(hit, 'depalign:noTokenJsonTuid', { tokensLen: Array.isArray(hit.tokens) ? hit.tokens.length : 0 });
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				try {
					const assembled = this._assembledKwicXmlContextForHit(hit);
					const rawCtx = this.getHitContext(hit) || '';
					const candidates = [];
					if (assembled.trim()) candidates.push(assembled);
					if (rawCtx.trim()) candidates.push(rawCtx);
					this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr', {
						assembledLen: assembled.length,
						rawCtxLen: rawCtx.length,
						candidateCount: candidates.length,
					});
					const seen = new Set();
					for (let ci = 0; ci < candidates.length; ci += 1) {
						const ctx = candidates[ci];
						if (!ctx || seen.has(ctx)) continue;
						seen.add(ctx);
						const container = this.buildContextContainer(hit, ctx);
						if (!container || container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback'))
							continue;
						const mids = this.getHitMatchIds(hit);
						const idSet = this._expandMatchIdSet(new Set(Array.isArray(mids) ? mids : []));
						const nodes = this.xmlContextTokenElements(container).filter(
							(n) => n.getAttribute && String(n.getAttribute('tuid') || '').trim()
						);
						this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.nodes', {
							candidateIndex: ci,
							nodesWithTuid: nodes.length,
							idSetSize: idSet.size,
						});
						for (let i = 0; i < nodes.length; i += 1) {
							const n = nodes[i];
							const stable = this.xmlElementStableIds(n);
							const tuid = String(n.getAttribute('tuid') || '').trim();
							if (!tuid) continue;
							if (!idSet.size || stable.some((id) => idSet.has(id))) {
								this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.pick', { stable, tuid });
								return tuid;
							}
						}
						const any = nodes[0] || null;
						if (any && any.getAttribute) {
							const tuid = String(any.getAttribute('tuid') || '').trim();
							if (tuid) {
								this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.firstAny', { tuid });
								return tuid;
							}
						}
					}
				} catch (err) {
					this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.catch', {
						err: err && err.message ? err.message : String(err),
					});
				}
			} else {
				this._logAlignmentTuidDebug(hit, 'depalign:skipDomTokAttr', {
					reason: !this.isXmlContext() ? '!isXmlContext' : '!hitHasStructuredContext',
				});
			}
			this._logAlignmentTuidDebug(hit, 'depalign:empty', { result: '' });
			return '';
		},

		/**
		 * Depalign expects sentence-level alignment id on both sides; token offsets like ".w13" are stripped.
		 * Optional TEITOK jump= passes the full token tuid for highlighting after navigation.
		 */
		_flexicorpDepalignSentenceTuidFromTokenTuid(fullTokenTuid) {
			const s = String(fullTokenTuid || '').trim();
			if (!s) return '';
			const m = s.match(/^(.+)\.[wt]\d+$/i);
			return m && m[1] ? String(m[1]).trim() : s;
		},

		/**
		 * Parallel corpus: open TEITOK depalign with both document ids and sentence-level alignment id.
		 * See index.php?action=depalign&ids=…&tuid=<sentence>&jump=<token> (optional).
		 */
		getHitParallelDepalignInfo(hit) {
			if (!hit || typeof hit !== 'object') return null;
			if (!this.searchHasAlignedHits()) return null;
			const companion = this.getAlignedCompanionHit(hit);
			if (!companion || typeof companion !== 'object') return null;
			const depA = this.getHitDependencyTreeInfo(hit);
			const depB = this.getHitDependencyTreeInfo(companion);
			if (!depA || !depA.cid || !depB || !depB.cid) return null;
			const cidA = String(depA.cid).trim();
			const cidB = String(depB.cid).trim();
			if (!cidA || !cidB || cidA === cidB) return null;
			const side = this.getHitOwnAlignedSide(hit);
			let srcCid = cidA;
			let tgtCid = cidB;
			if (side === 'target') {
				srcCid = cidB;
				tgtCid = cidA;
			} else if (side === 'source') {
				srcCid = cidA;
				tgtCid = cidB;
			}
			const tokenTuid =
				this.getHitAlignmentTuidForDepalign(hit) ||
				this.getHitAlignmentTuidForDepalign(companion);
			if (!tokenTuid) return null;
			const sentenceTuid = this._flexicorpDepalignSentenceTuidFromTokenTuid(tokenTuid);
			if (!sentenceTuid) return null;
			const out = { ids: `${srcCid},${tgtCid}`, tuid: sentenceTuid };
			if (tokenTuid !== sentenceTuid) {
				out.jump = tokenTuid;
			}
			return out;
		},

		getParallelDepalignInfoForPair(sourceHit, companionHit) {
			if (!sourceHit || typeof sourceHit !== 'object' || !companionHit || typeof companionHit !== 'object') return null;
			const srcCid = String(this.getHitDocCid(sourceHit) || sourceHit.doc_id || sourceHit.text_id || '').trim();
			const tgtCid = String(this.getHitDocCid(companionHit) || companionHit.doc_id || companionHit.text_id || '').trim();
			if (!srcCid || !tgtCid || srcCid === tgtCid) return null;
			const tokenTuid =
				this.getHitAlignmentTuidForDepalign(sourceHit) ||
				this.getHitAlignmentTuidForDepalign(companionHit);
			if (!tokenTuid) return null;
			const sentenceTuid = this._flexicorpDepalignSentenceTuidFromTokenTuid(tokenTuid);
			if (!sentenceTuid) return null;
			const out = { ids: `${srcCid},${tgtCid}`, tuid: sentenceTuid };
			if (tokenTuid !== sentenceTuid) out.jump = tokenTuid;
			return out;
		},

		/** flexicorp-pando may attach `teitok_tuview` on aligned pairs (group vs pair docs/tuid for `action=tuview`). */
		getHitTeitokTuviewPayload(hit) {
			if (!hit || typeof hit !== 'object') return null;
			if (hit.teitok_tuview && typeof hit.teitok_tuview === 'object') return hit.teitok_tuview;
			const raw = hit.aligned_payload_raw;
			if (raw && typeof raw === 'object' && raw.teitok_tuview && typeof raw.teitok_tuview === 'object') {
				return raw.teitok_tuview;
			}
			return null;
		},

		teitokTuviewUrlFromDocsTuidSet(docs, tuid, set) {
			const d = String(docs || '').trim();
			const t = String(tuid || '').trim();
			if (!d || !t) return '';
			const u = new URL('index.php', window.location.href);
			u.searchParams.set('action', 'tuview');
			u.searchParams.set('docs', d);
			u.searchParams.set('tuid', t);
			const s = String(set || '').trim();
			if (s) u.searchParams.set('set', s);
			return u.toString();
		},

		/** All aligned documents / tuids for this source row (same source position, multiple targets). */
		teitokTuviewGroupUrl(sourceHit) {
			const tv = this.getHitTeitokTuviewPayload(sourceHit);
			if (!tv || !tv.group || typeof tv.group !== 'object') return '';
			const g = tv.group;
			return this.teitokTuviewUrlFromDocsTuidSet(g.docs, g.tuid, tv.set);
		},

		/** This source row together with one aligned target only. */
		teitokTuviewPairUrl(companionHit) {
			if (!companionHit || typeof companionHit !== 'object') return '';
			const tv = this.getHitTeitokTuviewPayload(companionHit);
			if (!tv || !tv.pair || typeof tv.pair !== 'object') return '';
			const p = tv.pair;
			return this.teitokTuviewUrlFromDocsTuidSet(p.docs, p.tuid, tv.set);
		},

		getEyeCandyHtmlForAlignedPair(sourceHit, companionHit) {
			const depAlign = this.getParallelDepalignInfoForPair(sourceHit, companionHit);
			if (!depAlign || !depAlign.ids || !depAlign.tuid) return '';
			const urlAlign = new URL('index.php', window.location.href);
			urlAlign.searchParams.set('action', 'depalign');
			urlAlign.searchParams.set('ids', depAlign.ids);
			urlAlign.searchParams.set('tuid', depAlign.tuid);
			if (depAlign.jump) {
				urlAlign.searchParams.set('jump', depAlign.jump);
			}
			return `<a class="flexicorp-eye-candy-btn" href="${this.escapeHtmlForAttr(urlAlign.toString())}" target="_blank" rel="noopener noreferrer" title="Open aligned dependency trees (sentence scope); jump highlights the match token">trees</a>`;
		},

		/** All parallel depalign targets for a source hit (one per grouped aligned companion). */
		getHitParallelDepalignInfos(hit) {
			if (!hit || typeof hit !== 'object') return [];
			const alignSide = this.getHitOwnAlignedSide(hit);
			if (alignSide === 'target') return [];
			const depA = this.getHitDependencyTreeInfo(hit);
			const srcCid = String((depA && depA.cid) || this.getHitDocCid(hit) || hit.doc_id || hit.text_id || '').trim();
			if (!srcCid) return [];
			const companions = this.getGroupedAlignedCompanions(hit);
			if (!Array.isArray(companions) || !companions.length) return [];
			const out = [];
			const seen = new Set();
			for (let i = 0; i < companions.length; i += 1) {
				const companion = companions[i];
				if (!companion || typeof companion !== 'object') continue;
				const depB = this.getHitDependencyTreeInfo(companion);
				const tgtCid = String((depB && depB.cid) || this.getHitDocCid(companion) || companion.doc_id || companion.text_id || '').trim();
				if (!tgtCid || tgtCid === srcCid) continue;
				const tokenTuid =
					this.getHitAlignmentTuidForDepalign(hit) ||
					this.getHitAlignmentTuidForDepalign(companion);
				if (!tokenTuid) continue;
				const sentenceTuid = this._flexicorpDepalignSentenceTuidFromTokenTuid(tokenTuid);
				if (!sentenceTuid) continue;
				const info = { ids: `${srcCid},${tgtCid}`, tuid: sentenceTuid };
				if (tokenTuid !== sentenceTuid) info.jump = tokenTuid;
				const langHint = this.getAlignedDistinguishingValue(companion);
				if (langHint) info.label = this.formatAlignedDistinguishingValue(langHint);
				const key = `${info.ids}::${info.tuid}::${info.jump || ''}`;
				if (seen.has(key)) continue;
				seen.add(key);
				out.push(info);
			}
			return out;
		},

		/** TEI line / line-break geometry from the context fragment (verse line), when @facs is only on pb. */
		_parseBBox(raw) {
			const nums = String(raw == null ? '' : raw).trim().split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
			if (nums.length < 4) return null;
			const [x1, y1, x2, y2] = nums.slice(0, 4);
			return (x2 > x1 && y2 > y1) ? [x1, y1, x2, y2] : null;
		},

		_unionBBox(a, b) {
			return [Math.min(a[0], b[0]), Math.min(a[1], b[1]), Math.max(a[2], b[2]), Math.max(a[3], b[3])];
		},

		/**
		 * Line box from the fragment: the <l bbox> around the first matched token, else
		 * the last <lb bbox> before it (not simply the first line in the fragment, which
		 * may be a later line: Litoměřice "bogu" is on the line before the sentence's
		 * only <lb>); without matched ids, the first line.
		 */
		_lineBBoxFromFragmentContainer(container, idSet) {
			if (!container || !container.querySelector) return null;
			let line = null;
			if (idSet && idSet.size) {
				const ref = this._refTokenForPrecedingPb(container, idSet);
				if (ref) {
					line = ref.closest ? ref.closest('l[bbox]') : null;
					if (!line) {
						const before = Array.from(container.querySelectorAll('lb[bbox]')).filter(
							(lb) => lb.compareDocumentPosition(ref) & Node.DOCUMENT_POSITION_FOLLOWING
						);
						if (before.length) line = before[before.length - 1];
						else if (container.querySelector('lb[bbox]')) return null; // its line starts before the fragment
					}
				}
			}
			if (!line) line = container.querySelector('l[bbox], lb[bbox]');
			if (!line || !line.getAttribute) return null;
			const raw = (line.getAttribute('bbox') || '').trim();
			if (!raw) return null;
			const nums = raw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
			if (nums.length < 4) return null;
			const [x1, y1, x2, y2] = nums.slice(0, 4);
			if (!(x2 > x1 && y2 > y1)) return null;
			return [x1, y1, x2, y2];
		},

		/**
		 * First matched token in the fragment (for ./preceding::pb[1]/@facs-style resolution in XML).
		 */
		_refTokenForPrecedingPb(container, idSet) {
			if (!container || !container.querySelector) return null;
			if (idSet && idSet.size) {
				const expanded = this._expandMatchIdSet(idSet instanceof Set ? idSet : new Set(idSet));
				for (const el of container.querySelectorAll('tok, dtok, mtok')) {
					const id = this.xmlElementId(el);
					if (id && expanded.has(id)) return el;
				}
			}
			return container.querySelector('tok, dtok, mtok');
		},

		/**
		 * Nearest preceding pb/@facs before refEl (same idea as CQP xpath ./preceding::pb[1]/@facs).
		 */
		_facsFromPrecedingPb(container, refEl) {
			if (!container || !refEl || !container.querySelectorAll) return '';
			const pbs = Array.from(container.querySelectorAll('pb[facs]'));
			if (!pbs.length) return '';
			const before = pbs.filter(
				(pb) => pb.compareDocumentPosition(refEl) & Node.DOCUMENT_POSITION_FOLLOWING
			);
			if (!before.length) return '';
			let best = before[0];
			for (let i = 1; i < before.length; i++) {
				const pb = before[i];
				if (best.compareDocumentPosition(pb) & Node.DOCUMENT_POSITION_FOLLOWING) best = pb;
			}
			return (best.getAttribute('facs') || '').trim();
		},

		/**
		 * Union bbox of matched tokens only (not every node with @bbox in the fragment).
		 */
		_unionBBoxFromMatchedTokens(container, idSet) {
			if (!container || !container.querySelectorAll || !idSet || !idSet.size) return null;
			const expanded = this._expandMatchIdSet(idSet instanceof Set ? idSet : new Set(idSet));
			let lx = Infinity;
			let ly = Infinity;
			let ux = -Infinity;
			let uy = -Infinity;
			let any = false;
			for (const node of container.querySelectorAll('tok[bbox], dtok[bbox], mtok[bbox]')) {
				if (!node || !node.getAttribute) continue;
				const id = this.xmlElementId(node);
				if (!id || !expanded.has(id)) continue;
				const bboxRaw = (node.getAttribute('bbox') || '').trim();
				const parts = bboxRaw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
				if (parts.length < 4) continue;
				const [x1, y1, x2, y2] = parts.slice(0, 4);
				lx = Math.min(lx, x1);
				ly = Math.min(ly, y1);
				ux = Math.max(ux, x2);
				uy = Math.max(uy, y2);
				any = true;
			}
			if (!any || ![lx, ly, ux, uy].every((n) => Number.isFinite(n)) || !(ux > lx && uy > ly)) return null;
			return [lx, ly, ux, uy];
		},

		getHitFacsimileInfo(hit) {
			const cache = this._getHitEyeCandyCache(hit);
			if (cache && cache.facs !== undefined) return cache.facs;
			let out = null;
			let resolvedScope = '';
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				resolvedScope = (hit && hit.context && (hit.context.resolved_scope || hit.context.scope) ? String(hit.context.resolved_scope || hit.context.scope) : '')
					.trim()
					.toLowerCase();
				const container = this.buildContextContainer(hit);
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					// Prefer bbox spanning matched tokens only; facs may be absent on <tok> in XML
					// (CQP defines it via xpath e.g. ./preceding::pb[1]/@facs) and is supplied as hit.facs.
					const matchIds = this.getHitMatchIds(hit);
					const idSet = this._expandMatchIdSet(new Set(matchIds));

					// Do not use *[bbox][facs] — <pb bbox facs> is page-sized and breaks line crops; CQP
					// facs for the match comes from hit.facs (xpath), not from whichever pb appears in the slice.
					const candidates = Array.from(container.querySelectorAll(
						'l[bbox][facs], lb[bbox][facs], tok[bbox][facs], dtok[bbox][facs], mtok[bbox][facs]'
					));

					const filtered = idSet.size
						? candidates.filter((n) => {
							const id = n && n.getAttribute ? this.xmlElementId(n) : '';
							return id && idSet.has(id);
						})
						: candidates;

					const parseFromNodes = (nodes) => {
						// Parse bbox & keep union in the first observed facs image.
						let chosen = '';
						let lx = Infinity;
						let ly = Infinity;
						let ux = -Infinity;
						let uy = -Infinity;
						for (const node of nodes) {
							if (!node || !node.getAttribute) continue;
							const bboxRaw = (node.getAttribute('bbox') || '').trim();
							const facsRaw = (node.getAttribute('facs') || '').trim();
							if (!facsRaw || !bboxRaw) continue;
							const parts = bboxRaw.split(/\s+/).map(Number).filter(n => Number.isFinite(n));
							if (parts.length < 4) continue;

							if (!chosen) chosen = facsRaw;
							// If tokens span different facsimile pages, fall back to the first.
							if (chosen !== facsRaw) continue;

							const [x1, y1, x2, y2] = parts.slice(0, 4);
							lx = Math.min(lx, x1);
							ly = Math.min(ly, y1);
							ux = Math.max(ux, x2);
							uy = Math.max(uy, y2);
						}

						if (!chosen) return null;
						if (![lx, ly, ux, uy].every((n) => Number.isFinite(n))) return null;
						return { facs: chosen, bbox: [lx, ly, ux, uy] };
					};

					out = parseFromNodes(filtered);
					// Indexed facs (CQP tabulate / Pando hit row) matches cqpsettings xpath; fragment may
					// still carry a different pb — always use backend path for the image when provided.
					if (out && hit && typeof hit === 'object' && hit.facs) {
						const hf = String(hit.facs).trim();
						if (hf) out.facs = hf;
					}

					// Corpus often has @facs only on CQP side (derived from preceding pb), not on tokens.
					if (!out && idSet.size) {
						const unionTok = this._unionBBoxFromMatchedTokens(container, idSet);
						const facsFromHit = hit && typeof hit === 'object' && hit.facs ? String(hit.facs).trim() : '';
						if (unionTok && facsFromHit) {
							out = { facs: facsFromHit, bbox: unionTok };
						} else if (unionTok && !facsFromHit) {
							const refTok = this._refTokenForPrecedingPb(container, idSet);
							const facsPb = refTok ? this._facsFromPrecedingPb(container, refTok) : '';
							if (facsPb) out = { facs: facsPb, bbox: unionTok };
						}
					}

				}
			}

			// Fallback: the XML fragment might contain bbox but *not* facs (e.g. facs
			// can live on <pb/> outside the returned XML fragment). In that case we
			// take facs from the backend (hit.facs) and build bbox from XML (union),
			// instead of requiring @facs inside the fragment.
			if (!out && hit && typeof hit === 'object' && hit.facs) {
				const facsRaw = String(hit.facs).trim();
				let bboxArr = hit.bbox;
				let unionBBox = null;

				// If the XML fragment has @bbox but not @facs, we can still build a bbox
				// spanning all matched token ids, then reuse hit.facs for the image.
				let fallbackContainer = null;
				if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
					fallbackContainer = this.buildContextContainer(hit);
					if (fallbackContainer && !fallbackContainer.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
						const matchIds = this.getHitMatchIds(hit);
						const idSet = new Set(matchIds);
						// Do not union every *[bbox] (line/page boxes skew the crop); matched tokens only.
						unionBBox = idSet.size ? this._unionBBoxFromMatchedTokens(fallbackContainer, idSet) : null;
					}
				}

				// Prefer <l>/<lb> line bbox, then token union; use CQP tabulate bbox only as last resort
				// (it is often the full page from pb, not the verse line).
				let lineBBox = null;
				if (fallbackContainer && !fallbackContainer.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					lineBBox = this._parseBBox(hit.line_bbox)
						|| this._lineBBoxFromFragmentContainer(fallbackContainer, new Set(this.getHitMatchIds(hit)));
				}
				if (bboxArr !== undefined && bboxArr !== null && !Array.isArray(bboxArr)) {
					const bboxStr = String(bboxArr).trim();
					if (bboxStr) {
						bboxArr = bboxStr
							.split(/\s+/)
							.map((s) => Number(s))
							.filter((n) => Number.isFinite(n));
					}
				}
				const backendBBox = Array.isArray(bboxArr) && bboxArr.length >= 4 ? bboxArr.slice(0, 4) : null;
				const chosenBBox = lineBBox || unionBBox || backendBBox;
				if (facsRaw && chosenBBox) out = { facs: facsRaw, bbox: chosenBBox };
			}

			// Line bbox + token highlights: use fragment <l>/<lb> whenever present (scope may still say "s").
			if (out && this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				const container = this.buildContextContainer(hit);
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					// the line the match is on: indexed per token (hit.line_bbox), else the
					// line break before the match in the fragment
					const lineBBox = this._parseBBox(hit && hit.line_bbox)
						|| this._lineBBoxFromFragmentContainer(container, new Set(this.getHitMatchIds(hit)));
					if (lineBBox) {
						const word = Array.isArray(out.bbox) ? out.bbox : null;
						out.bbox = word ? this._unionBBox(lineBBox, word) : lineBBox;
						if (word && !out.highlights) out.highlights = [word];
					}
					else if (resolvedScope === 'l' || resolvedScope === 'lb') {
						const outer = container.querySelector(`${resolvedScope}[bbox]`);
						if (outer && outer.getAttribute) {
							const bboxRaw = (outer.getAttribute('bbox') || '').trim();
							const nums = bboxRaw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
							if (nums.length >= 4) out.bbox = nums.slice(0, 4);
						}
					}
					const wantHl = lineBBox || resolvedScope === 'l' || resolvedScope === 'lb' || container.querySelector('l[bbox], lb[bbox]');
					if (wantHl) {
						const matchIds = this.getHitMatchIds(hit);
						const idSet = this._expandMatchIdSet(new Set(matchIds));
						const rects = [];
						const nodes = Array.from(container.querySelectorAll('tok[bbox], dtok[bbox], mtok[bbox]'));
						for (const node of nodes) {
							if (!node || !node.getAttribute) continue;
							const id = this.xmlElementId(node);
							if (!idSet.size || !idSet.has(id)) continue;
							const bboxRaw = (node.getAttribute('bbox') || '').trim();
							const nums = bboxRaw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
							if (nums.length >= 4) rects.push(nums.slice(0, 4));
						}
						if (rects.length) out.highlights = rects;
					}
				}
			}
			if (cache) cache.facs = out;
			return out;
		},

		searchHasAnyEyeCandy() {
			if (!this.search || !this.search.ran) return false;
			if (!this.isSearchViewMode('table')) return false;
			const hits = this.getMergedSearchHits();
			return Array.isArray(hits) && hits.some((hit) => this.hasEyeCandy(hit));
		},

		searchHasAnyEyeCandyAlignedSide(side) {
			if (!this.search || !this.search.ran) return false;
			if (!this.isSearchViewMode('table')) return false;
			if (!this.searchHasAlignedHits()) return false;
			const sideKey = String(side || '').toLowerCase();
			const hits = this.getMergedSearchHits();
			if (!Array.isArray(hits) || !hits.length) return false;
			if (sideKey === 'source' || sideKey === 'src') {
				return hits.some((hit) => this.hasEyeCandy(hit));
			}
			if (sideKey === 'target' || sideKey === 'tgt') {
				return hits.some((hit) => {
					const companion = this.getAlignedCompanionHit(hit);
					return companion && this.hasEyeCandy(companion);
				});
			}
			return false;
		},

		hasEyeCandy(hit) {
			const audio = this.getHitAudioInfo(hit);
			const dep = this.getHitDependencyTreeInfo(hit);
			const depAlign = this.getHitParallelDepalignInfo(hit);
			const facs = this.getHitFacsimileInfo(hit);
			return !!(audio || dep || depAlign || facs);
		},

		/** Merged search hits that can show a facsimile cutout (same rule as the facs button). */
		getFacsNavigableHits() {
			return this.getMergedSearchHits().filter((h) => {
				const f = this.getHitFacsimileInfo(h);
				return !!(f && f.facs && Array.isArray(f.bbox));
			});
		},

		_findFacsNavIndexByArgs(navHits, cid, facs, bboxCsv, jumpTok) {
			if (!Array.isArray(navHits) || !navHits.length) return -1;
			const c = String(cid || '').trim();
			const facsStr = String(facs || '').trim();
			const bb = String(bboxCsv || '').trim();
			const j = String(jumpTok || '').trim();
			return navHits.findIndex((h) => {
				const fi = this.getHitFacsimileInfo(h);
				if (!fi || !fi.facs || !Array.isArray(fi.bbox)) return false;
				const hc = h.doc_id ? String(h.doc_id) : '';
				if (hc !== c) return false;
				if (String(fi.facs).trim() !== facsStr) return false;
				const bb2 = fi.bbox.slice(0, 4).join(',');
				if (bb2 !== bb) return false;
				const mids = this.getHitMatchIds(h);
				const j2 = mids.length ? String(mids[0]) : '';
				return j === j2;
			});
		},

		_removeFacsCutoutKeyListener() {
			if (this._facsCutoutKeydownBound) {
				document.removeEventListener('keydown', this._facsCutoutKeydownBound, true);
				this._facsCutoutKeydownBound = null;
			}
		},

		_setupFacsCutoutKeyboard() {
			this._removeFacsCutoutKeyListener();
			const handler = (e) => {
				const overlay = document.getElementById('flexicorp-facs-cutout-overlay');
				if (!overlay || !overlay.classList.contains('flexicorp-facs-cutout-overlay--open')) return;
				const nav = this._facsCutoutNav;
				if (e.key === 'Escape') {
					e.preventDefault();
					e.stopPropagation();
					this._closeFacsCutoutModal();
					return;
				}
				if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
				if (!nav || !Array.isArray(nav.hits) || !nav.hits.length) return;
				e.preventDefault();
				e.stopPropagation();
				if (nav.hits.length < 2) return;
				const delta = e.key === 'ArrowDown' ? 1 : -1;
				const idx = (nav.index + delta + nav.hits.length) % nav.hits.length;
				const nextHit = nav.hits[idx];
				if (!nextHit) return;
				const f = this.getHitFacsimileInfo(nextHit);
				if (!f || !f.facs || !Array.isArray(f.bbox)) return;
				const cid = nextHit.doc_id ? String(nextHit.doc_id) : '';
				const bboxCsv = f.bbox.slice(0, 4).join(',');
				const highlights = Array.isArray(f.highlights) ? f.highlights : [];
				const highlightsJson = JSON.stringify(highlights);
				const matchIds = this.getHitMatchIds(nextHit);
				const jumpTok = matchIds.length ? String(matchIds[0]) : '';
				this.openHitFacsimileCutout(cid, f.facs, bboxCsv, highlightsJson, jumpTok, String(idx));
			};
			this._facsCutoutKeydownBound = handler;
			document.addEventListener('keydown', handler, true);
		},

		getEyeCandyHtml(hit) {
			if (!this.hasEyeCandy(hit)) return '';
			const bits = [];

			const depAlign = this.getHitParallelDepalignInfo(hit);
			const alignSide = this.getHitOwnAlignedSide(hit);
			const showParallelDepTrees = !!(depAlign && depAlign.ids && depAlign.tuid && alignSide !== 'target');
			if (showParallelDepTrees) {
				const urlAlign = new URL('index.php', window.location.href);
				urlAlign.searchParams.set('action', 'depalign');
				urlAlign.searchParams.set('ids', depAlign.ids);
				urlAlign.searchParams.set('tuid', depAlign.tuid);
				if (depAlign.jump) {
					urlAlign.searchParams.set('jump', depAlign.jump);
				}
				bits.push(
					`<a class="flexicorp-eye-candy-btn" href="${this.escapeHtmlForAttr(urlAlign.toString())}" target="_blank" rel="noopener noreferrer" title="Open aligned dependency trees (sentence scope); jump highlights the match token">trees</a>`,
				);
			}

			const dep = this.getHitDependencyTreeInfo(hit);
			if (dep && dep.cid && !showParallelDepTrees) {
				const url = new URL('index.php', window.location.href);
				url.searchParams.set('action', 'deptree');
				url.searchParams.set('cid', dep.cid);
				if (dep.sid) {
					// TEITOK variants accept sid and/or s_id for sentence-scoped deptree.
					url.searchParams.set('sid', dep.sid);
					url.searchParams.set('s_id', dep.sid);
				}
				bits.push(`<a class="flexicorp-eye-candy-btn" href="${this.escapeHtmlForAttr(url.toString())}" target="_blank" rel="noopener noreferrer" title="dependency tree">tree</a>`);
			}

			const audio = this.getHitAudioInfo(hit);
			if (audio) {
				const mediaUrl = (audio.url || '').trim();
				const start = audio.start != null ? String(audio.start) : '';
				const end = audio.end != null ? String(audio.end) : '';
				const hasTimes =
					audio.start != null &&
					audio.end != null &&
					Number.isFinite(Number(audio.start)) &&
					Number.isFinite(Number(audio.end)) &&
					Number(audio.end) >= Number(audio.start);
				if (mediaUrl && hasTimes) {
					const safeUrl = this.escapeHtmlForAttr(encodeURIComponent(mediaUrl));
					const safeStart = this.escapeHtmlForAttr(start);
					const safeEnd = this.escapeHtmlForAttr(end);
					bits.push(
						`<button type="button" class="flexicorp-eye-candy-btn" title="play audio" ` +
							`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.playHitAudio) ? window.flexicorpAppInstance.playHitAudio(decodeURIComponent('${safeUrl}'),'${safeStart}','${safeEnd}', this) : null">sound</button>`
					);
				} else if (mediaUrl && !hasTimes) {
					const safeUrl = this.escapeHtmlForAttr(encodeURIComponent(mediaUrl));
					bits.push(
						`<button type="button" class="flexicorp-eye-candy-btn" title="play audio" ` +
							`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.playHitAudio) ? window.flexicorpAppInstance.playHitAudio(decodeURIComponent('${safeUrl}'),'','', this) : null">sound</button>`
					);
				} else if (!mediaUrl && hasTimes && hit && hit.doc_id) {
					const safeCid = this.escapeHtmlForAttr(String(hit.doc_id));
					const safeStart = this.escapeHtmlForAttr(start);
					const safeEnd = this.escapeHtmlForAttr(end);
					bits.push(
						`<button type="button" class="flexicorp-eye-candy-btn" title="play audio" ` +
							`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.playHitAudio) ? window.flexicorpAppInstance.playHitAudio('','${safeStart}','${safeEnd}', this, '${safeCid}') : null">sound</button>`
					);
				}
			}

			const facs = this.getHitFacsimileInfo(hit);
			if (facs && facs.facs && Array.isArray(facs.bbox)) {
				const cid = hit && hit.doc_id ? String(hit.doc_id) : '';
				const safeCid = this.escapeHtmlForAttr(cid);
				const safeFacs = this.escapeHtmlForAttr(String(facs.facs));
				const bboxCsv = (Array.isArray(facs.bbox) ? facs.bbox.slice(0, 4) : []).join(',');
				const safeBbox = this.escapeHtmlForAttr(bboxCsv);
				const highlights = Array.isArray(facs.highlights) ? facs.highlights : [];
				const highlightsJson = JSON.stringify(highlights);
				const safeHighlights = this.escapeHtmlForAttr(highlightsJson);
				const matchIds = this.getHitMatchIds(hit);
				const jumpTok = Array.isArray(matchIds) && matchIds.length ? String(matchIds[0]) : '';
				const safeJump = this.escapeHtmlForAttr(jumpTok);
				const navHits = this.getFacsNavigableHits();
				const navIdx = navHits.findIndex((h) => h === hit);
				const safeNavIdx = this.escapeHtmlForAttr(String(navIdx >= 0 ? navIdx : -1));
				const facsTitle =
					navHits.length > 1
						? 'facsimile view — ↑↓ browse results, Esc close'
						: 'facsimile view — Esc close';
				const safeFacsTitle = this.escapeHtmlForAttr(facsTitle);
				bits.push(
					`<button type="button" class="flexicorp-eye-candy-btn" title="${safeFacsTitle}" ` +
					`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.openHitFacsimileCutout) ? window.flexicorpAppInstance.openHitFacsimileCutout('${safeCid}','${safeFacs}','${safeBbox}','${safeHighlights}','${safeJump}','${safeNavIdx}') : (window.flexicorpAppInstance && window.flexicorpAppInstance.openHitFacsimile) ? window.flexicorpAppInstance.openHitFacsimile('${safeCid}','${safeJump}') : null">facs</button>`
				);
			}

			return bits.join('<br>');
		},

		_resolveMaybeRelativeMediaUrl(url) {
			if (!url || typeof url !== 'string') return '';
			const t = url.trim();
			if (!t) return '';
			if (/^https?:\/\//i.test(t)) return t;
			if (t.startsWith('//')) return window.location.protocol + t;
			try {
				return new URL(t, window.location.href).href;
			} catch (_) {
				return t;
			}
		},

		_extractRecordingUrlFromXmlOrHtmlText(txt) {
			if (!txt || typeof txt !== 'string') return '';
			const s = txt;
			const tryPatterns = () => {
				const patterns = [
					/<recording[^>]*\surl\s*=\s*["']([^"']+)["']/i,
					/<recording[^>]*\starget\s*=\s*["']([^"']+)["']/i,
					/<media[^>]*\surl\s*=\s*["']([^"']+)["']/i,
					/<audio[^>]*\ssrc\s*=\s*["']([^"']+)["']/i,
					/<video[^>]*\ssrc\s*=\s*["']([^"']+)["']/i,
				];
				for (const p of patterns) {
					const m = s.match(p);
					if (m && m[1]) return m[1].trim();
				}
				return '';
			};
			const trimmed = s.trim();
			if (trimmed.startsWith('<?xml') || /^<TEI[\s>]/i.test(trimmed) || /^<tei[\s>]/i.test(trimmed)) {
				try {
					const doc = new DOMParser().parseFromString(trimmed, 'application/xml');
					if (!doc.querySelector('parsererror')) {
						const pick = (el) => {
							if (!el) return '';
							return (
								(el.getAttribute && el.getAttribute('url')) ||
								(el.getAttribute && el.getAttribute('target')) ||
								(el.getAttribute && el.getAttribute('src')) ||
								''
							).trim();
						};
						const rec = doc.querySelector('recording') || doc.getElementsByTagNameNS('*', 'recording')[0];
						let u = pick(rec);
						if (!u) {
							const media = doc.querySelector('media') || doc.getElementsByTagNameNS('*', 'media')[0];
							u = pick(media);
						}
						if (u) return u;
					}
				} catch (_) {
					// fall through
				}
			}
			let out = tryPatterns();
			if (out) return out;
			const loose = s.match(/https?:\/\/[^\s"'<>]+\.(?:mp3|wav|ogg|m4a|webm|aac)(?:\?[^\s"'<>]*)?/i);
			return loose ? loose[0].trim() : '';
		},

		async resolveDocAudioUrlFromTeitok(cid) {
			const k = String(cid || '').trim();
			if (!k) return '';
			if (!this._docAudioUrlCache) this._docAudioUrlCache = Object.create(null);
			if (this._docAudioUrlCache[k] !== undefined) return this._docAudioUrlCache[k];
			const buildUrls = () => {
				const list = [];
				const a = new URL('index.php', window.location.href);
				a.searchParams.set('action', 'file');
				a.searchParams.set('cid', k);
				list.push(a.toString());
				const b = new URL('index.php', window.location.href);
				b.searchParams.set('action', 'file');
				b.searchParams.set('cid', k);
				b.searchParams.set('raw', '1');
				list.push(b.toString());
				const c = new URL('index.php', window.location.href);
				c.searchParams.set('action', 'file');
				c.searchParams.set('cid', k);
				c.searchParams.set('format', 'xml');
				list.push(c.toString());
				return list;
			};
			for (const u of buildUrls()) {
				try {
					const r = await fetch(u, { credentials: 'same-origin' });
					if (!r.ok) continue;
					const txt = await r.text();
					const raw = this._extractRecordingUrlFromXmlOrHtmlText(txt);
					if (raw) {
						const resolved = this._resolveMaybeRelativeMediaUrl(raw);
						this._docAudioUrlCache[k] = resolved;
						return resolved;
					}
				} catch (_) {
					// try next candidate URL
				}
			}
			this._docAudioUrlCache[k] = '';
			return '';
		},

		_clearAudioSegmentEndGuard() {
			if (typeof this._audioSegmentEndCleanup === 'function') {
				try {
					this._audioSegmentEndCleanup();
				} catch (_) {
					// ignore
				}
				this._audioSegmentEndCleanup = null;
			}
		},

		_attachAudioSegmentEndGuard(audioEl, startSec, endSec) {
			this._clearAudioSegmentEndGuard();
			if (!audioEl || !Number.isFinite(endSec) || !Number.isFinite(startSec) || endSec <= startSec) return;
			const endBound = endSec;
			const EPS = 0.05;
			const onTimeUpdate = () => {
				if (audioEl.currentTime >= endBound - EPS) {
					audioEl.pause();
					try {
						audioEl.currentTime = Math.min(endBound, Number.isFinite(audioEl.duration) ? audioEl.duration : endBound);
					} catch (_) {
						// ignore
					}
					this._clearAudioSegmentEndGuard();
				}
			};
			audioEl.addEventListener('timeupdate', onTimeUpdate);
			this._audioSegmentEndCleanup = () => {
				audioEl.removeEventListener('timeupdate', onTimeUpdate);
			};
		},

		_scheduleAudioSegmentEndGuard(start, end) {
			this._clearAudioSegmentEndGuard();
			if (!Number.isFinite(end) || !Number.isFinite(start) || end <= start) return;
			let bound = false;
			const tryBind = () => {
				if (bound) return;
				const el = document.getElementById('track');
				if (!el) return;
				bound = true;
				this._attachAudioSegmentEndGuard(el, start, end);
			};
			const el = document.getElementById('track');
			if (el) {
				el.addEventListener('playing', tryBind, { once: true });
			}
			setTimeout(tryBind, 250);
		},

		/**
		 * For a video (…/Video/<name>.mp4), an audio-only Audio/<name>.mp3 next to it
		 * is used for snippet playback when it exists: every browser decodes mp3, while
		 * whether an mp4 decodes in <audio> or <video> differs per browser, and the clip
		 * needs a few hundred KB instead of the whole video. Checked once per URL.
		 */
		async _preferAudioTrackFor(url) {
			const m = String(url).match(/^(.*\/)Video\/+([^/?#]+)\.(mp4|m4v|webm|ogv|mov)([?#].*)?$/i);
			if (!m) return url;
			if (!this._audioTrackForVideo) this._audioTrackForVideo = Object.create(null);
			if (this._audioTrackForVideo[url] !== undefined) return this._audioTrackForVideo[url] || url;
			const candidate = `${m[1]}Audio/${m[2]}.mp3`;
			let found = '';
			try {
				const r = await fetch(candidate, { method: 'HEAD', credentials: 'same-origin' });
				if (r.ok && /audio|mpeg/i.test(r.headers.get('content-type') || 'audio')) found = candidate;
			} catch (_) { /* keep the video */ }
			this._audioTrackForVideo[url] = found;
			return found || url;
		},

		/**
		 * TEITOK's playpart() plays through whatever element has id="track". For a
		 * video file that must be a <video> element: some browsers load an mp4 video
		 * in an <audio> element but then fail to decode it (MEDIA_ERR_DECODE), while
		 * the same file plays as video. Swap the hidden #track element to match.
		 */
		_ensureTrackElementFor(url) {
			const isVideo = /\.(mp4|m4v|webm|ogv|mov)(\?|#|$)/i.test(String(url || ''));
			const want = isVideo ? 'VIDEO' : 'AUDIO';
			const cur = document.getElementById('track');
			if (cur && cur.tagName === want) return;
			// keep a track element that belongs to the page itself (e.g. TEITOK's own player)
			if (cur && !cur.dataset.flexicorpTrack && cur.parentElement && cur.parentElement.style.display !== 'none') return;
			const el = document.createElement(want.toLowerCase());
			el.id = 'track';
			el.dataset.flexicorpTrack = '1';
			el.setAttribute('preload', 'auto');
			if (isVideo) {
				el.setAttribute('playsinline', '');
				// not display:none: some browsers pause invisible videos
				el.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none';
			} else {
				el.controls = true;
			}
			if (cur) {
				try { cur.pause(); } catch (_) { /* ignore */ }
				cur.replaceWith(el);
				if (el.parentElement && el.parentElement !== document.body) {
					el.parentElement.style.display = isVideo ? '' : 'none';
				}
			} else {
				const wrap = document.createElement('div');
				wrap.style.display = isVideo ? '' : 'none';
				wrap.appendChild(el);
				document.body.appendChild(wrap);
			}
		},

		async playHitAudio(url, startRaw, endRaw, btn, docIdForResolve) {
			this._clearAudioSegmentEndGuard();
			const ok = await this.ensureAudioControlLoaded();
			const start = startRaw !== '' ? Number(startRaw) : null;
			const end = endRaw !== '' ? Number(endRaw) : null;
			let resolvedUrl = (typeof url === 'string' && url.trim()) ? url.trim() : '';
			if (!resolvedUrl && docIdForResolve) {
				resolvedUrl = await this.resolveDocAudioUrlFromTeitok(String(docIdForResolve).trim());
			}
			if (resolvedUrl && !/^https?:\/\//i.test(resolvedUrl)) {
				resolvedUrl = this._resolveMaybeRelativeMediaUrl(resolvedUrl) || resolvedUrl;
			}
			if (resolvedUrl) resolvedUrl = await this._preferAudioTrackFor(resolvedUrl);
			if (resolvedUrl) this._ensureTrackElementFor(resolvedUrl);
			if (ok && typeof window.playpart === 'function' && resolvedUrl) {
				window.playpart(resolvedUrl, Number.isFinite(start) ? start : 0, Number.isFinite(end) ? end : 0, btn || null);
				// TEITOK audiocontrol often seeks to start but does not stop at end; enforce segment end on #track.
				if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
					this._scheduleAudioSegmentEndGuard(start, end);
				}
				return;
			}
			// Fallback: if we have a URL, try native audio playback.
			const audioEl = document.getElementById('track');
			if (!audioEl || !resolvedUrl) return;
			try {
				audioEl.src = resolvedUrl;
				if (Number.isFinite(start)) audioEl.currentTime = Math.max(0, start);
				await audioEl.play();
				if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
					this._attachAudioSegmentEndGuard(audioEl, start, end);
				}
			} catch (_) {
				// Ignore: user gesture / browser restrictions.
			}
		},

		openHitFacsimile(cid, jumpTokId) {
			const safeCid = String(cid || '').trim();
			if (!safeCid) return;
			const url = new URL('index.php', window.location.href);
			// Prefer facsimile view; for line-based corpora this can still show something,
			// and TEITOK will decide whether the view is enabled.
			url.searchParams.set('action', 'facsview');
			url.searchParams.set('cid', safeCid);
			const jmp = String(jumpTokId !== undefined && jumpTokId !== null ? jumpTokId : '').trim();
			if (jmp) url.searchParams.set('jmp', jmp);
			window.open(url.toString(), '_blank', 'noopener,noreferrer');
		},

		openHitFacsimileCutout(cid, facs, bboxCsv, highlightsJson, jumpTokId, facsNavIndexStr) {
			try {
				// eslint-disable-next-line no-console
				console.log('[flexicorp] facs cutout clicked', {
					cid: cid,
					facs: facs,
					bboxCsv: bboxCsv,
					highlightsJsonType: typeof highlightsJson,
				});

				const facsRaw = String(facs || '').trim();
				const cidTrim = String(cid || '').trim();
				const jumpTok = String(jumpTokId !== undefined && jumpTokId !== null ? jumpTokId : '').trim();
				if (!facsRaw) {
					// eslint-disable-next-line no-console
					console.warn('[flexicorp] facs cutout: missing facs, falling back to facsview', { cid: cidTrim });
					this.openHitFacsimile(cidTrim, jumpTok);
					return;
				}

			const bboxNums = String(bboxCsv || '')
				.split(',')
				.map((s) => Number(s.trim()))
				.filter((n) => Number.isFinite(n));
			if (bboxNums.length < 4) {
				// eslint-disable-next-line no-console
				console.warn('[flexicorp] facs cutout invalid bboxCsv', { bboxCsv, bboxNums });
				this.openHitFacsimile(cidTrim, jumpTok);
				return;
			}
			const bbox = bboxNums.slice(0, 4);
			const [x1, y1, x2, y2] = bbox;
			const bw = x2 - x1;
			const bh = y2 - y1;
			if (!Number.isFinite(bw) || !Number.isFinite(bh) || bw <= 0 || bh <= 0) {
				// eslint-disable-next-line no-console
				console.warn('[flexicorp] facs cutout invalid bbox geometry', { bbox: bboxNums, bw, bh, cid: cidTrim });
				this.openHitFacsimile(cidTrim, jumpTok);
				return;
			}

			const overlayId = 'flexicorp-facs-cutout-overlay';
			let overlay = document.getElementById(overlayId);
			if (!overlay) {
				overlay = document.createElement('div');
				overlay.id = overlayId;
				overlay.classList.add('flexicorp-facs-cutout-overlay');
				overlay.setAttribute('role', 'dialog');
				overlay.setAttribute('aria-modal', 'true');
				overlay.innerHTML = `
					<div class="flexicorp-facs-cutout-modal">
						<div class="flexicorp-facs-cutout-head">
							<div class="flexicorp-facs-cutout-head-text">
								<span class="flexicorp-facs-cutout-title">Facsimile cutout</span>
								<span class="flexicorp-facs-cutout-hint">↑↓ browse · Esc close</span>
							</div>
							<button type="button" class="flexicorp-facs-cutout-close" aria-label="Close">×</button>
						</div>
						<div class="flexicorp-facs-cutout-body">
							<div class="flexicorp-facs-cutout-stage" aria-label="Facsimile crop"></div>
							<div class="flexicorp-facs-cutout-meta">
								<a class="flexicorp-facs-cutout-fullview" href="#" target="_blank" rel="noopener noreferrer">Open full facsimile view</a>
							</div>
						</div>
					</div>
				`;
				document.body.appendChild(overlay);

				overlay.addEventListener('click', (e) => {
					if (e && e.target === overlay) this._closeFacsCutoutModal();
				});
				const closeBtn = overlay.querySelector('.flexicorp-facs-cutout-close');
				if (closeBtn) closeBtn.addEventListener('click', () => this._closeFacsCutoutModal());
			}

			// Set full view link (still TEITOK-managed).
			const fullLink = overlay.querySelector('.flexicorp-facs-cutout-fullview');
			if (fullLink) {
				fullLink.href = (() => {
					if (!cidTrim) return '#';
					const url = new URL('index.php', window.location.href);
					url.searchParams.set('action', 'facsview');
					url.searchParams.set('cid', cidTrim);
					if (jumpTok) url.searchParams.set('jmp', jumpTok);
					return url.toString();
				})();
			}

			const navHits = this.getFacsNavigableHits();
			let navIdx = Number.parseInt(String(facsNavIndexStr !== undefined && facsNavIndexStr !== null ? facsNavIndexStr : ''), 10);
			if (!Number.isFinite(navIdx) || navIdx < 0 || navIdx >= navHits.length) {
				navIdx = this._findFacsNavIndexByArgs(navHits, cidTrim, facsRaw, String(bboxCsv || ''), jumpTok);
			}
			if (navHits.length) {
				if (navIdx < 0) navIdx = 0;
				else if (navIdx >= navHits.length) navIdx = navHits.length - 1;
			} else {
				navIdx = 0;
			}
			this._facsCutoutNav = { hits: navHits, index: navIdx };

			const titleEl = overlay.querySelector('.flexicorp-facs-cutout-title');
			const hintEl = overlay.querySelector('.flexicorp-facs-cutout-hint');
			if (titleEl) {
				titleEl.textContent =
					navHits.length > 1 ? `Facsimile cutout (${navIdx + 1} / ${navHits.length})` : 'Facsimile cutout';
			}
			if (hintEl) {
				if (navHits.length > 1) {
					hintEl.style.display = '';
					hintEl.textContent = '↑↓ previous / next · Esc close';
				} else {
					hintEl.style.display = '';
					hintEl.textContent = 'Esc close';
				}
			}

			// Show overlay immediately, but fill crop after image load.
			// Base class must be present: it supplies position:fixed + full-viewport backdrop.
			overlay.classList.add('flexicorp-facs-cutout-overlay');
			overlay.classList.add('flexicorp-facs-cutout-overlay--open');
				// Make visibility robust even if CSS didn't load for some reason.
				overlay.style.display = 'flex';
				overlay.style.position = 'fixed';
				overlay.style.inset = '0';
				overlay.style.alignItems = 'center';
				overlay.style.justifyContent = 'center';
				overlay.style.background = 'rgba(0, 0, 0, 0.65)';
				overlay.style.zIndex = '10000';

			this._setupFacsCutoutKeyboard();
			const stage = overlay.querySelector('.flexicorp-facs-cutout-stage');
				if (stage) stage.textContent = 'Loading image...';
				// eslint-disable-next-line no-console
				console.log('[flexicorp] facs cutout overlay opened', {
					overlayFound: !!overlay,
					stageFound: !!stage,
				});

			// TEITOK's ttxml.php treats pb/@facs like:
			// - if it starts with http or '/', use as-is
			// - otherwise assume it's a filename under `Facsimile/`
			// We mirror that so cutouts also work with externally hosted facsimiles.
			let imgUrl;
			const facsPath = String(facsRaw || '').trim();
			if (/^(https?:\/\/|\/)/i.test(facsPath)) {
				imgUrl = facsPath;
			} else if (/^Facsimile\//i.test(facsPath)) {
				imgUrl = new URL(facsPath, window.location.href).toString();
			} else {
				imgUrl = new URL(`Facsimile/${facsPath}`, window.location.href).toString();
			}
			// eslint-disable-next-line no-console
			console.log('[flexicorp] facs cutout image URL', imgUrl);

			const img = new Image();
			img.onload = () => {
				// eslint-disable-next-line no-console
				console.log('[flexicorp] facs cutout image loaded');
				// Choose a scale that fits bbox into the modal while keeping bbox framing correct.
				const maxW = 720;
				const maxH = 560;
				const imgScaleW = maxW / bw;
				const imgScaleH = maxH / bh;
				const imgscale = Math.max(0.05, Math.min(imgScaleW, imgScaleH));

				const cutW = bw * imgscale;
				const cutH = bh * imgscale;
				const bgW = img.naturalWidth * imgscale;
				const bgH = img.naturalHeight * imgscale;

				const cutEl = overlay.querySelector('.flexicorp-facs-cutout-stage');
				if (!cutEl) return;
				cutEl.innerHTML = '';
				cutEl.style.position = 'relative';
				cutEl.style.overflow = 'hidden';
				cutEl.style.backgroundImage = `url(${imgUrl})`;
				cutEl.style.backgroundRepeat = 'no-repeat';
				cutEl.style.backgroundSize = `${bgW}px ${bgH}px`;
				cutEl.style.backgroundPosition = `-${x1 * imgscale}px -${y1 * imgscale}px`;
				cutEl.style.width = `${cutW}px`;
				cutEl.style.height = `${cutH}px`;

				// Center the crop stage.
				cutEl.style.margin = '0 auto';
				cutEl.style.display = 'block';

				// Optional: highlight matched tokens inside the line crop.
				let rects = [];
				if (typeof highlightsJson === 'string' && highlightsJson.trim()) {
					try {
						const parsed = JSON.parse(highlightsJson);
						if (Array.isArray(parsed)) rects = parsed;
					} catch (_) {
						rects = [];
					}
				}
				if (rects.length) {
					for (const rect of rects) {
						if (!Array.isArray(rect) || rect.length < 4) continue;
						const rx1 = Number(rect[0]);
						const ry1 = Number(rect[1]);
						const rx2 = Number(rect[2]);
						const ry2 = Number(rect[3]);
						if (![rx1, ry1, rx2, ry2].every((n) => Number.isFinite(n))) continue;
						const rhw = rx2 - rx1;
						const rhh = ry2 - ry1;
						if (rhw <= 0 || rhh <= 0) continue;

						const left = (rx1 - x1) * imgscale;
						const top = (ry1 - y1) * imgscale;
						const w = rhw * imgscale;
						const h = rhh * imgscale;

						const hl = document.createElement('div');
						hl.className = 'flexicorp-facs-cutout-highlight';
						hl.style.left = `${left}px`;
						hl.style.top = `${top}px`;
						hl.style.width = `${w}px`;
						hl.style.height = `${h}px`;
						cutEl.appendChild(hl);
					}
				}
			};
			img.onerror = () => {
				// On failure, still show the regular facsview so user is not blocked.
				// eslint-disable-next-line no-console
				console.error('[flexicorp] facs cutout image failed', { imgUrl, cid: cidTrim });
				this._closeFacsCutoutModal();
				this.openHitFacsimile(cidTrim, jumpTok);
			};
			img.src = imgUrl;
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[flexicorp] facs cutout handler threw', e);
				// Don't rethrow: keep UI responsive.
			}
		},

		_closeFacsCutoutModal() {
			this._removeFacsCutoutKeyListener();
			this._facsCutoutNav = null;
			const overlay = document.getElementById('flexicorp-facs-cutout-overlay');
			if (!overlay) return;
			overlay.classList.remove('flexicorp-facs-cutout-overlay--open');
			overlay.style.display = 'none';
		},

		getHitMatchIds(hit) {
			if (!hit || typeof hit !== 'object') return [];
			if (hit.highlight_map && Array.isArray(hit.highlight_map.match) && hit.highlight_map.match.length) {
				return hit.highlight_map.match.filter(Boolean);
			}
			if (hit.highlight_map && hit.highlight_map.default && Array.isArray(hit.highlight_map.default.tok_ids)) {
				return hit.highlight_map.default.tok_ids.filter(Boolean);
			}
			if (Array.isArray(hit.toks) && hit.toks.length) return hit.toks.filter(Boolean);
			return [];
		},

		getResultHighlightPaletteByGroup() {
			const out = {};
			const result = this.search && this.search.response ? this.search.response.result : null;
			const entries = result && Array.isArray(result.legend) && result.legend.length
				? this.sortHighlightEntriesByGroupId(result.legend)
				: (result && Array.isArray(result.groups) && result.groups.length ? this.sortHighlightEntriesByGroupId(result.groups) : []);
			const ids = new Set(
				entries
					.map((e) => String((e && e.id) || '').trim())
					.filter(Boolean),
			);
			entries.forEach((entry, idx) => {
				const palette = this.getGroupPalette(idx);
				const id = String((entry && entry.id) || '').trim();
				const name = String((entry && entry.name) || '').trim();
				const key = String((entry && entry.key) || '').trim();
				const label = String((entry && entry.label) || '').trim();
				// Positional id always wins (t1 → palette 0, t2 → palette 1, …).
				if (id) out[id] = palette.className;
				// User aliases / labels (a, noun, …). Never let a stale auto-name like "t1" on
				// group t2 overwrite t1's palette — that turns every match token the same colour.
				[name, key, label].forEach((k) => {
					if (!k || k === id) return;
					if (/^t\d+$/i.test(k) && ids.has(k) && k !== id) return;
					out[k] = palette.className;
				});
			});
			// Fallback: keep alias colors stable from query order (a,b,...) even if backend legend ids
			// use different keys (e.g. t1/t2 or mixed naming across source/target sides).
			const aliasOrder = this.getAlignedAliasOrderFromQuery(this.getExecutedSearchQueryText());
			aliasOrder.forEach((alias, idx) => {
				const a = String(alias || '').trim();
				if (!a) return;
				if (!out[a]) out[a] = this.getGroupPalette(idx).className;
			});
			return out;
		},

		getAlignedHitSide(hit, sideHint) {
			const hinted = String(sideHint || '').trim().toLowerCase();
			if (hinted === 'source' || hinted === 'target') return hinted;
			if (!hit || typeof hit !== 'object') return '';
			const sideA = hit.aligned_to && typeof hit.aligned_to === 'object' ? String(hit.aligned_to.side || '').trim().toLowerCase() : '';
			if (sideA === 'source' || sideA === 'target') return sideA;
			const sideB = String(hit.aligned_side || hit.side || '').trim().toLowerCase();
			if (sideB === 'source' || sideB === 'target') return sideB;
			return '';
		},

		resolveGroupPaletteClass(groupName, fallbackIndex, hit, paletteByGroup, nonMatchGroupCount, sideHint) {
			const name = String(groupName || '').trim();
			const side = this.getAlignedHitSide(hit, sideHint);
			const aliasOrder = this.getAlignedAliasOrderFromQuery(this.getExecutedSearchQueryText());
			// Critical: aligned side payloads may reuse local names like t1 on both sides.
			// In that case, global paletteByGroup(t1)=A is ambiguous; map by side first.
			if (this.searchHasAlignedHits() && Number(nonMatchGroupCount) === 1 && aliasOrder.length >= 2 && /^t1$/i.test(name)) {
				if (side === 'source') {
					return this.getGroupPalette(0).className;
				}
				if (side === 'target') {
					return this.getGroupPalette(1).className;
				}
			}
			if (name && paletteByGroup && paletteByGroup[name]) {
				return paletteByGroup[name];
			}
			const tMatch = name.match(/^t(\d+)$/i);
			if (tMatch) {
				return this.getGroupPalette(Math.max(0, Number(tMatch[1]) - 1)).className;
			}
			if (name && aliasOrder.length) {
				const aliasIdx = aliasOrder.findIndex((a) => String(a || '').trim() === name);
				if (aliasIdx >= 0) {
					return this.getGroupPalette(aliasIdx).className;
				}
			}
			// Aligned side rows often carry one logical group only; map by side so target stays "B".
			if (this.searchHasAlignedHits() && Number(nonMatchGroupCount) === 1 && aliasOrder.length >= 2 && name !== 'match') {
				if (side === 'source') {
					return this.getGroupPalette(0).className;
				}
				if (side === 'target') {
					return this.getGroupPalette(1).className;
				}
			}
			return this.getGroupPalette(fallbackIndex).className;
		},

		/** Return list of { name, ids } from hit.highlight_map (match + default.tok_ids + any named groups). */
		getHighlightMapGroups(hit, sideHint) {
			if (!hit || typeof hit !== 'object' || !hit.highlight_map || typeof hit.highlight_map !== 'object') return [];
			const out = [];
			const hm = hit.highlight_map;
			const paletteByGroup = this.getResultHighlightPaletteByGroup();
			const countNonMatch = () => {
				if (Array.isArray(hm.groups) && hm.groups.length) {
					return hm.groups.filter((g) => g && typeof g === 'object' && String(g.name || g.id || '').trim().toLowerCase() !== 'match').length;
				}
				return Object.keys(hm).filter((k) => k !== 'match' && k !== 'default' && k !== 'groups').length;
			};
			const nonMatchGroupCount = countNonMatch();
			if (Array.isArray(hm.groups) && hm.groups.length) {
				hm.groups.forEach((group, idx) => {
					if (!group || typeof group !== 'object') return;
					const ids = Array.isArray(group.tok_ids) ? group.tok_ids.filter(Boolean) : [];
					if (!ids.length) return;
					const palette = this.getGroupPalette(idx);
					// Keep palette stable by query-group name (e.g. a/b/t2), not by per-result-group id.
					const groupKey = String(group.name || group.id || `g${idx + 1}`).trim();
					const paletteClass = this.resolveGroupPaletteClass(groupKey, idx, hit, paletteByGroup, nonMatchGroupCount, sideHint);
					out.push({
						id: group.id || group.name || `g${idx + 1}`,
						name: group.name || group.id || `g${idx + 1}`,
						ids,
						querySpan: typeof group.query_span === 'string' ? group.query_span : '',
						resultGroup: Number.isFinite(Number(group.result_group)) ? Number(group.result_group) : null,
						color: group.color || null,
						paletteClass,
						paletteColor: palette.chipColor,
						paletteTextColor: palette.chipTextColor,
						order: idx,
					});
				});
				if (out.length) {
					const sorted = this.sortHighlightEntriesByGroupId(out);
					sorted.forEach((g, idx) => {
						const palette = this.getGroupPalette(idx);
						if (!g.paletteClass) g.paletteClass = palette.className;
						g.paletteColor = palette.chipColor;
						g.paletteTextColor = palette.chipTextColor;
						g.order = idx;
					});
					return sorted;
				}
			}
			if (Array.isArray(hm.match) && hm.match.length) out.push({ name: 'match', ids: hm.match, paletteClass: 'match', paletteColor: '#b08900', paletteTextColor: '#ffffff', order: 0 });
			else if (hm.default && Array.isArray(hm.default.tok_ids) && hm.default.tok_ids.length) out.push({ name: 'match', ids: hm.default.tok_ids, paletteClass: 'match', paletteColor: '#b08900', paletteTextColor: '#ffffff', order: 0 });
			const keys = Object.keys(hm).filter(k => k !== 'match' && k !== 'default' && k !== 'groups');
			keys.sort((a, b) => this.compareGroupIds(a, b));
			keys.forEach((key, idx) => {
				const val = hm[key];
				const palette = this.getGroupPalette(idx);
				const paletteClass = this.resolveGroupPaletteClass(String(key).trim(), idx, hit, paletteByGroup, nonMatchGroupCount, sideHint);
				if (Array.isArray(val) && val.length) out.push({ name: key, ids: val, paletteClass, paletteColor: palette.chipColor, paletteTextColor: palette.chipTextColor, order: idx });
				else if (val && typeof val === 'object' && Array.isArray(val.tok_ids)) out.push({ name: key, ids: val.tok_ids, querySpan: typeof val.query_span === 'string' ? val.query_span : '', color: val.color || null, paletteClass, paletteColor: palette.chipColor, paletteTextColor: palette.chipTextColor, order: idx });
			});
			return out;
		},

		compareGroupIds(a, b) {
			const ax = String(a || '').match(/^t(\d+)$/i);
			const bx = String(b || '').match(/^t(\d+)$/i);
			if (ax && bx) return Number(ax[1]) - Number(bx[1]);
			return String(a || '').localeCompare(String(b || ''));
		},

		/**
		 * Keep legend / highlight groups in t1, t2, … order. Named aliases (e.g. noun:t2) must not
		 * reorder entries relative to token position or palette indices no longer match the XML hits.
		 */
		sortHighlightEntriesByGroupId(entries) {
			if (!Array.isArray(entries) || entries.length < 2) return entries ? entries.slice() : [];
			const copy = entries.slice();
			if (!copy.every((e) => e && /^t\d+$/i.test(String(e.id || '')))) return copy;
			copy.sort((a, b) => this.compareGroupIds(String(a.id || ''), String(b.id || '')));
			return copy;
		},

		getGroupPalette(index) {
			const palette = [
				{ className: 'A', chipColor: '#ff6b6b', chipTextColor: '#ffffff' },
				{ className: 'B', chipColor: '#4c6ef5', chipTextColor: '#ffffff' },
				{ className: 'C', chipColor: '#2f9e44', chipTextColor: '#ffffff' },
				{ className: 'D', chipColor: '#7950f2', chipTextColor: '#ffffff' },
				{ className: 'E', chipColor: '#e67700', chipTextColor: '#ffffff' },
			];
			const safeIndex = Number.isFinite(index) && index >= 0 ? index : 0;
			return palette[safeIndex % palette.length];
		},

		extractQueryTokenClauses(query, queryLang) {
			if (typeof query !== 'string' || !query) return [];
			const lang = String(queryLang || '').toLowerCase();
			if (lang === 'pmltq' || lang === 'clickpmltq') {
				const spans = [];
				const stack = [];
				let start = -1;
				let quote = null;
				for (let i = 0; i < query.length; i += 1) {
					const ch = query[i];
					if (quote) {
						if (ch === '\\') {
							i += 1;
							continue;
						}
						if (ch === quote) quote = null;
						continue;
					}
					if (ch === '"' || ch === "'") {
						quote = ch;
						continue;
					}
					if (ch === '[') {
						stack.push(i);
						continue;
					}
					if (ch === ']' && stack.length) {
						const clauseStart = stack.pop();
						if (Number.isInteger(clauseStart) && clauseStart >= 0) {
							spans.push({
								start: clauseStart,
								end: i + 1,
								depth: stack.length + 1,
							});
						}
					}
				}
				return spans
					.sort((a, b) => (a.start - b.start) || (a.depth - b.depth))
					.map((span) => {
						let clause = query.slice(span.start, span.end).trim();
						if (clause.startsWith('[') && clause.endsWith(']')) {
							clause = clause.slice(1, -1).trim();
						}
						return clause;
					})
					.filter(Boolean);
			}
			const clauses = [];
			let depth = 0;
			let start = -1;
			let quote = null;
			for (let i = 0; i < query.length; i += 1) {
				const ch = query[i];
				if (quote) {
					if (ch === '\\') {
						i += 1;
						continue;
					}
					if (ch === quote) quote = null;
					continue;
				}
				if (ch === '"' || ch === "'") {
					quote = ch;
					continue;
				}
				if (ch === '[') {
					if (depth === 0) start = i + 1;
					depth += 1;
					continue;
				}
				if (ch === ']' && depth > 0) {
					depth -= 1;
					if (depth === 0 && start >= 0) {
						const clause = query.slice(start, i).trim();
						if (clause) clauses.push(clause);
						start = -1;
					}
				}
			}
			return clauses;
		},

		getLegendLabel(entry, tokenClauses) {
			const rawName = entry && (entry.name || entry.id || entry.key) ? String(entry.name || entry.id || entry.key) : '';
			const match = rawName.match(/^t(\d+)$/i);
			if (match) {
				const idx = Number(match[1]) - 1;
				if (tokenClauses[idx]) return tokenClauses[idx];
			}
			if (entry && typeof entry.label === 'string' && entry.label.trim()) return entry.label.trim();
			if (entry && typeof entry.querySpan === 'string' && entry.querySpan.trim()) return entry.querySpan.trim();
			if (rawName === 'match' && tokenClauses.length === 1) return tokenClauses[0];
			return rawName === 'match' ? 'Match' : rawName;
		},

		/** Sanitize group name for use in CSS class (alphanumeric, underscore, hyphen). */
		sanitizeGroupClass(name) {
			return String(name || '').replace(/[^a-z0-9_-]/gi, '') || 'g';
		},

		/** Legend entries for highlight groups: from result.legend / result.groups or derived from first hit. */
		searchHighlightLegend() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const executedQuery = this.search && typeof this.search.query === 'string' && this.search.query
				? this.search.query
				: (result && typeof result.query === 'string' ? result.query : '');
			const queryLang = result && typeof result.query_lang === 'string' ? result.query_lang : '';
			const tokenClauses = this.extractQueryTokenClauses(executedQuery, queryLang);
			const querySpanForName = (name) => {
				const n = String(name || '').trim();
				if (!n) return '';
				const fromAlias = this.getAliasClauseBody(executedQuery, n);
				if (fromAlias) return fromAlias.trim();
				return '';
			};
			const addSafe = (entry) => ({
				...entry,
				safeClass: this.sanitizeGroupClass(entry.name || entry.key || entry.id),
			});
			if (result && Array.isArray(result.legend) && result.legend.length) {
				const legendOrdered = this.sortHighlightEntriesByGroupId(result.legend);
				return legendOrdered.map((entry, idx) => {
					const palette = this.getGroupPalette(idx);
					return addSafe({
						name: entry.name || entry.key || entry.id,
						label: this.getLegendLabel(entry, tokenClauses),
						querySpan: (typeof entry.querySpan === 'string' ? entry.querySpan : (typeof entry.query_span === 'string' ? entry.query_span : '')) || querySpanForName(entry.name || entry.key || entry.id),
						color: entry.color || palette.chipColor,
						textColor: entry.textColor || palette.chipTextColor,
						paletteClass: palette.className,
					});
				});
			}
			if (result && Array.isArray(result.groups) && result.groups.length) {
				const groupsOrdered = this.sortHighlightEntriesByGroupId(result.groups);
				return groupsOrdered.map((entry, idx) => {
					const palette = this.getGroupPalette(idx);
					return addSafe({
						name: entry.name || entry.id,
						label: this.getLegendLabel(entry, tokenClauses),
						querySpan: (typeof entry.querySpan === 'string' ? entry.querySpan : (typeof entry.query_span === 'string' ? entry.query_span : '')) || querySpanForName(entry.name || entry.id),
						color: entry.color || palette.chipColor,
						textColor: entry.textColor || palette.chipTextColor,
						paletteClass: palette.className,
					});
				});
			}
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			const first = hits.find(h => this.getHighlightMapGroups(h).length > 0);
			if (!first) return [];
			return this.getHighlightMapGroups(first).map((group, idx) => addSafe({
				name: group.name || group.id,
				label: this.getLegendLabel(group, tokenClauses),
				querySpan: (typeof group.querySpan === 'string' ? group.querySpan : '') || querySpanForName(group.name || group.id),
				color: group.color || group.paletteColor || this.getGroupPalette(idx).chipColor,
				textColor: group.paletteTextColor || this.getGroupPalette(idx).chipTextColor,
				paletteClass: group.paletteClass || this.getGroupPalette(idx).className,
			}));
		},

		legendEntryTooltip(entry) {
			if (!entry || typeof entry !== 'object') return '';
			const name = String(entry.name || '').trim();
			const label = String(entry.label || '').trim();
			const querySpan = String(entry.querySpan || '').trim();
			if (querySpan) {
				if (name && label && name !== label) return `Query group ${name}: ${label} [${querySpan}]`;
				if (label) return `Query group ${label} [${querySpan}]`;
				if (name) return `Query group ${name} [${querySpan}]`;
				return `Query group [${querySpan}]`;
			}
			if (name && label && name !== label) return `Query group ${name}: ${label}`;
			if (label) return `Query group ${label}`;
			if (name) return `Query group ${name}`;
			return 'Query group';
		},

		_showLegendTooltip(entry, ev) {
			const text = this.legendEntryTooltip(entry);
			if (!text) {
				this._hideLegendTooltip();
				return;
			}
			const x = ev && Number.isFinite(ev.clientX) ? ev.clientX : 0;
			const y = ev && Number.isFinite(ev.clientY) ? ev.clientY : 0;
			const offset = 12;
			this._legendTooltip = {
				visible: true,
				text,
				left: Math.max(8, x + offset),
				top: Math.max(8, y + offset),
			};
		},

		_moveLegendTooltip(ev) {
			if (!this._legendTooltip || !this._legendTooltip.visible) return;
			const x = ev && Number.isFinite(ev.clientX) ? ev.clientX : this._legendTooltip.left || 0;
			const y = ev && Number.isFinite(ev.clientY) ? ev.clientY : this._legendTooltip.top || 0;
			const offset = 12;
			this._legendTooltip.left = Math.max(8, x + offset);
			this._legendTooltip.top = Math.max(8, y + offset);
		},

		_hideLegendTooltip() {
			this._legendTooltip = { visible: false, text: '', left: 0, top: 0 };
		},

		legendTooltipVisible() {
			return !!(this._legendTooltip && this._legendTooltip.visible && this._legendTooltip.text);
		},

		legendTooltipText() {
			return this._legendTooltip && this._legendTooltip.text ? this._legendTooltip.text : '';
		},

		legendTooltipStyle() {
			const tip = this._legendTooltip || {};
			const left = Number.isFinite(tip.left) ? tip.left : 0;
			const top = Number.isFinite(tip.top) ? tip.top : 0;
			return `left:${left}px;top:${top}px;`;
		},

		isXmlContext() {
			return !!(this.search && this.search.contextFormat === 'xml' && this.currentBackendSupportsXmlContext());
		},

		wantsStructuredContext() {
			return !!(this.search && this.search.contextFormat && this.search.contextFormat !== 'none');
		},

		hitHasStructuredContext(hit) {
			return !!this.getHitContext(hit);
		},

		/** Manatee query() sets hit.raw to internal TSV (doc, ids, positions, tokens) — never show that as primary UI. */
		hitLooksLikeManateeEngine(hit) {
			const eng = String((hit && hit.engine) || '').toLowerCase();
			if (eng.includes('manatee')) return true;
			const r = this.search && this.search.response && this.search.response.result;
			const re = r && typeof r.engine === 'string' ? r.engine.toLowerCase() : '';
			if (re.includes('manatee')) return true;
			const cf = r && typeof r.corpus_format === 'string' ? r.corpus_format.toLowerCase() : '';
			return cf === 'manatee';
		},

		/** Plain match words from Manatee hit (toks = surface strings from lexicon). */
		manateeHitPlainLine(hit) {
			if (!hit || typeof hit !== 'object') return '';
			if (Array.isArray(hit.toks) && hit.toks.length) {
				const s = hit.toks
					.filter((t) => t != null && String(t).trim() !== '')
					.map((t) => String(t))
					.join(' ')
					.trim();
				if (s) return s;
			}
			if (Array.isArray(hit.tokens) && hit.tokens.length) {
				const s = this.hitTokensToPlainLine(hit.tokens);
				if (s) return s;
			}
			return '';
		},

		/**
		 * Short label for the Context column link (filename lives in title / showdocinfo, not inline).
		 */
		getHitContextLinkLabel(_hit) {
			return 'Context';
		},

		alignedContextLabel(hit) {
			const ctx = String(this.getHitContextLinkLabel(hit) || 'Context').trim() || 'Context';
			const langRaw = this.getAlignedDistinguishingValue(hit);
			const lang = this.alignedLanguageDisplayName(langRaw);
			return lang ? `Aligned ${ctx} : ${lang}` : `Aligned ${ctx}`;
		},

		alignedContextLanguageLabel(hit) {
			const langRaw = this.getAlignedDistinguishingValue(hit);
			return this.alignedLanguageDisplayName(langRaw);
		},

		alignedLanguageDisplayName(raw) {
			const v = String(raw || '').trim().replace(/\s+context\s*$/i, '').trim();
			if (!v) return '';
			// Already descriptive (e.g. "Indonesian") — keep as-is.
			if (/[A-Z]/.test(v) || v.length > 3 || /[\s_-]/.test(v)) return v;
			const lower = v.toLowerCase();
			const iso3ToIso1 = {
				eng: 'en',
				deu: 'de',
				ger: 'de',
				nld: 'nl',
				dut: 'nl',
				fra: 'fr',
				fre: 'fr',
				spa: 'es',
				por: 'pt',
				ita: 'it',
				rus: 'ru',
				ind: 'id',
				ara: 'ar',
				jpn: 'ja',
				kor: 'ko',
				zho: 'zh',
				chi: 'zh',
				hin: 'hi',
				khm: 'km',
			};
			const code = iso3ToIso1[lower] || lower;
			try {
				if (typeof Intl !== 'undefined' && typeof Intl.DisplayNames === 'function') {
					const dn = new Intl.DisplayNames(['en'], { type: 'language' });
					const label = dn.of(code);
					if (typeof label === 'string' && label.trim()) return label;
				}
			} catch (_) {}
			const fallbackNames = {
				ind: 'Indonesian',
				id: 'Indonesian',
				nld: 'Dutch',
				nl: 'Dutch',
				deu: 'German',
				de: 'German',
				eng: 'English',
				en: 'English',
			};
			return fallbackNames[lower] || v;
		},

		getAlignedDistinguishingValue(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = String(hit.doc_id || hit.text_id || this.getHitDocLabel(hit) || '').trim();
			const docMeta = this.getDocument(docId);
			if (docMeta && typeof docMeta === 'object') {
				const keys = ['text_lang', 'lang', 'language'];
				for (let i = 0; i < keys.length; i += 1) {
					const v = docMeta[keys[i]];
					if (typeof v === 'string' && v.trim()) return v.trim();
				}
			}
			const m = docId.match(/-([A-Za-z][A-Za-z0-9_-]*)\.xml$/i);
			if (m && m[1]) return m[1];
			return '';
		},

		getAlignedCompanionHit(hit) {
			if (!hit || typeof hit !== 'object') return null;
			const side = hit.aligned_counterpart;
			if (!(side && typeof side === 'object')) return null;
			return this.isSelfAlignedCompanion(hit, side) ? null : side;
		},

		getGroupedAlignedCompanions(hit) {
			if (!hit || typeof hit !== 'object') return [];
			if (Array.isArray(hit.__flexicorpAlignedCompanions) && hit.__flexicorpAlignedCompanions.length) {
				return this.dedupeGroupedAlignedCompanions(
					hit,
					hit.__flexicorpAlignedCompanions.filter((c) => !this.isSelfAlignedCompanion(hit, c))
				);
			}
			const single = this.getAlignedCompanionHit(hit);
			return this.dedupeGroupedAlignedCompanions(hit, single ? [single] : []);
		},

		dedupeGroupedAlignedCompanions(sourceHit, companions) {
			if (!Array.isArray(companions) || !companions.length) return [];
			const out = [];
			const seen = new Set();
			for (let i = 0; i < companions.length; i += 1) {
				const c = companions[i];
				if (!c || typeof c !== 'object') continue;
				const key = this.groupedAlignedCompanionDisplayKey(sourceHit, c);
				if (seen.has(key)) continue;
				seen.add(key);
				out.push(c);
			}
			return out;
		},

		groupedAlignedCompanionDisplayKey(_sourceHit, companionHit) {
			if (!companionHit || typeof companionHit !== 'object') return '';
			const docId = String(this.getHitDocLabel(companionHit) || companionHit.doc_id || companionHit.text_id || '').trim();
			const sid = String(this.getHitSentenceIdForNavigation(companionHit) || '').trim();
			const match = this.getSentenceAlignedMatchText(companionHit)
				|| ((companionHit.context && typeof companionHit.context === 'object' && typeof companionHit.context.match === 'string')
					? companionHit.context.match
					: '');
			const ms = Number.isFinite(Number(companionHit.match_start)) ? String(companionHit.match_start) : '';
			const me = Number.isFinite(Number(companionHit.match_end)) ? String(companionHit.match_end) : '';
			// Group by displayed target result first (doc + sentence/result text), with span as fallback.
			return `${docId}::${sid}::${String(match || '').trim()}::${ms}::${me}`;
		},

		isSelfAlignedCompanion(sourceHit, companionHit) {
			if (!sourceHit || !companionHit || typeof sourceHit !== 'object' || typeof companionHit !== 'object') return false;
			const srcDoc = String(this.getHitDocLabel(sourceHit) || sourceHit.doc_id || sourceHit.text_id || '').trim();
			const tgtDoc = String(this.getHitDocLabel(companionHit) || companionHit.doc_id || companionHit.text_id || '').trim();
			if (!srcDoc || !tgtDoc || srcDoc !== tgtDoc) return false;
			const srcStart = Number(sourceHit.match_start);
			const srcEnd = Number(sourceHit.match_end);
			const tgtStart = Number(companionHit.match_start);
			const tgtEnd = Number(companionHit.match_end);
			const sameSpan =
				Number.isFinite(srcStart) &&
				Number.isFinite(srcEnd) &&
				Number.isFinite(tgtStart) &&
				Number.isFinite(tgtEnd) &&
				srcStart === tgtStart &&
				srcEnd === tgtEnd;
			if (sameSpan) return true;
			const srcTok = String(this.getHitTokenIds(sourceHit) || '').trim();
			const tgtTok = String(this.getHitTokenIds(companionHit) || '').trim();
			if (srcTok && tgtTok && srcTok === tgtTok) return true;
			return false;
		},

		getStructuralAlignedSourceGroupKey(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = this.getHitDocLabel(hit) || hit.doc_id || '';
			const sentenceId = String(this.getHitSentenceIdForNavigation(hit) || '').trim();
			const ms = Number.isFinite(Number(hit.match_start)) ? String(hit.match_start) : '';
			const me = Number.isFinite(Number(hit.match_end)) ? String(hit.match_end) : '';
			const tok = this.getHitTokenIds(hit) || '';
			const ctx = this.getSentenceAlignedMatchText(hit) || '';
			return `src-align::${docId}::${sentenceId}::${ms}::${me}::${tok}::${ctx}`;
		},

		getStructuralAlignedCompanionKey(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = this.getHitDocLabel(hit) || hit.doc_id || '';
			const sentenceId = String(this.getHitSentenceIdForNavigation(hit) || '').trim();
			const ms = Number.isFinite(Number(hit.match_start)) ? String(hit.match_start) : '';
			const me = Number.isFinite(Number(hit.match_end)) ? String(hit.match_end) : '';
			const tok = this.getHitTokenIds(hit) || '';
			const ctx = this.getSentenceAlignedMatchText(hit) || '';
			return `tgt-align::${docId}::${sentenceId}::${ms}::${me}::${tok}::${ctx}`;
		},

		queryUsesSentenceAlignedTarget() {
			const queryText = this.getExecutedSearchQueryText();
			if (!queryText) return false;
			return this.queryUsesStructuralAlignedTarget();
		},

		queryUsesStructuralAlignedTarget() {
			const queryText = this.getExecutedSearchQueryText();
			if (!queryText) return false;
			const m = queryText.match(/\bwith\b[\s\S]*?\b([A-Za-z_][A-Za-z0-9_]*)\s*:\s*<([A-Za-z_][A-Za-z0-9_-]*)(?:\s|>)/i);
			if (!m) return false;
			const tag = String(m[2] || '').trim().toLowerCase();
			return !!tag;
		},

		hitLooksSentenceAlignedByIds(hit) {
			const ids = this.getHitMatchIds(hit);
			if (!Array.isArray(ids) || !ids.length) return false;
			return ids.some((id) => {
				const s = String(id || '').trim().toLowerCase();
				if (!s) return false;
				return /^s[-_.]/.test(s) || /(^|[._-])s\d+$/.test(s) || s.includes('.s');
			});
		},

		getHitOwnAlignedSide(hit, sideHint) {
			const hinted = String(sideHint || '').trim().toLowerCase();
			if (hinted === 'source' || hinted === 'target') return hinted;
			if (!hit || typeof hit !== 'object') return '';
			const own = String(hit.aligned_side || hit.side || '').trim().toLowerCase();
			if (own === 'source' || own === 'target') return own;
			const alignedToSide = hit.aligned_to && typeof hit.aligned_to === 'object'
				? String(hit.aligned_to.side || '').trim().toLowerCase()
				: '';
			if (alignedToSide === 'target') return 'source';
			if (alignedToSide === 'source') return 'target';
			return '';
		},

		hitIsSentenceAligned(hit, sideHint) {
			if (!hit || typeof hit !== 'object') return false;
			const hasAlignedMeta = !!this.getAlignedCompanionHit(hit)
				|| !!(hit.aligned_to && typeof hit.aligned_to === 'object')
				|| !!String(hit.aligned_side || hit.side || sideHint || '').trim();
			if (!hasAlignedMeta) return false;
			const ownSide = this.getHitOwnAlignedSide(hit, sideHint);
			if (ownSide !== 'target') return false;
			if (this.queryUsesSentenceAlignedTarget()) return true;
			if (this.hitLooksSentenceAlignedByIds(hit)) return true;
			const sentenceId = this.getHitSentenceIdForNavigation(hit);
			if (sentenceId && !this.getHitMatchIds(hit).length) return true;
			return false;
		},

		getSentenceAlignedMatchText(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const ctx = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const pick = (obj, keys) => {
				if (!obj || typeof obj !== 'object') return '';
				for (const k of keys) {
					const v = obj[k];
					if (typeof v === 'string' && v.trim() !== '') return v;
				}
				return '';
			};
			const fromCtx = pick(ctx, ['match', 'mid', 'center', 'token']);
			if (fromCtx) return fromCtx;
			const fromHit = pick(hit, ['match', 'mid', 'center', 'token', 'word', 'form']);
			if (fromHit) return fromHit;
			if (Array.isArray(hit.tokens) && hit.tokens.length) {
				return this.hitTokensToPlainLine(hit.tokens);
			}
			return '';
		},

		getSentenceAlignedTokenIds(hit) {
			if (!hit || typeof hit !== 'object' || !Array.isArray(hit.tokens) || !hit.tokens.length) return [];
			const ids = [];
			const push = (v) => {
				const s = String(v == null ? '' : v).trim();
				if (!s || s === '_') return;
				if (!ids.includes(s)) ids.push(s);
			};
			for (let i = 0; i < hit.tokens.length; i += 1) {
				const tok = hit.tokens[i];
				if (!tok || typeof tok !== 'object') continue;
				push(tok.id);
				push(tok.tuid);
			}
			return ids;
		},

		clearAlignedHoverHighlights() {
			document.querySelectorAll('.flexicorp-hit-token--aligned-hover').forEach((el) => {
				el.classList.remove('flexicorp-hit-token--aligned-hover');
			});
		},

		highlightAlignedTokenByHover(event) {
			const ev = event || window.event;
			if (!ev || !ev.target) return;
			const tok = ev.target.closest('tok, dtok, mtok');
			if (!tok) return;
			const tuid = String(tok.getAttribute('tuid') || '').trim();
			if (!tuid) {
				this.clearAlignedHoverHighlights();
				return;
			}
			this.clearAlignedHoverHighlights();
			const esc = (typeof CSS !== 'undefined' && CSS.escape) ? CSS.escape(tuid) : tuid.replace(/["\\]/g, '\\$&');
			document.querySelectorAll(`.flexicorp-hit-xml tok[tuid="${esc}"], .flexicorp-hit-xml dtok[tuid="${esc}"], .flexicorp-hit-xml mtok[tuid="${esc}"]`).forEach((el) => {
				el.classList.add('flexicorp-hit-token--aligned-hover');
			});
		},

		getExecutedSearchQueryText() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			if (this.search && typeof this.search.query === 'string' && this.search.query.trim()) {
				return this.search.query;
			}
			if (result && typeof result.query === 'string' && result.query.trim()) {
				return result.query;
			}
			if (result && result.query && typeof result.query === 'object' && typeof result.query.text === 'string') {
				return result.query.text;
			}
			return '';
		},

		getAlignedAliasOrderFromQuery(queryText) {
			const q = String(queryText || '');
			if (!q) return [];
			const out = [];
			const re = /\b([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(?:\[|<)/g;
			let m;
			while ((m = re.exec(q)) !== null) {
				const alias = String(m[1] || '').trim();
				if (!alias) continue;
				if (!out.includes(alias)) out.push(alias);
			}
			return out;
		},

		getAliasClauseBody(queryText, alias) {
			const q = String(queryText || '');
			const a = String(alias || '').trim();
			if (!q || !a) return '';
			const esc = a.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			const bracketRe = new RegExp(`\\b${esc}\\s*:\\s*\\[([\\s\\S]*?)\\]`, 'i');
			const bracketMatch = q.match(bracketRe);
			if (bracketMatch && typeof bracketMatch[1] === 'string') return bracketMatch[1];
			const regionRe = new RegExp(`\\b${esc}\\s*:\\s*(<[^>]+>)`, 'i');
			const regionMatch = q.match(regionRe);
			if (regionMatch && typeof regionMatch[1] === 'string') return regionMatch[1];
			return '';
		},

		getAliasLanguageFromQuery(queryText, alias) {
			const body = this.getAliasClauseBody(queryText, alias);
			if (!body) return '';
			const m = body.match(/\btext_lang\s*=\s*(?:"([^"]+)"|'([^']+)')/i);
			if (!m) return '';
			return String(m[1] || m[2] || '').trim();
		},

		alignedSideHeaderTitle(side) {
			const sideKey = String(side || '').toLowerCase();
			if (sideKey === 'source' || sideKey === 'src') return 'Source';
			if (!(sideKey === 'target' || sideKey === 'tgt')) return '';
			const queryText = this.getExecutedSearchQueryText();
			const aliases = this.getAlignedAliasOrderFromQuery(queryText);
			const targetAlias = aliases.length >= 2 ? aliases[1] : '';
			const lang = targetAlias ? this.getAliasLanguageFromQuery(queryText, targetAlias) : '';
			if (lang) return `Target (Language = ${lang})`;
			return 'Target';
		},

		searchHasAlignedHits() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			for (let i = 0; i < hits.length; i += 1) {
				if (this.getAlignedCompanionHit(hits[i])) return true;
			}
			return false;
		},

		formatAlignedRawFallback(hit) {
			if (!hit || typeof hit !== 'object') return '';
			if (typeof hit.raw === 'string' && hit.raw.trim()) return hit.raw;
			try {
				return JSON.stringify(hit, null, 2);
			} catch (_) {
				return String(hit);
			}
		},

		shouldShowRawHit(hit) {
			if (!hit || typeof hit !== 'object' || !hit.raw) return false;
			if (!this.wantsStructuredContext()) {
				if (this.hitLooksLikeManateeEngine(hit)) return false;
				if (Array.isArray(hit.toks) && hit.toks.length) return false;
				if (Array.isArray(hit.tokens) && hit.tokens.length) return false;
			}
			return !this.wantsStructuredContext();
		},

		searchContextUnavailable() {
			if (!this.wantsStructuredContext()) return false;
			if (!this.search || !Array.isArray(this.search.hits) || !this.search.hits.length) return false;
			return !this.search.hits.some(hit => this.hitHasStructuredContext(hit));
		},

		/**
		 * Show the one-line “Search result” summary (query + stats, or either alone).
		 */
		searchResultHeadlineVisible() {
			if (!this.search || !this.search.ran) return false;
			if (this.callHasIssues(this.search.response)) return false;
			if (this.searchResultPrimaryLine()) return true;
			if (this.search && typeof this.search.query === 'string' && this.search.query.trim() && !this.search.showQueryForm) {
				return true;
			}
			return false;
		},

		/** Single stats line under “Search result”: counts · ipm · time (no duplicate QL/engine; those stay in the toolbar). */
		searchResultPrimaryLine() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			if (!result || typeof result !== 'object') return '';
			const response = this.search && this.search.response ? this.search.response : null;
			const selectedEngine =
				(result.engine && String(result.engine).trim())
				|| (this.settings && this.settings.queryEngine && String(this.settings.queryEngine).trim())
				|| (this.settings && this.settings.corpusFormat && String(this.settings.corpusFormat).trim())
				|| '';
			const totalNum = typeof result.total === 'number' ? result.total : Number.NaN;
			const totalLooksCapped = Number.isFinite(totalNum)
				? this.searchTotalLooksCapped(result, totalNum, selectedEngine)
				: false;
			const hitsCount = this.search && Array.isArray(this.search.hits) ? this.search.hits.length : 0;
			const rowsCount = this.getSearchTableRows().length;
			const isTable = this.searchHasTableResults();
			const returnedNum = result && (typeof result.returned === 'number' || (typeof result.returned === 'string' && result.returned !== ''))
				? Number(result.returned)
				: NaN;
			const returned = Number.isFinite(returnedNum) ? returnedNum : (isTable ? rowsCount : hitsCount);
			const totalFromResult = (result && (typeof result.total === 'number' || (typeof result.total === 'string' && result.total !== '')))
				? Number(result.total)
				: NaN;
			const totalFromResponse = (response && (typeof response.total === 'number' || (typeof response.total === 'string' && response.total !== '')))
				? Number(response.total)
				: NaN;
			const total = Number.isFinite(totalFromResult) ? totalFromResult : (Number.isFinite(totalFromResponse) ? totalFromResponse : null);
			const totalDisplay = total !== null
				? this.formatSearchTotalDisplay(result || response || {}, total, selectedEngine)
				: '';
			const countLabel = isTable
				? `${returned} of ${totalDisplay} ${returned === 1 ? 'row' : 'rows'}`
				: `${returned} of ${totalDisplay} ${returned === 1 ? 'hit' : 'hits'}`;
			const parts = [];
			if (total !== null && String(totalDisplay).length) {
				parts.push(countLabel);
			} else {
				parts.push(isTable ? `${returned} ${returned === 1 ? 'row' : 'rows'}` : `${returned} ${returned === 1 ? 'hit' : 'hits'}`);
			}
			let ipm = typeof result.ipm === 'number' && Number.isFinite(result.ipm) ? result.ipm : null;
			let corpusTokens =
				typeof result.corpus_tokens === 'number' && Number.isFinite(result.corpus_tokens)
					? result.corpus_tokens
					: null;
			if (corpusTokens === null && result.corpus_tokens != null && result.corpus_tokens !== '') {
				const n = Number(result.corpus_tokens);
				if (Number.isFinite(n) && n > 0) corpusTokens = n;
			}
			if (corpusTokens === null || corpusTokens <= 0) {
				const infoResult = this.info && this.info.result && typeof this.info.result === 'object' ? this.info.result : null;
				if (infoResult) {
					const n = Number(
						infoResult.tokens_count ?? infoResult.corpus_size ?? infoResult.size ?? infoResult.tokens ?? 0,
					);
					if (Number.isFinite(n) && n > 0) corpusTokens = n;
				}
			}
			if (!totalLooksCapped && ipm === null && typeof result.total === 'number' && result.total >= 0 && corpusTokens !== null && corpusTokens > 0) {
				ipm = (result.total / corpusTokens) * 1000000;
			}
			if (typeof ipm === 'number' && Number.isFinite(ipm) && this.searchIpmIsTrusted(result, totalLooksCapped)) {
				const ipmText = this.formatIpmDisplay(ipm, {
					count: total !== null && Number.isFinite(Number(total)) ? Number(total) : null,
				});
				if (ipmText) parts.push(`${ipmText} ipm`);
			}
			if (typeof result.time_ms === 'number') {
				parts.push(`${result.time_ms.toFixed(0)} ms`);
			}
			return parts.join(' · ');
		},

		/** @deprecated — prefer searchResultPrimaryLine(); kept for any template that still x-for’s meta. */
		searchMetaParts() {
			return [];
		},

		searchMeta() {
			return this.searchMetaParts().map(part => part && part.text ? String(part.text) : '');
		},

		searchTotalLooksCapped(result, total, engineHint) {
			if (!result || typeof result !== 'object') return false;
			if (!Number.isFinite(total) || total < 0) return false;
			if (result.total_capped === true || result.total_is_cap === true || result.max_total_reached === true || result.capped === true) {
				return true;
			}
			const maxTotalNum = Number(
				result.max_total ?? result.maxTotal ?? result.total_limit ?? result.limit_total ?? result.cap_total ?? result.cap ?? NaN,
			);
			if (Number.isFinite(maxTotalNum) && maxTotalNum > 0 && total >= maxTotalNum) {
				return true;
			}
			const engine = String(
				engineHint
				|| result.engine
				|| (this.settings && this.settings.queryEngine)
				|| (this.settings && this.settings.corpusFormat)
				|| '',
			).toLowerCase();
			// flexicorp-pando defaults to max_total=10000; treat that boundary as a lower bound display.
			if (engine.includes('pando') && total === 10000) return true;
			return false;
		},

		searchIpmIsTrusted(result, totalLooksCapped) {
			if (!totalLooksCapped) return true;
			if (!result || typeof result !== 'object') return false;
			if (result.ipm_exact === true || result.ipm_trusted === true || result.total_exact === true) return true;
			return false;
		},

		formatSearchTotalDisplay(result, total, engineHint) {
			if (!Number.isFinite(total)) return String(total);
			return this.searchTotalLooksCapped(result, total, engineHint) ? `${total}+` : String(total);
		},

		/** @deprecated — query + stats are combined under “Search result”; keep for any legacy template. */
		searchSummaryText() {
			return '';
		},

		/** @deprecated */
		searchSummarySuffix() {
			return '';
		},

		searchXmlPresenceText() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			if (!hits.length) return '';
			const parts = [];
			const maxHits = Math.min(120, hits.length);
			for (let i = 0; i < maxHits; i += 1) {
				const h = hits[i];
				const ctx = this.getHitContext(h);
				if (typeof ctx === 'string' && ctx.includes('<')) parts.push(ctx);
			}
			return parts.join('\n');
		},

		searchTextFormOptions() {
			const fd = (typeof window !== 'undefined' && window.formdef && typeof window.formdef === 'object') ? window.formdef : null;
			if (!fd || !this.isXmlContext()) return [];
			const xml = this.searchXmlPresenceText();
			if (!xml) return [];
			const canSeeAdmin = !!(typeof window !== 'undefined' && window.username);
			const opts = [];
			Object.keys(fd).forEach((key) => {
				const item = fd[key];
				if (!item || typeof item !== 'object') return;
				if (item.admin && !canSeeAdmin) return;
				const inheritKey = typeof item.inherit === 'string' ? item.inherit : '';
				const hasOwn = new RegExp(`\\s${String(key).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=`).test(xml);
				const hasInherited = inheritKey
					? new RegExp(`\\s${String(inheritKey).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=`).test(xml)
					: false;
				const relevant = key === 'pform' || !!item.transliterate || hasOwn || (!!item.subtract && hasInherited);
				if (!relevant) return;
				opts.push({
					key,
					label: item.display || key,
					color: item.color || '',
					admin: !!item.admin,
				});
			});
			return opts;
		},

		searchTagOptions() {
			const td = (typeof window !== 'undefined' && window.tagdef && typeof window.tagdef === 'object') ? window.tagdef : null;
			if (!td || !this.isXmlContext()) return [];
			const xml = this.searchXmlPresenceText();
			if (!xml) return [];
			const canSeeAdmin = !!(typeof window !== 'undefined' && window.username);
			const opts = [];
			Object.keys(td).forEach((key) => {
				const item = td[key];
				if (!item || typeof item !== 'object') return;
				if (item.admin && !canSeeAdmin) return;
				const hasKey = new RegExp(`\\s${String(key).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=`).test(xml);
				if (!hasKey) return;
				opts.push({
					key,
					label: item.display || key,
					color: item.color || '',
					admin: !!item.admin,
				});
			});
			return opts;
		},

		searchTextOptionsVisible() {
			return this.searchTextFormOptions().length > 1;
		},

		/** Tags UI disabled in template (TEITOK tagdef + heavy page CSS breaks KWIC); keep false until revisited. */
		searchTagOptionsVisible() {
			return false;
		},

		applySearchTextForm(key) {
			if (!key) return;
			if (typeof window !== 'undefined' && typeof window.setbut === 'function') {
				window.setbut(`but-${key}`);
			}
			if (typeof window !== 'undefined' && typeof window.setForm === 'function') {
				window.setForm(String(key));
			}
		},

		toggleSearchTag(key) {
			if (!key) return;
			if (typeof window !== 'undefined' && typeof window.toggletag === 'function') {
				window.toggletag(String(key));
			}
		},

		isSearchTextFormActive(key) {
			if (!key || typeof window === 'undefined') return false;
			const current = typeof window.showform === 'string' ? window.showform : '';
			return current === String(key);
		},

		isSearchTagActive(key) {
			if (!key || typeof window === 'undefined') return false;
			const labels = Array.isArray(window.labels) ? window.labels : [];
			return labels.includes(String(key));
		},

		searchHasTableResults() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const resultType = (result && typeof result.result_type === 'string' ? result.result_type : (this.search && this.search.resultType) || '').toLowerCase();
			// Frequency/statistical result tables belong to Stats, not Search.
			if (result && typeof result === 'object') {
				const hasCompareQueries = Array.isArray(result.compare_queries) && result.compare_queries.length > 0;
				const hasTotalsPerQuery = !!(result.totals_per_query && typeof result.totals_per_query === 'object');
				const hasRelativeStats = !!result.per_subcorpus_ipm;
				const rowLooksLikeFreq =
					Array.isArray(result.rows) &&
					result.rows.some((r) => r && typeof r === 'object' && r.queries && typeof r.queries === 'object');
				if (hasCompareQueries || hasTotalsPerQuery || hasRelativeStats || rowLooksLikeFreq) return false;
				// dcoll/coll payloads use collocates[], not concordance hits.
				if (Array.isArray(result.collocates) && result.collocates.length > 0) return true;
				const op = String(result.operation || '').trim().toLowerCase();
				if ((op === 'dcoll' || op === 'coll') && (Array.isArray(result.collocates) || Number(result.matches) > 0)) return true;
			}
			if (resultType === 'table') return true;
			if (result && typeof result === 'object') {
				const table = result.table && typeof result.table === 'object' ? result.table : null;
				if (table && (Array.isArray(table.rows) || Array.isArray(table.columns))) return true;
				if (Array.isArray(result.rows) || Array.isArray(result.columns) || Array.isArray(result.items)) return true;
			}
			return false;
		},

		getSearchTableColumns() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const table = result && result.table && typeof result.table === 'object' ? result.table : null;
			const direct = table && Array.isArray(table.columns)
				? table.columns
				: (result && Array.isArray(result.columns) ? result.columns : (this.search && Array.isArray(this.search.tableColumns) ? this.search.tableColumns : []));
			if (direct.length) return direct;
			const rows = this.getSearchTableRows();
			return rows.length && rows[0] && typeof rows[0] === 'object' ? Object.keys(rows[0]) : [];
		},

		getSearchTableRows() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const table = result && result.table && typeof result.table === 'object' ? result.table : null;
			if (table && Array.isArray(table.rows)) return table.rows;
			if (result && Array.isArray(result.collocates)) return result.collocates;
			if (result && Array.isArray(result.rows)) return result.rows;
			if (result && Array.isArray(result.items)) return result.items;
			return this.search && Array.isArray(this.search.tableRows) ? this.search.tableRows : [];
		},

		/**
		 * True when the last search completed without call errors and returned at least one
		 * hit/row (query-scoped frequency, collocations, dcoll, etc. need a non-empty match set).
		 */
		statsSearchHasHits() {
			if (!this.search || !this.search.ran) return false;
			if (this.callHasIssues(this.search.response)) return false;
			const result = this.search.response && this.search.response.result && typeof this.search.response.result === 'object'
				? this.search.response.result
				: null;
			// dcoll/coll: match set size is `matches`; table rows are `collocates`.
			if (result && typeof result === 'object') {
				const matches = Number(result.matches);
				if (Number.isFinite(matches) && matches > 0) return true;
				if (Array.isArray(result.collocates) && result.collocates.length > 0) return true;
			}
			if (this.searchHasTableResults()) {
				if (this.getSearchTableRows().length > 0) return true;
				if (result && Array.isArray(result.collocates) && result.collocates.length > 0) return true;
			}
			const response = this.search.response;
			const totalFromResult =
				result && (typeof result.total === 'number' || (typeof result.total === 'string' && result.total !== ''))
					? Number(result.total)
					: NaN;
			const totalFromResponse =
				response && (typeof response.total === 'number' || (typeof response.total === 'string' && response.total !== ''))
					? Number(response.total)
					: NaN;
			const total = Number.isFinite(totalFromResult)
				? totalFromResult
				: Number.isFinite(totalFromResponse)
					? totalFromResponse
					: null;
			if (total !== null && total === 0) return false;
			if (total !== null && total > 0) return true;
			const hits = Array.isArray(this.search.hits) ? this.search.hits : [];
			if (hits.length > 0) return true;
			const returnedNum =
				result && (typeof result.returned === 'number' || (typeof result.returned === 'string' && result.returned !== ''))
					? Number(result.returned)
					: NaN;
			if (Number.isFinite(returnedNum)) return returnedNum > 0;
			return false;
		},

		formatSearchTableCell(value) {
			if (value == null) return '';
			if (typeof value === 'string') return value;
			if (typeof value === 'number' || typeof value === 'boolean') return String(value);
			try {
				return JSON.stringify(value);
			} catch (_) {
				return String(value);
			}
		},

		isSearchViewMode(mode) {
			return !!(this.search && this.search.viewMode === mode);
		},

		/** Next node in document order (depth-first). */
		_nextNodeInDocumentOrder(node) {
			if (!node) return null;
			if (node.firstChild) return node.firstChild;
			let n = node;
			while (n) {
				if (n.nextSibling) return n.nextSibling;
				n = n.parentNode;
			}
			return null;
		},

		/**
		 * Normalize spacing in XML fragment for display so it matches TEITOK spacing rules:
		 * - When join="right" or join="left": remove space between those token elements.
		 * - When xml:space="remove" is on an ancestor: remove all whitespace-only nodes between token elements (nospace=1).
		 * Only affects XML fragment display; raw/engine output is unchanged.
		 */
		normalizeXmlFragmentSpacing(container) {
			if (!container || !container.querySelectorAll) return;
			const tokens = this.xmlContextTokenElements(container);
			const hasXmlSpaceRemove = container.querySelector && container.querySelector('[xml\\:space="remove"], [space="remove"]');
			for (let i = 0; i < tokens.length - 1; i++) {
				const prev = tokens[i];
				const next = tokens[i + 1];
				const prevJoin = (prev.getAttribute && prev.getAttribute('join')) || '';
				const nextJoin = (next.getAttribute && next.getAttribute('join')) || '';
				const removeSpace = hasXmlSpaceRemove || prevJoin === 'right' || nextJoin === 'left';
				if (!removeSpace) continue;
				let node = this._nextNodeInDocumentOrder(prev);
				while (node && node !== next) {
					const after = this._nextNodeInDocumentOrder(node);
					if (node.nodeType === Node.TEXT_NODE && /^\s*$/.test(node.textContent))
						node.remove();
					node = after;
				}
			}
		},

		getHitDomTokenPrefix(hit) {
			if (!hit || typeof hit !== 'object') return 'r0_';
			if (typeof hit.__flexicorpDomTokenPrefix === 'string' && hit.__flexicorpDomTokenPrefix)
				return hit.__flexicorpDomTokenPrefix;
			this.__flexicorpDomTokenPrefixSeq = Number(this.__flexicorpDomTokenPrefixSeq || 0) + 1;
			const prefix = `r${this.__flexicorpDomTokenPrefixSeq}_`;
			hit.__flexicorpDomTokenPrefix = prefix;
			return prefix;
		},

		rewriteTokenRefList(value, idMap) {
			if (typeof value !== 'string' || !value.trim() || !(idMap instanceof Map) || idMap.size === 0) return value;
			const tokens = value.split(/\s+/);
			let changed = false;
			const rewritten = tokens.map((part) => {
				if (!part) return part;
				const hashed = part.startsWith('#');
				const key = hashed ? part.slice(1) : part;
				const mapped = idMap.get(key);
				if (!mapped) return part;
				changed = true;
				return hashed ? `#${mapped}` : mapped;
			});
			return changed ? rewritten.join(' ') : value;
		},

		ensureUniqueTokenIdsInContainer(container, hit) {
			if (!container || !container.querySelectorAll) return;
			const tokenNodes = this.xmlContextTokenElements(container);
			if (!tokenNodes.length) return;
			const prefix = this.getHitDomTokenPrefix(hit);
			const idMap = new Map();
			tokenNodes.forEach((node, idx) => {
				const originalId = this.xmlElementId(node);
				const baseId = originalId || `anon${idx + 1}`;
				const uniqueId = `${prefix}${baseId}`;
				// Token-level hooks keep tokview tooltip positioning anchored to the actual token
				// instead of relying on document-level mouseover heuristics in table/KWIC snippets.
				node.setAttribute(
					'onmouseover',
					"var __ev = (typeof event !== 'undefined') ? event : (typeof window !== 'undefined' ? window.event : null); if (__ev && __ev.stopPropagation) __ev.stopPropagation(); if (typeof showtokinfo === 'function') showtokinfo(__ev, this, this); return false;"
				);
				node.setAttribute(
					'onmouseout',
					"var __ev = (typeof event !== 'undefined') ? event : (typeof window !== 'undefined' ? window.event : null); if (__ev && __ev.stopPropagation) __ev.stopPropagation(); if (typeof hidetokinfo === 'function') hidetokinfo(); return false;"
				);
				if (originalId) {
					idMap.set(originalId, uniqueId);
					node.setAttribute('data-flexicorp-orig-id', originalId);
				}
				node.setAttribute('id', uniqueId);
				// Afford click-to-tokedit when logged in (handler in initTeitokTokEditClicks).
				if (this.teitokUsername()) {
					node.setAttribute('title', 'Edit token');
					node.classList.add('flexicorp-tok-editable');
				}
			});
			if (!idMap.size) return;
			const refAttrs = ['head', 'ohead', 'sameAs', 'corresp', 'ana', 'target'];
			Array.from(container.querySelectorAll('*')).forEach((node) => {
				refAttrs.forEach((attr) => {
					if (!node.hasAttribute(attr)) return;
					const current = node.getAttribute(attr);
					const rewritten = this.rewriteTokenRefList(current, idMap);
					if (rewritten !== current) node.setAttribute(attr, rewritten);
				});
			});
		},

		/**
		 * XML string actually shown for token-window KWIC (left + match + right): Range serialization
		 * pulls in enclosing &lt;s tuid&gt; that the raw server fragment may omit.
		 */
		_assembledKwicXmlContextForHit(hit) {
			if (!this.isXmlContext() || !this.wantsStructuredContext() || !this.kwicScopeUsesTokenWindow()) {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', {
					reason: 'gates',
					isXmlContext: this.isXmlContext(),
					wantsStructuredContext: this.wantsStructuredContext(),
					kwicScopeUsesTokenWindow: this.kwicScopeUsesTokenWindow(),
				});
				return '';
			}
			if (!hit || typeof hit !== 'object') {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', { reason: '!hit' });
				return '';
			}
			const mids = this.getHitMatchIds(hit);
			if (!Array.isArray(mids) || !mids.length) {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', { reason: '!matchIds', mids });
				return '';
			}
			const parts = this.getXmlKwicPartsForIds(hit, mids, { wrap: false });
			if (!parts || !parts.isXml) {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', {
					reason: '!partsOrNotXml',
					hasParts: !!parts,
					isXml: parts && parts.isXml,
					partsKeys: parts && typeof parts === 'object' ? Object.keys(parts) : [],
				});
				return '';
			}
			const stripKwicShell = (h) => {
				if (typeof h !== 'string' || !h.trim()) return '';
				return h
					.replace(/<\s*div[^>]*class\s*=\s*["'][^"']*flexicorp-hit-xml[^"']*["'][^>]*>/gi, '')
					.replace(/<\s*\/\s*div\s*>/gi, '')
					.trim();
			};
			const left = stripKwicShell(parts.left || '');
			const match = stripKwicShell(parts.match || '');
			const right = stripKwicShell(parts.right || '');
			const joined = [left, match, right].filter((s) => s).join('');
			this._logAlignmentTuidDebug(hit, 'assembledKwic:built', {
				leftLen: left.length,
				matchLen: match.length,
				rightLen: right.length,
				joinedLen: joined.length,
				joinedHead: this._truncXmlDbg(joined, 200),
			});
			return joined;
		},

		buildContextContainer(hit, contextOverride) {
			let context =
				contextOverride !== undefined && contextOverride !== null ? String(contextOverride) : this.getHitContext(hit);
			// When context is ridx-style (path\tnum\tnum\tid), show as plain text; do not parse as XML
			if (this.isXmlContext() && context && this.isRidxStyleContext(context)) {
				const container = document.createElement('div');
				container.innerHTML = '<pre class="flexicorp-hit-raw flexicorp-hit-ridx-fallback">' + this.escapeHtmlForPre(context) + '</pre>';
				return container;
			}
			// When displaying as XML, decode HTML entities so innerHTML parses real tags (fixes empty/no output)
			if (this.isXmlContext() && context && /&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(context)) {
				context = this.decodeHtmlEntities(context);
			}
			if (this.isXmlContext() && context) {
				context = this.normalizeXmlContextFragmentBoundary(context);
			}
			const container = document.createElement('div');
			if (this.isXmlContext() && context) {
				try {
					const parser = new DOMParser();
					const xmlDoc = parser.parseFromString(`<flexicorp-root>${context}</flexicorp-root>`, 'application/xml');
					if (!xmlDoc.querySelector('parsererror') && xmlDoc.documentElement) {
						Array.from(xmlDoc.documentElement.childNodes).forEach((node) => {
							container.appendChild(document.importNode(node, true));
						});
						this.ensureUniqueTokenIdsInContainer(container, hit);
						this.normalizeXmlFragmentSpacing(container);
						return container;
					}
				} catch (_) {
					// Fall back to HTML parsing below.
				}
			}
			container.innerHTML = context || '';
			if (this.isXmlContext() && context) this.ensureUniqueTokenIdsInContainer(container, hit);
			return container;
		},

		/**
		 * Token-like elements in a parsed XML fragment. Namespaced TEI (e.g. {http://www.tei-c.org/ns/1.0}w)
		 * is invisible to querySelector("w"), so walk * and match localName.
		 */
		xmlContextTokenElements(container) {
			if (!container || !container.querySelectorAll) return [];
			const tags = new Set(['tok', 'dtok', 'mtok', 'w']);
			return Array.from(container.querySelectorAll('*')).filter((n) => {
				if (!n || n.nodeType !== Node.ELEMENT_NODE) return false;
				return tags.has(String(n.localName || '').toLowerCase());
			});
		},

		/**
		 * Guard against stale-offset XML fragments where payload starts mid-tag/header text.
		 * If we detect plain leaked preamble before the first plausible TEI/token element,
		 * trim the fragment to that first element so rendering stays token-centric.
		 */
		normalizeXmlContextFragmentBoundary(fragment) {
			if (typeof fragment !== 'string') return '';
			const src = fragment;
			if (!src.trim()) return src;
			const firstCandidate = src.search(/<(?:s|seg|tok|dtok|mtok|w|p|div|u|l|lb)\b/i);
			if (firstCandidate <= 0) return src;
			const prefix = src.slice(0, firstCandidate);
			// Keep intact when prefix already looks like valid XML/comment/prolog.
			if (/<[!?/]/.test(prefix)) return src;
			// Prefix has no tag opener and likely leaked textual debris -> trim.
			return src.slice(firstCandidate);
		},

		/** Heuristic: fragment offset drift leaked metadata/header text into context payload. */
		isClearlyCorruptedXmlContext(context) {
			if (typeof context !== 'string') return false;
			const s = context.trim();
			if (!s) return false;
			// Typical leakage seen with stale offsets in edited XML.
			if (/Tagged via .*tasks=.*[">]/i.test(s)) return true;
			if (/atize,parse,tag\">/i.test(s)) return true;
			// Plain metadata text without any token/sentence tags is unusable as XML.
			const hasXmlTag = /<(?:s|seg|tok|dtok|mtok|w|p|div|u|l|lb)\b/i.test(s);
			if (!hasXmlTag && /Tagged via|tasks=|lemmatize|parse|tag/i.test(s)) return true;
			return false;
		},

		getCorruptedXmlFallbackText(hit) {
			const plain = this.getPlainContextPartsFromHit(hit);
			if (plain) {
				return [plain.left, plain.match, plain.right]
					.map((x) => (typeof x === 'string' ? x.trim() : ''))
					.filter((x) => x)
					.join(' ');
			}
			const matchIds = this.getHitMatchIds(hit);
			const fromTokens = this.getKwicPartsFromJsonTokens(hit, matchIds);
			if (fromTokens) {
				return [fromTokens.left, fromTokens.match, fromTokens.right]
					.map((x) => (typeof x === 'string' ? x.trim() : ''))
					.filter((x) => x)
					.join(' ');
			}
			return this.getHitContextSyntheticFromTokens(hit) || '';
		},

		getRenderedXmlContext(hit, sideHint) {
			if (!this.isXmlContext()) return this.getHitContext(hit);
			if (this.hitIsSentenceAligned(hit, sideHint)) {
				const sentenceIds = this.getSentenceAlignedTokenIds(hit);
				if (this.hitHasTeitokXmlContext(hit) && sentenceIds.length) {
					const xmlParts = this.getXmlKwicPartsForIds(hit, sentenceIds, { wrap: false });
					if (xmlParts && xmlParts.isXml && typeof xmlParts.match === 'string' && xmlParts.match.trim()) {
						return `<span class="flexicorp-hit-xml">${xmlParts.match}</span>`;
					}
				}
				const sentenceText = this.getSentenceAlignedMatchText(hit);
				return `<span class="flexicorp-hit-sentence-plain">${this.escapeHtmlForPre(sentenceText)}</span>`;
			}
			const context = this.getHitContext(hit);
			if (!context) {
				if (hit && typeof hit === 'object') delete hit.__flexicorpRenderedXml;
				return '';
			}
			if (this.isClearlyCorruptedXmlContext(context)) {
				const fallbackText = this.getCorruptedXmlFallbackText(hit);
				const fallbackOut = '<pre class="flexicorp-hit-raw flexicorp-hit-xml-fallback">' + this.escapeHtmlForPre(fallbackText || '[stale XML context; reindex recommended]') + '</pre>';
				if (hit && typeof hit === 'object') {
					hit.__flexicorpRenderedXml = fallbackOut;
					hit.__flexicorpRenderedXmlKey = 'xml-corrupt-fallback';
				}
				return fallbackOut;
			}
			const highlightSig = (() => {
				try {
					const side = this.getAlignedHitSide(hit, sideHint) || '-';
					const groups = this.getHighlightMapGroups(hit, sideHint)
						.map((g) => `${String(g && (g.name || g.id) || '')}:${String(g && g.paletteClass || '')}:${Array.isArray(g && g.ids) ? g.ids.length : 0}`)
						.join(',');
					return `${side}|${groups}`;
				} catch (_) {
					return '-';
				}
			})();
			const renderKey = `${this.currentContextScopeKey()}|${this.activeKwicWindowSize()}|xml|${this.normalizeSearchViewMode(this.search && this.search.viewMode)}|${this.kwicHitUsesHighlighting(hit) ? 1 : 0}|${highlightSig}`;
			// Only use cache when it has content (avoid reusing empty from an earlier failed render)
			if (
				hit
				&& typeof hit === 'object'
				&& hit.__flexicorpRenderedXml
				&& hit.__flexicorpRenderedXml.length > 0
				&& hit.__flexicorpRenderedXmlKey === renderKey
			) return hit.__flexicorpRenderedXml;
			if (this.kwicScopeUsesTokenWindow()) {
				const xmlParts = this.getXmlKwicPartsForIds(hit, this.getHitMatchIds(hit), { wrap: false });
				if (xmlParts && xmlParts.isXml) {
					const out = `<span class="flexicorp-hit-xml flexicorp-kwic-xml">${xmlParts.left || ''}${xmlParts.match || ''}${xmlParts.right || ''}</span>`;
					if (hit && typeof hit === 'object' && out) {
						hit.__flexicorpRenderedXml = out;
						hit.__flexicorpRenderedXmlKey = renderKey;
					}
					return out;
				}
			}
			if (context && this.isRidxStyleContext(context)) {
				const out = '<pre class="flexicorp-hit-raw flexicorp-hit-ridx-fallback">' + this.escapeHtmlForPre(context) + '</pre>';
				if (hit && typeof hit === 'object') {
					hit.__flexicorpRenderedXml = out;
					hit.__flexicorpRenderedXmlKey = renderKey;
				}
				return out;
			}
			const container = this.buildContextContainer(hit);
			// If parsing produced no tok/seg (e.g. HTML parser ate the XML), show raw in <pre> instead
			const hasStructure =
				this.xmlContextTokenElements(container).length > 0 ||
				Array.from(container.querySelectorAll('*')).some((n) => {
					const ln = String(n.localName || '').toLowerCase();
					return ln === 'seg' || ln === 's' || ln === 'u' || ln === 'p' || ln === 'div' || ln === 'l' || ln === 'lb';
				});
			if (!hasStructure && context) {
				const fallback = '<pre class="flexicorp-hit-raw flexicorp-hit-xml-fallback">' + this.escapeHtmlForPre(context) + '</pre>';
				if (hit && typeof hit === 'object') {
					hit.__flexicorpRenderedXml = fallback;
					hit.__flexicorpRenderedXmlKey = renderKey;
				}
				return fallback;
			}
			this.applyHighlightTokenClassesToContainer(container, hit, sideHint);
			// Drop layout/facsimile attrs on token elements only (not <l>/<lb> line geometry). Stripping
			// from <tok> avoids huge inline facs previews beside running text; keeping <l bbox> matches
			// source XML and lets facsimile / line-crop logic see the verse-line box.
			const display = container.cloneNode(true);
			display.querySelectorAll('tok, dtok, mtok').forEach((el) => {
				el.removeAttribute('bbox');
				el.removeAttribute('facs');
				el.removeAttribute('baseline');
			});
			// Keep TEI structure intact, but neutralize facsimile/image triggers.
			display.querySelectorAll('pb, cb, lb').forEach((el) => {
				el.removeAttribute('facs');
				el.removeAttribute('img');
			});
			display.querySelectorAll('img, .imgdiv, .hlbar, .adminpart').forEach((el) => {
				if (el && el.remove) el.remove();
			});
			const rendered = display.innerHTML;
			if (hit && typeof hit === 'object' && rendered) {
				hit.__flexicorpRenderedXml = rendered;
				hit.__flexicorpRenderedXmlKey = renderKey;
			}
			return rendered;
		},

		/**
		 * TEI often uses xml:id on tokens; CWB tabulate may use the same logical id with/without a w/t prefix.
		 */
		xmlElementId(el) {
			if (!el || !el.getAttribute) return '';
			let v = el.getAttribute('data-flexicorp-orig-id');
			if (v && v.trim()) return v.trim();
			v = el.getAttribute('id');
			if (v && v.trim()) return v.trim();
			if (el.getAttributeNS) {
				v = el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
				if (v && v.trim()) return v.trim();
			}
			return '';
		},

		xmlElementIds(el) {
			if (!el || !el.getAttribute) return [];
			const ids = [];
			const push = (v) => {
				const s = typeof v === 'string' ? v.trim() : '';
				if (s && !ids.includes(s)) ids.push(s);
			};
			push(el.getAttribute('data-flexicorp-orig-id'));
			push(el.getAttribute('id'));
			if (el.getAttributeNS) {
				push(el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id'));
			}
			// Aligned flexicorp-pando highlights may use token tuid while XML carries both id+tuid.
			push(el.getAttribute('tuid'));
			return ids;
		},

		xmlElementStableIds(el) {
			if (!el || !el.getAttribute) return [];
			const ids = [];
			const push = (v) => {
				const s = typeof v === 'string' ? v.trim() : '';
				if (s && !ids.includes(s)) ids.push(s);
			};
			push(el.getAttribute('data-flexicorp-orig-id'));
			push(el.getAttribute('id'));
			if (el.getAttributeNS) {
				push(el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id'));
			}
			return ids;
		},

		xmlElementTuid(el) {
			if (!el || !el.getAttribute) return '';
			const s = String(el.getAttribute('tuid') || '').trim();
			return s || '';
		},

		/** Map highlight id -> classes, plus common alias keys so CQP ids match TEI xml:id / prefixed ids. */
		_expandHighlightIdLookup(idToClasses) {
			const out = Object.assign({}, idToClasses);
			for (const key of Object.keys(idToClasses)) {
				if (!key) continue;
				const s = String(key).trim();
				const cls = idToClasses[key];
				const noPrefix = s.replace(/^[wt](?=\d)/i, '');
				if (noPrefix && noPrefix !== s && !out[noPrefix]) out[noPrefix] = cls;
				if (/^\d+$/.test(s)) {
					if (!out[`w${s}`]) out[`w${s}`] = cls;
					if (!out[`t${s}`]) out[`t${s}`] = cls;
				}
			}
			return out;
		},

		_expandMatchIdSet(matchSet) {
			const out = new Set(matchSet);
			for (const id of [...out]) {
				const s = String(id).trim();
				if (!s) continue;
				const noPrefix = s.replace(/^[wt](?=\d)/i, '');
				if (noPrefix && noPrefix !== s) out.add(noPrefix);
				if (/^\d+$/.test(s)) {
					out.add(`w${s}`);
					out.add(`t${s}`);
				}
				// Some corpora expose decomposed sub-token ids (e.g. d-255-1) while
				// context fragments only carry the parent token id (e.g. w-255).
				const dtokParent = s.match(/^d-([^-]+)-\d+$/i);
				if (dtokParent && dtokParent[1]) {
					const base = dtokParent[1];
					out.add(base);
					out.add(`w-${base}`);
					out.add(`t-${base}`);
					out.add(`w${base}`);
					out.add(`t${base}`);
				}
			}
			return out;
		},

		/** Same class rules as getRenderedXmlContext — used for full context and XML KWIC columns. */
		applyHighlightTokenClassesToContainer(container, hit, sideHint) {
			if (!container || !container.querySelectorAll) return;
			if (this.hitIsSentenceAligned(hit, sideHint)) {
				// For sentence-aligned target rows (<s>), the full sentence is already the result.
				// Keep it unhighlighted to avoid misleading token/sentence emphasis.
				return;
			}
			const groups = this.getHighlightMapGroups(hit, sideHint);
			const idToClasses = {};
			const resultGroupValues = new Set();
			groups.forEach(({ resultGroup }) => {
				const n = Number(resultGroup);
				if (Number.isFinite(n) && n >= 0) resultGroupValues.add(n);
			});
			const hasMultipleResultGroups = resultGroupValues.size > 1;
			groups.forEach(({ name, ids, paletteClass, resultGroup }) => {
				const cls = name === 'match'
					? 'flexicorp-hit-token--match'
					: (paletteClass ? 'flexicorp-hit-token--group-' + paletteClass : 'flexicorp-hit-token--group-' + String(name).replace(/[^a-z0-9_-]/gi, ''));
				const n = Number(resultGroup);
				const hasResultGroup = Number.isFinite(n) && n >= 0;
				const resultGroupClass = hasResultGroup ? 'flexicorp-hit-token--result-group-' + String(n) : '';
				const resultGroupSlotClass = hasResultGroup ? 'flexicorp-hit-token--result-group-slot-' + String(Math.abs(n) % 6) : '';
				const resultGroupedClass = hasResultGroup && hasMultipleResultGroups ? 'flexicorp-hit-token--result-grouped' : '';
				(Array.isArray(ids) ? ids : []).forEach(id => {
					if (!id) return;
					if (!idToClasses[id]) idToClasses[id] = [];
					idToClasses[id].push(cls);
					if (resultGroupClass) idToClasses[id].push(resultGroupClass);
					if (resultGroupSlotClass) idToClasses[id].push(resultGroupSlotClass);
					if (resultGroupedClass) idToClasses[id].push(resultGroupedClass);
				});
			});
			const lookup = this._expandHighlightIdLookup(idToClasses);
			Array.from(container.querySelectorAll('tok, dtok, mtok')).forEach(node => {
				const aliases = this.xmlElementStableIds(node);
				const classSet = new Set();
				aliases.forEach((a) => {
					const classes = lookup[a];
					if (classes) classes.forEach((c) => classSet.add(c));
				});
				// Only fall back to tuid when no stable XML id matched. This avoids source/target
				// palette collisions when both sides happen to share numeric tuids.
				if (!classSet.size) {
					const tuid = this.xmlElementTuid(node);
					if (tuid) {
						const classes = lookup[tuid];
						if (classes) classes.forEach((c) => classSet.add(c));
					}
				}
				classSet.forEach((c) => node.classList.add(c));
			});
		},

		serializeXmlRange(range) {
			const div = document.createElement('div');
			div.appendChild(range.cloneContents());
			return div.innerHTML;
		},

		/**
		 * Keep KWIC snippets token-centric while preserving TEI tags. We only neutralize
		 * facsimile/image triggers and inline styles that can distort KWIC rendering.
		 */
		sanitizeKwicXmlHtml(html) {
			if (typeof html !== 'string' || !html.trim()) return '';
			const host = document.createElement('div');
			host.innerHTML = html;
			host.querySelectorAll('pb, cb, lb').forEach((el) => {
				el.removeAttribute('facs');
				el.removeAttribute('img');
			});
			host.querySelectorAll('img, .imgdiv, .hlbar, .adminpart').forEach((el) => {
				if (el && el.remove) el.remove();
			});
			host.querySelectorAll('[style]').forEach((el) => {
				el.removeAttribute('style');
			});
			return host.innerHTML;
		},

		/**
		 * Split TEITOK XML context into KWIC columns using DOM Range boundaries (document order),
		 * after applying query-group highlight classes. More reliable than string heuristics.
		 */
		getXmlKwicPartsForIds(hit, matchIds, options = {}) {
			if (!hit || !this.isXmlContext() || !this.wantsStructuredContext()) return null;
			if (!Array.isArray(matchIds) || !matchIds.length) return null;
			const wrapSegments = !(options && options.wrap === false);
			const matchSet = new Set(matchIds.filter(Boolean));
			if (!matchSet.size) return null;
			const container = this.buildContextContainer(hit);
			if (!container || !container.querySelector) return null;
			if (container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) return null;
			const sel = 'tok, dtok, mtok';
			const els = [...container.querySelectorAll(sel)];
			const expandedSet = this._expandMatchIdSet(matchSet);
			const matchEls = els.filter((el) => {
				const stableIds = this.xmlElementStableIds(el);
				for (let i = 0; i < stableIds.length; i += 1) {
					if (expandedSet.has(stableIds[i])) return true;
				}
				const tuid = this.xmlElementTuid(el);
				return !!(tuid && expandedSet.has(tuid));
			});
			if (!matchEls.length) return null;
			const first = matchEls[0];
			const last = matchEls[matchEls.length - 1];
			// Window boundaries should follow orthographic tokens. Nested dtok pieces must map
			// to their parent tok/mtok so we don't get context slices made from decomposition parts.
			const windowEls = [...container.querySelectorAll('tok, mtok, dtok')].filter((el) => {
				if (!el || !el.tagName) return false;
				const tag = String(el.tagName).toLowerCase();
				if (tag !== 'dtok') return true;
				return !el.closest('tok, mtok');
			});
			const toWindowAnchor = (el) => {
				if (!el || !el.tagName) return el;
				const tag = String(el.tagName).toLowerCase();
				if (tag === 'dtok') {
					const parentTok = el.closest('tok, mtok');
					if (parentTok) return parentTok;
				}
				return el;
			};
			const firstAnchor = toWindowAnchor(first);
			const lastAnchor = toWindowAnchor(last);
			const firstIdx = windowEls.indexOf(firstAnchor);
			const lastIdx = windowEls.indexOf(lastAnchor);
			let leftStartNode = null;
			let rightEndNode = null;
			if (this.kwicScopeUsesTokenWindow() && firstIdx >= 0 && lastIdx >= 0) {
				const win = this.activeKwicWindowSize();
				leftStartNode = windowEls[Math.max(0, firstIdx - win)] || firstAnchor;
				rightEndNode = windowEls[Math.min(windowEls.length - 1, lastIdx + win)] || lastAnchor;
			}
			if (this.kwicHitUsesHighlighting(hit)) {
				this.applyHighlightTokenClassesToContainer(container, hit);
			}

			const rLeft = document.createRange();
			if (leftStartNode) rLeft.setStartBefore(leftStartNode);
			else rLeft.selectNodeContents(container);
			rLeft.setEndBefore(first);
			const left = this.sanitizeKwicXmlHtml(this.serializeXmlRange(rLeft));

			const rMid = document.createRange();
			rMid.setStartBefore(first);
			rMid.setEndAfter(last);
			const match = this.sanitizeKwicXmlHtml(this.serializeXmlRange(rMid));

			const rRight = document.createRange();
			rRight.setStartAfter(last);
			if (rightEndNode) rRight.setEndAfter(rightEndNode);
			else rRight.setEnd(container, container.childNodes.length);
			const right = this.sanitizeKwicXmlHtml(this.serializeXmlRange(rRight));

			const wrap = (h) => {
				if (!h) return '';
				return wrapSegments ? `<div class="flexicorp-hit-xml flexicorp-kwic-xml">${h}</div>` : h;
			};
			return { left: wrap(left), match: wrap(match), right: wrap(right), isXml: true };
		},

		getContextTokens(hit) {
			if (hit && Array.isArray(hit.__flexicorpContextTokens)) return hit.__flexicorpContextTokens;
			const container = this.buildContextContainer(hit);
			const tokens = Array.from(container.querySelectorAll('tok, mtok, dtok'))
				.filter((node) => {
					if (!node || !node.tagName) return false;
					const tag = String(node.tagName).toLowerCase();
					if (tag !== 'dtok') return true;
					// Keep standalone dtok only; nested dtok are decomposition details of a tok.
					return !node.closest('tok, mtok');
				})
				.map(node => ({
					id: this.xmlElementId(node),
					text: (node.textContent || node.getAttribute('form') || '').trim(),
				}))
				.filter(token => token.text !== '');
			if (hit && typeof hit === 'object') hit.__flexicorpContextTokens = tokens;
			return tokens;
		},

		getTokenPositionsForIds(tokens, ids) {
			if (!Array.isArray(tokens) || !tokens.length || !Array.isArray(ids) || !ids.length) return [];
			const idSet = this._expandMatchIdSet(new Set(ids.filter(Boolean)));
			const positions = [];
			tokens.forEach((token, idx) => {
				if (!token || !token.id) return;
				const tokAliases = this._expandMatchIdSet(new Set([token.id]));
				for (const a of tokAliases) {
					if (idSet.has(a)) {
						positions.push(idx);
						break;
					}
				}
			});
			return positions;
		},

		getHitShapeInfo(hit) {
			if (hit && hit.__flexicorpHitShapeInfo) return hit.__flexicorpHitShapeInfo;
			const tokens = this.getContextTokens(hit);
			const matchIds = this.getHitMatchIds(hit);
			let positions = this.getTokenPositionsForIds(tokens, matchIds);
			let start = null;
			let end = null;
			if (positions.length) {
				start = Math.min(...positions);
				end = Math.max(...positions);
			} else {
				const rawStart = Number.isFinite(hit && hit.match_start) ? Number(hit.match_start) : null;
				const rawEnd = Number.isFinite(hit && hit.match_end) ? Number(hit.match_end) : null;
				if (rawStart !== null && rawEnd !== null && rawEnd >= rawStart) {
					start = rawStart;
					end = rawEnd;
				}
			}
			const matchedCount = matchIds.length || (Array.isArray(hit && hit.toks) ? hit.toks.filter(Boolean).length : 0);
			const spanLength = (start !== null && end !== null && end >= start) ? (end - start + 1) : matchedCount;
			const gapCount = Math.max(0, spanLength - matchedCount);
			const contiguous = matchedCount > 0 && gapCount === 0;
			const discontinuous = matchedCount > 1 && gapCount > 0;
			const wideDiscontinuous = discontinuous && gapCount >= Math.max(3, matchedCount);
			const info = {
				hasStructuredTokens: !!tokens.length,
				matchIds,
				positions,
				matchedCount,
				spanLength,
				gapCount,
				start,
				end,
				contiguous,
				discontinuous,
				wideDiscontinuous,
			};
			if (hit && typeof hit === 'object') hit.__flexicorpHitShapeInfo = info;
			return info;
		},

		isHitDiscontinuous(hit) {
			return !!this.getHitShapeInfo(hit).discontinuous;
		},

		searchHasDiscontinuousHits() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			return hits.some((hit) => this.isHitDiscontinuous(hit));
		},

		/** In KWIC, group highlights are only useful when hits are discontinuous. */
		kwicUsesHighlighting() {
			if (!this.isSearchViewMode('kwic') && !this.isSearchViewMode('anchor_kwic')) return true;
			return this.searchHasDiscontinuousHits();
		},

		/** Per-hit variant used while building XML KWIC fragments. */
		kwicHitUsesHighlighting(hit) {
			if (!this.isSearchViewMode('kwic') && !this.isSearchViewMode('anchor_kwic')) return true;
			return this.isHitDiscontinuous(hit);
		},

		searchShouldShowHighlightLegend() {
			if (!this.search || !Array.isArray(this.search.hits) || !this.search.hits.length) return false;
			if (!this.searchHighlightLegend().length) return false;
			if (this.isSearchViewMode('kwic') || this.isSearchViewMode('anchor_kwic')) {
				return this.kwicUsesHighlighting();
			}
			return true;
		},

		getAnchorGroup(hit) {
			const groups = this.getHighlightMapGroups(hit).filter((group) => group && Array.isArray(group.ids) && group.ids.length);
			if (!groups.length) return null;
			const specialNames = new Set(['target', 'head', 'focus', 'keyword', 'root']);
			const special = groups.find((group) => specialNames.has(String(group.name || '').toLowerCase()));
			if (special) return special;
			const named = groups.find((group) => {
				const name = String(group.name || '').trim();
				return name && name !== 'match' && !/^t\d+$/i.test(name);
			});
			if (named) return named;
			const firstNonMatch = groups.find((group) => String(group.name || '').trim().toLowerCase() !== 'match');
			if (firstNonMatch) return firstNonMatch;
			return groups[0] || null;
		},

		getAnchorTokenIds(hit) {
			const group = this.getAnchorGroup(hit);
			if (group && Array.isArray(group.ids) && group.ids.length) return group.ids.filter(Boolean);
			const matchIds = this.getHitMatchIds(hit);
			return matchIds.length ? [matchIds[0]] : [];
		},

		getAnchorLabel(hit) {
			const group = this.getAnchorGroup(hit);
			if (!group) return 'first match';
			return group.querySpan || group.name || 'anchor';
		},

		hitSupportsAnchorKwic(hit) {
			const tokens = this.getContextTokens(hit);
			if (!tokens.length) return false;
			return this.getAnchorTokenIds(hit).length > 0;
		},

		searchSupportsAnchorKwic() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			return hits.some((hit) => this.isHitDiscontinuous(hit) && this.hitSupportsAnchorKwic(hit));
		},

		getAnchorKwicParts(hit) {
			const cacheKey = [
				(this.search && this.search.contextFormat) || 'xml',
				this.currentContextScopeKey(),
				this.activeKwicWindowSize(),
				this.normalizeSearchViewMode(this.search && this.search.viewMode),
				this.kwicHitUsesHighlighting(hit) ? 1 : 0,
			].join('|');
			if (hit && hit.__flexicorpAnchorKwicParts && hit.__flexicorpAnchorKwicPartsFmt === cacheKey) {
				return hit.__flexicorpAnchorKwicParts;
			}
			if (this.isXmlContext() && this.wantsStructuredContext() && this.hitHasStructuredContext(hit)) {
				const anchorIds = this.getAnchorTokenIds(hit);
				if (anchorIds.length) {
					const xmlParts = this.getXmlKwicPartsForIds(hit, anchorIds);
					if (xmlParts) {
						if (hit && typeof hit === 'object') {
							hit.__flexicorpAnchorKwicParts = xmlParts;
							hit.__flexicorpAnchorKwicPartsFmt = cacheKey;
						}
						return xmlParts;
					}
				}
			}
			const tokens = this.getContextTokens(hit);
			const anchorIds = this.getAnchorTokenIds(hit);
			const positions = this.getTokenPositionsForIds(tokens, anchorIds);
			if (!tokens.length || !positions.length) {
				const fallback = this.getKwicParts(hit);
				if (hit && typeof hit === 'object') {
					hit.__flexicorpAnchorKwicParts = fallback;
					hit.__flexicorpAnchorKwicPartsFmt = cacheKey;
				}
				return fallback;
			}
			const start = Math.min(...positions);
			const end = Math.max(...positions);
			const parts = {
				left: tokens.slice(0, start).map((token) => token.text).join(' '),
				match: tokens.slice(start, end + 1).map((token) => token.text).join(' '),
				right: tokens.slice(end + 1).map((token) => token.text).join(' '),
				isXml: false,
			};
			if (hit && typeof hit === 'object') {
				hit.__flexicorpAnchorKwicParts = parts;
				hit.__flexicorpAnchorKwicPartsFmt = cacheKey;
			}
			return parts;
		},

		getActiveKwicParts(hit) {
			if (this.isSearchViewMode('anchor_kwic')) return this.getAnchorKwicParts(hit);
			return this.getKwicParts(hit);
		},

		/** True when KWIC columns render TEITOK XML (x-html) instead of plain text. */
		kwicCellIsXml(hit) {
			const p = this.getActiveKwicParts(hit);
			return !!(p && p.isXml);
		},

		kwicMatchColumnLabel() {
			return this.isSearchViewMode('anchor_kwic') ? 'Anchor' : 'Match';
		},

		/**
		 * Build left/match/right when DOM context tokens are missing but hit.tokens + match ids exist
		 * (flexicorp-pando without TEITOK XML fragment).
		 */
		getKwicPartsFromJsonTokens(hit, matchIds) {
			if (!hit || !Array.isArray(hit.tokens) || !hit.tokens.length || !Array.isArray(matchIds) || !matchIds.length) {
				return null;
			}
			const row = [];
			for (let i = 0; i < hit.tokens.length; i++) {
				const t = hit.tokens[i];
				const id = this.hitTokenIdString(t);
				if (!id) continue;
				row.push({ id, text: this.hitTokenSurfaceText(t) || '' });
			}
			if (!row.length) return null;
			const want = this._expandMatchIdSet(new Set(matchIds.map((m) => String(m))));
			const positions = [];
			for (let i = 0; i < row.length; i++) {
				const rid = String(row[i].id || '');
				if (!rid) continue;
				const aliases = this._expandMatchIdSet(new Set([rid]));
				let matched = false;
				for (const a of aliases) {
					if (want.has(String(a))) {
						matched = true;
						break;
					}
				}
				if (matched) positions.push(i);
			}
			if (!positions.length) {
				const rawStart = Number.isFinite(hit && hit.match_start) ? Number(hit.match_start) : null;
				const rawEnd = Number.isFinite(hit && hit.match_end) ? Number(hit.match_end) : null;
				if (rawStart !== null && rawEnd !== null && rawEnd >= rawStart) {
					const start = Math.max(0, rawStart);
					const end = Math.min(row.length - 1, rawEnd);
					for (let i = start; i <= end; i++) positions.push(i);
				}
			}
			if (!positions.length) return null;
			const start = Math.min(...positions);
			const end = Math.max(...positions);
			const joinSp = (slice) =>
				slice
					.map((x) => x.text)
					.filter((s) => s && s.trim())
					.join(' ');
			return {
				left: joinSp(row.slice(0, start)),
				match: joinSp(row.slice(start, end + 1)),
				right: joinSp(row.slice(end + 1)),
				isXml: false,
			};
		},

		isAbiendoloDebugHit(hit) {
			return false;
		},

		logAbiendoloKwic(stage, hit, payload = {}) {
			/* debug logging disabled */
		},

		logEmptySideKwic(stage, hit, parts, extra = {}) {
			/* debug logging disabled */
		},

		isInvalidKwicCachedParts(parts) {
			if (!parts || typeof parts !== 'object') return true;
			const match = typeof parts.match === 'string' ? parts.match : '';
			if (parts.isXml) return false;
			// Never keep ridx locator rows as rendered KWIC content.
			return this.isRidxStyleContext(match);
		},

		getPlainContextPartsFromHit(hit) {
			if (!hit || typeof hit !== 'object') return null;
			const ctx = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const pick = (obj, keys) => {
				if (!obj || typeof obj !== 'object') return '';
				for (const k of keys) {
					const v = obj[k];
					if (typeof v === 'string' && v.trim() !== '') return v;
				}
				return '';
			};
			const left = pick(ctx, ['left', 'left_context', 'pre', 'before']) || pick(hit, ['left', 'left_context', 'kwic_left', 'pre', 'before']);
			const match = pick(ctx, ['match', 'mid', 'center', 'token']) || pick(hit, ['match', 'mid', 'center', 'token', 'word', 'form']);
			const right = pick(ctx, ['right', 'right_context', 'post', 'after']) || pick(hit, ['right', 'right_context', 'kwic_right', 'post', 'after']);
			if (!left && !match && !right) return null;
			if (this.hitIsSentenceAligned(hit)) {
				const sentenceText = this.getSentenceAlignedMatchText(hit) || match || '';
				return { left: '', match: sentenceText, right: '', isXml: false };
			}
			return { left, match, right, isXml: false };
		},

		getSentenceAlignedKwicParts(hit) {
			if (!this.hitIsSentenceAligned(hit)) return null;
			const textDisplay = this.search && this.search.contextFormat === 'text';
			if (this.isXmlContext() && this.wantsStructuredContext() && this.hitHasTeitokXmlContext(hit) && !textDisplay) {
				const rendered = this.getRenderedXmlContext(hit);
				if (typeof rendered === 'string' && rendered.trim()) {
					return { left: '', match: rendered, right: '', isXml: true };
				}
			}
			const plain = this.getSentenceAlignedMatchText(hit);
			return { left: '', match: typeof plain === 'string' ? plain : '', right: '', isXml: false };
		},

		hitUsesDtokMatchIds(hit) {
			const ids = this.getHitMatchIds(hit);
			if (!ids.length) return false;
			return ids.some((id) => /^d-[^-]+-\d+$/i.test(String(id || '').trim()));
		},

		getKwicParts(hit) {
			const cacheKey = [
				(this.search && this.search.contextFormat) || 'xml',
				this.currentContextScopeKey(),
				this.activeKwicWindowSize(),
				this.normalizeSearchViewMode(this.search && this.search.viewMode),
				this.kwicHitUsesHighlighting(hit) ? 1 : 0,
				this.hitIsSentenceAligned(hit) ? 1 : 0,
			].join('|');
			if (hit && hit.__flexicorpKwicParts && hit.__flexicorpKwicPartsFmt === cacheKey) {
				if (this.isInvalidKwicCachedParts(hit.__flexicorpKwicParts)) {
					delete hit.__flexicorpKwicParts;
					delete hit.__flexicorpKwicPartsFmt;
				} else {
				this.logAbiendoloKwic('cache-hit', hit, {
					left: hit.__flexicorpKwicParts.left || '',
					match: hit.__flexicorpKwicParts.match || '',
					right: hit.__flexicorpKwicParts.right || '',
					isXml: !!hit.__flexicorpKwicParts.isXml,
				});
				return hit.__flexicorpKwicParts;
				}
			}
			const hasXml = this.hitHasTeitokXmlContext(hit);
			const textDisplay = this.search && this.search.contextFormat === 'text';
			let xmlMatchOnlyFallbackText = '';
			let xmlMatchOnlyFallbackHtml = '';
			const inferredPlain = this.getPlainContextPartsFromHit(hit);
			const ctxPlain = inferredPlain
				? { left: inferredPlain.left, match: inferredPlain.match, right: inferredPlain.right }
				: null;
			const sentenceAlignedParts = this.getSentenceAlignedKwicParts(hit);
			if (sentenceAlignedParts) {
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = sentenceAlignedParts;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				return sentenceAlignedParts;
			}
			const plainHasSides = !!(
				ctxPlain
				&& (
					(typeof ctxPlain.left === 'string' && ctxPlain.left.trim() !== '')
					|| (typeof ctxPlain.right === 'string' && ctxPlain.right.trim() !== '')
				)
			);
			// Fast path for token-window scope: use backend/native plain context directly.
			// This avoids expensive XML parsing and fixes contracted-form rows where XML clipping
			// may collapse to one orthographic token (e.g. a single-token "abiendolo" row).
			if (
				this.kwicScopeUsesTokenWindow()
				&& ctxPlain
				&& (plainHasSides || !hasXml || textDisplay)
				&& (typeof ctxPlain.left === 'string' || typeof ctxPlain.match === 'string' || typeof ctxPlain.right === 'string')
			) {
				const parts = {
					left: typeof ctxPlain.left === 'string' ? ctxPlain.left : '',
					match: typeof ctxPlain.match === 'string' ? ctxPlain.match : '',
					right: typeof ctxPlain.right === 'string' ? ctxPlain.right : '',
					isXml: false,
				};
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = parts;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('plain-fast-path', hit, parts);
				this.logAbiendoloKwic('plain-fast-path', hit, parts);
				return parts;
			}
			// Prefer TEITOK XML KWIC (highlights, facs, …) when a fragment exists and the user did not ask for plain text.
			if (this.isXmlContext() && this.wantsStructuredContext() && hasXml && !textDisplay) {
				const xmlParts = this.getXmlKwicPartsForIds(hit, this.getHitMatchIds(hit));
				if (xmlParts) {
					const plainCtx = ctxPlain;
					// Contracted forms can produce XML fragments with only the enclosing orthographic token
					// (e.g. one <tok> with matching <dtok>), which leaves XML KWIC with no side context.
					// If backend plain context has left/right, prefer that so the row is informative.
					if (this.kwicScopeUsesTokenWindow() && plainHasSides) {
						const xmlLeft = this.fragmentToText(xmlParts.left || '').trim();
						const xmlRight = this.fragmentToText(xmlParts.right || '').trim();
						if (xmlLeft === '' && xmlRight === '') {
							xmlMatchOnlyFallbackText = this.fragmentToText(xmlParts.match || '').trim();
							xmlMatchOnlyFallbackHtml = typeof xmlParts.match === 'string' ? xmlParts.match : '';
							if (plainHasSides) {
								const rescued = {
									left: typeof plainCtx.left === 'string' ? plainCtx.left : '',
									match: typeof plainCtx.match === 'string' ? plainCtx.match : '',
									right: typeof plainCtx.right === 'string' ? plainCtx.right : '',
									isXml: false,
								};
								if (hit && typeof hit === 'object') {
									hit.__flexicorpKwicParts = rescued;
									hit.__flexicorpKwicPartsFmt = cacheKey;
								}
								this.logEmptySideKwic('xml-empty-sides-plain-rescue', hit, rescued);
								return rescued;
							}
							// fall through to plain/json fallback paths below
						} else {
							if (hit && typeof hit === 'object') {
								hit.__flexicorpKwicParts = xmlParts;
								hit.__flexicorpKwicPartsFmt = cacheKey;
							}
							this.logEmptySideKwic('xml-kwic', hit, {
								left: this.fragmentToText(xmlParts.left || ''),
								match: this.fragmentToText(xmlParts.match || ''),
								right: this.fragmentToText(xmlParts.right || ''),
								isXml: true,
							});
							this.logAbiendoloKwic('xml-kwic', hit, {
								leftText: this.fragmentToText(xmlParts.left || '').trim(),
								matchText: this.fragmentToText(xmlParts.match || '').trim(),
								rightText: this.fragmentToText(xmlParts.right || '').trim(),
								isXml: true,
							});
							return xmlParts;
						}
					} else {
						const xmlLeft = this.fragmentToText(xmlParts.left || '').trim();
						const xmlRight = this.fragmentToText(xmlParts.right || '').trim();
						if (this.kwicScopeUsesTokenWindow() && xmlLeft === '' && xmlRight === '') {
							xmlMatchOnlyFallbackText = this.fragmentToText(xmlParts.match || '').trim();
							xmlMatchOnlyFallbackHtml = typeof xmlParts.match === 'string' ? xmlParts.match : '';
							// Generic policy: match-only XML rows are non-final in token-window mode.
							// This is especially common with dtok matches where fragment clipping may
							// collapse to a single orthographic token; continue to richer fallbacks.
							this.logEmptySideKwic('xml-empty-sides-fallthrough', hit, {
								left: xmlLeft,
								match: this.fragmentToText(xmlParts.match || '').trim(),
								right: xmlRight,
								isXml: true,
								dtokMatch: this.hitUsesDtokMatchIds(hit),
							});
						} else {
						if (hit && typeof hit === 'object') {
							hit.__flexicorpKwicParts = xmlParts;
							hit.__flexicorpKwicPartsFmt = cacheKey;
						}
						this.logEmptySideKwic('xml-kwic', hit, {
							left: this.fragmentToText(xmlParts.left || ''),
							match: this.fragmentToText(xmlParts.match || ''),
							right: this.fragmentToText(xmlParts.right || ''),
							isXml: true,
						});
						this.logAbiendoloKwic('xml-kwic', hit, {
							leftText: this.fragmentToText(xmlParts.left || '').trim(),
							matchText: this.fragmentToText(xmlParts.match || '').trim(),
							rightText: this.fragmentToText(xmlParts.right || '').trim(),
							isXml: true,
						});
						return xmlParts;
						}
					}
				}
			}
			const rawParts = this.parseEngineKwicRaw(hit && hit.raw ? hit.raw : '');
			if (rawParts) {
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = rawParts;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('raw-delimiter', hit, rawParts);
				this.logAbiendoloKwic('raw-delimiter', hit, rawParts);
				return rawParts;
			}
			// flexicorp-pando: plain { left, match, right } when there is no TEITOK XML, or when Display = plain text.
			if (!hasXml || textDisplay) {
				if (
					ctxPlain &&
					(typeof ctxPlain.left === 'string' || typeof ctxPlain.match === 'string' || typeof ctxPlain.right === 'string')
				) {
					const parts = {
						left: typeof ctxPlain.left === 'string' ? ctxPlain.left : '',
						match: typeof ctxPlain.match === 'string' ? ctxPlain.match : '',
						right: typeof ctxPlain.right === 'string' ? ctxPlain.right : '',
						isXml: false,
					};
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = parts;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					this.logEmptySideKwic('plain-fallback', hit, parts);
					this.logAbiendoloKwic('plain-fallback', hit, parts);
					return parts;
				}
			}
			const tokens = this.getContextTokens(hit);
			const matchIds = this.getHitMatchIds(hit);
			// Pando may omit XML fragment but still send hit.tokens + highlight_map (xidx not beside index).
			if (!tokens.length && matchIds.length && !this.hitUsesDtokMatchIds(hit)) {
				const jsonParts = this.getKwicPartsFromJsonTokens(hit, matchIds);
				if (jsonParts) {
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = jsonParts;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					this.logEmptySideKwic('json-token-fallback', hit, jsonParts);
					this.logAbiendoloKwic('json-token-fallback', hit, jsonParts);
					return jsonParts;
				}
			}
			if (!tokens.length || !matchIds.length) {
				const ctx = this.getHitContext(hit);
				const safeCtx = this.isRidxStyleContext(ctx) ? '' : ctx;
				if (xmlMatchOnlyFallbackHtml) {
					const fallbackXml = { left: '', match: xmlMatchOnlyFallbackHtml, right: '', isXml: true };
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = fallbackXml;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					return fallbackXml;
				}
				const fallback = {
					left: '',
					match: safeCtx || xmlMatchOnlyFallbackText || '',
					right: '',
					isXml: false,
				};
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = fallback;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('last-fallback-no-tokens-or-ids', hit, fallback);
				this.logAbiendoloKwic('last-fallback-no-tokens-or-ids', hit, fallback);
				return fallback;
			}
			const positions = [];
			tokens.forEach((token, idx) => {
				if (matchIds.includes(token.id)) positions.push(idx);
			});
			if (!positions.length) {
				const ctx = this.getHitContext(hit);
				const safeCtx = this.isRidxStyleContext(ctx) ? '' : ctx;
				if (xmlMatchOnlyFallbackHtml) {
					const fallbackXml = { left: '', match: xmlMatchOnlyFallbackHtml, right: '', isXml: true };
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = fallbackXml;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					return fallbackXml;
				}
				const fallback = {
					left: '',
					match: safeCtx || xmlMatchOnlyFallbackText || '',
					right: '',
					isXml: false,
				};
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = fallback;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('last-fallback-no-positions', hit, fallback);
				this.logAbiendoloKwic('last-fallback-no-positions', hit, fallback);
				return fallback;
			}
			const start = Math.min(...positions);
			const end = Math.max(...positions);
			const parts = {
				left: tokens.slice(0, start).map(token => token.text).join(' '),
				match: tokens.slice(start, end + 1).map(token => token.text).join(' '),
				right: tokens.slice(end + 1).map(token => token.text).join(' '),
				isXml: false,
			};
			if (hit && typeof hit === 'object') {
				hit.__flexicorpKwicParts = parts;
				hit.__flexicorpKwicPartsFmt = cacheKey;
			}
			this.logEmptySideKwic('xml-token-position-path', hit, parts);
			this.logAbiendoloKwic('xml-token-position-path', hit, parts);
			return parts;
		},

		getContextGroupKey(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = this.getHitDocLabel(hit) || hit.doc_id || '';
			const ctxObj = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const rawSentenceId = this.getHitSentenceIdForNavigation(hit);
			const sentenceId = String(rawSentenceId).trim();
			const scope = ctxObj && ctxObj.scope ? String(ctxObj.scope) : '';
			const format = ctxObj && ctxObj.format ? String(ctxObj.format) : '';
			if (sentenceId) return `sent::${docId}::${scope}::${format}::${sentenceId}`;
			// flexicorp-pando (no XML fragment): getHitContext() often collapses to the match word only
			// (synthetic line from hit.tokens), so never use context-string grouping before corpus positions —
			// otherwise every hit merges into one row (e.g. ten × "byt").
			const ms = hit.match_start;
			const me = hit.match_end;
			if (ms !== undefined && ms !== null && ms !== '' && Number.isFinite(Number(ms))) {
				const endPart = me !== undefined && me !== null && me !== '' && Number.isFinite(Number(me)) ? String(me) : '';
				return `pos::${docId}::${ms}::${endPart}`;
			}
			const context = this.getHitContext(hit);
			if (context) return `ctx::${docId}::${scope}::${format}::${context}`;
			if (hit.raw) return `raw::${docId}::${hit.raw}`;
			return `hit::${docId}::${hit.match_start || ''}::${hit.match_end || ''}::${this.getHitTokenIds(hit)}`;
		},

		getMergedHitCount(hit) {
			if (!hit || typeof hit !== 'object') return 1;
			const count = Number(hit.__flexicorpMergedHitCount);
			return Number.isFinite(count) && count > 0 ? count : 1;
		},

		buildMergedHit(hitGroup) {
			const sourceHits = Array.isArray(hitGroup) ? hitGroup.filter(Boolean) : [];
			if (!sourceHits.length) return null;
			const base = Object.assign({}, sourceHits[0]);
			const tokenIds = [];
			const groupsByName = {};
			const groupsByComposite = {};
			const alignedCompanions = [];
			let mergedTeitokTuview = null;
			const companionSeen = new Set();
			const companionKeyFor = (h) => {
				if (!h || typeof h !== 'object') return '';
				const docId = this.getHitDocLabel(h) || h.doc_id || '';
				const sid = String(this.getHitSentenceIdForNavigation(h) || '').trim();
				const ms = Number.isFinite(Number(h.match_start)) ? String(h.match_start) : '';
				const me = Number.isFinite(Number(h.match_end)) ? String(h.match_end) : '';
				const tok = this.getHitTokenIds(h) || '';
				const ctx = this.getSentenceAlignedMatchText(h) || this.getHitContext(h) || '';
				return `${docId}::${sid}::${ms}::${me}::${tok}::${ctx}`;
			};
			sourceHits.forEach(hit => {
				if (!mergedTeitokTuview && hit && hit.teitok_tuview && typeof hit.teitok_tuview === 'object') {
					mergedTeitokTuview = hit.teitok_tuview;
				}
				if (Array.isArray(hit.toks)) tokenIds.push(...hit.toks.filter(Boolean));
				this.getHighlightMapGroups(hit).forEach(({ name, ids, resultGroup }) => {
					if (!groupsByName[name]) groupsByName[name] = [];
					(Array.isArray(ids) ? ids : []).forEach(id => {
						if (id) groupsByName[name].push(id);
					});
					const rgKey = Number.isFinite(Number(resultGroup)) ? Number(resultGroup) : -1;
					const compositeKey = `${String(name)}::${String(rgKey)}`;
					if (!groupsByComposite[compositeKey]) {
						groupsByComposite[compositeKey] = { name: String(name), result_group: rgKey, tok_ids: [] };
					}
					(Array.isArray(ids) ? ids : []).forEach(id => {
						if (id) groupsByComposite[compositeKey].tok_ids.push(id);
					});
				});
				const companion = this.getAlignedCompanionHit(hit);
				if (companion) {
					const key = companionKeyFor(companion);
					if (key && !companionSeen.has(key)) {
						companionSeen.add(key);
						alignedCompanions.push(companion);
					}
				}
			});
			const uniq = (arr) => Array.from(new Set((arr || []).filter(Boolean)));
			const mergedTokenIds = uniq(tokenIds);
			const mergedHighlightMap = {
				groups: [],
				default: { tok_ids: mergedTokenIds },
			};
			Object.keys(groupsByComposite).forEach((key) => {
				const entry = groupsByComposite[key];
				const ids = uniq(entry && Array.isArray(entry.tok_ids) ? entry.tok_ids : []);
				if (!ids.length) return;
				const rg = Number.isFinite(Number(entry.result_group)) && Number(entry.result_group) >= 0 ? Number(entry.result_group) : null;
				const gid = rg !== null ? `${entry.name}_rg${rg}` : String(entry.name);
				mergedHighlightMap.groups.push({
					id: gid,
					name: String(entry.name),
					tok_ids: ids,
					result_group: rg,
				});
			});
			Object.keys(groupsByName).forEach(name => {
				const ids = uniq(groupsByName[name]);
				if (!ids.length) return;
				if (name === 'match') mergedHighlightMap.match = ids;
				else mergedHighlightMap[name] = ids;
			});
			base.toks = mergedTokenIds;
			base.highlight_map = mergedHighlightMap;
			base.__flexicorpMergedHits = sourceHits;
			base.__flexicorpMergedHitCount = sourceHits.length;
			if (alignedCompanions.length) {
				base.__flexicorpAlignedCompanions = alignedCompanions;
				base.aligned_counterpart = alignedCompanions[0];
			}
			if (mergedTeitokTuview) base.teitok_tuview = mergedTeitokTuview;
			return base;
		},

		getMergedSearchHits() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			if (!hits.length) return [];
			const shouldGroup = !(this.settings && this.settings.groupHitsBySentence === false);
			// Structural aligned targets (<s>, <p>, ...) already represent a full region on the target side.
			// Merging by sentence key collapses many distinct aligned rows into one unhelpful occurrence.
			if (this.queryUsesStructuralAlignedTarget()) return hits;
			if (!shouldGroup) return hits;
			const rawHits = _fcRaw(hits);
			const memoKey = this.isXmlContext() ? 'xml' : 'plain';
			const memo = _fcMergedHitsMemo.get(rawHits);
			if (memo && memo.key === memoKey && memo.length === rawHits.length) return memo.value;
			const merged = [];
			const seen = {};
			hits.forEach(hit => {
				const key = this.getContextGroupKey(hit);
				if (!seen[key]) {
					seen[key] = [];
					merged.push(seen[key]);
				}
				seen[key].push(hit);
			});
			const value = merged
				.map(group => this.buildMergedHit(group))
				.filter(Boolean);
			_fcMergedHitsMemo.set(rawHits, { key: memoKey, length: rawHits.length, value });
			return value;
		},

		groupHitsByDoc() {
			const groups = [];
			const seen = {};
			this.getMergedSearchHits().forEach(hit => {
				const key = this.getHitDocLabel(hit) || '(unknown document)';
				if (!seen[key]) {
					seen[key] = {
						docId: key,
						docCid: this.getHitDocCid(hit),
						docUrl: this.getHitDocUrl(hit),
						docTooltip: this.getDocumentTooltip(key),
						hits: [],
						totalHits: 0,
					};
					groups.push(seen[key]);
				}
				const bucket = seen[key];
				if (this.queryUsesStructuralAlignedTarget()) {
					if (!bucket._bySourceKey) bucket._bySourceKey = {};
					const srcKey = this.getStructuralAlignedSourceGroupKey(hit);
					let sourceRow = bucket._bySourceKey[srcKey];
					if (!sourceRow) {
						sourceRow = Object.assign({}, hit);
						sourceRow.__flexicorpAlignedCompanions = [];
						bucket._bySourceKey[srcKey] = sourceRow;
						bucket.hits.push(sourceRow);
						bucket.totalHits += this.getMergedHitCount(hit);
					}
					const companion = this.getAlignedCompanionHit(hit);
					if (companion) {
						const compKey = this.getStructuralAlignedCompanionKey(companion);
						const existing = sourceRow.__flexicorpAlignedCompanions.some((c) => this.getStructuralAlignedCompanionKey(c) === compKey);
						if (!existing) sourceRow.__flexicorpAlignedCompanions.push(companion);
						if (!sourceRow.aligned_counterpart) sourceRow.aligned_counterpart = companion;
					}
				} else {
					bucket.hits.push(hit);
					bucket.totalHits += this.getMergedHitCount(hit);
				}
			});
			return groups;
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
	return Object.assign({}, _flexicorpCore, _searchExt, _freqsExt, _fqsExt, _qbExt, _freqTableColHelpers);
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
