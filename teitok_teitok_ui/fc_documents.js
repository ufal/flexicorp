/**
 * flexicorp TEITOK UI: Documents tab.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.documents = function () {
	return {
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
	};
};
