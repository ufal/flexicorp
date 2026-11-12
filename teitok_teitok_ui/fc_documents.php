<?php
/**
 * fc_documents.php — the Documents tab for a corpus whose documents are not files on this server.
 *
 * A corpus served by FQS has no xmlfiles/ here, but its index knows its texts. Listing them as
 * region hits (r:<text>) costs about 0.2 s per text (the first tokens of each are fetched), so the
 * list is a grouping instead: `[]; count by text_id, text_<attr>…` answers all of them in about a
 * second, with the text's token count; one r:<text> hit tells which attributes the texts have.
 *
 *   tt_fc_document_fields( $hits )          [ 'text_id', 'text_langcode', … ] from one r:<text> hit
 *   tt_fc_documents_from_group_rows( $rows ) documents as the Documents tab wants them
 *   tt_fc_documents_where( $json )           the column filters of a request, checked: { text_attr: value }
 *   tt_fc_documents_condition( $where )      the token condition for them: [text_a="v" & …], or [] without filters
 */

if ( ! function_exists( 'tt_fc_documents_from_group_rows' ) ) {

	/** The attributes of the text region of the first r:<text> hit, as fields: text_id first. */
	function tt_fc_document_fields( $hits ) {
		$h = ( is_array( $hits ) && isset( $hits[0] ) && is_array( $hits[0] ) ) ? $hits[0] : array();
		$attrs = ( isset( $h['named_regions']['r']['attrs'] ) && is_array( $h['named_regions']['r']['attrs'] ) ) ? $h['named_regions']['r']['attrs'] : array();
		$names = array();
		foreach ( array_keys( $attrs ) as $k ) {
			// a path is not metadata (the id already is the file): left out of the columns
			if ( preg_match( '/^[A-Za-z_][\w]*$/', (string) $k ) && ! preg_match( '/(^|_)(file)?path$/i', (string) $k ) ) $names[] = (string) $k;
		}
		if ( ! in_array( 'id', $names, true ) ) return array();
		$names = array_merge( array( 'id' ), array_slice( array_values( array_diff( $names, array( 'id' ) ) ), 0, 8 ) );
		return array_map( function ( $n ) { return 'text_' . $n; }, $names );
	}

	/**
	 * @param array[] $rows the rows of `count by text_id, …` (one per text; count = its tokens)
	 * @return array[] { id, title, meta{attribute: value, tokens} }, by id
	 */
	function tt_fc_documents_from_group_rows( $rows ) {
		$docs = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( ! is_array( $r ) ) continue;
			$id = (string) ( $r['text_id'] ?? '' );
			if ( $id === '' ) continue;
			$meta = array();
			foreach ( $r as $k => $v ) {
				if ( strpos( (string) $k, 'text_' ) === 0 && $k !== 'text_id' && is_scalar( $v ) ) $meta[ substr( (string) $k, 5 ) ] = (string) $v;
			}
			if ( isset( $r['count'] ) ) $meta['tokens'] = (string) intval( $r['count'] );
			// the name as the region table shows it: the file name, without folders and extension
			$docs[] = array( 'id' => $id, 'title' => preg_replace( '/\.(xml|conllu)$/i', '', basename( $id ) ), 'meta' => $meta );
		}
		usort( $docs, function ( $a, $b ) { return strnatcasecmp( $a['id'], $b['id'] ); } );
		return $docs;
	}
}

if ( ! function_exists( 'tt_fc_documents_where' ) ) {

	/** The column filters sent with a request: only text attributes, only plain values. */
	function tt_fc_documents_where( $raw ) {
		$d = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$out = array();
		foreach ( is_array( $d ) ? $d : array() as $k => $v ) {
			if ( ! preg_match( '/^text_[A-Za-z0-9_]+$/', (string) $k ) || ! is_scalar( $v ) ) continue;
			$v = trim( (string) $v );
			if ( $v !== '' ) $out[ (string) $k ] = mb_substr( $v, 0, 200, 'UTF-8' );
		}
		return array_slice( $out, 0, 8, true );
	}

	/** [text_lang="English" & text_part="train"] for the filters, [] when there are none. */
	function tt_fc_documents_condition( $where ) {
		$parts = array();
		foreach ( is_array( $where ) ? $where : array() as $k => $v ) {
			$parts[] = $k . '="' . addcslashes( (string) $v, "\\\"" ) . '"';
		}
		return '[' . implode( ' & ', $parts ) . ']';
	}
}
