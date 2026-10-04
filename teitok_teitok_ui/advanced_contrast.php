<?php
/**
 * Contrast (named-query keyness) panels for Advanced host and main flexicorp Stats.
 */

if ( ! function_exists( 'tt_flexicorp_contrast_render_af_panel' ) ) {
	/**
	 * Advanced (AF) host: template bound to activeAdvancedModule() === contrast.
	 */
	function tt_flexicorp_contrast_render_af_panel() {
		ob_start();
		?>
		<template x-if="activeAdvancedModule() && activeAdvancedModule().id === 'contrast'">
			<div class="flexicorp-stats-subpanel">
				<h3 class="flexicorp-subtitle" x-text="activeAdvancedModule().label"></h3>
				<p class="flexicorp-panel-note">Uses active named queries from <strong>Search Scope</strong>. Keep at least two active named queries (e.g. <code>A</code>, <code>B</code>), then run <strong>Keyness</strong>. More contrast calculations can be added in this same panel.</p>
				<div>
					<div class="flexicorp-freq-setup" style="margin-bottom:0.5rem;">
						<div class="flexicorp-freq-setup__fields">
							<div class="flexicorp-form-row">
								<span class="flexicorp-form-row__label" id="af-contrast-field-group">Field(s)</span>
								<fieldset class="flexicorp-freq-field-checkboxes" aria-labelledby="af-contrast-field-group" x-init="afContrastNormalizeFieldsFromState()">
									<div class="flexicorp-freq-field-checkboxes__grid" role="group" aria-label="Select fields for keyness">
										<template x-for="opt in afContrastFieldOptions()" :key="'af-contrast-fld-' + opt.key">
											<label class="flexicorp-freq-field-cb">
												<input type="checkbox" :value="opt.key" :checked="afContrastIsFieldSelected(opt.key)" x-on:change="afContrastOnFieldToggle(opt.key, $event.target.checked)">
												<span class="flexicorp-freq-field-cb__text" x-text="opt.label"></span>
											</label>
										</template>
									</div>
								</fieldset>
							</div>
							<div class="flexicorp-form-row">
								<label for="af-contrast-points">Points</label>
								<select id="af-contrast-points" class="flexicorp-form-control flexicorp-form-control-sm" style="max-width:6rem;" x-model.number="afContrastVolcanoPointLimit">
									<template x-for="n in afContrastVolcanoPointOptions()" :key="'af-vpt-' + n">
										<option :value="n" x-text="n"></option>
									</template>
								</select>
							</div>
						</div>
						<div class="flexicorp-freq-setup__actions">
							<button type="button" class="btn btn-sm btn-primary flexicorp-freq-setup__run" x-on:click="submitAfKeynessRun()" :disabled="afContrastRunDisabled()" x-text="afKeynessLoading ? 'Running…' : 'Keyness'"></button>
							<span class="flexicorp-panel-note" x-show="afKeynessError" x-text="afKeynessError"></span>
						</div>
					</div>
					<p class="flexicorp-panel-note" style="margin-top:0.15rem;" x-show="afActiveNamedQueryIds().length">Active named queries: <code x-text="afActiveNamedQueryIds().join(', ')"></code></p>
						<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Contrast view" style="margin-bottom:0.6rem;">
							<span class="flexicorp-inline-label">View</span>
							<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': afContrastVizMode === 'table' }" :aria-pressed="afContrastVizMode === 'table' ? 'true' : 'false'" x-on:click="setAfContrastVizMode('table')">Table</button>
							<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': afContrastVizMode === 'hbar' }" :aria-pressed="afContrastVizMode === 'hbar' ? 'true' : 'false'" x-on:click="setAfContrastVizMode('hbar')">H-bar</button>
							<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': afContrastVizMode === 'volcano' }" :aria-pressed="afContrastVizMode === 'volcano' ? 'true' : 'false'" x-on:click="setAfContrastVizMode('volcano')">Volcano</button>
							<span class="flexicorp-inline-label" style="margin-left:0.5rem;">Metric</span>
							<select class="flexicorp-form-control flexicorp-form-control-sm" style="max-width:12rem;" x-model="afContrastMetric">
								<template x-for="opt in afContrastMetricOptions()" :key="'af-cm-' + opt.key">
									<option :value="opt.key" x-text="opt.label"></option>
								</template>
							</select>
						</div>
						<p class="flexicorp-panel-note" x-show="afContrastHasRows()">Metric: <strong x-text="afContrastMetricLabel()"></strong> · rows: <span x-text="afKeynessRows.length"></span></p>
						<p class="flexicorp-panel-note" x-show="!afContrastHasRows() && !afKeynessLoading">Run <strong>Keyness</strong> to populate this view.</p>
						<div x-show="afContrastVizMode === 'table' && afContrastHasRows()" style="border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;">
							<table class="flexicorp-table flexicorp-table--compact" style="margin:0;">
								<thead>
									<tr>
										<th>Item</th>
										<th style="text-align:right;" x-text="afContrastMetricLabel()"></th>
										<th style="text-align:right;">Focus</th>
										<th style="text-align:right;">Reference</th>
										<th style="text-align:right;">Direction</th>
									</tr>
								</thead>
								<tbody>
									<template x-for="row in afContrastRowsLimited(120)" :key="row.key">
										<tr>
											<td x-text="row.label"></td>
											<td style="text-align:right;" x-text="Number(afContrastMetricValue(row)).toFixed(4)"></td>
											<td style="text-align:right;" x-text="Number(row.focusFreq || 0).toLocaleString()"></td>
											<td style="text-align:right;" x-text="Number(row.refFreq || 0).toLocaleString()"></td>
											<td style="text-align:right;" x-text="row.effect || (Number(afContrastMetricValue(row)) >= 0 ? '+' : '-')"></td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
						<div x-show="afContrastVizMode === 'hbar' && afContrastHasRows()" style="border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;padding:0.5rem;">
							<template x-for="row in afContrastRowsLimited(80)" :key="'hb-' + row.key">
								<div style="display:grid;grid-template-columns:minmax(12rem,1fr) minmax(8rem,2fr) auto;align-items:center;gap:0.5rem;margin:0.2rem 0;">
									<div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="row.label" x-text="row.label"></div>
									<div style="height:0.8rem;background:#eef2f7;border-radius:999px;overflow:hidden;">
										<div :style="'height:100%;width:' + afContrastHbarFillWidth(afContrastMetricValue(row)) + ';background:' + (Number(afContrastMetricValue(row)) >= 0 ? '#2563eb' : '#dc2626') + ';'"></div>
									</div>
									<div style="font-family:ui-monospace,monospace;" x-text="Number(afContrastMetricValue(row)).toFixed(4)"></div>
								</div>
							</template>
						</div>
						<div x-show="afContrastVizMode === 'volcano' && afContrastHasRows()" style="position:relative;border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;padding:0.45rem;">
							<svg viewBox="0 0 760 280" width="100%" height="280" role="img" aria-label="Volcano plot" x-on:mousemove="afContrastOnVolcanoMouseMove($event)" x-on:mouseleave="afContrastHideTooltip()">
								<line x1="380" y1="16" x2="380" y2="250" stroke="#94a3b8" stroke-width="1"></line>
								<line x1="42" y1="250" x2="744" y2="250" stroke="#64748b" stroke-width="1"></line>
								<g x-html="afContrastVolcanoCirclesSvg()"></g>
								<text x="8" y="20" font-size="12" fill="#334155" x-text="'|' + afContrastMetricLabel() + '|'">|Metric|</text>
								<text x="318" y="272" font-size="12" fill="#334155">log ratio (A &lt; B)</text>
								<text x="410" y="272" font-size="12" fill="#334155">log ratio (A &gt; B)</text>
							</svg>
							<div x-show="afContrastTooltip && afContrastTooltip.visible" :style="'position:absolute;left:' + Number((afContrastTooltip && afContrastTooltip.left) || 0).toFixed(1) + 'px;top:' + Number((afContrastTooltip && afContrastTooltip.top) || 0).toFixed(1) + 'px;z-index:4;pointer-events:none;background:#0f172a;color:#fff;padding:0.3rem 0.45rem;border-radius:0.35rem;font-size:12px;line-height:1.25;max-width:240px;'" style="display:none;">
								<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="afContrastTooltip.label"></div>
								<div><span>log ratio:</span> <span x-text="Number(afContrastTooltip.logRatio || 0).toFixed(3)"></span></div>
								<div><span x-text="afContrastMetricLabel() + ':'"></span> <span x-text="Number(afContrastTooltip.metric || 0).toFixed(3)"></span></div>
							</div>
							<p class="flexicorp-panel-note" style="margin-top:0.35rem;">Volcano uses <strong>log ratio</strong> on X and absolute <strong x-text="afContrastMetricLabel()"></strong> on Y. Empty token buckets are excluded from volcano scaling.</p>
						</div>
				</div>
			</div>
		</template>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'tt_flexicorp_contrast_render_stats_panel' ) ) {
	/**
	 * Main flexicorp Stats subpanel (statsSubTab === contrast).
	 */
	function tt_flexicorp_contrast_render_stats_panel() {
		ob_start();
		?>
		<div class="flexicorp-stats-subpanel" x-show="statsSubTab === 'contrast'">
			<h3 class="flexicorp-subtitle">Contrast</h3>
			<p class="flexicorp-panel-note">Uses active named queries from <strong>Search scope</strong>. Keep at least two active named queries (e.g. <code>A</code>, <code>B</code>), then run <strong>Keyness</strong>.</p>
			<div>
				<div class="flexicorp-freq-setup" style="margin-bottom:0.5rem;">
					<div class="flexicorp-freq-setup__fields">
						<div class="flexicorp-form-row">
							<span class="flexicorp-form-row__label" id="fc-stats-contrast-field-group">Field(s)</span>
							<fieldset class="flexicorp-freq-field-checkboxes" aria-labelledby="fc-stats-contrast-field-group" x-init="afContrastNormalizeFieldsFromState()">
								<div class="flexicorp-freq-field-checkboxes__grid" role="group" aria-label="Select fields for keyness">
									<template x-for="opt in afContrastFieldOptions()" :key="'stats-contrast-fld-' + opt.key">
										<label class="flexicorp-freq-field-cb">
											<input type="checkbox" :value="opt.key" :checked="afContrastIsFieldSelected(opt.key)" x-on:change="afContrastOnFieldToggle(opt.key, $event.target.checked)">
											<span class="flexicorp-freq-field-cb__text" x-text="opt.label"></span>
										</label>
									</template>
								</div>
							</fieldset>
						</div>
						<div class="flexicorp-form-row">
							<label for="fc-stats-contrast-points">Points</label>
							<select id="fc-stats-contrast-points" class="flexicorp-form-control flexicorp-form-control-sm" style="max-width:6rem;" x-model.number="afContrastVolcanoPointLimit">
								<template x-for="n in afContrastVolcanoPointOptions()" :key="'st-vpt-' + n">
									<option :value="n" x-text="n"></option>
								</template>
							</select>
						</div>
					</div>
					<div class="flexicorp-freq-setup__actions">
						<button type="button" class="btn btn-sm btn-primary flexicorp-freq-setup__run" x-on:click="submitAfKeynessRun()" :disabled="afContrastRunDisabled()" x-text="afKeynessLoading ? 'Running…' : 'Keyness'"></button>
						<span class="flexicorp-panel-note" x-show="afKeynessError" x-text="afKeynessError"></span>
					</div>
				</div>
				<p class="flexicorp-panel-note" style="margin-top:0.15rem;" x-show="afActiveNamedQueryIds().length">Active named queries: <code x-text="afActiveNamedQueryIds().join(', ')"></code></p>
					<div class="flexicorp-freq-viz-toolbar flexicorp-freq-viz-toolbar--inline" role="group" aria-label="Contrast view" style="margin-bottom:0.6rem;">
						<span class="flexicorp-inline-label">View</span>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': afContrastVizMode === 'table' }" :aria-pressed="afContrastVizMode === 'table' ? 'true' : 'false'" x-on:click="setAfContrastVizMode('table')">Table</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': afContrastVizMode === 'hbar' }" :aria-pressed="afContrastVizMode === 'hbar' ? 'true' : 'false'" x-on:click="setAfContrastVizMode('hbar')">H-bar</button>
						<button type="button" class="flexicorp-seg-btn" :class="{ 'flexicorp-seg-btn--active': afContrastVizMode === 'volcano' }" :aria-pressed="afContrastVizMode === 'volcano' ? 'true' : 'false'" x-on:click="setAfContrastVizMode('volcano')">Volcano</button>
						<span class="flexicorp-inline-label" style="margin-left:0.5rem;">Metric</span>
						<select class="flexicorp-form-control flexicorp-form-control-sm" style="max-width:12rem;" x-model="afContrastMetric">
							<template x-for="opt in afContrastMetricOptions()" :key="'st-cm-' + opt.key">
								<option :value="opt.key" x-text="opt.label"></option>
							</template>
						</select>
					</div>
					<p class="flexicorp-panel-note" x-show="afContrastHasRows()">Metric: <strong x-text="afContrastMetricLabel()"></strong> · rows: <span x-text="afKeynessRows.length"></span></p>
					<p class="flexicorp-panel-note" x-show="!afContrastHasRows() && !afKeynessLoading">Run <strong>Keyness</strong> to populate this view.</p>
					<div x-show="afContrastVizMode === 'table' && afContrastHasRows()" style="border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;">
						<table class="flexicorp-table flexicorp-table--compact" style="margin:0;">
							<thead>
								<tr>
									<th>Item</th>
									<th style="text-align:right;" x-text="afContrastMetricLabel()"></th>
									<th style="text-align:right;">Focus</th>
									<th style="text-align:right;">Reference</th>
									<th style="text-align:right;">Direction</th>
								</tr>
							</thead>
							<tbody>
								<template x-for="row in afContrastRowsLimited(120)" :key="row.key">
									<tr>
										<td x-text="row.label"></td>
										<td style="text-align:right;" x-text="Number(afContrastMetricValue(row)).toFixed(4)"></td>
										<td style="text-align:right;" x-text="Number(row.focusFreq || 0).toLocaleString()"></td>
										<td style="text-align:right;" x-text="Number(row.refFreq || 0).toLocaleString()"></td>
										<td style="text-align:right;" x-text="row.effect || (Number(afContrastMetricValue(row)) >= 0 ? '+' : '-')"></td>
									</tr>
								</template>
							</tbody>
						</table>
					</div>
					<div x-show="afContrastVizMode === 'hbar' && afContrastHasRows()" style="border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;padding:0.5rem;">
						<template x-for="row in afContrastRowsLimited(80)" :key="'hb-' + row.key">
							<div style="display:grid;grid-template-columns:minmax(12rem,1fr) minmax(8rem,2fr) auto;align-items:center;gap:0.5rem;margin:0.2rem 0;">
								<div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="row.label" x-text="row.label"></div>
								<div style="height:0.8rem;background:#eef2f7;border-radius:999px;overflow:hidden;">
									<div :style="'height:100%;width:' + afContrastHbarFillWidth(afContrastMetricValue(row)) + ';background:' + (Number(afContrastMetricValue(row)) >= 0 ? '#2563eb' : '#dc2626') + ';'"></div>
								</div>
								<div style="font-family:ui-monospace,monospace;" x-text="Number(afContrastMetricValue(row)).toFixed(4)"></div>
							</div>
						</template>
					</div>
					<div x-show="afContrastVizMode === 'volcano' && afContrastHasRows()" style="position:relative;border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;padding:0.45rem;">
						<svg viewBox="0 0 760 280" width="100%" height="280" role="img" aria-label="Volcano plot" x-on:mousemove="afContrastOnVolcanoMouseMove($event)" x-on:mouseleave="afContrastHideTooltip()">
							<line x1="380" y1="16" x2="380" y2="250" stroke="#94a3b8" stroke-width="1"></line>
							<line x1="42" y1="250" x2="744" y2="250" stroke="#64748b" stroke-width="1"></line>
							<g x-html="afContrastVolcanoCirclesSvg()"></g>
							<text x="8" y="20" font-size="12" fill="#334155" x-text="'|' + afContrastMetricLabel() + '|'">|Metric|</text>
							<text x="318" y="272" font-size="12" fill="#334155">log ratio (A &lt; B)</text>
							<text x="410" y="272" font-size="12" fill="#334155">log ratio (A &gt; B)</text>
						</svg>
						<div x-show="afContrastTooltip && afContrastTooltip.visible" :style="'position:absolute;left:' + Number((afContrastTooltip && afContrastTooltip.left) || 0).toFixed(1) + 'px;top:' + Number((afContrastTooltip && afContrastTooltip.top) || 0).toFixed(1) + 'px;z-index:4;pointer-events:none;background:#0f172a;color:#fff;padding:0.3rem 0.45rem;border-radius:0.35rem;font-size:12px;line-height:1.25;max-width:240px;'" style="display:none;">
							<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="afContrastTooltip.label"></div>
							<div><span>log ratio:</span> <span x-text="Number(afContrastTooltip.logRatio || 0).toFixed(3)"></span></div>
							<div><span x-text="afContrastMetricLabel() + ':'"></span> <span x-text="Number(afContrastTooltip.metric || 0).toFixed(3)"></span></div>
						</div>
						<p class="flexicorp-panel-note" style="margin-top:0.35rem;">Volcano uses <strong>log ratio</strong> on X and absolute <strong x-text="afContrastMetricLabel()"></strong> on Y. Empty token buckets are excluded from volcano scaling.</p>
					</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
