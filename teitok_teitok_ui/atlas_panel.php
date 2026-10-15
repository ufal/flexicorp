<?php
/**
 * The atlas page's markup (atlas.php; state and methods in atlas.js). A function, so the TEITOK
 * installer (which copies *.php to Sources/) ships it with the module.
 */

if ( ! function_exists( 'tt_atlas_panel_html' ) ) {
	function tt_atlas_panel_html() {
		return <<<'HTML'
<p class="tt-at-lead">Map how a word, a form or a feature varies across the corpus's places: choose a query
and the attribute that varies, group its values into variants, and see per place which variant is used.</p>
<div class="tt-at-error" x-show="error" x-text="error"></div>
<div class="tt-at-grid">
	<section class="tt-at-side">
		<label>Map
			<select class="form-control form-control-sm" x-model="mapKey" x-on:change="openMap(mapKey)">
				<option value="">— a new map —</option>
				<template x-if="corpusMaps.length"><optgroup label="The corpus's maps">
					<template x-for="m in corpusMaps" :key="'c' + m.key"><option :value="'corpus:' + m.key" x-text="m.name"></option></template>
				</optgroup></template>
				<template x-if="myMaps.length"><optgroup label="My maps">
					<template x-for="m in myMaps" :key="'m' + m.key"><option :value="'mine:' + m.key" x-text="m.name"></option></template>
				</optgroup></template>
			</select>
		</label>
		<label>Name <input type="text" class="form-control form-control-sm" x-model="v.name" maxlength="80" placeholder="nicht"></label>
		<label>Query <input type="text" class="form-control form-control-sm tt-at-mono" x-model="v.query" placeholder='[nform="nicht"]' x-on:keydown.enter.prevent="loadValues()"></label>
		<label>The attribute that varies
			<input type="text" class="form-control form-control-sm tt-at-mono" x-model="v.field" list="tt-at-fields" placeholder="form">
			<datalist id="tt-at-fields"><option value="form"><option value="nform"><option value="lemma"><option value="pos"><option value="ipa"></datalist>
		</label>
		<button type="button" class="btn btn-sm btn-primary" :disabled="busy || !v.query" x-on:click="valuesAction()" x-text="valuesLabel()"></button>

		<template x-if="showValues && valuesFresh() && values.length">
			<div>
				<h3>Values <small x-text="valuesNote()"></small></h3>
				<table class="tt-at-values">
					<tbody>
						<template x-for="row in values" :key="row[0]">
							<tr>
								<td class="tt-at-mono" x-text="row[0]"></td>
								<td class="n" x-text="row[1].toLocaleString()"></td>
								<td>
									<select x-on:change="assign(row[0], $event.target.value); $event.target.value = ''">
										<option value="" x-text="variantOf(row[0]) || '— other —'"></option>
										<template x-for="vv in v.variants" :key="'a' + vv[0]"><option :value="vv[0]" x-text="'→ ' + vv[0]"></option></template>
										<option value="__new" x-text="'→ a new variant ' + row[0]"></option>
									</select>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</template>

		<h3>Variants</h3>
		<p class="tt-at-note" x-show="!v.variants.length">Assign values to variants above (or add one here); values in no variant count as "other".</p>
		<template x-for="(vv, i) in v.variants" :key="i">
			<div class="tt-at-variant">
				<span class="tt-at-sw" :style="'background:' + colour(vv[0])"></span>
				<input type="text" class="form-control form-control-sm" x-model="vv[0]" maxlength="40" title="Variant name">
				<input type="text" class="form-control form-control-sm tt-at-mono" x-model="vv[1]" title="Pattern: the values of this variant (a regular expression for the whole value)">
				<button type="button" class="btn btn-sm btn-link" x-on:click="v.variants.splice(i, 1)" title="Remove">✕</button>
			</div>
		</template>
		<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="v.variants.push(['v' + (v.variants.length + 1), ''])">+ Variant</button>

		<div class="tt-at-row">
			<label>Places with at least <input type="number" min="1" class="form-control form-control-sm tt-at-small" x-model.number="minN"> hits</label>
		</div>
		<div class="tt-at-row">
			<label>Show <select class="form-control form-control-sm" x-model="view" x-on:change="changeView()">
				<template x-for="o in viewOptions()" :key="o.value"><option :value="o.value" x-text="o.label" :selected="o.value === view"></option></template>
			</select></label>
			<label x-show="viewColours()">Colour <select class="form-control form-control-sm" x-model="colourBy" x-on:change="draw()">
				<option value="">by the most frequent variant</option>
				<template x-for="vv in v.variants" :key="'c' + vv[0]"><option :value="vv[0]" x-text="'by the share of ' + vv[0]"></option></template>
			</select></label>
			<label x-show="canClip() && viewCuts()" title="Cut the areas at the corpus's outline (switch it off for places across the border)">
				<input type="checkbox" x-model="clip" x-on:change="draw()"> Cut at the border</label>
		</div>
		<button type="button" class="btn btn-sm btn-primary" :disabled="busy || !v.query || !v.variants.length" x-on:click="makeMap()">Map it</button>
		<span x-show="busy">Counting…</span>

		<div class="tt-at-actions" x-show="mapped.length">
			<span x-text="mapNote()"></span>
			<button type="button" class="btn btn-sm btn-link" x-on:click="download()" title="The map as shown, for a GIS (QGIS, R, Python)">GeoJSON</button>
			<button type="button" class="btn btn-sm btn-link" x-on:click="share()" title="A link that opens this map as it is now, zoomed to where it is now">Share</button>
		</div>
		<div class="tt-at-share" x-show="shareLink">
			<input type="text" class="form-control form-control-sm tt-at-mono" readonly :value="shareLink" x-on:focus="$event.target.select()">
			<small x-text="shareNote"></small>
		</div>
		<div class="tt-at-actions" x-show="boot.user">
			<button type="button" class="btn btn-sm btn-outline-primary" :disabled="busy || !v.name || !v.variants.length" x-on:click="save('mine')">Keep as my map</button>
			<button type="button" class="btn btn-sm btn-outline-primary" x-show="boot.admin" :disabled="busy || !v.name || !v.variants.length" x-on:click="save('corpus')">Add to the corpus's maps</button>
			<button type="button" class="btn btn-sm btn-link" x-show="mapKey" x-on:click="remove()">delete this map</button>
		</div>
	</section>
	<section class="tt-at-mapwrap">
		<div id="tt-at-map" class="tt-at-map"></div>
		<p class="tt-at-note" x-text="placesNote()"></p>
	</section>
</div>
HTML;
	}
}
