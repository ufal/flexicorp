<?php
/**
 * flexicorp TEITOK UI: one place that decides how a query or program reaches an engine.
 *
 * tt_fc_engine_route() picks the route — FQS /run (a program with an aggregation,
 * collocation, keyness or a frequency request), FQS /query (a plain query) or the local
 * engine (pando CLI/daemon, the flexicorp CLI for the other backends) — and
 * tt_fc_engine_search() / tt_fc_engine_freq() / tt_fc_engine_coll() run it. Before, the
 * search and frequency branches of flexicorp.php each decided on their own, so a fix to
 * one route missed the other. The calls return the usual call envelope; its done.result
 * says which route ran (`engine_route`), and fc_result.php gives every route the same
 * result shape.
 *
 * $ctx (built once per request in flexicorp.php): fqs_eligible, fqs_backend_override,
 * fqs_probe, backend, project_root, backend_override_args, query_engine, query_language,
 * corpus_format, pando_exec.
 */

if ( ! function_exists( 'tt_fc_engine_session_id' ) ) {
	/**
	 * This user's engine session for a corpus: stable per PHP session and corpus, never the PHP
	 * session id itself (as in fqs_query.php). Named queries a program defines stay in it, and
	 * pando reuses them when the next program defines them again unchanged (`"reused"`): a
	 * search, then Frequency, then Dependencies run each named query once. '' without a session.
	 */
	function tt_fc_engine_session_id( $corpusId ) {
		if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
			@session_start();
		}
		$sid = function_exists( 'session_id' ) ? (string) session_id() : '';
		if ( $sid === '' || (string) $corpusId === '' ) return '';
		return 'tt-' . substr( hash( 'sha256', $sid . '|' . $corpusId ), 0, 32 );
	}
}

if ( ! function_exists( 'tt_fc_engine_is_program' ) ) {
	/** A statement FQS /query would drop: aggregations (freq, count, coll, …), dcoll, keyness, dist. */
	function tt_fc_engine_is_program( $text ) {
		foreach ( preg_split( '/\s*;\s*/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			$part = trim( $part );
			if ( ( function_exists( 'tt_flexicorp_teitok_query_is_aa_statement' ) && tt_flexicorp_teitok_query_is_aa_statement( $part ) )
				|| preg_match( '/^(dcoll|keyness|dist)\b/i', $part ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'tt_fc_engine_route' ) ) {
	/**
	 * fqs-run | fqs-query | local. FQS takes searches when it is eligible for the corpus;
	 * programs and frequencies go to /run, which needs pando behind FQS (a CQP override has
	 * no /run, so its searches use /query and its frequencies the local CLI).
	 */
	function tt_fc_engine_route( array $ctx, $run, $text = '' ) {
		$fqs = ! empty( $ctx['fqs_eligible'] );
		$pando = ( (string) ( $ctx['fqs_backend_override'] ?? '' ) ) === 'pando';
		if ( $run === 'freq' ) {
			return ( $fqs && $pando ) ? 'fqs-run' : 'local';
		}
		if ( $run === 'query' ) {
			if ( ! $fqs ) return 'local';
			return ( $pando && tt_fc_engine_is_program( $text ) ) ? 'fqs-run' : 'fqs-query';
		}
		return 'local';
	}
}

if ( ! function_exists( 'tt_fc_engine_field_value_query' ) ) {
	/** Field/value search mode as a query: `[field="value"]` (field defaults to lemma). */
	function tt_fc_engine_field_value_query( $field, $value, $escapeBackslash = false ) {
		$v = $escapeBackslash ? str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) : str_replace( '"', '\\"', (string) $value );
		$f = (string) $field;
		if ( $escapeBackslash ) {
			$f = preg_replace( '/[^A-Za-z0-9_:\\.-]/', '', $f );
			if ( $f === '' ) $f = 'lemma';
		}
		return '[' . $f . '="' . $v . '"]';
	}
}

if ( ! function_exists( 'tt_fc_engine_freq_program' ) ) {
	/**
	 * The program for a frequency request: the base query without its own aggregation tail,
	 * then `freq [names] by field` — the names of a `freq A, B by …` already in the query are
	 * kept, so named queries are compared. '' when there is no base query.
	 */
	function tt_fc_engine_freq_program( $baseQuery, $freqField, $kwicField = '', $kwicValue = '' ) {
		$base = trim( (string) $baseQuery );
		if ( $base === '' && $kwicValue !== '' ) {
			$base = tt_fc_engine_field_value_query( $kwicField, $kwicValue, true );
		}
		$names = function_exists( 'tt_flexicorp_teitok_parse_freq_query_names_from_query' )
			? tt_flexicorp_teitok_parse_freq_query_names_from_query( $base ) : '';
		if ( function_exists( 'tt_flexicorp_teitok_sanitize_query_aggregations' ) ) {
			$base = tt_flexicorp_teitok_sanitize_query_aggregations( $base );
		}
		$base = trim( (string) $base );
		if ( $base === '' ) return '';
		$prog = rtrim( $base );
		if ( substr( $prog, -1 ) !== ';' ) $prog .= ';';
		return $prog . ( $names !== '' ? ' freq ' . $names . ' by ' . $freqField . ';' : ' freq by ' . $freqField . ';' );
	}
}

if ( ! function_exists( 'tt_fc_engine_tag' ) ) {
	/** Record the route on the call and on its result (debug view, the client's planner). */
	function tt_fc_engine_tag( $call, $route ) {
		if ( ! is_array( $call ) ) return $call;
		$call['engine_route'] = $route;
		if ( isset( $call['data']['done']['result'] ) && is_array( $call['data']['done']['result'] ) ) {
			$call['data']['done']['result']['engine_route'] = $route;
		}
		return $call;
	}
}

if ( ! function_exists( 'tt_fc_engine_search' ) ) {
	/**
	 * A search (query text, or field/value mode when $query is empty). $opts: start, limit,
	 * window, context_scope, context_format, fragment (pando without XML).
	 */
	function tt_fc_engine_search( array $ctx, $query, $field, $value, array $opts ) {
		$start = (int) ( $opts['start'] ?? 0 );
		$limit = (int) ( $opts['limit'] ?? 25 );
		$window = (int) ( $opts['window'] ?? 0 );
		$contextScope = (string) ( $opts['context_scope'] ?? '' );
		$contextFormat = (string) ( $opts['context_format'] ?? '' );
		// KWIC token-window XML span (first..last displayed token byte range) is the only way to
		// get a real ±N-token context for dtok-bearing TEITOK corpora; without it the CQP backend
		// falls back to extract_teitok_fragment_xml(scope="window"), which has no <window> element
		// to anchor on and degrades to the parent <tok> of the matched dtok.
		$cposSpan = strtolower( trim( $contextFormat ) ) === 'xml'
			&& in_array( strtolower( trim( $contextScope ) ), array( 'window', 'tok' ), true );
		$pandoFqs = ( (string) ( $ctx['fqs_backend_override'] ?? '' ) ) === 'pando';

		if ( ! empty( $ctx['fqs_eligible'] ) ) {
			if ( $query !== '' ) {
				$text = $query;
				if ( $pandoFqs && function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) ) {
					$text = tt_flexicorp_teitok_query_effective_pando_search_query( $query );
				}
			} else {
				$text = tt_fc_engine_field_value_query( $field, $value );
			}
			// A program typed in the search box ("A = …; freq A by x;", coll, count, …) runs on
			// FQS /run: /query executes only the query statements and silently drops the rest.
			$route = tt_fc_engine_route( $ctx, 'query', $text );
			$call = tt_flexicorp_fqs_query_call(
				$ctx['fqs_probe'],
				$text,
				$ctx['query_language'],
				$start,
				$limit,
				$ctx['fqs_backend_override'],
				array(
					'program' => $route === 'fqs-run',
					'group_limit' => 1000,   // a typed freq/coll: the KWIC page size is not a group limit
					'window' => $window,
					'context_scope' => $contextScope,
					'context_format' => $contextFormat,
					'flexicorp_fragment_kwic_cpos_span' => $cposSpan,
					'fragment' => ! empty( $opts['fragment'] ),
					'project_root' => (string) $ctx['project_root'],
				)
			);
			return tt_fc_engine_tag( $call, $route );
		}

		$extraArgs = array_merge( $ctx['backend_override_args'], array(
			'--limit', escapeshellarg( (string) $limit ),
			'--window', escapeshellarg( (string) $window ),
			'--start', escapeshellarg( (string) $start ),
		) );
		if ( $contextFormat !== '' ) {
			$extraArgs[] = '--context-format';
			$extraArgs[] = escapeshellarg( $contextFormat );
		}
		if ( $contextScope !== '' ) {
			$extraArgs[] = '--context-scope';
			$extraArgs[] = escapeshellarg( $contextScope );
		}
		if ( $cposSpan ) {
			$extraArgs[] = '--flexicorp-fragment-kwic-cpos-span';
		}
		if ( $query === '' && ! empty( $ctx['pando_exec'] ) ) {
			// the local pando route reads no --field / --value: field/value mode as a query, as on FQS
			$query = tt_fc_engine_field_value_query( $field, $value );
		}
		if ( $query !== '' ) {
			$text = $query;
			if ( ! empty( $ctx['pando_exec'] ) && function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) ) {
				$text = tt_flexicorp_teitok_query_effective_pando_search_query( $query );
			}
			$call = tt_flexicorp_run( array( 'query', $text ), $ctx['backend'], $ctx['project_root'], $extraArgs, $ctx['query_engine'], $ctx['query_language'], $ctx['corpus_format'] );
		} else {
			if ( $field !== '' ) {
				$extraArgs[] = '--field';
				$extraArgs[] = escapeshellarg( $field );
			}
			$extraArgs[] = '--value';
			$extraArgs[] = escapeshellarg( $value );
			$call = tt_flexicorp_run( array( 'query' ), $ctx['backend'], $ctx['project_root'], $extraArgs, $ctx['query_engine'], $ctx['query_language'], $ctx['corpus_format'] );
		}
		return tt_fc_engine_tag( $call, 'local' );
	}
}

if ( ! function_exists( 'tt_fc_engine_freq' ) ) {
	/**
	 * A frequency request: on FQS /run the program from tt_fc_engine_freq_program() (named
	 * queries compared); locally the backend's freq command (`--field`, `--limit`).
	 */
	function tt_fc_engine_freq( array $ctx, $baseQuery, $freqField, $freqLimit, $kwicField = '', $kwicValue = '' ) {
		$route = tt_fc_engine_route( $ctx, 'freq' );
		if ( $route === 'fqs-run' ) {
			$prog = tt_fc_engine_freq_program( $baseQuery, $freqField, $kwicField, $kwicValue );
			if ( $prog === '' ) {
				return tt_fc_engine_tag( tt_flexicorp_error_call( 'fqs', 'freq', 'Empty query not allowed for frequency operation.' ), $route );
			}
			$call = tt_flexicorp_fqs_query_call( $ctx['fqs_probe'], $prog, $ctx['query_language'], 0, $freqLimit,
				$ctx['fqs_backend_override'], array( 'program' => true ) );
			return tt_fc_engine_tag( $call, $route );
		}
		$call = tt_flexicorp_run_freq(
			$ctx['backend'],
			$ctx['project_root'],
			array_merge( $ctx['backend_override_args'], array( '--field', escapeshellarg( $freqField ), '--limit', escapeshellarg( (string) $freqLimit ) ) ),
			$ctx['query_engine'],
			$ctx['query_language'],
			$ctx['corpus_format']
		);
		return tt_fc_engine_tag( $call, 'local' );
	}
}

if ( ! function_exists( 'tt_fc_engine_coll' ) ) {
	/** Collocations: the local engine (the window, measures, … come from the request). */
	function tt_fc_engine_coll( array $ctx ) {
		$call = tt_flexicorp_run_coll( $ctx['backend'], $ctx['project_root'], $ctx['backend_override_args'],
			$ctx['query_engine'], $ctx['query_language'], $ctx['corpus_format'] );
		return tt_fc_engine_tag( $call, 'local' );
	}
}
