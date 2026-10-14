<?php
/**
 * Custom layers made searchable: a user's Users/<folder>/custom_layers.xml becomes a pando overlay
 * index in <pando index>/overlays/<folder>/ (pando-index --overlay-index). pando-server and FQS merge
 * every overlay there when they open the corpus, so the labels can be searched and counted like
 * token attributes:
 *
 *   [overlay-ann-cl-transitivity="TRANS"]            a token ann labelled TRANS
 *   <overlay-ann-cl transitivity="TRANS">            the same as a labelled span (several tokens)
 *   count by match.overlay-ann-cl-sense-of-feel      counted by ann's labels
 *
 * The file stays the source (document + token ids, stable across reindexing); the overlay holds
 * corpus positions and is rebuilt from it. pando leaves out an overlay that is older than the main
 * index, so after a reindex the layer is unsearchable until it is rebuilt.
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/custom_layers_store.php';

if ( ! function_exists( 'tt_cl_overlay_name' ) ) {
	/** The query name of a user's attribute: overlay-<folder>-cl-<key, _ → ->. */
	function tt_cl_overlay_name( $folder, $key ) {
		return 'overlay-' . $folder . '-cl-' . str_replace( '_', '-', $key );
	}

	/** The span query name of a user's layer (all their labels as spans). */
	function tt_cl_overlay_struct( $folder ) {
		return 'overlay-' . $folder . '-cl';
	}

	function tt_cl_overlay_dir( $indexDir, $folder ) {
		return rtrim( $indexDir, '/' ) . '/overlays/' . $folder;
	}

	/** Labelled spans: [doc, [token ids], [attr → value]] for the attributes in the user's file. */
	function tt_cl_overlay_spans( array $data ) {
		$keys = array_keys( $data['attributes'] );
		$out = array();
		foreach ( $data['docs'] as $doc => $spans ) {
			foreach ( $spans as $corresp => $vals ) {
				$v = array();
				foreach ( $keys as $k ) {
					if ( isset( $vals[ $k ] ) && $vals[ $k ] !== '' ) $v[ $k ] = $vals[ $k ];
				}
				$ids = array_values( array_filter( array_map( function ( $x ) { return ltrim( $x, '#' ); },
					preg_split( '/\s+/', trim( (string) $corresp ) ) ) ) );
				if ( $v && $ids ) $out[] = array( (string) $doc, $ids, $v );
			}
		}
		return $out;
	}

	/**
	 * Corpus positions of the spans' tokens: doc → token id → position, from one pando process that
	 * reads one query per document. Ids pando cannot quote are left out (they stay unresolved).
	 */
	function tt_cl_overlay_resolve( $pando, $indexDir, array $spans ) {
		$want = array();
		foreach ( $spans as $s ) {
			foreach ( $s[1] as $id ) $want[ $s[0] ][ $id ] = true;
		}
		$ok = function ( $x ) { return $x !== '' && ! preg_match( '/["\\\\\n]/', $x ); };
		$lines = '';
		foreach ( $want as $doc => $ids ) {
			$ids = array_filter( array_keys( $ids ), $ok );
			if ( ! $ok( $doc ) || ! $ids ) continue;
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				$lines .= '[' . implode( '|', array_map( function ( $i ) { return 'id="' . $i . '"'; }, $chunk ) )
					. '] :: match.text_id="' . $doc . "\";\n";
			}
		}
		$pos = array();
		if ( $lines === '' ) return $pos;
		$cmd = escapeshellarg( $pando ) . ' --api --limit 100000 --context 0 --attrs id ' . escapeshellarg( $indexDir );
		$p = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $p ) ) return null;
		fwrite( $pipes[0], $lines );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $p );
		// one pretty-printed JSON object per query
		foreach ( preg_split( '/^(?=\{)/m', (string) $out ) as $chunk ) {
			$j = json_decode( $chunk, true );
			foreach ( $j['result']['hits'] ?? array() as $h ) {
				foreach ( $h['tokens'] ?? array() as $t ) {
					$doc = (string) ( $h['doc_id'] ?? '' );
					$id = (string) ( $t['id'] ?? '' );
					if ( isset( $want[ $doc ][ $id ] ) ) $pos[ $doc ][ $id ] = (int) $t['pos'];
				}
			}
		}
		return $pos;
	}

	/** The overlay as pando JSONL (header + one token-group region per labelled span). */
	function tt_cl_overlay_jsonl( array $keys, array $spans, array $pos, $size, &$missing ) {
		$missing = 0;
		$positional = array( 'cl_gid' );
		foreach ( $keys as $k ) $positional[] = 'cl_' . $k;
		$lines = array( json_encode( array( 'type' => 'header', 'version' => 2, 'positional' => $positional,
			'token_groups' => array( 'cl' ), 'token_group_membership' => array( 'cl_gid' ),
			'token_group_prop_attrs' => array( 'cl' => array_values( $keys ) ), 'multivalue' => array( 'cl_gid' ),
			'token_count' => (int) $size ), JSON_UNESCAPED_UNICODE ) );
		$n = 0;
		foreach ( $spans as $s ) {
			$p = array();
			foreach ( $s[1] as $id ) {
				if ( isset( $pos[ $s[0] ][ $id ] ) ) $p[] = $pos[ $s[0] ][ $id ];
			}
			if ( count( $p ) !== count( $s[1] ) ) { $missing++; continue; }
			sort( $p );
			$ranges = array();
			foreach ( $p as $x ) {
				$last = count( $ranges ) - 1;
				if ( $last >= 0 && $ranges[ $last ][1] + 1 >= $x ) $ranges[ $last ][1] = max( $ranges[ $last ][1], $x );
				else $ranges[] = array( $x, $x );
			}
			$gid = 'g' . ( ++$n );
			$ev = array( 'type' => 'region', 'struct' => 'cl' );
			if ( count( $ranges ) === 1 ) { $ev['start_pos'] = $ranges[0][0]; $ev['end_pos'] = $ranges[0][1]; }
			else $ev['spans'] = $ranges;
			$ev['attrs'] = array_merge( array( 'id' => $gid ), $s[2] );
			$ev['region_id'] = $gid;
			$lines[] = json_encode( $ev, JSON_UNESCAPED_UNICODE );
		}
		return implode( "\n", $lines ) . "\n";
	}

	function tt_cl_overlay_rmtree( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) return;
		foreach ( scandir( $dir ) as $f ) {
			if ( $f === '.' || $f === '..' ) continue;
			$p = $dir . '/' . $f;
			if ( is_dir( $p ) && ! is_link( $p ) ) tt_cl_overlay_rmtree( $p );
			else @unlink( $p );
		}
		@rmdir( $dir );
	}

	/** Index size from corpus.info (size=). */
	function tt_cl_overlay_main_size( $indexDir ) {
		$info = @file_get_contents( rtrim( $indexDir, '/' ) . '/corpus.info' );
		return ( $info !== false && preg_match( '/^size=(\d+)/m', $info, $m ) ) ? (int) $m[1] : 0;
	}

	/**
	 * Whether the user's layer is searchable: built (time), current (built after the last change of
	 * the file and after the last reindex), labels in it.
	 */
	function tt_cl_overlay_status( $root, $folder ) {
		$index = tt_fc_env_pando_index_dir( $root );
		$built = @filemtime( tt_cl_overlay_dir( $index, $folder ) . '/corpus.info' );
		$file = @filemtime( tt_cl_path( $root, $folder ) );
		$main = @filemtime( rtrim( $index, '/' ) . '/corpus.info' );
		$st = array( 'available' => $main !== false, 'built' => $built ? gmdate( 'Y-m-d\TH:i:s\Z', $built ) : '',
			'current' => false, 'reason' => '' );
		if ( $main === false ) $st['reason'] = 'no pando index';
		elseif ( ! $built ) $st['reason'] = 'not built';
		elseif ( $built < $main ) $st['reason'] = 'corpus reindexed';
		elseif ( $file !== false && $built < $file ) $st['reason'] = 'labels changed';
		else $st['current'] = true;
		$st['struct'] = tt_cl_overlay_struct( $folder );
		return $st;
	}

	/**
	 * (Re)build the user's overlay. Returns ['ok' => true, 'labels' => n, 'missing' => n, 'ms' => …] or
	 * an error message. The new overlay replaces the old one by rename, so a server never sees half
	 * of one.
	 */
	function tt_cl_overlay_build( $root, $folder ) {
		if ( ! tt_cl_valid_folder( $folder ) ) return 'log in to use custom layers';
		$t0 = microtime( true );
		$index = tt_fc_env_pando_index_dir( $root );
		$size = tt_cl_overlay_main_size( $index );
		if ( ! $size ) return 'this corpus has no pando index to search custom layers with';
		$pando = tt_fc_env_pando_bin( 'pando' );
		$pandoIndex = tt_fc_env_pando_bin( 'pando-index' );
		if ( $pando === '' || $pandoIndex === '' ) return 'pando is not installed on this server';
		$data = tt_cl_load( $root, $folder );
		$final = tt_cl_overlay_dir( $index, $folder );
		$spans = tt_cl_overlay_spans( $data );
		if ( ! $spans ) {
			tt_cl_overlay_rmtree( $final );
			return array( 'ok' => true, 'labels' => 0, 'missing' => 0, 'ms' => 0 );
		}
		$pos = tt_cl_overlay_resolve( $pando, $index, $spans );
		if ( $pos === null ) return 'pando could not be started';
		$missing = 0;
		$jsonl = tt_cl_overlay_jsonl( array_keys( $data['attributes'] ), $spans, $pos, $size, $missing );
		$base = dirname( $final );
		if ( ! is_dir( $base ) && ! @mkdir( $base, 0775, true ) ) return 'cannot write ' . $base;
		$tmp = $base . '/.' . $folder . '.' . getmypid() . '.new';
		$src = $tmp . '.jsonl';
		tt_cl_overlay_rmtree( $tmp );
		if ( @file_put_contents( $src, $jsonl ) === false ) return 'cannot write ' . $base;
		$cmd = escapeshellarg( $pandoIndex ) . ' --format jsonl --overlay-index --index-dir ' . escapeshellarg( $index )
			. ' ' . escapeshellarg( $src ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1';
		$log = array();
		exec( $cmd, $log, $rc );
		@unlink( $src );
		if ( $rc !== 0 || ! is_file( $tmp . '/corpus.info' ) ) {
			tt_cl_overlay_rmtree( $tmp );
			return 'building the layer failed: ' . trim( implode( ' ', array_slice( $log, -3 ) ) );
		}
		// layer_id = the user's folder (pando-index wrote the name of the temporary folder)
		$info = preg_replace( '/^layer_id=.*\n?/m', '', (string) @file_get_contents( $tmp . '/overlay.info' ) );
		@file_put_contents( $tmp . '/overlay.info', "layer_id={$folder}\n" . $info );
		$old = $base . '/.' . $folder . '.' . getmypid() . '.old';
		if ( is_dir( $final ) ) @rename( $final, $old );
		if ( ! @rename( $tmp, $final ) ) {
			if ( is_dir( $old ) ) @rename( $old, $final );
			tt_cl_overlay_rmtree( $tmp );
			return 'cannot write ' . $final;
		}
		tt_cl_overlay_rmtree( $old );
		return array( 'ok' => true, 'labels' => count( $spans ) - $missing, 'missing' => $missing,
			'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ) );
	}

	/**
	 * Custom-layer attributes the current user can count and search by in flexicorp: all of their own
	 * (with a built, current overlay) and the shared ones of others. Rows: name (the query name) and
	 * label ("Transitivity (my labels)", "Sense of feel (ann)").
	 */
	function tt_cl_overlay_field_rows( $root, $meFolder ) {
		$rows = array();
		$main = @filemtime( rtrim( tt_fc_env_pando_index_dir( $root ), '/' ) . '/corpus.info' );
		if ( $main === false ) return $rows;
		$index = tt_fc_env_pando_index_dir( $root );
		foreach ( tt_cl_all( $root ) as $folder => $data ) {
			$built = @filemtime( tt_cl_overlay_dir( $index, $folder ) . '/corpus.info' );
			if ( ! $built || $built < $main ) continue;   // not searchable (pando leaves a stale overlay out)
			$mine = $meFolder !== '' && $folder === $meFolder;
			foreach ( tt_cl_resolved_attributes( $root, $data ) as $key => $at ) {
				if ( ! $mine && empty( $at['shared'] ) ) continue;
				$rows[] = array( 'name' => tt_cl_overlay_name( $folder, $key ),
					'label' => ( $at['display'] !== '' ? $at['display'] : $key ) . ( $mine ? ' (my labels)' : ' (' . $folder . ')' ) );
			}
		}
		return $rows;
	}
}
