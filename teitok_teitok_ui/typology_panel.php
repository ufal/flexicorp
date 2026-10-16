<?php
/**
 * typology.php's page body (Alpine markup for ttTypologyApp, typology.js): the features to compare,
 * the languages, the views (dendrogram, similarity space, similarity matrix, feature table) and the
 * panel for a language clicked.
 */

if ( ! function_exists( 'tt_typology_panel_html' ) ) {
	function tt_typology_panel_html() {
		return <<<'HTML'
<p class="tt-ty-intro">How similar are the languages of this corpus, and in what respect? Each language gets a
profile of grammatical properties counted from its annotation (word orders, verb-second, subject
pronouns, case and gender systems, inflection), as in the World Atlas of Language Structures (WALS).
Languages with similar profiles end up close together. Click a language for its nearest languages and
what sets it apart; click a cell in the table for the examples behind it.</p>
<div class="tt-ty-error" x-show="error" x-text="error"></div>
<div x-show="loading">Loading…</div>
<div class="tt-ty-layout" x-show="!loading && !error">
	<aside class="tt-ty-side">
		<h3>Features</h3>
		<template x-for="g in featureGroups()" :key="g.key">
			<div class="tt-ty-group">
				<div class="tt-ty-group-title" x-text="g.label"></div>
				<template x-for="f in g.features" :key="f.id">
					<label class="tt-ty-feat" :title="f.note">
						<input type="checkbox" :checked="isSelected(f.id)" @change="toggle(f.id)">
						<span x-text="f.title"></span>
						<small x-show="f.wals" x-text="'WALS ' + f.wals"></small>
						<small class="tt-ty-busy" x-show="status[f.id] === 'counting'">counting…</small>
						<small class="tt-ty-err" x-show="(status[f.id] || '').startsWith('error')" x-text="status[f.id]"></small>
						<small class="tt-ty-na" x-show="(status[f.id] || '').startsWith('n/a')" x-text="(status[f.id] || '').slice(5)"></small>
						<button type="button" class="tt-ty-recount" x-show="admin && hasData(f.id)" @click.prevent="recompute(f.id)" title="Count again (after the corpus changed)">↻</button>
					</label>
				</template>
			</div>
		</template>
		<h3>Languages</h3>
		<label class="tt-ty-field">Only these (codes)
			<input type="text" x-model.debounce.600ms="langFilter" @input.debounce.600ms="rebuild()" placeholder="all, e.g. en de nl ja">
		</label>
		<label class="tt-ty-field">At least <input type="number" min="1" max="30" x-model.number="minFeatures" @change="rebuild()"> features per language</label>
		<label class="tt-ty-field">At least <input type="number" min="1" x-model.number="minN" @change="rebuild()"> hits per feature</label>
		<label class="tt-ty-field">Groups <input type="number" min="1" max="8" x-model.number="groupsK" @change="rebuild()"></label>
	</aside>
	<section class="tt-ty-main">
		<div class="tt-ty-busybar" x-show="countingNow().length">
			Counting <span x-text="countingNow().join(', ')"></span> … (the first time for this corpus can take a minute; the result is kept)
		</div>
		<div class="tt-ty-tabs">
			<button type="button" :class="{ on: view === 'dendrogram' }" @click="view = 'dendrogram'">Dendrogram</button>
			<button type="button" :class="{ on: view === 'space' }" @click="view = 'space'; rebuild()">Similarity space</button>
			<button type="button" :class="{ on: view === 'matrix' }" @click="view = 'matrix'">Similarity matrix</button>
			<button type="button" :class="{ on: view === 'table' }" @click="view = 'table'">Feature table</button>
			<button type="button" class="tt-ty-share" @click="copyShare()" x-text="copied ? 'Link copied' : 'Share'"></button>
		</div>
		<div class="tt-ty-summary" x-show="m()">
			<span x-text="m() ? m().langs.length + ' languages, ' + m().ids.length + ' features' : ''"></span>
			<span x-show="m() && m().langs.length < 2">: too few languages with enough features; lower the minimum.</span>
		</div>
		<div class="tt-ty-focus" x-show="focus">
			<div><strong x-text="label(focus)"></strong> <button type="button" @click="setFocus(focus)">×</button></div>
			<div class="tt-ty-near">Nearest:
				<template x-for="n in focusNearest()" :key="n.code">
					<a href="#" @click.prevent="setFocus(n.code)" :title="'distance ' + n.d + ' over ' + n.shared + ' features'" x-text="label(n.code)"></a>
				</template>
			</div>
			<div x-show="focusDifferences().rows.length">
				Differs most from <span x-text="label(focusDifferences().other)"></span> in:
				<table class="tt-ty-diff">
					<template x-for="r in focusDifferences().rows" :key="r.id">
						<tr><td x-text="r.title"></td><td x-text="r.a"></td><td x-text="r.b"></td></tr>
					</template>
				</table>
			</div>
		</div>
		<div class="tt-ty-view" x-show="view !== 'table'" x-html="svg" @click="onSvgClick($event)"></div>
		<div class="tt-ty-table-wrap" x-show="view === 'table' && m()">
			<table class="tt-ty-table">
				<thead><tr><th>Language</th>
					<template x-for="id in (m() ? m().ids : [])" :key="'h' + id"><th :title="features[id].note" x-text="features[id].title"></th></template>
				</tr></thead>
				<tbody>
					<template x-for="l in tableLangs()" :key="'r' + l">
						<tr>
							<th><a href="#" @click.prevent="setFocus(l)" x-text="label(l)"></a></th>
							<template x-for="id in m().ids" :key="l + id">
								<td :style="cellStyle(l, id)" :title="cellTitle(l, id)">
									<a :href="cellLink(l, id)" x-show="cell(l, id) && cell(l, id).dims" x-text="cellText(l, id)" target="_blank" rel="noopener"></a>
									<span x-show="!(cell(l, id) && cell(l, id).dims)" x-text="cellText(l, id)"></span>
								</td>
							</template>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
		<p class="tt-ty-caveat">Counts depend on the annotation: treebanks of one language can follow different
		conventions, and a feature that is not annotated cannot be counted (shown as “—”, with the reason on hover).
		Orders follow WALS's rule: an order is dominant when it is at least twice as frequent as the next.</p>
	</section>
</div>
HTML;
	}
}
