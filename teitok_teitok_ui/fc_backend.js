/**
 * flexicorp TEITOK UI: Backends, engines, query languages, corpora and reindexing.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.backend = function () {
	return {
		getBackendLabel(backend) {
			const item = this.backendOptions[backend];
			return item ? item.label : (backend || '');
		},

		getBackendDisplayLabel(backend) {
			const key = String(backend || '').trim();
			if (!key) return '';
			const keyLc = key.toLowerCase();
			const defaults = {
				pmltq: 'PML Tree Query',
				pando: 'Pando',
				'flexicorp-pando': 'Pando',
				cqp: 'CWB/CQP',
				manatee: 'Manatee',
				blacklab: 'BlackLab',
				clickhouse: 'ClickHouse',
				clickql: 'ClickQL',
			};
			const map = this.backendDisplayNames && typeof this.backendDisplayNames === 'object'
				? this.backendDisplayNames
				: {};
			if (map[key] != null && String(map[key]).trim() !== '') return String(map[key]).trim();
			if (map[keyLc] != null && String(map[keyLc]).trim() !== '') return String(map[keyLc]).trim();
			if (defaults[keyLc]) return defaults[keyLc];
			return this.getBackendLabel(key);
		},

		getBackendDescription(backend) {
			const item = this.backendOptions[backend];
			return item ? item.description : '';
		},

		getQueryLanguageHelpUrl(queryLanguage) {
			if (!queryLanguage) return '';
			const key = String(queryLanguage || '').toLowerCase();
			const map = this.queryLanguageHelpDocs || {};
			const action = typeof map[key] === 'string' ? map[key] : '';
			return action ? `index.php?action=${encodeURIComponent(action)}` : '';
		},

		/** Build href for combo help_url: flexicorp docs (when set) or TEITOK action. */
		helpHref(helpUrl) {
			if (!helpUrl) return '#';
			// Doc path (e.g. install-manatee-bindings.md) → flexicorp docs base or relative docs/
			if (String(helpUrl).indexOf('install-manatee-bindings') !== -1 || helpUrl.endsWith('.md')) {
				const base = (this.flexicorpDocsUrl || '').replace(/\/$/, '');
				return base ? base + '/' + helpUrl : 'docs/' + helpUrl;
			}
			return 'index.php?action=' + encodeURIComponent(helpUrl);
		},

		getExecutionEngineLabel(engine) {
			if (engine && String(engine).trim()) return String(engine).trim();
			if (this.settings && this.settings.queryEngine && String(this.settings.queryEngine).trim()) {
				return String(this.settings.queryEngine).trim();
			}
			if (this.settings && this.settings.corpusFormat && String(this.settings.corpusFormat).trim()) {
				return String(this.settings.corpusFormat).trim();
			}
			return '';
		},

		/** True when the active execution path is CWB/CQP (not Pando, Manatee, etc.). */
		isCwbBackend(settings) {
			const s = settings || this.settings || {};
			const backend = String(s.backend || '').toLowerCase();
			const ql = String(s.queryLanguage || '').toLowerCase();
			const cf = String(s.corpusFormat || '').toLowerCase();
			if (backend === 'cqp') return true;
			if (
				backend === 'flexi' &&
				(ql === 'cwb-cql' || ql === 'cwb' || ql === 'cql' || cf === 'cwb')
			) {
				return true;
			}
			return false;
		},

		/** Human-friendly title for the currently selected search tool (backend + query language). */
		currentToolTitle() {
			if (!this.settings) return 'Corpus search';
			const backend = String(this.settings.backend || '').trim();
			const label = this.getBackendDisplayLabel(backend);
			return label || 'Corpus search';
		},

		queryLanguageShort(code) {
			if (!code) return '';
			const key = String(code).toLowerCase();
			const map = this.queryLanguageOptions || {};
			const meta = map[key];
			return meta && meta.short ? meta.short : code;
		},

		getQueryLanguageHelpTitle(queryLanguage, engine) {
			const ql = String(queryLanguage || '').trim();
			const resolvedEngine = this.getExecutionEngineLabel(engine);
			if (!ql && !resolvedEngine) return '';
			if (ql && resolvedEngine) return `The ${ql} query will be executed on the ${resolvedEngine} database.`;
			if (ql) return `Help for ${ql}.`;
			return `Queries will be executed on ${resolvedEngine}.`;
		},

		/** Backend name to pass as reindex_backends (CLI expects cqp/manatee/clickhouse, not corpusFormat cwb). */
		reindexBackendForCombo(combo) {
			if (!combo) return combo && combo.backend;
			return (combo.corpusFormat === 'cwb' ? 'cqp' : (combo.corpusFormat || combo.backend));
		},

		selectedReindexBackends() {
			const list = this.backendCombinationList();
			return [...new Set(
				list
					.filter((c) => c && this.reindexSelection[c.id] && this.canReindexBackend(c))
					.map((c) => this.reindexBackendForCombo(c))
			)];
		},

		/** Build a list of logical (backend, query-language, corpus-format) combinations from state.backendCombos. */
		backendCombinationList() {
			const combos = Array.isArray(this.backendCombos) ? this.backendCombos : [];
			return combos.map((c) => {
				const isCurrent =
					this.settings &&
					this.settings.backend === c.backend &&
					this.settings.queryLanguage === c.queryLanguage &&
					this.settings.corpusFormat === c.corpusFormat;
				return {
					...(c && typeof c === 'object' ? c : {}),
					id: c.id,
					backend: c.backend,
					queryLanguage: c.queryLanguage,
					corpusFormat: c.corpusFormat,
					available: !!c.available,
					reason: c.reason || '',
					isCurrent,
				};
			});
		},

		/** Current (backend, query language, corpus format) row from backendCombos; used for engine-specific Stats tabs. */
		currentBackendComboRecord() {
			const combos = this.backendCombinationList();
			let hit = combos.find((c) => c && c.isCurrent);
			// flexi+pando-cql+pando often resolves to backend=pando server-side, but the client can
			// briefly have flexi + pando dialect with no matching combo id → hide Collocations / caps.
			if (!hit && this.settings) {
				const ql = this.settings.queryLanguage;
				const cf = this.settings.corpusFormat;
				hit = combos.find(
					(c) => c && c.available && c.queryLanguage === ql && c.corpusFormat === cf,
				);
			}
			if (!hit && this.settings && this.settings.queryEngine === 'pando') {
				hit = combos.find((c) => c && c.available && c.id === 'pando:pando-cql:pando');
			}
			return hit || null;
		},

		currentBackendQueryLanguageOptions() {
			const combos = this.backendCombinationList().filter(
				(c) =>
					c &&
					c.available &&
					c.backend !== 'flexi' &&
					c.backend !== 'clickql' &&
					c.backend !== 'clickhouse'
			);
			const seen = new Set();
			const unique = [];
			combos.forEach((combo) => {
				const queryLanguage = combo && combo.queryLanguage ? String(combo.queryLanguage) : '';
				const corpusFormat = combo && combo.corpusFormat ? String(combo.corpusFormat) : '';
				const optionId = `${queryLanguage}::${corpusFormat}`;
				if (!queryLanguage || seen.has(optionId)) return;
				seen.add(optionId);
				unique.push({
					optionId,
					queryLanguage,
					corpusFormat,
				});
			});
			const counts = {};
			unique.forEach((item) => {
				const key = `${item.backend}::${item.queryLanguage}`;
				counts[key] = (counts[key] || 0) + 1;
			});
			return unique.map((item) => ({
				...item,
				label:
					counts[`${item.backend}::${item.queryLanguage}`] > 1
						? `${item.queryLanguage} (${item.corpusFormat})`
						: item.queryLanguage,
			}));
		},

		hasMultipleQueryLanguageOptions() {
			return this.currentBackendQueryLanguageOptions().length > 1;
		},

		currentQueryLanguageOptionId() {
			if (this.settingsSelectedComboId) return this.settingsSelectedComboId;
			const currentBackend = this.settings && this.settings.backend ? String(this.settings.backend) : '';
			const currentQueryLanguage = this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage) : '';
			const currentCorpusFormat = this.settings && this.settings.corpusFormat ? String(this.settings.corpusFormat) : '';
			const combos = this.backendCombinationList().filter(
				(c) =>
					c &&
					c.available &&
					c.backend !== 'flexi' &&
					c.backend !== 'clickql' &&
					c.backend !== 'clickhouse'
			);
			const exactCombo = combos.find(
				(c) =>
					c.backend === currentBackend &&
					c.queryLanguage === currentQueryLanguage &&
					c.corpusFormat === currentCorpusFormat
			);
			if (exactCombo && exactCombo.id) return exactCombo.id;
			return combos.length && combos[0].id ? combos[0].id : '';
		},

		useCurrentBackendQueryLanguage(optionId) {
			// Preserve for older controls that still use queryLanguage/corpusFormat-only options
			const selected = this.currentBackendQueryLanguageOptions().find((opt) => opt.optionId === optionId);
			if (!selected) return;
			this.settings.queryLanguage = selected.queryLanguage;
			this.settings.corpusFormat = selected.corpusFormat;
			if (this.settings.backend === 'flexi') {
				if (selected.corpusFormat === 'manatee') this.settings.queryEngine = 'manatee';
				else if (selected.corpusFormat === 'cwb') this.settings.queryEngine = 'cqp';
				else if (selected.corpusFormat === 'pando') {
					this.settings.queryEngine = 'pando';
					this.settings.backend = 'pando';
				}
			} else if (this.settings.backend === 'pando' || this.settings.backend === 'flexicorp-pando') {
				this.settings.queryEngine = 'pando';
			}
			if (this.search && this.search.queryHighlightEnabled) {
				if (this.queryHighlightSupported()) this.scheduleQueryHighlight();
				else {
					this.search.queryHighlightHtml = '';
					this.search.queryHighlightError = '';
				}
			}
		},

		useSettingsBackendCombination(comboId) {
			if (!comboId) return;
			this.settingsSelectedComboId = comboId;
			const combos = this.backendCombinationList();
			const combo = combos.find((c) => c && c.id === comboId);
			if (!combo) return;
			this.useBackendCombination(combo);
		},


		hasLockedReindexBackend() {
			const combos = Array.isArray(this.backendCombos) ? this.backendCombos : [];
			return combos.some((c) => c && c.reindex && c.reindex.locked);
		},

		async refreshOverviewIfReindexing() {
			if (!this.hasLockedReindexBackend()) {
				if (this._reindexOverviewTimerId) {
					clearInterval(this._reindexOverviewTimerId);
					this._reindexOverviewTimerId = null;
				}
				return;
			}
			const formData = this.buildCommonRequestData();
			formData.set('active_tab', 'overview');
			formData.set('run', '');
			await this.submitAjaxData(formData, 'backend');
		},

		updateReindexOverviewWatcher() {
			const hasLocked = this.hasLockedReindexBackend();
			if (hasLocked && !this._reindexOverviewTimerId) {
				this._reindexOverviewTimerId = setInterval(() => {
					this.refreshOverviewIfReindexing();
				}, 10000);
			} else if (!hasLocked && this._reindexOverviewTimerId) {
				clearInterval(this._reindexOverviewTimerId);
				this._reindexOverviewTimerId = null;
			}
		},

		/** When query engine dropdown changes: flexi updates queryEngine; cqp/manatee switches backend and submits. */
		onQueryEngineChange(event) {
			const value = event && event.target ? event.target.value : '';
			if (!value) return;
			if (this.settings.backend === 'flexi') {
				this.settings.queryEngine = value;
				if (value === 'pando') {
					this.settings.backend = 'pando';
					this.settings.queryLanguage = 'pando-cql';
					this.settings.corpusFormat = 'pando';
				} else if (value === 'manatee') {
					this.settings.queryLanguage = 'manatee-cql';
					this.settings.corpusFormat = 'manatee';
				} else if (value === 'cqp') {
					this.settings.queryLanguage = 'cwb-cql';
					this.settings.corpusFormat = 'cwb';
				}
				this.syncFlexicorpSelectionToUrl();
				this.persistFlexicorpSelectionToSessionStorage();
			} else if (this.settings.backend === 'cqp' || this.settings.backend === 'manatee') {
				if (value === 'pando') {
					this.settings.backend = 'pando';
					this.settings.queryEngine = 'pando';
					this.settings.queryLanguage = 'pando-cql';
					this.settings.corpusFormat = 'pando';
					this.syncFlexicorpSelectionToUrl();
					this.persistFlexicorpSelectionToSessionStorage();
				} else if (value === 'manatee') {
					this.settings.backend = 'manatee';
					this.settings.queryEngine = 'manatee';
					this.settings.queryLanguage = 'manatee-cql';
					this.settings.corpusFormat = 'manatee';
					this.syncFlexicorpSelectionToUrl();
					this.persistFlexicorpSelectionToSessionStorage();
				} else {
					this.settings.backend = value;
					this.settings.queryEngine = value;
					this.submitBackendFromButton();
				}
			}
		},

		/** When backend selector changes in the toolbar, pick a sensible (available) combination for it. */
		onBackendChanged() {
			const backend = this.settings && this.settings.backend ? this.settings.backend : 'cqp';
			const combos = (Array.isArray(this.backendCombos) ? this.backendCombos : []).filter(
				(c) => c.backend === backend && c.available
			);
			if (combos.length) {
				const first = combos[0];
				this.settings.queryLanguage = first.queryLanguage;
				this.settings.corpusFormat = first.corpusFormat;
				if (backend === 'pando' || backend === 'flexicorp-pando') this.settings.queryEngine = 'pando';
				else if (backend === 'cqp' || backend === 'manatee' || backend === 'blacklab' || backend === 'clickql' || backend === 'clickhouse') this.settings.queryEngine = backend;
			}
		},

		/** From overview: switch to a full (backend, query-language, corpus-format) combination and run Compare (Details). */
		useBackendCombination(combo) {
			if (!this.canUseBackendCombination(combo)) return;
			this.settings.backend = combo.backend;
			this.settings.queryLanguage = combo.queryLanguage;
			this.settings.corpusFormat = combo.corpusFormat;
			if (this.settings.backend === 'flexi' && this.settings.queryEngine) {
				// keep flexi queryEngine (cqp/manatee) as-is
			} else if (this.settings.backend === 'pando' || this.settings.backend === 'flexicorp-pando') {
				this.settings.queryEngine = 'pando';
			} else if (['cqp', 'manatee'].includes(this.settings.backend)) {
				this.settings.queryEngine = this.settings.backend;
			} else if (['blacklab', 'clickql', 'clickhouse'].includes(this.settings.backend)) {
				this.settings.queryEngine = this.settings.backend;
			}
			this.submitBackendFromButton();
		},

		/** Backends that share the same underlying storage (reindexing one affects all). */
		reindexAffectsBackend(targetBackend, comboOrBackend) {
			const comboBackend = typeof comboOrBackend === 'object' && comboOrBackend !== null
				? this.reindexBackendForCombo(comboOrBackend) : comboOrBackend;
			if (targetBackend === comboBackend) return true;
			const chGroup = ['clickhouse', 'clickql'];
			return chGroup.includes(targetBackend) && chGroup.includes(comboBackend);
		},

		isReindexingCombo(combo) {
			if (!combo) return false;
			const targets = Array.isArray(this.reindexTargetBackends) ? this.reindexTargetBackends : [];
			const targetAffectsCombo = targets.some((b) => this.reindexAffectsBackend(b, combo));
			const loading = !!(this.loading && this.loading.reindex);
			const polling =
				!!(this.reindex && this.reindex.result && this.reindex.result.indexer && this.reindex.result.indexer.job_id);
			const immediate = targetAffectsCombo && (loading || polling);
			const resultBackends = this.reindex && this.reindex.result && this.reindex.result.reindex_backends;
			const enqueued =
				!!resultBackends &&
				Array.isArray(resultBackends) &&
				resultBackends.some((b) => this.reindexAffectsBackend(b, combo)) &&
				(this.reindex.result.status === 'enqueued' || !!(this.reindex.result.indexer && this.reindex.result.indexer.enqueued));
			const locked = !!(combo.reindex && combo.reindex.locked);
			return immediate || enqueued || locked;
		},

		comboStatusLabel(combo) {
			if (this.isReindexingCombo(combo)) return 'Reindexing';
			return combo && combo.available ? 'Available' : 'Unavailable';
		},

		comboReasonText(combo) {
			if (this.isReindexingCombo(combo)) return 'Reindex currently running for this backend.';
			return combo && combo.reason ? combo.reason : '';
		},

		reindexProgress() {
			const p = this.reindex && this.reindex.result && this.reindex.result.progress;
			return p && typeof p === 'object' ? p : null;
		},

		reindexProgressPct() {
			const p = this.reindexProgress();
			if (!p || p.pct == null) return null;
			const n = Number(p.pct);
			if (!Number.isFinite(n)) return null;
			return Math.max(0, Math.min(100, n));
		},

		reindexProgressLabel() {
			const p = this.reindexProgress();
			if (!p) return '';
			const parts = [];
			if (p.phase) parts.push(String(p.phase));
			if (p.manatee_current) {
				parts.push(String(p.manatee_current));
			}
			if (p.manatee_done != null && p.manatee_total != null) {
				parts.push(`mkstats ${p.manatee_done}/${p.manatee_total}`);
			}
			if (p.files_done != null && p.files_total != null) {
				parts.push(`${p.files_done}/${p.files_total} files`);
			}
			if (p.bytes_done != null && p.bytes_total != null) {
				parts.push(`${p.bytes_done}/${p.bytes_total} bytes`);
			}
			return parts.join(' - ');
		},

		isFqsReindexResultMessage() {
			const result = this.reindex && this.reindex.result && typeof this.reindex.result === 'object'
				? this.reindex.result
				: null;
			if (!result) return false;
			return String(result.source || '').toLowerCase() === 'fqs';
		},

		canUseBackendCombination(combo) {
			return !!(combo && combo.available && !combo.isCurrent && !this.isReindexingCombo(combo));
		},

		canChooseCorpora(combo) {
			// Corpora switcher disabled: would allow access to other corpora on the same BlackLab server.
			return false;
		},

		canReindexBackend(combo) {
			return !!(
				this.isAdmin &&
				combo &&
				combo.capabilities &&
				combo.capabilities.reindex &&
				(combo.reindexAvailable !== false)
			);
		},

		selectedBlacklabCorpusId() {
			return this.backendOverrides && this.backendOverrides.blacklab_corpus ? String(this.backendOverrides.blacklab_corpus) : '';
		},

		closeCorpora() {
			this.corporaOpen = false;
			this.corporaLoading = false;
			this.corporaError = '';
			this.corporaTargetCombo = null;
		},

		async openBackendCorpora(combo) {
			if (this.isReindexingCombo(combo)) return;
			this.corporaOpen = true;
			this.corporaLoading = true;
			this.corporaError = '';
			this.corporaList = [];
			this.corporaTargetCombo = combo || null;
			this.corporaTitle = `Available corpora for ${this.getBackendLabel('blacklab')}`;
			const formData = this.buildCommonRequestData();
			formData.set('backend', 'blacklab');
			formData.set('ajax', '1');
			formData.set('list_corpora', '1');
			if (combo && combo.queryLanguage) formData.set('query_language', combo.queryLanguage);
			if (combo && combo.corpusFormat) formData.set('corpus_format', combo.corpusFormat);
			try {
				let payload = null;
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
					payload = out.state;
				} else {
					const response = await fetch(this.getRequestUrl(), {
						method: 'POST',
						body: formData,
						headers: { 'X-Requested-With': 'XMLHttpRequest' },
					});
					if (!response.ok) throw new Error(`Request failed with ${response.status}`);
					payload = await response.json();
				}
				const errors = []
					.concat(payload && payload.error ? [payload.error] : [])
					.concat(Array.isArray(payload && payload.errors) ? payload.errors : []);
				if (errors.length) {
					this.corporaError = errors.join(' ');
					return;
				}
				const result = payload && payload.result && typeof payload.result === 'object' ? payload.result : {};
				this.corporaList = Array.isArray(result.corpora) ? result.corpora : [];
			} catch (err) {
				this.corporaError = err && err.message ? err.message : String(err);
			} finally {
				this.corporaLoading = false;
			}
		},

		chooseBlacklabCorpus(corpusId) {
			const target = String(corpusId || '').trim();
			if (!target) return;
			this.backendOverrides.blacklab_corpus = target;
			this.settings.backend = 'blacklab';
			this.settings.queryLanguage = (this.corporaTargetCombo && this.corporaTargetCombo.queryLanguage) ? this.corporaTargetCombo.queryLanguage : 'bcql';
			this.settings.corpusFormat = (this.corporaTargetCombo && this.corporaTargetCombo.corpusFormat) ? this.corporaTargetCombo.corpusFormat : 'blacklab';
			this.corporaOpen = false;
			this.submitBackendFromButton();
		},

		/** Poll reindex job status (BlackLab queue) until done or failed. Updates this.reindex and clears reindexTargetComboId when finished. */
		async pollReindexStatusUntilFinished(jobId) {
			const delayMs = 2500;
			const maxPolls = 240; // ~10 min
			for (let i = 0; i < maxPolls; i++) {
				const formData = new FormData();
				formData.set('action', this.action || 'flexicorp');
				formData.set('ajax', '1');
				formData.set('reindex_status', '1');
				formData.set('reindex_job_id', jobId);
				if (this.projectRoot) formData.set('projectRoot', this.projectRoot);
				try {
					let data = null;
					if (
						typeof window !== 'undefined' &&
						window.ttFlexicorpFns &&
						typeof window.ttFlexicorpFns.runFlexicorpAjaxFetch === 'function'
					) {
						const out = await window.ttFlexicorpFns.runFlexicorpAjaxFetch(
							formData,
							this.$root || null
						);
						if (!out || !out.response || !out.response.ok || out.parseError) continue;
						data = out.state;
					} else {
						const response = await fetch(this.getRequestUrl(), { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
						if (!response.ok) continue;
						data = await response.json();
					}
					const status = data && data.result && data.result.status ? data.result.status : '';
					const progress = data && data.result && data.result.progress ? data.result.progress : null;
					const source = data && data.result && data.result.source ? String(data.result.source) : 'background';
					if (source === 'fqs' && typeof this.onFqsReindexFeedback === 'function') {
						this.onFqsReindexFeedback(data && data.result ? data.result : null);
					}
					const reindexBackends = (data && data.result && Array.isArray(data.result.reindex_backends))
						? data.result.reindex_backends
						: ((this.reindex && this.reindex.result && Array.isArray(this.reindex.result.reindex_backends)) ? this.reindex.result.reindex_backends : []);
					const backendLabel = reindexBackends.length
						? ` for ${reindexBackends.map((b) => this.getBackendLabel(String(b || ''))).join(', ')}`
						: '';
					const durationSec = (data && data.result && data.result.duration_sec != null) ? Number(data.result.duration_sec) : null;
					const durationLabel = (durationSec != null && Number.isFinite(durationSec) && durationSec >= 0)
						? ` in ${durationSec.toFixed(durationSec >= 10 ? 0 : 1)}s`
						: '';
					const flexBin = data && data.result && data.result.flexencoder_binary ? String(data.result.flexencoder_binary) : '';
					const sourceLabel = source ? ` via ${source}` : '';
					const jobLabel = jobId ? ` (job ${jobId})` : '';
					if (status === 'pending' && progress) {
						const pct = progress.pct != null ? String(progress.pct) : '';
						const phase = progress.phase ? String(progress.phase) : '';
						const msg = pct !== ''
							? `Background reindex${jobLabel}${backendLabel}${sourceLabel}: ${pct}%${phase ? ` (${phase})` : ''}`
							: (phase !== ''
								? `Background reindex${jobLabel}${backendLabel}${sourceLabel}: ${phase}`
								: `Background reindex${jobLabel}${backendLabel}${sourceLabel} running…`);
						this.reindex = {
							...(this.reindex || {}),
							ok: true,
							error: '',
							errors: [],
							warnings: [],
							result: {
								...(this.reindex && this.reindex.result ? this.reindex.result : {}),
								message: msg,
								progress,
								source,
								job_id: jobId,
								reindex_backends: reindexBackends,
								duration_sec: durationSec,
								flexencoder_binary: flexBin || (this.reindex && this.reindex.result ? this.reindex.result.flexencoder_binary : ''),
							},
						};
					}
					if (status === 'done') {
						const binNote = flexBin ? ` (flexencoder: ${flexBin})` : '';
						this.reindex = {
							ok: true,
							error: '',
							errors: [],
							warnings: [],
							result: {
								message: `Background reindex${jobLabel}${backendLabel}${sourceLabel} completed${durationLabel}.${binNote}`,
								stdout: data.result.stdout,
								stderr: data.result.stderr,
								source,
								job_id: jobId,
								progress,
								reindex_backends: reindexBackends,
								duration_sec: durationSec,
								flexencoder_binary: flexBin,
							},
						};
						this.reindexTargetComboId = '';
						return;
					}
					if (status === 'failed') {
						const rawErr = data.result && data.result.error ? data.result.error : 'Reindex failed.';
						if (source === 'fqs' && typeof this.appendClientDebugEntry === 'function') {
							this.appendClientDebugEntry({
								time: new Date().toLocaleTimeString(),
								backend: 'fqs',
								operation: 'reindex_status',
								ok: false,
								command: '',
								raw: '',
								errors: [String(rawErr)],
								warnings: [],
								result: data && data.result ? data.result : {},
							});
						}
						this.reindex = {
							ok: false,
							error: `Background reindex${jobLabel}${backendLabel}${sourceLabel} failed${durationLabel}: ${rawErr}`,
							errors: [`Background reindex${jobLabel}${backendLabel}${sourceLabel} failed${durationLabel}: ${rawErr}`],
							warnings: [],
							result: { ...(data.result || {}), source, job_id: jobId, progress, reindex_backends: reindexBackends, duration_sec: durationSec, flexencoder_binary: flexBin },
						};
						this.reindexTargetComboId = '';
						return;
					}
				} catch (_) { /* ignore poll errors, retry */ }
				await new Promise(r => setTimeout(r, delayMs));
			}
			this.reindex = { ok: false, error: 'Reindex status poll timed out.', errors: ['Timed out waiting for job.'], warnings: [], result: null };
			this.reindexTargetComboId = '';
		},

		async runBackendReindex(combo) {
			if (!this.canReindexBackend(combo)) return;
			const reindexBackend = this.reindexBackendForCombo(combo);
			const backendLabel = this.getBackendLabel(combo.backend);
			const target = combo.backend === 'blacklab' && this.selectedBlacklabCorpusId()
				? ` for corpus ${this.selectedBlacklabCorpusId()}`
				: '';
			const ok = window.confirm(`Run reindex for ${backendLabel}${target}?`);
			if (!ok) return;
			const listingDescription = this.askListingDescription();
			if (listingDescription === null) return;
			const formData = new FormData();
			if (listingDescription) formData.set('corpus_description', listingDescription);
			formData.set('action', this.action || 'flexicorp');
			formData.set('backend', combo.backend);
			formData.set('reindex_backend', combo.backend);
			formData.append('reindex_backends[]', reindexBackend);
			if (combo.backend === 'blacklab') formData.set('blacklab_reindex_engine', 'docker');
			if (this.backendOverrides && this.backendOverrides.blacklab_url) formData.set('blacklab_url', this.backendOverrides.blacklab_url);
			if (this.backendOverrides && this.backendOverrides.blacklab_corpus) formData.set('blacklab_corpus', this.backendOverrides.blacklab_corpus);
			if (this.backendOverrides && this.backendOverrides.blacklab_user) formData.set('blacklab_user', this.backendOverrides.blacklab_user);
			if (this.backendOverrides && this.backendOverrides.blacklab_password) formData.set('blacklab_password', this.backendOverrides.blacklab_password);
			if (this.backendOverrides && this.backendOverrides.blacklab_field) formData.set('blacklab_field', this.backendOverrides.blacklab_field);
			formData.set('active_tab', 'overview');
			formData.set('run', 'reindex');
			this.reindex = {};
			if (this.responses && typeof this.responses === 'object') {
				this.responses.reindex = {};
			}
			this.reindexTargetComboId = combo.id || '';
			this.reindexTargetBackends = [reindexBackend];
			try {
				await this.submitAjaxData(formData, 'reindex');
				const idx = this.reindex && this.reindex.result && this.reindex.result.indexer;
				const enqueued = !!(this.reindex && this.reindex.ok && idx && idx.enqueued && idx.job_id);
				if (enqueued) {
					await this.pollReindexStatusUntilFinished(this.reindex.result.indexer.job_id);
				}
			} finally {
				this.reindexTargetComboId = '';
				this.reindexTargetBackends = [];
			}
		},

		/**
		 * Indexing puts the corpus in the corpus list, which shows its description: when it
		 * has none, ask for one. Returns the text, '' when not needed, null when cancelled.
		 */
		askListingDescription() {
			const cl = this.corpusListing;
			if (!cl || !cl.descriptionRequired || (cl.description && cl.description.trim())) return '';
			const text = window.prompt(
				'This corpus has no description yet. Indexing lists it in the corpus list, which shows '
				+ 'what it is: write one or two sentences about it (you can change it later as the page "description").'
			);
			if (text === null) return null;
			if (!text.trim()) {
				window.alert('Indexing needs a short description of the corpus.');
				return null;
			}
			cl.description = text.trim();
			return text.trim();
		},

		toggleReindexSelection(combo) {
			if (!this.canReindexBackend(combo)) return;
			const id = combo && combo.id;
			if (!id) return;
			this.reindexSelection = { ...this.reindexSelection, [id]: !this.reindexSelection[id] };
		},

		async runReindexSelected() {
			const backends = this.selectedReindexBackends();
			if (!backends || backends.length === 0) return;
			const label = backends.map((b) => this.getBackendLabel(b)).join(', ');
			const ok = window.confirm(`Run reindex for selected backends (${label})? This will use flexencoder once for CQP/ClickHouse, then BlackLab separately if selected.`);
			if (!ok) return;
			const listingDescription = this.askListingDescription();
			if (listingDescription === null) return;
			const formData = this.buildCommonRequestData();
			if (listingDescription) formData.set('corpus_description', listingDescription);
			formData.set('active_tab', 'overview');
			formData.set('run', 'reindex');
			backends.forEach((b) => formData.append('reindex_backends[]', b));
			this.reindex = {};
			if (this.responses && typeof this.responses === 'object') this.responses.reindex = {};
			this.reindexTargetComboId = 'selected';
			this.reindexTargetBackends = [...backends];
			try {
				await this.submitAjaxData(formData, 'reindex');
				const idxSel = this.reindex && this.reindex.result && this.reindex.result.indexer;
				const enqueuedSel = !!(this.reindex && this.reindex.ok && idxSel && idxSel.enqueued && idxSel.job_id);
				if (enqueuedSel) {
					await this.pollReindexStatusUntilFinished(this.reindex.result.indexer.job_id);
				}
				this.reindexSelection = {};
			} finally {
				this.reindexTargetComboId = '';
				this.reindexTargetBackends = [];
			}
		},

		/** From overview: run status/info for a combination, then show status details in a popup, without changing current selection. */
		async useBackendStatus(combo) {
			if (!combo || this.isReindexingCombo(combo)) return;
			// First optimization step: when FQS is available and the requested combo is already
			// the current one, reuse loaded status/info instead of issuing another PHP round-trip.
			const sameAsCurrent =
				!!combo.isCurrent
				|| (
					String(combo.backend || '') === String(this.settings && this.settings.backend ? this.settings.backend : '')
					&& String(combo.queryLanguage || '') === String(this.settings && this.settings.queryLanguage ? this.settings.queryLanguage : '')
					&& String(combo.corpusFormat || '') === String(this.settings && this.settings.corpusFormat ? this.settings.corpusFormat : '')
				);
			if (typeof this.isFqsAvailable === 'function' && this.isFqsAvailable() && sameAsCurrent) {
				this.statusDetailsOpen = true;
				return;
			}
			// Build a request for the chosen combination, but keep the current settings unchanged.
			const formData = this.buildCommonRequestData();
			formData.set('backend', combo.backend);
			if (combo.queryLanguage) formData.set('query_language', combo.queryLanguage);
			if (combo.corpusFormat) formData.set('corpus_format', combo.corpusFormat);
			formData.set('active_tab', 'overview');
			// We are interested in status/info only, not running search or quantitative (freq) requests.
			formData.set('run', '');
			await this.submitAjaxData(formData, 'backend');
			// After the AJAX call updates state, open the structured status dialog.
			this.statusDetailsOpen = true;
		},
	};
};
