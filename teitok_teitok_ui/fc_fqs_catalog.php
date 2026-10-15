<?php
/**
 * flexicorp → FQS catalogue over HTTP: whether a corpus is registered in the FQS this project
 * queries, and with which engine. FQS normally runs on another machine than TEITOK (or KonText),
 * so its catalogue file is not here to look in; FQS's own `GET /corpora` is (q= narrows it down;
 * the id must match exactly). The local `fqs corpora show` lookup (flexicorp.php's probe) stays for
 * FQS on the same machine.
 *
 * A project can also be a search-only view of a corpus that only FQS has (no local index): its
 * engine is then available because FQS serves it (tt_flexicorp_fqs_serves_engine), and the
 * settings `<flexicorp fqs_url="…" fqs_corpus_id="…"/>` say which FQS and which corpus.
 */

if ( ! function_exists( 'tt_flexicorp_fqs_http_corpus_row' ) ) {
	/** The catalogue entry of `$corpusId` in the FQS at the probe's URL, or null (signed as admin when there is a secret). */
	function tt_flexicorp_fqs_http_corpus_row( $probe, $corpusId ) {
		$url = rtrim( (string) ( $probe['http_url'] ?? '' ), '/' );
		$corpusId = trim( (string) $corpusId );
		if ( $url === '' || $corpusId === '' || empty( $probe['http_running'] ) ) return null;
		$headers = function_exists( 'tt_flexicorp_fqs_headers_with_optional_jwt' )
			? tt_flexicorp_fqs_headers_with_optional_jwt( $probe, 'admin' )
			: array( 'Accept: application/json' );
		$resp = tt_flexicorp_http_json_request( 'GET',
			$url . '/corpora?request_role=admin&include_noncurrent=true&q=' . rawurlencode( $corpusId ), null, $headers, 3.0 );
		$rows = is_array( $resp['data']['corpora'] ?? null ) ? $resp['data']['corpora'] : array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $corpusId ) return $row;
		}
		return null;
	}

	/** The engine FQS answers a catalogue entry with: pando or cqp (preferred_backend, else the first available one). */
	function tt_flexicorp_fqs_row_engine( $row ) {
		if ( ! is_array( $row ) ) return '';
		$pb = strtolower( trim( (string) ( $row['preferred_backend'] ?? '' ) ) );
		if ( $pb === 'pando' || $pb === 'cqp' ) return $pb;
		$avail = $row['settings']['available_backends'] ?? array();
		foreach ( is_array( $avail ) ? $avail : array() as $b ) {
			$b = strtolower( trim( (string) $b ) );
			if ( $b === 'pando' || $b === 'cqp' ) return $b;
		}
		$kind = (string) ( $row['source_kind'] ?? '' );
		return strpos( $kind, 'pando' ) !== false ? 'pando' : ( strpos( $kind, 'cqp' ) !== false || strpos( $kind, 'cwb' ) !== false ? 'cqp' : '' );
	}

	/** The corpus id this project asks FQS for: flexicorp/fqs_corpus_id, else from the project folder. */
	function tt_flexicorp_fqs_corpus_id_for( $projectRoot ) {
		$set = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_corpus_id', '' ) ) : '';
		return $set !== '' ? $set : tt_flexicorp_corpus_id_from_root( $projectRoot );
	}

	/** Does FQS serve this project's corpus with `$engine` (pando / cqp) without a local index? */
	function tt_flexicorp_fqs_serves_engine( $projectRoot, $engine ) {
		static $engines = array();
		if ( ! function_exists( 'tt_flexicorp_fqs_probe' ) ) return false;
		$probe = tt_flexicorp_fqs_probe( $projectRoot, false );
		if ( ! is_array( $probe ) || empty( $probe['ready_for_queries'] ) ) return false;
		// known from the HTTP lookup; else (found by the local fqs program) asked once
		$key = rtrim( (string) $projectRoot, '/' );
		if ( ! isset( $engines[ $key ] ) ) {
			$engines[ $key ] = (string) ( $probe['remote_engine'] ?? '' );
			if ( $engines[ $key ] === '' ) $engines[ $key ] = tt_flexicorp_fqs_row_engine( tt_flexicorp_fqs_http_corpus_row( $probe, $probe['corpus_id'] ?? '' ) );
		}
		return $engines[ $key ] === $engine;
	}
}
