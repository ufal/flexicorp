<?php

	global $maintext, $ttroot, $ecscripts;
	require_once("$ttroot/common/Sources/venv.php");
	require_once __DIR__ . '/flexicorp_search.php';
	require_once __DIR__ . '/flexicorp_freqs.php';
require_once __DIR__ . '/flexicorp_functions.php';

	if ( !isset($maintext) ) $maintext = "";

	if ( !function_exists('tt_flexicorp_h') ) {
		function tt_flexicorp_h($value) {
			return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
		}
	}

	// ─── TEMPORARY shims for getjsurl() / getUrlFromFilesystemPath() ──────────────────
	// Older TEITOK clones (and the Docker container build we're testing against) may not
	// expose getjsurl() in common/Sources/functions.php. Define our own guarded copies so
	// flexicorp.php can resolve asset URLs identically locally and in the container.
	// Remove once every deployment ships the canonical getjsurl() in TEITOK core.
	if ( !function_exists('getUrlFromFilesystemPath') ) {
		function getUrlFromFilesystemPath ( $filesystemPath ) {
			$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
			if ( $docRoot === '' || !file_exists($filesystemPath) ) return false;

			$sep         = DIRECTORY_SEPARATOR;
			$docRootReal = realpath($docRoot);
			$targetReal  = realpath($filesystemPath);
			if ( $docRootReal === false || $targetReal === false ) return false;

			// Direct case: file lives physically inside DOCUMENT_ROOT.
			if ( strpos($targetReal . $sep, $docRootReal . $sep) === 0 ) {
				$rel = substr($targetReal, strlen($docRootReal));
				return '/' . ltrim(str_replace($sep, '/', $rel), '/');
			}

			// Symlink case (typical macOS dev setup): DOCUMENT_ROOT contains symlinks
			// pointing into /Users/... Walk the doc root and rebuild the URL using the
			// symlink name we matched against, not the resolved real path.
			$dh = @opendir($docRoot);
			if ( $dh === false ) return false;
			while ( ($entry = readdir($dh)) !== false ) {
				if ( $entry === '.' || $entry === '..' ) continue;
				$entryPath = $docRoot . $sep . $entry;
				$entryReal = realpath($entryPath);
				if ( $entryReal === false ) continue;
				if ( strpos($targetReal . $sep, $entryReal . $sep) === 0 ) {
					$rel = substr($targetReal, strlen($entryReal));
					closedir($dh);
					$relUrl = $rel === '' ? '' : '/' . ltrim(str_replace($sep, '/', $rel), '/');
					return '/' . $entry . $relUrl;
				}
			}
			closedir($dh);
			return false;
		}
	}

	if ( !function_exists('getjsurl') ) {
		function getjsurl ( $jsname ) {
			global $sharedfolder, $jsurl;

			if ( file_exists("Scripts/$jsname.js") ) return "Scripts/$jsname.js";

			$sharedroot = function_exists('getset') ? getset('defaults/shared/url') : '';
			if ( $sharedroot && $sharedfolder && file_exists("$sharedfolder/Scripts/$jsname.js") ) {
				if ( substr($sharedroot, -8) === '/Scripts' ) return "$sharedroot/$jsname.js";
				return "$sharedroot/Scripts/$jsname.js";
			}
			if ( $sharedfolder && file_exists("$sharedfolder/Scripts/$jsname.js") ) {
				$derived = getUrlFromFilesystemPath("$sharedfolder/Scripts/$jsname.js");
				if ( $derived ) return $derived;
				// Filesystem→URL derivation failed (no symlink mapping). Fall through to
				// $jsurl rather than returning false (would emit <script src="">).
			}
			return rtrim((string) $jsurl, '/') . "/$jsname.js";
		}
	}
	// ─── end TEMPORARY shims ─────────────────────────────────────────────────────────

	if ( !function_exists('tt_flexicorp_project_root') ) {
		function tt_flexicorp_project_root() {
			$cwd = getcwd();
			if ( $cwd && is_dir($cwd) ) return $cwd;
			return '.';
		}
	}

	if ( !function_exists('tt_flexicorp_corpus_project_root' ) ) {
		/**
		 * TEITOK corpus directory for index paths (manatee/, cqp/, xidx/).
		 *
		 * tt_flexicorp_project_root() is only getcwd(), which is often the TEITOK install root
		 * or an unrelated server CWD — not the corpus folder. CQP status uses $foldername +
		 * getset; flexicorp CLI uses --project-root. Align PHP probes and Python --folder with
		 * the same corpus root.
		 */
		function tt_flexicorp_corpus_project_root() {
			global $foldername;
			$raw = '';
			if ( isset( $foldername ) && is_string( $foldername ) && trim( $foldername ) !== '' ) {
				$raw = trim( $foldername );
			} elseif ( function_exists( 'getset' ) ) {
				$raw = trim( (string) getset( 'defaults/base/foldername', '' ) );
			}
			if ( $raw === '' ) {
				return tt_flexicorp_project_root();
			}
			$raw = str_replace( '\\', '/', $raw );
			if ( isset( $raw[0] ) && $raw[0] === '/' ) {
				$rp = @realpath( $raw );
				return ( $rp && is_dir( $rp ) ) ? $rp : $raw;
			}
			$cwd = getcwd();
			if ( ! $cwd ) {
				return tt_flexicorp_project_root();
			}
			$cwd = str_replace( '\\', '/', $cwd );
			// If cwd is already the corpus folder (last segment equals foldername), do not append
			// foldername again — e.g. cwd .../teitok/infoveillance + foldername infoveillance would
			// wrongly become .../infoveillance/infoveillance and break manatee/xidx probes.
			if ( is_dir( $cwd ) ) {
				$base = basename( $cwd );
				if ( $base === $raw ) {
					$rp = @realpath( $cwd );
					return ( $rp && is_dir( $rp ) ) ? $rp : $cwd;
				}
			}
			$joined = rtrim( $cwd, '/' ) . '/' . $raw;
			$rp = @realpath( $joined );
			return ( $rp && is_dir( $rp ) ) ? $rp : $joined;
		}
	}

	if ( !function_exists('tt_flexicorp_append_scripts_segment') ) {
		/**
		 * Normalize a base URL into one that ends in "/Scripts" (trim + single-append).
		 */
		function tt_flexicorp_append_scripts_segment( $url ) {
			$u = rtrim(trim((string) $url), '/');
			if ( $u === '' ) return '';
			$looksLikeScriptsBase = (bool) preg_match('#/Scripts$#i', $u);
			return $looksLikeScriptsBase ? $u : ($u . '/Scripts');
		}
	}

	if ( !function_exists('tt_flexicorp_shared_scripts_url') ) {
		/**
		 * URL for $sharedfolder/Scripts — used when flexicorp.js lives in the TT_SHARED tree
		 * rather than the current project. $baseurl points at the project folder, so using it
		 * here yields URLs like /teitok/easycorp/infoveillance/Scripts/flexicorp.js that 404.
		 *
		 * Resolution order:
		 *   - $sharedurl global (TEITOK's defaults/base/sharedurl)
		 *   - getset('defaults/base/sharedurl')
		 *   - getset('defaults/shared/url')   (used by easycorp-style shared/url settings)
		 *   - Derive from realpath($sharedfolder) vs realpath(DOCUMENT_ROOT)
		 * Returns '' if no URL can be determined.
		 */
		function tt_flexicorp_shared_scripts_url() {
			global $sharedurl, $sharedfolder;

			$candidates = array();
			if ( isset($sharedurl) && is_string($sharedurl) && trim($sharedurl) !== '' ) {
				$candidates[] = $sharedurl;
			}
			if ( function_exists('getset') ) {
				foreach ( array('defaults/base/sharedurl', 'defaults/shared/url') as $key ) {
					$v = trim((string) getset($key, ''));
					if ( $v !== '' ) $candidates[] = $v;
				}
			}
			foreach ( $candidates as $v ) {
				$normalized = tt_flexicorp_append_scripts_segment($v);
				if ( $normalized !== '' ) return $normalized;
			}

			// Derive from filesystem path by subtracting the webserver document root.
			if ( !empty($sharedfolder) && !empty($_SERVER['DOCUMENT_ROOT']) ) {
				$sf = @realpath((string) $sharedfolder);
				$dr = @realpath((string) $_SERVER['DOCUMENT_ROOT']);
				if ( $sf && $dr && strpos($sf . DIRECTORY_SEPARATOR, $dr . DIRECTORY_SEPARATOR) === 0 ) {
					$rel = substr($sf, strlen($dr));
					$rel = '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $rel), '/');
					return tt_flexicorp_append_scripts_segment($rel);
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_ecscripts_base_url') ) {
		/**
		 * Base URL (without trailing slash, always ending in /Scripts) for flexicorp.css
		 * and flexicorp*.js assets. Prefers *local* Scripts/ trees in this order:
		 *   1. getset('flexicorp/ecscripts_base')
		 *   2. FLEXICORP_ECSCRIPTS environment variable
		 *   3. getjsurl('flexicorp') — as long as its answer is not the remote $jsurl fallback
		 *   4. Per-project Scripts/flexicorp.js (URL from $projecturl / $baseurl / relative)
		 *   5. $sharedfolder/Scripts/flexicorp.js (URL from tt_flexicorp_shared_scripts_url())
		 *   6. Legacy $ecscripts global
		 *   7. null — caller falls back to a relative "Scripts" path.
		 *
		 * We deliberately never return a URL derived from $jsurl directly: older TEITOK cores
		 * make getjsurl() silently fall through to e.g. http://www.teitok.org/Scripts, and
		 * serving a stale flexicorp.js from there silently masks local edits and breaks Alpine.
		 *
		 * @return string|null Non-empty base URL without trailing slash, or null.
		 */
		function tt_flexicorp_ecscripts_base_url() {
			global $projecturl, $sharedfolder, $jsurl, $ecscripts, $baseurl;

			// 1. Explicit overrides.
			if ( function_exists('getset') ) {
				$u = trim((string) getset('flexicorp/ecscripts_base', ''));
				if ( $u !== '' ) return rtrim($u, '/');
			}
			$env = getenv('FLEXICORP_ECSCRIPTS');
			if ( is_string($env) && trim($env) !== '' ) {
				return rtrim(trim($env), '/');
			}

			// 2. TEITOK's getjsurl(), but drop the answer if it matches the remote $jsurl
			//    fallback. Older 3-branch getjsurl() implementations return $jsurl/flexicorp.js
			//    whenever defaults/shared/url is unset, which is rarely what we want here.
			if ( function_exists('getjsurl') ) {
				$jsHref = (string) getjsurl('flexicorp');
				$suffix = '/flexicorp.js';
				if ( $jsHref !== '' && substr($jsHref, -strlen($suffix)) === $suffix ) {
					$base = rtrim(substr($jsHref, 0, -strlen($suffix)), '/');
					$jsurlBase = isset($jsurl) ? rtrim((string) $jsurl, '/') : '';
					if ( $base !== '' && $base !== $jsurlBase ) return $base;
				}
			}

			// 3. Per-project Scripts/flexicorp.js (project ships its own copy — rare).
			$pr = tt_flexicorp_project_root();
			if ( is_file($pr . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp.js') ) {
				if ( isset($projecturl) && is_string($projecturl) && trim($projecturl) !== '' ) {
					return rtrim((string) $projecturl, '/') . '/Scripts';
				}
				if ( isset($baseurl) && is_string($baseurl) && trim($baseurl) !== '' ) {
					return rtrim((string) $baseurl, '/') . '/Scripts';
				}
				return 'Scripts';
			}

			// 4. $sharedfolder/Scripts/flexicorp.js (TT_SHARED). This is the common deployment
			//    shape; resolve the URL via tt_flexicorp_shared_scripts_url() rather than
			//    $baseurl (which points at the current project, not the shared tree).
			if ( !empty($sharedfolder) ) {
				$sharedJs = rtrim((string) $sharedfolder, '/\\') . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp.js';
				if ( is_file($sharedJs) ) {
					$sharedBase = tt_flexicorp_shared_scripts_url();
					if ( $sharedBase !== '' ) return $sharedBase;
				}
			}

			// 5. Legacy global.
			if ( isset($ecscripts) && is_string($ecscripts) && trim($ecscripts) !== '' ) {
				return tt_flexicorp_append_scripts_segment($ecscripts);
			}

			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_public_flexicorp_urls') ) {
		/**
		 * Public href/src for flexicorp CSS and JS.
		 *
		 * IMPORTANT: use one coherent base URL for all flexicorp assets.
		 *
		 * In some deployments (notably Docker demos), TEITOK getjsurl() may resolve these
		 * files to remote/shared Script trees while the template/HTML comes from a newer local
		 * module copy. Mixing versions causes Alpine boot failures and stale UI.
		 *
		 * Therefore we intentionally bypass getjsurl() for flexicorp-specific assets and
		 * always build URLs from tt_flexicorp_ecscripts_base_url().
		 *
		 * @return array{ css: string, search_js: string, freqs_js: string, fqs_js: string, functions_js: string, search_scope_js: string, querybuilder_js: string, advanced_maps_js: string, advanced_contrast_js: string, advanced_dcoll_js: string, js: string, cwb_js: string, base: string }
		 */
		function tt_flexicorp_public_flexicorp_urls( $ecscriptsBaseUrl ) {
			$base = rtrim((string) $ecscriptsBaseUrl, '/');
			return array(
				'css' => $base . '/flexicorp.css',
				'search_js' => $base . '/flexicorp_search.js',
				'freqs_js' => $base . '/flexicorp_freqs.js',
				'fqs_js' => $base . '/flexicorp_fqs.js',
				'functions_js' => $base . '/flexicorp_functions.js',
				'search_scope_js' => $base . '/flexicorp_search_scope.js',
				'querybuilder_js' => $base . '/flexicorp_querybuilder.js',
				'advanced_maps_js' => $base . '/advanced_maps.js',
				'advanced_contrast_js' => $base . '/advanced_contrast.js',
				'advanced_dcoll_js' => $base . '/advanced_dcoll.js',
				'js' => $base . '/flexicorp.js',
				'cwb_js' => $base . '/flexicorp_cwb.js',
				'base' => $ecscriptsBaseUrl,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_current_action') ) {
		function tt_flexicorp_current_action() {
			global $action;
			return isset($action) && $action ? $action : 'flexicorp';
		}
	}

	if ( !function_exists('tt_flexicorp_ensure_session') ) {
		/**
		 * TEITOK does not always start PHP sessions before this module; recent queries and prefs need $_SESSION.
		 */
		function tt_flexicorp_ensure_session() {
			if ( function_exists('session_status') && session_status() === PHP_SESSION_NONE && !headers_sent() ) {
				@session_start();
			}
		}
	}

	if ( !function_exists('tt_flexicorp_search_disabled_ok_call') ) {
		/**
		 * Build an "ok + warnings only" response payload that the flexicorp UI
		 * can render as a non-fatal warning (so admins can still access "reindex").
		 */
		function tt_flexicorp_search_disabled_ok_call( $backend, $operation, $message ) {
			$op = trim((string)$operation);
			$backend = (string)$backend;
			$message = (string)$message;

			$result = array();
			if ( $op === 'query' ) {
				// Keep the shape similar to normal "hits" responses.
				$result = array(
					'result_type' => 'hits',
					'hits' => array(),
					'total' => 0,
					'returned' => 0,
				);
			}

			$done = array(
				'backend' => $backend,
				'operation' => $op,
				'result' => $result,
				'warnings' => array($message),
				'errors' => array(),
			);

			return array(
				'ok' => true,
				'command' => '',
				'raw' => '',
				'error' => '',
				'data' => array(
					'success' => true,
					'done' => $done,
				),
			);
		}
	}

	if ( !function_exists('tt_flexicorp_recent_queries_by_dialect_map') ) {
		/**
		 * Recent search strings keyed by flexicorp query_language (CQL dialect id), e.g. cwb-cql, pando-cql.
		 */
		function tt_flexicorp_recent_queries_by_dialect_map() {
			tt_flexicorp_ensure_session();
			if ( !isset($_SESSION) || !is_array($_SESSION) ) return array();
			if ( !isset($_SESSION['flexicorp_teitok']) || !is_array($_SESSION['flexicorp_teitok']) ) {
				$_SESSION['flexicorp_teitok'] = array();
			}
			$b = &$_SESSION['flexicorp_teitok'];
			if ( isset($b['recent_queries']) && is_array($b['recent_queries']) && count($b['recent_queries']) > 0 ) {
				if ( !isset($b['recent_queries_by_ql']) || !is_array($b['recent_queries_by_ql']) ) {
					$b['recent_queries_by_ql'] = array();
				}
				if ( empty($b['recent_queries_by_ql']['cwb-cql']) ) {
					$b['recent_queries_by_ql']['cwb-cql'] = array_values($b['recent_queries']);
				}
				unset($b['recent_queries']);
			}
			if ( !isset($b['recent_queries_by_ql']) || !is_array($b['recent_queries_by_ql']) ) {
				return array();
			}
			foreach ( $b['recent_queries_by_ql'] as $ql => $list ) {
				$b['recent_queries_by_ql'][ $ql ] = tt_flexicorp_recent_queries_canonicalize_bucket( $list );
			}
			return $b['recent_queries_by_ql'];
		}
	}

	if ( !function_exists('tt_flexicorp_stored_queries_by_dialect_map') ) {
		/**
		 * Stored named queries from TEITOK query manager persistence (Resources/queries.xml),
		 * keyed by query language.
		 * Output format: { [ql: string]: Array<{ name: string, query: string, source: 'stored' }> }.
		 */
		function tt_flexicorp_stored_queries_by_dialect_map( $projectRoot = '' ) {
			$root = trim((string)$projectRoot);
			if ( $root === '' ) return array();
			$candidates = array(
				$root . '/Resources/queries.xml',
				$root . '/resources/queries.xml',
			);
			$xmlPath = '';
			foreach ( $candidates as $p ) {
				if ( is_file($p) ) {
					$xmlPath = $p;
					break;
				}
			}
			if ( $xmlPath === '' ) return array();
			$raw = @file_get_contents($xmlPath);
			if ( !is_string($raw) || trim($raw) === '' ) return array();
			libxml_use_internal_errors(true);
			$xml = @simplexml_load_string($raw);
			libxml_clear_errors();
			if ( !$xml ) return array();
			$nodes = $xml->xpath('//query');
			if ( !is_array($nodes) ) $nodes = array();
			$out = array();
			$normQl = function( $rawQl ) {
				$k = strtolower(trim((string)$rawQl));
				if ( $k === 'cqp' ) return 'cwb-cql';
				if ( $k === 'manatee' ) return 'manatee-cql';
				return $k;
			};
			foreach ( $nodes as $node ) {
				if ( !($node instanceof SimpleXMLElement) ) continue;
				$attrs = $node->attributes();
				$q = isset($attrs['query']) ? trim((string)$attrs['query']) : '';
				if ( $q === '' && isset($node->query) ) $q = trim((string)$node->query);
				if ( $q === '' ) $q = trim((string)$node);
				$ql = isset($attrs['ql']) ? trim((string)$attrs['ql']) : '';
				if ( $ql === '' && isset($attrs['type']) ) $ql = trim((string)$attrs['type']);
				if ( $ql === '' && isset($node->ql) ) $ql = trim((string)$node->ql);
				if ( $ql === '' && isset($node->type) ) $ql = trim((string)$node->type);
				$ql = $normQl($ql);
				if ( $q === '' || $ql === '' ) continue;
				$name = isset($attrs['name']) ? trim((string)$attrs['name']) : '';
				if ( $name === '' && isset($node->name) ) $name = trim((string)$node->name);
				if ( $name === '' ) $name = 'Stored';
				if ( !isset($out[$ql]) || !is_array($out[$ql]) ) $out[$ql] = array();
				$out[$ql][] = array(
					'name' => $name,
					'query' => $q,
					'source' => 'stored',
				);
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_teitok_query_manager_ql') ) {
		/**
		 * Dialect id for TEITOK $_SESSION['queries'][*]['ql'] (querymng.php).
		 * Legacy code used the string "cqp" (CQP *action*); flexicorp must store the actual
		 * query language id (e.g. pando-cql, cwb-cql, manatee-cql) so the manager labels dialect correctly.
		 */
		function tt_flexicorp_teitok_query_manager_ql( $queryLanguage, $backend ) {
			$ql = trim((string)$queryLanguage);
			if ( $ql !== '' ) {
				return $ql;
			}
			$b = trim((string)$backend);
			if ( $b === 'pando' || $b === 'flexicorp-pando' ) {
				return 'pando-cql';
			}
			if ( $b === 'manatee' ) {
				return 'manatee-cql';
			}
			if ( $b === 'cqp' ) {
				return 'cwb-cql';
			}
			if ( $b === 'blacklab' ) {
				return 'bcql';
			}
			if ( $b === 'clickql' ) {
				return 'clickcql';
			}
			if ( $b === 'clickhouse' ) {
				return 'sql';
			}
			return 'cwb-cql';
		}
	}

	if ( !function_exists('tt_flexicorp_normalize_recent_query_text') ) {
		/**
		 * Normalize query text for recent-list deduplication and stable storage.
		 * Collapses runs of Unicode whitespace (incl. NBSP, newlines) to single spaces so
		 * visually identical queries do not occupy multiple slots.
		 */
		function tt_flexicorp_normalize_recent_query_text( $queryText ) {
			$s = (string) $queryText;
			// UTF-8 BOM
			if ( strncmp( $s, "\xEF\xBB\xBF", 3 ) === 0 ) {
				$s = substr( $s, 3 );
			}
			$s = trim( $s );
			if ( $s === '' ) {
				return '';
			}
			$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
			// Unicode-aware: \s includes NBSP and other separators when using /u
			$s = preg_replace( '/\s+/u', ' ', $s );
			return trim( $s );
		}
	}

	if ( !function_exists('tt_flexicorp_recent_queries_canonicalize_bucket') ) {
		/**
		 * Order-preserving dedupe for one dialect bucket (newest entries first).
		 * Uses tt_flexicorp_normalize_recent_query_text as the identity key.
		 */
		function tt_flexicorp_recent_queries_canonicalize_bucket( $list ) {
			if ( !is_array( $list ) || count( $list ) === 0 ) {
				return array();
			}
			$seen = array();
			$out = array();
			foreach ( $list as $x ) {
				$k = tt_flexicorp_normalize_recent_query_text( (string) $x );
				if ( $k === '' ) {
					continue;
				}
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				$out[] = $k;
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_recent_queries_push_for_language') ) {
		function tt_flexicorp_recent_queries_push_for_language( $queryLanguage, $queryText ) {
			$q = tt_flexicorp_normalize_recent_query_text( $queryText );
			if ( $q === '' ) return;
			$key = trim((string)$queryLanguage);
			if ( $key === '' ) return;
			tt_flexicorp_ensure_session();
			if ( !isset($_SESSION) || !is_array($_SESSION) ) return;
			if ( !isset($_SESSION['flexicorp_teitok']) || !is_array($_SESSION['flexicorp_teitok']) ) {
				$_SESSION['flexicorp_teitok'] = array();
			}
			$b = &$_SESSION['flexicorp_teitok'];
			if ( !isset($b['recent_queries_by_ql']) || !is_array($b['recent_queries_by_ql']) ) {
				$b['recent_queries_by_ql'] = array();
			}
			$list = isset($b['recent_queries_by_ql'][$key]) && is_array($b['recent_queries_by_ql'][$key]) ? $b['recent_queries_by_ql'][$key] : array();
			$list = array_values(array_filter($list, function ($x) use ($q) {
				return tt_flexicorp_normalize_recent_query_text( (string) $x ) !== $q;
			}));
			array_unshift($list, $q);
			$b['recent_queries_by_ql'][$key] = array_slice($list, 0, 25);
		}
	}

	if ( !function_exists('tt_flexicorp_sanitize_search_intro_html') ) {
		/**
		 * Allowed subset for HTML under the flexicorp search box (getset flexicorp/search_intro_html, Pages/* from search_text, lang layer).
		 */
		function tt_flexicorp_sanitize_search_intro_html( $html ) {
			$s = trim((string)$html);
			if ( $s === '' ) return '';
			$allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><code><pre><span><h3><h4><blockquote><div>';
			return strip_tags($s, $allowed);
		}
	}

	if ( !function_exists('tt_flexicorp_default_search_intro_html') ) {
		/**
		 * Last-resort HTML when no Pages file / lang layer (still sanitized by callers).
		 * Prefer teitok/flexicorp_help.html next to this file when present.
		 */
		function tt_flexicorp_default_search_intro_html() {
			$bundled = __DIR__ . DIRECTORY_SEPARATOR . 'flexicorp_help.html';
			if ( is_file($bundled) && is_readable($bundled) ) {
				$html = (string) @file_get_contents($bundled);
				if ( trim($html) !== '' ) {
					return $html;
				}
			}
			return '<p>Enter a query in the <strong>language shown next to the search box</strong>. Syntax depends on the corpus and on the engine you selected (CQL, PML-TQ, Tiger, SQL, etc.).</p>'
				. '<p>For <strong>CWB-style CQL</strong>, a typical pattern is to search for a word form or lemma; for example, words <em>starting with the letter a</em> often use a prefix pattern where your dialect supports regular expressions.</p>'
				. '<p><strong>Example</strong> buttons below (if the corpus administrator configured them) are chosen for the <strong>current query language only</strong> — the same file can list different lines under <code>[cwb-cql]</code>, <code>[pando-cql]</code>, <code>[pmltq]</code>, etc., so CQL examples are not shown for Tiger or PML-TQ.</p>';
		}
	}

	if ( !function_exists('tt_flexicorp_try_search_intro_from_pages') ) {
		/**
		 * TEITOK: getset('search_text', 'flexicorp_help') names Pages/&lt;key&gt;.html under the project,
		 * shared install, or ttroot (see search order). Bundled default: teitok/flexicorp_help.html (last fallback).
		 *
		 * @param string $pageKey Basename only (no path); must match /^[a-zA-Z0-9._-]+$/.
		 * @return string Raw HTML or ''.
		 */
		function tt_flexicorp_try_search_intro_from_pages( $pageKey ) {
			$pageKey = trim((string) $pageKey);
			if ( $pageKey === '' || strcasecmp($pageKey, 'none') === 0 || $pageKey === '-' ) {
				return '';
			}
			if ( ! preg_match('/^[a-zA-Z0-9._-]+$/', $pageKey) ) {
				return '';
			}
			global $sharedfolder, $ttroot;
			$base = $pageKey . '.html';
			$candidates = array();
			$cwd = getcwd();
			if ( $cwd ) {
				$candidates[] = $cwd . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . $base;
			}
			if ( isset($sharedfolder) && (string) $sharedfolder !== '' ) {
				$candidates[] = rtrim((string) $sharedfolder, '/\\') . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . $base;
			}
			if ( isset($ttroot) && (string) $ttroot !== '' ) {
				$tr = rtrim((string) $ttroot, '/\\');
				$candidates[] = $tr . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . $base;
				$candidates[] = $tr . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . $base;
			}
			$candidates[] = __DIR__ . DIRECTORY_SEPARATOR . $base;

			foreach ( $candidates as $path ) {
				if ( $path !== '' && is_file($path) && is_readable($path) ) {
					$html = (string) @file_get_contents($path);
					if ( trim($html) === '' ) {
						continue;
					}
					// Skip files that strip to nothing (bad markup); try next candidate (e.g. bundled teitok copy).
					if ( tt_flexicorp_sanitize_search_intro_html( $html ) !== '' ) {
						return $html;
					}
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_try_search_intro_from_lang' ) ) {
		/**
		 * Optional TEITOK language-layer HTML (same pattern as flexicorp-template): getlangpage / getlangfile('flexicorp-search-intro').
		 */
		function tt_flexicorp_try_search_intro_from_lang() {
			if ( function_exists('getlangpage') ) {
				$html = @getlangpage('flexicorp-search-intro');
				if ( is_string($html) && trim($html) !== '' ) {
					$san = tt_flexicorp_sanitize_search_intro_html($html);
					if ( $san !== '' ) return $san;
				}
			}
			if ( function_exists('getlangfile') ) {
				$langFile = getlangfile('flexicorp-search-intro');
				if ( is_string($langFile) && $langFile !== '' && is_file($langFile) && is_readable($langFile) ) {
					$html = (string) @file_get_contents($langFile);
					if ( trim($html) !== '' ) {
						$san = tt_flexicorp_sanitize_search_intro_html($html);
						if ( $san !== '' ) return $san;
					}
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_resolve_search_intro_html' ) ) {
		/**
		 * Order:
		 * 1) getset('flexicorp/search_intro_html') — inline HTML; '-' / 'none' = off.
		 * 2) Else TEITOK Pages: getset('search_text', 'flexicorp_help') → Pages/&lt;key&gt;.html (project → shared → ttroot → teitok bundle).
		 * 3) Else getlangpage / getlangfile flexicorp-search-intro.
		 * 4) Else tt_flexicorp_default_search_intro_html() (reads teitok/flexicorp_help.html when present).
		 *
		 * If flexicorp/search_intro_html is non-empty but strips to nothing, fall back through 2–4.
		 */
		function tt_flexicorp_resolve_search_intro_html() {
			$introRaw = function_exists('getset') ? (string) getset('flexicorp/search_intro_html', '') : '';
			$introTrim = trim($introRaw);
			if ( $introTrim === '-' || strcasecmp($introTrim, 'none') === 0 ) {
				return '';
			}
			if ( $introTrim !== '' ) {
				$san = tt_flexicorp_sanitize_search_intro_html($introRaw);
				if ( $san !== '' ) {
					return $san;
				}
			}
			$pageKey = function_exists('getset') ? trim((string) getset('search_text', 'flexicorp_help')) : 'flexicorp_help';
			if ( $pageKey !== '' && strcasecmp($pageKey, 'none') !== 0 && $pageKey !== '-' ) {
				$fromPages = tt_flexicorp_try_search_intro_from_pages($pageKey);
				if ( $fromPages !== '' ) {
					$san = tt_flexicorp_sanitize_search_intro_html($fromPages);
					if ( $san !== '' ) {
						return $san;
					}
				}
			}
			$lang = tt_flexicorp_try_search_intro_from_lang();
			if ( $lang !== '' ) {
				return $lang;
			}
			return tt_flexicorp_sanitize_search_intro_html(tt_flexicorp_default_search_intro_html());
		}
	}

	if ( !function_exists('tt_flexicorp_split_search_intro_lead_body') ) {
		/**
		 * First <p>...</p> block is the short hint (shown below Hit limit); remainder stays in the intro box.
		 *
		 * @return array{lead: string, body: string}
		 */
		function tt_flexicorp_split_search_intro_lead_body( $html ) {
			$html = trim( (string) $html );
			if ( $html === '' ) {
				return array( 'lead' => '', 'body' => '' );
			}
			if ( preg_match( '#^(\s*<p(?:\s[^>]*)?>.*?</p>)#is', $html, $m ) ) {
				$lead = trim( $m[1] );
				$body = trim( substr( $html, strlen( $m[0] ) ) );
				return array( 'lead' => $lead, 'body' => $body );
			}
			return array( 'lead' => '', 'body' => $html );
		}
	}

	if ( !function_exists('tt_flexicorp_parse_search_example_query_line') ) {
		function tt_flexicorp_parse_search_example_query_line( $line ) {
			$line = trim((string)$line);
			if ( $line === '' ) return null;
			if ( strpos($line, '|||' ) !== false ) {
				$parts = explode('|||', $line, 2);
				$q = trim((string)($parts[0] ?? ''));
				$lab = trim((string)($parts[1] ?? ''));
				if ( $q === '' ) return null;
				return array( 'query' => $q, 'label' => $lab );
			}
			return array( 'query' => $line, 'label' => '' );
		}
	}

	if ( !function_exists('tt_flexicorp_parse_search_example_queries_map') ) {
		/**
		 * getset flexicorp/search_example_queries: optional dialect sections, e.g.
		 *   [cwb-cql]
		 *   [lemma="test"]|||Lemma = test
		 *   [pando-cql]
		 *   [lemma="x"]|||…
		 *   [default]
		 *   …
		 * Lines without a [section] header are treated as legacy CQL-only (see resolve).
		 * # starts a comment line.
		 */
		function tt_flexicorp_parse_search_example_queries_map( $raw ) {
			$lines = preg_split('/\r\n|\r|\n/', (string)$raw);
			if ( !is_array($lines) ) {
				return array( 'sections' => array(), 'had_section_headers' => false );
			}
			$sections = array();
			$current = null;
			$had_section_headers = false;
			foreach ( $lines as $line ) {
				$t = trim((string)$line);
				if ( $t === '' || ( isset($t[0]) && $t[0] === '#' ) ) continue;
				if ( preg_match('/^\[([a-zA-Z0-9._-]+)\]$/', $t, $m ) ) {
					$had_section_headers = true;
					$current = strtolower((string)$m[1]);
					if ( !isset($sections[$current]) ) {
						$sections[$current] = array();
					}
					continue;
				}
				if ( $current === null ) {
					$current = '__legacy__';
					if ( !isset($sections['__legacy__']) ) {
						$sections['__legacy__'] = array();
					}
				}
				// Allow legacy block without explicit [__legacy__] header.
				if ( !isset($sections[$current]) ) {
					$sections[$current] = array();
				}
				// If a line looks like a section header but failed regex, treat as query (unlikely).
				$pq = tt_flexicorp_parse_search_example_query_line( $t );
				if ( $pq !== null ) {
					$sections[$current][] = $pq;
				}
			}
			return array( 'sections' => $sections, 'had_section_headers' => $had_section_headers );
		}
	}

	if ( !function_exists('tt_flexicorp_resolve_search_example_queries_for_language') ) {
		/**
		 * Picks the example list for the active flexicorp query_language id.
		 * Legacy unsectioned lines apply only to CQL-family dialects unless extended via getset.
		 */
		function tt_flexicorp_resolve_search_example_queries_for_language( $map, $queryLanguage ) {
			$ql = strtolower(trim((string)$queryLanguage));
			if ( $ql === '' || !is_array($map) || !isset($map['sections']) || !is_array($map['sections']) ) {
				return array();
			}
			$sections = $map['sections'];
			if ( isset($sections[$ql]) && is_array($sections[$ql]) && count($sections[$ql]) > 0 ) {
				return $sections[$ql];
			}
			if ( isset($sections['default']) && is_array($sections['default']) && count($sections['default']) > 0 ) {
				return $sections['default'];
			}
			$legacy = isset($sections['__legacy__']) && is_array($sections['__legacy__']) ? $sections['__legacy__'] : array();
			if ( count($legacy) === 0 ) {
				return array();
			}
			$cqlFamily = array( 'cwb-cql', 'pando-cql', 'manatee-cql', 'cql' );
			if ( in_array($ql, $cqlFamily, true) ) {
				return $legacy;
			}
			$extra = '';
			if ( function_exists('getset') ) {
				$extra = trim((string) getset('flexicorp/example_queries_legacy_as_cql_dialects', ''));
			}
			if ( $extra !== '' ) {
				$parts = preg_split('/[\s,;]+/', $extra);
				foreach ( $parts as $p ) {
					$p = strtolower(trim((string)$p));
					if ( $p !== '' && $p === $ql ) {
						return $legacy;
					}
				}
			}
			return array();
		}
	}

	if ( !function_exists('tt_flexicorp_backend_default') ) {
		/**
		 * Explicit corpus default from settings, or empty for auto
		 * (see tt_flexicorp_preferred_combo: pando → cqp → …; legacy last).
		 */
		function tt_flexicorp_backend_default() {
			$cfg = function_exists('getset') ? trim((string) getset('defaults/flexicorp/backend', '')) : '';
			if ( $cfg === '' || strtolower($cfg) === 'auto' || $cfg === '-' ) {
				return '';
			}
			return $cfg;
		}
	}

	if ( !function_exists('tt_flexicorp_backend_canonical') ) {
		/**
		 * Public backend id policy: expose "pando" only.
		 * Keep legacy "flexicorp-pando" as accepted input alias.
		 */
		function tt_flexicorp_backend_canonical( $backend ) {
			$b = trim((string)$backend);
			if ( $b === 'flexicorp-pando' ) return 'pando';
			return $b;
		}
	}

	if ( !function_exists('tt_flexicorp_legacy_backends') ) {
		/**
		 * Kept for emergency / admin use only — not offered as normal defaults.
		 * flexi = early native reader; clickql/clickhouse = deprecated ClickHouse path.
		 */
		function tt_flexicorp_legacy_backends() {
			return array( 'flexi', 'clickql', 'clickhouse' );
		}
	}

	if ( !function_exists('tt_flexicorp_is_legacy_backend') ) {
		function tt_flexicorp_is_legacy_backend( $backend ) {
			$b = tt_flexicorp_backend_canonical( $backend );
			return $b !== '' && in_array( $b, tt_flexicorp_legacy_backends(), true );
		}
	}

	if ( !function_exists('tt_flexicorp_show_legacy_backends') ) {
		/**
		 * Visitors: hidden unless flexicorp/show_legacy_backends is truthy.
		 * Admins: always visible (Engines / settings escape hatch).
		 */
		function tt_flexicorp_show_legacy_backends( $isAdmin = false ) {
			if ( $isAdmin ) {
				return true;
			}
			if ( ! function_exists( 'getset' ) ) {
				return false;
			}
			$raw = strtolower( trim( (string) getset( 'flexicorp/show_legacy_backends', '' ) ) );
			return in_array( $raw, array( '1', 'true', 'yes', 'on' ), true );
		}
	}

	if ( !function_exists('tt_flexicorp_info_backends') ) {
		function tt_flexicorp_info_backends( $projectRoot ) {
			return tt_flexicorp_run(array('info', 'backends'), 'clickhouse', $projectRoot, array(), null, null, null);
		}
	}

	if ( !function_exists('tt_flexicorp_command_exists') ) {
		function tt_flexicorp_command_exists( $command ) {
			$cmd = trim((string)$command);
			if ( $cmd === '' ) return false;
			if ( strpos($cmd, '/') !== false ) {
				return is_file($cmd) && is_executable($cmd);
			}
			$resolved = trim((string) shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
			return $resolved !== '' && is_file($resolved) && is_executable($resolved);
		}
	}

	if ( !function_exists('tt_flexicorp_cqp_bin') ) {
		/**
		 * Path to the CQP CLI. Prefer TEITOK's findapp("cqp") (same as cwcqp.php / cqp.php), which
		 * checks getset(bin/cqp), $bindir, /usr/bin, /usr/local/bin, then which — not only PATH.
		 */
		function tt_flexicorp_cqp_bin() {
			$env = trim((string)(getenv('FLEXICORP_CQP_BIN') ?: ''));
			if ( $env !== '' ) {
				return $env;
			}
			$cfg = function_exists('getset') ? trim((string) getset('cqp/bin', '')) : '';
			if ( $cfg !== '' ) {
				return $cfg;
			}
			if ( function_exists('findapp') ) {
				$p = trim((string) findapp('cqp'));
				// findapp() returns an absolute path when found; otherwise may return bare "cqp".
				if ( $p !== '' && strpos($p, '/') !== false ) {
					return $p;
				}
			}
			// findapp() does not probe /opt/homebrew/bin (Apple Silicon Homebrew).
			foreach ( array( '/opt/homebrew/bin/cqp', '/usr/local/bin/cqp', '/usr/bin/cqp' ) as $fallback ) {
				if ( is_file($fallback) && is_executable($fallback) ) {
					return $fallback;
				}
			}
			return 'cqp';
		}
	}

	/**
	 * Check if CQP backend is available: registry + corpus registry file + full words (word.corpus or xidx.rng).
	 * Paths are resolved relative to $projectRoot when from getset (TEITOK).
	 */
	if ( !function_exists('tt_flexicorp_cqp_available') ) {
		function tt_flexicorp_cqp_available( $projectRoot ) {
			$st = tt_flexicorp_cqp_status_resolved( $projectRoot );
			return !empty( $st['available'] );
		}
	}

	/**
	 * CQP status resolution that is consistent with TEITOK's cwcqp.php.
	 * This function is intentionally NOT guarded by function_exists() so it
	 * can override legacy versions that might already be loaded.
	 */
	if ( !function_exists('tt_flexicorp_cqp_status_resolved') ) {
		function tt_flexicorp_cqp_status_resolved( $projectRoot ) {
			global $debug;
			$isDebug = isset($debug) && !empty($debug);
			$cqpBin = tt_flexicorp_cqp_bin();
			if ( !tt_flexicorp_command_exists($cqpBin) ) {
				return array(
					'available' => false,
					'reason' => $isDebug
						? ('CQP command not found or not executable: ' . $cqpBin . '.')
						: 'CQP command not found.',
				);
			}

			$projectRoot = is_string($projectRoot) ? $projectRoot : '';
			$projectRoot = $projectRoot ? rtrim($projectRoot, '/') : '';
			if ( $projectRoot === '' ) {
				$projectRoot = (string) getcwd();
				if ( $projectRoot ) $projectRoot = rtrim($projectRoot, '/');
			}
			$projectRootAbs = $projectRoot;
			$rp = $projectRoot ? @realpath($projectRoot) : '';
			if ( $rp ) $projectRootAbs = $rp;
			// Prefer the directory containing TEITOK's index.php for absolute paths.
			// This is more robust than relying on the server working directory.
			$scriptFilename = isset($_SERVER['SCRIPT_FILENAME']) ? (string)$_SERVER['SCRIPT_FILENAME'] : '';
			if ( $scriptFilename ) {
				$scriptDir = @realpath(dirname($scriptFilename));
				if ( $scriptDir ) $projectRootAbs = $scriptDir;
			}

			$foldernameLocal = '';
			if ( function_exists('getset') ) {
				$foldernameLocal = trim((string) getset('defaults/base/foldername', ''));
			}

			$defaultCorpus = ($foldernameLocal !== '') ? ('tt-' . $foldernameLocal) : '';
			$corpus = function_exists('getset') ? (string) getset('cqp/corpus', $defaultCorpus) : $defaultCorpus;
			$corpus = strtoupper(trim((string)$corpus));
			if ( $corpus === '' ) {
				return array(
					'available' => false,
					'reason' => 'No CQP corpus name configured (resolved).',
				);
			}

			$corpusLower = strtolower($corpus);

			$regRel = function_exists('getset') ? (string) getset('cqp/defaults/registry', 'cqp') : 'cqp';
			$regRel = trim((string)$regRel);
			if ( $regRel === '' ) $regRel = 'cqp';

			$registryFolder = $regRel;
			// Interpret relative registry folder paths relative to the TEITOK project dir.
			if ( $registryFolder !== '' && $registryFolder[0] !== '/' ) {
				$registryFolder = $projectRootAbs !== '' ? ($projectRootAbs . '/' . $registryFolder) : $registryFolder;
			}
			$registryFolder = rtrim($registryFolder, '/');

			$regFile = $registryFolder . '/' . $corpusLower;
			$centralRegFile = '/usr/local/share/cwb/registry/' . $corpusLower;
			if ( !file_exists($regFile) && file_exists($centralRegFile) ) {
				$registryFolder = '/usr/local/share/cwb/registry';
				$regFile = $centralRegFile;
			}
			if ( !file_exists($regFile) ) {
				return array(
					'available' => false,
					'reason' => $isDebug
						? ('CQP registry file missing (resolved): corpus=' . $corpus . '; expected=' . $regFile . '.')
						: ('CQP corpus=' . $corpus . '; registry missing.'),
				);
			}

			$cqpfolderRel = function_exists('getset') ? (string) getset('cqp/cqpfolder', 'cqp') : 'cqp';
			$cqpfolderRel = strtolower(trim((string)$cqpfolderRel));
			if ( $cqpfolderRel === '' ) $cqpfolderRel = 'cqp';

			$cqpFolder = $cqpfolderRel;
			// Interpret relative cqpfolder paths relative to the TEITOK project dir.
			if ( $cqpFolder !== '' && $cqpFolder[0] !== '/' ) {
				$cqpFolder = $projectRootAbs !== '' ? ($projectRootAbs . '/' . $cqpFolder) : $cqpFolder;
			}
			$cqpFolder = rtrim($cqpFolder, '/');

			$xidxPath = $cqpFolder . '/xidx.rng';
			$wordPath = $cqpFolder . '/word.corpus';
			$xidxExists = file_exists($xidxPath);
			$wordExists = file_exists($wordPath) && @filesize($wordPath) > 0;

			if ( $xidxExists ) {
				return array(
					'available' => true,
					'reason' => $isDebug
						? ('CQP corpus=' . $corpus . '; registry=' . $regFile . '; xidx.rng present (' . $xidxPath . ').')
						: ('CQP corpus=' . $corpus . '; index available (xidx.rng).'),
				);
			}
			if ( $wordExists ) {
				return array(
					'available' => true,
					'reason' => $isDebug
						? ('CQP corpus=' . $corpus . '; registry=' . $regFile . '; word.corpus present (' . $wordPath . ').')
						: ('CQP corpus=' . $corpus . '; index available (word.corpus).'),
				);
			}

			return array(
				'available' => false,
				'reason' => $isDebug
					? ('CQP corpus=' . $corpus . '; registry ok (' . $regFile . '), but missing index: xidx.rng=' . $xidxPath . ', word.corpus=' . $wordPath . '.')
					: ('CQP corpus=' . $corpus . '; index not available.'),
			);
		}
	}

	/**
	 * CQP status: available (bool), reason (string).
	 */
	if ( !function_exists('tt_flexicorp_cqp_status') ) {
		function tt_flexicorp_cqp_status( $projectRoot ) {
			$cqpBin = tt_flexicorp_cqp_bin();
			if ( !tt_flexicorp_command_exists($cqpBin) ) {
				return array(
					'available' => false,
					'reason' => 'CQP command not found or not executable: ' . $cqpBin . '.',
				);
			}
			// Keep this consistent with TEITOK's `cwcqp.php`.
			// In this module, `$projectRoot` is not a reliable indicator for filesystem paths
			// (it's typically just the server working directory), so we resolve relative paths
			// against TEITOK's `$foldername` and rely on getset() for defaults.

			// Read TEITOK's global, but do not overwrite it (so any runtime changes
			// to `$foldername` remain effective automatically).
			global $foldername;
			$foldernameLocal = isset($foldername) ? trim((string)$foldername) : '';
			if ( $foldernameLocal === '' && function_exists('getset') ) {
				$foldernameLocal = trim((string) getset('defaults/base/foldername', ''));
			}

			$defaultCorpus = ($foldernameLocal !== '') ? ('tt-' . $foldernameLocal) : '';
			$corpus = function_exists('getset') ? (string) getset('cqp/corpus', $defaultCorpus) : $defaultCorpus;
			$corpus = strtoupper(trim((string)$corpus));
			if ( $corpus === '' ) {
				return array( 'available' => false, 'reason' => 'No CQP corpus name configured (missing getset("cqp/corpus") and default tt-$foldernameLocal).' );
			}
			$corpusLower = strtolower($corpus);

			$regRel = function_exists('getset') ? (string) getset('cqp/defaults/registry', 'cqp') : 'cqp';
			$regRel = trim((string)$regRel);
			if ( $regRel === '' ) $regRel = 'cqp';
			$registryFolder = $regRel;
			if ( $registryFolder !== '' && $registryFolder[0] !== '/' && $foldernameLocal !== '' ) {
				$registryFolder = rtrim($foldernameLocal, '/') . '/' . $registryFolder;
			}
			$registryFolder = rtrim($registryFolder, '/');

			$regFile = $registryFolder . '/' . $corpusLower;
			$centralRegFile = '/usr/local/share/cwb/registry/' . $corpusLower;
			if ( !file_exists($regFile) && file_exists($centralRegFile) ) {
				$registryFolder = '/usr/local/share/cwb/registry';
				$regFile = $centralRegFile;
			}
			if ( !file_exists($regFile) ) {
				return array(
					'available' => false,
					'reason' => 'CQP registry file missing: corpus=' . $corpus . '; expected=' . $regFile . '; central=' . $centralRegFile . '.',
				);
			}

			$cqpfolderRel = function_exists('getset') ? (string) getset('cqp/cqpfolder', 'cqp') : 'cqp';
			$cqpfolderRel = trim((string)$cqpfolderRel);
			if ( $cqpfolderRel === '' ) $cqpfolderRel = 'cqp';
			$cqpFolder = $cqpfolderRel;
			if ( $cqpFolder !== '' && $cqpFolder[0] !== '/' && $foldernameLocal !== '' ) {
				$cqpFolder = rtrim($foldernameLocal, '/') . '/' . $cqpFolder;
			}
			$cqpFolder = rtrim($cqpFolder, '/');

			$xidxPath = $cqpFolder . '/xidx.rng';
			$wordPath = $cqpFolder . '/word.corpus';
			$xidxExists = file_exists($xidxPath);
			$wordExists = file_exists($wordPath) && @filesize($wordPath) > 0;

			if ( $xidxExists ) {
				return array(
					'available' => true,
					'reason' => 'CQP corpus=' . $corpus . '; registry=' . $regFile . '; xidx.rng present (' . $xidxPath . ').',
				);
			}
			if ( $wordExists ) {
				return array(
					'available' => true,
					'reason' => 'CQP corpus=' . $corpus . '; registry=' . $regFile . '; word.corpus present (' . $wordPath . ').',
				);
			}
			return array(
				'available' => false,
				'reason' => 'CQP corpus=' . $corpus . '; registry ok (' . $regFile . '), but missing index: xidx.rng=' . $xidxPath . ', word.corpus=' . $wordPath . '.',
			);
		}
	}

	/**
	 * Check if Manatee backend is available: manatee folder (optionally with corp subfolder) and full words table.
	 * Looks for: manatee/word or manatee/word.frq, or manatee/corp/word or manatee/corp/word.frq.
	 */
	if ( !function_exists('tt_flexicorp_manatee_available') ) {
		function tt_flexicorp_manatee_available( $projectRoot ) {
			$st = tt_flexicorp_manatee_status( $projectRoot );
			return !empty( $st['available'] );
		}
	}

	/**
	 * True when Manatee corpus index files exist (independent of Python bindings).
	 */
	if ( !function_exists('tt_flexicorp_manatee_corpus_available') ) {
		function tt_flexicorp_manatee_corpus_available( $projectRoot ) {
			$st = tt_flexicorp_manatee_status( $projectRoot );
			return !empty( $st['corpus_available'] );
		}
	}

	/**
	 * Whether a directory looks like the manatee-open Python API (manatee.py + native _manatee.so).
	 * Mirrors flexicorp.backends.manatee._is_valid_manatee_api_dir.
	 */
	if ( !function_exists('tt_flexicorp_manatee_api_dir_valid') ) {
		function tt_flexicorp_manatee_api_dir_valid( $dir ) {
			$dir = rtrim( (string) $dir, '/' );
			if ( $dir === '' || ! is_dir( $dir ) ) {
				return false;
			}
			$py = $dir . '/manatee.py';
			if ( ! is_file( $py ) ) {
				return false;
			}
			$so1 = $dir . '/_manatee.so';
			$so2 = $dir . '/.libs/_manatee.so';
			return ( is_file( $so1 ) || is_file( $so2 ) );
		}
	}

	/**
	 * Whether the same TEITOK venv Python that runs `python -m flexicorp` can import `manatee`
	 * (same PYTHONPATH as tt_flexicorp_run). Mirrors subprocess environment, not PHP's MANATEE_API alone.
	 */
	if ( ! function_exists( 'tt_flexicorp_manatee_venv_import_check' ) ) {
		function tt_flexicorp_manatee_venv_import_check( $pythonExe, $projectRoot ) {
			$manateePythonPath = '';
			if ( function_exists( 'getset' ) ) {
				$manateePythonPath = trim( (string) getset( 'manatee/pythonpath', '' ) );
				if ( $manateePythonPath === '' ) {
					$manateePythonPath = trim( (string) getset( 'flexicorp/manatee_pythonpath', '' ) );
				}
			}
			$pycode = 'import importlib.util,sys; spec=importlib.util.find_spec("manatee"); sys.exit(0 if spec is not None else 1)';
			$cmd = escapeshellarg( (string) $pythonExe ) . ' -c ' . escapeshellarg( $pycode );
			if ( $manateePythonPath !== '' ) {
				$cmd = 'env PYTHONPATH=' . escapeshellarg( $manateePythonPath ) . ' ' . $cmd;
			}
			$out = array();
			$code = -1;
			@exec( $cmd . ' 2>&1', $out, $code );
			if ( $code === 0 ) {
				return array( 'ok' => true, 'reason' => '' );
			}
			$msg = trim( implode( "\n", $out ) );
			return array(
				'ok' => false,
				'reason' => $msg !== '' ? $msg : ( 'venv import manatee failed (exit ' . (int) $code . ')' ),
			);
		}
	}

	/**
	 * True when Manatee Python bindings are usable the same way flexicorp subprocesses use them.
	 * When TEITOK's VENV exists, this is determined only by that venv's `import manatee` (not PHP's
	 * MANATEE_API vs a different Python install).
	 */
	if ( !function_exists('tt_flexicorp_manatee_bindings_installed' ) ) {
		function tt_flexicorp_manatee_bindings_installed( $projectRoot ) {
			static $venv_probe_memo = null;
			if ( class_exists( 'VENV' ) ) {
				try {
					$venv = new VENV();
					$venvRoot = $venv->path();
					if ( is_string( $venvRoot ) && $venvRoot !== '' ) {
						$python = str_replace( '\\', '/', rtrim( $venvRoot, '/\\' ) ) . '/bin/python';
						if ( is_file( $python ) ) {
							if ( $venv_probe_memo === null ) {
								$venv_probe_memo = tt_flexicorp_manatee_venv_import_check( $python, $projectRoot );
							}
							$probe = $venv_probe_memo;
							if ( ! empty( $probe['ok'] ) ) {
								return array( 'ok' => true, 'reason' => '' );
							}
							return array(
								'ok' => false,
								'reason' => 'Manatee is not importable in the TEITOK flexicorp venv (same as `python -m flexicorp`). '
									. ( isset( $probe['reason'] ) ? (string) $probe['reason'] : '' ),
							);
						}
					}
				} catch ( Throwable $e ) {
					// Fall through to filesystem probes.
				}
			}
			$env = getenv( 'MANATEE_API' );
			if ( is_string( $env ) && $env !== '' && tt_flexicorp_manatee_api_dir_valid( $env ) ) {
				return array( 'ok' => true, 'reason' => '' );
			}
			foreach ( array( '/usr/local/share/manatee/api', '/opt/share/manatee/api' ) as $dir ) {
				if ( tt_flexicorp_manatee_api_dir_valid( $dir ) ) {
					return array( 'ok' => true, 'reason' => '' );
				}
			}
			if ( $projectRoot && is_dir( $projectRoot ) ) {
				$lib = rtrim( (string) $projectRoot, '/' ) . '/lib/manatee';
				if ( tt_flexicorp_manatee_api_dir_valid( $lib ) ) {
					return array( 'ok' => true, 'reason' => '' );
				}
			}
			return array(
				'ok' => false,
				'reason' => 'No Manatee Python API directory found (expected manatee.py and _manatee.so). Set MANATEE_API or install under /usr/local/share/manatee/api.',
			);
		}
	}

	/**
	 * Manatee status: available (bool), reason (string).
	 * Checks manatee/ and manatee/corp/ for a lexicon table, preferring word.lex as in TEITOK's Manatee layout.
	 *
	 * - corpus_available: index files present.
	 * - available: native "manatee" backend usable (corpus + Python bindings), same as flexicorp overview.py.
	 */
	if ( !function_exists('tt_flexicorp_manatee_status') ) {
		function tt_flexicorp_manatee_status( $projectRoot ) {
			if ( !$projectRoot || !is_dir($projectRoot) ) {
				return array(
					'available' => false,
					'corpus_available' => false,
					'bindings_available' => false,
					'reason' => 'Project root missing or not a directory.',
				);
			}
			$manateePath = function_exists('getset') ? getset('manatee/path', '') : '';
			if ( $manateePath === '' ) $manateePath = rtrim($projectRoot, '/') . '/manatee';
			elseif ( $manateePath[0] !== '/' ) $manateePath = rtrim($projectRoot, '/') . '/' . $manateePath;
			if ( !is_dir($manateePath) ) {
				return array(
					'available' => false,
					'corpus_available' => false,
					'bindings_available' => false,
					'reason' => 'Manatee folder not found: ' . basename($manateePath) . '.',
				);
			}
			$candidates = array(
				// Preferred TEITOK Manatee layout
				$manateePath . '/corp/word.lex',
				// Fallbacks for older / alternative layouts
				$manateePath . '/word.lex',
				$manateePath . '/word',
				$manateePath . '/word.frq',
				$manateePath . '/corp/word',
				$manateePath . '/corp/word.frq',
			);
			foreach ( $candidates as $path ) {
				if ( file_exists($path) && ( strpos($path, '.lex') !== false || strpos($path, '.frq') !== false || @filesize($path) > 0 ) ) {
					$bind = tt_flexicorp_manatee_bindings_installed( $projectRoot );
					$rel = str_replace( $projectRoot . '/', '', $path );
					if ( ! empty( $bind['ok'] ) ) {
						return array(
							'available' => true,
							'corpus_available' => true,
							'bindings_available' => true,
							'reason' => 'Words table found: ' . $rel . '.',
						);
					}
					$bindDetail = isset( $bind['reason'] ) ? trim( (string) $bind['reason'] ) : '';
					return array(
						'available' => false,
						'corpus_available' => true,
						'bindings_available' => false,
						'reason' => tt_flexicorp_manatee_public_bindings_short_reason(),
						'reason_detail' => $bindDetail !== '' ? $bindDetail : 'Python Manatee bindings not available in this environment.',
					);
				}
			}
			return array(
				'available' => false,
				'corpus_available' => false,
				'bindings_available' => false,
				'reason' => 'No words table (word.lex / word / word.frq) in manatee/ or manatee/corp/.',
			);
		}
	}

	if ( ! function_exists( 'tt_flexicorp_manatee_public_bindings_short_reason' ) ) {
		/** One-line text for Overview / Stats when index exists but native Manatee cannot run (avoid long Python traces). */
		function tt_flexicorp_manatee_public_bindings_short_reason() {
			return 'Index files are present, but Manatee is not installed or not working properly.';
		}
	}

	if ( ! function_exists( 'tt_flexicorp_manatee_message_should_redact_for_ui' ) ) {
		/**
		 * True when the flexicorp/Python message should be replaced by tt_flexicorp_manatee_public_bindings_short_reason() in the normal UI.
		 */
		function tt_flexicorp_manatee_message_should_redact_for_ui( $msg ) {
			$msg = trim( (string) $msg );
			if ( $msg === '' ) {
				return false;
			}
			$l = strtolower( $msg );
			if ( stripos( $msg, 'Internal error' ) !== false && stripos( $l, 'manatee' ) !== false ) {
				return true;
			}
			if ( stripos( $msg, 'manatee-open' ) !== false || stripos( $l, 'pypi package' ) !== false ) {
				return true;
			}
			if ( stripos( $msg, 'official python manatee' ) !== false ) {
				return true;
			}
			if ( stripos( $msg, "during 'info'" ) !== false && stripos( $l, 'manatee' ) !== false ) {
				return true;
			}
			if ( stripos( $msg, 'pure manatee backend' ) !== false ) {
				return true;
			}
			return false;
		}
	}

	if ( ! function_exists( 'tt_flexicorp_manatee_ui_reason_from_raw' ) ) {
		function tt_flexicorp_manatee_ui_reason_from_raw( $raw ) {
			$raw = trim( (string) $raw );
			if ( tt_flexicorp_manatee_message_should_redact_for_ui( $raw ) ) {
				return tt_flexicorp_manatee_public_bindings_short_reason();
			}
			return $raw;
		}
	}

	if ( ! function_exists( 'tt_flexicorp_sanitize_manatee_backend_payload_for_ui' ) ) {
		/**
		 * Shorten flexicorp stderr-style Manatee messages in status/info payloads for normal (non-debug) UI.
		 */
		function tt_flexicorp_sanitize_manatee_backend_payload_for_ui( $payload, $debugMode ) {
			if ( $debugMode || ! is_array( $payload ) ) {
				return $payload;
			}
			$errs = isset( $payload['errors'] ) && is_array( $payload['errors'] ) ? $payload['errors'] : array();
			$first = count( $errs ) > 0 ? trim( (string) $errs[0] ) : '';
			if ( $first === '' && ! empty( $payload['error'] ) ) {
				$first = trim( (string) $payload['error'] );
			}
			if ( ! tt_flexicorp_manatee_message_should_redact_for_ui( $first ) ) {
				return $payload;
			}
			$short = tt_flexicorp_manatee_public_bindings_short_reason();
			$payload['errors'] = array( $short );
			$payload['error'] = $short;
			if ( isset( $payload['result'] ) && is_array( $payload['result'] ) ) {
				if ( isset( $payload['result']['errors'] ) && is_array( $payload['result']['errors'] ) ) {
					$payload['result']['errors'] = array( $short );
				}
			}
			return $payload;
		}
	}

	if ( ! function_exists( 'tt_flexicorp_manatee_project_status_resolved' ) ) {
		/**
		 * Native Manatee availability: filesystem checks, then the same flexicorp subprocess as the UI
		 * (`python -m flexicorp … info corpus --backend manatee`). Aligns TEITOK gating with runtime.
		 *
		 * @param array $backendOverrideArgs From tt_flexicorp_backend_override_extra_args() (admin overrides).
		 */
		function tt_flexicorp_manatee_project_status_resolved( $projectRoot, $backendOverrideArgs = array(), $deepProbe = false ) {
			$fs = tt_flexicorp_manatee_status( $projectRoot );
			if ( empty( $fs['corpus_available'] ) ) {
				return $fs;
			}
			// Manatee is optional in the TEITOK UI. Only run the expensive/strict flexicorp subprocess
			// probe when explicitly requested; otherwise keep non-Manatee flows insulated from failures.
			if ( !$deepProbe ) {
				return $fs;
			}
			if ( ! is_array( $backendOverrideArgs ) ) {
				$backendOverrideArgs = array();
			}
			if ( ! function_exists( 'tt_flexicorp_run' ) || ! function_exists( 'tt_flexicorp_done' ) ) {
				return $fs;
			}
			$probe = tt_flexicorp_run(
				array( 'info', 'corpus' ),
				'manatee',
				$projectRoot,
				$backendOverrideArgs,
				'manatee',
				'manatee-cql',
				'manatee'
			);
			$GLOBALS['tt_flexicorp_manatee_info_probe_call'] = $probe;
			$done = tt_flexicorp_done( $probe );
			$errs = ( is_array( $done ) && isset( $done['errors'] ) && is_array( $done['errors'] ) ) ? $done['errors'] : array();
			$ok = ! empty( $probe['ok'] ) && count( $errs ) === 0 && isset( $done['result'] ) && is_array( $done['result'] );
			if ( $ok ) {
				$r = isset( $fs['reason'] ) ? trim( (string) $fs['reason'] ) : '';
				return array(
					'available' => true,
					'corpus_available' => true,
					'bindings_available' => true,
					'reason' => $r !== '' ? $r : 'Native Manatee backend verified (flexicorp info corpus).',
				);
			}
			$msg = '';
			if ( count( $errs ) > 0 ) {
				$msg = trim( (string) $errs[0] );
			}
			if ( $msg === '' && ! empty( $probe['error'] ) ) {
				$msg = trim( (string) $probe['error'] );
			}
			if ( $msg === '' ) {
				$msg = 'Native Manatee backend not available (flexicorp info corpus failed).';
			}
			return array(
				'available' => false,
				'corpus_available' => true,
				'bindings_available' => false,
				'reason' => tt_flexicorp_manatee_ui_reason_from_raw( $msg ),
				'reason_detail' => $msg,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_pando_status') ) {
		/**
		 * Pando is "available" only when the index exists and at least one query path works:
		 * local flexicorp-pando CLI, or FQS (HTTP) when the corpus is registered and reachable.
		 * Index-only (pando/ present, no binary, FQS down) must not advertise pando as a query engine
		 * or sessions/URLs that force query_engine=pando will hit "CLI not found" with 0 hits.
		 */
		function tt_flexicorp_pando_status( $projectRoot ) {
			static $memo = array();
			$rootKey = $projectRoot ? rtrim( (string) $projectRoot, '/' ) : '';
			if ( $rootKey !== '' && isset( $memo[ $rootKey ] ) ) {
				return $memo[ $rootKey ];
			}
			if ( !$projectRoot || !is_dir($projectRoot) ) {
				$out = array( 'available' => false, 'reason' => 'Project root missing or not a directory.' );
				if ( $rootKey !== '' ) {
					$memo[ $rootKey ] = $out;
				}
				return $out;
			}
			$pandoDir = function_exists('getset') ? trim((string)getset('pando/path', '')) : '';
			if ( $pandoDir === '' ) {
				$pandoDir = rtrim($projectRoot, '/') . '/pando';
			} elseif ( $pandoDir[0] !== '/' ) {
				$pandoDir = rtrim($projectRoot, '/') . '/' . $pandoDir;
			}
			if ( !is_dir($pandoDir) ) {
				$out = array( 'available' => false, 'reason' => 'Pando folder not found: ' . basename($pandoDir) . '.' );
				$memo[ $rootKey ] = $out;
				return $out;
			}
			$cliBin = '';
			if ( function_exists( 'tt_flexicorp_pando_cli_bin' ) ) {
				$cliBin = tt_flexicorp_pando_cli_bin( $projectRoot );
			}
			$fqsProbe = null;
			if ( function_exists( 'tt_flexicorp_fqs_probe' ) ) {
				$fqsProbe = tt_flexicorp_fqs_probe( $projectRoot, false );
			}
			$fqsReady = is_array( $fqsProbe ) && ! empty( $fqsProbe['ready_for_queries'] );
			if ( $cliBin !== '' || $fqsReady ) {
				$reason = 'Pando index folder found: ' . $pandoDir . '.';
				if ( $fqsReady && $cliBin === '' ) {
					$reason = 'Pando index folder found; queries use FQS (no local flexicorp-pando binary required).';
				} elseif ( ! $fqsReady && $cliBin !== '' ) {
					$reason = 'Pando index folder found; queries use local flexicorp-pando.';
				} elseif ( $fqsReady && $cliBin !== '' ) {
					$reason = 'Pando index folder found; queries can use FQS or local flexicorp-pando.';
				}
				$out = array( 'available' => true, 'reason' => $reason );
				$memo[ $rootKey ] = $out;
				return $out;
			}
			$fqsWhy = '';
			if ( is_array( $fqsProbe ) ) {
				$fqsWhy = isset( $fqsProbe['reason'] ) ? trim( (string) $fqsProbe['reason'] ) : '';
			}
			if ( $fqsWhy === '' || $fqsWhy === 'ready' ) {
				$fqsWhy = 'FQS not ready for queries';
			}
			$out = array(
				'available' => false,
				'reason' => 'Pando index exists (' . basename( $pandoDir ) . ') but there is no runnable query path: flexicorp-pando is not installed or not configured (set pando/flexicorp_pando_bin), and ' . $fqsWhy . '.',
			);
			$memo[ $rootKey ] = $out;
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_pando_index_dir') ) {
		/**
		 * Resolved Pando index directory (same rules as tt_flexicorp_pando_status).
		 * Daemon queries must use this path so corpus open matches configured pando/path.
		 */
		function tt_flexicorp_pando_index_dir( $projectRoot ) {
			$pandoDir = function_exists('getset') ? trim((string)getset('pando/path', '')) : '';
			if ( $pandoDir === '' ) {
				return rtrim((string)$projectRoot, '/') . '/pando';
			}
			if ( $pandoDir[0] !== '/' ) {
				return rtrim((string)$projectRoot, '/') . '/' . $pandoDir;
			}
			return $pandoDir;
		}
	}

	if ( !function_exists('tt_flexicorp_tree_max_mtime' ) ) {
		/**
		 * Newest modification time under $dir for files matching $ext (e.g. "xml") or all files if $ext is null.
		 * Caps file visits for very large trees.
		 *
		 * @param string|null $ext Lowercase extension without dot, or null for any file.
		 * @return int|null Unix mtime or null if nothing matched.
		 */
		function tt_flexicorp_tree_max_mtime( $dir, $ext, $maxFiles = 20000 ) {
			$dir = (string) $dir;
			if ( $dir === '' || ! is_dir( $dir ) ) {
				return null;
			}
			$maxFiles = max( 1, intval( $maxFiles ) );
			$max = 0;
			$n = 0;
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::SELF_FIRST
				);
				foreach ( $it as $f ) {
					if ( ++$n > $maxFiles ) {
						break;
					}
					if ( ! $f->isFile() ) {
						continue;
					}
					if ( $ext !== null && $ext !== '' ) {
						$e = strtolower( pathinfo( $f->getFilename(), PATHINFO_EXTENSION ) );
						if ( $e !== strtolower( (string) $ext ) ) {
							continue;
						}
					}
					$m = $f->getMTime();
					if ( $m > $max ) {
						$max = $m;
					}
				}
			} catch ( Exception $e ) {
				return null;
			}
			return $max > 0 ? $max : null;
		}
	}

	if ( !function_exists( 'tt_flexicorp_pando_index_freshness_hint' ) ) {
		/**
		 * Heuristic for admins: xmlfiles vs Pando index vs xidx/tokens.bin mtimes.
		 * Does not prove token alignment — only flags likely stale or split builds.
		 *
		 * @return array{level:string,code:string,message:string,detail:array}|null
		 */
		function tt_flexicorp_pando_index_freshness_hint( $projectRoot ) {
			if ( function_exists( 'getset' ) && getset( 'flexicorp/disable_pando_freshness_hint', false ) ) {
				return null;
			}
			$root = rtrim( (string) $projectRoot, '/' );
			if ( $root === '' || ! is_dir( $root ) ) {
				return null;
			}
			$pandoSt = tt_flexicorp_pando_status( $root );
			if ( empty( $pandoSt['available'] ) ) {
				return null;
			}
			$xmlDir = $root . '/xmlfiles';
			if ( ! is_dir( $xmlDir ) ) {
				return null;
			}
			$indexDir = tt_flexicorp_pando_index_dir( $root );
			$xidxDir = $root . '/xidx';
			$tokensBin = $xidxDir . '/tokens.bin';

			$xmlMax = tt_flexicorp_tree_max_mtime( $xmlDir, 'xml', 25000 );
			// Build time of the Pando index: corpus.info, which pando-index writes last. The
			// newest file in the directory is not that: files derived later (caches, packed
			// postings, an --upgrade) move it forward without a rebuild from the XML.
			$pandoInfo = $indexDir . '/corpus.info';
			$pandoMax = is_file( $pandoInfo ) ? @filemtime( $pandoInfo ) : null;
			if ( ! $pandoMax ) {
				$pandoMax = is_dir( $indexDir ) ? tt_flexicorp_tree_max_mtime( $indexDir, null, 400000 ) : null;
			}
			$xidxMax = null;
			if ( is_file( $tokensBin ) ) {
				$xidxMax = @filemtime( $tokensBin );
				if ( ! $xidxMax ) {
					$xidxMax = null;
				}
			} elseif ( is_dir( $xidxDir ) ) {
				$xidxMax = tt_flexicorp_tree_max_mtime( $xidxDir, null, 100000 );
			}

			$detail = array(
				'xml_max' => $xmlMax,
				'pando_max' => $pandoMax,
				'xidx_max' => $xidxMax,
			);

			if ( is_dir( $indexDir ) && ! is_file( $tokensBin ) ) {
				return array(
					'level' => 'warn',
					'code' => 'missing_xidx',
					'message' => 'xidx/tokens.bin is missing while a Pando index exists. Run flexicorp-pando reindex (flexencoder writes project/xidx and pando/ in one pass).',
					'detail' => $detail,
				);
			}

			$slack = 5;
			$indexRef = null;
			if ( $pandoMax !== null && $xidxMax !== null ) {
				$indexRef = min( $pandoMax, $xidxMax );
			} elseif ( $pandoMax !== null ) {
				$indexRef = $pandoMax;
			} elseif ( $xidxMax !== null ) {
				$indexRef = $xidxMax;
			}

			if ( $xmlMax !== null && $indexRef !== null && $xmlMax > $indexRef + $slack ) {
				return array(
					'level' => 'warn',
					'code' => 'xml_newer_than_index',
					'message' => 'Files under xmlfiles/ are newer than the Pando/xidx indexes. Re-run flexicorp-pando reindex so queries and TEITOK XML fragments stay aligned.',
					'detail' => $detail,
				);
			}

			// One flexencoder run writes xidx first and pando-index finishes after it (for a
			// large corpus many minutes later), so only xidx being *newer* than the Pando
			// build means they come from different runs (xidx rebuilt, Pando not).
			if ( $pandoMax !== null && $xidxMax !== null && $xidxMax - $pandoMax > 600 ) {
				return array(
					'level' => 'warn',
					'code' => 'pando_xidx_skew',
					'message' => 'xidx is newer than the Pando index (they were built in separate runs). Run a full flexencoder reindex to refresh both.',
					'detail' => $detail,
				);
			}

			return null;
		}
	}

	if ( !function_exists('tt_flexicorp_local_docs_from_xmlfiles') ) {
		function tt_flexicorp_local_docs_from_xmlfiles( $projectRoot, $limit = 50, $offset = 0 ) {
			$xmlDir = rtrim((string)$projectRoot, '/') . '/xmlfiles';
			if ( !is_dir($xmlDir) ) {
				return array( 'docs' => array(), 'total' => 0, 'returned' => 0, 'start' => max(0, intval($offset)) );
			}
			$limit = max(1, intval($limit));
			$offset = max(0, intval($offset));
			$files = glob($xmlDir . '/*.xml');
			if ( !is_array($files) ) $files = array();
			natcasesort($files);
			$files = array_values($files);
			$total = count($files);
			$slice = array_slice($files, $offset, $limit);
			$docs = array();
			foreach ( $slice as $filePath ) {
				$base = basename((string)$filePath);
				$docId = preg_replace('/\.xml$/i', '', $base);
				$relPath = 'xmlfiles/' . $base;
				$docs[] = array(
					'id' => (string)$docId,
					'title' => (string)$docId,
					'relative_path' => $relPath,
					'meta' => array(
						'filepath' => $relPath,
					),
				);
			}
			return array(
				'docs' => $docs,
				'total' => $total,
				'returned' => count($docs),
				'start' => $offset,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_clickhouse_status') ) {
		/**
		 * ClickHouse/ClickQL availability via the same python -m flexicorp runtime used by queries.
		 * Returns a compact status so PHP-side backend gating stays aligned with runtime behavior.
		 */
		function tt_flexicorp_clickhouse_status( $projectRoot, $extraArgs = array() ) {
			static $memo = array();
			$rootKey = rtrim((string)$projectRoot, '/');
			$argsKey = '';
			if ( is_array($extraArgs) && count($extraArgs) > 0 ) {
				$argsKey = md5(json_encode(array_values($extraArgs)));
			}
			$memoKey = $rootKey . '|' . $argsKey;
			if ( isset($memo[$memoKey]) ) {
				return $memo[$memoKey];
			}
			$out = array(
				'available' => false,
				'reason' => 'ClickHouse status not checked.',
			);
			if ( !function_exists('tt_flexicorp_run') || !function_exists('tt_flexicorp_done') ) {
				$out['reason'] = 'ClickHouse runtime probe unavailable in this PHP process.';
				$memo[$memoKey] = $out;
				return $out;
			}
			$call = tt_flexicorp_run(
				array('status'),
				'clickhouse',
				$projectRoot,
				is_array($extraArgs) ? $extraArgs : array(),
				'clickql',
				'clickcql',
				'clickhouse'
			);
			$done = tt_flexicorp_done($call);
			$result = ( isset($done['result']) && is_array($done['result']) ) ? $done['result'] : array();
			$errors = ( isset($done['errors']) && is_array($done['errors']) ) ? $done['errors'] : array();
			if ( isset($result['available']) ) {
				$out['available'] = !empty($result['available']);
				$out['reason'] = trim((string)($result['reason'] ?? ''));
			} elseif ( isset($result['backendStatus']['clickhouse']) && is_array($result['backendStatus']['clickhouse']) ) {
				$st = $result['backendStatus']['clickhouse'];
				$out['available'] = !empty($st['available']);
				$out['reason'] = trim((string)($st['reason'] ?? ''));
			} elseif ( !empty($call['ok']) ) {
				$out['available'] = true;
				$out['reason'] = 'ClickHouse backend is available.';
			}
			if ( $out['reason'] === '' ) {
				if ( count($errors) > 0 ) {
					$out['reason'] = trim((string)$errors[0]);
				} elseif ( !empty($call['error']) ) {
					$out['reason'] = trim((string)$call['error']);
				}
			}
			if ( $out['reason'] === '' ) {
				$out['reason'] = $out['available']
					? 'ClickHouse backend is available.'
					: 'ClickHouse backend is not available for this corpus.';
			}
			$memo[$memoKey] = $out;
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_guess_pmltq_treebank') ) {
		function tt_flexicorp_guess_pmltq_treebank( $projectRoot ) {
			$explicit = trim((string)($_REQUEST['pmltq_treebank'] ?? ''));
			if ( $explicit === '' && function_exists('getset') ) {
				$explicit = trim((string)getset('defaults/flexicorp/pmltq_treebank', ''));
			}
			if ( $explicit === '' && function_exists('getset') ) {
				$explicit = trim((string)getset('pmltq/treebank', ''));
			}
			if ( $explicit !== '' ) return $explicit;

			$cqpCorpus = function_exists('getset') ? trim((string)getset('cqp/corpus', '')) : '';
			if ( $cqpCorpus !== '' ) {
				$norm = strtolower($cqpCorpus);
				$norm = str_replace('-', '_', $norm);
				if ( $norm !== '' ) return $norm;
			}

			$baseName = basename(rtrim((string)$projectRoot, '/'));
			if ( $baseName === '' ) return '';
			return str_replace('-', '_', 'tt_' . strtolower($baseName));
		}
	}

	if ( !function_exists('tt_flexicorp_pmltq_status') ) {
		function tt_flexicorp_pmltq_status( $projectRoot, $extraArgs = array() ) {
			static $memo = array();
			$rootKey = rtrim((string)$projectRoot, '/');
			$argsKey = '';
			if ( is_array($extraArgs) && count($extraArgs) > 0 ) {
				$argsKey = md5(json_encode(array_values($extraArgs)));
			}
			$memoKey = $rootKey . '|' . $argsKey;
			if ( isset($memo[$memoKey]) ) return $memo[$memoKey];

			$out = array(
				'available' => false,
				'reason' => 'PMLTQ status not checked.',
			);
			if ( !function_exists('tt_flexicorp_run') || !function_exists('tt_flexicorp_done') ) {
				$out['reason'] = 'PMLTQ runtime probe unavailable in this PHP process.';
				$memo[$memoKey] = $out;
				return $out;
			}

			$call = tt_flexicorp_run(
				array('status'),
				'pmltq',
				$projectRoot,
				is_array($extraArgs) ? $extraArgs : array(),
				'pmltq',
				'pmltq',
				'pmltq'
			);
			$done = tt_flexicorp_done($call);
			$result = ( isset($done['result']) && is_array($done['result']) ) ? $done['result'] : array();
			$errors = ( isset($done['errors']) && is_array($done['errors']) ) ? $done['errors'] : array();
			if ( isset($result['available']) ) {
				$out['available'] = !empty($result['available']);
				$out['reason'] = trim((string)($result['reason'] ?? ''));
			} elseif ( isset($result['backendStatus']['pmltq']) && is_array($result['backendStatus']['pmltq']) ) {
				$st = $result['backendStatus']['pmltq'];
				$out['available'] = !empty($st['available']);
				$out['reason'] = trim((string)($st['reason'] ?? ''));
			} elseif ( !empty($call['ok']) ) {
				$out['available'] = true;
				$out['reason'] = 'PMLTQ backend is available.';
			}
			if ( $out['reason'] === '' ) {
				if ( count($errors) > 0 ) {
					$out['reason'] = trim((string)$errors[0]);
				} elseif ( !empty($call['error']) ) {
					$out['reason'] = trim((string)$call['error']);
				}
			}
			if ( $out['reason'] === '' ) {
				$out['reason'] = $out['available']
					? 'PMLTQ backend is available.'
					: 'PMLTQ backend is not available for this corpus.';
			}
			$memo[$memoKey] = $out;
			return $out;
		}
	}

	/**
	 * List backend ids that are available (functional folder + required data).
	 */
	if ( !function_exists('tt_flexicorp_available_backends') ) {
		function tt_flexicorp_available_backends( $projectRoot ) {
			$list = array();
			if ( !empty(tt_flexicorp_pando_status($projectRoot)['available']) ) {
				$list[] = 'pando';
			}
			if ( tt_flexicorp_cqp_available($projectRoot) ) $list[] = 'cqp';
			if ( tt_flexicorp_manatee_available($projectRoot) ) $list[] = 'manatee';
			$pmltqSt = tt_flexicorp_pmltq_status($projectRoot);
			if ( !empty($pmltqSt['available']) ) {
				$list[] = 'pmltq';
			}
			// Legacy escape hatch only when no primary engine is usable, or when explicitly enabled.
			$showLegacy = function_exists( 'tt_flexicorp_show_legacy_backends' )
				? tt_flexicorp_show_legacy_backends( false )
				: false;
			$clickSt = tt_flexicorp_clickhouse_status($projectRoot);
			if ( $showLegacy || empty( $list ) ) {
				$cqpOk = ! empty( tt_flexicorp_cqp_status_resolved( $projectRoot )['available'] );
				$manateeFiles = ! empty( tt_flexicorp_manatee_status( $projectRoot )['corpus_available'] );
				if ( $cqpOk || $manateeFiles ) {
					$list[] = 'flexi';
				}
				if ( ! empty( $clickSt['available'] ) ) {
					$list[] = 'clickql';
					$list[] = 'clickhouse';
				}
			}
			return $list;
		}
	}

	/**
	 * List query engine ids available for flexi (cqp / manatee) so UI can offer switch.
	 */
	if ( !function_exists('tt_flexicorp_available_query_engines') ) {
		function tt_flexicorp_available_query_engines( $projectRoot ) {
			$list = array();
			if ( tt_flexicorp_cqp_available($projectRoot) ) $list[] = 'cqp';
			if ( tt_flexicorp_manatee_available($projectRoot) ) $list[] = 'manatee';
			if ( !empty(tt_flexicorp_pando_status($projectRoot)['available']) ) $list[] = 'pando';
			$clickSt = tt_flexicorp_clickhouse_status($projectRoot);
			if ( !empty($clickSt['available']) ) {
				$list[] = 'clickql';
				$list[] = 'clickhouse';
			}
			$pmltqSt = tt_flexicorp_pmltq_status($projectRoot);
			if ( !empty($pmltqSt['available']) ) $list[] = 'pmltq';
			return $list;
		}
	}

	/**
	 * Full status for all backends and query engines: id => [ available, reason, label ] for overview.
	 */
	if ( !function_exists('tt_flexicorp_backend_engine_overview') ) {
		function tt_flexicorp_backend_engine_overview( $projectRoot ) {
			$backends = array(
				'pando' => array( 'label' => 'pando', 'available' => false, 'reason' => '' ),
				'cqp'       => array( 'label' => 'cqp', 'available' => false, 'reason' => '' ),
				'manatee'   => array( 'label' => 'manatee', 'available' => false, 'reason' => '' ),
				'pmltq' => array( 'label' => 'pmltq', 'available' => false, 'reason' => '' ),
				'flexi'     => array( 'label' => 'flexi', 'available' => false, 'reason' => 'Legacy escape hatch (hidden unless flexicorp/show_legacy_backends).' ),
				'clickql' => array( 'label' => 'clickql', 'available' => false, 'reason' => 'Deprecated ClickHouse query path.' ),
				'clickhouse' => array( 'label' => 'clickhouse', 'available' => false, 'reason' => 'Deprecated ClickHouse SQL path.' ),
				'teitokxml' => array( 'label' => 'teitokxml', 'available' => true, 'reason' => 'TEITOK XML files backend (doclist.sqlite).' ),
			);
			$cqpSt = tt_flexicorp_cqp_status_resolved( $projectRoot );
			$backends['cqp']['available'] = $cqpSt['available'];
			$backends['cqp']['reason'] = $cqpSt['reason'];
			$manateeSt = tt_flexicorp_manatee_status( $projectRoot );
			$backends['manatee']['available'] = $manateeSt['available'];
			$backends['manatee']['reason'] = $manateeSt['reason'];
			$pandoSt = tt_flexicorp_pando_status( $projectRoot );
			$backends['pando']['available'] = $pandoSt['available'];
			$backends['pando']['reason'] = $pandoSt['reason'];
			$clickSt = tt_flexicorp_clickhouse_status( $projectRoot );
			$backends['clickql']['available'] = !empty($clickSt['available']);
			$backends['clickql']['reason'] = (string)($clickSt['reason'] ?? '');
			$backends['clickhouse']['available'] = !empty($clickSt['available']);
			$backends['clickhouse']['reason'] = (string)($clickSt['reason'] ?? '');
			$pmltqSt = tt_flexicorp_pmltq_status( $projectRoot );
			$backends['pmltq']['available'] = !empty($pmltqSt['available']);
			$backends['pmltq']['reason'] = (string)($pmltqSt['reason'] ?? '');
			$engines = array(
				'cqp'       => array( 'label' => 'cqp', 'available' => $cqpSt['available'], 'reason' => $cqpSt['reason'] ),
				'manatee'   => array( 'label' => 'manatee', 'available' => $manateeSt['available'], 'reason' => $manateeSt['reason'] ),
				'pando'     => array( 'label' => 'pando', 'available' => $pandoSt['available'], 'reason' => $pandoSt['reason'] ),
				'clickql'   => array( 'label' => 'clickql', 'available' => !empty($clickSt['available']), 'reason' => (string)($clickSt['reason'] ?? '') ),
				'clickhouse' => array( 'label' => 'clickhouse', 'available' => !empty($clickSt['available']), 'reason' => (string)($clickSt['reason'] ?? '') ),
				'pmltq'     => array( 'label' => 'pmltq', 'available' => !empty($pmltqSt['available']), 'reason' => (string)($pmltqSt['reason'] ?? '') ),
				'teitokxml' => array( 'label' => 'teitokxml', 'available' => true, 'reason' => 'TEITOK XML files backend (doclist.sqlite).' ),
			);
			return array( 'backends' => $backends, 'queryEngines' => $engines );
		}
	}

	if ( !function_exists('tt_flexicorp_backend_combinations') ) {
		/**
		 * @param array|null $manateeResolved Optional result of tt_flexicorp_manatee_project_status_resolved().
		 *        When set, the native `manatee:manatee-cql:manatee` row uses it (matches `info corpus` gating).
		 * @param bool       $isAdmin         Passed to FQS probe (JWT role); default false (visitor).
		 */
		function tt_flexicorp_backend_combinations( $projectRoot, $manateeResolved = null, $isAdmin = false ) {
			$cqpSt = tt_flexicorp_cqp_status_resolved( $projectRoot );
			$manateeSt = tt_flexicorp_manatee_status( $projectRoot );
			$pandoSt = tt_flexicorp_pando_status( $projectRoot );
			$clickSt = tt_flexicorp_clickhouse_status( $projectRoot );
			$nativeManateeAvail = ! empty( $manateeSt['available'] );
			$nativeManateeReason = isset( $manateeSt['reason'] ) ? (string) $manateeSt['reason'] : '';
			if ( is_array( $manateeResolved ) ) {
				$nativeManateeAvail = ! empty( $manateeResolved['available'] );
				$nativeManateeReason = isset( $manateeResolved['reason'] ) ? (string) $manateeResolved['reason'] : $nativeManateeReason;
			}

			$combos = array();

			$pandoComboReason = isset( $pandoSt['reason'] ) ? (string) $pandoSt['reason'] : '';
			if ( ! empty( $pandoSt['available'] ) ) {
				$fqsProbe = tt_flexicorp_fqs_probe( $projectRoot, $isAdmin );
				$fqsWhy = isset( $fqsProbe['reason'] ) ? trim( (string) $fqsProbe['reason'] ) : '';
				if ( $fqsWhy !== '' ) {
					$fqsWhy = ' (' . $fqsWhy . ')';
				}
				if ( ! empty( $fqsProbe['ready_for_queries'] ) ) {
					$pandoComboReason = 'Pando index available. Queries for this engine are sent through FQS (the HTTP query/catalog service).';
				} elseif ( function_exists( 'tt_flexicorp_pando_runtime_info' ) ) {
					$pri = tt_flexicorp_pando_runtime_info( $projectRoot );
					$daemonUp = ! empty( $pri['daemon_pid'] );
					if ( $daemonUp ) {
						$pandoComboReason = 'Pando index available. FQS is not ready for query routing' . $fqsWhy . ', so queries use the local flexicorp-pando daemon (Unix socket).';
					} else {
						$pandoComboReason = 'Pando index available. FQS is not ready for query routing' . $fqsWhy . ', so queries use the flexicorp-pando CLI.';
					}
				} else {
					$pandoComboReason = 'Pando index available. FQS is not ready for query routing' . $fqsWhy . '; queries use flexicorp-pando on this server instead of FQS.';
				}
			}

			// Primary engines first.
			$combos[] = array(
				'id' => 'pando:pando-cql:pando',
				'backend' => 'pando',
				'queryLanguage' => 'pando-cql',
				'corpusFormat' => 'pando',
				'available' => !empty($pandoSt['available']),
				'reason' => $pandoComboReason,
				'help_url' => 'help-pando-cql',
				'capabilities' => array(
					'reindex' => true,
					'stats_keyness' => false,
					'stats_collocations' => true,
					'stats_dep_collocations' => false,
				),
			);

			$combos[] = array(
				'id' => 'cqp:cwb-cql:cwb',
				'backend' => 'cqp',
				'queryLanguage' => 'cwb-cql',
				'corpusFormat' => 'cwb',
				'available' => !empty($cqpSt['available']),
				'reason' => $cqpSt['reason'],
				'capabilities' => array(
					'reindex' => true,
					'stats_keyness' => false,
					'stats_collocations' => false,
					'stats_dep_collocations' => false,
				),
			);

			$combos[] = array(
				'id' => 'manatee:manatee-cql:manatee',
				'backend' => 'manatee',
				'queryLanguage' => 'manatee-cql',
				'corpusFormat' => 'manatee',
				'available' => $nativeManateeAvail,
				'reason' => $nativeManateeReason,
				'capabilities' => array(
					'reindex' => true,
					'stats_keyness' => false,
					'stats_collocations' => false,
					'stats_dep_collocations' => false,
				),
			);

			// Legacy escape hatches (hidden for visitors unless flexicorp/show_legacy_backends).
			$combos[] = array(
				'id' => 'flexi:cwb-cql:cwb',
				'backend' => 'flexi',
				'queryLanguage' => 'cwb-cql',
				'corpusFormat' => 'cwb',
				'available' => !empty($cqpSt['available']),
				'reason' => !empty($cqpSt['reason']) ? $cqpSt['reason'] : (!empty($cqpSt['available']) ? 'CWB/CQP index available.' : 'CWB/CQP index not available for this corpus.'),
				'legacy' => true,
				'capabilities' => array(
					'reindex' => false,
					'stats_keyness' => false,
					'stats_collocations' => false,
					'stats_dep_collocations' => false,
				),
			);

			$combos[] = array(
				'id' => 'flexi:manatee-cql:manatee',
				'backend' => 'flexi',
				'queryLanguage' => 'manatee-cql',
				'corpusFormat' => 'manatee',
				'available' => !empty($manateeSt['corpus_available']),
				'reason' => !empty($manateeSt['corpus_available']) ? 'Manatee index available for this corpus (flexi native file path).' : 'Manatee index not available for this corpus.',
				'legacy' => true,
				'capabilities' => array(
					'reindex' => false,
					'stats_keyness' => false,
					'stats_collocations' => false,
					'stats_dep_collocations' => false,
				),
			);

			$clickReason = isset($clickSt['reason']) ? (string)$clickSt['reason'] : '';
			$combos[] = array(
				'id' => 'clickql:clickcql:clickhouse',
				'backend' => 'clickql',
				'queryLanguage' => 'clickcql',
				'corpusFormat' => 'clickhouse',
				'available' => !empty($clickSt['available']),
				'reason' => $clickReason,
				'legacy' => true,
				'capabilities' => array(
					'reindex' => true,
					'stats_keyness' => false,
					'stats_collocations' => false,
					'stats_dep_collocations' => false,
				),
			);
			$combos[] = array(
				'id' => 'clickhouse:sql:clickhouse',
				'backend' => 'clickhouse',
				'queryLanguage' => 'sql',
				'corpusFormat' => 'clickhouse',
				'available' => !empty($clickSt['available']),
				'reason' => $clickReason,
				'legacy' => true,
				'capabilities' => array(
					'reindex' => true,
					'stats_keyness' => false,
					'stats_collocations' => false,
					'stats_dep_collocations' => false,
				),
			);

			// TEITOK XML files backend (no query support, documents only)
			$combos[] = array(
				'id' => 'teitokxml:teitok:xml',
				'backend' => 'teitokxml',
				'queryLanguage' => 'teitok',
				'corpusFormat' => 'xml',
				'available' => true,
				'reason' => 'Lightweight backend over TEITOK xmlfiles/ and tmp/doclist.sqlite.',
				'legacy' => true,
			);

			return $combos;
		}
	}

	if ( !function_exists('tt_flexicorp_public_combo_ids') ) {
		function tt_flexicorp_public_combo_ids() {
			if ( !function_exists('getset') ) return array();
			$raw = (string) getset('flexicorp/public_combos', '');
			if ( trim($raw) === '' ) return array();
			$parts = preg_split('/[\s,;]+/', $raw);
			$out = array();
			foreach ( $parts as $p ) {
				$p = trim((string)$p);
				if ( $p !== '' ) $out[$p] = true;
			}
			return array_keys($out);
		}
	}

	if ( !function_exists('tt_flexicorp_external_engines') ) {
		function tt_flexicorp_external_engines( $projectRoot ) {
			$list = array();
			if ( !function_exists('getset') ) return $list;
			$raw = (string) getset('flexicorp/external_engines', '');
			if ( trim($raw) === '' ) return $list;
			$lines = preg_split("/\r\n|\r|\n/", $raw);
			foreach ( $lines as $idx => $line ) {
				$line = trim($line);
				if ( $line === '' ) continue;
				$parts = explode("\t", $line, 2);
				$label = trim((string)($parts[0] ?? ''));
				$url = trim((string)($parts[1] ?? ''));
				if ( $label === '' || $url === '' ) continue;
				$list[] = array(
					'id' => 'ext' . $idx,
					'label' => $label,
					'url' => $url,
				);
			}
			return $list;
		}
	}

	if ( !function_exists('tt_flexicorp_preferred_combo') ) {
		function tt_flexicorp_preferred_combo( $combos, $preferredBackend = '' ) {
			if ( !is_array($combos) ) return null;
			$available = array_values(array_filter($combos, function ($combo) {
				return is_array($combo)
					&& !empty($combo['available'])
					&& !empty($combo['backend'])
					&& $combo['backend'] !== 'teitokxml';
			}));
			if ( empty($available) ) return null;

			$indexById = array();
			foreach ( $available as $combo ) {
				$indexById[(string)($combo['id'] ?? '')] = $combo;
			}

			$order = array();
			$preferredBackend = tt_flexicorp_backend_canonical(trim((string)$preferredBackend));
			if ( $preferredBackend === 'pando' ) $order[] = 'pando:pando-cql:pando';
			if ( $preferredBackend === 'cqp' ) $order[] = 'cqp:cwb-cql:cwb';
			if ( $preferredBackend === 'manatee' ) $order[] = 'manatee:manatee-cql:manatee';
			if ( $preferredBackend === 'blacklab' ) $order[] = 'blacklab:bcql:blacklab';
			if ( $preferredBackend === 'pmltq' ) $order[] = 'pmltq:pmltq:pmltq';
			if ( $preferredBackend === 'clickql' ) $order[] = 'clickql:clickcql:clickhouse';
			if ( $preferredBackend === 'clickhouse' ) $order[] = 'clickql:sql:clickhouse';
			if ( $preferredBackend === 'flexi' ) {
				$order[] = 'flexi:manatee-cql:manatee';
				$order[] = 'flexi:cwb-cql:cwb';
			}

			// Auto / fallback: primary engines first; legacy flexi + ClickHouse last.
			$order = array_merge($order, array(
				'pando:pando-cql:pando',
				'cqp:cwb-cql:cwb',
				'manatee:manatee-cql:manatee',
				'blacklab:bcql:blacklab',
				'pmltq:pmltq:pmltq',
				'flexi:manatee-cql:manatee',
				'flexi:cwb-cql:cwb',
				'clickql:clickcql:clickhouse',
				'clickql:sql:clickhouse',
				'clickhouse:sql:clickhouse',
			));

			foreach ( $order as $comboId ) {
				if ( isset($indexById[$comboId]) ) return $indexById[$comboId];
			}
			// Prefer a non-legacy available combo when the ordered list missed.
			foreach ( $available as $combo ) {
				$b = tt_flexicorp_backend_canonical( (string) ( $combo['backend'] ?? '' ) );
				if ( ! tt_flexicorp_is_legacy_backend( $b ) ) {
					return $combo;
				}
			}
			return $available[0];
		}
	}

	if ( !function_exists('tt_flexicorp_clamp_int') ) {
		function tt_flexicorp_clamp_int($value, $default, $min, $max) {
			$num = intval($value);
			if ( $num <= 0 ) $num = intval($default);
			if ( $num < $min ) $num = $min;
			if ( $num > $max ) $num = $max;
			return $num;
		}
	}

	/**
	 * Default KWIC / token context width (tokens on each side of the hit). TEITOK: getset( 'flexicorp/kwic_window', '' )
	 * — empty, -, or "default" uses 10. Clamped 1–50.
	 */
	if ( !function_exists('tt_flexicorp_default_kwic_window') ) {
		function tt_flexicorp_default_kwic_window() {
			$def = 10;
			$raw = function_exists( 'getset' ) ? trim( (string) getset( 'flexicorp/kwic_window', '' ) ) : '';
			$lr = strtolower( $raw );
			if ( $raw === '' || $raw === '-' || $lr === 'default' ) {
				if ( function_exists( 'getset' ) ) {
					$cqpKw = trim( (string) getset( 'cqp/defaults/kwic', '' ) );
					$ck = strtolower( $cqpKw );
					if ( $cqpKw !== '' && $cqpKw !== '-' && $ck !== 'default' ) {
						$n = intval( $cqpKw );
						if ( $n < 1 ) {
							$n = $def;
						}
						if ( $n > 50 ) {
							$n = 50;
						}
						return $n;
					}
				}
				return $def;
			}
			$n = intval( $raw );
			if ( $n < 1 ) {
				$n = $def;
			}
			if ( $n > 50 ) {
				$n = 50;
			}
			return $n;
		}
	}

	if ( !function_exists('tt_flexicorp_debug_log') ) {
		function tt_flexicorp_debug_log($entry) {
			global $tt_flexicorp_debug_calls;
			if ( !isset($tt_flexicorp_debug_calls) || !is_array($tt_flexicorp_debug_calls) ) {
				$tt_flexicorp_debug_calls = array();
			}
			$tt_flexicorp_debug_calls[] = $entry;
		}
	}

	if ( !function_exists('tt_flexicorp_json_encode_safe') ) {
		/**
		 * Encode payloads to JSON while tolerating malformed UTF-8 in backend raw output.
		 * Returns a JSON string on success, or false on hard failure.
		 */
		function tt_flexicorp_json_encode_safe($value, $flags = 0) {
			$baseFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
			if ( defined('JSON_INVALID_UTF8_SUBSTITUTE') ) {
				$baseFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
			}
			$json = json_encode($value, $baseFlags | $flags);
			if ( $json !== false ) return $json;

			if ( function_exists('mb_convert_encoding') ) {
				$normalize = function ($v) use (&$normalize) {
					if ( is_string($v) ) return mb_convert_encoding($v, 'UTF-8', 'UTF-8');
					if ( is_array($v) ) {
						$out = array();
						foreach ( $v as $k => $vv ) $out[$k] = $normalize($vv);
						return $out;
					}
					return $v;
				};
				$json = json_encode($normalize($value), $baseFlags | $flags);
				if ( $json !== false ) return $json;
			}
			return false;
		}
	}

	/**
	 * Annotation / metadata keys (not struct regions) that can leak into corpus /info
	 * lists. These are always excluded as context scopes; structural region ids are
	 * chosen via the allowlist (getset: flexicorp/context_scope_regions) with a
	 * minimal default (s, u, lb, l, p, seg).
	 */
	if ( !function_exists('tt_flexicorp_context_scope_region_noise_blocklist') ) {
		function tt_flexicorp_context_scope_region_noise_blocklist() {
			static $bl = null;
			if ( is_array( $bl ) ) {
				return $bl;
			}
			$keys = array(
				'gloss', 'lemma', 'lemmagloss', 'form', 'pform', 'nform', 'norm', 'word', 'orth', 'phon',
				'pos', 'msd', 'feats', 'upos', 'xpos', 'deprel', 'dep', 'head', 'join', 'syn', 'sense',
				'trans', 'transl', 'lang', 'xml_lang', 'hand', 'resp', 'cert', 'style', 'rend', 'cite',
				'ana', 'corresp', 'function', 'class', 'subtype', 'type', 'ns', 't',
				'teiheader', 'tei', 'facsimile', 'surface', 'facs', 'bbox', 'baseline',
			);
			$bl = array();
			foreach ( $keys as $k ) {
				$bl[ $k ] = true;
			}
			return $bl;
		}
	}

	/** @return list<string> */
	if ( !function_exists('tt_flexicorp_context_scope_region_default_allowlist') ) {
		function tt_flexicorp_context_scope_region_default_allowlist() {
			// Min TEITOK / CQP-friendly defaults; add more via getset( flexicorp/context_scope_regions, … ).
			return array( 's', 'u', 'lb', 'l', 'p', 'seg' );
		}
	}

	/** User-facing names (e.g. line) → CWB / xidx ids (e.g. l) — same idea as flexicorp.js normalize. */
	if ( !function_exists('tt_flexicorp_normalize_context_scope_region_key') ) {
		function tt_flexicorp_normalize_context_scope_region_key( $r ) {
			$r = strtolower( trim( (string) $r ) );
			if ( $r === '' ) {
				return '';
			}
			$map = array(
				'sentence' => 's',
				'sent' => 's',
				'line' => 'l',
				'verse' => 'l',
				'paragraph' => 'p',
				'para' => 'p',
				'utterance' => 'u',
				'linebreak' => 'lb',
				'line_break' => 'lb',
				'document' => 'text',
				'doc' => 'text',
			);
			if ( isset( $map[ $r ] ) ) {
				return (string) $map[ $r ];
			}
			return $r;
		}
	}

	/**
	 * getset( flexicorp/context_scope_regions, … ): comma/space/semicolon-separated
	 * region ids, or empty / "default" / "-" for the built-in minimal allowlist.
	 *
	 * @param mixed $projectRoot unused; reserved for per-corpus resolution if getset is extended.
	 * @return list<string> ordered, deduped
	 */
	if ( !function_exists('tt_flexicorp_context_scope_region_allowlist_resolved') ) {
		function tt_flexicorp_context_scope_region_allowlist_resolved( $projectRoot = null ) {
			$raw = function_exists( 'getset' )
				? trim( (string) getset( 'flexicorp/context_scope_regions', '' ) )
				: '';
			unset( $projectRoot );
			$default = tt_flexicorp_context_scope_region_default_allowlist();
			$lraw = strtolower( $raw );
			if ( $raw === '' || $raw === '-' || $lraw === 'default' ) {
				return $default;
			}
			$parts = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			$out = array();
			$seen = array();
			foreach ( $parts as $p ) {
				$k = tt_flexicorp_normalize_context_scope_region_key( $p );
				if ( $k === '' ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $k ) ) {
					continue;
				}
				if ( ! isset( $seen[ $k ] ) ) {
					$seen[ $k ] = true;
					$out[] = $k;
				}
			}
			if ( ! count( $out ) ) {
				return $default;
			}
			return $out;
		}
	}

	if ( !function_exists( 'tt_flexicorp_filter_context_scope_regions' ) ) {
		function tt_flexicorp_filter_context_scope_regions( $regions, $projectRoot = null ) {
			if ( ! is_array( $regions ) ) {
				return array();
			}
			$noise = tt_flexicorp_context_scope_region_noise_blocklist();
			$allow = array_flip( tt_flexicorp_context_scope_region_allowlist_resolved( $projectRoot ) );
			$keep = array();
			foreach ( $regions as $r0 ) {
				$r = tt_flexicorp_normalize_context_scope_region_key( $r0 );
				if ( $r === '' || isset( $noise[ $r ] ) || ! isset( $allow[ $r ] ) ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $r ) ) {
					continue;
				}
				$keep[ $r ] = true;
			}
			return array_keys( $keep );
		}
	}

	/**
	 * TEITOK native CQP UI (`common/Sources/cqp.php`): when `cqp/defaults/searchtype`
	 * is `context`, results use structural expansion via `cqp/defaults/subtype` (passed to
	 * xidx as `--expand=<region>`). Mirror that as Flexicorp default `context_scope` when
	 * the request does not override it.
	 *
	 * Flexicorp follows TEITOK’s effective getset/CQP configuration here — TEITOK remains the
	 * reference for how corpora are indexed and expanded; do not diverge unless flexiTools
	 * intentionally changes behaviour.
	 *
	 * Returns null to keep backend defaults (`tok` / `window`). Honors `flexicorp/context_scope_regions`
	 * allowlist and requires a matching `cqp/sattributes/<region>` entry like TEITOK.
	 *
	 * @param mixed $projectRoot reserved for per-corpus resolution (same as allowlist helper).
	 * @return string|null normalized region id or null
	 */
	/**
	 * Write the project's effective CQP settings (shared + local, as TEITOK merges
	 * them) to tmp/cqpsettings.xml before a reindex. flexencoder and flexicorp read
	 * that file in preference to Resources/settings.xml, but TEITOK only rewrites it
	 * when it regenerates its own CQP index, so it can be months old (a missing
	 * sentence sattribute there means a pando index without sentences, hence without
	 * dependency trees). Uses TEITOK's makesettings(), the same merge recqp.php uses.
	 * If the file cannot be rewritten and is older than Resources/settings.xml, it is
	 * moved aside so the reindex reads Resources/settings.xml instead.
	 *
	 * @return array{ok: bool, action: string, message: string}
	 */
	if ( !function_exists( 'tt_flexicorp_write_cqpsettings' ) ) {
		function tt_flexicorp_write_cqpsettings( $projectRoot ) {
			global $settings;
			$root = rtrim( (string) $projectRoot, '/' );
			$tmpDir = $root . '/tmp';
			$target = $tmpDir . '/cqpsettings.xml';
			$canonical = $root . '/Resources/settings.xml';
			$xml = '';
			if ( function_exists( 'makesettings' ) ) {
				$merged = makesettings( $settings );
				if ( $merged instanceof SimpleXMLElement && isset( $merged->cqp->pattributes->item ) ) {
					$xml = (string) $merged->asXML();
				}
			}
			if ( $xml !== '' && ( is_dir( $tmpDir ) || @mkdir( $tmpDir, 0775, true ) ) ) {
				$part = $target . '.part-' . getmypid();
				if ( @file_put_contents( $part, $xml ) !== false && @rename( $part, $target ) ) {
					return array( 'ok' => true, 'action' => 'written', 'message' => "wrote $target" );
				}
				@unlink( $part );
			}
			// Could not write a fresh copy: never let a stale one win over the canonical file.
			if ( is_file( $target ) && is_file( $canonical ) && filemtime( $target ) < filemtime( $canonical ) ) {
				$aside = $target . '.stale-' . date( 'Ymd-His' );
				if ( @rename( $target, $aside ) ) {
					return array( 'ok' => true, 'action' => 'moved_stale',
						'message' => "tmp/cqpsettings.xml was older than Resources/settings.xml; moved to $aside" );
				}
				return array( 'ok' => false, 'action' => 'stale',
					'message' => "tmp/cqpsettings.xml is older than Resources/settings.xml and could not be replaced or moved" );
			}
			return array( 'ok' => true, 'action' => 'kept', 'message' => 'kept existing tmp/cqpsettings.xml' );
		}
	}

	/**
	 * True when the project has TEITOK XML files (xmlfiles/ with at least one .xml,
	 * at any depth up to 3). Corpora without them (e.g. a Pando index built straight
	 * from CoNLL-U) get the engine's synthetic XML fragments and sentence context.
	 */
	if ( !function_exists( 'tt_flexicorp_project_has_xml' ) ) {
		function tt_flexicorp_project_has_xml( $projectRoot = null ) {
			static $memo = array();
			$root = rtrim( (string) ( $projectRoot !== null && $projectRoot !== '' ? $projectRoot : getcwd() ), '/' );
			if ( isset( $memo[ $root ] ) ) return $memo[ $root ];
			$found = false;
			$dirs = array( array( $root . '/xmlfiles', 0 ) );
			while ( ! $found && $dirs ) {
				list( $d, $depth ) = array_shift( $dirs );
				$h = @opendir( $d );
				if ( ! $h ) continue;
				while ( ( $e = readdir( $h ) ) !== false ) {
					if ( $e === '.' || $e === '..' ) continue;
					if ( preg_match( '/\.xml$/i', $e ) ) { $found = true; break; }
					if ( $depth < 3 && is_dir( $d . '/' . $e ) ) $dirs[] = array( $d . '/' . $e, $depth + 1 );
				}
				closedir( $h );
			}
			return $memo[ $root ] = $found;
		}
	}

	if ( !function_exists( 'tt_flexicorp_default_context_scope_from_teitok_cqp' ) ) {
		function tt_flexicorp_default_context_scope_from_teitok_cqp( $projectRoot = null ) {
			if ( ! function_exists( 'getset' ) ) {
				return null;
			}
			$searchType = strtolower( trim( (string) getset( 'cqp/defaults/searchtype', '' ) ) );
			if ( $searchType !== 'context' ) {
				return null;
			}
			$subtypeRaw = trim( (string) getset( 'cqp/defaults/subtype', '' ) );
			if ( $subtypeRaw === '' ) {
				return null;
			}
			$lr = strtolower( $subtypeRaw );
			if ( $lr === 'tok' || $lr === 'token' || $lr === 'tokens' ) {
				return null;
			}
			$subtype = tt_flexicorp_normalize_context_scope_region_key( $subtypeRaw );
			if ( $subtype === '' ) {
				return null;
			}
			$sattr = getset( 'cqp/sattributes/' . $subtype, null );
			if ( ! is_array( $sattr ) || count( $sattr ) === 0 ) {
				return null;
			}
			$allowed = array_flip( tt_flexicorp_context_scope_region_allowlist_resolved( $projectRoot ) );
			if ( ! isset( $allowed[ $subtype ] ) ) {
				return null;
			}
			return $subtype;
		}
	}


	if ( !function_exists('tt_flexicorp_corpus_id_from_root') ) {
		function tt_flexicorp_corpus_id_from_root( $projectRoot ) {
			$projectRoot = str_replace('\\', '/', (string) $projectRoot);
			$base = basename(rtrim($projectRoot, '/'));
			$base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $base);
			$base = trim((string)$base, '_');
			return $base !== '' ? $base : 'corpus';
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_url_from_runtime_file') ) {
		/**
		 * Reads URL from fqs-http.json (written beside fqs.db when `fqs serve` binds).
		 * Discovery order: FQS_HTTP_JSON path, dirname(FQS_DB_PATH)/fqs-http.json, then common defaults.
		 */
		function tt_flexicorp_fqs_url_from_runtime_file() {
			$candidates = array();
			$explicit = getenv('FQS_HTTP_JSON');
			if ( is_string($explicit) && trim($explicit) !== '' ) {
				$candidates[] = trim($explicit);
			}
			$dbp = getenv('FQS_DB_PATH');
			if ( is_string($dbp) && $dbp !== '' ) {
				$candidates[] = rtrim(str_replace('\\', '/', dirname($dbp)), '/') . '/fqs-http.json';
			}
			$candidates[] = '/usr/local/var/fqs/fqs-http.json';
			$candidates[] = '/var/lib/fqs/fqs-http.json';
			$seen = array();
			foreach ( $candidates as $c ) {
				if ( isset($seen[$c]) ) continue;
				$seen[$c] = true;
				if ( ! is_readable($c) ) {
					continue;
				}
				$raw = @file_get_contents($c);
				if ( $raw === false || trim($raw) === '' ) {
					continue;
				}
				$j = json_decode($raw, true);
				if ( ! is_array($j) || empty($j['url']) ) {
					continue;
				}
				$u = trim((string) $j['url']);
				if ( $u !== '' ) {
					return rtrim($u, '/');
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_db_path_from_runtime_file') ) {
		/**
		 * Reads db_path from fqs-http.json (same discovery order as URL helper).
		 */
		function tt_flexicorp_fqs_db_path_from_runtime_file() {
			$candidates = array();
			$explicit = getenv('FQS_HTTP_JSON');
			if ( is_string($explicit) && trim($explicit) !== '' ) {
				$candidates[] = trim($explicit);
			}
			$dbp = getenv('FQS_DB_PATH');
			if ( is_string($dbp) && $dbp !== '' ) {
				$candidates[] = rtrim(str_replace('\\', '/', dirname($dbp)), '/') . '/fqs-http.json';
			}
			$candidates[] = '/usr/local/var/fqs/fqs-http.json';
			$candidates[] = '/var/lib/fqs/fqs-http.json';
			$seen = array();
			foreach ( $candidates as $c ) {
				if ( isset($seen[$c]) ) continue;
				$seen[$c] = true;
				if ( !is_readable($c) ) continue;
				$raw = @file_get_contents($c);
				if ( $raw === false || trim($raw) === '' ) continue;
				$j = json_decode($raw, true);
				if ( !is_array($j) ) continue;
				$db = trim((string)($j['db_path'] ?? ''));
				if ( $db !== '' ) return $db;
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_url') ) {
		function tt_flexicorp_fqs_url() {
			$url = '';
			if ( function_exists('getset') ) {
				$url = trim((string) getset('flexicorp/fqs_url', ''));
			}
			if ( $url === '' ) {
				$env = getenv('FQS_URL');
				if ( is_string($env) ) {
					$url = trim($env);
				}
			}
			if ( $url === '' && function_exists('tt_flexicorp_fqs_url_from_runtime_file') ) {
				$url = tt_flexicorp_fqs_url_from_runtime_file();
			}
			if ( $url === '' ) {
				// Local default for `fqs serve` when no explicit URL or fqs-http.json is configured.
				$url = 'http://127.0.0.1:8787';
			}
			return rtrim($url, '/');
		}
	}

	if ( ! function_exists( 'tt_fqs_secret_from_env_file' ) ) {
		/**
		 * FQS_SECRET from FQS's own environment file (`/etc/fqs/fqs.env`, root:fqs 0640;
		 * the installer puts www-data in group fqs), so TEITOK needs no copy of the
		 * secret. Path: setting flexicorp/fqs_env_file or env FQS_ENV_FILE. '' when
		 * not readable (then: setting flexicorp/fqs_secret or env FQS_SECRET).
		 */
		function tt_fqs_secret_from_env_file() {
			static $memo = null;
			if ( $memo !== null ) return $memo;
			$path = '';
			if ( function_exists( 'getset' ) ) {
				$g = getset( 'flexicorp/fqs_env_file', '' );
				if ( is_scalar( $g ) ) $path = trim( (string) $g );
			}
			if ( $path === '' ) {
				$e = getenv( 'FQS_ENV_FILE' );
				$path = is_string( $e ) && trim( $e ) !== '' ? trim( $e ) : '/etc/fqs/fqs.env';
			}
			$memo = '';
			$txt = @is_readable( $path ) ? @file_get_contents( $path ) : false;
			// the last assignment wins, as for systemd's EnvironmentFile and `source`
			if ( is_string( $txt ) && preg_match_all( '/^\s*(?:export\s+)?FQS_SECRET\s*=\s*["\']?([^"\'\s#]+)/m', $txt, $m ) ) {
				$memo = (string) end( $m[1] );
			}
			return $memo;
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_secret') ) {
		function tt_flexicorp_fqs_secret() {
			// FQS's own env file first (what fqs serve runs with), then copies
			if ( function_exists('tt_fqs_secret_from_env_file') ) {
				$fromFile = tt_fqs_secret_from_env_file();
				if ( $fromFile !== '' ) return $fromFile;
			}
			$secret = '';
			if ( function_exists('getset') ) {
				$secret = trim((string) getset('flexicorp/fqs_secret', ''));
			}
			if ( $secret === '' ) {
				$env = getenv('FQS_SECRET');
				if ( is_string($env) ) $secret = trim($env);
			}
			if ( $secret === '' && function_exists('tt_fqs_secret_from_env_file') ) {
				$secret = tt_fqs_secret_from_env_file();
			}
			return $secret;
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_bin') ) {
		/**
		 * Path to the `fqs` CLI.
		 *
		 * Convention: locate apps with TEITOK findapp("fqs") (same pattern as cqp, etc.); do not maintain
		 * a separate search list here. If `fqs` only exists under a build directory (e.g. target/release),
		 * extend findapp / TEITOK bin path configuration to include that directory rather than hard-coding
		 * paths in Flexicorp.
		 */
		function tt_flexicorp_fqs_bin() {
			$bin = '';
			if ( function_exists('findapp') ) {
				$bin = trim((string) findapp('fqs'));
			}
			if ( $bin === '' ) {
				$probe = trim((string) shell_exec('command -v fqs 2>/dev/null'));
				if ( $probe !== '' ) {
					$bin = $probe;
				}
			}
			return $bin;
		}
	}

	if ( !function_exists('tt_flexicorp_b64url') ) {
		function tt_flexicorp_b64url( $raw ) {
			$s = base64_encode((string)$raw);
			return rtrim(strtr($s, '+/', '-_'), '=');
		}
	}

	if ( !function_exists('tt_flexicorp_make_hs256_jwt') ) {
		function tt_flexicorp_make_hs256_jwt( $claims, $secret ) {
			if ( !is_array($claims) || trim((string)$secret) === '' ) return '';
			$header = array('alg' => 'HS256', 'typ' => 'JWT');
			$h = tt_flexicorp_b64url(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			$p = tt_flexicorp_b64url(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			$sig = hash_hmac('sha256', $h . '.' . $p, (string)$secret, true);
			return $h . '.' . $p . '.' . tt_flexicorp_b64url($sig);
		}
	}

	if ( !function_exists('tt_flexicorp_http_json_request') ) {
		function tt_flexicorp_http_json_request( $method, $url, $body = null, $headers = array(), $timeout = 8.0 ) {
			$method = strtoupper(trim((string)$method));
			if ( $method === '' ) $method = 'GET';
			$url = trim((string)$url);
			$payload = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$respBody = '';
			$httpCode = 0;
			$err = '';

			if ( function_exists('curl_init') ) {
				$ch = curl_init($url);
				$h = is_array($headers) ? $headers : array();
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
				curl_setopt($ch, CURLOPT_TIMEOUT, (int) max(1, ceil((float)$timeout)));
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
				if ( $payload !== '' ) {
					curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
				}
				if ( count($h) > 0 ) {
					curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
				}
				$respBody = (string) curl_exec($ch);
				if ( $respBody === '' && curl_errno($ch) ) {
					$err = (string) curl_error($ch);
				}
				$httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				curl_close($ch);
			} else {
				$ctx = stream_context_create(array(
					'http' => array(
						'method' => $method,
						'header' => implode("\r\n", is_array($headers) ? $headers : array()),
						'content' => $payload,
						'ignore_errors' => true,
						'timeout' => (float) max(1, $timeout),
					),
				));
				$out = @file_get_contents($url, false, $ctx);
				if ( $out === false ) {
					$err = 'HTTP request failed';
					$respBody = '';
				} else {
					$respBody = (string) $out;
				}
				if ( isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0 ) {
					$line0 = (string) $http_response_header[0];
					if ( preg_match('/\s(\d{3})\s/', $line0, $m) ) $httpCode = intval($m[1]);
				}
			}

			$json = null;
			if ( trim($respBody) !== '' ) {
				$json = json_decode($respBody, true);
				if ( !is_array($json) && function_exists('tt_flexicorp_extract_json_payload_from_mixed_output') ) {
					$json = tt_flexicorp_extract_json_payload_from_mixed_output($respBody);
				}
			}

			return array(
				'ok' => $err === '' && $httpCode >= 200 && $httpCode < 300,
				'status' => (int) $httpCode,
				'error' => $err,
				'body' => (string) $respBody,
				'data' => is_array($json) ? $json : null,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_cli_status') ) {
		function tt_flexicorp_fqs_cli_status( $bin, $url = '' ) {
			$bin = trim((string)$bin);
			if ( $bin === '' ) return array();
			$cmd = escapeshellarg($bin) . ' status';
			$url = trim((string)$url);
			if ( $url !== '' ) {
				$cmd .= ' --url ' . escapeshellarg($url);
			}
			$cmd .= ' 2>&1';
			$raw = (string) shell_exec($cmd);
			$dec = json_decode($raw, true);
			if ( !is_array($dec) && function_exists('tt_flexicorp_extract_json_payload_from_mixed_output') ) {
				$dec = tt_flexicorp_extract_json_payload_from_mixed_output($raw);
			}
			return is_array($dec) ? $dec : array();
		}
	}

	if ( !function_exists('tt_flexicorp_request_user') ) {
		/**
		 * The logged-in TEITOK user ('' = not logged in): FQS limits by tier
		 * (visitor / user / admin) and per-user limits use it.
		 */
		function tt_flexicorp_request_user() {
			$u = '';
			if ( isset($GLOBALS['username']) && is_string($GLOBALS['username']) ) {
				$u = trim($GLOBALS['username']);
			}
			if ( $u === '' && isset($GLOBALS['user']) && is_array($GLOBALS['user']) ) {
				foreach ( array('email', 'short', 'name') as $k ) {
					if ( isset($GLOBALS['user'][$k]) && is_string($GLOBALS['user'][$k]) && trim($GLOBALS['user'][$k]) !== '' ) {
						$u = trim($GLOBALS['user'][$k]);
						break;
					}
				}
			}
			if ( $u === '' && isset($_SESSION) && is_array($_SESSION) ) {
				foreach ( array('username', 'user', 'login') as $k ) {
					if ( isset($_SESSION[$k]) && is_string($_SESSION[$k]) && trim($_SESSION[$k]) !== '' ) {
						$u = trim($_SESSION[$k]);
						break;
					}
				}
			}
			return $u;
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_probe') ) {
		function tt_flexicorp_fqs_probe( $projectRoot, $isAdmin ) {
			$url = tt_flexicorp_fqs_url();
			$bin = tt_flexicorp_fqs_bin();
			$corpusId = tt_flexicorp_corpus_id_from_root($projectRoot);
			// FQS tiers: corpus admin > logged-in user > visitor
			$role = $isAdmin ? 'admin' : ( tt_flexicorp_request_user() !== '' ? 'user' : 'visitor' );

			$probe = array(
				'enabled' => true,
				'corpus_id' => $corpusId,
				'request_role' => $role,
				'cli_installed' => $bin !== '',
				'cli_path' => $bin,
				'cli_version' => '',
				'corpus_registered' => false,
				'http_url' => $url,
				'http_running' => false,
				'http_status' => 0,
				'health' => null,
				'db_path' => '',
				'server_db_path' => '',
				'cli_db_path' => '',
				'db_mismatch' => false,
				'server_version' => '',
				'version_mismatch' => false,
				'jwt_configured' => false,
				'jwt_preview' => '',
				'ready_for_queries' => false,
				'reason' => '',
			);

			if ( $bin !== '' ) {
				$verRaw = trim((string) shell_exec(escapeshellarg($bin) . ' --version 2>/dev/null'));
				if ( preg_match('/\\b([0-9]+\\.[0-9]+\\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?)\\b/', $verRaw, $mVer) ) {
					$probe['cli_version'] = (string)$mVer[1];
				}
			}

			if ( $url !== '' ) {
				$health = tt_flexicorp_http_json_request('GET', $url . '/health', null, array('Accept: application/json'), 3.0);
				$hdata = isset($health['data']) && is_array($health['data']) ? $health['data'] : null;
				$looksLikeFqs = is_array($hdata)
					&& isset($hdata['service'])
					&& (string) $hdata['service'] === 'fqs';
				// Require JSON health from real FQS (not any stray HTTP 200 on the same port).
				$probe['http_running'] = ! empty($health['ok']) && $looksLikeFqs;
				$probe['http_status'] = (int) ($health['status'] ?? 0);
				$probe['health'] = $health['data'];
				if ( is_array($hdata) ) {
					$probe['db_path'] = trim((string)($hdata['db_path'] ?? ''));
				}
				if ( is_array($hdata) ) {
					$probe['server_version'] = trim((string)($hdata['version'] ?? ''));
				}
				// If configured URL is stale/unreachable, try runtime-discovered URL as fallback.
				if (
					!$probe['http_running']
					&& function_exists('tt_flexicorp_fqs_url_from_runtime_file')
				) {
					$runtimeUrl = rtrim((string) tt_flexicorp_fqs_url_from_runtime_file(), '/');
					if ( $runtimeUrl !== '' && $runtimeUrl !== rtrim((string)$url, '/') ) {
						$health2 = tt_flexicorp_http_json_request('GET', $runtimeUrl . '/health', null, array('Accept: application/json'), 3.0);
						$hdata2 = isset($health2['data']) && is_array($health2['data']) ? $health2['data'] : null;
						$looksLikeFqs2 = is_array($hdata2)
							&& isset($hdata2['service'])
							&& (string) $hdata2['service'] === 'fqs';
						if ( !empty($health2['ok']) && $looksLikeFqs2 ) {
							$probe['http_url'] = $runtimeUrl;
							$probe['http_running'] = true;
							$probe['http_status'] = (int) ($health2['status'] ?? 0);
							$probe['health'] = $health2['data'];
							if ( is_array($hdata2) ) {
								$probe['db_path'] = trim((string)($hdata2['db_path'] ?? ''));
							}
							if ( is_array($hdata2) ) {
								$probe['server_version'] = trim((string)($hdata2['version'] ?? ''));
							}
						}
					}
				}
			}
			$probe['version_mismatch'] = (
				trim((string)$probe['cli_version']) !== ''
				&& trim((string)$probe['server_version']) !== ''
				&& trim((string)$probe['cli_version']) !== trim((string)$probe['server_version'])
			);
			if ( $probe['db_path'] === '' && function_exists('tt_flexicorp_fqs_db_path_from_runtime_file') ) {
				$probe['db_path'] = tt_flexicorp_fqs_db_path_from_runtime_file();
			}
			if ( $bin !== '' ) {
				$statusUrl = rtrim((string)($probe['http_url'] ?? ''), '/');
				$st = tt_flexicorp_fqs_cli_status($bin, $statusUrl);
				if ( is_array($st) ) {
					$probe['cli_db_path'] = trim((string)($st['db_path'] ?? ''));
					$httpSt = isset($st['http']) && is_array($st['http']) ? $st['http'] : array();
					$probe['server_db_path'] = trim((string)($httpSt['server_db_path'] ?? ''));
					if ( $probe['server_db_path'] === '' ) {
						$probe['server_db_path'] = trim((string)($httpSt['db_path'] ?? ''));
					}
				}
			}
			if ( $probe['server_db_path'] === '' ) {
				$probe['server_db_path'] = trim((string)($probe['db_path'] ?? ''));
			}
			if ( $probe['db_path'] === '' ) {
				$probe['db_path'] = trim((string)($probe['server_db_path'] ?? ''));
			}
			$probe['db_mismatch'] = (
				trim((string)$probe['cli_db_path']) !== ''
				&& trim((string)$probe['server_db_path']) !== ''
				&& trim((string)$probe['cli_db_path']) !== trim((string)$probe['server_db_path'])
			);
			if ( $bin !== '' ) {
				$dbArg = '';
				$showDbPath = trim((string)($probe['server_db_path'] ?? ''));
				if ( $showDbPath === '' ) $showDbPath = trim((string)($probe['db_path'] ?? ''));
				if ( $showDbPath !== '' ) {
					$dbArg = ' --db ' . escapeshellarg($showDbPath);
				}
				$showCmd = escapeshellarg($bin) . ' corpora show' . $dbArg . ' --id ' . escapeshellarg($corpusId) . ' 2>&1';
				$showOut = (string) shell_exec($showCmd);
				$showJson = json_decode($showOut, true);
				if ( is_array($showJson) && isset($showJson['id']) && (string)$showJson['id'] === (string)$corpusId ) {
					$probe['corpus_registered'] = true;
				}
			}

			$secret = tt_flexicorp_fqs_secret();
			$probe['jwt_configured'] = $secret !== '';
			if ( $secret !== '' ) {
				$now = time();
				$claims = array(
					'iss' => 'teitok-flexicorp',
					'sub' => $corpusId,
					'role' => $role,
					'iat' => $now,
					'exp' => $now + 300,
				);
				$jwt = tt_flexicorp_make_hs256_jwt($claims, $secret);
				$probe['jwt_preview'] = $jwt !== '' ? substr($jwt, 0, 20) . '...' : '';
			}

			if ( !$probe['cli_installed'] ) {
				$probe['reason'] = 'fqs CLI not found';
			} elseif ( !$probe['corpus_registered'] ) {
				$probe['reason'] = 'corpus not registered in FQS catalog';
			} elseif ( $probe['http_url'] === '' ) {
				$probe['reason'] = 'fqs_url not configured';
			} elseif ( !$probe['http_running'] ) {
				$probe['reason'] = 'FQS HTTP server not reachable';
			} else {
				$probe['ready_for_queries'] = true;
				$probe['reason'] = 'ready';
			}

			return $probe;
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_query_call') ) {
		function tt_flexicorp_fqs_query_call( $probe, $queryText, $queryLanguage, $start, $size, $backendOverride, $queryOptions = array() ) {
			if ( !is_array($probe) || empty($probe['ready_for_queries']) ) {
				return tt_flexicorp_error_call('fqs', 'query', 'FQS not ready for queries.');
			}
			$url = rtrim((string)($probe['http_url'] ?? ''), '/');
			if ( $url === '' ) {
				return tt_flexicorp_error_call('fqs', 'query', 'Missing FQS URL.');
			}

			$headers = array(
				'Content-Type: application/json',
				'Accept: application/json',
			);
			$secret = tt_flexicorp_fqs_secret();
			if ( $secret !== '' ) {
				$now = time();
				$claims = array(
					'iss' => 'teitok-flexicorp',
					'sub' => (string)($probe['corpus_id'] ?? ''),
					'role' => (string)($probe['request_role'] ?? 'visitor'),
					'user' => tt_flexicorp_request_user(),
					'iat' => $now,
					'exp' => $now + 300,
				);
				$jwt = tt_flexicorp_make_hs256_jwt($claims, $secret);
				if ( $jwt !== '' ) $headers[] = 'Authorization: Bearer ' . $jwt;
			}

			$payload = array(
				'corpus' => (string)($probe['corpus_id'] ?? ''),
				'query' => (string)$queryText,
				'language' => trim( (string) $queryLanguage ) !== '' ? (string) $queryLanguage : 'auto',
				'start' => (int)$start,
				'size' => (int)$size,
				'request_role' => (string)($probe['request_role'] ?? 'visitor'),
				'user' => tt_flexicorp_request_user(),
			);
			$qopts = is_array($queryOptions) ? $queryOptions : array();
			if ( isset($qopts['window']) && $qopts['window'] !== '' && $qopts['window'] !== null ) {
				$payload['window'] = (int)$qopts['window'];
			}
			if ( isset($qopts['context_scope']) && trim((string)$qopts['context_scope']) !== '' ) {
				$payload['context_scope'] = trim((string)$qopts['context_scope']);
			}
			if ( isset($qopts['context_format']) && trim((string)$qopts['context_format']) !== '' ) {
				$payload['context_format'] = trim((string)$qopts['context_format']);
			}
			if ( !empty($qopts['flexicorp_fragment_kwic_cpos_span']) ) {
				$payload['flexicorp_fragment_kwic_cpos_span'] = true;
			}
			if ( !empty($qopts['fragment']) ) {
				// Pando: synthetic TEITOK-style XML per hit (corpus without xmlfiles)
				$payload['fragment'] = true;
			}
			if ( $backendOverride !== null && trim((string)$backendOverride) !== '' ) {
				$payload['backend'] = trim((string)$backendOverride);
			}

			$resp = tt_flexicorp_http_json_request('POST', $url . '/query', $payload, $headers, 10.0);
			$opErr = '';
			$doneErrors = array();
			$result = null;
			if ( !empty($resp['ok']) && is_array($resp['data']) && !empty($resp['data']['ok']) ) {
				$fqs = $resp['data'];
				$fqsOpEffective = '';
				if ( isset($fqs['operation_effective']) && is_string($fqs['operation_effective']) ) {
					$fqsOpEffective = strtolower(trim($fqs['operation_effective']));
				}
				// FQS returns an envelope where backend result is usually under raw.done.result.
				$inner = null;
				if ( isset($fqs['raw']) && is_array($fqs['raw']) ) {
					if ( isset($fqs['raw']['done']['result']) && is_array($fqs['raw']['done']['result']) ) {
						$inner = $fqs['raw']['done']['result'];
					} elseif ( isset($fqs['raw']['result']) && is_array($fqs['raw']['result']) ) {
						$inner = $fqs['raw']['result'];
					} elseif ( function_exists('tt_flexicorp_pando_top_level_looks_like_query_result') && tt_flexicorp_pando_top_level_looks_like_query_result($fqs['raw']) ) {
						$inner = $fqs['raw'];
					}
				}
				if ( !is_array($inner) ) {
					$inner = array();
				}
				// Preserve effective operation from FQS envelope when backend payload omits it.
				if ( $fqsOpEffective !== '' && !isset($inner['operation']) ) {
					$inner['operation'] = $fqsOpEffective;
				}
				// Some Pando program payloads use one extra wrapper:
				// { operation: "freq"|"dcoll"|"coll"|…, result: { rows|collocates: ... } }.
				if ( is_array($inner) && isset($inner['result']) && is_array($inner['result']) ) {
					$innerResult = $inner['result'];
					$opTag = isset($inner['operation']) ? strtolower((string)$inner['operation']) : '';
					$innerIsTable = isset($innerResult['rows']) || isset($innerResult['compare_queries']) || isset($innerResult['totals_per_query']);
					$innerIsCollocates = isset($innerResult['collocates']) && is_array($innerResult['collocates']);
					$aggOps = array( 'freq', 'group', 'count', 'dist', 'coll', 'dcoll', 'keyness' );
					if ( $opTag === 'freq' || $innerIsTable || $innerIsCollocates || in_array( $opTag, $aggOps, true ) ) {
						// Preserve outer operation tag through unwrap (count/dist/dcoll/keyness/freq/coll).
						if ( $opTag !== '' && ! isset( $innerResult['operation'] ) ) {
							$innerResult['operation'] = $opTag;
						}
						$inner = $innerResult;
					}
				}
				if ( is_array( $inner ) && isset( $inner['collocates'] ) && is_array( $inner['collocates'] ) ) {
					if ( ! isset( $inner['result_type'] ) || trim( (string) $inner['result_type'] ) === '' ) {
						$inner['result_type'] = 'table';
					}
					if ( ! isset( $inner['returned'] ) ) {
						$inner['returned'] = count( $inner['collocates'] );
					}
					if ( ! isset( $inner['total'] ) && isset( $inner['matches'] ) && is_numeric( $inner['matches'] ) ) {
						$inner['total'] = (int) $inner['matches'];
					}
				}
				if ( function_exists('tt_flexicorp_pando_normalize_multiquery_freq_result') ) {
					tt_flexicorp_pando_normalize_multiquery_freq_result($inner);
				}
				$hasCmp = isset($inner['compare_queries']) && is_array($inner['compare_queries']) && count($inner['compare_queries']) > 0;
				$hasRows = isset($inner['rows']) && is_array($inner['rows']) && count($inner['rows']) > 0;
				if ( ( $hasCmp || $hasRows ) && ( !isset($inner['result_type']) || trim((string)$inner['result_type']) === '' ) ) {
					$inner['result_type'] = 'table';
				}
				// pando-server / warm library answers keep the counts in page{} and the
				// background count in job{}: lift a known total to the flexicorp shape
				if ( !isset($inner['total']) && isset($inner['page']) && is_array($inner['page']) ) {
					$pg = $inner['page'];
					$job = isset($inner['job']) && is_array($inner['job']) ? $inner['job'] : array();
					if ( !empty($job['finished']) && isset($job['total']) && is_numeric($job['total']) ) {
						$inner['total'] = (int)$job['total'];
						$inner['total_exact'] = !empty($job['total_exact']);
					} elseif ( isset($pg['total']) && is_numeric($pg['total']) && !empty($pg['total_exact']) ) {
						// (a count still running is not a total: "Show more" must stay)
						$inner['total'] = (int)$pg['total'];
						$inner['total_exact'] = !empty($pg['total_exact']);
					}
					if ( !isset($inner['returned']) && isset($pg['returned']) ) $inner['returned'] = (int)$pg['returned'];
					if ( !isset($inner['start']) && isset($pg['start']) ) $inner['start'] = (int)$pg['start'];
				}
				if ( function_exists('tt_flexicorp_pando_normalize_aligned_pairs') ) {
					tt_flexicorp_pando_normalize_aligned_pairs( $inner );
				}
				if ( function_exists('tt_flexicorp_fqs_enrich_hits_for_ui') ) {
					$inner = tt_flexicorp_fqs_enrich_hits_for_ui($inner);
				}
				$inner['fqs_meta'] = array(
					'backend_resolved' => (string)($fqs['backend_resolved'] ?? ''),
					'backend_catalog' => (string)($fqs['backend_catalog'] ?? ''),
					'query' => isset($fqs['query']) ? $fqs['query'] : null,
					'policy' => isset($fqs['policy']) ? $fqs['policy'] : null,
					'executor' => isset($fqs['executor']) ? $fqs['executor'] : null,
					'meta' => isset($fqs['meta']) ? $fqs['meta'] : null,
				);
				$result = $inner;
			} else {
				$opErr = trim((string)($resp['error'] ?? ''));
				if ( $opErr === '' && is_array($resp['data']) ) {
					if ( isset($resp['data']['error']) ) {
						$opErr = trim((string) $resp['data']['error']);
					} elseif ( isset($resp['data']['message']) ) {
						$opErr = trim((string) $resp['data']['message']);
					}
				}
				// FQS often returns (4xx, plain-text anyhow message), not JSON — prefer response body.
				$body = trim((string)($resp['body'] ?? ''));
				if ( $opErr === '' && $body !== '' ) {
					$try = json_decode($body, true);
					if ( is_array($try) ) {
						if ( isset($try['error']) ) {
							$opErr = trim((string) $try['error']);
						} elseif ( isset($try['message']) ) {
							$opErr = trim((string) $try['message']);
						}
					}
					if ( $opErr === '' ) {
						$opErr = $body;
					}
				}
				$httpSt = (int)($resp['status'] ?? 0);
				if ( $opErr === '' && $httpSt > 0 ) {
					$opErr = 'FQS HTTP error ' . $httpSt;
				}
				if ( $opErr === '' ) {
					$opErr = 'FQS query failed';
				}
				$payloadJson = function_exists( 'tt_flexicorp_json_encode_safe' )
					? tt_flexicorp_json_encode_safe( $payload )
					: json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( $payloadJson === false ) {
					$payloadJson = '{}';
				}
				if ( strlen( $payloadJson ) > 4096 ) {
					$payloadJson = substr( $payloadJson, 0, 4096 ) . '…';
				}
				$opErr .= "\n\nRequest payload: " . $payloadJson;
				if ( strlen( $opErr ) > 12000 ) {
					$opErr = substr( $opErr, 0, 12000 ) . '…';
				}
				$doneErrors[] = $opErr;
			}

			$done = array(
				'backend' => 'fqs',
				'operation' => 'query',
				'result' => $result,
				'warnings' => array(),
				'errors' => $doneErrors,
			);
			$data = array(
				'success' => count($doneErrors) === 0,
				'done' => $done,
			);

			$debugRaw = (string)($resp['body'] ?? '');
			if ( strlen($debugRaw) > 65536 ) {
				$debugRaw = substr($debugRaw, 0, 65536) . "\n… [truncated at 64KiB for Debug panel]";
			}
			$debugEntry = array(
				'time' => date('H:i:s'),
				'backend' => 'fqs',
				'operation' => 'query',
				'ok' => count($doneErrors) === 0,
				'command' => 'POST ' . $url . '/query',
				'http_status' => (int)($resp['status'] ?? 0),
				'request_payload' => $payload,
				'raw' => $debugRaw,
				'errors' => $doneErrors,
				'warnings' => array(),
				'result' => $result,
			);
			tt_flexicorp_debug_log( $debugEntry );

			return array(
				'ok' => count($doneErrors) === 0,
				'command' => 'POST ' . $url . '/query',
				'raw' => (string)($resp['body'] ?? ''),
				'data' => $data,
				'error' => $opErr,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_headers_with_optional_jwt') ) {
		function tt_flexicorp_fqs_headers_with_optional_jwt( $probe, $roleOverride = null ) {
			$headers = array(
				'Content-Type: application/json',
				'Accept: application/json',
			);
			$secret = tt_flexicorp_fqs_secret();
			if ( $secret !== '' ) {
				$now = time();
				$claims = array(
					'iss' => 'teitok-flexicorp',
					'sub' => (string)($probe['corpus_id'] ?? ''),
					'role' => $roleOverride !== null ? (string)$roleOverride : (string)($probe['request_role'] ?? 'visitor'),
					'user' => tt_flexicorp_request_user(),
					'iat' => $now,
					'exp' => $now + 300,
				);
				$jwt = tt_flexicorp_make_hs256_jwt($claims, $secret);
				if ( $jwt !== '' ) $headers[] = 'Authorization: Bearer ' . $jwt;
			}
			return $headers;
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_enqueue_reindex') ) {
		function tt_flexicorp_parse_kv_options( $raw ) {
			$out = array();
			$text = trim((string)$raw);
			if ( $text === '' ) return $out;
			$parts = preg_split('/[,\n;]/', $text);
			if ( !is_array($parts) ) return $out;
			foreach ( $parts as $part ) {
				$chunk = trim((string)$part);
				if ( $chunk === '' ) continue;
				$eq = strpos($chunk, '=');
				if ( $eq === false ) continue;
				$key = trim(substr($chunk, 0, $eq));
				$val = trim(substr($chunk, $eq + 1));
				if ( $key === '' || $val === '' ) continue;
				$out[$key] = $val;
			}
			return $out;
		}

		function tt_flexicorp_fqs_http_list_corpus_ids( $probe, $limit = 50 ) {
			$out = array();
			if ( !is_array($probe) || empty($probe['http_running']) ) return $out;
			$url = rtrim((string)($probe['http_url'] ?? ''), '/');
			if ( $url === '' ) return $out;
			$headers = tt_flexicorp_fqs_headers_with_optional_jwt($probe, 'admin');
			$resp = tt_flexicorp_http_json_request(
				'GET',
				$url . '/corpora?request_role=admin&include_noncurrent=true',
				null,
				$headers,
				8.0
			);
			$data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
			$rows = isset($data['corpora']) && is_array($data['corpora']) ? $data['corpora'] : array();
			foreach ( $rows as $row ) {
				if ( !is_array($row) ) continue;
				$id = trim((string)($row['id'] ?? ''));
				if ( $id !== '' && !in_array($id, $out, true) ) $out[] = $id;
				if ( count($out) >= max(1, intval($limit)) ) break;
			}
			return $out;
		}

		function tt_flexicorp_fqs_http_find_corpus_id_for_project_root( $probe, $projectRoot ) {
			if ( !is_array($probe) || empty($probe['http_running']) ) return '';
			$url = rtrim((string)($probe['http_url'] ?? ''), '/');
			$projectRoot = rtrim(str_replace('\\', '/', trim((string)$projectRoot)), '/');
			if ( $url === '' || $projectRoot === '' ) return '';
			$headers = tt_flexicorp_fqs_headers_with_optional_jwt($probe, 'admin');
			$resp = tt_flexicorp_http_json_request(
				'GET',
				$url . '/corpora?request_role=admin&include_noncurrent=true',
				null,
				$headers,
				8.0
			);
			$data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
			$rows = isset($data['corpora']) && is_array($data['corpora']) ? $data['corpora'] : array();
			foreach ( $rows as $row ) {
				if ( !is_array($row) ) continue;
				$id = trim((string)($row['id'] ?? ''));
				$pr = rtrim(str_replace('\\', '/', trim((string)($row['project_root'] ?? ''))), '/');
				if ( $id !== '' && $pr !== '' && $pr === $projectRoot ) {
					return $id;
				}
			}
			return '';
		}

		function tt_flexicorp_fqs_corpus_id_candidates( $probe, $projectRoot ) {
			$out = array();
			$push = function( $v ) use ( &$out ) {
				$t = trim((string)$v);
				if ( $t === '' ) return;
				if ( !in_array($t, $out, true) ) $out[] = $t;
			};
			$primary = trim((string)($probe['corpus_id'] ?? ''));
			$push($primary);
			if ( function_exists('getset') ) {
				$cqpCorpus = trim((string)getset('cqp/corpus', ''));
				if ( $cqpCorpus !== '' ) {
					$push($cqpCorpus);
					$push(strtolower($cqpCorpus));
				}
			}
			$folder = basename(rtrim(str_replace('\\', '/', (string)$projectRoot), '/'));
			if ( $folder !== '' ) {
				$push($folder);
				$push('tt-' . $folder);
				$push('tt_' . $folder);
			}
			$snapshot = $out;
			foreach ( $snapshot as $id ) {
				if ( strpos($id, '-') !== false ) $push(str_replace('-', '_', $id));
				if ( strpos($id, '_') !== false ) $push(str_replace('_', '-', $id));
			}
			return $out;
		}

		function tt_flexicorp_fqs_enqueue_reindex( $probe, $reindexBackends, $projectRoot, $origin = 'teitok-flexicorp' ) {
			if ( !is_array($probe) || empty($probe['http_running']) ) {
				return array('ok' => false, 'job_id' => '', 'error' => 'FQS HTTP is not ready');
			}
			$url = rtrim((string)($probe['http_url'] ?? ''), '/');
			if ( $url === '' ) {
				return array('ok' => false, 'job_id' => '', 'error' => 'Missing FQS URL');
			}
			$backs = array();
			if ( is_array($reindexBackends) ) {
				foreach ( $reindexBackends as $b ) {
					$cb = tt_flexicorp_backend_canonical(trim((string)$b));
					if ( $cb !== '' && !in_array($cb, $backs, true) ) $backs[] = $cb;
				}
			}
			if ( count($backs) === 0 ) $backs = array('auto');
			$requestUser = '';
			if ( isset($GLOBALS['username']) ) {
				$requestUser = trim((string)$GLOBALS['username']);
			}
			if ( $requestUser === '' && isset($_SESSION) && is_array($_SESSION) ) {
				$requestUser = trim((string)(
					$_SESSION['username']
					?? $_SESSION['user']
					?? $_SESSION['login']
					?? ''
				));
			}
			$options = array();
			$backendOptions = array();
			$pmltqRequested = in_array('pmltq', $backs, true);
			$pmltqAvailable = false;
			if ( $pmltqRequested && function_exists('tt_flexicorp_pmltq_status') ) {
				$pmltqSt = tt_flexicorp_pmltq_status((string)$projectRoot);
				$pmltqAvailable = !empty($pmltqSt['available']);
			}
			if ( $pmltqRequested && $pmltqAvailable ) {
				$writePmlRaw = 'yes';
				if ( function_exists('getset') ) {
					$writePmlRaw = trim((string)getset('defaults/flexicorp/pmltq_write_pml', 'yes'));
				}
				$writePmlReq = trim((string)($_REQUEST['pmltq_write_pml'] ?? ''));
				if ( $writePmlReq !== '' ) $writePmlRaw = $writePmlReq;
				$writePmlNorm = strtolower($writePmlRaw);
				$writePmlOn = !in_array($writePmlNorm, array('', '0', 'no', 'false', 'off'), true);
				if ( $writePmlOn ) {
					$backendOptions['pmltq'] = array('pmltq_write_pml' => 'yes');
				}
			}
			$globalOptsRaw = '';
			if ( function_exists('getset') ) {
				$globalOptsRaw = trim((string)getset('defaults/flexicorp/reindex_options', ''));
			}
			$globalOptsReq = trim((string)($_REQUEST['reindex_options'] ?? ''));
			if ( $globalOptsReq !== '' ) $globalOptsRaw = $globalOptsReq;
			$options = tt_flexicorp_parse_kv_options($globalOptsRaw);

			$pmltqOptsRaw = '';
			if ( function_exists('getset') ) {
				$pmltqOptsRaw = trim((string)getset('defaults/flexicorp/pmltq_reindex_options', ''));
			}
			$pmltqOptsReq = trim((string)($_REQUEST['pmltq_reindex_options'] ?? ''));
			if ( $pmltqOptsReq !== '' ) $pmltqOptsRaw = $pmltqOptsReq;
			$pmltqOpts = tt_flexicorp_parse_kv_options($pmltqOptsRaw);
			if ( $pmltqRequested && !empty($pmltqOpts) ) {
				if ( !isset($backendOptions['pmltq']) || !is_array($backendOptions['pmltq']) ) {
					$backendOptions['pmltq'] = array();
				}
				foreach ( $pmltqOpts as $k => $v ) {
					$backendOptions['pmltq'][$k] = $v;
				}
			}
			$payload = array(
				'corpus' => (string)($probe['corpus_id'] ?? ''),
				'backends' => $backs,
				'priority' => 0,
				'request_role' => 'admin',
				'origin' => (string)$origin,
			);
			if ( !empty($options) ) $payload['options'] = $options;
			if ( !empty($backendOptions) ) $payload['backend_options'] = $backendOptions;
			if ( $requestUser !== '' ) {
				// Extra request metadata is persisted by FQS in request_json and helps UI scoping.
				$payload['requested_by'] = $requestUser;
				$payload['requested_by_user'] = $requestUser;
				$payload['username'] = $requestUser;
				$payload['user'] = $requestUser;
			}
			$headers = tt_flexicorp_fqs_headers_with_optional_jwt($probe, 'admin');
			$attemptedCorpora = array();
			$attempt = function( $payloadArg ) use ( $url, $headers, &$attemptedCorpora ) {
				$payloadUse = is_array($payloadArg) ? $payloadArg : array();
				$corpusTry = trim((string)($payloadUse['corpus'] ?? ''));
				if ( $corpusTry !== '' && !in_array($corpusTry, $attemptedCorpora, true) ) {
					$attemptedCorpora[] = $corpusTry;
				}
				$resp = tt_flexicorp_http_json_request('POST', $url . '/reindex/jobs', $payloadUse, $headers, 8.0);
				$data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
				$jobId = trim((string)($data['job_id'] ?? ''));
				if ( $jobId === '' && isset($data['result']) && is_array($data['result']) ) {
					$jobId = trim((string)($data['result']['job_id'] ?? ''));
					if ( $jobId === '' && isset($data['result']['job']) && is_array($data['result']['job']) ) {
						$jobId = trim((string)($data['result']['job']['job_id'] ?? ''));
					}
				}
				if ( $jobId === '' && isset($data['job']) && is_array($data['job']) ) {
					$jobId = trim((string)($data['job']['job_id'] ?? ''));
				}
				$err = trim((string)($data['error'] ?? ''));
				if ( $err === '' ) {
					$body = trim((string)($resp['body'] ?? ''));
					if ( $body !== '' && stripos($body, '<html') === false ) $err = $body;
				}
				if ( $err === '' ) $err = trim((string)($resp['error'] ?? ''));
				if ( $err === '' && ( empty($resp['ok']) || $jobId === '' ) ) $err = 'FQS enqueue failed';
				return array('resp' => $resp, 'job_id' => $jobId, 'error' => $err, 'payload' => $payloadUse);
			};
			$candidates = tt_flexicorp_fqs_corpus_id_candidates($probe, (string)$projectRoot);
			$try1 = $attempt($payload);
			if ( !empty($try1['resp']['ok']) && trim((string)$try1['job_id']) !== '' ) {
				return array('ok' => true, 'job_id' => (string)$try1['job_id'], 'error' => '', 'response' => $try1['resp']);
			}
			$errLower = strtolower(trim((string)$try1['error']));
			$looksMissingCorpus = ( strpos($errLower, 'not found in database') !== false || strpos($errLower, 'corpus') !== false && strpos($errLower, 'not found') !== false );
			if ( $looksMissingCorpus ) {
				// Try common corpus id aliases first (cqp/corpus, tt-{folder}, tt_{folder}, dash/underscore variants).
				foreach ( $candidates as $cand ) {
					$cand = trim((string)$cand);
					if ( $cand === '' || $cand === trim((string)($payload['corpus'] ?? '')) ) continue;
					$payloadAlias = $payload;
					$payloadAlias['corpus'] = $cand;
					$tryAlias = $attempt($payloadAlias);
					if ( !empty($tryAlias['resp']['ok']) && trim((string)$tryAlias['job_id']) !== '' ) {
						return array('ok' => true, 'job_id' => (string)$tryAlias['job_id'], 'error' => '', 'response' => $tryAlias['resp']);
					}
				}
				// First fallback: resolve corpus id by project_root in catalog and retry with that id.
				$resolvedId = tt_flexicorp_fqs_http_find_corpus_id_for_project_root($probe, (string)$projectRoot);
				if ( $resolvedId !== '' && $resolvedId !== trim((string)($payload['corpus'] ?? '')) ) {
					$payload2 = $payload;
					$payload2['corpus'] = $resolvedId;
					$tryAlias = $attempt($payload2);
					if ( !empty($tryAlias['resp']['ok']) && trim((string)$tryAlias['job_id']) !== '' ) {
						return array('ok' => true, 'job_id' => (string)$tryAlias['job_id'], 'error' => '', 'response' => $tryAlias['resp']);
					}
				}
				$pref = isset($backs[0]) ? (string)$backs[0] : 'auto';
				$upsert = tt_flexicorp_fqs_upsert_corpus_for_http_db($probe, (string)$projectRoot, $pref, $candidates);
				if ( !empty($upsert['ok']) ) {
					$retryIds = array();
					$upsertId = trim((string)($upsert['id'] ?? ''));
					if ( $upsertId !== '' ) $retryIds[] = $upsertId;
					foreach ( $candidates as $candRetry ) {
						$candRetry = trim((string)$candRetry);
						if ( $candRetry !== '' && !in_array($candRetry, $retryIds, true) ) $retryIds[] = $candRetry;
					}
					if ( count($retryIds) === 0 ) $retryIds[] = trim((string)($payload['corpus'] ?? ''));
					foreach ( $retryIds as $cidRetry ) {
						$payload2 = $payload;
						$payload2['corpus'] = (string)$cidRetry;
						$try2 = $attempt($payload2);
						if ( !empty($try2['resp']['ok']) && trim((string)$try2['job_id']) !== '' ) {
							return array('ok' => true, 'job_id' => (string)$try2['job_id'], 'error' => '', 'response' => $try2['resp']);
						}
					}
					$diag = array();
					$diag[] = 'url=' . $url;
					$diagDb = trim((string)($probe['server_db_path'] ?? ''));
					if ( $diagDb === '' ) $diagDb = trim((string)($probe['db_path'] ?? ''));
					if ( $diagDb === '' ) $diagDb = trim((string)($probe['health']['db_path'] ?? ''));
					$diag[] = 'db=' . $diagDb;
					$cliDbDiag = trim((string)($probe['cli_db_path'] ?? ''));
					if ( $cliDbDiag !== '' ) $diag[] = 'cli_db=' . $cliDbDiag;
					if ( !empty($probe['db_mismatch']) ) $diag[] = 'db_mismatch=1';
					$diag[] = 'upsert_id=' . $upsertId;
					$diag[] = 'tried=' . implode(',', $attemptedCorpora);
					$diagIds = tt_flexicorp_fqs_http_list_corpus_ids($probe, 50);
					if ( !empty($diagIds) ) $diag[] = 'api_ids=' . implode(',', $diagIds);
					return array('ok' => false, 'job_id' => '', 'error' => (string)$try1['error'] . ' [' . implode(' | ', $diag) . ']', 'response' => $try1['resp']);
				}
			}
			$diag = array();
			$diag[] = 'url=' . $url;
			$diagDb = trim((string)($probe['server_db_path'] ?? ''));
			if ( $diagDb === '' ) $diagDb = trim((string)($probe['db_path'] ?? ''));
			if ( $diagDb === '' ) $diagDb = trim((string)($probe['health']['db_path'] ?? ''));
			$diag[] = 'db=' . $diagDb;
			$cliDbDiag = trim((string)($probe['cli_db_path'] ?? ''));
			if ( $cliDbDiag !== '' ) $diag[] = 'cli_db=' . $cliDbDiag;
			if ( !empty($probe['db_mismatch']) ) $diag[] = 'db_mismatch=1';
			if ( isset($upsert) && is_array($upsert) && empty($upsert['ok']) ) {
				$uerr = trim((string)($upsert['error'] ?? ''));
				if ( $uerr !== '' ) $diag[] = 'upsert_error=' . $uerr;
			}
			$diag[] = 'tried=' . implode(',', $attemptedCorpora);
			$diagIds = tt_flexicorp_fqs_http_list_corpus_ids($probe, 50);
			if ( !empty($diagIds) ) $diag[] = 'api_ids=' . implode(',', $diagIds);
			return array('ok' => false, 'job_id' => '', 'error' => (string)$try1['error'] . ' [' . implode(' | ', $diag) . ']', 'response' => $try1['resp']);
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_mark_reindex') ) {
		function tt_flexicorp_fqs_mark_reindex( $probe, $jobId, $kind, $status = '', $error = '', $result = null ) {
			if ( !is_array($probe) || empty($probe['http_running']) ) return false;
			$url = rtrim((string)($probe['http_url'] ?? ''), '/');
			$jobId = trim((string)$jobId);
			if ( $url === '' || $jobId === '' ) return false;
			$headers = tt_flexicorp_fqs_headers_with_optional_jwt($probe, 'admin');
			$kind = trim((string)$kind);
			if ( $kind === 'started' ) {
				$payload = array('job_id' => $jobId, 'worker_id' => 'teitok-flexicorp');
				$resp = tt_flexicorp_http_json_request('POST', $url . '/reindex/jobs/mark-started', $payload, $headers, 6.0);
				return !empty($resp['ok']);
			}
			if ( $kind === 'finished' ) {
				$rawStatus = strtolower(trim((string)$status));
				$okStatus = in_array($rawStatus, array('completed', 'complete', 'done', 'ok', 'success', 'succeeded', 'indexed'), true);
				$normStatus = $okStatus ? 'completed' : 'failed';
				$payload = array(
					'job_id' => $jobId,
					'ok' => ( $normStatus === 'completed' ),
					'status' => $normStatus,
					'error' => trim((string)$error) !== '' ? (string)$error : null,
					'result' => is_array($result) ? $result : null,
				);
				$resp = tt_flexicorp_http_json_request('POST', $url . '/reindex/jobs/mark-finished', $payload, $headers, 8.0);
				return !empty($resp['ok']);
			}
			return false;
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_reindex_status_by_job_id') ) {
		/**
		 * Resolve a reindex job status directly from FQS control-plane by job id.
		 * Returns normalized TEITOK UI payload or null when the job is not found.
		 */
		function tt_flexicorp_fqs_reindex_status_by_job_id( $probe, $jobId, $projectRoot = '' ) {
			if ( !is_array($probe) || empty($probe['http_running']) ) return null;
			$url = rtrim((string)($probe['http_url'] ?? ''), '/');
			$jobId = trim((string)$jobId);
			if ( $url === '' || $jobId === '' ) return null;

			$headers = tt_flexicorp_fqs_headers_with_optional_jwt($probe, 'admin');
			// Lookup by explicit job id must include terminal states as well.
			// Do NOT narrow by corpus here: corpus ids may differ between deployments
			// (folder id vs configured corpus id), which can hide valid jobs.
			$q = 'status=all&limit=1000';
			$resp = tt_flexicorp_http_json_request('GET', $url . '/reindex/jobs?' . $q, null, $headers, 8.0);
			$data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
			$jobs = isset($data['jobs']) && is_array($data['jobs']) ? $data['jobs'] : array();
			$job = null;
			foreach ( $jobs as $row ) {
				if ( is_array($row) && trim((string)($row['job_id'] ?? '')) === $jobId ) {
					$job = $row;
					break;
				}
			}
			if ( !is_array($job) ) {
				// Fallback to history: some environments may keep events visible while
				// job listing is temporarily out of sync from the caller perspective.
				$hResp = tt_flexicorp_http_json_request('GET', $url . '/reindex/history?limit=2000', null, $headers, 8.0);
				$hData = isset($hResp['data']) && is_array($hResp['data']) ? $hResp['data'] : array();
				$hist = isset($hData['history']) && is_array($hData['history']) ? $hData['history'] : array();
				$lastEvt = null;
				foreach ( $hist as $ev ) {
					if ( !is_array($ev) ) continue;
					if ( trim((string)($ev['job_id'] ?? '')) === $jobId ) {
						$lastEvt = $ev;
						break;
					}
				}
				if ( is_array($lastEvt) ) {
					$event = strtolower(trim((string)($lastEvt['event'] ?? '')));
					$mapped = 'pending';
					$phaseMsg = 'queued';
					if ( $event === 'completed' || $event === 'indexed' ) {
						$mapped = 'done';
						$phaseMsg = 'completed';
					} elseif ( $event === 'failed' ) {
						$mapped = 'failed';
						$phaseMsg = 'failed';
					} elseif ( $event === 'started' || $event === 'dispatched' ) {
						$mapped = 'pending';
						$phaseMsg = 'running';
					}
					$details = isset($lastEvt['details']) && is_array($lastEvt['details']) ? $lastEvt['details'] : array();
					$backends = array();
					if ( isset($details['requested_backends']) && is_array($details['requested_backends']) ) {
						$backends = array_values(array_filter(array_map('strval', $details['requested_backends']), function($x){ return trim($x) !== ''; }));
					} elseif ( isset($details['reindex_backends']) && is_array($details['reindex_backends']) ) {
						$backends = array_values(array_filter(array_map('strval', $details['reindex_backends']), function($x){ return trim($x) !== ''; }));
					}
					return array(
						'status' => $mapped,
						'job_id' => $jobId,
						'fqs_job_id' => $jobId,
						'source' => 'fqs',
						'progress' => array(
							'phase' => $phaseMsg,
							'message' => (
								$phaseMsg === 'queued'
									? 'Queued in FQS; waiting for a reindex worker to pick it up.'
									: ( $phaseMsg === 'running'
										? 'Running via FQS worker.'
										: ( $phaseMsg === 'completed' ? 'Completed in FQS.' : 'Failed in FQS.' )
									)
							),
						),
						'error' => isset($details['error']) ? (string)$details['error'] : null,
						'created_ts' => isset($lastEvt['at']) ? (string)$lastEvt['at'] : null,
						'updated_ts' => isset($lastEvt['at']) ? (string)$lastEvt['at'] : null,
						'started_ts' => null,
						'finished_ts' => isset($lastEvt['at']) ? (string)$lastEvt['at'] : null,
						'duration_sec' => null,
						'reindex_backends' => $backends,
						'result' => null,
						'flexencoder_binary' => null,
						'log_hint' => '/var/www/html/teitok/' . tt_flexicorp_corpus_id_from_root((string)$projectRoot) . '/tmp/flexicorp_reindex.log',
						'fqs_log_hint' => '/usr/local/var/log/fqs/fqs.log',
					);
				}
				return null;
			}

			$stRaw = strtolower(trim((string)($job['status'] ?? 'queued')));
			$mapped = 'pending';
			if ( $stRaw === 'completed' || $stRaw === 'done' ) {
				$mapped = 'done';
			} elseif ( $stRaw === 'failed' || $stRaw === 'error' ) {
				$mapped = 'failed';
			}
			$phaseMsg = 'queued';
			if ( $stRaw === 'running' ) {
				$phaseMsg = 'running';
			} elseif ( $mapped === 'done' ) {
				$phaseMsg = 'completed';
			} elseif ( $mapped === 'failed' ) {
				$phaseMsg = 'failed';
			}
			$req = isset($job['request_json']) && is_array($job['request_json']) ? $job['request_json'] : array();
			$backends = isset($job['requested_backends']) && is_array($job['requested_backends']) ? $job['requested_backends'] : array();
			if ( count($backends) === 0 && isset($req['reindex_backends']) && is_array($req['reindex_backends']) ) {
				$backends = array_values(array_filter(array_map('strval', $req['reindex_backends']), function($x){ return trim($x) !== ''; }));
			}
			$result = isset($job['result_json']) && is_array($job['result_json']) ? $job['result_json'] : null;
			$error = trim((string)($job['last_error'] ?? ''));
			if ( $error === '' ) {
				$error = trim((string)($job['message'] ?? ''));
			}
			if ( $mapped !== 'failed' ) {
				$error = '';
			}
			return array(
				'status' => $mapped,
				'job_id' => $jobId,
				'fqs_job_id' => $jobId,
				'source' => 'fqs',
				'progress' => array(
					'phase' => $phaseMsg,
					'message' => (
						$phaseMsg === 'queued'
							? 'Queued in FQS; waiting for a reindex worker to pick it up.'
							: ( $phaseMsg === 'running'
								? 'Running via FQS worker.'
								: ( $phaseMsg === 'completed' ? 'Completed in FQS.' : 'Failed in FQS.' )
							)
					),
				),
				'error' => $error !== '' ? $error : null,
				'created_ts' => isset($job['requested_at']) ? (string)$job['requested_at'] : null,
				'updated_ts' => isset($job['updated_at']) ? (string)$job['updated_at'] : null,
				'started_ts' => isset($job['started_at']) ? (string)$job['started_at'] : null,
				'finished_ts' => isset($job['finished_at']) ? (string)$job['finished_at'] : null,
				'duration_sec' => null,
				'reindex_backends' => $backends,
				'result' => $result,
				'flexencoder_binary' => null,
				'log_hint' => '/var/www/html/teitok/' . $corpusId . '/tmp/flexicorp_reindex.log',
				'fqs_log_hint' => '/usr/local/var/log/fqs/fqs.log',
			);
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_reindex_active_jobs') ) {
		/**
		 * Read active (queued/running) reindex jobs for this corpus from FQS.
		 * Returns: [ok, jobs, active_backends, error]
		 */
		function tt_flexicorp_fqs_reindex_active_jobs( $probe, $projectRoot = '', $limit = 200 ) {
			$out = array(
				'ok' => false,
				'jobs' => array(),
				'active_backends' => array(),
				'error' => '',
			);
			if ( !is_array($probe) || empty($probe['http_running']) ) {
				$out['error'] = 'FQS HTTP is not ready';
				return $out;
			}
			$url = rtrim((string)($probe['http_url'] ?? ''), '/');
			if ( $url === '' ) {
				$out['error'] = 'Missing FQS URL';
				return $out;
			}
			$corpusId = trim((string)($probe['corpus_id'] ?? ''));
			if ( $corpusId === '' && trim((string)$projectRoot) !== '' ) {
				$corpusId = tt_flexicorp_corpus_id_from_root((string)$projectRoot);
			}
			$limit = max(1, intval($limit));
			$q = 'status=active&limit=' . $limit;
			if ( $corpusId !== '' ) {
				$q .= '&corpus=' . rawurlencode($corpusId);
			}
			$headers = tt_flexicorp_fqs_headers_with_optional_jwt($probe, 'admin');
			$resp = tt_flexicorp_http_json_request('GET', $url . '/reindex/jobs?' . $q, null, $headers, 8.0);
			$data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : array();
			$jobs = isset($data['jobs']) && is_array($data['jobs']) ? $data['jobs'] : array();
			$activeBackends = array();
			foreach ( $jobs as $row ) {
				if ( !is_array($row) ) continue;
				$backs = isset($row['requested_backends']) && is_array($row['requested_backends']) ? $row['requested_backends'] : array();
				if ( count($backs) === 0 ) {
					$req = isset($row['request']) && is_array($row['request']) ? $row['request'] : array();
					if ( isset($req['reindex_backends']) && is_array($req['reindex_backends']) ) {
						$backs = $req['reindex_backends'];
					}
				}
				foreach ( $backs as $b ) {
					$cb = tt_flexicorp_backend_canonical(trim((string)$b));
					if ( $cb === '' ) continue;
					$activeBackends[$cb] = true;
					if ( $cb === 'clickhouse' ) $activeBackends['clickql'] = true;
					if ( $cb === 'clickql' ) $activeBackends['clickhouse'] = true;
				}
			}
			$out['ok'] = true;
			$out['jobs'] = $jobs;
			$out['active_backends'] = array_keys($activeBackends);
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_upsert_corpus_for_http_db') ) {
		function tt_flexicorp_fqs_upsert_corpus_for_http_db( $probe, $projectRoot, $preferredBackend = 'auto', $candidateIds = array() ) {
			$res = array('ok' => false, 'id' => '', 'error' => '');
			if ( !is_array($probe) ) return $res;
			$bin = trim((string)($probe['cli_path'] ?? ''));
			if ( $bin === '' ) $bin = tt_flexicorp_fqs_bin();
			if ( $bin === '' || !is_executable($bin) ) {
				$res['error'] = 'fqs CLI not executable';
				return $res;
			}
			$health = isset($probe['health']) && is_array($probe['health']) ? $probe['health'] : array();
			$dbPath = trim((string)($probe['server_db_path'] ?? ''));
			if ( $dbPath === '' ) $dbPath = trim((string)($probe['db_path'] ?? ''));
			if ( $dbPath === '' ) $dbPath = trim((string)($health['db_path'] ?? ''));
			if ( $dbPath === '' && function_exists('tt_flexicorp_fqs_db_path_from_runtime_file') ) {
				$dbPath = trim((string) tt_flexicorp_fqs_db_path_from_runtime_file());
			}
			if ( $dbPath === '' ) {
				$res['error'] = 'missing FQS db_path from runtime/health';
				return $res;
			}
			$corpusId = trim((string)($probe['corpus_id'] ?? ''));
			if ( $corpusId === '' ) $corpusId = tt_flexicorp_corpus_id_from_root($projectRoot);
			$candidates = array();
			if ( is_array($candidateIds) ) {
				foreach ( $candidateIds as $cand ) {
					$cand = trim((string)$cand);
					if ( $cand !== '' && !in_array($cand, $candidates, true) ) $candidates[] = $cand;
				}
			}
			if ( $corpusId !== '' && !in_array($corpusId, $candidates, true) ) $candidates[] = $corpusId;
			if ( count($candidates) === 0 ) {
				$res['error'] = 'missing corpus id';
				return $res;
			}
			$pref = tt_flexicorp_backend_canonical(trim((string)$preferredBackend));
			if ( $pref === '' ) $pref = 'auto';
			$lastErr = '';
			foreach ( $candidates as $cid ) {
				// A corpus that is already in the catalogue is left alone: this fallback is for
				// unregistered projects, and a minimal row would replace its label, settings and
				// the choices made in the FQS admin (FCS, KonText).
				$showCmd = escapeshellarg($bin) . ' corpora show --id ' . escapeshellarg($cid)
					. ' --db ' . escapeshellarg($dbPath) . ' 2>/dev/null';
				$shown = json_decode((string) shell_exec($showCmd), true);
				if ( is_array($shown) && ( ( $shown['id'] ?? '' ) === $cid || ( $shown['corpus']['id'] ?? '' ) === $cid ) ) {
					$res['ok'] = true;
					$res['id'] = (string)$cid;
					$res['existing'] = true;
					$res['error'] = '';
					return $res;
				}
				$label = ucwords(str_replace(array('-', '_'), ' ', $cid));
				$purl = '';
				// this project's own title and URL, so that the new row is listed properly
				// (fqsadmin or "Register this corpus" can complete it later)
				if ( @realpath((string)$projectRoot) === @realpath(getcwd()) ) {
					if ( function_exists('getset') ) {
						$t = getset('defaults/title/display', '');
						if ( is_string($t) && trim($t) !== '' ) $label = trim($t);
					}
					$sn = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
					if ( $sn !== '' ) $purl = rtrim(str_replace('\\', '/', dirname($sn)), '/') . '/index.php';
				}
				$payload = array(
					'id' => $cid,
					'label' => $label,
					'project_root' => (string)$projectRoot,
					'interface_preference' => 'teitok',
					'preferred_backend' => $pref,
					'source_kind' => 'teitok_pando',
					'supports_xml' => true,
					'http_policy_mode' => 'public_query',
					'http_allowed_operations' => array('query', 'catalog'),
				);
				if ( $purl !== '' ) $payload['project_url'] = $purl;
				$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if ( !is_string($json) || trim($json) === '' ) {
					$lastErr = 'upsert payload encoding failed for ' . $cid;
					continue;
				}
				$cmd = escapeshellarg($bin)
					. ' corpora upsert-json'
					. ' --db ' . escapeshellarg($dbPath)
					. ' --json ' . escapeshellarg($json)
					. ' 2>&1';
				$out = (string) shell_exec($cmd);
				$dec = json_decode($out, true);
				if ( is_array($dec) && !empty($dec['ok']) ) {
					$res['ok'] = true;
					$res['id'] = (string)$cid;
					$res['error'] = '';
					return $res;
				}
				$dErr = is_array($dec) ? trim((string)($dec['error'] ?? '')) : '';
				$lastErr = $dErr !== '' ? $dErr : trim($out);
				if ( $lastErr === '' ) {
					$lastErr = 'upsert failed for ' . $cid;
				} else {
					$lastErr = 'upsert failed for ' . $cid . ': ' . $lastErr;
				}
			}
			$res['error'] = $lastErr !== '' ? $lastErr : 'upsert failed';
			return $res;
		}
	}

	if ( !function_exists('tt_flexicorp_reindex_log_append') ) {
		function tt_flexicorp_reindex_log_append( $projectRoot, $line ) {
			$projectRoot = trim((string)$projectRoot);
			if ( $projectRoot === '' ) return;
			$logDir = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp';
			if ( !is_dir($logDir) ) @mkdir($logDir, 0755, true);
			$logFile = $logDir . DIRECTORY_SEPARATOR . 'flexicorp_reindex.log';
			$prefix = date('Y-m-d H:i:s') . ' [reindex][fqs] ';
			@file_put_contents($logFile, $prefix . trim((string)$line) . "\n", FILE_APPEND | LOCK_EX);
		}
	}

	if ( !function_exists('tt_flexicorp_pando_normalize_aligned_pairs') ) {
		/**
		 * Aligned `with` queries come back as result.pairs[{source,target}] instead of result.hits[]
		 * (flexicorp-pando, pando --api and FQS alike): turn them into regular hits (source side,
		 * target attached as aligned_counterpart) and derive the group legend from the query.
		 */
		function tt_flexicorp_pando_normalize_aligned_pairs( &$result ) {
			if ( !is_array($result) ) return;
			// Aligned "with" queries may come back as result.pairs[{source,target}] instead of result.hits[].
			// Normalize to regular hits (source side) and keep target payload attached for enhanced UI rendering.
			if (
				(
					!isset($result['hits'])
					|| !is_array($result['hits'])
					|| count($result['hits']) === 0
				)
				&& isset($result['pairs'])
				&& is_array($result['pairs'])
				&& count($result['pairs']) > 0
			) {
				$normalizedHits = array();
				foreach ( $result['pairs'] as $pairIdx => $pair ) {
					if ( !is_array($pair) ) continue;
					$source = isset($pair['source']) && is_array($pair['source']) ? $pair['source'] : array();
					$target = isset($pair['target']) && is_array($pair['target']) ? $pair['target'] : array();
					if ( empty($source) && empty($target) ) continue;
					if ( empty($source) ) $source = $target;
					$alignment = isset($pair['alignment']) && is_array($pair['alignment']) ? $pair['alignment'] : array();
					if ( !isset($source['doc_id']) || trim((string)$source['doc_id']) === '' ) {
						$source['doc_id'] = isset($source['text_id']) ? (string)$source['text_id'] : '';
					}
					if ( !isset($target['doc_id']) || trim((string)$target['doc_id']) === '' ) {
						$target['doc_id'] = isset($target['text_id']) ? (string)$target['text_id'] : '';
					}
					$source['aligned'] = true;
					$source['aligned_role'] = 'source';
					$source['aligned_pair_index'] = (int)$pairIdx;
					$source['aligned_kind'] = isset($alignment['kind']) ? (string)$alignment['kind'] : '';
					$target['aligned'] = true;
					$target['aligned_role'] = 'target';
					$target['aligned_pair_index'] = (int)$pairIdx;
					$target['aligned_kind'] = isset($alignment['kind']) ? (string)$alignment['kind'] : '';
					$collectMatchedTokIds = function ( $hitPart ) {
						$out = array();
						if ( !is_array($hitPart) ) return $out;
						$toks = isset($hitPart['tokens']) && is_array($hitPart['tokens']) ? $hitPart['tokens'] : array();
						foreach ( $toks as $tok ) {
							if ( !is_array($tok) ) continue;
							// Prefer TEITOK xml:id (w-*) over tuid: tuid can repeat in noisy payloads.
							$tid = isset($tok['id']) ? trim((string)$tok['id']) : '';
							if ( $tid === '' || $tid === '_' ) {
								$tid = isset($tok['tuid']) ? trim((string)$tok['tuid']) : '';
							}
							if ( $tid === '' ) continue;
							$out[] = $tid;
						}
						return array_values(array_unique($out));
					};
					$source['_matched_tok_ids'] = $collectMatchedTokIds($source);
					$target['_matched_tok_ids'] = $collectMatchedTokIds($target);
					$source['aligned_counterpart'] = $target;
					$source['aligned_payload_raw'] = $pair;
					if ( isset( $pair['teitok_tuview'] ) && is_array( $pair['teitok_tuview'] ) ) {
						$source['teitok_tuview'] = $pair['teitok_tuview'];
						$target['teitok_tuview'] = $pair['teitok_tuview'];
					}
					$normalizedHits[] = $source;
				}
				$result['hits'] = array_values($normalizedHits);
				$result['aligned'] = true;
				$result['aligned_pairs_count'] = count($result['pairs']);
			}
			// Aligned queries can expose multiple named token groups (e.g. a: ... with b: ...),
			// while single-query hit payloads may only include the source-side groups.
			// Derive group labels from the query text so the UI legend covers both sides.
			if ( !empty($result['aligned']) ) {
				// flexicorp-pando gives the query as a string, pando --api / pando-server (FQS) as {language, text}
				$qtxt = '';
				if ( isset($result['query']) && is_array($result['query']) ) {
					$qtxt = (string)($result['query']['text'] ?? '');
				} elseif ( isset($result['query']) && is_scalar($result['query']) ) {
					$qtxt = (string)$result['query'];
				}
				if ( $qtxt !== '' ) {
					$parseNodes = function ( $expr ) {
						$rows = array();
						if ( !is_string($expr) || trim($expr) === '' ) return $rows;
						if ( preg_match_all('/(?:\b([A-Za-z_][A-Za-z0-9_]*)\s*:\s*)?\[/', $expr, $mm, PREG_SET_ORDER) ) {
							foreach ( (array)$mm as $m ) {
								$rows[] = array(
									'name' => isset($m[1]) ? trim((string)$m[1]) : '',
								);
							}
						}
						return $rows;
					};
					$preAlign = preg_split('/\s*::\s*/', $qtxt, 2)[0] ?? $qtxt;
					$splitWith = preg_split('/\s+with\s+/i', (string)$preAlign, 2);
					$sourceExpr = isset($splitWith[0]) ? (string)$splitWith[0] : '';
					$targetExpr = isset($splitWith[1]) ? (string)$splitWith[1] : '';
					$sourceNodes = $parseNodes($sourceExpr);
					$targetNodes = $parseNodes($targetExpr);
					if ( count($sourceNodes) > 0 || count($targetNodes) > 0 ) {
						$groupRows = array();
						$globalIdx = 0;
						foreach ( $sourceNodes as $n ) {
							$nm = trim((string)($n['name'] ?? ''));
							if ( $nm === '' ) $nm = 't' . (string)($globalIdx + 1);
							$groupRows[] = array(
								'index' => (int)$globalIdx,
								'id' => 't' . (string)($globalIdx + 1),
								'name' => (string)$nm,
							);
							$globalIdx++;
						}
						foreach ( $targetNodes as $n ) {
							$nm = trim((string)($n['name'] ?? ''));
							if ( $nm === '' ) $nm = 't' . (string)($globalIdx + 1);
							$groupRows[] = array(
								'index' => (int)$globalIdx,
								'id' => 't' . (string)($globalIdx + 1),
								'name' => (string)$nm,
							);
							$globalIdx++;
						}
						$result['groups'] = $groupRows;
						$result['_aligned_group_plan'] = array(
							'source_offset' => 0,
							'source_count' => count($sourceNodes),
							'target_offset' => count($sourceNodes),
							'target_count' => count($targetNodes),
						);
					}
				}
			}
		}
	}

	if ( !function_exists('tt_flexicorp_fqs_enrich_hits_for_ui') ) {
		/**
		 * FQS returns compact backend payloads. TEITOK KWIC UI expects highlight_map-like ids.
		 * Build a minimal compatible map from hit.tokens + hit.groups when available.
		 */
		function tt_flexicorp_fqs_enrich_hits_for_ui( $result ) {
			if ( !is_array($result) || !isset($result['hits']) || !is_array($result['hits']) ) {
				return $result;
			}
			foreach ( $result['hits'] as &$hit ) {
				if ( !is_array($hit) ) continue;
				if ( isset($hit['highlight_map']) && is_array($hit['highlight_map']) ) continue;
				$tokens = isset($hit['tokens']) && is_array($hit['tokens']) ? $hit['tokens'] : array();
				$groups = isset($hit['groups']) && is_array($hit['groups']) ? $hit['groups'] : array();
				$matchIds = array();
				$groupNamesByIdx = array();
				$groupTokIds = array();
				foreach ( $groups as $g ) {
					if ( !is_array($g) ) continue;
					$idx = isset($g['index']) ? intval($g['index']) : null;
					$name = trim((string)($g['id'] ?? ($g['name'] ?? '')));
					if ( $name === '' ) $name = 't1';
					if ( $idx !== null ) $groupNamesByIdx[$idx] = $name;
					if ( !isset($groupTokIds[$name]) ) $groupTokIds[$name] = array();
				}
				foreach ( $tokens as $t ) {
					if ( !is_array($t) ) continue;
					$tid = trim((string)($t['id'] ?? ''));
					if ( $tid === '' && isset($t['tuid']) ) {
						$tid = trim((string)$t['tuid']);
					}
					if ( $tid === '' ) continue;
					$matchIds[] = $tid;
					$gidx = isset($t['group']) ? intval($t['group']) : null;
					if ( $gidx !== null && isset($groupNamesByIdx[$gidx]) ) {
						$gname = $groupNamesByIdx[$gidx];
						if ( !isset($groupTokIds[$gname]) ) $groupTokIds[$gname] = array();
						$groupTokIds[$gname][] = $tid;
					}
				}
				$matchIds = array_values(array_unique($matchIds));
				if ( count($matchIds) === 0 ) continue;
				$hm = array(
					'match' => $matchIds,
					'default' => array(
						'tok_ids' => $matchIds,
						'range' => null,
					),
					'groups' => array(),
				);
				foreach ( $groupTokIds as $gname => $ids ) {
					$ids = array_values(array_unique(array_filter($ids, function($x){ return trim((string)$x) !== ''; })));
					if ( count($ids) === 0 ) continue;
					$hm[$gname] = array(
						'tok_ids' => $ids,
						'range' => null,
					);
					$hm['groups'][] = $gname;
				}
				$hit['highlight_map'] = $hm;
			}
			unset($hit);
			return $result;
		}
	}

	if ( !function_exists('tt_flexicorp_admin_backend_overrides') ) {
		function tt_flexicorp_admin_backend_overrides($isAdmin) {
			$out = array(
				'blacklab_url' => '',
				'blacklab_corpus' => '',
				'blacklab_user' => '',
				'blacklab_password' => '',
				'blacklab_field' => '',
			);
			if ( !$isAdmin ) return $out;
			foreach ( array_keys($out) as $key ) {
				$out[$key] = trim((string)($_REQUEST[$key] ?? ''));
			}
			return $out;
		}
	}

	if ( !function_exists('tt_flexicorp_backend_override_extra_args') ) {
		function tt_flexicorp_backend_override_extra_args($overrides) {
			if ( !is_array($overrides) ) return array();
			$map = array(
				'blacklab_url' => 'blacklab-url',
				'blacklab_corpus' => 'blacklab-corpus',
				'blacklab_user' => 'blacklab-user',
				'blacklab_password' => 'blacklab-password',
				'blacklab_field' => 'blacklab-field',
			);
			$args = array();
			foreach ( $map as $requestKey => $optKey ) {
				$value = trim((string)($overrides[$requestKey] ?? ''));
				if ( $value === '' ) continue;
				$args[] = '-O';
				$args[] = escapeshellarg($optKey . '=' . $value);
			}
			return $args;
		}
	}

	if ( !function_exists('tt_flexicorp_run') ) {
		function tt_flexicorp_run($subcommandParts, $backend, $projectRoot, $extraArgs = array(), $queryEngine = null, $queryLanguage = null, $corpusFormat = null) {
			$op0 = '';
			if ( is_array($subcommandParts) && isset($subcommandParts[0]) ) {
				$op0 = trim((string)$subcommandParts[0]);
			} elseif ( is_string($subcommandParts) ) {
				$op0 = trim((string)$subcommandParts);
			}
			$isPandoBackend = ( $backend === 'flexicorp-pando' || $backend === 'pando' );
			// Keep pando query/freq/coll on the local pando path, but route reindex through
			// the generic Python flexicorp CLI so background/staging jobs are visible and
			// consistent with other backends.
			if ( $isPandoBackend && $op0 !== 'reindex' ) {
				return tt_flexicorp_pando_run($subcommandParts, $projectRoot);
			}
			if ( $backend === 'flexicorp-pando' ) {
				$backend = 'pando';
			}
			global $debug;
			$venv = new VENV();
			$python = $venv->path() . '/bin/python';
			if ( !file_exists($python) ) {
				return array(
					'ok' => false,
					'command' => '',
					'raw' => '',
					'data' => null,
					'error' => 'Python venv not available.',
				);
			}
			$manateePythonPath = function_exists('getset') ? trim((string) getset('manatee/pythonpath', '')) : '';
			if ( $manateePythonPath === '' && function_exists('getset') ) $manateePythonPath = trim((string) getset('flexicorp/manatee_pythonpath', ''));
			$probeCmd = escapeshellarg($python) . ' -c ' . escapeshellarg('import importlib.util,sys; ok=(importlib.util.find_spec("flexicorp") is not None and importlib.util.find_spec("flexicorp.__main__") is not None); sys.exit(0 if ok else 1)');
			if ( $manateePythonPath !== '' ) {
				$probeCmd = 'env PYTHONPATH=' . escapeshellarg($manateePythonPath) . ' ' . $probeCmd;
			}
			$probeOut = array();
			$probeCode = 0;
			@exec($probeCmd . ' 2>&1', $probeOut, $probeCode);
			if ( $probeCode !== 0 ) {
				$probeRaw = implode("\n", $probeOut);
				$msg = 'flexicorp Python module is not runnable in the TEITOK venv (missing package or flexicorp.__main__).';
				if ( $probeRaw !== '' ) $msg .= ' Probe output: ' . $probeRaw;
				return array(
					'ok' => false,
					'command' => $probeCmd,
					'raw' => $probeRaw,
					'data' => null,
					'error' => $msg,
				);
			}

			if ( !is_array($subcommandParts) ) $subcommandParts = array($subcommandParts);
			if ( !is_array($extraArgs) ) $extraArgs = array();

			$parts = array(escapeshellarg($python), '-m', 'flexicorp');
			foreach ( $subcommandParts as $part ) {
				if ( $part === null || $part === '' ) continue;
				$parts[] = escapeshellarg((string)$part);
			}
			$parts[] = '--api';
			$parts[] = '--backend';
			$parts[] = escapeshellarg((string)$backend);
			$parts[] = '--folder';
			$parts[] = escapeshellarg((string)$projectRoot);
			$parts[] = '--teitok';
			$parts[] = 'yes';
			if ( (string)$backend === 'pmltq' ) {
				$treebank = tt_flexicorp_guess_pmltq_treebank($projectRoot);
				if ( $treebank !== '' ) {
					$parts[] = '--corpus';
					$parts[] = escapeshellarg($treebank);
				}
			}
			if ( $queryLanguage !== null && $queryLanguage !== '' ) {
				$parts[] = '--query-language';
				$parts[] = escapeshellarg((string)$queryLanguage);
			}
			if ( $corpusFormat !== null && $corpusFormat !== '' ) {
				$parts[] = '--corpus-format';
				$parts[] = escapeshellarg((string)$corpusFormat);
			}
			if ( $debug ) $parts[] = '--debug';
			foreach ( $extraArgs as $arg ) {
				if ( $arg === null || $arg === '' ) continue;
				$parts[] = $arg;
			}

			$cmd = implode(' ', $parts);
			if ( $manateePythonPath !== '' ) {
				$cmd = 'env PYTHONPATH=' . escapeshellarg($manateePythonPath) . ' ' . $cmd;
			}
			$isReindex = ( isset($subcommandParts[0]) && $subcommandParts[0] === 'reindex' );
			if ( $isReindex ) {
				@set_time_limit(600);
				$logDir = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp';
				if ( !is_dir($logDir) ) @mkdir($logDir, 0755, true);
				$logFile = $logDir . DIRECTORY_SEPARATOR . 'flexicorp_reindex.log';
				$logLine = date('Y-m-d H:i:s') . ' [reindex] START ' . $cmd . "\n";
				@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
			}
			$raw = shell_exec($cmd . ' 2>&1');
			$data = null;
			if ( $raw !== null && trim($raw) !== '' ) {
				$data = json_decode($raw, true);
				// Some environments prepend warnings/noise before JSON; salvage from last JSON-looking line.
				if ( !is_array($data) ) {
					$lines = preg_split('/\r\n|\r|\n/', (string)$raw);
					if ( is_array($lines) ) {
						for ( $i = count($lines) - 1; $i >= 0; $i-- ) {
							$line = trim((string)$lines[$i]);
							if ( $line === '' ) continue;
							if ( $line[0] !== '{' && $line[0] !== '[' ) continue;
							$try = json_decode($line, true);
							if ( is_array($try) ) { $data = $try; break; }
						}
					}
				}
				// Final recovery pass: extract trailing JSON object from mixed stdout/stderr.
				if ( !is_array($data) && function_exists('tt_flexicorp_extract_json_payload_from_mixed_output') ) {
					$recovered = tt_flexicorp_extract_json_payload_from_mixed_output((string)$raw);
					if ( is_array($recovered) ) $data = $recovered;
				}
			}
			if ( !is_array($data) ) {
				$rawText = (string)$raw;
				$importFail = stripos($rawText, 'No module named flexicorp') !== false || stripos($rawText, "No module named 'flexicorp'") !== false;
				$mainFail = stripos($rawText, 'flexicorp.__main__') !== false;
				$permFail = stripos($rawText, 'PermissionError') !== false
					|| ( stripos($rawText, 'Permission denied') !== false && stripos($rawText, '.py') !== false );
				$errMsg = 'flexicorp did not return valid JSON.';
				if ( $permFail ) {
					$errMsg = 'flexicorp could not start: permission denied reading Python source files (often the flexicorp checkout is not readable by the web server user). See raw output for the path.';
				} elseif ( $importFail || $mainFail ) {
					$errMsg = 'flexicorp Python module is not runnable in the TEITOK venv (missing package or flexicorp.__main__).';
				}
				if ( $isReindex && isset($logFile) ) {
					$logLine = date('Y-m-d H:i:s') . ' [reindex] FAIL no valid JSON (timeout or crash?) raw_len=' . strlen((string)$raw) . "\n";
					@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
				}
				tt_flexicorp_debug_log(array(
					'time' => date('H:i:s'),
					'backend' => $backend,
					'operation' => implode(' ', $subcommandParts),
					'ok' => false,
					'command' => $cmd,
					'raw' => (string)$raw,
					'errors' => array( $errMsg ),
					'warnings' => array(),
					'result' => null,
				));
				return array(
					'ok' => false,
					'command' => $cmd,
					'raw' => (string)$raw,
					'data' => null,
					'error' => $errMsg,
				);
			}

			$done = ( isset($data['done']) && is_array($data['done']) ) ? $data['done'] : array();
			if ( $isReindex && isset($logFile) ) {
				$ok = !empty($data['success']);
				$errs = isset($done['errors']) && is_array($done['errors']) ? $done['errors'] : array();
				$logLine = date('Y-m-d H:i:s') . ' [reindex] ' . ( $ok ? 'OK' : 'FAIL' ) . ' raw_len=' . strlen((string)$raw);
				if ( count($errs) > 0 ) $logLine .= ' errors=' . json_encode($errs);
				$logLine .= "\n";
				@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
			}
			tt_flexicorp_debug_log(array(
				'time' => date('H:i:s'),
				'backend' => $backend,
				'operation' => implode(' ', $subcommandParts),
				'ok' => !empty($data['success']),
				'command' => $cmd,
				'raw' => (string)$raw,
				'errors' => ( isset($done['errors']) && is_array($done['errors']) ) ? array_values($done['errors']) : array(),
				'warnings' => ( isset($done['warnings']) && is_array($done['warnings']) ) ? array_values($done['warnings']) : array(),
				'result' => $done['result'] ?? null,
			));

			return array(
				'ok' => !empty($data['success']),
				'command' => $cmd,
				'raw' => (string)$raw,
				'data' => $data,
				'error' => '',
			);
		}
	}

	if ( !function_exists('tt_flexicorp_pando_socket') ) {
		function tt_flexicorp_pando_socket($projectRoot) {
			$root = rtrim((string)$projectRoot, '/');
			$cfg = function_exists('getset') ? trim((string)getset('pando/socket', '')) : '';
			$env = trim((string)(getenv('FLEXICORP_PANDO_SOCKET') ?: ''));
			$candidates = array();
			if ( $cfg !== '' ) $candidates[] = $cfg;
			if ( $env !== '' ) $candidates[] = $env;
			$candidates[] = $root . '/tmp/flexicorp-pando.sock';
			$candidates[] = '/tmp/flexicorp-pando.sock';
			$candidates[] = '/Users/mjanssen/programming/flexicorp/tmp/flexicorp-pando.sock';
			$normalized = array();
			foreach ( $candidates as $cand ) {
				$cand = trim((string)$cand);
				if ( $cand === '' ) continue;
				if ( $cand[0] !== '/' ) $cand = $root . '/' . $cand;
				if ( !in_array($cand, $normalized, true) ) $normalized[] = $cand;
			}
			foreach ( $normalized as $cand ) {
				if ( is_string($cand) && $cand !== '' && file_exists($cand) ) return $cand;
			}
			return count($normalized) ? $normalized[0] : '/tmp/flexicorp-pando.sock';
		}
	}

	if ( !function_exists('tt_flexicorp_pando_daemon_call') ) {
		function tt_flexicorp_pando_daemon_call($socketPath, $wire) {
			$fp = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, 1.5);
			if ( !$fp ) return array('ok' => false, 'error' => 'daemon socket connect failed: ' . $errstr, 'socket_errno' => $errno);
			stream_set_timeout($fp, 10);
			$payload = json_encode($wire, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ( @fwrite($fp, $payload . "\n") === false ) {
				fclose($fp);
				return array('ok' => false, 'error' => 'daemon socket write failed');
			}
			$raw = '';
			$start = microtime(true);
			while ( !feof($fp) ) {
				$line = fgets($fp);
				if ( $line === false ) {
					$meta = stream_get_meta_data($fp);
					if ( !empty($meta['timed_out']) || (microtime(true) - $start) > 10.0 ) break;
					usleep(10000);
					continue;
				}
				$raw .= $line;
				$data = json_decode($raw, true);
				if ( is_array($data) ) {
					fclose($fp);
					return array('ok' => true, 'data' => $data, 'raw' => $raw);
				}
			}
			fclose($fp);
			return array('ok' => false, 'error' => 'daemon returned non-JSON', 'raw' => $raw);
		}
	}

	if ( ! function_exists( 'tt_flexicorp_pando_json_error_message' ) ) {
		/**
		 * flexicorp-pando may signal failure as top-level "error", daemon { ok:false, error }, or
		 * flexicorp envelope { success:false, done:{ errors:[...] } }. Collect the first message.
		 */
		function tt_flexicorp_pando_json_error_message( $data ) {
			if ( ! is_array( $data ) ) {
				return '';
			}
			if ( isset( $data['error'] ) ) {
				$e = trim( (string) $data['error'] );
				if ( $e !== '' ) {
					return $e;
				}
			}
			if ( isset( $data['done']['errors'] ) && is_array( $data['done']['errors'] ) ) {
				foreach ( $data['done']['errors'] as $e ) {
					$e = trim( (string) $e );
					if ( $e !== '' ) {
						return $e;
					}
				}
			}
			return '';
		}
	}

	if ( ! function_exists( 'tt_flexicorp_exec_candidate_is_wrong_abi' ) ) {
		/**
		 * On macOS, is_executable() is true for Linux ELF binaries; exec then fails with
		 * "cannot execute binary file". Skip those so the same path (e.g. flexicorp_pando/build/
		 * after a native cmake --build on this machine) can hold a Mach-O binary instead.
		 */
		function tt_flexicorp_exec_candidate_is_wrong_abi( $path ) {
			if ( PHP_OS_FAMILY !== 'Darwin' ) {
				return false;
			}
			$fh = @fopen( $path, 'rb' );
			if ( ! $fh ) {
				return false;
			}
			$magic = fread( $fh, 4 );
			fclose( $fh );
			return $magic === "\x7fELF";
		}
	}

	if ( ! function_exists( 'tt_flexicorp_exec_candidate_is_wrong_format_on_linux' ) ) {
		/**
		 * On Linux, is_executable() is true for macOS Mach-O binaries and other non-ELF files.
		 * execve fails and the shell may try to run the file as a script, yielding errors like
		 * "Syntax error: word unexpected (expecting ")")" on line 1. Skip anything that is not
		 * ELF and not a #! script (Docker: wrong flexicorp-pando on PATH before the real build).
		 */
		function tt_flexicorp_exec_candidate_is_wrong_format_on_linux( $path ) {
			if ( PHP_OS_FAMILY !== 'Linux' ) {
				return false;
			}
			$fh = @fopen( $path, 'rb' );
			if ( ! $fh ) {
				return false;
			}
			$magic4 = fread( $fh, 4 );
			fclose( $fh );
			if ( strlen( $magic4 ) >= 4 && $magic4 === "\x7fELF" ) {
				return false;
			}
			$fh = @fopen( $path, 'rb' );
			$magic2 = fread( $fh, 2 );
			fclose( $fh );
			if ( strlen( $magic2 ) >= 2 && $magic2 === '#!' ) {
				return false;
			}
			return true;
		}
	}

	if ( !function_exists('tt_flexicorp_pando_cli_bin') ) {
		/**
		 * Path to flexicorp-pando. Prefer getset / env, then TEITOK findapp("flexicorp-pando") (same idea as
		 * tt_flexicorp_cqp_bin / tt_flexicorp_fqs_bin), then usual paths — PHP-FPM often has a minimal PATH.
		 */
		function tt_flexicorp_pando_cli_bin($projectRoot) {
			$cfg = function_exists('getset') ? trim((string)getset('pando/flexicorp_pando_bin', '')) : '';
			$flexicorpRepoRoot = dirname(__DIR__, 1);
			$candidates = array();
			$workspaceBuild = $flexicorpRepoRoot . '/flexicorp_pando/build/flexicorp-pando';
			$workspaceBuildDarwin = $flexicorpRepoRoot . '/flexicorp_pando/build-darwin/flexicorp-pando';
			// If config still points to legacy build-darwin but a newer standard build exists,
			// prefer the newer local build so PHP/web path matches direct CLI checks.
			if ( $cfg !== '' && strpos($cfg, '/flexicorp_pando/build-darwin/flexicorp-pando') !== false ) {
				if ( is_file($workspaceBuild) && is_executable($workspaceBuild) ) {
					$cfgMtime = @filemtime($cfg);
					$buildMtime = @filemtime($workspaceBuild);
					if ( $cfgMtime === false || $buildMtime === false || $buildMtime >= $cfgMtime ) {
						$candidates[] = $workspaceBuild;
					}
				}
			}
			if ( $cfg !== '' ) {
				$candidates[] = $cfg;
			}
			$envBin = trim( (string) ( getenv( 'FLEXICORP_PANDO_BIN' ) ?: '' ) );
			if ( $envBin !== '' ) {
				$candidates[] = $envBin;
			}
			// Prefer the workspace build first so local source changes are picked up
			// immediately (e.g. collocation ProgramOptions support) instead of stale
			// globally installed binaries in /usr/local or Homebrew paths.
			$candidates[] = $workspaceBuild;
			$candidates[] = $workspaceBuildDarwin;
			// findapp() may not probe all Homebrew layouts; keep explicit fallbacks.
			$candidates[] = '/opt/homebrew/bin/flexicorp-pando';
			$candidates[] = '/usr/local/bin/flexicorp-pando';
			// Typical Docker/easycorp layout: flexicorp tree at /opt/flexicorp (TEITOK may live under /var/www/html/teitok only).
			$candidates[] = '/opt/flexicorp/flexicorp_pando/build/flexicorp-pando';
			$candidates[] = rtrim((string)$projectRoot, '/') . '/Scripts/flexicorp-pando';
			if ( function_exists('findapp') ) {
				$fa = trim((string) findapp('flexicorp-pando'));
				if ( $fa !== '' ) {
					$candidates[] = $fa;
				}
			}
			$candidates[] = 'flexicorp-pando';
			foreach ( $candidates as $cand ) {
				$cand = trim((string)$cand);
				if ( $cand === '' ) {
					continue;
				}
				if ( strpos($cand, '/') !== false ) {
					if ( ! is_file($cand) || ! is_executable($cand) ) {
						continue;
					}
					if ( tt_flexicorp_exec_candidate_is_wrong_abi($cand) ) {
						continue;
					}
					if ( tt_flexicorp_exec_candidate_is_wrong_format_on_linux($cand) ) {
						continue;
					}
					return $cand;
				}
				$resolved = trim((string)shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null'));
				if ( $resolved !== '' && is_file($resolved) && is_executable($resolved) && ! tt_flexicorp_exec_candidate_is_wrong_abi($resolved) && ! tt_flexicorp_exec_candidate_is_wrong_format_on_linux($resolved) ) {
					return $resolved;
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_pando_native_cli_bin') ) {
		/**
		 * Path to upstream `pando` CLI (used for aligned `with` queries that return `pairs`).
		 */
		function tt_flexicorp_pando_native_cli_bin($projectRoot) {
			$cfg = function_exists('getset') ? trim((string)getset('pando/pando_bin', '')) : '';
			$flexicorpRepoRoot = dirname(__DIR__, 1);
			$candidates = array();
			if ( $cfg !== '' ) $candidates[] = $cfg;
			$envBin = trim( (string) ( getenv( 'PANDO_BIN' ) ?: '' ) );
			if ( $envBin !== '' ) $candidates[] = $envBin;
			$candidates[] = '/Users/mjanssen/programming/pando/build/pando';
			$candidates[] = $flexicorpRepoRoot . '/../pando/build/pando';
			$candidates[] = '/opt/homebrew/bin/pando';
			$candidates[] = '/usr/local/bin/pando';
			$candidates[] = 'pando';
			foreach ( $candidates as $cand ) {
				$cand = trim((string)$cand);
				if ( $cand === '' ) continue;
				if ( strpos($cand, '/') !== false ) {
					if ( ! is_file($cand) || ! is_executable($cand) ) continue;
					if ( tt_flexicorp_exec_candidate_is_wrong_abi($cand) ) continue;
					if ( tt_flexicorp_exec_candidate_is_wrong_format_on_linux($cand) ) continue;
					return $cand;
				}
				$resolved = trim((string)shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null'));
				if ( $resolved !== '' && is_file($resolved) && is_executable($resolved) && ! tt_flexicorp_exec_candidate_is_wrong_abi($resolved) && ! tt_flexicorp_exec_candidate_is_wrong_format_on_linux($resolved) ) {
					return $resolved;
				}
			}
			return '';
		}
	}

	if ( !function_exists('tt_flexicorp_pando_cli_call') ) {
		function tt_flexicorp_pando_cli_call($projectRoot, $query, $offset, $limit, $context, $attrs = '', $contextScope = 'tok') {
			$query = trim((string)$query);
			if ( $query === '' ) $query = '[]';
			$offset = max(0, intval($offset));
			$limit = max(1, intval($limit));
			$context = max(0, intval($context));
			$looksAlignedWith = (
				stripos($query, ' with ') !== false
				&& strpos($query, '::') !== false
			);
			$bin = tt_flexicorp_pando_cli_bin($projectRoot);
			$nativePandoBin = '';
			if ( $looksAlignedWith ) {
				$nativePandoBin = tt_flexicorp_pando_native_cli_bin($projectRoot);
			}
			if ( $bin === '' && $nativePandoBin === '' ) {
				return array(
					'ok' => false,
					'command' => '',
					'raw' => '',
					'data' => null,
					'error' => 'No runnable Pando CLI found (flexicorp-pando or pando).',
				);
			}
			// Prefer flexicorp-pando whenever available (it now serializes aligned pairs
			// with XML fragment fields). Use native pando only as a fallback when the
			// flexicorp-pando binary is unavailable.
			$usingNativePando = ( $looksAlignedWith && $bin === '' && $nativePandoBin !== '' );
			if ( $usingNativePando ) {
				$indexDir = function_exists('tt_flexicorp_pando_index_dir')
					? tt_flexicorp_pando_index_dir($projectRoot)
					: ( rtrim((string)$projectRoot, '/') . '/pando' );
				$cmdParts = array(
					escapeshellarg($nativePandoBin),
					escapeshellarg((string)$indexDir),
					escapeshellarg($query),
					'--api',
					'--offset', escapeshellarg((string)$offset),
					'--limit', escapeshellarg((string)$limit),
					'--context', escapeshellarg((string)$context),
				);
			} else {
				$cmdParts = array(
					escapeshellarg($bin),
					'--project-root', escapeshellarg((string)$projectRoot),
					'--query', escapeshellarg($query),
					'--offset', escapeshellarg((string)$offset),
					'--limit', escapeshellarg((string)$limit),
					'--context', escapeshellarg((string)$context),
				);
			}
			$attrs = trim((string)$attrs);
			if ( $attrs !== '' && !$usingNativePando ) {
				$cmdParts[] = '--attrs';
				$cmdParts[] = escapeshellarg($attrs);
			}
			$contextScope = trim((string)$contextScope);
			if ( $contextScope === '' ) {
				$contextScope = 'tok';
			}
			$envParts = array();
			if ( !$usingNativePando ) {
				$envParts[] = 'FLEXICORP_PANDO_CONTEXT_SCOPE=' . escapeshellarg($contextScope);
			}
			// CLI fallback path for collocation still needs explicit ProgramOptions.
			// Pass them as env so flexicorp-pando can apply them for `...; coll by ...;`.
			if ( isset($_REQUEST['coll_left']) ) {
				$envParts[] = 'FLEXICORP_PANDO_COLL_LEFT=' . escapeshellarg((string) max(0, intval($_REQUEST['coll_left'])));
			}
			if ( isset($_REQUEST['coll_right']) ) {
				$envParts[] = 'FLEXICORP_PANDO_COLL_RIGHT=' . escapeshellarg((string) max(0, intval($_REQUEST['coll_right'])));
			}
			if ( isset($_REQUEST['coll_min_freq']) ) {
				$envParts[] = 'FLEXICORP_PANDO_COLL_MIN_FREQ=' . escapeshellarg((string) max(0, intval($_REQUEST['coll_min_freq'])));
			}
			if ( isset($_REQUEST['coll_max_items']) ) {
				$envParts[] = 'FLEXICORP_PANDO_COLL_MAX_ITEMS=' . escapeshellarg((string) max(1, intval($_REQUEST['coll_max_items'])));
			}
			if ( isset($_REQUEST['coll_stoplist']) ) {
				$envParts[] = 'FLEXICORP_PANDO_COLL_STOPLIST=' . escapeshellarg((string) max(0, intval($_REQUEST['coll_stoplist'])));
			}
			if ( function_exists('tt_flexicorp_coll_measures_list_from_request') ) {
				$cmList = tt_flexicorp_coll_measures_list_from_request();
				if ( is_array($cmList) && count($cmList) > 0 ) {
					$envParts[] = 'FLEXICORP_PANDO_COLL_MEASURES=' . escapeshellarg(implode(',', $cmList));
				}
			}
			$cmdShell = ( count($envParts) ? ('env ' . implode(' ', $envParts) . ' ') : '' ) . implode(' ', $cmdParts);
			$stdout = '';
			$stderr = '';
			$proc = @proc_open(
				$cmdShell,
				array(
					0 => array( 'pipe', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			if ( is_resource( $proc ) ) {
				fclose( $pipes[0] );
				$stdout = stream_get_contents( $pipes[1] );
				fclose( $pipes[1] );
				$stderr = stream_get_contents( $pipes[2] );
				fclose( $pipes[2] );
				proc_close( $proc );
			} else {
				$stdout = (string) shell_exec( $cmdShell . ' 2>&1' );
				$stderr = '';
			}
			$raw = $stdout;
			if ( $stderr !== '' && trim( $stderr ) !== '' ) {
				$raw = $stdout . ( $stdout !== '' ? "\n" : '' ) . $stderr;
			}
			$data = json_decode( $stdout, true );
			if ( !is_array($data) && function_exists( 'tt_flexicorp_extract_json_payload_from_mixed_output' ) ) {
				$data = tt_flexicorp_extract_json_payload_from_mixed_output( $stdout );
			}
			if ( !is_array($data) && function_exists( 'tt_flexicorp_extract_json_payload_from_mixed_output' ) ) {
				$data = tt_flexicorp_extract_json_payload_from_mixed_output( $raw );
			}
			if ( !is_array($data) ) {
				$lines = preg_split( '/\r\n|\r|\n/', (string) $stdout );
				if ( is_array( $lines ) ) {
					for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
						$line = trim( (string) $lines[ $i ] );
						if ( $line === '' ) {
							continue;
						}
						if ( $line[0] !== '{' && $line[0] !== '[' ) {
							continue;
						}
						$try = json_decode( $line, true );
						if ( is_array( $try ) ) {
							$data = $try;
							break;
						}
					}
				}
			}
			if ( !is_array($data) ) {
				$hint = '';
				if ( $stderr !== '' && trim( $stderr ) !== '' ) {
					$hint = ' stderr: ' . tt_flexicorp_first_line( $stderr );
				} elseif ( $stdout !== '' ) {
					$hint = ' stdout: ' . tt_flexicorp_first_line( $stdout );
				}
				return array(
					'ok' => false,
					'command' => $cmdShell,
					'raw' => $raw,
					'data' => null,
					'error' => 'flexicorp-pando did not return valid JSON.' . $hint,
				);
			}
			$ok = !empty($data['ok']) || !empty($data['success']);
			if ( !$ok && tt_flexicorp_pando_top_level_looks_like_query_result( $data ) ) {
				$ok = true;
			}
			$errMsg = '';
			if ( ! $ok ) {
				$errMsg = tt_flexicorp_pando_json_error_message( $data );
				if ( $errMsg === '' ) {
					$errMsg = 'flexicorp-pando query failed';
				}
			}
			return array(
				'ok' => $ok,
				'command' => $cmdShell,
				'raw' => $raw,
				'data' => $data,
				'error' => $errMsg,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_first_line') ) {
		function tt_flexicorp_first_line( $text ) {
			$text = trim((string)$text);
			if ( $text === '' ) return '';
			$parts = preg_split('/\r\n|\r|\n/', $text);
			if ( !is_array($parts) || !count($parts) ) return $text;
			return trim((string)$parts[0]);
		}
	}

	if ( !function_exists('tt_flexicorp_pando_runtime_info') ) {
		/**
		 * Best-effort runtime diagnostics for pando/flexicorp-pando resolution.
		 * Exposed in UI state (debug-friendly) to show exact binaries/daemon process.
		 */
		function tt_flexicorp_pando_runtime_info( $projectRoot ) {
			$socket = tt_flexicorp_pando_socket($projectRoot);
			$cliBin = tt_flexicorp_pando_cli_bin($projectRoot);
			$sysPando = trim((string)shell_exec('command -v pando 2>/dev/null'));
			$sysFlexicorpPando = trim((string)shell_exec('command -v flexicorp-pando 2>/dev/null'));

			$cliVersion = '';
			if ( $cliBin !== '' ) {
				$cliVersion = tt_flexicorp_first_line((string)shell_exec(escapeshellarg($cliBin) . ' --version 2>&1'));
			}
			$pandoVersion = '';
			if ( $sysPando !== '' ) {
				$pandoVersion = tt_flexicorp_first_line((string)shell_exec(escapeshellarg($sysPando) . ' --version 2>&1'));
			}

			$daemonPid = '';
			$daemonCmd = '';
			if ( is_string($socket) && $socket !== '' && file_exists($socket) ) {
				$lsofRaw = (string) shell_exec('lsof -Fp -Fc -- ' . escapeshellarg($socket) . ' 2>/dev/null');
				if ( $lsofRaw !== '' ) {
					$lines = preg_split('/\r\n|\r|\n/', $lsofRaw);
					if ( is_array($lines) ) {
						foreach ( $lines as $line ) {
							$line = trim((string)$line);
							if ( $line === '' ) continue;
							if ( $daemonPid === '' && strlen($line) > 1 && $line[0] === 'p' ) {
								$daemonPid = substr($line, 1);
							}
						}
					}
				}
				if ( $daemonPid !== '' ) {
					$daemonCmd = trim((string)shell_exec('ps -p ' . escapeshellarg($daemonPid) . ' -o command= 2>/dev/null'));
					$daemonCmd = tt_flexicorp_first_line($daemonCmd);
				}
			}

			return array(
				'socket' => (string)$socket,
				'socket_exists' => ( is_string($socket) && $socket !== '' && file_exists($socket) ) ? true : false,
				'flexicorp_pando_bin' => (string)$cliBin,
				'flexicorp_pando_version' => (string)$cliVersion,
				'which_flexicorp_pando' => (string)$sysFlexicorpPando,
				'which_pando' => (string)$sysPando,
				'pando_version' => (string)$pandoVersion,
				'daemon_pid' => (string)$daemonPid,
				'daemon_command' => (string)$daemonCmd,
			);
		}
	}

	if ( !function_exists('tt_flexicorp_pando_daemon_has_strict_quoted_strings') ) {
		/**
		 * Detect whether the currently running daemon was started with
		 * --strict-quoted-strings (changes query semantics for quoted patterns).
		 */
		function tt_flexicorp_pando_daemon_has_strict_quoted_strings( $projectRoot ) {
			static $cache = array();
			$socket = (string) tt_flexicorp_pando_socket($projectRoot);
			if ( isset($cache[$socket]) ) return $cache[$socket];
			$info = tt_flexicorp_pando_runtime_info($projectRoot);
			$cmd = strtolower((string)($info['daemon_command'] ?? ''));
			$cache[$socket] = ( strpos($cmd, '--strict-quoted-strings') !== false );
			return $cache[$socket];
		}
	}

	if ( !function_exists('tt_flexencoder_has_pando_api') ) {
		/**
		 * True if flexencoder was built with USE_PANDO_API (Makefile PANDO_SRC).
		 * The --help text includes the long "C++ API" line only when that flag is set.
		 */
		function tt_flexencoder_has_pando_api( $path ) {
			if ( ! is_string( $path ) || $path === '' || ! is_file( $path ) || ! is_executable( $path ) ) {
				return false;
			}
			$lines = array();
			@exec( escapeshellarg( $path ) . ' --help 2>&1', $lines, $ret );
			$out = implode( "\n", $lines );
			return ( strpos( $out, 'Also build Pando index in DIR (C++ API' ) !== false );
		}
	}

	if ( !function_exists('tt_flexicorp_pando_top_level_looks_like_query_result') ) {
		/**
		 * True when flexicorp-pando returned native program JSON from run_program_json (multi-statement
		 * scripts with ';') rather than the flexicorp envelope { success, done: { result } }.
		 * Without this, daemon/CLI treat the call as failed (no top-level ok/success) and PHP
		 * leaves result empty so Stats (e.g. freq A, B, C by …) breaks.
		 */
		function tt_flexicorp_pando_top_level_looks_like_query_result( $a ) {
			if ( ! is_array( $a ) ) {
				return false;
			}
			if ( isset( $a['done'] ) && is_array( $a['done'] ) && isset( $a['done']['result'] ) ) {
				return false;
			}
			if ( ( ! empty( $a['success'] ) || ! empty( $a['ok'] ) ) && isset( $a['result'] ) && is_array( $a['result'] ) ) {
				return false;
			}
			$err = isset( $a['error'] ) ? trim( (string) $a['error'] ) : '';
			if ( $err !== '' && empty( $a['rows'] ) && empty( $a['hits'] ) && empty( $a['items'] ) && empty( $a['compare_queries'] ) ) {
				return false;
			}
			if ( isset( $a['compare_queries'] ) && is_array( $a['compare_queries'] ) ) {
				return true;
			}
			if ( isset( $a['rows'] ) && is_array( $a['rows'] ) ) {
				return true;
			}
			if ( isset( $a['table'] ) && is_array( $a['table'] ) ) {
				return true;
			}
			if ( isset( $a['items'] ) && is_array( $a['items'] ) ) {
				return true;
			}
			if ( isset( $a['hits'] ) && is_array( $a['hits'] ) ) {
				return true;
			}
			if ( isset( $a['total'] ) && isset( $a['items'] ) && is_array( $a['items'] ) ) {
				return true;
			}
			return false;
		}
	}

	if ( ! function_exists( 'tt_flexicorp_pando_normalize_multiquery_freq_result' ) ) {
		/**
		 * Some Pando program JSON builds multi-query freq rows with per-row `queries:{Q:{count:…}}`
		 * but omits top-level `compare_queries`. TEITOK Stats + promote-to-Stats need the names array.
		 */
		function tt_flexicorp_pando_normalize_multiquery_freq_result( &$result ) {
			if ( ! is_array( $result ) ) {
				return;
			}
			if ( isset( $result['compare_queries'] ) && is_array( $result['compare_queries'] ) && count( $result['compare_queries'] ) > 0 ) {
				return;
			}
			if ( isset( $result['rows'][0] ) && is_array( $result['rows'][0] ) ) {
				$first = &$result['rows'][0];
			} elseif ( isset( $result['table']['rows'][0] ) && is_array( $result['table']['rows'][0] ) ) {
				$first = &$result['table']['rows'][0];
			} else {
				return;
			}
			if ( isset( $first['queries'] ) && is_string( $first['queries'] ) && trim( $first['queries'] ) !== '' ) {
				$decoded = json_decode( $first['queries'], true );
				if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
					$first['queries'] = $decoded;
				}
			}
			if ( ! isset( $first['queries'] ) || ! is_array( $first['queries'] ) || ! count( $first['queries'] ) ) {
				return;
			}
			$result['compare_queries'] = array_keys( $first['queries'] );
			if ( ! isset( $result['result_type'] ) || trim( (string) $result['result_type'] ) === '' ) {
				$result['result_type'] = 'table';
			}
		}
	}

	if ( ! function_exists( 'tt_flexicorp_sanitize_pando_ident' ) ) {
		/**
		 * Single-token identifier for Pando program clauses (named queries, token anchors).
		 *
		 * @param mixed $s Raw request value.
		 * @return string Non-empty only when valid.
		 */
		function tt_flexicorp_sanitize_pando_ident( $s ) {
			$t = trim( (string) $s );
			if ( $t === '' ) {
				return '';
			}
			return preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $t ) ? $t : '';
		}
	}

	if ( !function_exists('tt_flexicorp_pando_run') ) {
		if ( !function_exists('tt_flexicorp_pando_merge_corpus_info_into_result') ) {
			/**
			 * Enrich pando info/status payload with corpus metadata from pando/corpus.info and xidx/docs.tbl.
			 * Mirrors the Python pando backend info shape used by `python -m flexicorp info --backend pando`.
			 */
			function tt_flexicorp_pando_merge_corpus_info_into_result( $projectRoot, &$doneResult ) {
				if ( !is_array($doneResult) ) {
					$doneResult = array();
				}
				$root = rtrim((string)$projectRoot, '/');
				$indexDir = isset($doneResult['index_dir']) && is_string($doneResult['index_dir']) && trim($doneResult['index_dir']) !== ''
					? rtrim((string)$doneResult['index_dir'], '/')
					: rtrim($root, '/') . '/pando';
				$doneResult['index_dir'] = $indexDir;
				$doneResult['corpus'] = strtoupper((string)basename($root));
				if ( !isset($doneResult['backend']) || trim((string)$doneResult['backend']) === '' ) {
					$doneResult['backend'] = 'pando';
				}
				$doneResult['descriptor'] = array(
					'id' => 'pando',
					'label' => 'pando',
					'supported_query_languages' => array('clickcql'),
					'supported_corpus_formats' => array('pando'),
					'default_query_language' => 'clickcql',
					'default_corpus_format' => 'pando',
				);

				// docs_count from xidx/docs.tbl
				$docsTbl = $root . '/xidx/docs.tbl';
				$docsCount = null;
				if ( is_file($docsTbl) && is_readable($docsTbl) ) {
					$lines = @file($docsTbl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
					if ( is_array($lines) ) {
						$docsCount = count($lines);
						$doneResult['docs_count'] = $docsCount;
					}
				}

				$infoPath = $indexDir . '/corpus.info';
				if ( !is_file($infoPath) || !is_readable($infoPath) ) {
					return;
				}
				$rawLines = @file($infoPath, FILE_IGNORE_NEW_LINES);
				if ( !is_array($rawLines) ) {
					return;
				}
				$info = array();
				foreach ( $rawLines as $line ) {
					$line = trim((string)$line);
					if ( $line === '' || $line[0] === '#' ) continue;
					$eq = strpos($line, '=');
					if ( $eq === false ) continue;
					$key = trim(substr($line, 0, $eq));
					$val = trim(substr($line, $eq + 1));
					if ( $key === '' ) continue;
					if ( $key === 'positional' || $key === 'region_attrs' || $key === 'structural' ) {
						$parts = preg_split('/\s*,\s*/', $val);
						$parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), function($x){ return $x !== ''; })) : array();
						$info[$key] = $parts;
					} else {
						$info[$key] = $val;
					}
				}
				$doneResult['corpus_info'] = $info;
				$doneResult['pattributes'] = ( isset($info['positional']) && is_array($info['positional']) ) ? $info['positional'] : array();
				$doneResult['struct_attributes'] = ( isset($info['structural']) && is_array($info['structural']) ) ? $info['structural'] : array();

				$sattrsByRegion = array();
				if ( isset($info['region_attrs']) && is_array($info['region_attrs']) ) {
					foreach ( $info['region_attrs'] as $ra ) {
						if ( !is_string($ra) || $ra === '' ) continue;
						$pos = strpos($ra, '_');
						if ( $pos === false ) continue;
						$reg = substr($ra, 0, $pos);
						$attr = substr($ra, $pos + 1);
						if ( $reg === '' || $attr === '' ) continue;
						if ( !isset($sattrsByRegion[$reg]) ) $sattrsByRegion[$reg] = array();
						$sattrsByRegion[$reg][] = $attr;
					}
				}
				$doneResult['sattributes_by_region'] = $sattrsByRegion;

				if ( isset($info['size']) ) {
					$n = intval($info['size']);
					if ( $n > 0 ) {
						$doneResult['tokens_count'] = $n;
					}
				}
				if ( !isset($doneResult['docs_count']) && $docsCount !== null ) {
					$doneResult['docs_count'] = $docsCount;
				}
				if ( isset($doneResult['docs_count']) ) {
					if ( !isset($doneResult['doc_count']) ) {
						$doneResult['doc_count'] = (int)$doneResult['docs_count'];
					}
					if ( !isset($doneResult['documents_count']) ) {
						$doneResult['documents_count'] = (int)$doneResult['docs_count'];
					}
				}
			}
		}

		function tt_flexicorp_pando_run($subcommandParts, $projectRoot) {
			if ( !is_array($subcommandParts) ) $subcommandParts = array($subcommandParts);
			$op = isset($subcommandParts[0]) ? (string)$subcommandParts[0] : '';
			$socket = tt_flexicorp_pando_socket($projectRoot);
			$indexDir = tt_flexicorp_pando_index_dir($projectRoot);
			$query = isset($subcommandParts[1]) ? (string)$subcommandParts[1] : trim((string)($_REQUEST['query'] ?? ''));
			$offset = intval($_REQUEST['start'] ?? 0);
			$limit = intval($_REQUEST['kwic_limit'] ?? 20);
			$defWin = function_exists( 'tt_flexicorp_default_kwic_window' ) ? tt_flexicorp_default_kwic_window() : 10;
			$context = function_exists( 'tt_flexicorp_clamp_int' )
				? tt_flexicorp_clamp_int( $_REQUEST['window'] ?? $defWin, $defWin, 1, 50 )
				: max( 0, intval( $_REQUEST['window'] ?? $defWin ) );
			if ( $offset < 0 ) $offset = 0;
			if ( $limit <= 0 ) $limit = 20;
			if ( $context < 0 ) $context = 0;

			if ( $op === 'status' || $op === 'info' || $op === 'list-docs' ) {
				$st = tt_flexicorp_pando_status($projectRoot);
				$doneResult = array(
					'backend' => 'flexicorp-pando',
					'project_root' => $projectRoot,
					'index_dir' => $indexDir,
					'available' => !empty($st['available']),
					'reason' => (string)($st['reason'] ?? ''),
				);
				if ( $op === 'list-docs' ) {
					$docsLimit = intval($_REQUEST['docs_limit'] ?? 100);
					if ( $docsLimit <= 0 ) $docsLimit = 100;
					$docsOffset = intval($_REQUEST['docs_offset'] ?? 0);
					if ( $docsOffset < 0 ) $docsOffset = 0;
					$docList = tt_flexicorp_local_docs_from_xmlfiles($projectRoot, $docsLimit, $docsOffset);
					$doneResult['docs'] = $docList['docs'];
					$doneResult['total'] = $docList['total'];
					$doneResult['returned'] = $docList['returned'];
					$doneResult['start'] = $docList['start'];
				}
				if ( $op === 'info' && function_exists( 'tt_flexicorp_pando_merge_corpus_info_into_result' ) ) {
					tt_flexicorp_pando_merge_corpus_info_into_result( $projectRoot, $doneResult );
				}
				$data = array('success' => true, 'done' => array('backend' => 'flexicorp-pando', 'operation' => $op, 'result' => $doneResult));
				return array('ok' => true, 'command' => 'flexicorp-pando-daemon:' . $op, 'raw' => json_encode($data), 'data' => $data, 'error' => '');
			}

			if ( $op === 'reindex' ) {
				// Resolution order (first executable with Pando API wins):
				// 1) TEITOK setting pando/flexencoder_bin (explicit override)
				// 2) flexencoder on PATH (e.g. /usr/local/bin after install)
				// 3) flexicorp repo Scripts/flexencoder, corpus Scripts, then dev fallbacks
				$repoFlexencoder = dirname(__DIR__, 1) . '/Scripts/flexencoder';
				$cfgBin = function_exists('getset') ? trim((string)getset('pando/flexencoder_bin', '')) : '';
				$pathFlexencoder = trim((string)shell_exec('command -v flexencoder 2>/dev/null'));
				$candidates = array();
				if ( $cfgBin !== '' ) {
					$candidates[] = $cfgBin;
				}
				if ( $pathFlexencoder !== '' ) {
					$candidates[] = $pathFlexencoder;
				}
				$candidates[] = $repoFlexencoder;
				$candidates[] = rtrim($projectRoot, '/') . '/Scripts/flexencoder';
				$candidates[] = '/Users/mjanssen/programming/easycorp/git/TEITOK/Scripts/flexencoder';
				$candidates[] = '/Users/mjanssen/programming/flexicorp/flexencoder/Scripts/flexencoder';
				$seen = array();
				$candidates = array_values(
					array_filter(
						$candidates,
						function ( $p ) use ( &$seen ) {
							if ( ! is_string( $p ) || $p === '' ) {
								return false;
							}
							if ( isset( $seen[ $p ] ) ) {
								return false;
							}
							$seen[ $p ] = true;
							return true;
						}
					)
				);
				$flexencoder = '';
				foreach ( $candidates as $cand ) {
					if ( tt_flexencoder_has_pando_api( $cand ) ) {
						$flexencoder = $cand;
						break;
					}
				}
				if ( $flexencoder === '' ) {
					$notes = array();
					foreach ( $candidates as $cand ) {
						if ( ! is_file( $cand ) ) {
							$notes[] = $cand . ' (missing)';
						} elseif ( ! is_executable( $cand ) ) {
							$notes[] = $cand . ' (not executable)';
						} else {
							$notes[] = $cand . ' (no Pando in --help; rebuild with PANDO_SRC)';
						}
					}
					$msg = 'No flexencoder with Pando API (--output-pando) found. Checked: ' . implode( '; ', $notes ) . '. '
						. 'From flexicorp/flexencoder run: make -f Makefile.flexencoder clean flexencoder PANDO_SRC=/path/to/pando BINDIR=../Scripts';
					$data = array(
						'success' => false,
						'done' => array(
							'errors' => array( $msg ),
							'result' => null,
						),
					);
					return array('ok' => false, 'command' => 'flexicorp-pando-reindex', 'raw' => json_encode($data), 'data' => $data, 'error' => 'flexencoder without Pando API');
				}
				$settingsTmp = rtrim($projectRoot, '/') . '/tmp/cqpsettings.xml';
				$settingsRes = rtrim($projectRoot, '/') . '/Resources/settings.xml';
				$cmdParts = array(
					escapeshellarg($flexencoder),
					'--project-root', escapeshellarg($projectRoot),
					'--output-pando', escapeshellarg($indexDir),
					'--verbose',
				);
				if ( is_file($settingsTmp) ) {
					$cmdParts[] = '--settings';
					$cmdParts[] = escapeshellarg($settingsTmp);
				} elseif ( is_file($settingsRes) ) {
					$cmdParts[] = '--settings';
					$cmdParts[] = escapeshellarg($settingsRes);
				}
				$cmd = implode(' ', $cmdParts) . ' 2>&1';
				$lines = array();
				$exitCode = 1;
				if ( function_exists('exec') ) {
					@exec( $cmd, $lines, $exitCode );
					$raw = implode( "\n", $lines );
				} else {
					$raw = (string) shell_exec( $cmd );
					$exitCode = -1;
				}
				$hasPandoLine = ( strpos( $raw, 'Pando index written to' ) !== false );
				// Same flexencoder run always writes project_root/xidx (backend-agnostic); failure to open
				// those files now aborts flexencoder with a non-zero exit (see flexencoder_xidx.cpp).
				$hasXidxLine = ( strpos( $raw, 'xidx written under' ) !== false );
				// flexencoder only prints that line when built with USE_PANDO_API (see Makefile.flexencoder PANDO_SRC).
				if ( $exitCode === -1 ) {
					$ok = $hasPandoLine;
				} else {
					$ok = ( $exitCode === 0 ) && $hasPandoLine;
				}
				// Legacy daemon invalidation removed: runtime path is CLI/FQS-oriented.
				if ( $ok && isset( $_SESSION['flexicorp_teitok'] ) && is_array( $_SESSION['flexicorp_teitok'] ) ) {
					unset( $_SESSION['flexicorp_teitok']['pando_freshness_hint_v1'] );
				}
				$reindexWarnings = array();
				if ( $ok && ! $hasXidxLine ) {
					$reindexWarnings[] = 'flexencoder did not print "xidx written under …" (older binary?). '
						. 'TEITOK XML hit fragments need project/xidx in sync with pando/; upgrade flexencoder or re-run encode with a build that writes xidx.';
				}
				$reindexErrors = array();
				if ( ! $ok ) {
					if ( $exitCode > 0 ) {
						$reindexErrors[] = 'flexencoder exited with code ' . (int) $exitCode . '.';
					}
					if ( strpos( $raw, 'Unknown argument' ) !== false && strpos( $raw, 'output-pando' ) !== false ) {
						$reindexErrors[] = 'This flexencoder was built without the Pando API: rebuild with PANDO_SRC, e.g. make -f Makefile.flexencoder PANDO_SRC=/path/to/pando PANDO_BUILD=/path/to/pando/build (see flexencoder/Makefile.flexencoder).';
					} elseif ( ! $hasPandoLine ) {
						$reindexErrors[] = 'flexencoder output did not contain the Pando completion line (expected: Pando index written to …).';
					}
					if ( empty( $reindexErrors ) ) {
						$reindexErrors[] = 'Pando reindex did not complete successfully.';
					}
					$tail = trim( implode( "\n", array_slice( explode( "\n", $raw ), -12 ) ) );
					if ( $tail !== '' ) {
						$reindexErrors[] = 'Last output lines: ' . $tail;
					}
				}
				$data = array(
					'success' => $ok,
					'done' => array(
						'backend' => 'flexicorp-pando',
						'operation' => 'reindex',
						'errors' => $reindexErrors,
						'warnings' => $reindexWarnings,
						'result' => array(
							'message' => $ok
								? 'Pando reindex completed (pando/ and project/xidx from the same flexencoder run; foreground direct flexencoder path, no background worker job).'
								: 'Pando reindex may have failed (foreground direct flexencoder path).',
							'source' => 'flexicorp-pando-foreground',
							'indexer' => array(
								'enqueued' => false,
								'job_id' => null,
								'kind' => 'flexicorp-pando',
								'mode' => 'foreground',
							),
							'index_dir' => $indexDir,
							'exit_code' => $exitCode,
							'flexencoder' => $flexencoder,
							'xidx_reported' => $hasXidxLine,
						),
					),
				);
				return array('ok' => $ok, 'command' => $cmd, 'raw' => $raw, 'data' => $data, 'error' => $ok ? '' : 'Pando reindex failed');
			}

			if ( $op !== 'query' && $op !== 'highlight' && $op !== 'freq' && $op !== 'coll' && $op !== 'reindex' ) {
				$data = array('success' => false, 'done' => array('errors' => array('Unsupported flexicorp-pando operation: ' . $op), 'result' => null));
				return array('ok' => false, 'command' => 'flexicorp-pando-daemon:' . $op, 'raw' => json_encode($data), 'data' => $data, 'error' => 'Unsupported operation');
			}
			if ( $op !== 'query' && $op !== 'freq' && $op !== 'coll' ) {
				$data = array('success' => false, 'done' => array('errors' => array('Operation not yet implemented for flexicorp-pando in flexicorp.php: ' . $op), 'result' => null));
				return array('ok' => false, 'command' => 'flexicorp-pando-daemon:' . $op, 'raw' => json_encode($data), 'data' => $data, 'error' => 'Operation not implemented');
			}

			$runQuery = trim((string)$query);
			if ( $op === 'freq' ) {
				$freqFieldRaw = $_REQUEST['freq_field'] ?? 'lemma';
				if ( is_array($freqFieldRaw) ) {
					$freqFieldParts = array();
					foreach ( $freqFieldRaw as $f ) {
						$f = trim((string)$f);
						if ( $f !== '' ) $freqFieldParts[] = $f;
					}
					$freqField = implode(', ', $freqFieldParts);
					if ( $freqField === '' ) $freqField = 'lemma';
				} else {
					$freqField = trim((string)$freqFieldRaw);
					if ( $freqField === '' ) $freqField = 'lemma';
				}
				$base = trim((string)($_REQUEST['query'] ?? ''));
				$queryNames = '';
				if ( function_exists('tt_flexicorp_teitok_parse_freq_query_names_from_query') ) {
					$queryNames = tt_flexicorp_teitok_parse_freq_query_names_from_query($base);
				}
				if ( function_exists('tt_flexicorp_teitok_sanitize_query_aggregations') ) {
					$base = tt_flexicorp_teitok_sanitize_query_aggregations($base);
				}
				if ( $base === '' ) {
					$data = array('success' => false, 'done' => array('errors' => array('Empty query not allowed for frequency operation.'), 'result' => null));
					return array('ok' => false, 'command' => 'flexicorp-pando-daemon:query', 'raw' => json_encode($data), 'data' => $data, 'error' => 'Empty query not allowed');
				}
				$runQuery = rtrim($base);
				if ( substr($runQuery, -1) !== ';' ) $runQuery .= ';';
				if ( $queryNames !== '' ) {
					$runQuery .= ' freq ' . $queryNames . ' by ' . $freqField . ';';
				} else {
					$runQuery .= ' freq by ' . $freqField . ';';
				}
			}
			if ( $op === 'coll' ) {
				$collField = trim((string)($_REQUEST['coll_field'] ?? ''));
				if ( $collField === '' ) {
					$collField = 'lemma';
				}
				$collNamedHit = function_exists( 'tt_flexicorp_sanitize_pando_ident' )
					? tt_flexicorp_sanitize_pando_ident( $_REQUEST['coll_named_query'] ?? '' )
					: '';
				$collAnchorTok = function_exists( 'tt_flexicorp_sanitize_pando_ident' )
					? tt_flexicorp_sanitize_pando_ident( $_REQUEST['coll_anchor_token'] ?? '' )
					: '';
				$base = trim((string)($_REQUEST['query'] ?? ''));
				if ( function_exists('tt_flexicorp_teitok_sanitize_query_aggregations') ) {
					$base = tt_flexicorp_teitok_sanitize_query_aggregations($base);
				}
				if ( $base === '' ) {
					$data = array('success' => false, 'done' => array('errors' => array('Empty query not allowed for collocation operation.'), 'result' => null));
					return array('ok' => false, 'command' => 'flexicorp-pando-daemon:query', 'raw' => json_encode($data), 'data' => $data, 'error' => 'Empty query not allowed');
				}
				$runQuery = rtrim($base);
				if ( substr($runQuery, -1) !== ';' ) {
					$runQuery .= ';';
				}
				$collMid = '';
				if ( $collNamedHit !== '' ) {
					$collMid .= ' (' . $collNamedHit . ')';
				}
				if ( $collAnchorTok !== '' ) {
					$collMid .= ' (on ' . $collAnchorTok . ')';
				}
				$runQuery .= ' coll' . $collMid . ' by ' . $collField . ';';
			}
			if ( $runQuery === '' ) {
				$data = array('success' => false, 'done' => array('errors' => array('Empty query not allowed.'), 'result' => null));
				return array('ok' => false, 'command' => 'flexicorp-pando-daemon:query', 'raw' => json_encode($data), 'data' => $data, 'error' => 'Empty query not allowed');
			}
			// For coll, put program options before `query` so flexicorp-pando’s minimal JSON parser
			// sees \"measures\" first (long CQL strings can confuse naive key scanning).
			$wireHead = array(
				'action' => 'query',
				'corpus' => $indexDir,
			);
			if ( $op === 'coll' ) {
				$cmList = function_exists('tt_flexicorp_coll_measures_list_from_request') ? tt_flexicorp_coll_measures_list_from_request() : array('logdice');
				$wireHead['measures'] = implode(',', $cmList);
				$wireHead['left'] = max(0, intval($_REQUEST['coll_left'] ?? 5));
				$wireHead['right'] = max(0, intval($_REQUEST['coll_right'] ?? 5));
				$wireHead['min_freq'] = max(0, intval($_REQUEST['coll_min_freq'] ?? 1));
				$wireHead['max_items'] = max(1, intval($_REQUEST['coll_max_items'] ?? 50));
				$wireHead['stoplist'] = max(0, intval($_REQUEST['coll_stoplist'] ?? 0));
			}
			$wire = array_merge(
				$wireHead,
				array(
					'query' => $runQuery,
					'offset' => $offset,
					'limit' => $limit,
					'max_total' => (
						(
							$op !== 'query'
							|| (
								function_exists('tt_flexicorp_teitok_parse_query_aggregation_parts')
								&& count(tt_flexicorp_teitok_parse_query_aggregation_parts((string)$runQuery)) > 0
							)
						)
						? 1000000
						: 10000
					),
					'context' => $context,
					'context_scope' => trim((string)($_REQUEST['context_scope'] ?? 'tok')),
					'attrs' => '',
					// flexencoder writes projectRoot/xidx; pass explicitly so XML fragments resolve when
					// pando/path nests the index (derive_project_root(index_dir) would point at the wrong xidx/).
					'project_root' => rtrim((string)$projectRoot, '/'),
					'client_ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
					'request_time' => gmdate('c'),
					'request_id' => substr(sha1(uniqid('fc_', true)), 0, 16),
					'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '',
				)
			);
			$call = tt_flexicorp_pando_cli_call($projectRoot, $runQuery, $offset, $limit, $context, '', (string)($wire['context_scope'] ?? 'tok'));
			$viaDaemon = false;
			$daemonError = '';
			if ( isset($call['raw']) ) {
				$call['raw'] = "[flexicorp] flexicorp-pando CLI route (daemon disabled; FQS path preferred)\n" . (string)$call['raw'];
			}
			if ( empty($call['ok']) || !isset($call['data']) || !is_array($call['data']) ) {
				$mergedErr = trim( (string) ( $call['error'] ?? '' ) );
				if ( $mergedErr === '' && isset( $call['data'] ) && is_array( $call['data'] ) ) {
					$mergedErr = tt_flexicorp_pando_json_error_message( $call['data'] );
				}
				if ( $mergedErr === '' ) {
					$mergedErr = 'flexicorp-pando call failed';
				}
				$data = array( 'success' => false, 'done' => array( 'errors' => array( $mergedErr ), 'result' => null ) );
				return array( 'ok' => false, 'command' => (string) ( $call['command'] ?? '' ), 'raw' => (string) ( $call['raw'] ?? '' ), 'data' => $data, 'error' => $mergedErr );
			}

			$resp = $call['data'];
			// Some toolchains wrap the program payload once under `data` without `done`.
			if ( is_array( $resp ) && ! isset( $resp['done'] ) && isset( $resp['data'] ) && is_array( $resp['data'] ) ) {
				$inner = $resp['data'];
				if ( tt_flexicorp_pando_top_level_looks_like_query_result( $inner ) ) {
					$resp = $inner;
				}
			}
			// Handle flexicorp envelope (success/done/result), old Pando (ok/result), and native
			// run_program_json output (rows/compare_queries/… at top level — no done.result).
			$isOk = !empty($resp['success']) || !empty($resp['ok']);
			if ( isset($resp['done']['result']) && is_array($resp['done']['result']) ) {
				$result = $resp['done']['result'];
			} elseif ( isset($resp['result']) && is_array($resp['result']) ) {
				$result = $resp['result'];
			} elseif ( tt_flexicorp_pando_top_level_looks_like_query_result( $resp ) ) {
				$result = $resp;
				$isOk = true;
			} else {
				$result = array();
			}
			// run_program_json output is often wrapped as done.result = { ok, operation: "freq"|"dcoll"|"coll"|…, result: { … } }.
			// TEITOK expects rows/collocates on the object we surface as search/stats result.
			if ( is_array( $result ) && isset( $result['result'] ) && is_array( $result['result'] ) ) {
				$inner = $result['result'];
				$opTag = isset( $result['operation'] ) ? strtolower( (string) $result['operation'] ) : '';
				$innerIsTable = isset( $inner['rows'] ) || isset( $inner['compare_queries'] ) || isset( $inner['totals_per_query'] );
				$innerIsCollocates = isset( $inner['collocates'] ) && is_array( $inner['collocates'] );
				$aggOps = array( 'freq', 'group', 'count', 'dist', 'coll', 'dcoll', 'keyness' );
				if ( $opTag === 'freq' || $innerIsTable || $innerIsCollocates || in_array( $opTag, $aggOps, true ) ) {
					if ( $opTag !== '' && ! isset( $inner['operation'] ) ) {
						$inner['operation'] = $opTag;
					}
					$result = $inner;
					$isOk = true;
				}
			}
			// dcoll/coll: expose collocates for Stats UI + hit-count helpers.
			if ( is_array( $result ) && isset( $result['collocates'] ) && is_array( $result['collocates'] ) ) {
				if ( ! isset( $result['result_type'] ) || trim( (string) $result['result_type'] ) === '' ) {
					$result['result_type'] = 'table';
				}
				if ( ! isset( $result['returned'] ) ) {
					$result['returned'] = count( $result['collocates'] );
				}
				if ( ! isset( $result['total'] ) && isset( $result['matches'] ) && is_numeric( $result['matches'] ) ) {
					$result['total'] = (int) $result['matches'];
				}
			}
			// Backward compatibility for earlier adapter output.
			if ( !isset($result['groups']) && isset($result['query_groups']) && is_array($result['query_groups']) ) {
				$result['groups'] = $result['query_groups'];
			}
			if ( !isset($result['engine']) || $result['engine'] === '' ) {
				$result['engine'] = $viaDaemon ? 'flexicorp-pando-daemon' : 'flexicorp-pando';
			}
			if ( isset($result['daemon_socket']) ) unset($result['daemon_socket']);
			tt_flexicorp_pando_normalize_aligned_pairs( $result );
			// Multi-query freq scripts return a distribution table (rows / compare_queries), not KWIC hits.
			if ( $op === 'query' && is_array( $result ) ) {
				$hasCmp = isset( $result['compare_queries'] ) && is_array( $result['compare_queries'] ) && count( $result['compare_queries'] ) > 0;
				$hasRows = isset( $result['rows'] ) && is_array( $result['rows'] ) && count( $result['rows'] ) > 0;
				if ( ( $hasCmp || $hasRows ) && ( ! isset( $result['result_type'] ) || trim( (string) $result['result_type'] ) === '' ) ) {
					$result['result_type'] = 'table';
				}
				if ( $hasRows && ( ! isset( $result['table'] ) || ! is_array( $result['table'] ) || ! isset( $result['table']['rows'] ) || ! count( $result['table']['rows'] ) ) ) {
					if ( ! isset( $result['table'] ) || ! is_array( $result['table'] ) ) {
						$result['table'] = array();
					}
					$result['table']['rows'] = $result['rows'];
				}
				if ( function_exists( 'tt_flexicorp_pando_normalize_multiquery_freq_result' ) ) {
					tt_flexicorp_pando_normalize_multiquery_freq_result( $result );
				}
			}
			// Compatibility bridge: some daemon builds can under-handle slash-regex queries
			// that flexicorp-pando CLI already supports. If daemon returns 0/0 on a slash-regex,
			// retry once through CLI and promote that result when it yields hits.
			if (
				$op === 'query'
				&& $viaDaemon
				&& isset($result['returned'], $result['total'])
				&& (int)$result['returned'] === 0
				&& (int)$result['total'] === 0
				&& preg_match('#^/(?:[^/\\\\]|\\\\.)+/$#', $runQuery)
			) {
					$cliProbe = tt_flexicorp_pando_cli_call($projectRoot, $runQuery, $offset, $limit, $context, '', (string)($wire['context_scope'] ?? 'tok'));
				if ( !empty($cliProbe['ok']) && isset($cliProbe['data']) && is_array($cliProbe['data']) ) {
					$cliResp = $cliProbe['data'];
					$cliResult = array();
					if ( isset($cliResp['done']['result']) && is_array($cliResp['done']['result']) ) {
						$cliResult = $cliResp['done']['result'];
					} elseif ( isset($cliResp['result']) && is_array($cliResp['result']) ) {
						$cliResult = $cliResp['result'];
					}
					$cliTotal = (int)($cliResult['total'] ?? 0);
					$cliReturned = (int)($cliResult['returned'] ?? 0);
					if ( $cliTotal > 0 || $cliReturned > 0 ) {
						$result = $cliResult;
						$result['engine'] = 'flexicorp-pando-cli-fallback';
						if ( !isset($resp['done']) || !is_array($resp['done']) ) $resp['done'] = array();
						if ( !isset($resp['done']['warnings']) || !is_array($resp['done']['warnings']) ) $resp['done']['warnings'] = array();
						$resp['done']['warnings'][] = 'Daemon returned 0/0 for slash-regex query; used flexicorp-pando CLI compatibility fallback.';
					}
				}
			}

			// Query-token order for legends/highlights: sort by match index only. Do not sort group
			// labels by name — a named second token (e.g. "noun") sorts before "t1" lexically and
			// would swap palette colours in UIs that order legend entries by name.
			if ( isset($result['groups']) && is_array($result['groups']) ) {
				usort(
					$result['groups'],
					function ( $a, $b ) {
						return ( (int) ( $a['index'] ?? 0 ) ) <=> ( (int) ( $b['index'] ?? 0 ) );
					}
				);
				foreach ( $result['groups'] as &$g ) {
					if ( !is_array( $g ) || ! isset( $g['index'] ) ) {
						continue;
					}
					// Stable positional id (t1, t2, …) from match index.
					$g['id'] = 't' . (string) ( (int) $g['index'] + 1 );
					// Pando may leave an auto-label like "t1" on a later unnamed token after the
					// first token was user-aliased (a:[…]). That collides with t1's id and
					// makes UIs paint every token with the last group's colour. Keep real aliases
					// (a, noun, …); rewrite stale tN auto-names to match this group's id.
					$name = trim( (string) ( $g['name'] ?? '' ) );
					if ( $name === '' || preg_match( '/^t\d+$/i', $name ) ) {
						$g['name'] = $g['id'];
					}
				}
				unset( $g );
			}

			// Build highlight_map for each hit from per-token group assignments.
			if ( isset($result['hits']) && is_array($result['hits']) ) {
				$buildHitHighlightMeta = function ( &$hitRow ) use ( $projectRoot, $result ) {
					if ( !is_array($hitRow) || !isset($hitRow['tokens']) || !is_array($hitRow['tokens']) ) return;
					$groupPlan = isset($result['_aligned_group_plan']) && is_array($result['_aligned_group_plan']) ? $result['_aligned_group_plan'] : array();
					$isTargetSide = isset($hitRow['aligned_role']) && (string)$hitRow['aligned_role'] === 'target';
					$sideOffset = $isTargetSide ? (int)($groupPlan['target_offset'] ?? 0) : (int)($groupPlan['source_offset'] ?? 0);
					$sideCount = $isTargetSide ? (int)($groupPlan['target_count'] ?? 0) : (int)($groupPlan['source_count'] ?? 0);
					$explicitMatchedIds = isset($hitRow['_matched_tok_ids']) && is_array($hitRow['_matched_tok_ids']) ? array_values(array_unique(array_map('strval', $hitRow['_matched_tok_ids']))) : array();
					$explicitMatchedMap = array();
					foreach ( $explicitMatchedIds as $mi => $mid ) {
						$mk = trim((string)$mid);
						if ( $mk === '' ) continue;
						$explicitMatchedMap[$mk] = (int)$mi;
					}
					$fragXml = isset($hitRow['fragment']) ? (string)$hitRow['fragment'] : '';
					if ( $fragXml === '' && isset($hitRow['context_xml']) ) {
						$fragXml = (string)$hitRow['context_xml'];
					}
					$fragTokIds = ( $fragXml !== '' ) ? tt_flexicorp_tok_ids_from_xml_fragment($fragXml) : array();
					$useFragZip = ( count($fragTokIds) === count($hitRow['tokens']) && count($fragTokIds) > 0 );
					$hasExplicitMatchFlags = false;
					foreach ( $hitRow['tokens'] as $t0 ) {
						if ( !is_array($t0) ) continue;
						foreach ( array('matched', 'is_match', 'in_match', 'isMatched', 'match') as $mk ) {
							if ( array_key_exists($mk, $t0) ) {
								$hasExplicitMatchFlags = true;
								break 2;
							}
						}
					}
					$allIds = array();
					$groupIds = array();
					foreach ( $hitRow['tokens'] as $ti => $tok ) {
						if ( !is_array($tok) ) continue;
						// Prefer TEITOK xml:id over tuid to avoid accidental collisions.
						$tokId = (isset($tok['id']) && $tok['id'] !== '' && (string)$tok['id'] !== '_') ? (string)$tok['id']
							: (isset($tok['tuid']) && $tok['tuid'] !== '' ? (string)$tok['tuid'] : '');
						if ( $tokId === '' && $useFragZip ) {
							$tokId = (string)($fragTokIds[$ti] ?? '');
						}
						if ( $tokId === '' && isset($tok['corpus_pos']) ) {
							// Pando token corpus_pos indexing is shifted by +1 relative to the
							// xidx/tokens.bin corpus_pos used for tok_id_idx resolution.
							$tokId = tt_flexicorp_xidx_tok_id_string_for_corpus_pos(
								$projectRoot,
								(int)$tok['corpus_pos'] + 1
							);
						}
						if ( $tokId === '' ) continue;
						$tokIsMatched = true;
						if ( count($explicitMatchedMap) > 0 ) {
							$tokIsMatched = isset($explicitMatchedMap[$tokId]);
						}
						if ( count($explicitMatchedMap) === 0 && $hasExplicitMatchFlags ) {
							$tokIsMatched = false;
							foreach ( array('matched', 'is_match', 'in_match', 'isMatched', 'match') as $mk ) {
								if ( !array_key_exists($mk, $tok) ) continue;
								$mv = $tok[$mk];
								if ( is_bool($mv) ) {
									$tokIsMatched = $mv;
									break;
								}
								if ( is_numeric($mv) ) {
									$tokIsMatched = ( (int)$mv !== 0 );
									break;
								}
								$ms = strtolower(trim((string)$mv));
								$tokIsMatched = in_array($ms, array('1', 'true', 'yes', 'on', 'match', 'matched'), true);
								break;
							}
						}
						if ( !$tokIsMatched ) continue;
						$allIds[] = $tokId;
						$grp = null;
						if ( count($explicitMatchedMap) > 0 && isset($explicitMatchedMap[$tokId]) ) {
							$grp = (int)$explicitMatchedMap[$tokId];
						}
						if ( isset($tok['group']) ) {
							$grp = (int)$tok['group'];
						} elseif ( isset($tok['corpus_pos']) && isset($hitRow['groups']) && is_array($hitRow['groups']) ) {
							$cp = (int)$tok['corpus_pos'];
							foreach ( $hitRow['groups'] as $g ) {
								if ( !is_array($g) || !isset($g['index']) ) continue;
								$gs = isset($g['start']) ? (int)$g['start'] : null;
								$ge = isset($g['end']) ? (int)$g['end'] : null;
								if ( $gs === null || $ge === null ) continue;
								if ( $cp >= $gs && $cp <= $ge ) {
									$grp = (int)$g['index'];
									break;
								}
							}
						}
						if ( $grp === null ) {
							$grp = ( $sideCount > 0 ) ? (int)$ti : 0;
						}
						$grpGlobal = (int)$grp + (int)$sideOffset;
						if ( !isset($groupIds[$grpGlobal]) ) $groupIds[$grpGlobal] = array();
						$groupIds[$grpGlobal][] = $tokId;
					}
					ksort( $groupIds, SORT_NUMERIC );
					$hmGroups = array();
					$resultGroups = isset($result['groups']) && is_array($result['groups']) ? $result['groups'] : array();
					foreach ( $groupIds as $grpIdx => $ids ) {
						$name = '';
						$stableId = 't' . (string) ( (int) $grpIdx + 1 );
						foreach ( $resultGroups as $rg ) {
							if ( isset($rg['index']) && (int)$rg['index'] === $grpIdx ) {
								$name = (string)($rg['name'] ?? '');
								if ( isset($rg['id']) && trim((string)$rg['id']) !== '' ) {
									$stableId = (string)$rg['id'];
								}
								break;
							}
						}
						if ( $name === '' ) $name = 't' . ((int)$grpIdx + 1);
						$hmGroups[] = array(
							'id' => $stableId,
							'name' => $name,
							'tok_ids' => array_values($ids),
							'result_group' => isset($hitRow['aligned_pair_index']) ? (int)$hitRow['aligned_pair_index'] : null,
						);
					}
					$hitRow['highlight_map'] = array(
						'groups' => $hmGroups,
						'match' => array_values($allIds),
						'default' => array('tok_ids' => array_values($allIds)),
					);
					$hitRow['toks'] = array_values($allIds);
					$hf = isset($hitRow['facs']) ? trim((string)$hitRow['facs']) : '';
					$hb = isset($hitRow['bbox']) ? trim((string)$hitRow['bbox']) : '';
					$needFacs = ( $hf === '' || $hf === '_' );
					$needBbox = ( $hb === '' || $hb === '_' );
					if ( ( $needFacs || $needBbox ) && is_array($hitRow['tokens']) ) {
						foreach ( $hitRow['tokens'] as $t ) {
							if ( !is_array($t) ) continue;
							if ( $needFacs && isset($t['facs']) ) {
								$fv = trim((string)$t['facs']);
								if ( $fv !== '' && $fv !== '_' ) {
									$hitRow['facs'] = $fv;
									$needFacs = false;
								}
							}
							if ( $needBbox && isset($t['bbox']) ) {
								$bv = trim((string)$t['bbox']);
								if ( $bv !== '' && $bv !== '_' ) {
									$hitRow['bbox'] = $bv;
									$needBbox = false;
								}
							}
							if ( !$needFacs && !$needBbox ) break;
						}
					}
				};
				foreach ( $result['hits'] as &$hit ) {
					$buildHitHighlightMeta($hit);
					if ( isset($hit['aligned_counterpart']) && is_array($hit['aligned_counterpart']) ) {
						$buildHitHighlightMeta($hit['aligned_counterpart']);
					}
				}
				unset($hit);

				// Fragment/doc enrichment now comes from flexicorp-pando (daemon/FFI),
				// to keep heavy lifting out of PHP request handling.
			}

			$doneOp = 'query';
			if ( $op === 'freq' ) {
				$doneOp = 'freq';
			} elseif ( $op === 'coll' ) {
				$doneOp = 'coll';
			}
			// Query-program operations (freq/group/count/dist/coll/dcoll/keyness) can return
			// an effective operation in payload metadata; preserve it so downstream routing
			// uses authoritative backend JSON instead of query-string parsing fallbacks.
			$effectiveOp = '';
			if ( isset($result['operation']) && is_string($result['operation']) ) {
				$effectiveOp = strtolower(trim((string)$result['operation']));
			}
			if ( $effectiveOp === '' && isset($resp['operation_effective']) && is_string($resp['operation_effective']) ) {
				$effectiveOp = strtolower(trim((string)$resp['operation_effective']));
			}
			if ( $effectiveOp === '' && isset($resp['done']['result']['operation']) && is_string($resp['done']['result']['operation']) ) {
				$effectiveOp = strtolower(trim((string)$resp['done']['result']['operation']));
			}
			if ( $effectiveOp !== '' ) {
				$result['operation'] = $effectiveOp;
				if ( $op === 'query' ) {
					$doneOp = $effectiveOp;
				}
			}
			// Compatibility bridge: older flexicorp-pando daemon builds may return hit
			// payloads even for "freq by ..." queries. Derive table rows from hits so
			// Stats still shows usable values instead of empty placeholder rows.
			if ( $op === 'freq' ) {
				$hasFreqRows = (
					( isset($result['table']['rows']) && is_array($result['table']['rows']) && count($result['table']['rows']) > 0 ) ||
					( isset($result['rows']) && is_array($result['rows']) && count($result['rows']) > 0 ) ||
					( isset($result['items']) && is_array($result['items']) && count($result['items']) > 0 )
				);
				if ( !$hasFreqRows && isset($result['hits']) && is_array($result['hits']) && count($result['hits']) > 0 ) {
					$freqFieldRaw = $_REQUEST['freq_field'] ?? 'lemma';
					if ( is_array($freqFieldRaw) ) {
						$freqFieldParts = array();
						foreach ( $freqFieldRaw as $f ) {
							$f = trim((string)$f);
							if ( $f !== '' ) $freqFieldParts[] = $f;
						}
						$derivedField = implode(', ', $freqFieldParts);
						if ( $derivedField === '' ) $derivedField = 'lemma';
					} else {
						$derivedField = trim((string)$freqFieldRaw);
						if ( $derivedField === '' ) $derivedField = 'lemma';
					}
					// Region / document-level s-attributes (text_genre, …) are not present on token objects in
					// flexicorp-pando hit JSON — do not fall back to lemma/word (that mislabels the distribution).
					$isRegionSattr = function_exists( 'tt_flexicorp_teitok_freq_field_is_region_sattr' )
						&& tt_flexicorp_teitok_freq_field_is_region_sattr( $derivedField, $projectRoot );
					$countMap = array();
					foreach ( $result['hits'] as $hit ) {
						if ( !is_array($hit) ) continue;
						$tokens = isset($hit['tokens']) && is_array($hit['tokens']) ? $hit['tokens'] : array();
						if ( !count($tokens) ) continue;
						$tok = $tokens[0];
						if ( !is_array($tok) ) continue;
						$rawVal = '';
						if ( isset($tok[$derivedField]) ) {
							$rawVal = trim((string)$tok[$derivedField]);
						} elseif ( strpos($derivedField, '_') !== false && !$isRegionSattr ) {
							$rawVal = '';
						}
						if ( $rawVal === '' && !$isRegionSattr && isset($tok['lemma']) ) {
							$rawVal = trim((string)$tok['lemma']);
						}
						if ( $rawVal === '' && !$isRegionSattr && isset($tok['word']) ) {
							$rawVal = trim((string)$tok['word']);
						}
						if ( $rawVal === '' && !$isRegionSattr && isset($tok['form']) ) {
							$rawVal = trim((string)$tok['form']);
						}
						if ( $rawVal === '' ) continue;
						if ( !isset($countMap[$rawVal]) ) $countMap[$rawVal] = 0;
						$countMap[$rawVal] += 1;
					}
					if ( count($countMap) > 0 ) {
						arsort($countMap, SORT_NUMERIC);
						$derivedRows = array();
						foreach ( $countMap as $k => $n ) {
							$derivedRows[] = array(
								'value' => $k,
								'count' => (int)$n,
							);
						}
						$result['rows'] = $derivedRows;
						$result['items'] = $derivedRows;
						$result['field'] = $derivedField;
						$result['result_type'] = 'table';
						$result['returned'] = count($derivedRows);
						if ( !isset($result['total']) || !is_numeric($result['total']) ) {
							$result['total'] = count($derivedRows);
						}
						$isDebugMode = !empty($GLOBALS['debug']) || !empty($_REQUEST['debug']);
						if ( $isDebugMode ) {
							if ( !isset($resp['done']) || !is_array($resp['done']) ) $resp['done'] = array();
							if ( !isset($resp['done']['warnings']) || !is_array($resp['done']['warnings']) ) $resp['done']['warnings'] = array();
							$resp['done']['warnings'][] = 'Derived frequency rows from hit payload (compat mode: backend did not return native freq table).';
						}
					}
				}
			}
			if ( $op === 'freq' && isset($result['rows']) && is_array($result['rows']) && !isset($result['items']) ) {
				$result['items'] = $result['rows'];
			}
			if ( $op === 'freq' && isset($result['fields']) && is_array($result['fields']) && count($result['fields']) ) {
				$result['field'] = (string)$result['fields'][0];
			}
			// Native Pando freq JSON uses total_matches; Stats / augment expect total when present.
			if ( $op === 'freq' && is_array( $result ) ) {
				if ( ! isset( $result['total'] ) && isset( $result['total_matches'] ) && is_numeric( $result['total_matches'] ) ) {
					$result['total'] = (int) $result['total_matches'];
				}
				if ( isset( $result['rows'] ) && is_array( $result['rows'] ) && count( $result['rows'] ) > 0 && ! isset( $result['result_type'] ) ) {
					$result['result_type'] = 'table';
				}
			}
			$warnings = isset($resp['done']['warnings']) && is_array($resp['done']['warnings']) ? $resp['done']['warnings'] : array();
			// When no daemon socket is present, TEITOK uses flexicorp-pando CLI per request (supported path).
			// Keep this out of search/freq warning banners; surface in Debug via tt_flexicorp_debug_log + debugAppend.
			if ( !$viaDaemon && $daemonError !== '' ) {
				$note = 'flexicorp-pando CLI (' . $daemonError . ')';
				$cmdLine = trim( (string) ( $call['command'] ?? '' ) );
				if ( $cmdLine === '' ) {
					$cmdLine = '(cli path; command string missing from adapter)';
				}
				$rawOut = (string) ( $call['raw'] ?? '' );
				if ( strlen( $rawOut ) > 65536 ) {
					$rawOut = substr( $rawOut, 0, 65536 ) . "\n… [truncated at 64KiB for Debug panel]";
				}
				$diagEntry = array(
					'time' => date('H:i:s'),
					'backend' => 'flexicorp-pando',
					'operation' => 'query',
					'ok' => true,
					'command' => $cmdLine,
					'raw' => $rawOut,
					'errors' => array(),
					'warnings' => array($note),
					'result' => null,
				);
				tt_flexicorp_debug_log($diagEntry);
				$stripDebug = !empty($_REQUEST['ajax'])
					&& trim((string)($_REQUEST['active_tab'] ?? '')) !== 'debug'
					&& !(isset($_GET['debug']) && (string)$_GET['debug'] === '1');
				if ( $stripDebug ) {
					if ( !isset($GLOBALS['tt_flexicorp_debug_append']) || !is_array($GLOBALS['tt_flexicorp_debug_append']) ) {
						$GLOBALS['tt_flexicorp_debug_append'] = array();
					}
					$GLOBALS['tt_flexicorp_debug_append'][] = $diagEntry;
				}
			}
			$done = array(
				'backend' => 'flexicorp-pando',
				'operation' => $doneOp,
				'errors' => isset($resp['done']['errors']) && is_array($resp['done']['errors']) ? $resp['done']['errors'] : array(),
				'warnings' => $warnings,
				'result' => $result,
			);
			$data = array(
				'success' => $isOk,
				'done' => $done,
			);
			$topErr = '';
			if ( ! $isOk ) {
				$topErr = tt_flexicorp_pando_json_error_message( $resp );
				if ( $topErr === '' ) {
					$topErr = (string) ( $resp['error'] ?? 'Query failed' );
				}
			}
			return array( 'ok' => $isOk, 'command' => (string) ( $call['command'] ?? 'flexicorp-pando' ), 'raw' => (string) ( $call['raw'] ?? '' ), 'data' => $data, 'error' => $topErr );
		}
	}

	// Library-only include: require flexicorp_query.php (or define TT_FLEXICORP_LIBRARY_ONLY)
	// before loading this file to register tt_flexicorp_run / engine probes without running
	// the Corpus Search UI request handler below.
	if ( defined( 'TT_FLEXICORP_LIBRARY_ONLY' ) && TT_FLEXICORP_LIBRARY_ONLY ) {
		return;
	}

	$projectRoot = tt_flexicorp_corpus_project_root();
	tt_flexicorp_ensure_session();
	$actionName = tt_flexicorp_current_action();
	$isAdmin = isset($user['permissions']) && $user['permissions'] === 'admin';
	$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
	$GLOBALS['tt_flexicorp_debug_append'] = array();
	// Fast path for selection persistence: avoid backend probes and heavy bootstrap.
	if ( isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1' && isset($_REQUEST['save_selection']) && (string)$_REQUEST['save_selection'] === '1' ) {
		$backendFast = tt_flexicorp_backend_canonical( trim( (string) ( $_REQUEST['backend'] ?? '' ) ) );
		if ( $backendFast === '' ) {
			$backendFast = 'cqp';
		}
		$queryEngineFast = trim( (string) ( $_REQUEST['query_engine'] ?? '' ) );
		$queryLanguageFast = trim( (string) ( $_REQUEST['query_language'] ?? '' ) );
		$corpusFormatFast = trim( (string) ( $_REQUEST['corpus_format'] ?? '' ) );
		$groupHitsBySentenceFast = true;
		if ( isset($_REQUEST['group_hits_by_sentence']) ) {
			$txt = strtolower( trim( (string) $_REQUEST['group_hits_by_sentence'] ) );
			$groupHitsBySentenceFast = !in_array($txt, array('0', 'false', 'no', 'off', 'none', ''), true);
		}
		if ( isset($_SESSION) && is_array($_SESSION) ) {
			if ( !isset($_SESSION['flexicorp_teitok']) || !is_array($_SESSION['flexicorp_teitok']) ) {
				$_SESSION['flexicorp_teitok'] = array();
			}
			$_SESSION['flexicorp_teitok']['last_query_engine'] = $queryEngineFast;
			$_SESSION['flexicorp_teitok']['last_backend'] = $backendFast;
			$_SESSION['flexicorp_teitok']['last_query_language'] = $queryLanguageFast;
			$_SESSION['flexicorp_teitok']['last_corpus_format'] = $corpusFormatFast;
			$_SESSION['flexicorp_teitok']['group_hits_by_sentence'] = $groupHitsBySentenceFast ? 1 : 0;
		}
		if ( !headers_sent() ) header('Content-Type: application/json; charset=UTF-8');
		print json_encode(array(
			'ok' => true,
			'saved' => true,
			'settings' => array(
				'backend' => $backendFast,
				'queryEngine' => $queryEngineFast,
				'queryLanguage' => $queryLanguageFast,
				'corpusFormat' => $corpusFormatFast,
				'groupHitsBySentence' => $groupHitsBySentenceFast ? true : false,
			),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}
	// Fast path: query-builder field values for closed-list fields (loaded once per field on demand).
	if ( isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1' && isset($_REQUEST['qb_field_values']) && (string)$_REQUEST['qb_field_values'] === '1' ) {
		$fieldFast = trim((string)($_REQUEST['field'] ?? ''));
		$payload = function_exists('tt_flexicorp_fn_qb_field_value_payload')
			? tt_flexicorp_fn_qb_field_value_payload($fieldFast, $projectRoot, $isAdmin)
			: array('ok' => true, 'field' => $fieldFast, 'input' => 'text', 'closed_list' => false, 'source' => '', 'options' => array());
		if ( !headers_sent() ) header('Content-Type: application/json; charset=UTF-8');
		print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}
	// Fast path: query-builder suggestions for free-text fields (prefix mode with smart limits).
	if ( isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1' && isset($_REQUEST['qb_suggest_values']) && (string)$_REQUEST['qb_suggest_values'] === '1' ) {
		$fieldFast = trim((string)($_REQUEST['field'] ?? ''));
		$queryFast = trim((string)($_REQUEST['q'] ?? ''));
		$limitFast = intval($_REQUEST['limit'] ?? 30);
		$payload = function_exists('tt_flexicorp_fn_qb_field_suggestions_payload')
			? tt_flexicorp_fn_qb_field_suggestions_payload($fieldFast, $queryFast, $projectRoot, $isAdmin, $limitFast)
			: array('ok' => true, 'field' => $fieldFast, 'query' => $queryFast, 'enabled' => false, 'reason' => 'missing-handler', 'items' => array());
		if ( !headers_sent() ) header('Content-Type: application/json; charset=UTF-8');
		print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}
	$backendOverrides = tt_flexicorp_admin_backend_overrides($isAdmin);
	$backendOverrideArgs = tt_flexicorp_backend_override_extra_args($backendOverrides);
	$reqBackendForProbe = tt_flexicorp_backend_canonical(trim((string)($_REQUEST['backend'] ?? '')));
	$reqReindexBackendForProbe = tt_flexicorp_backend_canonical(trim((string)($_REQUEST['reindex_backend'] ?? '')));
	$reqQueryEngineForProbe = trim((string)($_REQUEST['query_engine'] ?? ''));
	$reqBackendComboForProbe = trim((string)($_REQUEST['backend_combo'] ?? ''));
	$manateeDeepProbeRequested = (
		$reqBackendForProbe === 'manatee'
		|| $reqReindexBackendForProbe === 'manatee'
		|| $reqQueryEngineForProbe === 'manatee'
		|| strpos($reqBackendComboForProbe, 'manatee:') === 0
		|| ($isAdmin && $debugMode)
	);
	// Native Manatee: default to filesystem-only checks; deep subprocess probe only when explicitly needed.
	$manateeProjectStatus = tt_flexicorp_manatee_project_status_resolved( $projectRoot, $backendOverrideArgs, $manateeDeepProbeRequested );
	$runProbe = trim((string)($_REQUEST['run'] ?? ''));
	$activeTabProbe = trim((string)($_REQUEST['active_tab'] ?? ''));
	$isAjaxProbe = isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1';
	// Search/kwic/freq/coll requests should not trigger full global backend discovery
	// (that can include unrelated checks like BlackLab/ClickHouse reachability).
	$skipOverviewProbe = in_array($runProbe, array('query', 'kwic', 'freq', 'coll'), true)
		|| ( $isAjaxProbe && $activeTabProbe === 'search' );
	$overviewCall = null;
	$overviewDone = array();
	$overviewResult = array();
	if ( !$skipOverviewProbe ) {
		$overviewCall = tt_flexicorp_run(array('info', 'backends'), 'clickhouse', $projectRoot, $backendOverrideArgs, null, null, null);
		$overviewDone = tt_flexicorp_done($overviewCall);
		$overviewResult = ( isset($overviewDone['result']) && is_array($overviewDone['result']) ) ? $overviewDone['result'] : array();
	}
	$availableBackends = ( isset($overviewResult['availableBackends']) && is_array($overviewResult['availableBackends']) )
		? array_values(array_unique(array_filter(array_map(function ($backendId) {
			return tt_flexicorp_backend_canonical($backendId);
		}, $overviewResult['availableBackends']), function ($backendId) {
			return $backendId !== '' && $backendId !== 'teitokxml';
		})))
		: tt_flexicorp_available_backends($projectRoot);
	// Python overview may omit backends that still work in this process (venv import vs. CLI,
	// MANATEE_API only in FPM env, etc.). Merge TEITOK filesystem probes like we do for query engines.
	foreach ( tt_flexicorp_available_backends( $projectRoot ) as $localBackend ) {
		$localBackend = tt_flexicorp_backend_canonical( (string) $localBackend );
		if ( $localBackend !== '' && $localBackend !== 'teitokxml' && ! in_array( $localBackend, $availableBackends, true ) ) {
			$availableBackends[] = $localBackend;
		}
	}
	// Reconcile with Python backendStatus when the availableBackends list desyncs (ordering/edge cases).
	if ( isset( $overviewResult['backendStatus'] ) && is_array( $overviewResult['backendStatus'] ) ) {
		foreach ( $overviewResult['backendStatus'] as $bid => $st ) {
			if ( ! is_array( $st ) || empty( $st['available'] ) ) {
				continue;
			}
			$bid = tt_flexicorp_backend_canonical( (string) $bid );
			if ( $bid === '' || $bid === 'teitokxml' ) {
				continue;
			}
			if ( ! in_array( $bid, $availableBackends, true ) ) {
				$availableBackends[] = $bid;
			}
		}
	}
	// Demote legacy flexi / ClickHouse: keep only when admin, explicitly enabled, or nothing else works.
	$showLegacyBackends = tt_flexicorp_show_legacy_backends( $isAdmin );
	$reqBackendForLegacy = tt_flexicorp_backend_canonical( trim( (string) ( $_REQUEST['backend'] ?? '' ) ) );
	$primaryAvailable = array_values( array_filter( $availableBackends, function ( $b ) {
		return ! tt_flexicorp_is_legacy_backend( $b );
	} ) );
	if ( ! $showLegacyBackends && count( $primaryAvailable ) > 0 ) {
		$keepLegacy = array();
		if ( tt_flexicorp_is_legacy_backend( $reqBackendForLegacy ) ) {
			$keepLegacy[] = $reqBackendForLegacy;
		}
		// Do not keep sticky legacy — visitors should move off deprecated engines.
		$availableBackends = array_values( array_unique( array_merge( $primaryAvailable, $keepLegacy ) ) );
	}
	$availableQueryEngines = array();
	if ( isset($overviewResult['queryEngines']) && is_array($overviewResult['queryEngines']) ) {
		foreach ( $overviewResult['queryEngines'] as $engineId => $engineCfg ) {
			if ( !is_array($engineCfg) ) continue;
			$engineId = tt_flexicorp_backend_canonical((string)$engineId);
			if ( $engineId === '' ) continue;
			if ( !empty($engineCfg['available']) ) $availableQueryEngines[] = $engineId;
		}
	}
	if ( empty($availableQueryEngines) ) {
		$availableQueryEngines = tt_flexicorp_available_query_engines($projectRoot);
	} else {
		// Overview JSON often omits "pando" even when flexicorp-pando is usable; merge in local probe
		// so session/request can keep query_engine=pando instead of falling back to cqp.
		foreach ( tt_flexicorp_available_query_engines($projectRoot) as $eng ) {
			if ( !in_array($eng, $availableQueryEngines, true) ) {
				$availableQueryEngines[] = $eng;
			}
		}
	}
	// Sticky: if the user/session asks for pando but the local probe omitted it, still accept it.
	$reqQeSticky = trim((string)($_REQUEST['query_engine'] ?? ''));
	if ( $reqQeSticky === 'pando' && !in_array('pando', $availableQueryEngines, true) ) {
		$availableQueryEngines[] = 'pando';
	}
	if ( isset($_SESSION['flexicorp_teitok']['last_query_engine']) && is_string($_SESSION['flexicorp_teitok']['last_query_engine']) ) {
		$sessQe = trim((string)$_SESSION['flexicorp_teitok']['last_query_engine']);
		if ( $sessQe === 'pando' && !in_array('pando', $availableQueryEngines, true) ) {
			$availableQueryEngines[] = 'pando';
		}
	}
	// Python overview may advertise "manatee" while TEITOK/PHP cannot run native Manatee (venv/bindings).
	if ( empty( $manateeProjectStatus['available'] ) ) {
		$availableBackends = array_values( array_filter( $availableBackends, function ( $b ) {
			return $b !== 'manatee';
		} ) );
		$availableQueryEngines = array_values( array_filter( $availableQueryEngines, function ( $e ) {
			return $e !== 'manatee';
		} ) );
	}
	$allCombos = ( isset($overviewResult['backendCombos']) && is_array($overviewResult['backendCombos']) )
		? array_values(array_filter(array_map(function ($combo) {
			if ( !is_array($combo) ) return null;
			$rawBackend = trim((string)($combo['backend'] ?? ''));
			if ( $rawBackend === 'flexicorp-pando' ) return null;
			$combo['backend'] = tt_flexicorp_backend_canonical($rawBackend);
			return $combo;
		}, $overviewResult['backendCombos']), function ($combo) {
			return is_array($combo) && !empty($combo['backend']);
		}))
		: tt_flexicorp_backend_combinations( $projectRoot, $manateeProjectStatus, $isAdmin );
	// Overview JSON can mark native Manatee available while TEITOK `info corpus` probe disagrees.
	foreach ( $allCombos as $aci => $ac ) {
		if ( ! is_array( $ac ) ) {
			continue;
		}
		if ( (string) ( $ac['id'] ?? '' ) !== 'manatee:manatee-cql:manatee' ) {
			continue;
		}
		$allCombos[ $aci ]['available'] = ! empty( $manateeProjectStatus['available'] );
		if ( isset( $manateeProjectStatus['reason'] ) ) {
			$allCombos[ $aci ]['reason'] = (string) $manateeProjectStatus['reason'];
		}
	}
	// Keep ClickHouse/ClickQL combo availability consistent across query-language rows.
	// Python overview may mark some clickql dialect rows stale/unavailable even when runtime
	// status is now healthy after reindex (or vice versa).
	$clickStatusForCombos = tt_flexicorp_clickhouse_status( $projectRoot, $backendOverrideArgs );
	foreach ( $allCombos as $aci => $ac ) {
		if ( ! is_array( $ac ) ) {
			continue;
		}
		$cb = tt_flexicorp_backend_canonical( (string) ( $ac['backend'] ?? '' ) );
		if ( $cb !== 'clickql' && $cb !== 'clickhouse' ) {
			continue;
		}
		$allCombos[ $aci ]['available'] = ! empty( $clickStatusForCombos['available'] );
		if ( isset( $clickStatusForCombos['reason'] ) ) {
			$allCombos[ $aci ]['reason'] = (string) $clickStatusForCombos['reason'];
		}
	}
	$defaultBackend = tt_flexicorp_backend_canonical(tt_flexicorp_backend_default());
	$preferredCombo = tt_flexicorp_preferred_combo($allCombos, $defaultBackend);
	$autoBackend = is_array($preferredCombo)
		? tt_flexicorp_backend_canonical((string)($preferredCombo['backend'] ?? ''))
		: '';
	if ( $autoBackend === '' ) {
		$autoBackend = 'cqp';
	}

	$requestBackend = tt_flexicorp_backend_canonical(trim((string)($_REQUEST['backend'] ?? '')));
	$requestQueryLanguage = trim((string)($_REQUEST['query_language'] ?? ''));
	$requestCorpusFormat = trim((string)($_REQUEST['corpus_format'] ?? ''));
	$requestRun = trim((string)($_REQUEST['run'] ?? ''));
	$reindexBackend = tt_flexicorp_backend_canonical(trim((string)($_REQUEST['reindex_backend'] ?? '')));
	$forceReindexBackend = ( $requestRun === 'reindex' && $reindexBackend !== '' );
	if ( $requestRun === 'reindex' && $reindexBackend !== '' ) {
		$requestBackend = $reindexBackend;
		$requestQueryLanguage = '';
		$requestCorpusFormat = '';
	}
	// Explicit settings default, else auto (pando → cqp → …). Never default to legacy flexi/click.
	if ( $requestBackend !== '' ) {
		$backend = $requestBackend;
	} elseif ( $defaultBackend !== '' ) {
		$backend = $defaultBackend;
	} else {
		$backend = $autoBackend;
	}
	// Sticky backend from last flexicorp run (save_selection AJAX or full request).
	$stickyBackendUnavailable = false;
	if ( $requestBackend === '' && isset($_SESSION) && is_array($_SESSION) && isset($_SESSION['flexicorp_teitok']['last_backend']) ) {
		$lb = tt_flexicorp_backend_canonical(trim((string)$_SESSION['flexicorp_teitok']['last_backend']));
		if ( $lb !== '' ) {
			$isLbPando = ( $lb === 'pando' );
			$lbManateeOk = false;
			if ( $isLbPando ) {
				$lbPandoSt = tt_flexicorp_pando_status($projectRoot);
				if ( !in_array('pando', $availableBackends, true) && !empty($lbPandoSt['available']) ) $availableBackends[] = 'pando';
			}
			if ( $lb === 'manatee' ) {
				$lbManateeOk = ! empty( $manateeProjectStatus['available'] );
				if ( !in_array('manatee', $availableBackends, true) && $lbManateeOk ) {
					$availableBackends[] = 'manatee';
				}
			}
			if ( in_array($lb, $availableBackends, true) || ( $isLbPando && !empty($lbPandoSt['available']) ) || ( $lb === 'manatee' && $lbManateeOk ) ) {
				$backend = $lb;
			} else {
				$stickyBackendUnavailable = true;
			}
		}
	}
	// Sticky session on a missing or legacy engine → preferred primary (not availableBackends[0]=flexi).
	if ( $requestBackend === '' && $stickyBackendUnavailable ) {
		$backend = $autoBackend;
		if ( ! in_array( $backend, $availableBackends, true ) && count( $availableBackends ) ) {
			$nonLegacy = array_values( array_filter( $availableBackends, function ( $b ) {
				return ! tt_flexicorp_is_legacy_backend( $b );
			} ) );
			$backend = count( $nonLegacy ) ? (string) $nonLegacy[0] : (string) $availableBackends[0];
		}
	}
	// Drop sticky legacy selections for visitors unless legacy engines are explicitly enabled.
	if (
		$requestBackend === ''
		&& tt_flexicorp_is_legacy_backend( $backend )
		&& ! tt_flexicorp_show_legacy_backends( $isAdmin )
	) {
		$backend = $autoBackend;
	}
	$backend = tt_flexicorp_backend_canonical($backend);
	$isPandoAlias = ( $backend === 'pando' );
	if ( $isPandoAlias ) {
		// Keep pando selectable even when python overview did not advertise it.
		$pandoStEarly = tt_flexicorp_pando_status($projectRoot);
		if ( !in_array('pando', $availableBackends, true) && !empty($pandoStEarly['available']) ) $availableBackends[] = 'pando';
	}
	$manateeStEarly = null;
	if ( $backend === 'manatee' ) {
		$manateeStEarly = $manateeProjectStatus;
		if ( !in_array('manatee', $availableBackends, true) && !empty($manateeStEarly['available']) ) {
			$availableBackends[] = 'manatee';
		}
	}
	// Do not trust Python overview for native Manatee; TEITOK probes decide (venv vs corpus folder shadowing, etc.).
	$manateeOkForSelection = (
		$backend === 'manatee'
		&& is_array( $manateeStEarly )
		&& ! empty( $manateeStEarly['available'] )
	);
	$backendSelectionError = '';
	$backendSelectionErrorDetail = '';
	$backendStatusReasonMap = array();
	if ( isset($overviewResult['backendStatus']) && is_array($overviewResult['backendStatus']) ) {
		foreach ( $overviewResult['backendStatus'] as $bid => $bst ) {
			$cbid = tt_flexicorp_backend_canonical((string)$bid);
			if ( $cbid === '' || !is_array($bst) ) continue;
			$backendStatusReasonMap[$cbid] = trim((string)($bst['reason'] ?? ''));
		}
	}
	if ( !$forceReindexBackend && !in_array($backend, $availableBackends, true) && !$isPandoAlias && !$manateeOkForSelection ) {
		if ( $requestBackend !== '' ) {
			if (
				$backend === 'manatee'
				&& is_array( $manateeStEarly )
				&& ! empty( $manateeStEarly['corpus_available'] )
				&& empty( $manateeStEarly['available'] )
			) {
				$why = isset( $manateeStEarly['reason'] ) ? trim( (string) $manateeStEarly['reason'] ) : '';
				$whyDetail = isset( $manateeStEarly['reason_detail'] ) ? trim( (string) $manateeStEarly['reason_detail'] ) : '';
				$backendSelectionError = $why !== '' ? $why : tt_flexicorp_manatee_public_bindings_short_reason();
				$backendSelectionErrorDetail = '';
				if ( $debugMode && $whyDetail !== '' ) {
					$backendSelectionErrorDetail = $whyDetail;
				}
			} else {
				$why = isset($backendStatusReasonMap[$backend]) ? trim((string)$backendStatusReasonMap[$backend]) : '';
				$backendSelectionError = $why !== ''
					? $why
					: 'Requested backend "' . $backend . '" is not available for this corpus.';
			}
		} elseif ( is_array($preferredCombo) ) {
			$backend = (string)($preferredCombo['backend'] ?? $autoBackend);
			if ( $requestQueryLanguage === '' ) $requestQueryLanguage = (string)($preferredCombo['queryLanguage'] ?? '');
			if ( $requestCorpusFormat === '' ) $requestCorpusFormat = (string)($preferredCombo['corpusFormat'] ?? '');
		} else {
			$nonLegacy = array_values( array_filter( $availableBackends, function ( $b ) {
				return ! tt_flexicorp_is_legacy_backend( $b );
			} ) );
			$backend = count( $nonLegacy )
				? (string) $nonLegacy[0]
				: ( count( $availableBackends ) ? (string) $availableBackends[0] : $autoBackend );
		}
	}
	$selectionBlockMessage = $backendSelectionError;
	if ( $selectionBlockMessage === '' && $backend === 'manatee' && empty( $manateeProjectStatus['available'] ) ) {
		$rb = isset( $manateeProjectStatus['reason'] ) ? trim( (string) $manateeProjectStatus['reason'] ) : '';
		$selectionBlockMessage = $rb !== '' ? $rb : tt_flexicorp_manatee_public_bindings_short_reason();
	}
	$reqQueryEngine = trim((string)($_REQUEST['query_engine'] ?? ''));
	// Non-flexi backends own their execution engine id; ignore stale query_engine
	// values carried over from previous UI state (e.g. pando -> pmltq switch).
	if ( $backend !== 'flexi' && $reqQueryEngine !== '' && $reqQueryEngine !== $backend ) {
		$reqQueryEngine = '';
	}
	$queryEngine = $reqQueryEngine;
	if ( ( $queryEngine === '' || !in_array($queryEngine, $availableQueryEngines, true) ) && isset($_SESSION) && is_array($_SESSION) && isset($_SESSION['flexicorp_teitok']['last_query_engine']) ) {
		$qeSess = trim((string)$_SESSION['flexicorp_teitok']['last_query_engine']);
		if ( $qeSess !== '' && in_array($qeSess, $availableQueryEngines, true) ) {
			$queryEngine = $qeSess;
		}
	}
	if ( $queryEngine === '' || !in_array($queryEngine, $availableQueryEngines, true) ) {
		if ( $backend !== 'flexi' && in_array($backend, $availableQueryEngines, true) ) {
			$queryEngine = $backend;
		} else {
			$queryEngine = count($availableQueryEngines) ? $availableQueryEngines[0] : '';
		}
	}
	// Native backends (manatee / cqp / pando): backend is authoritative, but only
	// auto-fill query_engine when request did not explicitly provide one.
	if ( $reqQueryEngine === '' && $backend !== 'flexi' && in_array($backend, $availableQueryEngines, true) ) {
		$queryEngine = $backend;
	}

	// Cold load: restore dialect + storage from last flexicorp run (save_selection or full request).
	if ( $requestQueryLanguage === '' && isset($_SESSION) && is_array($_SESSION) && isset($_SESSION['flexicorp_teitok']['last_query_language']) ) {
		$requestQueryLanguage = trim((string)$_SESSION['flexicorp_teitok']['last_query_language']);
	}
	if ( $requestCorpusFormat === '' && isset($_SESSION) && is_array($_SESSION) && isset($_SESSION['flexicorp_teitok']['last_corpus_format']) ) {
		$requestCorpusFormat = trim((string)$_SESSION['flexicorp_teitok']['last_corpus_format']);
	}

	// Logical query language and corpus storage format (shared contract with flexicorp CLI)
	$queryLanguage = $requestQueryLanguage;
	$corpusFormat = $requestCorpusFormat;
	$comboDefaultsByBackend = array();
	foreach ( $allCombos as $combo ) {
		if ( !is_array($combo) ) continue;
		$cb = tt_flexicorp_backend_canonical((string)($combo['backend'] ?? ''));
		if ( $cb === '' ) continue;
		$ql = trim((string)($combo['queryLanguage'] ?? ''));
		$cf = trim((string)($combo['corpusFormat'] ?? ''));
		if ( $ql === '' && $cf === '' ) continue;
		$candidate = array(
			'queryLanguage' => $ql,
			'corpusFormat' => $cf,
			'available' => !empty($combo['available']),
		);
		if ( !isset($comboDefaultsByBackend[$cb]) ) {
			$comboDefaultsByBackend[$cb] = $candidate;
		} elseif ( empty($comboDefaultsByBackend[$cb]['available']) && !empty($candidate['available']) ) {
			$comboDefaultsByBackend[$cb] = $candidate;
		}
	}

	if ( $backend !== 'flexi' ) {
		$defaults = isset($comboDefaultsByBackend[$backend]) && is_array($comboDefaultsByBackend[$backend])
			? $comboDefaultsByBackend[$backend]
			: array();
		// For non-flexi backends, keep dialect/storage aligned with the selected
		// backend combo instead of reusing stale session values.
		if ( isset($defaults['queryLanguage']) && trim((string)$defaults['queryLanguage']) !== '' ) {
			$queryLanguage = (string)$defaults['queryLanguage'];
		}
		if ( isset($defaults['corpusFormat']) && trim((string)$defaults['corpusFormat']) !== '' ) {
			$corpusFormat = (string)$defaults['corpusFormat'];
		}
		if ( in_array($backend, $availableQueryEngines, true) ) {
			$queryEngine = $backend;
		}
	} else {
		// When the request omits query_language/corpus_format (e.g. full reload), align with the
		// resolved execution engine so session-restored query_engine (cqp/manatee/pando) wins over
		// the generic "prefer Manatee else CWB" default.
		if ( $requestQueryLanguage === '' && $requestCorpusFormat === '' ) {
			if ( $queryEngine === 'pando' ) {
				$queryLanguage = 'pando-cql';
				$corpusFormat = 'pando';
			} elseif ( $queryEngine === 'manatee' ) {
				$queryLanguage = 'manatee-cql';
				$corpusFormat = 'manatee';
			} elseif ( $queryEngine === 'cqp' ) {
				$queryLanguage = 'cwb-cql';
				$corpusFormat = 'cwb';
			}
		}
		if ( $queryLanguage === '' && $corpusFormat === '' ) {
			// Prefer Manatee when corpus index exists, fall back to CWB when only that is present.
			if ( tt_flexicorp_manatee_corpus_available($projectRoot) ) {
				$queryLanguage = 'manatee-cql';
				$corpusFormat = 'manatee';
			} elseif ( tt_flexicorp_cqp_available($projectRoot) ) {
				$queryLanguage = 'cwb-cql';
				$corpusFormat = 'cwb';
			}
		} elseif ( $queryLanguage !== '' && $corpusFormat === '' ) {
			if ( $queryLanguage === 'manatee-cql' ) $corpusFormat = 'manatee';
			elseif ( $queryLanguage === 'cwb-cql' ) $corpusFormat = 'cwb';
		} elseif ( $corpusFormat !== '' && $queryLanguage === '' ) {
			if ( $corpusFormat === 'manatee' ) $queryLanguage = 'manatee-cql';
			elseif ( $corpusFormat === 'cwb' ) $queryLanguage = 'cwb-cql';
		}
	}

	// flexi: execution engine must agree with (queryLanguage, corpusFormat). Otherwise
	// preferredCombo can fill cwb-cql while session/URL restored query_engine=pando — UI "snaps back".
	if ( $backend === 'flexi' && $queryEngine !== '' && in_array($queryEngine, array('pando', 'manatee', 'cqp'), true) ) {
		if ( $queryEngine === 'pando' ) {
			$queryLanguage = 'pando-cql';
			$corpusFormat = 'pando';
		} elseif ( $queryEngine === 'manatee' ) {
			$queryLanguage = 'manatee-cql';
			$corpusFormat = 'manatee';
		} elseif ( $queryEngine === 'cqp' ) {
			$queryLanguage = 'cwb-cql';
			$corpusFormat = 'cwb';
		}
	}

	// No silent backend switching: explicit backend/query_engine conflicts are
	// treated as user-facing selection errors with a suggested backend.
	$requiredEngineByBackend = array();
	foreach ( $availableQueryEngines as $engineId ) {
		$engineId = tt_flexicorp_backend_canonical((string)$engineId);
		if ( $engineId === '' || $engineId === 'flexi' ) continue;
		$requiredEngineByBackend[$engineId] = $engineId;
	}
	if ( $selectionBlockMessage === '' && $reqQueryEngine !== '' && isset($requiredEngineByBackend[$backend]) ) {
		$requiredEngine = (string) $requiredEngineByBackend[$backend];
		if ( $reqQueryEngine !== $requiredEngine ) {
			$suggestBackend = $requiredEngine;
			$suggestText = in_array($suggestBackend, $availableBackends, true)
				? ' Try backend="' . $suggestBackend . '".'
				: '';
			$selectionBlockMessage =
				'Backend "' . $backend . '" cannot run with query_engine="' . $reqQueryEngine
				. '". Expected query_engine="' . $requiredEngine . '".'
				. $suggestText;
			$backendSelectionError = $selectionBlockMessage;
		}
	}
	if ( $selectionBlockMessage === '' && $backend === 'pmltq' && $queryLanguage !== '' && $queryLanguage !== 'pmltq' ) {
		$selectionBlockMessage = 'Backend "pmltq" requires query_language="pmltq" (current query_language="' . $queryLanguage . '").';
		$backendSelectionError = $selectionBlockMessage;
	}

	$fqsProbe = tt_flexicorp_fqs_probe($projectRoot, $isAdmin);
	$fqsBackendOverride = null;
	// FQS routing applies only to CWB/Pando execution modes. Do not infer FQS
	// routing from stale queryEngine values when an explicit non-FQS backend
	// (e.g. clickql/clickhouse/blacklab/manatee) is selected.
	$explicitNonFqsBackend = !in_array($backend, array('flexi', 'cqp', 'pando'), true);
	if ( !$explicitNonFqsBackend ) {
		if ( $queryEngine === 'pando' || $backend === 'pando' || $queryLanguage === 'pando-cql' || $corpusFormat === 'pando' ) {
			$fqsBackendOverride = 'pando';
		} elseif ( $queryEngine === 'cqp' || $backend === 'cqp' || $queryLanguage === 'cwb-cql' || $corpusFormat === 'cwb' ) {
			$fqsBackendOverride = 'cqp';
		}
	}
	$fqsRouteEligible = !empty($fqsProbe['ready_for_queries']) && $fqsBackendOverride !== null;
	$fqsProbe['routing_active'] = $fqsRouteEligible;
	$fqsProbe['routing_backend'] = $fqsBackendOverride;
	$fqsProbe['routing_reason'] = $fqsRouteEligible
		? 'query path routed via FQS'
		: (
			!empty($fqsProbe['ready_for_queries'])
				? 'current backend/query engine is not FQS-routed'
				: (string)($fqsProbe['reason'] ?? 'FQS not ready')
		);
	if ( !isset($GLOBALS['tt_flexicorp_debug_append']) || !is_array($GLOBALS['tt_flexicorp_debug_append']) ) {
		$GLOBALS['tt_flexicorp_debug_append'] = array();
	}
	$GLOBALS['tt_flexicorp_debug_append'][] = array(
		'time' => date('H:i:s'),
		'backend' => 'fqs',
		'operation' => 'probe',
		'ok' => !empty($fqsProbe['ready_for_queries']),
		'command' => 'fqs probe',
		'raw' => '',
		'errors' => empty($fqsProbe['ready_for_queries']) ? array((string)($fqsProbe['reason'] ?? 'FQS not ready')) : array(),
		'warnings' => array(),
		'result' => $fqsProbe,
	);
	$fqsActiveReindex = tt_flexicorp_fqs_reindex_active_jobs($fqsProbe, $projectRoot, 200);

	// AJAX: return only highlight API result for query syntax highlighting
	if ( isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1' && isset($_REQUEST['highlight_snippet']) ) {
		$highlightSnippet = (string) $_REQUEST['highlight_snippet'];
		$highlightSanitized = $highlightSnippet;
		if ( function_exists('tt_flexicorp_teitok_sanitize_query_aggregations') ) {
			$highlightSanitized = tt_flexicorp_teitok_sanitize_query_aggregations($highlightSnippet);
		}
		// Pando queries use tt_flexicorp_pando_run; highlight is not implemented there yet — delegate to flexi+Pando.
		$highlightBackend = $backend;
		$highlightQueryEngine = $queryEngine;
		if ( $backend === 'pando' ) {
			$highlightBackend = 'flexi';
			$highlightQueryEngine = 'pando';
		}
		$highlightCall = tt_flexicorp_run(
			array('highlight'),
			$highlightBackend,
			$projectRoot,
			array_merge($backendOverrideArgs, array(
				// Request token spans and let the frontend render safe HTML locally.
				// This avoids backend HTML truncation issues around '<' operators.
				'--format', 'tokens',
				'--snippet', escapeshellarg($highlightSanitized),
			)),
			$highlightQueryEngine,
			$queryLanguage,
			$corpusFormat
		);
		if ( !headers_sent() ) header('Content-Type: application/json; charset=UTF-8');
		$raw = (string)($highlightCall['raw'] ?? '');
		$payload = null;
		if ( $raw !== '' ) {
			$payload = json_decode($raw, true);
			if ( !is_array($payload) && function_exists('tt_flexicorp_extract_json_payload_from_mixed_output') ) {
				$jsonOnly = tt_flexicorp_extract_json_payload_from_mixed_output($raw);
				if ( $jsonOnly !== '' ) $payload = json_decode($jsonOnly, true);
			}
		}
		if ( is_array($payload) ) {
			print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		} else {
			print json_encode(array(
				'success' => false,
				'error' => (string)($highlightCall['error'] ?? 'highlight failed'),
				'done' => array(
					'backend' => (string)$backend,
					'operation' => 'highlight',
					'errors' => array((string)($highlightCall['error'] ?? 'highlight failed')),
					'warnings' => array(),
					'result' => null,
				),
				'raw' => $raw,
			), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		exit;
	}

	if ( isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1' && isset($_REQUEST['list_corpora']) && (string)$_REQUEST['list_corpora'] === '1' ) {
		if ( !headers_sent() ) header('Content-Type: application/json; charset=UTF-8');
		if ( !$isAdmin ) {
			print json_encode(array(
				'ok' => false,
				'error' => 'Admin permissions required.',
				'errors' => array('Admin permissions required.'),
				'result' => null,
			));
			exit;
		}
		$targetBackend = trim((string)($_REQUEST['backend'] ?? ''));
		if ( $targetBackend !== 'blacklab' ) {
			print json_encode(array(
				'ok' => false,
				'error' => 'Corpus listing is currently implemented only for BlackLab.',
				'errors' => array('Corpus listing is currently implemented only for BlackLab.'),
				'result' => null,
			));
			exit;
		}
		$corporaCall = tt_flexicorp_run(
			array('info'),
			'blacklab',
			$projectRoot,
			array_merge($backendOverrideArgs, array('-O', escapeshellarg('topic=corpora'))),
			null,
			'bcql',
			'blacklab'
		);
		print json_encode(tt_flexicorp_response_payload($corporaCall), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	// Reindex job status poll (for BlackLab queue when Docker is not accessible to the web server)
	if ( isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1' && isset($_REQUEST['reindex_status']) && (string)$_REQUEST['reindex_status'] === '1' ) {
		if ( !headers_sent() ) header('Content-Type: application/json; charset=UTF-8');
		$jobId = trim((string)($_REQUEST['reindex_job_id'] ?? ''));
		if ( $jobId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $jobId) ) {
			print json_encode(array('ok' => false, 'error' => 'Invalid or missing reindex_job_id', 'result' => null));
			exit;
		}
		$queueProjectRoot = trim((string)($_REQUEST['projectRoot'] ?? ''));
		if ( $queueProjectRoot === '' || !is_dir($queueProjectRoot) ) {
			$queueProjectRoot = $projectRoot;
		}
		$statusPathFlex = $queueProjectRoot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'flexicorp-reindex-jobs' . DIRECTORY_SEPARATOR . $jobId . '.json';
		if ( is_file($statusPathFlex) ) {
			$rawFlex = @file_get_contents($statusPathFlex);
			$dataFlex = is_string($rawFlex) ? json_decode($rawFlex, true) : null;
			if ( is_array($dataFlex) && isset($dataFlex['status'] ) ) {
				$st = (string) $dataFlex['status'];
				$mapped = 'pending';
				if ( $st === 'completed' ) {
					$mapped = 'done';
				} elseif ( $st === 'failed' ) {
					$mapped = 'failed';
				} elseif ( $st === 'running' || $st === 'queued' ) {
					$mapped = 'pending';
				}
				$createdTs = isset($dataFlex['created_ts']) ? (float)$dataFlex['created_ts'] : 0.0;
				$updatedTs = isset($dataFlex['updated_ts']) ? (float)$dataFlex['updated_ts'] : 0.0;
				$startedTs = isset($dataFlex['started_ts']) ? (float)$dataFlex['started_ts'] : 0.0;
				$finishedTs = isset($dataFlex['finished_ts']) ? (float)$dataFlex['finished_ts'] : 0.0;
				$durationSec = null;
				if ( $startedTs > 0.0 ) {
					$durationSec = ( $finishedTs > 0.0 ? $finishedTs : $updatedTs ) - $startedTs;
					if ( $durationSec < 0.0 ) $durationSec = 0.0;
				}
				$reqParams = ( isset($dataFlex['request']) && is_array($dataFlex['request']) && isset($dataFlex['request']['params']) && is_array($dataFlex['request']['params']) )
					? $dataFlex['request']['params']
					: array();
				$reindexBackends = array();
				if ( isset($reqParams['reindex_backends']) && is_array($reqParams['reindex_backends']) ) {
					$reindexBackends = array_values(array_filter(array_map('strval', $reqParams['reindex_backends']), function($x){ return trim($x) !== ''; }));
				}
				$resultFlex = ( isset($dataFlex['result']) && is_array($dataFlex['result']) ) ? $dataFlex['result'] : null;
				$flexencoderBin = null;
				if ( is_array($resultFlex) && isset($resultFlex['results']) && is_array($resultFlex['results']) ) {
					foreach ( $resultFlex['results'] as $rr ) {
						if ( is_array($rr) && (string)($rr['engine'] ?? '') === 'flexencoder' ) {
							$binVal = trim((string)($rr['binary'] ?? ''));
							if ( $binVal !== '' ) {
								$flexencoderBin = $binVal;
								break;
							}
						}
					}
				}
				$out = array(
					'status' => $mapped,
					'job_id' => $jobId,
					'progress' => ( isset($dataFlex['progress']) && is_array($dataFlex['progress']) ) ? $dataFlex['progress'] : null,
					'error' => isset($dataFlex['error']) ? $dataFlex['error'] : null,
					'source' => 'flexicorp',
					'created_ts' => $createdTs > 0.0 ? $createdTs : null,
					'updated_ts' => $updatedTs > 0.0 ? $updatedTs : null,
					'started_ts' => $startedTs > 0.0 ? $startedTs : null,
					'finished_ts' => $finishedTs > 0.0 ? $finishedTs : null,
					'duration_sec' => is_null($durationSec) ? null : round((float)$durationSec, 3),
					'reindex_backends' => $reindexBackends,
					'result' => $resultFlex,
					'flexencoder_binary' => $flexencoderBin,
				);
				$fqsMapPath = $queueProjectRoot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'flexicorp-reindex-jobs' . DIRECTORY_SEPARATOR . $jobId . '.fqs.json';
				if ( is_file($fqsMapPath) ) {
					$fqsMapRaw = @file_get_contents($fqsMapPath);
					$fqsMap = is_string($fqsMapRaw) ? json_decode($fqsMapRaw, true) : null;
					if ( is_array($fqsMap) ) {
						$fqsJobId = trim((string)($fqsMap['fqs_job_id'] ?? ''));
						$startedNotified = !empty($fqsMap['started_notified']);
						$finishedNotified = !empty($fqsMap['finished_notified']);
						if ( $fqsJobId !== '' && !empty($fqsProbe['ready_for_queries']) ) {
							if ( !$startedNotified ) {
								$startedNotified = tt_flexicorp_fqs_mark_reindex($fqsProbe, $fqsJobId, 'started');
								if ( $startedNotified ) $fqsMap['started_notified'] = true;
								tt_flexicorp_reindex_log_append($queueProjectRoot, 'local_job=' . $jobId . ' fqs_job=' . $fqsJobId . ' mark_started=' . ( $startedNotified ? 'ok' : 'fail' ));
							}
							if ( !$finishedNotified && ( $mapped === 'done' || $mapped === 'failed' ) ) {
								$finishStatus = ( $mapped === 'done' ) ? 'completed' : 'failed';
								$finishErr = $mapped === 'failed' ? (string)($dataFlex['error'] ?? 'reindex failed') : '';
								$finishRes = is_array($resultFlex) ? $resultFlex : array();
								$finishedNotified = tt_flexicorp_fqs_mark_reindex($fqsProbe, $fqsJobId, 'finished', $finishStatus, $finishErr, $finishRes);
								if ( $finishedNotified ) $fqsMap['finished_notified'] = true;
								tt_flexicorp_reindex_log_append($queueProjectRoot, 'local_job=' . $jobId . ' fqs_job=' . $fqsJobId . ' mark_finished=' . ( $finishedNotified ? 'ok' : 'fail' ) . ' status=' . $finishStatus);
							}
							@file_put_contents($fqsMapPath, json_encode($fqsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
						}
						$out['source'] = 'fqs';
						$out['fqs_job_id'] = $fqsJobId;
					}
				}
				if ( empty($out['source']) ) {
					$out['source'] = 'flexicorp';
				}
				print json_encode(array('ok' => true, 'result' => $out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				exit;
			}
		}

		// Direct FQS-controlled reindex path: no local flexicorp job file exists.
		$fqsOnly = tt_flexicorp_fqs_reindex_status_by_job_id($fqsProbe, $jobId, $queueProjectRoot);
		if ( is_array($fqsOnly) ) {
			print json_encode(array('ok' => true, 'result' => $fqsOnly), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		}
		// FQS-style ids should resolve via FQS control-plane. If a brand-new job is not
		// immediately visible yet, keep polling briefly before surfacing hard failure.
		if ( preg_match('/^rj-[a-zA-Z0-9_-]+$/', $jobId) ) {
			$ageSec = null;
			if ( preg_match('/^rj-([0-9]+)-/', $jobId, $mTs) ) {
				$rawTs = trim((string)$mTs[1]);
				if ( ctype_digit($rawTs) ) {
					$tsNum = (float)$rawTs;
					// Job ids currently use epoch nanoseconds; keep compatibility with ms/s variants.
					if ( $tsNum > 1000000000000000.0 ) {
						$tsNum = $tsNum / 1000000000.0;
					} elseif ( $tsNum > 1000000000000.0 ) {
						$tsNum = $tsNum / 1000.0;
					}
					$ageSec = time() - $tsNum;
				}
			}
			if ( is_null($ageSec) || $ageSec < 90 ) {
				print json_encode(array(
					'ok' => true,
					'result' => array(
						'status' => 'pending',
						'job_id' => $jobId,
						'fqs_job_id' => $jobId,
						'source' => 'fqs',
						'progress' => array(
							'phase' => 'queued',
							'message' => 'Sent to FQS; awaiting queue visibility/worker confirmation.',
						),
						'error' => null,
					),
				), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				exit;
			}
			print json_encode(array(
				'ok' => true,
				'result' => array(
					'status' => 'failed',
					'job_id' => $jobId,
					'fqs_job_id' => $jobId,
					'source' => 'fqs',
					'error' => 'FQS job not found in queue/history. Verify FQS server/db wiring and request logs.',
				),
			), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		}

		$statusPath = $queueProjectRoot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'flexicorp-jobs' . DIRECTORY_SEPARATOR . 'blacklab' . DIRECTORY_SEPARATOR . 'job-' . $jobId . '.status.json';
		if ( !is_file($statusPath) ) {
			print json_encode(array('ok' => true, 'result' => array('status' => 'pending', 'job_id' => $jobId)));
			exit;
		}
		$raw = @file_get_contents($statusPath);
		if ( $raw === false ) {
			print json_encode(array('ok' => false, 'error' => 'Could not read status file', 'result' => null));
			exit;
		}
		$data = json_decode($raw, true);
		if ( !is_array($data) ) {
			print json_encode(array('ok' => true, 'result' => array('status' => 'unknown', 'job_id' => $jobId)));
			exit;
		}
		print json_encode(array('ok' => true, 'result' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	$docsLimit = tt_flexicorp_clamp_int($_REQUEST['docs_limit'] ?? 10000, 10000, 1, 10000);
	$docsPerPage = tt_flexicorp_clamp_int($_REQUEST['docs_per_page'] ?? 10000, min($docsLimit, 10000), 1, 10000);
	$docsFilter = trim((string)($_REQUEST['docs_filter'] ?? ''));
	$kwicLimit = tt_flexicorp_clamp_int($_REQUEST['kwic_limit'] ?? 25, 25, 1, 200);
	$defaultKwicWindow = tt_flexicorp_default_kwic_window();
	$kwicWindow = tt_flexicorp_clamp_int( $_REQUEST['window'] ?? $defaultKwicWindow, $defaultKwicWindow, 1, 50 );
	$kwicStart = max(0, intval($_REQUEST['start'] ?? 0));
	$freqLimit = tt_flexicorp_clamp_int($_REQUEST['freq_limit'] ?? 100, 100, 1, 200);
	$kwicQuery = trim((string)($_REQUEST['query'] ?? ''));
	// Prevent visitors from sneaking expensive CQP aggregations into the base query.
	// Pando program scripts (e.g. "…; freq A,B by attr") must stay intact — stripping the trailing
	// freq clause breaks run_program_json and leaves Search at "0 hits" with no Stats promotion.
	$pandoExec = ( $backend === 'pando' ) || $queryEngine === 'pando';
	if ( !$isAdmin && ! $pandoExec && function_exists('tt_flexicorp_teitok_sanitize_query_aggregations') ) {
		$kwicQuery = tt_flexicorp_teitok_sanitize_query_aggregations($kwicQuery);
	}
	$kwicField = trim((string)($_REQUEST['field'] ?? 'lemma'));
	$kwicValue = trim((string)($_REQUEST['value'] ?? ''));
	$contextFormat = trim((string)($_REQUEST['context_format'] ?? 'xml'));
	if ( $contextFormat === '' || $contextFormat === 'none' ) $contextFormat = 'xml';
	$defaultContextScope = ( ( isset( $backend ) && $backend === 'pando' ) || ( isset( $queryEngine ) && $queryEngine === 'pando' ) ) ? 'tok' : 'window';
	// Pando corpus without TEITOK XML (index built straight from CoNLL-U etc.): no XML
	// fragments to cut a KWIC window from, so show whole sentences, rendered from the
	// engine's synthetic fragment (tokens with attributes, highlight by query token).
	$pandoWithoutXml = ( ( isset( $backend ) && $backend === 'pando' ) || ( isset( $queryEngine ) && $queryEngine === 'pando' ) )
		&& function_exists( 'tt_flexicorp_project_has_xml' )
		&& ! tt_flexicorp_project_has_xml( isset( $projectRoot ) ? $projectRoot : null );
	if ( $pandoWithoutXml ) $defaultContextScope = 's';
	// Pando with TEITOK XML: dependency queries (Pando's strength) read badly as a KWIC
	// line, so default to whole sentences (table view) when the corpus has a sentence
	// region that may be used as context. TEITOK's own cqp/defaults (searchtype=context)
	// below and an explicit context_scope in the request still win.
	elseif ( $defaultContextScope === 'tok' && function_exists( 'getset' )
		&& function_exists( 'tt_flexicorp_context_scope_region_allowlist_resolved' ) ) {
		$pandoSentSattr = getset( 'cqp/sattributes/s', null );
		if ( is_array( $pandoSentSattr ) && count( $pandoSentSattr )
			&& in_array( 's', tt_flexicorp_context_scope_region_allowlist_resolved( isset( $projectRoot ) ? $projectRoot : null ), true ) ) {
			$defaultContextScope = 's';
		}
	}
	if ( ! isset( $_REQUEST['context_scope'] ) && function_exists( 'tt_flexicorp_default_context_scope_from_teitok_cqp' ) ) {
		$cqpScope = tt_flexicorp_default_context_scope_from_teitok_cqp( isset( $projectRoot ) ? $projectRoot : null );
		if ( is_string( $cqpScope ) && $cqpScope !== '' ) {
			$defaultContextScope = $cqpScope;
		}
	}
	$contextScope = trim((string)($_REQUEST['context_scope'] ?? $defaultContextScope));
	$viewMode = trim((string)($_REQUEST['view_mode'] ?? 'table'));
	$groupHitsBySentence = true;
	if ( isset($_REQUEST['group_hits_by_sentence']) ) {
		$rawGroupReq = $_REQUEST['group_hits_by_sentence'];
		if ( is_bool($rawGroupReq) ) {
			$groupHitsBySentence = $rawGroupReq;
		} else {
			$txt = strtolower(trim((string)$rawGroupReq));
			$groupHitsBySentence = !in_array($txt, array('0', 'false', 'no', 'off', 'none', ''), true);
		}
	} elseif ( isset($_SESSION['flexicorp_teitok']['group_hits_by_sentence']) ) {
		$groupHitsBySentence = !empty($_SESSION['flexicorp_teitok']['group_hits_by_sentence']);
	}
	// Track whether the caller explicitly supplied freq_field — needed below to
	// avoid silently pre-checking the first Stats > Frequency field checkbox when
	// the user has only run a query and never touched the Frequency form.
	$freqFieldExplicit = isset($_REQUEST['freq_field']);
	$freqFieldRaw = $_REQUEST['freq_field'] ?? 'lemma';
	if ( is_array($freqFieldRaw) ) {
		$freqFieldParts = array();
		foreach ( $freqFieldRaw as $f ) {
			$f = trim((string)$f);
			if ( $f !== '' ) $freqFieldParts[] = $f;
		}
		$freqField = implode(', ', $freqFieldParts);
		if ( $freqField === '' ) {
			$freqField = 'lemma';
			// All-empty values is effectively "no selection".
			$freqFieldExplicit = false;
		}
	} else {
		$freqField = trim((string)$freqFieldRaw);
		if ( $freqField === '' ) {
			$freqField = 'lemma';
			$freqFieldExplicit = false;
		}
	}
	$collField = trim((string)($_REQUEST['coll_field'] ?? 'lemma'));
	$collLeft = max(0, intval($_REQUEST['coll_left'] ?? 5));
	$collRight = max(0, intval($_REQUEST['coll_right'] ?? 5));
	$collMinFreq = max(0, intval($_REQUEST['coll_min_freq'] ?? 1));
	$collMaxItems = max(1, intval($_REQUEST['coll_max_items'] ?? 100));
	$collStoplist = max(0, intval($_REQUEST['coll_stoplist'] ?? 0));
	$collAnchorToken = function_exists( 'tt_flexicorp_sanitize_pando_ident' )
		? tt_flexicorp_sanitize_pando_ident( $_REQUEST['coll_anchor_token'] ?? '' )
		: '';
	$collMeasureList = function_exists('tt_flexicorp_coll_measures_list_from_request') ? tt_flexicorp_coll_measures_list_from_request() : array('logdice');
	$collMeasures = implode(',', $collMeasureList);
	$run = trim((string)($_REQUEST['run'] ?? ''));
	$corpusInfoProbe = isset($_REQUEST['corpus_info_probe']) && (string)$_REQUEST['corpus_info_probe'] === '1';
	if ( $corpusInfoProbe ) {
		// Force explicit corpus-info refresh requests to run backend info.
		$run = 'info';
	}
	
	// Support autorun=1 from URL to pre-fill and immediately execute a search
	$autorunRaw = isset($_REQUEST['autorun']) ? trim((string)$_REQUEST['autorun']) : '';
	$autorun = ($autorunRaw === '1' || strtolower($autorunRaw) === 'true' || strtolower($autorunRaw) === 'yes');
	if ( $autorun && $run === '' && ($kwicQuery !== '' || $kwicValue !== '') ) {
		$run = 'query';
	}

	$activeTab = trim((string)($_REQUEST['active_tab'] ?? ''));
	$allowedTabs = array( 'overview', 'documents', 'search', 'frequency', 'debug' );
	if ( $activeTab !== '' && !in_array( $activeTab, $allowedTabs, true ) ) {
		$activeTab = '';
	}
	$isAjax = isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === '1';
	$isInitialShellRequest = ( !$isAjax && $run === '' );
	$isStatsActionAjax = ( $isAjax && ( $run === 'freq' || $run === 'coll' ) );
	if ( $activeTab === '' ) {
		$activeTab = ( $run === 'freq' || $run === 'coll' ) ? 'frequency' : ( ( $run === 'query' || $run === 'kwic' ) ? 'search' : ( $run === 'reindex' ? 'overview' : 'search' ) );
	}
	if ( $corpusInfoProbe ) {
		$activeTab = 'frequency';
	}
	// Let Stats (frequency) load even without a search so the "Corpus stats" subtab is accessible.

	// Include Stats (frequency) tab so corpus info/status load for the "Corpus stats" subpanel without forcing Overview.
	$loadOverview = ( !$isInitialShellRequest && ( $activeTab === 'overview' || $activeTab === 'debug' || $activeTab === 'frequency' ) );
	if ( $corpusInfoProbe || $run === 'info' ) {
		$loadOverview = true;
	}
	$loadDocuments = ( !$isInitialShellRequest && ( $activeTab === 'documents' ) );
	// Frequency/collocation AJAX runs should only execute stats calls; avoid extra status/info/list
	// work in the same request to prevent UI timeouts when backend probes are slow.
	if ( $isStatsActionAjax ) {
		$loadOverview = false;
		$loadDocuments = false;
	}
	$isReindexRun = ( $run === 'reindex' );
	$noBackendAvailableForQueries = empty($availableQueryEngines) || trim((string)$queryEngine) === '';
	$searchDisabledBecauseNoBackend = $noBackendAvailableForQueries
		&& !$isReindexRun
		&& in_array($run, array('query', 'kwic', 'freq', 'coll'), true);
	$searchDisabledMessage = 'Search is disabled because no query backend is available for this corpus yet. Run reindex before searching.';

	$statusCall = ( $loadOverview && !$isReindexRun && !$corpusInfoProbe && $run !== 'info' )
		? ( $selectionBlockMessage !== '' ? tt_flexicorp_error_call($backend, 'status', $selectionBlockMessage) : tt_flexicorp_run(array('status'), $backend, $projectRoot, $backendOverrideArgs, $queryEngine, $queryLanguage, $corpusFormat) )
		: null;
	$infoCall = null;
	if ( $loadOverview && !$isReindexRun ) {
		if ( $selectionBlockMessage !== '' ) {
			$infoCall = tt_flexicorp_error_call( $backend, 'info', $selectionBlockMessage );
		} elseif (
			$backend === 'manatee'
			&& $queryLanguage === 'manatee-cql'
			&& $corpusFormat === 'manatee'
			&& ! empty( $manateeProjectStatus['available'] )
			&& isset( $GLOBALS['tt_flexicorp_manatee_info_probe_call'] )
		) {
			$infoCall = $GLOBALS['tt_flexicorp_manatee_info_probe_call'];
		} else {
			$infoCall = tt_flexicorp_run( array( 'info', 'corpus' ), $backend, $projectRoot, $backendOverrideArgs, $queryEngine, $queryLanguage, $corpusFormat );
		}
	}
	$listCall = $loadDocuments ? ( $selectionBlockMessage !== '' ? tt_flexicorp_error_call($backend, 'list-docs', $selectionBlockMessage) : tt_flexicorp_run(
		array('list-docs'),
		$backend,
		$projectRoot,
		array_merge($backendOverrideArgs, array('--limit', escapeshellarg((string)$docsLimit))),
		$queryEngine,
		$queryLanguage,
		$corpusFormat
	)) : null;

	$kwicCall = null;
	$queryTimeMs = null;
	if ( ($run === 'query' || $run === 'kwic') && ( $kwicQuery !== '' || $kwicValue !== '' ) ) {
		// TEITOK-style recent query storage (session only). This enables TEITOK's
		// built-in query manager (querymng.php) to list "Recent Queries".
		//
		// We store CQL-like text even when user used field/value mode, so it can be re-run.
		// 'ql' is the flexicorp query_language id (dialect), not the legacy "cqp" action name.
		$teitokQueryDialect = tt_flexicorp_teitok_query_manager_ql($queryLanguage, $backend);
		$sessionQuery = $kwicQuery;
		if ( $sessionQuery === '' && $kwicValue !== '' ) {
			$val = str_replace('"', '\\"', $kwicValue);
			$sessionQuery = '[' . $kwicField . '="' . $val . '"]';
		}
		if ( $sessionQuery !== '' && isset($_SESSION) && is_array($_SESSION) ) {
			$qsid = time();
			if ( !isset($_SESSION['queries']) || !is_array($_SESSION['queries']) ) $_SESSION['queries'] = array();
			$_SESSION['queries'][$qsid] = array('query' => $sessionQuery, 'ql' => $teitokQueryDialect);
			tt_flexicorp_recent_queries_push_for_language($queryLanguage, $sessionQuery);
		}

		if ( $searchDisabledBecauseNoBackend ) {
			if ( $isAdmin ) {
				$kwicCall = tt_flexicorp_search_disabled_ok_call($backend, 'query', $searchDisabledMessage);
			} else {
				$kwicCall = tt_flexicorp_error_call($backend, 'query', $searchDisabledMessage);
			}
		} elseif ( $selectionBlockMessage !== '' ) {
			$kwicCall = tt_flexicorp_error_call($backend, 'query', $selectionBlockMessage);
		} else {
		$queryStart = microtime(true);
		if ( $fqsRouteEligible ) {
			if ( $kwicQuery !== '' ) {
				$kwicQueryForRun = $kwicQuery;
				if ( $fqsBackendOverride === 'pando' && function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) ) {
					$kwicQueryForRun = tt_flexicorp_teitok_query_effective_pando_search_query( $kwicQuery );
				}
			} else {
				$val = str_replace('"', '\\"', $kwicValue);
				$kwicQueryForRun = '[' . $kwicField . '="' . $val . '"]';
			}
				$kwicCall = tt_flexicorp_fqs_query_call(
				$fqsProbe,
				$kwicQueryForRun,
				$queryLanguage,
				$kwicStart,
				$kwicLimit,
					$fqsBackendOverride,
					array(
						'window' => (int)$kwicWindow,
						'context_scope' => (string)$contextScope,
						'context_format' => (string)$contextFormat,
						'flexicorp_fragment_kwic_cpos_span' => (
							strtolower(trim((string)$contextFormat)) === 'xml'
							&& in_array(strtolower(trim((string)$contextScope)), array('window', 'tok'), true)
						),
						'fragment' => ! empty( $pandoWithoutXml ),
					)
			);
		} else {
			$extraArgs = array_merge($backendOverrideArgs, array(
				'--limit', escapeshellarg((string)$kwicLimit),
				'--window', escapeshellarg((string)$kwicWindow),
				'--start', escapeshellarg((string)$kwicStart),
			));
			if ( $contextFormat !== '' ) {
				$extraArgs[] = '--context-format';
				$extraArgs[] = escapeshellarg($contextFormat);
			}
			if ( $contextScope !== '' ) {
				$extraArgs[] = '--context-scope';
				$extraArgs[] = escapeshellarg($contextScope);
			}
			// KWIC token-window XML span (first..last displayed token byte range) is the only
			// way to get a real ±N-token context for dtok-bearing TEITOK corpora; without this
			// flag the CQP backend falls back to extract_teitok_fragment_xml(scope="window"),
			// which has no <window> element to anchor on and degrades to the parent <tok> of
			// the matched dtok — losing all left/right context. Mirror the FQS-route behaviour
			// at lines 6010-6013 so the local flexicorp CLI fallback path stays in sync.
			if (
				strtolower(trim((string)$contextFormat)) === 'xml'
				&& in_array(strtolower(trim((string)$contextScope)), array('window', 'tok'), true)
			) {
				$extraArgs[] = '--flexicorp-fragment-kwic-cpos-span';
			}
			if ( $kwicQuery !== '' ) {
				$kwicQueryForRun = $kwicQuery;
				if ( $pandoExec && function_exists( 'tt_flexicorp_teitok_query_effective_pando_search_query' ) ) {
					$kwicQueryForRun = tt_flexicorp_teitok_query_effective_pando_search_query( $kwicQuery );
				}
				$kwicCall = tt_flexicorp_run(array('query', $kwicQueryForRun), $backend, $projectRoot, $extraArgs, $queryEngine, $queryLanguage, $corpusFormat);
			} else {
				if ( $kwicField !== '' ) {
					$extraArgs[] = '--field';
					$extraArgs[] = escapeshellarg($kwicField);
				}
				$extraArgs[] = '--value';
				$extraArgs[] = escapeshellarg($kwicValue);
				$kwicCall = tt_flexicorp_run(array('query'), $backend, $projectRoot, $extraArgs, $queryEngine, $queryLanguage, $corpusFormat);
			}
		}
		$queryTimeMs = (int) round((microtime(true) - $queryStart) * 1000);
		}
	}

	$freqCall = null;
	if ( $run === 'freq' && $freqField !== '' ) {
		if ( $searchDisabledBecauseNoBackend ) {
			if ( $isAdmin ) {
				$freqCall = tt_flexicorp_search_disabled_ok_call($backend, 'freq', $searchDisabledMessage);
			} else {
				$freqCall = tt_flexicorp_error_call($backend, 'freq', $searchDisabledMessage);
			}
		} elseif ( $selectionBlockMessage !== '' ) {
			$freqCall = tt_flexicorp_error_call($backend, 'freq', $selectionBlockMessage);
		} elseif ( $fqsRouteEligible && $fqsBackendOverride === 'pando' ) {
			$freqBaseQuery = trim((string)($_REQUEST['query'] ?? ''));
			if ( $freqBaseQuery === '' && $kwicValue !== '' ) {
				$freqEscValue = str_replace(array('\\', '"'), array('\\\\', '\\"'), $kwicValue);
				$freqEscField = preg_replace('/[^A-Za-z0-9_:\\.-]/', '', (string)$kwicField);
				if ( $freqEscField === '' ) $freqEscField = 'lemma';
				$freqBaseQuery = '[' . $freqEscField . '="' . $freqEscValue . '"]';
			}
			$freqQueryNames = '';
			if ( function_exists('tt_flexicorp_teitok_parse_freq_query_names_from_query') ) {
				$freqQueryNames = tt_flexicorp_teitok_parse_freq_query_names_from_query($freqBaseQuery);
			}
			if ( function_exists('tt_flexicorp_teitok_sanitize_query_aggregations') ) {
				$freqBaseQuery = tt_flexicorp_teitok_sanitize_query_aggregations($freqBaseQuery);
			}
			$freqBaseQuery = trim((string)$freqBaseQuery);
			if ( $freqBaseQuery === '' ) {
				$freqCall = tt_flexicorp_error_call('fqs', 'freq', 'Empty query not allowed for frequency operation.');
			} else {
				$freqQuery = rtrim($freqBaseQuery);
				if ( substr($freqQuery, -1) !== ';' ) $freqQuery .= ';';
				if ( $freqQueryNames !== '' ) {
					$freqQuery .= ' freq ' . $freqQueryNames . ' by ' . $freqField . ';';
				} else {
					$freqQuery .= ' freq by ' . $freqField . ';';
				}
				$freqCall = tt_flexicorp_fqs_query_call(
					$fqsProbe,
					$freqQuery,
					$queryLanguage,
					0,
					$freqLimit,
					$fqsBackendOverride
				);
			}
		} else {
		$freqCall = tt_flexicorp_run_freq(
			$backend,
			$projectRoot,
			array_merge($backendOverrideArgs, array('--field', escapeshellarg($freqField), '--limit', escapeshellarg((string)$freqLimit))),
			$queryEngine,
			$queryLanguage,
			$corpusFormat
		);
		}
	}

	$collCall = null;
	if ( $run === 'coll' ) {
		if ( $searchDisabledBecauseNoBackend ) {
			if ( $isAdmin ) {
				$collCall = tt_flexicorp_search_disabled_ok_call($backend, 'coll', $searchDisabledMessage);
			} else {
				$collCall = tt_flexicorp_error_call($backend, 'coll', $searchDisabledMessage);
			}
		} elseif ( $selectionBlockMessage !== '' ) {
			$collCall = tt_flexicorp_error_call($backend, 'coll', $selectionBlockMessage);
		} else {
			$collCall = tt_flexicorp_run_coll(
				$backend,
				$projectRoot,
				$backendOverrideArgs,
				$queryEngine,
				$queryLanguage,
				$corpusFormat
			);
		}
	}

	$reindexCall = null;
	// The corpus's entry in corpus lists (FQS): indexing registers it, or brings it up to
	// date (title, URL, description, languages), and needs a short description — from
	// Pages/description.html, or given with the reindex request (corpus_description).
	// <flexicorp require_description="0"/> lets indexing go ahead without one.
	if ( is_file( __DIR__ . '/fqs-lib.php' ) ) require_once __DIR__ . '/fqs-lib.php';
	$listingDescription = function_exists( 'tt_fqs_project_description' ) ? tt_fqs_project_description( $projectRoot ) : '';
	$listingDescriptionRequired = function_exists( 'tt_fqs_project_description' )
		&& (string) getset( 'flexicorp/require_description', '1' ) !== '0';
	if ( $run === 'reindex' && $isAdmin ) {
		$descIn = isset( $_REQUEST['corpus_description'] ) && is_string( $_REQUEST['corpus_description'] ) ? trim( $_REQUEST['corpus_description'] ) : '';
		if ( $descIn !== '' && $listingDescription === '' && function_exists( 'tt_fqs_save_project_description' ) ) {
			if ( tt_fqs_save_project_description( $projectRoot, $descIn ) ) $listingDescription = $descIn;
		}
	}
	if ( $run === 'reindex' && $listingDescriptionRequired && $listingDescription === '' ) {
		$reindexCall = tt_flexicorp_error_call( $backend, 'reindex',
			'Before indexing, give the corpus a short description: it is what the corpus list shows about it. '
			. 'Write it when asked, or as the page "description" (index.php?action=pageedit&id=description).' );
	} elseif ( $run === 'reindex' ) {
		$reindexWantsManatee = ( $reindexBackend === 'manatee' );
		if ( ! $reindexWantsManatee ) {
			$rb = $_REQUEST['reindex_backends'] ?? null;
			if ( is_array( $rb ) ) {
				foreach ( $rb as $x ) {
					if ( tt_flexicorp_backend_canonical( trim( (string) $x ) ) === 'manatee' ) {
						$reindexWantsManatee = true;
						break;
					}
				}
			} elseif ( is_string( $rb ) && stripos( $rb, 'manatee' ) !== false ) {
				$reindexWantsManatee = true;
			}
		}
		if ( $reindexWantsManatee && empty( $manateeProjectStatus['available'] ) ) {
			$rj = isset( $manateeProjectStatus['reason'] ) ? trim( (string) $manateeProjectStatus['reason'] ) : '';
			$reindexCall = tt_flexicorp_error_call(
				$backend,
				'reindex',
				$rj !== '' ? $rj : tt_flexicorp_manatee_public_bindings_short_reason()
			);
		} elseif ( $selectionBlockMessage !== '' ) {
			$reindexCall = tt_flexicorp_error_call($backend, 'reindex', $selectionBlockMessage);
		} else {
		// Ensure tmp/cqpsettings.xml exists and reflects merged shared+local
		// TEITOK CQP settings before any reindexing that depends on it
		// (CQP, BlackLab, and other tools using tt-cwb-encode/flexipipe).
		if ( function_exists('easycorp_write_cqpsettings') ) {
			easycorp_write_cqpsettings($projectRoot);
		} else {
			// TEITOK: refresh the merged snapshot (it is otherwise only rewritten by TEITOK's own CQP regeneration)
			$cqpSettingsRefresh = tt_flexicorp_write_cqpsettings($projectRoot);
			if ( empty($cqpSettingsRefresh['ok']) ) {
				error_log('[flexicorp] ' . $cqpSettingsRefresh['message']);
			}
		}

		$reindexExtraArgs = array('--verbose', '--background', '--staging');
		$reindexBackendsForFqs = array();
		$reindexBackendsRaw = $_REQUEST['reindex_backends'] ?? null;
		if ( is_array($reindexBackendsRaw) && count($reindexBackendsRaw) > 0 ) {
			$reindexExtraArgs[] = '--reindex-backends';
			$reindexExtraArgs[] = implode(',', array_map('trim', $reindexBackendsRaw));
			foreach ( $reindexBackendsRaw as $rbx ) {
				$cb = tt_flexicorp_backend_canonical(trim((string)$rbx));
				if ( $cb !== '' && !in_array($cb, $reindexBackendsForFqs, true) ) $reindexBackendsForFqs[] = $cb;
			}
		} elseif ( is_string($reindexBackendsRaw) && trim($reindexBackendsRaw) !== '' ) {
			$reindexExtraArgs[] = '--reindex-backends';
			$reindexExtraArgs[] = trim($reindexBackendsRaw);
			foreach ( explode(',', trim($reindexBackendsRaw)) as $rbx ) {
				$cb = tt_flexicorp_backend_canonical(trim((string)$rbx));
				if ( $cb !== '' && !in_array($cb, $reindexBackendsForFqs, true) ) $reindexBackendsForFqs[] = $cb;
			}
		}
		if ( count($reindexBackendsForFqs) === 0 ) {
			$cb = tt_flexicorp_backend_canonical((string)$backend);
			if ( $cb !== '' ) $reindexBackendsForFqs[] = $cb;
		}

		$fqsReindexEligible = (
			!empty($fqsProbe['http_running'])
			&& !empty($fqsProbe['http_url'])
			&& !empty($fqsProbe['cli_installed'])
		);
		if ( $fqsReindexEligible ) {
			// register the corpus in FQS, or update its entry, before its reindex is queued
			if ( function_exists( 'tt_fqs_register_project' ) ) {
				$reg = tt_fqs_register_project( $projectRoot );
				tt_flexicorp_reindex_log_append( $projectRoot, $reg['ok'] ? 'fqs_register_ok id=' . $reg['id'] : 'fqs_register_failed error=' . $reg['error'] );
			}
			$fqsEnq = tt_flexicorp_fqs_enqueue_reindex($fqsProbe, $reindexBackendsForFqs, $projectRoot, 'teitok-flexicorp-ui');
			if ( !empty($fqsEnq['ok']) && trim((string)($fqsEnq['job_id'] ?? '')) !== '' ) {
				$fqsJobId = trim((string)$fqsEnq['job_id']);
				$fqsResult = array(
					'status' => 'enqueued',
					'message' => '',
					'source' => 'fqs',
					'fqs_job_id' => $fqsJobId,
					'reindex_backends' => $reindexBackendsForFqs,
					'progress' => array(
						'phase' => 'queued',
						'message' => 'Queued in FQS; poll reindex status for transitions to running/completed/failed.',
					),
					'log_hint' => rtrim((string)$projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'flexicorp_reindex.log',
					'fqs_log_hint' => '/usr/local/var/log/fqs/fqs.log',
					'indexer' => array(
						'enqueued' => true,
						'job_id' => $fqsJobId,
						'kind' => 'fqs',
						'source' => 'fqs',
					),
				);
				$raw = json_encode(array('ok' => true, 'result' => $fqsResult), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if ( !is_string($raw) ) $raw = '';
				$reindexCall = array(
					'ok' => true,
					'command' => 'POST ' . rtrim((string)($fqsProbe['http_url'] ?? ''), '/') . '/reindex/jobs',
					'raw' => $raw,
					'data' => array(
						'success' => true,
						'done' => array(
							'backend' => 'fqs',
							'operation' => 'reindex',
							'errors' => array(),
							'warnings' => array(),
							'result' => $fqsResult,
						),
					),
					'error' => '',
				);
				tt_flexicorp_reindex_log_append($projectRoot, 'fqs_enqueue_ok job=' . $fqsJobId);
			} else {
				$errTxt = trim((string)($fqsEnq['error'] ?? 'unknown enqueue error'));
				tt_flexicorp_reindex_log_append($projectRoot, 'fqs_enqueue_failed error=' . $errTxt);
				$reindexCall = tt_flexicorp_error_call('fqs', 'reindex', 'FQS enqueue failed: ' . $errTxt);
			}
		} else {
			$skipReason = trim((string)($fqsProbe['reason'] ?? 'FQS not ready'));
			$skipUrl = rtrim((string)($fqsProbe['http_url'] ?? ''), '/');
			$skipHttp = (int)($fqsProbe['http_status'] ?? 0);
			tt_flexicorp_reindex_log_append(
				$projectRoot,
				'skip_fqs reason=' . ($skipReason !== '' ? $skipReason : 'unknown')
				. ' url=' . ($skipUrl !== '' ? $skipUrl : '(none)')
				. ' http_status=' . (string)$skipHttp
			);
			$reindexCall = tt_flexicorp_run(
				array('reindex'),
				$backend,
				$projectRoot,
				array_merge($backendOverrideArgs, $reindexExtraArgs),
				$queryEngine,
				$queryLanguage,
				$corpusFormat
			);
		}
		}
	}

	$listDone = tt_flexicorp_done($listCall);
	$documents = ( isset($listDone['result']['docs']) && is_array($listDone['result']['docs']) ) ? array_values($listDone['result']['docs']) : array();
	$documentsTotal = isset($listDone['result']['total']) ? intval($listDone['result']['total']) : count($documents);
	$kwicDone = tt_flexicorp_done($kwicCall);
	$promoteQueryFreqToStats = false;
	// JS Stats subtab hint (freq | coll | other). Sourced from the flexicorp
	// result envelope's "operation" field — flexicorp normalizes this across query
	// engines, so the UI does not parse the query.
	$statsSubTabHint = '';
	// Routing slot for any kwic-aggregation payload: 'freq' | 'coll' | 'other' | ''.
	// Drives which state subtree (frequency / collocation / other) gets hydrated below.
	$opSlot = '';
	if (
		$kwicCall
		&& ( $run === 'query' || $run === 'kwic' )
		&& $kwicQuery !== ''
	) {
		$errs = function_exists( 'tt_flexicorp_done_errors_non_empty' )
			? tt_flexicorp_done_errors_non_empty( $kwicDone )
			: ( isset( $kwicDone['errors'] ) && is_array( $kwicDone['errors'] ) ? $kwicDone['errors'] : array() );
		if ( ! count( $errs ) ) {
			// Mutate done.result in place. A previous version copied result into $kr and assigned it back;
			// an empty/stale copy could overwrite the real payload → "0 hits" and empty Stats (bad → worse).
			if ( isset( $kwicCall['data']['done']['result'] ) && is_array( $kwicCall['data']['done']['result'] ) ) {
				$kr = &$kwicCall['data']['done']['result'];
			} else {
				$kr = isset( $kwicDone['result'] ) && is_array( $kwicDone['result'] ) ? $kwicDone['result'] : array();
			}
			// Same unwrapping as FQS path: { operation: "freq", result: { rows, … } } → flat result for UI + Stats.
			// Read the canonical operation flexicorp returns (allow-listed below).
			$opName = strtolower( trim( (string) ( $kr['operation'] ?? '' ) ) );
			$opAggregationAllowed = array( 'freq', 'group', 'count', 'dist', 'coll', 'dcoll', 'keyness' );
			$opIsAggregation = in_array( $opName, $opAggregationAllowed, true );
			$opFreqOuter = ( $opName === 'freq' );
			if ( function_exists( 'tt_flexicorp_teitok_unwrap_pando_freq_envelope' ) ) {
				tt_flexicorp_teitok_unwrap_pando_freq_envelope( $kr );
			}
			if ( function_exists( 'tt_flexicorp_pando_normalize_multiquery_freq_result' ) ) {
				tt_flexicorp_pando_normalize_multiquery_freq_result( $kr );
			}
			// Some backend/engine wrappers only surface `operation` after the unwrap/normalization step.
			// Recompute so `$opName`, `$opIsAggregation`, and downstream routing use the correct tag.
			$opName = strtolower(
				trim(
					(string) (
						$kr['operation']
						?? ( ( isset($kr['result']) && is_array($kr['result']) ) ? ( $kr['result']['operation'] ?? '' ) : '' )
						?? ( ( isset($kwicCall['data']['done']) && is_array($kwicCall['data']['done']) ) ? ( $kwicCall['data']['done']['operation'] ?? '' ) : '' )
						?? ( ( isset($kwicCall['data']['done']['result']) && is_array($kwicCall['data']['done']['result']) ) ? ( $kwicCall['data']['done']['result']['operation'] ?? '' ) : '' )
						?? ''
					)
				)
			);
			$opIsAggregation = in_array( $opName, $opAggregationAllowed, true );
			$opFreqOuter = ( $opName === 'freq' );
			if ( isset( $kwicCall['data']['done']['result'] ) && is_array( $kwicCall['data']['done']['result'] ) ) {
				// $kr is a reference into kwicCall — already updated.
			} elseif ( count( $kr ) > 0 && isset( $kwicCall['data']['done'] ) && is_array( $kwicCall['data']['done'] ) ) {
				$kwicCall['data']['done']['result'] = $kr;
			}
			$rt = isset( $kr['result_type'] ) ? (string) $kr['result_type'] : '';
			$row0queries = false;
			if ( isset( $kr['rows'] ) && is_array( $kr['rows'] ) && count( $kr['rows'] ) > 0 && is_array( $kr['rows'][0] ?? null ) ) {
				$r0 = $kr['rows'][0];
				$row0queries = isset( $r0['queries'] ) && is_array( $r0['queries'] ) && count( $r0['queries'] ) > 0;
			}
			// Detect "non-concordance" / table-shaped results.
			// - Some backends provide `table.columns` without `table.rows` (e.g. empty result),
			//   so we must treat columns as sufficient to route away from Search rendering.
			$hasTable = $rt === 'table'
				|| ( isset( $kr['table']['rows'] ) && is_array( $kr['table']['rows'] ) && count( $kr['table']['rows'] ) > 0 )
				|| ( isset( $kr['table']['columns'] ) && is_array( $kr['table']['columns'] ) && count( $kr['table']['columns'] ) > 0 )
				|| ( isset( $kr['columns'] ) && is_array( $kr['columns'] ) && count( $kr['columns'] ) > 0 )
				|| ( isset( $kr['rows'] ) && is_array( $kr['rows'] ) && count( $kr['rows'] ) > 0 )
				|| ( isset( $kr['items'] ) && is_array( $kr['items'] ) && count( $kr['items'] ) > 0 )
				|| ( isset( $kr['compare_queries'] ) && is_array( $kr['compare_queries'] ) && count( $kr['compare_queries'] ) > 0 )
				|| $row0queries;
			// Regex fallback only — kept for backends that have not yet been normalized to
			// emit `operation`. Should be a no-op when flexicorp does its job.
			$clauseFreq = function_exists( 'tt_flexicorp_teitok_query_ends_with_freq_clause' )
				&& tt_flexicorp_teitok_query_ends_with_freq_clause( $kwicQuery );
			$clauseCount = (bool) preg_match(
				'/(?:^|;)\s*count(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i',
				(string) $kwicQuery
			);
			$clauseColl = (bool) preg_match('/(?:^|;)\s*coll\s+/i', (string) $kwicQuery);
			$clauseDist = (bool) preg_match('/(?:^|;)\s*dist\b/i', (string) $kwicQuery);
			$clauseDcoll = (bool) preg_match('/(?:^|;)\s*dcoll\b/i', (string) $kwicQuery);
			$clauseKeyness = (bool) preg_match('/(?:^|;)\s*keyness\b/i', (string) $kwicQuery);
			$multiQueryFreqDist = $row0queries
				|| ( isset( $kr['compare_queries'] ) && is_array( $kr['compare_queries'] ) && count( $kr['compare_queries'] ) > 0 );
			$explicitTable = strtolower( trim( $rt ) ) === 'table';
			// Promote when flexicorp says this is an aggregation/association op, OR (legacy
			// fallbacks) when query clauses indicate one even if result metadata is sparse.
			// `$opIsAggregation` is the authoritative path; clause fallbacks keep routing
			// stable until all backends always emit `operation`.
			if ( $opIsAggregation || $hasTable || $clauseCount || $clauseColl || $clauseDist || $clauseDcoll || $clauseKeyness ) {
				$promoteQueryFreqToStats = true;
				$activeTab = 'frequency';
				// Map flexicorp operation → JS Stats subtab. We have first-class panels for
				// freq, coll, dcoll and keyness. Everything else (count, dist) lands in the
				// generic "Other" subtab — same payload shape as a raw table, labelled with
				// the operation name. count/dist do NOT silently merge into Frequency: their
				// shape isn't always compatible, and quietly relabeling is the bug we hit
				// with `A=...; B=...; count by decade(text_year);` showing up as freq.
				// Operation-driven routing:
				// - `$opToStatsTab` decides which *Stats subtab* should be selected.
				// - `$opToOpSlot` decides which *state subtree* gets hydrated (freq/coll/other).
				$opToStatsTab = array(
					'freq'    => 'freq',
					'group'   => 'freq',
					'coll'    => 'coll',
					'dcoll'   => 'advanced_dcoll',
					// Keyness is rendered by the Contrast module, but still hydrated from `state.other`.
					'keyness' => 'contrast',
				);
				$opToOpSlot = array(
					'freq'    => 'freq',
					'group'   => 'freq',
					'coll'    => 'coll',
					'count'   => 'other',
					'dist'    => 'other',
				);
				$statsSubTabHint = $opIsAggregation && isset( $opToStatsTab[ $opName ] )
					? $opToStatsTab[ $opName ]
					: ( $opIsAggregation ? 'other'
					: (
						( $clauseCount || $clauseDist ) ? 'other'
						: ( $clauseKeyness ? 'contrast'
						: ( $clauseDcoll ? 'advanced_dcoll'
							: ( $clauseColl ? 'coll'
								: ( ( $multiQueryFreqDist || $clauseFreq ) ? 'freq' : ( $hasTable ? 'other' : 'freq' ) ) ) ) )
					) );
				if ( function_exists( 'tt_flexicorp_teitok_parse_freq_field_from_query' ) ) {
					$pf = tt_flexicorp_teitok_parse_freq_field_from_query( $kwicQuery );
					if ( $pf !== '' ) {
						$freqField = $pf;
					}
				}
				// $opSlot routes the kwic-aggregation payload into the right state subtree:
				//   'freq' → state.frequency, 'coll' → state.collocation, 'other' → state.other.
				// Empty for non-promoted (no kwic aggregation happened); we keep the legacy
				// freq fallback so backends that don't yet emit `operation` still light up
				// the Frequency panel rather than silently disappearing.
				$opSlot = '';
				if ( $opIsAggregation && isset( $opToOpSlot[ $opName ] ) ) {
					$opSlot = $opToOpSlot[ $opName ];
				} elseif ( $opIsAggregation && $opName === 'keyness' ) {
					// Dedicated module (`contrast`) reads directly from search response;
					// do not also hydrate `state.other`.
					$opSlot = '';
				} elseif ( $opIsAggregation && $opName === 'dcoll' ) {
					// Dedicated module (`advanced_dcoll`) reads directly from search response;
					// do not also hydrate `state.other`.
					$opSlot = '';
				} elseif ( $opIsAggregation ) {
					// Generic fallback: aggregation op without a first-class panel maps to Other.
					$opSlot = 'other';
				} elseif ( $clauseCount || $clauseDist ) {
					$opSlot = 'other';
				} elseif ( $clauseKeyness ) {
					$opSlot = '';
				} elseif ( $clauseDcoll ) {
					$opSlot = '';
				} elseif ( $clauseColl ) {
					$opSlot = 'coll';
				} elseif ( $multiQueryFreqDist || $clauseFreq ) {
					$opSlot = 'freq';
				} elseif ( $explicitTable ) {
					// Table-shaped aggregation payload without explicit op metadata:
					// safer fallback is "other" (do not silently coerce to Frequency).
					$opSlot = 'other';
				} elseif ( $hasTable ) {
					// Non-concordance / table-shaped payload without explicit `result_type`.
					// Route to the generic Stats "Other" panel.
					$opSlot = 'other';
				}
				// Only run the freq-specific metric augmentation when the payload is
				// actually a freq table — coll/count/dist/dcoll/keyness have different
				// row shapes and the augmenter would either no-op noisily or corrupt them.
				if (
					$opSlot === 'freq'
					&& isset( $kwicCall['data']['done']['result'] )
					&& is_array( $kwicCall['data']['done']['result'] )
					&& function_exists( 'tt_flexicorp_freq_result_augment_metrics' )
				) {
					tt_flexicorp_freq_result_augment_metrics( $kwicCall['data']['done']['result'], $projectRoot );
				}
				$kwicDone = tt_flexicorp_done( $kwicCall );
			}
		}
	}
	if ( $freqCall && isset( $freqCall['data']['done']['result'] ) && is_array( $freqCall['data']['done']['result'] ) ) {
		tt_flexicorp_freq_result_augment_metrics( $freqCall['data']['done']['result'], $projectRoot );
	}
	$freqDone = tt_flexicorp_done( $freqCall );
	$collDone = tt_flexicorp_done( $collCall );

	$backendStatus = array();
	$backendCombos = array();
	$externalEngines = array();
	if ( $loadOverview || !$isAjax ) {
		$localBackendStatus = array(
			'flexi' => array(
				'available' => true,
				'reason' => 'flexicorp Python CLI available via project virtualenv.',
			),
			'cqp' => tt_flexicorp_cqp_status_resolved($projectRoot),
			'manatee' => $manateeProjectStatus,
			'pando' => tt_flexicorp_pando_status($projectRoot),
			'pmltq' => tt_flexicorp_pmltq_status($projectRoot),
		);
		$backendStatus = $localBackendStatus;
		if ( isset($overviewResult['backendStatus']) && is_array($overviewResult['backendStatus']) ) {
			foreach ( $overviewResult['backendStatus'] as $bid => $st ) {
				if ( !is_array($st) ) continue;
				$canonBid = tt_flexicorp_backend_canonical((string)$bid);
				if ( $canonBid === '' ) continue;
				$backendStatus[$canonBid] = $st;
			}
			// Always prefer local detection for pando (python overview may not know this yet).
			$backendStatus['pando'] = $localBackendStatus['pando'];
			// Native Manatee: Python overview can report imports that TEITOK/PHP/runtime cannot use.
			$backendStatus['manatee'] = $localBackendStatus['manatee'];
		}

		$backendCombosAll = ( isset($overviewResult['backendCombos']) && is_array($overviewResult['backendCombos']) )
			? array_values(array_filter(array_map(function ($combo) {
				if ( !is_array($combo) ) return null;
				$rawBackend = trim((string)($combo['backend'] ?? ''));
				if ( $rawBackend === 'flexicorp-pando' ) return null;
				$combo['backend'] = tt_flexicorp_backend_canonical($rawBackend);
				return $combo;
			}, $overviewResult['backendCombos']), function ($combo) {
				return is_array($combo) && !empty($combo['backend']);
			}))
			: array();
		$localCombos = tt_flexicorp_backend_combinations( $projectRoot, $manateeProjectStatus, $isAdmin );
		$comboById = array();
		foreach ( $backendCombosAll as $combo ) {
			if ( !is_array($combo) || empty($combo['id']) ) continue;
			$comboById[(string)$combo['id']] = $combo;
		}
		foreach ( $localCombos as $combo ) {
			if ( !is_array($combo) || empty($combo['id']) ) continue;
			$cid = (string)$combo['id'];
			// Prefer local TEITOK detection for admins; always prefer local for native Manatee (Python overview can be wrong).
			if ( !isset($comboById[$cid]) || $isAdmin || $cid === 'manatee:manatee-cql:manatee' ) {
				$comboById[$cid] = $combo;
			}
		}
		$backendCombosAll = array_values($comboById);
		$publicComboIds = tt_flexicorp_public_combo_ids();
		if ( ! empty( $publicComboIds ) && ! $isAdmin ) {
			foreach ( $backendCombosAll as $combo ) {
				if ( in_array( $combo['id'], $publicComboIds, true ) ) {
					$backendCombos[] = $combo;
				}
			}
			if ( empty( $backendCombos ) ) {
				$backendCombos = $backendCombosAll;
			}
		} else {
			$backendCombos = $backendCombosAll;
		}
		$backendCombos = array_values(array_filter($backendCombos, function ($combo) {
			return isset($combo['backend']) && $combo['backend'] !== 'teitokxml';
		}));
		// Hide deprecated flexi / ClickHouse rows for visitors (admins keep the escape hatch).
		if ( ! tt_flexicorp_show_legacy_backends( $isAdmin ) ) {
			$primaryCombos = array_values( array_filter( $backendCombos, function ( $combo ) {
				$b = tt_flexicorp_backend_canonical( (string) ( $combo['backend'] ?? '' ) );
				return ! tt_flexicorp_is_legacy_backend( $b );
			} ) );
			if ( count( $primaryCombos ) > 0 ) {
				$backendCombos = $primaryCombos;
			}
		}
		// Stable UI order: primary engines before any remaining legacy rows.
		$comboRank = array(
			'pando' => 10,
			'cqp' => 20,
			'manatee' => 30,
			'blacklab' => 40,
			'pmltq' => 50,
			'flexi' => 80,
			'clickql' => 90,
			'clickhouse' => 91,
		);
		usort( $backendCombos, function ( $a, $b ) use ( $comboRank ) {
			$ba = tt_flexicorp_backend_canonical( (string) ( $a['backend'] ?? '' ) );
			$bb = tt_flexicorp_backend_canonical( (string) ( $b['backend'] ?? '' ) );
			$ra = isset( $comboRank[ $ba ] ) ? $comboRank[ $ba ] : 60;
			$rb = isset( $comboRank[ $bb ] ) ? $comboRank[ $bb ] : 60;
			if ( $ra !== $rb ) {
				return $ra - $rb;
			}
			return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
		} );
		// Startup lock: if FQS reports active reindex jobs, disable reindex actions
		// for affected engines so Engines tab reflects current queue/worker activity.
		$activeReindexBackends = ( isset($fqsActiveReindex['active_backends']) && is_array($fqsActiveReindex['active_backends']) )
			? $fqsActiveReindex['active_backends']
			: array();
		$activeReindexSet = array();
		foreach ( $activeReindexBackends as $arb ) {
			$cab = tt_flexicorp_backend_canonical(trim((string)$arb));
			if ( $cab !== '' ) $activeReindexSet[$cab] = true;
		}
		$hasAnyActiveReindex = ( !empty($fqsActiveReindex['ok']) && count($activeReindexSet) > 0 );
		if ( $hasAnyActiveReindex ) {
			foreach ( $backendCombos as $bci => $bc ) {
				if ( !is_array($bc) ) continue;
				$caps = isset($bc['capabilities']) && is_array($bc['capabilities']) ? $bc['capabilities'] : array();
				if ( empty($caps['reindex']) ) continue;
				$comboBackend = tt_flexicorp_backend_canonical((string)($bc['backend'] ?? ''));
				if ( $comboBackend === '' ) continue;
				$affected = isset($activeReindexSet[$comboBackend]) || isset($activeReindexSet['auto']);
				if ( !$affected && in_array($comboBackend, array('clickql', 'clickhouse'), true) ) {
					$affected = isset($activeReindexSet['clickql']) || isset($activeReindexSet['clickhouse']);
				}
				if ( !$affected ) continue;
				$backendCombos[$bci]['reindexAvailable'] = false;
				if ( !isset($backendCombos[$bci]['reindex']) || !is_array($backendCombos[$bci]['reindex']) ) {
					$backendCombos[$bci]['reindex'] = array();
				}
				$backendCombos[$bci]['reindex']['locked'] = true;
				$backendCombos[$bci]['reindex']['reason'] = 'Reindex currently queued/running in FQS for this backend.';
				$reason0 = trim((string)($backendCombos[$bci]['reason'] ?? ''));
				$lockMsg = 'Reindex currently queued/running in FQS.';
				if ( $reason0 === '' ) {
					$backendCombos[$bci]['reason'] = $lockMsg;
				} elseif ( stripos($reason0, $lockMsg) === false ) {
					$backendCombos[$bci]['reason'] = $reason0 . ' ' . $lockMsg;
				}
			}
		}
		// Apply the same ClickHouse/ClickQL normalization to the rendered Overview table.
		$clickStatusForOverviewCombos = tt_flexicorp_clickhouse_status( $projectRoot, $backendOverrideArgs );
		foreach ( $backendCombos as $bci => $bc ) {
			if ( !is_array($bc) ) continue;
			$cb = tt_flexicorp_backend_canonical((string)($bc['backend'] ?? ''));
			if ( $cb !== 'clickql' && $cb !== 'clickhouse' ) continue;
			$backendCombos[$bci]['available'] = !empty($clickStatusForOverviewCombos['available']);
			if ( isset($clickStatusForOverviewCombos['reason']) ) {
				$backendCombos[$bci]['reason'] = (string)$clickStatusForOverviewCombos['reason'];
			}
		}

		$externalEngines = tt_flexicorp_external_engines($projectRoot);
	}

	// Derive human-friendly metadata labels for document-level attributes using TEITOK's pattname
	// when available (e.g. text_code → "Original language code").
	$docMetaLabels = array();
	if ( $loadDocuments && is_array($documents) && function_exists('pattname') ) {
		foreach ( $documents as $doc ) {
			if ( !is_array($doc) || empty($doc['meta']) || !is_array($doc['meta']) ) continue;
			foreach ( $doc['meta'] as $key => $_value ) {
				if ( $key === 'id' || $key === 'title' ) continue;
				if ( isset($docMetaLabels[$key]) ) continue;
				$attrName = 'text_' . $key;
				$label = pattname($attrName);
				$docMetaLabels[$key] = $label ? (string)$label : (string)$key;
			}
		}
	}

	$searchResponsePayload = tt_flexicorp_search_response_merged($kwicCall, $kwicDone, $queryTimeMs);
	if ( $kwicCall && ( $run === 'query' || $run === 'kwic' ) ) {
		$searchResponsePayload = tt_flexicorp_search_augment_ipm( $searchResponsePayload, $projectRoot, $kwicDone );
	}
	if ( $isAjax && $activeTab !== 'debug' ) {
		$searchResponsePayload = tt_flexicorp_compact_search_state_payload($searchResponsePayload);
	}
	// Ensure total/returned always reach the client when backend provided them (for "X of Y hit(s)").
	if ( isset($searchResponsePayload['result']) && is_array($searchResponsePayload['result']) && isset($kwicDone['result']) && is_array($kwicDone['result']) ) {
		$res = &$searchResponsePayload['result'];
		$dr = $kwicDone['result'];
		$searchOp = '';
		if ( isset($dr['operation']) && is_string($dr['operation']) ) {
			$searchOp = strtolower(trim((string)$dr['operation']));
		}
		if ( $searchOp === '' && isset($kwicDone['operation']) && is_string($kwicDone['operation']) ) {
			$searchOp = strtolower(trim((string)$kwicDone['operation']));
		}
		if ( $searchOp === '' && isset($kwicCall['data']['done']['operation']) && is_string($kwicCall['data']['done']['operation']) ) {
			$searchOp = strtolower(trim((string)$kwicCall['data']['done']['operation']));
		}
		if ( $searchOp === '' && isset($kwicCall['data']['done']['result']['operation']) && is_string($kwicCall['data']['done']['result']['operation']) ) {
			$searchOp = strtolower(trim((string)$kwicCall['data']['done']['result']['operation']));
		}
		if ( $searchOp !== '' ) {
			$res['operation'] = $searchOp;
		}
		if ( isset($dr['total']) && ( !isset($res['total']) || $res['total'] === '' || $res['total'] === null ) ) {
			$res['total'] = (int) $dr['total'];
		}
		if ( isset($dr['returned']) && ( !isset($res['returned']) || $res['returned'] === '' || $res['returned'] === null ) ) {
			$res['returned'] = (int) $dr['returned'];
		}
		if ( ( !isset($res['returned']) || $res['returned'] === '' || $res['returned'] === null ) && isset($dr['hits']) && is_array($dr['hits']) ) {
			$res['returned'] = count($dr['hits']);
		}
	}

	$flexicorpDocsUrl = function_exists('getset') ? rtrim((string) getset('flexicorp/docs_url', ''), '/') : '';
	$backendDisplayNames = array();
	if ( function_exists('getset') ) {
		$bdnRaw = trim((string) getset('flexicorp/backend_display_names', ''));
		if ( $bdnRaw !== '' ) {
			$decoded = json_decode($bdnRaw, true);
			if ( is_array($decoded) ) {
				foreach ( $decoded as $k => $v ) {
					$kk = trim((string)$k);
					$vv = trim((string)$v);
					if ( $kk !== '' && $vv !== '' ) $backendDisplayNames[$kk] = $vv;
				}
			} else {
				$lines = preg_split('/\r\n|\r|\n/', $bdnRaw);
				if ( is_array($lines) ) {
					foreach ( $lines as $ln ) {
						$line = trim((string)$ln);
						if ( $line === '' || $line[0] === '#' ) continue;
						if ( preg_match('/^([A-Za-z0-9_.:-]+)\s*=\s*(.+)$/', $line, $m) ) {
							$kk = trim((string)$m[1]);
							$vv = trim((string)$m[2]);
							if ( $kk !== '' && $vv !== '' ) $backendDisplayNames[$kk] = $vv;
						}
					}
				}
			}
		}
	}
	$searchIntroHtml = tt_flexicorp_resolve_search_intro_html();
	$searchIntroSplit = tt_flexicorp_split_search_intro_lead_body( $searchIntroHtml );
	$searchIntroLeadHtml = $searchIntroSplit['lead'];
	$searchIntroBodyHtml = $searchIntroSplit['body'];
	$exampleRaw = function_exists('getset') ? (string) getset('flexicorp/search_example_queries', '') : '';
	$exampleMap = tt_flexicorp_parse_search_example_queries_map($exampleRaw);
	$searchExampleQueries = tt_flexicorp_resolve_search_example_queries_for_language($exampleMap, $queryLanguage);
	$queryBuilderEnabledRaw = function_exists('getset') ? getset('flexicorp/query_builder_enabled', '1') : '1';
	$queryBuilderEnabledTxt = strtolower(trim((string)$queryBuilderEnabledRaw));
	$queryBuilderEnabled = !in_array($queryBuilderEnabledTxt, array('0', 'false', 'no', 'off', ''), true);
	$qbDependencyRelations = array();
	$qbDepRaw = function_exists('getset') ? getset('flexicorp/query_builder_dependency_relations', '') : '';
	if ( is_array($qbDepRaw) ) {
		foreach ( $qbDepRaw as $v ) {
			$vv = trim((string)$v);
			if ( $vv !== '' ) $qbDependencyRelations[] = $vv;
		}
	} else {
		$depTxt = trim((string)$qbDepRaw);
		if ( $depTxt !== '' ) {
			$decoded = json_decode($depTxt, true);
			if ( is_array($decoded) ) {
				foreach ( $decoded as $v ) {
					$vv = trim((string)$v);
					if ( $vv !== '' ) $qbDependencyRelations[] = $vv;
				}
			} else {
				$parts = preg_split('/[\s,;]+/', $depTxt);
				if ( is_array($parts) ) {
					foreach ( $parts as $v ) {
						$vv = trim((string)$v);
						if ( $vv !== '' ) $qbDependencyRelations[] = $vv;
					}
				}
			}
		}
	}
	if ( !count($qbDependencyRelations) && function_exists('tt_flexicorp_fn_attribute_catalog') ) {
		$catTmp = tt_flexicorp_fn_attribute_catalog( $isAdmin );
		if (
			is_array($catTmp)
			&& isset($catTmp['field_details'])
			&& is_array($catTmp['field_details'])
			&& isset($catTmp['field_details']['deprel'])
			&& is_array($catTmp['field_details']['deprel'])
			&& isset($catTmp['field_details']['deprel']['options'])
			&& is_array($catTmp['field_details']['deprel']['options'])
		) {
			foreach ( $catTmp['field_details']['deprel']['options'] as $opt ) {
				if ( !is_array($opt) ) continue;
				$vv = trim((string)($opt['value'] ?? ''));
				if ( $vv !== '' ) $qbDependencyRelations[] = $vv;
			}
		}
	}
	$qbDependencyRelations = array_values(array_unique(array_filter(array_map('strval', $qbDependencyRelations))));
	if ( !count($qbDependencyRelations) ) $qbDependencyRelations = array('child');
	$queryBuilderConfig = array(
		'enabled' => $queryBuilderEnabled,
		'operators' => array('matches', 'contains', 'startswith', 'endsin'),
		'dependencyRelations' => $qbDependencyRelations,
		'sharedBase' => 'cqlCore',
		// NOTE: Explicit allowlist/denylist by query_language.
		// Unknown dialects are treated as QB-disabled on the client.
		// When adding more advanced QB features, validate support per CQL dialect
		// (CWB/Manatee/Pando/ClickCQL/BCQL can diverge in edge syntax/features).
		'qlPacks' => array(
			'cwb-cql' => array('family' => 'cqlCore', 'enabled' => true),
			'pando-cql' => array('family' => 'cqlCore', 'enabled' => true),
			'manatee-cql' => array('family' => 'cqlCore', 'enabled' => true),
			'clickcql' => array('family' => 'cqlCore', 'enabled' => true),
			'bcql' => array('family' => 'cqlCore', 'enabled' => true),
			'blacklab' => array('family' => 'cqlCore', 'enabled' => true),
			'tiger' => array('family' => 'tiger', 'enabled' => false),
			'sql' => array('family' => 'sql', 'enabled' => false),
			'pmltq' => array('family' => 'pmltq', 'enabled' => false),
			'clickpmltq' => array('family' => 'pmltq', 'enabled' => false),
		),
	);
	$recentQueriesByDialect = tt_flexicorp_recent_queries_by_dialect_map();
	$storedQueriesByDialect = function_exists('tt_flexicorp_stored_queries_by_dialect_map')
		? tt_flexicorp_stored_queries_by_dialect_map($projectRoot)
		: array();
	$recentQueriesForState = ( isset($recentQueriesByDialect[$queryLanguage]) && is_array($recentQueriesByDialect[$queryLanguage]) )
		? array_values($recentQueriesByDialect[$queryLanguage])
		: array();
	// Full HTML page load (not an AJAX round-trip): always open Search so the workflow starts from a query,
	// regardless of URL active_tab or promote-to-Stats. AJAX responses keep the tab chosen for that action.
	if ( $isInitialShellRequest && $run === '' ) {
		$activeTab = 'search';
	}
	$state = array(
		'action' => $actionName,
		'projectRoot' => $projectRoot,
		'isAdmin' => $isAdmin,
		// the corpus's entry in corpus lists: indexing asks for a description when there is none
		'corpusListing' => $isAdmin ? array(
			'description' => $listingDescription,
			'descriptionRequired' => $listingDescriptionRequired,
			'editUrl' => 'index.php?action=fqsadmin',
		) : null,
		'noshowFields' => function_exists('tt_flexicorp_noshow_fields') ? tt_flexicorp_noshow_fields() : array(),
		'attributeCatalog' => function_exists('tt_flexicorp_fn_attribute_catalog') ? tt_flexicorp_fn_attribute_catalog( $isAdmin ) : array(),
		'debugMode' => $debugMode,
		'backendOverrides' => $backendOverrides,
		'flexicorpDocsUrl' => $flexicorpDocsUrl,
		'activeTab' => $activeTab,
		// Client must not treat corpus-info probes as tab navigation (probe posts active_tab=frequency).
		'corpusInfoProbe' => $corpusInfoProbe ? true : false,
		'statsSubTabHint' => $statsSubTabHint,
		'settings' => array(
			'backend' => $backend,
			'queryEngine' => $queryEngine,
			'queryLanguage' => $queryLanguage,
			'corpusFormat' => $corpusFormat,
			'groupHitsBySentence' => $groupHitsBySentence ? true : false,
			'docsLimit' => $docsLimit,
			'kwicLimit' => $kwicLimit,
			'kwicWindow' => $kwicWindow,
			'freqLimit' => $freqLimit,
			'contextScopeRegionAllowlist' => array_values( tt_flexicorp_context_scope_region_allowlist_resolved( $projectRoot ) ),
		),
		'loadedSections' => array(
			'overview' => $loadOverview ? true : false,
			'documents' => $loadDocuments ? true : false,
			'search' => $kwicCall ? true : false,
			'frequency' => ( $freqCall || $promoteQueryFreqToStats || $collCall || $kwicCall ) ? true : false,
		),
		'documentsUi' => array(
			'filter' => $docsFilter,
			'perPage' => $docsPerPage,
			'visibleCount' => $docsPerPage,
		),
		'search' => array(
			'ran' => $kwicCall ? true : false,
			'query' => $kwicQuery,
			'field' => $kwicField,
			'value' => $kwicValue,
			'contextFormat' => $contextFormat,
			'contextScope' => $contextScope,
			'viewMode' => $viewMode,
			'window' => $kwicWindow,
			'limit' => $kwicLimit,
			'start' => $kwicStart,
			'response' => $searchResponsePayload,
			'hits' => ( isset($kwicDone['result']['hits']) && is_array($kwicDone['result']['hits']) ) ? array_values($kwicDone['result']['hits']) : array(),
			'resultType' => isset($kwicDone['result']['result_type']) && trim((string)$kwicDone['result']['result_type']) !== ''
				? (string)$kwicDone['result']['result_type']
				: (
					( !empty($kwicDone['result']['rows']) && is_array($kwicDone['result']['rows']) )
					|| ( !empty($kwicDone['result']['compare_queries']) && is_array($kwicDone['result']['compare_queries']) )
						? 'table'
						: 'hits'
				),
			'tableColumns' => ( isset($kwicDone['result']['table']['columns']) && is_array($kwicDone['result']['table']['columns']) ) ? array_values($kwicDone['result']['table']['columns']) : array(),
			'tableRows' => ( isset($kwicDone['result']['table']['rows']) && is_array($kwicDone['result']['table']['rows']) ) && count($kwicDone['result']['table']['rows']) > 0
				? array_slice(array_values($kwicDone['result']['table']['rows']), 0, $kwicLimit)
				: ( ( isset($kwicDone['result']['rows']) && is_array($kwicDone['result']['rows']) ) ? array_slice(array_values($kwicDone['result']['rows']), 0, $kwicLimit) : array() ),
		),
		// State subtree routing (gated on $opSlot from the promote block above):
		//   $opSlot === 'freq'  → kwic-aggregation payload feeds state.frequency
		//   $opSlot === 'coll'  → kwic-aggregation payload feeds state.collocation
		//   $opSlot === 'other' → kwic-aggregation payload feeds state.other (raw table)
		// Plus the legacy paths: an explicit Frequency form post still hydrates state.frequency
		// from $freqDone, an explicit Collocation form post still hydrates state.collocation
		// from $collDone. count/dist no longer silently render in the freq panel.
		'frequency' => array(
			'ran' => ( $freqCall || ( $promoteQueryFreqToStats && $opSlot === 'freq' ) ) ? true : false,
			'operation' => ( $promoteQueryFreqToStats && $opSlot === 'freq' ) ? $opName : '',
			// Only surface a pre-selected field/checkbox state when there is an
			// actual selection: a frequency run, a query promoted to freq stats,
			// or the caller explicitly supplied freq_field. Otherwise leave the
			// Stats > Frequency form blank so the first checkbox is not auto-ticked.
			'field' => ( $freqCall || ( $promoteQueryFreqToStats && $opSlot === 'freq' ) || $freqFieldExplicit )
				? $freqField
				: '',
			'formFields' => ( $freqCall || ( $promoteQueryFreqToStats && $opSlot === 'freq' ) || $freqFieldExplicit )
				? array_values( array_filter( array_map('trim', explode(',', $freqField)), 'strlen' ) )
				: array(),
			'fields' => (
				( $freqCall || ( $promoteQueryFreqToStats && $opSlot === 'freq' ) || $freqFieldExplicit )
					? (
						( $promoteQueryFreqToStats && $opSlot === 'freq' )
							? ( isset($kwicDone['result']['fields']) && is_array($kwicDone['result']['fields']) ? array_values($kwicDone['result']['fields']) : array_values( array_filter( array_map('trim', explode(',', $freqField)), 'strlen' ) ) )
							: ( isset($freqDone['result']['fields']) && is_array($freqDone['result']['fields']) ? array_values($freqDone['result']['fields']) : array_values( array_filter( array_map('trim', explode(',', $freqField)), 'strlen' ) ) )
					)
					: array()
			),
			'fieldLabels' => function_exists('tt_flexicorp_freq_field_label_map_from_settings') ? tt_flexicorp_freq_field_label_map_from_settings() : array(),
			'limit' => $freqLimit,
			'total' => ( $promoteQueryFreqToStats && $opSlot === 'freq' )
				? tt_flexicorp_freq_total( $kwicDone['result'] ?? null )
				: tt_flexicorp_freq_total( $freqDone['result'] ?? null ),
			'returned' => ( $promoteQueryFreqToStats && $opSlot === 'freq' )
				? tt_flexicorp_freq_returned( $kwicDone['result'] ?? null )
				: tt_flexicorp_freq_returned( $freqDone['result'] ?? null ),
			'response' => ( $promoteQueryFreqToStats && $opSlot === 'freq' )
				? (
					( $isAjax && $activeTab !== 'debug' && function_exists('tt_flexicorp_compact_response_payload') )
						? tt_flexicorp_compact_response_payload( tt_flexicorp_response_payload( $kwicCall ) )
						: tt_flexicorp_response_payload( $kwicCall )
				)
				: (
					( $isAjax && $activeTab !== 'debug' && function_exists('tt_flexicorp_compact_response_payload') )
						? tt_flexicorp_compact_response_payload( tt_flexicorp_response_payload( $freqCall ) )
						: tt_flexicorp_response_payload( $freqCall )
				),
			'rows' => ( $promoteQueryFreqToStats && $opSlot === 'freq' )
				? array_slice( tt_flexicorp_freq_rows( $kwicDone['result'] ?? null ), 0, $freqLimit )
				: array_slice( tt_flexicorp_freq_rows( $freqDone['result'] ?? null ), 0, $freqLimit ),
		),
		'collocation' => array(
			'ran' => ( $collCall || ( $promoteQueryFreqToStats && $opSlot === 'coll' ) ) ? true : false,
			'operation' => ( $promoteQueryFreqToStats && $opSlot === 'coll' ) ? $opName : '',
			'field' => $collField,
			'anchorToken' => $collAnchorToken,
			'left' => $collLeft,
			'right' => $collRight,
			'minFreq' => $collMinFreq,
			'maxItems' => $collMaxItems,
			'stoplist' => $collStoplist,
			'measureKeys' => $collMeasureList,
			'matches' => function_exists( 'tt_flexicorp_coll_matches_total' )
				? tt_flexicorp_coll_matches_total(
					( $promoteQueryFreqToStats && $opSlot === 'coll' )
						? ( $kwicDone['result'] ?? null )
						: ( $collDone['result'] ?? null )
				)
				: ( isset( $collDone['result']['matches'] ) ? intval( $collDone['result']['matches'] ) : null ),
			'response' => ( $promoteQueryFreqToStats && $opSlot === 'coll' )
				? (
					( $isAjax && $activeTab !== 'debug' && function_exists('tt_flexicorp_compact_response_payload') )
						? tt_flexicorp_compact_response_payload( tt_flexicorp_response_payload( $kwicCall ) )
						: tt_flexicorp_response_payload( $kwicCall )
				)
				: (
					( $isAjax && $activeTab !== 'debug' && function_exists('tt_flexicorp_compact_response_payload') )
						? tt_flexicorp_compact_response_payload( tt_flexicorp_response_payload( $collCall ) )
						: tt_flexicorp_response_payload( $collCall )
				),
			'rows' => function_exists( 'tt_flexicorp_coll_rows' )
				? tt_flexicorp_coll_rows(
					( $promoteQueryFreqToStats && $opSlot === 'coll' )
						? ( $kwicDone['result'] ?? null )
						: ( $collDone['result'] ?? null )
				)
				: array(),
		),
		// "Other" subtab — generic raw-table view for aggregation operations we don't
		// have first-class panels for yet (count, dist, dcoll, keyness). Filled directly
		// from the kwic-aggregation payload; the JS labels the panel with `operation`.
		'other' => array(
			'ran' => ( $promoteQueryFreqToStats && $opSlot === 'other' ) ? true : false,
			'operation' => ( $opSlot === 'other' ) ? $opName : '',
			'columns' => ( $promoteQueryFreqToStats && $opSlot === 'other' )
				? (
					( isset($kwicDone['result']['columns']) && is_array($kwicDone['result']['columns']) )
						? array_values($kwicDone['result']['columns'])
						: ( ( isset($kwicDone['result']['table']['columns']) && is_array($kwicDone['result']['table']['columns']) )
							? array_values($kwicDone['result']['table']['columns'])
							: array() )
				)
				: array(),
			'rows' => ( $promoteQueryFreqToStats && $opSlot === 'other' )
				? (
					( isset($kwicDone['result']['rows']) && is_array($kwicDone['result']['rows']) )
						? array_values($kwicDone['result']['rows'])
						: ( ( isset($kwicDone['result']['table']['rows']) && is_array($kwicDone['result']['table']['rows']) )
							? array_values($kwicDone['result']['table']['rows'])
							: array() )
				)
				: array(),
			'total' => ( $promoteQueryFreqToStats && $opSlot === 'other' )
				? ( isset($kwicDone['result']['total']) ? intval($kwicDone['result']['total']) : null )
				: null,
			'response' => ( $promoteQueryFreqToStats && $opSlot === 'other' )
				? (
					( $isAjax && $activeTab !== 'debug' && function_exists('tt_flexicorp_compact_response_payload') )
						? tt_flexicorp_compact_response_payload( tt_flexicorp_response_payload( $kwicCall ) )
						: tt_flexicorp_response_payload( $kwicCall )
				)
				: null,
		),
		'responses' => ( $isAjax && $activeTab !== 'debug' ) ? array() : array(
			'search' => tt_flexicorp_response_payload($kwicCall),
			'frequency' => tt_flexicorp_response_payload($freqCall),
			'collocation' => tt_flexicorp_response_payload($collCall),
		),
		'debugEntries' => ( $isAjax && $activeTab !== 'debug' && !$debugMode ) ? array() : ( ( isset($tt_flexicorp_debug_calls) && is_array($tt_flexicorp_debug_calls) ) ? array_values($tt_flexicorp_debug_calls) : array() ),
		'debugAppend' => ( isset($GLOBALS['tt_flexicorp_debug_append']) && is_array($GLOBALS['tt_flexicorp_debug_append']) ) ? array_values($GLOBALS['tt_flexicorp_debug_append']) : array(),
		'recentQueries' => $recentQueriesForState,
		'recentQueriesByDialect' => $recentQueriesByDialect,
		'storedQueriesByDialect' => $storedQueriesByDialect,
		'searchIntroHtml' => $searchIntroBodyHtml,
		'searchIntroLeadHtml' => $searchIntroLeadHtml,
		'searchExampleQueries' => $searchExampleQueries,
		'queryBuilder' => $queryBuilderConfig,
		'backendDisplayNames' => $backendDisplayNames,
		'fqs' => $fqsProbe,
		'fqsActiveReindex' => $fqsActiveReindex,
	);
	$ajaxResultOnly = (
		$isAjax
		&& $activeTab !== 'debug'
		&& in_array($run, array('query', 'kwic', 'freq', 'coll'), true)
	);
	if ( $ajaxResultOnly ) {
		// Query/frequency/collocation round-trips should only return result-focused data.
		// Keep backend/env diagnostics for explicit overview/status requests.
		unset(
			$state['availableBackends'],
			$state['availableQueryEngines'],
			$state['backendStatus'],
			$state['backendCombos'],
			$state['externalEngines'],
			$state['status'],
			$state['info'],
			$state['reindex'],
			$state['listDocs'],
			$state['docMetaLabels'],
			$state['documents'],
			$state['documentsTotal'],
			$state['xidxRegionTypes'],
			$state['bootstrapDiag'],
			$state['fqs'],
			$state['adminPandoIndexHint'],
			$state['pandoRuntime']
		);
		if ( isset($state['loadedSections']) && is_array($state['loadedSections']) ) {
			$state['loadedSections']['overview'] = false;
			$state['loadedSections']['documents'] = false;
		}
		$state['debugAppend'] = array();
	}
	if ( isset($_SESSION) && is_array($_SESSION) ) {
		if ( !isset($_SESSION['flexicorp_teitok']) || !is_array($_SESSION['flexicorp_teitok']) ) {
			$_SESSION['flexicorp_teitok'] = array();
		}
		$_SESSION['flexicorp_teitok']['last_query_engine'] = $queryEngine;
		$_SESSION['flexicorp_teitok']['last_backend'] = $backend;
		$_SESSION['flexicorp_teitok']['last_query_language'] = $queryLanguage;
		$_SESSION['flexicorp_teitok']['last_corpus_format'] = $corpusFormat;
		$_SESSION['flexicorp_teitok']['last_active_tab'] = $activeTab;
		$_SESSION['flexicorp_teitok']['group_hits_by_sentence'] = $groupHitsBySentence ? 1 : 0;
		// For advanced_freqs / geomap-style tools: reuse last non-empty search scope without retyping.
		if ( isset( $kwicQuery ) && is_string( $kwicQuery ) && trim( $kwicQuery ) !== '' ) {
			$_SESSION['flexicorp_teitok']['last_search_query'] = $kwicQuery;
		}
	}
	if ( $backend === 'pando' || $debugMode ) {
		$state['pandoRuntime'] = tt_flexicorp_pando_runtime_info($projectRoot);
	}

	$state['adminPandoIndexHint'] = null;
	if ( $isAdmin ) {
		$hint = null;
		$cached = false;
		$ttl = 300;
		if ( isset( $_SESSION['flexicorp_teitok'] ) && is_array( $_SESSION['flexicorp_teitok'] ) ) {
			$cache = $_SESSION['flexicorp_teitok']['pando_freshness_hint_v1'] ?? null;
			if ( is_array( $cache ) && isset( $cache['t'] ) && ( time() - (int) $cache['t'] ) < $ttl ) {
				$hint = array_key_exists( 'hint', $cache ) ? $cache['hint'] : null;
				$cached = true;
			}
		}
		if ( ! $cached && function_exists( 'tt_flexicorp_pando_index_freshness_hint' ) ) {
			$hint = tt_flexicorp_pando_index_freshness_hint( $projectRoot );
			if ( isset( $_SESSION['flexicorp_teitok'] ) && is_array( $_SESSION['flexicorp_teitok'] ) ) {
				$_SESSION['flexicorp_teitok']['pando_freshness_hint_v1'] = array(
					't' => time(),
					'hint' => $hint,
				);
			}
		}
		$state['adminPandoIndexHint'] = $hint;
	}

	if ( $loadOverview || !$isAjax ) {
		$state['availableBackends'] = $availableBackends;
		$state['availableQueryEngines'] = $availableQueryEngines;
		$state['backendStatus'] = $backendStatus;
		$state['backendCombos'] = $backendCombos;
		$state['externalEngines'] = $externalEngines;
		$state['reindex'] = tt_flexicorp_response_payload($reindexCall);
		$state['responses']['backends'] = ( $isAjax && $activeTab !== 'debug' ) ? tt_flexicorp_compact_response_payload(tt_flexicorp_response_payload($overviewCall)) : tt_flexicorp_response_payload($overviewCall);
		$statusPayloadForState = tt_flexicorp_response_payload( $statusCall );
		$infoPayloadForState = tt_flexicorp_response_payload( $infoCall );
		$sanitizeManateeUi = ! $debugMode && $activeTab !== 'debug';
		if ( $sanitizeManateeUi ) {
			$statusPayloadForState = tt_flexicorp_sanitize_manatee_backend_payload_for_ui( $statusPayloadForState, false );
			$infoPayloadForState = tt_flexicorp_sanitize_manatee_backend_payload_for_ui( $infoPayloadForState, false );
		}
		$state['status'] = $statusPayloadForState;
		$state['info'] = $infoPayloadForState;
		$state['responses']['status'] = ( $isAjax && $activeTab !== 'debug' ) ? tt_flexicorp_compact_response_payload( $statusPayloadForState ) : $statusPayloadForState;
		$state['responses']['info'] = ( $isAjax && $activeTab !== 'debug' ) ? tt_flexicorp_compact_response_payload( $infoPayloadForState ) : $infoPayloadForState;
		$state['responses']['reindex'] = tt_flexicorp_response_payload($reindexCall);
	}

	if ( $loadDocuments || !$isAjax ) {
		$state['docMetaLabels'] = $docMetaLabels;
		$state['documents'] = $documents;
		$state['documentsTotal'] = $documentsTotal;
		$state['listDocs'] = tt_flexicorp_response_payload($listCall);
		$state['responses']['documents'] = ( $isAjax && $activeTab !== 'debug' ) ? tt_flexicorp_compact_response_payload(tt_flexicorp_response_payload($listCall)) : tt_flexicorp_response_payload($listCall);
	}

	$state['xidxRegionTypes'] = array();
	$xidxRtPath = rtrim((string)$projectRoot, '/') . '/xidx/region_types.tbl';
	if ( is_file($xidxRtPath) ) {
		$rtLines = @file($xidxRtPath, FILE_IGNORE_NEW_LINES);
		if ( is_array($rtLines) ) {
			foreach ( $rtLines as $rtLine ) {
				$rtLine = trim((string)$rtLine);
				if ( $rtLine !== '' ) {
					$state['xidxRegionTypes'][] = $rtLine;
				}
			}
		}
	}
	// Region types for context-scope hints: xidx index only (flexencoder region_types.tbl).
	// Corpus-level structure names come from the flexicorp backend /info (CQP / triple / etc.), not TEITOK xmlfile forms.
	$state['xidxRegionTypes'] = tt_flexicorp_filter_context_scope_regions( $state['xidxRegionTypes'], $projectRoot );

	global $foldername;
	$foldername_diag = '';
	if ( isset( $foldername ) && is_string( $foldername ) && trim( $foldername ) !== '' ) {
		$foldername_diag = trim( $foldername );
	} elseif ( function_exists( 'getset' ) ) {
		$foldername_diag = trim( (string) getset( 'defaults/base/foldername', '' ) );
	}
	$state['bootstrapDiag'] = array(
		'corpusProjectRoot' => $projectRoot,
		'phpGetcwd' => function_exists( 'getcwd' ) ? getcwd() : null,
		'teitokFoldername' => $foldername_diag,
		'backend' => $backend,
		'queryEngine' => $queryEngine,
		'requestBackend' => isset( $requestBackend ) ? (string) $requestBackend : '',
		'defaultBackend' => isset( $defaultBackend ) ? (string) $defaultBackend : '',
		'availableBackends' => $availableBackends,
		'availableQueryEngines' => $availableQueryEngines,
		'backendSelectionError' => $backendSelectionError,
		'backendSelectionErrorDetail' => isset( $backendSelectionErrorDetail ) ? (string) $backendSelectionErrorDetail : '',
		'selectionBlockMessage' => isset( $selectionBlockMessage ) ? (string) $selectionBlockMessage : '',
		'backendInAvailableList' => in_array( $backend, $availableBackends, true ),
		'manateeOkForSelection' => isset( $manateeOkForSelection ) ? (bool) $manateeOkForSelection : false,
		'manateeStatus' => is_array( $manateeProjectStatus ) ? array_merge(
			array(
				'available' => ! empty( $manateeProjectStatus['available'] ),
				'corpus_available' => ! empty( $manateeProjectStatus['corpus_available'] ),
				'reason' => isset( $manateeProjectStatus['reason'] ) ? (string) $manateeProjectStatus['reason'] : '',
			),
			( $debugMode && ! empty( $manateeProjectStatus['reason_detail'] ) )
				? array( 'reasonDetail' => (string) $manateeProjectStatus['reason_detail'] )
				: array()
		) : null,
		'overviewManatee' => array(
			'backendStatus' => isset( $overviewResult['backendStatus']['manatee'] ) ? $overviewResult['backendStatus']['manatee'] : null,
			'queryEngine' => isset( $overviewResult['queryEngines']['manatee'] ) ? $overviewResult['queryEngines']['manatee'] : null,
		),
		'kwicRanServerSide' => (bool) $kwicCall,
		'fqsActiveReindex' => $fqsActiveReindex,
		'hint' => 'Open DevTools Console: warnings when backendSelectionError is set; with ?debug=1 also console.info bootstrapDiag.',
	);

	$stateJson = tt_flexicorp_json_encode_safe($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	if ( $stateJson === false ) {
		$stateJson = '{"ok":false,"error":"flexicorp state encoding failed","errors":["flexicorp state encoding failed"],"result":null,"searchIntroHtml":"","searchIntroLeadHtml":"","searchExampleQueries":[]}';
	}
	if ( $isAjax ) {
		if ( !headers_sent() ) header('Content-Type: application/json; charset=UTF-8');
		print $stateJson;
		exit;
	}
	$teitokScripts = ( isset($jsurl) && $jsurl ) ? $jsurl : '/teitok/TEITOK/Scripts';
	$formDefs = function_exists('getset') ? getset('xmlfile/pattributes/forms', array()) : array();
	$tagDefs = function_exists('getset') ? getset('xmlfile/pattributes/tags', array()) : array();
	$translitDefs = function_exists('getset') ? getset('transliteration', array()) : array();
	$sattDefs = function_exists('getset') ? getset('xmlfile/sattributes', array()) : array();
	$jsonForms = function_exists('array2json') ? array2json($formDefs) : json_encode($formDefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$jsonTags = function_exists('array2json') ? array2json($tagDefs) : json_encode($tagDefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$jsonTranslit = function_exists('array2json') ? array2json($translitDefs) : json_encode($translitDefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$jsonSatts = function_exists('array2json') ? array2json($sattDefs) : json_encode($sattDefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ( !$jsonForms ) $jsonForms = '{}';
	if ( !$jsonTags ) $jsonTags = '{}';
	if ( !$jsonTranslit ) $jsonTranslit = '{}';
	if ( !$jsonSatts ) $jsonSatts = '{}';
	$ecscriptsBaseUrl = tt_flexicorp_ecscripts_base_url();
	if ( $ecscriptsBaseUrl === null || $ecscriptsBaseUrl === '' ) {
		// Prefer any locally deployed Scripts/ even if we could not form an absolute URL above.
		// Silently falling back to a remote corplist copy masks local edits and breaks Alpine boot.
		if ( !$ecscripts ) $ecscripts = 'Scripts';
		$ecscripts = rtrim((string)$ecscripts, '/');
		$looksLikeScriptsBase = (bool) preg_match('#/Scripts$#i', $ecscripts);
		$ecscriptsBaseUrl = $looksLikeScriptsBase ? $ecscripts : ($ecscripts . '/Scripts');
	}
	// $ecscripts may be unset when tt_flexicorp_ecscripts_base_url() succeeded; mtime candidates still use it.
	if ( !isset($ecscripts) ) {
		$ecscripts = '';
	}
	$flexicorpAssetUrls = tt_flexicorp_public_flexicorp_urls($ecscriptsBaseUrl);
	if ( !function_exists('tt_flexicorp_asset_version') ) {
		/**
		 * Resolve cache-busting version from the first readable local file candidate.
		 * Falls back to a stable default when no local file path can be resolved.
		 */
		function tt_flexicorp_asset_version( $candidates, $fallback = '20260309' ) {
			if ( !is_array($candidates) ) $candidates = array($candidates);
			foreach ( $candidates as $cand ) {
				$path = trim((string)$cand);
				if ( $path === '' ) continue;
				// Skip URLs; filemtime only works on local filesystem paths.
				if ( preg_match('#^[a-z]+://#i', $path) ) continue;
				$mtime = @filemtime($path);
				if ( $mtime ) return (string)$mtime;
			}
			return (string)$fallback;
		}
	}
	$prRoot = tt_flexicorp_project_root();
	$flexicorpCssVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp.css',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp.css',
		$ecscripts !== '' ? $ecscripts . '/flexicorp.css' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp.css' : '',
	));
	$flexicorpJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp.js' : '',
	));
	$flexicorpFunctionsJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp_functions.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp_functions.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp_functions.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp_functions.js' : '',
	), $flexicorpJsVersion);
	$flexicorpSearchScopeJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp_search_scope.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp_search_scope.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp_search_scope.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp_search_scope.js' : '',
	), $flexicorpJsVersion);
	$flexicorpQuerybuilderJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp_querybuilder.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp_querybuilder.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp_querybuilder.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp_querybuilder.js' : '',
	), $flexicorpJsVersion);
	$flexicorpSearchJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp_search.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp_search.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp_search.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp_search.js' : '',
	), $flexicorpJsVersion);
	$flexicorpFreqsJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp_freqs.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp_freqs.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp_freqs.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp_freqs.js' : '',
	), $flexicorpJsVersion);
	$flexicorpFqsJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp_fqs.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp_fqs.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp_fqs.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp_fqs.js' : '',
	), $flexicorpJsVersion);
	$flexicorpCwbJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/flexicorp_cwb.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'flexicorp_cwb.js',
		$ecscripts !== '' ? $ecscripts . '/flexicorp_cwb.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/flexicorp_cwb.js' : '',
	), $flexicorpJsVersion);
	$flexicorpAdvancedMapsJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/advanced_maps.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'advanced_maps.js',
		$ecscripts !== '' ? $ecscripts . '/advanced_maps.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/advanced_maps.js' : '',
	), $flexicorpJsVersion);
	$flexicorpAdvancedContrastJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/advanced_contrast.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'advanced_contrast.js',
		$ecscripts !== '' ? $ecscripts . '/advanced_contrast.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/advanced_contrast.js' : '',
	), $flexicorpJsVersion);
	$flexicorpAdvancedDcollJsVersion = tt_flexicorp_asset_version(array(
		__DIR__ . '/advanced_dcoll.js',
		$prRoot . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'advanced_dcoll.js',
		$ecscripts !== '' ? $ecscripts . '/advanced_dcoll.js' : '',
		$ecscripts !== '' ? $ecscripts . '/Scripts/advanced_dcoll.js' : '',
	), $flexicorpJsVersion);

	$stats_geo_map_json = '{}';
	$flexicorp_stats_has_geo = false;
	if ( ! function_exists( 'tt_flexicorp_fn_advanced_freqs_bootstrap_context' ) && is_file( __DIR__ . '/flexicorp_functions.php' ) ) {
		require_once __DIR__ . '/flexicorp_functions.php';
	}
	if ( function_exists( 'tt_flexicorp_fn_advanced_freqs_bootstrap_context' ) ) {
		$af_ctx_stats = tt_flexicorp_fn_advanced_freqs_bootstrap_context( $_REQUEST );
		$flexicorp_stats_has_geo = ! empty( $af_ctx_stats['capabilities']['hasGeo'] );
		$gm_stats = isset( $af_ctx_stats['geo_map'] ) && is_array( $af_ctx_stats['geo_map'] ) ? $af_ctx_stats['geo_map'] : array();
		$stats_geo_map_enc = json_encode( $gm_stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$stats_geo_map_json = ( is_string( $stats_geo_map_enc ) && $stats_geo_map_enc !== '' ) ? $stats_geo_map_enc : '{}';
	}
	if ( is_file( __DIR__ . '/advanced_maps.php' ) ) {
		require_once __DIR__ . '/advanced_maps.php';
	}

	// Admin-only debug note for corpus name + cqp layout.
	// This should never depend on which tt_flexicorp_cqp_status() implementation
	// is currently active (function_exists guards can otherwise mask changes).
	$cqpFoldernameForDefault = function_exists('getset') ? trim((string) getset('defaults/base/foldername', '')) : '';
	if ( $cqpFoldernameForDefault === '' ) {
		global $foldername;
		$cqpFoldernameForDefault = isset($foldername) ? trim((string)$foldername) : '';
	}
	$cqpDefaultCorpus = $cqpFoldernameForDefault !== '' ? 'tt-' . $cqpFoldernameForDefault : '';
	$cqpCorpusName = function_exists('getset') ? strtoupper(trim((string) getset('cqp/corpus', $cqpDefaultCorpus))) : $cqpDefaultCorpus;
	// Only show the corpus name in the Engines tab note; registry/cqpfolder are
	// backend-specific (CQP only) and would be misleading for other backends.
	$cqpRegistryRel = function_exists('getset') ? strtolower(trim((string) getset('cqp/defaults/registry', 'cqp'))) : 'cqp';
	$cqpCqpfolderRel = function_exists('getset') ? strtolower(trim((string) getset('cqp/cqpfolder', 'cqp'))) : 'cqp';

	$mainTpl = '';
	if ( function_exists('getlangfile') ) {
		// Language-layer file overrides teitok/flexicorp-template.html; must stay in sync or Alpine x-data / layout breaks.
		$langFile = getlangfile('flexicorp-template');
		if ( is_string($langFile) && $langFile !== '' ) {
			if ( is_file($langFile) && is_readable($langFile) ) {
				$mainTpl = (string) @file_get_contents($langFile);
			} else {
				$mainTpl = (string) $langFile;
			}
		}
	}
	if ( trim($mainTpl) === '' ) {
		$mainTpl = '<div class="flexicorp-loading-shell"><h1>Corpus Search</h1><p class="wrong">Could not load flexicorp template.</p></div>';
	}
	$infoResultForStats = null;
	if ( $infoCall && function_exists( 'tt_flexicorp_done' ) ) {
		$infoDoneForStats = tt_flexicorp_done( $infoCall );
		if ( isset( $infoDoneForStats['result'] ) && is_array( $infoDoneForStats['result'] ) ) {
			$infoResultForStats = $infoDoneForStats['result'];
		}
	}
	$mainVars = array(
		'{{CQP_CORPUS_NAME}}' => tt_flexicorp_h($cqpCorpusName),
		'{{ACTION_NAME}}' => tt_flexicorp_h($actionName),
		'{{DOCS_LIMIT}}' => tt_flexicorp_h((string)$docsLimit),
		'{{STATS_PANEL_HTML}}' => tt_flexicorp_stats_tab_panel_html( $actionName, $infoResultForStats, $projectRoot, $backend, $stats_geo_map_json ),
		'{{STATS_SETTINGS_FREQ_ROW_HTML}}' => tt_flexicorp_stats_settings_freq_limit_row(),
		'{{STATS_HELP_HTML}}' => tt_flexicorp_stats_help_html(),
		'{{STATE_JSON}}' => $stateJson,
		'{{USERNAME_JSON}}' => json_encode(isset($username) ? $username : ''),
		'{{TEITOK_SCRIPTS_JSON}}' => json_encode($teitokScripts),
		'{{JSON_FORMS}}' => $jsonForms,
		'{{JSON_TAGS}}' => $jsonTags,
		'{{JSON_TRANSLIT}}' => $jsonTranslit,
		'{{JSON_SATTS}}' => $jsonSatts,
		'{{DEBUG_JSON}}' => json_encode($debugMode ? 1 : 0),
		'{{TEITOK_SCRIPTS}}' => $teitokScripts,
		'{{ECSCRIPTS}}' => $ecscriptsBaseUrl,
		'{{FLEXICORP_CSS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['css']),
		'{{FLEXICORP_SEARCH_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['search_js']),
		'{{FLEXICORP_FREQS_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['freqs_js']),
		'{{FLEXICORP_FQS_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['fqs_js']),
		'{{FLEXICORP_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['js']),
		'{{FLEXICORP_FUNCTIONS_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['functions_js']),
		'{{FLEXICORP_SEARCH_SCOPE_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['search_scope_js']),
		'{{FLEXICORP_ADVANCED_MAPS_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['advanced_maps_js']),
		'{{FLEXICORP_ADVANCED_CONTRAST_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['advanced_contrast_js']),
		'{{FLEXICORP_ADVANCED_DCOLL_JS_URL}}' => tt_flexicorp_h($flexicorpAssetUrls['advanced_dcoll_js']),
		'{{FLEXICORP_CSS_VERSION}}' => (string)$flexicorpCssVersion,
		'{{FLEXICORP_SEARCH_JS_VERSION}}' => (string)$flexicorpSearchJsVersion,
		'{{FLEXICORP_FREQS_JS_VERSION}}' => (string)$flexicorpFreqsJsVersion,
		'{{FLEXICORP_FQS_JS_VERSION}}' => (string)$flexicorpFqsJsVersion,
		'{{FLEXICORP_JS_VERSION}}' => (string)$flexicorpJsVersion,
		'{{FLEXICORP_FUNCTIONS_JS_VERSION}}' => (string)$flexicorpFunctionsJsVersion,
		'{{FLEXICORP_SEARCH_SCOPE_JS_VERSION}}' => (string)$flexicorpSearchScopeJsVersion,
		'{{FLEXICORP_ADVANCED_MAPS_JS_VERSION}}' => (string)$flexicorpAdvancedMapsJsVersion,
		'{{FLEXICORP_ADVANCED_CONTRAST_JS_VERSION}}' => (string)$flexicorpAdvancedContrastJsVersion,
		'{{FLEXICORP_ADVANCED_DCOLL_JS_VERSION}}' => (string)$flexicorpAdvancedDcollJsVersion,
		// Raw HTML (sanitized); intro box = body only; lead = first <p> shown below Hit limit (SSR fallback for Alpine).
		'{{SEARCH_INTRO_HTML}}' => $searchIntroBodyHtml,
		'{{SEARCH_INTRO_LEAD_HTML}}' => $searchIntroLeadHtml,
	);
	$maintext .= strtr( $mainTpl, $mainVars );

	// Shared URL/fetch helpers for flexicorp.js (getRequestUrl → flexicorpAjaxPostUrl). Must load before flexicorp.js.
	if ( strpos( $maintext, 'flexicorp_functions.js' ) === false && preg_match( '#<script[^>]+src="[^"]*flexicorp\\.js[^"]*"[^>]*></script>#i', $maintext ) ) {
		$fcFnsHref = tt_flexicorp_h( $flexicorpAssetUrls['functions_js'] . '?v=' . $flexicorpFunctionsJsVersion );
		$fcFnsTag  = '<script src="' . $fcFnsHref . '" defer></script>';
		$fcScopeHref = tt_flexicorp_h( $flexicorpAssetUrls['search_scope_js'] . '?v=' . $flexicorpSearchScopeJsVersion );
		$fcScopeTag  = '<script src="' . $fcScopeHref . '" defer></script>';
		$fcQbHref = tt_flexicorp_h( $flexicorpAssetUrls['querybuilder_js'] . '?v=' . $flexicorpQuerybuilderJsVersion );
		$fcQbTag  = '<script src="' . $fcQbHref . '" defer></script>';
		$fcAdvMapsHref = tt_flexicorp_h( $flexicorpAssetUrls['advanced_maps_js'] . '?v=' . $flexicorpAdvancedMapsJsVersion );
		$fcAdvMapsTag = '<script src="' . $fcAdvMapsHref . '" defer></script>';
		$fcAdvContrastHref = tt_flexicorp_h( $flexicorpAssetUrls['advanced_contrast_js'] . '?v=' . $flexicorpAdvancedContrastJsVersion );
		$fcAdvContrastTag = '<script src="' . $fcAdvContrastHref . '" defer></script>';
		$fcAdvDcollHref = tt_flexicorp_h( $flexicorpAssetUrls['advanced_dcoll_js'] . '?v=' . $flexicorpAdvancedDcollJsVersion );
		$fcAdvDcollTag = '<script src="' . $fcAdvDcollHref . '" defer></script>';
		$fcMapsLeafletTags = '';
		if ( function_exists( 'tt_flexicorp_adv_maps_print_assets' ) && ! empty( $flexicorp_stats_has_geo ) ) {
			ob_start();
			tt_flexicorp_adv_maps_print_assets( true, false );
			$fcMapsLeafletTags = (string) ob_get_clean();
		}
		$maintext  = preg_replace(
			'#<script([^>]*src="[^"]*flexicorp\\.js[^"]*"[^>]*)></script>#i',
			$fcFnsTag . $fcScopeTag . $fcQbTag . $fcMapsLeafletTags . $fcAdvMapsTag . $fcAdvContrastTag . $fcAdvDcollTag . '<script$1></script>',
			$maintext,
			1
		);
	}
	// Query-builder module: keep separate from core app so we can evolve it independently.
	// Inject it before flexicorp.js when template does not include it explicitly.
	if ( strpos( $maintext, 'flexicorp_querybuilder.js' ) === false && preg_match( '#<script[^>]+src="[^"]*flexicorp\\.js[^"]*"[^>]*></script>#i', $maintext ) ) {
		$fcQbHref = tt_flexicorp_h( $flexicorpAssetUrls['querybuilder_js'] . '?v=' . $flexicorpQuerybuilderJsVersion );
		$fcQbTag  = '<script src="' . $fcQbHref . '" defer></script>';
		$maintext = preg_replace(
			'#<script([^>]*src="[^"]*flexicorp\\.js[^"]*"[^>]*)></script>#i',
			$fcQbTag . '<script$1></script>',
			$maintext,
			1
		);
	}

	// CWB/CQP-only client hooks (e.g. outgoing query tweaks). Omit for Pando, Manatee, BlackLab, etc.
	$injectFlexicorpCwbJs = ( $backend === 'cqp' ) || ( $backend === 'flexi' && $corpusFormat === 'cwb' );
	if ( $injectFlexicorpCwbJs ) {
		$cwbJsHref = tt_flexicorp_h( $flexicorpAssetUrls['cwb_js'] . '?v=' . $flexicorpCwbJsVersion );
		$cwbTag      = '<script src="' . $cwbJsHref . '" defer></script>';
		if ( stripos( $maintext, '</body>' ) !== false ) {
			$maintext = preg_replace( '#</body>#i', $cwbTag . '</body>', $maintext, 1 );
		} else {
			$maintext .= $cwbTag;
		}
	}

