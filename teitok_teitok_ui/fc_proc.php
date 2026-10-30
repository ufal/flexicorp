<?php
/**
 * fc_proc.php — run a program flexicorp needs for a request (flexicorp-pando, pando) within a time limit, so
 * that a query running too long is stopped here, with a message, before PHP's own limit ends the request
 * without an answer (the browser then gets an empty response and the page cannot say what happened).
 *
 * The limit: the flexicorp/query_timeout setting (seconds), else 5 s below max_execution_time (0 there: none).
 */

if ( ! function_exists( 'tt_fc_proc_limit' ) ) {
	/** The time a request's program may take, in seconds; 0 for no limit. */
	function tt_fc_proc_limit() {
		$set = function_exists( 'getset' ) ? (int) getset( 'flexicorp/query_timeout', 0 ) : 0;
		if ( $set > 0 ) return $set;
		$php = (int) ini_get( 'max_execution_time' );
		return $php > 0 ? max( 5, $php - 5 ) : 0;
	}
}

if ( ! function_exists( 'tt_fc_proc_run' ) ) {
	/**
	 * Run $cmd (a shell command line): array( 'ok' => started, 'stdout', 'stderr', 'timed_out', 'limit', 'seconds' ).
	 * A run past the limit is stopped (TERM, then KILL) and returns what it had written.
	 */
	function tt_fc_proc_run( $cmd, $limit = null ) {
		$limit = $limit === null ? tt_fc_proc_limit() : (int) $limit;
		$out = array( 'ok' => false, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'limit' => $limit, 'seconds' => 0.0 );
		$t0 = microtime( true );
		$proc = @proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $proc ) ) return $out;
		$out['ok'] = true;
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$open = array( 1 => $pipes[1], 2 => $pipes[2] );
		while ( $open ) {
			$left = $limit > 0 ? $limit - ( microtime( true ) - $t0 ) : 1.0;
			if ( $limit > 0 && $left <= 0 ) {
				$out['timed_out'] = true;
				break;
			}
			$read = array_values( $open );
			$w = $e = null;
			$n = @stream_select( $read, $w, $e, (int) floor( min( $left, 1.0 ) ), (int) ( fmod( min( $left, 1.0 ), 1.0 ) * 1e6 ) );
			if ( $n === false ) break;
			foreach ( $read as $r ) {
				$k = $r === $pipes[1] ? 1 : 2;
				$chunk = fread( $r, 65536 );
				if ( $chunk === '' || $chunk === false ) {
					if ( feof( $r ) ) unset( $open[ $k ] );
					continue;
				}
				$out[ $k === 1 ? 'stdout' : 'stderr' ] .= $chunk;
			}
		}
		if ( $out['timed_out'] ) {
			proc_terminate( $proc, 15 );
			usleep( 200000 );
			$st = proc_get_status( $proc );
			if ( $st && $st['running'] ) proc_terminate( $proc, 9 );
		}
		foreach ( array( 1, 2 ) as $k ) if ( is_resource( $pipes[ $k ] ) ) fclose( $pipes[ $k ] );
		proc_close( $proc );
		$out['seconds'] = microtime( true ) - $t0;
		return $out;
	}
}
