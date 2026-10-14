<?php
/**
 * TEITOK action `custom_layers`: classify concordance lines with attributes and values of your own
 * (storage: custom_layers_store.php; interface: custom_layers.js / .css). Hits come from flexicorp's
 * search request in the browser (action=flexicorp, run=query), so nothing here depends on flexicorp.php.
 * Rollout and design: flexicorp dev/OVERLAY-CLASSIFICATION-ROLLOUT.md.
 *
 *   index.php?action=custom_layers[&query=…][&task=id]                 the page
 *   POST op=state | save_attribute | add_reference | save_task | label | others   (JSON; writes need a
 *        login and the page's token, sent in the X-Custom-Layers-Token header)
 *   GET  op=export&task=id                                             CSV of your values for a task
 */

global $maintext;
require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/custom_layers_store.php';
require_once __DIR__ . '/custom_layers_panel.php';

if ( ! isset( $maintext ) ) $maintext = '';

$ttClRoot = tt_fc_env_project_root();
$ttClUser = tt_fc_env_user();
$ttClFolder = tt_fc_env_user_folder( $ttClUser );
$ttClToken = tt_fc_env_session_token( 'custom_layers' );
$ttClOp = (string) ( $_REQUEST['op'] ?? '' );

if ( $ttClOp === 'export' ) {
	$data = tt_cl_load( $ttClRoot, $ttClFolder );
	$task = $data['tasks'][ (string) ( $_GET['task'] ?? '' ) ] ?? null;
	if ( $ttClFolder === '' || ! $task ) {
		http_response_code( 404 );
		print 'No such task';
		exit;
	}
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $task['id'] . '.csv"' );
	print tt_cl_csv( $data, $task );
	exit;
}

if ( $ttClOp !== '' ) {
	$reply = function ( $d ) {
		if ( ! headers_sent() ) header( 'Content-Type: application/json; charset=UTF-8' );
		print json_encode( $d, JSON_UNESCAPED_UNICODE );
		exit;
	};
	$in = json_decode( (string) file_get_contents( 'php://input' ), true );
	if ( ! is_array( $in ) ) $in = $_POST;
	if ( ! is_dir( $ttClRoot . '/Resources' ) ) $reply( array( 'ok' => false, 'error' => 'not a corpus folder' ) );
	if ( $ttClFolder === '' ) $reply( array( 'ok' => false, 'error' => 'log in to use custom layers' ) );
	if ( in_array( $ttClOp, array( 'save_attribute', 'add_reference', 'save_task', 'label' ), true ) ) {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) $reply( array( 'ok' => false, 'error' => 'POST only' ) );
		if ( ! hash_equals( $ttClToken, (string) ( $_SERVER['HTTP_X_CUSTOM_LAYERS_TOKEN'] ?? '' ) ) ) {
			$reply( array( 'ok' => false, 'error' => 'the page has expired; reload it' ) );
		}
	}
	$state = function () use ( $ttClRoot, $ttClFolder ) {
		$data = tt_cl_load( $ttClRoot, $ttClFolder );
		return array( 'ok' => true, 'attributes' => array_values( tt_cl_resolved_attributes( $ttClRoot, $data ) ),
			'tasks' => array_values( $data['tasks'] ), 'docs' => (object) $data['docs'],
			'shared' => tt_cl_shared_attributes( $ttClRoot, $ttClFolder ), 'user' => $ttClFolder );
	};
	$done = function ( $r ) use ( $reply, $state ) {
		if ( is_string( $r ) ) $reply( array( 'ok' => false, 'error' => $r ) );
		$reply( $state() );
	};
	switch ( $ttClOp ) {
		case 'state':
			$reply( $state() );
		case 'save_attribute':
			$done( tt_cl_save_attribute( $ttClRoot, $ttClUser, $in ) );
		case 'add_reference':
			$done( tt_cl_add_reference( $ttClRoot, $ttClUser, (string) ( $in['owner'] ?? '' ), (string) ( $in['key'] ?? '' ) ) );
		case 'save_task':
			$done( tt_cl_save_task( $ttClRoot, $ttClUser, $in ) );
		case 'label':
			$r = tt_cl_set_values( $ttClRoot, $ttClUser, $in );
			if ( is_string( $r ) ) $reply( array( 'ok' => false, 'error' => $r ) );
			$reply( array( 'ok' => true ) );
		case 'others':
			$data = tt_cl_load( $ttClRoot, $ttClFolder );
			$task = $data['tasks'][ (string) ( $in['task'] ?? '' ) ] ?? null;
			if ( ! $task ) $reply( array( 'ok' => false, 'error' => 'no such task' ) );
			$owners = array();
			foreach ( tt_cl_resolved_attributes( $ttClRoot, $data ) as $k => $at ) {
				if ( in_array( $k, $task['attributes'], true ) ) $owners[ $k ] = $at['owner'] !== '' ? $at['owner'] : $ttClFolder;
			}
			$reply( array( 'ok' => true, 'others' => (object) tt_cl_others_values( $ttClRoot, $ttClFolder, $owners ) ) );
	}
	$reply( array( 'ok' => false, 'error' => 'unknown op' ) );
}

// The page: the interface boots from custom_layers.js (Alpine).
$ttClScripts = tt_fc_env_scripts_url( 'custom_layers' );
$ttClVer = (string) @filemtime( __DIR__ . '/custom_layers.js' );
$ttClBoot = htmlspecialchars( json_encode( array(
	'token' => $ttClToken,
	'user' => $ttClFolder,
	'query' => (string) ( $_GET['query'] ?? '' ),
	'task' => (string) ( $_GET['task'] ?? '' ),
), JSON_UNESCAPED_UNICODE ), ENT_QUOTES, 'UTF-8' );
$h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
$ttClCss = $h( $ttClScripts . '/custom_layers.css?v=' . $ttClVer );
$ttClJs = $h( $ttClScripts . '/custom_layers.js?v=' . $ttClVer );

$maintext .= <<<HTML
<link rel="stylesheet" href="{$ttClCss}">
<script src="{$ttClJs}" defer></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.15.11/dist/cdn.min.js" defer></script>
<h1>Custom layers</h1>
<div class="tt-cl" x-data="ttCustomLayersApp({$ttClBoot})" x-cloak>
HTML;
$maintext .= tt_cl_panel_html();
$maintext .= "</div>\n";
