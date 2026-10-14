<?php
/**
 * The TEITOK environment of a flexicorp module: who is logged in, whether they are an admin, the
 * project folder, the PHP session and the URL of the shared Scripts/ folder. One place for what every
 * module page needs (classify.php uses it; flexicorp.php keeps its own copies until the post-demo
 * split moves it here too — flexicorp dev/ROADMAP-TODO.md item 0).
 */

if ( ! function_exists( 'tt_fc_env_user' ) ) {
	/** The logged-in TEITOK user ('' for visitors): $username, else the user record, else the session. */
	function tt_fc_env_user() {
		if ( isset( $GLOBALS['username'] ) && is_string( $GLOBALS['username'] ) && trim( $GLOBALS['username'] ) !== '' ) {
			return trim( $GLOBALS['username'] );
		}
		if ( isset( $GLOBALS['user'] ) && is_array( $GLOBALS['user'] ) ) {
			foreach ( array( 'email', 'short', 'name' ) as $k ) {
				if ( isset( $GLOBALS['user'][ $k ] ) && is_string( $GLOBALS['user'][ $k ] ) && trim( $GLOBALS['user'][ $k ] ) !== '' ) {
					return trim( $GLOBALS['user'][ $k ] );
				}
			}
		}
		// a login through an external identity (Shibboleth, other SSO): TEITOK's $_SESSION['extid']
		if ( isset( $_SESSION['extid'] ) && is_array( $_SESSION['extid'] ) ) {
			foreach ( $_SESSION['extid'] as $id ) {
				if ( is_string( $id ) && trim( $id ) !== '' ) return trim( $id );
			}
		}
		return '';
	}

	/** The user's own folder name under the project's Users/ (as TEITOK's query manager names it); '' for visitors. */
	function tt_fc_env_user_folder( $user = null ) {
		$u = $user === null ? tt_fc_env_user() : (string) $user;
		return preg_replace( '/[^a-z0-9]/', '', strtolower( $u ) );
	}

	/** A TEITOK admin (the one permission TEITOK treats specially). */
	function tt_fc_env_is_admin() {
		return isset( $GLOBALS['user']['permissions'] ) && $GLOBALS['user']['permissions'] === 'admin';
	}

	/** The project (corpus) folder: TEITOK runs a project's pages from it. */
	function tt_fc_env_project_root() {
		$cwd = getcwd();
		return ( $cwd && is_dir( $cwd ) ) ? $cwd : '.';
	}

	function tt_fc_env_session() {
		if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
			@session_start();
		}
	}

	/**
	 * A per-session token for a module's write requests (sent back in a header, compared with
	 * hash_equals): requests from other sites cannot know it.
	 */
	function tt_fc_env_session_token( $module ) {
		tt_fc_env_session();
		$k = 'tt_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $module ) ) . '_token';
		if ( empty( $_SESSION[ $k ] ) ) $_SESSION[ $k ] = bin2hex( random_bytes( 16 ) );
		return (string) $_SESSION[ $k ];
	}

	/**
	 * URL of the folder the module's scripts are installed in (SHARED/Scripts by install-teitok-ui.pl):
	 * the flexicorp/ecscripts_base setting, FLEXICORP_ECSCRIPTS, TEITOK's getjsurl() for $bundle,
	 * the $ecscripts setting, else 'Scripts' (relative to the project).
	 */
	function tt_fc_env_scripts_url( $bundle = 'flexicorp' ) {
		if ( function_exists( 'getset' ) ) {
			$u = trim( (string) getset( 'flexicorp/ecscripts_base', '' ) );
			if ( $u !== '' ) return rtrim( $u, '/' );
		}
		$env = getenv( 'FLEXICORP_ECSCRIPTS' );
		if ( is_string( $env ) && trim( $env ) !== '' ) return rtrim( trim( $env ), '/' );
		if ( function_exists( 'getjsurl' ) ) {
			$u = preg_replace( '/\?.*$/', '', trim( (string) getjsurl( $bundle ) ) );
			$tail = '/' . $bundle . '.js';
			if ( $u !== '' && substr( $u, -strlen( $tail ) ) === $tail ) return rtrim( substr( $u, 0, -strlen( $tail ) ), '/' );
		}
		global $ecscripts;
		$base = isset( $ecscripts ) ? rtrim( trim( (string) $ecscripts ), '/' ) : '';
		if ( $base !== '' ) return preg_match( '#/Scripts$#i', $base ) ? $base : $base . '/Scripts';
		return 'Scripts';
	}
}
