<?php
/**
 * Advanced Maps module view + assets for advanced_freqs host.
 */

if ( ! function_exists( 'tt_flexicorp_adv_maps_render_panel' ) ) {
	function tt_flexicorp_adv_maps_render_panel() {
		ob_start();
		?>
		<div class="flexicorp-stats-subpanel" x-show="advancedSubTab === 'maps' && capabilities.hasGeo">
			<h3 class="flexicorp-subtitle">Geolocation mapping</h3>
			<div class="advanced-freqs-maps-viz" style="margin-top:1rem;">
				<div class="flexicorp-freq-viz-wrap">
					<div class="flexicorp-freq-setup__viz">
						<div class="flexicorp-freq-setup__viz-row flexicorp-freq-setup__viz-row--primary">
							<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Maps view">
								<span class="flexicorp-inline-label">View</span>
								<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsVizMode === 'table' }" :aria-pressed="mapsVizMode === 'table' ? 'true' : 'false'" x-on:click="setMapsVizMode('table')">Table</button>
								<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsVizMode === 'map' }" :aria-pressed="mapsVizMode === 'map' ? 'true' : 'false'" x-on:click="setMapsVizMode('map')">Map</button>
								<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsVizMode === 'chart' }" :aria-pressed="mapsVizMode === 'chart' ? 'true' : 'false'" x-on:click="setMapsVizMode('chart')">Chart</button>
							</div>
							<div class="flexicorp-freq-viz-toolbar flexicorp-freq-export-toolbar flexicorp-freq-viz-toolbar--inline" x-show="mapsVizMode === 'table' || mapsVizMode === 'map'">
								<span class="flexicorp-inline-label">Export</span>
								<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadMapsTableCSV()" title="Download region counts as CSV">CSV</button>
								<button type="button" class="btn btn-sm btn-outline-secondary" x-show="mapsVizMode === 'map'" x-on:click="downloadMapsMapPNG()" title="Download current map as PNG">PNG</button>
							</div>
						</div>
					</div>
				</div>
				<div class="advanced-freqs-maps-map-shell" x-show="mapsVizMode === 'map'" style="margin-top:0.75rem;">
					<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Map type" style="margin-bottom:0.5rem;">
						<span class="flexicorp-inline-label">Map type</span>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsMapMode === 'points' }" :aria-pressed="mapsMapMode === 'points' ? 'true' : 'false'" x-on:click="setMapsMapMode('points')">Points</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsMapMode === 'regions' }" :aria-pressed="mapsMapMode === 'regions' ? 'true' : 'false'" x-on:click="setMapsMapMode('regions')">Regions</button>
						<span class="flexicorp-panel-note" style="margin-left:0.5rem;display:inline;" x-show="mapsLoading">Loading map data...</span>
					</div>
					<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Point visualization" x-show="mapsMapMode === 'points'" style="margin-bottom:0.5rem;">
						<span class="flexicorp-inline-label">Points</span>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsPointVizMode === 'cluster' }" :aria-pressed="mapsPointVizMode === 'cluster' ? 'true' : 'false'" x-on:click="setMapsPointVizMode('cluster')">Cluster</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsPointVizMode === 'intensity' }" :aria-pressed="mapsPointVizMode === 'intensity' ? 'true' : 'false'" x-on:click="setMapsPointVizMode('intensity')">Intensity</button>
					</div>
					<div
						x-bind:style="(mapsMapMode === 'regions' || mapsConfiguredAreas().length)
							? 'display:flex;flex-wrap:nowrap;justify-content:flex-start;gap:0.5rem 0.9rem;align-items:center;margin-bottom:0.5rem;overflow-x:auto;overflow-y:hidden;padding-bottom:0.15rem;'
							: 'display:none;'">
						<div class="flexicorp-form-row" x-show="mapsMapMode === 'regions' && mapsBoundaryDefinitions().length > 1" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Boundaries</label>
							<select class="form-control form-control-sm" style="max-width:20rem;" x-model="mapsBoundaryDatasetKey" x-on:change="setMapsBoundaryDatasetKey($event.target.value)">
								<template x-for="ds in mapsBoundaryDefinitions()" :key="'boundary-' + ds.key">
									<option :value="ds.key" x-text="ds.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsMapMode === 'regions'" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Metric</label>
							<select class="form-control form-control-sm" style="max-width:14rem;" x-model="mapsRegionMetric" x-on:change="syncMapsViz()">
								<template x-for="opt in regionMetricOptions()" :key="'metric-' + opt.key">
									<option :value="opt.key" x-text="opt.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsHasCompareQueries()" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Compare scale</label>
							<select class="form-control form-control-sm" style="max-width:16rem;" x-model="mapsCompareScaleMode" x-on:change="syncMapsViz()">
								<template x-for="opt in mapsCompareScaleOptions()" :key="'cmp-scale-' + opt.key">
									<option :value="opt.key" x-text="opt.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsMapMode === 'regions'" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Colors</label>
							<select class="form-control form-control-sm" style="max-width:16rem;" x-model="mapsRegionColorMode" x-on:change="syncMapsViz()">
								<template x-for="opt in mapsRegionColorModeOptions()" :key="'colormode-' + opt.key">
									<option :value="opt.key" x-text="opt.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsConfiguredAreas().length" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Area</label>
							<select class="form-control form-control-sm" style="max-width:18rem;" x-model="mapsSelectedAreaKey" x-on:change="setMapsAreaByKey($event.target.value)">
							<option value="__default__">Default view</option>
							<option value="__fit__">Zoom to fit</option>
							<template x-for="area in mapsConfiguredAreas()" :key="'area-' + (area.key || area.display || '')">
								<option :value="area.key || ''" x-text="area.display || area.key || 'Area'"></option>
							</template>
							</select>
						</div>
						<span class="flexicorp-panel-note" style="display:inline;" x-show="mapsMapMode === 'regions' && mapsBoundaryLoading">Loading boundaries...</span>
					</div>
					<p class="flexicorp-panel-note" style="margin-top:0.35rem;" x-show="mapsPointRows.length">
						Point rows: <span x-text="mapsPointRows.length"></span> · point field: <span x-text="mapsPointField || resolvedGeoPointField()"></span>
						<span x-show="mapsMapMode === 'points'"> · limit: <span x-text="mapsPointLimit"></span></span>
						<span x-show="mapsFreqMetaLine()"> · <span x-text="mapsFreqMetaLine()"></span></span>
					</p>
					<p class="flexicorp-panel-note advanced-freqs-maps-error" style="margin-top:0.35rem;color:#a40000;" x-show="mapsError" x-text="mapsError"></p>
					<p class="flexicorp-panel-note advanced-freqs-maps-error" style="margin-top:0.35rem;color:#a40000;" x-show="mapsBoundaryError" x-text="mapsBoundaryError"></p>
					<div style="position:relative;">
						<div id="advanced-freqs-maps-geomap-host" class="advanced-freqs-maps-geomap-host" style="width:100%;aspect-ratio:1.41421356 / 1;min-height:320px;max-height:72vh;background:#f6f7f9;border:1px dashed #c5cad3;border-radius:4px;display:flex;align-items:center;justify-content:center;text-align:center;padding:1rem;" role="region" aria-label="Geographic map placeholder"></div>
						<div x-show="mapsMapMode === 'regions' && mapsRegionLegendSummary()" style="position:absolute;right:10px;bottom:10px;z-index:800;background:rgba(255,255,255,0.94);border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;box-shadow:0 2px 8px rgba(15,23,42,0.18);">
							<div class="flexicorp-panel-note" style="font-weight:600;margin:0;" x-text="regionMetricDisplayLabel(mapsRegionMetric)"></div>
							<div x-bind:style="mapsRegionColorMode === 'stepped' ? 'display:flex;width:220px;height:12px;border:1px solid #ccc;margin:6px 0;' : 'display:none;'">
								<template x-for="(clr, idx) in mapsRegionLegendColors()" :key="'legend-step-' + idx">
									<div style="flex:1;" x-bind:style="'flex:1;background:' + clr + ';'"></div>
								</template>
							</div>
							<div x-show="mapsRegionColorMode === 'continuous'" x-bind:style="mapsRegionLegendContinuousStyle()"></div>
							<div style="display:flex;justify-content:space-between;width:220px;font-size:11px;">
								<template x-for="lbl in mapsRegionLegendLabels()" :key="'legend-lbl-' + lbl">
									<span x-text="lbl"></span>
								</template>
							</div>
							<div style="margin-top:6px; font-size:11px;">
								<span style="display:inline-block;width:12px;height:12px;background:#FAFAF8;border:1px solid #ccc;margin-right:5px;"></span>
								No data
							</div>
						</div>
					</div>
				</div>
				<div class="advanced-freqs-maps-chart-wrap" x-show="mapsVizMode === 'chart' && mapsChartRows.length" style="margin-top:0.75rem;">
					<canvas id="advanced-freqs-maps-chart" height="280" aria-label="Regional distribution chart"></canvas>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'tt_flexicorp_adv_maps_render_stats_panel' ) ) {
	/**
	 * Maps UI embedded in main flexicorp Stats (Quantitative analysis) — same Alpine helpers as AF.
	 */
		function tt_flexicorp_adv_maps_render_stats_panel() {
		ob_start();
		?>
		<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'maps'" x-init="if (typeof initMapsModule === 'function') initMapsModule(null); if (mapsMapMode === 'regions' && typeof ensureMapsRegionData === 'function') ensureMapsRegionData(); if (mapsMapMode !== 'regions' && typeof ensureMapsPointsData === 'function') ensureMapsPointsData();">
			<h3 class="flexicorp-subtitle">Geolocation mapping</h3>
			<div class="advanced-freqs-maps-viz" style="margin-top:1rem;">
				<div class="flexicorp-freq-viz-wrap">
					<div class="flexicorp-freq-setup__viz">
						<div class="flexicorp-freq-setup__viz-row flexicorp-freq-setup__viz-row--primary">
							<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Maps view">
								<span class="flexicorp-inline-label">View</span>
								<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsVizMode === 'table' }" :aria-pressed="mapsVizMode === 'table' ? 'true' : 'false'" x-on:click="setMapsVizMode('table')">Table</button>
								<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsVizMode === 'map' }" :aria-pressed="mapsVizMode === 'map' ? 'true' : 'false'" x-on:click="setMapsVizMode('map')">Map</button>
								<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsVizMode === 'chart' }" :aria-pressed="mapsVizMode === 'chart' ? 'true' : 'false'" x-on:click="setMapsVizMode('chart')">Chart</button>
							</div>
							<div class="flexicorp-freq-viz-toolbar flexicorp-freq-export-toolbar flexicorp-freq-viz-toolbar--inline" x-show="mapsVizMode === 'table' || mapsVizMode === 'map'">
								<span class="flexicorp-inline-label">Export</span>
								<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="downloadMapsTableCSV()" title="Download region counts as CSV">CSV</button>
								<button type="button" class="btn btn-sm btn-outline-secondary" x-show="mapsVizMode === 'map'" x-on:click="downloadMapsMapPNG()" title="Download current map as PNG">PNG</button>
							</div>
						</div>
					</div>
				</div>
				<div class="advanced-freqs-maps-map-shell" x-show="mapsVizMode === 'map'" style="margin-top:0.75rem;">
					<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Map type" style="margin-bottom:0.5rem;">
						<span class="flexicorp-inline-label">Map type</span>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsMapMode === 'points' }" :aria-pressed="mapsMapMode === 'points' ? 'true' : 'false'" x-on:click="setMapsMapMode('points')">Points</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsMapMode === 'regions' }" :aria-pressed="mapsMapMode === 'regions' ? 'true' : 'false'" x-on:click="setMapsMapMode('regions')">Regions</button>
						<span class="flexicorp-panel-note" style="margin-left:0.5rem;display:inline;" x-show="mapsLoading">Loading map data...</span>
					</div>
					<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Point visualization" x-show="mapsMapMode === 'points'" style="margin-bottom:0.5rem;">
						<span class="flexicorp-inline-label">Points</span>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsPointVizMode === 'cluster' }" :aria-pressed="mapsPointVizMode === 'cluster' ? 'true' : 'false'" x-on:click="setMapsPointVizMode('cluster')">Cluster</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': mapsPointVizMode === 'intensity' }" :aria-pressed="mapsPointVizMode === 'intensity' ? 'true' : 'false'" x-on:click="setMapsPointVizMode('intensity')">Intensity</button>
					</div>
					<div
						x-bind:style="(mapsMapMode === 'regions' || mapsConfiguredAreas().length)
							? 'display:flex;flex-wrap:nowrap;justify-content:flex-start;gap:0.5rem 0.9rem;align-items:center;margin-bottom:0.5rem;overflow-x:auto;overflow-y:hidden;padding-bottom:0.15rem;'
							: 'display:none;'">
						<div class="flexicorp-form-row" x-show="mapsMapMode === 'regions' && mapsBoundaryDefinitions().length > 1" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Boundaries</label>
							<select class="form-control form-control-sm" style="max-width:20rem;" x-model="mapsBoundaryDatasetKey" x-on:change="setMapsBoundaryDatasetKey($event.target.value)">
								<template x-for="ds in mapsBoundaryDefinitions()" :key="'boundary-' + ds.key">
									<option :value="ds.key" x-text="ds.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsMapMode === 'regions'" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Metric</label>
							<select class="form-control form-control-sm" style="max-width:14rem;" x-model="mapsRegionMetric" x-on:change="syncMapsViz()">
								<template x-for="opt in regionMetricOptions()" :key="'metric-' + opt.key">
									<option :value="opt.key" x-text="opt.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsHasCompareQueries()" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Compare scale</label>
							<select class="form-control form-control-sm" style="max-width:16rem;" x-model="mapsCompareScaleMode" x-on:change="syncMapsViz()">
								<template x-for="opt in mapsCompareScaleOptions()" :key="'cmp-scale-' + opt.key">
									<option :value="opt.key" x-text="opt.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsMapMode === 'regions'" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Colors</label>
							<select class="form-control form-control-sm" style="max-width:16rem;" x-model="mapsRegionColorMode" x-on:change="syncMapsViz()">
								<template x-for="opt in mapsRegionColorModeOptions()" :key="'colormode-' + opt.key">
									<option :value="opt.key" x-text="opt.label"></option>
								</template>
							</select>
						</div>
						<div class="flexicorp-form-row" x-show="mapsConfiguredAreas().length" style="display:inline-flex;flex-direction:row;align-items:center;gap:0.4rem;margin:0;width:auto;max-width:none;flex:0 0 auto;">
							<label class="flexicorp-inline-label">Area</label>
							<select class="form-control form-control-sm" style="max-width:18rem;" x-model="mapsSelectedAreaKey" x-on:change="setMapsAreaByKey($event.target.value)">
							<option value="__default__">Default view</option>
							<option value="__fit__">Zoom to fit</option>
							<template x-for="area in mapsConfiguredAreas()" :key="'area-' + (area.key || area.display || '')">
								<option :value="area.key || ''" x-text="area.display || area.key || 'Area'"></option>
							</template>
							</select>
						</div>
						<span class="flexicorp-panel-note" style="display:inline;" x-show="mapsMapMode === 'regions' && mapsBoundaryLoading">Loading boundaries...</span>
					</div>
					<p class="flexicorp-panel-note" style="margin-top:0.35rem;" x-show="mapsPointRows.length">
						Point rows: <span x-text="mapsPointRows.length"></span> · point field: <span x-text="mapsPointField || resolvedGeoPointField()"></span>
						<span x-show="mapsMapMode === 'points'"> · limit: <span x-text="mapsPointLimit"></span></span>
						<span x-show="mapsFreqMetaLine()"> · <span x-text="mapsFreqMetaLine()"></span></span>
					</p>
					<p class="flexicorp-panel-note advanced-freqs-maps-error" style="margin-top:0.35rem;color:#a40000;" x-show="mapsError" x-text="mapsError"></p>
					<p class="flexicorp-panel-note advanced-freqs-maps-error" style="margin-top:0.35rem;color:#a40000;" x-show="mapsBoundaryError" x-text="mapsBoundaryError"></p>
					<div style="position:relative;">
						<div id="advanced-freqs-maps-geomap-host" class="advanced-freqs-maps-geomap-host" style="width:100%;aspect-ratio:1.41421356 / 1;min-height:320px;max-height:72vh;background:#f6f7f9;border:1px dashed #c5cad3;border-radius:4px;display:flex;align-items:center;justify-content:center;text-align:center;padding:1rem;" role="region" aria-label="Geographic map placeholder"></div>
						<div x-show="mapsMapMode === 'regions' && mapsRegionLegendSummary()" style="position:absolute;right:10px;bottom:10px;z-index:800;background:rgba(255,255,255,0.94);border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;box-shadow:0 2px 8px rgba(15,23,42,0.18);">
							<div class="flexicorp-panel-note" style="font-weight:600;margin:0;" x-text="regionMetricDisplayLabel(mapsRegionMetric)"></div>
							<div x-bind:style="mapsRegionColorMode === 'stepped' ? 'display:flex;width:220px;height:12px;border:1px solid #ccc;margin:6px 0;' : 'display:none;'">
								<template x-for="(clr, idx) in mapsRegionLegendColors()" :key="'legend-step-' + idx">
									<div style="flex:1;" x-bind:style="'flex:1;background:' + clr + ';'"></div>
								</template>
							</div>
							<div x-show="mapsRegionColorMode === 'continuous'" x-bind:style="mapsRegionLegendContinuousStyle()"></div>
							<div style="display:flex;justify-content:space-between;width:220px;font-size:11px;">
								<template x-for="lbl in mapsRegionLegendLabels()" :key="'legend-lbl-' + lbl">
									<span x-text="lbl"></span>
								</template>
							</div>
							<div style="margin-top:6px; font-size:11px;">
								<span style="display:inline-block;width:12px;height:12px;background:#FAFAF8;border:1px solid #ccc;margin-right:5px;"></span>
								No data
							</div>
						</div>
					</div>
				</div>
				<div class="advanced-freqs-maps-chart-wrap" x-show="mapsVizMode === 'chart' && mapsChartRows.length" style="margin-top:0.75rem;">
					<canvas id="advanced-freqs-maps-chart" height="280" aria-label="Regional distribution chart"></canvas>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'tt_flexicorp_adv_maps_print_assets' ) ) {
	/**
	 * @param bool $has_geo Whether geo fields exist for this corpus.
	 * @param bool $include_module_js When false, print Leaflet/Chart deps only (caller loads advanced_maps.js with cache-busting).
	 */
	function tt_flexicorp_adv_maps_print_assets( $has_geo, $include_module_js = true ) {
		if ( ! $has_geo ) {
			return;
		}
		$mapsJsUrl = tt_flexicorp_fn_asset_url( 'advanced_maps', 'js' );
		?>
		<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
		<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
		<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
		<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" defer></script>
		<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>
		<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" defer></script>
		<script src="https://unpkg.com/leaflet-image@0.4.0/leaflet-image.js" defer></script>
		<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js" defer></script>
		<?php if ( $include_module_js ) : ?>
		<script src="<?php echo htmlspecialchars( $mapsJsUrl, ENT_QUOTES, 'UTF-8' ); ?>" defer></script>
		<?php endif; ?>
		<?php
	}
}

