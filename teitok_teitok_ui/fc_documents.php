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
 */

if ( ! function_exists( 'tt_fc_documents_from_group_rows' ) ) {

	/** The attributes of the text region of the first r:<text> hit, as fields: text_id first. */
	function tt_fc_document_fields( $hits ) {
		$h = ( is_array( $hits ) && isset( $hits[0] ) && is_array( $hits[0] ) ) ? $hits[0] : array();
		$attrs = ( isset( $h['named_regions']['r']['attrs'] ) && is_array( $h['named_regions']['r']['attrs'] ) ) ? $h['named_regions']['r']['attrs'] : array();
		$names = array();
		foreach ( array_keys( $attrs ) as $k ) {
			if ( preg_match( '/^[A-Za-z_][\w]*$/', (string) $k ) ) $names[] = (string) $k;
		}
		if ( ! in_array( 'id', $names, true ) ) return array();
		$names = array_merge( array( 'id' ), array_slice( array_values( array_diff( $names, array( 'id' ) ) ), 0, 5 ) );
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
			$docs[] = array( 'id' => $id, 'title' => preg_replace( '/\.(xml|conllu)$/i', '', $id ), 'meta' => $meta );
		}
		usort( $docs, function ( $a, $b ) { return strnatcasecmp( $a['id'], $b['id'] ); } );
		return $docs;
	}
}
