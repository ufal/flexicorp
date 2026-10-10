<?php
/**
 * flexicorp TEITOK UI: one shape for engine answers.
 *
 * Every route that runs a query or a program (FQS /run and /query, the local pando
 * CLI or daemon) hands its parsed answer to tt_fc_result_unwrap(); the array it
 * returns is the `done.result` the browser receives. Before, each route peeled the
 * envelopes itself and lifted only tables and collocates out of
 * `{operation, result: {…}}`, so the hits of a named-query program
 * (`A = […]; B = […];`) stayed one level too deep and the UI saw none.
 */

if ( !function_exists('tt_flexicorp_pando_top_level_looks_like_query_result') ) {
	/**
	 * True when flexicorp-pando returned native program JSON from run_program_json (multi-statement
	 * scripts with ';') rather than the flexicorp envelope { success, done: { result } }.
	 * Without this, daemon/CLI treat the call as failed (no top-level ok/success) and PHP
	 * leaves result empty so Stats (e.g. freq A, B, C by …) breaks.
	 */
	function tt_flexicorp_pando_top_level_looks_like_query_result( $a ) {
		if ( ! is_array( $a ) ) {
			return false;
		}
		if ( isset( $a['done'] ) && is_array( $a['done'] ) && isset( $a['done']['result'] ) ) {
			return false;
		}
		if ( ( ! empty( $a['success'] ) || ! empty( $a['ok'] ) ) && isset( $a['result'] ) && is_array( $a['result'] ) ) {
			return false;
		}
		$err = isset( $a['error'] ) ? trim( (string) $a['error'] ) : '';
		if ( $err !== '' && empty( $a['rows'] ) && empty( $a['hits'] ) && empty( $a['items'] ) && empty( $a['compare_queries'] ) ) {
			return false;
		}
		if ( isset( $a['compare_queries'] ) && is_array( $a['compare_queries'] ) ) {
			return true;
		}
		if ( isset( $a['rows'] ) && is_array( $a['rows'] ) ) {
			return true;
		}
		if ( isset( $a['table'] ) && is_array( $a['table'] ) ) {
			return true;
		}
		if ( isset( $a['items'] ) && is_array( $a['items'] ) ) {
			return true;
		}
		if ( isset( $a['hits'] ) && is_array( $a['hits'] ) ) {
			return true;
		}
		if ( isset( $a['total'] ) && isset( $a['items'] ) && is_array( $a['items'] ) ) {
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'tt_flexicorp_pando_normalize_multiquery_freq_result' ) ) {
	/**
	 * Some Pando program JSON builds multi-query freq rows with per-row `queries:{Q:{count:…}}`
	 * but omits top-level `compare_queries`. TEITOK Stats + promote-to-Stats need the names array.
	 */
	function tt_flexicorp_pando_normalize_multiquery_freq_result( &$result ) {
		if ( ! is_array( $result ) ) {
			return;
		}
		if ( isset( $result['compare_queries'] ) && is_array( $result['compare_queries'] ) && count( $result['compare_queries'] ) > 0 ) {
			return;
		}
		if ( isset( $result['rows'][0] ) && is_array( $result['rows'][0] ) ) {
			$first = &$result['rows'][0];
		} elseif ( isset( $result['table']['rows'][0] ) && is_array( $result['table']['rows'][0] ) ) {
			$first = &$result['table']['rows'][0];
		} else {
			return;
		}
		if ( isset( $first['queries'] ) && is_string( $first['queries'] ) && trim( $first['queries'] ) !== '' ) {
			$decoded = json_decode( $first['queries'], true );
			if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
				$first['queries'] = $decoded;
			}
		}
		if ( ! isset( $first['queries'] ) || ! is_array( $first['queries'] ) || ! count( $first['queries'] ) ) {
			return;
		}
		$result['compare_queries'] = array_keys( $first['queries'] );
		if ( ! isset( $result['result_type'] ) || trim( (string) $result['result_type'] ) === '' ) {
			$result['result_type'] = 'table';
		}
	}
}

if ( ! function_exists( 'tt_fc_result_is_body' ) ) {
	/** An engine result body (hits, a table, collocates), as opposed to an envelope around one. */
	function tt_fc_result_is_body( $a ) {
		if ( ! is_array( $a ) ) return false;
		foreach ( array( 'hits', 'rows', 'collocates', 'compare_queries', 'totals_per_query', 'items' ) as $k ) {
			if ( isset( $a[ $k ] ) && is_array( $a[ $k ] ) ) return true;
		}
		return isset( $a['table'] ) && is_array( $a['table'] );
	}
}

if ( ! function_exists( 'tt_fc_result_unwrap' ) ) {
	/**
	 * The result body inside any engine envelope: FQS /query (`raw.done.result`, `raw.result`),
	 * the flexicorp envelope (`done.result`), a pando program answer (`{ok, operation,
	 * result: {…}}`, also nested once more), a toolchain's `data` wrapper. The innermost
	 * operation tag is kept on the body when it has none of its own. Without a body, the
	 * innermost array reached (e.g. `{query, page}`), or with $strict only the operation.
	 *
	 * @param mixed $payload
	 * @param bool $strict
	 * @return array<string,mixed>
	 */
	function tt_fc_result_unwrap( $payload, $strict = false ) {
		if ( ! is_array( $payload ) ) return array();
		$op = '';
		$x = $payload;
		for ( $depth = 0; $depth < 8; $depth++ ) {
			foreach ( array( 'operation_effective', 'operation' ) as $k ) {
				if ( isset( $x[ $k ] ) && is_string( $x[ $k ] ) && trim( $x[ $k ] ) !== '' ) {
					$op = strtolower( trim( $x[ $k ] ) );
				}
			}
			if ( tt_fc_result_is_body( $x ) ) break;
			if ( isset( $x['raw']['done']['result'] ) && is_array( $x['raw']['done']['result'] ) ) {
				$x = $x['raw']['done']['result'];
			} elseif ( isset( $x['raw']['result'] ) && is_array( $x['raw']['result'] ) ) {
				$x = $x['raw']['result'];
			} elseif ( isset( $x['raw'] ) && tt_fc_result_is_body( $x['raw'] ) ) {
				$x = $x['raw'];
			} elseif ( isset( $x['done']['result'] ) && is_array( $x['done']['result'] ) ) {
				$x = $x['done']['result'];
			} elseif ( isset( $x['result'] ) && is_array( $x['result'] ) ) {
				$x = $x['result'];
			} elseif ( ! isset( $x['done'] ) && isset( $x['data'] ) && is_array( $x['data'] ) ) {
				$x = $x['data'];
			} else {
				break;
			}
		}
		if ( $strict && ! tt_fc_result_is_body( $x ) ) {
			return $op !== '' ? array( 'operation' => $op ) : array();
		}
		if ( $op !== '' && ! isset( $x['operation'] ) ) {
			$x['operation'] = $op;
		}
		return $x;
	}
}

if ( ! function_exists( 'tt_fc_result_finish_collocates' ) ) {
	/** coll / dcoll: a table for the Stats UI, with returned and total for the hit-count helpers. */
	function tt_fc_result_finish_collocates( array &$r ) {
		if ( ! isset( $r['collocates'] ) || ! is_array( $r['collocates'] ) ) return;
		if ( ! isset( $r['result_type'] ) || trim( (string) $r['result_type'] ) === '' ) {
			$r['result_type'] = 'table';
		}
		if ( ! isset( $r['returned'] ) ) {
			$r['returned'] = count( $r['collocates'] );
		}
		if ( ! isset( $r['total'] ) && isset( $r['matches'] ) && is_numeric( $r['matches'] ) ) {
			$r['total'] = (int) $r['matches'];
		}
	}
}

if ( ! function_exists( 'tt_fc_result_finish_table' ) ) {
	/** freq / count / multi-query tables: compare_queries named, result_type table. */
	function tt_fc_result_finish_table( array &$r ) {
		tt_flexicorp_pando_normalize_multiquery_freq_result( $r );
		$hasCmp = isset( $r['compare_queries'] ) && is_array( $r['compare_queries'] ) && count( $r['compare_queries'] ) > 0;
		$hasRows = isset( $r['rows'] ) && is_array( $r['rows'] ) && count( $r['rows'] ) > 0;
		if ( ( $hasCmp || $hasRows ) && ( ! isset( $r['result_type'] ) || trim( (string) $r['result_type'] ) === '' ) ) {
			$r['result_type'] = 'table';
		}
	}
}

if ( ! function_exists( 'tt_fc_result_lift_page_total' ) ) {
	/**
	 * pando-server / warm library answers keep the counts in page{} and the background count
	 * in job{}: lift a known total to the flexicorp shape (a count still running is not a
	 * total: "Show more" must stay).
	 */
	function tt_fc_result_lift_page_total( array &$r ) {
		if ( isset( $r['total'] ) || ! isset( $r['page'] ) || ! is_array( $r['page'] ) ) return;
		$pg = $r['page'];
		$job = isset( $r['job'] ) && is_array( $r['job'] ) ? $r['job'] : array();
		if ( ! empty( $job['finished'] ) && isset( $job['total'] ) && is_numeric( $job['total'] ) ) {
			$r['total'] = (int) $job['total'];
			$r['total_exact'] = ! empty( $job['total_exact'] );
		} elseif ( isset( $pg['total'] ) && is_numeric( $pg['total'] ) && ! empty( $pg['total_exact'] ) ) {
			$r['total'] = (int) $pg['total'];
			$r['total_exact'] = ! empty( $pg['total_exact'] );
		}
		if ( ! isset( $r['returned'] ) && isset( $pg['returned'] ) ) $r['returned'] = (int) $pg['returned'];
		if ( ! isset( $r['start'] ) && isset( $pg['start'] ) ) $r['start'] = (int) $pg['start'];
	}
}

if ( ! function_exists( 'tt_fc_result_kind' ) ) {
	/** What the body is, for the client's planner: hits, compare_table, table, collocates or empty. */
	function tt_fc_result_kind( array $r ) {
		if ( isset( $r['collocates'] ) && is_array( $r['collocates'] ) ) return 'collocates';
		if ( isset( $r['compare_queries'] ) && is_array( $r['compare_queries'] ) && count( $r['compare_queries'] ) ) return 'compare_table';
		if ( ( isset( $r['rows'] ) && is_array( $r['rows'] ) ) || ( isset( $r['table'] ) && is_array( $r['table'] ) ) ) return 'table';
		if ( isset( $r['hits'] ) && is_array( $r['hits'] ) ) return 'hits';
		return 'empty';
	}
}
