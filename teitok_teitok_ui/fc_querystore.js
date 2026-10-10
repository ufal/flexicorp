/**
 * flexicorp TEITOK UI: Recent and stored queries.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.querystore = function () {
	return {
		/**
		 * Browser-storage key of this corpus's queries: host + project path, since every TEITOK
		 * project on a host shares one origin (and the PHP session list is shared by all of them).
		 */
		fcQueryStoreKey() {
			try {
				const path = String(window.location.pathname || '').replace(/[^/]*$/, '');
				return 'fc.q.v1:' + window.location.host + path;
			} catch (_) {
				return '';
			}
		},

		/** Load this corpus's recent queries into recentQueriesLocal (null when storage is unavailable). */
		fcQueryStoreLoad() {
			const key = this.fcQueryStoreKey();
			if (!key) { this.recentQueriesLocal = null; return; }
			try {
				const raw = window.localStorage.getItem(key);
				const parsed = raw ? JSON.parse(raw) : null;
				const list = parsed && Array.isArray(parsed.recent) ? parsed.recent : [];
				this.recentQueriesLocal = list.filter((r) => r && typeof r.text === 'string' && r.text.trim());
			} catch (_) {
				this.recentQueriesLocal = null;
			}
		},

		/** Write recentQueriesLocal back; a full or blocked storage just leaves the list in memory. */
		fcQueryStoreSave() {
			const key = this.fcQueryStoreKey();
			if (!key || !Array.isArray(this.recentQueriesLocal)) return;
			try {
				window.localStorage.setItem(key, JSON.stringify({ v: 1, recent: this.recentQueriesLocal }));
			} catch (_) {}
		},

		/** A new stable id for a stored query (kept with it; later used to reuse its hits). */
		fcQueryStoreNewId() {
			return 'q' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
		},

		/**
		 * Record a query the user ran (not paging, not a share-link restore, not a module's
		 * generated program): newest first, one entry per text and query language, at most 50.
		 */
		fcQueryStoreRecord(text) {
			if (!Array.isArray(this.recentQueriesLocal)) return;
			const t = this.normalizeRecentQueryText(text);
			if (!t) return;
			const ql = this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage) : '';
			const prev = this.recentQueriesLocal.find((r) => r.ql === ql && this.normalizeRecentQueryText(r.text) === t);
			const rest = this.recentQueriesLocal.filter((r) => r !== prev);
			const rec = { qid: prev && prev.qid ? prev.qid : this.fcQueryStoreNewId(), text: t, ql, at: Date.now() };
			this.recentQueriesLocal = [rec].concat(rest).slice(0, 50);
			this.fcQueryStoreSave();
		},

		/** After a reload: the last query of this corpus in the search box (not run), so the compare set is back. */
		fcQueryStorePrefillLastQuery() {
			if (!this.search || String(this.search.query || '').trim() || String(this.search.value || '').trim()) return;
			const list = this.recentQueriesList();
			if (list.length && Array.isArray(this.recentQueriesLocal)) this.search.query = String(list[0]);
		},

		/**
		 * Recent queries for the active query_language (CQL dialect), newest first: this corpus's
		 * own list from browser storage; the PHP session lists only when storage is unavailable.
		 */
		recentQueriesList() {
			const ql = this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage) : '';
			if (Array.isArray(this.recentQueriesLocal)) {
				return this.recentQueriesLocal.filter((r) => !ql || !r.ql || r.ql === ql).map((r) => r.text);
			}
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
	};
};
