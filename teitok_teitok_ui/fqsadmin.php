<?php
/**
 * fqsadmin.php — the FQS admin UI inside TEITOK, without handing out tokens.
 *
 * A TEITOK action (index.php?action=fqsadmin). TEITOK's login decides who may
 * use it; this file then serves the admin UI from FQS's admin listener and
 * forwards the UI's API calls to it, signing each call server-side with a
 * fresh admin token (aud=fqs-admin, 2 minutes, user = the TEITOK user). The
 * browser never sees a token, FQS_SECRET never leaves the server, and the
 * admin listener can stay on 127.0.0.1.
 *
 * Who, and for what:
 * - server-wide: an admin of the shared project, or a shared user for all projects
 *   (projects="all"), listed in `flexicorp/fqs_admin_users` (comma-separated; `*` = every
 *   such admin), e.g. `<flexicorp fqs_admin_users="maarten"/>` in the shared settings.xml,
 *   or env FQS_ADMIN_USERS: the whole FQS admin, for every corpus of the server;
 * - a project: the admins of a project, opening it from that project: only the
 *   catalogue entry of that project's corpus (its listing: title, description, labels,
 *   FCS, …), nothing server-wide. `<flexicorp fqs_project_admins="0"/>` turns this off.
 *
 * Requests:
 *   index.php?action=fqsadmin                 the UI (FQS's admin/index.html, rewritten)
 *   index.php?action=fqsadmin&fqsa=app.js        a static file of the UI (admin.css, app.js, …)
 *   index.php?action=fqsadmin&fqsa=api/<path>&…  an admin API call (any method, JSON body)
 *   index.php?action=fqsadmin&fqsa=selftest      what this module sees: user, settings source,
 *                                                FQS reachability (JSON; no secret values)
 *   index.php?action=fqsadmin&fqsa=session       is the TEITOK session still there (JSON: logged_in,
 *                                                allowed, user, and this session's CSRF value);
 *                                                the UI uses it as keep-alive ping and to pick up
 *                                                a new session after logging in again
 *   index.php?action=fqsadmin&fqsa=loggedin      where TEITOK's login returns to from the UI's
 *                                                "log in again" tab (a page that closes itself)
 *
 * When the TEITOK session has ended, API calls get 401 with `login_required` and a
 * `login_url` (the UI then asks to log in again, in another tab, and carries on), and
 * opening the page itself goes to TEITOK's login and back.
 * API calls must carry the header X-FQS-Admin-CSRF with this session's value
 * (put in the page as <meta name="fqs-admin-csrf">): another site cannot set
 * it, so it cannot make a logged-in admin's browser change the catalog.
 *
 * Configuration (TEITOK settings, else environment):
 *   flexicorp/fqs_admin_url   | FQS_ADMIN_URL | http://127.0.0.1:8790   (fqs serve --admin-bind)
 *   FQS_SECRET in /etc/fqs/fqs.env (flexicorp/fqs_env_file) | flexicorp/fqs_secret | env FQS_SECRET
 *   flexicorp/fqs_admin_users                                           (who; see above)
 */

	global $user, $username, $isshared;

	if ( ! function_exists( 'ttfa_setting' ) ) {
		function ttfa_setting( $key, $env = '' ) {
			$v = '';
			$raw = function_exists( 'getset' ) ? getset( $key, '' ) : '';
			if ( is_array( $raw ) ) {
				// <flexicorp><item key="…" value="…"/></flexicorp> style entries
				foreach ( array( 'value', 'default', 'display' ) as $k ) {
					if ( isset( $raw[ $k ] ) && is_scalar( $raw[ $k ] ) && trim( (string) $raw[ $k ] ) !== '' ) {
						$raw = $raw[ $k ];
						break;
					}
				}
			}
			if ( is_scalar( $raw ) ) $v = trim( (string) $raw );
			if ( $v === '' && $env !== '' ) {
				$e = getenv( $env );
				if ( is_string( $e ) ) $v = trim( $e );
			}
			return $v;
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

	if ( ! function_exists( 'ttfa_out' ) ) {
		function ttfa_out( $status, $body, $ctype = 'application/json; charset=UTF-8', $extra = array() ) {
			while ( ob_get_level() > 0 ) ob_end_clean();   // nothing TEITOK buffered goes out
			if ( ! headers_sent() ) {
				http_response_code( $status );
				header( 'Content-Type: ' . $ctype );
				header( 'Cache-Control: no-store' );
				header( 'X-Content-Type-Options: nosniff' );
				header( 'X-Frame-Options: DENY' );
				foreach ( $extra as $h ) header( $h );
			}
			print $body;
			exit;
		}
	}

	if ( ! function_exists( 'ttfa_err' ) ) {
		function ttfa_err( $status, $msg ) {
			ttfa_out( $status, json_encode( array( 'ok' => false, 'error' => $msg ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}

	if ( ! function_exists( 'ttfa_json' ) ) {
		function ttfa_json( $status, $data ) {
			ttfa_out( $status, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}

	if ( ! function_exists( 'ttfa_csrf' ) ) {
		/** This session's CSRF value (made on first use). */
		function ttfa_csrf() {
			if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
				@session_start();
			}
			if ( empty( $_SESSION['fqsadmin_csrf'] ) || ! is_string( $_SESSION['fqsadmin_csrf'] ) ) {
				$_SESSION['fqsadmin_csrf'] = bin2hex( random_bytes( 24 ) );
			}
			return $_SESSION['fqsadmin_csrf'];
		}
	}

	if ( ! function_exists( 'ttfa_b64url' ) ) {
		function ttfa_b64url( $s ) {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		}
	}

	if ( ! function_exists( 'ttfa_http' ) ) {
		/** [status, body, content-type, error] */
		function ttfa_http( $method, $url, $body, $headers, $timeout ) {
			if ( function_exists( 'curl_init' ) ) {
				$ch = curl_init( $url );
				curl_setopt_array( $ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CUSTOMREQUEST => $method,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_CONNECTTIMEOUT_MS => 2000,
					CURLOPT_TIMEOUT_MS => (int) ( $timeout * 1000 ),
					CURLOPT_FOLLOWLOCATION => false,
				) );
				if ( $body !== null && $body !== '' ) curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
				$out = curl_exec( $ch );
				$err = $out === false ? (string) curl_error( $ch ) : '';
				$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
				$ctype = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
				curl_close( $ch );
				return array( $status, $out === false ? '' : (string) $out, $ctype, $err );
			}
			$ctx = stream_context_create( array( 'http' => array(
				'method' => $method, 'header' => implode( "\r\n", $headers ),
				'content' => $body === null ? '' : $body, 'ignore_errors' => true, 'timeout' => $timeout,
				'follow_location' => 0,
			) ) );
			$out = @file_get_contents( $url, false, $ctx );
			$status = 0;
			$ctype = '';
			foreach ( isset( $http_response_header ) ? $http_response_header : array() as $i => $line ) {
				if ( $i === 0 && preg_match( '/\s(\d{3})\s/', $line, $m ) ) $status = (int) $m[1];
				if ( stripos( $line, 'Content-Type:' ) === 0 ) $ctype = trim( substr( $line, 13 ) );
			}
			return array( $status, $out === false ? '' : (string) $out, $ctype, $out === false ? 'FQS admin unreachable' : '' );
		}
	}

	// ── who ─────────────────────────────────────────────────────────────────────

	$ttfa_user = isset( $username ) && is_string( $username ) ? trim( $username ) : '';
	$ttfa_usr = ( isset( $user ) && is_array( $user ) ) ? $user : array();
	if ( $ttfa_user === '' ) {
		foreach ( array( 'email', 'short', 'name' ) as $k ) {
			if ( isset( $ttfa_usr[ $k ] ) && is_string( $ttfa_usr[ $k ] ) && trim( $ttfa_usr[ $k ] ) !== '' ) {
				$ttfa_user = trim( $ttfa_usr[ $k ] );
				break;
			}
		}
	}
	$ttfa_is_admin = isset( $ttfa_usr['permissions'] ) && $ttfa_usr['permissions'] === 'admin';
	$ttfa_allowed_raw = ttfa_setting( 'flexicorp/fqs_admin_users', 'FQS_ADMIN_USERS' );
	$ttfa_allowed = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $ttfa_allowed_raw ) ) );
	$ttfa_self = 'index.php?action=' . rawurlencode( isset( $action ) && is_string( $action ) && $action !== '' ? $action : 'fqsadmin' );
	// `fqsa` (not `p`, which a TEITOK installation may use for itself)
	$ttfa_p = isset( $_GET['fqsa'] ) && is_string( $_GET['fqsa'] ) ? trim( $_GET['fqsa'] ) : '';
	// TEITOK's login, returning to a page that closes itself (the UI opens it in a new tab)
	$ttfa_login_url = 'index.php?action=login&goon=' . rawurlencode( $ttfa_self . '&fqsa=loggedin' );
	// server-wide admins (shared project, or a shared user for all projects) get the whole
	// FQS admin; the admins of a project only the entry of that project's corpus
	$ttfa_serverwide = $ttfa_is_admin && ( ! empty( $isshared )
		|| ( isset( $ttfa_usr['projects'] ) && $ttfa_usr['projects'] === 'all' ) );
	$ttfa_listed = $ttfa_allowed_raw !== ''
		&& ( in_array( '*', $ttfa_allowed, true ) || in_array( $ttfa_user, $ttfa_allowed, true ) );
	$ttfa_scope = '';
	if ( $ttfa_user !== '' && $ttfa_is_admin ) {
		if ( $ttfa_serverwide && $ttfa_listed ) {
			$ttfa_scope = 'server';
		} elseif ( empty( $isshared ) && ttfa_setting( 'flexicorp/fqs_project_admins' ) !== '0' ) {
			$ttfa_scope = 'project';
		}
	}
	$ttfa_may = $ttfa_scope !== '';

	// session state: the UI's keep-alive ping, and how it picks up a new session
	if ( $ttfa_p === 'session' ) {
		ttfa_json( 200, array(
			'ok' => true,
			'logged_in' => $ttfa_user !== '',
			'allowed' => $ttfa_may,
			'scope' => $ttfa_scope,
			'user' => $ttfa_user,
			'csrf' => $ttfa_may ? ttfa_csrf() : null,
			'login_url' => $ttfa_login_url,
		) );
	}
	if ( $ttfa_p === 'loggedin' ) {
		ttfa_out( 200, '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FQS admin</title></head><body>'
			. '<p>' . ( $ttfa_may ? 'You are logged in again. You can close this tab: the FQS admin page carries on.'
				: 'This TEITOK user cannot use the FQS admin.' ) . '</p>'
			. ( $ttfa_may ? '<script src="' . htmlspecialchars( $ttfa_self . '&fqsa=loggedin.js', ENT_QUOTES ) . '"></script>' : '' )
			. '</body></html>', 'text/html; charset=UTF-8',
			array( "Content-Security-Policy: default-src 'none'; script-src 'self'" ) );
	}
	if ( $ttfa_p === 'loggedin.js' ) {
		ttfa_out( 200, 'window.close();', 'text/javascript; charset=UTF-8' );
	}

	if ( $ttfa_user === '' ) {
		// not (or no longer) logged in: the page goes to TEITOK's login and back here;
		// the UI's calls get 401 so that it can ask to log in again
		if ( $ttfa_p === '' ) {
			while ( ob_get_level() > 0 ) ob_end_clean();
			http_response_code( 302 );
			header( 'Location: index.php?action=login&goon=' . rawurlencode( $ttfa_self ) );
			exit;
		}
		ttfa_json( 401, array( 'ok' => false, 'login_required' => true, 'login_url' => $ttfa_login_url,
			'error' => 'Your TEITOK session has ended: log in again to use the FQS admin.' ) );
	}
	if ( ! $ttfa_is_admin ) {
		ttfa_err( 403, 'FQS admin: log in as a TEITOK admin' );
	}
	if ( $ttfa_scope === '' ) {
		if ( $ttfa_allowed_raw === '' ) {
			ttfa_err( 404, 'FQS admin is not enabled: set flexicorp/fqs_admin_users in the settings of the shared project'
					  . ' (e.g. <flexicorp fqs_admin_users="yourname"/>; * = every server-wide TEITOK admin),'
					  . ' or FQS_ADMIN_USERS in the web server environment' );
		}
		if ( ! empty( $isshared ) || $ttfa_serverwide ) {
			ttfa_err( 403, 'FQS admin: TEITOK user "' . $ttfa_user . '" is not listed in flexicorp/fqs_admin_users' );
		}
		ttfa_err( 403, 'FQS admin: project admins may not edit the listing of their corpus here (flexicorp/fqs_project_admins="0")' );
	}

	// FQS's own env file first (the secret fqs serve actually runs with), then a
	// TEITOK setting / web server environment (copies that can go stale)
	$ttfa_secret = tt_fqs_secret_from_env_file();
	$ttfa_secret_src = 'FQS env file';
	if ( $ttfa_secret === '' ) {
		$ttfa_secret = ttfa_setting( 'flexicorp/fqs_secret' );
		$ttfa_secret_src = 'setting flexicorp/fqs_secret';
	}
	if ( $ttfa_secret === '' ) {
		$e = getenv( 'FQS_SECRET' );
		$ttfa_secret = is_string( $e ) ? trim( $e ) : '';
		$ttfa_secret_src = 'web server environment FQS_SECRET';
	}
	if ( $ttfa_secret === '' ) {
		ttfa_err( 500, 'FQS admin: no FQS secret — TEITOK could not read FQS_SECRET from /etc/fqs/fqs.env'
				  . ' (the web server user must be in group fqs, and php-fpm restarted after adding it;'
				  . ' or set flexicorp/fqs_env_file), and neither flexicorp/fqs_secret nor FQS_SECRET is set' );
	}
	$ttfa_base = rtrim( ttfa_setting( 'flexicorp/fqs_admin_url', 'FQS_ADMIN_URL' ), '/' );
	if ( $ttfa_base === '' ) $ttfa_base = 'http://127.0.0.1:8790';

	/** A fresh admin token for one call to FQS (2 minutes). */
	$ttfa_jwt = function () use ( $ttfa_user, $ttfa_secret, $ttfa_scope ) {
		$now = time();
		$claims = array( 'iss' => 'teitok-fqsadmin', 'aud' => 'fqs-admin', 'role' => 'admin',
						 'user' => $ttfa_user, 'scope' => $ttfa_scope, 'iat' => $now, 'exp' => $now + 120 );
		$h = ttfa_b64url( json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$pl = ttfa_b64url( json_encode( $claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $h . '.' . $pl . '.' . ttfa_b64url( hash_hmac( 'sha256', $h . '.' . $pl, $ttfa_secret, true ) );
	};

	// project scope: this project's corpus entries (project_root = this folder), and all ids
	$ttfa_here = @realpath( getcwd() );
	$ttfa_own = array();
	$ttfa_all_ids = array();
	if ( $ttfa_scope === 'project' ) {
		list( $st, $out ) = ttfa_http( 'GET', $ttfa_base . '/admin/api/corpora', null,
			array( 'Accept: application/json', 'Authorization: Bearer ' . $ttfa_jwt() ), 20.0 );
		$dec = $st === 200 ? json_decode( $out, true ) : null;
		foreach ( ( is_array( $dec ) && isset( $dec['corpora'] ) && is_array( $dec['corpora'] ) ) ? $dec['corpora'] : array() as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['id'] ) ) continue;
			$ttfa_all_ids[] = (string) $c['id'];
			$pr = isset( $c['project_root'] ) ? @realpath( (string) $c['project_root'] ) : false;
			if ( $pr !== false && $ttfa_here !== false && $pr === $ttfa_here ) $ttfa_own[] = (string) $c['id'];
		}
	}

	// per-session value the UI sends back on every API call (CSRF; see ttfa_csrf)
	$ttfa_csrf = ttfa_csrf();
	if ( function_exists( 'session_write_close' ) ) session_write_close();   // do not hold the session lock during FQS calls

	// ── self-test: what this module sees (no secret values) ─────────────────────

	if ( $ttfa_p === 'selftest' && $ttfa_scope !== 'server' ) {
		ttfa_err( 403, 'FQS admin: the self-test is for server-wide admins' );
	}
	if ( $ttfa_p === 'selftest' ) {
		$now = time();
		$h = ttfa_b64url( json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$pl = ttfa_b64url( json_encode( array( 'iss' => 'teitok-fqsadmin', 'aud' => 'fqs-admin', 'role' => 'admin',
			'user' => $ttfa_user, 'iat' => $now, 'exp' => $now + 60 ), JSON_UNESCAPED_SLASHES ) );
		$jwt = $h . '.' . $pl . '.' . ttfa_b64url( hash_hmac( 'sha256', $h . '.' . $pl, $ttfa_secret, true ) );
		$probe = function ( $url, $auth ) use ( $jwt ) {
			list( $st, $out, $ctype, $err ) = ttfa_http( 'GET', $url, null,
				$auth ? array( 'Accept: application/json', 'Authorization: Bearer ' . $jwt ) : array( 'Accept: application/json' ), 10.0 );
			return array( 'url' => $url, 'status' => $st, 'content_type' => $ctype, 'error' => $err,
						  'bytes' => strlen( $out ), 'start' => substr( preg_replace( '/\s+/', ' ', $out ), 0, 300 ) );
		};
		$api = $probe( $ttfa_base . '/admin/api/health', true );
		$hint = $api['status'] === 401
			? 'FQS rejects tokens signed with this secret (source: ' . $ttfa_secret_src . '): TEITOK and the running fqs serve'
			  . ' use different FQS_SECRET values. Compare secret_fingerprint with:'
			  . ' sudo bash -c \'. /etc/fqs/fqs.env 2>/dev/null; printf %s "$FQS_SECRET"\' | sha256sum | cut -c1-12'
			: ( $api['status'] === 0 ? 'FQS admin listener unreachable: is fqs serve running with --admin-bind ' . $ttfa_base . '?' : '' );
		ttfa_out( 200, json_encode( array(
			'ok' => $api['status'] === 200,
			'hint' => $hint,
			'teitok_user' => $ttfa_user,
			'teitok_admin' => $ttfa_is_admin,
			'allowed' => $ttfa_allowed,
			'secret_source' => $ttfa_secret_src,
			'secret_fingerprint' => substr( hash( 'sha256', $ttfa_secret ), 0, 12 ),
			'env_file' => ( function () {
				$path = getenv( 'FQS_ENV_FILE' ) ?: '/etc/fqs/fqs.env';
				if ( function_exists( 'getset' ) ) {
					$g = getset( 'flexicorp/fqs_env_file', '' );
					if ( is_scalar( $g ) && trim( (string) $g ) !== '' ) $path = trim( (string) $g );
				}
				return array( 'path' => $path, 'exists' => @file_exists( $path ), 'readable' => @is_readable( $path ),
							  'has_secret' => tt_fqs_secret_from_env_file() !== '' );
			} )(),
			'also_set' => array(
				'setting flexicorp/fqs_secret' => ttfa_setting( 'flexicorp/fqs_secret' ) !== '',
				'web server environment FQS_SECRET' => ( getenv( 'FQS_SECRET' ) ?: '' ) !== '',
			),
			'admin_url' => $ttfa_base,
			'action' => $ttfa_self,
			'get_keys' => array_keys( $_GET ),
			'php' => PHP_VERSION,
			'curl' => function_exists( 'curl_init' ),
			'fqs_admin_ui' => $probe( $ttfa_base . '/admin/', false ),
			'fqs_admin_health' => $api,
			'fqs_admin_corpora' => $probe( $ttfa_base . '/admin/api/corpora', true ),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	// ── API: forward with a fresh admin token ──────────────────────────────────

	if ( strpos( $ttfa_p, 'api/' ) === 0 ) {
		$sent = isset( $_SERVER['HTTP_X_FQS_ADMIN_CSRF'] ) ? (string) $_SERVER['HTTP_X_FQS_ADMIN_CSRF'] : '';
		if ( $sent === '' || ! hash_equals( $ttfa_csrf, $sent ) ) {
			// e.g. after logging in again: a new session has a new value (the UI fetches it)
			ttfa_json( 403, array( 'ok' => false, 'csrf_stale' => true,
				'error' => 'FQS admin: missing or wrong X-FQS-Admin-CSRF (reload the page)' ) );
		}
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) && $_SERVER['HTTP_ORIGIN'] !== '' ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
			if ( parse_url( (string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST ) !== parse_url( 'http://' . $host, PHP_URL_HOST ) ) {
				ttfa_err( 403, 'FQS admin: cross-origin request refused' );
			}
		}
		$rest = substr( $ttfa_p, 4 );
		if ( ! preg_match( '#^[A-Za-z0-9_./-]+$#', $rest ) || strpos( $rest, '..' ) !== false ) ttfa_err( 400, 'FQS admin: bad API path' );
		$qs = $_GET;
		unset( $qs['action'], $qs['fqsa'] );
		$url = $ttfa_base . '/admin/api/' . $rest . ( $qs ? '?' . http_build_query( $qs ) : '' );
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET' );
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) ttfa_err( 405, 'FQS admin: method not allowed' );
		$body = in_array( $method, array( 'POST', 'PUT', 'DELETE' ), true ) ? (string) file_get_contents( 'php://input' ) : null;

		// project scope: only this project's corpus entry
		$ttfa_filter_list = false;
		if ( $ttfa_scope === 'project' ) {
			$seg = explode( '/', $rest );
			$ok = false;
			if ( $rest === 'corpora' && $method === 'GET' ) {
				$ok = true;
				$ttfa_filter_list = true;
			} elseif ( $rest === 'corpora' && $method === 'PUT' ) {
				// entries of this project only: its own ids (or a new one), its own folder
				$dec = json_decode( (string) $body, true );
				$entries = ( is_array( $dec ) && array_keys( $dec ) === range( 0, count( $dec ) - 1 ) ) ? $dec : array( $dec );
				$ok = count( $entries ) > 0;
				foreach ( $entries as $e ) {
					$id = is_array( $e ) && isset( $e['id'] ) ? (string) $e['id'] : '';
					$pr = is_array( $e ) && isset( $e['project_root'] ) ? @realpath( (string) $e['project_root'] ) : false;
					if ( $id === '' || $pr === false || $pr !== $ttfa_here
						|| ( ! in_array( $id, $ttfa_own, true ) && in_array( $id, $ttfa_all_ids, true ) ) ) {
						$ok = false;
					}
				}
			} elseif ( $seg[0] === 'corpora' && isset( $seg[1] ) && in_array( rawurldecode( $seg[1] ), $ttfa_own, true ) ) {
				$ok = ( count( $seg ) === 2 && in_array( $method, array( 'GET', 'DELETE' ), true ) )
					|| ( count( $seg ) === 3 && $method === 'POST' && in_array( $seg[2], array( 'validate', 'fcs-enabled' ), true ) );
			}
			if ( ! $ok ) {
				ttfa_err( 403, 'FQS admin: opened from this project, you can edit the entry of this project\'s corpus only;'
						  . ' everything else is for server-wide admins (the FQS admin of the shared project)' );
			}
		}
		$headers = array( 'Accept: application/json', 'Authorization: Bearer ' . $ttfa_jwt() );
		if ( $body !== null && $body !== '' ) $headers[] = 'Content-Type: application/json';
		list( $st, $out, $ctype, $err ) = ttfa_http( $method, $url, $body, $headers, 120.0 );
		if ( $st === 0 ) ttfa_err( 502, 'FQS admin unreachable at ' . $ttfa_base . ( $err !== '' ? ' (' . $err . ')' : '' ) );
		if ( $ttfa_filter_list && $st === 200 ) {
			$dec = json_decode( $out, true );
			if ( is_array( $dec ) && isset( $dec['corpora'] ) && is_array( $dec['corpora'] ) ) {
				$dec['corpora'] = array_values( array_filter( $dec['corpora'], function ( $c ) use ( $ttfa_own ) {
					return is_array( $c ) && isset( $c['id'] ) && in_array( (string) $c['id'], $ttfa_own, true );
				} ) );
				$dec['count'] = count( $dec['corpora'] );
				$dec['scope'] = 'project';
				$out = json_encode( $dec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}
		ttfa_out( $st, $out, $ctype !== '' ? $ctype : 'application/json; charset=UTF-8' );
	}

	// ── static files of the UI ──────────────────────────────────────────────────

	if ( $ttfa_p !== '' ) {
		if ( ! preg_match( '#^[A-Za-z0-9_-]+\.(js|css|svg|png|ico)$#', $ttfa_p ) ) ttfa_err( 400, 'FQS admin: bad file name' );
		list( $st, $out, $ctype, $err ) = ttfa_http( 'GET', $ttfa_base . '/admin/' . $ttfa_p, null, array(), 10.0 );
		if ( $st !== 200 ) ttfa_err( $st ?: 502, 'FQS admin: cannot load ' . $ttfa_p . ( $err !== '' ? ' (' . $err . ')' : '' ) );
		ttfa_out( 200, $out, $ctype !== '' ? $ctype : 'application/octet-stream',
				  array( "Content-Security-Policy: default-src 'none'" ) );
	}

	// ── the page: FQS's admin/index.html, assets and API through this action ───

	list( $st, $html, $ctype, $err ) = ttfa_http( 'GET', $ttfa_base . '/admin/', null, array(), 10.0 );
	if ( $st !== 200 || stripos( $html, '<html' ) === false ) {
		ttfa_err( 502, 'FQS admin UI unreachable at ' . $ttfa_base . '/admin/' . ( $err !== '' ? ' (' . $err . ')' : '' )
				  . ' — is fqs serve running with --enable-admin-http --admin-bind?' );
	}
	$asset = function ( $m ) use ( $ttfa_self ) {
		return $m[1] . '="' . htmlspecialchars( $ttfa_self . '&fqsa=' . rawurlencode( $m[2] ), ENT_QUOTES ) . '"';
	};
	$html = preg_replace( '#<base\b[^>]*>#i', '', $html );   // FQS_ADMIN_BASE_HREF is for direct access, not here
	$html = preg_replace_callback( '#\b(href|src)="(?:\./)?([A-Za-z0-9_-]+\.(?:js|css|svg|png|ico))"#', $asset, $html );
	$meta = '<meta name="fqs-admin-proxy" content="' . htmlspecialchars( $ttfa_self, ENT_QUOTES ) . '" />'
		  . '<meta name="fqs-admin-csrf" content="' . htmlspecialchars( $ttfa_csrf, ENT_QUOTES ) . '" />'
		  . '<meta name="fqs-admin-user" content="' . htmlspecialchars( $ttfa_user, ENT_QUOTES ) . '" />'
		  . '<meta name="fqs-admin-scope" content="' . htmlspecialchars( $ttfa_scope, ENT_QUOTES ) . '" />'
		  . '<meta name="fqs-admin-corpora" content="' . htmlspecialchars( implode( ',', $ttfa_own ), ENT_QUOTES ) . '" />'
		  . '<meta name="fqs-admin-register" content="index.php?action=fqs&amp;act=addcorpus" />';
	$html = preg_replace( '#<head([^>]*)>#i', '<head$1>' . $meta, $html, 1 );
	ttfa_out( 200, $html, 'text/html; charset=UTF-8', array(
		"Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
		'Referrer-Policy: same-origin',
	) );
