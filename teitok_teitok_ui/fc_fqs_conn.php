<?php
/**
 * fc_fqs_conn.php — which FQS a project talks to, and with which secret: one answer for every page and
 * endpoint (flexicorp, fqs_query, the FQS admin pages, the lenses). A project talks to one FQS; another
 * FQS is another project (or the shared settings for all of them).
 *
 *   address  flexicorp/fqs_url, else FQS_URL; only when neither is set, the FQS this machine runs
 *            (fqs-http.json, written by `fqs serve`), else http://127.0.0.1:8787. A configured address
 *            is used as it is: when it does not answer, the request says so, it does not go elsewhere.
 *   secret   the project's own settings first — flexicorp/fqs_env_file (a file with FQS_SECRET=…),
 *            then flexicorp/fqs_secret — then the machine's: FQS_ENV_FILE or /etc/fqs/fqs.env, then
 *            FQS_SECRET. A file outside the web folders is better than the setting: Resources/ is kept
 *            from the web only by .htaccess, which nginx ignores.
 */

if ( ! function_exists( 'tt_fc_fqs_runtime_file' ) ) {
	/** fqs-http.json of the FQS on this machine (url, db_path, …), as an array; array() when none. */
	function tt_fc_fqs_runtime_file() {
		static $memo = null;
		if ( $memo !== null ) return $memo;
		$cands = array();
		$e = getenv( 'FQS_HTTP_JSON' );
		if ( is_string( $e ) && trim( $e ) !== '' ) $cands[] = trim( $e );
		$db = getenv( 'FQS_DB_PATH' );
		if ( is_string( $db ) && $db !== '' ) $cands[] = rtrim( str_replace( '\\', '/', dirname( $db ) ), '/' ) . '/fqs-http.json';
		$cands[] = '/usr/local/var/fqs/fqs-http.json';
		$cands[] = '/var/lib/fqs/fqs-http.json';
		foreach ( array_unique( $cands ) as $c ) {
			if ( ! is_readable( $c ) ) continue;
			$j = json_decode( (string) @file_get_contents( $c ), true );
			if ( is_array( $j ) && ( ! empty( $j['url'] ) || ! empty( $j['db_path'] ) ) ) return $memo = $j + array( '_file' => $c );
		}
		return $memo = array();
	}
}

if ( ! function_exists( 'tt_fc_fqs_url_info' ) ) {
	/** array( url, source ): the FQS address and where it came from (the stack check shows both). */
	function tt_fc_fqs_url_info() {
		$u = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_url', '' ) ) : '';
		if ( $u !== '' ) return array( rtrim( $u, '/' ), 'flexicorp/fqs_url' );
		$e = getenv( 'FQS_URL' );
		if ( is_string( $e ) && trim( $e ) !== '' ) return array( rtrim( trim( $e ), '/' ), 'FQS_URL' );
		$rt = tt_fc_fqs_runtime_file();
		if ( ! empty( $rt['url'] ) ) return array( rtrim( trim( (string) $rt['url'] ), '/' ), 'this machine\'s FQS (' . $rt['_file'] . ')' );
		return array( 'http://127.0.0.1:8787', 'default' );
	}
}

if ( ! function_exists( 'tt_fc_fqs_url' ) ) {
	/** The FQS address, without a trailing slash. */
	function tt_fc_fqs_url() {
		return tt_fc_fqs_url_info()[0];
	}
}

if ( ! function_exists( 'tt_fc_fqs_env_file_secret' ) ) {
	/** FQS_SECRET from an environment file (the last assignment wins, as for systemd and `source`); '' when none. */
	function tt_fc_fqs_env_file_secret( $path ) {
		$txt = $path !== '' && @is_readable( $path ) ? @file_get_contents( $path ) : false;
		if ( is_string( $txt ) && preg_match_all( '/^\s*(?:export\s+)?FQS_SECRET\s*=\s*["\']?([^"\'\s#]+)/m', $txt, $m ) ) {
			return (string) end( $m[1] );
		}
		return '';
	}
}

if ( ! function_exists( 'tt_fc_fqs_secret' ) ) {
	/** The secret to sign FQS requests with; '' when there is none (requests then go unsigned). */
	function tt_fc_fqs_secret() {
		static $memo = null;
		if ( $memo !== null ) return $memo;
		$get = function ( $k ) {
			$v = function_exists( 'getset' ) ? getset( $k, '' ) : '';
			return is_scalar( $v ) ? trim( (string) $v ) : '';
		};
		if ( ( $f = $get( 'flexicorp/fqs_env_file' ) ) !== '' ) return $memo = tt_fc_fqs_env_file_secret( $f );
		if ( ( $s = $get( 'flexicorp/fqs_secret' ) ) !== '' ) return $memo = $s;
		$e = getenv( 'FQS_ENV_FILE' );
		$s = tt_fc_fqs_env_file_secret( is_string( $e ) && trim( $e ) !== '' ? trim( $e ) : '/etc/fqs/fqs.env' );
		if ( $s !== '' ) return $memo = $s;
		$e = getenv( 'FQS_SECRET' );
		return $memo = is_string( $e ) ? trim( $e ) : '';
	}
}

if ( ! function_exists( 'tt_fqs_secret_from_env_file' ) ) {
	/** The secret (former name, kept for the pages that call it). */
	function tt_fqs_secret_from_env_file() {
		return tt_fc_fqs_secret();
	}
}
