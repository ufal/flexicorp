<?php
/**
 * TEITOK action `typology`: how similar are the languages of a multilingual dependency-annotated
 * corpus (UD), and in what respect. WALS-style features counted per language (typology_features.php:
 * word orders, V2, pro-drop, case / gender / number inventories, inflectional richness), each language's
 * profile, and the ways typologists show similarity: a dendrogram, a similarity space (MDS), a
 * similarity matrix, a feature table with links to the hits. See dev/lenses-plan.md.
 *
 *   index.php?action=typology[&s=…]          the page (s: a share link's state, typology.js)
 *   POST op=state | kept (ids: all kept counts at once) | feature (id, or def: a definition to try)
 *        | recompute (id; admins) | save_feature | delete_feature | unhide | save_attributes | save_names
 *        (scope mine / corpus; writes need a login and the page's token, X-Typology-Token; the
 *        corpus's set needs an admin)                                                   JSON
 *
 * Settings: <typology lang="text_langcode" min_n="30"/> (the language attribute; the fewest hits a
 * feature needs in a language). Counting: typology_engine.php (pando, kept in tmp/typology/); the page:
 * typology_panel.php, typology.js, typology_math.js, typology_views.js.
 */

global $maintext;
require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/typology_engine.php';
require_once __DIR__ . '/typology_panel.php';
require_once __DIR__ . '/typology_editor_panel.php';

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
$ttTyFolder = tt_fc_env_user_folder();
$ttTyToken = tt_fc_env_session_token( 'typology' );
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
	$lang = $ttTyCfg['lang'];
	$merged = tt_typology_merged( $ttTyRoot, $ttTyFolder );
	$features = $merged['features'];
	// whose limits apply at FQS: admin for the built-in and the corpus's features (written by admins),
	// the user's own role for their own features and for features tried out on the page
	$userRole = $ttTyAdmin ? 'admin' : ( $ttTyFolder !== '' ? 'user' : 'visitor' );
	$state = function () use ( $ttTyRoot, $ttTyFolder, $lang, $ttTyCfg, $ttTyAdmin ) {
		$m = tt_typology_merged( $ttTyRoot, $ttTyFolder );
		$hidden = array_values( array_unique( array_merge(
			tt_typology_store_load( tt_typology_store_path( $ttTyRoot, '' ) )['hidden'],
			$ttTyFolder !== '' ? tt_typology_store_load( tt_typology_store_path( $ttTyRoot, $ttTyFolder ) )['hidden'] : array() ) ) );
		return array( 'ok' => true, 'settings' => $ttTyCfg, 'admin' => $ttTyAdmin, 'user' => $ttTyFolder,
			'cannot_count' => tt_typology_cannot_count( $ttTyRoot ), 'via' => tt_fc_pando_route( $ttTyRoot )['via'],
			'features' => tt_typology_catalogue( $ttTyRoot, $lang, $m['features'] ),
			'attributes' => (object) $m['attributes'], 'corpus_attributes' => (object) $m['corpus_attributes'],
			'my_attributes' => (object) $m['my_attributes'], 'hidden' => $hidden,
			'names' => (object) $m['names'], 'corpus_names' => (object) $m['corpus_names'] );
	};
	// writes: POST, the page's token, a login; the corpus's set: admins
	$write = function () use ( $in, $ttTyToken, $ttTyFolder, $ttTyAdmin, $ttTyRoot, $fail ) {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) $fail( 'POST only' );
		if ( ! hash_equals( $ttTyToken, (string) ( $_SERVER['HTTP_X_TYPOLOGY_TOKEN'] ?? '' ) ) ) $fail( 'the page has expired; reload it' );
		if ( $ttTyFolder === '' ) $fail( 'log in to keep features' );
		$corpus = ( $in['scope'] ?? '' ) === 'corpus';
		if ( $corpus && ! $ttTyAdmin ) $fail( "only the corpus's administrators can change the corpus's features" );
		return tt_typology_store_path( $ttTyRoot, $corpus ? '' : $ttTyFolder );
	};
	switch ( $ttTyOp ) {
		case 'state':
			$reply( $state() );
		case 'kept':
			$ids = array_values( array_filter( (array) ( $in['ids'] ?? array() ), 'is_string' ) );
			$reply( array( 'ok' => true, 'features' => (object) tt_typology_kept( $ttTyRoot, $features, $ids, $lang ) ) );
		case 'feature':
		case 'recompute':
			if ( $ttTyOp === 'recompute' && ! $ttTyAdmin ) $fail( "only the corpus's administrators can count a feature again" );
			if ( is_array( $in['def'] ?? null ) ) {   // a definition being edited: tried, not kept anywhere but the counts' cache
				$c = tt_typology_clean_feature( $in['def'] );
				if ( is_string( $c ) ) $fail( $c );
				list( $id, $feat ) = $c;
				$role = $userRole;
			} else {
				if ( ! isset( $features[ $id ] ) ) $fail( 'unknown feature' );
				$feat = $features[ $id ];
				$role = ( $feat['origin'] ?? 'builtin' ) === 'mine' ? $userRole : 'admin';
			}
			$cannot = tt_typology_cannot_count( $ttTyRoot );
			if ( $cannot !== '' && ( $ttTyOp === 'recompute' || ! is_file( tt_typology_cache_path( $ttTyRoot, $id, $feat, $lang ) ) ) ) $fail( $cannot );
			$r = tt_typology_count( $ttTyRoot, $id, $feat, $lang, $ttTyOp === 'recompute', null, $role );
			if ( is_string( $r ) ) $fail( $r );
			$reply( array( 'ok' => true, 'id' => $id, 'program' => tt_typology_program( $feat, $lang ) ) + $r );
		case 'save_feature':
			$path = $write();
			$c = tt_typology_clean_feature( (array) ( $in['def'] ?? array() ) );
			if ( is_string( $c ) ) $fail( $c );
			$store = tt_typology_store_load( $path );
			$store['features'][ $c[0] ] = $c[1];
			$store['hidden'] = array_values( array_diff( $store['hidden'], array( $c[0] ) ) );
			$err = tt_typology_store_save( $path, $store );
			if ( $err !== '' ) $fail( $err );
			$reply( $state() );
		case 'delete_feature':
			// an own feature goes; a built-in one is hidden (for the user, or for the corpus)
			$path = $write();
			$store = tt_typology_store_load( $path );
			if ( isset( $store['features'][ $id ] ) ) unset( $store['features'][ $id ] );
			elseif ( isset( tt_typology_features()[ $id ] ) ) $store['hidden'] = array_values( array_unique( array_merge( $store['hidden'], array( $id ) ) ) );
			else $fail( 'no such feature here' );
			$err = tt_typology_store_save( $path, $store );
			if ( $err !== '' ) $fail( $err );
			$reply( $state() );
		case 'unhide':
			$path = $write();
			$store = tt_typology_store_load( $path );
			$store['hidden'] = array_values( array_diff( $store['hidden'], array( $id ) ) );
			$err = tt_typology_store_save( $path, $store );
			if ( $err !== '' ) $fail( $err );
			$reply( $state() );
		case 'save_names':
			// the names given on the page replace the scope's list (an empty name removes one)
			$path = $write();
			$store = tt_typology_store_load( $path );
			$store['names'] = tt_typology_clean_names( array_merge( $store['names'], (array) ( $in['names'] ?? array() ) ) );
			$err = tt_typology_store_save( $path, $store );
			if ( $err !== '' ) $fail( $err );
			$reply( $state() );
		case 'save_attributes':
			$path = $write();
			$store = tt_typology_store_load( $path );
			$store['attributes'] = tt_typology_clean_attributes( (array) ( $in['attributes'] ?? array() ) );
			$err = tt_typology_store_save( $path, $store );
			if ( $err !== '' ) $fail( $err );
			$reply( $state() );
	}
	$fail( 'unknown op' );
}

// cache buster: the module's newest file here (the installer puts the JS in Scripts/)
$ttTyVer = (string) max( array_map( function ( $f ) { return (int) @filemtime( $f ); }, array_merge( array( __FILE__ ), glob( __DIR__ . '/typology*' ) ?: array() ) ) );
$h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
$ttTyScripts = tt_fc_env_scripts_url( 'typology' );
$ttTyBoot = $h( json_encode( array( 'admin' => $ttTyAdmin, 'token' => $ttTyToken, 'user' => $ttTyFolder,
	's' => preg_match( '/^[A-Za-z0-9_-]{1,12000}$/', (string) ( $_GET['s'] ?? '' ) ) ? (string) $_GET['s'] : '' ), JSON_UNESCAPED_UNICODE ) );
$ttTyCss = $h( $ttTyScripts . '/typology.css?v=' . $ttTyVer );
$ttTyJs = array_map( function ( $n ) use ( $h, $ttTyScripts, $ttTyVer ) { return $h( "$ttTyScripts/$n.js?v=$ttTyVer" ); },
	array( 'math' => 'typology_math', 'views' => 'typology_views', 'export' => 'typology_export', 'editor' => 'typology_editor', 'app' => 'typology' ) );

$maintext .= <<<HTML
<link rel="stylesheet" href="{$ttTyCss}">
<script src="{$ttTyJs['math']}" defer></script>
<script src="{$ttTyJs['views']}" defer></script>
<script src="{$ttTyJs['export']}" defer></script>
<script src="{$ttTyJs['editor']}" defer></script>
<script src="{$ttTyJs['app']}" defer></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.11/dist/cdn.min.js" defer></script>
<h1>Typology</h1>
<div class="tt-ty" x-data="ttTypologyApp({$ttTyBoot})" x-cloak>
HTML;
$maintext .= tt_typology_panel_html();
$maintext .= "</div>\n";
