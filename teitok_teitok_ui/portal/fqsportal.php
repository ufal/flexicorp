<?php
/**
 * fqsportal.php — one TEITOK project that searches many corpora served by FQS.
 *
 * Library for Sources/startup.php (the settings overlay) and Sources/corpora.php (the
 * corpus picker); prints nothing. Installed into the portal project only, never into the
 * shared project (install-portal.pl; README.md in this folder). TEITOK reads the project's Resources/settings.xml, runs
 * Sources/startup.php, and only then fills in the shared settings (common/Sources/
 * settings.php). The overlay replaces, for this request only, what flexicorp reads per
 * corpus — flexicorp/fqs_corpus_id, cqp/pattributes, cqp/sattributes, the default engine,
 * the title — with a fragment built from FQS. flexicorp itself sees an ordinary
 * single-corpus project without XML files.
 *
 * The fragment comes from FQS's /info for the corpus (every engine: Pando from its index, CWB
 * and Manatee from their registry) and otherwise from the catalogue row (settings.corpus_info,
 * written by the catalogue importer from the Manatee registry).
 * It is cached in tmp/fqsportal/ until the row's updated_at changes.
 *
 * Settings (the portal's Resources/settings.xml):
 *   <fqsportal param="corpus" tag="portal:lindat" sources="non-teitok" environment="live"
 *              cache_ttl="300" needs="flexicorp,fqs_query,advanced_">
 *     <corpora><item key="syn2015"/>…</corpora>     optional: exactly these ids
 *   </fqsportal>
 *   tag       only catalogue rows with this label (FQS /corpora?tag=)
 *   sources   non-teitok (default: rows that are not TEITOK projects) | all
 *   needs     actions that need a corpus: without one they go to the picker
 * FQS address and secret as for flexicorp: flexicorp/fqs_url | FQS_URL, and
 * flexicorp/fqs_secret | FQS_SECRET | FQS's env file (flexicorp/fqs_env_file,
 * FQS_ENV_FILE, /etc/fqs/fqs.env).
 */

if ( ! function_exists( 'fqp_conf' ) ) {

	/** A fqsportal/… setting (attribute or child), else $default. */
	function fqp_conf( $key, $default = '' ) {
		$v = function_exists( 'getset' ) ? getset( 'fqsportal/' . $key, '' ) : '';
		if ( is_array( $v ) ) return $v;
		$v = trim( (string) $v );
		return $v !== '' ? $v : $default;
	}

	function fqp_enabled() {
		return function_exists( 'getset' ) && is_array( getset( 'fqsportal', null ) );
	}

	function fqp_fqs_url() {
		$u = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_url', '' ) ) : '';
		if ( $u === '' ) $u = trim( (string) getenv( 'FQS_URL' ) );
		return rtrim( $u !== '' ? $u : 'http://127.0.0.1:8787', '/' );
	}

	/** FQS_SECRET as flexicorp finds it (fqs_query.php): FQS's env file first (flexicorp/fqs_env_file,
	 *  FQS_ENV_FILE, else /etc/fqs/fqs.env), then flexicorp/fqs_secret, then FQS_SECRET; '' when none. */
	function fqp_secret() {
		static $memo = null;
		if ( $memo !== null ) return $memo;
		$s = '';
		$path = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/fqs_env_file', '' ) ) : '';
		if ( $path === '' ) $path = trim( (string) getenv( 'FQS_ENV_FILE' ) );
		if ( $path === '' ) $path = '/etc/fqs/fqs.env';
		$txt = @is_readable( $path ) ? @file_get_contents( $path ) : false;
		// the last assignment wins, as for systemd's EnvironmentFile
		if ( is_string( $txt ) && preg_match_all( '/^\s*(?:export\s+)?FQS_SECRET\s*=\s*["\']?([^"\'\s#]+)/m', $txt, $m ) ) {
			$s = (string) end( $m[1] );
		}
		if ( $s === '' && function_exists( 'getset' ) ) $s = trim( (string) getset( 'flexicorp/fqs_secret', '' ) );
		if ( $s === '' ) $s = trim( (string) getenv( 'FQS_SECRET' ) );
		return $memo = $s;
	}

	function fqp_b64url( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	/** HS256 token as flexicorp signs it (fqs_query.php); '' without a secret. */
	function fqp_token( $role, $user = '', $sub = '' ) {
		$secret = fqp_secret();
		if ( $secret === '' ) return '';
		$now = time();
		$h = fqp_b64url( '{"alg":"HS256","typ":"JWT"}' );
		$p = fqp_b64url( json_encode( array(
			'iss' => 'teitok-fqsportal', 'sub' => $sub, 'role' => $role, 'user' => $user,
			'iat' => $now, 'exp' => $now + 120,
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		return $h . '.' . $p . '.' . fqp_b64url( hash_hmac( 'sha256', $h . '.' . $p, $secret, true ) );
	}

	/** GET $path at FQS as $role → [decoded JSON or null, error]. */
	function fqp_get( $path, $role = 'admin', $sub = '' ) {
		$url = fqp_fqs_url() . $path;
		$headers = array( 'Accept: application/json' );
		$tok = fqp_token( $role, 'fqsportal', $sub );
		if ( $tok !== '' ) $headers[] = 'Authorization: Bearer ' . $tok;
		// without a secret FQS believes the role hint (local set-ups)
		$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'request_role=' . rawurlencode( $role );
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, array(
				CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
				CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 8000,
			) );
			$body = curl_exec( $ch );
			$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$err = $body === false ? (string) curl_error( $ch ) : '';
			curl_close( $ch );
		} else {
			$ctx = stream_context_create( array( 'http' => array(
				'method' => 'GET', 'header' => implode( "\r\n", $headers ), 'ignore_errors' => true, 'timeout' => 8,
			) ) );
			$body = @file_get_contents( $url, false, $ctx );
			$status = 0;
			if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) $status = (int) $m[1];
			$err = $body === false ? 'no answer' : '';
		}
		if ( $body === false || $body === '' ) return array( null, "FQS at " . fqp_fqs_url() . ": " . ( $err !== '' ? $err : 'empty answer' ) );
		$j = json_decode( (string) $body, true );
		if ( $status >= 400 || ! is_array( $j ) ) {
			return array( null, "FQS $path: HTTP $status " . substr( trim( (string) $body ), 0, 200 ) );
		}
		return array( $j, '' );
	}

	/** tmp/fqsportal/ of this project ('' when it cannot be made). */
	function fqp_cache_dir() {
		$d = getcwd() . '/tmp/fqsportal';
		if ( ! is_dir( $d ) ) @mkdir( $d, 0775, true );
		return is_dir( $d ) && is_writable( $d ) ? $d : '';
	}

	function fqp_cache_write( $file, $data ) {
		$tmp = $file . '.' . getmypid();
		if ( @file_put_contents( $tmp, $data ) !== false ) @rename( $tmp, $file );
	}

	/** A comma-separated <fqsportal> option as a lower-case list ('' → empty). */
	function fqp_conf_list( $key ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) fqp_conf( $key ) ) ) ) ) );
	}

	/** The portals a row is meant for: settings.portals (a list, or one name). Unlike labels,
	 *  these don't show up in FQS's label browser or anyone's facets. */
	function fqp_row_portals( $row ) {
		$p = $row['settings']['portals'] ?? array();
		return array_map( 'strval', is_array( $p ) ? $p : array( $p ) );
	}

	/** Is a catalogue row a TEITOK project (it has its own TEITOK search then)? Not by
	 *  interface_preference: FQS sets that to teitok for any row inside a TEITOK folder. */
	function fqp_is_teitok_row( $row ) {
		return strpos( strtolower( (string) ( $row['source_kind'] ?? '' ) ), 'teitok' ) === 0;
	}

	/**
	 * The portal's corpora: id => catalogue row. Read as admin (every row the selection names,
	 * whoever asks), so the overlay can check an id; FQS still checks each query's role.
	 * Cached for fqsportal/cache_ttl seconds; $error tells why the list is empty.
	 */
	function fqp_catalogue( $fresh = false, &$error = null ) {
		static $memo = null;
		$error = '';
		if ( $memo !== null && ! $fresh ) return $memo;
		$ttl = (int) fqp_conf( 'cache_ttl', '300' );
		$dir = fqp_cache_dir();
		$file = $dir !== '' ? $dir . '/catalogue.json' : '';
		if ( ! $fresh && $file !== '' && is_file( $file ) && time() - filemtime( $file ) < $ttl ) {
			$j = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $j ) ) return $memo = $j;
		}
		// the whole catalogue: a server has few enough corpora to select from here
		$q = array();
		if ( ( $e = fqp_conf( 'environment' ) ) !== '' ) $q['environment'] = $e;
		list( $j, $err ) = fqp_get( '/corpora' . ( $q ? '?' . http_build_query( $q ) : '' ) );
		if ( ! is_array( $j ) || ! isset( $j['corpora'] ) || ! is_array( $j['corpora'] ) ) {
			$error = $err !== '' ? $err : 'FQS /corpora: no corpus list';
			// an old list beats none while FQS is away
			if ( $file !== '' && is_file( $file ) ) {
				$old = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $old ) ) return $memo = $old;
			}
			return array();
		}
		$only = fqp_conf( 'corpora', array() );
		$only = is_array( $only ) ? array_map( 'strval', array_keys( $only ) ) : array();
		$all = strtolower( fqp_conf( 'sources', 'non-teitok' ) ) === 'all';
		$tags = fqp_conf_list( 'tag' );
		$engines = fqp_conf_list( 'engines' );
		$portal = fqp_conf( 'portal' );
		$out = array();
		foreach ( $j['corpora'] as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( $id === '' ) continue;
			if ( $only ) {
				if ( ! in_array( $id, $only, true ) ) continue;
			} else {
				if ( ! $all && fqp_is_teitok_row( $row ) ) continue;
				if ( $tags && ! array_intersect( $tags, array_map( 'strtolower', array_map( 'strval', (array) ( $row['labels'] ?? array() ) ) ) ) ) continue;
				if ( $engines && ! in_array( fqp_engine( $row ), $engines, true ) ) continue;
				if ( $portal !== '' && ! in_array( $portal, fqp_row_portals( $row ), true ) ) continue;
			}
			if ( strtolower( (string) ( $row['http_policy_mode'] ?? '' ) ) === 'disabled' ) continue;
			$out[ $id ] = $row;
		}
		if ( $file !== '' ) fqp_cache_write( $file, json_encode( $out ) );
		return $memo = $out;
	}

	/**
	 * Does the picker offer this row to $role? FQS's rule (public_query for all, auth_required
	 * admin only), and the KonText access groups the importer kept in settings.access: a corpus
	 * not open to "anonymous" is not offered to visitors. That is the picker only — FQS has no
	 * groups, so a visitor who knows the id still gets answers (see README).
	 */
	function fqp_role_may( $row, $role ) {
		$mode = strtolower( (string) ( $row['http_policy_mode'] ?? 'public_query' ) );
		if ( $mode === 'disabled' ) return false;
		if ( $mode === 'auth_required' ) return $role === 'admin';
		$access = $row['settings']['access'] ?? null;
		if ( is_array( $access ) && ! in_array( 'anonymous', $access, true ) ) return $role !== 'visitor';
		return true;
	}

	// ── corpus description: FQS /info, else the catalogue row ─────────────────────────

	/**
	 * The corpus's attributes in one shape, whatever the source:
	 *   ['attributes' => [['name','label'],…], 'structures' => [['name','label','attrs' => [['name','label'],…]],…],
	 *    'size' => int|null, 'source' => 'fqs-info'|'catalogue'|'minimal', 'note' => '…']
	 * /info answers as Pando does (result.attributes[].name, result.structures[].attrs or
	 * .region_attrs[].name); labels are taken when present (`label`).
	 */
	function fqp_describe( $row ) {
		$id = (string) $row['id'];
		list( $j, $err ) = fqp_get( '/info?corpus=' . rawurlencode( $id ), 'admin', $id );
		$r = is_array( $j ) ? ( isset( $j['result'] ) && is_array( $j['result'] ) ? $j['result'] : $j ) : null;
		if ( is_array( $r ) && ! empty( $r['attributes'] ) ) {
			$d = fqp_describe_info( $r );
			$d['source'] = 'fqs-info';
			return $d;
		}
		$s = isset( $row['settings'] ) && is_array( $row['settings'] ) ? $row['settings'] : array();
		if ( isset( $s['corpus_info'] ) && is_array( $s['corpus_info'] ) && ! empty( $s['corpus_info']['attributes'] ) ) {
			$d = fqp_describe_info( $s['corpus_info'] );
			$d['source'] = 'catalogue';
			$d['note'] = '/info: ' . $err;
			return $d;
		}
		// rows from before corpus_info: names only
		if ( ! empty( $s['manatee_attributes'] ) && is_array( $s['manatee_attributes'] ) ) {
			$st = array();
			foreach ( (array) ( $s['manatee_structures'] ?? array() ) as $n => $as ) {
				$st[] = array( 'name' => (string) $n, 'attrs' => array_values( (array) $as ) );
			}
			$d = fqp_describe_info( array( 'attributes' => $s['manatee_attributes'], 'structures' => $st ) );
			$d['source'] = 'catalogue';
			$d['note'] = '/info: ' . $err;
			return $d;
		}
		return array(
			'attributes' => array( array( 'name' => 'word', 'label' => '' ) ), 'structures' => array(),
			'size' => isset( $row['corpus_size'] ) ? (int) $row['corpus_size'] : null,
			'source' => 'minimal', 'note' => '/info: ' . $err . '; no corpus_info in the catalogue row',
		);
	}

	/** Pando-style info (or corpus_info) → the shape of fqp_describe. */
	function fqp_describe_info( $r ) {
		$name = function ( $x ) { return is_array( $x ) ? (string) ( $x['name'] ?? '' ) : (string) $x; };
		$label = function ( $x ) { return is_array( $x ) ? (string) ( $x['label'] ?? '' ) : ''; };
		$attrs = array();
		foreach ( (array) ( $r['attributes'] ?? array() ) as $a ) {
			$n = $name( $a );
			if ( $n !== '' ) $attrs[] = array( 'name' => $n, 'label' => $label( $a ) );
		}
		$structs = array();
		foreach ( (array) ( $r['structures'] ?? array() ) as $st ) {
			$sn = $name( $st );
			if ( $sn === '' ) continue;
			$list = array();
			$src = is_array( $st ) && ! empty( $st['region_attrs'] ) ? $st['region_attrs'] : ( is_array( $st ) ? ( $st['attrs'] ?? array() ) : array() );
			foreach ( (array) $src as $a ) {
				$an = $name( $a );
				// Pando may name a region attribute in full (text_id): keep the part after the structure
				if ( strpos( $an, $sn . '_' ) === 0 ) $an = substr( $an, strlen( $sn ) + 1 );
				if ( $an !== '' ) $list[] = array( 'name' => $an, 'label' => $label( $a ) );
			}
			$structs[] = array( 'name' => $sn, 'label' => $label( $st ), 'attrs' => $list );
		}
		$size = $r['size'] ?? ( $r['tokens'] ?? null );
		return array( 'attributes' => $attrs, 'structures' => $structs, 'size' => $size === null ? null : (int) $size, 'note' => '' );
	}

	// ── the settings fragment ──────────────────────────────────────────────────────

	function fqp_x( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/** A readable label for an attribute without one (word → Word, lemma_lc → Lemma lc). */
	function fqp_pretty( $name ) {
		static $known = array(
			'word' => 'Word', 'form' => 'Form', 'lc' => 'Word (lowercase)', 'lemma' => 'Lemma',
			'lemma_lc' => 'Lemma (lowercase)', 'tag' => 'Tag', 'pos' => 'Part of speech', 'upos' => 'Universal POS',
			'xpos' => 'Treebank POS', 'feats' => 'Morphological features', 'deprel' => 'Dependency relation',
			'doc' => 'Document', 'text' => 'Text', 's' => 'Sentence', 'p' => 'Paragraph', 'id' => 'ID',
		);
		$n = (string) $name;
		return $known[ $n ] ?? ucfirst( str_replace( '_', ' ', $n ) );
	}

	/** The engine flexicorp should preselect for this row. */
	function fqp_engine( $row ) {
		$pb = strtolower( (string) ( $row['preferred_backend'] ?? '' ) );
		if ( in_array( $pb, array( 'pando', 'cqp', 'manatee' ), true ) ) return $pb;
		$s = isset( $row['settings'] ) && is_array( $row['settings'] ) ? $row['settings'] : array();
		$qb = strtolower( (string) ( $s['query_backend'] ?? '' ) );
		if ( in_array( $qb, array( 'pando', 'cqp', 'manatee' ), true ) ) return $qb;
		foreach ( (array) ( $s['available_backends'] ?? array() ) as $b ) {
			$b = strtolower( (string) $b );
			if ( in_array( $b, array( 'pando', 'cqp', 'manatee' ), true ) ) return $b;
		}
		return 'pando';
	}

	/** The settings fragment (as settings.xml) for one catalogue row. */
	function fqp_fragment_xml( $row, $d ) {
		$id = (string) $row['id'];
		$label = trim( (string) ( $row['label'] ?? '' ) );
		if ( $label === '' ) $label = $id;
		$s = isset( $row['settings'] ) && is_array( $row['settings'] ) ? $row['settings'] : array();
		$engine = fqp_engine( $row );
		$intro = '';
		if ( ! empty( $s['description'] ) ) $intro .= '<p>' . htmlspecialchars( (string) $s['description'] ) . '</p>';
		$intro .= "<p><a href='index.php?action=corpora'>Choose another corpus</a></p>";

		// no attributes on the root: xmlflatten makes them top-level settings (fqsportal would be overwritten)
		$x = "<ttsettings>\n\t<!-- fqsportal: corpus " . str_replace( '--', '- -', $id ) . ", attributes from " . $d['source'] . " -->\n";
		// not defaults/title: the page keeps the portal's title; fqp_startup puts the corpus in the menu header
		$x .= "\t<defaults>\n\t\t<flexicorp backend=\"" . fqp_x( $engine ) . "\"/>\n\t</defaults>\n";
		$x .= "\t<flexicorp fqs_corpus_id=\"" . fqp_x( $id ) . "\" search_intro_html=\"" . fqp_x( $intro ) . "\"/>\n";
		// noshare: settings.php must not fill this corpus's attribute lists from the shared project
		$x .= "\t<cqp corpus=\"" . fqp_x( strtoupper( (string) ( $s['cqp_corpus'] ?? $s['kontext_corpname'] ?? $id ) ) ) . "\" noshare=\"1\">\n";
		$x .= "\t\t<pattributes>\n";
		foreach ( $d['attributes'] as $a ) {
			$x .= "\t\t\t<item key=\"" . fqp_x( $a['name'] ) . "\" display=\"" . fqp_x( $a['label'] !== '' ? $a['label'] : fqp_pretty( $a['name'] ) ) . "\"/>\n";
		}
		$x .= "\t\t</pattributes>\n\t\t<sattributes>\n";
		foreach ( $d['structures'] as $st ) {
			$sl = $st['label'] !== '' ? $st['label'] : fqp_pretty( $st['name'] );
			$x .= "\t\t\t<item key=\"" . fqp_x( $st['name'] ) . "\" level=\"" . fqp_x( $st['name'] ) . "\" display=\"" . fqp_x( $sl ) . "\">\n";
			foreach ( $st['attrs'] as $a ) {
				$x .= "\t\t\t\t<item key=\"" . fqp_x( $a['name'] ) . "\" display=\"" . fqp_x( $a['label'] !== '' ? $a['label'] : fqp_pretty( $a['name'] ) ) . "\"/>\n";
			}
			$x .= "\t\t\t</item>\n";
		}
		$x .= "\t\t</sattributes>\n\t</cqp>\n";
		// no TEITOK XML behind these corpora: no xmlfile attribute lists either
		$x .= "\t<xmlfile noshare=\"1\"/>\n";
		$x .= "</ttsettings>\n";
		return $x;
	}

	/**
	 * The fragment for corpus $id: cached in tmp/fqsportal/<id>.json while the row's
	 * updated_at is unchanged (a fallback fragment is rebuilt after cache_ttl, so /info is
	 * tried again). → ['xml', 'source', 'note', 'updated_at'] or null.
	 * Fragments cached by an older version of fqp_fragment_xml (another 'v') are rebuilt.
	 */
	function fqp_fragment( $id, $fresh = false ) {
		$version = 2;
		$cat = fqp_catalogue();
		if ( ! isset( $cat[ $id ] ) ) return null;
		$row = $cat[ $id ];
		$dir = fqp_cache_dir();
		$file = $dir !== '' ? $dir . '/' . preg_replace( '/[^A-Za-z0-9_.-]/', '_', $id ) . '.json' : '';
		$stamp = (string) ( $row['updated_at'] ?? '' );
		if ( ! $fresh && $file !== '' && is_file( $file ) ) {
			$c = json_decode( (string) file_get_contents( $file ), true );
			$young = time() - filemtime( $file ) < (int) fqp_conf( 'cache_ttl', '300' );
			if ( is_array( $c ) && ( $c['v'] ?? 0 ) === $version && ( $c['updated_at'] ?? null ) === $stamp && ( $c['source'] === 'fqs-info' || $young ) ) return $c;
		}
		$d = fqp_describe( $row );
		$c = array( 'v' => $version, 'xml' => fqp_fragment_xml( $row, $d ), 'source' => $d['source'], 'note' => (string) ( $d['note'] ?? '' ), 'updated_at' => $stamp );
		if ( $file !== '' ) fqp_cache_write( $file, json_encode( $c ) );
		return $c;
	}

	/** Overlay merge: arrays merge key by key, except cqp and xmlfile, which are replaced. */
	function fqp_merge( &$base, $over, $depth = 0 ) {
		if ( ! is_array( $base ) ) $base = array();
		foreach ( $over as $k => $v ) {
			if ( $depth === 0 && in_array( $k, array( 'cqp', 'xmlfile' ), true ) ) {
				$base[ $k ] = $v;
			} elseif ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
				fqp_merge( $base[ $k ], $v, $depth + 1 );
			} else {
				$base[ $k ] = $v;
			}
		}
	}

	/** The session slot of this project (TEITOK has one session for all its projects). */
	function &fqp_session() {
		if ( ! isset( $_SESSION['fqsportal'] ) || ! is_array( $_SESSION['fqsportal'] ) ) $_SESSION['fqsportal'] = array();
		$k = basename( getcwd() );
		if ( ! isset( $_SESSION['fqsportal'][ $k ] ) ) $_SESSION['fqsportal'][ $k ] = '';
		return $_SESSION['fqsportal'][ $k ];
	}

	/**
	 * Startup (Sources/startup.php): the corpus from ?corpus= (remembered in the session) or
	 * the session; overlay its fragment on $settings. An action that needs a corpus and has
	 * none goes to the picker. Sets $GLOBALS['fqsportal'] = ['corpus', 'source', 'note', 'error'].
	 */
	/**
	 * The corpus as the menu header (menu/title), linked to the picker; the page title stays the
	 * portal's own. A project whose menu has no header (title="none" or "[none]") keeps none.
	 */
	function fqp_menu_header( $id ) {
		global $settings;
		$mt = isset( $settings['menu']['title'] ) && is_string( $settings['menu']['title'] ) ? $settings['menu']['title'] : '';
		if ( $mt === 'none' || $mt === '[none]' ) return;
		$cat = fqp_catalogue();
		$label = trim( (string) ( $cat[ $id ]['label'] ?? '' ) );
		if ( ! isset( $settings['menu'] ) || ! is_array( $settings['menu'] ) ) $settings['menu'] = array();
		$settings['menu']['title'] = htmlspecialchars( $label !== '' ? $label : $id, ENT_QUOTES, 'UTF-8' );
		$settings['menu']['link'] = 'index.php?action=corpora';
	}

	function fqp_startup() {
		global $settings;
		$GLOBALS['fqsportal'] = array( 'corpus' => '', 'source' => '', 'note' => '', 'error' => '', 'detail' => '' );
		if ( ! fqp_enabled() ) return;
		$param = fqp_conf( 'param', 'corpus' );
		$sel = &fqp_session();
		$asked = isset( $_REQUEST[ $param ] ) ? trim( (string) $_REQUEST[ $param ] ) : '';
		$action = isset( $_GET['action'] ) ? (string) $_GET['action'] : '';
		if ( $asked !== '' && $action !== 'corpora' ) {
			$cat = fqp_catalogue( false, $err );
			if ( isset( $cat[ $asked ] ) ) {
				// another corpus: flexicorp's remembered query belongs to the old one
				if ( $sel !== $asked ) unset( $_SESSION['flexicorp_teitok'] );
				$sel = $asked;
			} else {
				// FQS's reason names its address: kept apart, for admins (corpora.php)
				$GLOBALS['fqsportal']['error'] = $err !== '' ? 'The list of corpora cannot be read right now.' : "No corpus '$asked' in this portal";
				$GLOBALS['fqsportal']['detail'] = $err;
			}
		}
		$id = $sel;
		if ( $id !== '' ) {
			$frag = fqp_fragment( $id );
			if ( $frag === null ) {
				$GLOBALS['fqsportal']['error'] = "Corpus '$id' is no longer in this portal";
				$sel = $id = '';
			} else {
				$xml = @simplexml_load_string( $frag['xml'] );
				if ( $xml && function_exists( 'xmlflatten' ) ) {
					fqp_merge( $settings, xmlflatten( $xml ) );
					fqp_menu_header( $id );
					$GLOBALS['fqsportal'] = array( 'corpus' => $id, 'source' => $frag['source'], 'note' => $frag['note'],
						'error' => $GLOBALS['fqsportal']['error'], 'detail' => $GLOBALS['fqsportal']['detail'] );
				}
			}
		}
		if ( $id === '' && $action !== '' ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', fqp_conf( 'needs', 'flexicorp,fqs_query,advanced_' ) ) ) ) as $p ) {
				if ( strpos( $action, $p ) !== 0 || PHP_SAPI === 'cli' ) continue;
				if ( $action === 'fqs_query' ) {   // a JSON endpoint: an answer, not the picker
					header( 'Content-Type: application/json', true, 409 );
					echo json_encode( array( 'ok' => false, 'status' => 409, 'error' => 'No corpus selected: open index.php?action=corpora' ) );
					exit;
				}
				header( 'Location: index.php?action=corpora' );
				exit;
			}
		}
	}
}
