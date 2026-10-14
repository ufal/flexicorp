<?php
/**
 * Custom layers made public: a user publishes their shared attributes under a public name, chosen from
 * free suggestions (or typed), unique in the corpus. Everyone can then search and count by them as
 * `<name>-<attribute>`: `[maarten-feelwsd="experience"]`, as the owner writes `my-feelwsd`. Names
 * and attributes are letters a–z, so a query name is two words joined by one hyphen.
 * Names are rewritten like `my-…` (custom_layers_names.php); the owner keeps writing `my-…`.
 * Stored as `<customLayers public="feel">` in the owner's Users/<folder>/custom_layers.xml.
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/custom_layers_store.php';
require_once __DIR__ . '/custom_layers_overlay.php';

if ( ! function_exists( 'tt_cl_public_valid' ) ) {
	/** Words that cannot be public names: our own prefixes and common corpus attribute / region names. */
	function tt_cl_public_reserved() {
		return array( 'my', 'overlay', 'private', 'text', 'match', 'feats', 'tok', 'token', 'word', 'form', 'lemma',
			'pos', 'upos', 'xpos', 'id', 'head', 'deprel', 'deps', 'misc', 'doc', 'body', 'tuid', 'lang' );
	}

	/** A public name: 2–16 lowercase letters a–z, not reserved. */
	function tt_cl_public_valid( $name ) {
		return is_string( $name ) && preg_match( '/^[a-z]{2,16}$/', $name ) && ! in_array( $name, tt_cl_public_reserved(), true );
	}

	/** Published names in the corpus: name → owner folder. */
	function tt_cl_public_names( $root ) {
		$out = array();
		foreach ( tt_cl_all( $root ) as $folder => $data ) {
			if ( ! empty( $data['public'] ) ) $out[ $data['public'] ] = $folder;
		}
		return $out;
	}

	/** The name others type for an attribute of a layer published as $public. */
	function tt_cl_public_query_name( $public, $key ) {
		return $public . '-' . $key;
	}

	/** The owner's attributes that are public: their own attributes marked shared (references stay theirs). */
	function tt_cl_public_attributes( array $data ) {
		return array_filter( $data['attributes'], function ( $at ) { return $at['owner'] === '' && ! empty( $at['shared'] ); } );
	}

	/**
	 * Free names to publish under, best first: from the attributes and tasks (their first word, the
	 * whole key), then from the login (its part before @ or a space). At most $max.
	 */
	function tt_cl_public_suggestions( $root, $folder, $login = '', $max = 5 ) {
		$data = tt_cl_load( $root, $folder );
		$taken = tt_cl_public_names( $root );
		$cands = array();
		$add = function ( $s ) use ( &$cands ) {
			$s = substr( preg_replace( '/[^a-z]/', '', strtolower( (string) $s ) ), 0, 16 );
			if ( $s !== '' && ! in_array( $s, $cands, true ) ) $cands[] = $s;
		};
		// who made it: the login's first part (maarten), its initials and whole (maartenjanssen), the
		// folder; then what it is about: the tasks (feel), and the person with a task (maartenfeel)
		$person = preg_split( '/[@\s]/', (string) $login )[0] ?? '';
		$parts = preg_split( '/[^a-z]+/', strtolower( $person ), -1, PREG_SPLIT_NO_EMPTY );
		if ( $parts ) $add( $parts[0] );
		if ( count( $parts ) > 1 ) { $add( implode( '', array_map( function ( $w ) { return $w[0]; }, $parts ) ) ); $add( implode( '', $parts ) ); }
		$add( $folder );
		foreach ( array_keys( $data['tasks'] ) as $k ) $add( $k );
		foreach ( array_keys( $data['tasks'] ) as $k ) if ( $parts ) $add( $parts[0] . $k );
		$out = array();
		foreach ( $cands as $c ) {
			if ( tt_cl_public_valid( $c ) && ( ! isset( $taken[ $c ] ) || $taken[ $c ] === $folder ) ) $out[] = $c;
			if ( count( $out ) >= $max ) break;
		}
		return $out;
	}

	/** Publish the user's shared attributes under $name ('' = no longer public). */
	function tt_cl_set_public( $root, $user, $name ) {
		$name = strtolower( trim( (string) $name ) );
		$folder = tt_cl_user_folder( $user );
		if ( $name !== '' ) {
			if ( ! tt_cl_public_valid( $name ) ) return 'a public name is 2 to 16 letters and digits, starting with a letter (and not a word like "text" or "my")';
			$owner = tt_cl_public_names( $root )[ $name ] ?? '';
			if ( $owner !== '' && $owner !== $folder ) return "the name '$name' is taken; choose one of the suggestions";
		}
		return tt_cl_update( $root, $user, function ( $data ) use ( $name ) {
			if ( $name !== '' && ! tt_cl_public_attributes( $data ) ) return 'share an attribute first (edit it, Shared): only shared attributes are published';
			$data['public'] = $name;
			return $data;
		} );
	}

	/**
	 * Published attributes others can search (layers built and not stale): query name → [stored name,
	 * display, public name]. The user's own are left out (they write my-…).
	 */
	function tt_cl_public_map( $root, $exceptFolder = '' ) {
		$out = array();
		$main = @filemtime( rtrim( tt_fc_env_pando_index_dir( $root ), '/' ) . '/corpus.info' );
		foreach ( tt_cl_all( $root ) as $folder => $data ) {
			$pub = (string) ( $data['public'] ?? '' );
			if ( $pub === '' || $folder === $exceptFolder ) continue;
			$built = @filemtime( tt_cl_overlay_dir( tt_fc_env_pando_index_dir( $root ), $folder ) . '/corpus.info' );
			if ( ! $built || ( $main !== false && $built < $main ) ) continue;
			foreach ( tt_cl_public_attributes( $data ) as $key => $at ) {
				$out[ tt_cl_public_query_name( $pub, $key ) ] = array( tt_cl_overlay_name( $folder, $key ),
					$at['display'] !== '' ? $at['display'] : $key, $pub );
			}
		}
		return $out;
	}

	/**
	 * flexicorp's attribute catalog with the custom-layer attributes the user can search, as
	 * `custom_labels`: [{value, label, group: mine|published}] — the user's own (my-…, once searchable)
	 * and those others published. The query builder lists them in groups of their own.
	 */
	function tt_cl_catalog_add_labels( $catalog, $root ) {
		if ( ! is_array( $catalog ) ) $catalog = array();
		$me = tt_fc_env_user_folder();
		$labels = array();
		foreach ( tt_cl_overlay_field_rows( $root, $me ) as $r ) {
			$labels[] = array( 'value' => $r['name'], 'label' => preg_replace( '/ \(my labels\)$/', '', $r['label'] ), 'group' => 'mine' );
		}
		foreach ( tt_cl_public_map( $root, $me ) as $qname => $pub ) {
			$labels[] = array( 'value' => $qname, 'label' => $pub[1] . ' (' . $pub[2] . ')', 'group' => 'published' );
		}
		$catalog['custom_labels'] = $labels;
		return $catalog;
	}
}
