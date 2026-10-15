<?php
/**
 * flexicorp → FQS warm-up: when the search page opens, the page asks (run=warm, once, after it
 * has loaded) and this tells FQS that the corpus is about to be queried (POST /warm), so FQS reads
 * its main index files into the file cache while the user types — as KonText's choice of a corpus
 * warms it. FQS answers at once (accepted / recent / busy / skipped); the reply is JSON and never
 * an error the page has to show: a warm-up that does not happen only costs the first query.
 */

if ( ! function_exists( 'tt_flexicorp_fqs_warm_call' ) ) {
	/** POST /warm for the probe's corpus, signed as the other FQS calls (tt_flexicorp_fqs_query_call). */
	function tt_flexicorp_fqs_warm_call( $probe ) {
		$url = rtrim( (string) ( $probe['http_url'] ?? '' ), '/' );
		$corpus = (string) ( $probe['corpus_id'] ?? '' );
		if ( $url === '' || $corpus === '' ) return array( 'ok' => false, 'status' => 'no FQS' );
		$headers = array( 'Content-Type: application/json', 'Accept: application/json' );
		$secret = function_exists( 'tt_flexicorp_fqs_secret' ) ? tt_flexicorp_fqs_secret() : '';
		if ( $secret !== '' ) {
			$now = time();
			$jwt = tt_flexicorp_make_hs256_jwt( array(
				'iss' => 'teitok-flexicorp',
				'sub' => $corpus,
				'role' => (string) ( $probe['request_role'] ?? 'visitor' ),
				'user' => tt_flexicorp_request_user(),
				'iat' => $now,
				'exp' => $now + 60,
			), $secret );
			if ( $jwt !== '' ) $headers[] = 'Authorization: Bearer ' . $jwt;
		}
		$resp = tt_flexicorp_http_json_request( 'POST', $url . '/warm', array( 'corpus' => $corpus ), $headers, 2.0 );
		if ( is_array( $resp ) && is_array( $resp['data'] ?? null ) ) return $resp['data'];
		return array( 'ok' => false, 'status' => 'no answer (HTTP ' . (int) ( $resp['status'] ?? 0 ) . ')' );
	}

	/** The reply to run=warm (JSON, then exit): FQS's answer, or why there was nothing to warm. */
	function tt_flexicorp_fqs_warm_reply( $probe, $routed ) {
		$ans = $routed ? tt_flexicorp_fqs_warm_call( $probe ) : array( 'ok' => true, 'status' => 'not on FQS' );
		if ( ! headers_sent() ) header( 'Content-Type: application/json; charset=UTF-8' );
		print json_encode( $ans, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
