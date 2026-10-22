<?php
/**
 * multiedit: what the page shows of a match comes from the XML, not from the index (right even when the
 * index is stale): the target token's form, its current values, the context around it, and for a reattach the
 * form of its current and its new head. And which fields may be edited.
 */

if ( ! function_exists( 'tt_me_fields' ) ) {
	/**
	 * The token attributes multiedit may change: the settings' tags (xmlfile/pattributes/tags, not noedit),
	 * head and deprel, and the word forms (xmlfile/pattributes/forms, not pform) only with
	 * <flexicorp multiedit_forms="1"/>. Never id. Returns array( key => label ).
	 */
	function tt_me_fields() {
		$out = array();
		$add = function ( $group ) use ( &$out ) {
			foreach ( (array) getset( "xmlfile/pattributes/$group", array() ) as $key => $item ) {
				if ( ! is_array( $item ) || ! empty( $item['noedit'] ) || in_array( $key, array( 'id', 'pform' ), true ) ) continue;
				$out[ $key ] = trim( (string) ( $item['display'] ?? '' ) ) ?: $key;
			}
		};
		$add( 'tags' );
		foreach ( array( 'deprel' => 'relation', 'head' => 'head' ) as $k => $l ) if ( ! isset( $out[ $k ] ) ) $out[ $k ] = $l;
		if ( (string) getset( 'flexicorp/multiedit_forms', '' ) === '1' ) $add( 'forms' );
		return $out;
	}
}

if ( ! function_exists( 'tt_me_doc_path' ) ) {
	/** The file of a document id as the index gives it (xmlfiles/x.xml, x.xml, sub/x.xml), inside xmlfiles/; or ''. */
	function tt_me_doc_path( $doc ) {
		$doc = ltrim( str_replace( '\\', '/', (string) $doc ), '/' );
		if ( $doc === '' || strpos( $doc, '..' ) !== false ) return '';
		if ( strpos( $doc, 'xmlfiles/' ) !== 0 ) $doc = "xmlfiles/$doc";
		if ( substr( $doc, -4 ) !== '.xml' ) $doc .= '.xml';
		return is_file( $doc ) ? $doc : '';
	}
}

if ( ! function_exists( 'tt_me_load' ) ) {
	/** A document read-only, as TEITOK reads it (namespaces made plain), or null. */
	function tt_me_load( $path ) {
		$raw = @file_get_contents( $path );
		if ( $raw === false ) return null;
		if ( function_exists( 'namespacemake' ) ) $raw = namespacemake( $raw );
		$prev = libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $raw );
		libxml_use_internal_errors( $prev );
		return $xml ?: null;
	}
}

if ( ! function_exists( 'tt_me_form' ) ) {
	/** A token's form: @form, else its text. */
	function tt_me_form( $el ) {
		$f = (string) $el['form'];
		if ( $f !== '' ) return $f;
		return trim( html_entity_decode( strip_tags( $el->asXML() ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
	}
}

if ( ! function_exists( 'tt_me_doc_index' ) ) {
	/** Per document: all tok in order (for the context), and tok / dtok by id. */
	function tt_me_doc_index( $xml ) {
		$toks = $xml->xpath( '//tok' ) ?: array();
		$pos = array();
		$byid = array();
		foreach ( $toks as $i => $t ) {
			$id = (string) $t['id'];
			if ( $id !== '' ) { $pos[ $id ] = $i; $byid[ $id ] = $t; }
			foreach ( $t->dtok as $d ) {
				$did = (string) $d['id'];
				if ( $did !== '' ) { $byid[ $did ] = $d; $pos[ $did ] = $i; }
			}
		}
		return array( $toks, $pos, $byid );
	}
}

if ( ! function_exists( 'tt_me_page' ) ) {
	/**
	 * The rows of one page with what the XML says: for each row (document, token id, head id): found, form,
	 * the current value of each field, left and right context (WIDTH tokens), and the forms of the current and
	 * the new head. Documents are read once per page.
	 */
	function tt_me_page( array $rows, array $fields, $width = 6 ) {
		$docs = array();
		$out = array();
		foreach ( $rows as $r ) {
			$doc = (string) $r['doc'];
			if ( ! array_key_exists( $doc, $docs ) ) {
				$path = tt_me_doc_path( $doc );
				$xml = $path !== '' ? tt_me_load( $path ) : null;
				$docs[ $doc ] = $xml ? tt_me_doc_index( $xml ) : null;
			}
			$o = array( 'n' => $r['n'], 'doc' => $doc, 'id' => $r['id'], 'sid' => $r['sid'] ?? '', 'found' => false );
			$d = $docs[ $doc ];
			if ( ! $d || ! isset( $d[2][ $r['id'] ] ) ) {
				$o['problem'] = $d ? 'the token is no longer in the document (the index is older than the file)' : 'the document cannot be read';
				$out[] = $o;
				continue;
			}
			list( $toks, $pos, $byid ) = $d;
			$el = $byid[ $r['id'] ];
			$o['found'] = true;
			$o['form'] = tt_me_form( $el );
			$o['values'] = array();
			foreach ( $fields as $f ) $o['values'][ $f ] = (string) $el[ $f ];
			$at = $pos[ $r['id'] ];
			$ctx = function ( $from, $to ) use ( $toks ) {
				$w = array();
				for ( $i = max( 0, $from ); $i < min( count( $toks ), $to ); $i++ ) $w[] = tt_me_form( $toks[ $i ] );
				return implode( ' ', $w );
			};
			$o['left'] = $ctx( $at - $width, $at );
			$o['right'] = $ctx( $at + 1, $at + 1 + $width );
			if ( $el->getName() === 'dtok' ) $o['in'] = tt_me_form( $toks[ $at ] );   // the word it is part of
			$h = (string) $el['head'];
			if ( $h !== '' && isset( $byid[ $h ] ) ) $o['headform'] = tt_me_form( $byid[ $h ] );
			if ( ! empty( $r['head'] ) ) {
				$o['newhead'] = $r['head'];
				$o['newheadform'] = isset( $byid[ $r['head'] ] ) ? tt_me_form( $byid[ $r['head'] ] ) : '';
			}
			$out[] = $o;
		}
		return $out;
	}
}

if ( ! function_exists( 'tt_me_cycle' ) ) {
	/**
	 * Would attaching token ID to NEWHEAD make a cycle? Follows the heads up from NEWHEAD (heads are token
	 * ids of the same document); BYID: id => element.
	 */
	function tt_me_cycle( $id, $newhead, array $byid ) {
		$seen = array();
		for ( $h = (string) $newhead; $h !== '' && $h !== '0'; $h = isset( $byid[ $h ] ) ? (string) $byid[ $h ]['head'] : '' ) {
			if ( $h === (string) $id ) return true;
			if ( isset( $seen[ $h ] ) ) return false;   // an existing cycle higher up: not this change's
			$seen[ $h ] = 1;
		}
		return false;
	}
}
?>
