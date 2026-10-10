/**
 * flexicorp TEITOK UI: Recent and stored queries.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.querystore = function () {
	return {
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
