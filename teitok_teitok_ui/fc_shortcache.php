<?php
/**
 * fc_shortcache.php — answers kept between requests for a short time, per project (tmp/flexicorp-cache/),
 * so that a page does not ask FQS again what it asked a few seconds before: whether FQS answers, the
 * corpus's catalogue entry, its route and regions. Each value is kept with the FQS address it came from;
 * another address (the settings changed) is a miss. Not for query results.
 *
 *   tt_fc_cache_get( $root, $name, $ttl )        → the value, or null (missing, older than $ttl, other FQS)
 *   tt_fc_cache_put( $root, $name, $value )
 *   tt_fc_cache_ttl( $default )                  → flexicorp/fqs_check_ttl (seconds; 0 = keep nothing)
 *   tt_fc_cache_drop( [$root] )                  after the project's catalogue entry changed (register, reindex)
 */

require_once __DIR__ . '/fc_fqs_conn.php';

if ( ! function_exists( 'tt_fc_cache_file' ) ) {
	function tt_fc_cache_file( $root, $name ) {
		$dir = rtrim( (string) $root, '/' ) . '/tmp/flexicorp-cache';
		return array( $dir, $dir . '/' . preg_replace( '/[^A-Za-z0-9_.-]/', '_', (string) $name ) . '.json' );
	}

	function tt_fc_cache_ttl( $default ) {
		$v = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_check_ttl', '' ) ) : '';
		return $v !== '' && is_numeric( $v ) ? max( 0, (int) $v ) : (int) $default;
	}

	function tt_fc_cache_get( $root, $name, $ttl ) {
		if ( $ttl <= 0 || (string) $root === '' ) return null;
		list( , $file ) = tt_fc_cache_file( $root, $name );
		$j = @is_readable( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : null;
		if ( ! is_array( $j ) || ! array_key_exists( 'value', $j ) ) return null;
		if ( ( time() - (int) ( $j['t'] ?? 0 ) ) >= $ttl || (string) ( $j['fqs'] ?? '' ) !== tt_fc_fqs_url() ) return null;
		return $j['value'];
	}

	function tt_fc_cache_put( $root, $name, $value ) {
		if ( (string) $root === '' ) return;
		list( $dir, $file ) = tt_fc_cache_file( $root, $name );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) ) return;
		$tmp = $file . '.' . getmypid();
		if ( @file_put_contents( $tmp, json_encode( array( 't' => time(), 'fqs' => tt_fc_fqs_url(), 'value' => $value ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) !== false ) @rename( $tmp, $file );
	}

	function tt_fc_cache_drop( $root = null ) {
		$root = $root !== null && (string) $root !== '' ? (string) $root : (string) getcwd();
		list( $dir ) = tt_fc_cache_file( $root, 'x' );
		foreach ( (array) @glob( $dir . '/*.json' ) as $f ) @unlink( $f );
	}
}
