<?php
/**
 * TEITOK action `typology`: how similar are the languages of a multilingual dependency-annotated
 * corpus (UD), and in what respect. WALS-style features counted per language (typology_features.php:
 * word orders, V2, pro-drop, case / gender / number inventories, inflectional richness), each language's
 * profile, and the ways typologists show similarity: a dendrogram, a similarity space (MDS), a
 * similarity matrix, a feature table with links to the hits. See dev/lenses-plan.md.
 *
 *   index.php?action=typology[&s=…]          the page (s: a share link's state, typology.js)
 *   POST op=state | feature (id) | recompute (id; admins)        JSON
 *
 * Settings: <typology lang="text_langcode" min_n="30"/> (the language attribute; the fewest hits a
 * feature needs in a language). Counting: typology_engine.php (pando, kept in tmp/typology/); the page:
 * typology_panel.php, typology.js, typology_math.js, typology_views.js.
 */

global $maintext;
require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/typology_engine.php';
require_once __DIR__ . '/typology_panel.php';

if ( ! isset( $maintext ) ) $maintext = '';

if ( ! function_exists( 'tt_typology_settings' ) ) {
	function tt_typology_settings() {
		$get = function ( $k, $d ) {
			$v = function_exists( 'getset' ) ? getset( "typology/$k", '' ) : '';
			return ( is_string( $v ) && trim( $v ) !== '' ) ? trim( $v ) : $d;
		};
		$lang = $get( 'lang', 'text_langcode' );
		return array(
			'lang' => preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $lang ) ? $lang : 'text_langcode',
			'min_n' => max( 1, (int) $get( 'min_n', '30' ) ),
		);
	}
}

$ttTyRoot = tt_fc_env_project_root();
$ttTyAdmin = tt_fc_env_is_admin();
$ttTyCfg = tt_typology_settings();
$ttTyOp = (string) ( $_REQUEST['op'] ?? '' );

if ( $ttTyOp !== '' ) {
	$reply = function ( $d ) {
		if ( ! headers_sent() ) header( 'Content-Type: application/json; charset=UTF-8' );
		print json_encode( $d, JSON_UNESCAPED_UNICODE );
		exit;
	};
	$in = json_decode( (string) file_get_contents( 'php://input' ), true );
	if ( ! is_array( $in ) ) $in = $_POST;
	$fail = function ( $msg ) use ( $reply ) { $reply( array( 'ok' => false, 'error' => $msg ) ); };
	$id = (string) ( $in['id'] ?? '' );
	switch ( $ttTyOp ) {
		case 'state':
			$reply( array( 'ok' => true, 'settings' => $ttTyCfg, 'admin' => $ttTyAdmin,
				'features' => tt_typology_catalogue( $ttTyRoot, $ttTyCfg['lang'] ) ) );
		case 'feature':
		case 'recompute':
			if ( $ttTyOp === 'recompute' && ! $ttTyAdmin ) $fail( "only the corpus's administrators can count a feature again" );
			$r = tt_typology_feature( $ttTyRoot, $id, $ttTyCfg['lang'], $ttTyOp === 'recompute' );
			if ( is_string( $r ) ) $fail( $r );
			$reply( array( 'ok' => true, 'id' => $id ) + $r );
	}
	$fail( 'unknown op' );
}

// cache buster: the module's newest file here (the installer puts the JS in Scripts/)
$ttTyVer = (string) max( array_map( function ( $f ) { return (int) @filemtime( $f ); }, array_merge( array( __FILE__ ), glob( __DIR__ . '/typology*' ) ?: array() ) ) );
$h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
$ttTyScripts = tt_fc_env_scripts_url( 'typology' );
$ttTyBoot = $h( json_encode( array( 'admin' => $ttTyAdmin,
	's' => preg_match( '/^[A-Za-z0-9_-]{1,12000}$/', (string) ( $_GET['s'] ?? '' ) ) ? (string) $_GET['s'] : '' ), JSON_UNESCAPED_UNICODE ) );
$ttTyCss = $h( $ttTyScripts . '/typology.css?v=' . $ttTyVer );
$ttTyJs = array_map( function ( $n ) use ( $h, $ttTyScripts, $ttTyVer ) { return $h( "$ttTyScripts/$n.js?v=$ttTyVer" ); },
	array( 'math' => 'typology_math', 'views' => 'typology_views', 'app' => 'typology' ) );

$maintext .= <<<HTML
<link rel="stylesheet" href="{$ttTyCss}">
<script src="{$ttTyJs['math']}" defer></script>
<script src="{$ttTyJs['views']}" defer></script>
<script src="{$ttTyJs['app']}" defer></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.11/dist/cdn.min.js" defer></script>
<h1>Typology</h1>
<div class="tt-ty" x-data="ttTypologyApp({$ttTyBoot})" x-cloak>
HTML;
$maintext .= tt_typology_panel_html();
$maintext .= "</div>\n";
