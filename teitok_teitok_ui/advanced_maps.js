/*
 * Advanced Maps module for advanced_freqs host.
 */
(function () {
	function ttMapsSharedFns() {
		return typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
			? window.ttFlexicorpFns
			: {};
	}

	function installAdvancedMaps(app, registerModule) {
		if (!app || typeof app !== 'object') return;
		if (typeof registerModule === 'function') {
			registerModule({
				id: 'maps',
				label: 'Maps',
				description: 'Geographical distribution views for corpora with geo-enabled metadata fields.',
				isAvailable: (ctx) => {
					// Primary: use core-provided flags (when present).
					if (ctx) {
						if (ctx.hasGeo) return true;
						if (ctx.capabilities && ctx.capabilities.hasGeo) return true;
					}

					// Module-owned fallback: read geo bootstrap config from Stats UI.
					// This is intentionally module-specific so core stays generic.
					try {
						if (typeof document === 'undefined') return false;
						const boot = document.getElementById('flexicorp-stats-maps-bootstrap');
						if (!boot) return false;
						const raw = String(boot.getAttribute('data-flexicorp-maps-geo-config') || '{}');
						const gm = JSON.parse(raw);
						const hints = gm && typeof gm === 'object' && gm.hints ? gm.hints : {};
						const coords = Array.isArray(hints.coordinateFields) ? hints.coordinateFields : [];
						const regions = Array.isArray(hints.regionFields) ? hints.regionFields : [];
						return coords.length > 0 || regions.length > 0;
					} catch (_) {
						return false;
					}
				},
			});
		}

		Object.assign(app, {
			geoMapConfig: {},
			geoAggLevel: 'country',
			geoCustomRegionField: '',
			mapsLoading: false,
			mapsError: '',
			mapsChartRows: [],
			mapsChartInstance: null,
			mapsFreqTotal: null,
			mapsFreqReturned: null,
			mapsResolvedFreqField: '',
			mapsVizMode: 'map',
			mapsChartMode: 'bar',
			mapsMapMode: 'points',
			mapsTableSort: { col: 'count', asc: false },
			mapsTableSearch: '',
			mapsPointRows: [],
			mapsPointField: '',
			mapsPointLimit: 5000,
			mapsPointVizMode: 'cluster',
			mapsRegionRuns: [],
			mapsActiveRegionRunId: '',
			mapsRegionMetric: 'count',
			mapsRegionColorMode: 'stepped',
			mapsCompareQueries: [],
			mapsCompareTotalsByQuery: {},
			mapsCompareScaleMode: 'relative',
			mapsRegionDonutLayer: null,
			mapsLeafletMap: null,
			mapsLeafletCanvasRenderer: null,
			mapsLeafletClusterLayer: null,
			mapsBoundaryLayer: null,
			mapsBoundaryCache: {},
			mapsBoundaryLoading: false,
			mapsBoundaryError: '',
			mapsBoundaryDatasetKey: 'spainProvinces',
			mapsLeafletRetryTimer: null,
			mapsPointAutoRequested: false,
			mapsRegionAutoRequested: false,
			mapsSelectedAreaKey: '__default__',
			// map centre/zoom from a share link: applied once the data is drawn (after the auto-fit)
			mapsPendingView: null,

			_mapsSearchScopeFns() {
				return (typeof window !== 'undefined' && window.ttFlexicorpFns && window.ttFlexicorpFns.searchScope)
					? window.ttFlexicorpFns.searchScope
					: null;
			},
			_mapsScopeStore() {
				return this.statsSearchScopeStore || this.afSearchScopeStore || null;
			},
			_mapsFullProgramText() {
				const ss = this._mapsSearchScopeFns();
				const store = this._mapsScopeStore();
				if (!ss || !store) return '';
				const scope = ss.compileQueriesToScopeProgram(store.queries, { activeOnly: false });
				return typeof store.mergeFullProgram === 'function' ? store.mergeFullProgram(scope) : scope;
			},
			_mapsScopeOnlyQuery() {
				const ss = this._mapsSearchScopeFns();
				if (!ss) return '';
				return ss.stripAggregationClausesFromQuery(this._mapsFullProgramText());
			},
			afSearchScopeMapsQuery() {
				const ss = this._mapsSearchScopeFns();
				const store = this._mapsScopeStore();
				if (!ss || !store) return String(this._mapsScopeOnlyQuery() || '').trim();
				const one = ss.firstActiveQueryStatement(store.queries);
				return one || this._mapsScopeOnlyQuery();
			},
			_mapsAjaxActionName() {
				const a = String(this.action || '').trim();
				if (a) return a;
				if (typeof this.afAjaxActionName === 'function') return this.afAjaxActionName();
				const root = typeof document !== 'undefined' ? document.getElementById('flexicorp-advanced-freqs-root') : null;
				const attr = root ? String(root.getAttribute('data-af-action') || '').trim() : '';
				return attr || 'flexicorp';
			},
			buildAfFreqFormData(freqField, freqLimit) {
				const fd = new FormData();
				fd.set('ajax', '1');
				fd.set('action', this._mapsAjaxActionName());
				fd.set('run', 'freq');
				fd.set('active_tab', 'frequency');
				const bo = this.backendOverrides && typeof this.backendOverrides === 'object' ? this.backendOverrides : {};
				fd.set('backend', String((this.settings && this.settings.backend) || this.backend || '').trim() || 'cqp');
				const qe = String((this.settings && this.settings.queryEngine) || this.queryEngine || '').trim();
				if (qe) fd.set('query_engine', qe);
				const ql = String((this.settings && this.settings.queryLanguage) || this.queryLanguage || '').trim();
				if (ql) fd.set('query_language', ql);
				const cf = String((this.settings && this.settings.corpusFormat) || this.corpusFormat || '').trim();
				if (cf) fd.set('corpus_format', cf);
				if (bo.blacklab_url) fd.set('blacklab_url', String(bo.blacklab_url));
				if (bo.blacklab_corpus) fd.set('blacklab_corpus', String(bo.blacklab_corpus));
				if (bo.blacklab_user) fd.set('blacklab_user', String(bo.blacklab_user));
				if (bo.blacklab_password) fd.set('blacklab_password', String(bo.blacklab_password));
				if (bo.blacklab_field) fd.set('blacklab_field', String(bo.blacklab_field));
				if (this.settings && this.settings.docsLimit != null) fd.set('docs_limit', String(this.settings.docsLimit));
				if (this.settings && this.settings.kwicLimit != null) fd.set('kwic_limit', String(this.settings.kwicLimit));
				if (this.settings && this.settings.kwicWindow != null) fd.set('window', String(this.settings.kwicWindow));
				fd.set('query', this._mapsScopeOnlyQuery());
				fd.set('freq_field', String(freqField || '').trim());
				fd.set('freq_limit', String(freqLimit != null ? freqLimit : 60));
				if (!fd.has('docs_limit')) fd.set('docs_limit', '20');
				if (!fd.has('docs_per_page')) fd.set('docs_per_page', '20');
				if (!fd.has('kwic_limit')) fd.set('kwic_limit', '25');
				fd.set('start', '0');
				return fd;
			},

			initMapsModule(root) {
				let gm = {};
				if (root) {
					try { gm = JSON.parse(String(root.getAttribute('data-af-geo-map') || '{}')); } catch (_) { gm = {}; }
				}
				if (!gm || typeof gm !== 'object' || !Object.keys(gm).length) {
					const boot = typeof document !== 'undefined' ? document.getElementById('flexicorp-stats-maps-bootstrap') : null;
					if (boot) {
						try {
							gm = JSON.parse(String(boot.getAttribute('data-flexicorp-maps-geo-config') || '{}'));
						} catch (_) {
							gm = {};
						}
					}
				}
				this.geoMapConfig = gm && typeof gm === 'object' ? gm : {};
				const defAgg = String(this.geoMapConfig.defaultAggregation || 'country');
				this.geoAggLevel = defAgg === 'subnational' ? 'subnational' : (defAgg === 'custom' ? 'custom' : 'country');
				this.mapsSelectedAreaKey = '__default__';
				const defs = this.mapsBoundaryDefinitions();
				if (defs.length) this.mapsBoundaryDatasetKey = defs[0].key;
			},
			mapsRuntimeSettings() {
				const s = this.geoMapConfig && this.geoMapConfig.settings ? this.geoMapConfig.settings : {};
				return s && typeof s === 'object' ? s : {};
			},
			mapsConfiguredAreas() {
				const s = this.mapsRuntimeSettings();
				const arr = s && Array.isArray(s.areas) ? s.areas : [];
				return arr.filter((x) => x && typeof x === 'object');
			},
			mapsBoundaryDefinitions() {
				const s = this.mapsRuntimeSettings();
				const configured = s && Array.isArray(s.boundaryDatasets) ? s.boundaryDatasets : [];
				const normalizeBoundaryDef = (d, i) => ({
					key: String(d.key || `dataset${i + 1}`).trim(),
					label: String(d.label || d.display || d.key || `Dataset ${i + 1}`).trim(),
					provider: String(d.provider || 'geoboundaries').trim().toLowerCase(),
					iso3: String(d.iso3 || d.iso || '').trim().toUpperCase(),
					adm: String(d.adm || 'ADM1').trim().toUpperCase(),
					geometry: String(d.geometry || '').trim().toLowerCase(),
					url: String(d.url || '').trim(),
					nameProperty: String(d.nameProperty || '').trim(),
					group: String(d.group || '').trim(),
					cqp: String(d.cqp || '').trim(),
					aliases: Array.isArray(d.aliases) ? d.aliases : [],
				});
				const levelToAdm = (levelRaw) => {
					const level = String(levelRaw || '').trim().toLowerCase();
					if (!level) return 'ADM1';
					if (/^adm[0-9]+$/i.test(level)) return level.toUpperCase();
					if (
						level === 'province' || level === 'provinces' || level === 'provincia' || level === 'provincias' ||
						level === 'county' || level === 'counties' || level === 'district' || level === 'districts' ||
						level === 'municipality' || level === 'municipalities'
					) return 'ADM2';
					if (
						level === 'region' || level === 'regions' || level === 'state' || level === 'states' ||
						level === 'department' || level === 'departments' || level === 'autonomia' || level === 'autonomias'
					) return 'ADM1';
					if (level === 'comarca' || level === 'comarcas') return 'ADM3';
					return 'ADM1';
				};
				const countryToIso3 = (countryRaw) => {
					const c = String(countryRaw || '').trim().toLowerCase();
					const map = { spain: 'ESP', france: 'FRA', portugal: 'PRT', italy: 'ITA', germany: 'DEU' };
					return map[c] || '';
				};
				const regions = s && Array.isArray(s.regions) ? s.regions : [];
				const regionDefs = regions
					.filter((r) => r && typeof r === 'object')
					.map((r, i) => ({
						key: String(r.key || `region${i + 1}`).trim(),
						label: String(r.label || r.key || '').trim() || `${String(r.country || '').trim()} ${String(r.level || '').trim()}`.trim() || `Region ${i + 1}`,
						provider: String(r.provider || 'geoboundaries').trim().toLowerCase(),
						iso3: String(r.iso3 || countryToIso3(r.country)).trim().toUpperCase(),
						adm: String(r.adm || levelToAdm(r.level)).trim().toUpperCase(),
						geometry: String(r.geometry || '').trim().toLowerCase(),
						url: String(r.url || '').trim(),
						nameProperty: String(r.nameProperty || '').trim(),
						group: String(r.group || '').trim(),
						cqp: String(r.cqp || '').trim(),
						aliases: Array.isArray(r.aliases) ? r.aliases : [],
					}))
					.filter((d) => d.key);
				const baseDefs = configured.length
					? configured.filter((d) => d && typeof d === 'object').map(normalizeBoundaryDef)
					: (regionDefs.length ? regionDefs : [{ key: 'spainProvinces', label: 'Spain provinces (GeoBoundaries ADM2)', provider: 'geoboundaries', iso3: 'ESP', adm: 'ADM2', geometry: 'simplified', url: '', nameProperty: '', group: 'province', cqp: 'text_province', aliases: [] }]);
				const grouped = [];
				const groupMembers = new Map();
				for (let i = 0; i < baseDefs.length; i += 1) {
					const d = baseDefs[i];
					const g = String(d.group || '').trim();
					if (!g) continue;
					if (!groupMembers.has(g)) groupMembers.set(g, []);
					groupMembers.get(g).push(d.key);
				}
				groupMembers.forEach((members, group) => {
					if (!members || members.length < 2) return;
					grouped.push({
						key: `group:${group}`,
						label: `${group.charAt(0).toUpperCase()}${group.slice(1)} (all configured)`,
						provider: 'group',
						group,
						members: members.slice(),
						iso3: '',
						adm: '',
						geometry: 'simplified',
						url: '',
						nameProperty: '',
						cqp: '',
						aliases: [],
					});
				});
				return baseDefs.concat(grouped).filter((d) => d.key);
			},
			mapsBoundaryAliasMap(def) {
				const aliasMap = new Map();
				const ingestPairs = (pairs) => {
					const list = Array.isArray(pairs) ? pairs : [];
					for (let i = 0; i < list.length; i += 1) {
						const p = list[i] && typeof list[i] === 'object' ? list[i] : null;
						if (!p) continue;
						const a = this.mapsNormalizedRegionName(p.key);
						const b = this.mapsNormalizedRegionName(p.syn);
						if (!a || !b) continue;
						aliasMap.set(a, b);
						aliasMap.set(b, b);
					}
				};
				const pushDefAliases = (oneDef) => {
					if (!oneDef || typeof oneDef !== 'object') return;
					ingestPairs(oneDef.aliases);
				};
				pushDefAliases(def);
				if (def && def.provider === 'group' && Array.isArray(def.members)) {
					for (let i = 0; i < def.members.length; i += 1) {
						const child = this.mapsBoundaryByKey(def.members[i]);
						pushDefAliases(child);
					}
				}
				return aliasMap;
			},
			mapsCanonicalRegionName(rawName, aliasMap) {
				const n = this.mapsNormalizedRegionName(rawName);
				if (!n) return '';
				if (aliasMap && aliasMap.has(n)) return String(aliasMap.get(n) || n);
				return n;
			},
			mapsBoundaryByKey(key) {
				const wanted = String(key || '').trim();
				return this.mapsBoundaryDefinitions().find((d) => d.key === wanted) || null;
			},
			mapsCurrentBoundaryDefinition() {
				const defs = this.mapsBoundaryDefinitions();
				const k = String(this.mapsBoundaryDatasetKey || '').trim();
				return defs.find((d) => d.key === k) || (defs.length ? defs[0] : null);
			},
			autoSelectBoundaryForField(fieldName) {
				const f = String(fieldName || '').trim();
				if (!f) return;
				const defs = this.mapsBoundaryDefinitions();
				const direct = defs.find((d) => String(d.cqp || '').trim() === f);
				if (direct) this.mapsBoundaryDatasetKey = direct.key;
			},
			setMapsBoundaryDatasetKey(key) {
				this.mapsBoundaryDatasetKey = String(key || '').trim();
				const field = this.resolvedGeoRegionField();
				const expectedRunId = field ? `region:${field}` : '';
				if (expectedRunId && this.mapsRegionRuns.some((r) => r.id === expectedRunId)) {
					this.mapsActiveRegionRunId = expectedRunId;
					this.applyCurrentRegionRunToOutputs();
				} else {
					this.mapsRegionAutoRequested = false;
					this.ensureMapsRegionData();
				}
				this.syncMapsViz();
			},
			parseLatLonPair(rawValue) {
				const s = String(rawValue || '').trim();
				const nums = s.match(/-?\d+(?:\.\d+)?/g);
				if (!nums || nums.length < 2) return null;
				const lat = Number(nums[0]); const lon = Number(nums[1]);
				if (!Number.isFinite(lat) || !Number.isFinite(lon) || lat < -90 || lat > 90 || lon < -180 || lon > 180) return null;
				return [lat, lon];
			},
			mapsConfiguredStartView() {
				const s = this.mapsRuntimeSettings();
				const p = this.parseLatLonPair(s.startpos || '');
				const z = Number(s.zoom);
				if (!p) return null;
				return { center: p, zoom: Number.isFinite(z) && z > 0 ? z : 6 };
			},
			mapsDefaultView() {
				const cfg = this.mapsConfiguredStartView();
				if (cfg) return cfg;
				return { center: [20, 0], zoom: 2 };
			},
			mapsConfiguredTileLayer() {
				const s = this.mapsRuntimeSettings();
				const tpl = String(s.osmlayer || '').trim();
				return tpl || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
			},
			setMapsAreaByKey(key) {
				const wanted = String(key || '').trim();
				if (wanted === '__default__') {
					this.mapsSelectedAreaKey = wanted;
					const defView = this.mapsDefaultView();
					if (this.mapsLeafletMap) this.mapsLeafletMap.setView(defView.center, defView.zoom, { animate: false });
					else this.syncMapsViz();
					return;
				}
				if (wanted === '__fit__') {
					this.mapsSelectedAreaKey = wanted;
					this.zoomToFitCurrentMapData();
					return;
				}
				this.mapsSelectedAreaKey = wanted;
				const area = this.mapsConfiguredAreas().find((a) => String(a.key || '').trim() === wanted);
				if (!area || !this.mapsLeafletMap) return;
				const p = this.parseLatLonPair(area.startpos || '');
				const z = Number(area.zoom);
				if (p) this.mapsLeafletMap.setView(p, Number.isFinite(z) && z > 0 ? z : this.mapsLeafletMap.getZoom(), { animate: false });
			},
			zoomToFitCurrentMapData() {
				if (!this.mapsLeafletMap) {
					this.syncMapsViz();
					return;
				}
				// Prefer currently plotted point markers.
				if (this.mapsLeafletClusterLayer && typeof this.mapsLeafletClusterLayer.getBounds === 'function') {
					try {
						const b = this.mapsLeafletClusterLayer.getBounds();
						if (b && typeof b.isValid === 'function' && b.isValid()) {
							this.mapsLeafletMap.fitBounds(b, { padding: [24, 24], maxZoom: 11, animate: false });
							return;
						}
					} catch (_) {}
				}
				// Fallback: raw point rows if cluster layer not ready.
				if (Array.isArray(this.mapsPointRows) && this.mapsPointRows.length && typeof window !== 'undefined' && window.L && typeof window.L.latLngBounds === 'function') {
					try {
						const pts = this.mapsPointRows.map((p) => [p.lat, p.lon]);
						const b = window.L.latLngBounds(pts);
						if (b && typeof b.isValid === 'function' && b.isValid()) {
							this.mapsLeafletMap.fitBounds(b, { padding: [24, 24], maxZoom: 11, animate: false });
							return;
						}
					} catch (_) {}
				}
				// Last fallback: configured/default view.
				const defView = this.mapsDefaultView();
				this.mapsLeafletMap.setView(defView.center, defView.zoom, { animate: false });
			},
			geoHintsObject() {
				const h = this.geoMapConfig && this.geoMapConfig.hints ? this.geoMapConfig.hints : {};
				return h && typeof h === 'object' ? h : {};
			},
			preferredGeoFieldFromCandidates(candidates) {
				const list = Array.isArray(candidates) ? candidates.map((x) => String(x || '').trim()).filter(Boolean) : [];
				if (!list.length) return '';
				const metaSuffix = /_(?:display|key|noshow|nosearch)$/i;
				const byBase = new Map();
				for (let i = 0; i < list.length; i += 1) {
					const f = list[i];
					const base = f.replace(metaSuffix, '');
					if (!byBase.has(base)) byBase.set(base, []);
					byBase.get(base).push(f);
				}
				const bases = Array.from(byBase.keys());
				for (let i = 0; i < bases.length; i += 1) {
					const base = bases[i];
					const group = byBase.get(base) || [];
					// Prefer the raw structural field, then any non-metadata variant.
					if (group.includes(base)) return base;
					const clean = group.find((f) => !metaSuffix.test(f));
					if (clean) return clean;
					// If we only got metadata flags (e.g. text_geo_nosearch/_display/_key),
					// resolve to the base s-attribute itself.
					if (base) return base;
				}
				return list[0];
			},
			resolvedGeoPointField() {
				const h = this.geoHintsObject();
				const coords = Array.isArray(h.coordinateFields) ? h.coordinateFields : [];
				return this.preferredGeoFieldFromCandidates(coords);
			},
			resolvedGeoRegionField() {
				const byDataset = this.mapsCurrentBoundaryDefinition();
				if (byDataset && String(byDataset.cqp || '').trim()) return String(byDataset.cqp || '').trim();
				const defs = this.mapsBoundaryDefinitions();
				const firstConfigured = defs.find((d) => String(d.cqp || '').trim());
				if (firstConfigured) return String(firstConfigured.cqp || '').trim();
				if (this.geoAggLevel === 'custom') return String(this.geoCustomRegionField || '').trim();
				const h = this.geoHintsObject();
				if (this.geoAggLevel === 'country') {
					const arr = Array.isArray(h.countryFields) ? h.countryFields : [];
					return arr.length ? String(arr[0]).trim() : '';
				}
				const sub = Array.isArray(h.subnationalFields) ? h.subnationalFields : [];
				if (sub.length) return String(sub[0]).trim();
				const reg = Array.isArray(h.regionFields) ? h.regionFields : [];
				return reg.length ? String(reg[0]).trim() : '';
			},
			geoHintSummary() {
				const coords = this.geoHintsObject().coordinateFields || [];
				const c0 = Array.isArray(coords) && coords.length ? coords[0] : '';
				const r = this.resolvedGeoRegionField();
				const parts = [];
				if (c0) parts.push(`Coordinates: ${c0} (lat/lon)`);
				if (r) parts.push(`Region column: ${r}`);
				if (this.geoAggLevel === 'custom' && !r) parts.push('Enter a structural attribute for regional aggregation.');
				return parts.join(' · ');
			},
			mapsRunAllowed() { return this.afSearchScopeMapsQuery() !== '' && this.resolvedGeoRegionField() !== ''; },
			mapsPointsRunAllowed() { return this.afSearchScopeMapsQuery() !== '' && this.resolvedGeoPointField() !== ''; },
			mapsHasCompareQueries() { return Array.isArray(this.mapsCompareQueries) && this.mapsCompareQueries.length > 1; },
			mapsCompareScaleOptions() {
				return [
					{ key: 'relative', label: 'Relative (size-normalized)' },
					{ key: 'raw', label: 'Raw (absolute counts)' },
				];
			},
			mapsEnsureCompareDefaults() {
				if (!this.mapsHasCompareQueries()) return;
				const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
				if (metric !== 'compare_balance') this.mapsRegionMetric = 'compare_balance';
			},
			mapsChartLibraryAvailable() { const Chart = typeof window !== 'undefined' ? window.Chart : null; return !!(Chart && typeof Chart === 'function'); },
			setMapsVizMode(mode) {
				const next = (String(mode || '').trim().toLowerCase() === 'map' || String(mode || '').trim().toLowerCase() === 'chart') ? String(mode || '').trim().toLowerCase() : 'table';
				this.mapsVizMode = next;
				if (next === 'map' && this.mapsMapMode === 'points') this.ensureMapsPointsData();
				if (next === 'map') this.ensureMapsRegionData();
				this.syncMapsViz();
			},
			setMapsChartMode(mode) {
				const allowed = new Set(['bar', 'vbar', 'line', 'pie', 'doughnut', 'polar']);
				const m = String(mode || '').trim().toLowerCase();
				if (!allowed.has(m)) return;
				this.mapsChartMode = m;
				if (this.mapsVizMode === 'chart') this.syncMapsViz();
			},
			setMapsMapMode(mode) {
				const m = String(mode || '').trim().toLowerCase();
				this.mapsMapMode = m === 'regions' ? 'regions' : 'points';
				if (this.mapsMapMode === 'points') this.ensureMapsPointsData();
				else {
					this.ensureMapsRegionData();
					if (!this.mapsActiveRegionRunId && this.mapsRegionRuns.length) { this.mapsActiveRegionRunId = this.mapsRegionRuns[0].id; this.applyCurrentRegionRunToOutputs(); }
				}
				this.syncMapsViz();
			},
			setMapsPointVizMode(mode) {
				const m = String(mode || '').trim().toLowerCase();
				this.mapsPointVizMode = m === 'intensity' ? 'intensity' : 'cluster';
				this.syncMapsViz();
			},
			selectRegionRun(id) {
				const wanted = String(id || '').trim();
				if (!wanted || !this.mapsRegionRuns.some((r) => r.id === wanted)) return;
				this.mapsActiveRegionRunId = wanted;
				this.applyCurrentRegionRunToOutputs();
				this.mapsMapMode = 'regions';
				this.syncMapsViz();
			},
			currentRegionRun() {
				if (!this.mapsRegionRuns.length) return null;
				const wanted = String(this.mapsActiveRegionRunId || '').trim();
				return this.mapsRegionRuns.find((r) => r.id === wanted) || this.mapsRegionRuns[0];
			},
			regionRunButtonLabel(fieldName) {
				const f = String(fieldName || '').trim();
				if (!f) return 'Region';
				const base = f.replace(/^.*_/, '');
				const clean = base.replace(/[_-]+/g, ' ').trim();
				return clean ? clean.charAt(0).toUpperCase() + clean.slice(1) : f;
			},
			regionMetricOptions() {
				const rows = this.mapsCurrentTableRows();
				const hasIpm = rows.some((r) => Number.isFinite(Number(r && r.ipm)));
				const hasRfPct = rows.some((r) => Number.isFinite(Number(r && (r.rfPct ?? r.rf_pct))));
				const hasUnexpectedness = rows.some((r) => Number.isFinite(Number(r && r.unexpectedness)));
				const hasCompare = this.mapsHasCompareQueries();
				if (hasCompare) {
					return [{ key: 'compare_balance', label: 'Compare balance (A↔B)' }];
				}
				const opts = [{ key: 'count', label: 'Raw count' }, { key: 'pct', label: '% of total' }];
				if (hasIpm) opts.push({ key: 'ipm', label: 'IPM' });
				if (hasRfPct) opts.push({ key: 'rf_pct', label: 'Relative frequency (%)' });
				if (hasUnexpectedness) opts.push({ key: 'unexpectedness', label: 'Unexpectedness' });
				return opts;
			},
			regionMetricDisplayLabel(key) {
				const k = String(key || '').trim().toLowerCase();
				if (k === 'pct') return '% of total';
				if (k === 'ipm') return 'IPM';
				if (k === 'rf_pct') return 'Relative frequency (%)';
				if (k === 'unexpectedness') return 'Unexpectedness';
				if (k === 'compare_balance') return 'Compare balance (A↔B)';
				return 'Occurrences';
			},
			mapsRegionMetricUnit() {
				const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
				if (metric === 'pct' || metric === 'rf_pct' || metric === 'compare_balance') return '%';
				return '';
			},
			mapsFormatMetricValue(v) {
				const n = Number(v);
				if (!Number.isFinite(n)) return '0';
				const abs = Math.abs(n);
				let digits = 3;
				if (abs >= 100) digits = 1;
				else if (abs >= 10) digits = 2;
				return n.toLocaleString(undefined, { maximumFractionDigits: digits });
			},
			mapsRegionLegendRange() {
				const run = this.currentRegionRun();
				if (!run || !Array.isArray(run.rows) || !run.rows.length) return null;
				const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
				let min = Number.POSITIVE_INFINITY;
				let max = Number.NEGATIVE_INFINITY;
				for (let i = 0; i < run.rows.length; i += 1) {
					const val = Number(this.regionMetricValue(run.rows[i]));
					if (!Number.isFinite(val)) continue;
					if (val < min) min = val;
					if (val > max) max = val;
				}
				if (!Number.isFinite(min) || !Number.isFinite(max)) return null;
				if (metric === 'compare_balance') {
					// Diverging metric: keep legend symmetric around zero so A/B shifts are readable.
					const absMax = Math.max(Math.abs(min), Math.abs(max), 1);
					return { min: -absMax, max: absMax };
				}
				return { min, max };
			},
			mapsRegionLegendSummary() {
				const range = this.mapsRegionLegendRange();
				if (!range) return '';
				const label = this.regionMetricDisplayLabel(this.mapsRegionMetric);
				const unit = this.mapsRegionMetricUnit();
				const left = this.mapsFormatMetricValue(range.min);
				const right = this.mapsFormatMetricValue(range.max);
				const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
				if (metric === 'compare_balance') {
					const names = this.mapsHasCompareQueries() ? this.mapsCompareQueries.slice() : [];
					const leftName = names.length > 1 ? names[1] : 'B';
					const rightName = names.length > 0 ? names[0] : 'A';
					return `${label}: ${leftName} ${left}${unit ? unit : ''} ↔ ${right}${unit ? unit : ''} ${rightName}`;
				}
				return `${label}: (blue) ${left}${unit ? unit : ''} - ${right}${unit ? unit : ''} (red)`;
			},
			mapsRegionLegendColors() {
				const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
				if (metric === 'compare_balance') {
					// Diverging bins for stepped legend and stepped choropleth.
					return ['#2b6cb0', '#93c5fd', '#fca5a5', '#b91c1c'];
				}
				return ['#FEB24C', '#FC4E2A', '#BD0026', '#800026'];
			},
			mapsRegionLegendContinuousStyle() {
				const stops = [0, 0.3333333, 0.6666667, 1]
					.map((p) => `${this.mapsRegionContinuousColor(p)} ${Math.round(p * 100)}%`)
					.join(', ');
				return `width:220px;height:12px;border:1px solid #ccc;margin:6px 0;background:linear-gradient(90deg, ${stops});`;
			},
			mapsRegionColorModeOptions() {
				return [
					{ key: 'stepped', label: 'Stepped (4 bins)' },
					{ key: 'continuous', label: 'Continuous (precise)' },
				];
			},
			mapsHexToRgb(hex) {
				const s = String(hex || '').trim().replace('#', '');
				if (!/^[0-9a-f]{6}$/i.test(s)) return null;
				return {
					r: parseInt(s.slice(0, 2), 16),
					g: parseInt(s.slice(2, 4), 16),
					b: parseInt(s.slice(4, 6), 16),
				};
			},
			mapsLerp(a, b, t) {
				return a + ((b - a) * t);
			},
			mapsRegionContinuousColor(ratio) {
				const x = Math.max(0, Math.min(1, Number(ratio) || 0));
				const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
				if (metric === 'compare_balance') {
					// Diverging: blue (B) -> near-white (balanced) -> red (A).
					const toRgb = (r, g, b) => ({ r, g, b });
					const bSide = toRgb(43, 108, 176);
					const mid = toRgb(247, 250, 252);
					const aSide = toRgb(185, 28, 28);
					const lerp = (a, b, t) => Math.round(a + ((b - a) * t));
					if (x <= 0.5) {
						const t = x / 0.5;
						return `rgb(${lerp(bSide.r, mid.r, t)}, ${lerp(bSide.g, mid.g, t)}, ${lerp(bSide.b, mid.b, t)})`;
					}
					const t = (x - 0.5) / 0.5;
					return `rgb(${lerp(mid.r, aSide.r, t)}, ${lerp(mid.g, aSide.g, t)}, ${lerp(mid.b, aSide.b, t)})`;
				}
				const palette = this.mapsRegionLegendColors();
				if (!palette.length) return '#FC4E2A';
				if (palette.length === 1) return palette[0];
				const scaled = x * (palette.length - 1);
				const idx = Math.floor(scaled);
				const next = Math.min(palette.length - 1, idx + 1);
				const localT = Math.max(0, Math.min(1, scaled - idx));
				const c0 = this.mapsHexToRgb(palette[idx]);
				const c1 = this.mapsHexToRgb(palette[next]);
				if (!c0 || !c1) return palette[idx] || palette[0];
				const r = Math.round(this.mapsLerp(c0.r, c1.r, localT));
				const g = Math.round(this.mapsLerp(c0.g, c1.g, localT));
				const b = Math.round(this.mapsLerp(c0.b, c1.b, localT));
				return `rgb(${r}, ${g}, ${b})`;
			},
			mapsRegionLegendBreaks() {
				const range = this.mapsRegionLegendRange();
				if (!range) return [];
				const min = Number(range.min);
				const max = Number(range.max);
				if (!Number.isFinite(min) || !Number.isFinite(max)) return [];
				if (Math.abs(max - min) < 1e-12) return [min, min, min, min, min];
				const step = (max - min) / 4;
				return [min, min + step, min + (2 * step), min + (3 * step), max];
			},
			mapsRegionLegendLabels() {
				const breaks = this.mapsRegionLegendBreaks();
				const unit = this.mapsRegionMetricUnit();
				if (!breaks.length) return [];
				return breaks.map((b) => `${this.mapsFormatMetricValue(b)}${unit ? unit : ''}`);
			},
			mapsRegionLegendIndex(value) {
				const colors = this.mapsRegionLegendColors();
				const breaks = this.mapsRegionLegendBreaks();
				const v = Number(value);
				if (!Number.isFinite(v) || breaks.length < 5 || !colors.length) return -1;
				if (Math.abs(Number(breaks[4]) - Number(breaks[0])) < 1e-12) return colors.length - 1;
				for (let i = 0; i < colors.length; i += 1) {
					const upper = Number(breaks[i + 1]);
					if (v <= upper || i === colors.length - 1) return i;
				}
				return colors.length - 1;
			},
			mapsRegionLegendMinLabel() {
				const range = this.mapsRegionLegendRange();
				if (!range) return '';
				const unit = this.mapsRegionMetricUnit();
				return `${this.mapsFormatMetricValue(range.min)}${unit ? unit : ''}`;
			},
			mapsRegionLegendMaxLabel() {
				const range = this.mapsRegionLegendRange();
				if (!range) return '';
				const unit = this.mapsRegionMetricUnit();
				return `${this.mapsFormatMetricValue(range.max)}${unit ? unit : ''}`;
			},
			regionMetricValue(row) {
				const r = row && typeof row === 'object' ? row : {};
				const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
				if (metric === 'pct') return Number(r.pctOfTotal || 0);
				if (metric === 'ipm') return Number(r.ipm || 0);
				if (metric === 'rf_pct') return Number((r.rfPct ?? r.rf_pct) || 0);
				if (metric === 'unexpectedness') return Number(r.unexpectedness || 0);
				if (metric === 'compare_balance') {
					const names = this.mapsHasCompareQueries() ? this.mapsCompareQueries.slice() : [];
					if (names.length < 2) return 0;
					const cmp = this.mapsNormalizeCompareCounts(r.perQueryCounts || {});
					const a = Number(cmp && cmp[names[0]] != null ? cmp[names[0]] : 0);
					const b = Number(cmp && cmp[names[1]] != null ? cmp[names[1]] : 0);
					const denom = a + b;
					if (!Number.isFinite(a) || !Number.isFinite(b) || !Number.isFinite(denom) || denom <= 0) return 0;
					// -100 = all B, +100 = all A.
					return ((a - b) / denom) * 100;
				}
				return Number(r.count || 0);
			},
			mapsHasRegionData() { return this.mapsRegionRuns.length > 0; },
			mapsCoordinateHintLine() {
				const h = this.geoHintsObject();
				const coords = h && Array.isArray(h.coordinateFields) ? h.coordinateFields : [];
				if (!coords.length) return '';
				const picked = this.preferredGeoFieldFromCandidates(coords);
				const listed = coords.join(', ');
				return picked ? `Coordinate fields: ${listed} (using: ${picked})` : `Coordinate fields: ${listed}`;
			},
			ensureMapsPointsData() {
				if (this.mapsPointRows.length || this.mapsLoading || !this.mapsPointsRunAllowed() || this.mapsPointAutoRequested) return;
				this.mapsPointAutoRequested = true;
				this.runMapsPointsFrequency({ silent: false });
			},
			ensureMapsRegionData() {
				if (this.mapsRegionRuns.length || this.mapsLoading || !this.mapsRunAllowed() || this.mapsRegionAutoRequested) return;
				this.mapsRegionAutoRequested = true;
				this.runMapsFrequency().finally(() => {
					if (!this.mapsRegionRuns.length) this.mapsRegionAutoRequested = false;
				});
			},
			pickMapsRowLabel(row) { const r = row && typeof row === 'object' ? row : {}; const raw = r.value ?? r.label ?? r.key ?? ''; const s = String(raw).trim(); return s.length > 48 ? `${s.slice(0, 47)}…` : (s || '—'); },
			pickMapsRowCount(row) { const r = row && typeof row === 'object' ? row : {}; const c = Number(r.count ?? r.freq ?? r.n ?? 0); return Number.isFinite(c) ? Math.max(0, c) : 0; },
			mapsRowPerQueryCounts(row, compareNames) {
				const names = Array.isArray(compareNames) ? compareNames.map((x) => String(x || '').trim()).filter(Boolean) : [];
				if (!names.length) return {};
				const r = row && typeof row === 'object' ? row : {};
				const q = r && r.queries && typeof r.queries === 'object' ? r.queries : {};
				const out = {};
				const lowerKeyMap = {};
				Object.keys(r).forEach((k) => { lowerKeyMap[String(k || '').toLowerCase()] = k; });
				const readDirectCount = (nm) => {
					const keyExact = String(nm || '');
					const keyLower = keyExact.toLowerCase();
					const direct = r[keyExact];
					if (Number.isFinite(Number(direct))) return Number(direct);
					const directLower = lowerKeyMap[keyLower] != null ? r[lowerKeyMap[keyLower]] : undefined;
					if (Number.isFinite(Number(directLower))) return Number(directLower);
					const cands = [
						`${keyExact}_count`,
						`${keyExact}_freq`,
						`${keyExact}_n`,
						`${keyLower}_count`,
						`${keyLower}_freq`,
						`${keyLower}_n`,
					];
					for (let ci = 0; ci < cands.length; ci += 1) {
						const kk = cands[ci];
						const exact = r[kk];
						if (Number.isFinite(Number(exact))) return Number(exact);
						const hit = lowerKeyMap[kk.toLowerCase()];
						if (hit != null && Number.isFinite(Number(r[hit]))) return Number(r[hit]);
					}
					return null;
				};
				for (let i = 0; i < names.length; i += 1) {
					const nm = names[i];
					const ent = q && q[nm] && typeof q[nm] === 'object' ? q[nm] : {};
					let c = Number(ent.count ?? ent.freq ?? ent.n);
					if (!Number.isFinite(c)) {
						const fromDirect = readDirectCount(nm);
						c = Number.isFinite(Number(fromDirect)) ? Number(fromDirect) : 0;
					}
					out[nm] = Number.isFinite(c) ? Math.max(0, c) : 0;
				}
				return out;
			},
			normalizeMapsChartRows(rows, compareNames) {
				const names = Array.isArray(compareNames) ? compareNames.map((x) => String(x || '').trim()).filter(Boolean) : [];
				const raw = (Array.isArray(rows) ? rows : []).map((row) => ({
					label: this.pickMapsRowLabel(row),
					count: this.pickMapsRowCount(row),
					perQueryCounts: this.mapsRowPerQueryCounts(row, names),
				})).sort((a, b) => b.count - a.count);
				const max = raw.reduce((m, x) => (x.count > m ? x.count : m), 0);
				const total = raw.reduce((s, x) => s + x.count, 0);
				return raw.map(({ label, count, perQueryCounts }) => ({ label, count, perQueryCounts, pct: max > 0 ? (count / max) * 100 : 0, pctOfTotal: total > 0 ? (count / total) * 100 : 0, countDisplay: count.toLocaleString() }));
			},
			normalizeRegionRunRows(rowsRaw, compareNames) {
				const names = Array.isArray(compareNames) ? compareNames.map((x) => String(x || '').trim()).filter(Boolean) : [];
				const src = Array.isArray(rowsRaw) ? rowsRaw : [];
				const normalized = src.map((row) => {
					const r = row && typeof row === 'object' ? row : {};
					return {
						label: this.pickMapsRowLabel(r),
						count: this.pickMapsRowCount(r),
						perQueryCounts: this.mapsRowPerQueryCounts(r, names),
						pctOfTotal: Number(r.pct ?? r.pctOfTotal ?? 0),
						ipm: Number(r.ipm ?? 0),
						rfPct: Number(r.rf_pct ?? r.rfPct ?? 0),
						unexpectednessRaw: Number(r.unexpectedness ?? r.surprise ?? r.keyness ?? NaN),
						subcorpusSize: Number(r.subcorpus_size ?? r.subcorpusSize ?? 0),
					};
				}).sort((a, b) => Number(b.count || 0) - Number(a.count || 0));
				const total = normalized.reduce((s, r) => s + (Number.isFinite(Number(r.count)) ? Number(r.count) : 0), 0);
				const totalSubcorpus = normalized.reduce((s, r) => s + (Number.isFinite(Number(r.subcorpusSize)) ? Number(r.subcorpusSize) : 0), 0);
				const corpusRate = totalSubcorpus > 0 ? total / totalSubcorpus : 0;
				return normalized.map((r) => {
					const pctVal = Number.isFinite(Number(r.pctOfTotal)) && Number(r.pctOfTotal) > 0
						? Number(r.pctOfTotal)
						: (total > 0 ? (100 * Number(r.count || 0)) / total : 0);
					let unexpectedness = Number.isFinite(Number(r.unexpectednessRaw)) ? Number(r.unexpectednessRaw) : NaN;
					if (!Number.isFinite(unexpectedness)) {
						// Fallback: log2 observed rate vs expected corpus rate from subcorpus size.
						const sc = Number(r.subcorpusSize || 0);
						const observedRate = sc > 0 ? Number(r.count || 0) / sc : 0;
						if (sc > 0 && observedRate > 0 && corpusRate > 0) {
							unexpectedness = Math.log2(observedRate / corpusRate);
						} else {
							unexpectedness = 0;
						}
					}
					return {
						label: r.label,
						count: Number(r.count) || 0,
						perQueryCounts: r.perQueryCounts || {},
						countDisplay: Number(r.count || 0).toLocaleString(),
						pctOfTotal: Number(pctVal) || 0,
						ipm: Number.isFinite(r.ipm) ? r.ipm : 0,
						rfPct: Number.isFinite(r.rfPct) ? r.rfPct : 0,
						unexpectedness: Number.isFinite(unexpectedness) ? unexpectedness : 0,
						subcorpusSize: Number.isFinite(Number(r.subcorpusSize)) ? Number(r.subcorpusSize) : 0,
					};
				});
			},
			saveRegionRun(field, rowsRaw, freq, compareNames) {
				const fieldName = String(field || '').trim(); if (!fieldName) return;
				const normalizedRows = this.normalizeRegionRunRows(rowsRaw, compareNames); if (!normalizedRows.length) return;
				const id = `region:${fieldName}`;
				const rec = {
					id,
					field: fieldName,
					label: this.regionRunButtonLabel(fieldName),
					rows: normalizedRows,
					compareQueries: Array.isArray(compareNames) ? compareNames.slice() : [],
					total: freq && Number.isFinite(Number(freq.total)) ? Number(freq.total) : null,
					returned: normalizedRows.length,
				};
				const idx = this.mapsRegionRuns.findIndex((r) => r.id === id);
				if (idx >= 0) this.mapsRegionRuns.splice(idx, 1, rec); else this.mapsRegionRuns.push(rec);
				this.autoSelectBoundaryForField(fieldName);
				this.mapsActiveRegionRunId = id; this.applyCurrentRegionRunToOutputs(); this.mapsMapMode = 'regions';
			},
			applyCurrentRegionRunToOutputs() {
				const run = this.currentRegionRun(); if (!run) return;
				this.mapsChartRows = Array.isArray(run.rows) ? run.rows.slice() : [];
				this.mapsCompareQueries = Array.isArray(run.compareQueries) ? run.compareQueries.slice() : [];
				this.mapsResolvedFreqField = run.field || this.mapsResolvedFreqField;
				if (run.total != null) this.mapsFreqTotal = run.total;
				if (run.returned != null) this.mapsFreqReturned = run.returned;
			},
			mapsCurrentTableRows() { const run = this.currentRegionRun(); return run && Array.isArray(run.rows) ? run.rows.slice() : (Array.isArray(this.mapsChartRows) ? this.mapsChartRows.slice() : []); },
			mapsSortedDisplayRows() {
				const rows = this.mapsCurrentTableRows();
				const col = this.mapsTableSort && this.mapsTableSort.col === 'label' ? 'label' : 'count';
				const asc = !!(this.mapsTableSort && this.mapsTableSort.asc);
				rows.sort((a, b) => col === 'label' ? (asc ? String(a.label || '').localeCompare(String(b.label || '')) : String(b.label || '').localeCompare(String(a.label || ''))) : (asc ? Number(a.count || 0) - Number(b.count || 0) : Number(b.count || 0) - Number(a.count || 0)));
				return rows;
			},
			mapsFilteredTableRows() {
				const q = String(this.mapsTableSearch || '').trim().toLowerCase(); const rows = this.mapsSortedDisplayRows();
				return q ? rows.filter((r) => String(r.label || '').toLowerCase().includes(q)) : rows;
			},
			setMapsTableSort(col) {
				const key = String(col || '').trim().toLowerCase() === 'label' ? 'label' : 'count';
				this.mapsTableSort = this.mapsTableSort.col === key ? { col: key, asc: !this.mapsTableSort.asc } : { col: key, asc: key === 'label' };
			},
			mapsFreqMetaLine() {
				const parts = []; const f = String(this.mapsResolvedFreqField || '').trim(); if (f) parts.push(`Region attribute: ${f}`);
				if (this.mapsFreqReturned != null) parts.push(`rows returned: ${Number(this.mapsFreqReturned)}`);
				if (this.mapsFreqTotal != null) parts.push(`hit total (where reported): ${Number(this.mapsFreqTotal)}`);
				if (this.mapsHasCompareQueries()) parts.push(`compare: ${this.mapsCompareQueries.join(' vs ')}`);
				return parts.join(' · ');
			},
			mapsCompareQueryNamesFromFreq(freq) {
				if (!freq || typeof freq !== 'object') return [];
				if (Array.isArray(freq.compare_queries) && freq.compare_queries.length) {
					return freq.compare_queries.map((x) => String(x || '').trim()).filter(Boolean);
				}
				if (freq.table && Array.isArray(freq.table.compare_queries) && freq.table.compare_queries.length) {
					return freq.table.compare_queries.map((x) => String(x || '').trim()).filter(Boolean);
				}
				const resp = freq.response && typeof freq.response === 'object' ? freq.response : null;
				const res = resp && resp.result && typeof resp.result === 'object' ? resp.result : null;
				if (res && Array.isArray(res.compare_queries) && res.compare_queries.length) {
					return res.compare_queries.map((x) => String(x || '').trim()).filter(Boolean);
				}
				if (res && res.table && Array.isArray(res.table.compare_queries) && res.table.compare_queries.length) {
					return res.table.compare_queries.map((x) => String(x || '').trim()).filter(Boolean);
				}
				return [];
			},
			mapsInferCompareNamesFromRows(rowsRaw) {
				const rows = Array.isArray(rowsRaw) ? rowsRaw : [];
				const tally = {};
				for (let i = 0; i < rows.length; i += 1) {
					const r = rows[i] && typeof rows[i] === 'object' ? rows[i] : {};
					const q = r.queries && typeof r.queries === 'object' ? r.queries : null;
					if (q) {
						Object.keys(q).forEach((k) => {
							const nm = String(k || '').trim();
							const val = Number(q[k]);
							if (!nm || !Number.isFinite(val) || val <= 0) return;
							tally[nm] = Number(tally[nm] || 0) + val;
						});
					}
					Object.keys(r).forEach((k) => {
						const nm = String(k || '').trim();
						// Keep fallback conservative: only likely named-query ids.
						if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(nm)) return;
						if (/^(value|label|key|count|freq|n|pct|ipm|rfPct|rf_pct|unexpectedness)$/i.test(nm)) return;
						const val = Number(r[k]);
						if (!Number.isFinite(val) || val <= 0) return;
						tally[nm] = Number(tally[nm] || 0) + val;
					});
				}
				return Object.keys(tally).sort((a, b) => Number(tally[b] || 0) - Number(tally[a] || 0));
			},
			mapsCompareTotalsFromFreq(freq, compareNames) {
				const names = Array.isArray(compareNames) ? compareNames.map((x) => String(x || '').trim()).filter(Boolean) : [];
				const out = {};
				if (!names.length || !freq || typeof freq !== 'object') return out;
				const resp = freq.response && typeof freq.response === 'object' ? freq.response : null;
				const res = resp && resp.result && typeof resp.result === 'object' ? resp.result : null;
				const totals = (freq.totals_per_query && typeof freq.totals_per_query === 'object'
					? freq.totals_per_query
					: (freq.table && freq.table.totals_per_query && typeof freq.table.totals_per_query === 'object'
						? freq.table.totals_per_query
						: (res && res.totals_per_query && typeof res.totals_per_query === 'object'
							? res.totals_per_query
							: (res && res.table && res.table.totals_per_query && typeof res.table.totals_per_query === 'object'
								? res.table.totals_per_query
								: {}))));
				for (let i = 0; i < names.length; i += 1) {
					const nm = names[i];
					const n = Number(totals[nm]);
					if (Number.isFinite(n) && n > 0) out[nm] = n;
				}
				return out;
			},
			mapsNormalizeCompareCounts(perQueryCounts) {
				const raw = perQueryCounts && typeof perQueryCounts === 'object' ? perQueryCounts : {};
				const mode = String(this.mapsCompareScaleMode || 'relative').trim().toLowerCase();
				if (mode === 'raw') return raw;
				const totals = this.mapsCompareTotalsByQuery && typeof this.mapsCompareTotalsByQuery === 'object'
					? this.mapsCompareTotalsByQuery
					: {};
				const out = {};
				let hasNorm = false;
				Object.keys(raw).forEach((k) => {
					const c = Number(raw[k]);
					const base = Number(totals[k]);
					if (Number.isFinite(c) && c >= 0 && Number.isFinite(base) && base > 0) {
						out[k] = (c / base) * 1000000;
						hasNorm = true;
					} else if (Number.isFinite(c) && c >= 0) {
						out[k] = c;
					}
				});
				return hasNorm ? out : raw;
			},
			buildMapsFreqProgram(freqField) {
				const field = String(freqField || '').trim();
				if (!field) return '';
				const ss = this._mapsSearchScopeFns();
				const store = this._mapsScopeStore();
				if (!ss || !store) return this.afSearchScopeMapsQuery();
				const scope = ss.compileQueriesToScopeProgram(store.queries, { activeOnly: true });
				if (!scope) return '';
				const names = ss.activeNamedAssignIds(store.queries);
				const tail = ss.buildFreqClause(names, field);
				return ss.compileScopeProgram(scope, tail);
			},
			extractFreqErrorFromState(freq) {
				if (!freq || typeof freq !== 'object') return '';
				const resp = freq.response && typeof freq.response === 'object' ? freq.response : null;
				if (!resp) return '';
				return String(resp.error || (resp.result && resp.result.error) || '').trim();
			},
			async executeMapsFrequencyRequest(freqField, freqLimit) {
				const fd = this.buildAfFreqFormData(String(freqField || '').trim(), freqLimit);
				const program = this.buildMapsFreqProgram(freqField);
				if (program) fd.set('query', program);
				const rootEl = typeof document !== 'undefined' ? document.getElementById('flexicorp-advanced-freqs-root') : null;
				const fns = ttMapsSharedFns();
				const url = typeof fns.flexicorpAjaxPostUrl === 'function'
					? fns.flexicorpAjaxPostUrl(rootEl, this._mapsAjaxActionName())
					: (typeof this.afAjaxPostUrl === 'function' ? this.afAjaxPostUrl() : '');
				const out = (typeof fns.runFlexicorpAjaxFetch === 'function')
					? await fns.runFlexicorpAjaxFetch(fd, rootEl, url)
					: { response: await fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json, text/javascript, */*;q=0.01' }, credentials: 'same-origin' }), raw: '', state: null, parseError: null };
				const response = out.response;
				let state = out.state;
				if (!state && !out.parseError && out.response && typeof out.response.text === 'function') {
					try { state = JSON.parse(await out.response.text()); } catch (_) {}
				}
				if (out.parseError || !response.ok || !state || typeof state !== 'object') {
					return { ok: false, error: out.parseError ? 'Invalid JSON from endpoint.' : `HTTP ${response ? response.status : 0}`, rowsRaw: [], freq: null };
				}
				const freq = state.frequency;
				const err = this.extractFreqErrorFromState(freq);
				const rowsRaw = freq && Array.isArray(freq.rows) ? freq.rows : [];
				if (err && (!freq || !freq.ran || !rowsRaw.length)) return { ok: false, error: err, rowsRaw, freq };
				return { ok: true, error: err, rowsRaw, freq };
			},
			async runMapsPointsFrequency({ silent = false } = {}) {
				if (!silent) this.mapsError = '';
				const q = this.afSearchScopeMapsQuery(); const field = this.resolvedGeoPointField();
				if (!q || !field) {
					if (!silent) {
						if (!q) this.mapsError = 'Set Search scope first (pick at least one active query in Search Scope).';
						else this.mapsError = 'This corpus has no geo coordinate field hints (e.g. text_geo/lat/lon).';
					}
					return;
				}
				this.mapsLoading = true;
				try {
					const out = await this.executeMapsFrequencyRequest(field, this.mapsPointLimit);
					if (!out.ok) { this.mapsError = out.error || 'Point aggregation failed.'; return; }
					let compareNames = this.mapsCompareQueryNamesFromFreq(out.freq || {});
					if (!compareNames.length) compareNames = this.mapsInferCompareNamesFromRows(out.rowsRaw);
					this.mapsCompareQueries = compareNames;
					this.mapsCompareTotalsByQuery = this.mapsCompareTotalsFromFreq(out.freq || {}, compareNames);
					this.mapsEnsureCompareDefaults();
					this.mapsPointField = out.freq && out.freq.field != null ? String(out.freq.field).trim() : field;
					this.mapsPointRows = this.normalizeMapsPointRows(out.rowsRaw, compareNames);
					if (!this.mapsPointRows.length) this.mapsError = 'Point aggregation ran, but no mappable coordinates were found in returned values.';
					this.syncMapsViz();
				} finally { this.mapsLoading = false; }
			},
			async runMapsFrequency() {
				this.mapsError = '';
				this.mapsBoundaryError = '';
				const q = this.afSearchScopeMapsQuery(); const field = this.resolvedGeoRegionField();
				if (!q || !field) {
					if (!q) this.mapsError = 'Set Search scope first (pick at least one active query in Search Scope).';
					else this.mapsError = 'This corpus has no geo region field hints (e.g. province/region/state columns).';
					return;
				}
				this.mapsLoading = true;
				try {
					const out = await this.executeMapsFrequencyRequest(field, 60);
					if (!out.ok) { this.mapsError = out.error || 'No frequency rows returned.'; return; }
					const freq = out.freq || {};
					let compareNames = this.mapsCompareQueryNamesFromFreq(freq);
					if (!compareNames.length) compareNames = this.mapsInferCompareNamesFromRows(out.rowsRaw);
					this.mapsCompareQueries = compareNames;
					this.mapsCompareTotalsByQuery = this.mapsCompareTotalsFromFreq(freq || {}, compareNames);
					this.mapsEnsureCompareDefaults();
					this.mapsResolvedFreqField = freq && freq.field != null ? String(freq.field).trim() : field;
					this.mapsFreqTotal = freq && freq.total != null ? freq.total : null;
					this.mapsFreqReturned = freq && freq.returned != null ? freq.returned : out.rowsRaw.length;
					this.mapsChartRows = this.normalizeMapsChartRows(out.rowsRaw, compareNames);
					this.saveRegionRun(this.mapsResolvedFreqField || field, out.rowsRaw, freq, compareNames);
					this.syncMapsViz();
				} finally { this.mapsLoading = false; }
			},
			parseLatLon(rawValue) {
				const s = String(rawValue || '').trim(); const nums = s.match(/-?\d+(?:\.\d+)?/g);
				if (!nums || nums.length < 2) return null; const lat = Number(nums[0]); const lon = Number(nums[1]);
				if (!Number.isFinite(lat) || !Number.isFinite(lon) || lat < -90 || lat > 90 || lon < -180 || lon > 180) return null;
				return { lat, lon };
			},
			mapsEscapeHtml(v) {
				return String(v == null ? '' : v)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#39;');
			},
			mapsPointDisplayName(row, fallbackValue) {
				const r = row && typeof row === 'object' ? row : {};
				const candidates = [
					r.place,
					r.location,
					r.title,
					r.name,
					r.display,
					r.label,
					r.key,
					fallbackValue,
				];
				for (let i = 0; i < candidates.length; i += 1) {
					const s = String(candidates[i] == null ? '' : candidates[i]).trim();
					if (!s) continue;
					// If the candidate itself looks like pure coordinates, keep searching.
					if (this.parseLatLon(s)) continue;
					return s;
				}
				return String(fallbackValue || '').trim();
			},
			mapsDocumentsUrlForCoordinate(coordString) {
				try {
					const url = new URL('index.php', window.location.href);
					url.searchParams.set('action', 'flexicorp');
					url.searchParams.set('active_tab', 'documents');
					url.searchParams.set('docs_filter', String(coordString || '').trim());
					if (String(this.backend || '').trim()) url.searchParams.set('backend', String(this.backend).trim());
					if (String(this.queryEngine || '').trim()) url.searchParams.set('query_engine', String(this.queryEngine).trim());
					if (String(this.queryLanguage || '').trim()) url.searchParams.set('query_language', String(this.queryLanguage).trim());
					if (String(this.corpusFormat || '').trim()) url.searchParams.set('corpus_format', String(this.corpusFormat).trim());
					return url.toString();
				} catch (_) {
					return '';
				}
			},
			mapsDonutSliceColor(i, total) {
				const n = Math.max(1, Number(total) || 1);
				return `hsl(${Math.round((360 * i) / n)}, 68%, 50%)`;
			},
			mapsDonutCssForCounts(perQueryCounts, sizePx) {
				const entries = Object.entries(perQueryCounts || {}).map(([k, v]) => [k, Math.max(0, Number(v) || 0)]).filter(([, v]) => v > 0);
				const total = entries.reduce((s, [, v]) => s + v, 0);
				if (!entries.length || total <= 0) return '';
				let acc = 0;
				const segs = [];
				for (let i = 0; i < entries.length; i += 1) {
					const pct = (entries[i][1] / total) * 100;
					const from = acc;
					acc += pct;
					segs.push(`${this.mapsDonutSliceColor(i, entries.length)} ${from}% ${acc}%`);
				}
				const size = Math.max(10, Number(sizePx) || 18);
				return `width:${size}px;height:${size}px;border-radius:50%;border:1px solid rgba(31,41,55,0.45);background:conic-gradient(${segs.join(',')});box-shadow:inset 0 0 0 ${Math.max(2, Math.round(size * 0.24))}px rgba(255,255,255,0.92);`;
			},
			mapsPointDonutSizePx(count, maxCount, mode) {
				const c = Math.max(0, Number(count) || 0);
				const m = Math.max(1, Number(maxCount) || 1);
				const ratio = Math.max(0, Math.min(1, c / m));
				// Emphasize visible size differences for compare donuts.
				const eased = Math.sqrt(ratio);
				if (String(mode || '').toLowerCase() === 'intensity') {
					return 14 + Math.round(30 * eased); // 14..44 px
				}
				return 16 + Math.round(22 * eased); // 16..38 px
			},
			mapsClusterAggregateCounts(cluster, compareNames) {
				const names = Array.isArray(compareNames) ? compareNames : [];
				const out = {};
				for (let i = 0; i < names.length; i += 1) out[String(names[i])] = 0;
				const markers = cluster && typeof cluster.getAllChildMarkers === 'function' ? cluster.getAllChildMarkers() : [];
				let total = 0;
				for (let i = 0; i < markers.length; i += 1) {
					const mk = markers[i] && typeof markers[i] === 'object' ? markers[i] : null;
					if (!mk) continue;
					const pc = mk.options && mk.options.fcPerQueryCounts && typeof mk.options.fcPerQueryCounts === 'object'
						? mk.options.fcPerQueryCounts
						: {};
					for (let ni = 0; ni < names.length; ni += 1) {
						const nm = String(names[ni] || '');
						const n = Number(pc[nm] != null ? pc[nm] : 0);
						if (Number.isFinite(n) && n > 0) out[nm] += n;
					}
					const c = Number(mk.options && mk.options.fcCount != null ? mk.options.fcCount : 0);
					if (Number.isFinite(c) && c > 0) total += c;
				}
				const cmpCounts = this.mapsNormalizeCompareCounts(out);
				if (total <= 0) total = Object.values(cmpCounts).reduce((s, n) => s + (Number.isFinite(Number(n)) ? Number(n) : 0), 0);
				return { perQueryCounts: cmpCounts, total };
			},
			normalizeMapsPointRows(rows, compareNames) {
				const names = Array.isArray(compareNames) ? compareNames.map((x) => String(x || '').trim()).filter(Boolean) : [];
				return (Array.isArray(rows) ? rows : []).map((row) => {
					const rawValue = row && typeof row === 'object' ? (row.value ?? row.label ?? row.key ?? '') : '';
					const c = this.parseLatLon(rawValue); if (!c) return null;
					const perQueryCounts = this.mapsRowPerQueryCounts(row, names);
					const perQueryTotal = Object.values(perQueryCounts).reduce((s, n) => s + (Number.isFinite(Number(n)) ? Number(n) : 0), 0);
					const rawCount = this.pickMapsRowCount(row);
					const count = rawCount > 0 ? rawCount : (perQueryTotal > 0 ? perQueryTotal : 0);
					return {
						lat: c.lat,
						lon: c.lon,
						count,
						perQueryCounts,
						value: String(rawValue || ''),
						label: this.mapsPointDisplayName(row, rawValue),
					};
				}).filter(Boolean);
			},
			destroyMapsChart() { try { if (this.mapsChartInstance && typeof this.mapsChartInstance.destroy === 'function') this.mapsChartInstance.destroy(); } catch (_) {} this.mapsChartInstance = null; },
			destroyMapsLeaflet() { try { if (this.mapsLeafletRetryTimer) clearTimeout(this.mapsLeafletRetryTimer); } catch (_) {} this.mapsLeafletRetryTimer = null; try { if (this.mapsLeafletMap && typeof this.mapsLeafletMap.stop === 'function') this.mapsLeafletMap.stop(); } catch (_) {} try { if (this.mapsLeafletMap && typeof this.mapsLeafletMap.remove === 'function') this.mapsLeafletMap.remove(); } catch (_) {} this.mapsLeafletMap = null; this.mapsLeafletCanvasRenderer = null; this.mapsLeafletClusterLayer = null; this.mapsBoundaryLayer = null; this.mapsRegionDonutLayer = null; },
			mapsNormalizedRegionName(v) {
				return String(v == null ? '' : v)
					.normalize('NFD')
					.replace(/[\u0300-\u036f]/g, '')
					.toLowerCase()
					.replace(/[^a-z0-9]+/g, ' ')
					.trim();
			},
			mapsFeatureNameFromProperties(props, preferredKey) {
				if (!props || typeof props !== 'object') return '';
				const pk = String(preferredKey || '').trim();
				if (pk && props[pk] != null && String(props[pk]).trim()) return String(props[pk]).trim();
				const cands = ['shapeName', 'name', 'NAME_1', 'NAME', 'province', 'provincia', 'region'];
				for (let i = 0; i < cands.length; i += 1) {
					const k = cands[i];
					if (props[k] != null && String(props[k]).trim()) return String(props[k]).trim();
				}
				return '';
			},
			async mapsFetchGeoBoundariesGeoJson(iso3, adm, geometryPref) {
				const iso = String(iso3 || '').trim().toUpperCase();
				const level = String(adm || 'ADM1').trim().toUpperCase();
				const pref = String(geometryPref || '').trim().toLowerCase();
				const preferSimplified = pref !== 'full' && pref !== 'detailed';
				const looksLikeGeoJson = (obj) => !!(obj && typeof obj === 'object' && (obj.type === 'FeatureCollection' || obj.type === 'Feature'));
				const tryJson = async (url, label) => {
					const res = await fetch(url, { credentials: 'omit', mode: 'cors' });
					if (!res.ok) throw new Error(`${label} HTTP ${res.status}`);
					let text = '';
					try { text = await res.text(); } catch (_) { text = ''; }
					const trimmed = String(text || '').trim();
					if (!trimmed) throw new Error(`${label} returned empty body`);
					if (/^version https:\/\/git-lfs\.github\.com\/spec\/v1/i.test(trimmed)) {
						throw new Error(`${label} returned Git LFS pointer, not GeoJSON`);
					}
					let data = null;
					try { data = JSON.parse(trimmed); } catch (_) { data = null; }
					if (!looksLikeGeoJson(data)) throw new Error(`${label} did not return valid GeoJSON`);
					return data;
				};
				// Attempt official API first (includes canonical download URL).
				let commitRef = 'main';
				try {
					const metaUrl = `https://www.geoboundaries.org/api/current/gbOpen/${encodeURIComponent(iso)}/${encodeURIComponent(level)}/`;
					const meta = await tryJson(metaUrl, 'GeoBoundaries metadata');
					const rawUrl = String((meta && meta.gjDownloadURL) || '').trim();
					const simpleUrl = String((meta && meta.simplifiedGeometryGeoJSON) || '').trim();
					const ordered = preferSimplified ? [simpleUrl, rawUrl] : [rawUrl, simpleUrl];
					const firstUrl = ordered.find((u) => u) || '';
					if (firstUrl) {
						const m = firstUrl.match(/geoBoundaries\/raw\/([^/]+)\//i);
						if (m && m[1]) commitRef = String(m[1]).trim();
						for (let i = 0; i < ordered.length; i += 1) {
							const u = String(ordered[i] || '').trim();
							if (!u) continue;
							try { return await tryJson(u, 'GeoBoundaries GeoJSON'); } catch (_) {}
						}
					}
				} catch (_) {}
				// Fallback mirrors for stricter CORS/LFS environments.
				const suffix = preferSimplified ? '_simplified' : '';
				const altSuffix = preferSimplified ? '' : '_simplified';
				const mirrors = [
					`https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/${encodeURIComponent(commitRef)}/releaseData/gbOpen/${encodeURIComponent(iso)}/${encodeURIComponent(level)}/geoBoundaries-${encodeURIComponent(iso)}-${encodeURIComponent(level)}${suffix}.geojson`,
					`https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/${encodeURIComponent(commitRef)}/releaseData/gbOpen/${encodeURIComponent(iso)}/${encodeURIComponent(level)}/geoBoundaries-${encodeURIComponent(iso)}-${encodeURIComponent(level)}${altSuffix}.geojson`,
					`https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/main/releaseData/gbOpen/${encodeURIComponent(iso)}/${encodeURIComponent(level)}/geoBoundaries-${encodeURIComponent(iso)}-${encodeURIComponent(level)}${suffix}.geojson`,
					`https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/main/releaseData/gbOpen/${encodeURIComponent(iso)}/${encodeURIComponent(level)}/geoBoundaries-${encodeURIComponent(iso)}-${encodeURIComponent(level)}${altSuffix}.geojson`,
					`https://cdn.jsdelivr.net/gh/wmgeolab/geoBoundaries@main/releaseData/gbOpen/${encodeURIComponent(iso)}/${encodeURIComponent(level)}/geoBoundaries-${encodeURIComponent(iso)}-${encodeURIComponent(level)}${suffix}.geojson`,
					`https://raw.githubusercontent.com/wmgeolab/geoBoundaries/main/releaseData/gbOpen/${encodeURIComponent(iso)}/${encodeURIComponent(level)}/geoBoundaries-${encodeURIComponent(iso)}-${encodeURIComponent(level)}${suffix}.geojson`,
				];
				let lastErr = null;
				for (let i = 0; i < mirrors.length; i += 1) {
					try {
						return await tryJson(mirrors[i], 'GeoBoundaries mirror');
					} catch (e) {
						lastErr = e;
					}
				}
				throw lastErr || new Error('Could not fetch GeoBoundaries data.');
			},
			async mapsLoadBoundaryGeoJson(def) {
				if (!def) throw new Error('No boundary dataset selected.');
				if (def.provider === 'group') {
					const memberKeys = Array.isArray(def.members) ? def.members : [];
					const features = [];
					for (let i = 0; i < memberKeys.length; i += 1) {
						const child = this.mapsBoundaryByKey(memberKeys[i]);
						if (!child || child.provider === 'group') continue;
						const childGeo = await this.mapsEnsureBoundaryData(child);
						const childFeatures = childGeo && Array.isArray(childGeo.features) ? childGeo.features : [];
						for (let j = 0; j < childFeatures.length; j += 1) features.push(childFeatures[j]);
					}
					return { type: 'FeatureCollection', features };
				}
				if (def.provider === 'geoboundaries') {
					return await this.mapsFetchGeoBoundariesGeoJson(def.iso3, def.adm || 'ADM1', def.geometry || 'simplified');
				}
				const url = String(def.url || '').trim();
				if (!url) throw new Error(`Dataset "${def.key}" missing url`);
				const res = await fetch(url, { credentials: 'same-origin' });
				if (!res.ok) throw new Error(`Boundary GeoJSON HTTP ${res.status}`);
				return await res.json();
			},
			async mapsEnsureBoundaryData(def) {
				const key = def && def.key ? String(def.key) : '';
				if (!key) throw new Error('Boundary dataset key missing');
				if (this.mapsBoundaryCache[key]) return this.mapsBoundaryCache[key];
				this.mapsBoundaryLoading = true;
				this.mapsBoundaryError = '';
				try {
					const geojson = await this.mapsLoadBoundaryGeoJson(def);
					this.mapsBoundaryCache[key] = geojson;
					return geojson;
				} catch (e) {
					this.mapsBoundaryError = e && e.message ? e.message : String(e);
					throw e;
				} finally {
					this.mapsBoundaryLoading = false;
				}
			},
			ensureLeafletMapBase() {
				const host = document.getElementById('advanced-freqs-maps-geomap-host'); if (!host) return null;
				const L = typeof window !== 'undefined' ? window.L : null; if (!L || typeof L.map !== 'function') return null;
				if (!this.mapsLeafletMap) {
					host.innerHTML = '';
					this.mapsLeafletMap = L.map(host, {
						zoomControl: true,
						zoomAnimation: false,
						fadeAnimation: false,
						markerZoomAnimation: false,
					});
					this.mapsLeafletCanvasRenderer = typeof L.canvas === 'function' ? L.canvas() : null;
					L.tileLayer(this.mapsConfiguredTileLayer(), {
						maxZoom: 19,
						attribution: '&copy; OpenStreetMap contributors',
						crossOrigin: true,
					}).addTo(this.mapsLeafletMap);
					const defView = this.mapsDefaultView();
					this.mapsLeafletMap.setView(defView.center, defView.zoom, { animate: false });
				}
				// x-show can keep the map host hidden during init; force a size refresh when visible.
				try {
					if (this.mapsLeafletMap && typeof this.mapsLeafletMap.invalidateSize === 'function') {
						this.mapsLeafletMap.invalidateSize();
						setTimeout(() => {
							try { this.mapsLeafletMap && this.mapsLeafletMap.invalidateSize(); } catch (_) {}
						}, 120);
					}
				} catch (_) {}
				return L;
			},
			renderMapsLeafletPoints() {
				const L = this.ensureLeafletMapBase();
				if (!L) {
					if (!this.mapsLeafletRetryTimer) {
						this.mapsLeafletRetryTimer = setTimeout(() => { this.mapsLeafletRetryTimer = null; this.syncMapsViz(); }, 350);
					}
					return;
				}
				if (this.mapsLeafletClusterLayer && typeof this.mapsLeafletMap.removeLayer === 'function') {
					try { this.mapsLeafletMap.removeLayer(this.mapsLeafletClusterLayer); } catch (_) {}
				}
				this.mapsLeafletClusterLayer = null;
				if (this.mapsBoundaryLayer && typeof this.mapsLeafletMap.removeLayer === 'function') {
					try { this.mapsLeafletMap.removeLayer(this.mapsBoundaryLayer); } catch (_) {}
				}
				this.mapsBoundaryLayer = null;
				if (this.mapsRegionDonutLayer && typeof this.mapsLeafletMap.removeLayer === 'function') {
					try { this.mapsLeafletMap.removeLayer(this.mapsRegionDonutLayer); } catch (_) {}
				}
				this.mapsRegionDonutLayer = null;
				if (!Array.isArray(this.mapsPointRows) || !this.mapsPointRows.length) {
					const defView = this.mapsDefaultView();
					this.mapsLeafletMap.setView(defView.center, defView.zoom, { animate: false });
					return;
				}
				const bounds = [];
				const mode = String(this.mapsPointVizMode || 'cluster').toLowerCase();
				const compareNames = this.mapsHasCompareQueries() ? this.mapsCompareQueries.slice() : [];
				const maxCount = this.mapsPointRows.reduce((m, p) => Math.max(m, Number(p.count || 0)), 0) || 1;
				const layer = (mode === 'cluster' && typeof L.markerClusterGroup === 'function')
					? L.markerClusterGroup(compareNames.length
						? {
							iconCreateFunction: (cluster) => {
								const agg = this.mapsClusterAggregateCounts(cluster, compareNames);
								const clusterTotal = Math.max(1, Number(agg.total || cluster.getChildCount() || 1));
								const size = this.mapsPointDonutSizePx(clusterTotal, maxCount, 'intensity');
								const css = this.mapsDonutCssForCounts(agg.perQueryCounts, size);
								const html = css
									? `<div style="${css}"></div>`
									: `<div style="width:${size}px;height:${size}px;border-radius:50%;background:#334155;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;border:1px solid rgba(255,255,255,0.85);">${Number(cluster.getChildCount() || 0)}</div>`;
								return L.divIcon({
									className: 'fc-maps-cluster-donut',
									html,
									iconSize: [size, size],
									iconAnchor: [Math.round(size / 2), Math.round(size / 2)],
								});
							},
						}
						: undefined)
					: L.layerGroup();
				layer.addTo(this.mapsLeafletMap);
				this.mapsLeafletClusterLayer = layer;
				for (let i = 0; i < this.mapsPointRows.length; i += 1) {
					const p = this.mapsPointRows[i];
					const compareSliceCounts = this.mapsNormalizeCompareCounts(p.perQueryCounts || {});
					const coordText = `${p.lat} ${p.lon}`;
					const markerTitle = p.label || coordText;
					const docsUrl = this.mapsDocumentsUrlForCoordinate(coordText);
					const popupParts = [];
					popupParts.push(`<strong>${this.mapsEscapeHtml(markerTitle)}</strong>`);
					if (p.label && p.label !== coordText) {
						popupParts.push(`<div><small>${this.mapsEscapeHtml(coordText)}</small></div>`);
					}
					popupParts.push(`<div>Hits: ${Number(p.count || 0).toLocaleString()}</div>`);
					if (compareNames.length) {
						for (let qi = 0; qi < compareNames.length; qi += 1) {
							const qn = compareNames[qi];
							const qc = Number(p.perQueryCounts && p.perQueryCounts[qn] != null ? p.perQueryCounts[qn] : 0);
							popupParts.push(`<div>${this.mapsEscapeHtml(qn)}: ${Number.isFinite(qc) ? qc.toLocaleString() : '0'}</div>`);
						}
					}
					if (docsUrl) {
						popupParts.push(
							`<div style="margin-top:0.35rem;"><a href="${this.mapsEscapeHtml(docsUrl)}" target="_self">Show documents for this point</a></div>`,
						);
					}
					const pointLatLng = [p.lat, p.lon];
					if (mode === 'intensity') {
						const count = Math.max(0, Number(p.count || 0));
						const ratio = Math.max(0, Math.min(1, count / maxCount));
						const radius = 4 + Math.round(18 * Math.sqrt(ratio));
						const hue = 220 - Math.round(220 * ratio); // blue -> red
						const color = `hsl(${hue}, 90%, 45%)`;
						if (compareNames.length) {
							const donutSize = this.mapsPointDonutSizePx(count, maxCount, mode);
							const marker = L.marker(pointLatLng, {
								title: markerTitle,
								fcPerQueryCounts: p.perQueryCounts || {},
								fcCount: Number(p.count || 0),
								icon: L.divIcon({
									className: 'fc-maps-donut-icon',
									html: `<div style="${this.mapsDonutCssForCounts(compareSliceCounts, donutSize)}"></div>`,
									iconSize: [donutSize, donutSize],
									iconAnchor: [Math.round(donutSize / 2), Math.round(donutSize / 2)],
								}),
							});
							marker.bindPopup(popupParts.join(''));
							layer.addLayer(marker);
						} else {
							const marker = L.circleMarker(pointLatLng, {
								radius,
								weight: 1,
								color: '#1f2937',
								fillColor: color,
								fillOpacity: 0.55 + (0.35 * ratio),
							});
							marker.bindPopup(popupParts.join(''));
							layer.addLayer(marker);
						}
					} else {
						const marker = compareNames.length
							? (() => {
								const donutSize = this.mapsPointDonutSizePx(Number(p.count || 0), maxCount, mode);
								return L.marker(pointLatLng, {
									title: markerTitle,
									fcPerQueryCounts: p.perQueryCounts || {},
									fcCount: Number(p.count || 0),
									icon: L.divIcon({
										className: 'fc-maps-donut-icon',
										html: `<div style="${this.mapsDonutCssForCounts(compareSliceCounts, donutSize)}"></div>`,
										iconSize: [donutSize, donutSize],
										iconAnchor: [Math.round(donutSize / 2), Math.round(donutSize / 2)],
									}),
								});
							})()
							: L.marker(pointLatLng, { title: markerTitle });
						marker.bindPopup(popupParts.join(''));
						layer.addLayer(marker);
					}
					bounds.push(pointLatLng);
				}
				// Respect configured geomap default center/zoom as authoritative.
				// Only auto-fit when no explicit geomap start view exists.
				if (bounds.length && !this.mapsConfiguredStartView()) {
					this.mapsLeafletMap.fitBounds(bounds, { padding: [24, 24], maxZoom: 11, animate: false });
				}
				if (bounds.length) this.mapsApplyPendingView();
			},
			mapsCurrentView() {
				const m = this.mapsLeafletMap;
				if (!m || typeof m.getCenter !== 'function') return null;
				try {
					const c = m.getCenter();
					return { center: [Number(c.lat.toFixed(5)), Number(c.lng.toFixed(5))], zoom: m.getZoom() };
				} catch (_) { return null; }
			},
			mapsApplyPendingView() {
				const v = this.mapsPendingView;
				if (!v || !this.mapsLeafletMap || !Array.isArray(v.center) || v.center.length !== 2) return;
				this.mapsPendingView = null;
				try { this.mapsLeafletMap.setView(v.center, Number.isFinite(Number(v.zoom)) ? Number(v.zoom) : this.mapsLeafletMap.getZoom(), { animate: false }); } catch (_) {}
			},
			async renderMapsRegionsChoropleth() {
				const L = this.ensureLeafletMapBase();
				if (!L) return;
				const run = this.currentRegionRun();
				const def = this.mapsCurrentBoundaryDefinition();
				if (!run || !Array.isArray(run.rows) || !run.rows.length || !def) return;
				const aliasMap = this.mapsBoundaryAliasMap(def);
				const geojson = await this.mapsEnsureBoundaryData(def);
				const features = geojson && Array.isArray(geojson.features) ? geojson.features : [];
				if (!features.length) { this.mapsBoundaryError = 'Boundary dataset has no features'; return; }
				if (this.mapsLeafletClusterLayer && typeof this.mapsLeafletMap.removeLayer === 'function') {
					try { this.mapsLeafletMap.removeLayer(this.mapsLeafletClusterLayer); } catch (_) {}
				}
				this.mapsLeafletClusterLayer = null;
				const scoreByNorm = new Map();
				const rowByNorm = new Map();
				for (let i = 0; i < run.rows.length; i += 1) {
					const row = run.rows[i];
					const norm = this.mapsCanonicalRegionName(row && row.label, aliasMap);
					if (!norm) continue;
					const score = this.regionMetricValue(row);
					scoreByNorm.set(norm, score);
					rowByNorm.set(norm, row);
				}
				const matchedNorms = new Set();
				const legendColors = this.mapsRegionLegendColors();
				if (this.mapsBoundaryLayer && this.mapsLeafletMap && typeof this.mapsLeafletMap.removeLayer === 'function') {
					try { this.mapsLeafletMap.removeLayer(this.mapsBoundaryLayer); } catch (_) {}
				}
				if (this.mapsRegionDonutLayer && this.mapsLeafletMap && typeof this.mapsLeafletMap.removeLayer === 'function') {
					try { this.mapsLeafletMap.removeLayer(this.mapsRegionDonutLayer); } catch (_) {}
				}
				this.mapsRegionDonutLayer = null;
				this.mapsBoundaryLayer = L.geoJSON(geojson, {
					renderer: this.mapsLeafletCanvasRenderer || undefined,
					style: (feature) => {
						const props = feature && feature.properties ? feature.properties : {};
						const name = this.mapsFeatureNameFromProperties(props, def.nameProperty);
						const normName = this.mapsCanonicalRegionName(name, aliasMap);
						const val = scoreByNorm.get(normName);
						if (scoreByNorm.has(normName)) matchedNorms.add(normName);
						const has = Number.isFinite(Number(val));
						const bucket = has ? this.mapsRegionLegendIndex(Number(val)) : -1;
						const breaks = this.mapsRegionLegendBreaks();
						const bMin = Number(breaks[0]);
						const bMax = Number(breaks[breaks.length - 1]);
						const ratio = has && Number.isFinite(bMin) && Number.isFinite(bMax) && Math.abs(bMax - bMin) > 1e-12
							? Math.max(0, Math.min(1, (Number(val) - bMin) / (bMax - bMin)))
							: 0;
						const colorMode = String(this.mapsRegionColorMode || 'stepped').trim().toLowerCase();
						return {
							weight: 1,
							color: '#64748b',
							fillColor: !has
								? '#FAFAF8'
								: (colorMode === 'continuous'
									? this.mapsRegionContinuousColor(ratio)
									: ((bucket >= 0 && legendColors[bucket]) ? legendColors[bucket] : '#FAFAF8')),
							fillOpacity: has ? 0.42 : 0.18,
						};
					},
					onEachFeature: (feature, lyr) => {
						const props = feature && feature.properties ? feature.properties : {};
						const name = this.mapsFeatureNameFromProperties(props, def.nameProperty) || '(unnamed)';
						const norm = this.mapsCanonicalRegionName(name, aliasMap);
						const row = rowByNorm.get(norm) || null;
						const val = scoreByNorm.get(norm);
						const showVal = Number.isFinite(Number(val)) ? Number(val) : 0;
						const metric = String(this.mapsRegionMetric || '').trim().toLowerCase();
						const unit = metric === 'pct' || metric === 'rf_pct' ? '%' : (metric === 'count' ? 'hits' : '');
						const valueText = Number.isFinite(showVal) ? (Math.round(showVal * 1000) / 1000).toLocaleString() : '0';
						const popupParts = [];
						popupParts.push(`<strong>${this.mapsEscapeHtml(name)}</strong>`);
						popupParts.push(`<div>${this.mapsEscapeHtml(this.regionMetricDisplayLabel(metric))}: ${this.mapsEscapeHtml(valueText)}${unit ? ` ${this.mapsEscapeHtml(unit)}` : ''}</div>`);
						if (row) {
							const count = Number(row.count || 0);
							const pct = Number(row.pctOfTotal || 0);
							const ipm = Number(row.ipm || 0);
							const rfPct = Number(row.rfPct || row.rf_pct || 0);
							const unexp = Number(row.unexpectedness || 0);
							popupParts.push(`<div>Hits: ${Number.isFinite(count) ? count.toLocaleString() : '0'}</div>`);
							popupParts.push(`<div>% of total: ${Number.isFinite(pct) ? this.mapsFormatMetricValue(pct) : '0'}%</div>`);
							if (Number.isFinite(ipm) && ipm !== 0) popupParts.push(`<div>IPM: ${this.mapsFormatMetricValue(ipm)}</div>`);
							if (Number.isFinite(rfPct) && rfPct !== 0) popupParts.push(`<div>Relative frequency: ${this.mapsFormatMetricValue(rfPct)}%</div>`);
							if (Number.isFinite(unexp) && unexp !== 0) popupParts.push(`<div>Unexpectedness: ${this.mapsFormatMetricValue(unexp)}</div>`);
							const compareNames = Array.isArray(this.mapsCompareQueries) ? this.mapsCompareQueries : [];
							for (let qi = 0; qi < compareNames.length; qi += 1) {
								const qn = compareNames[qi];
								const qc = Number(row.perQueryCounts && row.perQueryCounts[qn] != null ? row.perQueryCounts[qn] : 0);
								popupParts.push(`<div>${this.mapsEscapeHtml(qn)}: ${Number.isFinite(qc) ? qc.toLocaleString() : '0'}</div>`);
							}
						}
						lyr.bindPopup(popupParts.join(''));
					},
				});
				this.mapsBoundaryLayer.addTo(this.mapsLeafletMap);
				if (this.mapsHasCompareQueries()) {
					const donutLayer = L.layerGroup();
					this.mapsBoundaryLayer.eachLayer((lyr) => {
						try {
							if (!lyr || typeof lyr.getBounds !== 'function') return;
							const center = lyr.getBounds().getCenter();
							const feat = lyr.feature && lyr.feature.properties ? lyr.feature.properties : {};
							const nm = this.mapsFeatureNameFromProperties(feat, def.nameProperty);
							const norm = this.mapsCanonicalRegionName(nm, aliasMap);
							const row = rowByNorm.get(norm);
							if (!row) return;
							const total = Number(row.count || 0);
							if (!Number.isFinite(total) || total <= 0) return;
							const base = 12 + Math.min(18, Math.round(Math.sqrt(total)));
							const icon = L.divIcon({
								className: 'fc-maps-region-donut',
								html: `<div style="${this.mapsDonutCssForCounts(row.perQueryCounts || {}, base)}"></div>`,
								iconSize: [base, base],
								iconAnchor: [Math.round(base / 2), Math.round(base / 2)],
							});
							const mk = L.marker(center, { icon, interactive: false, keyboard: false });
							donutLayer.addLayer(mk);
						} catch (_) {}
					});
					donutLayer.addTo(this.mapsLeafletMap);
					this.mapsRegionDonutLayer = donutLayer;
				}
				try {
					const b = this.mapsBoundaryLayer.getBounds();
					if (b && typeof b.isValid === 'function' && b.isValid()) this.mapsLeafletMap.fitBounds(b, { padding: [18, 18], maxZoom: 9, animate: false });
				} catch (_) {}
				this.mapsApplyPendingView();
			},
			mapsChartPalette(count) { const n = Math.max(1, Number(count) || 1); return Array.from({ length: n }, (_, i) => `hsla(${Math.round((360 * i) / n)}, 65%, 55%, 0.78)`); },
			renderMapsChart() {
				this.destroyMapsChart();
				const Chart = typeof window !== 'undefined' ? window.Chart : null;
				const canvas = document.getElementById('advanced-freqs-maps-chart');
				if (!Chart || typeof Chart !== 'function' || !canvas || !this.mapsChartRows.length) return;
				const labels = this.mapsChartRows.map((r) => r.label);
				const data = this.mapsChartRows.map((r) => Number(r.count));
				const palette = this.mapsChartPalette(labels.length);
				const ctx = canvas.getContext('2d'); if (!ctx) return;
				const mode = String(this.mapsChartMode || 'bar').toLowerCase();
				const typ = mode === 'vbar' ? 'bar' : (mode === 'line' ? 'line' : (mode === 'polar' ? 'polarArea' : (mode === 'pie' || mode === 'doughnut' ? mode : 'bar')));
				let datasets = [];
				if (this.mapsHasCompareQueries() && (typ === 'bar' || typ === 'line')) {
					const qnames = this.mapsCompareQueries.slice();
					datasets = qnames.map((qn, qi) => {
						const color = this.mapsDonutSliceColor(qi, qnames.length);
						return {
							label: qn,
							data: this.mapsChartRows.map((r) => Number((r.perQueryCounts && r.perQueryCounts[qn]) || 0)),
							borderWidth: 1,
							backgroundColor: color,
							borderColor: color,
							fill: typ === 'line' ? false : undefined,
							tension: typ === 'line' ? 0.2 : undefined,
							pointRadius: typ === 'line' ? 3 : undefined,
						};
					});
				} else {
					datasets = [{ label: 'Hits', data, borderWidth: 1, backgroundColor: (typ === 'pie' || typ === 'doughnut' || typ === 'polarArea') ? palette : 'rgba(54, 162, 235, 0.5)', borderColor: (typ === 'pie' || typ === 'doughnut' || typ === 'polarArea') ? '#fff' : 'rgba(54, 162, 235, 1)', fill: typ === 'line' ? false : undefined, tension: typ === 'line' ? 0.2 : undefined, pointRadius: typ === 'line' ? 3 : undefined }];
				}
				this.mapsChartInstance = new Chart(ctx, {
					type: typ,
					data: { labels, datasets },
					options: {
						indexAxis: mode === 'bar' ? 'y' : 'x',
						responsive: true,
						maintainAspectRatio: false,
						plugins: { legend: { display: this.mapsHasCompareQueries() || (typ === 'pie' || typ === 'doughnut' || typ === 'polarArea' ? labels.length <= 18 : false), position: 'right' } },
						scales: (typ === 'bar' || typ === 'line')
							? { y: { beginAtZero: true, stacked: this.mapsHasCompareQueries() && typ === 'bar', ticks: { precision: 0 } }, x: { stacked: this.mapsHasCompareQueries() && typ === 'bar' } }
							: undefined,
					},
				});
			},
			syncMapsViz() {
				try {
					if (this.mapsVizMode === 'map') {
						this.destroyMapsChart();
						const run = () => {
							try {
								if (this.mapsMapMode === 'points') {
									this.renderMapsLeafletPoints();
								} else {
									this.renderMapsRegionsChoropleth().catch((e) => {
										this.mapsBoundaryError = e && e.message ? e.message : String(e);
									});
								}
							} catch (_) {}
						};
						if (typeof this.$nextTick === 'function') this.$nextTick(run); else queueMicrotask(run);
						setTimeout(() => {
							try {
								if (this.mapsLeafletMap && typeof this.mapsLeafletMap.invalidateSize === 'function') {
									this.mapsLeafletMap.invalidateSize();
								}
							} catch (_) {}
						}, 180);
						return;
					}
					if (this.mapsVizMode !== 'chart') { this.destroyMapsChart(); return; }
					const run = () => { try { this.renderMapsChart(); } catch (_) {} };
					if (typeof this.$nextTick === 'function') this.$nextTick(run); else queueMicrotask(run);
				} catch (_) {}
			},
			downloadMapsTableCSV() {
				const rows = this.mapsSortedDisplayRows();
				const esc = (s) => { const t = String(s ?? ''); return /[",\r\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t; };
				const total = rows.reduce((s, r) => s + (Number.isFinite(Number(r.count)) ? Number(r.count) : 0), 0);
				const compareNames = this.mapsHasCompareQueries() ? this.mapsCompareQueries.slice() : [];
				const lines = [['Region', 'Count', 'Pct of total'].concat(compareNames).join(',')];
				for (let i = 0; i < rows.length; i += 1) {
					const r = rows[i]; const c = Number(r.count); const pct = total > 0 && Number.isFinite(c) ? (100 * c) / total : '';
					const cols = [esc(r.label), esc(c), esc(pct === '' ? '' : String(Math.round(pct * 1000) / 1000))];
					for (let qi = 0; qi < compareNames.length; qi += 1) {
						const qn = compareNames[qi];
						cols.push(esc(Number((r.perQueryCounts && r.perQueryCounts[qn]) || 0)));
					}
					lines.push(cols.join(','));
				}
				const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
				const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'flexicorp-maps-frequency.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 2500);
			},
			async downloadMapsMapPNG() {
				const host = document.getElementById('advanced-freqs-maps-geomap-host');
				if (!host) {
					this.mapsError = 'Map container not found.';
					return;
				}
				const drawLegendOnCanvas = (canvas) => {
					if (!canvas || this.mapsMapMode !== 'regions' || !this.mapsRegionLegendSummary()) return;
					const colors = this.mapsRegionLegendColors();
					const labels = this.mapsRegionLegendLabels();
					if (!colors.length || labels.length < 2) return;
					const ctx = canvas.getContext('2d');
					if (!ctx) return;
					const boxW = 250;
					const boxH = 88;
					const margin = 14;
					const x = Math.max(0, canvas.width - boxW - margin);
					const y = Math.max(0, canvas.height - boxH - margin);
					ctx.save();
					ctx.fillStyle = 'rgba(255,255,255,0.94)';
					ctx.strokeStyle = '#cbd5e1';
					ctx.lineWidth = 1;
					ctx.beginPath();
					ctx.rect(x, y, boxW, boxH);
					ctx.fill();
					ctx.stroke();
					ctx.fillStyle = '#1f2937';
					ctx.font = '600 12px sans-serif';
					ctx.fillText(this.regionMetricDisplayLabel(this.mapsRegionMetric), x + 10, y + 16);
					const barX = x + 10;
					const barY = y + 24;
					const barW = 220;
					const barH = 12;
					const colorMode = String(this.mapsRegionColorMode || 'stepped').trim().toLowerCase();
					if (colorMode === 'continuous') {
						const grad = ctx.createLinearGradient(barX, barY, barX + barW, barY);
						const palette = this.mapsRegionLegendColors();
						if (palette.length > 1) {
							for (let i = 0; i < palette.length; i += 1) {
								const t = i / (palette.length - 1);
								grad.addColorStop(t, palette[i]);
							}
						} else {
							grad.addColorStop(0, this.mapsRegionContinuousColor(0));
							grad.addColorStop(1, this.mapsRegionContinuousColor(1));
						}
						ctx.fillStyle = grad;
						ctx.fillRect(barX, barY, barW, barH);
					} else {
						const segW = barW / colors.length;
						for (let i = 0; i < colors.length; i += 1) {
							ctx.fillStyle = colors[i];
							ctx.fillRect(barX + (i * segW), barY, segW, barH);
						}
					}
					ctx.strokeStyle = '#cbd5e1';
					ctx.strokeRect(barX, barY, barW, barH);
					ctx.font = '11px sans-serif';
					ctx.fillStyle = '#111827';
					const tickY = barY + 25;
					for (let i = 0; i < labels.length; i += 1) {
						const t = String(labels[i] || '');
						const tx = barX + (barW * (i / (labels.length - 1)));
						const tw = ctx.measureText(t).width;
						let drawX = tx - (tw / 2);
						if (i === 0) drawX = barX;
						if (i === labels.length - 1) drawX = barX + barW - tw;
						ctx.fillText(t, drawX, tickY);
					}
					const ndY = y + boxH - 12;
					ctx.fillStyle = '#FAFAF8';
					ctx.fillRect(x + 10, ndY - 10, 12, 12);
					ctx.strokeStyle = '#cbd5e1';
					ctx.strokeRect(x + 10, ndY - 10, 12, 12);
					ctx.fillStyle = '#111827';
					ctx.fillText('No data', x + 28, ndY);
					ctx.restore();
				};
				const exportLeafletImage = async () => {
					const leafletImage = typeof window !== 'undefined' ? window.leafletImage : null;
					if (!this.mapsLeafletMap || typeof leafletImage !== 'function') return false;
					const canvas = await new Promise((resolve, reject) => {
						try {
							leafletImage(this.mapsLeafletMap, (err, c) => {
								if (err || !c) reject(err || new Error('Leaflet export failed.'));
								else resolve(c);
							});
						} catch (e) { reject(e); }
					});
					drawLegendOnCanvas(canvas);
					await new Promise((resolve, reject) => {
						canvas.toBlob((blob) => {
							if (!blob) { reject(new Error('Could not encode PNG file.')); return; }
							const url = URL.createObjectURL(blob);
							const a = document.createElement('a');
							a.href = url;
							a.download = `flexicorp-map-${Date.now()}.png`;
							a.click();
							setTimeout(() => URL.revokeObjectURL(url), 3000);
							resolve();
						}, 'image/png');
					});
					return true;
				};
				const html2canvas = typeof window !== 'undefined' ? window.html2canvas : null;
				if (typeof html2canvas !== 'function') {
					this.mapsError = 'PNG export library not loaded yet. Please try again in a second.';
					return;
				}
				this.mapsError = '';
				try {
					if (this.mapsLeafletMap && typeof this.mapsLeafletMap.invalidateSize === 'function') {
						this.mapsLeafletMap.invalidateSize();
					}
					try {
						const ok = await exportLeafletImage();
						if (ok) return;
					} catch (_) {}
					const canvas = await html2canvas(host, {
						backgroundColor: '#ffffff',
						useCORS: true,
						allowTaint: false,
						scale: Math.max(1, Math.min(2, window.devicePixelRatio || 1)),
					});
					if (!canvas || typeof canvas.toBlob !== 'function') throw new Error('Could not render PNG canvas.');
					await new Promise((resolve, reject) => {
						canvas.toBlob((blob) => {
							if (!blob) { reject(new Error('Could not encode PNG file.')); return; }
							const url = URL.createObjectURL(blob);
							const a = document.createElement('a');
							a.href = url;
							a.download = `flexicorp-map-${Date.now()}.png`;
							a.click();
							setTimeout(() => URL.revokeObjectURL(url), 3000);
							resolve();
						}, 'image/png');
					});
				} catch (e) {
					this.mapsError = `PNG export failed: ${e && e.message ? e.message : String(e)}`;
				}
			},
		});
	}

	window.ttAdvancedMapsInstall = installAdvancedMaps;
	window.ttFlexicorpModuleInstallers = window.ttFlexicorpModuleInstallers || [];
	window.ttFlexicorpModuleInstallers.push(installAdvancedMaps);
	window.ttAdvancedFreqsModuleInstallers = window.ttAdvancedFreqsModuleInstallers || [];
	window.ttAdvancedFreqsModuleInstallers.push(installAdvancedMaps);
})();
