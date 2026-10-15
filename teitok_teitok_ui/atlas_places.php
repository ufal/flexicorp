<?php
/**
 * atlas.php's places table: coordinates for a corpus that has place names but no coordinates, from
 * the settings' <atlas place="sp_loc_childhood" places="Resources/places.tsv"/>. One line per value
 * of the place attribute, tab-separated:
 *
 *   value <TAB> lat <TAB> lon [<TAB> name [<TAB> note]]
 *
 * name is what the map shows (default: the value): values with the same name are one place (spelling
 * variants, or a village written with and without its district). A value without coordinates is
 * left off the map (unknown, abroad, …); lines starting with # are comments.
 */

if ( ! function_exists( 'tt_atlas_places_spec' ) ) {
	/** The setting checked: 'Resources/….tsv' or '' (coordinates from the corpus: the geo attribute). */
	function tt_atlas_places_spec( $v ) {
		$v = trim( (string) $v );
		return preg_match( '#^Resources/[A-Za-z0-9_][A-Za-z0-9_./-]*\.(?:tsv|txt)$#', $v ) && strpos( $v, '..' ) === false ? $v : '';
	}

	/** The table of the project at $root: value → [name, lat, lon] for the values with coordinates. */
	function tt_atlas_place_table( $root, $spec ) {
		$f = rtrim( (string) $root, '/' ) . "/$spec";
		$out = array();
		if ( $spec === '' || ! is_file( $f ) ) return $out;
		foreach ( (array) file( $f, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( $line === '' || $line[0] === '#' ) continue;
			$c = explode( "\t", $line );
			if ( count( $c ) < 3 || ! is_numeric( $c[1] ) || ! is_numeric( $c[2] ) ) continue;
			$lat = (float) $c[1]; $lon = (float) $c[2];
			if ( abs( $lat ) > 90 || abs( $lon ) > 180 ) continue;
			$name = trim( $c[3] ?? '' );
			$out[ $c[0] ] = array( $name !== '' ? $name : $c[0], $lat, $lon );
		}
		return $out;
	}
}
