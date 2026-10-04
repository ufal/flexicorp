window.flexicorpQuerybuilderExtend = function flexicorpQuerybuilderExtend() {
	return {
		queryBuilderConfig: {
			enabled: false,
			operators: ['matches', 'contains', 'startswith', 'endsin'],
			sharedBase: 'cqlCore',
			qlPacks: {},
		},
		queryBuilderState: {
			enabled: false,
			open: false,
			qlFamily: 'cqlCore',
			queryName: '',
			warning: '',
			tokens: [],
			selectedTokenId: '',
			globals: [],
			regions: [],
			relations: [],
			alignment: {
				enabled: false,
				joinField: 'tuid',
				sourceAlias: 'a',
				targetAlias: 'b',
				targetRestrictions: [],
			},
		},
		queryBuilderFieldUi: {},
		queryBuilderFieldUiLoading: {},
		queryBuilderSuggestState: {},
		_qbSuggestTimers: {},
		_qbSuggestReqSeq: 1,
		_qbTokenSeq: 1,
		_qbSeq: 1,

		initQueryBuilderModule() {
			this.queryBuilderRefreshFromState();
		},

		onQueryBuilderStateApplied() {
			this.queryBuilderRefreshFromState();
		},

		queryBuilderRefreshFromState() {
			const cfg = this.queryBuilderConfig && typeof this.queryBuilderConfig === 'object'
				? this.queryBuilderConfig
				: {};
			const fam = this.queryBuilderFamilyForLanguage(this.settings && this.settings.queryLanguage);
			if (!this.queryBuilderState || typeof this.queryBuilderState !== 'object') {
				this.queryBuilderState = {};
			}
			this.queryBuilderState.enabled = !!cfg.enabled;
			this.queryBuilderState.qlFamily = fam;
			if (!Array.isArray(this.queryBuilderState.tokens)) this.queryBuilderState.tokens = [];
			if (!Array.isArray(this.queryBuilderState.globals)) this.queryBuilderState.globals = [];
			if (!Array.isArray(this.queryBuilderState.regions)) this.queryBuilderState.regions = [];
			if (!Array.isArray(this.queryBuilderState.relations)) this.queryBuilderState.relations = [];
			if (!this.queryBuilderState.alignment || typeof this.queryBuilderState.alignment !== 'object') {
				this.queryBuilderState.alignment = {};
			}
			if (typeof this.queryBuilderState.alignment.enabled !== 'boolean') this.queryBuilderState.alignment.enabled = false;
			const joinField = String(this.queryBuilderState.alignment.joinField || '').trim();
			this.queryBuilderState.alignment.joinField = (joinField === 's_tuid') ? 's_tuid' : 'tuid';
			const sourceAlias = String(this.queryBuilderState.alignment.sourceAlias || '').trim();
			const targetAlias = String(this.queryBuilderState.alignment.targetAlias || '').trim();
			this.queryBuilderState.alignment.sourceAlias = sourceAlias || 'a';
			this.queryBuilderState.alignment.targetAlias = targetAlias || 'b';
			if (!Array.isArray(this.queryBuilderState.alignment.targetRestrictions)) {
				this.queryBuilderState.alignment.targetRestrictions = [];
			}
			if (!this.queryBuilderState.alignment.targetRestrictions.length) {
				this.queryBuilderState.alignment.targetRestrictions = [this.queryBuilderMakeRestriction()];
			}
			if (typeof this.queryBuilderState.open !== 'boolean') this.queryBuilderState.open = false;
			if (typeof this.queryBuilderState.warning !== 'string') this.queryBuilderState.warning = '';
			if (typeof this.queryBuilderState.queryName !== 'string') this.queryBuilderState.queryName = '';
			if (!this.queryBuilderFieldUi || typeof this.queryBuilderFieldUi !== 'object') this.queryBuilderFieldUi = {};
			if (!this.queryBuilderFieldUiLoading || typeof this.queryBuilderFieldUiLoading !== 'object') this.queryBuilderFieldUiLoading = {};
			if (!this.queryBuilderSuggestState || typeof this.queryBuilderSuggestState !== 'object') this.queryBuilderSuggestState = {};
			if (!this._qbSuggestTimers || typeof this._qbSuggestTimers !== 'object') this._qbSuggestTimers = {};
			if (!Number.isFinite(this._qbTokenSeq) || this._qbTokenSeq < 1) this._qbTokenSeq = 1;
			this.queryBuilderEnsureTokenSeed();
			this.queryBuilderEnsureAlignmentSeed();
			if (!this.queryBuilderSupportsDependencies() && this.queryBuilderState && Array.isArray(this.queryBuilderState.tokens)) {
				this.queryBuilderState.tokens.forEach((t) => {
					if (!t || typeof t !== 'object') return;
					t.dependsOn = '';
					t.dependencyRelation = '';
				});
			}
		},

		queryBuilderFamilyForLanguage(queryLanguage) {
			const ql = String(queryLanguage || '').toLowerCase();
			const packs = this.queryBuilderConfig && this.queryBuilderConfig.qlPacks && typeof this.queryBuilderConfig.qlPacks === 'object'
				? this.queryBuilderConfig.qlPacks
				: {};
			if (packs[ql] && packs[ql].family) return String(packs[ql].family);
			return 'unsupported';
		},

		queryBuilderCurrentPack() {
			const ql = String((this.settings && this.settings.queryLanguage) || '').toLowerCase();
			const packs = this.queryBuilderConfig && this.queryBuilderConfig.qlPacks && typeof this.queryBuilderConfig.qlPacks === 'object'
				? this.queryBuilderConfig.qlPacks
				: {};
			const pack = packs[ql] && typeof packs[ql] === 'object' ? packs[ql] : {};
			return {
				id: ql,
				family: String(pack.family || 'unsupported'),
				enabled: !!pack.enabled,
			};
		},

		queryBuilderSupportsCurrentLanguage() {
			const pack = this.queryBuilderCurrentPack();
			return this.queryBuilderState && this.queryBuilderState.enabled && pack.enabled && pack.family === 'cqlCore';
		},

		queryBuilderSupportsAlignment() {
			if (!this.queryBuilderSupportsCurrentLanguage()) return false;
			const ql = String((this.settings && this.settings.queryLanguage) || '').trim().toLowerCase();
			return ql === 'pando-cql';
		},

		queryBuilderAvailableAlignmentJoinFields() {
			return ['tuid', 's_tuid'];
		},

		queryBuilderAlignmentJoinFieldOptions() {
			return this.queryBuilderAvailableAlignmentJoinFields().map((value) => ({
				value,
				label: value === 's_tuid' ? 'Sentence alignment (s_tuid)' : 'Token alignment (tuid)',
			}));
		},

		queryBuilderEnsureAlignmentSeed() {
			if (!this.queryBuilderState || !this.queryBuilderState.alignment) return;
			const a = this.queryBuilderState.alignment;
			if (!Array.isArray(a.targetRestrictions)) a.targetRestrictions = [];
			if (!a.targetRestrictions.length) a.targetRestrictions = [this.queryBuilderMakeRestriction()];
			const allowed = this.queryBuilderAvailableAlignmentJoinFields();
			if (allowed.length) {
				const cur = String(a.joinField || '').trim();
				if (!allowed.includes(cur)) a.joinField = allowed[0];
			} else {
				a.joinField = 'tuid';
			}
		},

		queryBuilderSetAlignmentEnabled(nextEnabled) {
			if (!this.queryBuilderState || !this.queryBuilderState.alignment) return;
			const can = this.queryBuilderSupportsAlignment();
			this.queryBuilderState.alignment.enabled = !!nextEnabled && can;
			if (this.queryBuilderState.alignment.enabled) {
				this.queryBuilderEnsureAlignmentSeed();
				const tokens = this.queryBuilderState && Array.isArray(this.queryBuilderState.tokens)
					? this.queryBuilderState.tokens
					: [];
				const first = tokens.length ? tokens[0] : null;
				const currentName = String((first && first.name) || '').trim();
				if (first && !currentName) {
					const fallback = String(this.queryBuilderState.alignment.sourceAlias || 'a').trim() || 'a';
					first.name = fallback;
				}
			}
		},

		queryBuilderAddTargetRestriction() {
			if (!this.queryBuilderState || !this.queryBuilderState.alignment) return;
			const a = this.queryBuilderState.alignment;
			if (!Array.isArray(a.targetRestrictions)) a.targetRestrictions = [];
			a.targetRestrictions.push(this.queryBuilderMakeRestriction());
		},

		queryBuilderRemoveTargetRestriction(restrictionId) {
			if (!this.queryBuilderState || !this.queryBuilderState.alignment) return;
			const a = this.queryBuilderState.alignment;
			if (!Array.isArray(a.targetRestrictions)) return;
			const rid = String(restrictionId || '');
			a.targetRestrictions = a.targetRestrictions.filter((r) => String((r && r.id) || '') !== rid);
			if (!a.targetRestrictions.length) a.targetRestrictions = [this.queryBuilderMakeRestriction()];
		},

		queryBuilderAlignmentUsesSentenceTarget() {
			const joinField = String((this.queryBuilderState && this.queryBuilderState.alignment && this.queryBuilderState.alignment.joinField) || '').trim();
			return joinField === 's_tuid';
		},

		queryBuilderAlignmentFieldAllowed(fieldName, mode) {
			const field = String(fieldName || '').trim();
			if (!field) return false;
			if (/(_tuid|^tuid$)/i.test(field)) return false;
			if (mode === 'sentence-target') {
				const knownRegions = new Set(this.queryBuilderKnownRegions());
				const kind = this.queryBuilderFieldKind(field, knownRegions);
				return kind.kind !== 'token';
			}
			return true;
		},

		queryBuilderTargetFieldGroups() {
			const groups = this.queryBuilderFieldGroups();
			const mode = this.queryBuilderAlignmentUsesSentenceTarget() ? 'sentence-target' : 'token-target';
			return groups
				.map((grp) => {
					const opts = (Array.isArray(grp.options) ? grp.options : [])
						.filter((opt) => this.queryBuilderAlignmentFieldAllowed(opt && opt.value, mode));
					return {
						id: grp.id,
						label: grp.label,
						options: opts,
					};
				})
				.filter((grp) => Array.isArray(grp.options) && grp.options.length > 0);
		},

		queryBuilderDependencyLanguageAllowed() {
			const ql = String((this.settings && this.settings.queryLanguage) || '').trim().toLowerCase();
			return ql === 'pando-cql' || ql === 'pmltq' || ql === 'clickpmltq';
		},

		queryBuilderCorpusHasDependencies() {
			try {
				if (typeof this.statsModuleInstallContext === 'function') {
					const ctx = this.statsModuleInstallContext();
					if (ctx && ctx.hasDeps) return true;
				}
			} catch (_) {}
			return false;
		},

		queryBuilderSupportsDependencies() {
			return this.queryBuilderDependencyLanguageAllowed() && this.queryBuilderCorpusHasDependencies();
		},

		queryBuilderDependencyRelationOptions() {
			const cfg = this.queryBuilderConfig && typeof this.queryBuilderConfig === 'object' ? this.queryBuilderConfig : {};
			const raw = Array.isArray(cfg.dependencyRelations) ? cfg.dependencyRelations : [];
			const vals = raw.map((x) => String(x || '').trim()).filter(Boolean);
			return vals.length ? vals : ['child'];
		},

		qbFieldOptionsFromCatalog() {
			const rootEl = typeof document !== 'undefined' ? document.getElementById('flexicorp-root') : null;
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			const cat = this.attributeCatalog && typeof this.attributeCatalog === 'object' ? this.attributeCatalog : {};
			const noShow = new Set(Array.isArray(cat.noshow_keys) ? cat.noshow_keys.map((x) => String(x)) : []);
			const labelMap = typeof fns.frequencySelectableFieldLabelMap === 'function'
				? fns.frequencySelectableFieldLabelMap(this, rootEl)
				: (() => {
					const displayMap = cat.searchable_display_labels && typeof cat.searchable_display_labels === 'object'
						? cat.searchable_display_labels
						: {};
					const anyMap = cat.searchable_labels && typeof cat.searchable_labels === 'object'
						? cat.searchable_labels
						: {};
					return Object.assign({}, anyMap, displayMap);
				})();
			const fieldDetails = cat.field_details && typeof cat.field_details === 'object'
				? cat.field_details
				: {};
			const keys = new Set(Object.keys(labelMap || {}));
			const allowedKeys = typeof fns.frequencySelectableFieldKeys === 'function'
				? new Set(fns.frequencySelectableFieldKeys(rootEl))
				: new Set();
			const out = [];
			const blockedByAllowlist = [];
			Array.from(keys).forEach((key) => {
				const field = String(key || '').trim();
				if (!field || noShow.has(field)) return;
				const adminOnly = !!(fieldDetails[field] && fieldDetails[field].admin);
				const adminVisible = !!(this.isAdmin && adminOnly);
				if (allowedKeys.size && !allowedKeys.has(field) && !adminVisible) {
					blockedByAllowlist.push(field);
					return;
				}
				const label = String((labelMap && labelMap[field]) || field).trim();
				out.push({ value: field, label: label || field, admin: adminOnly });
			});
			out.sort((a, b) => String(a.label).localeCompare(String(b.label)));
			return out;
		},

		queryBuilderKnownRegions() {
			const out = [];
			const seen = new Set();
			const cat = this.attributeCatalog && typeof this.attributeCatalog === 'object' ? this.attributeCatalog : {};
			const fieldMeta = cat.field_meta && typeof cat.field_meta === 'object' ? cat.field_meta : {};
			Object.keys(fieldMeta).forEach((field) => {
				const meta = fieldMeta[field];
				if (!meta || typeof meta !== 'object') return;
				const kind = String(meta.kind || '').toLowerCase();
				const region = String(meta.region || '').trim();
				if (kind !== 'region' || !region || seen.has(region)) return;
				seen.add(region);
				out.push(region);
			});
			try {
				const sattrObj = typeof window !== 'undefined' && window.satts && typeof window.satts === 'object'
					? window.satts
					: null;
				if (sattrObj) {
					Object.keys(sattrObj).forEach((k) => {
						const kk = String(k || '').trim();
						if (!kk) return;
						if (seen.has(kk)) return;
						seen.add(kk);
						out.push(kk);
					});
				}
			} catch (_) {}
			const searchable = cat.searchable_labels && typeof cat.searchable_labels === 'object'
				? cat.searchable_labels
				: {};
			Object.keys(searchable).forEach((field) => {
				const f = String(field || '').trim();
				const pos = f.indexOf('_');
				if (pos <= 0) return;
				const reg = f.slice(0, pos);
				if (!reg || seen.has(reg)) return;
				seen.add(reg);
				out.push(reg);
			});
			return out;
		},

		queryBuilderFieldKind(fieldName, knownRegionsSet) {
			const field = String(fieldName || '').trim();
			if (!field) return { kind: 'token', region: '' };
			const cat = this.attributeCatalog && typeof this.attributeCatalog === 'object' ? this.attributeCatalog : {};
			const fieldMeta = cat.field_meta && typeof cat.field_meta === 'object' ? cat.field_meta : {};
			const explicit = fieldMeta[field] && typeof fieldMeta[field] === 'object' ? fieldMeta[field] : null;
			if (explicit) {
				const kind = String(explicit.kind || '').toLowerCase();
				const region = String(explicit.region || '').trim();
				if (kind === 'documents') return { kind: 'documents', region: region || 'text' };
				if (kind === 'region') return { kind: 'region', region: region || '' };
				if (kind === 'token') return { kind: 'token', region: '' };
			}
			const lower = field.toLowerCase();
			// TEITOK convention: text_* is document-level metadata (sattributes on text region).
			if (
				lower.startsWith('text_')
				|| lower.startsWith('doc_')
				|| lower.startsWith('document_')
			) {
				return { kind: 'documents', region: 'text' };
			}
			const pos = field.indexOf('_');
			if (pos > 0) {
				const region = field.slice(0, pos);
				if (knownRegionsSet.has(region)) return { kind: 'region', region };
			}
			return { kind: 'token', region: '' };
		},

		queryBuilderFieldGroups() {
			const fields = this.qbFieldOptionsFromCatalog();
			const knownRegions = new Set(this.queryBuilderKnownRegions());
			const tokenOptions = [];
			const documentOptions = [];
			const byRegion = {};
			fields.forEach((opt) => {
				const value = String(opt && opt.value ? opt.value : '');
				const kind = this.queryBuilderFieldKind(value, knownRegions);
				if (kind.kind === 'documents') {
					documentOptions.push(opt);
					return;
				}
				if (kind.kind === 'region' && kind.region) {
					if (!Array.isArray(byRegion[kind.region])) byRegion[kind.region] = [];
					byRegion[kind.region].push(opt);
					return;
				}
				tokenOptions.push(opt);
			});
			tokenOptions.sort((a, b) => String(a.label).localeCompare(String(b.label)));
			documentOptions.sort((a, b) => String(a.label).localeCompare(String(b.label)));
			Object.keys(byRegion).forEach((region) => {
				byRegion[region].sort((a, b) => String(a.label).localeCompare(String(b.label)));
			});
			const groups = [];
			if (tokenOptions.length) {
				groups.push({ id: 'token', label: 'Token attributes', options: tokenOptions });
			}
			if (documentOptions.length) {
				groups.push({ id: 'documents', label: 'Documents', options: documentOptions });
			}
			this.queryBuilderKnownRegions().forEach((region) => {
				if (region === 'text') return;
				const opts = byRegion[region];
				if (!Array.isArray(opts) || !opts.length) return;
				groups.push({ id: `region:${region}`, label: `Region: ${region}`, options: opts });
			});
			Object.keys(byRegion).forEach((region) => {
				if (region === 'text') return;
				if (this.queryBuilderKnownRegions().includes(region)) return;
				const opts = byRegion[region];
				if (!Array.isArray(opts) || !opts.length) return;
				groups.push({ id: `region:${region}`, label: `Region: ${region}`, options: opts });
			});
			return groups;
		},

		queryBuilderFieldOptions() {
			const groups = this.queryBuilderFieldGroups();
			return groups.reduce((acc, g) => acc.concat(Array.isArray(g.options) ? g.options : []), []);
		},

		queryBuilderOperators() {
			const raw = this.queryBuilderConfig && Array.isArray(this.queryBuilderConfig.operators)
				? this.queryBuilderConfig.operators
				: ['matches', 'contains', 'startswith', 'endsin'];
			return raw.map((x) => String(x)).filter(Boolean);
		},

		queryBuilderDefaultField() {
			const opts = this.queryBuilderFieldOptions();
			return opts.length ? String(opts[0].value) : '';
		},

		queryBuilderMakeRestriction() {
			return {
				id: 'r' + (this._qbSeq++),
				field: '',
				operator: 'matches',
				value: '',
			};
		},

		queryBuilderRestrictionFieldMissing(r) {
			const field = String((r && r.field) || '').trim();
			const value = String((r && r.value) || '').trim();
			return !field && !!value;
		},

		queryBuilderMakeToken() {
			const tokenNo = this._qbTokenSeq++;
			const tokenId = 't' + tokenNo;
			return {
				id: tokenId,
				label: `Token ${tokenNo}`,
				name: '',
				dependsOn: '',
				dependencyRelation: '',
				restrictions: [this.queryBuilderMakeRestriction()],
			};
		},

		queryBuilderEnsureTokenSeed() {
			if (!this.queryBuilderState || !Array.isArray(this.queryBuilderState.tokens)) return;
			this.queryBuilderState.tokens.forEach((t) => {
				if (!t || typeof t !== 'object') return;
				if (typeof t.dependsOn !== 'string') t.dependsOn = '';
				if (typeof t.dependencyRelation !== 'string') t.dependencyRelation = '';
			});
			if (!this.queryBuilderState.tokens.length) {
				this.queryBuilderState.tokens = [this.queryBuilderMakeToken()];
			}
			const selected = String(this.queryBuilderState.selectedTokenId || '');
			const hasSelected = this.queryBuilderState.tokens.some((t) => t && String(t.id) === selected);
			if (!hasSelected) {
				this.queryBuilderState.selectedTokenId = String(this.queryBuilderState.tokens[0].id || '');
			}
		},

		queryBuilderSelectedToken() {
			const selected = String((this.queryBuilderState && this.queryBuilderState.selectedTokenId) || '');
			const tokens = this.queryBuilderState && Array.isArray(this.queryBuilderState.tokens)
				? this.queryBuilderState.tokens
				: [];
			return tokens.find((t) => t && String(t.id) === selected) || null;
		},

		queryBuilderOpen() {
			if (!this.queryBuilderState || !this.queryBuilderState.enabled) return;
			this.queryBuilderEnsureTokenSeed();
			this.queryBuilderState.open = true;
			this.queryBuilderAutoLoadFromCurrentQuery();
		},

		queryBuilderClose() {
			if (!this.queryBuilderState) return;
			this.queryBuilderState.open = false;
		},

		queryBuilderToggle() {
			if (!this.queryBuilderState || !this.queryBuilderState.enabled) return;
			if (this.queryBuilderState.open) this.queryBuilderClose();
			else this.queryBuilderOpen();
		},

		queryBuilderAddToken() {
			if (!this.queryBuilderState || !Array.isArray(this.queryBuilderState.tokens)) return;
			const tok = this.queryBuilderMakeToken();
			this.queryBuilderState.tokens.push(tok);
			this.queryBuilderState.selectedTokenId = tok.id;
		},

		queryBuilderSelectToken(tokenId) {
			if (!this.queryBuilderState) return;
			this.queryBuilderState.selectedTokenId = String(tokenId || '');
		},

		queryBuilderRemoveToken(tokenId) {
			if (!this.queryBuilderState || !Array.isArray(this.queryBuilderState.tokens)) return;
			const removeId = String(tokenId || '');
			this.queryBuilderState.tokens = this.queryBuilderState.tokens.filter((t) => String(t.id) !== removeId);
			this.queryBuilderState.tokens.forEach((tok) => {
				if (!tok || typeof tok !== 'object') return;
				if (String(tok.dependsOn || '') === removeId) {
					tok.dependsOn = '';
					tok.dependencyRelation = '';
				}
			});
			this.queryBuilderEnsureTokenSeed();
		},

		queryBuilderMoveToken(tokenId, direction) {
			if (!this.queryBuilderState || !Array.isArray(this.queryBuilderState.tokens)) return;
			const id = String(tokenId || '');
			const tokens = this.queryBuilderState.tokens;
			const idx = tokens.findIndex((t) => String(t && t.id) === id);
			if (idx < 0) return;
			const nextIdx = direction === 'up' ? idx - 1 : idx + 1;
			if (nextIdx < 0 || nextIdx >= tokens.length) return;
			const tmp = tokens[idx];
			tokens[idx] = tokens[nextIdx];
			tokens[nextIdx] = tmp;
		},

		queryBuilderTokenIndex(tokenId) {
			if (!this.queryBuilderState || !Array.isArray(this.queryBuilderState.tokens)) return -1;
			const id = String(tokenId || '');
			return this.queryBuilderState.tokens.findIndex((t) => String((t && t.id) || '') === id);
		},

		queryBuilderCanMoveToken(tokenId, direction) {
			const idx = this.queryBuilderTokenIndex(tokenId);
			if (idx < 0) return false;
			const tok = this.queryBuilderState.tokens[idx];
			if (this.queryBuilderSupportsDependencies() && tok && String(tok.dependsOn || '').trim()) return false;
			const total = this.queryBuilderState && Array.isArray(this.queryBuilderState.tokens)
				? this.queryBuilderState.tokens.length
				: 0;
			if (direction === 'up') return idx > 0;
			if (direction === 'down') return idx >= 0 && idx < (total - 1);
			return false;
		},

		queryBuilderTokenDependencyLabel(tok) {
			if (!this.queryBuilderSupportsDependencies()) return '';
			if (!tok || typeof tok !== 'object') return '';
			const parentId = String(tok.dependsOn || '').trim();
			if (!parentId) return '';
			const relation = String(tok.dependencyRelation || '').trim() || 'dep';
			const tokens = this.queryBuilderState && Array.isArray(this.queryBuilderState.tokens) ? this.queryBuilderState.tokens : [];
			const parent = tokens.find((t) => String((t && t.id) || '') === parentId);
			const parentLabel = parent
				? ((parent.name && String(parent.name).trim()) ? String(parent.name).trim() : (parent.label || parent.id || parentId))
				: parentId;
			return `${relation} -> ${parentLabel}`;
		},

		queryBuilderDependencyParentOptions(tokenId) {
			if (!this.queryBuilderSupportsDependencies()) return [];
			const selfId = String(tokenId || '');
			const tokens = this.queryBuilderState && Array.isArray(this.queryBuilderState.tokens) ? this.queryBuilderState.tokens : [];
			return tokens
				.filter((t) => t && String(t.id || '') !== selfId)
				.map((t) => ({
					value: String(t.id || ''),
					label: (t.name && String(t.name).trim()) ? String(t.name).trim() : (t.label || t.id),
				}));
		},

		queryBuilderOnTokenDependencyChange(tok) {
			if (!this.queryBuilderSupportsDependencies()) {
				if (tok && typeof tok === 'object') {
					tok.dependsOn = '';
					tok.dependencyRelation = '';
				}
				return;
			}
			if (!tok || typeof tok !== 'object') return;
			const parentId = String(tok.dependsOn || '').trim();
			if (!parentId) {
				tok.dependencyRelation = '';
				return;
			}
			const allowed = this.queryBuilderDependencyRelationOptions();
			const cur = String(tok.dependencyRelation || '').trim();
			if (!cur || !allowed.includes(cur)) tok.dependencyRelation = allowed[0] || 'child';
		},

		queryBuilderAddRestriction() {
			const token = this.queryBuilderSelectedToken();
			if (!token) return;
			if (!Array.isArray(token.restrictions)) token.restrictions = [];
			token.restrictions.push(this.queryBuilderMakeRestriction());
		},

		queryBuilderFieldUiState(field) {
			const f = String(field || '').trim();
			if (!f) return { input: 'text', options: [] };
			const hit = this.queryBuilderFieldUi && this.queryBuilderFieldUi[f] && typeof this.queryBuilderFieldUi[f] === 'object'
				? this.queryBuilderFieldUi[f]
				: null;
			if (!hit) return { input: 'text', options: [] };
			return {
				input: String(hit.input || 'text'),
				options: Array.isArray(hit.options) ? hit.options : [],
			};
		},

		queryBuilderRestrictionInputType(r) {
			const field = String((r && r.field) || '').trim();
			if (field && !(this.queryBuilderFieldUi && this.queryBuilderFieldUi[field])) {
				this.queryBuilderEnsureFieldUiLoaded(field);
			}
			const state = this.queryBuilderFieldUiState(field);
			return state.input === 'select' ? 'select' : 'text';
		},

		queryBuilderRestrictionOptions(r) {
			const state = this.queryBuilderFieldUiState(r && r.field);
			return Array.isArray(state.options) ? state.options : [];
		},

		queryBuilderRestrictionFieldLoading(r) {
			const f = String((r && r.field) || '').trim();
			if (!f) return false;
			return !!(this.queryBuilderFieldUiLoading && this.queryBuilderFieldUiLoading[f]);
		},

		queryBuilderSuggestKey(r) {
			return r && r.id ? String(r.id) : '';
		},

		queryBuilderCanSuggest(r) {
			if (!r) return false;
			if (this.queryBuilderRestrictionInputType(r) === 'select') return false;
			const op = String((r.operator || '')).trim().toLowerCase();
			if (op !== 'matches') return false;
			const field = String((r.field || '')).trim();
			if (!field) return false;
			return true;
		},

		queryBuilderSuggestStateFor(r) {
			const key = this.queryBuilderSuggestKey(r);
			if (!key) return { loading: false, items: [], open: false, reason: '' };
			const hit = this.queryBuilderSuggestState && this.queryBuilderSuggestState[key] ? this.queryBuilderSuggestState[key] : null;
			return hit || { loading: false, items: [], open: false, reason: '' };
		},

		queryBuilderSuggestItems(r) {
			const st = this.queryBuilderSuggestStateFor(r);
			return Array.isArray(st.items) ? st.items : [];
		},

		queryBuilderSuggestOpen(r) {
			const st = this.queryBuilderSuggestStateFor(r);
			return !!st.open && Array.isArray(st.items) && st.items.length > 0;
		},

		queryBuilderSuggestLoading(r) {
			const st = this.queryBuilderSuggestStateFor(r);
			return !!st.loading;
		},

		queryBuilderSelectSuggestion(r, item) {
			if (!r || !item) return;
			r.value = String(item.value || '');
			this.queryBuilderCloseSuggestions(r);
		},

		queryBuilderCloseSuggestions(r) {
			const key = this.queryBuilderSuggestKey(r);
			if (!key) return;
			if (!this.queryBuilderSuggestState[key]) this.queryBuilderSuggestState[key] = {};
			this.queryBuilderSuggestState[key].open = false;
		},

		queryBuilderOnRestrictionValueInput(r) {
			if (!r) return;
			const key = this.queryBuilderSuggestKey(r);
			if (!key) return;
			if (!this.queryBuilderCanSuggest(r)) {
				this.queryBuilderCloseSuggestions(r);
				return;
			}
			const val = String((r.value || '')).trim();
			if (val.length < 2) {
				this.queryBuilderCloseSuggestions(r);
				return;
			}
			if (!this._qbSuggestTimers || typeof this._qbSuggestTimers !== 'object') this._qbSuggestTimers = {};
			if (this._qbSuggestTimers[key]) {
				clearTimeout(this._qbSuggestTimers[key]);
				delete this._qbSuggestTimers[key];
			}
			this._qbSuggestTimers[key] = setTimeout(() => {
				delete this._qbSuggestTimers[key];
				try {
					console.log('[flexicorp-qb] suggest trigger', {
						field: String((r.field || '')).trim(),
						operator: String((r.operator || '')).trim(),
						query: String((r.value || '')).trim(),
						mode: 'debounced',
					});
				} catch (_) {}
				this.queryBuilderFetchSuggestions(r);
			}, 220);
		},

		async queryBuilderFetchSuggestions(r) {
			if (!r || !this.queryBuilderCanSuggest(r)) return;
			const key = this.queryBuilderSuggestKey(r);
			const field = String((r.field || '')).trim();
			const q = String((r.value || '')).trim();
			if (!key || !field || q.length < 2) return;
			if (!this.queryBuilderSuggestState || typeof this.queryBuilderSuggestState !== 'object') this.queryBuilderSuggestState = {};
			if (!this.queryBuilderSuggestState[key]) this.queryBuilderSuggestState[key] = {};
			const reqId = this._qbSuggestReqSeq++;
			this.queryBuilderSuggestState[key].loading = true;
			this.queryBuilderSuggestState[key].requestId = reqId;
			try {
				let formData = null;
				if (typeof this.buildCommonRequestData === 'function') formData = this.buildCommonRequestData();
				else {
					formData = new FormData();
					formData.set('action', this.action || 'flexicorp');
				}
				formData.set('ajax', '1');
				formData.set('qb_suggest_values', '1');
				formData.set('field', field);
				formData.set('q', q);
				formData.set('limit', '30');
				try {
					console.log('[flexicorp-qb] suggest request', { field, q, limit: 30 });
				} catch (_) {}
				const resp = await fetch(this.getRequestUrl(), {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				});
				const data = await resp.json();
				const current = this.queryBuilderSuggestState[key] || {};
				if (current.requestId !== reqId) return;
				const items = data && Array.isArray(data.items) ? data.items : [];
				this.queryBuilderSuggestState[key] = {
					...current,
					loading: false,
					open: !!(data && data.enabled && items.length),
					items,
					reason: data && data.reason ? String(data.reason) : '',
				};
				try {
					console.log('[flexicorp-qb] suggest response', {
						field,
						query: q,
						enabled: !!(data && data.enabled),
						reason: data && data.reason ? String(data.reason) : '',
						count: items.length,
						truncated: !!(data && data.truncated),
					});
				} catch (_) {}
			} catch (_) {
				const current = this.queryBuilderSuggestState[key] || {};
				if (current.requestId !== reqId) return;
				this.queryBuilderSuggestState[key] = { ...current, loading: false, open: false, items: [], reason: 'error' };
				try {
					console.log('[flexicorp-qb] suggest response', { field, query: q, enabled: false, reason: 'error', count: 0 });
				} catch (_) {}
			}
		},

		queryBuilderOnRestrictionFieldChange(r) {
			const f = String((r && r.field) || '').trim();
			if (!f) return;
			this.queryBuilderEnsureFieldUiLoaded(f);
			this.queryBuilderCloseSuggestions(r);
			if (this.queryBuilderRestrictionInputType(r) === 'select') {
				const opts = this.queryBuilderRestrictionOptions(r);
				if (opts.length && !opts.some((x) => String(x.value) === String(r.value || ''))) {
					r.value = '';
				}
			}
		},

		async queryBuilderEnsureFieldUiLoaded(field) {
			const f = String(field || '').trim();
			if (!f) return;
			if (this.queryBuilderFieldUi && this.queryBuilderFieldUi[f]) return;
			if (this.queryBuilderFieldUiLoading && this.queryBuilderFieldUiLoading[f]) return;
			if (!this.queryBuilderFieldUiLoading || typeof this.queryBuilderFieldUiLoading !== 'object') this.queryBuilderFieldUiLoading = {};
			this.queryBuilderFieldUiLoading[f] = true;
			try {
				let formData = null;
				if (typeof this.buildCommonRequestData === 'function') {
					formData = this.buildCommonRequestData();
				} else {
					formData = new FormData();
					formData.set('action', this.action || 'flexicorp');
				}
				formData.set('ajax', '1');
				formData.set('qb_field_values', '1');
				formData.set('qb_debug', '1');
				formData.set('field', f);
				const resp = await fetch(this.getRequestUrl(), {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				});
				const data = await resp.json();
				if (!this.queryBuilderFieldUi || typeof this.queryBuilderFieldUi !== 'object') this.queryBuilderFieldUi = {};
				this.queryBuilderFieldUi[f] = {
					input: data && data.input ? String(data.input) : 'text',
					options: data && Array.isArray(data.options) ? data.options : [],
					source: data && data.source ? String(data.source) : '',
				};
			} catch (err) {
				if (!this.queryBuilderFieldUi || typeof this.queryBuilderFieldUi !== 'object') this.queryBuilderFieldUi = {};
				this.queryBuilderFieldUi[f] = { input: 'text', options: [], source: 'error' };
			} finally {
				if (this.queryBuilderFieldUiLoading && typeof this.queryBuilderFieldUiLoading === 'object') {
					delete this.queryBuilderFieldUiLoading[f];
				}
			}
		},

		queryBuilderRemoveRestriction(restrictionId) {
			const token = this.queryBuilderSelectedToken();
			if (!token || !Array.isArray(token.restrictions)) return;
			const rid = String(restrictionId || '');
			token.restrictions = token.restrictions.filter((r) => String(r.id) !== rid);
			if (!token.restrictions.length) token.restrictions = [this.queryBuilderMakeRestriction()];
		},

		queryBuilderEscapeRegexValue(raw) {
			return String(raw || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
		},

		queryBuilderCompileRestriction(r) {
			const field = String((r && r.field) || '').trim();
			const operator = String((r && r.operator) || 'matches').trim();
			const valueRaw = String((r && r.value) || '');
			if (!field || valueRaw.trim() === '') return '';
			let regex = this.queryBuilderEscapeRegexValue(valueRaw);
			if (operator === 'contains') regex = '.*' + regex + '.*';
			else if (operator === 'startswith') regex = regex + '.*';
			else if (operator === 'endsin') regex = '.*' + regex;
			return `${field} = "${regex}"`;
		},

		queryBuilderCompileToken(tok, childrenByParent, seen) {
			const token = tok && typeof tok === 'object' ? tok : {};
			const tokenId = String(token.id || '');
			if (seen.has(tokenId)) return '';
			seen.add(tokenId);
			const restrictions = Array.isArray(token.restrictions) ? token.restrictions : [];
			const parts = restrictions
				.map((r) => this.queryBuilderCompileRestriction(r))
				.filter((x) => String(x).trim() !== '');
			const kids = this.queryBuilderSupportsDependencies() ? (childrenByParent[tokenId] || []) : [];
			const depParts = kids.map((childTok) => {
				const rel = String((childTok && childTok.dependencyRelation) || '').trim() || 'dep';
				const childExpr = this.queryBuilderCompileToken(childTok, childrenByParent, seen);
				if (!childExpr) return '';
				return `${rel} ${childExpr}`;
			}).filter((x) => String(x).trim() !== '');
			const all = [];
			if (parts.length) all.push(parts.join(' & '));
			if (depParts.length) all.push(depParts.join(' & '));
			const tname = String((token && token.name) || '').trim();
			const tprefix = tname ? `${tname}:` : '';
			if (!all.length) return `${tprefix}[]`;
			return `${tprefix}[${all.join(' & ')}]`;
		},

		queryBuilderCompileRestrictionList(restrictions) {
			const list = Array.isArray(restrictions) ? restrictions : [];
			return list
				.map((r) => this.queryBuilderCompileRestriction(r))
				.filter((x) => String(x).trim() !== '');
		},

		queryBuilderStripTokenAlias(expr) {
			const text = String(expr || '').trim();
			return text.replace(/^\s*[A-Za-z0-9_]+\s*:\s*/, '');
		},

		queryBuilderDetectTokenAlias(expr) {
			const m = String(expr || '').trim().match(/^\s*([A-Za-z0-9_]+)\s*:/);
			return m ? String(m[1] || '').trim() : '';
		},

		compileBuilderStateToQuery(state, qlPack) {
			const pack = qlPack && typeof qlPack === 'object' ? qlPack : { family: 'cqlCore', enabled: false };
			if (!pack.enabled || pack.family !== 'cqlCore') return '';
			const st = state && typeof state === 'object' ? state : {};
			const tokens = Array.isArray(st.tokens) ? st.tokens : [];
			const byId = {};
			tokens.forEach((t) => { byId[String((t && t.id) || '')] = t; });
			const childrenByParent = {};
			if (this.queryBuilderSupportsDependencies()) {
				tokens.forEach((t) => {
					if (!t || typeof t !== 'object') return;
					const parent = String(t.dependsOn || '').trim();
					const tid = String(t.id || '');
					if (!parent || !tid || !byId[parent] || parent === tid) return;
					if (!Array.isArray(childrenByParent[parent])) childrenByParent[parent] = [];
					childrenByParent[parent].push(t);
				});
			}
			const topLevel = this.queryBuilderSupportsDependencies()
				? tokens.filter((t) => !String((t && t.dependsOn) || '').trim() || !byId[String((t && t.dependsOn) || '').trim()])
				: tokens;
			const seen = new Set();
			const tokenExpr = topLevel
				.map((tok) => this.queryBuilderCompileToken(tok, childrenByParent, seen))
				.filter((x) => String(x).trim() !== '');
			if (!tokenExpr.length) return '';
			const stAlign = st.alignment && typeof st.alignment === 'object' ? st.alignment : {};
			const alignEnabled = !!stAlign.enabled && this.queryBuilderSupportsAlignment();
			let cql = '';
			if (alignEnabled) {
				if (tokenExpr.length !== 1) return '';
				const firstTok = Array.isArray(tokens) && tokens.length ? tokens[0] : null;
				const sourceAliasCfg = String((firstTok && firstTok.name) || stAlign.sourceAlias || 'a').trim() || 'a';
				const targetAlias = String(stAlign.targetAlias || 'b').trim() || 'b';
				const joinField = String(stAlign.joinField || '').trim() === 's_tuid' ? 's_tuid' : 'tuid';
				const sourceExprRaw = String(tokenExpr[0] || '').trim();
				const sourceAliasFound = this.queryBuilderDetectTokenAlias(sourceExprRaw);
				const sourceAlias = sourceAliasFound || sourceAliasCfg;
				const sourceExpr = sourceAliasFound ? sourceExprRaw : `${sourceAlias}:${this.queryBuilderStripTokenAlias(sourceExprRaw)}`;
				const targetParts = this.queryBuilderCompileRestrictionList(stAlign.targetRestrictions);
				let targetExpr = '';
				if (joinField === 's_tuid') {
					targetExpr = targetParts.length
						? `${targetAlias}:<s ${targetParts.join(' & ')}>`
						: `${targetAlias}:<s>`;
				} else {
					targetExpr = targetParts.length
						? `${targetAlias}:[${targetParts.join(' & ')}]`
						: `${targetAlias}:[]`;
				}
				cql = `${sourceExpr} with ${targetExpr} :: ${sourceAlias}.${joinField} = ${targetAlias}.${joinField}`;
			} else {
				cql = tokenExpr.join(' ');
				if (!/\swithin\s/.test(cql)) cql += ' within text';
			}
			const qname = String((st && st.queryName) || '').trim();
			if (qname) cql = `${qname} = ${cql}`;
			return cql;
		},

		queryBuilderUnsupportedReason(query, qlPack) {
			const pack = qlPack && typeof qlPack === 'object' ? qlPack : { family: 'cqlCore', enabled: false };
			const q = String(query || '').trim();
			if (!q) return '';
			if (!pack.enabled || pack.family !== 'cqlCore') return 'Current query language is not supported by this builder.';
			if (/::/.test(q) && !/\bwith\b/i.test(q)) return 'Global restrictions (:: ...) are not supported in this MVP.';
			if (/ within\s+(?!text\b)/i.test(q)) return 'Non-text region restrictions (within <region>) are not supported in this MVP.';
			const tokenMatches = q.match(/\[[^\]]*\]/g) || [];
			if (tokenMatches.length > 1) return 'Multi-token sequence queries are not supported in this MVP.';
			if (/[()|]/.test(q)) return 'Grouped/alternative query expressions are not supported in this MVP.';
			return 'Current query shape cannot yet be represented by this MVP token builder.';
		},

		parseQueryToBuilderState(query, qlPack) {
			const pack = qlPack && typeof qlPack === 'object' ? qlPack : { family: 'cqlCore', enabled: false };
			const q = String(query || '').trim();
			if (!q || !pack.enabled || pack.family !== 'cqlCore') return null;
			let queryName = '';
			let qBody = q;
			const qAssign = q.match(/^\s*([A-Za-z0-9_]+)\s*=\s*(.+)$/);
			if (qAssign) {
				queryName = String(qAssign[1] || '').trim();
				qBody = String(qAssign[2] || '').trim();
			}
			const parseRestrictions = (raw) => {
				const text = String(raw || '').trim();
				if (!text) return [];
				const out = [];
				const re = /([A-Za-z0-9_]+)\s*=\s*"([^"]*)"/g;
				let m;
				while ((m = re.exec(text)) !== null) {
					let op = 'matches';
					let val = String(m[2] || '');
					const c1 = val.match(/^\.\*(.*)\.\*$/);
					const c2 = val.match(/^(.*)\.\*$/);
					const c3 = val.match(/^\.\*(.*)$/);
					if (c1) {
						op = 'contains';
						val = c1[1];
					} else if (c2) {
						op = 'startswith';
						val = c2[1];
					} else if (c3) {
						op = 'endsin';
						val = c3[1];
					}
					out.push({
						id: 'r' + (this._qbSeq++),
						field: String(m[1] || '').trim(),
						operator: op,
						value: val,
					});
				}
				return out;
			};
			const mAligned = qBody.match(/^\s*(.+?)\s+with\s+(.+?)\s*::\s*([A-Za-z0-9_]+)\.(tuid|s_tuid)\s*=\s*([A-Za-z0-9_]+)\.\4\s*$/i);
			if (mAligned) {
				const left = String(mAligned[1] || '').trim();
				const right = String(mAligned[2] || '').trim();
				const sourceAlias = String(mAligned[3] || '').trim() || 'a';
				const joinField = String(mAligned[4] || '').trim().toLowerCase() === 's_tuid' ? 's_tuid' : 'tuid';
				const targetAlias = String(mAligned[5] || '').trim() || 'b';
				const mLeft = left.match(/^\s*(?:([A-Za-z0-9_]+)\s*:\s*)?\[\s*([^\]]*)\s*\]\s*$/);
				if (!mLeft) return null;
				const leftAlias = String(mLeft[1] || '').trim() || sourceAlias;
				const leftRestrictions = parseRestrictions(String(mLeft[2] || '').trim());
				const mRightToken = right.match(/^\s*(?:([A-Za-z0-9_]+)\s*:\s*)?\[\s*([^\]]*)\s*\]\s*$/);
				const mRightSentence = right.match(/^\s*(?:([A-Za-z0-9_]+)\s*:\s*)?<s(?:\s+([^>]*))?\s*>\s*$/i);
				let rightRestrictions = [];
				let parsedJoinField = joinField;
				let rightAlias = targetAlias;
				if (mRightToken) {
					rightAlias = String(mRightToken[1] || '').trim() || targetAlias;
					rightRestrictions = parseRestrictions(String(mRightToken[2] || '').trim());
					if (parsedJoinField === 's_tuid') parsedJoinField = 'tuid';
				} else if (mRightSentence) {
					rightAlias = String(mRightSentence[1] || '').trim() || targetAlias;
					rightRestrictions = parseRestrictions(String(mRightSentence[2] || '').trim());
					parsedJoinField = 's_tuid';
				} else {
					return null;
				}
				return {
					tokens: [{
						id: 't' + (this._qbSeq++),
						label: 'Token 1',
						name: leftAlias,
						dependsOn: '',
						dependencyRelation: '',
						restrictions: leftRestrictions.length ? leftRestrictions : [this.queryBuilderMakeRestriction()],
					}],
					selectedTokenId: '',
					globals: [],
					regions: [],
					relations: [],
					queryName,
					alignment: {
						enabled: true,
						joinField: parsedJoinField,
						sourceAlias: leftAlias || sourceAlias || 'a',
						targetAlias: rightAlias || targetAlias || 'b',
						targetRestrictions: rightRestrictions.length ? rightRestrictions : [this.queryBuilderMakeRestriction()],
					},
				};
			}
			const mNamed = qBody.match(/^\s*(?:([A-Za-z0-9_]+)\s*:\s*)?\[\s*([A-Za-z0-9_]+)\s*=\s*"([^"]*)"\s*\]\s*(?:within\s+([A-Za-z0-9_]+))?\s*$/);
			if (!mNamed) return null;
			const tokenName = mNamed[1] ? String(mNamed[1]) : '';
			const field = mNamed[2];
			let val = mNamed[3];
			let op = 'matches';
			const c1 = val.match(/^\.\*(.*)\.\*$/);
			const c2 = val.match(/^(.*)\.\*$/);
			const c3 = val.match(/^\.\*(.*)$/);
			if (c1) {
				op = 'contains';
				val = c1[1];
			} else if (c2) {
				op = 'startswith';
				val = c2[1];
			} else if (c3) {
				op = 'endsin';
				val = c3[1];
			}
			return {
				tokens: [{
					id: 't' + (this._qbSeq++),
					label: 'Token 1',
					name: tokenName,
					restrictions: [{
						id: 'r' + (this._qbSeq++),
						field,
						operator: op,
						value: val,
					}],
				}],
				selectedTokenId: '',
				globals: [],
				regions: [],
				relations: [],
				queryName,
				alignment: {
					enabled: false,
					joinField: 'tuid',
					sourceAlias: 'a',
					targetAlias: 'b',
					targetRestrictions: [this.queryBuilderMakeRestriction()],
				},
			};
		},

		queryBuilderLoadFromQuery() {
			const pack = this.queryBuilderCurrentPack();
			const parsed = this.parseQueryToBuilderState(this.search && this.search.query, pack);
			if (!parsed) {
				const reason = this.queryBuilderUnsupportedReason(this.search && this.search.query, pack);
				this.queryBuilderState.warning = reason || 'Current query is not a simple token query that this MVP can load yet.';
				return;
			}
			this.queryBuilderState.tokens = parsed.tokens;
			this.queryBuilderState.selectedTokenId = parsed.tokens.length ? parsed.tokens[0].id : '';
			this.queryBuilderState.globals = [];
			this.queryBuilderState.regions = [];
			this.queryBuilderState.relations = [];
			this.queryBuilderState.queryName = String(parsed.queryName || '');
			this.queryBuilderState.alignment = parsed.alignment && typeof parsed.alignment === 'object'
				? parsed.alignment
				: {
					enabled: false,
					joinField: 'tuid',
					sourceAlias: 'a',
					targetAlias: 'b',
					targetRestrictions: [this.queryBuilderMakeRestriction()],
				};
			this.queryBuilderState.warning = '';
			this.queryBuilderEnsureTokenSeed();
			this.queryBuilderEnsureAlignmentSeed();
		},

		queryBuilderAutoLoadFromCurrentQuery() {
			const pack = this.queryBuilderCurrentPack();
			const q = this.search && typeof this.search.query === 'string' ? this.search.query : '';
			if (!String(q).trim()) {
				this.queryBuilderState.warning = '';
				this.queryBuilderEnsureTokenSeed();
				return;
			}
			const parsed = this.parseQueryToBuilderState(q, pack);
			if (parsed) {
				this.queryBuilderState.tokens = parsed.tokens;
				this.queryBuilderState.selectedTokenId = parsed.tokens.length ? parsed.tokens[0].id : '';
				this.queryBuilderState.globals = [];
				this.queryBuilderState.regions = [];
				this.queryBuilderState.relations = [];
				this.queryBuilderState.queryName = String(parsed.queryName || '');
				this.queryBuilderState.alignment = parsed.alignment && typeof parsed.alignment === 'object'
					? parsed.alignment
					: {
						enabled: false,
						joinField: 'tuid',
						sourceAlias: 'a',
						targetAlias: 'b',
						targetRestrictions: [this.queryBuilderMakeRestriction()],
					};
				this.queryBuilderState.warning = '';
				this.queryBuilderEnsureTokenSeed();
				this.queryBuilderEnsureAlignmentSeed();
				return;
			}
			const reason = this.queryBuilderUnsupportedReason(q, pack);
			this.queryBuilderState.warning = reason || 'Query cannot currently be represented by the Query Builder.';
			this.queryBuilderEnsureTokenSeed();
		},

		queryBuilderApplyToQuery() {
			if (!this.queryBuilderState || !this.queryBuilderState.enabled) return;
			const pack = this.queryBuilderCurrentPack();
			if (!this.queryBuilderSupportsCurrentLanguage()) {
				this.queryBuilderState.warning = 'Selected query language is not yet supported by this builder.';
				return;
			}
			const cql = this.compileBuilderStateToQuery(this.queryBuilderState, pack);
			if (!cql) {
				const alignOn = !!(this.queryBuilderState && this.queryBuilderState.alignment && this.queryBuilderState.alignment.enabled);
				this.queryBuilderState.warning = alignOn
					? 'Alignment mode currently supports one source token plus optional target restrictions.'
					: 'Add at least one token restriction with field and value.';
				try {
					console.log('[flexicorp-qb] apply blocked: empty compiled query', {
						state: this.queryBuilderState,
					});
				} catch (_) {}
				return;
			}
			this.search.query = cql;
			this.queryBuilderState.warning = '';
			if (typeof this.scheduleQueryHighlight === 'function') this.scheduleQueryHighlight();
			this.queryBuilderClose();
		},

		queryBuilderPreviewQuery() {
			const pack = this.queryBuilderCurrentPack();
			if (!pack.enabled || pack.family !== 'cqlCore') return '';
			return this.compileBuilderStateToQuery(this.queryBuilderState, pack);
		},
	};
};
