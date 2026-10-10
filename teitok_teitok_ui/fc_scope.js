/**
 * flexicorp TEITOK UI: Stats: search scope (named queries being compared), named sources, outgoing queries and their display.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.scope = function () {
	return {
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
						raw.push({ name, query: text, source: 'recent', named: row.named === true });
					}
				} else {
					raw.push({ name: `Recent${i + 1}`, query: scopeOnly || q0, source: 'recent' });
				}
			}
			// one entry per query text; a name the user gave (A = …) wins over an unnamed run of the same text
			const byText = new Map();
			const out = [];
			for (let i = 0; i < raw.length; i += 1) {
				const rec = raw[i];
				const qkey = String(rec.query || '').trim().replace(/\s+/g, ' ');
				if (!qkey) continue;
				if (byText.has(qkey)) {
					const at = byText.get(qkey);
					if (rec.named && !out[at].named) out[at] = rec;
					continue;
				}
				byText.set(qkey, out.length);
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
	};
};
