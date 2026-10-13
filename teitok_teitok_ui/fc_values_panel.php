<?php
/**
 * flexicorp TEITOK UI: the value list panel of the corpus overview (methods in fc_stats_values.js).
 * Shown under the table of the attribute whose vocabulary size was clicked.
 */

if ( !function_exists( 'tt_flexicorp_corpus_values_panel' ) ) {
	/** $regionExpr: Alpine expression for the table's region ("''" for token attributes, "grp.raw"). */
	function tt_flexicorp_corpus_values_panel( $regionExpr ) {
		$r = htmlspecialchars( $regionExpr, ENT_QUOTES, 'UTF-8' );
		return <<<HTML
<div class="flexicorp-corpus-values" x-show="corpusValuesShownFor({$r})" x-cloak>
	<div class="flexicorp-corpus-values__head">
		<strong x-text="corpusValues.label"></strong>
		<input type="search" class="form-control form-control-sm flexicorp-corpus-values__filter" placeholder="Filter values"
			x-model="corpusValues.filter" x-on:keydown.enter.prevent="corpusValuesComplete() || corpusValuesFilterAll()">
		<button type="button" class="btn btn-sm btn-outline-secondary" x-show="corpusValues.filter.trim() && !corpusValuesComplete() && corpusValues.serverFilter !== corpusValues.filter.trim()"
			x-on:click="corpusValuesFilterAll()">Search all values</button>
		<button type="button" class="btn btn-sm btn-outline-secondary" x-show="corpusValues.filter || corpusValues.serverFilter" x-on:click="corpusValuesClearFilter()">Clear</button>
		<button type="button" class="btn btn-sm btn-link" x-on:click="corpusValues.attr = ''">Close</button>
	</div>
	<p class="flexicorp-panel-note" x-show="corpusValues.loading">Loading values…</p>
	<div class="flexicorp-call-error" x-show="corpusValues.error" x-text="corpusValues.error"></div>
	<template x-if="!corpusValues.loading && !corpusValues.error">
		<div>
			<p class="flexicorp-panel-note" x-text="corpusValuesSummary() + ' Click a value to search for it.'"></p>
			<table class="flexicorp-table flexicorp-corpus-values__table">
				<thead><tr><th>Value</th><th>Count</th><th>Share</th></tr></thead>
				<tbody>
					<template x-for="(row, vi) in corpusValuesVisibleRows()" :key="'cv-' + vi + '-' + row.value">
						<tr>
							<td><button type="button" class="btn btn-link btn-sm p-0 flexicorp-corpus-values__value" x-on:click="corpusValuesSearch(row.value)"
								:title="'Search: ' + corpusValuesQueryFor(row.value)" x-text="row.value === '' ? '(empty)' : row.value"></button></td>
							<td x-text="row.count.toLocaleString()"></td>
							<td x-text="corpusValuesShare(row)"></td>
						</tr>
					</template>
				</tbody>
			</table>
			<p class="flexicorp-panel-note" x-show="!corpusValuesVisibleRows().length">No values match the filter.</p>
			<button type="button" class="btn btn-sm btn-outline-secondary" x-show="!corpusValuesComplete()" x-on:click="corpusValuesMore()">Show more values</button>
		</div>
	</template>
</div>
HTML;
	}
}
