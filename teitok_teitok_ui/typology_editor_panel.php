<?php
/**
 * typology.php's feature editor (Alpine markup; the logic in typology_editor.js): a feature's
 * definition with its queries, "Try", keeping it for oneself or for the corpus, the corpus's
 * attribute names, hidden built-in features, export / import.
 */

if ( ! function_exists( 'tt_typology_editor_html' ) ) {
	/** The tools above the feature list (new, attribute names, export, import). */
	function tt_typology_editor_tools_html() {
		return <<<'HTML'
<div class="tt-ty-tools">
	<select @change="if ($event.target.value) { startNew($event.target.value); $event.target.value = ''; }" title="A new feature, from a template">
		<option value="">+ New feature…</option>
		<template x-for="(label, k) in kinds" :key="k"><option :value="k" x-text="label"></option></template>
	</select>
	<button type="button" @click="openAttributes()" title="The names this corpus uses for parts of speech, dependency relations and morphological features">Attribute names</button>
	<button type="button" @click="exportFeatures()" title="Your and the corpus's own features as a file, to share">Export</button>
	<label class="tt-ty-import" x-show="canKeep()" title="Add features from a file (to yours)">Import <input type="file" accept=".json,application/json" @change="importFeatures($event)"></label>
	<div class="tt-ty-msg" x-show="importMsg" x-text="importMsg"></div>
</div>
HTML;
	}

	/** The editor dialog, the attribute names dialog and the hidden features. */
	function tt_typology_editor_html() {
		return <<<'HTML'
<div class="tt-ty-dialog" x-show="editing" x-cloak @keydown.escape.window="closeEditor()">
	<template x-if="editing">
	<div class="tt-ty-dialog-box">
		<h3 x-text="editingOrigin === 'new' ? 'New feature' : 'Feature: ' + editing.title"></h3>
		<div class="tt-ty-note" x-show="editMsg" x-text="editMsg"></div>
		<div class="tt-ty-form">
			<label>Id <input type="text" x-model="editing.id" :readonly="editingOrigin !== 'new' && editingOrigin !== 'builtin'" placeholder="e.g. clitics" size="14"></label>
			<label>Title <input type="text" x-model="editing.title" size="44"></label>
			<label>Group
				<select x-model="editing.group"><option value="order">Word order</option><option value="clause">Clauses</option><option value="morphology">Morphology</option><option value="other">Other</option></select>
			</label>
			<label>WALS <input type="text" x-model="editing.wals" size="5" placeholder="e.g. 81A"></label>
			<label>Kind
				<select :value="editing.kind" @change="setKind($event.target.value)">
					<template x-for="(label, k) in kinds" :key="k"><option :value="k" x-text="label" :selected="k === editing.kind"></option></template>
				</select>
			</label>
		</div>
		<template x-if="editing.kind === 'order' || editing.kind === 'share'">
			<div class="tt-ty-variants">
				<p class="tt-ty-help">One query per alternative (pando CQL; name tokens and compare positions after <code>::</code>, e.g.
				<code>h:[upos="NOUN"] &gt; d:[deprel="amod"] :: d &lt; h</code>). Each is counted per language;
				<span x-text="editing.kind === 'order' ? 'an order is dominant when it is at least twice as frequent as the next (WALS).' : 'the table shows the largest share.'"></span></p>
				<template x-for="(v, i) in editing.variants" :key="i">
					<div class="tt-ty-variant">
						<input type="text" x-model="v.name" placeholder="name" size="10">
						<textarea x-model="v.query" rows="2" placeholder="query"></textarea>
						<button type="button" @click="removeVariant(i)" title="Remove">×</button>
					</div>
				</template>
				<button type="button" @click="addVariant()">+ Alternative</button>
			</div>
		</template>
		<template x-if="editing.kind === 'inventory' || editing.kind === 'bundles'">
			<div class="tt-ty-form">
				<label>Part(s) of speech <input type="text" x-model="editing.pos" size="14" placeholder="NOUN or VERB|AUX"></label>
				<label x-show="editing.kind === 'inventory'">Feature <input type="text" x-model="editing.feat" size="12" placeholder="Case"></label>
				<p class="tt-ty-help" x-text="editing.kind === 'inventory' ? 'The values of this feature on these parts of speech; a value counts when at least 0.5% of the tokens have it.' : 'The distinct feature bundles on these parts of speech, and the features per token.'"></p>
			</div>
		</template>
		<label class="tt-ty-wide">Note (what it relies on) <input type="text" x-model="editing.note"></label>
		<div class="tt-ty-actions">
			<button type="button" @click="tryFeature()" :disabled="trialBusy" x-text="trialBusy ? 'Counting…' : 'Try'"></button>
			<button type="button" x-show="canKeep()" @click="keep('mine')">Keep for me</button>
			<button type="button" x-show="admin" @click="keep('corpus')">Keep for the corpus</button>
			<button type="button" x-show="canKeep() && editingOrigin === 'mine'" @click="remove('mine')">Delete</button>
			<button type="button" x-show="admin && editingOrigin === 'corpus'" @click="remove('corpus')">Delete from the corpus</button>
			<button type="button" x-show="canKeep() && editingOrigin === 'builtin'" @click="remove('mine')">Hide for me</button>
			<button type="button" x-show="admin && editingOrigin === 'builtin'" @click="remove('corpus')">Hide for the corpus</button>
			<button type="button" @click="closeEditor()">Close</button>
			<span class="tt-ty-help" x-show="!canKeep()">Log in to keep features.</span>
		</div>
		<div class="tt-ty-trial" x-show="trial">
			<template x-if="trial">
				<div>
					<div x-show="trial.missing" x-text="trial.missing"></div>
					<div x-show="!trial.missing"><span x-text="trial.usable"></span> of <span x-text="trial.languages"></span> languages with enough hits
						(<span x-text="trial.seconds"></span> s). The largest:</div>
					<table><template x-for="r in trial.rows" :key="r[0]"><tr><td x-text="label(r[0])"></td><td x-text="r[1]"></td><td x-text="'n = ' + r[2]"></td></tr></template></table>
					<details><summary>The program counted</summary><pre x-text="trial.program"></pre></details>
				</div>
			</template>
		</div>
	</div>
	</template>
</div>
<div class="tt-ty-dialog" x-show="namesOpen" x-cloak @keydown.escape.window="namesOpen = false">
	<div class="tt-ty-dialog-box">
		<h3>Language names</h3>
		<p class="tt-ty-help">Names for the language codes of this corpus, used in every view and download. Codes the
		browser knows a name for are listed last; a name given here replaces it. Leave a field empty to use the code.</p>
		<div class="tt-ty-names">
			<template x-for="(name, code) in namesEdit" :key="'nm' + code">
				<label :class="{ missing: !hasName(code) }"><code x-text="code"></code>
					<input type="text" x-model="namesEdit[code]" :placeholder="hasName(code) ? label(code) : 'name'"></label>
			</template>
		</div>
		<div class="tt-ty-actions">
			<button type="button" x-show="canKeep()" @click="saveNames('mine')">Keep for me</button>
			<button type="button" x-show="admin" @click="saveNames('corpus')">Keep for the corpus</button>
			<button type="button" @click="namesOpen = false">Close</button>
			<span class="tt-ty-help" x-show="!canKeep()">Log in to keep names; in the SVG download the labels are text and can be corrected there.</span>
		</div>
	</div>
</div>
<div class="tt-ty-dialog" x-show="attrsOpen" x-cloak @keydown.escape.window="attrsOpen = false">
	<div class="tt-ty-dialog-box">
		<h3>Attribute names in this corpus</h3>
		<p class="tt-ty-help">The built-in features use the Universal Dependencies names. If this corpus names them
		differently, give its names here: every built-in feature's queries use them (leave empty for the UD name).</p>
		<div class="tt-ty-form">
			<label>Part of speech (<code>upos</code>) <input type="text" x-model="attrsEdit.upos" placeholder="upos" size="12"></label>
			<label>Dependency relation (<code>deprel</code>) <input type="text" x-model="attrsEdit.deprel" placeholder="deprel" size="12"></label>
			<label>Morphological features (<code>feats</code>) <input type="text" x-model="attrsEdit.feats" placeholder="feats" size="12"></label>
		</div>
		<div class="tt-ty-actions">
			<button type="button" x-show="canKeep()" @click="saveAttributes('mine')">Keep for me</button>
			<button type="button" x-show="admin" @click="saveAttributes('corpus')">Keep for the corpus</button>
			<button type="button" @click="attrsOpen = false">Close</button>
		</div>
	</div>
</div>
HTML;
	}

	/** Hidden built-in features, with a way back. */
	function tt_typology_hidden_html() {
		return <<<'HTML'
<div class="tt-ty-hidden" x-show="hidden.length">
	<div class="tt-ty-group-title">Hidden</div>
	<template x-for="id in hidden" :key="'hid' + id">
		<div><span x-text="id"></span>
			<button type="button" x-show="canKeep()" @click="unhide(id, 'mine')">show</button>
			<button type="button" x-show="admin" @click="unhide(id, 'corpus')">show for the corpus</button>
		</div>
	</template>
</div>
HTML;
	}
}
