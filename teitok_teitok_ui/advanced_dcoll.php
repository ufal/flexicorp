<?php
/**
 * Advanced dependency-collocation panel for flexicorp Stats host.
 */

if ( ! function_exists( 'tt_flexicorp_adv_dcoll_render_stats_panel' ) ) {
	function tt_flexicorp_adv_dcoll_render_stats_panel() {
		ob_start();
		?>
		<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'advanced_dcoll'">
			<h3 class="flexicorp-subtitle">Dependencies</h3>
			<p class="flexicorp-panel-note">Dependency collocations over the active Search scope (Pando, dependency-enabled corpora). For multi-token or aligned queries, pick which named token the dependency step is anchored on.</p>
			<form class="flexicorp-form" x-on:submit.prevent="submitDcollAdvRun()">
				<div class="flexicorp-form-grid">
					<div class="flexicorp-form-row">
						<label for="fc-dcoll-anchor-token">Count on token</label>
						<select id="fc-dcoll-anchor-token" class="flexicorp-form-control flexicorp-form-control-sm" x-model="dcollAdv.anchorToken">
							<option value="">(unspecified — only safe for a single token span)</option>
							<template x-for="tok in collocationTokenAliasOptions()" :key="'dcoll-anch-' + tok">
								<option :value="tok" :selected="String(tok) === String(dcollAdv.anchorToken)" x-text="tok"></option>
							</template>
						</select>
					</div>
					<div class="flexicorp-form-row">
						<label for="fc-dcoll-field">Field</label>
						<select id="fc-dcoll-field" class="flexicorp-form-control flexicorp-form-control-sm" x-model="dcollAdv.field">
							<template x-for="opt in dcollAdvFieldOptions()" :key="'dcoll-field-' + opt.key">
								<option :value="opt.key" :selected="String(opt.key) === String(dcollAdv.field)" x-text="opt.label"></option>
							</template>
						</select>
					</div>
					<div class="flexicorp-form-row">
						<label for="fc-dcoll-relation">Relation</label>
						<select id="fc-dcoll-relation" class="flexicorp-form-control flexicorp-form-control-sm" x-model="dcollAdv.relation">
							<template x-for="opt in dcollAdvRelationOptions()" :key="'dcoll-rel-' + opt.key">
								<option :value="opt.key" :selected="String(opt.key) === String(dcollAdv.relation)" x-text="opt.label"></option>
							</template>
						</select>
					</div>
					<div class="flexicorp-form-row">
						<label for="fc-dcoll-breakdown" title="For each collocate, how often it came with each value of this attribute (e.g. its dependency relation or word class)">Show also</label>
						<select id="fc-dcoll-breakdown" class="flexicorp-form-control flexicorp-form-control-sm" x-model="dcollAdv.breakdown">
							<template x-for="opt in dcollAdvBreakdownOptions()" :key="'dcoll-bd-' + opt.key">
								<option :value="opt.key" :selected="String(opt.key) === String(dcollAdv.breakdown)" x-text="opt.label"></option>
							</template>
						</select>
					</div>
					<div class="flexicorp-form-row">
						<label for="fc-dcoll-minfreq">Min. frequency</label>
						<input id="fc-dcoll-minfreq" type="number" min="1" step="1" class="flexicorp-form-control flexicorp-form-control-sm" x-model.number="dcollAdv.minFreq">
					</div>
					<div class="flexicorp-form-row">
						<label for="fc-dcoll-maxitems">Max. items</label>
						<input id="fc-dcoll-maxitems" type="number" min="1" step="1" class="flexicorp-form-control flexicorp-form-control-sm" x-model.number="dcollAdv.maxItems">
					</div>
					<div class="flexicorp-form-row">
						<label for="fc-dcoll-stoplist">Stoplist (top N)</label>
						<input id="fc-dcoll-stoplist" type="number" min="0" step="1" class="flexicorp-form-control flexicorp-form-control-sm" x-model.number="dcollAdv.stoplist">
					</div>
					<div class="flexicorp-form-row flexicorp-form-row--coll-measures">
						<span class="flexicorp-form-row__label">Association measures</span>
						<div class="flexicorp-coll-measure-checks" role="group" aria-label="Association measures">
							<template x-for="mid in dcollAdvMeasureOptions()" :key="'dcoll-mk-' + mid.key">
								<label class="flexicorp-measure-check">
									<input type="checkbox" :value="mid.key" x-model="dcollAdv.measureKeys" x-on:change="onDcollAdvMeasureSelectionChanged()">
									<span x-text="mid.label"></span>
								</label>
							</template>
						</div>
					</div>
				</div>
				<div class="flexicorp-form-actions">
					<button type="button" class="btn btn-sm btn-primary" x-on:click="submitDcollAdvRun()" :disabled="dcollAdvLoading || !statsSearchHasHits()" x-text="dcollAdvLoading ? 'Running…' : 'Run dependencies'"></button>
					<span class="flexicorp-panel-note" x-show="dcollAdvError" x-text="dcollAdvError"></span>
				</div>
			</form>

			<div x-show="dcollAdv.ran && Array.isArray(dcollAdv.rows) && dcollAdv.rows.length">
				<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="D-coll view" style="margin-bottom:0.45rem;">
					<span class="flexicorp-inline-label">View</span>
					<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': dcollAdvVizMode === 'table' }" x-on:click="setDcollAdvVizMode('table')">Table</button>
					<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': dcollAdvVizMode === 'bar' }" x-on:click="setDcollAdvVizMode('bar')">H-bar</button>
					<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': dcollAdvVizMode === 'line' }" x-on:click="setDcollAdvVizMode('line')">Line</button>
					<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': dcollAdvVizMode === 'polar' }" x-on:click="setDcollAdvVizMode('polar')">Polar</button>
					<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': dcollAdvVizMode === 'pie' }" x-on:click="setDcollAdvVizMode('pie')">Pie</button>
					<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': dcollAdvVizMode === 'doughnut' }" x-on:click="setDcollAdvVizMode('doughnut')">Donut</button>
				</div>
				<div class="flexicorp-freq-viz-toolbar flexicorp-freq-export-toolbar flexicorp-freq-viz-toolbar--inline" x-show="dcollAdvVizMode !== 'table' && frequencyChartLibraryAvailable()" style="margin-bottom:0.5rem;">
					<span class="flexicorp-inline-label">Metric</span>
					<select class="flexicorp-form-control flexicorp-form-control-sm" x-model="dcollAdvChartMetricKey" x-on:change="onDcollAdvChartMetricChanged()">
						<template x-for="opt in dcollAdvChartMetricOptions()" :key="'dcoll-metric-' + opt.key">
							<option :value="opt.key" :selected="String(opt.key) === String(dcollAdvChartMetricKey)" x-text="opt.label"></option>
						</template>
					</select>
				</div>

				<table class="flexicorp-table" x-show="dcollAdvVizMode === 'table'">
					<thead>
						<tr>
							<th>Word</th>
							<th>Obs</th>
							<th>Freq</th>
							<template x-for="mk in dcollAdvMeasureKeys()" :key="'dcoll-h-' + mk">
								<th x-text="mk"></th>
							</template>
							<th x-show="dcollAdvBreakdownShown()" x-text="(dcollAdvBreakdownOptions().find((o) => o.key === dcollAdvBreakdownShown()) || { label: dcollAdvBreakdownShown() }).label"></th>
						</tr>
					</thead>
					<tbody>
						<template x-for="(row, idx) in dcollAdv.rows" :key="'dcoll-r-' + idx">
							<tr>
								<td x-text="row.word != null ? row.word : ''"></td>
								<td x-text="row.obs != null ? row.obs : ''"></td>
								<td x-text="row.freq != null ? row.freq : ''"></td>
								<template x-for="mk in dcollAdvMeasureKeys()" :key="'dcoll-c-' + idx + '-' + mk">
									<td x-text="row[mk] != null ? row[mk] : ''"></td>
								</template>
								<td x-show="dcollAdvBreakdownShown()" class="flexicorp-dcoll-breakdown" x-text="dcollAdvBreakdownText(row)"></td>
							</tr>
						</template>
					</tbody>
				</table>
				<div class="flexicorp-freq-chart-wrap" x-show="dcollAdvVizMode !== 'table' && frequencyChartLibraryAvailable()">
					<canvas id="flexicorp-dcoll-adv-chart-canvas" aria-label="Dependency collocation chart"></canvas>
				</div>
			</div>
			<p class="flexicorp-panel-note" x-show="dcollAdv.ran && (!Array.isArray(dcollAdv.rows) || !dcollAdv.rows.length)">No dependency collocates returned.</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

