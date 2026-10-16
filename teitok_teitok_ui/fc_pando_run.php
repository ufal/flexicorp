<?php
/**
 * A pando program for a project, wherever its index is: through FQS (POST /run) when FQS serves the
 * project's corpus — on a set-up where the indexes live with FQS on another machine (LINDAT), that is
 * the only way — else with the pando program on the project's own index (fc_teitok_env.php). For
 * lenses that count with fixed, server-side programs (typology.php): the program is not the
 * visitor's, so the call to FQS is signed as admin (no visitor limits on a whole-corpus count); the
 * result is cached by the caller.
 *
 *   tt_fc_pando_run( $root, $program[, $timeout, $role] ) → pando's JSON answer (array) or an error message
 *   tt_fc_pando_route( $root )         → array( 'via' => 'fqs' | 'local' | '', 'why' => …, … )
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/fqs-lib.php';

if ( ! function_exists( 'tt_fc_pando_route' ) ) {
	/** The FQS this TEITOK talks to: flexicorp/fqs_url, else FQS_URL, else the local default. */
	function tt_fc_fqs_base_url() {
		$u = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_url', '' ) ) : '';
		if ( $u === '' ) $u = trim( (string) ( getenv( 'FQS_URL' ) ?: '' ) );
		return rtrim( $u !== '' ? $u : 'http://127.0.0.1:8787', '/' );
	}

	/** The project's corpus id at FQS: flexicorp/fqs_corpus_id, else from the project folder. */
	function tt_fc_fqs_corpus_id( $root ) {
		$set = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_corpus_id', '' ) ) : '';
		return $set !== '' ? $set : tt_fqs_corpus_id_from_root( $root );
	}

	/** JSON over HTTP: array( 'status' => code (0: no answer), 'data' => decoded body or null ). */
	function tt_fc_http_json( $method, $url, $body, array $headers, $timeout ) {
		$headers = array_merge( array( 'Accept: application/json' ), $body !== null ? array( 'Content-Type: application/json' ) : array(), $headers );
		$payload = $body !== null ? json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array( CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
				CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => (int) ceil( $timeout ) ) );
			if ( $payload !== null ) curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
			$raw = curl_exec( $ch );
			$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		} else {
			$ctx = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => implode( "\r\n", $headers ),
				'content' => (string) $payload, 'timeout' => $timeout, 'ignore_errors' => true ) ) );
			$raw = @file_get_contents( $url, false, $ctx );
			$status = 0;
			// PHP 8.4+: http_get_last_response_headers(); before: the local $http_response_header
			$hdrs = function_exists( 'http_get_last_response_headers' ) ? ( http_get_last_response_headers() ?? array() ) : ( $http_response_header ?? array() );
			foreach ( $hdrs as $h ) if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $h, $m ) ) $status = (int) $m[1];
		}
		return array( 'status' => $raw === false ? 0 : $status, 'data' => is_string( $raw ) ? json_decode( $raw, true ) : null );
	}

	/**
	 * Where the project's programs run: FQS when it answers and has the corpus, else the local pando
	 * on the project's index, else nowhere ('why' says what is missing). Asked once per request.
	 */
	function tt_fc_pando_route( $root ) {
		static $memo = array();
		$key = rtrim( (string) $root, '/' );
		if ( isset( $memo[ $key ] ) ) return $memo[ $key ];
		$url = tt_fc_fqs_base_url();
		$id = tt_fc_fqs_corpus_id( $root );
		$r = tt_fc_http_json( 'GET', $url . '/corpora?include_noncurrent=true&q=' . rawurlencode( $id ), null, tt_fqs_bearer_headers( 'admin', 'teitok-lens', 60 ), 5 );
		$ids = array();
		foreach ( (array) ( $r['data']['corpora'] ?? array() ) as $c ) $ids[] = (string) ( $c['id'] ?? '' );
		if ( in_array( $id, $ids, true ) ) return $memo[ $key ] = array( 'via' => 'fqs', 'url' => $url, 'corpus' => $id, 'why' => '' );
		$index = rtrim( tt_fc_env_pando_index_dir( $root ), '/' );
		$pando = tt_fc_env_pando_bin( 'pando' );
		if ( $pando !== '' && is_file( "$index/corpus.info" ) ) return $memo[ $key ] = array( 'via' => 'local', 'index' => $index, 'why' => '' );
		$fqsWhy = $r['status'] === 0 ? "no FQS answers at $url" : "FQS at $url has no corpus '$id' (flexicorp/fqs_corpus_id names it)";
		$localWhy = $pando === '' ? 'pando is not installed here' : "no pando index at $index (<pando path=\"…\"/> names it)";
		return $memo[ $key ] = array( 'via' => '', 'why' => "$fqsWhy, and $localWhy" );
	}

	/**
	 * Run a pando program for the project: its JSON answer, or an error message. $role: whose limits
	 * FQS applies (admin for fixed, server-side programs; the user's role for queries a user wrote).
	 */
	function tt_fc_pando_run( $root, $program, $timeout = 600, $role = 'admin' ) {
		$route = tt_fc_pando_route( $root );
		if ( $route['via'] === 'fqs' ) {
			$r = tt_fc_http_json( 'POST', $route['url'] . '/run', array( 'corpus' => $route['corpus'], 'cql' => $program ),
				tt_fqs_bearer_headers( $role, 'teitok-lens', 120 ), $timeout );
			$j = $r['data'];
			if ( ! is_array( $j ) ) return 'FQS gave no answer (HTTP ' . $r['status'] . ')';
			if ( ( $j['ok'] ?? true ) === false || $r['status'] >= 400 ) {
				$e = $j['error'] ?? ( $j['message'] ?? 'error' );
				return 'pando (FQS): ' . ( is_array( $e ) ? ( $e['message'] ?? json_encode( $e ) ) : $e );
			}
			return $j;
		}
		if ( $route['via'] === 'local' ) {
			$cmd = escapeshellarg( tt_fc_env_pando_bin( 'pando' ) ) . ' --api --json ' . escapeshellarg( $route['index'] ) . ' ' . escapeshellarg( $program ) . ' 2>&1';
			$out = (string) shell_exec( $cmd );
			preg_match_all( '/^\{/m', $out, $starts, PREG_OFFSET_CAPTURE );
			if ( empty( $starts[0] ) ) return 'pando: ' . trim( substr( $out, 0, 300 ) );
			$j = json_decode( substr( $out, end( $starts[0] )[1] ), true );
			if ( ! is_array( $j ) ) return 'pando gave no answer';
			if ( isset( $j['ok'] ) && $j['ok'] === false ) {
				$e = $j['error'] ?? 'error';
				return 'pando: ' . ( is_array( $e ) ? ( $e['message'] ?? json_encode( $e ) ) : $e );
			}
			return $j;
		}
		return $route['why'];
	}
}
