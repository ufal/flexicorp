/**
 * flexicorp TEITOK UI: Stats: subtab switching and corpus-stats loading.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_tabs = function () {
	return {
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
	};
};
