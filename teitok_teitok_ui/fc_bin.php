<?php
/**
 * fc_bin.php — the programs flexicorp runs (flexicorp-pando, pando, pando-index): one path each, from
 * the settings, else where install-stack.pl puts them. Nothing is searched for at query time; the
 * admin check (fc_stack_check.php) shows which program is used, its build and where the path came from.
 *
 *   flexicorp-pando  pando/flexicorp_pando_bin
 *   pando            pando/pando_bin
 *   pando-index      pando/pando_index_bin, else next to pando/pando_bin
 *   otherwise        PREFIX/bin/NAME, PREFIX = pando/prefix (install-stack.pl --prefix), default /usr/local
 */

if ( ! function_exists( 'tt_fc_bin_path' ) ) {
	/** array( path, source ): the path flexicorp uses for $name and the setting it came from. */
	function tt_fc_bin_path( $name ) {
		$get = function ( $k ) {
			return function_exists( 'getset' ) ? trim( (string) getset( $k, '' ) ) : '';
		};
		$keys = array( 'flexicorp-pando' => 'pando/flexicorp_pando_bin', 'pando' => 'pando/pando_bin',
			'pando-index' => 'pando/pando_index_bin' );
		if ( isset( $keys[ $name ] ) && ( $p = $get( $keys[ $name ] ) ) !== '' ) return array( $p, $keys[ $name ] );
		if ( $name === 'pando-index' && ( $p = $get( 'pando/pando_bin' ) ) !== '' )
			return array( dirname( $p ) . '/pando-index', 'pando/pando_bin (its folder)' );
		$prefix = $get( 'pando/prefix' );
		return array( rtrim( $prefix !== '' ? $prefix : '/usr/local', '/' ) . "/bin/$name",
			$prefix !== '' ? 'pando/prefix' : 'install-stack.pl default (/usr/local)' );
	}
}

if ( ! function_exists( 'tt_fc_bin' ) ) {
	/** The program $name to run, or '' when that path holds no executable. */
	function tt_fc_bin( $name ) {
		$p = tt_fc_bin_path( $name )[0];
		return is_file( $p ) && is_executable( $p ) ? $p : '';
	}
}
