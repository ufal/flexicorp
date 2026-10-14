<?php
/**
 * TEITOK action `classify`: classify concordance lines with a label set of your own (layers in
 * classify_store.php; interface in classify.js / classify.css). Stand-alone: hits come from
 * flexicorp's search request in the browser (action=flexicorp, run=query), so this module does not
 * depend on flexicorp.php. Rollout and design: flexicorp dev/OVERLAY-CLASSIFICATION-ROLLOUT.md (R1).
 *
 *   index.php?action=classify[&query=…][&layer=id]      the page
 *   POST op=list | get | save | label   (ajax=1, JSON; writes need a login and the page's token)
 *   GET  op=export&layer=id             CSV of the items
 */

global $maintext;
require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/classify_store.php';
require_once __DIR__ . '/classify_panel.php';

if ( ! isset( $maintext ) ) $maintext = '';

$ttClassifyRoot = tt_fc_env_project_root();
$ttClassifyUser = tt_fc_env_user();
$ttClassifyAdmin = tt_fc_env_is_admin();
$ttClassifyToken = tt_fc_env_session_token( 'classify' );

$ttClassifyOp = (string) ( $_REQUEST['op'] ?? '' );

if ( $ttClassifyOp === 'export' ) {
	$layer = tt_classify_load( $ttClassifyRoot, (string) ( $_GET['layer'] ?? '' ) );
	if ( ! $layer || ! tt_classify_can_read( $layer, $ttClassifyUser, $ttClassifyAdmin ) ) {
		http_response_code( 404 );
		print 'No such layer';
		exit;
	}
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $layer['id'] . '.csv"' );
	print tt_classify_csv( $layer );
	exit;
}

if ( $ttClassifyOp !== '' ) {
	$reply = function ( $data ) {
		if ( ! headers_sent() ) header( 'Content-Type: application/json; charset=UTF-8' );
		print json_encode( $data, JSON_UNESCAPED_UNICODE );
		exit;
	};
	$in = json_decode( (string) file_get_contents( 'php://input' ), true );
	if ( ! is_array( $in ) ) $in = $_POST;
	if ( ! is_dir( $ttClassifyRoot . '/Resources' ) ) $reply( array( 'ok' => false, 'error' => 'not a corpus folder' ) );
	$write = in_array( $ttClassifyOp, array( 'save', 'label' ), true );
	if ( $write ) {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) $reply( array( 'ok' => false, 'error' => 'POST only' ) );
		$sent = (string) ( $_SERVER['HTTP_X_CLASSIFY_TOKEN'] ?? '' );
		if ( ! hash_equals( $ttClassifyToken, $sent ) ) $reply( array( 'ok' => false, 'error' => 'the page has expired; reload it' ) );
	}
	$asResult = function ( $r ) use ( $reply ) {
		if ( is_string( $r ) ) $reply( array( 'ok' => false, 'error' => $r ) );
		return $r;
	};
	switch ( $ttClassifyOp ) {
		case 'list':
			$reply( array( 'ok' => true, 'layers' => tt_classify_list( $ttClassifyRoot, $ttClassifyUser, $ttClassifyAdmin ),
				'user' => $ttClassifyUser ) );
		case 'get':
			$layer = tt_classify_load( $ttClassifyRoot, (string) ( $in['layer'] ?? '' ) );
			if ( ! $layer || ! tt_classify_can_read( $layer, $ttClassifyUser, $ttClassifyAdmin ) ) $reply( array( 'ok' => false, 'error' => 'no such layer' ) );
			$layer['can_edit'] = tt_classify_can_edit( $layer, $ttClassifyUser, $ttClassifyAdmin );
			$reply( array( 'ok' => true, 'layer' => $layer ) );
		case 'save':
			$layer = $asResult( tt_classify_save_definition( $ttClassifyRoot, $in, $ttClassifyUser, $ttClassifyAdmin ) );
			$reply( array( 'ok' => true, 'layer' => $layer ) );
		case 'label':
			$layer = $asResult( tt_classify_set_label( $ttClassifyRoot, (string) ( $in['layer'] ?? '' ), $in, $ttClassifyUser, $ttClassifyAdmin ) );
			$reply( array( 'ok' => true, 'item_count' => count( $layer['items'] ) ) );
	}
	$reply( array( 'ok' => false, 'error' => 'unknown op' ) );
}

// The page: the interface boots from classify.js (Alpine), next to flexicorp's scripts.
$ttClassifyScripts = tt_fc_env_scripts_url( 'classify' );
$ttClassifyVer = (string) @filemtime( __DIR__ . '/classify.js' );
$ttClassifyBoot = htmlspecialchars( json_encode( array(
	'token' => $ttClassifyToken,
	'user' => $ttClassifyUser,
	'query' => (string) ( $_GET['query'] ?? '' ),
	'layer' => (string) ( $_GET['layer'] ?? '' ),
), JSON_UNESCAPED_UNICODE ), ENT_QUOTES, 'UTF-8' );
$h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
$ttClassifyCss = $h( $ttClassifyScripts . '/classify.css?v=' . $ttClassifyVer );
$ttClassifyJs = $h( $ttClassifyScripts . '/classify.js?v=' . $ttClassifyVer );

$maintext .= <<<HTML
<link rel="stylesheet" href="{$ttClassifyCss}">
<script src="{$ttClassifyJs}" defer></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.11/dist/cdn.min.js" defer></script>
<h1>Classify</h1>
<div class="tt-classify" x-data="ttClassifyApp({$ttClassifyBoot})" x-cloak>
HTML;
$maintext .= tt_classify_panel_html();
$maintext .= "</div>\n";
