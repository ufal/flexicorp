<?php
/**
 * The classify page's markup (classify.php; state and methods in classify.js). A function, so the
 * TEITOK installer (which copies *.php to Sources/) ships it with the module.
 */

if ( ! function_exists( 'tt_classify_panel_html' ) ) {
	function tt_classify_panel_html() {
		return <<<'HTML'
<p class="tt-classify-lead">Give the lines of a search a label from a set you define — senses or patterns of a word,
literal or figurative use, correct or wrong annotation — and use the labelled lines as data.</p>

<p class="tt-classify-note" x-show="!boot.user">Log in to create layers and to classify; shared layers can then be used by everyone working on this corpus.</p>

<div class="tt-classify-bar">
	<label>Layer
		<select x-model="layerId" x-on:change="openLayer(layerId)">
			<option value="">—</option>
			<template x-for="l in layers" :key="l.id">
				<option :value="l.id" x-text="l.name + (l.shared ? '' : ' (private)') + ' · ' + l.item_count + ' labelled'"></option>
			</template>
		</select>
	</label>
	<button type="button" class="btn btn-sm btn-outline-primary" x-show="boot.user" x-on:click="newLayer()">New layer</button>
	<template x-if="layer">
		<span class="tt-classify-bar__actions">
			<button type="button" class="btn btn-sm btn-outline-secondary" x-show="layer.can_edit" x-on:click="editLayer()">Edit layer</button>
			<a class="btn btn-sm btn-outline-secondary" :href="exportUrl()">Export CSV</a>
		</span>
	</template>
</div>
<div class="tt-classify-error" x-show="error" x-text="error"></div>

<!-- layer definition -->
<template x-if="editor">
<form class="tt-classify-editor" x-on:submit.prevent="saveLayer()">
	<h3 x-text="editor.create ? 'New layer' : 'Edit layer'"></h3>
	<div class="tt-classify-grid">
		<label>Name <input type="text" class="form-control form-control-sm" x-model="editor.name" required maxlength="120"></label>
		<label>Search query <input type="text" class="form-control form-control-sm tt-classify-mono" x-model="editor.query" placeholder='v:[lemma="run" &amp; upos="VERB"]'></label>
		<label>Label on <select class="form-control form-control-sm" x-model="editor.unit">
			<option value="">the whole match</option>
			<template x-for="n in queryTokenNames(editor.query)" :key="n"><option :value="n" x-text="'token ' + n"></option></template>
		</select></label>
		<label>Sample <input type="number" min="0" max="5000" class="form-control form-control-sm" x-model.number="editor.sampleSize" placeholder="all hits">
			<small>lines, drawn at random but the same each time (0 or empty: all hits, up to 2000)</small></label>
	</div>
	<label>Description <textarea class="form-control form-control-sm" rows="2" x-model="editor.description" placeholder="What is being classified, and how to decide"></textarea></label>
	<table class="flexicorp-table tt-classify-labels">
		<thead><tr><th>Key</th><th>Code</th><th>Name</th><th>When to use it</th><th></th></tr></thead>
		<tbody>
			<template x-for="(l, i) in editor.labels" :key="i">
				<tr>
					<td x-text="i < 9 ? i + 1 : ''"></td>
					<td><input type="text" class="form-control form-control-sm" x-model="l.code" maxlength="32" placeholder="P1"></td>
					<td><input type="text" class="form-control form-control-sm" x-model="l.name" maxlength="80"></td>
					<td><input type="text" class="form-control form-control-sm" x-model="l.description" maxlength="1000"></td>
					<td><button type="button" class="btn btn-sm btn-link" x-on:click="editor.labels.splice(i, 1)" title="Remove this label">✕</button></td>
				</tr>
			</template>
		</tbody>
	</table>
	<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="editor.labels.push({ code: '', name: '', description: '' })">+ Label</button>
	<label class="tt-classify-check"><input type="checkbox" x-model="editor.shared"> Shared: everyone logged in to this corpus can see and label it</label>
	<div class="tt-classify-editor__buttons">
		<button type="submit" class="btn btn-sm btn-primary" :disabled="busy">Save layer</button>
		<button type="button" class="btn btn-sm btn-link" x-on:click="editor = null">Cancel</button>
	</div>
</form>
</template>

<!-- classifying -->
<template x-if="layer && !editor">
	<div class="tt-classify-work">
		<p class="tt-classify-desc" x-show="layer.description" x-text="layer.description"></p>
		<div class="tt-classify-legend">
			<template x-for="(l, i) in layer.labels" :key="l.code">
				<span class="tt-classify-legend__item" :title="l.description"><kbd x-show="i < 9" x-text="i + 1"></kbd> <b x-text="l.code"></b> <span x-text="l.name"></span></span>
			</template>
			<span class="tt-classify-legend__item"><kbd>0</kbd> clear · <kbd>↑</kbd><kbd>↓</kbd> move</span>
		</div>
		<div class="tt-classify-status">
			<code class="tt-classify-mono" x-text="layer.query"></code>
			<button type="button" class="btn btn-sm btn-primary" x-on:click="loadHits()" :disabled="busy || !layer.query" x-text="hits.length ? 'Reload lines' : 'Load lines'"></button>
			<span x-show="loadingHits">Loading lines…</span>
			<span x-show="hits.length" x-text="statusLine()"></span>
			<label x-show="hits.length">Show <select x-model="show">
				<option value="all">all lines</option>
				<option value="open">lines without my label</option>
				<template x-for="l in layer.labels" :key="'f-' + l.code"><option :value="'l:' + l.code" x-text="'only ' + l.code"></option></template>
			</select></label>
		</div>
		<table id="rollovertable" class="flexicorp-table tt-classify-lines" x-show="hits.length">
			<tbody>
				<template x-for="row in visibleRows()" :key="row.key">
					<tr :class="{ 'tt-classify-current': row.idx === cursor }" x-on:click="cursor = row.idx">
						<td class="tt-classify-left"><span x-text="row.left"></span></td>
						<td class="tt-classify-match" x-text="row.match"></td>
						<td class="tt-classify-right" x-text="row.right"></td>
						<td class="tt-classify-buttons">
							<template x-for="l in layer.labels" :key="row.key + l.code">
								<button type="button" class="tt-classify-btn" :class="{ 'tt-classify-btn--on': myLabel(row) === l.code }"
									:title="l.name" x-on:click.stop="setLabel(row, myLabel(row) === l.code ? '' : l.code)" x-text="l.code"></button>
							</template>
							<span class="tt-classify-others" x-show="otherLabels(row)" x-text="otherLabels(row)" title="Labels by others"></span>
						</td>
					</tr>
				</template>
			</tbody>
		</table>
	</div>
</template>
HTML;
	}
}
