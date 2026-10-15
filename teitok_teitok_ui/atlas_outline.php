<?php
/**
 * atlas.php's outline: the shape to cut the map's areas at, from the settings'
 * <atlas clip="…"/>: country codes (ISO 3166 alpha-3, e.g. "CHE" or "CHE,LIE"; the borders come from
 * geoBoundaries, ADM0, simplified, and are cached in the project's tmp/) or a GeoJSON file of the
 * project (e.g. "Resources/outline.geojson"). The result is the polygons only, as a GeoJSON
 * MultiPolygon's coordinates ([lon, lat]), rounded to 5 decimals (about 1 m).
 */

if ( ! function_exists( 'tt_atlas_outline_spec' ) ) {
	/** The setting checked: 'CHE,LIE' (codes), 'Resources/….geojson' (a file) or '' (no outline). */
	function tt_atlas_outline_spec( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^[A-Za-z]{3}(?:\s*[,;\s]\s*[A-Za-z]{3})*$/', $v ) ) {
			return implode( ',', array_unique( array_map( 'strtoupper', preg_split( '/[\s,;]+/', $v ) ) ) );
		}
		if ( preg_match( '#^Resources/[A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:geo)?json$#', $v ) && strpos( $v, '..' ) === false ) return $v;
		return '';
	}

	/** The polygons of a GeoJSON object (FeatureCollection, Feature or geometry), rounded; other geometries are left out. */
	function tt_atlas_outline_polygons( $g ) {
		if ( ! is_array( $g ) ) return array();
		$round = function ( $poly ) {
			$rings = array();
			foreach ( (array) $poly as $ring ) {
				$r = array();
				foreach ( (array) $ring as $pt ) {
					if ( is_array( $pt ) && count( $pt ) >= 2 && is_numeric( $pt[0] ) && is_numeric( $pt[1] ) ) $r[] = array( round( (float) $pt[0], 5 ), round( (float) $pt[1], 5 ) );
				}
				if ( count( $r ) >= 4 ) $rings[] = $r;
			}
			return $rings;
		};
		switch ( $g['type'] ?? '' ) {
			case 'FeatureCollection':
				$out = array();
				foreach ( (array) ( $g['features'] ?? array() ) as $f ) $out = array_merge( $out, tt_atlas_outline_polygons( $f ) );
				return $out;
			case 'Feature':
				return tt_atlas_outline_polygons( $g['geometry'] ?? null );
			case 'GeometryCollection':
				$out = array();
				foreach ( (array) ( $g['geometries'] ?? array() ) as $x ) $out = array_merge( $out, tt_atlas_outline_polygons( $x ) );
				return $out;
			case 'Polygon':
				$p = $round( $g['coordinates'] ?? array() );
				return $p ? array( $p ) : array();
			case 'MultiPolygon':
				$out = array();
				foreach ( (array) ( $g['coordinates'] ?? array() ) as $poly ) {
					$p = $round( $poly );
					if ( $p ) $out[] = $p;
				}
				return $out;
		}
		return array();
	}

	/** GET over https (curl, else a stream): the body, or false. */
	function tt_atlas_outline_http( $url ) {
		if ( function_exists( 'curl_init' ) ) {
			$c = curl_init( $url );
			curl_setopt_array( $c, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
				CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'TEITOK atlas', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS ) );
			$body = curl_exec( $c );
			$ok = curl_getinfo( $c, CURLINFO_RESPONSE_CODE ) === 200;
			curl_close( $c );
			return $ok && is_string( $body ) ? $body : false;
		}
		$ctx = stream_context_create( array( 'http' => array( 'timeout' => 20, 'user_agent' => 'TEITOK atlas', 'follow_location' => 1, 'max_redirects' => 5 ) ) );
		return @file_get_contents( $url, false, $ctx );
	}

	/** A country's boundaries from geoBoundaries (simplified) at a level (ADM0: the border, ADM1: its first-level regions, …): the GeoJSON, or an error. */
	function tt_atlas_outline_fetch( $iso, $level = 'ADM0' ) {
		$meta = json_decode( (string) tt_atlas_outline_http( "https://www.geoboundaries.org/api/current/gbOpen/$iso/$level/" ), true );
		$url = is_array( $meta ) ? (string) ( $meta['simplifiedGeometryGeoJSON'] ?? '' ) : '';
		if ( $url === '' ) return "geoBoundaries has no $level boundaries for '$iso'";
		if ( strpos( $url, 'https://github.com/wmgeolab/geoBoundaries/' ) !== 0 ) return "unexpected download address for '$iso'";
		$g = json_decode( (string) tt_atlas_outline_http( $url ), true );
		return is_array( $g ) ? $g : "the $level boundaries of '$iso' could not be downloaded";
	}

	/** The outline of a checked spec (see tt_atlas_outline_spec) for the project at $root: polygons, or an error. */
	function tt_atlas_outline( $root, $spec ) {
		$root = rtrim( (string) $root, '/' );
		if ( $spec === '' ) return 'no outline is set';
		if ( strpos( $spec, '/' ) !== false ) {
			$f = "$root/$spec";
			if ( ! is_file( $f ) ) return "$spec does not exist";
			$polys = tt_atlas_outline_polygons( json_decode( (string) file_get_contents( $f ), true ) );
			return $polys ? $polys : "$spec has no polygons";
		}
		$out = array();
		foreach ( explode( ',', $spec ) as $iso ) {
			$cache = "$root/tmp/atlas-outline-$iso.json";
			$polys = is_file( $cache ) ? json_decode( (string) file_get_contents( $cache ), true ) : null;
			if ( ! is_array( $polys ) || ! $polys ) {
				$g = tt_atlas_outline_fetch( $iso );
				if ( is_string( $g ) ) return $g;
				$polys = tt_atlas_outline_polygons( $g );
				if ( ! $polys ) return "the border of '$iso' is not usable GeoJSON";
				if ( is_dir( "$root/tmp" ) ) @file_put_contents( $cache, json_encode( $polys ) );
			}
			$out = array_merge( $out, $polys );
		}
		return $out;
	}
}
