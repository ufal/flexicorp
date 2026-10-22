<?php
/**
 * multiedit: the query side. Which token a query edits (the label `edit:`, or the match of a one-token query),
 * which labels it has (`head:` for reattaching, others for grouping and sorting), and the pando programs for
 * the match list (`tabulate`) and the overview (`count by`). The query engine only finds the candidates
 * (document, sentence and token ids); what the page shows is read from the XML (multiedit_xml.php).
 * See dev/MULTIEDIT-DESIGN.md.
 */

if ( ! function_exists( 'tt_me_unquoted' ) ) {
	/** The query with its string literals blanked out (same length), so that brackets and labels inside quotes do not count. */
	function tt_me_unquoted( $q ) {
		return preg_replace_callback( '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s', function ( $m ) {
			return str_repeat( '_', strlen( $m[0] ) );
		}, (string) $q );
	}
}

if ( ! function_exists( 'tt_me_labels' ) ) {
	/** The token labels of a query, in order: `a:[…]` → a (region labels `s:<s>` are not tokens). */
	function tt_me_labels( $q ) {
		preg_match_all( '/(?<![A-Za-z0-9_.])([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\[/', tt_me_unquoted( $q ), $m );
		return array_values( array_unique( $m[1] ) );
	}
}

if ( ! function_exists( 'tt_me_top_tokens' ) ) {
	/** How many token patterns `[…]` the query has at the top level (not nested inside one). */
	function tt_me_top_tokens( $q ) {
		$s = tt_me_unquoted( $q );
		$depth = 0;
		$n = 0;
		for ( $i = 0, $l = strlen( $s ); $i < $l; $i++ ) {
			if ( $s[ $i ] === '[' ) {
				if ( $depth === 0 ) $n++;
				$depth++;
			} elseif ( $s[ $i ] === ']' && $depth > 0 ) {
				$depth--;
			}
		}
		return $n;
	}
}

if ( ! function_exists( 'tt_me_prepare' ) ) {
	/**
	 * A query as multiedit runs it: array( query, labels, error ). A one-token query gets the label `edit:`;
	 * otherwise the query must name the token to edit `edit:`. Programs (`;`) are not queries.
	 */
	function tt_me_prepare( $q ) {
		$q = trim( preg_replace( '/;\s*$/', '', trim( (string) $q ) ) );
		if ( $q === '' ) return array( '', array(), 'Give a query.' );
		if ( strpos( tt_me_unquoted( $q ), ';' ) !== false ) {
			return array( '', array(), 'A query only, not a program with ";": the page counts and lists the matches itself.' );
		}
		$labels = tt_me_labels( $q );
		if ( ! in_array( 'edit', $labels, true ) ) {
			if ( tt_me_top_tokens( $q ) !== 1 || $labels ) {
				return array( '', $labels, 'Mark the token to edit with the label edit: — for example [lemma="ser"] edit:[lemma="que"].' );
			}
			$q = preg_replace( '/^\s*\[/', 'edit:[', $q, 1 );
			if ( strpos( $q, 'edit:[' ) !== 0 ) {
				return array( '', $labels, 'Mark the token to edit with the label edit: — for example edit:[lemma="que"].' );
			}
			$labels = array( 'edit' );
		}
		return array( $q, $labels, '' );
	}
}

if ( ! function_exists( 'tt_me_narrow' ) ) {
	/** The query with one more condition: LABEL.FIELD = VALUE (after `::`, joined with `&`). */
	function tt_me_narrow( $q, $label, $field, $value ) {
		$cond = $label . '.' . $field . ' = "' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
		return strpos( tt_me_unquoted( $q ), '::' ) !== false ? "$q & $cond" : "$q :: $cond";
	}
}

if ( ! function_exists( 'tt_me_ids_program' ) ) {
	/**
	 * The program listing the matches: per match the document, sentence and token id of `edit`, the ids of
	 * `head` when the query has it, and LABEL.FIELD values for grouping and sorting.
	 */
	function tt_me_ids_program( $q, array $labels, array $extra, $max ) {
		$cols = array( 'edit.text_id', 'edit.s_id', 'edit.id' );
		if ( in_array( 'head', $labels, true ) ) {
			$cols[] = 'head.id';
			$cols[] = 'head.s_id';
		}
		foreach ( $extra as $c ) if ( ! in_array( $c, $cols, true ) ) $cols[] = $c;
		return 'M = ' . $q . '; tabulate M 0 ' . (int) $max . ' ' . implode( ', ', $cols );
	}
}

if ( ! function_exists( 'tt_me_count_program' ) ) {
	function tt_me_count_program( $q, $label, $field ) {
		return 'M = ' . $q . '; count by ' . $label . '.' . $field;
	}
}

if ( ! function_exists( 'tt_me_run' ) ) {
	/**
	 * Run a pando program through flexicorp (local pando or FQS): the result block (fields, rows,
	 * total_matches …), or array( 'error' => … ). $runner (tests) replaces flexicorp.
	 */
	function tt_me_run( $program, $runner = null ) {
		if ( $runner ) return call_user_func( $runner, $program );
		require_once __DIR__ . '/flexicorp_query.php';
		$call = flexicorp_query( $program, array( 'engine' => 'pando', 'limit' => 1 ) );
		if ( empty( $call['ok'] ) ) {
			return array( 'error' => (string) ( $call['error'] ?? 'the query did not run' ) );
		}
		$res = flexicorp_query_result( $call );
		if ( ! is_array( $res ) ) {
			$done = tt_flexicorp_done( $call );
			$err = is_array( $done ) && isset( $done['error'] ) ? $done['error'] : 'no result';
			return array( 'error' => is_array( $err ) ? (string) ( $err['message'] ?? json_encode( $err ) ) : (string) $err );
		}
		return $res;
	}
}

if ( ! function_exists( 'tt_me_rows' ) ) {
	/** Tabulate rows as maps keyed by field name. */
	function tt_me_rows( array $res ) {
		$fields = isset( $res['fields'] ) && is_array( $res['fields'] ) ? $res['fields'] : array();
		$out = array();
		foreach ( ( isset( $res['rows'] ) && is_array( $res['rows'] ) ) ? $res['rows'] : array() as $row ) {
			if ( ! is_array( $row ) ) continue;
			$r = array();
			foreach ( $fields as $i => $f ) $r[ $f ] = isset( $row[ $i ] ) ? ( is_array( $row[ $i ] ) ? implode( '|', $row[ $i ] ) : (string) $row[ $i ] ) : '';
			$out[] = $r;
		}
		return $out;
	}
}
?>
