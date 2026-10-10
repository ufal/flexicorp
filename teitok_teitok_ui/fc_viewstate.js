/**
 * flexicorp TEITOK UI: View state: share links (visualization snapshots) and the remembered backend selection.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.viewstate = function () {
	return {
		_base64UrlEncodeUnicode(str) {
			try {
				const utf8 = encodeURIComponent(String(str)).replace(/%([0-9A-F]{2})/g, (_, p1) =>
					String.fromCharCode(parseInt(p1, 16))
				);
				return btoa(utf8).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
			} catch (_) {
				return '';
			}
		},

		_base64UrlDecodeUnicode(str) {
			try {
				const s = String(str || '').replace(/-/g, '+').replace(/_/g, '/');
				const pad = s.length % 4 === 0 ? '' : '='.repeat(4 - (s.length % 4));
				const bin = atob(s + pad);
				let pct = '';
				for (let i = 0; i < bin.length; i += 1) {
					pct += '%' + bin.charCodeAt(i).toString(16).padStart(2, '0');
				}
				return decodeURIComponent(pct);
			} catch (_) {
				return '';
			}
		},

		buildVisualizationSnapshotPayload() {
			const scopeQueries = this.statsSearchScopeStore && Array.isArray(this.statsSearchScopeStore.queries)
				? this.statsSearchScopeStore.queries.map((q) => ({
					id: q && q.id != null ? String(q.id) : '',
					name: q && q.name != null ? String(q.name) : '',
					assignId: q && q.assignId != null ? String(q.assignId) : '',
					query: q && q.query != null ? String(q.query) : '',
					active: !(q && q.active === false),
				}))
				: [];
			return {
				schema: 'flexicorp-viz-snapshot',
				version: 1,
				created_at: new Date().toISOString(),
				settings: {
					backend: this.settings && this.settings.backend ? String(this.settings.backend) : '',
					query_engine: this.settings && this.settings.queryEngine ? String(this.settings.queryEngine) : '',
					query_language: this.settings && this.settings.queryLanguage ? String(this.settings.queryLanguage) : '',
					corpus_format: this.settings && this.settings.corpusFormat ? String(this.settings.corpusFormat) : '',
				},
				ui: {
					active_tab: this.activeTab || 'search',
					stats_subtab: this.statsSubTab || 'freq',
				},
				search: {
					query: this.search && this.search.query ? String(this.search.query) : '',
					field: this.search && this.search.field ? String(this.search.field) : '',
					value: this.search && this.search.value ? String(this.search.value) : '',
					context_format: this.search && this.search.contextFormat ? String(this.search.contextFormat) : '',
					context_scope: this.search && this.search.contextScope ? String(this.search.contextScope) : '',
					view_mode: this.search && this.search.viewMode ? String(this.search.viewMode) : '',
					window: this.search && this.search.window != null ? Number(this.search.window) : null,
					limit: this.search && this.search.limit != null ? Number(this.search.limit) : null,
					// hits loaded so far ("Show more"): the link loads as many again
					loaded: typeof this.searchLoadedItemCount === 'function' ? this.searchLoadedItemCount() : null,
				},
				scope: {
					queries: scopeQueries,
				},
				frequency: {
					field: this.frequency && this.frequency.field ? String(this.frequency.field) : '',
					form_fields: this.frequency && Array.isArray(this.frequency.formFields) ? this.frequency.formFields.map((x) => String(x)) : [],
					limit: this.frequency && this.frequency.limit != null ? Number(this.frequency.limit) : null,
					viz_mode: this.frequencyVizMode != null ? String(this.frequencyVizMode) : '',
					chart_type: this.frequencyChartType != null ? String(this.frequencyChartType) : '',
					chart_scale: this.frequencyChartValueScale != null ? String(this.frequencyChartValueScale) : '',
					chart_order: this.frequencyChartOrder != null ? String(this.frequencyChartOrder) : '',
					table_search: this.frequencyTableSearch != null ? String(this.frequencyTableSearch) : '',
					table_sort: this.frequencyTableSort && typeof this.frequencyTableSort === 'object' ? this.frequencyTableSort : null,
					compare_metric_cols: Array.isArray(this.frequencyCompareMetricCols) ? this.frequencyCompareMetricCols.map((x) => String(x)) : [],
				},
				collocation: {
					field: this.collocation && this.collocation.field ? String(this.collocation.field) : '',
					anchor_token: this.collocation && this.collocation.anchorToken ? String(this.collocation.anchorToken) : '',
					left: this.collocation && this.collocation.left != null ? Number(this.collocation.left) : null,
					right: this.collocation && this.collocation.right != null ? Number(this.collocation.right) : null,
					min_freq: this.collocation && this.collocation.minFreq != null ? Number(this.collocation.minFreq) : null,
					max_items: this.collocation && this.collocation.maxItems != null ? Number(this.collocation.maxItems) : null,
					stoplist: this.collocation && this.collocation.stoplist != null ? Number(this.collocation.stoplist) : null,
					measure_keys: this.collocation && Array.isArray(this.collocation.measureKeys) ? this.collocation.measureKeys.map((x) => String(x)) : [],
					viz_mode: this.collocationVizMode != null ? String(this.collocationVizMode) : '',
					chart_metric: this.collocationChartMetricKey != null ? String(this.collocationChartMetricKey) : '',
				},
				dcoll: this.dcollAdv && typeof this.dcollAdv === 'object' ? {
					anchor_token: String(this.dcollAdv.anchorToken || ''),
					relation: String(this.dcollAdv.relation || ''),
					field: String(this.dcollAdv.field || ''),
					min_freq: this.dcollAdv.minFreq != null ? Number(this.dcollAdv.minFreq) : null,
					max_items: this.dcollAdv.maxItems != null ? Number(this.dcollAdv.maxItems) : null,
					stoplist: this.dcollAdv.stoplist != null ? Number(this.dcollAdv.stoplist) : null,
					measure_keys: Array.isArray(this.dcollAdv.measureKeys) ? this.dcollAdv.measureKeys.map((x) => String(x)) : [],
					viz_mode: String(this.dcollAdvVizMode || ''),
					chart_metric: String(this.dcollAdvChartMetricKey || ''),
				} : null,
				contrast: this.afContrastVizMode !== undefined ? {
					fields: Array.isArray(this.afKeynessFields) ? this.afKeynessFields.map((x) => String(x)) : [],
					score_field: String(this.afKeynessScoreField || ''),
					viz_mode: String(this.afContrastVizMode || ''),
					metric: String(this.afContrastMetric || ''),
					volcano_limit: this.afContrastVolcanoPointLimit != null ? Number(this.afContrastVolcanoPointLimit) : null,
				} : null,
				other: {
					viz_mode: this.otherVizMode != null ? String(this.otherVizMode) : '',
					chart_x_column: this.otherChartXColumn != null ? String(this.otherChartXColumn) : '',
					chart_y_column: this.otherChartYColumn != null ? String(this.otherChartYColumn) : '',
				},
				maps: this.mapsVizMode == null ? null : {
					viz_mode: String(this.mapsVizMode || ''),
					map_mode: String(this.mapsMapMode || ''),
					chart_mode: String(this.mapsChartMode || ''),
					point_viz_mode: String(this.mapsPointVizMode || ''),
					region_metric: String(this.mapsRegionMetric || ''),
					color_mode: String(this.mapsRegionColorMode || ''),
					compare_scale: String(this.mapsCompareScaleMode || ''),
					dataset: String(this.mapsBoundaryDatasetKey || ''),
					area: this.mapsSelectedAreaKey && this.mapsSelectedAreaKey !== '__default__' ? String(this.mapsSelectedAreaKey) : '',
					agg_level: String(this.geoAggLevel || ''),
					custom_region_field: String(this.geoCustomRegionField || ''),
					point_limit: this.mapsPointLimit != null ? Number(this.mapsPointLimit) : null,
					table_search: String(this.mapsTableSearch || ''),
					table_sort: this.mapsTableSort && typeof this.mapsTableSort === 'object' ? this.mapsTableSort : null,
					view: typeof this.mapsCurrentView === 'function' && this.mapsVizMode === 'map' ? this.mapsCurrentView() : null,
				},
				documents: this.documentsUi && typeof this.documentsUi === 'object' ? {
					filter: String(this.documentsUi.filter || ''),
					per_page: this.documentsUi.perPage != null ? Number(this.documentsUi.perPage) : null,
					visible: this.documentsUi.visibleCount != null ? Number(this.documentsUi.visibleCount) : null,
				} : null,
			};
		},

		_compactPruneValue(value) {
			if (value == null) return null;
			if (Array.isArray(value)) {
				const arr = value
					.map((v) => this._compactPruneValue(v))
					.filter((v) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0));
				return arr.length ? arr : null;
			}
			if (typeof value === 'object') {
				const out = {};
				Object.keys(value).forEach((k) => {
					const v = this._compactPruneValue(value[k]);
					if (v === null || v === '') return;
					if (Array.isArray(v) && v.length === 0) return;
					if (typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0) return;
					out[k] = v;
				});
				return Object.keys(out).length ? out : null;
			}
			return value;
		},

		_compactVisualizationSnapshotPayload(fullPayload) {
			const p = fullPayload && typeof fullPayload === 'object' ? fullPayload : {};
			const compact = {
				s: 'fvs1',
				b: {
					b: p.settings && p.settings.backend,
					e: p.settings && p.settings.query_engine,
					l: p.settings && p.settings.query_language,
					f: p.settings && p.settings.corpus_format,
				},
				u: {
					t: p.ui && p.ui.active_tab,
					s: p.ui && p.ui.stats_subtab,
				},
				q: {
					q: p.search && p.search.query,
					f: p.search && p.search.field,
					v: p.search && p.search.value,
					cf: p.search && p.search.context_format,
					cs: p.search && p.search.context_scope,
					vm: p.search && p.search.view_mode,
					w: p.search && p.search.window,
					l: p.search && p.search.limit,
					n: p.search && p.search.loaded > (p.search.limit || 0) ? p.search.loaded : null,
				},
				dc: p.ui && p.ui.active_tab === 'documents' && p.documents ? {
					f: p.documents.filter, pp: p.documents.per_page, v: p.documents.visible,
				} : null,
				sc: {
					q: Array.isArray(p.scope && p.scope.queries)
						? p.scope.queries.map((r) => ({
							i: r && r.id,
							n: r && r.name,
							a: r && r.assignId,
							q: r && r.query,
							x: r && r.active === false ? 0 : 1,
						}))
						: [],
				},
				f: {
					f: p.frequency && p.frequency.field,
					ff: p.frequency && p.frequency.form_fields,
					l: p.frequency && p.frequency.limit,
					vm: p.frequency && p.frequency.viz_mode,
					ct: p.frequency && p.frequency.chart_type,
					cs: p.frequency && p.frequency.chart_scale,
					co: p.frequency && p.frequency.chart_order,
					ts: p.frequency && p.frequency.table_search,
					to: p.frequency && p.frequency.table_sort,
					m: p.frequency && p.frequency.compare_metric_cols,
				},
				c: {
					f: p.collocation && p.collocation.field,
					l: p.collocation && p.collocation.left,
					r: p.collocation && p.collocation.right,
					mf: p.collocation && p.collocation.min_freq,
					mi: p.collocation && p.collocation.max_items,
					sl: p.collocation && p.collocation.stoplist,
					mk: p.collocation && p.collocation.measure_keys,
					vm: p.collocation && p.collocation.viz_mode,
					cm: p.collocation && p.collocation.chart_metric,
				},
				// module states only when the link opens on that module
				d: p.ui && p.ui.stats_subtab === 'advanced_dcoll' && p.dcoll ? {
					a: p.dcoll.anchor_token, r: p.dcoll.relation, f: p.dcoll.field,
					mf: p.dcoll.min_freq, mi: p.dcoll.max_items, sl: p.dcoll.stoplist,
					mk: p.dcoll.measure_keys, vm: p.dcoll.viz_mode, cm: p.dcoll.chart_metric,
				} : null,
				k: p.ui && p.ui.stats_subtab === 'contrast' && p.contrast ? {
					f: p.contrast.fields, sf: p.contrast.score_field, vm: p.contrast.viz_mode,
					m: p.contrast.metric, vl: p.contrast.volcano_limit,
				} : null,
				o: {
					vm: p.other && p.other.viz_mode,
					x: p.other && p.other.chart_x_column,
					y: p.other && p.other.chart_y_column,
				},
				// maps state only when the link opens on Maps (keeps other links short)
				mp: p.ui && p.ui.stats_subtab === 'maps' && p.maps ? {
					vm: p.maps.viz_mode,
					mm: p.maps.map_mode,
					cm: p.maps.chart_mode,
					pv: p.maps.point_viz_mode,
					rm: p.maps.region_metric,
					co: p.maps.color_mode,
					cs: p.maps.compare_scale,
					ds: p.maps.dataset,
					ar: p.maps.area,
					al: p.maps.agg_level,
					cr: p.maps.custom_region_field,
					pl: p.maps.point_limit,
					ts: p.maps.table_search,
					to: p.maps.table_sort,
					v: p.maps.view,
				} : null,
			};
			return this._compactPruneValue(compact) || { s: 'fvs1' };
		},

		_expandVisualizationSnapshotPayload(payload) {
			const p = payload && typeof payload === 'object' ? payload : null;
			if (!p || p.s !== 'fvs1') return payload;
			const expanded = {
				schema: 'flexicorp-viz-snapshot',
				version: 1,
				created_at: new Date().toISOString(),
				settings: {
					backend: p.b && p.b.b ? String(p.b.b) : '',
					query_engine: p.b && p.b.e ? String(p.b.e) : '',
					query_language: p.b && p.b.l ? String(p.b.l) : '',
					corpus_format: p.b && p.b.f ? String(p.b.f) : '',
				},
				ui: {
					active_tab: p.u && p.u.t ? String(p.u.t) : '',
					stats_subtab: p.u && p.u.s ? String(p.u.s) : '',
				},
				search: {
					query: p.q && p.q.q ? String(p.q.q) : '',
					field: p.q && p.q.f ? String(p.q.f) : '',
					value: p.q && p.q.v ? String(p.q.v) : '',
					context_format: p.q && p.q.cf ? String(p.q.cf) : '',
					context_scope: p.q && p.q.cs ? String(p.q.cs) : '',
					view_mode: p.q && p.q.vm ? String(p.q.vm) : '',
					window: p.q && p.q.w != null ? Number(p.q.w) : null,
					limit: p.q && p.q.l != null ? Number(p.q.l) : null,
					loaded: p.q && p.q.n != null ? Number(p.q.n) : null,
				},
				documents: p.dc && typeof p.dc === 'object' ? {
					filter: p.dc.f ? String(p.dc.f) : '',
					per_page: p.dc.pp != null ? Number(p.dc.pp) : null,
					visible: p.dc.v != null ? Number(p.dc.v) : null,
				} : null,
				scope: {
					queries: Array.isArray(p.sc && p.sc.q)
						? p.sc.q.map((r, idx) => ({
							id: r && r.i ? String(r.i) : `q${idx + 1}`,
							name: r && r.n ? String(r.n) : '',
							assignId: r && r.a ? String(r.a) : '',
							query: r && r.q ? String(r.q) : '',
							active: !(r && Number(r.x) === 0),
						}))
						: [],
				},
				frequency: {
					field: p.f && p.f.f ? String(p.f.f) : '',
					form_fields: Array.isArray(p.f && p.f.ff) ? p.f.ff.map((x) => String(x)) : [],
					limit: p.f && p.f.l != null ? Number(p.f.l) : null,
					viz_mode: p.f && p.f.vm ? String(p.f.vm) : '',
					chart_type: p.f && p.f.ct ? String(p.f.ct) : '',
					chart_scale: p.f && p.f.cs ? String(p.f.cs) : '',
					chart_order: p.f && p.f.co ? String(p.f.co) : '',
					table_search: p.f && p.f.ts ? String(p.f.ts) : '',
					table_sort: p.f && p.f.to && typeof p.f.to === 'object' ? p.f.to : null,
					compare_metric_cols: Array.isArray(p.f && p.f.m) ? p.f.m.map((x) => String(x)) : [],
				},
				collocation: {
					field: p.c && p.c.f ? String(p.c.f) : '',
					left: p.c && p.c.l != null ? Number(p.c.l) : null,
					right: p.c && p.c.r != null ? Number(p.c.r) : null,
					min_freq: p.c && p.c.mf != null ? Number(p.c.mf) : null,
					max_items: p.c && p.c.mi != null ? Number(p.c.mi) : null,
					stoplist: p.c && p.c.sl != null ? Number(p.c.sl) : null,
					measure_keys: Array.isArray(p.c && p.c.mk) ? p.c.mk.map((x) => String(x)) : [],
					viz_mode: p.c && p.c.vm ? String(p.c.vm) : '',
					chart_metric: p.c && p.c.cm ? String(p.c.cm) : '',
				},
				dcoll: p.d && typeof p.d === 'object' ? {
					anchor_token: p.d.a ? String(p.d.a) : '',
					relation: p.d.r ? String(p.d.r) : '',
					field: p.d.f ? String(p.d.f) : '',
					min_freq: p.d.mf != null ? Number(p.d.mf) : null,
					max_items: p.d.mi != null ? Number(p.d.mi) : null,
					stoplist: p.d.sl != null ? Number(p.d.sl) : null,
					measure_keys: Array.isArray(p.d.mk) ? p.d.mk.map((x) => String(x)) : [],
					viz_mode: p.d.vm ? String(p.d.vm) : '',
					chart_metric: p.d.cm ? String(p.d.cm) : '',
				} : null,
				contrast: p.k && typeof p.k === 'object' ? {
					fields: Array.isArray(p.k.f) ? p.k.f.map((x) => String(x)) : [],
					score_field: p.k.sf ? String(p.k.sf) : '',
					viz_mode: p.k.vm ? String(p.k.vm) : '',
					metric: p.k.m ? String(p.k.m) : '',
					volcano_limit: p.k.vl != null ? Number(p.k.vl) : null,
				} : null,
				other: {
					viz_mode: p.o && p.o.vm ? String(p.o.vm) : '',
					chart_x_column: p.o && p.o.x ? String(p.o.x) : '',
					chart_y_column: p.o && p.o.y ? String(p.o.y) : '',
				},
				maps: p.mp && typeof p.mp === 'object' ? {
					viz_mode: p.mp.vm ? String(p.mp.vm) : '',
					map_mode: p.mp.mm ? String(p.mp.mm) : '',
					chart_mode: p.mp.cm ? String(p.mp.cm) : '',
					point_viz_mode: p.mp.pv ? String(p.mp.pv) : '',
					region_metric: p.mp.rm ? String(p.mp.rm) : '',
					color_mode: p.mp.co ? String(p.mp.co) : '',
					compare_scale: p.mp.cs ? String(p.mp.cs) : '',
					dataset: p.mp.ds ? String(p.mp.ds) : '',
					area: p.mp.ar ? String(p.mp.ar) : '',
					agg_level: p.mp.al ? String(p.mp.al) : '',
					custom_region_field: p.mp.cr ? String(p.mp.cr) : '',
					point_limit: p.mp.pl != null ? Number(p.mp.pl) : null,
					table_search: p.mp.ts ? String(p.mp.ts) : '',
					table_sort: p.mp.to && typeof p.mp.to === 'object' ? p.mp.to : null,
					view: p.mp.v && typeof p.mp.v === 'object' ? p.mp.v : null,
				} : null,
			};
			return expanded;
		},

		applyVisualizationSnapshotPayload(payload) {
			const p = payload && typeof payload === 'object' ? payload : null;
			if (!p) return false;
			const s = p.settings && typeof p.settings === 'object' ? p.settings : {};
			if (this.settings && typeof this.settings === 'object') {
				if (s.backend) this.settings.backend = String(s.backend);
				if (s.query_engine) this.settings.queryEngine = String(s.query_engine);
				if (s.query_language) this.settings.queryLanguage = String(s.query_language);
				if (s.corpus_format) this.settings.corpusFormat = String(s.corpus_format);
			}
			const ui = p.ui && typeof p.ui === 'object' ? p.ui : {};
			if (ui.active_tab) this.activeTab = String(ui.active_tab);
			if (ui.stats_subtab) this.statsSubTab = String(ui.stats_subtab);
			// what the link points at, before ensureStatsSubTabAllowed() sends a Stats subtab
			// back to Corpus stats for lack of hits (the restore reopens it once they are in)
			this._snapshotWantedTab = ui.active_tab ? String(ui.active_tab) : '';
			this._snapshotWantedSub = ui.stats_subtab ? String(ui.stats_subtab) : '';
			const search = p.search && typeof p.search === 'object' ? p.search : {};
			if (this.search && typeof this.search === 'object') {
				if (search.query != null) this.search.query = String(search.query);
				if (search.field != null) this.search.field = String(search.field);
				if (search.value != null) this.search.value = String(search.value);
				if (search.context_format != null) this.search.contextFormat = String(search.context_format);
				if (search.context_scope != null) this.search.contextScope = String(search.context_scope);
				if (search.view_mode != null) this.search.viewMode = String(search.view_mode);
				if (search.window != null && Number.isFinite(Number(search.window))) this.search.window = Number(search.window);
				if (search.limit != null && Number.isFinite(Number(search.limit))) this.search.limit = Number(search.limit);
				this._snapshotWantedLoaded = search.loaded != null && Number.isFinite(Number(search.loaded)) ? Number(search.loaded) : 0;
			}
			const docs = p.documents && typeof p.documents === 'object' ? p.documents : null;
			if (docs && this.documentsUi && typeof this.documentsUi === 'object') {
				if (docs.filter) this.documentsUi.filter = String(docs.filter);
				if (docs.per_page != null && Number.isFinite(docs.per_page)) this.documentsUi.perPage = docs.per_page;
				if (docs.visible != null && Number.isFinite(docs.visible)) this.documentsUi.visibleCount = docs.visible;
			}
			const scope = p.scope && typeof p.scope === 'object' ? p.scope : {};
			if (
				scope &&
				Array.isArray(scope.queries) &&
				this.statsSearchScopeStore &&
				Array.isArray(this.statsSearchScopeStore.queries)
			) {
				this.statsSearchScopeStore.queries = scope.queries.map((q, idx) => ({
					id: q && q.id ? String(q.id) : `q${idx + 1}`,
					name: q && q.name ? String(q.name) : '',
					assignId: q && q.assignId ? String(q.assignId) : '',
					query: q && q.query ? String(q.query) : '',
					active: !(q && q.active === false),
				}));
			}
			const freq = p.frequency && typeof p.frequency === 'object' ? p.frequency : {};
			if (this.frequency && typeof this.frequency === 'object') {
				if (freq.field != null) this.frequency.field = String(freq.field);
				if (Array.isArray(freq.form_fields)) this.frequency.formFields = freq.form_fields.map((x) => String(x));
				if (freq.limit != null && Number.isFinite(Number(freq.limit))) this.frequency.limit = Number(freq.limit);
			}
			if (freq.viz_mode != null && this.frequencyVizMode != null) this.frequencyVizMode = String(freq.viz_mode);
			if (freq.chart_type != null && this.frequencyChartType != null) this.frequencyChartType = String(freq.chart_type);
			if (freq.chart_scale != null && this.frequencyChartValueScale != null) {
				this.frequencyChartValueScale = String(freq.chart_scale);
				this._frequencyScaleChosen = true;
			}
			if (freq.chart_order != null && this.frequencyChartOrder != null) this.frequencyChartOrder = String(freq.chart_order);
			if (freq.table_search != null && this.frequencyTableSearch != null) this.frequencyTableSearch = String(freq.table_search);
			if (freq.table_sort && typeof freq.table_sort === 'object' && this.frequencyTableSort && typeof this.frequencyTableSort === 'object') {
				this.frequencyTableSort = {
					col: typeof freq.table_sort.col === 'string' ? freq.table_sort.col : '',
					asc: !!freq.table_sort.asc,
				};
			}
			if (Array.isArray(freq.compare_metric_cols) && Array.isArray(this.frequencyCompareMetricCols)) {
				this.frequencyCompareMetricCols = freq.compare_metric_cols.map((x) => String(x));
			}
			const coll = p.collocation && typeof p.collocation === 'object' ? p.collocation : {};
			if (this.collocation && typeof this.collocation === 'object') {
				if (coll.field != null) this.collocation.field = String(coll.field);
				if (coll.anchor_token != null) this.collocation.anchorToken = String(coll.anchor_token);
				if (coll.left != null && Number.isFinite(Number(coll.left))) this.collocation.left = Number(coll.left);
				if (coll.right != null && Number.isFinite(Number(coll.right))) this.collocation.right = Number(coll.right);
				if (coll.min_freq != null && Number.isFinite(Number(coll.min_freq))) this.collocation.minFreq = Number(coll.min_freq);
				if (coll.max_items != null && Number.isFinite(Number(coll.max_items))) this.collocation.maxItems = Number(coll.max_items);
				if (coll.stoplist != null && Number.isFinite(Number(coll.stoplist))) this.collocation.stoplist = Number(coll.stoplist);
				if (Array.isArray(coll.measure_keys)) this.collocation.measureKeys = coll.measure_keys.map((x) => String(x));
			}
			if (coll.viz_mode != null && this.collocationVizMode != null) this.collocationVizMode = String(coll.viz_mode);
			if (coll.chart_metric && this.collocationChartMetricKey !== undefined) this.collocationChartMetricKey = String(coll.chart_metric);
			const dcoll = p.dcoll && typeof p.dcoll === 'object' ? p.dcoll : null;
			if (dcoll && this.dcollAdv && typeof this.dcollAdv === 'object') {
				if (dcoll.anchor_token) this.dcollAdv.anchorToken = String(dcoll.anchor_token);
				if (dcoll.relation) this.dcollAdv.relation = String(dcoll.relation);
				if (dcoll.field) this.dcollAdv.field = String(dcoll.field);
				if (dcoll.min_freq != null && Number.isFinite(Number(dcoll.min_freq))) this.dcollAdv.minFreq = Number(dcoll.min_freq);
				if (dcoll.max_items != null && Number.isFinite(Number(dcoll.max_items))) this.dcollAdv.maxItems = Number(dcoll.max_items);
				if (dcoll.stoplist != null && Number.isFinite(Number(dcoll.stoplist))) this.dcollAdv.stoplist = Number(dcoll.stoplist);
				if (Array.isArray(dcoll.measure_keys) && dcoll.measure_keys.length) this.dcollAdv.measureKeys = dcoll.measure_keys.map((x) => String(x));
				if (dcoll.viz_mode) this.dcollAdvVizMode = String(dcoll.viz_mode);
				if (dcoll.chart_metric) this.dcollAdvChartMetricKey = String(dcoll.chart_metric);
			}
			const contrast = p.contrast && typeof p.contrast === 'object' ? p.contrast : null;
			if (contrast && this.afContrastVizMode !== undefined) {
				if (Array.isArray(contrast.fields) && contrast.fields.length) {
					this.afKeynessFields = contrast.fields.map((x) => String(x));
					this.afKeynessField = this.afKeynessFields[0];
				}
				if (contrast.score_field) this.afKeynessScoreField = String(contrast.score_field);
				if (contrast.viz_mode) this.afContrastVizMode = String(contrast.viz_mode);
				if (contrast.metric) this.afContrastMetric = String(contrast.metric);
				if (contrast.volcano_limit != null && Number.isFinite(Number(contrast.volcano_limit))) this.afContrastVolcanoPointLimit = Number(contrast.volcano_limit);
			}
			const other = p.other && typeof p.other === 'object' ? p.other : {};
			if (other.viz_mode != null && this.otherVizMode != null) this.otherVizMode = String(other.viz_mode);
			if (other.chart_x_column != null && this.otherChartXColumn != null) this.otherChartXColumn = String(other.chart_x_column);
			if (other.chart_y_column != null && this.otherChartYColumn != null) this.otherChartYColumn = String(other.chart_y_column);
			const maps = p.maps && typeof p.maps === 'object' ? p.maps : null;
			if (maps && this.mapsVizMode != null) {
				if (maps.viz_mode) this.mapsVizMode = String(maps.viz_mode);
				if (maps.map_mode) this.mapsMapMode = maps.map_mode === 'regions' ? 'regions' : 'points';
				if (maps.chart_mode) this.mapsChartMode = String(maps.chart_mode);
				if (maps.point_viz_mode) this.mapsPointVizMode = String(maps.point_viz_mode);
				if (maps.region_metric) this.mapsRegionMetric = String(maps.region_metric);
				if (maps.color_mode) this.mapsRegionColorMode = String(maps.color_mode);
				if (maps.compare_scale) this.mapsCompareScaleMode = String(maps.compare_scale);
				if (maps.dataset) this.mapsBoundaryDatasetKey = String(maps.dataset);
				if (maps.area) this.mapsSelectedAreaKey = String(maps.area);
				if (maps.agg_level) this.geoAggLevel = String(maps.agg_level);
				if (maps.custom_region_field) this.geoCustomRegionField = String(maps.custom_region_field);
				if (maps.point_limit != null && Number.isFinite(Number(maps.point_limit))) this.mapsPointLimit = Number(maps.point_limit);
				if (maps.table_search) this.mapsTableSearch = String(maps.table_search);
				if (maps.table_sort && typeof maps.table_sort === 'object') {
					this.mapsTableSort = { col: String(maps.table_sort.col || 'count'), asc: !!maps.table_sort.asc };
				}
				if (maps.view && Array.isArray(maps.view.center)) this.mapsPendingView = { center: maps.view.center.map(Number), zoom: Number(maps.view.zoom) };
			}
			this.ensureStatsSubTabAllowed();
			// eslint-disable-next-line no-console
			console.log('[flexicorp][viz] applied snapshot state', {
				backend: this.settings && this.settings.backend,
				queryEngine: this.settings && this.settings.queryEngine,
				queryLanguage: this.settings && this.settings.queryLanguage,
				corpusFormat: this.settings && this.settings.corpusFormat,
				activeTab: this.activeTab,
				statsSubTab: this.statsSubTab,
				searchQuery: this.search && this.search.query,
				scopeQueries: this.statsSearchScopeStore && Array.isArray(this.statsSearchScopeStore.queries)
					? this.statsSearchScopeStore.queries
					: [],
				frequency: {
					field: this.frequency && this.frequency.field,
					formFields: this.frequency && this.frequency.formFields,
					vizMode: this.frequencyVizMode,
					chartScale: this.frequencyChartValueScale,
					chartOrder: this.frequencyChartOrder,
					compareMetricCols: this.frequencyCompareMetricCols,
				},
			});
			return true;
		},

		buildVisualizationSnapshotUrl() {
			const payload = this.buildVisualizationSnapshotPayload();
			const compactPayload = this._compactVisualizationSnapshotPayload(payload);
			const encoded = this._base64UrlEncodeUnicode(JSON.stringify(payload));
			const compactEncoded = this._base64UrlEncodeUnicode(JSON.stringify(compactPayload));
			const chosen = compactEncoded && compactEncoded.length < encoded.length ? compactEncoded : encoded;
			if (!encoded) return '';
			const cur = new URL(window.location.href);
			const u = new URL(cur.origin + cur.pathname);
			const actionParam = (cur.searchParams.get('action') || '').trim();
			u.searchParams.set('action', actionParam || 'flexicorp');
			u.searchParams.set('viz', chosen);
			// eslint-disable-next-line no-console
			console.log('[flexicorp][viz] snapshot sizes', {
				full: encoded.length,
				compact: compactEncoded ? compactEncoded.length : 0,
				chosen: chosen.length,
			});
			return u.toString();
		},

		shareVisualizationSnapshot() {
			const url = this.buildVisualizationSnapshotUrl();
			if (!url) {
				window.alert('Could not build visualization URL.');
				return;
			}
			this.visualizationShareUrl = url;
			this.visualizationShareStatus = '';
			// one popup everywhere (Stats and Search): the URL to copy, plus Share… / Email
			this.visualizationShareOpen = true;
		},

		closeVisualizationShare() {
			this.visualizationShareOpen = false;
			this.visualizationShareStatus = '';
		},

		canNativeShareVisualization() {
			return !!(navigator && typeof navigator.share === 'function');
		},

		async copyVisualizationShareUrl() {
			const url = this.visualizationShareUrl || this.buildVisualizationSnapshotUrl();
			if (!url) {
				this.visualizationShareStatus = 'Could not build visualization URL.';
				return;
			}
			this.visualizationShareUrl = url;
			if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
				try {
					await navigator.clipboard.writeText(url);
					this.visualizationShareStatus = 'Link copied.';
					return;
				} catch (_) {
					/* fallback below */
				}
			}
			window.prompt('Copy visualization URL', url);
			this.visualizationShareStatus = 'Copy dialog opened.';
		},

		async nativeShareVisualization() {
			const url = this.visualizationShareUrl || this.buildVisualizationSnapshotUrl();
			if (!url) {
				this.visualizationShareStatus = 'Could not build visualization URL.';
				return;
			}
			this.visualizationShareUrl = url;
			if (!this.canNativeShareVisualization()) {
				this.visualizationShareStatus = 'Native share is not available in this browser.';
				return;
			}
			try {
				await navigator.share({
					title: 'flexicorp visualization',
					text: 'Shared flexicorp visualization',
					url,
				});
				this.visualizationShareStatus = 'Shared.';
			} catch (_) {
				// User-cancel is fine; keep silent-ish.
				this.visualizationShareStatus = 'Share canceled.';
			}
		},

		emailVisualizationShare() {
			const url = this.visualizationShareUrl || this.buildVisualizationSnapshotUrl();
			if (!url) {
				this.visualizationShareStatus = 'Could not build visualization URL.';
				return;
			}
			this.visualizationShareUrl = url;
			const subject = encodeURIComponent('flexicorp visualization');
			const body = encodeURIComponent(url);
			window.location.href = `mailto:?subject=${subject}&body=${body}`;
		},

		searchViewModeShareEligible() {
			const mode = this.normalizeSearchViewMode(this.search && this.search.viewMode);
			return mode === 'kwic' || mode === 'grouped' || mode === 'anchor_kwic' || mode === 'table';
		},

		canShareSearchSnapshot() {
			if (this.activeTab !== 'search') return false;
			if (!this.search || !this.search.ran) return false;
			return this.searchViewModeShareEligible();
		},

		/** @deprecated Share lives in the Search result title row (template); kept as no-ops for init callers. */
		ensureSearchShareButton() {},
		updateSearchShareButtonVisibility() {},

		loadVisualizationSnapshotFromPrompt() {
			const raw = window.prompt('Paste visualization URL (or viz token)');
			if (!raw) return;
			const token = this.extractVisualizationTokenFromText(raw);
			if (!token) {
				window.alert('No viz token found.');
				return;
			}
			const decoded = this._base64UrlDecodeUnicode(token);
			if (!decoded) {
				window.alert('Could not decode viz token.');
				return;
			}
			let payload = null;
			try {
				payload = JSON.parse(decoded);
			} catch (_) {
				payload = null;
			}
			payload = this._expandVisualizationSnapshotPayload(payload);
			// eslint-disable-next-line no-console
			console.log('[flexicorp][viz] decoded snapshot payload (prompt)', payload);
			if (!payload || !this.applyVisualizationSnapshotPayload(payload)) {
				window.alert('Could not apply visualization snapshot.');
				return;
			}
			this.runVisualizationSnapshotQuery();
			window.alert('Visualization snapshot loaded. Query execution started.');
		},

		extractVisualizationTokenFromText(raw) {
			const s = String(raw || '').trim();
			if (!s) return '';
			try {
				const u = new URL(s);
				const q = u.searchParams.get('viz');
				if (q && q.trim()) return q.trim();
				if (u.hash && u.hash.startsWith('#viz=')) return u.hash.slice(5).trim();
			} catch (_) {
				/* not a URL, continue */
			}
			if (s.startsWith('viz=')) return s.slice(4).trim();
			return s;
		},

		loadVisualizationSnapshotFromLocation() {
			try {
				const u = new URL(window.location.href);
				let token = (u.searchParams.get('viz') || '').trim();
				if (!token && u.hash && u.hash.startsWith('#viz=')) {
					token = u.hash.slice(5).trim();
				}
				if (!token) return false;
				const decoded = this._base64UrlDecodeUnicode(token);
				if (!decoded) return false;
				let payload = JSON.parse(decoded);
				payload = this._expandVisualizationSnapshotPayload(payload);
				// eslint-disable-next-line no-console
				console.log('[flexicorp][viz] decoded snapshot payload (url)', payload);
				const ok = this.applyVisualizationSnapshotPayload(payload);
				if (ok) this.runVisualizationSnapshotQuery();
				return ok;
			} catch (_) {
				return false;
			}
		},

		runVisualizationSnapshotQuery() {
			const q = this.search && typeof this.search.query === 'string' ? this.search.query.trim() : '';
			const v = this.search && typeof this.search.value === 'string' ? this.search.value.trim() : '';
			if (!q && !v) return false;
			// The search answer switches to the Search tab; a link to Stats (Maps, Frequency, …)
			// goes back there once the hits are in, since Stats subtabs need hits.
			const wantedTab = String(this._snapshotWantedTab || this.activeTab || '').trim();
			const wantedSub = String(this._snapshotWantedSub || this.statsSubTab || '').trim();
			this._snapshotWantedTab = '';
			this._snapshotWantedSub = '';
			// eslint-disable-next-line no-console
			const vlog = (...args) => console.log('[flexicorp][viz]', ...args);
			const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
			const loadMore = async () => {
				// as many hits as were on screen ("Show more" pages), at most 20 extra requests
				const want = Number(this._snapshotWantedLoaded || 0);
				this._snapshotWantedLoaded = 0;
				for (let i = 0; i < 20 && want > this.searchLoadedItemCount(); i += 1) {
					const before = this.searchLoadedItemCount();
					await this.submitSearchRequest({ append: true });
					if (this.searchLoadedItemCount() <= before) break;
				}
			};
			// Any search in flight (also one the page started itself) must have landed first. An
			// aggregation program (`A = …; freq A by …`) lands with a table and no hits: done as well.
			const waitForSearch = async () => {
				for (let i = 0; i < 120; i += 1) {
					if (!this.isLoading('search') && this.search && this.search.ran) break;
					await sleep(250);
				}
				return typeof this.statsSearchHasHits === 'function' && this.statsSearchHasHits();
			};
			const reopen = async () => {
				if (!wantedTab || wantedTab === 'search') return;
				// subtab first, so the Stats tab does not open (and load) Corpus stats
				if (wantedTab === 'frequency' && wantedSub) this.statsSubTab = wantedSub;
				if (typeof this.setTab === 'function') this.setTab(wantedTab);
				if (wantedTab !== 'frequency' || !wantedSub) return;
				for (let i = 0; i < 3 && this.statsSubTab !== wantedSub; i += 1) {
					if (typeof this.setStatsSubTab === 'function') this.setStatsSubTab(wantedSub);
					if (this.statsSubTab !== wantedSub) await sleep(300);
				}
				if (typeof this.setStatsSubTab === 'function') this.setStatsSubTab(wantedSub);   // also schedules the subtab's loaders
				vlog('reopen: tab', this.activeTab, 'subtab', this.statsSubTab, '(wanted', wantedTab, wantedSub + ')');
				if (this.statsSubTab !== wantedSub) {
					// eslint-disable-next-line no-console
					console.warn('[flexicorp][viz] could not reopen Stats subtab', wantedSub, 'now on', this.statsSubTab,
						'hits:', typeof this.statsSearchHasHits === 'function' ? this.statsSearchHasHits() : '?',
						'modules:', typeof this.availableStatsModules === 'function' ? this.availableStatsModules().map((m) => m.id) : '?');
					return;
				}
				// the analysis the link was made from, with the restored settings; not when the search
				// answer already is that result (a typed program): rerunning would replace it
				if (typeof this.statsHasResultFor === 'function' && this.statsHasResultFor(wantedSub)) {
					vlog('reopen: the search answer is the', wantedSub, 'result; not rerunning it');
					return;
				}
				if (wantedSub === 'freq' && typeof this.submitFrequencyFromButton === 'function') this.submitFrequencyFromButton();
				else if (wantedSub === 'coll' && typeof this.submitCollocationFromButton === 'function') this.submitCollocationFromButton();
				else if (wantedSub === 'advanced_dcoll' && typeof this.submitDcollAdvRun === 'function') this.submitDcollAdvRun();
				else if (wantedSub === 'contrast' && typeof this.submitAfKeynessRun === 'function' && !(typeof this.afKeynessRunDisabled === 'function' && this.afKeynessRunDisabled())) this.submitAfKeynessRun();
				if (wantedSub === 'maps' && typeof this.setMapsVizMode === 'function') {
					const mapMode = this.mapsMapMode;
					this.setMapsVizMode(this.mapsVizMode);
					if (this.mapsVizMode === 'map' && typeof this.setMapsMapMode === 'function') this.setMapsMapMode(mapMode);
				}
			};
			setTimeout(async () => {
				try {
					if (this.isLoading('search')) {
						vlog('restore: a search is already running; waiting for it');
					} else {
						vlog('restore: running the search');
						await this.submitSearchRequest({ append: false });
					}
					const hits = await waitForSearch();
					vlog('restore: hits', hits, 'loaded', this.searchLoadedItemCount());
					if (hits) await loadMore();
					await reopen();
				} catch (err) {
					// eslint-disable-next-line no-console
					console.warn('[flexicorp][viz] restore failed', err);
				}
			}, 0);
			return true;
		},

		/** Parse a JSON blob from storage; never throws. */
		_parseFlexicorpSelectionSnapshot(raw) {
			if (!raw) return null;
			try {
				const s = JSON.parse(raw);
				return s && typeof s === 'object' ? s : null;
			} catch (_) {
				return null;
			}
		},

		/** Last known selection: sessionStorage, then localStorage (private mode / blocked session), then current URL. */
		readFlexicorpSelectionSnapshot() {
			const key = this._flexicorpSelectionStorageKey;
			try {
				const s = this._parseFlexicorpSelectionSnapshot(sessionStorage.getItem(key));
				if (s) return s;
			} catch (_) {}
			try {
				const s = this._parseFlexicorpSelectionSnapshot(localStorage.getItem(key));
				if (s) return s;
			} catch (_) {}
			return this.flexicorpSelectionSnapshotFromUrl();
		},

		flexicorpSelectionSnapshotFromUrl() {
			try {
				const url = new URL(window.location.href);
				const qe = (url.searchParams.get('query_engine') || '').trim();
				const be = (url.searchParams.get('backend') || '').trim();
				const ql = (url.searchParams.get('query_language') || '').trim();
				const cf = (url.searchParams.get('corpus_format') || '').trim();
				if (!qe && !be && !ql && !cf) return null;
				return {
					backend: be,
					queryEngine: qe,
					queryLanguage: ql,
					corpusFormat: cf,
				};
			} catch (_) {
				return null;
			}
		},

		/** Persists to PHP $_SESSION['flexicorp_teitok'] (works even when browser storage is blocked). */
		saveFlexicorpSelectionToServer() {
			try {
				if (!this.settings || typeof this.settings !== 'object') return;
				const signature = JSON.stringify({
					backend: this.settings.backend != null ? String(this.settings.backend) : '',
					queryEngine: this.settings.queryEngine != null ? String(this.settings.queryEngine) : '',
					queryLanguage: this.settings.queryLanguage != null ? String(this.settings.queryLanguage) : '',
					corpusFormat: this.settings.corpusFormat != null ? String(this.settings.corpusFormat) : '',
				});
				if (this._saveSelectionInFlight && this._saveSelectionInFlight === signature) return;
				if (this._lastSavedSelectionSignature && this._lastSavedSelectionSignature === signature) return;
				this._saveSelectionInFlight = signature;
				const fd = new FormData();
				fd.set('ajax', '1');
				fd.set('save_selection', '1');
				fd.set('action', this.action || 'flexicorp');
				fd.set('backend', this.settings.backend != null ? String(this.settings.backend) : '');
				fd.set('query_engine', this.settings.queryEngine != null ? String(this.settings.queryEngine) : '');
				fd.set('query_language', this.settings.queryLanguage != null ? String(this.settings.queryLanguage) : '');
				fd.set('corpus_format', this.settings.corpusFormat != null ? String(this.settings.corpusFormat) : '');
				const url = this.getRequestUrl();
				void fetch(url, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				})
					.then((resp) => {
						if (resp && resp.ok) this._lastSavedSelectionSignature = signature;
					})
					.catch(() => {})
					.finally(() => {
						if (this._saveSelectionInFlight === signature) this._saveSelectionInFlight = '';
					});
			} catch (_) {}
		},

		persistFlexicorpSelectionToSessionStorage() {
			try {
				if (!this.settings || typeof this.settings !== 'object') return;
				const key = this._flexicorpSelectionStorageKey;
				const json = JSON.stringify({
					backend: this.settings.backend,
					queryEngine: this.settings.queryEngine,
					queryLanguage: this.settings.queryLanguage,
					corpusFormat: this.settings.corpusFormat,
				});
				try {
					sessionStorage.setItem(key, json);
				} catch (_) {}
				try {
					localStorage.setItem(key, json);
				} catch (_) {}
				this.saveFlexicorpSelectionToServer();
			} catch (_) {}
		},

		applyStoredFlexicorpSelection() {
			try {
				const s = this.readFlexicorpSelectionSnapshot();
				if (!s) return;
				const snapBackend = s.backend ? String(s.backend) : '';
				let eng = s.queryEngine ? String(s.queryEngine) : '';
				if (!eng && (snapBackend === 'pando' || snapBackend === 'flexicorp-pando')) eng = 'pando';
				const be = this.settings && this.settings.backend ? String(this.settings.backend) : '';
				const srvEng = this.settings && this.settings.queryEngine ? String(this.settings.queryEngine) : '';
				// Stored Pando selection wins over a server-side CQP default.
				if (eng === 'pando' && (be === 'cqp' || be === 'flexi' || be === '')) {
					this.settings.backend = 'pando';
					this.settings.queryEngine = 'pando';
					this.settings.queryLanguage = s.queryLanguage ? String(s.queryLanguage) : 'pando-cql';
					this.settings.corpusFormat = s.corpusFormat ? String(s.corpusFormat) : 'pando';
					return;
				}
				// Prefer native manatee backend — do not route through deprecated flexi.
				if (eng === 'manatee' && (be === 'cqp' || be === 'flexi' || be === '')) {
					this.settings.backend = 'manatee';
					this.settings.queryEngine = 'manatee';
					this.settings.queryLanguage = s.queryLanguage ? String(s.queryLanguage) : 'manatee-cql';
					this.settings.corpusFormat = s.corpusFormat ? String(s.corpusFormat) : 'manatee';
					return;
				}
				// Stale flexi+pando snapshot → real pando backend.
				if ((be === 'flexi' || be === 'pando') && srvEng === 'cqp' && eng === 'pando') {
					this.settings.backend = 'pando';
					this.settings.queryEngine = 'pando';
					this.settings.queryLanguage = s.queryLanguage ? String(s.queryLanguage) : 'pando-cql';
					this.settings.corpusFormat = s.corpusFormat ? String(s.corpusFormat) : 'pando';
				}
				// Drop deprecated flexi sticky when the server already chose a primary engine.
				if (snapBackend === 'flexi' && (be === 'pando' || be === 'cqp' || be === 'manatee')) {
					return;
				}
			} catch (_) {}
		},

		/** Keep backend / engine / dialect in the URL so force-reload matches session (belt-and-suspenders). */
		syncFlexicorpSelectionToUrl() {
			try {
				const url = new URL(window.location.href);
				if (this.activeTab) url.searchParams.set('active_tab', this.activeTab);
				if (this.settings && this.settings.backend) url.searchParams.set('backend', this.settings.backend);
				if (this.settings && this.settings.queryLanguage) url.searchParams.set('query_language', this.settings.queryLanguage);
				if (this.settings && this.settings.corpusFormat) url.searchParams.set('corpus_format', this.settings.corpusFormat);
				if (
					this.settings &&
					this.settings.queryEngine &&
					(this.settings.backend === 'flexi' ||
						this.settings.backend === 'pando' ||
						this.settings.backend === 'flexicorp-pando')
				) {
					url.searchParams.set('query_engine', this.settings.queryEngine);
				}
				window.history.replaceState({}, '', url.toString());
			} catch (_) {}
		},
	};
};
