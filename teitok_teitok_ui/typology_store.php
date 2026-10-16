<?php
/**
 * typology.php's own features and attribute names: kept for the corpus (Resources/typology.json,
 * admins) and per user (Users/<folder>/typology.json), as the atlas keeps maps. A file:
 *
 *   { "attributes": { "upos": "pos", "deprel": "rel", "feats": "morph" },   // names in this corpus
 *     "features": { "<id>": { title, group, kind, wals, note, variants: [[name, query], …], pos, feat } },
 *     "hidden": [ "<built-in id>", … ],
 *     "names": { "<language code>": "<name>", … } }                       // names for codes without one
 *
 * The catalogue a page uses: the built-in features (typology_features.php) with the attribute names
 * applied, then the corpus's features, then the user's (a feature with a built-in's id replaces it).
 */

require_once __DIR__ . '/typology_features.php';

if ( ! function_exists( 'tt_typology_store_path' ) ) {
	function tt_typology_kinds() { return array( 'order', 'share', 'inventory', 'bundles' ); }
	function tt_typology_groups() { return array( 'order', 'clause', 'morphology', 'other' ); }

	/** The corpus's file, or a user's (folder as TEITOK names it); '' for an invalid folder. */
	function tt_typology_store_path( $root, $folder ) {
		if ( $folder === '' ) return rtrim( $root, '/' ) . '/Resources/typology.json';
		return preg_match( '/^[a-z0-9]{1,128}$/', $folder ) ? rtrim( $root, '/' ) . "/Users/$folder/typology.json" : '';
	}

	function tt_typology_store_load( $path ) {
		$j = ( $path !== '' && is_file( $path ) ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		$j = is_array( $j ) ? $j : array();
		return array(
			'attributes' => is_array( $j['attributes'] ?? null ) ? $j['attributes'] : array(),
			'features' => is_array( $j['features'] ?? null ) ? $j['features'] : array(),
			'hidden' => array_values( array_filter( (array) ( $j['hidden'] ?? array() ), 'is_string' ) ),
			'names' => is_array( $j['names'] ?? null ) ? tt_typology_clean_names( $j['names'] ) : array(),
		);
	}

	function tt_typology_store_save( $path, array $store ) {
		if ( $path === '' ) return 'no place to keep it';
		if ( ! is_dir( dirname( $path ) ) && ! @mkdir( dirname( $path ), 0775, true ) ) return 'cannot write ' . dirname( $path );
		$tmp = $path . '.tmp' . getmypid();
		if ( @file_put_contents( $tmp, json_encode( $store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) === false ) return "cannot write $path";
		return @rename( $tmp, $path ) ? '' : "cannot write $path";
	}

	/** An attribute name, or '' (letters, digits, underscore; feats-style names may hold a slash). */
	function tt_typology_attr_name( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $v ) ? $v : '';
	}

	/** The attribute names a corpus uses for upos / deprel / feats (only valid, different ones). */
	function tt_typology_clean_attributes( array $a ) {
		$out = array();
		foreach ( array( 'upos', 'deprel', 'feats' ) as $k ) {
			$v = tt_typology_attr_name( $a[ $k ] ?? '' );
			if ( $v !== '' && $v !== $k ) $out[ $k ] = $v;
		}
		return $out;
	}

	/** Language names: code → name (codes and names as a page sends them, checked; at most 2000). */
	function tt_typology_clean_names( array $n ) {
		$out = array();
		foreach ( $n as $code => $name ) {
			$code = trim( (string) $code );
			$name = mb_substr( trim( (string) $name ), 0, 80 );
			if ( $code === '' || mb_strlen( $code ) > 64 || $name === '' || preg_match( '/[<>\x00-\x1f]/', $code . $name ) ) continue;
			$out[ $code ] = $name;
			if ( count( $out ) >= 2000 ) break;
		}
		return $out;
	}

	/** A feature definition from a page, checked: array(feature) or an error message. */
	function tt_typology_clean_feature( array $in ) {
		$id = strtolower( trim( (string) ( $in['id'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-z][a-z0-9]{0,31}$/', $id ) ) return 'an id is a letter, then letters or digits (at most 32)';
		$kind = (string) ( $in['kind'] ?? '' );
		if ( ! in_array( $kind, tt_typology_kinds(), true ) ) return 'unknown kind';
		$text = function ( $k, $max ) use ( $in ) { return mb_substr( trim( (string) ( $in[ $k ] ?? '' ) ), 0, $max ); };
		$f = array( 'title' => $text( 'title', 120 ), 'group' => in_array( $in['group'] ?? '', tt_typology_groups(), true ) ? $in['group'] : 'other',
			'kind' => $kind, 'wals' => preg_replace( '/[^0-9A-Za-z]/', '', $text( 'wals', 8 ) ), 'note' => $text( 'note', 400 ) );
		if ( $f['title'] === '' ) return 'give the feature a title';
		if ( $kind === 'order' || $kind === 'share' ) {
			$vs = array();
			foreach ( (array) ( $in['variants'] ?? array() ) as $v ) {
				$name = mb_substr( trim( (string) ( is_array( $v ) ? ( $v['name'] ?? $v[0] ?? '' ) : '' ) ), 0, 40 );
				$q = trim( (string) ( is_array( $v ) ? ( $v['query'] ?? $v[1] ?? '' ) : '' ) );
				if ( $name === '' && $q === '' ) continue;
				if ( $name === '' || $q === '' ) return 'each alternative needs a name and a query';
				if ( strlen( $q ) > 2000 || strpos( $q, ';' ) !== false ) return "the query of '$name' is too long or has a ';' (one query per alternative)";
				$vs[] = array( $name, $q );
			}
			if ( count( $vs ) < 2 || count( $vs ) > 12 ) return 'give 2 to 12 alternatives';
			if ( count( array_unique( array_column( $vs, 0 ) ) ) !== count( $vs ) ) return 'the alternatives need different names';
			$f['variants'] = $vs;
		} else {
			$pos = trim( (string) ( $in['pos'] ?? '' ) );
			if ( ! preg_match( '/^[A-Za-z0-9_|]{1,80}$/', $pos ) ) return 'give the part(s) of speech, e.g. NOUN or VERB|AUX';
			$f['pos'] = $pos;
			if ( $kind === 'inventory' ) {
				$feat = tt_typology_attr_name( $in['feat'] ?? '' );
				if ( $feat === '' ) return 'give the feature to count, e.g. Case';
				$f['feat'] = $feat;
			}
		}
		return array( $id, $f );
	}

	/** A built-in feature with the corpus's attribute names in its queries. */
	function tt_typology_rename( array $feat, array $attrs ) {
		if ( ! $attrs ) return $feat;
		$sub = function ( $q ) use ( $attrs ) {
			foreach ( $attrs as $from => $to ) {
				// the attribute before a comparison (upos=, deprel!=) and feats/X
				$q = preg_replace( '/\b' . preg_quote( $from, '/' ) . '(?=\s*!?=|\/)/', $to, $q );
			}
			return $q;
		};
		if ( isset( $feat['variants'] ) ) $feat['variants'] = array_map( function ( $v ) use ( $sub ) { return array( $v[0], $sub( $v[1] ) ); }, $feat['variants'] );
		$feat['attrs'] = $attrs;
		return $feat;
	}

	/**
	 * The catalogue for a page: id → feature with 'origin' (builtin | corpus | mine) and 'editable'
	 * by this user; attribute names: the user's over the corpus's.
	 */
	function tt_typology_merged( $root, $folder ) {
		$corpus = tt_typology_store_load( tt_typology_store_path( $root, '' ) );
		$mine = $folder !== '' ? tt_typology_store_load( tt_typology_store_path( $root, $folder ) ) : tt_typology_store_load( '' );
		$attrs = tt_typology_clean_attributes( array_merge( $corpus['attributes'], $mine['attributes'] ) );
		$out = array();
		$hidden = array_merge( $corpus['hidden'], $mine['hidden'] );
		foreach ( tt_typology_features() as $id => $f ) {
			if ( in_array( $id, $hidden, true ) ) continue;
			$out[ $id ] = tt_typology_rename( $f, $attrs ) + array( 'origin' => 'builtin' );
		}
		foreach ( array( 'corpus' => $corpus, 'mine' => $mine ) as $origin => $store ) {
			foreach ( $store['features'] as $id => $f ) {
				$c = tt_typology_clean_feature( array( 'id' => $id ) + (array) $f );
				if ( is_string( $c ) ) continue;
				$out[ $c[0] ] = $c[1] + array( 'origin' => $origin );
			}
		}
		return array( 'features' => $out, 'attributes' => $attrs, 'corpus_attributes' => tt_typology_clean_attributes( $corpus['attributes'] ),
			'my_attributes' => tt_typology_clean_attributes( $mine['attributes'] ),
			// language names: the user's over the corpus's
			'names' => array_merge( $corpus['names'], $mine['names'] ), 'corpus_names' => $corpus['names'] );
	}
}
