<?php
/**
 * flexicorp → FQS catalogue over HTTP: whether a corpus is registered in the FQS this project
 * queries, and with which engine. FQS normally runs on another machine than TEITOK (or KonText),
 * so its catalogue file is not here to look in; FQS's own `GET /corpora` is (q= narrows it down;
 * the id must match exactly). The local `fqs corpora show` lookup (flexicorp.php's probe) stays for
 * FQS on the same machine.
 *
 * A project can also be a search-only view of a corpus that only FQS has (no local index): its
 * engine is then available because FQS serves it (tt_flexicorp_fqs_serves_engine), and the
 * settings `<flexicorp fqs_url="…" fqs_corpus_id="…"/>` say which FQS and which corpus.
 */

if ( ! function_exists( 'tt_flexicorp_fqs_http_corpus_row' ) ) {
	/** The catalogue entry of `$corpusId` in the FQS at the probe's URL, or null (signed as admin when there is a secret). */
	function tt_flexicorp_fqs_http_corpus_row( $probe, $corpusId ) {
		static $memo = array();
		$url = rtrim( (string) ( $probe['http_url'] ?? '' ), '/' );
		$corpusId = trim( (string) $corpusId );
		if ( $url === '' || $corpusId === '' || empty( $probe['http_running'] ) ) return null;
		$key = $url . '|' . $corpusId;
		if ( array_key_exists( $key, $memo ) ) return $memo[ $key ];
		// kept between requests (fc_shortcache.php), in the project the page runs in
		$root = function_exists( 'tt_fc_env_project_root' ) ? tt_fc_env_project_root() : (string) getcwd();
		$name = 'fqs-row-' . $corpusId;
		if ( function_exists( 'tt_fc_cache_get' ) ) {
			$kept = tt_fc_cache_get( $root, $name, tt_fc_cache_ttl( 30 ) );
			if ( is_array( $kept ) ) return $memo[ $key ] = ( $kept['row'] ?? null );
		}
		$headers = function_exists( 'tt_flexicorp_fqs_headers_with_optional_jwt' )
			? tt_flexicorp_fqs_headers_with_optional_jwt( $probe, 'admin' )
			: array( 'Accept: application/json' );
		$resp = tt_flexicorp_http_json_request( 'GET',
			$url . '/corpora?request_role=admin&include_noncurrent=true&q=' . rawurlencode( $corpusId ), null, $headers, 3.0 );
		$rows = is_array( $resp['data']['corpora'] ?? null ) ? $resp['data']['corpora'] : array();
		$found = null;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $corpusId ) { $found = $row; break; }
		}
		// an answer (with or without the corpus) is kept; no answer is not
		if ( isset( $resp['data']['corpora'] ) && function_exists( 'tt_fc_cache_put' ) ) tt_fc_cache_put( $root, $name, array( 'row' => $found ) );
		return $memo[ $key ] = $found;
	}

	/** The engine FQS answers a catalogue entry with: pando, cqp or manatee (preferred_backend, else the first available one). */
	function tt_flexicorp_fqs_row_engine( $row ) {
		if ( ! is_array( $row ) ) return '';
		$pb = strtolower( trim( (string) ( $row['preferred_backend'] ?? '' ) ) );
		if ( $pb === 'pando' || $pb === 'cqp' || $pb === 'manatee' ) return $pb;
		$avail = $row['settings']['available_backends'] ?? array();
		foreach ( is_array( $avail ) ? $avail : array() as $b ) {
			$b = strtolower( trim( (string) $b ) );
			if ( $b === 'pando' || $b === 'cqp' || $b === 'manatee' ) return $b;
		}
		$kind = (string) ( $row['source_kind'] ?? '' );
		if ( strpos( $kind, 'manatee' ) !== false ) return 'manatee';
		return strpos( $kind, 'pando' ) !== false ? 'pando' : ( strpos( $kind, 'cqp' ) !== false || strpos( $kind, 'cwb' ) !== false ? 'cqp' : '' );
	}

	/**
	 * Every engine FQS can query this catalogue row with: its preferred one first, then the others
	 * in `settings.available_backends` (a TEITOK project with pando and CWB: pando, cqp).
	 */
	function tt_flexicorp_fqs_row_engines( $row ) {
		$out = array();
		$first = tt_flexicorp_fqs_row_engine( $row );
		if ( $first !== '' ) $out[] = $first;
		$avail = is_array( $row ) ? ( $row['settings']['available_backends'] ?? array() ) : array();
		foreach ( is_array( $avail ) ? $avail : array() as $b ) {
			$b = strtolower( trim( (string) $b ) );
			if ( in_array( $b, array( 'pando', 'cqp', 'manatee' ), true ) && ! in_array( $b, $out, true ) ) $out[] = $b;
		}
		return $out;
	}

	/** The corpus id this project asks FQS for: flexicorp/fqs_corpus_id, else from the project folder. */
	function tt_flexicorp_fqs_corpus_id_for( $projectRoot ) {
		$set = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_corpus_id', '' ) ) : '';
		return $set !== '' ? $set : tt_flexicorp_corpus_id_from_root( $projectRoot );
	}

	/**
	 * Does FQS give this corpus's hits their real TEITOK XML (from its xidx, `xml_fragments` in the
	 * catalogue)? Then a project without XML files of its own need not ask for synthetic fragments.
	 * An FQS whose catalogue doesn't say so is asked as before.
	 */
	function tt_flexicorp_fqs_xml_fragments( $probe ) {
		if ( ! is_array( $probe ) || empty( $probe['ready_for_queries'] ) ) return false;
		$row = tt_flexicorp_fqs_http_corpus_row( $probe, $probe['corpus_id'] ?? '' );
		return is_array( $row ) && ! empty( $row['xml_fragments'] );
	}

	/**
	 * Where this corpus's documents are shown, when not here: a project without XML files of its own
	 * (a search-only project, a portal) whose corpus is a TEITOK project elsewhere links its hits to
	 * that project's document view, the `project_url` of the corpus in the FQS catalogue (B5). The
	 * TEITOK base ("…/iswoc", without index.php), or '' (none, not a TEITOK corpus, or this project).
	 */
	function tt_flexicorp_remote_doc_base( $projectRoot ) {
		if ( function_exists( 'tt_flexicorp_project_has_xml' ) && tt_flexicorp_project_has_xml( $projectRoot ) ) return '';
		if ( ! function_exists( 'tt_flexicorp_fqs_probe' ) ) return '';
		$probe = tt_flexicorp_fqs_probe( $projectRoot, false );
		if ( ! is_array( $probe ) || empty( $probe['ready_for_queries'] ) ) return '';
		$row = tt_flexicorp_fqs_http_corpus_row( $probe, $probe['corpus_id'] ?? '' );
		if ( ! is_array( $row ) || strpos( (string) ( $row['source_kind'] ?? '' ), 'teitok' ) !== 0 ) return '';
		return tt_flexicorp_teitok_base( (string) ( $row['project_url'] ?? '' ), (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
	}

	/**
	 * A TEITOK project's base URL from a catalogue `project_url` (with or without index.php, a query or a
	 * trailing slash; absolute, or a path on this server): '' when empty, not http(s), or this project
	 * itself (`$here`, the request's path).
	 */
	function tt_flexicorp_teitok_base( $projectUrl, $here = '' ) {
		$u = trim( (string) $projectUrl );
		if ( $u === '' ) return '';
		$u = preg_replace( '/[?#].*$/s', '', $u );
		$u = preg_replace( '#/index\.php$#', '', rtrim( $u, '/' ) );
		$u = rtrim( $u, '/' );
		if ( $u === '' || ! preg_match( '#^(https?://[^/\s]+)?/#i', $u ) ) return '';
		$path = preg_replace( '#^https?://[^/]+#i', '', $u );
		$herePath = rtrim( preg_replace( '#/index\.php$#', '', (string) parse_url( (string) $here, PHP_URL_PATH ) ), '/' );
		if ( $herePath !== '' && $path === $herePath ) return '';
		return $u;
	}

	/**
	 * Does FQS serve this project's corpus with `$engine` (pando / cqp / manatee) without a local index?
	 * Any engine of the row counts, not only the preferred one: a project with pando and CWB in FQS
	 * gets pando by default and CWB as a choice in the settings.
	 */
	function tt_flexicorp_fqs_serves_engine( $projectRoot, $engine ) {
		static $engines = array();
		if ( ! function_exists( 'tt_flexicorp_fqs_probe' ) ) return false;
		$probe = tt_flexicorp_fqs_probe( $projectRoot, false );
		if ( ! is_array( $probe ) || empty( $probe['ready_for_queries'] ) ) return false;
		// known from the HTTP lookup; else (found by the local fqs program) asked once
		$key = rtrim( (string) $projectRoot, '/' );
		if ( ! isset( $engines[ $key ] ) ) {
			$engines[ $key ] = is_array( $probe['remote_engines'] ?? null ) ? $probe['remote_engines'] : array();
			if ( empty( $engines[ $key ] ) ) $engines[ $key ] = tt_flexicorp_fqs_row_engines( tt_flexicorp_fqs_http_corpus_row( $probe, $probe['corpus_id'] ?? '' ) );
		}
		return in_array( $engine, $engines[ $key ], true );
	}
}
