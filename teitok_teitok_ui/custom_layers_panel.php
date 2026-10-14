<?php
/**
 * The custom_layers page's markup (custom_layers.php; state and methods in custom_layers.js). A
 * function, so the TEITOK installer (which copies *.php to Sources/) ships it with the module.
 */

if ( ! function_exists( 'tt_cl_panel_html' ) ) {
	function tt_cl_panel_html() {
		return <<<'HTML'
<p class="tt-cl-lead">Classify the lines of a search with attributes of your own — the sense of a word, transitive or
intransitive, literal or figurative — several at once, and use the labelled lines as data.</p>

<p class="tt-cl-note" x-show="!boot.user">Log in to create custom layers; attributes shared by others can then be used in your own tasks.</p>

<div class="tt-cl-bar" x-show="boot.user">
	<label>Task
		<select x-model="taskId" x-on:change="openTask(taskId)">
			<option value="">—</option>
			<template x-for="t in tasks" :key="t.id"><option :value="t.id" x-text="t.display"></option></template>
		</select>
	</label>
	<button type="button" class="btn btn-sm btn-outline-primary" x-on:click="newTask('')">New task</button>
	<template x-if="task && view === 'work'">
		<span class="tt-cl-bar__actions">
			<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="editTask()">Edit task</button>
			<a class="btn btn-sm btn-outline-secondary" :href="exportUrl()">Export CSV</a>
		</span>
	</template>
</div>
<div class="tt-cl-error" x-show="error" x-text="error"></div>

<!-- task: query, attributes, sample -->
<template x-if="view === 'task' && taskEd">
<form class="tt-cl-editor" x-on:submit.prevent="saveTask()">
	<h3 x-text="taskEd.create ? 'New task' : 'Edit task'"></h3>
	<div class="tt-cl-grid">
		<label>Name <input type="text" class="form-control form-control-sm" x-model="taskEd.display" required maxlength="120" :disabled="!taskEd.create"></label>
		<label>Search query <input type="text" class="form-control form-control-sm tt-cl-mono" x-model="taskEd.query" required placeholder='v:[lemma="feel"]'></label>
		<label>Label on <select class="form-control form-control-sm" x-model="taskEd.unit">
			<option value="">the whole match</option>
			<template x-for="n in queryTokenNames(taskEd.query)" :key="n"><option :value="n" x-text="'token ' + n"></option></template>
		</select></label>
		<label>Sample <input type="number" min="0" max="5000" class="form-control form-control-sm" x-model.number="taskEd.sample" placeholder="all lines">
			<small>lines drawn at random, the same each time (empty: all lines, up to 2000)</small></label>
	</div>
	<h4>Attributes to classify</h4>
	<p class="tt-cl-note" x-show="!attributes.length">Create an attribute (or use one shared by someone else) to classify with.</p>
	<ul class="tt-cl-attrlist">
		<template x-for="a in attributes" :key="a.key">
			<li>
				<label class="tt-cl-check"><input type="checkbox" :value="a.key" x-model="taskEd.attributes">
					<b x-text="a.display"></b> <span class="tt-cl-values" x-text="a.values.map((v) => v.display).join(' · ')"></span>
					<span class="tt-cl-note" x-show="a.owner" x-text="'(by ' + a.owner + ')'"></span>
					<span class="tt-cl-note" x-show="a.prefill && a.prefill.if" title="Pre-filled by a rule">pre-filled</span></label>
				<button type="button" class="btn btn-sm btn-link" x-show="!a.owner" x-on:click="editAttribute(a)">edit</button>
			</li>
		</template>
	</ul>
	<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="newAttribute()">+ New attribute</button>
	<template x-if="sharedToAdd().length">
		<div class="tt-cl-shared">
			<span>Shared by others:</span>
			<template x-for="s in sharedToAdd()" :key="s.owner + s.key">
				<button type="button" class="btn btn-sm btn-link" x-on:click="addReference(s)" :title="s.description" x-text="s.display + ' (' + s.owner + ')'"></button>
			</template>
		</div>
	</template>
	<div class="tt-cl-editor__buttons">
		<button type="submit" class="btn btn-sm btn-primary" :disabled="busy || !taskEd.attributes.length">Save task</button>
		<button type="button" class="btn btn-sm btn-link" x-on:click="taskEd = null; view = 'work'">Cancel</button>
	</div>
</form>
</template>

<!-- attribute: values, pre-fill rule, sharing -->
<template x-if="view === 'attribute' && attrEd">
<form class="tt-cl-editor" x-on:submit.prevent="saveAttribute()">
	<h3 x-text="attrEd.create ? 'New attribute' : 'Edit attribute'"></h3>
	<div class="tt-cl-grid">
		<label>Name <input type="text" class="form-control form-control-sm" x-model="attrEd.display" required maxlength="120"></label>
		<label>Description <input type="text" class="form-control form-control-sm" x-model="attrEd.description" placeholder="What is classified, and how to decide"></label>
	</div>
	<table class="flexicorp-table tt-cl-valtable">
		<thead><tr><th>Key</th><th>Value</th><th>When to use it</th><th></th></tr></thead>
		<tbody>
			<template x-for="(v, i) in attrEd.values" :key="i">
				<tr>
					<td x-text="i < 9 ? i + 1 : ''"></td>
					<td><input type="text" class="form-control form-control-sm" x-model="v.display" maxlength="80" placeholder="Touch"></td>
					<td><input type="text" class="form-control form-control-sm" x-model="v.description" maxlength="1000"></td>
					<td><button type="button" class="btn btn-sm btn-link" x-on:click="attrEd.values.splice(i, 1)" title="Remove this value">✕</button></td>
				</tr>
			</template>
		</tbody>
	</table>
	<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="attrEd.values.push({ key: '', display: '', description: '' })">+ Value</button>
	<fieldset class="tt-cl-prefill">
		<legend>Pre-fill (optional)</legend>
		<label>When the labelled token also matches <input type="text" class="form-control form-control-sm tt-cl-mono" x-model="attrEd.prefill.if" placeholder='child [deprel="obj"]'></label>
		<label>suggest <select class="form-control form-control-sm" x-model="attrEd.prefill.then"><option value="">—</option>
			<template x-for="v in attrEd.values.filter((x) => x.display)" :key="'t' + v.display"><option :value="v.key || v.display" x-text="v.display"></option></template></select></label>
		<label>otherwise <select class="form-control form-control-sm" x-model="attrEd.prefill.else"><option value="">—</option>
			<template x-for="v in attrEd.values.filter((x) => x.display)" :key="'e' + v.display"><option :value="v.key || v.display" x-text="v.display"></option></template></select></label>
		<small>Suggestions are shown until you confirm them (Enter) or choose another value.</small>
	</fieldset>
	<label class="tt-cl-check"><input type="checkbox" x-model="attrEd.shared"> Shared: others logged in to this corpus can use it in their own tasks</label>
	<div class="tt-cl-editor__buttons">
		<button type="submit" class="btn btn-sm btn-primary" :disabled="busy">Save attribute</button>
		<button type="button" class="btn btn-sm btn-link" x-on:click="view = taskEd ? 'task' : 'work'">Cancel</button>
	</div>
</form>
</template>

<!-- classifying -->
<template x-if="view === 'work' && task">
	<div class="tt-cl-work">
		<div class="tt-cl-legend">
			<template x-for="(a, ai) in taskAttributes()" :key="a.key">
				<span class="tt-cl-legend__attr" :class="{ 'tt-cl-legend__attr--active': ai === activeAttr }">
					<b x-text="a.display"></b>
					<template x-for="(v, i) in a.values" :key="a.key + v.key"><span :title="v.description"><kbd x-show="i < 9" x-text="i + 1"></kbd> <span x-text="v.display"></span></span></template>
				</span>
			</template>
			<span class="tt-cl-legend__keys"><kbd>Tab</kbd> next attribute · <kbd>0</kbd> clear · <kbd>Enter</kbd> accept suggestions · <kbd>↑</kbd><kbd>↓</kbd> move</span>
		</div>
		<div class="tt-cl-status">
			<code class="tt-cl-mono" x-text="task.query"></code>
			<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="openTask(taskId)" :disabled="loadingHits">Reload lines</button>
			<span x-show="loadingHits">Loading lines…</span>
			<span x-show="rows.length" x-text="statusLine()"></span>
			<label x-show="rows.length">Show <select x-model="show">
				<option value="all">all lines</option>
				<option value="open">lines not done</option>
				<option value="done">lines done</option>
			</select></label>
		</div>
		<table id="rollovertable" class="tt-cl-lines" x-show="rows.length">
			<tbody>
				<template x-for="row in visibleRows()" :key="row.key">
					<tr :class="{ 'tt-cl-current': row.idx === cursor }" x-on:click="cursor = row.idx">
						<td class="tt-cl-left"><span x-text="row.left"></span></td>
						<td class="tt-cl-match" x-text="row.match"></td>
						<td class="tt-cl-right" x-text="row.right"></td>
						<td class="tt-cl-buttons">
							<template x-for="(a, ai) in taskAttributes()" :key="row.key + a.key">
								<div class="tt-cl-group" :class="{ 'tt-cl-group--active': row.idx === cursor && ai === activeAttr }">
									<span class="tt-cl-group__name" x-text="a.display" x-show="taskAttributes().length > 1"></span>
									<template x-for="v in a.values" :key="row.key + a.key + v.key">
										<button type="button" class="tt-cl-btn"
											:class="{ 'tt-cl-btn--on': value(row, a.key) === v.key, 'tt-cl-btn--suggested': !value(row, a.key) && suggestion(row, a.key) === v.key }"
											:title="v.description || v.display" x-on:click.stop="activeAttr = ai; setValue(row, a.key, v.key)" x-text="v.display"></button>
									</template>
								</div>
							</template>
							<div class="tt-cl-others" x-show="othersText(row)" x-text="othersText(row)"></div>
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
