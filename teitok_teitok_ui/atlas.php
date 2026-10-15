<?php
/**
 * TEITOK action `atlas`: linguistic maps of a corpus. Choose a query and the field that varies (form,
 * nform, an IPA field, a custom layer, …), group its values into variants, and see per place which
 * variant is used: Voronoi cells (the area closest to each place) or points on a map (Leaflet), with
 * the counts on click and GeoJSON to take to a GIS. Maps can be kept: your own, and (admins) the
 * corpus's. The same model as pando_geo's atlas files (pando/python/src/pando_geo).
 *
 *   index.php?action=atlas[&map=key | &s=…]             the page (s: a share link's map, atlas_share.js)
 *   POST op=state | values | counts | outline | regions | save | delete   (JSON; save / delete need a login and the
 *        page's token, X-Atlas-Token; scope=corpus needs an admin)
 *
 * Counting: atlas_engine.php (pando); kept maps: atlas_store.php; the outline to cut the areas at:
 * atlas_outline.php; fixed regions: atlas_regions.php; the page: atlas_panel.php, atlas.js, atlas_geo.js
 * (geometry), atlas_views.js (the ways to show a map).
 */

global $maintext;
require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/atlas_store.php';
require_once __DIR__ . '/atlas_engine.php';
require_once __DIR__ . '/atlas_panel.php';

if ( ! isset( $maintext ) ) $maintext = '';

$ttAtRoot = tt_fc_env_project_root();
$ttAtFolder = tt_fc_env_user_folder();
$ttAtAdmin = tt_fc_env_is_admin();
$ttAtToken = tt_fc_env_session_token( 'atlas' );
$ttAtCfg = tt_atlas_settings();
$ttAtOp = (string) ( $_REQUEST['op'] ?? '' );

if ( $ttAtOp !== '' ) {
	$reply = function ( $d ) {
		if ( ! headers_sent() ) header( 'Content-Type: application/json; charset=UTF-8' );
		print json_encode( $d, JSON_UNESCAPED_UNICODE );
		exit;
	};
	$in = json_decode( (string) file_get_contents( 'php://input' ), true );
	if ( ! is_array( $in ) ) $in = $_POST;
	$fail = function ( $msg ) use ( $reply ) { $reply( array( 'ok' => false, 'error' => $msg ) ); };
	$query = (string) ( $in['query'] ?? '' );
	$field = (string) ( $in['field'] ?? 'form' );
	switch ( $ttAtOp ) {
		case 'state':
			$places = tt_atlas_places( $ttAtRoot, $ttAtCfg );
			if ( is_string( $places ) ) $fail( $places );
			$reply( array( 'ok' => true, 'settings' => $ttAtCfg, 'places' => (object) $places,
				'corpus_maps' => array_values( tt_atlas_load( tt_atlas_path( $ttAtRoot, '' ) ) ),
				'my_maps' => $ttAtFolder !== '' ? array_values( tt_atlas_load( tt_atlas_path( $ttAtRoot, $ttAtFolder ) ) ) : array(),
				'user' => $ttAtFolder, 'admin' => $ttAtAdmin ) );
		case 'values':
		case 'counts':
			if ( trim( $query ) === '' ) $fail( 'give a query' );
			if ( ! tt_atlas_valid_field( $field ) ) $fail( "'$field' is not an attribute" );
			$r = $ttAtOp === 'values' ? tt_atlas_values( $ttAtRoot, $query, $field ) : tt_atlas_counts( $ttAtRoot, $ttAtCfg, $query, $field );
			if ( is_string( $r ) ) $fail( $r );
			$reply( $ttAtOp === 'values' ? array( 'ok' => true ) + $r : array( 'ok' => true, 'counts' => (object) $r ) );
		case 'outline':
			$r = tt_atlas_outline( $ttAtRoot, $ttAtCfg['clip'] );
			if ( is_string( $r ) ) $fail( $r );
			$reply( array( 'ok' => true, 'outline' => $r ) );
		case 'regions':
			$key = (string) ( $in['key'] ?? '' );
			if ( ! in_array( $key, array_column( $ttAtCfg['regions'], 'key' ), true ) ) $fail( 'unknown regions' );
			$r = tt_atlas_regions( $ttAtRoot, $ttAtCfg['clip'], $key );
			if ( is_string( $r ) ) $fail( $r );
			$reply( array( 'ok' => true, 'regions' => $r ) );
		case 'save':
		case 'delete':
			if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) $fail( 'POST only' );
			if ( ! hash_equals( $ttAtToken, (string) ( $_SERVER['HTTP_X_ATLAS_TOKEN'] ?? '' ) ) ) $fail( 'the page has expired; reload it' );
			if ( $ttAtFolder === '' ) $fail( 'log in to save maps' );
			$corpus = ( $in['scope'] ?? '' ) === 'corpus';
			if ( $corpus && ! $ttAtAdmin ) $fail( "only the corpus's administrators can add maps to the corpus" );
			$r = tt_atlas_save( $ttAtRoot, $corpus ? '' : $ttAtFolder, $in, $ttAtOp === 'delete' );
			if ( is_string( $r ) ) $fail( $r );
			$reply( array( 'ok' => true, 'corpus_maps' => array_values( tt_atlas_load( tt_atlas_path( $ttAtRoot, '' ) ) ),
				'my_maps' => array_values( tt_atlas_load( tt_atlas_path( $ttAtRoot, $ttAtFolder ) ) ) ) );
	}
	$fail( 'unknown op' );
}

// cache buster: the module's newest file here (the installer puts the JS in Scripts/)
$ttAtVer = (string) max( array_map( function ( $f ) { return (int) @filemtime( $f ); }, array_merge( array( __FILE__ ), glob( __DIR__ . '/atlas*' ) ?: array() ) ) );
$h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
$ttAtScripts = tt_fc_env_scripts_url( 'atlas' );
$ttAtBoot = $h( json_encode( array( 'token' => $ttAtToken, 'user' => $ttAtFolder, 'admin' => $ttAtAdmin,
	'map' => (string) ( $_GET['map'] ?? '' ),
	// a share link's map (atlas_share.js); only its alphabet passes, the page checks the rest
	's' => preg_match( '/^[A-Za-z0-9_-]{1,12000}$/', (string) ( $_GET['s'] ?? '' ) ) ? (string) $_GET['s'] : '' ), JSON_UNESCAPED_UNICODE ) );
$ttAtCss = $h( $ttAtScripts . '/atlas.css?v=' . $ttAtVer );
$ttAtJs = $h( $ttAtScripts . '/atlas.js?v=' . $ttAtVer );
$ttAtGeoJs = $h( $ttAtScripts . '/atlas_geo.js?v=' . $ttAtVer );
$ttAtViewsJs = $h( $ttAtScripts . '/atlas_views.js?v=' . $ttAtVer );
$ttAtShareJs = $h( $ttAtScripts . '/atlas_share.js?v=' . $ttAtVer );

$maintext .= <<<HTML
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{$ttAtCss}">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/polygon-clipping@0.15.7/dist/polygon-clipping.umd.min.js"></script>
<script src="{$ttAtGeoJs}" defer></script>
<script src="{$ttAtViewsJs}" defer></script>
<script src="{$ttAtShareJs}" defer></script>
<script src="{$ttAtJs}" defer></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.11/dist/cdn.min.js" defer></script>
<h1>Atlas</h1>
<div class="tt-at" x-data="ttAtlasApp({$ttAtBoot})" x-cloak>
HTML;
$maintext .= tt_atlas_panel_html();
$maintext .= "</div>\n";
