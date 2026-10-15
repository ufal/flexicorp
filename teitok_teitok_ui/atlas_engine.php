<?php
/**
 * atlas.php's counting: pando, through its command-line program on the corpus's index (as the custom
 * layers build does). Every count is complete (pando's display limits off). Places come from the
 * atlas's place attribute, their coordinates from its location attribute ("lat lon", "lat,lon",
 * geo: URI or WKT POINT(lon lat), as pando's geodist() reads them), or, when the settings name a
 * places table, from that table (atlas_places.php; its names also merge values into one place).
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/atlas_places.php';

if ( ! function_exists( 'tt_atlas_run' ) ) {
	/** pando's JSON answer to a program (the last statement's), or an error message. */
	function tt_atlas_run( $root, $program ) {
		$pando = tt_fc_env_pando_bin( 'pando' );
		$index = tt_fc_env_pando_index_dir( $root );
		if ( $pando === '' ) return 'pando is not installed on this server';
		if ( ! is_file( rtrim( $index, '/' ) . '/corpus.info' ) ) return 'this corpus has no pando index';
		$cmd = escapeshellarg( $pando ) . ' --api --json ' . escapeshellarg( $index ) . ' ' . escapeshellarg( $program ) . ' 2>&1';
		$out = (string) shell_exec( $cmd );
		$starts = array();
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

	/** Leaves of a count answer: [[value1, value2, …, count], …] (one field: rows; several: the tree). */
	function tt_atlas_count_rows( array $result ) {
		$out = array();
		if ( isset( $result['hierarchy'] ) ) {
			$walk = function ( $nodes, $path ) use ( &$walk, &$out ) {
				foreach ( $nodes as $n ) {
					$p = array_merge( $path, array( (string) ( $n['value'] ?? '' ) ) );
					if ( ! empty( $n['children'] ) ) $walk( $n['children'], $p );
					else $out[] = array_merge( $p, array( (int) ( $n['count'] ?? 0 ) ) );
				}
			};
			$walk( $result['hierarchy'], array() );
		} else {
			foreach ( $result['rows'] ?? array() as $r ) $out[] = array( (string) ( $r['key'] ?? '' ), (int) ( $r['count'] ?? 0 ) );
		}
		return $out;
	}

	/** A location value as pando reads it → array(lat, lon), or null. */
	function tt_atlas_parse_geo( $text ) {
		$s = trim( (string) $text );
		$num = '[-+]?\d+(?:\.\d+)?';
		if ( preg_match( "/^point\s*\(\s*($num)[\s,;]+($num)\s*\)$/i", $s, $m ) ) {
			$lat = (float) $m[2]; $lon = (float) $m[1];
		} else {
			if ( stripos( $s, 'geo:' ) === 0 ) {
				$s = explode( ';', substr( $s, 4 ) )[0];
				$s = implode( ',', array_slice( explode( ',', $s ), 0, 2 ) );
			}
			if ( ! preg_match( "/^($num)\s*[,; \t]\s*($num)$/", $s, $m ) ) return null;
			$lat = (float) $m[1]; $lon = (float) $m[2];
		}
		return ( $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180 ) ? array( $lat, $lon ) : null;
	}

	/** Strip a trailing ';' and statements after the query: the atlas adds its own count. */
	function tt_atlas_query( $q ) {
		return rtrim( trim( (string) $q ), "; \t\n" );
	}

	/** place → array(lat, lon, tokens) for the places with coordinates. */
	function tt_atlas_places( $root, array $cfg ) {
		if ( ( $cfg['places'] ?? '' ) !== '' ) {
			$table = tt_atlas_place_table( $root, $cfg['places'] );
			$j = tt_atlas_run( $root, "set group_limit 0; set child_limit 0; []; count by {$cfg['place']}" );
			if ( is_string( $j ) ) return $j;
			$out = array();
			foreach ( tt_atlas_count_rows( $j['result'] ?? array() ) as $r ) {
				if ( count( $r ) < 2 || ! isset( $table[ $r[0] ] ) ) continue;
				list( $name, $lat, $lon ) = $table[ $r[0] ];
				if ( ! isset( $out[ $name ] ) ) $out[ $name ] = array( $lat, $lon, 0, array() );
				$out[ $name ][2] += $r[1];
				$out[ $name ][3][] = $r[0];   // the attribute's values for this place (for links to its hits)
			}
			ksort( $out );
			return $out;
		}
		$j = tt_atlas_run( $root, "set group_limit 0; set child_limit 0; []; count by {$cfg['place']}, {$cfg['geo']}" );
		if ( is_string( $j ) ) return $j;
		$out = array();
		foreach ( tt_atlas_count_rows( $j['result'] ?? array() ) as $r ) {
			list( $place, $geo, $n ) = $r;
			$p = tt_atlas_parse_geo( $geo );
			if ( ! $p || $place === '' || $place === '_' ) continue;
			if ( ! isset( $out[ $place ] ) ) $out[ $place ] = array( $p[0], $p[1], 0, array( $place ) );
			$out[ $place ][2] += $n;
		}
		ksort( $out );
		return $out;
	}

	/** The query's most frequent values of $field: [[value, count], …] (at most $limit), and the hits. */
	function tt_atlas_values( $root, $query, $field, $limit = 80 ) {
		$j = tt_atlas_run( $root, 'set group_limit ' . (int) $limit . '; ' . tt_atlas_query( $query ) . '; count by ' . $field );
		if ( is_string( $j ) ) return $j;
		return array( 'values' => tt_atlas_count_rows( $j['result'] ?? array() ), 'total' => (int) ( $j['result']['total_matches'] ?? 0 ) );
	}

	/** The query's hits per place and value: place → value → count (complete). */
	function tt_atlas_counts( $root, array $cfg, $query, $field ) {
		$j = tt_atlas_run( $root, 'set group_limit 0; set child_limit 0; ' . tt_atlas_query( $query ) . "; count by {$cfg['place']}, $field" );
		if ( is_string( $j ) ) return $j;
		$table = ( $cfg['places'] ?? '' ) !== '' ? tt_atlas_place_table( $root, $cfg['places'] ) : null;
		$out = array();
		foreach ( tt_atlas_count_rows( $j['result'] ?? array() ) as $r ) {
			if ( count( $r ) < 3 ) continue;
			$place = $r[0];
			if ( $table !== null ) {   // values merged under the table's names; values without coordinates left out
				if ( ! isset( $table[ $place ] ) ) continue;
				$place = $table[ $place ][0];
			}
			$out[ $place ][ $r[1] ] = ( $out[ $place ][ $r[1] ] ?? 0 ) + $r[2];
		}
		return $out;
	}

	/** A field name pando can count by: an attribute (form, nform, a.form, text_lang), nothing else. */
	function tt_atlas_valid_field( $f ) {
		return is_string( $f ) && preg_match( '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)?$/', $f );
	}
}
