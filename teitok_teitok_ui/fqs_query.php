<?php
/**
 * fqs_query.php — lean JSON endpoint: TEITOK login → one FQS call → JSON.
 *
 * A TEITOK action like any other (index.php?action=fqs_query): TEITOK starts up as
 * usual and so establishes $user / $username (nothing in TEITOK changes). This file
 * then does only what the search needs: it signs a short-lived token with the
 * caller's role (visitor / user / admin), forwards the request to FQS and returns
 * FQS's answer. No probe, no `fqs` subprocesses, no GUI: FQS itself enforces limits
 * by tier, admission, timeouts, corpus policy and hit-set sessions.
 *
 * Request (GET, form POST or a JSON body):
 *   op        query (default) | run | status | context
 *   query     CQL                              (op=query; op=run: `cql` or `query`)
 *   ql        query language: auto (default), pando-cql, cwb-cql, …
 *   start, size, window, context_scope, context_format, backend (pando|cqp)
 *   name, from                                  hit sets of this user's FQS session
 *   offset, limit, group_limit                  (op=run)
 *   job                                         (op=status: a background total — a page
 *                                               answers with result.job_id / result.job)
 *   pos, left, right, sentence                  (op=context)
 * The corpus is always this TEITOK project (never taken from the request), and the
 * role / user come from the login (never from the request).
 *
 * Response: {"ok", "op", "corpus", "result", "fqs_meta", "timing"} or
 * {"ok": false, "error", "status", …} with FQS's HTTP status (403 denied, 408 time
 * limit, 413 hit limit, 429 / 503 busy + Retry-After). `timing` (and the
 * Server-Timing header) splits the request: TEITOK start-up, FQS call, total.
 *
 * Configuration (TEITOK settings, else environment, else FQS's runtime file):
 *   flexicorp/fqs_url      | FQS_URL | fqs-http.json | http://127.0.0.1:8787
 *   flexicorp/fqs_secret   | FQS_SECRET | /etc/fqs/fqs.env   (HS256 token; FQS --jwt-secret)
 *   flexicorp/fqs_corpus_id                        (default: the project folder name)
 *   flexicorp/fqs_max_size                         (largest page; default 1000)
 */

	global $user, $username, $foldername, $settings;

	$ttfq_t0 = microtime( true );

	// ── helpers (prefixed ttfq_; nothing shared with flexicorp.php) ───────────────

	if ( ! function_exists( 'ttfq_setting' ) ) {
		function ttfq_setting( $key, $env = '' ) {
			$v = '';
			if ( function_exists( 'getset' ) ) {
				$v = trim( (string) getset( $key, '' ) );
			}
			if ( $v === '' && $env !== '' ) {
				$e = getenv( $env );
				if ( is_string( $e ) ) $v = trim( $e );
			}
			return $v;
		}
	}

	if ( ! function_exists( 'ttfq_fqs_url' ) ) {
		function ttfq_fqs_url() {
			$url = ttfq_setting( 'flexicorp/fqs_url', 'FQS_URL' );
			if ( $url === '' ) {
				// fqs-http.json (written by `fqs serve`): FQS_HTTP_JSON, beside FQS_DB_PATH, defaults
				$cands = array();
				$e = getenv( 'FQS_HTTP_JSON' );
				if ( is_string( $e ) && trim( $e ) !== '' ) $cands[] = trim( $e );
				$db = getenv( 'FQS_DB_PATH' );
				if ( is_string( $db ) && $db !== '' ) $cands[] = rtrim( dirname( $db ), '/' ) . '/fqs-http.json';
				$cands[] = '/usr/local/var/fqs/fqs-http.json';
				$cands[] = '/var/lib/fqs/fqs-http.json';
				foreach ( $cands as $c ) {
					if ( ! is_readable( $c ) ) continue;
					$j = json_decode( (string) @file_get_contents( $c ), true );
					if ( is_array( $j ) && ! empty( $j['url'] ) ) {
						$url = trim( (string) $j['url'] );
						break;
					}
				}
			}
			if ( $url === '' ) $url = 'http://127.0.0.1:8787';
			return rtrim( $url, '/' );
		}
	}

	if ( ! function_exists( 'ttfq_corpus_id' ) ) {
		/** The FQS corpus id of this TEITOK project (as flexicorp.php: the folder name, sanitised). */
		function ttfq_corpus_id() {
			$id = ttfq_setting( 'flexicorp/fqs_corpus_id' );
			if ( $id === '' ) {
				global $foldername;
				$root = ( isset( $foldername ) && is_string( $foldername ) && trim( $foldername ) !== '' )
					? trim( $foldername )
					: ( function_exists( 'getset' ) ? trim( (string) getset( 'defaults/base/foldername', '' ) ) : '' );
				if ( $root === '' ) $root = (string) getcwd();
				$id = basename( rtrim( str_replace( '\\', '/', $root ), '/' ) );
			}
			$id = trim( (string) preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $id ), '_' );
			return $id !== '' ? $id : 'corpus';
		}
	}

	if ( ! function_exists( 'ttfq_login' ) ) {
		/** [role, user] from TEITOK's login ($user / $username, as flexicorp.php reads them). */
		function ttfq_login() {
			$u = '';
			if ( isset( $GLOBALS['username'] ) && is_string( $GLOBALS['username'] ) ) $u = trim( $GLOBALS['username'] );
			$usr = ( isset( $GLOBALS['user'] ) && is_array( $GLOBALS['user'] ) ) ? $GLOBALS['user'] : array();
			if ( $u === '' ) {
				foreach ( array( 'email', 'short', 'name' ) as $k ) {
					if ( isset( $usr[ $k ] ) && is_string( $usr[ $k ] ) && trim( $usr[ $k ] ) !== '' ) {
						$u = trim( $usr[ $k ] );
						break;
					}
				}
			}
			if ( isset( $usr['permissions'] ) && $usr['permissions'] === 'admin' ) return array( 'admin', $u );
			return array( $u !== '' ? 'user' : 'visitor', $u );
		}
	}

	if ( ! function_exists( 'tt_fqs_secret_from_env_file' ) ) {
		/**
		 * FQS_SECRET from FQS's own environment file (`/etc/fqs/fqs.env`, root:fqs 0640;
		 * the installer puts www-data in group fqs), so TEITOK needs no copy of the
		 * secret. Path: setting flexicorp/fqs_env_file or env FQS_ENV_FILE. '' when
		 * not readable (then: setting flexicorp/fqs_secret or env FQS_SECRET).
		 */
		function tt_fqs_secret_from_env_file() {
			static $memo = null;
			if ( $memo !== null ) return $memo;
			$path = '';
			if ( function_exists( 'getset' ) ) {
				$g = getset( 'flexicorp/fqs_env_file', '' );
				if ( is_scalar( $g ) ) $path = trim( (string) $g );
			}
			if ( $path === '' ) {
				$e = getenv( 'FQS_ENV_FILE' );
				$path = is_string( $e ) && trim( $e ) !== '' ? trim( $e ) : '/etc/fqs/fqs.env';
			}
			$memo = '';
			$txt = @is_readable( $path ) ? @file_get_contents( $path ) : false;
			// the last assignment wins, as for systemd's EnvironmentFile and `source`
			if ( is_string( $txt ) && preg_match_all( '/^\s*(?:export\s+)?FQS_SECRET\s*=\s*["\']?([^"\'\s#]+)/m', $txt, $m ) ) {
				$memo = (string) end( $m[1] );
			}
			return $memo;
		}
	}

	if ( ! function_exists( 'ttfq_b64url' ) ) {
		function ttfq_b64url( $s ) {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		}
	}

	if ( ! function_exists( 'ttfq_token' ) ) {
		/** HS256 token FQS verifies (--jwt-secret); '' without a secret. */
		function ttfq_token( $secret, $corpus, $role, $user ) {
			if ( $secret === '' ) return '';
			$now = time();
			$h = ttfq_b64url( '{"alg":"HS256","typ":"JWT"}' );
			$p = ttfq_b64url( json_encode( array(
				'iss' => 'teitok-flexicorp', 'sub' => $corpus, 'role' => $role, 'user' => $user,
				'iat' => $now, 'exp' => $now + 300,
			), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return $h . '.' . $p . '.' . ttfq_b64url( hash_hmac( 'sha256', $h . '.' . $p, $secret, true ) );
		}
	}

	if ( ! function_exists( 'ttfq_http' ) ) {
		/** One HTTP call to FQS → [status, body, retry_after, error]. */
		function ttfq_http( $method, $url, $body, $headers, $timeout ) {
			if ( function_exists( 'curl_init' ) ) {
				$ch = curl_init( $url );
				$retry = '';
				curl_setopt_array( $ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CUSTOMREQUEST => $method,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_CONNECTTIMEOUT_MS => 2000,
					CURLOPT_TIMEOUT_MS => (int) ( $timeout * 1000 ),
					CURLOPT_TCP_NODELAY => true,
					CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$retry ) {
						if ( stripos( $line, 'Retry-After:' ) === 0 ) $retry = trim( substr( $line, 12 ) );
						return strlen( $line );
					},
				) );
				if ( $body !== null ) curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
				$out = curl_exec( $ch );
				$err = $out === false ? (string) curl_error( $ch ) : '';
				$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
				curl_close( $ch );
				return array( $status, $out === false ? '' : (string) $out, $retry, $err );
			}
			$ctx = stream_context_create( array( 'http' => array(
				'method' => $method, 'header' => implode( "\r\n", $headers ),
				'content' => $body === null ? '' : $body, 'ignore_errors' => true, 'timeout' => $timeout,
			) ) );
			$out = @file_get_contents( $url, false, $ctx );
			$status = 0;
			$retry = '';
			foreach ( isset( $http_response_header ) ? $http_response_header : array() as $i => $line ) {
				if ( $i === 0 && preg_match( '/\s(\d{3})\s/', $line, $m ) ) $status = (int) $m[1];
				if ( stripos( $line, 'Retry-After:' ) === 0 ) $retry = trim( substr( $line, 12 ) );
			}
			return array( $status, $out === false ? '' : (string) $out, $retry, $out === false ? 'FQS unreachable' : '' );
		}
	}

	if ( ! function_exists( 'ttfq_highlight_map' ) ) {
		/** hits[].highlight_map from tokens / groups (as tt_flexicorp_fqs_enrich_hits_for_ui). */
		function ttfq_highlight_map( &$result ) {
			if ( ! isset( $result['hits'] ) || ! is_array( $result['hits'] ) ) return;
			foreach ( $result['hits'] as &$hit ) {
				if ( ! is_array( $hit ) || isset( $hit['highlight_map'] ) ) continue;
				$names = array();
				$byGroup = array();
				foreach ( ( isset( $hit['groups'] ) && is_array( $hit['groups'] ) ) ? $hit['groups'] : array() as $g ) {
					if ( ! is_array( $g ) ) continue;
					$n = trim( (string) ( isset( $g['id'] ) ? $g['id'] : ( isset( $g['name'] ) ? $g['name'] : '' ) ) );
					if ( $n === '' ) $n = 't1';
					if ( isset( $g['index'] ) ) $names[ (int) $g['index'] ] = $n;
					if ( ! isset( $byGroup[ $n ] ) ) $byGroup[ $n ] = array();
				}
				$ids = array();
				foreach ( ( isset( $hit['tokens'] ) && is_array( $hit['tokens'] ) ) ? $hit['tokens'] : array() as $t ) {
					if ( ! is_array( $t ) ) continue;
					$tid = trim( (string) ( isset( $t['id'] ) ? $t['id'] : ( isset( $t['tuid'] ) ? $t['tuid'] : '' ) ) );
					if ( $tid === '' ) continue;
					$ids[ $tid ] = true;
					if ( isset( $t['group'] ) && isset( $names[ (int) $t['group'] ] ) ) $byGroup[ $names[ (int) $t['group'] ] ][ $tid ] = true;
				}
				if ( ! $ids ) continue;
				$ids = array_keys( $ids );
				$hm = array( 'match' => $ids, 'default' => array( 'tok_ids' => $ids, 'range' => null ), 'groups' => array() );
				foreach ( $byGroup as $n => $set ) {
					if ( ! $set ) continue;
					$hm[ $n ] = array( 'tok_ids' => array_keys( $set ), 'range' => null );
					$hm['groups'][] = $n;
				}
				$hit['highlight_map'] = $hm;
			}
			unset( $hit );
		}
	}

	if ( ! function_exists( 'ttfq_send' ) ) {
		function ttfq_send( $status, $payload, $timing, $retry = '' ) {
			while ( ob_get_level() > 0 ) ob_end_clean();   // nothing TEITOK buffered goes out
			if ( ! headers_sent() ) {
				http_response_code( $status );
				header( 'Content-Type: application/json; charset=UTF-8' );
				header( 'Cache-Control: no-store' );
				if ( $retry !== '' ) header( 'Retry-After: ' . $retry );
				$st = array();
				foreach ( $timing as $k => $ms ) $st[] = $k . ';dur=' . $ms;
				header( 'Server-Timing: ' . implode( ', ', $st ) );
			}
			$payload['timing'] = $timing;
			print json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			exit;
		}
	}

	// ── the request ─────────────────────────────────────────────────────────────

	$ttfq_in = $_REQUEST;
	$ttfq_ct = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '';
	if ( stripos( $ttfq_ct, 'application/json' ) !== false ) {
		$j = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( is_array( $j ) ) $ttfq_in = array_merge( $ttfq_in, $j );
	}
	$ttfq_str = function ( $k, $def = '' ) use ( $ttfq_in ) {
		return isset( $ttfq_in[ $k ] ) && is_scalar( $ttfq_in[ $k ] ) ? trim( (string) $ttfq_in[ $k ] ) : $def;
	};
	$ttfq_int = function ( $k, $def, $min, $max ) use ( $ttfq_in ) {
		if ( ! isset( $ttfq_in[ $k ] ) || ! is_numeric( $ttfq_in[ $k ] ) ) return $def;
		return max( $min, min( $max, (int) $ttfq_in[ $k ] ) );
	};

	$ttfq_boot_ms = isset( $_SERVER['REQUEST_TIME_FLOAT'] )
		? (int) round( ( $ttfq_t0 - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 ) : 0;
	$ttfq_timing = array( 'teitok' => $ttfq_boot_ms );

	$ttfq_op = strtolower( $ttfq_str( 'op', 'query' ) );
	if ( ! in_array( $ttfq_op, array( 'query', 'run', 'status', 'context' ), true ) ) {
		ttfq_send( 400, array( 'ok' => false, 'error' => 'unknown op (query, run, status, context)' ), $ttfq_timing );
	}
	$ttfq_corpus = ttfq_corpus_id();
	list( $ttfq_role, $ttfq_user ) = ttfq_login();

	// This user's FQS hit-set session: stable per PHP session and corpus (never the
	// PHP session id itself). The session lock is released before FQS is called, so
	// a user's parallel requests (page + total + freq) do not queue behind each other.
	if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
		@session_start();
	}
	$ttfq_sid = session_id() !== '' ? 'tt-' . substr( hash( 'sha256', session_id() . '|' . $ttfq_corpus ), 0, 32 ) : '';

	$ttfq_query = $ttfq_op === 'run' ? $ttfq_str( 'cql', $ttfq_str( 'query' ) ) : $ttfq_str( 'query' );
	$ttfq_ql = $ttfq_str( 'ql', $ttfq_str( 'query_language', 'auto' ) );
	if ( $ttfq_op === 'query' && $ttfq_query !== '' ) {
		// a plain query with a trailing `;` runs as a query, not a program (flexicorp.php:
		// tt_flexicorp_teitok_query_effective_pando_search_query, loaded only for programs)
		if ( strpos( rtrim( $ttfq_query, " \t\n\r\0\x0B;" ), ';' ) === false ) {
			$ttfq_query = rtrim( $ttfq_query, " \t\n\r\0\x0B;" );
		} else {
			if ( ! function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) && is_readable( __DIR__ . '/flexicorp_freqs.php' ) ) {
				require_once __DIR__ . '/flexicorp_freqs.php';
			}
			if ( function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) ) {
				$ttfq_query = tt_flexicorp_teitok_query_effective_pando_search_query( $ttfq_query );
			}
		}
		// recent queries (the same session entries flexicorp.php keeps)
		if ( isset( $_SESSION ) && is_array( $_SESSION ) && $ttfq_str( 'from' ) === '' ) {
			$norm = trim( (string) preg_replace( '/\s+/u', ' ', $ttfq_query ) );
			if ( ! isset( $_SESSION['queries'] ) || ! is_array( $_SESSION['queries'] ) ) $_SESSION['queries'] = array();
			$_SESSION['queries'][ time() ] = array( 'query' => $ttfq_query, 'ql' => $ttfq_ql );
			if ( $ttfq_ql !== '' && $ttfq_ql !== 'auto' ) {
				if ( ! isset( $_SESSION['flexicorp_teitok'] ) || ! is_array( $_SESSION['flexicorp_teitok'] ) ) $_SESSION['flexicorp_teitok'] = array();
				$b = &$_SESSION['flexicorp_teitok'];
				$list = ( isset( $b['recent_queries_by_ql'][ $ttfq_ql ] ) && is_array( $b['recent_queries_by_ql'][ $ttfq_ql ] ) )
					? $b['recent_queries_by_ql'][ $ttfq_ql ] : array();
				$list = array_values( array_filter( $list, function ( $x ) use ( $norm ) {
					return trim( (string) preg_replace( '/\s+/u', ' ', (string) $x ) ) !== $norm;
				} ) );
				array_unshift( $list, $norm );
				$b['recent_queries_by_ql'][ $ttfq_ql ] = array_slice( $list, 0, 25 );
				unset( $b );
			}
		}
	}
	if ( function_exists( 'session_write_close' ) && session_status() === PHP_SESSION_ACTIVE ) {
		session_write_close();
	}

	// ── the FQS call ────────────────────────────────────────────────────────────

	$ttfq_base = ttfq_fqs_url();
	$ttfq_headers = array( 'Accept: application/json', 'Content-Type: application/json' );
	$ttfq_secret = tt_fqs_secret_from_env_file();   // what fqs serve runs with; then copies
	if ( $ttfq_secret === '' ) $ttfq_secret = ttfq_setting( 'flexicorp/fqs_secret', 'FQS_SECRET' );
	$ttfq_tok = ttfq_token( $ttfq_secret, $ttfq_corpus, $ttfq_role, $ttfq_user );
	if ( $ttfq_tok !== '' ) $ttfq_headers[] = 'Authorization: Bearer ' . $ttfq_tok;
	$ttfq_maxsize = (int) ttfq_setting( 'flexicorp/fqs_max_size' );
	if ( $ttfq_maxsize <= 0 ) $ttfq_maxsize = 1000;

	$ttfq_method = 'POST';
	$ttfq_body = array( 'corpus' => $ttfq_corpus, 'request_role' => $ttfq_role, 'user' => $ttfq_user );
	$ttfq_timeout = 60.0;
	switch ( $ttfq_op ) {
		case 'query':
			if ( $ttfq_query === '' && $ttfq_str( 'from' ) === '' ) {
				ttfq_send( 400, array( 'ok' => false, 'error' => 'query is required' ), $ttfq_timing );
			}
			$ttfq_path = '/query';
			$ttfq_body['query'] = $ttfq_query;
			$ttfq_body['language'] = $ttfq_ql !== '' ? $ttfq_ql : 'auto';
			$ttfq_body['start'] = $ttfq_int( 'start', 0, 0, PHP_INT_MAX );
			$ttfq_body['size'] = $ttfq_int( 'size', $ttfq_int( 'kwic_limit', 20, 0, $ttfq_maxsize ), 0, $ttfq_maxsize );
			if ( isset( $ttfq_in['window'] ) ) $ttfq_body['window'] = $ttfq_int( 'window', 5, 0, 50 );
			foreach ( array( 'context_scope', 'context_format' ) as $k ) {
				if ( $ttfq_str( $k ) !== '' ) $ttfq_body[ $k ] = $ttfq_str( $k );
			}
			// the TEITOK XML fragment span for a token window (as flexicorp.php sets it)
			if ( strtolower( $ttfq_str( 'context_format' ) ) === 'xml'
				 && in_array( strtolower( $ttfq_str( 'context_scope', 'tok' ) ), array( 'window', 'tok' ), true ) ) {
				$ttfq_body['flexicorp_fragment_kwic_cpos_span'] = true;
			}
			if ( in_array( $ttfq_str( 'backend' ), array( 'pando', 'cqp' ), true ) ) $ttfq_body['backend'] = $ttfq_str( 'backend' );
			// Pando: synthetic TEITOK-style XML fragment per hit — explicit, or for a project without xmlfiles/
			$ttfq_frag = $ttfq_str( 'fragment' );
			if ( $ttfq_frag === '1' || strtolower( $ttfq_frag ) === 'true'
				 || ( $ttfq_frag === '' && function_exists( 'tt_flexicorp_project_has_xml' ) && ! tt_flexicorp_project_has_xml() ) ) {
				$ttfq_body['fragment'] = true;
			}
			foreach ( array( 'name', 'from' ) as $k ) {
				$v = $ttfq_str( $k );
				if ( $v !== '' ) {
					if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $v ) ) {
						ttfq_send( 400, array( 'ok' => false, 'error' => "bad $k (a hit-set name: letters, digits, _)" ), $ttfq_timing );
					}
					$ttfq_body[ $k ] = $v;
				}
			}
			if ( ( isset( $ttfq_body['name'] ) || isset( $ttfq_body['from'] ) ) && $ttfq_sid !== '' ) $ttfq_body['session_id'] = $ttfq_sid;
			if ( isset( $ttfq_in['timeout_ms'] ) ) $ttfq_body['timeout_ms'] = $ttfq_int( 'timeout_ms', 0, 1, 600000 );
			break;
		case 'run':
			if ( $ttfq_query === '' ) ttfq_send( 400, array( 'ok' => false, 'error' => 'cql is required' ), $ttfq_timing );
			$ttfq_path = '/run';
			$ttfq_body['cql'] = $ttfq_query;
			if ( $ttfq_sid !== '' ) $ttfq_body['session_id'] = $ttfq_sid;   // names persist across requests
			foreach ( array( 'offset' => array( 0, PHP_INT_MAX ), 'limit' => array( 0, $ttfq_maxsize ),
							 'group_limit' => array( 1, 100000 ), 'timeout_ms' => array( 1, 600000 ) ) as $k => $r ) {
				if ( isset( $ttfq_in[ $k ] ) ) $ttfq_body[ $k ] = $ttfq_int( $k, $r[0], $r[0], $r[1] );
			}
			$ttfq_timeout = 300.0;
			break;
		case 'status':
			$job = $ttfq_str( 'job' );
			if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $job ) ) ttfq_send( 400, array( 'ok' => false, 'error' => 'job is required' ), $ttfq_timing );
			$ttfq_method = 'GET';
			$ttfq_path = '/status?corpus=' . rawurlencode( $ttfq_corpus ) . '&job=' . rawurlencode( $job );
			$ttfq_body = null;
			$ttfq_timeout = 10.0;
			break;
		case 'context':
			$qs = array( 'corpus' => $ttfq_corpus, 'pos' => (string) $ttfq_int( 'pos', -1, -1, PHP_INT_MAX ) );
			if ( $qs['pos'] === '-1' ) ttfq_send( 400, array( 'ok' => false, 'error' => 'pos is required' ), $ttfq_timing );
			foreach ( array( 'left', 'right' ) as $k ) {
				if ( isset( $ttfq_in[ $k ] ) ) $qs[ $k ] = (string) $ttfq_int( $k, 5, 0, 500 );
			}
			if ( $ttfq_str( 'sentence' ) !== '' ) $qs['sentence'] = $ttfq_str( 'sentence' ) === '0' ? '0' : '1';
			$ttfq_method = 'GET';
			$ttfq_path = '/context?' . http_build_query( $qs );
			$ttfq_body = null;
			$ttfq_timeout = 10.0;
			break;
	}

	$ttfq_t1 = microtime( true );
	list( $ttfq_status, $ttfq_raw, $ttfq_retry, $ttfq_err ) = ttfq_http(
		$ttfq_method, $ttfq_base . $ttfq_path,
		$ttfq_body === null ? null : json_encode( $ttfq_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		$ttfq_headers, $ttfq_timeout );
	$ttfq_timing['fqs'] = (int) round( ( microtime( true ) - $ttfq_t1 ) * 1000 );
	$ttfq_timing['total'] = isset( $_SERVER['REQUEST_TIME_FLOAT'] )
		? (int) round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 )
		: (int) round( ( microtime( true ) - $ttfq_t0 ) * 1000 );

	if ( $ttfq_status === 0 ) {
		ttfq_send( 502, array( 'ok' => false, 'error' => 'FQS not reachable at ' . $ttfq_base . ( $ttfq_err !== '' ? ': ' . $ttfq_err : '' ) ), $ttfq_timing );
	}
	$ttfq_json = json_decode( $ttfq_raw, true );
	if ( $ttfq_status >= 400 ) {
		// FQS / engine refusals keep their reason fields (denied, limit, busy, timed_out, tier, …)
		$out = is_array( $ttfq_json ) ? $ttfq_json : array( 'error' => trim( $ttfq_raw ) !== '' ? trim( $ttfq_raw ) : 'FQS HTTP ' . $ttfq_status );
		$out['ok'] = false;
		$out['status'] = $ttfq_status;
		$out['op'] = $ttfq_op;
		$out['corpus'] = $ttfq_corpus;
		ttfq_send( $ttfq_status, $out, $ttfq_timing, $ttfq_retry );
	}
	if ( ! is_array( $ttfq_json ) ) {
		ttfq_send( 502, array( 'ok' => false, 'error' => 'FQS answered no JSON', 'op' => $ttfq_op ), $ttfq_timing );
	}

	// the engine's result: /query wraps it (raw.done.result / raw.result); /run,
	// /status and /context return it directly
	$ttfq_result = $ttfq_json;
	$ttfq_meta = null;
	if ( $ttfq_op === 'query' ) {
		$raw = isset( $ttfq_json['raw'] ) && is_array( $ttfq_json['raw'] ) ? $ttfq_json['raw'] : array();
		if ( isset( $raw['done']['result'] ) && is_array( $raw['done']['result'] ) ) $ttfq_result = $raw['done']['result'];
		elseif ( isset( $raw['result'] ) && is_array( $raw['result'] ) ) $ttfq_result = $raw['result'];
		else $ttfq_result = $raw;
		$ttfq_meta = array();
		foreach ( array( 'backend_resolved', 'backend_catalog', 'query', 'policy', 'executor', 'meta' ) as $k ) {
			if ( isset( $ttfq_json[ $k ] ) ) $ttfq_meta[ $k ] = $ttfq_json[ $k ];
		}
	} elseif ( isset( $ttfq_json['result'] ) && is_array( $ttfq_json['result'] ) ) {
		$ttfq_result = $ttfq_json['result'];
		if ( isset( $ttfq_json['operation'] ) && ! isset( $ttfq_result['operation'] ) ) $ttfq_result['operation'] = $ttfq_json['operation'];
	}
	if ( is_array( $ttfq_result ) ) ttfq_highlight_map( $ttfq_result );

	$ttfq_out = array( 'ok' => true, 'op' => $ttfq_op, 'corpus' => $ttfq_corpus, 'result' => $ttfq_result );
	if ( $ttfq_meta !== null ) $ttfq_out['fqs_meta'] = $ttfq_meta;
	if ( $ttfq_op === 'status' && isset( $ttfq_json['job'] ) ) $ttfq_out['job'] = $ttfq_json['job'];
	ttfq_send( 200, $ttfq_out, $ttfq_timing );


?>