<?php
/**
 * Custom layers in queries: the names users type. A user's own labels are `my-<attribute>`
 * (`[my-feelwsd="experience"]`, `count by my-feelwsd`); flexicorp rewrites them to the user's
 * overlay (`overlay-<user folder>-cl-<attribute>`, custom_layers_overlay.php) on the way in and back
 * on the way out, so the stored names never show. Typing a stored name of someone else's layer does not
 * reach it (private layers stay private); published layers are reached as `<public name>-<attribute>`
 * (`[maarten-feelwsd="experience"]`, custom_layers_publish.php). One hyphen, a–z on both sides. Called once per flexicorp request (flexicorp.php).
 *
 * Mistakes are left to pando ("Unknown attribute 'my-x'"); the output filter adds which labels the user
 * has. Later this moves into FQS with the overlay build (dev/lindat-architecture-plan.md).
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/custom_layers_overlay.php';
require_once __DIR__ . '/custom_layers_publish.php';

if ( ! function_exists( 'tt_cl_names_rewrite' ) ) {
	/** Request parameters that can name attributes. */
	function tt_cl_names_params() {
		return array( 'query', 'q', 'field', 'freq_field', 'coll_field', 'qb_field_values' );
	}

	/**
	 * `$fn` applied to the parts of a query outside quoted strings ("…", '…') and regex literals
	 * (/…/ after = or !=), so `[form="my-x"]` keeps its value.
	 */
	function tt_cl_names_outside_quotes( $text, callable $fn ) {
		$out = '';
		$plain = '';
		$n = strlen( $text );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = $text[ $i ];
			$regex = $c === '/' && preg_match( '/!?=\s*$/', $plain );
			if ( $c === '"' || $c === "'" || $regex ) {
				$out .= $fn( $plain );
				$plain = '';
				$j = $i + 1;
				while ( $j < $n && $text[ $j ] !== $c ) $j += ( $text[ $j ] === '\\' ) ? 2 : 1;
				$out .= substr( $text, $i, $j - $i + 1 );
				$i = $j;
				continue;
			}
			$plain .= $c;
		}
		return $out . $fn( $plain );
	}

	/** The user's own query names: `my-<attribute>` → stored name, for their attributes (references too). */
	function tt_cl_names_mine_map( $root, $folder ) {
		$out = array();
		if ( $folder === '' ) return $out;
		foreach ( array_keys( tt_cl_resolved_attributes( $root, tt_cl_load( $root, $folder ) ) ) as $k ) {
			$out[ 'my-' . $k ] = tt_cl_overlay_name( $folder, $k );
		}
		return $out;
	}

	/**
	 * A query or field with the label names in $names (`my-feelwsd`, `maarten-feelwsd` → stored name)
	 * rewritten, outside quoted values. Names are `<layer>-<attribute>` (one hyphen, a–z); one not in
	 * $names is left as typed, so pando reports it. Stored names of someone else's layer
	 * become `private-…` (unknown to pando, so an error); the user's own stored names stay.
	 */
	function tt_cl_names_rewrite( $text, $folder, array $names = array() ) {
		$text = (string) $text;
		if ( strpos( $text, '-' ) === false ) return $text;
		return tt_cl_names_outside_quotes( $text, function ( $part ) use ( $folder, $names ) {
			$part = preg_replace_callback( '/(?<![A-Za-z0-9_\-])overlay-([a-z0-9]+)-cl-([A-Za-z][A-Za-z0-9\-]*)/',
				function ( $m ) use ( $folder ) { return $m[1] === $folder ? $m[0] : 'private-' . $m[2]; }, $part );
			return preg_replace_callback( '/(?<![A-Za-z0-9_\-])[a-z]+-[a-z]+(?![A-Za-z0-9_\-])/',
				function ( $m ) use ( $names ) { return $names[ $m[0] ] ?? $m[0]; }, $part );
		} );
	}

	/** The label names a request uses (name → stored name), to show them back in its output. */
	function tt_cl_names_used( array $names, array $texts ) {
		$out = array();
		$all = implode( "\n", $texts );
		foreach ( $names as $n => $stored ) {
			if ( preg_match( '/(?<![A-Za-z0-9_\-])' . preg_quote( $n, '/' ) . '(?![A-Za-z0-9_\-])/', $all ) ) $out[ $n ] = $stored;
		}
		return $out;
	}

	/** Rewrite the request parameters in place ($_GET, $_POST, $_REQUEST). */
	function tt_cl_names_apply_to_request( $folder, array $names = array() ) {
		foreach ( array( '_GET', '_POST', '_REQUEST' ) as $g ) {
			foreach ( tt_cl_names_params() as $k ) {
				if ( ! isset( $GLOBALS[ $g ][ $k ] ) ) continue;
				$v = $GLOBALS[ $g ][ $k ];
				$GLOBALS[ $g ][ $k ] = is_array( $v )
					? array_map( function ( $x ) use ( $folder, $names ) { return is_string( $x ) ? tt_cl_names_rewrite( $x, $folder, $names ) : $x; }, $v )
					: ( is_string( $v ) ? tt_cl_names_rewrite( $v, $folder, $names ) : $v );
			}
		}
	}

	/**
	 * The output with stored names shown as the names the request used ($used: name → stored; the
	 * user's other stored names as their first my- name, $mine), and hints on errors about labels: which
	 * names the user has, or that other people's labels need publishing.
	 */
	function tt_cl_names_present( $out, $folder, array $mine, array $used = array() ) {
		$back = array();
		foreach ( $mine as $n => $stored ) if ( ! isset( $back[ $stored ] ) ) $back[ $stored ] = $n;
		foreach ( $used as $n => $stored ) $back[ $stored ] = $n;   // as the request named it
		if ( $back ) {
			uksort( $back, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );   // one may extend another
			$out = strtr( $out, $back );
		}
		// the error as pando wrote it; in flexicorp's JSON the quotes may be escaped (\u0027)
		$q = "(?:'|\\\\u0027)";
		if ( preg_match( "/Unknown attribute {$q}my-/", $out ) ) {
			$hint = $folder === ''
				? ' (log in to search your own labels)'
				: ( $mine ? ' (your labels: ' . implode( ', ', array_keys( $mine ) ) . ')' : ' (you have no searchable labels yet: Custom layers, Make my labels searchable)' );
			$out = preg_replace( "/(Unknown attribute {$q}my-[A-Za-z0-9\\-]*{$q})/", '$1' . addcslashes( $hint, '\\$' ), $out );
		}
		if ( preg_match( "/Unknown attribute {$q}private-/", $out ) ) {
			$out = preg_replace( "/Unknown attribute {$q}private-[A-Za-z0-9\\-]*{$q}/",
				'Unknown attribute: the labels of other users can be searched once they publish them', $out );
		}
		return $out;
	}

	/** The user's own label names (name → stored), when their layer is searchable. */
	function tt_cl_names_mine( $root, $folder ) {
		if ( $folder === '' ) return array();
		$st = tt_cl_overlay_status( $root, $folder );
		if ( empty( $st['built'] ) || $st['reason'] === 'corpus reindexed' ) return array();
		return tt_cl_names_mine_map( $root, $folder );
	}

	/** Rewrite this request and show stored names as the label names in its output (flexicorp.php calls this first). */
	function tt_cl_names_apply() {
		$folder = tt_fc_env_user_folder();
		$root = tt_fc_env_project_root();
		$texts = array();
		foreach ( tt_cl_names_params() as $k ) {
			$v = $_REQUEST[ $k ] ?? '';
			$texts[] = is_array( $v ) ? implode( "\n", array_filter( $v, 'is_string' ) ) : (string) $v;
		}
		// label names have a hyphen between letters: only then are the user's and the published names read
		$names = array();
		if ( preg_match( '/[a-z]-[a-z]/', implode( "\n", $texts ) ) ) {
			$names = tt_cl_names_mine_map( $root, $folder )
				+ array_map( function ( $x ) { return $x[0]; }, tt_cl_public_map( $root, $folder ) );
		}
		$used = tt_cl_names_used( $names, $texts );
		tt_cl_names_apply_to_request( $folder, $names );
		ob_start( function ( $out ) use ( $folder, $root, $used ) {
			$mine = ( $folder !== '' && ( strpos( $out, tt_cl_overlay_prefix( $folder ) ) !== false || strpos( $out, 'Unknown attribute ' ) !== false ) )
				? tt_cl_names_mine( $root, $folder ) : array();
			return tt_cl_names_present( $out, $folder, $mine, $used );
		} );
	}
}
