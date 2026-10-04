<?php
/**
 * Advanced statistics module scaffold.
 *
 * This module is intentionally isolated from legacy flexicorp stats state.
 * It can run standalone, and later be embedded as an optional tab.
 *
 * Note: for production TEITOK, prefer the main flexicorp Stats tab (maps/contrast modules).
 * This page remains useful for local development and experiments (Queries tab, etc.).
 */

global $maintext;
if ( ! isset( $maintext ) ) {
	$maintext = '';
}

require_once __DIR__ . '/flexicorp_functions.php';
require_once __DIR__ . '/advanced_maps.php';
require_once __DIR__ . '/advanced_contrast.php';

$af_emit_mode = tt_flexicorp_fn_get_string( $_REQUEST, 'af_emit', '' );
$af_tabs_only = ( $af_emit_mode === 'tabs_only' );

$af_ctx = tt_flexicorp_fn_advanced_freqs_bootstrap_context( $_REQUEST );
$af_backend = isset( $af_ctx['backend'] ) ? (string) $af_ctx['backend'] : '';
$af_query_language = isset( $af_ctx['query_language'] ) ? (string) $af_ctx['query_language'] : '';
$af_corpus_format = isset( $af_ctx['corpus_format'] ) ? (string) $af_ctx['corpus_format'] : '';
$af_capabilities = isset( $af_ctx['capabilities'] ) && is_array( $af_ctx['capabilities'] )
	? $af_ctx['capabilities']
	: array( 'hasGeo' => false );
$af_capabilities_json = json_encode( $af_capabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $af_capabilities_json ) || $af_capabilities_json === '' ) {
	$af_capabilities_json = '{"hasGeo":false}';
}

$af_base_query = isset( $af_ctx['base_query'] ) ? (string) $af_ctx['base_query'] : '';
$af_named_sources = isset( $af_ctx['named_query_sources'] ) && is_array( $af_ctx['named_query_sources'] )
	? $af_ctx['named_query_sources']
	: array(
		'last'   => array(),
		'recent' => array(),
		'stored' => array(),
	);
$af_named_sources_json = json_encode( $af_named_sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $af_named_sources_json ) || $af_named_sources_json === '' ) {
	$af_named_sources_json = '{"last":[],"recent":[],"stored":[]}';
}
$af_query_engine = isset( $af_ctx['query_engine'] ) ? (string) $af_ctx['query_engine'] : '';
$af_geo_map = isset( $af_ctx['geo_map'] ) && is_array( $af_ctx['geo_map'] ) ? $af_ctx['geo_map'] : array();
$af_has_geo = ! empty( $af_capabilities['hasGeo'] );
$af_geo_map_json = json_encode( $af_geo_map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $af_geo_map_json ) || $af_geo_map_json === '' ) {
	$af_geo_map_json = '{}';
}

/** Same JSON API as Corpus Search / Stats (FQS vs local decided inside flexicorp.php). */
$af_json_endpoint = tt_flexicorp_fn_flexicorp_json_endpoint_url();

$afCssUrl = tt_flexicorp_fn_asset_url( 'advanced_freqs', 'css' );
$afJsUrl = tt_flexicorp_fn_asset_url( 'advanced_freqs', 'js' );
$afContrastJsUrl = tt_flexicorp_fn_asset_url( 'advanced_contrast', 'js' );
$fcCssUrl = tt_flexicorp_fn_asset_url( 'flexicorp', 'css' );
$fcFnsJsUrl = tt_flexicorp_fn_asset_url( 'flexicorp_functions', 'js' );
$fcScopeJsUrl = tt_flexicorp_fn_asset_url( 'flexicorp_search_scope', 'js' );

ob_start();
?>
<?php if ( ! $af_tabs_only ) : ?>
<div id="advanced-freqs-loading" class="flexicorp-loading-shell">
	<h1>Advanced statistics</h1>
	<p class="flexicorp-loading-note">Loading interface...</p>
</div>
<div id="flexicorp-advanced-freqs-root"
	class="flexicorp-shell"
	data-backend="<?php echo htmlspecialchars( $af_backend, ENT_QUOTES, 'UTF-8' ); ?>"
	data-query-language="<?php echo htmlspecialchars( $af_query_language, ENT_QUOTES, 'UTF-8' ); ?>"
	data-query-engine="<?php echo htmlspecialchars( $af_query_engine, ENT_QUOTES, 'UTF-8' ); ?>"
	data-corpus-format="<?php echo htmlspecialchars( $af_corpus_format, ENT_QUOTES, 'UTF-8' ); ?>"
	data-capabilities="<?php echo htmlspecialchars( $af_capabilities_json, ENT_QUOTES, 'UTF-8' ); ?>"
	data-base-query="<?php echo htmlspecialchars( $af_base_query, ENT_QUOTES, 'UTF-8' ); ?>"
	data-af-geo-map="<?php echo htmlspecialchars( $af_geo_map_json, ENT_QUOTES, 'UTF-8' ); ?>"
	data-af-action="flexicorp"
	data-af-json-endpoint="<?php echo htmlspecialchars( $af_json_endpoint, ENT_QUOTES, 'UTF-8' ); ?>"
	data-af-named-query-sources="<?php echo htmlspecialchars( $af_named_sources_json, ENT_QUOTES, 'UTF-8' ); ?>"
	x-data="ttAdvancedFreqsApp()"
	x-cloak>
	<h1>Advanced statistics</h1>
	<div class="flexicorp-content">
<?php endif; ?>

<?php if ( $af_tabs_only ) : ?>
	<span x-init="if (typeof advancedStatsSubTab === 'undefined' || !advancedStatsSubTab) advancedStatsSubTab = 'advanced';"></span>
	<button
		type="button"
		class="flexicorp-tab"
		:class="{ 'flexicorp-tab--active': activeTab === 'frequency' && advancedStatsSubTab === 'advanced' }"
		:aria-selected="(activeTab === 'frequency' && advancedStatsSubTab === 'advanced') ? 'true' : 'false'"
		x-on:click.prevent="activeTab = 'frequency'; advancedStatsSubTab = 'advanced'"
	>Advanced</button>
	<button
		type="button"
		class="flexicorp-tab"
		:class="{ 'flexicorp-tab--active': activeTab === 'frequency' && advancedStatsSubTab === 'queries' }"
		:aria-selected="(activeTab === 'frequency' && advancedStatsSubTab === 'queries') ? 'true' : 'false'"
		x-on:click.prevent="activeTab = 'frequency'; advancedStatsSubTab = 'queries'"
	>Queries</button>
<?php else : ?>
	<div class="flexicorp-toolbar">
		<div class="flexicorp-toolbar-left">
			<div class="flexicorp-project-title" x-text="toolbarMainTitle()"></div>
			<div class="flexicorp-project-meta">
				<span x-show="toolbarMetaLine()" x-text="toolbarMetaLine()"></span>
				<span x-show="!toolbarMetaLine()" class="flexicorp-panel-note" style="margin:0;">Open Corpus Search to select a backend and dialect, or pass <code>backend</code> / <code>query_language</code> in the URL.</span>
			</div>
		</div>
		<div class="flexicorp-toolbar-right">
			<button type="button" class="flexicorp-tab" :class="{ 'flexicorp-tab--active': activeTab === 'advanced' }" :aria-selected="activeTab === 'advanced' ? 'true' : 'false'" x-on:click="setTab('advanced')">Advanced</button>
			<button type="button" class="flexicorp-tab" :class="{ 'flexicorp-tab--active': activeTab === 'queries' }" :aria-selected="activeTab === 'queries' ? 'true' : 'false'" x-on:click="setTab('queries')">Queries</button>
			<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="openHelp()" :aria-expanded="showHelp ? 'true' : 'false'">Help</button>
			<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="openSettings()">Settings</button>
			<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="toggleFullscreen()" x-text="fullscreenButtonLabel()"></button>
		</div>
	</div>

	<section class="flexicorp-panel" x-show="showHelp">
		<div class="flexicorp-panel-title-row">
			<h2 class="flexicorp-panel-title">Help</h2>
		</div>
		<p class="flexicorp-panel-note">Use <strong>Queries</strong> to pick named queries from configured sources. Use <strong>Advanced</strong> to run contrast operations (keyness first in V1).</p>
	</section>

	<section class="flexicorp-panel" role="tabpanel" x-show="activeTab === 'advanced'">
		<div class="flexicorp-panel-title-row">
			<h2 class="flexicorp-panel-title">Advanced</h2>
		</div>

		<div class="flexicorp-stats-base-query advanced-freqs-scope" style="margin-bottom: 0.75rem;" x-show="hasBaseQuery()" id="advanced-freqs-search-scope" x-init="afEnsureSearchScopeStore()">
			<div class="flexicorp-stats-base-query__label">Search scope</div>
			<div class="flexicorp-stats-search-scope" style="display:flex;flex-direction:column;gap:0.45rem;">
				<template x-for="row in (afSearchScopeStore && afSearchScopeStore.queries) ? afSearchScopeStore.queries : []" :key="row.id">
					<div
						class="flexicorp-stats-search-scope__row"
						style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:0.35rem;border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;padding:0.35rem 0.5rem;"
						:class="row.active === false ? 'flexicorp-stats-search-scope__row--muted' : ''"
					>
						<button
							type="button"
							class="btn btn-sm"
							:class="row.active === false ? 'btn-outline-secondary' : 'btn-primary'"
							x-on:click="afSearchScopeToggleActive(row.id, !(row.active !== false))"
							x-text="row.active === false ? 'Off' : 'On'"
						></button>
						<pre class="flexicorp-stats-base-query__query flexicorp-query-highlight-code" style="flex:1 1 18rem;border:1px solid rgba(0,0,0,0.08);border-radius:0.3rem;padding:0.35rem 0.45rem;margin:0;cursor:pointer;white-space:pre-wrap;" x-text="afSearchScopeProgramText(row)" x-on:click="afSearchScopeCardClick(row)" :title="row.active === false ? 'Click to enable this query in scope' : 'Click to disable this query in scope'"></pre>
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="afSearchScopeRenameViaPrompt(row)">Rename</button>
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="afSearchScopeEditRow(row)">Edit</button>
					</div>
				</template>
				<p class="flexicorp-panel-note" style="margin:0;">Queries are shown as full Pando syntax cards. Click card to (de)select. <strong>Edit</strong> auto-names anonymous queries and opens the Queries tab editor flow.</p>
			</div>
		</div>
		<p class="flexicorp-panel-note" style="margin-top: 0; margin-bottom: 0.75rem;" x-show="!hasBaseQuery()">
			Set a <strong>search scope</strong> for all advanced analyses. Run a query in Corpus Search first, pass <code>query=…</code> in the URL, or pick entries on the <strong>Queries</strong> tab. Use <strong>+ Add query</strong> after you have at least one scope row.
		</p>

		<div class="flexicorp-stats-subtabs" role="tablist" aria-label="Advanced module subtabs">
			<template x-for="mod in availableAdvancedModules()" :key="mod.id">
				<button
					type="button"
					class="flexicorp-tab flexicorp-tab--sub"
					:class="{ 'flexicorp-tab--active': advancedSubTab === mod.id }"
					:aria-selected="advancedSubTab === mod.id ? 'true' : 'false'"
					x-on:click.prevent="setAdvancedSubTab(mod.id)"
					x-text="mod.label"
				></button>
			</template>
		</div>
		<template x-if="!availableAdvancedModules().length">
			<p class="flexicorp-panel-note">No advanced modules are available for the current corpus configuration.</p>
		</template>

		<?php echo function_exists( 'tt_flexicorp_contrast_render_af_panel' ) ? tt_flexicorp_contrast_render_af_panel() : ''; ?>

		<?php echo tt_flexicorp_adv_maps_render_panel(); ?>
	</section>

	<section class="flexicorp-panel" role="tabpanel" x-show="activeTab === 'queries'">
		<div class="flexicorp-panel-title-row">
			<h2 class="flexicorp-panel-title">Queries</h2>
		</div>
		<p class="flexicorp-panel-note">Add picks into the shared Search scope. Sources are session-only (last search, recent list, TEITOK stored queries when available).</p>
		<div class="flexicorp-stats-subpanel">
			<h3 class="flexicorp-subtitle">Current scope editor</h3>
			<p class="flexicorp-panel-note">This is the authoring surface for query text and names. Search Scope cards in Advanced/Stats are for quick (de)selection.</p>
			<div style="display:flex;flex-direction:column;gap:0.45rem;">
				<template x-for="row in (afSearchScopeStore && afSearchScopeStore.queries) ? afSearchScopeStore.queries : []" :key="'ed-' + row.id">
					<div style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:flex-start;border:1px solid rgba(0,0,0,0.08);border-radius:0.35rem;padding:0.45rem;">
						<input type="text" class="form-control form-control-sm" style="max-width:10rem;" placeholder="Name" x-model="row.name" x-on:blur="afSearchScopeRenameCommitted(row)">
						<textarea class="form-control form-control-sm" style="flex:1 1 18rem;min-height:2.8rem;resize:vertical;font-family:ui-monospace,monospace;" rows="2" x-model="row.text" x-on:blur="afSearchScopePersist()"></textarea>
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="afSearchScopeToggleActive(row.id, !(row.active !== false))" x-text="row.active === false ? 'Enable' : 'Disable'"></button>
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="afSearchScopeRemoveRow(row.id)" :disabled="!afSearchScopeStore || afSearchScopeStore.queries.length <= 1">Remove</button>
					</div>
				</template>
				<div>
					<button type="button" class="btn btn-sm btn-outline-primary" x-on:click="afSearchScopeAddRow()">+ Add query</button>
				</div>
			</div>
		</div>
		<div class="flexicorp-stats-subpanel">
			<h3 class="flexicorp-subtitle">Last search</h3>
			<template x-if="!afNamedSourcesLast().length">
				<p class="flexicorp-panel-note">No last query recorded in this session yet.</p>
			</template>
			<ul class="list-unstyled" style="margin:0;">
				<template x-for="rec in afNamedSourcesLast()" :key="'last-' + rec.name + '-' + (rec.query || '').slice(0,12)">
					<li style="margin:0.25rem 0;">
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="afAddNamedSourcePick(rec)">Add</button>
						<code style="margin-left:0.35rem;" x-text="(rec.query || '').length > 120 ? (rec.query.slice(0,119) + '…') : (rec.query || '')"></code>
					</li>
				</template>
			</ul>
		</div>
		<div class="flexicorp-stats-subpanel">
			<h3 class="flexicorp-subtitle">Recent</h3>
			<template x-if="!afNamedSourcesRecent().length">
				<p class="flexicorp-panel-note">No recent queries for this dialect yet.</p>
			</template>
			<ul class="list-unstyled" style="margin:0;max-height:14rem;overflow:auto;">
				<template x-for="rec in afNamedSourcesRecent()" :key="'rc-' + rec.name + '-' + (rec.query || '').slice(0,12)">
					<li style="margin:0.25rem 0;">
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="afAddNamedSourcePick(rec)">Add</button>
						<span style="margin-left:0.35rem;font-weight:600;" x-text="rec.name"></span>
						<code style="margin-left:0.35rem;" x-text="(rec.query || '').length > 96 ? (rec.query.slice(0,95) + '…') : (rec.query || '')"></code>
					</li>
				</template>
			</ul>
		</div>
		<div class="flexicorp-stats-subpanel">
			<h3 class="flexicorp-subtitle">Stored (TEITOK)</h3>
			<template x-if="!afNamedSourcesStored().length">
				<p class="flexicorp-panel-note">No stored queries in <code>$_SESSION['queries']</code> for this session.</p>
			</template>
			<ul class="list-unstyled" style="margin:0;max-height:16rem;overflow:auto;">
				<template x-for="rec in afNamedSourcesStored()" :key="'st-' + rec.name + '-' + (rec.query || '').slice(0,12)">
					<li style="margin:0.25rem 0;">
						<button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="afAddNamedSourcePick(rec)">Add</button>
						<span style="margin-left:0.35rem;font-weight:600;" x-text="rec.name"></span>
						<code style="margin-left:0.35rem;" x-text="(rec.query || '').length > 96 ? (rec.query.slice(0,95) + '…') : (rec.query || '')"></code>
					</li>
				</template>
			</ul>
		</div>
		<p class="flexicorp-panel-note">Saving to TEITOK query manager uses the Corpus Search UI (<strong>Save query</strong> / <code>storeCurrentQueryToTeitok()</code>).</p>
	</section>
<?php endif; ?>

<?php if ( ! $af_tabs_only ) : ?>
</div>
</div>
<script>
try {
	document.addEventListener('alpine:init', function () {
		var loading = document.getElementById('advanced-freqs-loading');
		if (loading) loading.style.display = 'none';
	});
} catch (_) {}
</script>
<link rel="stylesheet" href="<?php echo htmlspecialchars( $fcCssUrl, ENT_QUOTES, 'UTF-8' ); ?>" />
<link rel="stylesheet" href="<?php echo htmlspecialchars( $afCssUrl, ENT_QUOTES, 'UTF-8' ); ?>" />
<script src="<?php echo htmlspecialchars( $fcFnsJsUrl, ENT_QUOTES, 'UTF-8' ); ?>" defer></script>
<script src="<?php echo htmlspecialchars( $fcScopeJsUrl, ENT_QUOTES, 'UTF-8' ); ?>" defer></script>
<?php tt_flexicorp_adv_maps_print_assets( $af_has_geo ); ?>
<script src="<?php echo htmlspecialchars( $afContrastJsUrl, ENT_QUOTES, 'UTF-8' ); ?>" defer></script>
<script src="<?php echo htmlspecialchars( $afJsUrl, ENT_QUOTES, 'UTF-8' ); ?>" defer></script>
<script>
(function () {
	try {
		if (window.Alpine) return;
		var s = document.createElement('script');
		s.src = 'https://cdn.jsdelivr.net/npm/alpinejs@3.15.11/dist/cdn.min.js';
		s.defer = true;
		document.head.appendChild(s);
	} catch (_) {}
})();
</script>
<?php endif; ?>
<?php
$af_html = ob_get_clean();

// TEITOK module mode: append to main page content.
if ( isset( $maintext ) ) {
	$maintext .= $af_html;
} else {
	// Standalone fallback for direct invocation.
	print $af_html;
}

