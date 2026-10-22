<?php
/**
 * multiedit: the match list of a query (fixed once listed, so pages stay the same while someone works through
 * them, and with a record of which matches have been on screen), and the jobs: changes collected from the
 * pages (a draft per user), handed to the background worker (multiedit_worker.php), which records per change
 * what it did. A finished job is the edit log; undo is a new job with old and new swapped.
 *
 *   tmp/multiedit/sets/<id>.json   query, user, target and head labels, rows [doc, sid, id, head, keys{}], seen {n: 1}
 *   tmp/multiedit/jobs/<id>.json   user, query, status (draft, queued, running, done, failed), changes,
 *                                  results, progress
 */

if ( ! function_exists( 'tt_me_dir' ) ) {
	function tt_me_dir( $sub ) {
		$d = "tmp/multiedit/$sub";
		if ( ! is_dir( $d ) ) @mkdir( $d, 0775, true );
		return $d;
	}
}

if ( ! function_exists( 'tt_me_write_json' ) ) {
	/** Write JSON atomically (a reader never sees half a file). */
	function tt_me_write_json( $file, $data ) {
		$tmp = $file . '.' . getmypid() . '.tmp';
		if ( @file_put_contents( $tmp, json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) === false ) return false;
		return @rename( $tmp, $file );
	}
}

if ( ! function_exists( 'tt_me_read_json' ) ) {
	function tt_me_read_json( $file ) {
		$d = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $d ) ? $d : null;
	}
}

if ( ! function_exists( 'tt_me_valid_id' ) ) {
	function tt_me_valid_id( $id ) {
		return is_string( $id ) && preg_match( '/^[0-9a-z-]{6,40}$/', $id );
	}
}

// ---- the match list of a query

if ( ! function_exists( 'tt_me_set_make' ) ) {
	/**
	 * Keep the matches of a query (rows from tabulate: edit.text_id, edit.s_id, edit.id, head.id …; the other
	 * columns as sort / group keys). Returns the set.
	 */
	function tt_me_set_make( $query, array $labels, array $tabrows, $total, $user, $target = 'edit', $head = '' ) {
		$rows = array();
		$ids = array( "$target.text_id", "$target.s_id", "$target.id" );
		if ( $head !== '' ) array_push( $ids, "$head.id", "$head.s_id" );
		foreach ( $tabrows as $r ) {
			$keys = $r;
			foreach ( $ids as $k ) unset( $keys[ $k ] );
			$row = array( 'doc' => $r["$target.text_id"] ?? '', 'sid' => $r["$target.s_id"] ?? '', 'id' => $r["$target.id"] ?? '', 'keys' => $keys );
			if ( $head !== '' && isset( $r["$head.id"] ) ) {
				$row['head'] = $r["$head.id"];
				$row['headsid'] = $r["$head.s_id"] ?? '';
			}
			$rows[] = $row;
		}
		$set = array( 'id' => substr( sha1( $user . "\n" . $query . "\n" . microtime() ), 0, 16 ), 'query' => $query, 'labels' => $labels,
			'target' => $target, 'head' => $head,
			'user' => $user, 'made' => date( 'Y-m-d H:i:s' ), 'total' => (int) $total, 'rows' => $rows, 'seen' => new stdClass() );
		tt_me_write_json( tt_me_dir( 'sets' ) . "/{$set['id']}.json", $set );
		return $set;
	}
}

if ( ! function_exists( 'tt_me_set_load' ) ) {
	function tt_me_set_load( $id, $user ) {
		if ( ! tt_me_valid_id( $id ) ) return null;
		$set = tt_me_read_json( tt_me_dir( 'sets' ) . "/$id.json" );
		return $set && $set['user'] === $user ? $set : null;
	}
}

if ( ! function_exists( 'tt_me_set_order' ) ) {
	/** The row numbers in the order asked: corpus order, or by a key (LABEL.FIELD), equal keys in corpus order. */
	function tt_me_set_order( array $set, $sort ) {
		$order = array_keys( $set['rows'] );
		if ( $sort !== '' ) {
			$key = function ( $n ) use ( $set, $sort ) { return (string) ( $set['rows'][ $n ]['keys'][ $sort ] ?? '' ); };
			usort( $order, function ( $a, $b ) use ( $key ) { return strcmp( $key( $a ), $key( $b ) ) ?: $a - $b; } );
		}
		return $order;
	}
}

if ( ! function_exists( 'tt_me_set_page' ) ) {
	/** One page of a set (rows with their number n), recorded as seen. */
	function tt_me_set_page( array &$set, $from, $size, $sort ) {
		$order = array_slice( tt_me_set_order( $set, $sort ), max( 0, (int) $from ), max( 1, (int) $size ) );
		$rows = array();
		$seen = (array) $set['seen'];
		foreach ( $order as $n ) {
			$rows[] = array( 'n' => $n ) + $set['rows'][ $n ];
			$seen[ (string) $n ] = 1;
		}
		$set['seen'] = $seen;
		tt_me_write_json( tt_me_dir( 'sets' ) . "/{$set['id']}.json", $set );
		return $rows;
	}
}

// ---- jobs

if ( ! function_exists( 'tt_me_job_file' ) ) {
	function tt_me_job_file( $id ) {
		return tt_me_valid_id( $id ) ? tt_me_dir( 'jobs' ) . "/$id.json" : '';
	}
}

if ( ! function_exists( 'tt_me_job_load' ) ) {
	function tt_me_job_load( $id ) {
		$f = tt_me_job_file( $id );
		return $f !== '' ? tt_me_read_json( $f ) : null;
	}
}

if ( ! function_exists( 'tt_me_job_save' ) ) {
	function tt_me_job_save( array $job ) {
		return tt_me_write_json( tt_me_job_file( $job['id'] ), $job );
	}
}

if ( ! function_exists( 'tt_me_jobs' ) ) {
	/** The jobs, latest first (without their changes: a summary each). */
	function tt_me_jobs( $limit = 30 ) {
		$out = array();
		foreach ( glob( tt_me_dir( 'jobs' ) . '/*.json' ) ?: array() as $f ) {
			$j = tt_me_read_json( $f );
			if ( ! $j ) continue;
			$out[] = tt_me_job_summary( $j );
		}
		usort( $out, function ( $a, $b ) { return strcmp( $b['id'], $a['id'] ); } );
		return array_slice( $out, 0, $limit );
	}
}

if ( ! function_exists( 'tt_me_job_summary' ) ) {
	function tt_me_job_summary( array $j ) {
		$counts = array();
		foreach ( (array) ( $j['results'] ?? array() ) as $r ) $counts[ $r['result'] ] = ( $counts[ $r['result'] ] ?? 0 ) + 1;
		$docs = array();
		foreach ( (array) $j['changes'] as $c ) $docs[ $c['doc'] ] = 1;
		return array( 'id' => $j['id'], 'user' => $j['user'], 'query' => $j['query'], 'status' => $j['status'], 'made' => $j['made'],
			'finished' => $j['finished'] ?? '', 'changes' => count( (array) $j['changes'] ), 'documents' => count( $docs ),
			'counts' => $counts, 'progress' => $j['progress'] ?? null, 'undo_of' => $j['undo_of'] ?? '', 'undone_by' => $j['undone_by'] ?? '',
			'error' => $j['error'] ?? '' );
	}
}

if ( ! function_exists( 'tt_me_new_id' ) ) {
	function tt_me_new_id() {
		return date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
	}
}

if ( ! function_exists( 'tt_me_draft' ) ) {
	/** The user's draft: the changes collected from the pages, not yet run (made when there is none). */
	function tt_me_draft( $user, $make = true ) {
		foreach ( glob( tt_me_dir( 'jobs' ) . '/*.json' ) ?: array() as $f ) {
			$j = tt_me_read_json( $f );
			if ( $j && $j['status'] === 'draft' && $j['user'] === $user ) return $j;
		}
		if ( ! $make ) return null;
		return array( 'id' => tt_me_new_id(), 'user' => $user, 'query' => '', 'status' => 'draft', 'made' => date( 'Y-m-d H:i:s' ), 'changes' => array() );
	}
}

if ( ! function_exists( 'tt_me_add' ) ) {
	/**
	 * Add changes from a page to the user's draft. Each: n (the row of the set), field, old, new. Only rows
	 * that have been on screen, only fields that may be edited, and a head only as the one the query found.
	 * Returns array( draft, problems ).
	 */
	function tt_me_add( array $set, array $changes, array $fields, $user ) {
		$seen = (array) $set['seen'];
		$draft = tt_me_draft( $user );
		$have = array();
		foreach ( $draft['changes'] as $i => $c ) $have[ "{$c['doc']}|{$c['id']}|{$c['field']}" ] = $i;
		$problems = array();
		foreach ( $changes as $c ) {
			$n = (string) ( $c['n'] ?? '' );
			$f = (string) ( $c['field'] ?? '' );
			$new = $c['new'] ?? null;
			if ( ! isset( $set['rows'][ (int) $n ] ) || ! isset( $seen[ $n ] ) ) { $problems[] = "match $n has not been on screen"; continue; }
			if ( ! isset( $fields[ $f ] ) ) { $problems[] = "$f may not be changed here"; continue; }
			if ( ! is_string( $new ) || strlen( $new ) > 2000 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $new ) ) { $problems[] = "a bad value for $f"; continue; }
			$row = $set['rows'][ (int) $n ];
			if ( $f === 'head' && $new !== '' && $new !== ( $row['head'] ?? null ) ) { $problems[] = "a head can only be the one the query found"; continue; }
			$rec = array( 'doc' => $row['doc'], 'id' => $row['id'], 'sid' => $row['sid'], 'form' => (string) ( $c['form'] ?? '' ),
				'field' => $f, 'old' => (string) ( $c['old'] ?? '' ), 'new' => $new );
			$k = "{$row['doc']}|{$row['id']}|$f";
			if ( isset( $have[ $k ] ) ) $draft['changes'][ $have[ $k ] ] = $rec;
			else { $have[ $k ] = count( $draft['changes'] ); $draft['changes'][] = $rec; }
		}
		if ( $draft['query'] === '' ) $draft['query'] = $set['query'];
		elseif ( strpos( "\n" . $draft['query'] . "\n", "\n" . $set['query'] . "\n" ) === false ) $draft['query'] .= "\n" . $set['query'];
		tt_me_job_save( $draft );
		return array( $draft, $problems );
	}
}

if ( ! function_exists( 'tt_me_undo_job' ) ) {
	/** A job undoing a finished one: what it applied, old and new swapped (through the same checks). */
	function tt_me_undo_job( array $job, $user ) {
		$changes = array();
		foreach ( (array) ( $job['results'] ?? array() ) as $r ) {
			if ( $r['result'] !== 'applied' ) continue;
			$c = $job['changes'][ $r['i'] ];
			$changes[] = array( 'doc' => $c['doc'], 'id' => $c['id'], 'sid' => $c['sid'] ?? '', 'form' => $c['form'], 'field' => $c['field'],
				'old' => $c['new'], 'new' => $c['old'] );
		}
		return array( 'id' => tt_me_new_id(), 'user' => $user, 'query' => $job['query'], 'status' => 'queued', 'made' => date( 'Y-m-d H:i:s' ),
			'undo_of' => $job['id'], 'changes' => $changes );
	}
}

if ( ! function_exists( 'tt_me_php_cli' ) ) {
	/** A PHP command line to run the worker with (the web server's PHP is often not one). */
	function tt_me_php_cli() {
		$set = trim( (string) getset( 'flexicorp/php_cli', '' ) );
		if ( $set !== '' ) return $set;
		if ( PHP_BINARY && preg_match( '#/php[0-9.]*$#', PHP_BINARY ) && is_executable( PHP_BINARY ) ) return PHP_BINARY;
		$v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		foreach ( array( "/usr/bin/php$v", '/usr/bin/php', '/usr/local/bin/php', '/opt/homebrew/bin/php', "/opt/homebrew/opt/php@$v/bin/php" ) as $p ) {
			if ( is_executable( $p ) ) return $p;
		}
		return '';
	}
}

if ( ! function_exists( 'tt_me_start' ) ) {
	/**
	 * Hand a queued job to the worker, in the background (the shell ignores the hang-up, every stream
	 * redirected: no nohup, which cannot detach under Apache). Returns '' or why not.
	 */
	function tt_me_start( array $job ) {
		global $ttroot, $sharedfolder;
		$php = tt_me_php_cli();
		if ( $php === '' ) return 'no PHP command line found to run the changes with (set flexicorp/php_cli in the settings)';
		$file = realpath( tt_me_job_file( $job['id'] ) );
		$log = realpath( tt_me_dir( 'jobs' ) ) . "/{$job['id']}.log";
		$args = array( __DIR__ . '/multiedit_worker.php', '--job', $file, '--project', realpath( '.' ), '--ttroot', realpath( $ttroot ) );
		if ( ! empty( $sharedfolder ) && realpath( $sharedfolder ) ) array_push( $args, '--shared', realpath( $sharedfolder ) );
		$cmd = escapeshellarg( $php ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) );
		$inner = "trap '' HUP; nice -n 10 $cmd > " . escapeshellarg( $log ) . ' 2>&1';
		shell_exec( 'sh -c ' . escapeshellarg( $inner ) . ' > /dev/null 2>&1 < /dev/null &' );
		return '';
	}
}
?>
