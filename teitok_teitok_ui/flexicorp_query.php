<?php
/**
 * TEITOK library API for flexicorp queries (engine-agnostic facade).
 *
 * All operations delegate to flexicorp.php (tt_flexicorp_run / tt_flexicorp_run_freq).
 * No direct CQP, CWB file, or flexicorp-pando CLI calls in this file.
 *
 *   require_once __DIR__ . '/flexicorp_query.php';
 *   $vals = flexicorp_query_attribute_values( 'lemma', array( 'max' => 500 ) );
 *   $res  = flexicorp_query( '[lemma="book"]', array( 'engine' => 'auto', 'limit' => 20 ) );
 *   $hits = flexicorp_query_result( $res );
 *
 *   $grp  = flexicorp_query_group( '[lemma="x"]', 'pos', array( 'engine' => 'auto' ) );
 *   $rows = flexicorp_query_group_items( $grp );
 *
 *   $tab  = flexicorp_query_tabulate( '[lemma="x"]', 'match pos, match lemma', array( 'engine' => 'auto' ) );
 *   $rows = flexicorp_query_tabulate_rows( $tab );
 *
 *   $res  = flexicorp_query_program( '[lemma="x"]; tabulate Matches match pos, match lemma;', array( 'engine' => 'auto' ) );
 */

require_once __DIR__ . '/flexicorp_functions.php';

if ( ! function_exists( 'tt_flexicorp_done' ) ) {
	/**
	 * Normalize tt_flexicorp_run() / flexicorp_query() return shape to the inner "done" block.
	 *
	 * @param array<string,mixed>|null $call
	 * @return array<string,mixed>
	 */
	function tt_flexicorp_done( $call ) {
		if ( ! is_array( $call ) ) {
			return array();
		}
		if ( isset( $call['data']['done'] ) && is_array( $call['data']['done'] ) ) {
			return $call['data']['done'];
		}
		if ( isset( $call['done'] ) && is_array( $call['done'] ) ) {
			return $call['done'];
		}
		if ( isset( $call['data'] ) && is_array( $call['data'] ) && isset( $call['data']['result'] ) ) {
			return array(
				'backend'   => (string) ( $call['data']['backend'] ?? '' ),
				'operation' => (string) ( $call['data']['operation'] ?? '' ),
				'errors'    => isset( $call['data']['errors'] ) && is_array( $call['data']['errors'] ) ? $call['data']['errors'] : array(),
				'warnings'  => isset( $call['data']['warnings'] ) && is_array( $call['data']['warnings'] ) ? $call['data']['warnings'] : array(),
				'result'    => $call['data']['result'],
			);
		}
		return array();
	}
}

if ( ! function_exists( 'flexicorp_query_corpus_root' ) ) {
	/**
	 * TEITOK corpus directory, aligned with flexicorp.php.
	 */
	function flexicorp_query_corpus_root() {
		if ( function_exists( 'tt_flexicorp_corpus_project_root' ) ) {
			return tt_flexicorp_corpus_project_root();
		}
		global $foldername;
		$raw = '';
		if ( isset( $foldername ) && is_string( $foldername ) && trim( $foldername ) !== '' ) {
			$raw = trim( $foldername );
		} elseif ( function_exists( 'getset' ) ) {
			$raw = trim( (string) getset( 'defaults/base/foldername', '' ) );
		}
		if ( $raw === '' ) {
			$cwd = getcwd();
			return ( $cwd && is_dir( $cwd ) ) ? $cwd : '.';
		}
		$raw = str_replace( '\\', '/', $raw );
		if ( isset( $raw[0] ) && $raw[0] === '/' ) {
			$rp = @realpath( $raw );
			return ( $rp && is_dir( $rp ) ) ? $rp : $raw;
		}
		$cwd = getcwd();
		if ( ! $cwd ) {
			return '.';
		}
		$cwd = str_replace( '\\', '/', $cwd );
		if ( is_dir( $cwd ) && basename( $cwd ) === $raw ) {
			$rp = @realpath( $cwd );
			return ( $rp && is_dir( $rp ) ) ? $rp : $cwd;
		}
		$joined = rtrim( $cwd, '/' ) . '/' . $raw;
		$rp = @realpath( $joined );
		return ( $rp && is_dir( $rp ) ) ? $rp : $joined;
	}
}

if ( ! function_exists( 'flexicorp_query_load_engine_lib' ) ) {
	/**
	 * Load flexicorp.php engine helpers (tt_flexicorp_run, probes) without UI bootstrap.
	 */
	function flexicorp_query_load_engine_lib() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		if ( ! defined( 'TT_FLEXICORP_LIBRARY_ONLY' ) ) {
			define( 'TT_FLEXICORP_LIBRARY_ONLY', true );
		}
		$lib = __DIR__ . '/flexicorp.php';
		if ( is_file( $lib ) ) {
			require_once $lib;
		}
		$loaded = true;
	}
}

if ( ! function_exists( 'flexicorp_query_resolve_engine' ) ) {
	/**
	 * Pick flexicorp execution engine from options / project indices.
	 *
	 * @param string               $project_root Corpus root.
	 * @param array<string,mixed>  $options      engine?
	 * @return array{engine:string,reason:string}
	 */
	function flexicorp_query_resolve_engine( $project_root = '', array $options = array() ) {
		$root = $project_root !== '' ? rtrim( (string) $project_root, '/' ) : flexicorp_query_corpus_root();
		$explicit = isset( $options['engine'] ) ? strtolower( trim( (string) $options['engine'] ) ) : '';
		if ( $explicit === 'flexicorp-pando' ) {
			$explicit = 'pando';
		}
		if ( in_array( $explicit, array( 'cqp', 'pando', 'manatee', 'flexi', 'clickhouse', 'clickql' ), true ) ) {
			return array( 'engine' => $explicit, 'reason' => 'explicit' );
		}
		if ( function_exists( 'getset' ) ) {
			$pref = strtolower( trim( (string) getset( 'flexicorp/preferred_query_engine', '' ) ) );
			if ( $pref === 'flexicorp-pando' ) {
				$pref = 'pando';
			}
			if ( in_array( $pref, array( 'cqp', 'pando', 'manatee', 'flexi' ), true ) ) {
				return array( 'engine' => $pref, 'reason' => 'settings' );
			}
		}
		flexicorp_query_load_engine_lib();
		if ( function_exists( 'tt_flexicorp_pando_status' ) ) {
			$ps = tt_flexicorp_pando_status( $root );
			if ( ! empty( $ps['available'] ) ) {
				return array( 'engine' => 'pando', 'reason' => 'pando_index' );
			}
		} elseif ( is_dir( $root . '/pando' ) && is_file( $root . '/pando/corpus.info' ) ) {
			return array( 'engine' => 'pando', 'reason' => 'pando_index' );
		}
		$indexDir = function_exists( 'getset' ) ? trim( (string) getset( 'cqp/cqpfolder', 'cqp' ) ) : 'cqp';
		if ( $indexDir === '' ) {
			$indexDir = 'cqp';
		}
		if ( is_dir( $root . '/' . $indexDir ) && is_file( $root . '/' . $indexDir . '/word.corpus' ) ) {
			return array( 'engine' => 'cqp', 'reason' => 'indexed_corpus' );
		}
		if ( is_dir( $root . '/cqp' ) && is_file( $root . '/cqp/word.corpus' ) ) {
			return array( 'engine' => 'cqp', 'reason' => 'indexed_corpus' );
		}
		return array( 'engine' => 'cqp', 'reason' => 'default_fallback' );
	}
}

if ( ! function_exists( 'flexicorp_query_run_context' ) ) {
	/**
	 * @param array<string,mixed> $options
	 * @return array{root:string,engine:string,backend:string,query_language:string,corpus_format:string,engine_reason:string}
	 */
	function flexicorp_query_run_context( array $options = array() ) {
		$root     = isset( $options['project_root'] ) ? rtrim( (string) $options['project_root'], '/' ) : flexicorp_query_corpus_root();
		$resolved = flexicorp_query_resolve_engine( $root, $options );
		$engine   = (string) $resolved['engine'];
		$backend  = isset( $options['backend'] ) ? trim( (string) $options['backend'] ) : '';
		if ( $backend === '' ) {
			$backend = ( $engine === 'pando' ) ? 'pando' : ( ( $engine === 'manatee' ) ? 'manatee' : 'cqp' );
		}
		$queryLanguage = isset( $options['query_language'] ) ? trim( (string) $options['query_language'] ) : '';
		$corpusFormat  = isset( $options['corpus_format'] ) ? trim( (string) $options['corpus_format'] ) : '';
		if ( $queryLanguage === '' ) {
			if ( $engine === 'pando' ) {
				$queryLanguage = 'pando-cql';
			} elseif ( $engine === 'manatee' ) {
				$queryLanguage = 'manatee-cql';
			} else {
				$queryLanguage = 'cwb-cql';
			}
		}
		if ( $corpusFormat === '' ) {
			$corpusFormat = ( $engine === 'pando' ) ? 'pando' : ( ( $engine === 'manatee' ) ? 'manatee' : 'cwb' );
		}
		return array(
			'root'           => $root,
			'engine'         => $engine,
			'backend'        => $backend,
			'query_language' => $queryLanguage,
			'corpus_format'  => $corpusFormat,
			'engine_reason'  => (string) ( $resolved['reason'] ?? '' ),
		);
	}
}

if ( ! function_exists( 'flexicorp_query_fail' ) ) {
	/**
	 * @return array<string,mixed>
	 */
	function flexicorp_query_fail( $message ) {
		return array(
			'ok'      => false,
			'command' => '',
			'raw'     => '',
			'data'    => null,
			'error'   => (string) $message,
		);
	}
}

if ( ! function_exists( 'flexicorp_query_normalize_scope_assignment' ) ) {
	/**
	 * Turn a scope into an assignment statement (default set name Matches).
	 */
	function flexicorp_query_normalize_scope_assignment( $scope, $set_name = 'Matches' ) {
		$scope = trim( (string) $scope );
		$set   = trim( (string) $set_name );
		if ( $set === '' ) {
			$set = 'Matches';
		}
		if ( $scope === '' ) {
			return '';
		}
		if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $scope ) ) {
			return rtrim( $scope, " \t\n\r\0\x0B;" ) . ';';
		}
		return $set . ' = ' . rtrim( $scope, " \t\n\r\0\x0B;" ) . ';';
	}
}

if ( ! function_exists( 'flexicorp_query_format_tabulate_columns' ) ) {
	/**
	 * @param string|array<int,string> $columns
	 */
	function flexicorp_query_format_tabulate_columns( $columns ) {
		if ( is_array( $columns ) ) {
			$parts = array();
			foreach ( $columns as $col ) {
				$c = trim( (string) $col );
				if ( $c === '' ) {
					continue;
				}
				if ( ! preg_match( '/^match\b/i', $c ) && ! preg_match( '/^(matchend|match\[)/i', $c ) ) {
					$c = 'match ' . $c;
				}
				$parts[] = $c;
			}
			return implode( ', ', $parts );
		}
		return trim( (string) $columns );
	}
}

if ( ! function_exists( 'flexicorp_query_build_program' ) ) {
	/**
	 * Join scope assignment and trailing statement(s) into one program string.
	 *
	 * @param string               $scope
	 * @param string               $tail     e.g. "freq by pos" or "tabulate Matches match pos"
	 * @param array<string,mixed>  $options  set?
	 */
	function flexicorp_query_build_program( $scope, $tail, array $options = array() ) {
		$set = isset( $options['set'] ) ? trim( (string) $options['set'] ) : 'Matches';
		if ( $set === '' ) {
			$set = 'Matches';
		}
		$assign = flexicorp_query_normalize_scope_assignment( $scope, $set );
		$tail   = trim( (string) $tail );
		if ( $assign === '' ) {
			return $tail !== '' ? rtrim( $tail, " \t\n\r\0\x0B;" ) . ';' : '';
		}
		if ( $tail === '' ) {
			return $assign;
		}
		return $assign . ' ' . rtrim( $tail, " \t\n\r\0\x0B;" ) . ';';
	}
}

if ( ! function_exists( 'flexicorp_query' ) ) {
	/**
	 * Run a corpus query program (search/KWIC or multi-statement script). Returns tt_flexicorp_run() envelope.
	 *
	 * Options: engine, backend, query_language, corpus_format, limit, offset, window,
	 * context_scope, context_format, project_root, extra_args.
	 *
	 * @param string              $query
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	function flexicorp_query( $query, array $options = array() ) {
		$query = trim( (string) $query );
		if ( $query === '' ) {
			return flexicorp_query_fail( 'empty query' );
		}
		flexicorp_query_load_engine_lib();
		if ( ! function_exists( 'tt_flexicorp_run' ) ) {
			return flexicorp_query_fail( 'flexicorp.php engine helpers not available' );
		}
		$ctx   = flexicorp_query_run_context( $options );
		$extra = isset( $options['extra_args'] ) && is_array( $options['extra_args'] ) ? $options['extra_args'] : array();
		$limit  = isset( $options['limit'] ) ? max( 1, (int) $options['limit'] ) : 50;
		$offset = isset( $options['offset'] ) ? max( 0, (int) $options['offset'] ) : 0;
		$window = isset( $options['window'] ) ? max( 0, (int) $options['window'] ) : 5;
		$extra[] = '--limit';
		$extra[] = escapeshellarg( (string) $limit );
		$extra[] = '--start';
		$extra[] = escapeshellarg( (string) $offset );
		$extra[] = '--window';
		$extra[] = escapeshellarg( (string) $window );
		if ( ! empty( $options['context_format'] ) ) {
			$extra[] = '--context-format';
			$extra[] = escapeshellarg( (string) $options['context_format'] );
		}
		if ( ! empty( $options['context_scope'] ) ) {
			$extra[] = '--context-scope';
			$extra[] = escapeshellarg( (string) $options['context_scope'] );
		}
		$runQuery = $query;
		if ( function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) ) {
			$eff = tt_flexicorp_teitok_query_effective_pando_search_query( $query );
			if ( $eff !== '' ) {
				$runQuery = $eff;
			}
		}
		return tt_flexicorp_run(
			array( 'query', $runQuery ),
			$ctx['backend'],
			$ctx['root'],
			$extra,
			$ctx['engine'],
			$ctx['query_language'],
			$ctx['corpus_format']
		);
	}
}

if ( ! function_exists( 'flexicorp_query_freq' ) ) {
	/**
	 * Scoped frequency / grouping (flexicorp `freq` operation).
	 *
	 * @param string              $scope_query
	 * @param string              $field
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	function flexicorp_query_freq( $scope_query, $field, array $options = array() ) {
		$scope_query = trim( (string) $scope_query );
		$field       = trim( (string) $field );
		if ( $scope_query === '' ) {
			return flexicorp_query_fail( 'empty scope query' );
		}
		if ( $field === '' ) {
			return flexicorp_query_fail( 'empty field' );
		}
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_:-]*$/', $field ) ) {
			return flexicorp_query_fail( 'invalid field name' );
		}
		flexicorp_query_load_engine_lib();
		if ( ! function_exists( 'tt_flexicorp_run_freq' ) && ! function_exists( 'tt_flexicorp_run' ) ) {
			return flexicorp_query_fail( 'flexicorp.php engine helpers not available' );
		}
		$ctx    = flexicorp_query_run_context( $options );
		$limit  = isset( $options['limit'] ) ? max( 1, (int) $options['limit'] ) : 1000;
		$offset = isset( $options['offset'] ) ? max( 0, (int) $options['offset'] ) : 0;
		$extra  = isset( $options['extra_args'] ) && is_array( $options['extra_args'] ) ? $options['extra_args'] : array();
		$extra[] = '--query';
		$extra[] = escapeshellarg( $scope_query );
		$extra[] = '--field';
		$extra[] = escapeshellarg( $field );
		$extra[] = '--limit';
		$extra[] = escapeshellarg( (string) $limit );
		$extra[] = '--offset';
		$extra[] = escapeshellarg( (string) $offset );

		if ( ! function_exists( 'tt_flexicorp_run_freq' ) ) {
			$program = flexicorp_query_build_program( $scope_query, 'freq by ' . $field, $options );
			return flexicorp_query( $program, $options );
		}
		$savedRequest = null;
		if ( $ctx['backend'] === 'pando' || $ctx['backend'] === 'flexicorp-pando' ) {
			$savedRequest = $_REQUEST;
			$_REQUEST     = array_merge(
				is_array( $savedRequest ) ? $savedRequest : array(),
				array(
					'query'      => $scope_query,
					'freq_field' => $field,
					'start'      => (string) $offset,
					'kwic_limit' => (string) $limit,
				)
			);
		}
		$call = tt_flexicorp_run_freq(
			$ctx['backend'],
			$ctx['root'],
			$extra,
			$ctx['engine'],
			$ctx['query_language'],
			$ctx['corpus_format']
		);
		if ( $savedRequest !== null ) {
			$_REQUEST = $savedRequest;
		}
		return $call;
	}
}

if ( ! function_exists( 'flexicorp_query_group' ) ) {
	/** Alias for flexicorp_query_freq() (CQP “group … match …” style counts). */
	function flexicorp_query_group( $scope_query, $field, array $options = array() ) {
		return flexicorp_query_freq( $scope_query, $field, $options );
	}
}

if ( ! function_exists( 'flexicorp_query_freq_items' ) ) {
	/**
	 * @param array<string,mixed>|null $call
	 * @return array<int,array<string,mixed>>
	 */
	function flexicorp_query_freq_items( $call ) {
		$result = flexicorp_query_result( $call );
		if ( ! is_array( $result ) ) {
			return array();
		}
		if ( function_exists( 'tt_flexicorp_freq_rows' ) ) {
			return tt_flexicorp_freq_rows( $result );
		}
		$rows = array();
		if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
			$rows = $result['items'];
		} elseif ( isset( $result['rows'] ) && is_array( $result['rows'] ) ) {
			$rows = $result['rows'];
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$value = isset( $row['value'] ) ? trim( (string) $row['value'] ) : '';
			if ( $value === '' && isset( $row['label'] ) ) {
				$value = trim( (string) $row['label'] );
			}
			$count = null;
			if ( isset( $row['count'] ) && is_numeric( $row['count'] ) ) {
				$count = (int) $row['count'];
			} elseif ( isset( $row['freq'] ) && is_numeric( $row['freq'] ) ) {
				$count = (int) $row['freq'];
			} elseif ( isset( $row['n'] ) && is_numeric( $row['n'] ) ) {
				$count = (int) $row['n'];
			}
			if ( $value === '' || $count === null ) {
				continue;
			}
			$out[] = array( 'value' => $value, 'count' => $count );
		}
		return $out;
	}
}

if ( ! function_exists( 'flexicorp_query_group_items' ) ) {
	function flexicorp_query_group_items( $call ) {
		return flexicorp_query_freq_items( $call );
	}
}

if ( ! function_exists( 'flexicorp_query_freq_map' ) ) {
	/**
	 * @param array<string,mixed>|null $call
	 * @return array<string,int>
	 */
	function flexicorp_query_freq_map( $call ) {
		$map = array();
		foreach ( flexicorp_query_freq_items( $call ) as $row ) {
			$v = isset( $row['value'] ) ? trim( (string) $row['value'] ) : '';
			if ( $v === '' || ! isset( $row['count'] ) ) {
				continue;
			}
			$map[ $v ] = (int) $row['count'];
		}
		return $map;
	}
}

if ( ! function_exists( 'flexicorp_query_group_map' ) ) {
	function flexicorp_query_group_map( $call ) {
		return flexicorp_query_freq_map( $call );
	}
}

if ( ! function_exists( 'flexicorp_query_tabulate' ) ) {
	/**
	 * Tabulate rows for hits in $scope_query (program tail: tabulate …).
	 *
	 * @param string|array<int,string> $columns
	 * @param array<string,mixed>      $options
	 * @return array<string,mixed>
	 */
	function flexicorp_query_tabulate( $scope_query, $columns, array $options = array() ) {
		$scope_query = trim( (string) $scope_query );
		$colsExpr    = flexicorp_query_format_tabulate_columns( $columns );
		if ( $scope_query === '' ) {
			return flexicorp_query_fail( 'empty scope query' );
		}
		if ( $colsExpr === '' ) {
			return flexicorp_query_fail( 'empty tabulate columns' );
		}
		$set = isset( $options['set'] ) ? trim( (string) $options['set'] ) : 'Matches';
		if ( $set === '' ) {
			$set = 'Matches';
		}
		$program = flexicorp_query_build_program(
			$scope_query,
			'tabulate ' . $set . ' ' . $colsExpr,
			array_merge( $options, array( 'set' => $set ) )
		);
		return flexicorp_query(
			$program,
			array_merge(
				$options,
				array(
					'limit'  => isset( $options['limit'] ) ? (int) $options['limit'] : 1000,
					'offset' => isset( $options['offset'] ) ? (int) $options['offset'] : 0,
				)
			)
		);
	}
}

if ( ! function_exists( 'flexicorp_query_tabulate_fields' ) ) {
	/**
	 * Column names from a tabulate result (Pando: result.fields).
	 *
	 * @param array<string,mixed>|null $call
	 * @return array<int,string>
	 */
	function flexicorp_query_tabulate_fields( $call ) {
		$result = flexicorp_query_result( $call );
		if ( ! is_array( $result ) || ! isset( $result['fields'] ) || ! is_array( $result['fields'] ) ) {
			return array();
		}
		$fields = array();
		foreach ( $result['fields'] as $f ) {
			$f = trim( (string) $f );
			if ( $f !== '' ) {
				$fields[] = $f;
			}
		}
		return $fields;
	}
}

if ( ! function_exists( 'flexicorp_query_tabulate_row_assoc' ) ) {
	/**
	 * Zip one tabulate row (list or map) with field names.
	 *
	 * @param array<int|string,mixed> $row
	 * @param array<int,string>       $fields
	 * @return array<string,mixed>
	 */
	function flexicorp_query_tabulate_row_assoc( array $row, array $fields ) {
		if ( $fields === array() ) {
			return $row;
		}
		$isList = function_exists( 'array_is_list' ) ? array_is_list( $row ) : ( array_keys( $row ) === range( 0, count( $row ) - 1 ) );
		if ( ! $isList ) {
			return $row;
		}
		$out = array();
		foreach ( $fields as $i => $name ) {
			$out[ (string) $name ] = array_key_exists( $i, $row ) ? $row[ $i ] : '';
		}
		return $out;
	}
}

if ( ! function_exists( 'flexicorp_query_tabulate_rows' ) ) {
	/**
	 * Tabulate rows as associative arrays keyed by column name.
	 *
	 * Pando native tabulate JSON uses parallel arrays:
	 *   "fields": ["a.aid", "a.title"],
	 *   "rows": [["…", "…"], …]
	 * This helper zips them to ["a.aid" => …, "a.title" => …] per row.
	 *
	 * @param array<string,mixed>|null $call
	 * @return array<int,array<string,mixed>>
	 */
	function flexicorp_query_tabulate_rows( $call ) {
		$result = flexicorp_query_result( $call );
		if ( ! is_array( $result ) ) {
			return array();
		}
		$fields = flexicorp_query_tabulate_fields( $call );
		if ( isset( $result['rows'] ) && is_array( $result['rows'] ) && $fields !== array() ) {
			$out = array();
			foreach ( $result['rows'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$out[] = flexicorp_query_tabulate_row_assoc( $row, $fields );
			}
			return $out;
		}
		if ( isset( $result['rows'] ) && is_array( $result['rows'] ) ) {
			return $result['rows'];
		}
		if ( isset( $result['hits'] ) && is_array( $result['hits'] ) ) {
			return $result['hits'];
		}
		if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
			return $result['items'];
		}
		return array();
	}
}

if ( ! function_exists( 'flexicorp_query_program' ) ) {
	/**
	 * Run a multi-statement query program; dispatches freq/group/tabulate tails.
	 *
	 * @param string              $program
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	function flexicorp_query_program( $program, array $options = array() ) {
		$program = trim( (string) $program );
		if ( $program === '' ) {
			return flexicorp_query_fail( 'empty program' );
		}
		flexicorp_query_load_engine_lib();

		$aa = array();
		if ( function_exists( 'tt_flexicorp_teitok_query_aa_statements' ) ) {
			$aa = tt_flexicorp_teitok_query_aa_statements( $program );
		} elseif ( function_exists( 'tt_flexicorp_fn_is_aa_clause' ) ) {
			$parts = preg_split( '/\s*;\s*/', $program, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $parts ) ) {
				foreach ( $parts as $p ) {
					$p = trim( (string) $p );
					if ( $p !== '' && tt_flexicorp_fn_is_aa_clause( $p ) ) {
						$aa[] = $p;
					}
				}
			}
		}

		if ( count( $aa ) === 0 ) {
			return flexicorp_query( $program, $options );
		}

		$tail = $aa[ count( $aa ) - 1 ];
		$base = $program;
		if ( function_exists( 'tt_flexicorp_teitok_sanitize_query_aggregations' ) ) {
			$base = tt_flexicorp_teitok_sanitize_query_aggregations( $program );
		}
		$base = trim( (string) $base );

		if ( preg_match( '/^(?:freq|count|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i', $tail ) ) {
			$field = '';
			if ( function_exists( 'tt_flexicorp_teitok_parse_freq_field_from_query' ) ) {
				$field = trim( (string) tt_flexicorp_teitok_parse_freq_field_from_query( $program ) );
			}
			if ( $field === '' ) {
				return flexicorp_query_fail( 'could not parse freq/group field from program' );
			}
			if ( $base === '' ) {
				return flexicorp_query_fail( 'freq/group requires a scope query before the aggregation clause' );
			}
			return flexicorp_query_freq( $base, $field, $options );
		}

		if ( preg_match( '/^tabulate\s+/i', $tail ) ) {
			if ( ! preg_match( '/^tabulate\s+([A-Za-z_][A-Za-z0-9_]*)\s+(.+)$/i', $tail, $m ) ) {
				return flexicorp_query_fail( 'invalid tabulate clause (expected: tabulate SetName match col, …)' );
			}
			if ( $base === '' ) {
				return flexicorp_query_fail( 'tabulate requires a scope query before the tabulate clause' );
			}
			return flexicorp_query_tabulate(
				$base,
				trim( (string) $m[2] ),
				array_merge( $options, array( 'set' => (string) $m[1] ) )
			);
		}

		return flexicorp_query( $program, $options );
	}
}

if ( ! function_exists( 'flexicorp_query_attribute_values' ) ) {
	/**
	 * Distinct values for a corpus attribute via flexicorp freq (all tokens).
	 *
	 * @param string              $field
	 * @param array<string,mixed> $options engine, max, project_root, …
	 * @return array{ok:bool,values:array<int,string>,engine:string,error:string}
	 */
	function flexicorp_query_attribute_values( $field, array $options = array() ) {
		$field = trim( (string) $field );
		if ( $field === '' ) {
			return array(
				'ok'     => false,
				'values' => array(),
				'engine' => '',
				'error'  => 'empty field',
			);
		}
		$max = isset( $options['max'] ) ? max( 1, (int) $options['max'] ) : 1000;
		$ctx = flexicorp_query_run_context( $options );
		$call = flexicorp_query_freq(
			'[]',
			$field,
			array_merge(
				$options,
				array(
					'limit'  => $max,
					'offset' => 0,
				)
			)
		);
		if ( empty( $call['ok'] ) ) {
			$err = trim( (string) ( $call['error'] ?? '' ) );
			$done = tt_flexicorp_done( $call );
			if ( $err === '' && ! empty( $done['errors'][0] ) ) {
				$err = trim( (string) $done['errors'][0] );
			}
			return array(
				'ok'     => false,
				'values' => array(),
				'engine' => $ctx['engine'],
				'error'  => $err !== '' ? $err : 'freq failed',
			);
		}
		$values = array();
		foreach ( flexicorp_query_freq_items( $call ) as $row ) {
			$v = isset( $row['value'] ) ? trim( (string) $row['value'] ) : '';
			if ( $v !== '' && $v !== '_' ) {
				$values[] = $v;
			}
		}
		natcasesort( $values );
		return array(
			'ok'     => true,
			'values' => array_values( $values ),
			'engine' => $ctx['engine'],
			'error'  => '',
		);
	}
}

if ( ! function_exists( 'flexicorp_query_result' ) ) {
	/**
	 * @param array<string,mixed>|null $call
	 * @return array<string,mixed>|null
	 */
	function flexicorp_query_result( $call ) {
		$done = tt_flexicorp_done( $call );
		return ( isset( $done['result'] ) && is_array( $done['result'] ) ) ? $done['result'] : null;
	}
}
