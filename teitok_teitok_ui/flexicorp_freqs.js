/**
 * Quantitative analysis tab (frequency distribution, future collocations / keyness / corpus stats).
 * Merged into flexicorpApp via flexicorpFreqsExtend (see flexicorp.js).
 */
window.flexicorpFreqsExtend = function flexicorpFreqsExtend() {
	return {
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

		frequencyUseRadioFields() {
			const qe = String((this.settings && this.settings.queryEngine) || '').toLowerCase();
			const backend = String((this.settings && this.settings.backend) || '').toLowerCase();
			return qe === 'cqp' || qe.includes('cqp') || backend === 'cqp';
		},

		isFrequencyFieldSelected(key) {
			const k = String(key || '').trim();
			if (!k || !this.frequency) return false;
			if (this.frequencyUseRadioFields()) {
				const single = String(this.frequency.field || '').trim();
				if (single) return single === k;
			}
			const arr = Array.isArray(this.frequency.formFields) ? this.frequency.formFields : [];
			return arr.map((x) => String(x).trim()).includes(k);
		},

		onFrequencyFieldInputChange(key, checked) {
			const k = String(key || '').trim();
			if (!k || !this.frequency) return;
			if (this.frequencyUseRadioFields()) {
				if (checked) {
					this.frequency.field = k;
					this.frequency.formFields = [k];
				}
				return;
			}
			const cur = Array.isArray(this.frequency.formFields) ? this.frequency.formFields : [];
			const next = cur.map((x) => String(x).trim()).filter(Boolean);
			const idx = next.indexOf(k);
			if (checked && idx === -1) next.push(k);
			if (!checked && idx !== -1) next.splice(idx, 1);
			this.frequency.formFields = next;
			if (next.length) this.frequency.field = next[0];
		},

		/**
		 * Default frequency.formFields from server-rendered field list (data-field-keys).
		 * CQP uses single selection (radio); others keep multi-select checkboxes.
		 *
		 * Guard: if the frequency operation has not actually been run yet, ignore any
		 * server-supplied default field/formFields and start the form unchecked. This
		 * prevents the "first checkbox auto-ticked" regression where a backend default
		 * (e.g. 'lemma') leaks into `state.frequency.formFields` and is rendered as a
		 * pre-selection even though the user has never opened the form before.
		 */
		initFrequencyFieldCheckboxes(fieldsetEl) {
			if (!fieldsetEl || !this.frequency) return;
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			const keys = typeof fns.frequencySelectableFieldKeys === 'function'
				? fns.frequencySelectableFieldKeys(fieldsetEl.parentElement || fieldsetEl)
				: (() => {
					const raw = fieldsetEl.getAttribute('data-field-keys');
					if (!raw) return [];
					try { return JSON.parse(raw); } catch (_) { return []; }
				})();
			if (!Array.isArray(keys) || !keys.length) return;
			const allow = new Set(keys.map((k) => String(k).trim()).filter(Boolean));
			const ran = !!(this.frequency && this.frequency.ran);
			const current = ran && Array.isArray(this.frequency.formFields)
				? this.frequency.formFields.map((k) => String(k).trim()).filter((k) => k && allow.has(k))
				: [];
			if (this.frequencyUseRadioFields()) {
				const pick = current.length ? current[0] : '';
				this.frequency.formFields = pick ? [pick] : [];
				this.frequency.field = pick || '';
				return;
			}
			if (current.length) {
				this.frequency.formFields = current;
				this.frequency.field = current[0];
				return;
			}
			this.frequency.formFields = [];
			this.frequency.field = '';
		},

		frequencyFieldSelectionOk() {
			if (!this.frequency) return false;
			if (this.frequencyUseRadioFields()) {
				return String(this.frequency.field || '').trim() !== '';
			}
			return Array.isArray(this.frequency.formFields) && this.frequency.formFields.length > 0;
		},

		frequencyChartLibraryAvailable() {
			return typeof window !== 'undefined' && typeof window.Chart === 'function';
		},

		/** Canonical Pando measure id order (primary = first in list among selected). */
		collocationMeasureOrder() {
			return ['logdice', 'mi', 'mi3', 'tscore', 'll', 'dice'];
		},

		normalizeCollocationMeasureKeys(keys) {
			const ORDER = this.collocationMeasureOrder();
			const arr = Array.isArray(keys) ? keys.filter(Boolean) : [];
			const set = new Set(arr);
			const out = ORDER.filter((id) => set.has(id));
			return out.length ? out : ['logdice'];
		},

		/** Keep checkbox group non-empty (Alpine multi-checkbox) and canonical order. */
		syncCollocationMeasureKeysAfterChange() {
			if (!this.collocation) return;
			const next = this.normalizeCollocationMeasureKeys(this.collocation.measureKeys);
			this.collocation.measureKeys = next;
		},

		onCollocationMeasureSelectionChanged() {
			this.syncCollocationMeasureKeysAfterChange();
			// If collocations are already shown, changing measures means we need fresh
			// backend data for those metrics; do not fake columns from stale rows.
			if (!this.collocation || !this.collocation.ran) return;
			if (!this.search || !this.search.ran || (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits())) return;
			if (typeof this.isLoading === 'function' && this.isLoading('collocation')) return;
			if (this._collocationMeasureRerunTimer) {
				clearTimeout(this._collocationMeasureRerunTimer);
				this._collocationMeasureRerunTimer = null;
			}
			this._collocationMeasureRerunTimer = setTimeout(() => {
				this._collocationMeasureRerunTimer = null;
				try {
					this.submitCollocationFromButton();
				} catch (_) {
					/* ignore */
				}
			}, 250);
		},

		/** Text of the last search query used as scope for Stats (CQL box or constructed [field="…"]). */
		statsBaseQueryText() {
			const s = this.search;
			if (!s) return '';
			const q = s.query && String(s.query).trim();
			if (q) return q;
			const fv = s.value && String(s.value).trim();
			if (!fv) return '';
			const field = (s.field && String(s.field).trim()) || 'lemma';
			const esc = fv.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
			return '[' + field + '="' + esc + '"]';
		},

		/** Strip trailing aggregation clauses (matches tt_flexicorp_teitok_sanitize_query_aggregations). */
		stripAggregationClausesFromQuery(query) {
			const q = String(query || '').trim();
			if (!q) return '';
			const parts = q.split(/\s*;\s*/);
			const filtered = [];
			for (let i = 0; i < parts.length; i++) {
				const p = parts[i].trim();
				if (!p) continue;
				if (/^(freq|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i.test(p)) continue;
				if (/^coll\s+/i.test(p)) continue;
				if (/^tabulate\s+/i.test(p)) continue;
				if (/^sort\s+/i.test(p)) continue;
				if (/^count\b/i.test(p)) continue;
				if (/^dist\b/i.test(p)) continue;
				if (/^dcoll\b/i.test(p)) continue;
				if (/^keyness\b/i.test(p)) continue;
				if (/^show\s+/i.test(p)) continue;
				if (/^size\b/i.test(p)) continue;
				filtered.push(p);
			}
			return filtered.join('; ');
		},

		/** Statements removed by stripAggregationClausesFromQuery (exact text from the query). */
		statsAggregationPartsFromQuery(query) {
			const q = String(query || '').trim();
			if (!q) return [];
			const parts = q.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
			const out = [];
			for (let i = 0; i < parts.length; i++) {
				const p = parts[i];
				if (/^(freq|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i.test(p)) out.push(p);
				else if (/^coll\s+/i.test(p)) out.push(p);
				else if (/^tabulate\s+/i.test(p)) out.push(p);
				else if (/^sort\s+/i.test(p)) out.push(p);
				else if (/^count\b/i.test(p)) out.push(p);
				else if (/^dist\b/i.test(p)) out.push(p);
				else if (/^dcoll\b/i.test(p)) out.push(p);
				else if (/^keyness\b/i.test(p)) out.push(p);
				else if (/^show\s+/i.test(p)) out.push(p);
				else if (/^size\b/i.test(p)) out.push(p);
			}
			return out;
		},

		/** Search scope only (no freq / coll / … tail). */
		statsSearchOnlyQueryText() {
			return this.stripAggregationClausesFromQuery(this.statsBaseQueryText());
		},

		_ttSearchScope() {
			return typeof window !== 'undefined' && window.ttFlexicorpFns && window.ttFlexicorpFns.searchScope
				? window.ttFlexicorpFns.searchScope
				: null;
		},

		statsSearchScopeRehydrateFromSearch() {
			const ss = this._ttSearchScope();
			if (!ss || typeof ss.createSearchScopeStore !== 'function' || !this.search) return;
			const full = String(this.search.query != null ? this.search.query : '').trim();
			const sig = full;
			if (this._statsSearchScopeSig === sig && this.statsSearchScopeStore) return;
			this._statsSearchScopeSig = sig;
			if (!this.statsSearchScopeStore) {
				this.statsSearchScopeStore = ss.createSearchScopeStore(full);
			} else if (typeof this.statsSearchScopeStore.hydrateFromProgram === 'function') {
				this.statsSearchScopeStore.hydrateFromProgram(full);
			}
		},

		statsSearchScopePersistToSearch() {
			const ss = this._ttSearchScope();
			if (!ss || !this.statsSearchScopeStore || !this.search) return;
			const scopeOnly = ss.compileQueriesToScopeProgram(this.statsSearchScopeStore.queries, { activeOnly: false });
			const next =
				typeof this.statsSearchScopeStore.mergeFullProgram === 'function'
					? this.statsSearchScopeStore.mergeFullProgram(scopeOnly)
					: scopeOnly;
			if (String(this.search.query || '').trim() !== String(next || '').trim()) {
				this.search.query = next;
				this._statsSearchScopeSig = String(next || '').trim();
				if (typeof this.scheduleQueryHighlight === 'function') this.scheduleQueryHighlight();
			}
		},

		statsSearchScopeToggleActive(id, on) {
			if (!this.statsSearchScopeStore || typeof this.statsSearchScopeStore.setActive !== 'function') return;
			this.statsSearchScopeStore.setActive(id, !!on);
			this.statsSearchScopePersistToSearch();
		},
		statsSearchScopeUniqueName(base = 'Q') {
			const root = String(base || 'Q').trim() || 'Q';
			const rows = this.statsSearchScopeStore && Array.isArray(this.statsSearchScopeStore.queries) ? this.statsSearchScopeStore.queries : [];
			const used = new Set(rows.map((r) => String(r && r.name || '').trim()).filter(Boolean));
			let i = 1;
			let candidate = `${root}${i}`;
			while (used.has(candidate)) { i += 1; candidate = `${root}${i}`; }
			return candidate;
		},
		statsSearchScopeIsNamed(row) {
			const r = row && typeof row === 'object' ? row : {};
			const nm = String(r.name || '').trim();
			return !!(r.named === true && nm && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm));
		},
		statsSearchScopeEnsureNamed(row) {
			if (!row || !this.statsSearchScopeStore) return '';
			if (this.statsSearchScopeIsNamed(row)) return String(row.name || '').trim();
			const next = this.statsSearchScopeUniqueName('Q');
			row.name = next;
			row.named = true;
			if (typeof this.statsSearchScopeStore.renameQuery === 'function') this.statsSearchScopeStore.renameQuery(row.id, next);
			this.statsSearchScopePersistToSearch();
			return next;
		},
		statsSearchScopeProgramText(row) {
			const r = row && typeof row === 'object' ? row : {};
			const text = String(r.text || '').trim();
			if (!text) return '—';
			if (this.statsSearchScopeIsNamed(r)) return `${String(r.name).trim()} = ${text}`;
			return text;
		},
		statsSearchScopeCardHtml(row) {
			return this.statsSnippetHighlightHtml(this.statsSearchScopeProgramText(row));
		},
		statsSearchScopeCardClick(row) {
			if (!row || !this.statsSearchScopeStore) return;
			if (this.statsScopeSingleSelectModeActive()) {
				this.collocationSelectScopeQuery(row.id);
				return;
			}
			this.statsSearchScopeToggleActive(row.id, !(row.active !== false));
		},
		statsSingleScopeModeTabIds() {
			return ['coll', 'advanced_dcoll'];
		},
		statsTabNeedsSingleScopeQuery(tabId) {
			const id = String(tabId || '').trim();
			return this.statsSingleScopeModeTabIds().includes(id);
		},
		statsScopeSingleSelectModeActive() {
			return this.statsTabNeedsSingleScopeQuery(this.statsSubTab);
		},
		statsSearchScopeActiveIds() {
			const rows = this.statsSearchScopeStore && Array.isArray(this.statsSearchScopeStore.queries)
				? this.statsSearchScopeStore.queries
				: [];
			return rows.filter((r) => r && r.active !== false).map((r) => String(r.id || '').trim()).filter(Boolean);
		},
		statsSearchScopeFirstActiveNamedId() {
			const rows = this.statsSearchScopeStore && Array.isArray(this.statsSearchScopeStore.queries)
				? this.statsSearchScopeStore.queries
				: [];
			for (let i = 0; i < rows.length; i += 1) {
				const row = rows[i] && typeof rows[i] === 'object' ? rows[i] : null;
				if (!row || row.active === false) continue;
				const nm = String(row.name || '').trim();
				if (row.named === true && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm)) return nm;
			}
			return '';
		},
		collocationSelectScopeQuery(id) {
			const targetId = String(id || '').trim();
			if (!targetId || !this.statsSearchScopeStore || typeof this.statsSearchScopeStore.setActive !== 'function') return;
			const rows = Array.isArray(this.statsSearchScopeStore.queries) ? this.statsSearchScopeStore.queries : [];
			let changed = false;
			for (let i = 0; i < rows.length; i += 1) {
				const rowId = String(rows[i] && rows[i].id || '').trim();
				if (!rowId) continue;
				const shouldBeActive = rowId === targetId;
				const wasActive = rows[i].active !== false;
				if (wasActive !== shouldBeActive) {
					this.statsSearchScopeStore.setActive(rowId, shouldBeActive);
					changed = true;
				}
			}
			if (changed) this.statsSearchScopePersistToSearch();
		},
		collocationEnterSingleSelectScopeMode() {
			if (!this.statsSearchScopeStore || typeof this.statsSearchScopeStore.setActive !== 'function') return;
			const rows = Array.isArray(this.statsSearchScopeStore.queries) ? this.statsSearchScopeStore.queries : [];
			if (!rows.length) return;
			if (!Array.isArray(this._collocationScopeRestoreActiveIds)) {
				this._collocationScopeRestoreActiveIds = this.statsSearchScopeActiveIds();
			}
			const activeNow = this.statsSearchScopeActiveIds();
			let focusId = activeNow.length ? activeNow[0] : '';
			if (!focusId) {
				focusId = String(rows[0] && rows[0].id || '').trim();
			}
			if (!focusId) return;
			this.collocationSelectScopeQuery(focusId);
		},
		collocationExitSingleSelectScopeMode() {
			if (!Array.isArray(this._collocationScopeRestoreActiveIds)) return;
			if (!this.statsSearchScopeStore || typeof this.statsSearchScopeStore.setActive !== 'function') {
				this._collocationScopeRestoreActiveIds = null;
				return;
			}
			const restore = new Set(this._collocationScopeRestoreActiveIds.map((x) => String(x || '').trim()).filter(Boolean));
			const rows = Array.isArray(this.statsSearchScopeStore.queries) ? this.statsSearchScopeStore.queries : [];
			let changed = false;
			for (let i = 0; i < rows.length; i += 1) {
				const rowId = String(rows[i] && rows[i].id || '').trim();
				if (!rowId) continue;
				const shouldBeActive = restore.has(rowId);
				const wasActive = rows[i].active !== false;
				if (wasActive !== shouldBeActive) {
					this.statsSearchScopeStore.setActive(rowId, shouldBeActive);
					changed = true;
				}
			}
			this._collocationScopeRestoreActiveIds = null;
			if (changed) this.statsSearchScopePersistToSearch();
		},
		statsOpenQueriesEditor() {
			this.statsQueriesTabVisible = true;
			if (!this.statsSearchScopeStore) this.statsSearchScopeRehydrateFromSearch();
			this.setStatsSubTab('queries');
		},
		statsCanShowQueriesSubtab() {
			return this.statsQueriesTabVisible === true || this.statsSubTab === 'queries';
		},
		statsSearchScopeRenameViaPrompt(row) {
			if (!row) return;
			const cur = this.statsSearchScopeEnsureNamed(row);
			const next = typeof window !== 'undefined' && typeof window.prompt === 'function'
				? String(window.prompt('Rename query', cur) || '').trim()
				: '';
			if (!next) return;
			if (!/^[A-Za-z_][A-Za-z0-9_-]*$/.test(next)) return;
			row.name = next;
			row.named = true;
			if (this.statsSearchScopeStore && typeof this.statsSearchScopeStore.renameQuery === 'function') this.statsSearchScopeStore.renameQuery(row.id, next);
			this.statsSearchScopePersistToSearch();
		},
		/**
		 * Edit from Search scope cards: ensure a stable name, then prompt rename.
		 * Follow-up: route to an in-page Queries/authoring flow (see Advanced dev page).
		 */
		statsSearchScopeEditRow(row) {
			if (!row) return;
			this.statsSearchScopeEnsureNamed(row);
			this.statsSearchScopeRenameViaPrompt(row);
		},

		statsSearchScopeAddRow() {
			const ss = this._ttSearchScope();
			if (!ss || !this.statsSearchScopeStore || typeof this.statsSearchScopeStore.addQuery !== 'function') return;
			this.statsSearchScopeStore.addQuery({ text: '', source: 'user' });
			this.statsSearchScopePersistToSearch();
		},

		statsSearchScopeRemoveRow(id) {
			if (!this.statsSearchScopeStore || typeof this.statsSearchScopeStore.removeQuery !== 'function') return;
			this.statsSearchScopeStore.removeQuery(id);
			this.statsSearchScopePersistToSearch();
		},

		statsSearchScopeRenameCommitted(row) {
			const ss = this._ttSearchScope();
			if (!row || !this.statsSearchScopeStore || !ss) return;
			const nm = String(row.name || '').trim();
			if (nm && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm)) {
				row.named = true;
				if (typeof this.statsSearchScopeStore.renameQuery === 'function') {
					this.statsSearchScopeStore.renameQuery(row.id, nm);
				}
			} else {
				row.named = false;
			}
			this.statsSearchScopePersistToSearch();
		},
		statsNamedSourcesRecent() {
			const ss = this._ttSearchScope();
			const list = typeof this.recentQueriesListDeduped === 'function' ? this.recentQueriesListDeduped() : [];
			if (!Array.isArray(list) || !list.length) return [];
			const raw = [];
			for (let i = 0; i < list.length; i += 1) {
				const q0 = String(list[i] || '').trim();
				if (!q0) continue;
				const scopeOnly = ss && typeof ss.stripAggregationClausesFromQuery === 'function'
					? String(ss.stripAggregationClausesFromQuery(q0) || '').trim()
					: q0;
				const parsed = ss && typeof ss.parseScopeOnlyToQueries === 'function'
					? ss.parseScopeOnlyToQueries(scopeOnly, { defaultSource: 'recent' })
					: [];
				if (Array.isArray(parsed) && parsed.length) {
					for (let j = 0; j < parsed.length; j += 1) {
						const row = parsed[j] && typeof parsed[j] === 'object' ? parsed[j] : {};
						const text = String(row.text || '').trim();
						if (!text) continue;
						const name = String(row.name || '').trim() || `Recent${i + 1}_${j + 1}`;
						raw.push({ name, query: text, source: 'recent' });
					}
				} else {
					raw.push({ name: `Recent${i + 1}`, query: scopeOnly || q0, source: 'recent' });
				}
			}
			const seen = new Set();
			const out = [];
			for (let i = 0; i < raw.length; i += 1) {
				const rec = raw[i];
				const qkey = String(rec.query || '').trim().replace(/\s+/g, ' ');
				if (!qkey || seen.has(qkey)) continue;
				const key = qkey;
				seen.add(key);
				out.push(rec);
			}
			return out;
		},
		statsNamedSourcesStored() {
			const ql = this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage).trim() : '';
			const byDialect = this.storedQueriesByDialect && typeof this.storedQueriesByDialect === 'object'
				? this.storedQueriesByDialect
				: {};
			const rows = ql && Array.isArray(byDialect[ql]) ? byDialect[ql] : [];
			const list = Array.isArray(rows) ? rows : [];
			const seen = new Set();
			const out = [];
			for (let i = 0; i < list.length; i += 1) {
				const rec = list[i] && typeof list[i] === 'object' ? list[i] : {};
				const name = String(rec.name || '').trim();
				const query = String(rec.query || '').trim();
				if (!query) continue;
				const key = `${name}\u0000${query}`;
				if (seen.has(key)) continue;
				seen.add(key);
				out.push(rec);
			}
			return out;
		},
		statsSearchScopeUniquePreferredName(preferred, usedNames) {
			const set = usedNames instanceof Set ? usedNames : new Set();
			const seed = String(preferred || '').trim();
			let base = seed;
			if (!/^[A-Za-z_][A-Za-z0-9_-]*$/.test(base)) {
				const clean = seed.replace(/[^A-Za-z0-9_-]+/g, '_').replace(/^_+/, '');
				base = /^[A-Za-z_]/.test(clean) ? clean : `Q_${clean || 'pick'}`;
			}
			if (!set.has(base)) {
				set.add(base);
				return base;
			}
			let idx = 2;
			let candidate = `${base}_${idx}`;
			while (set.has(candidate)) {
				idx += 1;
				candidate = `${base}_${idx}`;
			}
			set.add(candidate);
			return candidate;
		},
		statsAddNamedSourcePick(rec) {
			const ss = this._ttSearchScope();
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			const norm = typeof fns.normalizeNamedQueryRecord === 'function'
				? fns.normalizeNamedQueryRecord(rec)
				: { name: '', query: '', source: 'stored' };
			const q = String(norm.query || '').trim();
			if (!q || !ss) return;
			this.statsSearchScopeRehydrateFromSearch();
			if (!this.statsSearchScopeStore || typeof this.statsSearchScopeStore.addQuery !== 'function') return;
			const source = String(norm.source || 'stored');
			const scopeOnly = typeof ss.stripAggregationClausesFromQuery === 'function'
				? String(ss.stripAggregationClausesFromQuery(q) || '').trim()
				: q;
			const parsed = typeof ss.parseScopeOnlyToQueries === 'function'
				? ss.parseScopeOnlyToQueries(scopeOnly, { defaultSource: source })
				: [];
			const incoming = Array.isArray(parsed) && parsed.length
				? parsed
				: [{ name: String(norm.name || '').trim(), text: scopeOnly || q, named: true }];
			const existingRows = Array.isArray(this.statsSearchScopeStore.queries) ? this.statsSearchScopeStore.queries : [];
			const used = new Set(
				existingRows
					.map((row) => String(row && row.name || '').trim())
					.filter((nm) => /^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm))
			);
			for (let i = 0; i < incoming.length; i += 1) {
				const row = incoming[i] && typeof incoming[i] === 'object' ? incoming[i] : {};
				const text = String(row.text || '').trim();
				if (!text) continue;
				const preferred = String(row.name || '').trim() || String(norm.name || '').trim() || 'Pick';
				const name = this.statsSearchScopeUniquePreferredName(preferred, used);
				this.statsSearchScopeStore.addQuery({ name, text, source, named: true });
			}
			this.statsSearchScopePersistToSearch();
		},

		statsFrequencyFieldKeysForSubmit() {
			const f = this.frequency;
			if (!f) return [];
			if (Array.isArray(f.formFields) && f.formFields.length) {
				return f.formFields.map((k) => String(k).trim()).filter(Boolean);
			}
			if (Array.isArray(f.fields) && f.fields.length) {
				return f.fields.map((k) => String(k).trim()).filter(Boolean);
			}
			if (f.field) {
				return String(f.field)
					.split(/\s*,\s*/)
					.map((s) => s.trim())
					.filter(Boolean);
			}
			return [];
		},

		statsOutgoingQueryForFreqSubmit() {
			const ss = this._ttSearchScope();
			if (!ss || !this.statsSearchScopeStore || !this.search || !this.search.ran) return '';
			if (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits()) return '';
			this.statsSearchScopeRehydrateFromSearch();
			const fields = this.statsFrequencyFieldKeysForSubmit();
			if (!fields.length) return '';
			const scopeOnly = ss.compileQueriesToScopeProgram(this.statsSearchScopeStore.queries, { activeOnly: true });
			if (!String(scopeOnly || '').trim()) return '';
			const names = ss.activeNamedAssignIds(this.statsSearchScopeStore.queries);
			const tail = ss.buildFreqClause(names, fields);
			return ss.compileScopeProgram(scopeOnly, tail);
		},
		statsOutgoingQueryForCollSubmit() {
			const ss = this._ttSearchScope();
			if (!ss || !this.statsSearchScopeStore || !this.search || !this.search.ran) return '';
			if (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits()) return '';
			this.statsSearchScopeRehydrateFromSearch();
			const scopeOnly = ss.compileQueriesToScopeProgram(this.statsSearchScopeStore.queries, { activeOnly: true });
			return String(scopeOnly || '').trim();
		},

		statsHasAggregationClauseInQuery() {
			const full = String(this.statsBaseQueryText() || '').trim();
			const stripped = String(this.statsSearchOnlyQueryText() || '').trim();
			return full !== '' && full !== stripped;
		},

		statsGuiFrequencyAggregationCommandText() {
			const f = this.frequency;
			if (!f || !f.ran) return '';
			const namesFromQuery = this.parseFreqQueryNamesFromQuery(this.statsBaseQueryText());
			const namesFromResult =
				f &&
				f.response &&
				f.response.result &&
				Array.isArray(f.response.result.compare_queries) &&
				f.response.result.compare_queries.length
					? f.response.result.compare_queries.map((n) => String(n).trim()).filter(Boolean)
					: [];
			const names = namesFromQuery.length ? namesFromQuery : namesFromResult;
			let fields = [];
			if (Array.isArray(f.formFields) && f.formFields.length) {
				fields = f.formFields.map((k) => String(k).trim()).filter(Boolean);
			} else if (Array.isArray(f.fields) && f.fields.length) {
				fields = f.fields.map((k) => String(k).trim()).filter(Boolean);
			} else if (f.field) {
				fields = String(f.field)
					.split(/\s*,\s*/)
					.map((s) => s.trim())
					.filter(Boolean);
			}
			if (!fields.length) return '';
			return 'freq ' + (names.length ? names.join(', ') + ' ' : '') + 'by ' + fields.join(', ');
		},

		statsShouldShowAggregationCommand() {
			if (!(this.search && this.search.ran)) return false;
			if (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits()) return false;
			if (this.statsHasAggregationClauseInQuery()) return true;
			if (this.statsSubTab !== 'freq') return false;
			return this.statsGuiFrequencyAggregationCommandText() !== '';
		},

		statsHasMultipleAggregationClauses() {
			const raw = this.search && this.search.query != null ? String(this.search.query) : '';
			return this.statsAggregationPartsFromQuery(raw).length > 1;
		},

		statsSnippetHighlightKey(snippet) {
			return String(snippet || '').replace(/\r?\n/g, ' ').trim();
		},

		statsSnippetHighlightHtml(snippet) {
			const key = this.statsSnippetHighlightKey(snippet);
			if (!key) return this.escapeHtmlForPre('—');
			if (this.queryHighlightSupported() && this.search && String(this.search.query || '').trim() === key && this.search.queryHighlightHtml) {
				return this.search.queryHighlightHtml;
			}
			if (!this.queryHighlightSupported()) return this.escapeHtmlForPre(key);
			const cache = this.statsSnippetHighlightCache && typeof this.statsSnippetHighlightCache === 'object'
				? this.statsSnippetHighlightCache
				: {};
			if (typeof cache[key] === 'string' && cache[key]) return cache[key];
			const pending = this.statsSnippetHighlightPending && typeof this.statsSnippetHighlightPending === 'object'
				? this.statsSnippetHighlightPending
				: {};
			if (!pending[key]) this.fetchStatsSnippetHighlight(key);
			return this.escapeHtmlForPre(key);
		},

		async fetchStatsSnippetHighlight(snippet) {
			const key = this.statsSnippetHighlightKey(snippet);
			if (!key || !this.queryHighlightSupported()) return;
			const pendingNow = this.statsSnippetHighlightPending && typeof this.statsSnippetHighlightPending === 'object'
				? this.statsSnippetHighlightPending
				: {};
			if (pendingNow[key]) return;
			this.statsSnippetHighlightPending = Object.assign({}, pendingNow, { [key]: true });
			const finish = () => {
				const p = this.statsSnippetHighlightPending && typeof this.statsSnippetHighlightPending === 'object'
					? this.statsSnippetHighlightPending
					: {};
				this.statsSnippetHighlightPending = Object.assign({}, p, { [key]: false });
			};
			try {
				const url = this.getHighlightRequestUrl(key);
				const response = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
				const data = await response.json().catch(() => null);
				let html = this.escapeHtmlForPre(key);
				if (data) {
					let result = (data.done && data.done.result) ? data.done.result : (data.result != null ? data.result : null);
					if ((result == null || typeof result !== 'object') && typeof data === 'object' && (data.html != null || data.spans != null || data.markup != null)) {
						result = data;
					}
					const resultObj = result && typeof result === 'object' ? result : null;
					if (resultObj) {
						const direct = typeof resultObj.html === 'string' && resultObj.html.length > 0 ? resultObj.html
							: (typeof resultObj.markup === 'string' && resultObj.markup.length > 0 ? resultObj.markup
								: (typeof resultObj.output === 'string' && resultObj.output.length > 0 ? resultObj.output : null));
						if (direct) {
							html = direct;
						} else {
							const tokens = Array.isArray(resultObj.tokens) ? resultObj.tokens : null;
							const spans = Array.isArray(resultObj.spans) ? resultObj.spans : null;
							if (tokens && tokens.length && typeof tokens[0].text === 'string' && tokens[0].kind != null) {
								html = this.buildHighlightHtmlFromTokens(tokens, null, key);
							} else if (spans && spans.length) {
								html = this.buildHighlightHtmlFromSpans(key, spans);
							}
						}
					} else if (typeof data.done === 'string' && data.done.length > 0) {
						html = data.done;
					}
				}
				const cache = this.statsSnippetHighlightCache && typeof this.statsSnippetHighlightCache === 'object'
					? this.statsSnippetHighlightCache
					: {};
				this.statsSnippetHighlightCache = Object.assign({}, cache, { [key]: html });
			} catch (_) {
				const cache = this.statsSnippetHighlightCache && typeof this.statsSnippetHighlightCache === 'object'
					? this.statsSnippetHighlightCache
					: {};
				this.statsSnippetHighlightCache = Object.assign({}, cache, { [key]: this.escapeHtmlForPre(key) });
			} finally {
				finish();
			}
		},

		statsMultiAaNotice() {
			return 'Only the last aggregation/association step is executed in this interface. Earlier steps are not run here (the CLI may emit multiple results).';
		},

		/**
		 * Pando program statements of the form `Name = <tokenQuery>` (see pando_cql NamedAssign).
		 * Order matches the script.
		 */
		pandoNamedAssignPartsFromScope(scope) {
			const q = String(scope || '').trim();
			if (!q) return [];
			const parts = q.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
			const out = [];
			for (let i = 0; i < parts.length; i++) {
				const p = parts[i];
				const m = p.match(/^([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(.+)$/s);
				if (m) out.push({ name: m[1], text: p });
			}
			return out;
		},

		/** Names from the last `freq|count|group Name1, Name2 by …` / `match` clause (aligns with PHP tt_flexicorp_teitok_parse_freq_query_names_from_query). */
		parseFreqQueryNamesFromQuery(fullQuery) {
			const q = String(fullQuery || '').trim();
			if (!q) return [];
			const parts = q.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
			if (!parts.length) return [];
			const last = parts[parts.length - 1];
			const m = last.match(/^(?:freq|count|group)(?:\s+([A-Za-z0-9_,\s]+))?\s+(?:by|match)\s+/i);
			if (!m || !m[1]) return [];
			return m[1]
				.split(',')
				.map((s) => s.trim())
				.filter(Boolean);
		},

		collocationHasMultipleNamedQueryBases() {
			const scope = this.statsSearchOnlyQueryText();
			if (this.pandoNamedAssignPartsFromScope(scope).length > 1) return true;
			return this.parseFreqQueryNamesFromQuery(this.statsBaseQueryText()).length > 1;
		},

		/**
		 * First name in program order for named assigns; else first name from a multi-name freq clause.
		 * Web UI runs a single `coll by …`; flexicorp-pando applies that to one hit set (first is the safe label).
		 */
		collocationEffectiveNamedQueryId() {
			if (this.statsScopeSingleSelectModeActive()) {
				const activeNm = this.statsSearchScopeFirstActiveNamedId();
				if (activeNm) return activeNm;
			}
			const assigns = this.pandoNamedAssignPartsFromScope(this.statsSearchOnlyQueryText());
			if (assigns.length) return assigns[0].name;
			const fq = this.parseFreqQueryNamesFromQuery(this.statsBaseQueryText());
			return fq.length ? fq[0] : '';
		},

		/**
		 * Token aliases (`a:[…]`, `b:<s>`, …) in the scope program — for coll/dcoll anchor selection.
		 */
		collocationTokenAliasOptions() {
			const scope = typeof this.statsSearchOnlyQueryText === 'function'
				? String(this.statsSearchOnlyQueryText() || '').trim()
				: '';
			if (!scope) return [];
			const parts = scope.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
			const seen = new Set();
			const out = [];
			const push = (nm) => {
				const n = String(nm || '').trim();
				if (!n || seen.has(n)) return;
				seen.add(n);
				out.push(n);
			};
			for (let pi = 0; pi < parts.length; pi += 1) {
				let frag = parts[pi];
				frag = frag.replace(/^[A-Za-z_][A-Za-z0-9_-]*\s*=\s*/, '');
				const re = /\b([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(?:\[|<)/g;
				let m;
				while ((m = re.exec(frag)) !== null) {
					push(m[1]);
				}
			}
			return out;
		},

		collocationEnsureAnchorDefault() {
			if (!this.collocation || typeof this.collocation !== 'object') return;
			const opts = this.collocationTokenAliasOptions();
			const cur = String(this.collocation.anchorToken || '').trim();
			if (opts.length === 1) {
				this.collocation.anchorToken = opts[0];
				return;
			}
			if (cur && opts.indexOf(cur) >= 0) return;
		},

		collocationNamedQueryNoticeLine() {
			const assigns = this.pandoNamedAssignPartsFromScope(this.statsSearchOnlyQueryText());
			if (assigns.length > 1) {
				return (
					'Collocations use the first named query (' +
					assigns[0].name +
					') only; other named definitions in this script are not used here.'
				);
			}
			const fq = this.parseFreqQueryNamesFromQuery(this.statsBaseQueryText());
			if (fq.length > 1 && assigns.length === 0) {
				return (
					'Several query names appear in a freq/count/group clause (' +
					fq.join(', ') +
					'). The web interface runs a single collocate step; use one explicit named query (Name = …) if you need a predictable base.'
				);
			}
			return '';
		},

		/**
		 * Search scope with non-effective named assigns greyed out (Collocations tab). Falls back to the
		 * global Stats search-scope HTML when there is at most one named definition.
		 */
		collocationSearchScopeDisplayHtml() {
			if (!this.search) return this.escapeHtmlForPre('—');
			const assigns = this.pandoNamedAssignPartsFromScope(this.statsSearchOnlyQueryText());
			if (assigns.length <= 1) return this.statsSearchScopeQueryDisplayHtml();
			const effective = assigns[0].name;
			const scope = this.statsSearchOnlyQueryText();
			const parts = scope.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
			const esc = (s) => this.escapeHtmlForPre(String(s).replace(/\r?\n/g, ' '));
			const htmlParts = parts.map((p) => {
				const m = p.match(/^([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(.+)$/s);
				if (m) {
					const name = m[1];
					const isEff = name === effective;
					const cls = isEff ? 'flexicorp-aa-effective' : 'flexicorp-aa-dropped';
					const title = isEff
						? 'Collocations use this named query (first in program order).'
						: 'Not used for collocations in the web interface.';
					return '<span class="' + cls + '" title="' + title.replace(/"/g, '&quot;') + '">' + esc(p) + '</span>';
				}
				return esc(p);
			});
			return htmlParts.join('<span class="flexicorp-aa-sep">; </span>');
		},

		/** Effective `; coll by …` tail; hit set comes from the Stats search scope, optional anchor for multi-token queries. */
		collocationEffectiveCollTailHtml() {
			const f = this.collocation && this.collocation.field ? String(this.collocation.field).trim() : 'lemma';
			const anch = this.collocation && this.collocation.anchorToken
				? String(this.collocation.anchorToken).trim()
				: '';
			let mid = '';
			if (anch) mid += ' (on ' + anch + ')';
			const tail = '; coll' + mid + ' by ' + f;
			const eff = this.collocationEffectiveNamedQueryId();
			if (!eff) return this.escapeHtmlForPre(tail);
			return (
				this.escapeHtmlForPre(tail + ' — ') +
				'<span class="flexicorp-aa-effective" title="Hit set is taken from this named query (first named definition in the scope program, in program order).">' +
				this.escapeHtmlForPre('hits from ' + eff) +
				'</span>'
			);
		},

		/**
		 * HTML for the search-scope line: same styling as Search collapsed query; highlight only when
		 * the full CQL box matches scope-only (no aggregation tail).
		 */
		statsSearchScopeQueryDisplayHtml() {
			if (!this.search) return this.escapeHtmlForPre('—');
			const searchOnly = this.statsSearchOnlyQueryText();
			if (!searchOnly) return this.escapeHtmlForPre('—');
			const full = this.statsBaseQueryText();
			const q = this.search.query != null ? String(this.search.query).trim() : '';
			const scopeFromCqlBox = !!q;
			const hasAgg = this.statsHasAggregationClauseInQuery();
			if (
				!hasAgg &&
				scopeFromCqlBox &&
				q === full &&
				typeof this.queryHighlightSupported === 'function' &&
				this.queryHighlightSupported() &&
				this.search.queryHighlightHtml
			) {
				return this.search.queryHighlightHtml;
			}
			return this.statsSnippetHighlightHtml(searchOnly);
		},

		/**
		 * Aggregation / association steps: same font as search scope. If several AA steps exist (CLI-style
		 * chains), grey out all but the last — only the last is executed in the web UI / Pando search run.
		 */
		statsAggregationClauseDisplayHtml() {
			const raw = this.search && this.search.query != null ? String(this.search.query) : '';
			const parts = this.statsAggregationPartsFromQuery(raw);
			if (!parts.length) {
				const guiCmd = this.statsGuiFrequencyAggregationCommandText();
				return guiCmd ? this.statsSnippetHighlightHtml(guiCmd) : this.escapeHtmlForPre('—');
			}
			const oneLine = (s) => String(s).replace(/\r?\n/g, ' ');
			const hl = (s) => this.statsSnippetHighlightHtml(oneLine(s));
			if (parts.length === 1) {
				return hl(parts[0]);
			}
			const dropped = parts.slice(0, -1);
			const last = parts[parts.length - 1];
			const droppedHtml = dropped
				.map(
					(p) =>
						`<span class="flexicorp-aa-dropped" title="Not executed in the web UI (only the last aggregation/association step runs).">${hl(p)}</span>`
				)
				.join('<span class="flexicorp-aa-sep">; </span>');
			return (
				droppedHtml +
				'<span class="flexicorp-aa-sep">; </span><span class="flexicorp-aa-effective" title="This step is executed in the web interface.">' +
				hl(last) +
				'</span>'
			);
		},

		/** Human-readable field labels for the last frequency run (uses TEITOK fieldLabels when present). */
		statsFrequencyFieldLabelsLine() {
			const f = this.frequency;
			if (!f) return '';
			const labels = this.corpusStatsFieldLabels();
			let keys = [];
			if (Array.isArray(f.formFields) && f.formFields.length) {
				keys = f.formFields.map((k) => String(k).trim()).filter(Boolean);
			} else if (Array.isArray(f.fields) && f.fields.length) {
				keys = f.fields.map((k) => String(k).trim()).filter(Boolean);
			} else if (f.field) {
				keys = String(f.field)
					.split(/\s*,\s*/)
					.map((s) => s.trim())
					.filter(Boolean);
			}
			if (!keys.length) return '';
			const parts = keys.map((key) => {
				const human = this.frequencyFieldPrettyLabel(key, labels);
				return human || key;
			});
			return 'Fields: ' + parts.join(', ');
		},

		frequencySetupSummaryText() {
			const f = this.frequency;
			if (!f) return '';
			const labelLine = this.statsFrequencyFieldLabelsLine();
			const lim = f.limit != null && f.limit !== '' ? String(f.limit) : '';
			const parts = [];
			if (labelLine) parts.push(labelLine);
			if (lim) parts.push('Limit: ' + lim);
			return parts.join(' · ');
		},

		/**
		 * @deprecated Use statsSearchScopeQueryDisplayHtml for Stats header; kept for compatibility.
		 */
		statsBaseQueryDisplayHtml() {
			return this.statsSearchScopeQueryDisplayHtml();
		},

		/** Raw flexicorp info payload (corpus) for Stats → Corpus overview. */
		corpusStatsInfoPayload() {
			const info = this.info;
			if (!info || typeof info !== 'object') return null;
			return info;
		},
		corpusStatsInfoResult() {
			const p = this.corpusStatsInfoPayload();
			const r = p && p.result;
			return r && typeof r === 'object' ? r : null;
		},
		corpusStatsHasResult() {
			const r = this.corpusStatsInfoResult();
			if (!r) return false;
			const keys = [
				'tokens_count',
				'corpus_size',
				'size',
				'n_tokens',
				'corpus_tokens',
				'tokens',
				'doc_count',
				'documents_count',
				'pattributes',
				'sattributes',
				'native_structures',
				'struct_attributes',
				'sattributes_by_region',
			];
			return keys.some((k) => r[k] != null && r[k] !== '');
		},
		corpusStatsInfoError() {
			const p = this.corpusStatsInfoPayload();
			if (!p) return false;
			if (p.ok === false) return true;
			const err = p.error || p.err;
			if (err && String(err).trim()) return true;
			const errs = p.errors;
			return Array.isArray(errs) && errs.length > 0;
		},
		corpusStatsInfoErrorMessages() {
			const out = [];
			const p = this.corpusStatsInfoPayload();
			if (!p) return out;
			const err = p.error || p.err;
			if (err && String(err).trim()) out.push(String(err).trim());
			const errs = p.errors;
			if (Array.isArray(errs)) {
				errs.forEach((e) => {
					if (e && String(e).trim()) out.push(String(e).trim());
				});
			}
			const r = p.result;
			if (r && typeof r === 'object' && Array.isArray(r.errors) && r.errors.length) {
				r.errors.forEach((e) => {
					if (e && String(e).trim()) out.push(String(e).trim());
				});
			}
			return out;
		},
		corpusStatsBackendLine() {
			const s = this.settings || {};
			const b = String(s.backend || '').trim() || '—';
			const ql = String(s.queryLanguage || '').trim();
			const cf = String(s.corpusFormat || '').trim();
			const bits = [b];
			if (ql) bits.push(ql);
			if (cf) bits.push(cf);
			const ir = this.corpusStatsInfoResult();
			if (ir && ir.file_format && String(ir.file_format).trim()) {
				const ff = String(ir.file_format).trim();
				if (!bits.some((x) => x.toLowerCase().includes(ff))) bits.push(`format: ${ff}`);
			}
			return bits.join(' · ');
		},
		corpusStatsTokenLine() {
			const r = this.corpusStatsInfoResult();
			if (!r) return '—';
			const keys = ['tokens_count', 'corpus_size', 'size', 'n_tokens', 'corpus_tokens', 'tokens'];
			for (let i = 0; i < keys.length; i++) {
				const k = keys[i];
				const v = r[k];
				if (v == null || v === '') continue;
				const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
				if (Number.isFinite(n) && n >= 0) return n.toLocaleString();
			}
			const ct = typeof this.frequencyCorpusTokensForIpm === 'function' ? this.frequencyCorpusTokensForIpm() : null;
			if (ct != null && Number.isFinite(ct) && ct > 0) return `${Math.round(ct).toLocaleString()} (from index)`;
			return '—';
		},
		corpusStatsDocumentsLine() {
			const t = this.documentsTotal;
			if (Number.isFinite(t) && t >= 0) return t.toLocaleString();
			const r = this.corpusStatsInfoResult();
			if (r) {
				const docKeys = ['doc_count', 'docs_count', 'documents_count', 'n_docs', 'docs', 'documents'];
				for (let i = 0; i < docKeys.length; i++) {
					const k = docKeys[i];
					if (r[k] != null && Number.isFinite(Number(r[k]))) {
						return Number(r[k]).toLocaleString();
					}
				}
			}
			const ld = this.listDocs && this.listDocs.result;
			if (ld && ld.total != null && Number.isFinite(Number(ld.total))) return Number(ld.total).toLocaleString();
			return '—';
		},
		corpusStatsRegistryLine() {
			const r = this.corpusStatsInfoResult();
			if (!r) return '—';
			const parts = [];
			const deriveProjectCorpusId = () => {
				const root = String(this.projectRoot || '').trim();
				if (!root) return '';
				const segs = root.split('/').filter(Boolean);
				const leaf = segs.length ? segs[segs.length - 1] : '';
				return leaf ? `tt_${leaf.toLowerCase()}` : '';
			};
			const rawCorpus = (r.corpus && String(r.corpus).trim()) || '';
			const rawRegistry = (r.registry && String(r.registry).trim()) || '';
			const lowerCorpus = rawCorpus.toLowerCase();
			const genericCorpusName = lowerCorpus === '' || ['pando', 'cqp', 'manatee', 'clickhouse', 'blacklab', 'flexi'].includes(lowerCorpus);
			const corpusName =
				(!genericCorpusName ? rawCorpus : '')
				|| (rawRegistry && !rawRegistry.includes('/') ? rawRegistry : '')
				|| deriveProjectCorpusId()
				|| rawCorpus
				|| rawRegistry
				|| '';
			if (corpusName) parts.push(`corpus: ${corpusName}`);
			const backendName =
				(r.backend && String(r.backend).trim())
				|| (this.settings && this.settings.backend && String(this.settings.backend).trim())
				|| '';
			if (backendName) parts.push(`backend: ${backendName}`);
			const storageEngine =
				(r.file_format && String(r.file_format).trim())
				|| (r.corpus_format && String(r.corpus_format).trim())
				|| (this.settings && this.settings.corpusFormat && String(this.settings.corpusFormat).trim())
				|| '';
			if (storageEngine) parts.push(`storage: ${storageEngine}`);
			if (!parts.length) return '—';
			return parts.slice(0, 3).join(' · ');
		},
		corpusStatsShowRegistryCard() {
			return this.corpusStatsRegistryLine() !== '—';
		},
		corpusStatsNumberFromKeys(obj, keys) {
			if (!obj || typeof obj !== 'object' || !Array.isArray(keys)) return null;
			for (let i = 0; i < keys.length; i++) {
				const v = obj[keys[i]];
				if (v == null || v === '') continue;
				const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
				if (Number.isFinite(n) && n >= 0) return n;
			}
			return null;
		},
		corpusStatsDisplayLabelOnly(key) {
			const k = String(key || '').trim();
			if (!k || !this.corpusStatsHasMeaningfulLabel(k)) return '';
			return String(this.corpusStatsFieldLabel(k) || '').trim();
		},
		corpusStatsValueType(value, fallback = '—') {
			if (value == null || value === '') return fallback;
			if (typeof value === 'number') return Number(value).toLocaleString();
			if (typeof value === 'boolean') return value ? 'yes' : 'no';
			const txt = String(value).trim();
			return txt || fallback;
		},
		corpusStatsSemanticType(rawName, backendTypeHint) {
			const raw = String(rawName || '').trim().toLowerCase();
			const hint = String(backendTypeHint || '').trim().toLowerCase();
			if (!raw && !hint) return '';
			// Ignore generic storage classes; show only semantic type info.
			if (['pattribute', 'sattribute', 'region', 'attribute', 'string'].includes(hint)) {
				// continue with raw-name heuristics
			} else if (hint) {
				if (['date', 'datetime', 'timestamp'].includes(hint)) return 'Date/time';
				if (['time'].includes(hint)) return 'Time';
				if (['mval', 'multi', 'multivalue', 'set', 'array', 'list'].includes(hint)) return 'Multivalue';
				if (['kv', 'keyvalue', 'map', 'dict', 'object', 'json'].includes(hint)) return 'Key-value';
				if (['int', 'integer', 'long', 'float', 'double', 'number', 'numeric'].includes(hint)) return 'Numeric';
				if (['bool', 'boolean'].includes(hint)) return 'Boolean';
				return this.corpusStatsValueType(backendTypeHint, '');
			}
			if (/(^|_)(year|date|month|day|century)(_|$)/.test(raw)) return 'Date/time';
			if (/(^|_)(time|hour|min|sec)(_|$)/.test(raw)) return 'Time';
			if (/(^|_)(geo|geolocation|location|place|lat|lon|lng|latitude|longitude|coords?)(_|$)/.test(raw)) return 'Geolocation';
			if (/(^|_)(mval|multi|values|list|set)(_|$)/.test(raw)) return 'Multivalue';
			if (/(^|_)(kv|keyvalue|json|dict|map)(_|$)/.test(raw)) return 'Key-value';
			if (/(^|_)(upos|xpos|pos|deprel|udfeats|feats)(_|$)/.test(raw)) return 'Linguistic tag/features';
			return '';
		},
		corpusStatsGeneralInfoRows() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const rows = [];
			const tokenCount = this.corpusStatsNumberFromKeys(r, ['tokens_count', 'corpus_size', 'size', 'n_tokens', 'corpus_tokens', 'tokens']);
			const docCount = this.corpusStatsNumberFromKeys(r, ['doc_count', 'docs_count', 'documents_count', 'n_docs', 'docs', 'documents']);
			rows.push({ label: 'Tokens', value: tokenCount != null ? tokenCount.toLocaleString() : '—' });
			rows.push({ label: 'Documents', value: docCount != null ? docCount.toLocaleString() : '—' });
			if (tokenCount != null && docCount != null && docCount > 0) {
				rows.push({ label: 'Avg tokens per document', value: (tokenCount / docCount).toLocaleString(undefined, { maximumFractionDigits: 2 }) });
			}
			rows.push({ label: 'Backend / storage', value: this.corpusStatsBackendLine() || '—' });
			if (this.isAdmin && this.corpusStatsShowRegistryCard()) {
				rows.push({ label: 'Corpus / backend / storage (admin)', value: this.corpusStatsRegistryLine() || '—' });
			}
			if (r.corpus != null && String(r.corpus).trim()) rows.push({ label: 'Corpus id', value: String(r.corpus).trim() });
			if (r.available != null) rows.push({ label: 'Index available', value: this.corpusStatsValueType(r.available, '—') });
			return rows;
		},
		corpusStatsPattributeRows() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const out = [];
			const skip = this.corpusStatsHiddenFieldSet();
			const pushRow = (rawName, displayName, vocab, attrType) => {
				const raw = String(rawName || '').trim();
				if (!raw || skip.has(raw)) return;
				const labelOnly = String(displayName || '').trim();
				if (!labelOnly && !this.isAdmin) return;
				out.push({
					raw,
					display: labelOnly || (this.isAdmin ? '(raw only)' : ''),
					vocab: vocab == null || vocab === '' ? '—' : this.corpusStatsValueType(vocab),
						type: this.corpusStatsSemanticType(raw, attrType) || '—',
				});
			};
			if (Array.isArray(r.attributes)) {
				r.attributes.forEach((attr) => {
					if (!attr || typeof attr !== 'object' || !attr.name) return;
					const name = String(attr.name).trim();
					const label = this.corpusStatsDisplayLabelOnly(name)
						|| (attr.display != null ? String(attr.display).trim() : '')
						|| (attr.label != null ? String(attr.label).trim() : '');
					const vocab = attr.vocab ?? attr.types ?? attr.distinct ?? null;
					const type = attr.type ?? attr.value_type ?? attr.kind ?? attr.data_type ?? null;
					pushRow(name, label, vocab, type);
				});
			} else {
				const raw = r.pattributes || r.positional_attrs || r.native_pattributes;
				if (Array.isArray(raw)) {
					raw.forEach((x) => {
						const name = String(x || '').trim();
						pushRow(name, this.corpusStatsDisplayLabelOnly(name), null, null);
					});
				}
			}
			out.sort((a, b) => a.raw.localeCompare(b.raw));
			return out.slice(0, 120);
		},
		corpusStatsFieldLabels() {
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			if (typeof fns.frequencySelectableFieldLabelMap === 'function') {
				return fns.frequencySelectableFieldLabelMap(this) || {};
			}
			return this.frequency && this.frequency.fieldLabels && typeof this.frequency.fieldLabels === 'object'
				? this.frequency.fieldLabels
				: {};
		},
		corpusStatsHiddenFieldSet() {
			if (this.isAdmin) return new Set();
			const out = new Set();
			const cat = this.attributeCatalog && typeof this.attributeCatalog === 'object'
				? this.attributeCatalog
				: {};
			const ckeys = Array.isArray(cat.noshow_keys) ? cat.noshow_keys : [];
			for (let i = 0; i < ckeys.length; i += 1) {
				const k = String(ckeys[i] || '').trim();
				if (k) out.add(k);
			}
			const legacy = Array.isArray(this.noshowFields) ? this.noshowFields : [];
			for (let i = 0; i < legacy.length; i += 1) {
				const k = String(legacy[i] || '').trim();
				if (k) out.add(k);
			}
			return out;
		},
		corpusStatsFieldLabel(key) {
			const k = String(key || '').trim();
			if (!k) return '';
			const labels = this.corpusStatsFieldLabels();
			const lbl = labels[k];
			return (lbl && String(lbl).trim()) ? String(lbl).trim() : '';
		},
		corpusStatsHasMeaningfulLabel(key) {
			const k = String(key || '').trim();
			if (!k) return false;
			const lbl = this.corpusStatsFieldLabel(k);
			if (!lbl) return false;
			const low = String(lbl).trim().toLowerCase();
			// Ignore placeholder-like labels leaked from *_display attribute keys.
			if (low === 'display') return false;
			return true;
		},
		corpusStatsHumanizeKey(key) {
			const k = String(key || '').trim();
			if (!k) return '';
			const base = k.replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
			if (!base) return '';
			return base.charAt(0).toUpperCase() + base.slice(1);
		},
		corpusStatsCleanRegionLabel(label, regionKey) {
			const raw = String(label || '').trim();
			if (!raw) return '';
			let out = raw.replace(/\s+/g, ' ').trim();
			// TEITOK often uses labels like "Document Search" for region display; keep this concise in corpus stats.
			if (String(regionKey || '').trim().toLowerCase() === 'text') {
				out = out.replace(/\s+search$/i, '').trim();
			}
			return out || raw;
		},
		corpusStatsDisplayName(key) {
			const k = String(key || '').trim();
			if (!k) return '';
			if (!this.corpusStatsHasMeaningfulLabel(k)) return this.isAdmin ? k : '';
			const lbl = this.corpusStatsFieldLabel(k);
			return this.isAdmin ? `${lbl} (${k})` : lbl;
		},
		corpusStatsRegionHeading(regionKey) {
			const key = String(regionKey || '').trim();
			if (!key) return '';
			// Prefer explicit region label if configured as <region>_display, otherwise humanize.
			const displayKey = `${key}_display`;
			const viaDisplayAttr = this.corpusStatsFieldLabel(displayKey);
			if (this.corpusStatsHasMeaningfulLabel(displayKey) && viaDisplayAttr) {
				const clean = this.corpusStatsCleanRegionLabel(viaDisplayAttr, key);
				return this.isAdmin ? `${clean} (${key})` : clean;
			}
			if (!this.isAdmin) return '';
			if (typeof this.contextScopeLabel === 'function') {
				const fromScope = String(this.contextScopeLabel(key) || '').trim();
				if (fromScope && fromScope.toLowerCase() !== key.toLowerCase()) {
					const pretty = fromScope.charAt(0).toUpperCase() + fromScope.slice(1);
					return this.isAdmin ? `${pretty} (${key})` : pretty;
				}
			}
			return this.corpusStatsDisplayName(key);
		},
		corpusStatsPattributes() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const out = [];
			const skip = this.corpusStatsHiddenFieldSet();
			if (Array.isArray(r.attributes)) {
				r.attributes.forEach((attr) => {
					if (!attr || typeof attr !== 'object' || !attr.name) return;
					const name = String(attr.name).trim();
				if (name && !skip.has(name)) {
						let s = this.corpusStatsDisplayName(name);
						if (!s) return;
						if (attr.vocab != null && Number.isFinite(Number(attr.vocab))) {
							s += ` (${Number(attr.vocab).toLocaleString()} types)`;
						}
						out.push(s);
					}
				});
			} else {
				const raw = r.pattributes || r.positional_attrs || r.native_pattributes;
				if (Array.isArray(raw)) {
					raw.forEach((x) => {
						const name = String(x).trim();
						if (!name || skip.has(name)) return;
						const shown = this.corpusStatsDisplayName(name);
						if (!shown) return;
						out.push(shown);
					});
				}
			}
			out.sort((a, b) => a.localeCompare(b));
			return out.slice(0, 48);
		},
		corpusStatsRegionGroups() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const groups = [];
			const skip = this.corpusStatsHiddenFieldSet();
			const pushRegionWithAttrs = (regionRaw, regionLabel, attrs, meta = [], regionInfo = {}) => {
				if (!regionLabel) return;
				groups.push({
					raw: String(regionRaw || '').trim(),
					region: regionLabel,
					meta: Array.isArray(meta) ? meta.filter(Boolean) : [],
					attrs: Array.isArray(attrs) ? attrs.filter(Boolean) : [],
					info: regionInfo && typeof regionInfo === 'object' ? regionInfo : {},
				});
			};
			if (Array.isArray(r.structures)) {
				r.structures.forEach((st) => {
					if (!st || typeof st !== 'object' || !st.name) return;
					const sname = String(st.name).trim();
					if (!sname) return;
					const attrs = [];
					if (Array.isArray(st.region_attrs)) {
						st.region_attrs.forEach((ra) => {
							if (!ra || typeof ra !== 'object' || !ra.name) return;
							const raname = String(ra.name).trim();
							if (raname && !skip.has(`${sname}_${raname}`) && !skip.has(raname)) {
								const rawKey = `${sname}_${raname}`;
								const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(raname);
								if (!label && !this.isAdmin) return;
								attrs.push({
									raw: rawKey,
									display: label || '(raw only)',
									vocab: this.corpusStatsValueType(ra.vocab ?? ra.types ?? ra.distinct ?? null),
									type: this.corpusStatsSemanticType(rawKey, ra.type ?? ra.value_type ?? ra.kind ?? ra.data_type ?? null) || '—',
								});
							}
						});
					} else if (Array.isArray(st.attrs)) {
						st.attrs.forEach((a) => {
							const raname = String(a).trim();
							if (raname && !skip.has(`${sname}_${raname}`) && !skip.has(raname)) {
								const rawKey = `${sname}_${raname}`;
								const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(raname);
								if (!label && !this.isAdmin) return;
								attrs.push({
									raw: rawKey,
									display: label || '(raw only)',
									vocab: '—',
									type: this.corpusStatsSemanticType(rawKey, null) || '—',
								});
							}
						});
					}
					const meta = [];
					if (st.regions != null && Number.isFinite(Number(st.regions))) meta.push(`n=${Number(st.regions).toLocaleString()}`);
					const shownRegion = this.corpusStatsRegionHeading(sname) || sname;
					if (!shownRegion) return;
					const regionInfo = {
						count: st.regions ?? st.count ?? st.n ?? null,
						token_count: st.tokens ?? st.token_count ?? st.region_tokens ?? null,
						type: st.type ?? st.region_type ?? st.kind ?? null,
						overlap: st.overlap ?? st.overlapping ?? null,
						discontinuous: st.discontinuous ?? st.is_discontinuous ?? null,
					};
					pushRegionWithAttrs(sname, shownRegion, attrs, meta, regionInfo);
				});
			} else {
				const by = r.sattributes_by_region;
				if (by && typeof by === 'object' && !Array.isArray(by)) {
					Object.keys(by)
						.sort()
						.forEach((region) => {
							const attrs = by[region];
							if (attrs && typeof attrs === 'object' && !Array.isArray(attrs)) {
								const names = Object.keys(attrs)
									.filter((k) => !String(k).startsWith('_') && !skip.has(`${region}_${k}`) && !skip.has(k))
									.sort();
								const shown = names
									.map((k) => {
										const rawKey = `${region}_${k}`;
										const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(k);
										if (!label && !this.isAdmin) return null;
										return {
											raw: rawKey,
											display: label || '(raw only)',
											vocab: '—',
											type: this.corpusStatsSemanticType(rawKey, null) || '—',
										};
									})
									.filter(Boolean);
								const shownRegion = this.corpusStatsRegionHeading(region) || region;
								if (!shownRegion) return;
								pushRegionWithAttrs(region, shownRegion, shown);
							} else if (Array.isArray(attrs) && attrs.length) {
								const names = attrs
									.map(String)
									.filter((k) => k && !skip.has(`${region}_${k}`) && !skip.has(k));
								const shown = names
									.map((k) => {
										const rawKey = `${region}_${k}`;
										const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(k);
										if (!label && !this.isAdmin) return null;
										return {
											raw: rawKey,
											display: label || '(raw only)',
											vocab: '—',
											type: this.corpusStatsSemanticType(rawKey, null) || '—',
										};
									})
									.filter(Boolean);
								const shownRegion = this.corpusStatsRegionHeading(region) || region;
								if (!shownRegion) return;
								pushRegionWithAttrs(region, shownRegion, shown);
							} else {
								const shownRegion = this.corpusStatsRegionHeading(region) || region;
								if (!shownRegion) return;
								pushRegionWithAttrs(region, shownRegion, []);
							}
						});
				}
			}
			groups.sort((a, b) => String(a.region).localeCompare(String(b.region)));
			return groups.slice(0, 20);
		},
		corpusStatsRegionLines() {
			// Backwards-compatible flattened view.
			const groups = this.corpusStatsRegionGroups();
			const lines = [];
			groups.forEach((g) => {
				const meta = Array.isArray(g.meta) && g.meta.length ? ` (${g.meta.join('; ')})` : '';
				lines.push(`${g.region}${meta}`);
				if (Array.isArray(g.attrs) && g.attrs.length) {
					const attrNames = g.attrs.map((a) => (a && typeof a === 'object' ? (a.display || a.raw || '') : String(a))).filter(Boolean);
					if (attrNames.length) lines.push(`Attributes: ${attrNames.join(', ')}`);
				}
			});
			return lines;
		},

		truncateFrequencyChartLabel(val, maxLen) {
			const max = typeof maxLen === 'number' && maxLen > 8 ? maxLen : 48;
			const s = val == null ? '' : String(val);
			if (s.length <= max) return s;
			return s.slice(0, Math.max(0, max - 1)) + '…';
		},

		frequencyChartPalette(count) {
			const out = [];
			for (let i = 0; i < count; i++) {
				const h = (200 + i * 47) % 360;
				const s = 52 + (i % 3) * 7;
				const l = 48 + (i % 5) * 4;
				out.push(`hsla(${h}, ${s}%, ${l}%, 0.88)`);
			}
			return out;
		},

		/** Row count for charts: same as the frequency limit (no extra cap). */
		frequencyChartMaxSlices() {
			return Math.max(1, this.normalizePositiveInt(this.frequency && this.frequency.limit, 100));
		},

		frequencyChartSumRowCounts(rows) {
			if (!Array.isArray(rows)) return 0;
			return rows.reduce((sum, row) => {
				const raw = row && (row.count ?? row.freq ?? row.n);
				const n = Number(raw);
				const v = Number.isFinite(n) && n >= 0 ? n : 0;
				return sum + v;
			}, 0);
		},

		frequencyRowIpmNumber(row) {
			if (!row || typeof row !== 'object') return null;
			const raw = row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm;
			if (raw != null && raw !== '') {
				const n = typeof raw === 'number' ? raw : Number(String(raw).replace(/,/g, ''));
				if (Number.isFinite(n) && n >= 0) return n;
			}
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row.count ?? row.freq ?? row.n);
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) return (c / ct) * 1000000;
			return null;
		},

		frequencyRowNormalizedIpmNumber(row) {
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row && (row.count ?? row.freq ?? row.n));
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) return (c / ct) * 1000000;
			return null;
		},

		frequencyRowRelativeSubcorpusIpmNumber(row) {
			if (!this.frequencyUsesSubcorpusIpm()) return null;
			const raw = row && (row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm);
			if (raw === null || raw === undefined || raw === '') return null;
			const n = typeof raw === 'number' ? raw : Number(String(raw).replace(/,/g, ''));
			return Number.isFinite(n) && n >= 0 ? n : null;
		},

		frequencyRowPctNumber(row) {
			const raw = row && (row.pct ?? row.percent ?? row.percentage);
			const p = Number(raw);
			if (Number.isFinite(p) && p >= 0) return p;
			const base = this.frequencyRelativeBase();
			const c = Number(row && (row.count ?? row.freq ?? row.n));
			if (base !== null && base > 0 && Number.isFinite(c) && c >= 0) return (c / base) * 100;
			return null;
		},

		/** Numeric value driving chart slice size (not the table). */
		frequencyRowChartNumeric(row) {
			const scale = this.frequencyChartValueScale || 'relative';
			if (scale === 'absolute') {
				return this.frequencyRowCount(row);
			}
			const ipm = this.frequencyRowIpmNumber(row);
			if (ipm !== null) return ipm;
			const pct = this.frequencyRowPctNumber(row);
			if (pct !== null) return pct;
			return this.frequencyRowCount(row);
		},

		frequencyChartSumRowChartNumerics(rows) {
			const slice = Array.isArray(rows) ? rows : [];
			let s = 0;
			for (let i = 0; i < slice.length; i++) {
				const v = this.frequencyRowChartNumeric(slice[i]);
				if (Number.isFinite(v) && v >= 0) s += v;
			}
			return s;
		},

		/** Others bucket weight for charts (aligned with table Rest when possible). */
		frequencyChartOthersNumeric() {
			const scale = this.frequencyChartValueScale || 'relative';
			const rest = this.frequencyRestCount();
			if (rest <= 0) return 0;
			if (scale === 'absolute') return rest;
			const s = this.frequencyRestRowIpm();
			if (s !== '') {
				const n = Number(s);
				if (Number.isFinite(n) && n >= 0) return n;
			}
			const total = this.frequencyEffectiveTotal();
			if (total !== null && total > 0) return (rest / total) * 100;
			return rest;
		},

		frequencyChartYLabel() {
			const scale = this.frequencyChartValueScale || 'relative';
			if (scale === 'absolute') return 'Count';
			const sample = this.frequencyMetricSampleRows();
			const hasIpm = sample.some((row) => this.frequencyRowIpmNumber(row) != null);
			if (hasIpm) {
				return this.frequencyUsesSubcorpusIpm() ? 'Relative (Subcorpus IPM)' : 'Relative (IPM)';
			}
			return 'Relative (%)';
		},

		frequencySupportsErrorBars() {
			if (this.frequencyHasCompareQueries()) return false;
			return this.frequencyRows().some((row) => {
				const n = Number(row && row.subcorpus_size);
				const k = this.frequencyRowCount(row);
				return Number.isFinite(n) && n > 0 && Number.isFinite(k) && k >= 0 && k <= n;
			});
		},

		frequencyRowUncertaintyBounds(row) {
			const n = Number(row && row.subcorpus_size);
			const kRaw = this.frequencyRowCount(row);
			if (!Number.isFinite(n) || n <= 0 || !Number.isFinite(kRaw) || kRaw < 0) return null;
			const k = Math.min(kRaw, n);
			const p = k / n;
			const z = 1.959963984540054;
			const z2 = z * z;
			const denom = 1 + z2 / n;
			const center = (p + z2 / (2 * n)) / denom;
			const margin = (z * Math.sqrt((p * (1 - p) + z2 / (4 * n)) / n)) / denom;
			let lo = Math.max(0, center - margin);
			let hi = Math.min(1, center + margin);

			const scale = this.frequencyChartValueScale || 'relative';
			if (scale === 'absolute') {
				return { low: lo * n, high: hi * n };
			}
			// Match chart scale: IPM when available, otherwise percentage.
			const usesIpm = this.frequencyRowIpmNumber(row) !== null;
			const factor = usesIpm ? 1000000 : 100;
			lo *= factor;
			hi *= factor;
			return { low: lo, high: hi };
		},

		setFrequencyChartValueScale(scale) {
			const next = scale === 'absolute' ? 'absolute' : 'relative';
			if (next === 'relative' && !this.frequencySupportsRelativeScale()) {
				this.frequencyChartValueScale = 'absolute';
			} else {
				this.frequencyChartValueScale = next;
			}
			if (this.frequencyVizMode && this.frequencyVizMode !== 'table') {
				this.scheduleFrequencyChartRender();
			}
		},

		setFrequencyChartOrder(mode) {
			const allowed = new Set(['size', 'value', 'time']);
			let next = allowed.has(mode) ? mode : 'size';
			if (next === 'time' && !this.frequencyCanUseTimeOrder()) next = 'size';
			this.frequencyChartOrder = next;
			if (this.frequencyVizMode && this.frequencyVizMode !== 'table' && this.frequencyVizMode !== 'pivot') {
				this.scheduleFrequencyChartRender();
			}
		},

		frequencySingleFieldKeyForOrdering() {
			const f = this.frequency && typeof this.frequency === 'object' ? this.frequency : {};
			const keys = [];
			if (Array.isArray(f.fields) && f.fields.length) {
				for (let i = 0; i < f.fields.length; i += 1) {
					const k = String(f.fields[i] || '').trim();
					if (k) keys.push(k);
				}
			} else if (Array.isArray(f.formFields) && f.formFields.length) {
				for (let i = 0; i < f.formFields.length; i += 1) {
					const k = String(f.formFields[i] || '').trim();
					if (k) keys.push(k);
				}
			} else {
				const k = String(f.field || '').trim();
				if (k) keys.push(k);
			}
			if (keys.length !== 1) return '';
			const only = keys[0];
			if (only.includes(',') || only.includes('\t')) return '';
			return only;
		},

		frequencyFieldLooksTemporal(fieldKey) {
			const k = String(fieldKey || '').trim().toLowerCase();
			if (!k) return false;
			if (/^decade\s*\(/i.test(k) || /^century\s*\(/i.test(k)) return true;
			return /(^|_)(date|datetime|timestamp|time|year|month|day|decade|century)(_|$)/.test(k);
		},

		frequencyCanUseTimeOrder() {
			const key = this.frequencySingleFieldKeyForOrdering();
			if (!key) return false;
			return this.frequencyFieldLooksTemporal(key);
		},

		frequencyChartRowsBase() {
			let rows = this.frequencyRows();
			const q = (this.frequencyTableSearch || '').trim().toLowerCase();
			if (q) {
				rows = rows.filter((r) => this.frequencyRowRawValue(r).toLowerCase().includes(q));
			}
			return rows;
		},

		frequencyChartRowCompareNumeric(row) {
			if (!row || !row.queries || typeof row.queries !== 'object') return 0;
			const scale = this.frequencyChartValueScale || 'relative';
			const qs = this.frequencyCompareQueries();
			let sum = 0;
			for (let i = 0; i < qs.length; i++) {
				const q = qs[i];
				const cell = row.queries[q] || {};
				let v = 0;
				if (scale === 'relative') v = this.frequencyRowQueryRelativeNumber(row, q);
				else v = Number(cell.count) || 0;
				if (Number.isFinite(v) && v >= 0) sum += v;
			}
			return sum;
		},

		frequencyChartParseTemporalOrder(rawValue) {
			const raw = String(rawValue == null ? '' : rawValue).trim();
			if (!raw) return null;
			const decade = raw.match(/(-?\d{3,4})\s*s$/i);
			if (decade) return Number(decade[1]);
			const century = raw.match(/(-?\d{1,2})(?:st|nd|rd|th)?\s*century$/i);
			if (century) return (Number(century[1]) - 1) * 100;
			const year = raw.match(/^-?\d{3,4}$/);
			if (year) return Number(year[0]);
			const anyYear = raw.match(/-?\d{3,4}/);
			if (anyYear) return Number(anyYear[0]);
			return null;
		},

		frequencyChartTemporalMeta(rawValue) {
			const rawAll = String(rawValue == null ? '' : rawValue).trim();
			if (!rawAll) return null;
			const parts = rawAll.split('\t').map((p) => String(p).trim()).filter(Boolean);
			const candidates = parts.length ? parts : [rawAll];
			const fieldHint = String(this.frequency && this.frequency.field ? this.frequency.field : '').toLowerCase();
			const hintDecade = fieldHint.includes('decade(');
			const hintCentury = fieldHint.includes('century(');
			for (let i = 0; i < candidates.length; i++) {
				const raw = candidates[i];
				const decade = raw.match(/(-?\d{3,4})\s*s$/i);
				if (decade) {
					const y = Number(decade[1]);
					const d = Math.floor(y / 10) * 10;
					return { key: d, step: 10, label: `${d}s` };
				}
				const century = raw.match(/(-?\d{1,2})(?:st|nd|rd|th)?\s*century$/i);
				if (century) {
					const c = Number(century[1]);
					return { key: (c - 1) * 100, step: 100, label: `${c}th century` };
				}
				const year = raw.match(/^-?\d{3,4}$/);
				if (year) {
					const y = Number(year[0]);
					if (hintDecade) {
						const d = Math.floor(y / 10) * 10;
						return { key: d, step: 10, label: String(d) };
					}
					if (hintCentury) {
						const c0 = Math.floor(y / 100) * 100;
						return { key: c0, step: 100, label: String(c0) };
					}
					return { key: y, step: 1, label: String(y) };
				}
				const anyYear = raw.match(/-?\d{3,4}/);
				if (anyYear) {
					const y = Number(anyYear[0]);
					if (hintDecade) {
						const d = Math.floor(y / 10) * 10;
						return { key: d, step: 10, label: String(d) };
					}
					if (hintCentury) {
						const c0 = Math.floor(y / 100) * 100;
						return { key: c0, step: 100, label: String(c0) };
					}
					return { key: y, step: 1, label: String(y) };
				}
			}
			return null;
		},

		frequencyChartZeroRowForTemporalLabel(label, multiple = false) {
			const row = { value: String(label), count: 0, freq: 0, n: 0 };
			if (multiple) {
				const queries = this.frequencyCompareQueries();
				row.queries = {};
				for (let i = 0; i < queries.length; i++) {
					const q = queries[i];
					row.queries[q] = { count: 0, pct: 0, ipm: 0 };
				}
			}
			return row;
		},

		frequencyChartFillTemporalGaps(rows, multiple = false) {
			const list = Array.isArray(rows) ? rows : [];
			if (!list.length) return list;
			const parsed = [];
			const passthrough = [];
			for (let i = 0; i < list.length; i++) {
				const row = list[i];
				const meta = this.frequencyChartTemporalMeta(this.frequencyRowRawValue(row));
				if (!meta) {
					passthrough.push(row);
					continue;
				}
				parsed.push({ row, key: meta.key, step: meta.step, label: meta.label, raw: this.frequencyRowRawValue(row) });
			}
			if (parsed.length < 2) return list;
			const stepCounts = {};
			parsed.forEach((p) => {
				stepCounts[p.step] = (stepCounts[p.step] || 0) + 1;
			});
			const bestStep = Number(
				Object.keys(stepCounts).sort((a, b) => Number(stepCounts[b]) - Number(stepCounts[a]))[0] || 0,
			);
			const step = Number.isFinite(bestStep) && bestStep > 0 ? bestStep : 1;
			const byKey = new Map();
			parsed.forEach((p) => byKey.set(p.key, p.row));
			const keys = parsed.map((p) => p.key).sort((a, b) => a - b);
			const min = keys[0];
			const max = keys[keys.length - 1];
			if (!Number.isFinite(min) || !Number.isFinite(max) || max <= min) return list;
			const useDecadeSuffix = step === 10 && parsed.some((p) => /\d{3,4}\s*s$/i.test(String(p.raw || '')));
			const filled = [];
			for (let k = min; k <= max; k += step) {
				if (byKey.has(k)) {
					filled.push(byKey.get(k));
					continue;
				}
				let label = String(k);
				if (step === 10) label = useDecadeSuffix ? `${k}s` : String(k);
				else if (step === 100) label = `${Math.floor(k / 100) + 1}th century`;
				filled.push(this.frequencyChartZeroRowForTemporalLabel(label, multiple));
			}
			return filled.concat(passthrough);
		},

		frequencyRomanNumeralToInt(label) {
			const raw = String(label || '').trim().toUpperCase();
			if (!raw) return null;
			// Accept pure Roman numerals or a leading Roman token (e.g. "XIX century").
			const m = raw.match(/^([MDCLXVI]+)(?:\b|$)/);
			if (!m) return null;
			const token = String(m[1] || '').trim();
			if (!token || !/^[MDCLXVI]+$/.test(token)) return null;
			const vals = { I: 1, V: 5, X: 10, L: 50, C: 100, D: 500, M: 1000 };
			let total = 0;
			for (let i = 0; i < token.length; i += 1) {
				const cur = vals[token[i]] || 0;
				const next = vals[token[i + 1]] || 0;
				if (cur < next) total -= cur;
				else total += cur;
			}
			if (!Number.isFinite(total) || total <= 0) return null;
			// Validate by canonical round-trip to avoid false positives on malformed numerals.
			const toRoman = (n) => {
				const parts = [
					[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'],
					[100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'],
					[10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I'],
				];
				let x = n;
				let out = '';
				for (let p = 0; p < parts.length; p += 1) {
					const v = parts[p][0];
					const s = parts[p][1];
					while (x >= v) {
						out += s;
						x -= v;
					}
				}
				return out;
			};
			return toRoman(total) === token ? total : null;
		},

		frequencyCompareValueLabels(aRaw, bRaw) {
			const a = String(aRaw == null ? '' : aRaw);
			const b = String(bRaw == null ? '' : bRaw);
			const ar = this.frequencyRomanNumeralToInt(a);
			const br = this.frequencyRomanNumeralToInt(b);
			if (ar !== null && br !== null) return ar - br;
			if (ar !== null) return -1;
			if (br !== null) return 1;
			return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
		},

		frequencyChartOrderedRows(rows, multiple = false) {
			const list = Array.isArray(rows) ? [...rows] : [];
			const mode = this.frequencyChartOrder || 'size';
			const sizeValue = (row) => (multiple ? this.frequencyChartRowCompareNumeric(row) : this.frequencyRowChartNumeric(row));
			if (mode === 'value') {
				list.sort((a, b) => this.frequencyCompareValueLabels(this.frequencyRowRawValue(a), this.frequencyRowRawValue(b)));
				return list;
			}
			if (mode === 'time' && this.frequencyCanUseTimeOrder()) {
				list.sort((a, b) => {
					const ta = this.frequencyChartParseTemporalOrder(this.frequencyRowRawValue(a));
					const tb = this.frequencyChartParseTemporalOrder(this.frequencyRowRawValue(b));
					if (ta !== null && tb !== null) return ta - tb;
					if (ta !== null) return -1;
					if (tb !== null) return 1;
					return this.frequencyCompareValueLabels(this.frequencyRowRawValue(a), this.frequencyRowRawValue(b));
				});
				return this.frequencyChartFillTemporalGaps(list, multiple);
			}
			list.sort((a, b) => sizeValue(b) - sizeValue(a));
			return list;
		},

		frequencyChartIsTimeOrder() {
			return (this.frequencyChartOrder || 'size') === 'time' && this.frequencyCanUseTimeOrder();
		},

		/**
		 * Charts: first N rows (N = frequency limit) in table order, then one Others slice at the end
		 * (not reordered by size). Others uses the same scale as primary slices.
		 */
		frequencyChartDataset() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				const labels = [];
				const rows = this.frequencyChartRowsBase();
				const orderedRows = this.frequencyChartOrderedRows(rows, true);
				const maxSlices = this.frequencyChartMaxSlices();
				const cap = this.frequencyChartIsTimeOrder()
					? orderedRows.length
					: Math.min(orderedRows.length, maxSlices);
				const slice = orderedRows.slice(0, cap);
				
				const datasets = queries.map((q, idx) => {
					const colors = [
						'rgba(54, 162, 235, 0.7)',
						'rgba(255, 99, 132, 0.7)',
						'rgba(75, 192, 192, 0.7)',
						'rgba(255, 206, 86, 0.7)',
						'rgba(153, 102, 255, 0.7)'
					];
					const borderColors = [
						'rgb(54, 162, 235)',
						'rgb(255, 99, 132)',
						'rgb(75, 192, 192)',
						'rgb(255, 206, 86)',
						'rgb(153, 102, 255)'
					];
					const color = colors[idx % colors.length];
					const borderColor = borderColors[idx % borderColors.length];
					return {
						label: q,
						data: [],
						backgroundColor: color,
						borderColor: borderColor,
						borderWidth: 1,
						fill: false
					};
				});

				for (let i = 0; i < slice.length; i++) {
					const row = slice[i];
					const rawLabel = this.frequencyRowValue(row);
					labels.push(this.truncateFrequencyChartLabel(rawLabel === '' ? '(empty)' : rawLabel));
					
					queries.forEach((q, qIdx) => {
						let val = 0;
						if (row.queries && row.queries[q]) {
							if (this.frequencyChartValueScale === 'relative') {
								val = this.frequencyRowQueryRelativeNumber(row, q);
							} else {
								val = Number(row.queries[q].count) || 0;
							}
						}
						datasets[qIdx].data.push(val);
					});
				}
				
				return { labels, data: [], datasets, multiple: true, rowRefs: [] };
			}

			const labels = [];
			const data = [];
			const rows = this.frequencyChartRowsBase();
			const orderedRows = this.frequencyChartOrderedRows(rows, false);
			const maxSlices = this.frequencyChartMaxSlices();
			const cap = this.frequencyChartIsTimeOrder()
				? orderedRows.length
				: Math.min(orderedRows.length, maxSlices);
			const slice = orderedRows.slice(0, cap);
			const rowRefs = [];
			let sumSlice = 0;
			for (let i = 0; i < slice.length; i++) {
				const row = slice[i];
				const v = this.frequencyRowChartNumeric(row);
				const num = Number(v);
				const use = Number.isFinite(num) && num >= 0 ? num : 0;
				sumSlice += use;
				const rawLabel = this.frequencyRowValue(row);
				const label = this.truncateFrequencyChartLabel(rawLabel === '' ? '(empty)' : rawLabel);
				// Keep zero points in chart arrays so timeline gaps remain visible in Time order.
				labels.push(label);
				data.push(use);
				rowRefs.push(row);
			}
			const totalEff = typeof this.frequencyEffectiveTotal === 'function' ? this.frequencyEffectiveTotal() : null;
			let others = 0;
			if (!this.frequencyChartIsTimeOrder()) {
				if (totalEff !== null && totalEff > 0) {
					others = this.frequencyChartOthersNumeric();
				} else {
					others = this.frequencyChartSumRowChartNumerics(orderedRows.slice(cap));
				}
			}
			if (others > 0) {
				labels.push('Others');
				data.push(others);
				rowRefs.push(null);
			}
			return { labels, data, rowRefs };
		},

		destroyFrequencyChart() {
			this._frequencyChartEpoch = (this._frequencyChartEpoch || 0) + 1;
			const ch = this.frequencyChartInstance;
			if (ch) {
				try {
					if (typeof ch.stop === 'function') ch.stop();
				} catch (_) {
					/* ignore */
				}
				try {
					if (typeof ch.destroy === 'function') ch.destroy();
				} catch (_) {
					/* ignore */
				}
			}
			this.frequencyChartInstance = null;
			
			// Hard-reset the canvas DOM node to prevent Chart.js from throwing
			// 'Cannot read properties of null (reading "save")' if it tries to 
			// complete a lingering requestAnimationFrame on an orphaned context.
			const oldCanvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (oldCanvas && oldCanvas.parentNode) {
				const newCanvas = document.createElement('canvas');
				newCanvas.id = 'flexicorp-freq-chart-canvas';
				newCanvas.setAttribute('aria-label', 'Frequency chart');
				oldCanvas.parentNode.replaceChild(newCanvas, oldCanvas);
			}
		},

		renderFrequencyChart() {
			const Chart = typeof window !== 'undefined' ? window.Chart : null;
			if (!Chart || !this.frequencyChartLibraryAvailable()) return;
			const mode = this.frequencyVizMode;
			if (mode === 'table') return;
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext || !canvas.isConnected) return;
			const { labels, data, datasets, multiple, rowRefs } = this.frequencyChartDataset();
			if (!labels.length) {
				this.destroyFrequencyChart();
				return;
			}
			this.destroyFrequencyChart();
			// destroyFrequencyChart replaces the canvas node; the prior `canvas` ref is detached.
			const canvasLive = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvasLive || !canvasLive.getContext || !canvasLive.isConnected) return;
			const ctx = canvasLive.getContext('2d');
			if (!ctx) return;
			const palette = this.frequencyChartPalette(labels.length);
			const scale = this.frequencyChartValueScale || 'relative';
			const yLabel = this.frequencyChartYLabel();
			const dsLabel = scale === 'absolute' ? 'Count' : yLabel;
			// Short animations (Chart default is ~1000ms). Lifecycle guards (stop/destroy, epoch, tab/backend teardown)
			// avoid the rare null-ctx crash when switching views; no need to disable motion entirely.
			const common = {
				responsive: true,
				maintainAspectRatio: false,
				animation: {
					duration: 750,
				},
			};
			/** Keep table order in the legend; always place Others last (not sorted by slice size). */
			const othersLastLegendSort = (a, b, data) => {
				const lab = data && data.labels ? data.labels : [];
				const aTxt = String((lab[a.index] != null ? lab[a.index] : a.text) || '').trim();
				const bTxt = String((lab[b.index] != null ? lab[b.index] : b.text) || '').trim();
				const aO = aTxt === 'Others';
				const bO = bTxt === 'Others';
				if (aO !== bO) return aO ? 1 : -1;
				return (a.index ?? 0) - (b.index ?? 0);
			};
			const pieLikeTooltip = {
				callbacks: {
					label: (ctx) => {
						let raw = ctx.raw;
						if (raw == null && ctx.parsed != null) {
							raw = typeof ctx.parsed === 'object' && ctx.parsed.r != null ? ctx.parsed.r : ctx.parsed;
						}
						const n = Number(raw);
						const tot = Array.isArray(data) ? data.reduce((a, b) => a + (Number(b) || 0), 0) : 0;
						const pct = tot > 0 && Number.isFinite(n) ? ((n / tot) * 100).toFixed(1) : '';
						if (scale === 'absolute') {
							return pct !== '' ? `count: ${n} (${pct}%)` : `count: ${n}`;
						}
						const main = `${ctx.dataset.label || yLabel}: ${this.frequencyFormatIpmDisplay(n)}`;
						return pct !== '' ? `${main} (${pct}% of chart)` : main;
					},
				},
			};
			const barTooltip = {
				callbacks: {
					label: (ctx) => {
						// Horizontal bar (indexAxis 'y'): value is on x; y is category index (often 0 for first row).
						// Vertical bar: value is on y.
						let v;
						if (mode === 'bar' || mode === 'stacked-bar') {
							v = ctx.parsed.x;
						} else if (mode === 'vbar' || mode === 'stacked-vbar') {
							v = ctx.parsed.y;
						} else if (mode === 'line') {
							v = ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed.x;
						} else if (mode === 'radar') {
							v = ctx.parsed.r != null ? ctx.parsed.r : ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed.x;
						} else {
							v = ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed.x;
						}
						if (typeof v !== 'number' || !Number.isFinite(v)) return '';
						let baseLabel = '';
						if (scale === 'absolute') baseLabel = `count: ${v}`;
						else baseLabel = `${ctx.dataset.label || yLabel}: ${this.frequencyFormatIpmDisplay(v)}`;
						if (mode === 'vbar-error' && !multiple && ctx.dataset) {
							const lo = Array.isArray(ctx.dataset.errorLower) ? Number(ctx.dataset.errorLower[ctx.dataIndex]) : NaN;
							const hi = Array.isArray(ctx.dataset.errorUpper) ? Number(ctx.dataset.errorUpper[ctx.dataIndex]) : NaN;
							if (Number.isFinite(lo) && Number.isFinite(hi) && hi >= lo) {
								if (scale === 'absolute') return `${baseLabel} (95% CI: ${lo.toFixed(1)} - ${hi.toFixed(1)})`;
								return `${baseLabel} (95% CI: ${lo.toFixed(2)} - ${hi.toFixed(2)})`;
							}
						}
						return baseLabel;
					},
				},
			};

			const chartDatasets = multiple ? datasets : [
				{
					// Single-series charts use the external scale toggle; keep internal legend label empty.
					label: '',
					data,
					backgroundColor: palette,
					borderColor: mode === 'vbar' ? palette.map(() => 'rgba(0,0,0,0.08)') : 'rgba(0,0,0,0.08)',
					borderWidth: 1,
				}
			];
			const showSeriesLegend = multiple && Array.isArray(chartDatasets) && chartDatasets.length > 1;
			if (mode === 'line' && !multiple) {
				chartDatasets[0].borderColor = 'rgb(54, 162, 235)';
				chartDatasets[0].backgroundColor = 'rgba(54, 162, 235, 0.12)';
				chartDatasets[0].borderWidth = 2;
				chartDatasets[0].fill = true;
				chartDatasets[0].tension = 0.25;
				chartDatasets[0].pointRadius = 3;
				chartDatasets[0].pointHoverRadius = 5;
			} else if (mode === 'polar' || mode === 'pie' || mode === 'doughnut') {
				if (multiple) {
					// Fallback for pie/doughnut/polar when multiple queries: 
					// we just display the first query to avoid messing up the UI.
					chartDatasets.splice(1);
				}
				if (!multiple) chartDatasets[0].borderColor = '#fff';
			}

			const errorBarPlugin = {
				id: 'flexicorpErrorBars',
				afterDatasetsDraw: (chart) => {
					if (mode !== 'vbar-error' || multiple) return;
					const ds = chart && chart.data && chart.data.datasets ? chart.data.datasets[0] : null;
					if (!ds || !Array.isArray(ds.errorLower) || !Array.isArray(ds.errorUpper)) return;
					const meta = chart.getDatasetMeta(0);
					if (!meta || !Array.isArray(meta.data)) return;
					const yScaleRef = chart.scales && chart.scales.y;
					if (!yScaleRef) return;
					const c = chart.ctx;
					c.save();
					c.strokeStyle = 'rgba(20, 20, 20, 0.85)';
					c.lineWidth = 1.2;
					for (let i = 0; i < meta.data.length; i += 1) {
						const el = meta.data[i];
						const lo = Number(ds.errorLower[i]);
						const hi = Number(ds.errorUpper[i]);
						if (!el || !Number.isFinite(lo) || !Number.isFinite(hi)) continue;
						const yLo = yScaleRef.getPixelForValue(lo);
						const yHi = yScaleRef.getPixelForValue(hi);
						const x = el.x;
						if (!Number.isFinite(x) || !Number.isFinite(yLo) || !Number.isFinite(yHi)) continue;
						const halfCap = Math.max(4, Math.min(10, (el.width || 18) * 0.25));
						c.beginPath();
						c.moveTo(x, yLo);
						c.lineTo(x, yHi);
						c.moveTo(x - halfCap, yLo);
						c.lineTo(x + halfCap, yLo);
						c.moveTo(x - halfCap, yHi);
						c.lineTo(x + halfCap, yHi);
						c.stroke();
					}
					c.restore();
				},
			};

			try {
			if (mode === 'bar' || mode === 'stacked-bar') {
				const stacked = mode === 'stacked-bar';
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'bar',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						indexAxis: 'y',
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							x: {
								stacked: stacked,
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
							y: {
								stacked: stacked,
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'vbar' || mode === 'stacked-vbar' || mode === 'vbar-error') {
				const stacked = mode === 'stacked-vbar';
				if (mode === 'vbar-error' && !multiple && Array.isArray(rowRefs)) {
					const lows = [];
					const highs = [];
					for (let i = 0; i < rowRefs.length; i += 1) {
						const b = rowRefs[i] ? this.frequencyRowUncertaintyBounds(rowRefs[i]) : null;
						lows.push(b ? b.low : null);
						highs.push(b ? b.high : null);
					}
					chartDatasets[0].errorLower = lows;
					chartDatasets[0].errorUpper = highs;
				}
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'bar',
					data: {
						labels,
						datasets: chartDatasets,
					},
					plugins: mode === 'vbar-error' ? [errorBarPlugin] : [],
					options: {
						...common,
						indexAxis: 'x',
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							x: {
								stacked: stacked,
								type: 'category',
								offset: true,
								ticks: {
									maxRotation: 55,
									minRotation: 35,
									autoSkip: true,
								},
							},
							y: {
								stacked: stacked,
								type: 'linear',
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'radar') {
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'radar',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							r: {
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'line') {
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'line',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							x: {
								ticks: {
									maxRotation: 55,
									minRotation: 35,
									autoSkip: true,
								},
							},
							y: {
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'polar') {
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'polarArea',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						layout: { padding: 8 },
						plugins: {
							legend: { display: false },
							tooltip: pieLikeTooltip,
						},
						scales: {
							r: {
								beginAtZero: true,
								ticks: { precision: 0 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			const ring = mode === 'doughnut';
			this.frequencyChartInstance = new Chart(ctx, {
				type: ring ? 'doughnut' : 'pie',
				data: {
					labels,
					datasets: chartDatasets,
				},
				options: {
					...common,
					layout: {
						padding: 8,
					},
					plugins: {
						legend: { display: false },
						tooltip: pieLikeTooltip,
					},
				},
			});
			this._resizeFrequencyChartAfterLayout();
			} catch (_) {
				this.destroyFrequencyChart();
			}
		},

		frequencyChartExportBasename() {
			const field = this.frequency && this.frequency.field ? String(this.frequency.field) : 'frequency';
			const safe = field.replace(/[^\w\-]+/g, '_').slice(0, 64) || 'frequency';
			const d = new Date();
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `flexicorp-freq-${safe}-${y}${m}${day}`;
		},

		triggerDownload(href, filename) {
			const a = document.createElement('a');
			a.href = href;
			a.download = filename;
			a.rel = 'noopener';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
		},

		downloadFrequencyChartPNG() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/png');
			this.triggerDownload(url, `${this.frequencyChartExportBasename()}.png`);
		},

		downloadFrequencyChartJPEG() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/jpeg', 0.92);
			this.triggerDownload(url, `${this.frequencyChartExportBasename()}.jpg`);
		},

		downloadFrequencyTableCSV() {
			const rows = this.frequencyDisplayRows();
			if (!rows.length) return;
			const fields = this.frequencyFieldsArray();
			const hasPct = this.frequencyHasProvidedPct();
			const hasNormIpm = this.frequencyHasNormalizedIpm();
			const hasRelSubIpm = this.frequencyHasRelativeSubcorpusIpm();
			const hasSize = this.frequencyHasProvidedSubcorpusSize();
			
			const headerCols = [...fields, 'Count'];
			if (hasPct) headerCols.push('%');
			if (hasNormIpm) headerCols.push('Normalized (IPM)');
			if (hasSize) headerCols.push('Subcorpus size');
			if (hasRelSubIpm) headerCols.push('Relative (Subcorpus IPM)');
			
			const escapeCsv = (str) => {
				const s = String(str || '');
				if (s.includes('"') || s.includes(',') || s.includes('\n') || s.includes('\r')) {
					return '"' + s.replace(/"/g, '""') + '"';
				}
				return s;
			};
			
			let csvLines = [headerCols.map(escapeCsv).join(',')];
			
			rows.forEach(row => {
				const cols = [...this.frequencyRowValuesArray(row), row.count || ''];
				if (hasPct) cols.push(this.frequencyRowPercent(row) || '');
				if (hasNormIpm) cols.push(this.frequencyRowNormalizedIpm(row) || '');
				if (hasSize) cols.push(this.frequencyRowSubcorpusSize(row) || '');
				if (hasRelSubIpm) cols.push(this.frequencyRowRelativeSubcorpusIpm(row) || '');
				csvLines.push(cols.map(escapeCsv).join(','));
			});
			
			if (this.frequencyHasRestBucket()) {
				const restCols = [];
				for (let i=0; i<fields.length; i++) restCols.push(i === 0 ? 'Others' : '');
				restCols.push(this.frequencyRestCount());
				if (hasPct) restCols.push(this.frequencyRestRowPercent());
				if (hasNormIpm) restCols.push(this.frequencyRestRowIpm());
				if (hasSize) restCols.push('');
				if (hasRelSubIpm) restCols.push('');
				csvLines.push(restCols.map(escapeCsv).join(','));
			}
			
			const csvContent = csvLines.join('\r\n');
			const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
			const url = URL.createObjectURL(blob);
			this.triggerDownload(url, `${this.frequencyChartExportBasename()}.csv`);
		},

		downloadFrequencyChartPrintForPdf() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const imgData = canvas.toDataURL('image/png', 1.0);
			const w = window.open('');
			if (!w) return;
			const title = 'Frequency chart — Save as PDF from print dialog';
			w.document.write(
				`<!DOCTYPE html><html><head><meta charset="utf-8"/><title>${title}</title></head><body style="margin:0;padding:16px;text-align:center;font-family:system-ui,sans-serif;"><p style="margin:0 0 12px 0;">Use <strong>Print</strong> (Ctrl+P / Cmd+P) and choose <strong>Save as PDF</strong>.</p><img src="${imgData}" alt="chart" style="max-width:100%;height:auto;border:1px solid #ddd"/></body></html>`
			);
			w.document.close();
		},

		loadJsPDF() {
			return new Promise((resolve, reject) => {
				if (typeof window !== 'undefined' && window.jspdf && window.jspdf.jsPDF) {
					return resolve(window.jspdf.jsPDF);
				}
				const existing = document.querySelector('script[data-flexicorp-jspdf]');
				if (existing) {
					existing.addEventListener('load', () => {
						if (window.jspdf && window.jspdf.jsPDF) resolve(window.jspdf.jsPDF);
						else reject(new Error('jspdf load failed'));
					});
					existing.addEventListener('error', () => reject(new Error('jspdf script error')));
					return;
				}
				const s = document.createElement('script');
				s.src = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js';
				s.crossOrigin = 'anonymous';
				s.defer = true;
				s.setAttribute('data-flexicorp-jspdf', '1');
				s.onload = () => {
					if (window.jspdf && window.jspdf.jsPDF) resolve(window.jspdf.jsPDF);
					else reject(new Error('jspdf missing'));
				};
				s.onerror = () => reject(new Error('jspdf script failed'));
				document.head.appendChild(s);
			});
		},

		async downloadFrequencyChartPDF() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const imgData = canvas.toDataURL('image/png', 1.0);
			const imgWpx = canvas.width;
			const imgHpx = canvas.height;
			if (imgWpx <= 0 || imgHpx <= 0) return;
			try {
				const JsPDF = await this.loadJsPDF();
				const pdf = new JsPDF({ orientation: imgWpx >= imgHpx ? 'l' : 'p', unit: 'mm', format: 'a4' });
				const pageW = pdf.internal.pageSize.getWidth();
				const pageH = pdf.internal.pageSize.getHeight();
				const margin = 12;
				const maxW = pageW - margin * 2;
				const maxH = pageH - margin * 2;
				const ratio = imgHpx / imgWpx;
				let drawW = maxW;
				let drawH = drawW * ratio;
				if (drawH > maxH) {
					drawH = maxH;
					drawW = drawH / ratio;
				}
				const x = margin + (maxW - drawW) / 2;
				const y = margin + (maxH - drawH) / 2;
				pdf.addImage(imgData, 'PNG', x, y, drawW, drawH);
				pdf.save(`${this.frequencyChartExportBasename()}.pdf`);
			} catch (_) {
				this.downloadFrequencyChartPrintForPdf();
			}
		},

		_resizeFrequencyChartAfterLayout() {
			const ch = this.frequencyChartInstance;
			if (!ch || typeof ch.resize !== 'function') return;
			const epoch = this._frequencyChartEpoch;
			requestAnimationFrame(() => {
				if (epoch !== this._frequencyChartEpoch || this.frequencyChartInstance !== ch) return;
				try {
					ch.resize();
				} catch (_) {
					/* ignore */
				}
			});
		},

		scheduleFrequencyChartRender() {
			if (!this.frequencyChartLibraryAvailable()) return;
			if (this.statsSubTab && this.statsSubTab !== 'freq') {
				this.destroyFrequencyChart();
				return;
			}
			if (this.frequencyVizMode === 'table' || this.frequencyVizMode === 'pivot') {
				this.destroyFrequencyChart();
				return;
			}
			// Double rAF: canvas wrapper often has 0 size until after Alpine/layout paints (avoids Chart.js draw on bad layout).
			const epoch = this._frequencyChartEpoch;
			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					if (epoch !== this._frequencyChartEpoch) return;
					if (!this.frequency || !this.frequency.ran || !this.frequency.rows || !this.frequency.rows.length) {
						this.destroyFrequencyChart();
						return;
					}
					if (this.statsSubTab && this.statsSubTab !== 'freq') return;
					if (this.frequencyVizMode === 'table' || this.frequencyVizMode === 'pivot') return;
					if (this.activeTab && this.activeTab !== 'frequency') return;
					this.renderFrequencyChart();
				});
			});
		},

		/** Secondary tabs inside the Stats (Quantitative analysis) panel. */
		setStatsSubTab(tab) {
			const wanted = String(tab || '').trim();
			const prev = String(this.statsSubTab || '').trim();
			if (wanted === 'queries') this.statsQueriesTabVisible = true;
			const dyn =
				typeof this.availableStatsModules === 'function'
					? this.availableStatsModules().map((m) => m.id)
					: [];
			const baseAllowed = ['freq', 'coll', 'other', 'corpus', 'queries'];
			if (!wanted || (!baseAllowed.includes(wanted) && !dyn.includes(wanted))) return;
			if (wanted !== 'corpus' && wanted !== 'queries') {
				if (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits()) return;
			}
			if (wanted === 'coll' && typeof this.statsCapabilityCollocations === 'function' && !this.statsCapabilityCollocations()) return;
			if (wanted === 'other' && typeof this.statsCapabilityOther === 'function' && !this.statsCapabilityOther()) return;
			this.statsSubTab = wanted;
			if (wanted === 'coll' && typeof this.collocationEnsureAnchorDefault === 'function') {
				this.collocationEnsureAnchorDefault();
			}
			if (wanted === 'advanced_dcoll' && typeof this.dcollAdvEnsureAnchorDefault === 'function') {
				this.dcollAdvEnsureAnchorDefault();
			}
			if (this.statsTabNeedsSingleScopeQuery(wanted)) {
				this.collocationEnterSingleSelectScopeMode();
			} else if (this.statsTabNeedsSingleScopeQuery(prev)) {
				this.collocationExitSingleSelectScopeMode();
			}
			if (wanted === 'corpus' && typeof this.fetchCorpusStatsInfo === 'function') {
				this.fetchCorpusStatsInfo(true);
			}
			const schedule = () => {
				if (wanted === 'freq') {
					if (typeof this.destroyCollocationChart === 'function') this.destroyCollocationChart();
					this.scheduleFrequencyChartRender();
				} else if (wanted === 'coll') {
					if (typeof this.destroyFrequencyChart === 'function') this.destroyFrequencyChart();
					if (typeof this.scheduleCollocationChartRender === 'function') this.scheduleCollocationChartRender();
				} else if (wanted === 'other') {
					if (typeof this.destroyFrequencyChart === 'function') this.destroyFrequencyChart();
					if (typeof this.destroyCollocationChart === 'function') this.destroyCollocationChart();
					if (typeof this.scheduleOtherChartRender === 'function') this.scheduleOtherChartRender();
				} else {
					if (typeof this.destroyFrequencyChart === 'function') this.destroyFrequencyChart();
					if (typeof this.destroyCollocationChart === 'function') this.destroyCollocationChart();
					if (typeof this.destroyOtherChart === 'function') this.destroyOtherChart();
				}
				if (wanted === 'maps' && typeof this.ensureMapsPointsData === 'function') this.ensureMapsPointsData();
				if (wanted === 'maps' && typeof this.ensureMapsRegionData === 'function') this.ensureMapsRegionData();
			};
			if (this.$nextTick) this.$nextTick(schedule);
			else setTimeout(schedule, 0);
		},

		ensureCorpusStatsLoaded() {
			if (this.activeTab && this.activeTab !== 'frequency') return;
			if (this.statsSubTab && this.statsSubTab !== 'corpus') return;
			const r = typeof this.corpusStatsInfoResult === 'function' ? this.corpusStatsInfoResult() : null;
			if (r && typeof r === 'object') {
				const hasCoreStats =
					Number.isFinite(Number(r.tokens_count ?? r.corpus_tokens ?? r.corpus_size ?? r.size ?? r.n_tokens))
					|| Number.isFinite(Number(r.doc_count ?? r.documents_count))
					|| (Array.isArray(r.pattributes) && r.pattributes.length > 0)
					|| (Array.isArray(r.native_pattributes) && r.native_pattributes.length > 0)
					|| (Array.isArray(r.structures) && r.structures.length > 0)
					|| (r.sattributes_by_region && typeof r.sattributes_by_region === 'object' && Object.keys(r.sattributes_by_region).length > 0);
				if (hasCoreStats) return;
			}
			if (typeof this.isLoading === 'function' && this.isLoading('backend')) return;
			if (this.corpusInfoRefreshInFlight) return;
			if (typeof this.fetchCorpusStatsInfo === 'function') {
				this.fetchCorpusStatsInfo();
			}
		},

		async fetchCorpusStatsInfo(force = false) {
			if (this.corpusInfoRefreshInFlight) return;
			if (!force && this.activeTab && this.activeTab !== 'frequency') return;
			if (!force && this.statsSubTab && this.statsSubTab !== 'corpus') return;
			if (typeof this.submitAjaxData !== 'function') return;
			this.corpusInfoRefreshInFlight = true;
			try {
				// Minimal corpus-info probe payload: avoid query/kwic/freq/coll fields entirely.
				const formData = new FormData();
				formData.set('action', this.action || 'flexicorp');
				formData.set('backend', (this.settings && this.settings.backend) ? String(this.settings.backend) : 'cqp');
				if (this.settings && this.settings.queryEngine) {
					formData.set('query_engine', String(this.settings.queryEngine));
				}
				if (this.settings && this.settings.queryLanguage) {
					formData.set('query_language', String(this.settings.queryLanguage));
				}
				if (this.settings && this.settings.corpusFormat) {
					formData.set('corpus_format', String(this.settings.corpusFormat));
				}
				if (this.backendOverrides && this.backendOverrides.blacklab_url) formData.set('blacklab_url', this.backendOverrides.blacklab_url);
				if (this.backendOverrides && this.backendOverrides.blacklab_corpus) formData.set('blacklab_corpus', this.backendOverrides.blacklab_corpus);
				if (this.backendOverrides && this.backendOverrides.blacklab_user) formData.set('blacklab_user', this.backendOverrides.blacklab_user);
				if (this.backendOverrides && this.backendOverrides.blacklab_password) formData.set('blacklab_password', this.backendOverrides.blacklab_password);
				if (this.backendOverrides && this.backendOverrides.blacklab_field) formData.set('blacklab_field', this.backendOverrides.blacklab_field);
				formData.set('active_tab', 'frequency');
				formData.set('run', 'info');
				formData.set('corpus_info_probe', '1');
				// Probe must refresh info/status only — never steal the active tab if the user
				// navigated away (e.g. Engines) while this request was in flight.
				await this.submitAjaxData(formData, 'backend', { ignoreActiveTab: true });
			} finally {
				this.corpusInfoRefreshInFlight = false;
			}
		},

		setFrequencyVizMode(mode) {
			const allowed = new Set(['table', 'bar', 'vbar', 'vbar-error', 'stacked-bar', 'stacked-vbar', 'line', 'radar', 'polar', 'pie', 'doughnut', 'pivot']);
			let next = allowed.has(mode) ? mode : 'table';
			if (this.frequencyHasCompareQueries() && (next === 'polar' || next === 'pie' || next === 'doughnut')) {
				next = 'bar';
			}
			if (next === 'vbar-error' && !this.frequencySupportsErrorBars()) {
				next = 'vbar';
			}
			if (next !== 'table' && next !== 'pivot' && !this.frequencyChartLibraryAvailable()) return;
			this.frequencyVizMode = next;
			if (next === 'table' || next === 'pivot') {
				this.destroyFrequencyChart();
			} else {
				this.scheduleFrequencyChartRender();
			}
		},

		onFrequencyStateApplied() {
			if (this.frequencyHasCompareQueries() && (this.frequencyVizMode === 'polar' || this.frequencyVizMode === 'pie' || this.frequencyVizMode === 'doughnut')) {
				this.frequencyVizMode = 'bar';
			}
			if (this.frequencyVizMode === 'vbar-error' && !this.frequencySupportsErrorBars()) {
				this.frequencyVizMode = 'vbar';
			}
			if (this.frequencySupportsRelativeScale()) {
				// Default to relative whenever the backend provides relative/subcorpus metrics.
				this.frequencyChartValueScale = 'relative';
			} else if (this.frequencyChartValueScale === 'relative') {
				this.frequencyChartValueScale = 'absolute';
			}
			this.normalizeFrequencyCompareMetricCols();
			if (this.frequencyVizMode && this.frequencyVizMode !== 'table') {
				this.scheduleFrequencyChartRender();
			}
		},

		frequencyRows() {
			return Array.isArray(this.frequency && this.frequency.rows) ? this.frequency.rows : [];
		},

		setFrequencyTableSort(col) {
			if (this.frequencyTableSort.col === col) {
				this.frequencyTableSort.asc = !this.frequencyTableSort.asc;
			} else {
				this.frequencyTableSort.col = col;
				this.frequencyTableSort.asc = (col === 'value');
			}
		},

		frequencyCompareMetricOptions() {
			return [
				{ key: 'count', label: 'Raw', available: true },
				{ key: 'pct', label: '%', available: this.frequencyHasProvidedPct() },
				{ key: 'relf', label: 'RelFreq %', available: this.frequencyHasDerivedRelfreq() },
				{ key: 'nipm', label: 'Normalized IPM', available: this.frequencyHasNormalizedIpm() },
				{ key: 'ripm', label: 'Relative Subcorpus IPM', available: this.frequencyHasRelativeSubcorpusIpm() },
			];
		},

		normalizeFrequencyCompareMetricCols() {
			const available = this.frequencyCompareMetricOptions().filter((o) => o.available).map((o) => o.key);
			if (!available.length) {
				this.frequencyCompareMetricCols = ['count'];
				return;
			}
			const cur = Array.isArray(this.frequencyCompareMetricCols) ? this.frequencyCompareMetricCols : [];
			let next = cur.filter((k) => available.includes(k));
			if (!next.length) {
				next = available.includes('pct') ? ['pct'] : (available.includes('relf') ? ['relf'] : [available[0]]);
			}
			this.frequencyCompareMetricCols = next;
		},

		isFrequencyCompareMetricSelected(key) {
			const k = String(key || '');
			return Array.isArray(this.frequencyCompareMetricCols) && this.frequencyCompareMetricCols.includes(k);
		},

		onFrequencyCompareMetricToggle(key, checked) {
			const k = String(key || '');
			if (!k) return;
			const cur = Array.isArray(this.frequencyCompareMetricCols) ? [...this.frequencyCompareMetricCols] : [];
			const has = cur.includes(k);
			if (checked && !has) cur.push(k);
			if (!checked && has) {
				if (cur.length <= 1) return;
				cur.splice(cur.indexOf(k), 1);
			}
			this.frequencyCompareMetricCols = cur;
		},

		frequencyCompareMetricVisible(key) {
			if (!this.frequencyHasCompareQueries()) return false;
			const k = String(key || '');
			const available = this.frequencyCompareMetricOptions().some((o) => o.key === k && o.available);
			return available && this.isFrequencyCompareMetricSelected(k);
		},

		frequencyHasCompareQueries() {
			const r = this.frequencyResult();
			return Array.isArray(r.compare_queries) && r.compare_queries.length > 0;
		},

		frequencyCompareQueries() {
			return this.frequencyHasCompareQueries() ? this.frequencyResult().compare_queries : [];
		},

		frequencyRowQueryCount(row, q) {
			if (!row || !row.queries || !row.queries[q]) return 0;
			return Number(row.queries[q].count) || 0;
		},

		frequencyRowQueryPct(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const n = Number(row.queries[q].pct);
			return Number.isFinite(n) ? n.toFixed(2) : '';
		},

		frequencyRowQueryIpm(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const cell = row.queries[q] || {};
			const n = this.frequencyRowQueryIpmNumber(row, q);
			const c = Number(cell.count);
			return this.frequencyFormatIpmDisplay(n, { count: Number.isFinite(c) && c >= 0 ? c : null });
		},

		frequencyRowQueryIpmNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const n = Number(row.queries[q].ipm);
			return Number.isFinite(n) ? n : null;
		},

		frequencyRowQueryNormalizedIpm(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row.queries[q].count);
			const n = this.frequencyRowQueryNormalizedIpmNumber(row, q);
			if (Number.isFinite(n)) return this.frequencyFormatIpmDisplay(n, { count: Number.isFinite(c) && c >= 0 ? c : null, base: ct });
			return '';
		},

		frequencyRowQueryNormalizedIpmNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row.queries[q].count);
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) return (c / ct) * 1000000;
			return null;
		},

		frequencyRowQueryRelativeSubcorpusIpm(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const cell = row.queries[q] || {};
			const n = this.frequencyRowQueryRelativeSubcorpusIpmNumber(row, q);
			const c = Number(cell.count);
			const baseRaw = cell.subcorpus_size ?? cell.q_size ?? cell.query_size ?? null;
			const base = Number(baseRaw);
			return this.frequencyFormatIpmDisplay(n, {
				count: Number.isFinite(c) && c >= 0 ? c : null,
				base: Number.isFinite(base) && base > 0 ? base : null,
			});
		},

		frequencyRowQueryRelativeSubcorpusIpmNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const cell = row.queries[q] || {};
			const raw = cell.q_ipm ?? cell.subcorpus_ipm ?? cell.relative_subcorpus_ipm ?? cell.ipm;
			const n = Number(raw);
			return Number.isFinite(n) ? n : null;
		},

		frequencyCompareTotalsPerQuery() {
			const r = this.frequencyResult();
			if (!r || typeof r !== 'object') return {};
			const src = r.totals_per_query && typeof r.totals_per_query === 'object'
				? r.totals_per_query
				: (r.table && r.table.totals_per_query && typeof r.table.totals_per_query === 'object'
					? r.table.totals_per_query
					: {});
			const out = {};
			Object.keys(src).forEach((k) => {
				const n = Number(src[k]);
				if (Number.isFinite(n) && n > 0) out[String(k)] = n;
			});
			return out;
		},

		frequencyHasDerivedRelfreq() {
			if (!this.frequencyHasCompareQueries()) return false;
			const totals = this.frequencyCompareTotalsPerQuery();
			const qs = this.frequencyCompareQueries();
			if (!qs.some((q) => Number(totals[q]) > 0)) return false;
			return this.frequencyMetricSampleRows().some((row) => {
				if (!row || !row.queries || typeof row.queries !== 'object') return false;
				return qs.some((q) => {
					const c = row.queries[q] && row.queries[q].count;
					return c !== null && c !== undefined && c !== '' && Number.isFinite(Number(c));
				});
			});
		},

		frequencyRowQueryDerivedRelfreqNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const c = Number(row.queries[q].count);
			const totals = this.frequencyCompareTotalsPerQuery();
			const base = Number(totals[q]);
			if (!Number.isFinite(c) || c < 0 || !Number.isFinite(base) || base <= 0) return null;
			return (c / base) * 100;
		},

		frequencyRowQueryDerivedRelfreq(row, q) {
			const n = this.frequencyRowQueryDerivedRelfreqNumber(row, q);
			return n === null ? '' : n.toFixed(2);
		},

		frequencyRowQueryRelativeNumber(row, q) {
			const cell = row && row.queries && row.queries[q] ? row.queries[q] : null;
			if (!cell) return 0;
			let v = Number(cell.q_ipm ?? cell.subcorpus_ipm ?? cell.relative_subcorpus_ipm);
			if (Number.isFinite(v)) return v;
			v = Number(cell.ipm);
			if (Number.isFinite(v)) return v;
			v = Number(cell.pct);
			if (Number.isFinite(v)) return v;
			const relf = this.frequencyRowQueryDerivedRelfreqNumber(row, q);
			if (relf !== null) return relf;
			return 0;
		},

		frequencyDisplayRows() {
			let rows = this.frequencyRows();
			const q = (this.frequencyTableSearch || '').trim().toLowerCase();
			if (q) {
				rows = rows.filter(r => this.frequencyRowRawValue(r).toLowerCase().includes(q));
			}
			const sortCol = this.frequencyTableSort.col;
			if (sortCol) {
				const asc = this.frequencyTableSort.asc ? 1 : -1;
				rows = [...rows].sort((a, b) => {
					if (sortCol === 'value') {
						const cmp = this.frequencyCompareValueLabels(this.frequencyRowRawValue(a), this.frequencyRowRawValue(b));
						return cmp * asc;
					}
					let va = 0, vb = 0;
					if (sortCol === 'count') {
						va = this.frequencyRowCount(a);
						vb = this.frequencyRowCount(b);
					} else if (sortCol === 'pct') {
						va = this.frequencyRowPctNumber(a);
						vb = this.frequencyRowPctNumber(b);
					} else if (sortCol === 'ipm') {
						va = this.frequencyRowIpmNumber(a);
						vb = this.frequencyRowIpmNumber(b);
					} else if (sortCol === 'nipm') {
						va = this.frequencyRowNormalizedIpmNumber(a);
						vb = this.frequencyRowNormalizedIpmNumber(b);
					} else if (sortCol === 'ripm') {
						va = this.frequencyRowRelativeSubcorpusIpmNumber(a);
						vb = this.frequencyRowRelativeSubcorpusIpmNumber(b);
					} else if (sortCol.startsWith('count_')) {
						const qname = sortCol.substring(6);
						va = this.frequencyRowQueryCount(a, qname);
						vb = this.frequencyRowQueryCount(b, qname);
					} else if (sortCol.startsWith('pct_')) {
						const qname = sortCol.substring(4);
						va = Number(this.frequencyRowQueryPct(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryPct(b, qname)) || 0;
					} else if (sortCol.startsWith('relf_')) {
						const qname = sortCol.substring(5);
						va = Number(this.frequencyRowQueryDerivedRelfreqNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryDerivedRelfreqNumber(b, qname)) || 0;
					} else if (sortCol.startsWith('ipm_')) {
						const qname = sortCol.substring(4);
						va = Number(this.frequencyRowQueryIpmNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryIpmNumber(b, qname)) || 0;
					} else if (sortCol.startsWith('nipm_')) {
						const qname = sortCol.substring(5);
						va = Number(this.frequencyRowQueryNormalizedIpmNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryNormalizedIpmNumber(b, qname)) || 0;
					} else if (sortCol.startsWith('ripm_')) {
						const qname = sortCol.substring(5);
						va = Number(this.frequencyRowQueryRelativeSubcorpusIpmNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryRelativeSubcorpusIpmNumber(b, qname)) || 0;
					}
					return (va - vb) * asc;
				});
			}
			return rows;
		},

		frequencyPivotData() {
			const rows = this.frequencyDisplayRows();
			const rowKeys = new Set();
			const colKeys = new Set();
			const cells = {};
			let maxVal = 0;

			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				queries.forEach(q => colKeys.add(q));
				rows.forEach(r => {
					const rowName = this.frequencyRowValue(r) || '(empty)';
					rowKeys.add(rowName);
					if (!cells[rowName]) cells[rowName] = {};
					queries.forEach(q => {
						let val = 0;
						if (r.queries && r.queries[q]) {
							if (this.frequencyChartValueScale === 'relative') {
								val = this.frequencyRowQueryRelativeNumber(r, q);
							} else {
								val = Number(r.queries[q].count) || 0;
							}
						}
						cells[rowName][q] = val;
						if (val > maxVal) maxVal = val;
					});
				});
			} else {
				rows.forEach(r => {
					const parts = this.frequencyRowValuesArray(r);
					const rowName = parts[0] || '(empty)';
					const colName = parts.slice(1).join(' · ') || '(empty)';
					rowKeys.add(rowName);
					colKeys.add(colName);
					
					const val = this.frequencyRowChartNumeric(r);
					if (!cells[rowName]) cells[rowName] = {};
					cells[rowName][colName] = val;
					if (val > maxVal) maxVal = val;
				});
			}
			
			return {
				rows: Array.from(rowKeys).sort(),
				cols: Array.from(colKeys).sort(),
				cells,
				maxVal
			};
		},

		frequencyPivotColumns() {
			return this.frequencyPivotData().cols;
		},

		frequencyPivotRows() {
			return this.frequencyPivotData().rows;
		},

		frequencyPivotCellValue(r, c) {
			const data = this.frequencyPivotData();
			const val = data.cells[r] && data.cells[r][c];
			if (val == null) return '';
			
			// Format depending on scale
			if (this.frequencyChartValueScale === 'absolute') return Math.round(val);
			// Relative scale can be IPM or percentage; prefer shared IPM precision formatter.
			if (this.frequencyUsesSubcorpusIpm() || this.frequencyHasBackendProvidedIpm()) {
				return this.frequencyFormatIpmDisplay(val);
			}
			return Number.isInteger(val) ? val : Number(val).toFixed(2);
		},

		frequencyPivotCellStyle(r, c) {
			const data = this.frequencyPivotData();
			const val = data.cells[r] && data.cells[r][c];
			if (!val || data.maxVal <= 0) return '';
			
			// Simple heatmap calculation (white to blue)
			const intensity = Math.min(1, Math.max(0, val / data.maxVal));
			// Use an RGBA blue background
			return `background-color: rgba(0, 123, 255, ${intensity * 0.7}); color: ${intensity > 0.5 ? '#fff' : 'inherit'};`;
		},

		frequencyMetricSampleRows() {
			const f = this.frequency;
			if (!f) return [];
			if (Array.isArray(f.rows) && f.rows.length) return f.rows;
			const rr = f.response && f.response.result;
			if (rr && rr.table && Array.isArray(rr.table.rows) && rr.table.rows.length) return rr.table.rows;
			if (rr && Array.isArray(rr.rows) && rr.rows.length) return rr.rows;
			if (rr && Array.isArray(rr.items) && rr.items.length) return rr.items;
			return [];
		},

		frequencyResult() {
			return this.frequency && this.frequency.response && this.frequency.response.result && typeof this.frequency.response.result === 'object'
				? this.frequency.response.result
				: {};
		},

		frequencyRowRawValue(row) {
			if (!row || typeof row !== 'object') return '';
			const pick = (v) => {
				if (v === null || v === undefined) return '';
				const s = String(v);
				return s.trim() === '' ? '' : s;
			};
			return pick(row.value) || pick(row.label) || pick(row.key) || '';
		},

		frequencyRowValue(row) {
			const val = this.frequencyRowRawValue(row);
			return val.replace(/\t/g, ' · ');
		},

		frequencyFieldGuessLabel(k) {
			const s = String(k);
			const expr = s.match(/^\s*([a-z_][a-z0-9_]*)\s*\((.+)\)\s*$/i);
			if (expr) {
				const fn = String(expr[1] || '').toLowerCase();
				if (fn === 'decade') return 'Decade';
				if (fn === 'century') return 'Century';
				return fn ? fn.charAt(0).toUpperCase() + fn.slice(1) : s;
			}
			if (/(_|^)century$/i.test(s)) return 'Century';
			if (/(_|^)year$/i.test(s)) return 'Year';
			if (/(_|^)decade$/i.test(s)) return 'Decade';
			if (s.indexOf('_') < 0) return s;
			const parts = s.split('_');
			return parts[0] + ' · ' + parts.slice(1).join(' · ');
		},

		frequencyFieldPrettyLabel(key, labels = null) {
			const map = labels && typeof labels === 'object' ? labels : {};
			const k = String(key || '').trim();
			if (!k) return '';
			const fromMap = map[k];
			if (fromMap != null && String(fromMap).trim() !== '') return String(fromMap).trim();
			// Canonicalize known derived / temporal field names first.
			const guessed = this.frequencyFieldGuessLabel(k);
			if (guessed && guessed !== k) return guessed;
			return k;
		},

		frequencyFieldsArray() {
			const f = this.frequency;
			const labels = (f && f.fieldLabels && typeof f.fieldLabels === 'object') ? f.fieldLabels : {};
			if (f && Array.isArray(f.fields) && f.fields.length > 0) {
				return f.fields.map((k) => this.frequencyFieldPrettyLabel(k, labels));
			}
			const single = f && f.field ? f.field : 'Value';
			return [single === 'Value' ? single : this.frequencyFieldPrettyLabel(single, labels)];
		},

		frequencyRowValuesArray(row) {
			const val = this.frequencyRowRawValue(row);
			const parts = val.split('\t');
			const len = this.frequencyFieldsArray().length;
			while (parts.length < len) parts.push('');
			return parts.slice(0, len);
		},

		frequencyRowCount(row) {
			if (!row || typeof row !== 'object') return 0;
			const raw = row.count ?? row.freq ?? row.n ?? 0;
			const n = Number(raw);
			return Number.isFinite(n) && n > 0 ? n : 0;
		},

		frequencyRowsTotal() {
			return this.frequencyRows().reduce((sum, row) => sum + this.frequencyRowCount(row), 0);
		},

		/** Grand total for the distribution (matches tt_flexicorp_freq_total + nested result fields). */
		frequencyEffectiveTotal() {
			const tryNum = (v) => {
				const n = Number(v);
				return Number.isFinite(n) && n > 0 ? n : null;
			};
			const f = this.frequency;
			let t = tryNum(f && f.total);
			if (t !== null) return t;
			const r = f && f.response && f.response.result;
			if (!r || typeof r !== 'object') return null;
			const keys = [
				'total',
				'total_matches',
				'total_count',
				'n_total',
				'match_total',
				'hits_total',
				'total_tokens',
				'token_total',
				'freq_total',
				'sum',
				'sum_count',
			];
			for (let i = 0; i < keys.length; i++) {
				t = tryNum(r[keys[i]]);
				if (t !== null) return t;
			}
			if (r.table && typeof r.table === 'object') {
				for (let i = 0; i < keys.length; i++) {
					t = tryNum(r.table[keys[i]]);
					if (t !== null) return t;
				}
			}
			return null;
		},

		frequencyRelativeBase() {
			const total = this.frequencyEffectiveTotal();
			if (total !== null && total > 0) return total;
			const rowsTotal = this.frequencyRowsTotal();
			if (rowsTotal > 0) return rowsTotal;
			return null;
		},

		/** Corpus token count for IPM when backend omits per-row ipm (aligned with flexicorp.js + flexicorp.php augment). */
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

		frequencyRestCount() {
			const total = this.frequencyEffectiveTotal();
			if (total === null || total <= 0) return 0;
			const shown = this.frequencyRowsTotal();
			if (!Number.isFinite(shown) || shown < 0) return 0;
			const rest = total - shown;
			if (rest <= 0) return 0;
			return Math.max(0, Math.round(rest));
		},

		frequencyHasRestBucket() {
			return this.frequencyRestCount() > 0;
		},

		frequencyRestRowPercent() {
			const rest = this.frequencyRestCount();
			if (rest <= 0) return '';
			const total = this.frequencyEffectiveTotal();
			if (total !== null && total > 0) {
				return ((rest / total) * 100).toFixed(2);
			}
			return '';
		},

		frequencyRestRowIpm() {
			const rest = this.frequencyRestCount();
			if (rest <= 0) return '';
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct !== null && ct > 0) {
				return ((rest / ct) * 1000000).toFixed(2);
			}
			return '';
		},

		isSattributeFrequencyField() {
			const field = String((this.frequency && this.frequency.field) || '').trim();
			return field.includes('_');
		},

		frequencyHasProvidedPct() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => {
					return this.frequencyMetricSampleRows().some(row => {
						const v = row && row.queries && row.queries[q] && (row.queries[q].pct ?? row.queries[q].percent ?? row.queries[q].percentage);
						return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
					});
				});
			}

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

		frequencyHasBackendProvidedPct() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => this.frequencyMetricSampleRows().some(row => {
					const v = row && row.queries && row.queries[q] && (row.queries[q].pct ?? row.queries[q].percent ?? row.queries[q].percentage);
					return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
				}));
			}
			return this.frequencyMetricSampleRows().some((row) => {
				const v = row && (row.pct ?? row.percent ?? row.percentage);
				return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
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

		frequencyHasProvidedIpm() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => {
					return this.frequencyMetricSampleRows().some(row => {
						const v = row && row.queries && row.queries[q] && (row.queries[q].q_ipm ?? row.queries[q].subcorpus_ipm ?? row.queries[q].relative_subcorpus_ipm ?? row.queries[q].ipm ?? row.queries[q].IPM ?? row.queries[q].relfreq ?? row.queries[q].relative ?? row.queries[q].relative_ipm);
						if (v === null || v === undefined || v === '') return false;
						const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
						return Number.isFinite(n);
					});
				});
			}

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

		frequencyHasNormalizedIpm() {
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct === null || ct <= 0) return false;
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return this.frequencyMetricSampleRows().some((row) => {
					if (!row || !row.queries || typeof row.queries !== 'object') return false;
					return queries.some((q) => {
						const c = row.queries[q] && row.queries[q].count;
						return c !== null && c !== undefined && c !== '' && Number.isFinite(Number(c));
					});
				});
			}
			return this.frequencyMetricSampleRows().some((row) => {
				const c = row && (row.count ?? row.freq ?? row.n);
				return c !== null && c !== undefined && c !== '' && Number.isFinite(Number(c));
			});
		},

		frequencyHasRelativeSubcorpusIpm() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return this.frequencyMetricSampleRows().some((row) => {
					if (!row || !row.queries || typeof row.queries !== 'object') return false;
					return queries.some((q) => {
						const cell = row.queries[q];
						if (!cell || typeof cell !== 'object') return false;
						const v = cell.q_ipm ?? cell.subcorpus_ipm ?? cell.relative_subcorpus_ipm;
						return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
					});
				});
			}
			if (!this.frequencyUsesSubcorpusIpm()) return false;
			if (!this.frequencyHasBackendProvidedIpm()) return false;
			return this.frequencyHasProvidedSubcorpusSize();
		},

		frequencyHasBackendProvidedIpm() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => this.frequencyMetricSampleRows().some(row => {
					const v = row && row.queries && row.queries[q] && (row.queries[q].q_ipm ?? row.queries[q].subcorpus_ipm ?? row.queries[q].relative_subcorpus_ipm ?? row.queries[q].ipm ?? row.queries[q].IPM ?? row.queries[q].relfreq ?? row.queries[q].relative ?? row.queries[q].relative_ipm);
					if (v === null || v === undefined || v === '') return false;
					const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
					return Number.isFinite(n);
				}));
			}
			return this.frequencyMetricSampleRows().some((row) => {
				const v = row && (row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm);
				if (v === null || v === undefined || v === '') return false;
				const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
				return Number.isFinite(n);
			});
		},

		frequencySupportsRelativeScale() {
			return this.frequencyHasBackendProvidedPct() || this.frequencyHasBackendProvidedIpm() || this.frequencyHasDerivedRelfreq();
		},

		frequencyRowIpm(row) {
			if (!row || typeof row !== 'object') return '';
			const raw = row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm;
			const c = Number(row.count ?? row.freq ?? row.n);
			if (raw !== null && raw !== undefined && raw !== '') {
				const n = typeof raw === 'number' ? raw : Number(String(raw).replace(/,/g, ''));
				if (Number.isFinite(n)) {
					return this.frequencyFormatIpmDisplay(n, { count: Number.isFinite(c) && c >= 0 ? c : null });
				}
			}
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) {
				return this.frequencyFormatIpmDisplay((c / ct) * 1000000, { count: c, base: ct });
			}
			return '';
		},

		frequencyFormatIpmDisplay(value, opts = null) {
			if (typeof this.formatIpmDisplay === 'function') {
				return this.formatIpmDisplay(value, opts);
			}
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

		frequencyRowNormalizedIpm(row) {
			const n = this.frequencyRowNormalizedIpmNumber(row);
			return n === null ? '' : this.frequencyFormatIpmDisplay(n);
		},

		frequencyRowRelativeSubcorpusIpm(row) {
			const n = this.frequencyRowRelativeSubcorpusIpmNumber(row);
			return n === null ? '' : this.frequencyFormatIpmDisplay(n);
		},

		frequencyUsesSubcorpusIpm() {
			const r = this.frequencyResult();
			return !!(r && r.per_subcorpus_ipm);
		},

		frequencyHasProvidedSubcorpusSize() {
			return this.frequencyMetricSampleRows().some((row) => {
				const v = row && row.subcorpus_size;
				if (v === null || v === undefined || v === '') return false;
				return Number.isFinite(Number(v));
			});
		},

		frequencyRowSubcorpusSize(row) {
			const n = Number(row && row.subcorpus_size);
			if (!Number.isFinite(n)) return '';
			return String(Math.round(n));
		},

		collocationResult() {
			const c = this.collocation;
			if (!c || !c.response) return null;
			let r = c.response.result || null;
			for (let i = 0; i < 4 && r && typeof r === 'object'; i += 1) {
				if (Array.isArray(r.collocates) || Array.isArray(r.rows) || (r.table && Array.isArray(r.table.rows))) {
					return r;
				}
				if (r.result && typeof r.result === 'object') {
					r = r.result;
					continue;
				}
				break;
			}
			return r && typeof r === 'object' ? r : null;
		},

		collocationMeasureKeys() {
			// Table columns should follow the user's selected measure list.
			// Backend payload shape may only expose a primary/default measure, so
			// result-driven detection alone can incorrectly pin the table to logdice.
			const selected =
				typeof this.normalizeCollocationMeasureKeys === 'function'
					? this.normalizeCollocationMeasureKeys(this.collocation && this.collocation.measureKeys)
					: (this.collocation && Array.isArray(this.collocation.measureKeys) ? this.collocation.measureKeys : []);
			if (Array.isArray(selected) && selected.length) return selected.map((m) => String(m));
			const r = this.collocationResult();
			if (r && Array.isArray(r.measures) && r.measures.length) return r.measures.map((m) => String(m));
			const row0 = this.collocationRows()[0];
			if (!row0 || typeof row0 !== 'object') return [];
			const skip = new Set(['word', 'obs', 'freq']);
			return Object.keys(row0).filter((k) => !skip.has(k));
		},

		collocationMetaLine() {
			const c = this.collocation || {};
			const r = this.collocationResult();
			const attr = (r && r.attribute) || c.field || '';
			const w = r && Array.isArray(r.window) && r.window.length >= 2 ? r.window : [c.left, c.right];
			const m = c.matches != null ? c.matches : r && r.matches;
			let s = 'attribute=' + attr + ' · window L' + w[0] + '/R' + w[1];
			if (m != null && m !== '') s += ' · matches ' + m;
			if (this.collocationHasMultipleNamedQueryBases()) {
				const eff = this.collocationEffectiveNamedQueryId();
				if (eff) s += ' · base ' + eff;
			}
			return s;
		},

		collocationRowsObsTotal() {
			const rows = this.collocationRows();
			return rows.reduce((sum, row) => {
				const n = Number(row && row.obs);
				return sum + (Number.isFinite(n) && n > 0 ? n : 0);
			}, 0);
		},

		collocationEffectiveObsTotal() {
			const r = this.collocationResult();
			if (!r || typeof r !== 'object') return null;
			const keys = [
				'total_obs',
				'obs_total',
				'sum_obs',
				'window_total',
				'total',
				'total_count',
			];
			for (let i = 0; i < keys.length; i += 1) {
				const n = Number(r[keys[i]]);
				if (Number.isFinite(n) && n > 0) return n;
			}
			if (r.table && typeof r.table === 'object') {
				for (let i = 0; i < keys.length; i += 1) {
					const n = Number(r.table[keys[i]]);
					if (Number.isFinite(n) && n > 0) return n;
				}
			}
			return null;
		},

		collocationRestObsCount() {
			const total = this.collocationEffectiveObsTotal();
			if (total === null || total <= 0) return 0;
			const shown = this.collocationRowsObsTotal();
			const rest = total - shown;
			if (!Number.isFinite(rest) || rest <= 0) return 0;
			return Math.round(rest);
		},

		collocationHasRestBucket() {
			return this.collocationRestObsCount() > 0;
		},

		collocationChartMetricOptions() {
			const opts = [{ key: 'obs', label: 'Obs' }];
			const seen = new Set(['obs']);
			const keys = this.collocationMeasureKeys();
			for (let i = 0; i < keys.length; i += 1) {
				const k = String(keys[i] || '').trim();
				if (!k || seen.has(k)) continue;
				seen.add(k);
				opts.push({ key: k, label: k });
			}
			return opts;
		},

		collocationEnsureChartMetricSelection() {
			const opts = this.collocationChartMetricOptions();
			const valid = new Set(opts.map((o) => String(o.key)));
			const cur = String(this.collocationChartMetricKey || '').trim();
			if (cur && valid.has(cur)) return cur;
			const preferred = opts.find((o) => o.key !== 'obs');
			const next = preferred ? preferred.key : 'obs';
			this.collocationChartMetricKey = next;
			return next;
		},

		onCollocationChartMetricChanged() {
			this.collocationEnsureChartMetricSelection();
			this.scheduleCollocationChartRender();
		},

		/** Column used for charts: chosen metric, or first available measure, else obs. */
		collocationChartValueKey() {
			return this.collocationEnsureChartMetricSelection();
		},

		collocationChartValueLabel() {
			return this.collocationChartValueKey();
		},

		collocationChartDataset() {
			const rows = this.collocationRows();
			const key = this.collocationChartValueKey();
			const labels = [];
			const data = [];
			for (let i = 0; i < rows.length; i++) {
				const row = rows[i];
				const w = row && row.word != null ? String(row.word) : '';
				const label = this.truncateFrequencyChartLabel(w === '' ? '(empty)' : w);
				const raw = row && row[key];
				const n = Number(raw);
				const v = Number.isFinite(n) ? n : 0;
				labels.push(label);
				data.push(v);
			}
			return { labels, data, valueKey: key };
		},

		destroyCollocationChart() {
			this._collocationChartEpoch = (this._collocationChartEpoch || 0) + 1;
			const ch = this.collocationChartInstance;
			if (ch) {
				try {
					if (typeof ch.stop === 'function') ch.stop();
				} catch (_) {
					/* ignore */
				}
				try {
					if (typeof ch.destroy === 'function') ch.destroy();
				} catch (_) {
					/* ignore */
				}
			}
			this.collocationChartInstance = null;
		},

		renderCollocationChart() {
			const Chart = typeof window !== 'undefined' ? window.Chart : null;
			if (!Chart || !this.frequencyChartLibraryAvailable()) return;
			const mode = this.collocationVizMode;
			if (mode === 'table') return;
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext || !canvas.isConnected) return;
			const { labels, data } = this.collocationChartDataset();
			if (!labels.length) {
				this.destroyCollocationChart();
				return;
			}
			const valueLabel = this.collocationChartValueLabel();
			const pieLike = mode === 'polar' || mode === 'pie' || mode === 'doughnut';
			const dataPlot = pieLike ? data.map((v) => Math.max(0, Number(v))) : data;
			if (pieLike && !dataPlot.some((x) => Number(x) > 0)) {
				this.destroyCollocationChart();
				return;
			}
			this.destroyCollocationChart();
			if (!canvas.isConnected) return;
			const ctx = canvas.getContext('2d');
			if (!ctx) return;
			const palette = this.frequencyChartPalette(labels.length);
			const common = {
				responsive: true,
				maintainAspectRatio: false,
				animation: { duration: 750 },
			};
			const legendSortByIndex = (a, b) => (a.index ?? 0) - (b.index ?? 0);
			const fmtScore = (x) => {
				const n = Number(x);
				return Number.isFinite(n) ? n.toFixed(3) : String(x);
			};
			const barTooltip = {
				callbacks: {
					label: (ctx) => {
						const v =
							mode === 'bar'
								? ctx.parsed.x
								: ctx.parsed.y != null
									? ctx.parsed.y
									: ctx.parsed.x;
						return typeof v === 'number' && Number.isFinite(v) ? `${valueLabel}: ${fmtScore(v)}` : '';
					},
				},
			};
			const pieTooltip = {
				callbacks: {
					label: (ctx) => {
						let raw = ctx.raw;
						if (raw == null && ctx.parsed != null) {
							raw = typeof ctx.parsed === 'object' && ctx.parsed.r != null ? ctx.parsed.r : ctx.parsed;
						}
						const n = Number(raw);
						const arr = pieLike ? dataPlot : data;
						const tot = Array.isArray(arr) ? arr.reduce((a, b) => a + (Number(b) || 0), 0) : 0;
						const pct = tot > 0 && Number.isFinite(n) ? ((n / tot) * 100).toFixed(1) : '';
						const core = `${valueLabel}: ${fmtScore(n)}`;
						return pct !== '' ? `${core} (${pct}%)` : core;
					},
				},
			};

			try {
				if (mode === 'bar') {
					this.collocationChartInstance = new Chart(ctx, {
						type: 'bar',
						data: {
							labels,
							datasets: [
								{
									label: valueLabel,
									data,
									backgroundColor: palette,
									borderColor: 'rgba(0,0,0,0.08)',
									borderWidth: 1,
								},
							],
						},
						options: {
							...common,
							indexAxis: 'y',
							plugins: { legend: { display: false }, title: { display: false }, tooltip: barTooltip },
							scales: {
								x: {
									beginAtZero: false,
									ticks: {
										callback: (v) => (typeof v === 'number' && Number.isFinite(v) ? fmtScore(v) : v),
									},
								},
							},
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				if (mode === 'vbar') {
					const borderEach = palette.map(() => 'rgba(0,0,0,0.08)');
					this.collocationChartInstance = new Chart(ctx, {
						type: 'bar',
						data: {
							labels,
							datasets: [
								{
									label: valueLabel,
									data,
									backgroundColor: palette,
									borderColor: borderEach,
									borderWidth: 1,
								},
							],
						},
						options: {
							...common,
							indexAxis: 'x',
							plugins: { legend: { display: false }, title: { display: false }, tooltip: barTooltip },
							scales: {
								x: {
									type: 'category',
									offset: true,
									ticks: { maxRotation: 55, minRotation: 35, autoSkip: true },
								},
								y: {
									type: 'linear',
									beginAtZero: false,
									ticks: {
										callback: (v) => (typeof v === 'number' && Number.isFinite(v) ? fmtScore(v) : v),
									},
								},
							},
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				if (mode === 'line') {
					this.collocationChartInstance = new Chart(ctx, {
						type: 'line',
						data: {
							labels,
							datasets: [
								{
									label: valueLabel,
									data,
									borderColor: 'rgb(75, 192, 192)',
									backgroundColor: 'rgba(75, 192, 192, 0.12)',
									borderWidth: 2,
									fill: true,
									tension: 0.25,
									pointRadius: 3,
									pointHoverRadius: 5,
								},
							],
						},
						options: {
							...common,
							plugins: { legend: { display: false }, title: { display: false }, tooltip: barTooltip },
							scales: {
								x: { ticks: { maxRotation: 55, minRotation: 35, autoSkip: true } },
								y: {
									beginAtZero: false,
									ticks: {
										callback: (v) => (typeof v === 'number' && Number.isFinite(v) ? fmtScore(v) : v),
									},
								},
							},
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				if (mode === 'polar') {
					this.collocationChartInstance = new Chart(ctx, {
						type: 'polarArea',
						data: {
							labels,
							datasets: [{ data: dataPlot, backgroundColor: palette, borderColor: '#fff', borderWidth: 1 }],
						},
						options: {
							...common,
							layout: { padding: 8 },
							plugins: {
								legend: {
									position: 'bottom',
									labels: { boxWidth: 12, maxWidth: 220, sort: legendSortByIndex },
								},
								tooltip: pieTooltip,
							},
							scales: { r: { beginAtZero: true } },
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				const ring = mode === 'doughnut';
				this.collocationChartInstance = new Chart(ctx, {
					type: ring ? 'doughnut' : 'pie',
					data: {
						labels,
						datasets: [
							{
								data: dataPlot,
								backgroundColor: palette,
								borderColor: '#fff',
								borderWidth: 1,
							},
						],
					},
					options: {
						...common,
						layout: { padding: 8 },
						plugins: {
							legend: {
								position: 'bottom',
								labels: { boxWidth: 12, maxWidth: 220, sort: legendSortByIndex },
							},
							tooltip: pieTooltip,
						},
					},
				});
				this._resizeCollocationChartAfterLayout();
			} catch (_) {
				this.destroyCollocationChart();
			}
		},

		_resizeCollocationChartAfterLayout() {
			const ch = this.collocationChartInstance;
			if (!ch || typeof ch.resize !== 'function') return;
			const epoch = this._collocationChartEpoch;
			requestAnimationFrame(() => {
				if (epoch !== this._collocationChartEpoch || this.collocationChartInstance !== ch) return;
				try {
					ch.resize();
				} catch (_) {
					/* ignore */
				}
			});
		},

		scheduleCollocationChartRender() {
			if (!this.frequencyChartLibraryAvailable()) return;
			if (this.statsSubTab && this.statsSubTab !== 'coll') {
				this.destroyCollocationChart();
				return;
			}
			if (this.collocationVizMode === 'table') {
				this.destroyCollocationChart();
				return;
			}
			const epoch = this._collocationChartEpoch;
			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					if (epoch !== this._collocationChartEpoch) return;
					if (!this.collocation || !this.collocation.ran || !this.collocationRows().length) {
						this.destroyCollocationChart();
						return;
					}
					if (this.statsSubTab && this.statsSubTab !== 'coll') return;
					if (this.collocationVizMode === 'table') return;
					if (this.activeTab && this.activeTab !== 'frequency') return;
					this.renderCollocationChart();
				});
			});
		},

		setCollocationVizMode(mode) {
			const allowed = new Set(['table', 'bar', 'vbar', 'line', 'polar', 'pie', 'doughnut']);
			const next = allowed.has(mode) ? mode : 'table';
			if (next !== 'table' && !this.frequencyChartLibraryAvailable()) return;
			this.collocationVizMode = next;
			if (next === 'table') {
				this.destroyCollocationChart();
			} else {
				this.scheduleCollocationChartRender();
			}
		},

		onCollocationStateApplied() {
			if (this.collocationVizMode && this.collocationVizMode !== 'table') {
				this.scheduleCollocationChartRender();
			}
		},

		otherColumnsList() {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			const rows = Array.isArray(other.rows) ? other.rows : [];
			const cols = Array.isArray(other.columns) ? other.columns : [];
			if (cols.length) return cols.map((c) => String(c));
			if (rows.length && rows[0] && typeof rows[0] === 'object' && !Array.isArray(rows[0])) {
				return Object.keys(rows[0]);
			}
			if (rows.length && Array.isArray(rows[0])) {
				return rows[0].map((_, i) => `col_${i + 1}`);
			}
			return [];
		},

		otherRowsList() {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			return Array.isArray(other.rows) ? other.rows : [];
		},

		otherCellValue(row, col, colIndex) {
			if (Array.isArray(row)) return row[colIndex];
			if (row && typeof row === 'object') return row[col];
			return row;
		},

		otherToNumber(value) {
			if (typeof value === 'number' && Number.isFinite(value)) return value;
			if (typeof value === 'string') {
				const cleaned = value.replace(/,/g, '').trim();
				if (!cleaned) return NaN;
				const n = Number(cleaned);
				return Number.isFinite(n) ? n : NaN;
			}
			if (typeof value === 'boolean') return value ? 1 : 0;
			return NaN;
		},

		otherNumericColumns() {
			const cols = this.otherColumnsList();
			const rows = this.otherRowsList();
			if (!cols.length || !rows.length) return [];
			const out = [];
			for (let i = 0; i < cols.length; i += 1) {
				const col = cols[i];
				let seen = 0;
				let numeric = 0;
				for (let r = 0; r < rows.length && seen < 120; r += 1) {
					const v = this.otherCellValue(rows[r], col, i);
					if (v == null || v === '') continue;
					seen += 1;
					if (Number.isFinite(this.otherToNumber(v))) numeric += 1;
				}
				if (seen > 0 && numeric / seen >= 0.7) out.push(col);
			}
			return out;
		},

		otherEnsureChartSelection() {
			const cols = this.otherColumnsList();
			if (!cols.length) {
				this.otherChartXColumn = '';
				this.otherChartYColumn = '';
				return;
			}
			const xNow = String(this.otherChartXColumn || '').trim();
			if (!xNow || !cols.includes(xNow)) this.otherChartXColumn = cols[0];
			const numeric = this.otherNumericColumns();
			const x = String(this.otherChartXColumn || '').trim();
			const preferredY = numeric.find((c) => c !== x) || numeric[0] || '';
			const yNow = String(this.otherChartYColumn || '').trim();
			if (!yNow || !numeric.includes(yNow) || yNow === x) this.otherChartYColumn = preferredY;
			if (!this.otherChartYColumn) this.otherVizMode = 'table';
		},

		otherChartData() {
			this.otherEnsureChartSelection();
			const rows = this.otherRowsList();
			const cols = this.otherColumnsList();
			const xCol = String(this.otherChartXColumn || '').trim();
			const yCol = String(this.otherChartYColumn || '').trim();
			const xi = cols.indexOf(xCol);
			const yi = cols.indexOf(yCol);
			if (xi < 0 || yi < 0 || !rows.length) return { labels: [], values: [], yLabel: yCol || 'Value' };
			const labels = [];
			const values = [];
			for (let i = 0; i < rows.length && labels.length < 120; i += 1) {
				const row = rows[i];
				const xv = this.otherCellValue(row, xCol, xi);
				const yv = this.otherCellValue(row, yCol, yi);
				const n = this.otherToNumber(yv);
				if (!Number.isFinite(n)) continue;
				const lbl = String(xv == null ? '' : xv).trim() || `(row ${i + 1})`;
				labels.push(this.truncateFrequencyChartLabel(lbl));
				values.push(n);
			}
			return { labels, values, yLabel: yCol || 'Value' };
		},

		destroyOtherChart() {
			this._otherChartEpoch = (this._otherChartEpoch || 0) + 1;
			const ch = this.otherChartInstance;
			if (ch) {
				try { if (typeof ch.stop === 'function') ch.stop(); } catch (_) {}
				try { if (typeof ch.destroy === 'function') ch.destroy(); } catch (_) {}
			}
			this.otherChartInstance = null;
		},

		renderOtherChart() {
			const Chart = typeof window !== 'undefined' ? window.Chart : null;
			if (!Chart || !this.frequencyChartLibraryAvailable()) return;
			const mode = this.otherVizMode;
			if (mode === 'table') return;
			const canvas = document.getElementById('flexicorp-other-chart-canvas');
			if (!canvas || !canvas.getContext || !canvas.isConnected) return;
			const { labels, values, yLabel } = this.otherChartData();
			if (!labels.length) {
				this.destroyOtherChart();
				return;
			}
			this.destroyOtherChart();
			const ctx = canvas.getContext('2d');
			if (!ctx) return;
			const palette = this.frequencyChartPalette(labels.length);
			try {
				this.otherChartInstance = new Chart(ctx, {
					type: mode === 'line' ? 'line' : 'bar',
					data: {
						labels,
						datasets: [{
							label: yLabel,
							data: values,
							backgroundColor: mode === 'line' ? 'rgba(59,130,246,0.18)' : palette,
							borderColor: mode === 'line' ? 'rgb(37,99,235)' : 'rgba(0,0,0,0.08)',
							borderWidth: mode === 'line' ? 2 : 1,
							fill: mode === 'line',
							tension: mode === 'line' ? 0.2 : 0,
						}],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						indexAxis: mode === 'bar' ? 'y' : 'x',
						plugins: { legend: { display: false } },
						scales: {
							x: { ticks: { maxRotation: 55, minRotation: 35, autoSkip: true } },
							y: { beginAtZero: false },
						},
					},
				});
			} catch (_) {
				this.destroyOtherChart();
			}
		},

		scheduleOtherChartRender() {
			this.otherEnsureChartSelection();
			if (!this.frequencyChartLibraryAvailable()) return;
			if (this.statsSubTab && this.statsSubTab !== 'other') {
				this.destroyOtherChart();
				return;
			}
			if (this.otherVizMode === 'table') {
				this.destroyOtherChart();
				return;
			}
			const epoch = this._otherChartEpoch;
			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					if (epoch !== this._otherChartEpoch) return;
					if (!this.other || !this.other.ran || !this.otherRowsList().length) {
						this.destroyOtherChart();
						return;
					}
					if (this.statsSubTab && this.statsSubTab !== 'other') return;
					if (this.activeTab && this.activeTab !== 'frequency') return;
					this.renderOtherChart();
				});
			});
		},

		setOtherVizMode(mode) {
			const allowed = new Set(['table', 'bar', 'line']);
			const next = allowed.has(mode) ? mode : 'table';
			if (next !== 'table' && !this.frequencyChartLibraryAvailable()) return;
			this.otherVizMode = next;
			if (next === 'table') this.destroyOtherChart();
			else this.scheduleOtherChartRender();
		},

		onOtherChartColumnsChanged() {
			this.otherEnsureChartSelection();
			if (this.otherVizMode !== 'table') this.scheduleOtherChartRender();
		},

		collocationChartExportBasename() {
			const field = this.collocation && this.collocation.field ? String(this.collocation.field) : 'coll';
			const safe = field.replace(/[^\w\-]+/g, '_').slice(0, 64) || 'coll';
			const d = new Date();
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `flexicorp-coll-${safe}-${y}${m}${day}`;
		},

		downloadCollocationChartPNG() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/png');
			this.triggerDownload(url, `${this.collocationChartExportBasename()}.png`);
		},

		downloadCollocationChartJPEG() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/jpeg', 0.92);
			this.triggerDownload(url, `${this.collocationChartExportBasename()}.jpg`);
		},

		downloadCollocationChartPrintForPdf() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const imgData = canvas.toDataURL('image/png', 1.0);
			const w = window.open('');
			if (!w) return;
			const title = 'Collocation chart — Save as PDF from print dialog';
			w.document.write(
				`<!DOCTYPE html><html><head><meta charset="utf-8"/><title>${title}</title></head><body style="margin:0;padding:16px;text-align:center;font-family:system-ui,sans-serif;"><p style="margin:0 0 12px 0;">Use <strong>Print</strong> (Ctrl+P / Cmd+P) and choose <strong>Save as PDF</strong>.</p><img src="${imgData}" alt="chart" style="max-width:100%;height:auto;border:1px solid #ddd"/></body></html>`
			);
			w.document.close();
		},

		async downloadCollocationChartPDF() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const imgData = canvas.toDataURL('image/png', 1.0);
			const imgWpx = canvas.width;
			const imgHpx = canvas.height;
			if (imgWpx <= 0 || imgHpx <= 0) return;
			try {
				const JsPDF = await this.loadJsPDF();
				const pdf = new JsPDF({ orientation: imgWpx >= imgHpx ? 'l' : 'p', unit: 'mm', format: 'a4' });
				const pageW = pdf.internal.pageSize.getWidth();
				const pageH = pdf.internal.pageSize.getHeight();
				const margin = 12;
				const maxW = pageW - margin * 2;
				const maxH = pageH - margin * 2;
				const ratio = imgHpx / imgWpx;
				let drawW = maxW;
				let drawH = drawW * ratio;
				if (drawH > maxH) {
					drawH = maxH;
					drawW = drawH / ratio;
				}
				const x = margin + (maxW - drawW) / 2;
				const y = margin + (maxH - drawH) / 2;
				pdf.addImage(imgData, 'PNG', x, y, drawW, drawH);
				pdf.save(`${this.collocationChartExportBasename()}.pdf`);
			} catch (_) {
				this.downloadCollocationChartPrintForPdf();
			}
		},

		submitFrequency(event) {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const form = this.getEventForm(event);
			if (!form) return;
			const fd = this.buildCommonRequestData();
			const fe = new FormData(form);
			for (const pair of fe.entries()) {
				const k = pair[0];
				const v = pair[1];
				if (k === 'freq_field[]') {
					fd.append(k, v);
					continue;
				}
				fd.set(k, v);
			}
			fd.set('active_tab', 'frequency');
			fd.set('run', 'freq');
			const qo = typeof this.statsOutgoingQueryForFreqSubmit === 'function' ? this.statsOutgoingQueryForFreqSubmit() : '';
			if (qo) fd.set('query', qo);
			this.submitAjaxData(fd, 'frequency');
		},

		submitFrequencyFromButton(element) {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const formData = this.buildCommonRequestData();
			const qo = typeof this.statsOutgoingQueryForFreqSubmit === 'function' ? this.statsOutgoingQueryForFreqSubmit() : '';
			if (qo) formData.set('query', qo);
			formData.set('active_tab', 'frequency');
			formData.set('run', 'freq');
			this.submitAjaxData(formData, 'frequency');
		},

		canShowMoreFrequency() {
			if (!this.frequency || !this.frequency.ran || this.callHasErrors(this.frequency.response)) return false;
			const rows = Array.isArray(this.frequency.rows) ? this.frequency.rows : [];
			const total =
				typeof this.frequencyEffectiveTotal === 'function'
					? this.frequencyEffectiveTotal()
					: (Number.isFinite(this.frequency.total) ? Number(this.frequency.total) : null);
			if (Number.isFinite(total) && total !== null) return rows.length < Number(total);
			const returnedNum = Number(this.frequency.returned);
			if (Number.isFinite(returnedNum) && returnedNum >= 0 && rows.length >= returnedNum) return false;
			const limit = this.normalizePositiveInt(this.frequency.limit, 100);
			return rows.length >= limit;
		},

		showMoreFrequency() {
			const rows = Array.isArray(this.frequency && this.frequency.rows) ? this.frequency.rows : [];
			const step = this.normalizePositiveInt(this.frequency && this.frequency.limit, 100);
			this.frequency.limit = rows.length + step;
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const formData = this.buildCommonRequestData();
			const qo = typeof this.statsOutgoingQueryForFreqSubmit === 'function' ? this.statsOutgoingQueryForFreqSubmit() : '';
			if (qo) formData.set('query', qo);
			formData.set('active_tab', 'frequency');
			formData.set('run', 'freq');
			this.submitAjaxData(formData, 'frequency');
		},

		/** Summary line under frequency-distribution results (field / returned / total). */
		frequencyDistributionMetaLine() {
			const f = this.frequency;
			if (!f) return '';
			const tot =
				f.total !== null && f.total !== undefined && f.total !== '' ? f.total : this.frequencyEffectiveTotal();
			
			const labels = f.fieldLabels && typeof f.fieldLabels === 'object' ? f.fieldLabels : {};
			const rawFields = Array.isArray(f.fields) && f.fields.length ? f.fields : (f.field ? String(f.field).split(/\s*,\s*/) : []);
			const fieldPretty = rawFields
				.map((k) => this.frequencyFieldPrettyLabel(k, labels))
				.filter(Boolean)
				.join(', ');
			let metaStr = 'field=' + (fieldPretty || '');
			if (f.returned !== null && f.returned !== undefined) {
				metaStr += ' · returned ' + f.returned;
			}
			
			if (tot !== null && tot !== undefined && tot !== '') {
				metaStr += ' · total ' + tot;
			} else if (this.frequencyHasCompareQueries && this.frequencyHasCompareQueries()) {
				const r = this.frequencyResult();
				if (r && r.totals_per_query) {
					const parts = [];
					for (const q of this.frequencyCompareQueries()) {
						if (r.totals_per_query[q] != null) {
							parts.push(`${q}: ${Number(r.totals_per_query[q]).toLocaleString()}`);
						}
					}
					if (parts.length) {
						metaStr += ' · totals: ' + parts.join(', ');
					}
				}
			}

			return metaStr;
		},

		submitCollocation(event) {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const form = this.getEventForm(event);
			if (!form) return;
			const fd = this.buildCommonRequestData();
			const fe = new FormData(form);
			for (const pair of fe.entries()) {
				fd.set(pair[0], pair[1]);
			}
			fd.set('active_tab', 'frequency');
			fd.set('run', 'coll');
			const qo = typeof this.statsOutgoingQueryForCollSubmit === 'function' ? this.statsOutgoingQueryForCollSubmit() : '';
			if (qo) fd.set('query', qo);
			this.submitAjaxData(fd, 'collocation');
		},

		submitCollocationFromButton() {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const formData = this.buildCommonRequestData();
			const qo = typeof this.statsOutgoingQueryForCollSubmit === 'function' ? this.statsOutgoingQueryForCollSubmit() : '';
			if (qo) formData.set('query', qo);
			formData.set('active_tab', 'frequency');
			formData.set('run', 'coll');
			this.submitAjaxData(formData, 'collocation');
		},
	};
};
