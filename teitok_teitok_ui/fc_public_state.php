<?php
/**
 * fc_public_state.php — what a page and its AJAX answers tell someone who is not an admin.
 *
 * Server paths are for admins (the project folder, the venv, registries, the folder FQS read an
 * index from): a visitor or user gets none of them. The diagnostics that carry them go
 * altogether (the log of the commands the page ran, `bootstrapDiag`; the engines' raw answers); `projectRoot` becomes the
 * project's folder name (what the page's own code uses it for); any absolute path left in other
 * data (an engine's status text, FQS's `fqs_meta`) is replaced by `…`. The search results' own
 * text (hits, context, fragments, documents) is left as it is: a corpus may contain such strings.
 *
 *   tt_fc_state_public( $state )        the page / AJAX state for a non-admin
 *   tt_fc_strip_server_paths( $value )  the same path removal on any value (fqs_query.php)
 */

if ( ! function_exists( 'tt_fc_strip_server_paths' ) ) {

	/** Keys whose values are corpus content, not diagnostics: never rewritten. */
	function tt_fc_public_content_keys() {
		return array( 'hits', 'matches', 'lines', 'context', 'context_xml', 'context_data', 'fragment',
			'fragments', 'tokens', 'documents', 'values', 'rows', 'collocates', 'items', 'kwic', 'text' );
	}

	/** An absolute path on this server, as a whole word (not part of a URL). */
	function tt_fc_server_path_pattern() {
		return '#(?<![\w:/.~-])/(?:var|opt|home|srv|etc|usr|tmp|private|Users|Library|Volumes|mnt|data|corpora)/[^\s"\'<>|,;)\]]*#';
	}

	function tt_fc_strip_server_paths( $value, $depth = 0 ) {
		if ( is_string( $value ) ) {
			return strpos( $value, '/' ) === false ? $value : preg_replace( tt_fc_server_path_pattern(), '…', $value );
		}
		if ( ! is_array( $value ) || $depth > 40 ) return $value;
		$content = tt_fc_public_content_keys();
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && in_array( $k, $content, true ) ) continue;
			$value[ $k ] = tt_fc_strip_server_paths( $v, $depth + 1 );
		}
		return $value;
	}

	function tt_fc_state_public( $state ) {
		if ( ! is_array( $state ) ) return $state;
		$state['debugEntries'] = array();
		$state['debugAppend'] = array();
		unset( $state['bootstrapDiag'] );
		// the engines' raw answers (only for the admin's "Raw" buttons) repeat the hits and were the
		// largest part of a result page: about 1.7 MB of 4.4 MB for 'give' in UD 2.19
		if ( isset( $state['responses'] ) && is_array( $state['responses'] ) ) {
			foreach ( $state['responses'] as $k => $resp ) {
				if ( is_array( $resp ) ) unset( $state['responses'][ $k ]['raw'] );
			}
		}
		if ( isset( $state['search']['response'] ) && is_array( $state['search']['response'] ) ) {
			unset( $state['search']['response']['raw'] );
		}
		if ( isset( $state['projectRoot'] ) && is_string( $state['projectRoot'] ) ) {
			$state['projectRoot'] = basename( rtrim( $state['projectRoot'], '/' ) );
		}
		return tt_fc_strip_server_paths( $state );
	}
}
