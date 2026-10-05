/*
 * Flexicorp compact Query Builder (the pop-up next to the query box).
 *
 * Modelled on TEITOK's cwb.php query builder: every token attribute of the corpus is a form row
 * (match type + value), UD features expand into one select per feature, and the document /
 * region attributes sit in their own column. On top of that:
 *   - several tokens, shown as a strip; between two tokens a connector: next to, a gap of
 *     [min,max] tokens, or (pando-cql, corpora with dependencies) a dependency operator > < >> <<
 *   - an aligned (parallel) query for pando-cql: the whole sequence on the source side, a
 *     target language and target restrictions, joined on tuid or s_tuid
 *   - the query text below the form is editable and read back into the form while it stays
 *     within what the builder can show
 *   - an optional link to a full-screen query builder (flexicorp/query_builder_full_url)
 *
 * Output is dialect-aware (see qbDialect): document filters are inside the first token for
 * pando-cql, a `:: match.x = "v"` global condition for cwb-cql / clickcql, and
 * `within <text x="v"/>` for manatee-cql / bcql.
 */
window.flexicorpQuerybuilderExtend = function flexicorpQuerybuilderExtend() {
	const QB_OPERATORS = [
		{ value: 'matches', label: 'matches' },
		{ value: 'is', label: 'is' },
		{ value: 'contains', label: 'contains' },
		{ value: 'startswith', label: 'starts with' },
		{ value: 'endsin', label: 'ends with' },
		{ value: 'not', label: 'is not' },
	];
	const QB_SELECT_OPERATORS = [
		{ value: 'is', label: 'is' },
		{ value: 'not', label: 'is not' },
	];
	const QB_DEP_LINKS = [
		{ value: '>', label: 'head of ›', title: 'left token is the head of the right token (>)' },
		{ value: '<', label: '‹ dependent of', title: 'left token is a dependent of the right token (<)' },
		{ value: '>>', label: 'ancestor of ››', title: 'left token dominates the right token (>>)' },
		{ value: '<<', label: '‹‹ descendant of', title: 'left token is dominated by the right token (<<)' },
	];
	// Inside a quoted CQL string pando reads \\ as one backslash (so a regex escape is written \\.),
	// CQP / Manatee / BlackLab pass the backslash on to the regex (\. as typed); all escape a quote as \".
	const qbEscape = (raw, doubleBackslash) => {
		const src = String(raw == null ? '' : raw);
		let out = '';
		for (let i = 0; i < src.length; i++) {
			const ch = src[i];
			if (ch === '\\') {
				if (doubleBackslash) out += '\\\\';
				else if (src[i + 1] === '"') { out += '\\"'; i++; }
				else out += ch;
			} else if (ch === '"') out += '\\"';
			else out += ch;
		}
		return out;
	};
	const qbUnescape = (raw, doubleBackslash) => {
		const s = String(raw == null ? '' : raw);
		return doubleBackslash ? s.replace(/\\(["\\])/g, '$1') : s.replace(/\\"/g, '"');
	};
	const qbRegexQuote = (raw) => String(raw == null ? '' : raw).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const qbHasRegex = (raw) => /[.*+?^${}()|[\]\\]/.test(String(raw || ''));
	const qbRegexUnquote = (raw) => String(raw || '').replace(/\\([.*+?^${}()|[\]\\])/g, '$1');

	return {
		queryBuilderConfig: {
			enabled: false,
			operators: ['matches', 'contains', 'startswith', 'endsin'],
			sharedBase: 'cqlCore',
			qlPacks: {},
			fullBuilderUrl: '',
		},
		queryBuilderState: {
			enabled: false,
			open: false,
			qlFamily: 'cqlCore',
			queryName: '',
			warning: '',
			tokens: [],
			selectedTokenId: '',
			docs: {},
			within: 'text',
			alignment: {
				enabled: false,
				joinField: 'tuid',
				targetAlias: 'b',
				targetLang: '',
				targetRestrictions: [],
			},
			text: '',
			textEdited: false,
			textNote: '',
		},
		queryBuilderFieldUi: {},
		queryBuilderFieldUiLoading: {},
		queryBuilderSuggestState: {},
		_qbSuggestTimers: {},
		_qbSuggestReqSeq: 1,
		_qbTokenSeq: 1,
		_qbSeq: 1,
		_qbParseTimer: null,

		// ------------------------------------------------------------------ lifecycle

		initQueryBuilderModule() {
			this.queryBuilderRefreshFromState();
		},

		onQueryBuilderStateApplied() {
			this.queryBuilderRefreshFromState();
		},

		queryBuilderRefreshFromState() {
			const cfg = this.queryBuilderConfig && typeof this.queryBuilderConfig === 'object' ? this.queryBuilderConfig : {};
			if (!this.queryBuilderState || typeof this.queryBuilderState !== 'object') this.queryBuilderState = {};
			const st = this.queryBuilderState;
			st.enabled = !!cfg.enabled;
			st.qlFamily = this.queryBuilderFamilyForLanguage(this.settings && this.settings.queryLanguage);
			if (!Array.isArray(st.tokens)) st.tokens = [];
			if (!st.docs || typeof st.docs !== 'object') st.docs = {};
			if (typeof st.within !== 'string') st.within = 'text';
			if (!st.alignment || typeof st.alignment !== 'object') st.alignment = {};
			const a = st.alignment;
			if (typeof a.enabled !== 'boolean') a.enabled = false;
			a.joinField = String(a.joinField || '').trim() === 's_tuid' ? 's_tuid' : 'tuid';
			a.targetAlias = String(a.targetAlias || '').trim() || 'b';
			if (typeof a.targetLang !== 'string') a.targetLang = '';
			if (!Array.isArray(a.targetRestrictions)) a.targetRestrictions = [];
			if (typeof st.open !== 'boolean') st.open = false;
			if (typeof st.warning !== 'string') st.warning = '';
			if (typeof st.queryName !== 'string') st.queryName = '';
			if (typeof st.text !== 'string') st.text = '';
			if (typeof st.textEdited !== 'boolean') st.textEdited = false;
			if (typeof st.textNote !== 'string') st.textNote = '';
			if (!this.queryBuilderFieldUi || typeof this.queryBuilderFieldUi !== 'object') this.queryBuilderFieldUi = {};
			if (!this.queryBuilderFieldUiLoading || typeof this.queryBuilderFieldUiLoading !== 'object') this.queryBuilderFieldUiLoading = {};
			if (!this.queryBuilderSuggestState || typeof this.queryBuilderSuggestState !== 'object') this.queryBuilderSuggestState = {};
			if (!this._qbSuggestTimers || typeof this._qbSuggestTimers !== 'object') this._qbSuggestTimers = {};
			if (!Number.isFinite(this._qbTokenSeq) || this._qbTokenSeq < 1) this._qbTokenSeq = 1;
			this.queryBuilderEnsureTokenSeed();
			if (!this.qbDialect().align) a.enabled = false;
		},

		// ------------------------------------------------------------------ dialects

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
			return { id: ql, family: String(pack.family || 'unsupported'), enabled: !!pack.enabled };
		},

		queryBuilderSupportsCurrentLanguage() {
			const pack = this.queryBuilderCurrentPack();
			return !!(this.queryBuilderState && this.queryBuilderState.enabled && pack.enabled && pack.family === 'cqlCore');
		},

		/**
		 * How the current query language spells the things the builder emits.
		 *  docs:   'token' (region attribute inside the first token), 'global' (:: match.x = "v"),
		 *          'within' (within <text x="v"/>)
		 *  feats:  'kv' (feats/Tense="Past") or 'regex' (feats="(.*\|)?Tense=Past(\|.*)?")
		 *  nocase: '%c' flag, '(?i)' regex prefix, or '' (not offered)
		 */
		qbDialect() {
			const ql = String((this.settings && this.settings.queryLanguage) || '').trim().toLowerCase();
			if (ql === 'pando-cql') {
				return { id: ql, docs: 'token', feats: 'kv', nocase: '%c', deps: this.queryBuilderSupportsDependencies(), align: true, pandoStrings: true };
			}
			if (ql === 'manatee-cql') return { id: ql, docs: 'within', feats: 'regex', nocase: '', deps: false, align: false };
			if (ql === 'bcql' || ql === 'blacklab') return { id: ql, docs: 'within', feats: 'regex', nocase: '(?i)', deps: false, align: false };
			return { id: ql, docs: 'global', feats: 'regex', nocase: '%c', deps: false, align: false };
		},

		queryBuilderSupportsAlignment() {
			return this.queryBuilderSupportsCurrentLanguage() && this.qbDialect().align;
		},

		queryBuilderDependencyLanguageAllowed() {
			const ql = String((this.settings && this.settings.queryLanguage) || '').trim().toLowerCase();
			return ql === 'pando-cql';
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

		qbFullBuilderUrl() {
			const cfg = this.queryBuilderConfig && typeof this.queryBuilderConfig === 'object' ? this.queryBuilderConfig : {};
			const base = String(cfg.fullBuilderUrl || '').trim();
			if (!base) return '';
			const q = this.qbCurrentText();
			if (!q) return base;
			return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'query=' + encodeURIComponent(q)
				+ '&query_language=' + encodeURIComponent(String((this.settings && this.settings.queryLanguage) || ''));
		},

		// ------------------------------------------------------------------ corpus definitions

		qbFieldOptionsFromCatalog() {
			const rootEl = typeof document !== 'undefined' ? document.getElementById('flexicorp-root') : null;
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			const cat = this.attributeCatalog && typeof this.attributeCatalog === 'object' ? this.attributeCatalog : {};
			const noShow = new Set(Array.isArray(cat.noshow_keys) ? cat.noshow_keys.map((x) => String(x)) : []);
			const labelMap = typeof fns.frequencySelectableFieldLabelMap === 'function'
				? fns.frequencySelectableFieldLabelMap(this, rootEl)
				: Object.assign({},
					cat.searchable_labels && typeof cat.searchable_labels === 'object' ? cat.searchable_labels : {},
					cat.searchable_display_labels && typeof cat.searchable_display_labels === 'object' ? cat.searchable_display_labels : {});
			const fieldDetails = cat.field_details && typeof cat.field_details === 'object' ? cat.field_details : {};
			// Settings order (cqp/pattributes, cqp/sattributes) as in TEITOK's builder; catalog keys first.
			const keys = [];
			const seen = new Set();
			Object.keys(fieldDetails).concat(Object.keys(labelMap || {})).forEach((k) => {
				if (!seen.has(k) && Object.prototype.hasOwnProperty.call(labelMap || {}, k)) { seen.add(k); keys.push(k); }
			});
			const out = [];
			keys.forEach((key) => {
				const field = String(key || '').trim();
				if (!field || noShow.has(field)) return;
				const det = fieldDetails[field] && typeof fieldDetails[field] === 'object' ? fieldDetails[field] : {};
				if (det.nosearch) return;
				const adminOnly = !!det.admin;
				if (adminOnly && !this.isAdmin) return;
				const label = String((labelMap && labelMap[field]) || field).trim();
				out.push({ value: field, label: label || field, admin: adminOnly, type: String(det.type || '').toLowerCase() });
			});
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
				const region = String(meta.region || '').trim();
				const kind = String(meta.kind || '').toLowerCase();
				if ((kind !== 'region' && kind !== 'documents') || !region || seen.has(region)) return;
				seen.add(region);
				out.push(region);
			});
			const searchable = cat.searchable_labels && typeof cat.searchable_labels === 'object' ? cat.searchable_labels : {};
			Object.keys(searchable).forEach((field) => {
				const f = String(field || '').trim();
				const pos = f.indexOf('_');
				if (pos <= 0) return;
				const reg = f.slice(0, pos);
				if (!reg || seen.has(reg) || !(fieldMeta[f] && fieldMeta[f].kind !== 'token')) return;
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
			if (lower.startsWith('text_') || lower.startsWith('doc_') || lower.startsWith('document_')) {
				return { kind: 'documents', region: 'text' };
			}
			const pos = field.indexOf('_');
			if (pos > 0) {
				const region = field.slice(0, pos);
				const known = knownRegionsSet || new Set(this.queryBuilderKnownRegions());
				if (known.has(region)) return { kind: 'region', region };
			}
			return { kind: 'token', region: '' };
		},

		queryBuilderFieldGroups() {
			const fields = this.qbFieldOptionsFromCatalog();
			const knownRegions = new Set(this.queryBuilderKnownRegions());
			const tokenOptions = [];
			const documentOptions = [];
			const byRegion = {};
			const regionOrder = [];
			fields.forEach((opt) => {
				const kind = this.queryBuilderFieldKind(opt.value, knownRegions);
				if (kind.kind === 'documents') { documentOptions.push(opt); return; }
				if (kind.kind === 'region' && kind.region) {
					if (!byRegion[kind.region]) { byRegion[kind.region] = []; regionOrder.push(kind.region); }
					byRegion[kind.region].push(opt);
					return;
				}
				tokenOptions.push(opt);
			});
			const groups = [];
			if (tokenOptions.length) groups.push({ id: 'token', region: '', label: 'Token attributes', options: tokenOptions });
			if (documentOptions.length) groups.push({ id: 'documents', region: 'text', label: 'Documents', options: documentOptions });
			regionOrder.forEach((region) => {
				if (region === 'text') return;
				groups.push({ id: 'region:' + region, region, label: this.qbRegionLabel(region), options: byRegion[region] });
			});
			return groups;
		},

		queryBuilderFieldOptions() {
			return this.queryBuilderFieldGroups().reduce((acc, g) => acc.concat(g.options || []), []);
		},

		qbRegionLabel(region) {
			const r = String(region || '');
			if (r === 's') return 'Sentence';
			if (r === 'p') return 'Paragraph';
			if (r === 'u') return 'Utterance';
			if (r === 'text') return 'Documents';
			return 'Region <' + r + '>';
		},

		qbIsAlignField(field) {
			return /(^|_)tuid$/i.test(String(field || ''));
		},

		/** Token attributes shown as form rows (TEITOK order, without alignment ids). */
		qbTokenFields() {
			const grp = this.queryBuilderFieldGroups().find((g) => g.id === 'token');
			return (grp ? grp.options : []).filter((o) => !this.qbIsAlignField(o.value));
		},

		/** Document / region attribute groups for the right-hand column. */
		qbDocGroups() {
			const groups = this.queryBuilderFieldGroups()
				.filter((g) => g.id !== 'token')
				.map((g) => ({ ...g, options: g.options.filter((o) => !this.qbIsAlignField(o.value)) }))
				.filter((g) => g.options.length);
			// Region conditions read from a typed query on attributes the corpus settings do not list.
			const known = new Set();
			groups.forEach((g) => g.options.forEach((o) => known.add(o.value)));
			const docs = this.queryBuilderState && this.queryBuilderState.docs ? this.queryBuilderState.docs : {};
			const other = Object.keys(docs)
				.filter((k) => !known.has(k) && docs[k] && String(docs[k].value || '').trim() !== '')
				.map((k) => ({ value: k, label: k, admin: false, type: '' }));
			if (other.length) groups.push({ id: 'other', region: '', label: 'Other conditions', options: other });
			return groups;
		},

		qbFieldType(field) {
			const cat = this.attributeCatalog && typeof this.attributeCatalog === 'object' ? this.attributeCatalog : {};
			const det = cat.field_details && cat.field_details[field] && typeof cat.field_details[field] === 'object' ? cat.field_details[field] : {};
			return String(det.type || '').toLowerCase();
		},

		qbIsFeatsField(field) {
			const t = this.qbFieldType(field);
			if (t === 'udfeats') return true;
			const ui = this.queryBuilderFieldUi && this.queryBuilderFieldUi[field];
			return !!(ui && ui.input === 'udfeats');
		},

		qbFieldLabel(field) {
			const opt = this.queryBuilderFieldOptions().find((o) => o.value === field);
			return opt ? opt.label : field;
		},

		qbLanguageField() {
			const all = this.queryBuilderFieldOptions();
			const hit = all.find((o) => /^text_(lang|language)$/i.test(o.value))
				|| all.find((o) => /(^|_)(lang|language)$/i.test(o.value) && this.queryBuilderFieldKind(o.value).kind !== 'token');
			return hit ? hit.value : '';
		},

		qbWithinOptions() {
			const regions = this.queryBuilderKnownRegions();
			const out = [{ value: '', label: 'anywhere' }];
			['s', 'p', 'u'].forEach((r) => { if (regions.includes(r)) out.push({ value: r, label: this.qbRegionLabel(r).toLowerCase() + ' (' + r + ')' }); });
			if (!out.some((o) => o.value === 's')) out.push({ value: 's', label: 'sentence (s)' });
			regions.forEach((r) => { if (!out.some((o) => o.value === r) && r !== 'text') out.push({ value: r, label: r }); });
			out.push({ value: 'text', label: 'document (text)' });
			return out;
		},

		// ------------------------------------------------------------------ field values

		queryBuilderFieldUiState(field) {
			const f = String(field || '').trim();
			const hit = f && this.queryBuilderFieldUi && this.queryBuilderFieldUi[f] && typeof this.queryBuilderFieldUi[f] === 'object'
				? this.queryBuilderFieldUi[f]
				: null;
			if (!hit) return { input: 'text', options: [], features: {} };
			return {
				input: String(hit.input || 'text'),
				options: Array.isArray(hit.options) ? hit.options : [],
				features: hit.features && typeof hit.features === 'object' ? hit.features : {},
			};
		},

		qbInputType(field) {
			const f = String(field || '').trim();
			if (f && !(this.queryBuilderFieldUi && this.queryBuilderFieldUi[f])) this.queryBuilderEnsureFieldUiLoaded(f);
			if (this.qbFieldType(f) === 'udfeats') return 'udfeats';
			const input = this.queryBuilderFieldUiState(f).input;
			if (input === 'select' || input === 'datalist' || input === 'udfeats') return input;
			return 'text';
		},

		qbOptions(field) {
			return this.queryBuilderFieldUiState(field).options;
		},

		qbFeatureList(field) {
			const feats = this.queryBuilderFieldUiState(field).features;
			return Object.keys(feats).sort().map((name) => ({ name, values: Array.isArray(feats[name]) ? feats[name] : [] }));
		},

		qbFieldLoading(field) {
			const f = String(field || '').trim();
			return !!(f && this.queryBuilderFieldUiLoading && this.queryBuilderFieldUiLoading[f]);
		},

		qbOperatorsFor(field) {
			return this.qbInputType(field) === 'select' ? QB_SELECT_OPERATORS : QB_OPERATORS;
		},

		queryBuilderOperators() {
			return QB_OPERATORS.map((o) => o.value);
		},

		qbOperatorOptions() {
			return QB_OPERATORS;
		},

		async queryBuilderEnsureFieldUiLoaded(field) {
			const f = String(field || '').trim();
			if (!f) return;
			if (this.queryBuilderFieldUi && this.queryBuilderFieldUi[f]) return;
			if (this.queryBuilderFieldUiLoading && this.queryBuilderFieldUiLoading[f]) return;
			if (!this.queryBuilderFieldUiLoading || typeof this.queryBuilderFieldUiLoading !== 'object') this.queryBuilderFieldUiLoading = {};
			this.queryBuilderFieldUiLoading[f] = true;
			try {
				const formData = typeof this.buildCommonRequestData === 'function' ? this.buildCommonRequestData() : new FormData();
				if (typeof this.buildCommonRequestData !== 'function') formData.set('action', this.action || 'flexicorp');
				formData.set('ajax', '1');
				formData.set('qb_field_values', '1');
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
					features: data && data.features && typeof data.features === 'object' ? data.features : {},
					source: data && data.source ? String(data.source) : '',
				};
			} catch (err) {
				if (!this.queryBuilderFieldUi || typeof this.queryBuilderFieldUi !== 'object') this.queryBuilderFieldUi = {};
				this.queryBuilderFieldUi[f] = { input: 'text', options: [], features: {}, source: 'error' };
			} finally {
				if (this.queryBuilderFieldUiLoading && typeof this.queryBuilderFieldUiLoading === 'object') delete this.queryBuilderFieldUiLoading[f];
			}
		},

		// --- autocomplete for free-text rows (prefix lookups in the value lists)

		qbSuggestState(key) {
			const hit = key && this.queryBuilderSuggestState ? this.queryBuilderSuggestState[key] : null;
			return hit || { loading: false, items: [], open: false };
		},

		qbSuggestOpen(key) {
			const st = this.qbSuggestState(key);
			return !!st.open && Array.isArray(st.items) && st.items.length > 0;
		},

		qbSuggestItems(key) {
			const st = this.qbSuggestState(key);
			return Array.isArray(st.items) ? st.items : [];
		},

		qbCloseSuggest(key) {
			if (!key || !this.queryBuilderSuggestState) return;
			if (this.queryBuilderSuggestState[key]) this.queryBuilderSuggestState[key].open = false;
		},

		qbPickSuggestion(key, row, item) {
			if (!row || !item) return;
			row.value = String(item.value || '');
			if (row.op === 'matches' && !qbHasRegex(row.value)) row.op = 'is';
			this.qbCloseSuggest(key);
			this.qbStateChanged();
		},

		qbOnValueInput(key, field, row) {
			this.qbStateChanged();
			if (!key || !row) return;
			const op = String(row.op || 'matches');
			const val = String(row.value || '').trim();
			if (this.qbInputType(field) !== 'text' || !['matches', 'is', 'startswith'].includes(op) || val.length < 2) {
				this.qbCloseSuggest(key);
				return;
			}
			if (this._qbSuggestTimers[key]) clearTimeout(this._qbSuggestTimers[key]);
			this._qbSuggestTimers[key] = setTimeout(() => {
				delete this._qbSuggestTimers[key];
				this.qbFetchSuggestions(key, field, val);
			}, 220);
		},

		async qbFetchSuggestions(key, field, q) {
			if (!this.queryBuilderSuggestState || typeof this.queryBuilderSuggestState !== 'object') this.queryBuilderSuggestState = {};
			const reqId = this._qbSuggestReqSeq++;
			this.queryBuilderSuggestState[key] = Object.assign({}, this.queryBuilderSuggestState[key] || {}, { loading: true, requestId: reqId });
			try {
				const formData = typeof this.buildCommonRequestData === 'function' ? this.buildCommonRequestData() : new FormData();
				if (typeof this.buildCommonRequestData !== 'function') formData.set('action', this.action || 'flexicorp');
				formData.set('ajax', '1');
				formData.set('qb_suggest_values', '1');
				formData.set('field', field);
				formData.set('q', q);
				formData.set('limit', '30');
				const resp = await fetch(this.getRequestUrl(), {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				});
				const data = await resp.json();
				const cur = this.queryBuilderSuggestState[key] || {};
				if (cur.requestId !== reqId) return;
				const items = data && Array.isArray(data.items) ? data.items : [];
				this.queryBuilderSuggestState[key] = { ...cur, loading: false, open: !!(data && data.enabled && items.length), items };
			} catch (_) {
				const cur = this.queryBuilderSuggestState[key] || {};
				if (cur.requestId !== reqId) return;
				this.queryBuilderSuggestState[key] = { ...cur, loading: false, open: false, items: [] };
			}
		},

		// ------------------------------------------------------------------ state: tokens

		queryBuilderMakeRestriction() {
			return { id: 'r' + (this._qbSeq++), field: '', operator: 'matches', value: '' };
		},

		qbMakeRow() {
			return { op: 'matches', value: '' };
		},

		queryBuilderMakeToken() {
			const tok = {
				id: 't' + (this._qbTokenSeq++),
				name: '',
				optional: false,
				nocase: false,
				link: { type: 'seq', min: 0, max: 3, op: '>' },
				values: {},
				feats: {},
				featsOpen: false,
			};
			this.qbNormalizeToken(tok);
			return tok;
		},

		qbNormalizeToken(tok) {
			if (!tok || typeof tok !== 'object') return;
			if (!tok.values || typeof tok.values !== 'object') tok.values = {};
			if (!tok.feats || typeof tok.feats !== 'object') tok.feats = {};
			if (!tok.link || typeof tok.link !== 'object') tok.link = { type: 'seq', min: 0, max: 3, op: '>' };
			if (!['seq', 'gap', 'dep'].includes(tok.link.type)) tok.link.type = 'seq';
			if (typeof tok.name !== 'string') tok.name = '';
			tok.optional = !!tok.optional;
			tok.nocase = !!tok.nocase;
			tok.featsOpen = !!tok.featsOpen;
			if (!Array.isArray(tok.extra)) tok.extra = [];
			this.qbTokenFields().forEach((f) => {
				if (!tok.values[f.value] || typeof tok.values[f.value] !== 'object') tok.values[f.value] = this.qbMakeRow();
			});
		},

		qbNormalizeDocs() {
			const st = this.queryBuilderState;
			if (!st.docs || typeof st.docs !== 'object') st.docs = {};
			this.qbDocGroups().forEach((g) => g.options.forEach((o) => {
				if (!st.docs[o.value] || typeof st.docs[o.value] !== 'object') st.docs[o.value] = this.qbMakeRow();
			}));
		},

		queryBuilderEnsureTokenSeed() {
			const st = this.queryBuilderState;
			if (!st || !Array.isArray(st.tokens)) return;
			if (!st.tokens.length) st.tokens = [this.queryBuilderMakeToken()];
			st.tokens.forEach((t) => this.qbNormalizeToken(t));
			this.qbNormalizeDocs();
			const selected = String(st.selectedTokenId || '');
			if (!st.tokens.some((t) => t && String(t.id) === selected)) st.selectedTokenId = String(st.tokens[0].id || '');
		},

		queryBuilderSelectedToken() {
			const st = this.queryBuilderState;
			const selected = String((st && st.selectedTokenId) || '');
			const tokens = st && Array.isArray(st.tokens) ? st.tokens : [];
			return tokens.find((t) => t && String(t.id) === selected) || null;
		},

		qbSel() {
			return this.queryBuilderSelectedToken();
		},

		qbSetFeat(tok, name, value) {
			if (!tok) return;
			if (!tok.feats || typeof tok.feats !== 'object') tok.feats = {};
			tok.feats[name] = String(value || '');
			this.qbStateChanged();
		},

		qbSelectedIndex() {
			const st = this.queryBuilderState;
			return (st.tokens || []).findIndex((t) => String(t.id) === String(st.selectedTokenId || ''));
		},

		queryBuilderSelectToken(tokenId) {
			if (this.queryBuilderState) this.queryBuilderState.selectedTokenId = String(tokenId || '');
		},

		queryBuilderAddToken() {
			const st = this.queryBuilderState;
			if (!st || !Array.isArray(st.tokens)) return;
			const tok = this.queryBuilderMakeToken();
			st.tokens.push(tok);
			st.selectedTokenId = tok.id;
			this.qbStateChanged();
		},

		queryBuilderRemoveToken(tokenId) {
			const st = this.queryBuilderState;
			if (!st || !Array.isArray(st.tokens)) return;
			const id = String(tokenId || '');
			const idx = st.tokens.findIndex((t) => String(t.id) === id);
			if (idx < 0) return;
			st.tokens.splice(idx, 1);
			if (!st.tokens.length) st.tokens.push(this.queryBuilderMakeToken());
			const next = st.tokens[Math.min(idx, st.tokens.length - 1)];
			st.selectedTokenId = String(next.id);
			this.qbStateChanged();
		},

		queryBuilderMoveToken(tokenId, direction) {
			const st = this.queryBuilderState;
			if (!st || !Array.isArray(st.tokens)) return;
			const idx = st.tokens.findIndex((t) => String(t && t.id) === String(tokenId || ''));
			const nextIdx = direction === 'up' || direction === 'left' ? idx - 1 : idx + 1;
			if (idx < 0 || nextIdx < 0 || nextIdx >= st.tokens.length) return;
			// Connectors stay between the same positions: swap the tokens, keep the links in place.
			const a = st.tokens[idx];
			const b = st.tokens[nextIdx];
			const linkA = a.link;
			a.link = b.link;
			b.link = linkA;
			st.tokens.splice(idx, 1, b);
			st.tokens.splice(nextIdx, 1, a);
			this.qbStateChanged();
		},

		queryBuilderCanMoveToken(tokenId, direction) {
			const st = this.queryBuilderState;
			const idx = (st.tokens || []).findIndex((t) => String(t && t.id) === String(tokenId || ''));
			if (idx < 0) return false;
			if (direction === 'up' || direction === 'left') return idx > 0;
			return idx < st.tokens.length - 1;
		},

		qbTokenRows(tok) {
			if (!tok) return [];
			return this.qbTokenFields().map((f) => {
				if (!tok.values[f.value]) tok.values[f.value] = this.qbMakeRow();
				return { field: f.value, label: f.label, admin: f.admin, row: tok.values[f.value], key: tok.id + ':' + f.value };
			});
		},

		qbDocRows(group) {
			const st = this.queryBuilderState;
			return (group && group.options ? group.options : []).map((f) => {
				if (!st.docs[f.value]) st.docs[f.value] = this.qbMakeRow();
				return { field: f.value, label: f.label, admin: f.admin, row: st.docs[f.value], key: 'doc:' + f.value };
			});
		},

		qbRemoveExtra(tok, idx) {
			if (!tok || !Array.isArray(tok.extra)) return;
			tok.extra.splice(idx, 1);
			this.qbStateChanged();
		},

		qbToggleFeats(tok, field) {
			if (!tok) return;
			tok.featsOpen = !tok.featsOpen;
			if (tok.featsOpen && tok.values[field]) tok.values[field].value = '';
			this.qbStateChanged();
		},

		qbLinkOptions() {
			const out = [
				{ value: 'seq', label: 'next to', title: 'directly followed by' },
				{ value: 'gap', label: 'words between…', title: 'a gap of min–max tokens: []{min,max}' },
			];
			if (this.qbDialect().deps) QB_DEP_LINKS.forEach((d) => out.push({ value: 'dep:' + d.value, label: d.label, title: d.title }));
			return out;
		},

		qbLinkValue(tok) {
			const l = tok && tok.link ? tok.link : { type: 'seq' };
			return l.type === 'dep' ? 'dep:' + (l.op || '>') : l.type;
		},

		qbSetLink(tok, value) {
			if (!tok) return;
			const v = String(value || 'seq');
			if (v.startsWith('dep:')) { tok.link.type = 'dep'; tok.link.op = v.slice(4) || '>'; }
			else tok.link.type = v === 'gap' ? 'gap' : 'seq';
			this.qbStateChanged();
		},

		/** Short text for a token chip in the strip. */
		qbTokenSummary(tok) {
			if (!tok) return '';
			const parts = this.qbTokenConditions(tok, this.qbDialect(), false);
			if (!parts.length) return 'any word';
			return parts.join(' & ');
		},

		qbTokenDisplayName(tok, idx) {
			const n = tok && String(tok.name || '').trim();
			if (n) return n;
			const a = this.queryBuilderState && this.queryBuilderState.alignment;
			if ((idx | 0) === 0 && a && a.enabled && this.qbDialect().align) return 'a';
			return String((idx | 0) + 1);
		},

		// ------------------------------------------------------------------ alignment

		queryBuilderSetAlignmentEnabled(nextEnabled) {
			const a = this.queryBuilderState && this.queryBuilderState.alignment;
			if (!a) return;
			a.enabled = !!nextEnabled && this.queryBuilderSupportsAlignment();
			this.qbStateChanged();
		},

		queryBuilderAlignmentJoinFieldOptions() {
			return [
				{ value: 'tuid', label: 'word (tuid)' },
				{ value: 's_tuid', label: 'sentence (s_tuid)' },
			];
		},

		queryBuilderAlignmentUsesSentenceTarget() {
			const a = this.queryBuilderState && this.queryBuilderState.alignment;
			return !!(a && a.joinField === 's_tuid');
		},

		queryBuilderTargetFieldGroups() {
			const sentence = this.queryBuilderAlignmentUsesSentenceTarget();
			const langField = this.qbLanguageField();
			const groups = this.queryBuilderFieldGroups()
				.map((g) => ({
					...g,
					options: g.options.filter((o) => !this.qbIsAlignField(o.value) && o.value !== langField && !(sentence && g.id === 'token')),
				}))
				.filter((g) => g.options.length);
			const known = new Set();
			groups.forEach((g) => g.options.forEach((o) => known.add(o.value)));
			const a = this.queryBuilderState && this.queryBuilderState.alignment;
			const other = [];
			((a && a.targetRestrictions) || []).forEach((r) => {
				const f = String((r && r.field) || '').trim();
				if (f && !known.has(f)) { known.add(f); other.push({ value: f, label: f }); }
			});
			if (other.length) groups.push({ id: 'other', label: 'Other', options: other });
			return groups;
		},

		queryBuilderAddTargetRestriction() {
			const a = this.queryBuilderState && this.queryBuilderState.alignment;
			if (!a) return;
			a.targetRestrictions.push(this.queryBuilderMakeRestriction());
		},

		queryBuilderRemoveTargetRestriction(restrictionId) {
			const a = this.queryBuilderState && this.queryBuilderState.alignment;
			if (!a) return;
			a.targetRestrictions = a.targetRestrictions.filter((r) => String(r.id) !== String(restrictionId || ''));
			this.qbStateChanged();
		},

		queryBuilderRestrictionFieldMissing(r) {
			return !String((r && r.field) || '').trim() && !!String((r && r.value) || '').trim();
		},

		qbLanguageValues() {
			const f = this.qbLanguageField();
			return f ? this.qbOptions(f) : [];
		},

		// ------------------------------------------------------------------ compile

		qbCondition(field, op, value, nocase, dialect) {
			const f = String(field || '').trim();
			const raw = String(value == null ? '' : value);
			if (!f || raw.trim() === '') return '';
			const o = String(op || 'matches');
			let rx = raw;
			if (o === 'is') rx = qbRegexQuote(raw);
			if (o === 'contains') rx = '.*' + raw + '.*';
			else if (o === 'startswith') rx = raw + '.*';
			else if (o === 'endsin') rx = '.*' + raw;
			let flag = '';
			if (nocase && dialect.nocase === '(?i)') rx = '(?i)' + rx;
			else if (nocase && dialect.nocase === '%c') flag = ' %c';
			return `${f}${o === 'not' ? '!=' : '='}"${qbEscape(rx, dialect.pandoStrings)}"${flag}`;
		},

		qbFeatsConditions(field, feats, dialect) {
			const out = [];
			Object.keys(feats || {}).sort().forEach((name) => {
				const v = String(feats[name] || '').trim();
				if (!v) return;
				if (dialect.feats === 'kv') out.push(`${field}/${name}="${qbEscape(qbRegexQuote(v), dialect.pandoStrings)}"`);
				else out.push(`${field}="${qbEscape('(.*\\|)?' + qbRegexQuote(name) + '=' + qbRegexQuote(v) + '(\\|.*)?', dialect.pandoStrings)}"`);
			});
			return out;
		},

		qbTokenConditions(tok, dialect, withName) {
			const parts = [];
			this.qbTokenFields().forEach((f) => {
				const row = tok.values && tok.values[f.value];
				if (this.qbIsFeatsField(f.value) && tok.featsOpen) {
					this.qbFeatsConditions(f.value, tok.feats, dialect).forEach((c) => parts.push(c));
					return;
				}
				if (!row) return;
				const isSelect = this.qbInputType(f.value) === 'select';
				const c = this.qbCondition(f.value, row.op, row.value, tok.nocase && !isSelect, dialect);
				if (c) parts.push(c);
			});
			(Array.isArray(tok.extra) ? tok.extra : []).forEach((c) => { if (c) parts.push(c); });
			return parts;
		},

		qbDocConditions(dialect) {
			const st = this.queryBuilderState;
			const out = [];
			this.qbDocGroups().forEach((g) => g.options.forEach((o) => {
				const row = st.docs && st.docs[o.value];
				if (!row) return;
				const c = this.qbCondition(o.value, row.op, row.value, false, dialect);
				const region = g.region || this.queryBuilderFieldKind(o.value).region || 'text';
				if (c) out.push({ field: o.value, region, cond: c, row });
			}));
			return out;
		},

		qbCompileToken(tok, dialect, extraConds, alias) {
			const parts = this.qbTokenConditions(tok, dialect, true).concat(extraConds || []);
			const name = String(alias || tok.name || '').trim();
			return (name ? name + ':' : '') + '[' + parts.join(' & ') + ']' + (tok.optional ? '?' : '');
		},

		qbCompileLink(tok) {
			const l = tok.link || {};
			if (l.type === 'dep' && this.qbDialect().deps) return ' ' + (l.op || '>') + ' ';
			if (l.type === 'gap') {
				let min = parseInt(l.min, 10);
				let max = parseInt(l.max, 10);
				if (!Number.isFinite(min) || min < 0) min = 0;
				if (!Number.isFinite(max) || max < min) max = min;
				return ' []{' + min + ',' + max + '} ';
			}
			return ' ';
		},

		compileBuilderStateToQuery(state, qlPack) {
			const pack = qlPack && typeof qlPack === 'object' ? qlPack : this.queryBuilderCurrentPack();
			if (!pack.enabled || pack.family !== 'cqlCore') return '';
			const st = state && typeof state === 'object' ? state : this.queryBuilderState;
			const dialect = this.qbDialect();
			const tokens = Array.isArray(st.tokens) ? st.tokens : [];
			if (!tokens.length) return '';
			const docs = this.qbDocConditions(dialect);
			const align = st.alignment && st.alignment.enabled && dialect.align;
			const firstExtra = dialect.docs === 'token' ? docs.map((d) => d.cond) : [];
			const srcAlias = align ? (String(tokens[0].name || '').trim() || 'a') : '';
			let seq = '';
			tokens.forEach((tok, i) => {
				if (i > 0) seq += this.qbCompileLink(tok);
				seq += this.qbCompileToken(tok, dialect, i === 0 ? firstExtra : [], i === 0 ? srcAlias : '');
			});
			let cql = seq;
			if (align) {
				const a = st.alignment;
				const tAlias = String(a.targetAlias || 'b').trim() || 'b';
				const join = a.joinField === 's_tuid' ? 's_tuid' : 'tuid';
				const tParts = (a.targetRestrictions || [])
					.map((r) => this.qbCondition(r.field, r.operator, r.value, false, dialect))
					.filter(Boolean);
				const langField = this.qbLanguageField();
				if (langField && String(a.targetLang || '').trim()) tParts.unshift(this.qbCondition(langField, 'is', a.targetLang, false, dialect));
				const target = join === 's_tuid'
					? `${tAlias}:<s${tParts.length ? ' ' + tParts.join(' & ') : ''}>`
					: `${tAlias}:[${tParts.join(' & ')}]`;
				cql = `${seq} with ${target} :: ${srcAlias}.${join} = ${tAlias}.${join}`;
			} else {
				let within = String(st.within || '').trim();
				if (dialect.docs === 'global' && docs.length) {
					cql += ' :: ' + docs.map((d) => 'match.' + d.cond).join(' & ');
				}
				if (dialect.docs === 'within' && docs.length) {
					const region = docs[0].region || 'text';
					const attrs = docs.filter((d) => (d.region || 'text') === region).map((d) => {
						const attr = d.field.slice(region.length + 1);
						return d.cond.replace(d.field, attr);
					});
					within = `<${region} ${attrs.join(' ')}/>`;
				}
				if (within) cql += ' within ' + within;
			}
			const qname = String(st.queryName || '').trim();
			if (qname) cql = `${qname} = ${cql}`;
			return cql;
		},

		queryBuilderPreviewQuery() {
			const pack = this.queryBuilderCurrentPack();
			if (!pack.enabled || pack.family !== 'cqlCore') return '';
			return this.compileBuilderStateToQuery(this.queryBuilderState, pack);
		},

		/** The text shown in the query box of the builder (typed text while the user edits it). */
		qbCurrentText() {
			const st = this.queryBuilderState;
			if (st && st.textEdited) return String(st.text || '');
			return this.queryBuilderPreviewQuery();
		},

		qbStateChanged() {
			const st = this.queryBuilderState;
			if (!st) return;
			st.textEdited = false;
			st.textNote = '';
			st.warning = '';
		},

		qbOnTextInput(value) {
			const st = this.queryBuilderState;
			st.text = String(value || '');
			st.textEdited = true;
			if (this._qbParseTimer) clearTimeout(this._qbParseTimer);
			this._qbParseTimer = setTimeout(() => {
				this._qbParseTimer = null;
				this.qbReadText(false);
			}, 300);
		},

		qbOnTextBlur() {
			this.qbReadText(true);
		},

		/** Load the typed query into the form; keep the text as typed while it is being edited. */
		qbReadText(normalize) {
			const st = this.queryBuilderState;
			if (!st.textEdited) return;
			const text = String(st.text || '').trim();
			if (!text) { st.textNote = ''; return; }
			const res = this.qbParseQuery(text);
			if (!res.state) {
				st.textNote = (res.reason || 'This query cannot be shown in the builder') + '; Apply uses the text as typed.';
				return;
			}
			this.qbApplyParsed(res.state);
			st.textNote = '';
			if (normalize) st.textEdited = false;
		},

		// ------------------------------------------------------------------ parse

		/** Split at top level (outside quotes, [], <>, ()) on a separator regex. */
		qbSplitTop(text, sepRe) {
			const out = [];
			let depth = 0;
			let quote = false;
			let cur = '';
			for (let i = 0; i < text.length; i++) {
				const ch = text[i];
				if (quote) {
					cur += ch;
					if (ch === '\\' && i + 1 < text.length) { cur += text[++i]; continue; }
					if (ch === '"') quote = false;
					continue;
				}
				if (ch === '"') { quote = true; cur += ch; continue; }
				if (ch === '[' || ch === '(' || ch === '{') depth++;
				if (ch === ']' || ch === ')' || ch === '}') depth--;
				if (depth === 0) {
					sepRe.lastIndex = 0;
					const m = sepRe.exec(text.slice(i));
					if (m && m.index === 0) {
						out.push(cur);
						cur = '';
						i += m[0].length - 1;
						continue;
					}
				}
				cur += ch;
			}
			out.push(cur);
			return out.map((s) => s.trim());
		},

		/** Conditions inside [...] → { values, feats, docs, nocase, ok, reason } */
		qbParseConditions(body, target) {
			const res = { values: {}, feats: {}, docs: {}, nocase: false, extra: [], ok: true, reason: '' };
			const text = String(body || '').trim();
			if (!text) return res;
			const knownRegions = new Set(this.queryBuilderKnownRegions());
			const parts = this.qbSplitTop(text, /^&/);
			for (const part of parts) {
				const m = part.match(/^([A-Za-z_][A-Za-z0-9_#]*)(?:\/([A-Za-z0-9_\[\]]+))?\s*(!?=)\s*"((?:[^"\\]|\\.)*)"\s*(%[cd]+)?$/);
				if (!m) { res.ok = false; res.reason = 'Condition not supported by the builder: ' + part; return res; }
				const field = m[1];
				const sub = m[2] || '';
				const neg = m[3] === '!=';
				let val = qbUnescape(m[4], this.qbDialect().pandoStrings);
				const flags = m[5] || '';
				if (flags.indexOf('c') >= 0) res.nocase = true;
				if (/^\(\?i\)/.test(val)) { val = val.slice(4); res.nocase = true; }
				if (sub) {
					if (neg) { res.ok = false; res.reason = 'Negated feature conditions are not supported by the builder'; return res; }
					res.feats[sub] = qbRegexUnquote(val);
					res.featsField = field;
					continue;
				}
				const fm = val.match(/^(?:\(\.\*\\\|\)\?|\.\*)([A-Za-z0-9_\[\]]+)=([^|()*]+?)(?:\(\\\|\.\*\)\?|\.\*)$/);
				if (fm && this.qbIsFeatsField(field) && !neg) {
					res.feats[qbRegexUnquote(fm[1])] = qbRegexUnquote(fm[2]);
					res.featsField = field;
					continue;
				}
				let op = 'matches';
				if (neg) {
					op = 'not';
				} else {
					const c1 = val.match(/^\.\*(.+)\.\*$/);
					const c2 = val.match(/^(.+)\.\*$/);
					const c3 = val.match(/^\.\*(.+)$/);
					if (c1) { op = 'contains'; val = c1[1]; }
					else if (c2) { op = 'startswith'; val = c2[1]; }
					else if (c3) { op = 'endsin'; val = c3[1]; }
					else if (/^([^.*+?^${}()|[\]\\]|\\.)*$/.test(val)) { op = 'is'; val = qbRegexUnquote(val); }
				}
				const kind = this.queryBuilderFieldKind(field, knownRegions).kind;
				if (!target && kind !== 'token') res.docs[field] = { op, value: val };
				else if (target || this.qbTokenFields().some((f) => f.value === field)) res.values[field] = { op, value: val };
				else res.extra.push(part);
			}
			return res;
		},

		/** Sequence of tokens with connectors → { tokens, docs } or { reason } */
		qbParseSequence(text) {
			const tokens = [];
			const docs = {};
			let rest = String(text || '').trim();
			let pendingLink = null;
			while (rest.length) {
				let m = rest.match(/^(>>|<<|>|<)\s*/);
				if (m) {
					if (!tokens.length || pendingLink) return { reason: 'Unexpected operator ' + m[1] };
					pendingLink = { type: 'dep', op: m[1] };
					rest = rest.slice(m[0].length);
					continue;
				}
				m = rest.match(/^\[\]\s*\{\s*(\d+)\s*,\s*(\d+)\s*\}\s*/) || rest.match(/^\[\]\s*\{\s*(\d+)\s*\}\s*/);
				if (m && tokens.length && !pendingLink) {
					const min = parseInt(m[1], 10);
					const max = m[2] !== undefined ? parseInt(m[2], 10) : min;
					pendingLink = { type: 'gap', min, max };
					rest = rest.slice(m[0].length);
					continue;
				}
				m = rest.match(/^\[\]\s*([*+])\s*/);
				if (m && tokens.length && !pendingLink) {
					pendingLink = { type: 'gap', min: m[1] === '+' ? 1 : 0, max: 10 };
					rest = rest.slice(m[0].length);
					continue;
				}
				m = rest.match(/^(?:([A-Za-z][A-Za-z0-9_]*)\s*:\s*)?/);
				const name = m && m[1] ? m[1] : '';
				rest = rest.slice(m ? m[0].length : 0);
				const tok = this.queryBuilderMakeToken();
				tok.name = name;
				if (rest[0] === '"') {
					const wm = rest.match(/^"((?:[^"\\]|\\.)*)"\s*(%[cd]+)?\s*/);
					if (!wm) return { reason: 'Unclosed string' };
					const wordField = (this.qbTokenFields()[0] || { value: 'word' }).value;
					const parsed = this.qbParseConditions(`${wordField}="${wm[1]}"${wm[2] ? ' ' + wm[2] : ''}`, false);
					Object.assign(tok.values, parsed.values);
					tok.nocase = parsed.nocase;
					rest = rest.slice(wm[0].length);
				} else if (rest[0] === '[') {
					const bracket = this.qbTakeBracket(rest);
					if (!bracket) return { reason: 'Unclosed [' };
					const parsed = this.qbParseConditions(bracket.inner, false);
					if (!parsed.ok) return { reason: parsed.reason };
					Object.keys(parsed.values).forEach((k) => { tok.values[k] = parsed.values[k]; });
					Object.assign(docs, parsed.docs);
					if (Object.keys(parsed.feats).length) { tok.feats = parsed.feats; tok.featsOpen = true; }
					tok.extra = parsed.extra;
					tok.nocase = parsed.nocase;
					rest = rest.slice(bracket.length).trimStart();
				} else {
					return { reason: 'Cannot read the query from: ' + rest.slice(0, 30) };
				}
				const q = rest.match(/^\?\s*/);
				if (q) { tok.optional = true; rest = rest.slice(q[0].length); }
				if (/^[*+{]/.test(rest)) return { reason: 'Repetition of tokens is not supported by the builder' };
				if (tokens.length) tok.link = Object.assign({ type: 'seq', min: 0, max: 3, op: '>' }, pendingLink || {});
				pendingLink = null;
				tokens.push(tok);
				rest = rest.trimStart();
			}
			if (pendingLink) return { reason: 'The query ends with a connector' };
			if (!tokens.length) return { reason: 'No tokens' };
			return { tokens, docs };
		},

		qbTakeBracket(text) {
			if (text[0] !== '[') return null;
			let quote = false;
			let depth = 0;
			for (let i = 0; i < text.length; i++) {
				const ch = text[i];
				if (quote) {
					if (ch === '\\') { i++; continue; }
					if (ch === '"') quote = false;
					continue;
				}
				if (ch === '"') { quote = true; continue; }
				if (ch === '[') depth++;
				if (ch === ']') { depth--; if (depth === 0) return { inner: text.slice(1, i), length: i + 1 }; }
			}
			return null;
		},

		/** Text query → { state } or { reason } */
		qbParseQuery(query) {
			let q = String(query || '').trim().replace(/;\s*$/, '');
			if (!q) return { reason: 'Empty query' };
			const out = { queryName: '', tokens: [], docs: {}, within: '', alignment: { enabled: false, joinField: 'tuid', targetAlias: 'b', targetLang: '', targetRestrictions: [] } };
			const assign = q.match(/^([A-Za-z][A-Za-z0-9_]*)\s*=\s*(?![=~"])(.+)$/s);
			if (assign) { out.queryName = assign[1]; q = assign[2].trim(); }
			if (/[|()]/.test(q.replace(/"(?:[^"\\]|\\.)*"/g, '""'))) return { reason: 'Alternatives and groups are not supported by the builder' };
			if (/\b(child|parent|ancestor|descendant|sibling)\s*\[/.test(q)) return { reason: 'Dependency restrictions inside a token are for the full query builder' };
			// global conditions
			let globals = '';
			const gParts = this.qbSplitTop(q, /^::/);
			if (gParts.length > 2) return { reason: 'More than one :: part' };
			if (gParts.length === 2) { q = gParts[0]; globals = gParts[1]; }
			// within (at the end of the token part, or after the global part in CQP order)
			const takeWithin = (s) => {
				const wm = s.match(/\swithin\s+(<[^>]*\/?>|[A-Za-z_][A-Za-z0-9_]*)\s*$/);
				if (!wm) return { rest: s, within: '' };
				return { rest: s.slice(0, wm.index).trim(), within: wm[1] };
			};
			let w = takeWithin(' ' + q);
			q = w.rest.trim();
			let within = w.within;
			if (globals) {
				const gw = takeWithin(' ' + globals);
				globals = gw.rest.trim();
				if (gw.within) within = gw.within;
			}
			// aligned: <seq> with <target>
			let target = '';
			const wParts = this.qbSplitTop(q, /^\s+with\s+/);
			if (wParts.length > 2) return { reason: 'Only one aligned language is supported' };
			if (wParts.length === 2) { q = wParts[0]; target = wParts[1]; }
			const seq = this.qbParseSequence(q);
			if (!seq.tokens) return { reason: seq.reason };
			out.tokens = seq.tokens;
			Object.keys(seq.docs).forEach((k) => { out.docs[k] = seq.docs[k]; });
			if (within) {
				const wr = within.match(/^<([A-Za-z_][A-Za-z0-9_]*)\s*(.*?)\s*\/?>$/);
				if (wr) {
					const region = wr[1];
					const attrRe = /([A-Za-z_][A-Za-z0-9_]*)\s*(!?=)\s*"((?:[^"\\]|\\.)*)"/g;
					let am;
					let any = false;
					while ((am = attrRe.exec(wr[2])) !== null) {
						any = true;
						const parsed = this.qbParseConditions(`${region}_${am[1]}${am[2]}"${am[3]}"`, false);
						if (!parsed.ok) return { reason: parsed.reason };
						Object.assign(out.docs, parsed.docs, parsed.values);
					}
					out.within = any ? '' : region;
				} else {
					out.within = within;
				}
			}
			if (globals) {
				for (const part of this.qbSplitTop(globals, /^&/)) {
					const jm = part.match(/^([A-Za-z][A-Za-z0-9_]*)\.(tuid|s_tuid)\s*=\s*([A-Za-z][A-Za-z0-9_]*)\.\2$/);
					if (jm && target) {
						out.alignment.joinField = jm[2];
						if (!out.tokens[0].name) out.tokens[0].name = jm[1];
						out.alignment.targetAlias = jm[3];
						continue;
					}
					const mm = part.match(/^match\.(.+)$/);
					if (mm) {
						const parsed = this.qbParseConditions(mm[1].trim(), false);
						if (!parsed.ok) return { reason: parsed.reason };
						Object.assign(out.docs, parsed.docs, parsed.values);
						continue;
					}
					return { reason: 'Global condition not supported by the builder: ' + part };
				}
			}
			if (target) {
				const a = out.alignment;
				a.enabled = true;
				const tm = target.match(/^(?:([A-Za-z][A-Za-z0-9_]*)\s*:\s*)?(?:\[(.*)\]|<s\b\s*(.*?)\s*\/?>)$/s);
				if (!tm) return { reason: 'The aligned side must be one token or one <s> region' };
				if (tm[1]) a.targetAlias = tm[1];
				const isSentence = tm[3] !== undefined && tm[2] === undefined;
				if (isSentence) a.joinField = 's_tuid';
				const parsed = this.qbParseConditions(isSentence ? tm[3] : tm[2], true);
				if (!parsed.ok) return { reason: parsed.reason };
				const langField = this.qbLanguageField();
				Object.keys(parsed.values).forEach((field) => {
					const r = parsed.values[field];
					if (field === langField && r.op === 'is' && !a.targetLang) { a.targetLang = r.value; return; }
					a.targetRestrictions.push({ id: 'r' + (this._qbSeq++), field, operator: r.op, value: r.value });
				});
			} else if (globals && /\.(s_)?tuid\s*=/.test(globals)) {
				return { reason: 'Alignment conditions need a with part' };
			}
			return { state: out };
		},

		qbApplyParsed(parsed) {
			const st = this.queryBuilderState;
			const prevIdx = Math.max(0, this.qbSelectedIndex());
			st.tokens = parsed.tokens;
			st.tokens.forEach((t) => this.qbNormalizeToken(t));
			st.docs = {};
			this.qbNormalizeDocs();
			Object.keys(parsed.docs || {}).forEach((k) => { st.docs[k] = Object.assign(this.qbMakeRow(), parsed.docs[k]); });
			st.within = parsed.within || '';
			st.queryName = parsed.queryName || '';
			st.alignment = Object.assign({ enabled: false, joinField: 'tuid', targetAlias: 'b', targetLang: '', targetRestrictions: [] }, parsed.alignment || {});
			st.selectedTokenId = String(st.tokens[Math.min(prevIdx, st.tokens.length - 1)].id);
			st.warning = '';
		},

		/** Kept for callers of the previous builder. */
		parseQueryToBuilderState(query) {
			const res = this.qbParseQuery(query);
			return res.state || null;
		},

		// ------------------------------------------------------------------ open / apply

		queryBuilderOpen() {
			const st = this.queryBuilderState;
			if (!st || !st.enabled) return;
			this.queryBuilderEnsureTokenSeed();
			st.open = true;
			this.queryBuilderAutoLoadFromCurrentQuery();
			this.qbTokenFields().forEach((f) => this.queryBuilderEnsureFieldUiLoaded(f.value));
			this.qbDocGroups().forEach((g) => g.options.forEach((o) => this.queryBuilderEnsureFieldUiLoaded(o.value)));
		},

		queryBuilderClose() {
			if (this.queryBuilderState) this.queryBuilderState.open = false;
		},

		queryBuilderToggle() {
			const st = this.queryBuilderState;
			if (!st || !st.enabled) return;
			if (st.open) this.queryBuilderClose();
			else this.queryBuilderOpen();
		},

		queryBuilderReset() {
			const st = this.queryBuilderState;
			st.tokens = [this.queryBuilderMakeToken()];
			st.selectedTokenId = st.tokens[0].id;
			st.docs = {};
			this.qbNormalizeDocs();
			st.within = 'text';
			st.queryName = '';
			st.alignment = { enabled: false, joinField: 'tuid', targetAlias: 'b', targetLang: '', targetRestrictions: [] };
			this.qbStateChanged();
		},

		queryBuilderAutoLoadFromCurrentQuery() {
			const st = this.queryBuilderState;
			const q = this.search && typeof this.search.query === 'string' ? this.search.query.trim() : '';
			st.textEdited = false;
			st.textNote = '';
			st.warning = '';
			if (!q) return;
			if (q === this.queryBuilderPreviewQuery()) return;
			const res = this.qbParseQuery(q);
			if (res.state) {
				this.qbApplyParsed(res.state);
				return;
			}
			st.text = q;
			st.textEdited = true;
			st.textNote = (res.reason || 'This query cannot be shown in the builder') + '; Apply uses the text as typed.';
		},

		queryBuilderLoadFromQuery() {
			this.queryBuilderAutoLoadFromCurrentQuery();
		},

		queryBuilderApplyToQuery() {
			const st = this.queryBuilderState;
			if (!st || !st.enabled) return;
			if (!this.queryBuilderSupportsCurrentLanguage()) {
				st.warning = 'Selected query language is not yet supported by this builder.';
				return;
			}
			const cql = String(this.qbCurrentText() || '').trim();
			if (!cql) {
				st.warning = 'Fill in at least one field.';
				return;
			}
			this.search.query = cql;
			st.warning = '';
			if (typeof this.scheduleQueryHighlight === 'function') this.scheduleQueryHighlight();
			this.queryBuilderClose();
		},
	};
};
