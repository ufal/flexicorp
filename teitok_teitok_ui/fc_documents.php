<?php
/**
 * fc_documents.php — the Documents tab for a corpus whose documents are not files on this server.
 *
 * A corpus served by FQS has no xmlfiles/ here, but its index knows its texts: the region query
 * r:<text> answers one compact hit per text (hit_kind "regions"), with the text's attributes.
 *
 *   tt_fc_documents_from_region_hits( $hits )   documents as the Documents tab wants them
 */

if ( ! function_exists( 'tt_fc_documents_from_region_hits' ) ) {

	/**
	 * @param array $hits the hits of an r:<text> answer
	 * @return array[] { id, title, meta{attribute: value, tokens} }
	 */
	function tt_fc_documents_from_region_hits( $hits ) {
		$docs = array();
		foreach ( is_array( $hits ) ? $hits : array() as $h ) {
			if ( ! is_array( $h ) ) continue;
			$attrs = ( isset( $h['named_regions']['r']['attrs'] ) && is_array( $h['named_regions']['r']['attrs'] ) )
				? $h['named_regions']['r']['attrs'] : array();
			$id = (string) ( $attrs['id'] ?? ( $h['doc_id'] ?? '' ) );
			if ( $id === '' ) continue;
			$meta = array();
			foreach ( $attrs as $k => $v ) {
				if ( $k !== 'id' && is_scalar( $v ) ) $meta[ (string) $k ] = (string) $v;
			}
			if ( isset( $h['token_count'] ) ) $meta['tokens'] = (string) intval( $h['token_count'] );
			$docs[] = array( 'id' => $id, 'title' => preg_replace( '/\.(xml|conllu)$/i', '', $id ), 'meta' => $meta );
		}
		return $docs;
	}
}
