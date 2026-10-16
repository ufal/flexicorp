<?php
/**
 * typology.php's counting: a feature's program (typology_features.php) on the corpus's pando index
 * (the atlas's runner, atlas_engine.php), its answer as language → value → count, kept in the
 * project's tmp/typology/ (a profile changes only with the corpus; admins recompute it).
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/atlas_engine.php';
require_once __DIR__ . '/typology_features.php';

if ( ! function_exists( 'tt_typology_counts' ) ) {
	/**
	 * A feature's counts from pando's answer: language → value → count. Orders and shares: the value
	 * is the variant's name; inventories: the feature's value ('' = unmarked); bundles: the bundle.
	 */
	function tt_typology_counts( array $feat, array $answer ) {
		$out = array();
		$rows = $answer['result']['rows'] ?? array();
		if ( $feat['kind'] === 'order' || $feat['kind'] === 'share' ) {
			foreach ( $rows as $r ) {
				$lang = (string) ( $r['key'] ?? '' );
				if ( $lang === '' ) continue;
				foreach ( $feat['variants'] as $i => $v ) {
					$n = (int) ( $r['queries']["q$i"]['count'] ?? 0 );
					if ( $n > 0 ) $out[ $lang ][ $v[0] ] = $n;
				}
			}
			return $out;
		}
		foreach ( $rows as $r ) {
			$parts = explode( "\t", (string) ( $r['key'] ?? '' ) );
			if ( count( $parts ) < 2 ) continue;
			$lang = array_pop( $parts );
			$value = implode( "\t", $parts );
			if ( $value === '_' ) $value = '';
			if ( $lang === '' ) continue;
			$out[ $lang ][ $value ] = ( $out[ $lang ][ $value ] ?? 0 ) + (int) ( $r['count'] ?? 0 );
		}
		return $out;
	}

	/** Where a feature's counts are kept. */
	function tt_typology_cache_path( $root, $id, $lang ) {
		return rtrim( $root, '/' ) . "/tmp/typology/$lang-$id.json";
	}

	/**
	 * A feature's counts: kept ones, or counted now ($force: count again). array('counts', 'computed',
	 * 'seconds') or an error message. $run: the program runner (tests pass their own).
	 */
	function tt_typology_feature( $root, $id, $lang, $force = false, $run = null ) {
		$feats = tt_typology_features();
		if ( ! isset( $feats[ $id ] ) ) return 'unknown feature';
		$path = tt_typology_cache_path( $root, $id, $lang );
		if ( ! $force && is_file( $path ) ) {
			$kept = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $kept ) && isset( $kept['counts'] ) ) return $kept;
		}
		$run = $run ?: function ( $program ) use ( $root ) { return tt_atlas_run( $root, $program ); };
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 600 );
		if ( ! is_dir( dirname( $path ) ) ) @mkdir( dirname( $path ), 0775, true );
		// one count at a time per feature: a second request waits and then reads the first one's result
		$lock = @fopen( $path . '.lock', 'c' );
		if ( $lock ) flock( $lock, LOCK_EX );
		clearstatcache();
		if ( ! $force && is_file( $path ) ) {
			$kept = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $kept ) && isset( $kept['counts'] ) ) {
				if ( $lock ) { flock( $lock, LOCK_UN ); fclose( $lock ); }
				return $kept;
			}
		}
		$t0 = microtime( true );
		$answer = $run( tt_typology_program( $feats[ $id ], $lang ) );
		// an attribute the corpus does not have (no feats layer): the feature is not annotated here
		if ( is_string( $answer ) && preg_match( "/field '([^']+)': unknown token or region attribute/", $answer, $m ) ) {
			$answer = "not annotated in this corpus (no {$m[1]})";
		}
		if ( is_string( $answer ) ) {
			if ( $lock ) { flock( $lock, LOCK_UN ); fclose( $lock ); }
			return $answer;
		}
		$out = array( 'counts' => (object) tt_typology_counts( $feats[ $id ], $answer ), 'computed' => date( 'c' ),
			'seconds' => round( microtime( true ) - $t0, 1 ) );
		@file_put_contents( $path . '.tmp', json_encode( $out, JSON_UNESCAPED_UNICODE ) );
		@rename( $path . '.tmp', $path );
		if ( $lock ) { flock( $lock, LOCK_UN ); fclose( $lock ); }
		return $out;
	}

	/** The catalogue as the page needs it: id → wals, title, group, kind, variants' names and queries, note. */
	function tt_typology_catalogue( $root, $lang ) {
		$out = array();
		foreach ( tt_typology_features() as $id => $f ) {
			$out[ $id ] = array(
				'id' => $id, 'wals' => $f['wals'], 'title' => $f['title'], 'group' => $f['group'], 'kind' => $f['kind'],
				'variants' => array_map( function ( $v ) { return array( 'name' => $v[0], 'query' => $v[1] ); }, $f['variants'] ?? array() ),
				'pos' => $f['pos'] ?? '', 'feat' => $f['feat'] ?? '', 'note' => $f['note'] ?? '',
				'kept' => is_file( tt_typology_cache_path( $root, $id, $lang ) ),
			);
		}
		return $out;
	}
}
