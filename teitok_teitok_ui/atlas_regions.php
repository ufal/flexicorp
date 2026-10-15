<?php
/**
 * atlas.php's regions: fixed areas (cantons, districts, a dialect survey's areas, …) to colour by the
 * variants of the places inside them, from the settings' <atlas regions="…"/>: a comma-separated list
 * of ADM1 … ADM5 (geoBoundaries' administrative levels of the countries in clip="…") or GeoJSON files of
 * the project, each optionally with a label: regions="ADM1:Cantons, ADM2:Districts,
 * Resources/areas.geojson:Survey areas". A region's name is the feature's shapeName, name or NAME.
 * Downloaded levels are cached in the project's tmp/, as the outline (atlas_outline.php).
 */
require_once __DIR__ . '/atlas_outline.php';

if ( ! function_exists( 'tt_atlas_regions_spec' ) ) {
	/** The setting checked: [ ['key' => 'ADM1' or 'Resources/….geojson', 'label' => 'Cantons'], … ]. */
	function tt_atlas_regions_spec( $v ) {
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', trim( (string) $v ) ) as $item ) {
			if ( ! preg_match( '#^(ADM[1-5]|Resources/[A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:geo)?json)(?:\s*:\s*([^:<>&"]{1,40}))?$#', $item, $m ) ) continue;
			if ( strpos( $m[1], '..' ) !== false ) continue;
			$label = trim( $m[2] ?? '' );
			if ( $label === '' ) $label = $m[1][0] === 'A' ? 'Regions (' . $m[1] . ')' : preg_replace( '/\.(?:geo)?json$/', '', basename( $m[1] ) );
			$out[ $m[1] ] = array( 'key' => $m[1], 'label' => $label );
		}
		return array_values( $out );
	}

	/** The named regions of a GeoJSON object: [ ['name' => …, 'polygons' => MultiPolygon coordinates], … ]. */
	function tt_atlas_regions_features( $g ) {
		$out = array();
		$features = is_array( $g ) && ( $g['type'] ?? '' ) === 'FeatureCollection' ? (array) ( $g['features'] ?? array() ) : array( $g );
		foreach ( $features as $i => $f ) {
			$polys = tt_atlas_outline_polygons( $f );
			if ( ! $polys ) continue;
			$p = is_array( $f ) ? (array) ( $f['properties'] ?? array() ) : array();
			$name = trim( (string) ( $p['shapeName'] ?? $p['name'] ?? $p['NAME'] ?? '' ) );
			$out[] = array( 'name' => $name !== '' ? $name : 'region ' . ( $i + 1 ), 'polygons' => $polys );
		}
		return $out;
	}

	/** The regions for $key (one of the setting's keys) of the project at $root, with clip="…" as $clip: regions, or an error. */
	function tt_atlas_regions( $root, $clip, $key ) {
		$root = rtrim( (string) $root, '/' );
		if ( strpos( $key, '/' ) !== false ) {
			$f = "$root/$key";
			if ( ! is_file( $f ) ) return "$key does not exist";
			$r = tt_atlas_regions_features( json_decode( (string) file_get_contents( $f ), true ) );
			return $r ? $r : "$key has no polygons";
		}
		if ( $clip === '' || strpos( $clip, '/' ) !== false ) return "$key needs the countries in clip=\"…\" (country codes)";
		$out = array();
		foreach ( explode( ',', $clip ) as $iso ) {
			$cache = "$root/tmp/atlas-regions-$iso-$key.json";
			$r = is_file( $cache ) ? json_decode( (string) file_get_contents( $cache ), true ) : null;
			if ( ! is_array( $r ) || ! $r ) {
				$g = tt_atlas_outline_fetch( $iso, $key );
				if ( is_string( $g ) ) return $g;
				$r = tt_atlas_regions_features( $g );
				if ( ! $r ) return "the $key boundaries of '$iso' are not usable GeoJSON";
				if ( is_dir( "$root/tmp" ) ) @file_put_contents( $cache, json_encode( $r ) );
			}
			$out = array_merge( $out, $r );
		}
		return $out;
	}
}
