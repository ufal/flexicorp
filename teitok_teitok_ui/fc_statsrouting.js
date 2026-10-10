/**
 * flexicorp TEITOK UI: Stats module registry and routing of results to tabs (applyState).
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.statsrouting = function () {
	return {
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
			return window.ttFlexicorpPlanner.subtabForOperation(operationName, this.fcPlannerModules());
		},

		/** Why an answer is not shown where it belongs (statsRouteNote). */
		fcRouteNoteText(planned, shown) {
			const labels = { freq: 'Frequency', coll: 'Collocations', other: 'Other', corpus: 'Corpus stats', advanced_dcoll: 'Dependencies', contrast: 'Contrast', maps: 'Maps', queries: 'Queries' };
			const name = (id) => labels[id] || id;
			const why = typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits() && !this.statsHasResultFor(planned)
				? 'there are no hits to analyse'
				: `the ${name(planned)} panel is not available for this corpus or engine`;
			return `This result belongs in ${name(planned)}, but ${why}; showing ${name(shown)} instead.`;
		},

		/** The Stats modules as the planner sees them: id and the operations they show. */
		fcPlannerModules() {
			const reg = Array.isArray(this.statsModuleRegistry) ? this.statsModuleRegistry : [];
			return reg.filter((m) => m && m.id).map((m) => ({ id: m.id, operations: Array.isArray(m.operations) ? m.operations : [] }));
		},

		/** The planner's input from a server state (see fc_planner.js), and its decision. */
		fcPlanFromState(state, preserveTab) {
			const sr = state && state.search && state.search.response && state.search.response.result
				&& typeof state.search.response.result === 'object' ? state.search.response.result : null;
			const input = {
				preserveTab: !!preserveTab,
				serverTab: state && state.activeTab ? String(state.activeTab) : '',
				currentTab: this.activeTab,
				currentSubtab: this.statsSubTab,
				hint: state && typeof state.statsSubTabHint === 'string' ? state.statsSubTabHint : '',
				slotOp: this.statsOperationFromState(state),
				ran: {
					other: !!(state && state.other && state.other.ran),
					coll: !!(state && state.collocation && state.collocation.ran),
					freq: !!(state && state.frequency && state.frequency.ran),
				},
				searchRan: !!(state && state.search && state.search.ran),
				hasHits: typeof this.statsSearchHasHits === 'function' && this.statsSearchHasHits(),
				searchResult: sr ? { kind: String(sr.kind || ''), operation: String(sr.operation || '') } : null,
				modules: this.fcPlannerModules(),
			};
			const out = window.ttFlexicorpPlanner.plan(input);
			if (this.debugMode) console.info('[flexicorp][plan]', out.reason, out, input);
			return out;
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
		/**
		 * A result for this Stats subtab is loaded. An aggregation program (`A = …; freq A by …`)
		 * answers with a table and no hits, so "no hits" must not close the subtab showing it.
		 */
		statsHasResultFor(sub) {
			const ran = (slot) => !!(slot && slot.ran && !(slot.response && this.callHasErrors(slot.response)));
			if (sub === 'freq') return ran(this.frequency);
			if (sub === 'coll') return ran(this.collocation);
			if (sub === 'other') return ran(this.other);
			return false;
		},

		ensureStatsSubTabAllowed() {
			const prev = this.statsSubTab;
			const dynIds =
				typeof this.availableStatsModules === 'function'
					? this.availableStatsModules().map((m) => m.id)
					: [];
			if (!this.search || !this.search.ran) {
				this.statsSubTab = 'corpus';
			} else if (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits()) {
				if (prev && !['corpus', 'queries'].includes(prev) && !this.statsHasResultFor(prev)) {
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

			// Load Stats subtabs (maps, contrast, …) before validating statsSubTab so isAvailable() sees current backend/corpus.
			if (typeof this.installFlexicorpStatsModuleExtensions === 'function') {
				this.installFlexicorpStatsModuleExtensions();
			}
			// Where the answer lands: the planner (fc_planner.js) decides tab and subtab once,
			// with the modules' operations known; the guard below keeps an unavailable subtab out.
			const plan = this.fcPlanFromState(state, preserveClientTab);
			if (plan.subtab) this.statsSubTab = plan.subtab;
			this.statsRouteNote = '';
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
			if (plan.tab) this.activeTab = plan.tab;
			// the guard moved the planned subtab (a module this corpus does not offer, no hits):
			// say so instead of showing another panel without a word
			if (plan.subtab && this.statsSubTab !== plan.subtab && !preserveClientTab) {
				this.statsRouteNote = this.fcRouteNoteText(plan.subtab, this.statsSubTab);
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
	};
};
