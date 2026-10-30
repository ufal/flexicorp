<?php
/**
 * fc_bin.php — the programs flexicorp runs (flexicorp-pando, pando, pando-index): one path each, from
 * TEITOK's own settings for programs, as findapp() reads them, without findapp's search of /usr/bin and
 * the PATH. Nothing is searched for at query time; the admin check (fc_stack_check.php) shows which program
 * is used, its build and where the path came from.
 *
 *   bin/NAME             one program (<bin flexicorp-pando="…" pando="…"/>)
 *   defaults/base/bin    the folder of all of them (TEITOK's $bindir), default /usr/local/bin, where
 *                        install-stack.pl puts them
 */

if ( ! function_exists( 'tt_fc_bin_path' ) ) {
	/** array( path, source ): the path flexicorp uses for $name and the setting it came from. */
	function tt_fc_bin_path( $name ) {
		$get = function ( $k ) {
			return function_exists( 'getset' ) ? trim( (string) getset( $k, '' ) ) : '';
		};
		if ( ( $p = $get( "bin/$name" ) ) !== '' ) return array( $p, "bin/$name" );
		$dir = isset( $GLOBALS['bindir'] ) && is_string( $GLOBALS['bindir'] ) && $GLOBALS['bindir'] !== ''
			? $GLOBALS['bindir'] : $get( 'defaults/base/bin' );
		if ( $dir !== '' ) return array( rtrim( $dir, '/' ) . "/$name", 'defaults/base/bin' );
		return array( "/usr/local/bin/$name", 'defaults/base/bin (default /usr/local/bin)' );
	}
}

if ( ! function_exists( 'tt_fc_bin' ) ) {
	/** The program $name to run, or '' when that path holds no executable. */
	function tt_fc_bin( $name ) {
		$p = tt_fc_bin_path( $name )[0];
		return is_file( $p ) && is_executable( $p ) ? $p : '';
	}
}
