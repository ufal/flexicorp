/**
 * flexicorp TEITOK UI: Search requests, request data, paging, search limit and call messages.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.requests = function () {
	return {
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
				} else {
					// The tab this request's answer moved to (e.g. Stats for a typed `freq … by`
					// program) is where the user now is: a later probe must restore that one.
					this._userActiveTab = this.activeTab;
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
			const recordText = !canAppend && !this._snapshotRestoring && typeof this.statsBaseQueryText === 'function'
				? this.statsBaseQueryText() : '';
			if (!recordText) formData.set('record', '0');
			return this.submitAjaxData(formData, 'search', { appendSearch: canAppend }).then((out) => {
				if (recordText && this.search && this.search.ran && !this.callHasErrors(this.search.response)
					&& typeof this.fcQueryStoreRecord === 'function') {
					this.fcQueryStoreRecord(recordText);
				}
				return out;
			});
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
	};
};
