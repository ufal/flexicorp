<?php
/**
 * Classification layers (classify.php): storage and permissions.
 *
 * A layer is one classification task on a corpus: a label set, the query its items came from, and
 * the items — hits identified by document and token ids (stable across reindexing), each with a
 * label from the set. Stand-off, outside the corpus XML: one file per layer in
 * <project>/Annotations/classify/<id>.xml, written under a lock and replaced atomically.
 *
 *   <layer id="run-cpa" name="…" owner="…" shared="1" unit="v" created="…" updated="…">
 *     <description>…</description>
 *     <query>v:[lemma="run" &amp; upos="VERB"]</query>
 *     <sample size="200" seed="17"/>
 *     <labels><label code="P1" key="1" name="…">description</label>…</labels>
 *     <items><item doc="xmlfiles/70.xml" tokens="w-183" label="P1" by="…" when="…">note</item>…</items>
 *   </layer>
 *
 * Permissions: a private layer is its owner's (and admins'); a shared one can be read and labelled
 * by every logged-in user of the project. Only the owner (or an admin) changes the definition.
 */

if ( ! function_exists( 'tt_classify_dir' ) ) {
	function tt_classify_dir( $root ) {
		return rtrim( (string) $root, '/' ) . '/Annotations/classify';
	}

	/** A layer id: lowercase letters, digits and dashes, from a name. */
	function tt_classify_slug( $name ) {
		$s = strtolower( trim( (string) $name ) );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		$s = trim( (string) $s, '-' );
		return substr( $s, 0, 48 );
	}

	function tt_classify_valid_id( $id ) {
		return is_string( $id ) && preg_match( '/^[a-z0-9][a-z0-9-]{0,47}$/', $id );
	}

	function tt_classify_path( $root, $id ) {
		return tt_classify_valid_id( $id ) ? tt_classify_dir( $root ) . '/' . $id . '.xml' : '';
	}

	function tt_classify_can_read( array $layer, $user, $isAdmin ) {
		if ( $isAdmin ) return true;
		if ( (string) $user === '' ) return false;
		return ! empty( $layer['shared'] ) || $layer['owner'] === (string) $user;
	}

	function tt_classify_can_label( array $layer, $user, $isAdmin ) {
		return tt_classify_can_read( $layer, $user, $isAdmin );
	}

	function tt_classify_can_edit( array $layer, $user, $isAdmin ) {
		return $isAdmin || ( (string) $user !== '' && $layer['owner'] === (string) $user );
	}

	/** The layer as an array (null when missing or unreadable). */
	function tt_classify_load( $root, $id ) {
		$path = tt_classify_path( $root, $id );
		if ( $path === '' || ! is_file( $path ) ) return null;
		$xml = @simplexml_load_file( $path );
		if ( ! $xml ) return null;
		$a = function ( $node, $k, $def = '' ) {
			return isset( $node[ $k ] ) ? (string) $node[ $k ] : $def;
		};
		$layer = array(
			'id' => $id,
			'name' => $a( $xml, 'name', $id ),
			'owner' => $a( $xml, 'owner' ),
			'shared' => $a( $xml, 'shared' ) === '1',
			'unit' => $a( $xml, 'unit' ),
			'created' => $a( $xml, 'created' ),
			'updated' => $a( $xml, 'updated' ),
			'description' => isset( $xml->description ) ? (string) $xml->description : '',
			'query' => isset( $xml->query ) ? (string) $xml->query : '',
			'sample' => null,
			'labels' => array(),
			'items' => array(),
		);
		if ( isset( $xml->sample ) ) {
			$layer['sample'] = array( 'size' => (int) $a( $xml->sample, 'size', '0' ), 'seed' => (int) $a( $xml->sample, 'seed', '0' ) );
		}
		if ( isset( $xml->labels->label ) ) {
			foreach ( $xml->labels->label as $l ) {
				$layer['labels'][] = array( 'code' => $a( $l, 'code' ), 'name' => $a( $l, 'name' ), 'key' => $a( $l, 'key' ), 'description' => (string) $l );
			}
		}
		if ( isset( $xml->items->item ) ) {
			foreach ( $xml->items->item as $it ) {
				$layer['items'][] = array( 'doc' => $a( $it, 'doc' ), 'tokens' => $a( $it, 'tokens' ), 'label' => $a( $it, 'label' ),
					'by' => $a( $it, 'by' ), 'when' => $a( $it, 'when' ), 'note' => (string) $it );
			}
		}
		return $layer;
	}

	/** Layers this user may see, without their items. */
	function tt_classify_list( $root, $user, $isAdmin ) {
		$out = array();
		foreach ( glob( tt_classify_dir( $root ) . '/*.xml' ) ?: array() as $f ) {
			$id = basename( $f, '.xml' );
			$l = tt_classify_load( $root, $id );
			if ( ! $l || ! tt_classify_can_read( $l, $user, $isAdmin ) ) continue;
			$n = count( $l['items'] );
			unset( $l['items'] );
			$l['item_count'] = $n;
			$l['can_edit'] = tt_classify_can_edit( $l, $user, $isAdmin );
			$out[] = $l;
		}
		usort( $out, function ( $x, $y ) { return strcmp( $y['updated'], $x['updated'] ); } );
		return $out;
	}

	function tt_classify_to_xml( array $layer ) {
		$d = new DOMDocument( '1.0', 'UTF-8' );
		$d->formatOutput = true;
		$root = $d->appendChild( $d->createElement( 'layer' ) );
		foreach ( array( 'id', 'name', 'owner', 'unit', 'created', 'updated' ) as $k ) {
			$root->setAttribute( $k, (string) ( $layer[ $k ] ?? '' ) );
		}
		$root->setAttribute( 'shared', ! empty( $layer['shared'] ) ? '1' : '0' );
		$root->appendChild( $d->createElement( 'description' ) )->appendChild( $d->createTextNode( (string) ( $layer['description'] ?? '' ) ) );
		$root->appendChild( $d->createElement( 'query' ) )->appendChild( $d->createTextNode( (string) ( $layer['query'] ?? '' ) ) );
		if ( ! empty( $layer['sample']['size'] ) ) {
			$s = $root->appendChild( $d->createElement( 'sample' ) );
			$s->setAttribute( 'size', (string) (int) $layer['sample']['size'] );
			$s->setAttribute( 'seed', (string) (int) ( $layer['sample']['seed'] ?? 0 ) );
		}
		$labels = $root->appendChild( $d->createElement( 'labels' ) );
		foreach ( $layer['labels'] ?? array() as $l ) {
			$e = $labels->appendChild( $d->createElement( 'label' ) );
			foreach ( array( 'code', 'name', 'key' ) as $k ) $e->setAttribute( $k, (string) ( $l[ $k ] ?? '' ) );
			$e->appendChild( $d->createTextNode( (string) ( $l['description'] ?? '' ) ) );
		}
		$items = $root->appendChild( $d->createElement( 'items' ) );
		foreach ( $layer['items'] ?? array() as $it ) {
			$e = $items->appendChild( $d->createElement( 'item' ) );
			foreach ( array( 'doc', 'tokens', 'label', 'by', 'when' ) as $k ) $e->setAttribute( $k, (string) ( $it[ $k ] ?? '' ) );
			if ( (string) ( $it['note'] ?? '' ) !== '' ) $e->appendChild( $d->createTextNode( (string) $it['note'] ) );
		}
		return $d->saveXML();
	}

	/**
	 * Change a layer under an exclusive lock: $fn gets the current layer (null if new) and returns
	 * the new one (or a string: an error, nothing written). Written to a temp file, then renamed.
	 */
	function tt_classify_update( $root, $id, callable $fn ) {
		$path = tt_classify_path( $root, $id );
		if ( $path === '' ) return 'invalid layer id';
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) ) return 'cannot create ' . basename( dirname( $dir ) ) . '/classify';
		$lock = @fopen( $dir . '/.lock', 'c' );
		if ( ! $lock ) return 'cannot lock the layer folder';
		flock( $lock, LOCK_EX );
		try {
			$new = $fn( tt_classify_load( $root, $id ) );
			if ( is_string( $new ) ) return $new;
			$new['id'] = $id;
			$new['updated'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			$tmp = $path . '.tmp' . getmypid();
			if ( @file_put_contents( $tmp, tt_classify_to_xml( $new ) ) === false || ! @rename( $tmp, $path ) ) {
				@unlink( $tmp );
				return 'cannot write the layer';
			}
			return $new;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** Clean a label set from the client: codes unique, at most 20, keys 1–9 kept when unique. */
	function tt_classify_clean_labels( $labels ) {
		$out = array();
		$codes = array();
		$keys = array();
		foreach ( is_array( $labels ) ? $labels : array() as $l ) {
			$code = trim( (string) ( $l['code'] ?? '' ) );
			if ( $code === '' || isset( $codes[ $code ] ) || mb_strlen( $code ) > 32 ) continue;
			$codes[ $code ] = 1;
			$key = trim( (string) ( $l['key'] ?? '' ) );
			if ( ! preg_match( '/^[1-9]$/', $key ) || isset( $keys[ $key ] ) ) $key = '';
			if ( $key !== '' ) $keys[ $key ] = 1;
			$out[] = array( 'code' => $code, 'name' => mb_substr( trim( (string) ( $l['name'] ?? '' ) ), 0, 80 ),
				'key' => $key, 'description' => mb_substr( trim( (string) ( $l['description'] ?? '' ) ), 0, 1000 ) );
			if ( count( $out ) >= 20 ) break;
		}
		return $out;
	}

	/** Create a layer or change its definition (owner / admin); items are kept. */
	function tt_classify_save_definition( $root, array $in, $user, $isAdmin ) {
		if ( (string) $user === '' ) return 'log in to create classification layers';
		$id = trim( (string) ( $in['id'] ?? '' ) );
		if ( $id === '' ) $id = tt_classify_slug( $in['name'] ?? '' );
		if ( ! tt_classify_valid_id( $id ) ) return 'give the layer a name';
		$labels = tt_classify_clean_labels( $in['labels'] ?? array() );
		if ( ! $labels ) return 'a layer needs at least one label';
		$isNew = ! empty( $in['create'] );
		return tt_classify_update( $root, $id, function ( $cur ) use ( $in, $labels, $user, $isAdmin, $isNew ) {
			if ( $cur && $isNew ) return 'a layer with this name exists already';
			if ( $cur && ! tt_classify_can_edit( $cur, $user, $isAdmin ) ) return 'only the owner can change this layer';
			$layer = $cur ?: array( 'owner' => (string) $user, 'created' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'items' => array() );
			$layer['name'] = mb_substr( trim( (string) ( $in['name'] ?? '' ) ), 0, 120 ) ?: ( $layer['name'] ?? '' );
			$layer['description'] = mb_substr( trim( (string) ( $in['description'] ?? '' ) ), 0, 4000 );
			$layer['query'] = mb_substr( trim( (string) ( $in['query'] ?? '' ) ), 0, 4000 );
			$layer['unit'] = preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string) ( $in['unit'] ?? '' ) ) ? (string) $in['unit'] : '';
			$layer['shared'] = ! empty( $in['shared'] );
			$size = (int) ( $in['sample']['size'] ?? 0 );
			$layer['sample'] = $size > 0 ? array( 'size' => min( $size, 5000 ), 'seed' => (int) ( $in['sample']['seed'] ?? 1 ) ) : null;
			$layer['labels'] = $labels;
			return $layer;
		} );
	}

	/** Set (or with label '' remove) this user's label on one item. */
	function tt_classify_set_label( $root, $id, array $in, $user, $isAdmin ) {
		if ( (string) $user === '' ) return 'log in to classify';
		$doc = trim( (string) ( $in['doc'] ?? '' ) );
		$tokens = trim( preg_replace( '/\s+/', ' ', (string) ( $in['tokens'] ?? '' ) ) );
		if ( $doc === '' || $tokens === '' || strlen( $doc ) > 400 || strlen( $tokens ) > 2000 ) return 'which item?';
		$label = trim( (string) ( $in['label'] ?? '' ) );
		$note = mb_substr( trim( (string) ( $in['note'] ?? '' ) ), 0, 1000 );
		return tt_classify_update( $root, $id, function ( $cur ) use ( $doc, $tokens, $label, $note, $user, $isAdmin ) {
			if ( ! $cur ) return 'no such layer';
			if ( ! tt_classify_can_label( $cur, $user, $isAdmin ) ) return 'you cannot label in this layer';
			if ( $label !== '' && ! in_array( $label, array_column( $cur['labels'], 'code' ), true ) ) return 'unknown label';
			$items = array();
			foreach ( $cur['items'] as $it ) {
				if ( $it['doc'] === $doc && $it['tokens'] === $tokens && $it['by'] === (string) $user ) continue;
				$items[] = $it;
			}
			if ( $label !== '' ) {
				$items[] = array( 'doc' => $doc, 'tokens' => $tokens, 'label' => $label, 'note' => $note,
					'by' => (string) $user, 'when' => gmdate( 'Y-m-d\TH:i:s\Z' ) );
			}
			$cur['items'] = $items;
			return $cur;
		} );
	}

	/** CSV of the items (doc, tokens, label, label name, annotator, time, note). */
	function tt_classify_csv( array $layer ) {
		$names = array_column( $layer['labels'], 'name', 'code' );
		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, array( 'doc', 'tokens', 'label', 'label_name', 'annotator', 'time', 'note' ), ',', '"', '' );
		foreach ( $layer['items'] as $it ) {
			fputcsv( $fh, array( $it['doc'], $it['tokens'], $it['label'], $names[ $it['label'] ] ?? '', $it['by'], $it['when'], $it['note'] ), ',', '"', '' );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}
}
