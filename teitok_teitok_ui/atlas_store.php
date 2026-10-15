<?php
/**
 * atlas.php's settings and saved variables.
 *
 * Settings: <atlas place="text_place" geo="text_geo" min_n="10" startpos="46.8 8.2" zoom="8" clip="CHE"/> in
 * the corpus's settings.xml (all optional; clip: the outline to cut the areas at, see atlas_outline.php;
 * regions="ADM1:Cantons": fixed areas to colour, see atlas_regions.php; places="Resources/places.tsv":
 * coordinates for place names, see atlas_places.php).
 * A variable is what pando_geo's atlas files call one too:
 *
 *   <variable key="nicht" name="nicht" query='[nform="nicht"]' field="form">
 *     <desc>Swiss German 'nicht'</desc>
 *     <variant name="nöd" pattern="nö+d"/> …
 *   </variable>
 *
 * Kept in Users/<folder>/atlas.xml (the user's own) and Resources/atlas.xml (the corpus's: admins).
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/atlas_outline.php';
require_once __DIR__ . '/atlas_regions.php';
require_once __DIR__ . '/atlas_places.php';

if ( ! function_exists( 'tt_atlas_settings' ) ) {
	function tt_atlas_settings() {
		$get = function ( $k, $d ) {
			$v = function_exists( 'getset' ) ? getset( "atlas/$k", '' ) : '';
			return ( is_string( $v ) && trim( $v ) !== '' ) ? trim( $v ) : $d;
		};
		$attr = function ( $v, $d ) { return preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $v ) ? $v : $d; };
		return array(
			'place' => $attr( $get( 'place', 'text_place' ), 'text_place' ),
			'geo' => $attr( $get( 'geo', 'text_geo' ), 'text_geo' ),
			'min_n' => max( 1, (int) $get( 'min_n', '10' ) ),
			'startpos' => $get( 'startpos', '' ),
			'zoom' => (int) $get( 'zoom', '0' ),
			'clip' => tt_atlas_outline_spec( $get( 'clip', '' ) ),
			'regions' => tt_atlas_regions_spec( $get( 'regions', '' ) ),
			'places' => tt_atlas_places_spec( $get( 'places', '' ) ),
		);
	}

	function tt_atlas_valid_key( $k ) {
		return is_string( $k ) && preg_match( '/^[a-z][a-z0-9]{0,31}$/', $k );
	}

	/** Where variables are kept: the corpus's file, or a user's (folder as TEITOK names it). */
	function tt_atlas_path( $root, $folder ) {
		if ( $folder === '' ) return rtrim( $root, '/' ) . '/Resources/atlas.xml';
		return preg_match( '/^[a-z0-9]{1,128}$/', $folder ) ? rtrim( $root, '/' ) . "/Users/$folder/atlas.xml" : '';
	}

	/** key → variable (name, query, field, description, variants: [[name, pattern], …]). */
	function tt_atlas_load( $path ) {
		$out = array();
		if ( $path === '' || ! is_file( $path ) ) return $out;
		$x = @simplexml_load_file( $path );
		if ( ! $x ) return $out;
		foreach ( $x->variable ?? array() as $v ) {
			$key = (string) $v['key'];
			if ( ! tt_atlas_valid_key( $key ) ) continue;
			$variants = array();
			foreach ( $v->variant ?? array() as $vv ) $variants[] = array( (string) $vv['name'], (string) $vv['pattern'] );
			$out[ $key ] = array( 'key' => $key, 'name' => (string) ( $v['name'] ?: $key ), 'query' => (string) $v['query'],
				'field' => (string) ( $v['field'] ?: 'form' ), 'description' => (string) ( $v->desc ?? '' ), 'variants' => $variants );
		}
		return $out;
	}

	/** A variable from the page, checked: an error message, or the variable. */
	function tt_atlas_clean_variable( array $in ) {
		$name = mb_substr( trim( (string) ( $in['name'] ?? '' ) ), 0, 80 );
		$key = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $name ) );
		if ( $key === '' || ! preg_match( '/^[a-z]/', $key ) ) return 'give the map a name (starting with a letter)';
		$key = substr( $key, 0, 32 );
		$query = mb_substr( trim( (string) ( $in['query'] ?? '' ) ), 0, 2000 );
		if ( $query === '' ) return 'a map needs a query';
		$field = (string) ( $in['field'] ?? 'form' );
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)?$/', $field ) ) return "'$field' is not an attribute";
		$variants = array();
		foreach ( (array) ( $in['variants'] ?? array() ) as $v ) {
			$vn = mb_substr( trim( (string) ( $v[0] ?? $v['name'] ?? '' ) ), 0, 40 );
			$pat = mb_substr( (string) ( $v[1] ?? $v['pattern'] ?? '' ), 0, 400 );
			if ( $vn === '' || $pat === '' ) continue;
			if ( @preg_match( '/^(?:' . str_replace( '/', '\/', $pat ) . ')$/u', '' ) === false ) return "the pattern of '$vn' is not a valid pattern";
			$variants[] = array( $vn, $pat );
		}
		if ( ! $variants ) return 'a map needs at least one variant';
		return array( 'key' => $key, 'name' => $name, 'query' => $query, 'field' => $field,
			'description' => mb_substr( trim( (string) ( $in['description'] ?? '' ) ), 0, 400 ), 'variants' => $variants );
	}

	/** Write variables (key → variable) to $path, under a lock, replacing the file. */
	function tt_atlas_write( $path, array $vars ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) ) return 'cannot create ' . basename( $dir );
		$d = new DOMDocument( '1.0', 'UTF-8' );
		$d->formatOutput = true;
		$r = $d->appendChild( $d->createElement( 'atlas' ) );
		foreach ( $vars as $v ) {
			$e = $r->appendChild( $d->createElement( 'variable' ) );
			foreach ( array( 'key', 'name', 'query', 'field' ) as $k ) $e->setAttribute( $k, $v[ $k ] );
			if ( $v['description'] !== '' ) $e->appendChild( $d->createElement( 'desc' ) )->appendChild( $d->createTextNode( $v['description'] ) );
			foreach ( $v['variants'] as $vv ) {
				$ve = $e->appendChild( $d->createElement( 'variant' ) );
				$ve->setAttribute( 'name', $vv[0] );
				$ve->setAttribute( 'pattern', $vv[1] );
			}
		}
		$lock = @fopen( $dir . '/.atlas.lock', 'c' );
		if ( ! $lock ) return 'cannot write ' . basename( $path );
		flock( $lock, LOCK_EX );
		$tmp = $path . '.tmp' . getmypid();
		$ok = @file_put_contents( $tmp, $d->saveXML() ) !== false && @rename( $tmp, $path );
		if ( ! $ok ) @unlink( $tmp );
		flock( $lock, LOCK_UN );
		fclose( $lock );
		return $ok ? true : 'cannot write ' . basename( $path );
	}

	/** Save (or with $delete remove) a variable in the user's or (admin) the corpus's file. */
	function tt_atlas_save( $root, $folder, array $in, $delete = false ) {
		$path = tt_atlas_path( $root, $folder );
		if ( $path === '' ) return 'log in to save maps';
		$vars = tt_atlas_load( $path );
		if ( $delete ) {
			unset( $vars[ (string) ( $in['key'] ?? '' ) ] );
		} else {
			$v = tt_atlas_clean_variable( $in );
			if ( is_string( $v ) ) return $v;
			$vars[ $v['key'] ] = $v;
		}
		return tt_atlas_write( $path, $vars );
	}
}
