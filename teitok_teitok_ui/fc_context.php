<?php
/**
 * fc_context.php — the wider context around a corpus position from the project's own index
 * (index.php?action=flexicorp&act=context&pos=…&left=…&right=…[&sentence=1]), for when FQS does not serve
 * the corpus: flexicorp-pando answers pando-server's /context on the local index (--request). The answer
 * has the shape of action=fqs_query&op=context: {"ok", "op", "result": {left, match, right, doc_id, …}} or
 * {"ok": false, "error"}.
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/fc_bin.php';
require_once __DIR__ . '/fc_proc.php';

if ( ! function_exists( 'tt_fc_context_local' ) ) {
	/** The context around $pos: array( HTTP status, payload ). */
	function tt_fc_context_local( $root, array $in ) {
		$int = function ( $k, $def, $min, $max ) use ( $in ) {
			return isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? max( $min, min( $max, (int) $in[ $k ] ) ) : $def;
		};
		$pos = $int( 'pos', -1, -1, PHP_INT_MAX );
		if ( $pos < 0 ) return array( 400, array( 'ok' => false, 'error' => 'pos is required' ) );
		$qs = array( 'pos' => $pos, 'left' => $int( 'left', 40, 0, 500 ), 'right' => $int( 'right', 40, 0, 500 ) );
		if ( ! empty( $in['sentence'] ) && $in['sentence'] !== '0' ) $qs['sentence'] = '1';
		$bin = tt_fc_bin( 'flexicorp-pando' );
		if ( $bin === '' ) return array( 503, array( 'ok' => false, 'error' => tt_fc_bin_missing( array( 'flexicorp-pando' ) ) ) );
		$cmd = escapeshellarg( $bin ) . ' --project-root ' . escapeshellarg( (string) $root )
			. ' --index-dir ' . escapeshellarg( tt_fc_env_pando_index_dir( $root ) )
			. ' --request ' . escapeshellarg( '/context?' . http_build_query( $qs ) );
		$run = tt_fc_proc_run( $cmd );
		$j = json_decode( (string) $run['stdout'], true );
		if ( ! is_array( $j ) ) {
			$why = $run['timed_out'] ? 'stopped after ' . (int) $run['limit'] . ' seconds' : trim( (string) $run['stderr'] );
			return array( 500, array( 'ok' => false, 'error' => 'no context' . ( $why !== '' ? ": $why" : '' ) ) );
		}
		if ( empty( $j['ok'] ) ) return array( 400, array( 'ok' => false, 'error' => (string) ( $j['error'] ?? 'no context' ) ) );
		unset( $j['ok'] );
		return array( 200, array( 'ok' => true, 'op' => 'context', 'result' => $j ) );
	}
}

if ( ! function_exists( 'tt_fc_context_send' ) ) {
	/** Answer the request with the context as JSON, and end it. */
	function tt_fc_context_send( $root ) {
		list( $status, $payload ) = tt_fc_context_local( $root, $_REQUEST );
		while ( ob_get_level() > 0 ) ob_end_clean();
		if ( ! headers_sent() ) {
			http_response_code( $status );
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Cache-Control: no-store' );
		}
		print json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
