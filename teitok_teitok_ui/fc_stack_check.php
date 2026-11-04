<?php
/**
 * fc_stack_check.php — the admin's check of what this project's queries run on (index.php?action=flexicorp
 * &act=stackcheck). Never part of a query: it reports, for the programs flexicorp runs (fc_bin.php), the path,
 * where the path came from and the build the program reports; for each kind of request the route it takes
 * (the local programs, or FQS); and, for a query typed in, the path pando takes and its time.
 *
 * The routes, as flexicorp has them:
 *   search (concordance,      flexicorp-pando, a process per request (hits and their XML fragments);
 *   `with … ::` pairs too)    pando only when there is no flexicorp-pando (pairs without fragments)
 *   programs (statistics,     FQS when it lists the corpus, else pando, a process per request
 *   frequencies, documents)
 */

require_once __DIR__ . '/fc_teitok_env.php';
require_once __DIR__ . '/fc_pando_run.php';

if ( ! function_exists( 'tt_fc_stack_check' ) ) {
	/** The report: array( 'programs' => …, 'fqs' => …, 'routes' => …, 'query' => … ). */
	function tt_fc_stack_check( $root, $query = '' ) {
		$out = array( 'programs' => array(), 'routes' => array() );
		foreach ( array( 'flexicorp-pando', 'pando', 'pando-index' ) as $name ) {
			list( $path, $source ) = tt_fc_bin_path( $name );
			$p = array( 'name' => $name, 'path' => $path, 'source' => $source, 'problem' => '' );
			if ( ! is_file( $path ) ) $p['problem'] = 'no file there';
			elseif ( ! is_executable( $path ) ) $p['problem'] = 'not executable';
			elseif ( ! tt_fc_env_runs_here( $path ) ) $p['problem'] = 'built for another system';
			else {
				$p['built'] = gmdate( 'Y-m-d H:i', (int) filemtime( $path ) ) . ' UTC';
				$v = trim( (string) shell_exec( escapeshellarg( $path ) . ' --version 2>&1' ) );
				$j = json_decode( $v, true );
				if ( is_array( $j ) ) {   // flexicorp-pando: the adapter's build and the pando it links
					$p['version'] = 'pando ' . ( $j['build_string'] ?? '?' ) . '; adapter ' . ( $j['adapter']['build'] ?? 'older than --version' );
					$p['pando_commit'] = (string) ( $j['commit'] ?? '' );
				} else {
					$p['version'] = $v !== '' ? strtok( $v, "\n" ) : '(no --version)';
					if ( preg_match( '/-g([0-9a-f]{7,})/', $v, $m ) ) $p['pando_commit'] = $m[1];
				}
				if ( strpos( (string) $p['version'], '-dirty' ) !== false )
					$p['note'] = 'built from a checkout with uncommitted changes';
			}
			$out['programs'][ $name ] = $p;
		}
		// the same pando in every program?
		$commits = array();
		foreach ( $out['programs'] as $p ) if ( ! empty( $p['pando_commit'] ) ) $commits[ $p['name'] ] = $p['pando_commit'];
		$short = array_unique( array_map( function ( $c ) { return substr( $c, 0, 7 ); }, $commits ) );
		$out['same_pando'] = count( $short ) <= 1;

		$index = tt_fc_env_pando_index_dir( $root );
		$out['index'] = array( 'path' => $index, 'ok' => is_file( "$index/corpus.info" ),
			'xidx' => is_dir( dirname( $index ) . '/xidx' ) || is_dir( rtrim( $root, '/' ) . '/xidx' ) );
		$route = tt_fc_pando_route( $root );
		list( $fqsUrl, $fqsFrom ) = tt_fc_fqs_url_info();
		$out['fqs'] = array( 'url' => $fqsUrl, 'from' => $fqsFrom, 'corpus' => tt_fc_fqs_corpus_id( $root ),
			'serves' => $route['via'] === 'fqs' );
		$fp = $out['programs']['flexicorp-pando'];
		$pp = $out['programs']['pando'];
		$out['routes'] = array(
			array( 'what' => 'search (concordance)', 'via' => 'flexicorp-pando, a process per request',
				'ok' => $fp['problem'] === '' && $out['index']['ok'] ),
			array( 'what' => '`with … ::` searches (pairs)',
				'via' => $fp['problem'] === '' ? 'flexicorp-pando, a process per request'
					: 'pando, a process per request (no flexicorp-pando: pairs without XML fragments)',
				'ok' => ( $fp['problem'] === '' || $pp['problem'] === '' ) && $out['index']['ok'] ),
			array( 'what' => 'statistics, frequencies, documents',
				'via' => $route['via'] === 'fqs' ? 'FQS at ' . $route['url'] . ' (corpus ' . $route['corpus'] . ')'
					: ( $route['via'] === 'local' ? 'pando, a process per request' : 'none: ' . $route['why'] ),
				'ok' => $route['via'] !== '' ),
		);
		// a query: pando's path and time for its first page (no total), run by the search's program
		if ( trim( (string) $query ) !== '' && $pp['problem'] === '' && $out['index']['ok'] ) {
			$t0 = microtime( true );
			$o = (string) shell_exec( escapeshellarg( $pp['path'] ) . ' --timing --limit 20 ' . escapeshellarg( $index ) . ' '
				. escapeshellarg( (string) $query ) . ' 2>&1' );
			$q = array( 'query' => (string) $query, 'wall_ms' => round( ( microtime( true ) - $t0 ) * 1000 ) );
			if ( preg_match( '/path=(\S+)/', $o, $m ) ) $q['path'] = $m[1];
			if ( preg_match( '/query_sec=([0-9.]+)/', $o, $m ) ) $q['query_ms'] = round( (float) $m[1] * 1000, 1 );
			if ( preg_match( '/^(?:Query error|Error): .*$/m', $o, $m ) ) $q['error'] = $m[0];
			$out['query'] = $q;
		}
		return $out;
	}
}

if ( ! function_exists( 'tt_fc_stack_check_page' ) ) {
	/** The report as a TEITOK page (admins only). */
	function tt_fc_stack_check_page( $root, $query = '' ) {
		$h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
		if ( ! tt_fc_env_is_admin() ) return '<p>The stack check is for admins.</p>';
		$r = tt_fc_stack_check( $root, $query );
		$mark = function ( $ok ) { return $ok ? '<span style="color:#2a7a2a">✓</span>' : '<span style="color:#b03030">✗</span>'; };
		$o = '<h1>Stack check</h1><p>What this project\'s queries run on. Paths come from the settings named; '
			. 'nothing is searched for when a query runs.</p>';
		$o .= '<h2>Programs</h2><table class="rollovertable"><tr><th></th><th>program</th><th>path</th><th>from</th>'
			. '<th>build</th><th>file date</th></tr>';
		foreach ( $r['programs'] as $p ) {
			$o .= '<tr><td>' . $mark( $p['problem'] === '' ) . '</td><td>' . $h( $p['name'] ) . '</td><td><code>' . $h( $p['path'] )
				. '</code></td><td>' . $h( $p['source'] ) . '</td><td>' . $h( $p['problem'] !== '' ? $p['problem'] : $p['version'] )
				. ( ! empty( $p['note'] ) ? '<br><i>' . $h( $p['note'] ) . '</i>' : '' ) . '</td><td>' . $h( $p['built'] ?? '' ) . '</td></tr>';
		}
		$o .= '</table>';
		if ( ! $r['same_pando'] ) $o .= '<p>' . $mark( false ) . ' The programs are built from different pando commits.</p>';
		$o .= '<h2>Index</h2><p>' . $mark( $r['index']['ok'] ) . ' <code>' . $h( $r['index']['path'] ) . '</code>'
			. ( $r['index']['xidx'] ? ', with xidx (XML fragments)' : ', no xidx: hits without XML fragments' ) . '</p>';
		$o .= '<h2>Routes</h2><table class="rollovertable"><tr><th></th><th>request</th><th>runs on</th></tr>';
		foreach ( $r['routes'] as $x )
			$o .= '<tr><td>' . $mark( $x['ok'] ) . '</td><td>' . $h( $x['what'] ) . '</td><td>' . $h( $x['via'] ) . '</td></tr>';
		$o .= '</table><p>FQS: ' . $h( $r['fqs']['url'] ) . ' (from ' . $h( $r['fqs']['from'] ) . '), corpus ' . $h( $r['fqs']['corpus'] ) . ': '
			. ( $r['fqs']['serves'] ? 'serves it' : 'does not serve it (or does not answer)' ) . '.</p>';
		$o .= '<h2>A query</h2><form method="get"><input type="hidden" name="action" value="flexicorp">'
			. '<input type="hidden" name="act" value="stackcheck"><input name="check_query" size="80" value="' . $h( $query ) . '">'
			. ' <input type="submit" value="Check"></form>';
		if ( ! empty( $r['query'] ) ) {
			$q = $r['query'];
			$o .= '<p>' . ( isset( $q['error'] ) ? $mark( false ) . ' ' . $h( $q['error'] )
				: 'pando path <code>' . $h( $q['path'] ?? '?' ) . '</code>, first page ' . $h( $q['query_ms'] ?? '?' ) . ' ms in pando, '
					. $h( $q['wall_ms'] ) . ' ms with the process' ) . '</p>';
		}
		return $o;
	}
}
