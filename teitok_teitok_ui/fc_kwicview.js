/**
 * flexicorp TEITOK UI: Context scope, search view mode and KWIC window.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.kwicview = function () {
	return {
		normalizePositiveInt(value, fallback) {
			const num = parseInt(value, 10);
			return Number.isFinite(num) && num > 0 ? num : fallback;
		},

		/** Tokens on each side of the hit; server default from getset flexicorp/kwic_window (PHP) is 10. */
		defaultKwicWindow() {
			const w = this.settings && this.settings.kwicWindow;
			const n = parseInt(w, 10);
			return Number.isFinite(n) && n > 0 ? n : 10;
		},

		currentContextScopeKey() {
			return this.normalizeContextScopeValue(this.search && this.search.contextScope);
		},

		kwicScopeUsesTokenWindow() {
			const scope = this.currentContextScopeKey();
			return scope === 'window' || scope === 'tok';
		},

		ensureKwicColWeightsShape() {
			const d = { ctx: 7, left: 36, match: 10, right: 36, alLeft: 12, alMatch: 14, alRight: 12 };
			if (!this.settings || typeof this.settings !== 'object') return;
			if (!this.settings.kwicColWeights || typeof this.settings.kwicColWeights !== 'object') {
				this.settings.kwicColWeights = Object.assign({}, d);
			} else {
				Object.keys(d).forEach((k) => {
					const v = Number(this.settings.kwicColWeights[k]);
					if (!Number.isFinite(v) || v < 1) this.settings.kwicColWeights[k] = d[k];
				});
			}
		},

		/** Percent widths for <colgroup> (always 7 cols to match thead); sums to ~100. */
		kwicColumnPercentages() {
			this.ensureKwicColWeightsShape();
			const w = this.settings && this.settings.kwicColWeights ? this.settings.kwicColWeights : {};
			const aligned = typeof this.searchHasAlignedHits === 'function' && this.searchHasAlignedHits();
			const keys = ['ctx', 'left', 'match', 'right', 'alLeft', 'alMatch', 'alRight'];
			const raw = keys.map((k) => {
				if (!aligned && (k === 'alLeft' || k === 'alMatch' || k === 'alRight')) return 0.001;
				return Math.max(1, Number(w[k]) || 1);
			});
			const sum = raw.reduce((a, b) => a + b, 0);
			if (sum <= 0) return [7, 36, 10, 36, 0.001, 0.001, 0.001];
			return raw.map((x) => (x / sum) * 100);
		},

		activeKwicWindowSize() {
			return this.normalizePositiveInt(this.search && this.search.window, this.defaultKwicWindow());
		},

		normalizeContextScopeValue(value) {
			let text = String(value || '').trim().toLowerCase();
			if (text.startsWith('<') && text.endsWith('>') && text.length > 2) {
				text = text.slice(1, -1).trim().toLowerCase();
			}
			const aliases = {
				sentence: 's',
				sent: 's',
				line: 'l',
				verse: 'l',
				paragraph: 'p',
				para: 'p',
				document: 'text',
				doc: 'text',
				'token-window': 'window',
				'tok-window': 'window',
				kwic: 'window',
			};
			return aliases[text] || text;
		},

		contextScopeSupportsTokenWindow() {
			// Keep the traditional fallback available across backends: users expect
			// context-window even when structural scopes (sentence/paragraph/...) exist.
			return true;
		},

		currentResultCapabilities() {
			const result = this.search && this.search.response && this.search.response.result && typeof this.search.response.result === 'object'
				? this.search.response.result
				: null;
			const infoResult = this.info && this.info.result && typeof this.info.result === 'object'
				? this.info.result
				: null;
			return {
				supportsXmlContext: result && typeof result.supports_xml_context === 'boolean'
					? result.supports_xml_context
					: (infoResult && typeof infoResult.supports_xml_context === 'boolean' ? infoResult.supports_xml_context : true),
				supportsDocumentLinks: result && typeof result.supports_document_links === 'boolean'
					? result.supports_document_links
					: (infoResult && typeof infoResult.supports_document_links === 'boolean' ? infoResult.supports_document_links : true),
			};
		},

		currentBackendSupportsXmlContext() {
			return !!this.currentResultCapabilities().supportsXmlContext;
		},

		currentBackendSupportsDocumentLinks() {
			return !!this.currentResultCapabilities().supportsDocumentLinks;
		},

		contextScopeLabel(value) {
			const key = this.normalizeContextScopeValue(value);
			const labels = {
				window: 'context-window',
				s: 'sentence',
				u: 'utterance (<u>)',
				p: 'paragraph',
				text: 'document',
				l: 'line (verse line / <l>)',
				lb: 'line break (<lb/>)',
				quote: 'quote',
				seg: 'segment',
			};
			return labels[key] || key;
		},

		/**
		 * Annotation / metadata names that are not struct regions (noise in /info lists). Structural
		 * options come from the server allowlist (settings.contextScopeRegionAllowlist), default
		 * s u lb l p seg — override in TEITOK: getset flexicorp/context_scope_regions.
		 */
		contextScopeRegionNoiseBlocklist() {
			return new Set([
				'gloss',
				'lemma',
				'lemmagloss',
				'form',
				'pform',
				'nform',
				'norm',
				'word',
				'orth',
				'phon',
				'pos',
				'msd',
				'feats',
				'upos',
				'xpos',
				'deprel',
				'dep',
				'head',
				'join',
				'syn',
				'sense',
				'trans',
				'transl',
				'lang',
				'xml_lang',
				'hand',
				'resp',
				'cert',
				'style',
				'rend',
				'cite',
				'ana',
				'corresp',
				'function',
				'class',
				'subtype',
				'type',
				'ns',
				't',
				'teiheader',
				'tei',
				'facsimile',
				'surface',
				'facs',
				'bbox',
				'baseline',
			]);
		},

		/** Normalized ids in allowlist (matches flexicorp.php tt_flexicorp_context_scope_region_allowlist_resolved). */
		contextScopeRegionAllowlistSet() {
			const raw = this.settings && this.settings.contextScopeRegionAllowlist;
			const list =
				Array.isArray(raw) && raw.length
					? raw
					: ['s', 'u', 'lb', 'l', 'p', 'seg'];
			return new Set(
				list
					.map((x) => {
						const n = this.normalizeContextScopeValue(x);
						return n && n !== 'window' && n !== 'tok' ? n : null;
					})
					.filter((x) => x)
			);
		},

		isContextScopeRegionKey(region) {
			const r = this.normalizeContextScopeValue(region);
			if (!r || r === 'window' || r === 'tok') return false;
			if (!/^[a-z][a-z0-9_-]{0,31}$/.test(r)) return false;
			if (this.contextScopeRegionNoiseBlocklist().has(r)) return false;
			return this.contextScopeRegionAllowlistSet().has(r);
		},

		collectContextRegions() {
			const out = new Set();
			const addRegion = (raw) => {
				const region = this.normalizeContextScopeValue(raw);
				if (!region || region === 'window' || region === 'tok') return;
				if (!this.isContextScopeRegionKey(region)) return;
				out.add(region);
			};
			const infoResult = this.info && this.info.result && typeof this.info.result === 'object' ? this.info.result : null;
			if (infoResult && Array.isArray(infoResult.structures)) {
				infoResult.structures.forEach((st) => {
					if (st && st.name) addRegion(st.name);
				});
			}
			const byRegion = infoResult && infoResult.sattributes_by_region && typeof infoResult.sattributes_by_region === 'object'
				? infoResult.sattributes_by_region
				: null;
			if (byRegion) Object.keys(byRegion).forEach(addRegion);
			const flatLists = [];
			if (infoResult && Array.isArray(infoResult.struct_attributes)) flatLists.push(infoResult.struct_attributes);
			if (infoResult && Array.isArray(infoResult.sattributes)) flatLists.push(infoResult.sattributes);
			if (infoResult && Array.isArray(infoResult.native_structures)) flatLists.push(infoResult.native_structures);
			flatLists.forEach((items) => {
				items.forEach((item) => {
					const text = String(item || '').trim();
					if (!text) return;
					if (text.includes('_')) addRegion(text.split('_', 1)[0]);
					else addRegion(text);
				});
			});
			if (Array.isArray(this.xidxRegionTypes) && this.xidxRegionTypes.length) {
				this.xidxRegionTypes.forEach((rt) => {
					const region = this.normalizeContextScopeValue(rt);
					if (!region || region === 'window' || region === 'tok') return;
					if (!this.isContextScopeRegionKey(region)) return;
					out.add(region);
				});
			}
			const ordered = Array.from(out);
			ordered.sort((a, b) => {
				const order = { s: 0, u: 1, seg: 2, l: 3, lb: 4, p: 5, text: 6, quote: 7 };
				const ao = Object.prototype.hasOwnProperty.call(order, a) ? order[a] : 99;
				const bo = Object.prototype.hasOwnProperty.call(order, b) ? order[b] : 99;
				return ao - bo || a.localeCompare(b);
			});
			return ordered;
		},

		/** Pando (native or flexi+queryEngine pando): when there is no context-scope dropdown, API default is token scope, not CWB window. */
		executionUsesPandoContextDefault() {
			const b = String((this.settings && this.settings.backend) || '').toLowerCase();
			if (b === 'pando' || b === 'flexicorp-pando') return true;
			return String((this.settings && this.settings.queryEngine) || '').toLowerCase() === 'pando';
		},

		contextScopeOptions() {
			const regions = this.collectContextRegions();
			const options = [];
			// Token window is a first-class option whenever the backend can use CWB / Pando window scope,
			// not a fallback that appears only when no regions qualify.
			if (this.contextScopeSupportsTokenWindow()) {
				options.push({ value: 'window', label: this.contextScopeLabel('window') });
			}
			regions.forEach((region) => {
				options.push({ value: region, label: this.contextScopeLabel(region) });
			});
			if (!options.length && this.contextScopeSupportsTokenWindow()) {
				options.push({ value: 'window', label: this.contextScopeLabel('window') });
			}
			return options;
		},

		preferredContextScopeValue() {
			const options = this.contextScopeOptions();
			if (!options.length) {
				if (this.executionUsesPandoContextDefault()) return 'tok';
				return 'window';
			}
			const current = this.normalizeContextScopeValue(this.search && this.search.contextScope);
			if (current && options.some((opt) => opt.value === current)) return current;
			if (options.some((opt) => opt.value === 's')) return 's';
			if (options.some((opt) => opt.value === 'l')) return 'l';
			if (options.some((opt) => opt.value === 'lb')) return 'lb';
			if (options.some((opt) => opt.value === 'window')) return 'window';
			return options[0].value;
		},

		ensureValidContextScope() {
			if (!this.search || typeof this.search !== 'object') return;
			const before = this.normalizeContextScopeValue(this.search.contextScope);
			if (!this.currentBackendSupportsXmlContext() && this.search.contextFormat === 'xml') {
				this.search.contextFormat = 'text';
			}
			const normalized = this.normalizeContextScopeValue(this.search.contextScope);
			const options = this.contextScopeOptions();
			if (normalized && options.some((opt) => opt.value === normalized)) {
				this.search.contextScope = normalized;
				return;
			}
			this.search.contextScope = this.preferredContextScopeValue();
			const after = this.normalizeContextScopeValue(this.search.contextScope);
			if (before !== after) {
				const vm = this.normalizeSearchViewMode(this.search.viewMode);
				const preferred = this.preferredSearchViewModeForCurrentScope();
				if ((preferred === 'kwic' && (vm === 'grouped' || vm === 'table')) || (preferred === 'table' && vm === 'kwic')) {
					this.search.viewMode = preferred;
				}
			}
		},

		/**
		 * When there is no structural context scope to choose, Table / Grouped views have nothing
		 * to show in the "context" sense — default to KWIC. This covers two cases:
		 *   (a) opts is empty (e.g. Pando with only text/quote in xidx, blocked), and
		 *   (b) opts contains only token-window scopes ('window'/'tok'), e.g. a CQP corpus
		 *       with no sentence/utterance regions ("s-less"). In that situation 'window' is
		 *       always present as a first-class option, so opts.length > 0 even though there
		 *       is no real structural region to group by — Group / Table still cannot render
		 *       meaningfully and KWIC must be the default.
		 * Must run after ensureValidSearchViewMode so the latter does not restore "table" from
		 * the saved state.
		 */
		_syncSearchViewWhenNoContextScope() {
			if (!this.search || typeof this.search !== 'object') return;
			const opts = this.contextScopeOptions();
			const hasStructuralScope = opts.some((o) => o && o.value !== 'window' && o.value !== 'tok');
			if (hasStructuralScope) return;
			const vm = this.normalizeSearchViewMode(this.search.viewMode);
			if (vm === 'table' || vm === 'grouped') {
				this.search.viewMode = 'kwic';
			}
		},

		normalizeSearchViewMode(value) {
			const text = String(value || '').trim().toLowerCase();
			if (text === 'anchor-kwic') return 'anchor_kwic';
			return text || 'table';
		},

		searchViewModeOptions() {
			const options = [
				{ value: 'grouped', label: 'Grouped context' },
				{ value: 'table', label: 'Table' },
				{ value: 'kwic', label: 'KWIC' },
			];
			if (this.searchSupportsAnchorKwic() || this.normalizeSearchViewMode(this.search && this.search.viewMode) === 'anchor_kwic') {
				options.push({ value: 'anchor_kwic', label: 'Anchor KWIC' });
			}
			return options;
		},

		preferredSearchViewModeForCurrentScope() {
			if (this.shouldAutoPreferGroupedAlignedView()) return 'grouped';
			const scope = this.normalizeContextScopeValue(this.search && this.search.contextScope);
			if (scope === 'window' || scope === 'tok') return 'kwic';
			return 'table';
		},

		shouldAutoPreferGroupedAlignedView() {
			// Sentence / XML-region aligned targets (alias : <region>) benefit from grouped rows.
			if (this.queryUsesStructuralAlignedTarget()) return true;
			if (!this.searchHasAlignedHits()) return false;
			const hits = Array.isArray(this.search && this.search.hits) ? this.search.hits : [];
			if (!hits.length) return false;
			// Typical parallel/bitext queries have exactly one companion per source row.
			// Prefer KWIC/table unless at least one source row fans out to multiple targets.
			for (let i = 0; i < hits.length; i += 1) {
				const hit = hits[i];
				if (!hit || typeof hit !== 'object') continue;
				const companions = this.getGroupedAlignedCompanions(hit);
				if (companions.length > 1) return true;
			}
			return false;
		},

		onContextScopeChanged() {
			if (!this.search || typeof this.search !== 'object') return;
			this.debugViewModeLog('onContextScopeChanged:before');
			this.ensureValidContextScope();
			const vm = this.normalizeSearchViewMode(this.search.viewMode);
			const preferred = this.preferredSearchViewModeForCurrentScope();
			// Scope switch defaults: token-window -> KWIC; structural region -> Table; many aligned -> Grouped.
			if (vm !== preferred) {
				this.search.viewMode = preferred;
			}
			// Scope change should re-enable sensible defaults for that scope.
			this.searchViewModeAuto = true;
			this.debugViewModeLog('onContextScopeChanged:after');
		},

		onSearchViewModeChanged(event) {
			this.searchViewModeAuto = false;
			this.debugViewModeLog('onSearchViewModeChanged:user', {
				eventTrusted: event && Object.prototype.hasOwnProperty.call(event, 'isTrusted') ? !!event.isTrusted : null,
				value: this.normalizeSearchViewMode(this.search && this.search.viewMode),
			});
			this.updateSearchShareButtonVisibility();
			this.scheduleAlignedTableHeightSync();
		},

		ensureValidSearchViewMode() {
			if (!this.search || typeof this.search !== 'object') return;
			this.debugViewModeLog('ensureValidSearchViewMode:before');
			const normalized = this.normalizeSearchViewMode(this.search.viewMode);
			const options = this.searchViewModeOptions();
			if (options.some((opt) => opt.value === normalized)) {
				this.search.viewMode = normalized;
				if (this.searchViewModeAuto) {
					const preferred = this.preferredSearchViewModeForCurrentScope();
					if (options.some((opt) => opt.value === preferred) && normalized !== preferred) {
						this.search.viewMode = preferred;
					}
				}
				this.debugViewModeLog('ensureValidSearchViewMode:after-existing');
				return;
			}
			const preferred = this.preferredSearchViewModeForCurrentScope();
			this.search.viewMode = options.some((opt) => opt.value === preferred) ? preferred : 'table';
			this.debugViewModeLog('ensureValidSearchViewMode:after-fallback');
		},
	};
};
