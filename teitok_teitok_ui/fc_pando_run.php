<?php
/**
 * A pando program for a project, wherever its index is: through FQS (POST /run) when FQS serves the
 * project's corpus — on a set-up where the indexes live with FQS on another machine (LINDAT), that is
 * the only way — else with the pando program on the project's own index (fc_teitok_env.php). For
 * lenses that count with fixed, server-side programs (typology.php): the program is not the
 * visitor's, so the call to FQS is signed as admin (no visitor limits on a whole-corpus count); the
 * result is cached by the caller.
 *
 *   tt_fc_pando_run( $root, $program[, $timeout, $role] ) → pando's JSON answer (array) or an error message
 *   tt_fc_pando_route( $root )         → array( 'via' => 'fqs' | 'local' | '', 'why' => …, … )
 *   tt_fc_pando_regions( $root )       → the index's region names (s, p, doc, …)
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/fqs-lib.php';
require_once __DIR__ . '/fc_shortcache.php';

if ( ! function_exists( 'tt_fc_pando_route' ) ) {
	/** The FQS this TEITOK talks to (fc_fqs_conn.php). */
	function tt_fc_fqs_base_url() {
		return tt_fc_fqs_url();
	}

	/** The project's corpus id at FQS: flexicorp/fqs_corpus_id, else from the project folder. */
	function tt_fc_fqs_corpus_id( $root ) {
		$set = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_corpus_id', '' ) ) : '';
		return $set !== '' ? $set : tt_fqs_corpus_id_from_root( $root );
	}

	/** JSON over HTTP: array( 'status' => code (0: no answer), 'data' => decoded body or null ). */
	function tt_fc_http_json( $method, $url, $body, array $headers, $timeout ) {
		$headers = array_merge( array( 'Accept: application/json' ), $body !== null ? array( 'Content-Type: application/json' ) : array(), $headers );
		$payload = $body !== null ? json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array( CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
				CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => (int) ceil( $timeout ) ) );
			if ( $payload !== null ) curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
			$raw = curl_exec( $ch );
			$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		} else {
			$ctx = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => implode( "\r\n", $headers ),
				'content' => (string) $payload, 'timeout' => $timeout, 'ignore_errors' => true ) ) );
			$raw = @file_get_contents( $url, false, $ctx );
			$status = 0;
			// PHP 8.4+: http_get_last_response_headers(); before: the local $http_response_header
			$hdrs = function_exists( 'http_get_last_response_headers' ) ? ( http_get_last_response_headers() ?? array() ) : ( $http_response_header ?? array() );
			foreach ( $hdrs as $h ) if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $h, $m ) ) $status = (int) $m[1];
		}
		return array( 'status' => $raw === false ? 0 : $status, 'data' => is_string( $raw ) ? json_decode( $raw, true ) : null );
	}

	/**
	 * Where the project's programs run: FQS when it answers and has the corpus, else the local pando
	 * on the project's index, else nowhere ('why' says what is missing). Asked once per request, and kept
	 * between requests for a short time (fc_shortcache.php).
	 */
	function tt_fc_pando_route( $root ) {
		static $memo = array();
		$key = rtrim( (string) $root, '/' );
		if ( isset( $memo[ $key ] ) ) return $memo[ $key ];
		// kept between requests (fc_shortcache.php), when there is a route
		$kept = tt_fc_cache_get( $root, 'pando-route', tt_fc_cache_ttl( 30 ) );
		// (a route kept by an older version has no language list: ask again)
		if ( is_array( $kept ) && ! empty( $kept['via'] ) && ( $kept['via'] !== 'fqs' || array_key_exists( 'languages', $kept ) ) ) return $memo[ $key ] = $kept;
		$memo[ $key ] = tt_fc_pando_route_uncached( $root );
		if ( $memo[ $key ]['via'] !== '' ) tt_fc_cache_put( $root, 'pando-route', $memo[ $key ] );
		return $memo[ $key ];
	}

	/** tt_fc_pando_route, asked now. */
	function tt_fc_pando_route_uncached( $root ) {
		$url = tt_fc_fqs_base_url();
		$id = tt_fc_fqs_corpus_id( $root );
		$r = tt_fc_http_json( 'GET', $url . '/corpora?include_noncurrent=true&q=' . rawurlencode( $id ), null, tt_fqs_bearer_headers( 'admin', 'teitok-lens', 60 ), 5 );
		foreach ( (array) ( $r['data']['corpora'] ?? array() ) as $c ) {
			if ( (string) ( $c['id'] ?? '' ) !== $id ) continue;
			// the query languages FQS accepts for this corpus (by engine and annotation); null from an
			// FQS that does not list them
			$langs = isset( $c['query_languages'] ) && is_array( $c['query_languages'] ) ? array_values( array_map( 'strval', $c['query_languages'] ) ) : null;
			return array( 'via' => 'fqs', 'url' => $url, 'corpus' => $id, 'why' => '', 'languages' => $langs );
		}
		$index = rtrim( tt_fc_env_pando_index_dir( $root ), '/' );
		$pando = tt_fc_env_pando_bin( 'pando' );
		if ( $pando !== '' && is_file( "$index/corpus.info" ) ) return array( 'via' => 'local', 'index' => $index, 'why' => '' );
		$fqsWhy = $r['status'] === 0 ? "no FQS answers at $url" : "FQS at $url has no corpus '$id' (flexicorp/fqs_corpus_id names it)";
		$localWhy = $pando === '' ? 'pando is not installed here' : "no pando index at $index (<pando path=\"…\"/> names it)";
		return array( 'via' => '', 'why' => "$fqsWhy, and $localWhy" );
	}

	/**
	 * The query languages pando can be asked in for this corpus: through FQS, the ones FQS lists for
	 * it (`query_languages` in /corpora; PML-TQ and TIGER only for a corpus labelled for them, see
	 * FQS's README); from an FQS without that list, pando CQL and CWB CQL. Without FQS (the local
	 * flexicorp-pando): pando CQL, plus CWB CQL and Manatee CQL when the adapter takes `--cql`;
	 * PML-TQ and TIGER need FQS's labels.
	 */
	function tt_fc_pando_languages( $root ) {
		$route = tt_fc_pando_route( $root );
		if ( ( $route['via'] ?? '' ) === 'local' ) {
			return tt_fc_pando_adapter_has_cql( $root ) ? array( 'pando-cql', 'cwb-cql', 'manatee-cql' ) : array( 'pando-cql' );
		}
		if ( ( $route['via'] ?? '' ) !== 'fqs' ) return array( 'pando-cql' );
		if ( is_array( $route['languages'] ?? null ) && $route['languages'] ) return $route['languages'];
		return array( 'pando-cql', 'cwb-cql' );
	}

	/** Does the installed flexicorp-pando take `--cql` (adapter API 6)? Asked once per binary. */
	function tt_fc_pando_adapter_has_cql( $root ) {
		static $memo = array();
		$bin = function_exists( 'tt_fc_bin' ) ? tt_fc_bin( 'flexicorp-pando' ) : '';
		if ( $bin === '' || ! is_file( $bin ) ) return false;
		$key = 'fcp-cql-' . substr( md5( $bin . '|' . (string) @filemtime( $bin ) ), 0, 12 );
		if ( isset( $memo[ $key ] ) ) return $memo[ $key ];
		$kept = tt_fc_cache_get( $root, $key, 86400 );
		if ( is_array( $kept ) ) return $memo[ $key ] = ! empty( $kept['cql'] );
		$help = (string) @shell_exec( escapeshellarg( $bin ) . ' --help 2>&1' );
		$has = strpos( $help, '--cql' ) !== false;
		tt_fc_cache_put( $root, $key, array( 'cql' => $has ) );
		return $memo[ $key ] = $has;
	}

	/** FQS's /info for the corpus (its 'result'), once per request and kept between requests; array() without. */
	function tt_fc_fqs_info( $root, $route ) {
		static $memo = array();
		$key = rtrim( (string) $root, '/' );
		if ( isset( $memo[ $key ] ) ) return $memo[ $key ];
		$kept = tt_fc_cache_get( $root, 'fqs-info', tt_fc_cache_ttl( 30 ) );
		if ( is_array( $kept ) ) return $memo[ $key ] = $kept;
		$r = tt_fc_http_json( 'GET', $route['url'] . '/info?corpus=' . rawurlencode( $route['corpus'] ), null, tt_fqs_bearer_headers( 'admin', 'teitok-lens', 60 ), 10 );
		$info = is_array( $r['data']['result'] ?? null ) ? $r['data']['result'] : array();
		if ( $info ) tt_fc_cache_put( $root, 'fqs-info', $info );
		return $memo[ $key ] = $info;
	}

	/**
	 * The corpus's region attributes, wherever its index is: array( 'text_lang' => number of values or
	 * null (not known: a local index), … ), in the index's order; array() when there is no index.
	 */
	function tt_fc_pando_region_attrs( $root ) {
		static $memo = array();
		$key = rtrim( (string) $root, '/' );
		if ( isset( $memo[ $key ] ) ) return $memo[ $key ];
		$route = tt_fc_pando_route( $root );
		$out = array();
		if ( $route['via'] === 'fqs' ) {
			$info = tt_fc_fqs_info( $root, $route );
			foreach ( (array) ( $info['structures'] ?? array() ) as $st ) {
				foreach ( (array) ( $st['region_attrs'] ?? array() ) as $ra ) {
					if ( is_array( $ra ) && isset( $ra['name'] ) ) $out[ $st['name'] . '_' . $ra['name'] ] = isset( $ra['vocab'] ) ? (int) $ra['vocab'] : null;
				}
			}
			if ( ! $out ) foreach ( (array) ( $info['region_attrs'] ?? array() ) as $ra ) if ( is_string( $ra ) ) $out[ $ra ] = null;
		} elseif ( $route['via'] === 'local' ) {
			foreach ( @file( $route['index'] . '/corpus.info', FILE_IGNORE_NEW_LINES ) ?: array() as $line ) {
				if ( preg_match( '/^region_attrs=(.*)$/', $line, $m ) ) foreach ( explode( ',', $m[1] ) as $ra ) if ( trim( $ra ) !== '' ) $out[ trim( $ra ) ] = null;
			}
		}
		return $memo[ $key ] = $out;
	}

	/**
	 * The corpus's region names (s, p, doc, …), wherever its index is, in the index's order; array()
	 * when there is no index. For a project without xidx (no TEITOK XML files), whose sentence etc.
	 * can only be known from the index.
	 */
	function tt_fc_pando_regions( $root ) {
		static $memo = array();
		$key = rtrim( (string) $root, '/' );
		if ( isset( $memo[ $key ] ) ) return $memo[ $key ];
		$route = tt_fc_pando_route( $root );
		$out = array();
		if ( $route['via'] === 'fqs' ) {
			foreach ( (array) ( tt_fc_fqs_info( $root, $route )['structures'] ?? array() ) as $st ) {
				if ( is_array( $st ) && isset( $st['name'] ) && (string) $st['name'] !== '' ) $out[] = (string) $st['name'];
			}
		} elseif ( $route['via'] === 'local' ) {
			foreach ( @file( $route['index'] . '/corpus.info', FILE_IGNORE_NEW_LINES ) ?: array() as $line ) {
				if ( preg_match( '/^structural=(.*)$/', $line, $m ) ) foreach ( explode( ',', $m[1] ) as $s ) if ( trim( $s ) !== '' ) $out[] = trim( $s );
			}
		}
		return $memo[ $key ] = array_values( array_unique( $out ) );
	}

	/**
	 * Run a pando program for the project: its JSON answer, or an error message. $role: whose limits
	 * FQS applies (admin for fixed, server-side programs; the user's role for queries a user wrote).
	 */
	function tt_fc_pando_run( $root, $program, $timeout = 600, $role = 'admin' ) {
		$route = tt_fc_pando_route( $root );
		if ( $route['via'] === 'fqs' ) {
			$r = tt_fc_http_json( 'POST', $route['url'] . '/run', array( 'corpus' => $route['corpus'], 'cql' => $program ),
				tt_fqs_bearer_headers( $role, 'teitok-lens', 120 ), $timeout );
			$j = $r['data'];
			if ( ! is_array( $j ) ) return 'FQS gave no answer (HTTP ' . $r['status'] . ')';
			if ( ( $j['ok'] ?? true ) === false || $r['status'] >= 400 ) {
				$e = $j['error'] ?? ( $j['message'] ?? 'error' );
				return 'pando (FQS): ' . ( is_array( $e ) ? ( $e['message'] ?? json_encode( $e ) ) : $e );
			}
			return $j;
		}
		if ( $route['via'] === 'local' ) {
			$cmd = escapeshellarg( tt_fc_env_pando_bin( 'pando' ) ) . ' --api --json ' . escapeshellarg( $route['index'] ) . ' ' . escapeshellarg( $program ) . ' 2>&1';
			$out = (string) shell_exec( $cmd );
			preg_match_all( '/^\{/m', $out, $starts, PREG_OFFSET_CAPTURE );
			if ( empty( $starts[0] ) ) return 'pando: ' . trim( substr( $out, 0, 300 ) );
			$j = json_decode( substr( $out, end( $starts[0] )[1] ), true );
			if ( ! is_array( $j ) ) return 'pando gave no answer';
			if ( isset( $j['ok'] ) && $j['ok'] === false ) {
				$e = $j['error'] ?? 'error';
				return 'pando: ' . ( is_array( $e ) ? ( $e['message'] ?? json_encode( $e ) ) : $e );
			}
			return $j;
		}
		return $route['why'];
	}
}
