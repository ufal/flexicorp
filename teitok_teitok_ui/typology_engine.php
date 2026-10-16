<?php
/**
 * typology.php's counting: a feature's program (typology_features.php) through FQS where it serves
 * the corpus, else on the project's own pando index (fc_pando_run.php); its answer as language →
 * value → count, kept in the project's tmp/typology/ under the feature's id and a fingerprint of its
 * program (a feature edited, or other attribute names, is counted afresh; admins can recount).
 * Features: the built-in ones, the corpus's and a user's own (typology_store.php).
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/fc_pando_run.php';
require_once __DIR__ . '/typology_features.php';
require_once __DIR__ . '/typology_store.php';

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

	/** Why the features cannot be counted for the project ('' when they can: through FQS or locally). */
	function tt_typology_cannot_count( $root ) {
		return tt_fc_pando_route( $root )['why'];
	}

	/** Where a feature's counts are kept: its id and a fingerprint of its program. */
	function tt_typology_cache_path( $root, $id, array $feat, $lang ) {
		$fp = substr( sha1( tt_typology_program( $feat, $lang ) ), 0, 10 );
		return rtrim( $root, '/' ) . "/tmp/typology/$lang-$id-$fp.json";
	}

	/**
	 * A feature's counts: kept ones, or counted now ($force: count again). array('counts', 'computed',
	 * 'seconds', 'missing'?) or an error message. $role: whose limits apply at FQS (admin for the
	 * built-in and the corpus's features, the user's own role for their features). $run: the program
	 * runner (tests pass their own).
	 */
	function tt_typology_count( $root, $id, array $feat, $lang, $force = false, $run = null, $role = 'admin' ) {
		$path = tt_typology_cache_path( $root, $id, $feat, $lang );
		if ( ! $force && is_file( $path ) ) {
			$kept = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $kept ) && isset( $kept['counts'] ) ) return $kept;
		}
		$run = $run ?: function ( $program ) use ( $root, $role ) { return tt_fc_pando_run( $root, $program, 600, $role ); };
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 600 );
		if ( ! is_dir( dirname( $path ) ) ) @mkdir( dirname( $path ), 0775, true );
		// one count at a time per feature: a second request waits and then reads the first one's result
		$lock = @fopen( $path . '.lock', 'c' );
		if ( $lock ) flock( $lock, LOCK_EX );
		$unlock = function () use ( $lock ) { if ( $lock ) { flock( $lock, LOCK_UN ); fclose( $lock ); } };
		clearstatcache();
		if ( ! $force && is_file( $path ) ) {
			$kept = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $kept ) && isset( $kept['counts'] ) ) { $unlock(); return $kept; }
		}
		$t0 = microtime( true );
		$answer = $run( tt_typology_program( $feat, $lang ) );
		// an attribute the corpus does not have (no feats layer): not annotated here — kept as such, so it
		// is not asked again on every visit
		$missing = '';
		if ( is_string( $answer ) && preg_match( "/field '([^']+)': unknown token or region attribute/", $answer, $m ) ) {
			$missing = "not annotated in this corpus (no {$m[1]})";
			$answer = array( 'result' => array( 'rows' => array() ) );
		}
		if ( is_string( $answer ) ) { $unlock(); return $answer; }
		$counts = tt_typology_counts( $feat, $answer );
		// the queries ran but matched nothing: they need annotation this corpus lacks (PronType, VerbForm, …)
		if ( $missing === '' && ! $counts ) $missing = 'nothing to count in this corpus' . ( ! empty( $feat['note'] ) ? ' (' . rtrim( $feat['note'], '.' ) . ')' : '' );
		$out = array( 'counts' => (object) $counts, 'computed' => date( 'c' ), 'seconds' => round( microtime( true ) - $t0, 1 ) );
		if ( $missing !== '' ) $out['missing'] = $missing;
		@file_put_contents( $path . '.tmp', json_encode( $out, JSON_UNESCAPED_UNICODE ) );
		@rename( $path . '.tmp', $path );
		$unlock();
		return $out;
	}

	/** A built-in feature's counts (no attribute names, no own features): as tt_typology_count. */
	function tt_typology_feature( $root, $id, $lang, $force = false, $run = null ) {
		$feats = tt_typology_features();
		if ( ! isset( $feats[ $id ] ) ) return 'unknown feature';
		return tt_typology_count( $root, $id, $feats[ $id ], $lang, $force, $run );
	}

	/** The kept counts of several features at once (only reads; features not counted yet are left out). */
	function tt_typology_kept( $root, array $features, array $ids, $lang ) {
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! isset( $features[ $id ] ) ) continue;
			$path = tt_typology_cache_path( $root, $id, $features[ $id ], $lang );
			if ( ! is_file( $path ) ) continue;
			$kept = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $kept ) && isset( $kept['counts'] ) ) $out[ $id ] = $kept;
		}
		return $out;
	}

	/**
	 * The catalogue as the page needs it: id → wals, title, group, kind, variants (name, query), pos,
	 * feat, note, origin (builtin | corpus | mine), the program, and whether its counts are kept.
	 * $features: from tt_typology_merged() (default: the built-in ones).
	 */
	function tt_typology_catalogue( $root, $lang, ?array $features = null ) {
		$features = $features ?? tt_typology_features();
		$out = array();
		foreach ( $features as $id => $f ) {
			$out[ $id ] = array(
				'id' => $id, 'wals' => $f['wals'] ?? '', 'title' => $f['title'], 'group' => $f['group'] ?? 'other', 'kind' => $f['kind'],
				'variants' => array_map( function ( $v ) { return array( 'name' => $v[0], 'query' => $v[1] ); }, $f['variants'] ?? array() ),
				'pos' => $f['pos'] ?? '', 'feat' => $f['feat'] ?? '', 'note' => $f['note'] ?? '',
				'origin' => $f['origin'] ?? 'builtin', 'program' => tt_typology_program( $f, $lang ),
				'kept' => is_file( tt_typology_cache_path( $root, $id, $f, $lang ) ),
			);
		}
		return $out;
	}
}
