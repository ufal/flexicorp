<?php
/**
 * multiedit (index.php?action=multiedit): change many tokens found by a query, after looking at them — the
 * successor of TEITOK's cqpedit, through flexicorp (pando). See dev/MULTIEDIT-DESIGN.md.
 *
 *   ?query=…                 the page, with the query filled in (flexicorp links here)
 *   act=list    (GET)        the query's matches: kept as a fixed list; the overview of the fields to change
 *   act=page    (GET)        one page of that list, with what the XML says now
 *   act=add     (POST JSON)  changes from a page into the user's draft
 *   act=draft   (GET)        the draft;  act=clear (POST) empties it
 *   act=run     (POST)       hand the draft to the background worker
 *   act=job     (GET)        a job: status, progress, what it did;  act=jobs: the latest
 *   act=undo    (POST)       a job undoing a finished one
 *
 * Who: logged-in users (as cqpedit); <flexicorp multiedit="admin"/> admins only, "off" nobody.
 * Word forms only with <flexicorp multiedit_forms="1"/>. Posts are JSON, never multipart.
 */

	// the requests answer JSON: PHP's warnings (TEITOK's getset() warns about every setting that is not there) must not
	// end up in front of it; what is printed before the answer is discarded ($me_json)
	if ( ( $_GET['act'] ?? '' ) !== '' ) {
		ini_set( 'display_errors', '0' );
		ob_start();
	}
	check_login();
	require_once __DIR__ . '/multiedit_query.php';
	require_once __DIR__ . '/multiedit_xml.php';
	require_once __DIR__ . '/multiedit_jobs.php';

	$me_who = strtolower( trim( (string) getset( 'flexicorp/multiedit', '' ) ) );
	$me_admin = ( $user['permissions'] ?? '' ) === 'admin';
	if ( $me_who === 'off' || ( $me_who === 'admin' && ! $me_admin ) ) {
		fatal( 'Changing tokens from a query is not available to you in this corpus.' );
	}
	$me_user = (string) $username;
	$me_act = $_GET['act'] ?? '';
	$me_fields = tt_me_fields();
	$me_cap = max( 100, (int) getset( 'flexicorp/multiedit_max', 50000 ) );

	$me_json = function ( $data, $code = 200 ) {
		while ( ob_get_level() > 0 ) ob_end_clean();
		http_response_code( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		print json_encode( $data, JSON_UNESCAPED_UNICODE );
		exit;
	};
	$me_post = function () use ( $me_json ) {
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) $me_json( array( 'error' => 'POST only' ), 405 );
		$in = json_decode( (string) file_get_contents( 'php://input' ), true );
		return is_array( $in ) ? $in : array();
	};

	if ( $me_act === 'list' ) {
		// the matches, kept as a fixed list; per label the fields to change (for the overview, grouping, sorting)
		list( $q, $labels, $target, $head, $err ) = tt_me_prepare( $_GET['query'] ?? '', (string) ( $_GET['target'] ?? '' ), (string) ( $_GET['head'] ?? '' ) );
		if ( $err !== '' ) $me_json( array( 'error' => $err, 'labels' => $labels ), 400 );
		// several labelled tokens, none chosen: which one to edit (and which, if any, to attach to)
		if ( $target === '' ) $me_json( array( 'choose' => $labels, 'query' => $q ) );
		$want = array_values( array_intersect( (array) ( $_GET['fields'] ?? array() ), array_keys( $me_fields ) ) );
		if ( ! $want ) $me_json( array( 'error' => 'Choose what to change.' ), 400 );
		$extra = array();
		foreach ( $labels as $l ) {
			$extra[] = "$l.form";
			foreach ( $want as $f ) if ( $f !== 'head' ) $extra[] = "$l.$f";
		}
		$res = tt_me_run( tt_me_ids_program( $q, $target, $head, $extra, $me_cap + 1 ) );
		if ( isset( $res['error'] ) ) $me_json( array( 'error' => 'The query did not run: ' . $res['error'] ), 400 );
		$total = (int) ( $res['total_matches'] ?? 0 );
		if ( $total > $me_cap ) {
			$me_json( array( 'error' => number_format( $total ) . " matches: more than $me_cap. Narrow the query." ), 400 );
		}
		$set = tt_me_set_make( $q, $labels, tt_me_rows( $res ), $total, $me_user, $target, $head );
		$overview = array();
		foreach ( $set['rows'] as $r ) {
			foreach ( $r['keys'] as $k => $v ) {
				if ( substr( $k, -5 ) === '.form' && $k !== "$target.form" ) continue;
				$overview[ $k ][ $v ] = ( $overview[ $k ][ $v ] ?? 0 ) + 1;
			}
		}
		foreach ( $overview as $k => $vals ) arsort( $overview[ $k ] );
		$me_json( array( 'set' => $set['id'], 'query' => $q, 'labels' => $labels, 'target' => $target, 'head' => $head, 'fields' => $want, 'total' => $total,
			'overview' => $overview, 'sortkeys' => array_keys( $set['rows'][0]['keys'] ?? array() ) ) );
	}

	if ( $me_act === 'page' ) {
		$set = tt_me_set_load( $_GET['set'] ?? '', $me_user );
		if ( ! $set ) $me_json( array( 'error' => 'This list is no longer there: show the matches again.' ), 404 );
		$size = in_array( (int) ( $_GET['size'] ?? 100 ), array( 100, 250, 500, 1000 ), true ) ? (int) $_GET['size'] : 100;
		$sort = (string) ( $_GET['sort'] ?? '' );
		if ( $sort !== '' && ! array_key_exists( $sort, $set['rows'][0]['keys'] ?? array() ) ) $sort = '';
		$want = array_values( array_intersect( (array) ( $_GET['fields'] ?? array() ), array_keys( $me_fields ) ) );
		$rows = tt_me_set_page( $set, (int) ( $_GET['from'] ?? 0 ), $size, $sort );
		$me_json( array( 'rows' => tt_me_page( $rows, $want ), 'total' => count( $set['rows'] ), 'from' => (int) ( $_GET['from'] ?? 0 ), 'size' => $size ) );
	}

	if ( $me_act === 'add' ) {
		$in = $me_post();
		$set = tt_me_set_load( (string) ( $in['set'] ?? '' ), $me_user );
		if ( ! $set ) $me_json( array( 'error' => 'This list is no longer there: show the matches again.' ), 404 );
		list( $draft, $problems ) = tt_me_add( $set, (array) ( $in['changes'] ?? array() ), $me_fields, $me_user );
		$me_json( array( 'draft' => tt_me_job_summary( $draft ), 'problems' => $problems ) );
	}

	if ( $me_act === 'draft' ) {
		$draft = tt_me_draft( $me_user, false );
		$me_json( array( 'draft' => $draft ? tt_me_job_summary( $draft ) + array( 'list' => array_slice( $draft['changes'], 0, 2000 ) ) : null ) );
	}

	if ( $me_act === 'clear' ) {
		$me_post();
		$draft = tt_me_draft( $me_user, false );
		if ( $draft ) @unlink( tt_me_job_file( $draft['id'] ) );
		$me_json( array( 'ok' => 1 ) );
	}

	if ( $me_act === 'run' ) {
		$me_post();
		$draft = tt_me_draft( $me_user, false );
		if ( ! $draft || ! $draft['changes'] ) $me_json( array( 'error' => 'There are no changes to make.' ), 400 );
		$draft['status'] = 'queued';
		$draft['queued'] = date( 'Y-m-d H:i:s' );
		tt_me_job_save( $draft );
		$why = tt_me_start( $draft );
		if ( $why !== '' ) {
			$draft['status'] = 'draft';
			tt_me_job_save( $draft );
			$me_json( array( 'error' => "The changes could not be started: $why" ), 500 );
		}
		$me_json( array( 'job' => $draft['id'] ) );
	}

	if ( $me_act === 'job' ) {
		$job = tt_me_job_load( $_GET['id'] ?? '' );
		if ( ! $job || $job['status'] === 'draft' ) $me_json( array( 'error' => 'no such job' ), 404 );
		$details = array();
		foreach ( (array) ( $job['results'] ?? array() ) as $r ) {
			$c = $job['changes'][ $r['i'] ];
			$details[] = array( 'doc' => $c['doc'], 'id' => $c['id'], 'form' => $c['form'], 'field' => $c['field'], 'old' => $c['old'],
				'new' => $c['new'], 'result' => $r['result'], 'why' => $r['why'] ?? '' );
		}
		$me_json( tt_me_job_summary( $job ) + array( 'details' => $details,
			'may_undo' => $job['status'] === 'done' && empty( $job['undone_by'] ) && ( $job['user'] === $me_user || $me_admin ) ) );
	}

	if ( $me_act === 'jobs' ) {
		$me_json( array( 'jobs' => array_values( array_filter( tt_me_jobs(), function ( $j ) { return $j['status'] !== 'draft'; } ) ) ) );
	}

	if ( $me_act === 'undo' ) {
		$in = $me_post();
		$job = tt_me_job_load( (string) ( $in['id'] ?? '' ) );
		if ( ! $job || $job['status'] !== 'done' || ! empty( $job['undone_by'] ) ) $me_json( array( 'error' => 'This job cannot be undone.' ), 400 );
		if ( $job['user'] !== $me_user && ! $me_admin ) $me_json( array( 'error' => 'Only who made it, or an admin, can undo a job.' ), 403 );
		$undo = tt_me_undo_job( $job, $me_user );
		if ( ! $undo['changes'] ) $me_json( array( 'error' => 'The job changed nothing.' ), 400 );
		tt_me_job_save( $undo );
		$why = tt_me_start( $undo );
		if ( $why !== '' ) {
			@unlink( tt_me_job_file( $undo['id'] ) );
			$me_json( array( 'error' => "The undo could not be started: $why" ), 500 );
		}
		$me_json( array( 'job' => $undo['id'] ) );
	}

	// ---- the page
	$me_h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
	// the scripts are where flexicorp's are (the shared project's Scripts/, as a rule): flexicorp's own address for them,
	// else TEITOK's getjsurl()
	require_once __DIR__ . '/flexicorp_query.php';
	flexicorp_query_load_engine_lib();
	$me_base = function_exists( 'tt_flexicorp_ecscripts_base_url' ) ? rtrim( (string) tt_flexicorp_ecscripts_base_url(), '/' ) : '';
	if ( $me_base === '' && function_exists( 'getjsurl' ) ) $me_base = dirname( (string) getjsurl( 'multiedit' ) );
	if ( in_array( $me_base, array( '', '.', '/' ), true ) || $me_base === dirname( '/x' ) ) $me_base = 'Scripts';
	$me_ver = @filemtime( __DIR__ . '/multiedit.js' ) ?: 1;
	$me_tree = is_file( 'Sources/deptree-edit.php' ) || ( ! empty( $sharedfolder ) && is_file( "$sharedfolder/Sources/deptree-edit.php" ) );
	$me_cfg = array(
		'base' => "index.php?action=$action",
		'query' => (string) ( $_GET['query'] ?? '' ),
		'fields' => $me_fields,
		'admin' => $me_admin,
		'tree' => $me_tree,
	);
	$maintext .= "<h1>{%Change tokens from a query}</h1>
		<link rel='stylesheet' href='" . $me_h( "$me_base/multiedit.css?v=$me_ver" ) . "'>
		<div id='me-app' class='me-app'>
		<p class='me-intro'>{%Find the tokens with a query, look at what they are now, and change the ones you tick.}
		{%A query of one token edits that token; in a longer query, label the tokens} (<code>[lemma=\"ser\"] edit:[lemma=\"que\"]</code>)
		{%and choose which one to edit} ({%a token labelled} <code>edit:</code> {%is taken by itself}). {%The ticked tokens can also be attached to another labelled token}
		(<code>head:[upos=\"VERB\"] [] edit:[lemma=\"que\"]</code>). {%The other labels group and sort the matches.}</p>
		<div class='me-define'></div>
		<div class='me-msg' aria-live='polite'></div>
		<div class='me-overview'></div>
		<div class='me-list'></div>
		<div class='me-draft'></div>
		<div class='me-job'></div>
		<h2>{%Earlier changes}</h2><div class='me-jobs'></div>
		</div>
		<script src='" . $me_h( "$me_base/multiedit.js?v=$me_ver" ) . "'></script>
		<script>ME.init(" . json_encode( $me_cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ");</script>";
?>
