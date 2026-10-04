/*
 * Advanced statistics host app (module-ready).
 */

function ttAfSharedFns() {
	const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
		? window.ttFlexicorpFns
		: {};
	return fns;
}

function ttAdvancedFreqsApp() {
	const moduleRegistry = [];
	const registerModule = (definition) => {
		if (!definition || typeof definition !== 'object') return;
		const id = String(definition.id || '').trim();
		if (!id) return;
		const label = String(definition.label || id).trim();
		const isAvailable = typeof definition.isAvailable === 'function' ? definition.isAvailable : () => true;
		moduleRegistry.push({
			id,
			label,
			description: String(definition.description || '').trim(),
			isAvailable,
		});
	};

	const app = {
		activeTab: 'advanced',
		advancedSubTab: '',
		showHelp: false,
		fullscreen: false,
		backend: '',
		queryLanguage: '',
		corpusFormat: '',
		queryEngine: '',
		capabilities: { hasGeo: false },
		/** @type {null|ReturnType<NonNullable<typeof window.ttFlexicorpFns>['searchScope']['createSearchScopeStore']>} */
		afSearchScopeStore: null,
		afNamedSources: { last: [], recent: [], stored: [] },
		querySource: 'stored',
		namedQueries: [],
		selectedQueryNames: [],
		result: null,
		operation: 'keyness',
		field: 'lemma',

		_ss() {
			const fns = ttAfSharedFns();
			return fns && fns.searchScope ? fns.searchScope : null;
		},

		init() {
			const root = document.getElementById('flexicorp-advanced-freqs-root');
			this.backend = root ? String(root.getAttribute('data-backend') || '').trim() : '';
			this.queryLanguage = root ? String(root.getAttribute('data-query-language') || '').trim() : '';
			this.queryEngine = root ? String(root.getAttribute('data-query-engine') || '').trim() : '';
			this.corpusFormat = root ? String(root.getAttribute('data-corpus-format') || '').trim() : '';
			this.capabilities = this.readCapabilities(root);
			let initProg = root ? String(root.getAttribute('data-base-query') || '').trim() : '';
			const srcJson = root ? String(root.getAttribute('data-af-named-query-sources') || '').trim() : '';
			try {
				const parsed = srcJson ? JSON.parse(srcJson) : {};
				this.afNamedSources = {
					last: Array.isArray(parsed.last) ? parsed.last : [],
					recent: Array.isArray(parsed.recent) ? parsed.recent : [],
					stored: Array.isArray(parsed.stored) ? parsed.stored : [],
				};
			} catch (_) {
				this.afNamedSources = { last: [], recent: [], stored: [] };
			}
			this.namedQueries = [];
			const ss = this._ss();
			this.afSearchScopeStore = ss && typeof ss.createSearchScopeStore === 'function' ? ss.createSearchScopeStore(initProg) : null;
			if (typeof this.initMapsModule === 'function') this.initMapsModule(root);
			this.ensureAdvancedSubTab();
		},

		afEnsureSearchScopeStore() {
			const ss = this._ss();
			if (!this.afSearchScopeStore && ss && typeof ss.createSearchScopeStore === 'function') {
				this.afSearchScopeStore = ss.createSearchScopeStore('');
			}
		},

		afFullProgramText() {
			const ss = this._ss();
			if (!ss || !this.afSearchScopeStore) return '';
			const scope = ss.compileQueriesToScopeProgram(this.afSearchScopeStore.queries, { activeOnly: false });
			return typeof this.afSearchScopeStore.mergeFullProgram === 'function'
				? this.afSearchScopeStore.mergeFullProgram(scope)
				: scope;
		},

		afSearchScopeOnlyQuery() {
			const ss = this._ss();
			if (!ss) return '';
			return ss.stripAggregationClausesFromQuery(this.afFullProgramText());
		},

		hasBaseQuery() {
			return String(this.afSearchScopeOnlyQuery() || '').trim() !== '';
		},

		baseQueryTextDisplay() {
			const q = String(this.afFullProgramText() || '').trim();
			return q || '—';
		},

		afPersistScope() {
			// Embedded mode should mirror parent Search/Stats scope, not keep independent local memory.
			// Standalone advanced pages remain session/bootstrap-driven via server-provided data-base-query.
		},

		afSearchScopePersist() {
			this.afPersistScope();
		},
		afSearchScopeUniqueName(base = 'Q') {
			const root = String(base || 'Q').trim() || 'Q';
			const rows = this.afSearchScopeStore && Array.isArray(this.afSearchScopeStore.queries) ? this.afSearchScopeStore.queries : [];
			const used = new Set(rows.map((r) => String(r && r.name || '').trim()).filter(Boolean));
			let i = 1;
			let candidate = `${root}${i}`;
			while (used.has(candidate)) { i += 1; candidate = `${root}${i}`; }
			return candidate;
		},
		afSearchScopeIsNamed(row) {
			const r = row && typeof row === 'object' ? row : {};
			const nm = String(r.name || '').trim();
			return !!(r.named === true && nm && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm));
		},
		afSearchScopeEnsureNamed(row) {
			if (!row || !this.afSearchScopeStore) return '';
			if (this.afSearchScopeIsNamed(row)) return String(row.name || '').trim();
			const next = this.afSearchScopeUniqueName('Q');
			row.name = next;
			row.named = true;
			if (typeof this.afSearchScopeStore.renameQuery === 'function') this.afSearchScopeStore.renameQuery(row.id, next);
			this.afPersistScope();
			return next;
		},
		afSearchScopeProgramText(row) {
			const r = row && typeof row === 'object' ? row : {};
			const text = String(r.text || '').trim();
			if (!text) return '—';
			if (this.afSearchScopeIsNamed(r)) return `${String(r.name).trim()} = ${text}`;
			return text;
		},
		afSearchScopeCardClick(row) {
			if (!row) return;
			this.afSearchScopeToggleActive(row.id, !(row.active !== false));
		},
		afSearchScopeRenameViaPrompt(row) {
			if (!row) return;
			const cur = this.afSearchScopeEnsureNamed(row);
			const next = typeof window !== 'undefined' && typeof window.prompt === 'function'
				? String(window.prompt('Rename query', cur) || '').trim()
				: '';
			if (!next) return;
			if (!/^[A-Za-z_][A-Za-z0-9_-]*$/.test(next)) return;
			row.name = next;
			row.named = true;
			if (this.afSearchScopeStore && typeof this.afSearchScopeStore.renameQuery === 'function') this.afSearchScopeStore.renameQuery(row.id, next);
			this.afPersistScope();
		},
		afSearchScopeEditRow(row) {
			if (!row) return;
			this.afSearchScopeEnsureNamed(row);
			this.afPersistScope();
			this.setTab('queries');
		},

		afSearchScopeToggleActive(id, on) {
			if (!this.afSearchScopeStore || typeof this.afSearchScopeStore.setActive !== 'function') return;
			this.afSearchScopeStore.setActive(id, !!on);
			this.afPersistScope();
		},

		afSearchScopeAddRow() {
			const ss = this._ss();
			if (!this.afSearchScopeStore || !ss || typeof this.afSearchScopeStore.addQuery !== 'function') return;
			this.afSearchScopeStore.addQuery({ text: '', source: 'user' });
			this.afPersistScope();
		},

		afSearchScopeRemoveRow(id) {
			if (!this.afSearchScopeStore || typeof this.afSearchScopeStore.removeQuery !== 'function') return;
			this.afSearchScopeStore.removeQuery(id);
			this.afPersistScope();
		},

		afSearchScopeRenameCommitted(row) {
			const ss = this._ss();
			if (!row || !this.afSearchScopeStore || !ss) return;
			const nm = String(row.name || '').trim();
			if (nm && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm)) {
				row.named = true;
				if (typeof this.afSearchScopeStore.renameQuery === 'function') {
					this.afSearchScopeStore.renameQuery(row.id, nm);
				}
			} else {
				row.named = false;
			}
			this.afPersistScope();
		},

		afActiveNamedQueryIds() {
			const ss = this._ss();
			if (!ss || !this.afSearchScopeStore) return [];
			return ss.activeNamedAssignIds(this.afSearchScopeStore.queries);
		},

		afNamedSourcesLast() {
			return Array.isArray(this.afNamedSources.last) ? this.afNamedSources.last : [];
		},
		afNamedSourcesRecent() {
			return Array.isArray(this.afNamedSources.recent) ? this.afNamedSources.recent : [];
		},
		afNamedSourcesStored() {
			return Array.isArray(this.afNamedSources.stored) ? this.afNamedSources.stored : [];
		},

		afAddNamedSourcePick(rec) {
			const fns = ttAfSharedFns();
			const norm = typeof fns.normalizeNamedQueryRecord === 'function' ? fns.normalizeNamedQueryRecord(rec) : { name: '', query: '', source: 'stored', timestamp: '' };
			const q = String(norm.query || '').trim();
			if (!q) return;
			this.afEnsureSearchScopeStore();
			const ss = this._ss();
			if (!this.afSearchScopeStore || !ss) return;
			const nm0 = String(norm.name || '').trim();
			let name = nm0 && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm0) ? nm0 : '';
			if (!name) {
				const base = 'Pick';
				let n = this.afSearchScopeStore.queries.length + 1;
				name = `${base}${n}`;
				const existing = new Set(this.afSearchScopeStore.queries.map((r) => String(r && r.name).trim()));
				while (existing.has(name)) {
					n += 1;
					name = `${base}${n}`;
				}
			}
			this.afSearchScopeStore.addQuery({ name, text: q, source: String(norm.source || 'stored'), named: true });
			this.afPersistScope();
			this.setTab('advanced');
		},

		afAjaxPostUrl() {
			const fns = ttAfSharedFns();
			const root = document.getElementById('flexicorp-advanced-freqs-root');
			if (typeof fns.flexicorpAjaxPostUrl === 'function') return fns.flexicorpAjaxPostUrl(root);
			const explicit = root ? String(root.getAttribute('data-af-json-endpoint') || '').trim() : '';
			if (explicit) return explicit;
			try {
				const cur = new URL(window.location.href);
				const actionName = (root && root.getAttribute('data-af-action')) || 'flexicorp';
				const url = new URL('index.php', window.location.href);
				url.searchParams.set('action', actionName);
				const debug = cur.searchParams.get('debug');
				if (debug !== null && debug !== '') url.searchParams.set('debug', debug);
				const subc = cur.searchParams.get('subc');
				if (subc !== null && subc !== '') url.searchParams.set('subc', subc);
				return url.toString();
			} catch (_) {
				return window.location.href;
			}
		},
		afAjaxActionName() {
			const root = document.getElementById('flexicorp-advanced-freqs-root');
			const a = root ? String(root.getAttribute('data-af-action') || '').trim() : '';
			return a || 'flexicorp';
		},
		setTab(tab) {
			this.activeTab = tab === 'queries' ? 'queries' : 'advanced';
			if (this.activeTab === 'advanced') this.ensureAdvancedSubTab();
		},
		readCapabilities(root) {
			const fns = ttAfSharedFns();
			if (typeof fns.readCapabilitiesFromDataAttr === 'function') return fns.readCapabilitiesFromDataAttr(root, 'data-capabilities');
			return { hasGeo: false };
		},
		availableAdvancedModules() {
			const ctx = { backend: this.backend, queryLanguage: this.queryLanguage, corpusFormat: this.corpusFormat, capabilities: this.capabilities || {} };
			return moduleRegistry.filter((m) => {
				try {
					return !!m.isAvailable(ctx);
				} catch (_) {
					return false;
				}
			});
		},
		ensureAdvancedSubTab() {
			const mods = this.availableAdvancedModules();
			if (!mods.length) {
				this.advancedSubTab = '';
				return;
			}
			if (!mods.some((m) => m.id === this.advancedSubTab)) this.advancedSubTab = mods[0].id;
			if (this.advancedSubTab === 'maps' && typeof this.ensureMapsPointsData === 'function') this.ensureMapsPointsData();
			if (this.advancedSubTab === 'maps' && typeof this.ensureMapsRegionData === 'function') this.ensureMapsRegionData();
		},
		setAdvancedSubTab(id) {
			const wanted = String(id || '').trim();
			if (!wanted) return;
			const mods = this.availableAdvancedModules();
			if (mods.some((m) => m.id === wanted)) {
				this.advancedSubTab = wanted;
				if (wanted === 'maps' && typeof this.ensureMapsPointsData === 'function') this.ensureMapsPointsData();
				if (wanted === 'maps' && typeof this.ensureMapsRegionData === 'function') this.ensureMapsRegionData();
			}
		},
		activeAdvancedModule() {
			const mods = this.availableAdvancedModules();
			return mods.find((m) => m.id === this.advancedSubTab) || (mods.length ? mods[0] : null);
		},
		toolbarMainTitle() {
			if (!String(this.backend || '').trim()) return 'Advanced statistics';
			const fns = ttAfSharedFns();
			if (typeof fns.flexicorpToolbarMainTitle === 'function') return fns.flexicorpToolbarMainTitle(this.backend, this.queryLanguage);
			return this.backend || 'Advanced statistics';
		},
		toolbarMetaLine() {
			const parts = [];
			if (String(this.queryLanguage || '').trim()) parts.push(String(this.queryLanguage).trim());
			if (String(this.corpusFormat || '').trim()) parts.push(String(this.corpusFormat).trim());
			return parts.join(' · ');
		},
		openHelp() {
			this.showHelp = !this.showHelp;
		},
		openSettings() {},
		fullscreenButtonLabel() {
			return this.fullscreen ? 'Exit fullscreen' : 'Fullscreen';
		},
		toggleFullscreen() {
			try {
				const root = document.getElementById('flexicorp-advanced-freqs-root');
				if (!document.fullscreenElement && root && root.requestFullscreen) {
					root.requestFullscreen();
					this.fullscreen = true;
					return;
				}
				if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen();
			} catch (_) {}
			this.fullscreen = !!document.fullscreenElement;
		},
	};

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
	for (let i = 0; i < installers.length; i += 1) {
		const fn = installers[i];
		if (typeof fn !== 'function') continue;
		try {
			fn(app, registerModule);
		} catch (_) {}
	}

	return app;
}
