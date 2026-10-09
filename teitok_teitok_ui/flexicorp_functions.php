<?php
/**
 * Shared helper functions for FlexiCorp UI modules.
 *
 * Purpose:
 * - Provide explicit, low-coupling utility functions reusable by both
 *   legacy flexicorp and advanced_freqs modules.
 * - Avoid sharing mutable module state across app boundaries.
 */

if ( ! function_exists( 'tt_flexicorp_fn_get_string' ) ) {
	/**
	 * Read a request value as trimmed string.
	 */
	function tt_flexicorp_fn_get_string( $arr, $key, $default = '' ) {
		if ( ! is_array( $arr ) || ! array_key_exists( $key, $arr ) ) {
			return (string) $default;
		}
		return trim( (string) $arr[ $key ] );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_detect_operation_from_result' ) ) {
	/**
	 * Extract normalized operation name from a result envelope.
	 * Returns empty string if unknown.
	 */
	function tt_flexicorp_fn_detect_operation_from_result( $result ) {
		if ( ! is_array( $result ) ) {
			return '';
		}
		$op = '';
		if ( isset( $result['operation'] ) ) {
			$op = strtolower( trim( (string) $result['operation'] ) );
		}
		if ( $op === '' && isset( $result['result'] ) && is_array( $result['result'] ) && isset( $result['result']['operation'] ) ) {
			$op = strtolower( trim( (string) $result['result']['operation'] ) );
		}
		$allowed = array( 'query', 'freq', 'count', 'dist', 'coll', 'dcoll', 'keyness', 'info', 'status', 'list-docs', 'reindex' );
		return in_array( $op, $allowed, true ) ? $op : '';
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_is_aa_clause' ) ) {
	/**
	 * Whether a statement is an aggregation/association clause.
	 */
	function tt_flexicorp_fn_is_aa_clause( $part ) {
		$p = trim( (string) $part );
		if ( $p === '' ) return false;
		if ( preg_match( '/^(freq|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i', $p ) ) return true;
		if ( preg_match( '/^(count|dist|dcoll|keyness)\b/i', $p ) ) return true;
		if ( preg_match( '/^coll\s+/i', $p ) ) return true;
		if ( preg_match( '/^(tabulate|sort|show)\s+/i', $p ) ) return true;
		if ( preg_match( '/^size\b/i', $p ) ) return true;
		return false;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_normalize_flexicorp_bundle_path' ) ) {
	/**
	 * Some TEITOK / easycorp layouts omit the slash between the Scripts folder and flexicorp.js
	 * ("Scriptsflexicorp.js"). Fix so dirname() and suffix swaps produce stable paths.
	 *
	 * @param string $path_or_url Path or full URL (query string should be stripped by caller).
	 */
	function tt_flexicorp_fn_normalize_flexicorp_bundle_path( $path_or_url ) {
		$s = str_replace( '\\', '/', (string) $path_or_url );
		$s = preg_replace( '#Scriptsflexicorp\\.js(\\?.*)?$#i', 'Scripts/flexicorp.js', $s );
		$s = preg_replace( '#Scriptsflexicorp\\.php(\\?.*)?$#i', 'Scripts/flexicorp.php', $s );
		return $s;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_join_url_path' ) ) {
	/**
	 * Join base URL/path with one path segment (always exactly one slash between).
	 */
	function tt_flexicorp_fn_join_url_path( $base, $tail ) {
		$b = str_replace( '\\', '/', rtrim( (string) $base, '/' ) );
		$t = str_replace( '\\', '/', ltrim( (string) $tail, '/' ) );
		if ( $t === '' ) {
			return $b;
		}
		return $b === '' ? $t : $b . '/' . $t;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_scripts_base_url' ) ) {
	/**
	 * Resolve a public Scripts base URL (ending in /Scripts).
	 */
	function tt_flexicorp_fn_scripts_base_url() {
		if ( function_exists( 'getjsurl' ) ) {
			$u = trim( (string) getjsurl( 'flexicorp' ) );
			if ( $u !== '' ) {
				$uNoQuery = preg_replace( '/\?.*$/', '', $u );
				$uNoQuery = tt_flexicorp_fn_normalize_flexicorp_bundle_path( $uNoQuery );
				if ( preg_match( '#/flexicorp\.js$#i', $uNoQuery ) ) {
					return rtrim( dirname( $uNoQuery ), '/' );
				}
				if ( preg_match( '#/Scripts(?:/|$)#i', $uNoQuery ) ) {
					return rtrim( $uNoQuery, '/' );
				}
			}
		}

		// Legacy fallback based on configured scripts root.
		global $ecscripts;
		$base = isset( $ecscripts ) ? trim( (string) $ecscripts ) : '';
		if ( $base !== '' ) {
			$base = rtrim( $base, '/' );
			if ( ! preg_match( '#/Scripts$#i', $base ) ) {
				$base .= '/Scripts';
			}
			return $base;
		}

		return 'Scripts';
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_http_same_origin_directory_base' ) ) {
	/**
	 * Scheme + host + dirname(SCRIPT_NAME) — same origin directory as `index.php` beside the active script.
	 */
	function tt_flexicorp_fn_http_same_origin_directory_base() {
		$https = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off';
		$scheme  = $https ? 'https' : 'http';
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
		$script  = isset( $_SERVER['SCRIPT_NAME'] ) ? str_replace( '\\', '/', (string) $_SERVER['SCRIPT_NAME'] ) : '';
		if ( $script === '' ) {
			return $scheme . '://' . $host;
		}
		$dir = dirname( $script );
		if ( $dir === '/' || $dir === '.' || $dir === '\\' ) {
			return $scheme . '://' . $host;
		}

		return $scheme . '://' . $host . rtrim( $dir, '/' );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_resolve_http_url_forcurl' ) ) {
	/**
	 * Turn browser-style relative URLs (e.g. index.php?…) into absolute URLs for curl/file_get_contents.
	 */
	function tt_flexicorp_fn_resolve_http_url_forcurl( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return $url;
		}
		if ( empty( $_SERVER['SCRIPT_NAME'] ) ) {
			return $url;
		}

		return tt_flexicorp_fn_join_url_path(
			tt_flexicorp_fn_http_same_origin_directory_base(),
			$url
		);
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_flexicorp_json_endpoint_url' ) ) {
	/**
	 * Relative URL for POSTing `ajax=1` FlexiCorp JSON — TEITOK uses **index.php** in the current directory.
	 *
	 * Routing (FQS vs local flexicorp adapters) stays in the flexicorp module; entry is always `index.php?action=flexicorp`.
	 * Do not POST to advanced_stats.php / advanced_freqs.php directly — they return HTML shells only.
	 *
	 * @param string $action TEITOK action name (default `flexicorp`).
	 * @return string Relative URL such as `index.php?action=flexicorp`.
	 */
	function tt_flexicorp_fn_flexicorp_json_endpoint_url( $action = 'flexicorp' ) {
		$action = trim( (string) $action );
		if ( $action === '' ) {
			$action = 'flexicorp';
		}
		$params = array( 'action' => $action );
		if ( isset( $_REQUEST['debug'] ) && (string) $_REQUEST['debug'] !== '' ) {
			$params['debug'] = (string) $_REQUEST['debug'];
		}
		if ( isset( $_REQUEST['subc'] ) && (string) $_REQUEST['subc'] !== '' ) {
			$params['subc'] = (string) $_REQUEST['subc'];
		}

		return 'index.php?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_http_post_form_urlencoded' ) ) {
	/**
	 * POST application/x-www-form-urlencoded (same as browser Stats forms).
	 *
	 * @return array{ ok: bool, status: int, body: string }
	 */
	function tt_flexicorp_fn_http_post_form_urlencoded( $url, array $fields, array $extra_headers = array() ) {
		$url = tt_flexicorp_fn_resolve_http_url_forcurl( trim( (string) $url ) );
		$body = http_build_query( $fields, '', '&', PHP_QUERY_RFC1738 );
		$headers = array_merge(
			array( 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' ),
			$extra_headers
		);
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			if ( $ch === false ) {
				return array( 'ok' => false, 'status' => 0, 'body' => '' );
			}
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
			if ( ! empty( $_SERVER['HTTP_COOKIE'] ) ) {
				curl_setopt( $ch, CURLOPT_COOKIE, (string) $_SERVER['HTTP_COOKIE'] );
			}
			$raw = curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_close( $ch );
			return array(
				'ok' => $code >= 200 && $code < 300,
				'status' => $code,
				'body' => is_string( $raw ) ? $raw : '',
			);
		}

		$header_line = implode( "\r\n", $headers );
		$ctx = stream_context_create(
			array(
				'http' => array(
					'method' => 'POST',
					'header' => $header_line . "\r\n",
					'content' => $body,
					'timeout' => 120,
				),
			)
		);
		$raw = @file_get_contents( $url, false, $ctx );
		return array(
			'ok' => $raw !== false,
			'status' => $raw !== false ? 200 : 0,
			'body' => is_string( $raw ) ? $raw : '',
		);
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_run_flexicorp_ajax_json' ) ) {
	/**
	 * Single server-side entry: POST TEITOK **index.php** flexicorp JSON (`ajax=1`). FQS vs local adapters are resolved inside the flexicorp module.
	 *
	 * @param array<string,string|int|float|bool> $post_fields Body fields (`ajax` and default `action` added).
	 * @return array{ ok: bool, state: ?array, error: string, http_status: int, raw_snippet: string }
	 */
	function tt_flexicorp_fn_run_flexicorp_ajax_json( array $post_fields ) {
		$post_fields['ajax'] = '1';
		if ( ! isset( $post_fields['action'] ) ) {
			$post_fields['action'] = 'flexicorp';
		}
		$url = tt_flexicorp_fn_flexicorp_json_endpoint_url( (string) $post_fields['action'] );
		$res = tt_flexicorp_fn_http_post_form_urlencoded(
			$url,
			$post_fields,
			array( 'Accept: application/json, text/javascript, */*;q=0.1', 'X-Requested-With: XMLHttpRequest' )
		);
		$snippet = substr( preg_replace( '/\s+/', ' ', (string) $res['body'] ), 0, 320 );
		if ( ! $res['ok'] ) {
			return array(
				'ok' => false,
				'state' => null,
				'error' => 'HTTP ' . (int) $res['status'] . ( $snippet !== '' ? ': ' . $snippet : '' ),
				'http_status' => (int) $res['status'],
				'raw_snippet' => $snippet,
			);
		}
		$decoded = json_decode( $res['body'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'ok' => false,
				'state' => null,
				'error' => 'JSON: ' . json_last_error_msg(),
				'http_status' => (int) $res['status'],
				'raw_snippet' => $snippet,
			);
		}
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok' => false,
				'state' => null,
				'error' => 'Decoded JSON was not an object.',
				'http_status' => (int) $res['status'],
				'raw_snippet' => $snippet,
			);
		}
		return array(
			'ok' => true,
			'state' => $decoded,
			'error' => '',
			'http_status' => (int) $res['status'],
			'raw_snippet' => '',
		);
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_run_query' ) ) {
	/**
	 * Preferred alias: one place for server-side flexicorp queries (same routing as the Corpus Search UI).
	 */
	function tt_flexicorp_fn_run_query( array $post_fields ) {
		return tt_flexicorp_fn_run_flexicorp_ajax_json( $post_fields );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_asset_url' ) ) {
	/**
	 * Resolve a public asset URL for shared FlexiCorp UI files.
	 */
	function tt_flexicorp_fn_asset_url( $basename, $ext ) {
		$name = trim( (string) $basename );
		$extn = strtolower( trim( (string) $ext ) );
		if ( $name === '' || $extn === '' ) return '';

		$localScriptsPath = 'Scripts/' . $name . '.' . $extn;
		if ( is_file( $localScriptsPath ) ) {
			return $localScriptsPath;
		}

		$base = tt_flexicorp_fn_scripts_base_url();
		if ( $base !== '' ) {
			return rtrim( $base, '/' ) . '/' . $name . '.' . $extn;
		}

		return $localScriptsPath;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_ensure_session' ) ) {
	/**
	 * Start PHP session when safe (TEITOK may already have started it).
	 */
	function tt_flexicorp_fn_ensure_session() {
		if ( function_exists( 'tt_flexicorp_ensure_session' ) ) {
			tt_flexicorp_ensure_session();
			return;
		}
		if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
			@session_start();
		}
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_read_sticky_flexicorp_selection' ) ) {
	/**
	 * Last backend / dialect / storage from flexicorp (save_selection or full page load).
	 *
	 * @return array{backend:string,query_language:string,corpus_format:string,query_engine:string}
	 */
	function tt_flexicorp_fn_read_sticky_flexicorp_selection() {
		$out = array(
			'backend'         => '',
			'query_language'  => '',
			'corpus_format'   => '',
			'query_engine'    => '',
		);
		tt_flexicorp_fn_ensure_session();
		if ( ! isset( $_SESSION ) || ! is_array( $_SESSION ) ) {
			return $out;
		}
		$b = isset( $_SESSION['flexicorp_teitok'] ) && is_array( $_SESSION['flexicorp_teitok'] )
			? $_SESSION['flexicorp_teitok']
			: array();
		if ( isset( $b['last_backend'] ) ) {
			$out['backend'] = trim( (string) $b['last_backend'] );
		}
		if ( isset( $b['last_query_language'] ) ) {
			$out['query_language'] = trim( (string) $b['last_query_language'] );
		}
		if ( isset( $b['last_corpus_format'] ) ) {
			$out['corpus_format'] = trim( (string) $b['last_corpus_format'] );
		}
		if ( isset( $b['last_query_engine'] ) ) {
			$out['query_engine'] = trim( (string) $b['last_query_engine'] );
		}
		return $out;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_collect_string_keys_recursive' ) ) {
	/**
	 * Collect string keys from nested arrays (settings shapes vary).
	 *
	 * @param mixed $node Value to walk.
	 * @param int   $depth Guard against cycles.
	 * @return string[] Unique non-empty keys.
	 */
	function tt_flexicorp_fn_collect_string_keys_recursive( $node, $depth = 0 ) {
		if ( $depth > 12 ) {
			return array();
		}
		$keys = array();
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				if ( is_string( $k ) && trim( $k ) !== '' ) {
					$keys[] = $k;
				}
				if ( is_array( $v ) ) {
					foreach ( tt_flexicorp_fn_collect_string_keys_recursive( $v, $depth + 1 ) as $sub ) {
						$keys[] = $sub;
					}
				} elseif ( is_string( $v ) ) {
					// Lists of attribute names sometimes appear as string leaves.
					$t = trim( $v );
					if ( $t !== '' && strlen( $t ) < 120 && preg_match( '/^[a-zA-Z0-9_.-]+$/', $t ) ) {
						$keys[] = $t;
					}
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( 'trim', $keys ) ) ) );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_array_is_numeric_list' ) ) {
	/**
	 * @param mixed $node Value.
	 */
	function tt_flexicorp_fn_array_is_numeric_list( $node ) {
		if ( ! is_array( $node ) ) {
			return false;
		}
		$n = count( $node );
		if ( $n === 0 ) {
			return false;
		}
		return array_keys( $node ) === range( 0, $n - 1 );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_geo_hint_assoc_children_are_metadata_only' ) ) {
	/**
	 * TEITOK often nests attribute metadata under the real id (`text_province` => label/type/…).
	 * When **all** child keys look like UI/metadata, the **parent path** is the s-attribute id — do not append children.
	 *
	 * @param array $assoc Associative array only.
	 */
	function tt_flexicorp_fn_geo_hint_assoc_children_are_metadata_only( array $assoc ) {
		if ( count( $assoc ) === 0 ) {
			return true;
		}
		// An attribute definition in TEITOK's flattened settings has only plain values
		// (display, xpath, nosearch, noshow, …); a container holds further items. Any
		// TEITOK flag then counts as metadata: `<item key="geo" nosearch="1" noshow="1">`
		// must give `text_geo`, not `text_geo_nosearch` / `text_geo_noshow`.
		$allScalar = true;
		foreach ( $assoc as $cv ) {
			if ( is_array( $cv ) ) {
				$allScalar = false;
				break;
			}
		}
		if ( $allScalar ) {
			return true;
		}
		$meta = '/^(type|label|name|display|desc|hint|help|sort|readonly|multivalued|ui|widget|icon|color|width|height|required|default|encoding|xpath|namespace|attr|path|level|key|nosearch|noshow|nolist|noview|admin|values|external|inherit|translate|transliterate|audio)$/i';
		foreach ( array_keys( $assoc ) as $ck ) {
			if ( ! is_string( $ck ) || trim( (string) $ck ) === '' ) {
				return false;
			}
			if ( ! preg_match( $meta, (string) $ck ) ) {
				return false;
			}
		}
		return true;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_collect_attribute_slug_candidates_from_tree' ) ) {
	/**
	 * Attribute *names* for geo hinting:
	 * - string elements of **numeric lists** (registry lists),
	 * - **path-prefixed** nested keys so `quote` + `regionname` yields `quote_regionname`, not a bare `regionname`,
	 * - nested objects whose children are only metadata keys collapse to the parent path (`text_province` stays `text_province`).
	 * Does **not** scrape arbitrary string leaves (human labels).
	 *
	 * @param mixed  $node    Settings subtree.
	 * @param int    $depth   Recursion guard.
	 * @param string $prefix  Underscore path from ancestors (e.g. `quote`).
	 * @return string[] Unique slugs.
	 */
	function tt_flexicorp_fn_collect_attribute_slug_candidates_from_tree( $node, $depth = 0, $prefix = '' ) {
		if ( $depth > 12 || ! is_array( $node ) ) {
			return array();
		}
		$slug_ok = function ( $s ) {
			$t = trim( (string) $s );
			return $t !== '' && strlen( $t ) < 120 && preg_match( '/^[a-zA-Z0-9_.-]+$/', $t );
		};

		if ( tt_flexicorp_fn_array_is_numeric_list( $node ) ) {
			$out = array();
			foreach ( $node as $v ) {
				if ( is_string( $v ) && $slug_ok( $v ) ) {
					$out[] = trim( $v );
				} elseif ( is_array( $v ) ) {
					foreach ( tt_flexicorp_fn_collect_attribute_slug_candidates_from_tree( $v, $depth + 1, $prefix ) as $sub ) {
						$out[] = $sub;
					}
				}
			}
			return array_values( array_unique( array_filter( array_map( 'trim', $out ) ) ) );
		}

		$out = array();
		foreach ( $node as $k => $v ) {
			if ( ! is_string( $k ) || trim( $k ) === '' ) {
				continue;
			}
			$seg  = trim( $k );
			$full = ( $prefix === '' ) ? $seg : $prefix . '_' . $seg;

			if ( is_array( $v ) ) {
				if ( tt_flexicorp_fn_array_is_numeric_list( $v ) ) {
					foreach ( tt_flexicorp_fn_collect_attribute_slug_candidates_from_tree( $v, $depth + 1, '' ) as $sub ) {
						$out[] = $sub;
					}
				} elseif ( tt_flexicorp_fn_geo_hint_assoc_children_are_metadata_only( $v ) ) {
					$out[] = $full;
				} else {
					foreach ( tt_flexicorp_fn_collect_attribute_slug_candidates_from_tree( $v, $depth + 1, $full ) as $sub ) {
						$out[] = $sub;
					}
				}
			} elseif ( $slug_ok( $v ) ) {
				// Rare scalar leaf under a path — keep full path as id.
				$out[] = $full;
			}
		}
		return array_values( array_unique( array_filter( array_map( 'trim', $out ) ) ) );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_geo_hint_drop_text_prefixed_shadows' ) ) {
	/**
	 * When both `province` and `text_province` appear, prefer the qualified **s-attribute** id TEITOK uses for CQP/Pando.
	 *
	 * @param string[] $fields Candidate field names (any order).
	 * @return string[]
	 */
	function tt_flexicorp_fn_geo_hint_drop_text_prefixed_shadows( array $fields ) {
		$fields = array_values( array_unique( array_filter( array_map( 'trim', $fields ) ) ) );
		if ( count( $fields ) < 2 ) {
			return $fields;
		}
		$set = array_fill_keys( $fields, true );
		$out = array();
		foreach ( $fields as $f ) {
			if ( $f === '' ) {
				continue;
			}
			$pref = 'text_' . $f;
			if ( isset( $set[ $pref ] ) && $f !== $pref ) {
				continue;
			}
			$out[] = $f;
		}
		return $out;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_geo_hint_drop_suffix_shadowed_short_tokens' ) ) {
	/**
	 * Drop bare last segments when a longer id exists (`regionname` if `quote_regionname` is present).
	 *
	 * @param string[] $fields Candidate field names.
	 * @return string[]
	 */
	function tt_flexicorp_fn_geo_hint_drop_suffix_shadowed_short_tokens( array $fields ) {
		$fields = array_values( array_unique( array_filter( array_map( 'trim', $fields ) ) ) );
		if ( count( $fields ) < 2 ) {
			return $fields;
		}
		$out = array();
		foreach ( $fields as $f ) {
			if ( $f === '' ) {
				continue;
			}
			$drop = false;
			foreach ( $fields as $g ) {
				if ( $f === $g ) {
					continue;
				}
				if ( strlen( (string) $g ) <= strlen( (string) $f ) ) {
					continue;
				}
				if ( substr( (string) $g, -strlen( (string) $f ) - 1 ) === '_' . $f ) {
					$drop = true;
					break;
				}
			}
			if ( ! $drop ) {
				$out[] = $f;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_geo_hint_sort_slug_preference' ) ) {
	/**
	 * Prefer `text_*` names, then longer / more specific slugs (works across corpora without hard-coded field ids).
	 *
	 * @param string[] $fields Field names.
	 * @return string[]
	 */
	function tt_flexicorp_fn_geo_hint_sort_slug_preference( array $fields ) {
		usort(
			$fields,
			function ( $a, $b ) {
				$a = (string) $a;
				$b = (string) $b;
				$pa = ( strpos( $a, 'text_' ) === 0 ) ? 1 : 0;
				$pb = ( strpos( $b, 'text_' ) === 0 ) ? 1 : 0;
				if ( $pa !== $pb ) {
					return $pb - $pa;
				}
				$lc = strlen( $b ) - strlen( $a );
				if ( $lc !== 0 ) {
					return $lc;
				}
				return strcmp( $a, $b );
			}
		);
		return $fields;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_teitok_geo_capability_hint' ) ) {
	/**
	 * True when TEITOK / CQP attribute metadata suggests geolocation fields (field name heuristics).
	 * Aligns with corpusStatsSemanticType geo regex in flexicorp_freqs.js.
	 */
	function tt_flexicorp_fn_teitok_geo_capability_hint() {
		if ( ! function_exists( 'getset' ) ) {
			return false;
		}
		$sources = array(
			getset( 'cqp/pattributes', array() ),
			getset( 'cqp/sattributes', array() ),
			getset( 'xmlfile/pattributes/forms', array() ),
			getset( 'xmlfile/pattributes/tags', array() ),
			getset( 'xmlfile/sattributes', array() ),
		);
		$re = '/(^|_)(geo|geolocation|location|place|lat|lon|lng|latitude|longitude|coords?)(_|$)/i';
		foreach ( $sources as $src ) {
			foreach ( tt_flexicorp_fn_collect_string_keys_recursive( $src ) as $key ) {
				if ( preg_match( $re, (string) $key ) ) {
					return true;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_read_session_last_search_query' ) ) {
	/**
	 * Optional persisted search scope for advanced modules (filled when flexicorp saves it).
	 *
	 * @return string Trimmed query or ''.
	 */
	function tt_flexicorp_fn_read_session_last_search_query() {
		tt_flexicorp_fn_ensure_session();
		if ( ! isset( $_SESSION ) || ! is_array( $_SESSION ) ) {
			return '';
		}
		$b = isset( $_SESSION['flexicorp_teitok'] ) && is_array( $_SESSION['flexicorp_teitok'] )
			? $_SESSION['flexicorp_teitok']
			: array();
		if ( isset( $b['last_search_query'] ) && is_string( $b['last_search_query'] ) ) {
			return trim( $b['last_search_query'] );
		}
		return '';
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_named_query_sources_bootstrap' ) ) {
	/**
	 * Bootstrap named-query pick lists for Advanced → Queries (session-only v1).
	 *
	 * @param string $query_language Active dialect label (e.g. Pando-CQL) for filtering recent lists.
	 * @return array{last:array<int,array<string,mixed>>,recent:array<int,array<string,mixed>>,stored:array<int,array<string,mixed>>}
	 */
	function tt_flexicorp_fn_named_query_sources_bootstrap( $query_language = '' ) {
		tt_flexicorp_fn_ensure_session();
		$ql = trim( (string) $query_language );

		$last_q = function_exists( 'tt_flexicorp_fn_read_session_last_search_query' )
			? tt_flexicorp_fn_read_session_last_search_query()
			: '';
		$last = array();
		if ( is_string( $last_q ) && trim( $last_q ) !== '' ) {
			$last[] = array(
				'name'    => 'Last',
				'query'   => trim( $last_q ),
				'source'  => 'last',
				'timestamp' => '',
			);
		}

		$recent = array();
		if ( function_exists( 'tt_flexicorp_recent_queries_by_dialect_map' ) ) {
			$map = tt_flexicorp_recent_queries_by_dialect_map();
			$candidates = array();
			if ( $ql !== '' && isset( $map[ $ql ] ) && is_array( $map[ $ql ] ) ) {
				$candidates = $map[ $ql ];
			} elseif ( is_array( $map ) ) {
				foreach ( $map as $arr ) {
					if ( ! is_array( $arr ) ) {
						continue;
					}
					foreach ( $arr as $q ) {
						if ( is_string( $q ) && trim( $q ) !== '' ) {
							$candidates[] = trim( $q );
						}
					}
				}
			}
			$candidates = array_values( array_unique( array_map( 'trim', $candidates ) ) );
			$candidates = array_slice( $candidates, 0, 40 );
			foreach ( $candidates as $idx => $q ) {
				$recent[] = array(
					'name'    => 'Recent ' . ( $idx + 1 ),
					'query'   => $q,
					'source'  => 'recent',
					'timestamp' => '',
				);
			}
		}

		$stored = array();
		if ( isset( $_SESSION['queries'] ) && is_array( $_SESSION['queries'] ) ) {
			foreach ( $_SESSION['queries'] as $rec ) {
				if ( ! is_array( $rec ) ) {
					continue;
				}
				$nm = '';
				if ( isset( $rec['name'] ) && is_string( $rec['name'] ) ) {
					$nm = trim( $rec['name'] );
				} elseif ( isset( $rec['title'] ) && is_string( $rec['title'] ) ) {
					$nm = trim( $rec['title'] );
				}
				$qtxt = '';
				if ( isset( $rec['query'] ) && is_string( $rec['query'] ) ) {
					$qtxt = trim( $rec['query'] );
				} elseif ( isset( $rec['cql'] ) && is_string( $rec['cql'] ) ) {
					$qtxt = trim( $rec['cql'] );
				}
				if ( $qtxt === '' ) {
					continue;
				}
				if ( $nm === '' ) {
					$nm = 'Stored';
				}
				$stored[] = array(
					'name'    => $nm,
					'query'   => $qtxt,
					'source'  => 'stored',
					'timestamp' => isset( $rec['updated'] ) ? trim( (string) $rec['updated'] ) : '',
				);
			}
		}

		return array(
			'last'   => $last,
			'recent' => $recent,
			'stored' => array_slice( $stored, 0, 80 ),
		);
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_geo_map_field_hints' ) ) {
	/**
	 * Candidate s-attribute names for map aggregation (generalized; not Spain-specific).
	 * Inspired by easycorp cqp-new.php (text_province, text_geo, text_place) but driven by settings keys.
	 *
	 * @return array{coordinateFields:string[],regionFields:string[],countryFields:string[],subnationalFields:string[]}
	 */
	function tt_flexicorp_fn_geo_map_field_hints() {
		$empty = array(
			'coordinateFields'   => array(),
			'regionFields'         => array(),
			'countryFields'        => array(),
			'subnationalFields'    => array(),
		);
		if ( ! function_exists( 'getset' ) ) {
			return $empty;
		}
		$sources = array(
			getset( 'cqp/sattributes', array() ),
			getset( 'xmlfile/sattributes', array() ),
		);
		$keys = array();
		foreach ( $sources as $satts ) {
			foreach ( tt_flexicorp_fn_collect_attribute_slug_candidates_from_tree( $satts ) as $k ) {
				$keys[] = $k;
			}
		}
		$keys = array_values( array_unique( array_filter( array_map( 'trim', $keys ) ) ) );
		$keys = tt_flexicorp_fn_geo_hint_drop_text_prefixed_shadows( $keys );
		$keys = tt_flexicorp_fn_geo_hint_drop_suffix_shadowed_short_tokens( $keys );
		$keys = tt_flexicorp_fn_geo_hint_sort_slug_preference( $keys );
		$coord = array();
		$country = array();
		$subnat  = array();
		foreach ( $keys as $k ) {
			$k = (string) $k;
			if ( $k === '' ) {
				continue;
			}
			$lk = strtolower( $k );
			if ( preg_match( '/(^|_)(geo|geolocation|lat|lon|lng|latitude|longitude|coords?)(_|$)/', $lk ) ) {
				$coord[] = $k;
			}
			if ( preg_match( '/country|nation|iso.?3166|^cc$/', $lk ) ) {
				$country[] = $k;
			}
			if ( preg_match( '/province|region|state|county|prefecture|department|nuts|cca|comunidad|municip/', $lk ) ) {
				$subnat[] = $k;
			}
		}
		$coord   = array_values( array_unique( $coord ) );
		$country = array_values( array_unique( $country ) );
		$subnat  = array_values( array_unique( $subnat ) );
		$region  = array_values( array_unique( array_merge( $country, $subnat ) ) );
		return array(
			'coordinateFields' => array_slice( $coord, 0, 24 ),
			'regionFields'     => array_slice( $region, 0, 40 ),
			'countryFields'    => array_slice( $country, 0, 24 ),
			'subnationalFields'=> array_slice( $subnat, 0, 24 ),
		);
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_geo_map_default_aggregation' ) ) {
	/**
	 * @param array $hints From tt_flexicorp_fn_geo_map_field_hints().
	 */
	function tt_flexicorp_fn_geo_map_default_aggregation( $hints ) {
		if ( ! is_array( $hints ) ) {
			return 'country';
		}
		$c = isset( $hints['countryFields'] ) && is_array( $hints['countryFields'] ) ? $hints['countryFields'] : array();
		$s = isset( $hints['subnationalFields'] ) && is_array( $hints['subnationalFields'] ) ? $hints['subnationalFields'] : array();
		if ( count( $c ) ) {
			return 'country';
		}
		if ( count( $s ) ) {
			return 'subnational';
		}
		return 'custom';
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_geo_map_runtime_settings' ) ) {
	/**
	 * Read optional geomap runtime settings from TEITOK config-like stores.
	 *
	 * Expected shape (when available):
	 * - startpos: "lat lon"
	 * - zoom: number
	 * - osmlayer: tile URL template
	 * - markertype: string
	 * - areas: [{key,display,startpos,zoom}, ...]
	 * - regions: [{key,country,level,group,cqp,label,provider,url,nameProperty,iso3,adm}, ...]
	 *
	 * @return array{startpos:string,zoom:int,osmlayer:string,markertype:string,areas:array<int,array<string,mixed>>,regions:array<int,array<string,mixed>>}
	 */
	function tt_flexicorp_fn_geo_map_runtime_settings() {
		$out = array(
			'startpos' => '',
			'zoom' => 0,
			'osmlayer' => '',
			'markertype' => '',
			'areas' => array(),
			'regions' => array(),
		);
		if ( ! function_exists( 'getset' ) ) {
			return $out;
		}
		$candidates = array(
			getset( 'geomap', array() ),
			getset( 'xmlfile/geomap', array() ),
			getset( 'settings/geomap', array() ),
			getset( 'xmlfile/settings/geomap', array() ),
		);
		$raw = array();
		foreach ( $candidates as $cand ) {
			if ( is_array( $cand ) && count( $cand ) > 0 ) {
				$raw = $cand;
				break;
			}
		}
		if ( ! is_array( $raw ) || ! count( $raw ) ) {
			return $out;
		}
		$out['startpos'] = isset( $raw['startpos'] ) ? trim( (string) $raw['startpos'] ) : '';
		$out['zoom'] = isset( $raw['zoom'] ) ? (int) $raw['zoom'] : 0;
		$out['osmlayer'] = isset( $raw['osmlayer'] ) ? trim( (string) $raw['osmlayer'] ) : '';
		$out['markertype'] = isset( $raw['markertype'] ) ? trim( (string) $raw['markertype'] ) : '';
		$areas = array();
		if ( isset( $raw['areas'] ) && is_array( $raw['areas'] ) ) {
			$src = $raw['areas'];
			// Accept either ['item'=>[...]] or plain list.
			if ( isset( $src['item'] ) && is_array( $src['item'] ) ) {
				$src = $src['item'];
			}
			foreach ( $src as $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}
				$areas[] = array(
					'key' => isset( $it['key'] ) ? trim( (string) $it['key'] ) : '',
					'display' => isset( $it['display'] ) ? trim( (string) $it['display'] ) : '',
					'startpos' => isset( $it['startpos'] ) ? trim( (string) $it['startpos'] ) : '',
					'zoom' => isset( $it['zoom'] ) ? (int) $it['zoom'] : 0,
				);
			}
		}
		$out['areas'] = $areas;
		$regions = array();
		if ( isset( $raw['regions'] ) && is_array( $raw['regions'] ) ) {
			$src = $raw['regions'];
			// Accept either ['item'=>[...]] or plain list.
			if ( isset( $src['item'] ) && is_array( $src['item'] ) ) {
				$src = $src['item'];
			}
			foreach ( $src as $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}
				$aliases = array();
				if ( isset( $it['aliases'] ) && is_array( $it['aliases'] ) ) {
					$asrc = $it['aliases'];
					if ( isset( $asrc['item'] ) && is_array( $asrc['item'] ) ) {
						$asrc = $asrc['item'];
					}
					foreach ( $asrc as $a ) {
						if ( ! is_array( $a ) ) {
							continue;
						}
						$akey = isset( $a['key'] ) ? trim( (string) $a['key'] ) : '';
						$asyn = isset( $a['syn'] ) ? trim( (string) $a['syn'] ) : '';
						if ( $akey === '' || $asyn === '' ) {
							continue;
						}
						$aliases[] = array(
							'key' => $akey,
							'syn' => $asyn,
						);
					}
				}
				$regions[] = array(
					'key' => isset( $it['key'] ) ? trim( (string) $it['key'] ) : '',
					'label' => isset( $it['label'] ) ? trim( (string) $it['label'] ) : ( isset( $it['name'] ) ? trim( (string) $it['name'] ) : '' ),
					'country' => isset( $it['country'] ) ? trim( (string) $it['country'] ) : '',
					'level' => isset( $it['level'] ) ? trim( (string) $it['level'] ) : '',
					'group' => isset( $it['group'] ) ? trim( (string) $it['group'] ) : '',
					'cqp' => isset( $it['cqp'] ) ? trim( (string) $it['cqp'] ) : '',
					'provider' => isset( $it['provider'] ) ? trim( (string) $it['provider'] ) : '',
					'geometry' => isset( $it['geometry'] ) ? trim( (string) $it['geometry'] ) : '',
					'url' => isset( $it['url'] ) ? trim( (string) $it['url'] ) : '',
					'nameProperty' => isset( $it['nameProperty'] ) ? trim( (string) $it['nameProperty'] ) : '',
					'iso3' => isset( $it['iso3'] ) ? strtoupper( trim( (string) $it['iso3'] ) ) : '',
					'adm' => isset( $it['adm'] ) ? strtoupper( trim( (string) $it['adm'] ) ) : '',
					'aliases' => $aliases,
				);
			}
		}
		$out['regions'] = $regions;
		return $out;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_truthy' ) ) {
	/**
	 * Shared boolean coercion for TEITOK XML-ish config flags.
	 */
	function tt_flexicorp_fn_cfg_truthy( $value ) {
		if ( is_bool( $value ) ) return $value;
		if ( is_int( $value ) || is_float( $value ) ) return ( (float) $value ) !== 0.0;
		$s = strtolower( trim( (string) $value ) );
		if ( $s === '' ) return false;
		return ! in_array( $s, array( '0', 'false', 'no', 'off', 'none', 'null' ), true );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_is_attr_key' ) ) {
	/**
	 * Accept attribute ids like lemma, text_year, quote_region.
	 */
	function tt_flexicorp_fn_cfg_is_attr_key( $key ) {
		$key = trim( (string) $key );
		if ( $key === '' ) return false;
		if ( isset( $key[0] ) && $key[0] === '@' ) return false;
		return (bool) preg_match( '/^[A-Za-z][A-Za-z0-9_:-]*$/', $key );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_is_meta_attr_key' ) ) {
	/**
	 * Keys that are node metadata/config, not actual searchable attributes.
	 */
	function tt_flexicorp_fn_cfg_is_meta_attr_key( $key ) {
		$k = strtolower( trim( (string) $key ) );
		if ( $k === '' ) return true;
		$meta = array(
			'type',
			'types',
			'select',
			'translate',
			'display',
			'label',
			'name',
			'title',
			'help',
			'description',
			'options',
			'query',
			'value',
			'values',
			'default',
			'format',
			'separator',
			'prefix',
			'suffix',
			'sort',
			'order',
		);
		return in_array( $k, $meta, true );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_meta_label' ) ) {
	/**
	 * Prefer explicit display labels from settings, then fallback.
	 */
	function tt_flexicorp_fn_cfg_meta_label( $node, $fallback ) {
		if ( is_array( $node ) ) {
			foreach ( array( '@display', 'display', '@label', 'label', '@name', 'name' ) as $k ) {
				if ( array_key_exists( $k, $node ) ) {
					$v = trim( (string) $node[ $k ] );
					if ( $v !== '' ) return $v;
				}
			}
		}
		return trim( (string) $fallback );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_has_display_title' ) ) {
	/**
	 * Whether an attribute has explicit display metadata.
	 */
	function tt_flexicorp_fn_cfg_has_display_title( $node ) {
		if ( ! is_array( $node ) ) return false;
		foreach ( array( '@display', 'display' ) as $k ) {
			if ( array_key_exists( $k, $node ) && trim( (string) $node[ $k ] ) !== '' ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_is_admin_only' ) ) {
	function tt_flexicorp_fn_cfg_is_admin_only( $node ) {
		if ( ! is_array( $node ) ) return false;
		if ( array_key_exists( '@admin', $node ) && tt_flexicorp_fn_cfg_truthy( $node['@admin'] ) ) return true;
		if ( array_key_exists( 'admin', $node ) && tt_flexicorp_fn_cfg_truthy( $node['admin'] ) ) return true;
		return false;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_is_nosearch' ) ) {
	function tt_flexicorp_fn_cfg_is_nosearch( $node ) {
		if ( ! is_array( $node ) ) return false;
		if ( array_key_exists( '@nosearch', $node ) && tt_flexicorp_fn_cfg_truthy( $node['@nosearch'] ) ) return true;
		if ( array_key_exists( 'nosearch', $node ) && tt_flexicorp_fn_cfg_truthy( $node['nosearch'] ) ) return true;
		return false;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_cfg_is_noshow' ) ) {
	function tt_flexicorp_fn_cfg_is_noshow( $node ) {
		if ( ! is_array( $node ) ) return false;
		if ( array_key_exists( '@noshow', $node ) && tt_flexicorp_fn_cfg_truthy( $node['@noshow'] ) ) return true;
		if ( array_key_exists( 'noshow', $node ) && tt_flexicorp_fn_cfg_truthy( $node['noshow'] ) ) return true;
		return false;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ) {
	/**
	 * Canonical attribute catalog from TEITOK CQP settings.
	 *
	 * Returned keys:
	 * - searchable_display_labels: [field => label] (respects @admin + @nosearch + @display)
	 * - searchable_labels: [field => label] (respects @admin + @nosearch)
	 * - noshow_keys: [field, ...] (respects @admin)
	 * - field_meta: [field => { kind: token|documents|region, region?: string }]
	 */
	function tt_flexicorp_fn_attribute_catalog( $is_admin = null ) {
		$isAdmin = is_bool( $is_admin ) ? $is_admin : false;
		if ( $is_admin === null ) {
			$isAdmin = ! empty( $GLOBALS['isadmin'] ) || ! empty( $GLOBALS['admin'] );
		}
		$prefixSattr = false;
		if ( function_exists( 'getset' ) ) {
			$rawPref = getset( 'flexicorp/freq_prefix_sattr_display', null );
			if ( $rawPref === null || $rawPref === '' ) {
				$rawPref = getset( 'flexicorp/prefix_sattr_display', null );
			}
			$prefixSattr = tt_flexicorp_fn_cfg_truthy( $rawPref );
		}
		$pattrs = function_exists( 'getset' ) ? getset( 'cqp/pattributes', array() ) : array();
		$sattrs = function_exists( 'getset' ) ? getset( 'cqp/sattributes', array() ) : array();
		$searchableDisplay = array();
		$searchable = array();
		$noshow = array();
		$fieldMeta = array();
		$fieldDetails = array();
		$add = function ( $key, $node, $fallbackLabel, $kind = 'token', $region = '' ) use ( $isAdmin, &$searchableDisplay, &$searchable, &$noshow, &$fieldMeta, &$fieldDetails ) {
			$k = trim( (string) $key );
			if ( $k === '' ) return;
			$n = is_array( $node ) ? $node : array();
			$isAdminOnly = tt_flexicorp_fn_cfg_is_admin_only( $n );
			$isNoSearch = tt_flexicorp_fn_cfg_is_nosearch( $n );
			$isNoShow = tt_flexicorp_fn_cfg_is_noshow( $n );
			if ( $isAdminOnly && ! $isAdmin ) return;
			$label = tt_flexicorp_fn_cfg_meta_label( $n, $fallbackLabel );
			if ( ! $isNoSearch ) {
				$searchable[ $k ] = $label;
				if ( tt_flexicorp_fn_cfg_has_display_title( $n ) ) {
					$searchableDisplay[ $k ] = $label;
				}
			}
			if ( $isNoShow ) {
				$noshow[ $k ] = true;
			}
			$meta = array(
				'kind' => (string) $kind,
			);
			if ( $region !== '' ) $meta['region'] = (string) $region;
			$fieldMeta[ $k ] = $meta;
			$typeRaw = '';
			if ( array_key_exists( 'type', $n ) ) $typeRaw = trim( (string) $n['type'] );
			elseif ( array_key_exists( '@type', $n ) ) $typeRaw = trim( (string) $n['@type'] );
			$selectRaw = null;
			if ( array_key_exists( 'select', $n ) ) $selectRaw = $n['select'];
			elseif ( array_key_exists( '@select', $n ) ) $selectRaw = $n['@select'];
			$isSelect = tt_flexicorp_fn_cfg_truthy( $selectRaw );
			$translateRaw = '';
			if ( array_key_exists( 'translate', $n ) ) $translateRaw = trim( (string) $n['translate'] );
			elseif ( array_key_exists( '@translate', $n ) ) $translateRaw = trim( (string) $n['@translate'] );
			$isTranslate = tt_flexicorp_fn_cfg_truthy( $translateRaw );
			$typeNorm = (string) strtolower( $typeRaw );
			if ( $typeNorm === '' && $isSelect ) $typeNorm = 'select';
			$details = array(
				'kind' => (string) $kind,
				'region' => (string) $region,
				'label' => (string) $label,
				'type' => $typeNorm,
				'select' => (bool) $isSelect,
				'translate_flag' => (bool) $isTranslate,
				'admin' => (bool) $isAdminOnly,
				'nosearch' => (bool) $isNoSearch,
				'noshow' => (bool) $isNoShow,
				'translate' => (string) $translateRaw,
				'has_options' => false,
				'options' => array(),
			);
			if ( isset( $n['options'] ) && is_array( $n['options'] ) ) {
				$details['has_options'] = true;
				foreach ( $n['options'] as $optKey => $optNode ) {
					$ov = trim( (string) $optKey );
					if ( $ov === '' ) continue;
					$ol = $ov;
					if ( is_array( $optNode ) ) {
						$disp = trim( (string) ( $optNode['display'] ?? '' ) );
						if ( $disp !== '' ) $ol = $disp;
					}
					$details['options'][] = array(
						'value' => $ov,
						'label' => $ol,
					);
				}
			}
			$fieldDetails[ $k ] = $details;
		};

		if ( is_array( $pattrs ) ) {
			foreach ( $pattrs as $k => $v ) {
				if (
					is_string( $k )
					&& tt_flexicorp_fn_cfg_is_attr_key( $k )
					&& ! tt_flexicorp_fn_cfg_is_meta_attr_key( $k )
					&& is_array( $v )
				) {
					$add( $k, $v, $k, 'token', '' );
				}
			}
		}
		if ( is_array( $sattrs ) ) {
			foreach ( $sattrs as $regionKey => $regionNode ) {
				if ( ! is_string( $regionKey ) || ! tt_flexicorp_fn_cfg_is_attr_key( $regionKey ) || ! is_array( $regionNode ) ) continue;
				$regionLabel = tt_flexicorp_fn_cfg_meta_label( $regionNode, $regionKey );
				foreach ( $regionNode as $attrKey => $attrNode ) {
					if ( ! is_string( $attrKey ) ) continue;
					if ( ! tt_flexicorp_fn_cfg_is_attr_key( $attrKey ) ) continue;
					if ( tt_flexicorp_fn_cfg_is_meta_attr_key( $attrKey ) ) continue;
					if ( ! is_array( $attrNode ) ) continue;
					$composite = $regionKey . '_' . $attrKey;
					$attrLabel = tt_flexicorp_fn_cfg_meta_label( $attrNode, $attrKey );
					$label = $prefixSattr ? ( $regionLabel . ': ' . $attrLabel ) : $attrLabel;
					$kind = ( $regionKey === 'text' ) ? 'documents' : 'region';
					$add( $composite, $attrNode, $label, $kind, (string) $regionKey );
				}
			}
		}
		return array(
			'searchable_display_labels' => $searchableDisplay,
			'searchable_labels'         => $searchable,
			'noshow_keys'               => array_values( array_keys( $noshow ) ),
			'field_meta'                => $fieldMeta,
			'field_details'             => $fieldDetails,
		);
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_qb_field_node_lookup' ) ) {
	/**
	 * Resolve a query-builder field to its TEITOK CQP node.
	 *
	 * @return array{ok:bool,scope:string,node:array,key:string}
	 */
	function tt_flexicorp_fn_qb_field_node_lookup( $field ) {
		$f = trim( (string) $field );
		if ( $f === '' ) return array( 'ok' => false, 'scope' => '', 'node' => array(), 'key' => '' );
		$pattrs = function_exists( 'getset' ) ? getset( 'cqp/pattributes', array() ) : array();
		if ( is_array( $pattrs ) && isset( $pattrs[ $f ] ) && is_array( $pattrs[ $f ] ) ) {
			return array( 'ok' => true, 'scope' => 'pattr', 'node' => $pattrs[ $f ], 'key' => $f );
		}
		$sattrs = function_exists( 'getset' ) ? getset( 'cqp/sattributes', array() ) : array();
		if ( is_array( $sattrs ) && preg_match( '/^([^_]+)_(.+)$/', $f, $m ) ) {
			$region = (string) $m[1];
			$attr = (string) $m[2];
			if ( isset( $sattrs[ $region ] ) && is_array( $sattrs[ $region ] ) && isset( $sattrs[ $region ][ $attr ] ) && is_array( $sattrs[ $region ][ $attr ] ) ) {
				return array( 'ok' => true, 'scope' => 'sattr', 'node' => $sattrs[ $region ][ $attr ], 'key' => $f );
			}
		}
		return array( 'ok' => false, 'scope' => '', 'node' => array(), 'key' => '' );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_qb_candidate_value_files' ) ) {
	/**
	 * Candidate list files for a CQP field (lexicon/avs).
	 *
	 * @return array<int,string>
	 */
	function tt_flexicorp_fn_qb_candidate_value_files( $field, $scope, $project_root = '' ) {
		$f = trim( (string) $field );
		$root = rtrim( (string) $project_root, '/' );
		$cqpfolder = function_exists( 'getset' ) ? trim( (string) getset( 'cqp/cqpfolder', 'cqp' ) ) : 'cqp';
		if ( $cqpfolder === '' ) $cqpfolder = 'cqp';
		$dirs = array();
		if ( $root !== '' ) {
			$dirs[] = $root . '/cqp';
			$dirs[] = $root . '/' . $cqpfolder;
		}
		$dirs = array_values( array_unique( array_filter( $dirs ) ) );
		$files = array();
		$suffix = ( $scope === 'sattr' ) ? '.avs' : '.lexicon';
		foreach ( $dirs as $d ) {
			$files[] = $d . '/' . $f . $suffix;
		}
		// Pando indexes: <attr>.lex (positional) and <region>_<attr>.lex (region attributes) are
		// NUL-separated value lists like the CQP files, so pando-only corpora get value lists too.
		if ( $root !== '' ) {
			$pandoDir = function_exists( 'tt_flexicorp_pando_index_dir' ) ? (string) tt_flexicorp_pando_index_dir( $root ) : $root . '/pando';
			if ( $pandoDir !== '' && preg_match( '/^[A-Za-z0-9_#-]+$/', $f ) ) {
				$files[] = rtrim( $pandoDir, '/' ) . '/' . $f . '.lex';
			}
		}
		return $files;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_qb_primary_value_file' ) ) {
	/**
	 * Resolve first existing value file for a QB field.
	 *
	 * @return string
	 */
	function tt_flexicorp_fn_qb_primary_value_file( $field, $scope, $project_root = '' ) {
		$files = tt_flexicorp_fn_qb_candidate_value_files( $field, $scope, $project_root );
		foreach ( $files as $p ) {
			if ( is_string( $p ) && $p !== '' && is_file( $p ) && is_readable( $p ) ) return $p;
		}
		return '';
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_qb_values_from_cqp_file' ) ) {
	/**
	 * Read zero-separated CQP value files.
	 *
	 * @return array<int,string>
	 */
	function tt_flexicorp_fn_qb_values_from_cqp_file( $path, $max_items = 1000 ) {
		$out = array();
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return $out;
		$raw = @file_get_contents( $path );
		if ( ! is_string( $raw ) || $raw === '' ) return $out;
		$seen = array();
		foreach ( explode( "\0", $raw ) as $v ) {
			$vv = trim( (string) $v );
			if ( $vv === '' || isset( $seen[ $vv ] ) ) continue;
			$seen[ $vv ] = 1;
			$out[] = $vv;
			if ( count( $out ) >= max( 1, intval( $max_items ) ) ) break;
		}
		natcasesort( $out );
		return array_values( $out );
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_qb_field_value_payload' ) ) {
	/**
	 * Field value metadata for Query Builder.
	 *
	 * @return array{ok:bool,field:string,input:string,closed_list:bool,source:string,options:array<int,array{value:string,label:string}>}
	 */
	function tt_flexicorp_fn_qb_field_value_payload( $field, $project_root = '', $is_admin = null ) {
		$f = trim( (string) $field );
		$debugMode = false;
		if ( isset( $_REQUEST['qb_debug'] ) ) {
			$debugMode = tt_flexicorp_fn_cfg_truthy( (string) $_REQUEST['qb_debug'] );
		}
		$base = array(
			'ok' => true,
			'field' => $f,
			'input' => 'text',
			'closed_list' => false,
			'source' => '',
			'options' => array(),
		);
		if ( $f === '' ) return $base;
		$catalog = function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ? tt_flexicorp_fn_attribute_catalog( $is_admin ) : array();
		$fieldDetails = ( is_array( $catalog ) && isset( $catalog['field_details'] ) && is_array( $catalog['field_details'] ) )
			? $catalog['field_details']
			: array();
		$details = ( isset( $fieldDetails[ $f ] ) && is_array( $fieldDetails[ $f ] ) ) ? $fieldDetails[ $f ] : array();
		$type = strtolower( trim( (string) ( $details['type'] ?? '' ) ) );
		$isSelectFlag = ! empty( $details['select'] );
		$isTranslateFlag = ! empty( $details['translate_flag'] ) || tt_flexicorp_fn_cfg_truthy( (string) ( $details['translate'] ?? '' ) );
		$options = array();
		$source = '';
		if ( isset( $details['options'] ) && is_array( $details['options'] ) ) {
			foreach ( $details['options'] as $optRec ) {
				if ( ! is_array( $optRec ) ) continue;
				$v = trim( (string) ( $optRec['value'] ?? '' ) );
				if ( $v === '' ) continue;
				$lbl = trim( (string) ( $optRec['label'] ?? '' ) );
				$options[] = array( 'value' => $v, 'label' => ( $lbl !== '' ? $lbl : $v ) );
			}
			$source = 'settings-options';
		}
		$isClosedType =
			$isSelectFlag
			|| $isTranslateFlag
			|| in_array( $type, array( 'select', 'kselect', 'mainpos', 'dropdown', 'choice', 'enum' ), true )
			|| strpos( $type, 'select' ) !== false;
		$hit = tt_flexicorp_fn_qb_field_node_lookup( $f );
		$candidateFiles = array();
		if ( !$options && $isClosedType && $hit['ok'] ) {
			$files = tt_flexicorp_fn_qb_candidate_value_files( $f, (string) $hit['scope'], $project_root );
			$candidateFiles = $files;
			foreach ( $files as $cand ) {
				$vals = tt_flexicorp_fn_qb_values_from_cqp_file( $cand, 1000 );
				if ( $vals ) {
					foreach ( $vals as $vv ) $options[] = array( 'value' => $vv, 'label' => $vv );
					$source = 'cqp-file';
					break;
				}
			}
		}
		if ( $isClosedType || $options ) {
			$base['input'] = 'select';
			$base['closed_list'] = true;
			$base['options'] = array_values( $options );
			$base['source'] = $source;
		} elseif ( $type === 'udfeats' && $hit['ok'] ) {
			// UD features: one list of values per feature, for the expanded feature selects.
			$base['input'] = 'udfeats';
			$features = array();
			foreach ( tt_flexicorp_fn_qb_candidate_value_files( $f, (string) $hit['scope'], $project_root ) as $cand ) {
				if ( ! is_file( $cand ) || ! is_readable( $cand ) ) continue;
				$sz = @filesize( $cand );
				if ( $sz === false || $sz > 16 * 1024 * 1024 ) continue;
				$raw = @file_get_contents( $cand );
				if ( ! is_string( $raw ) || $raw === '' ) continue;
				foreach ( explode( "\0", $raw ) as $bundle ) {
					foreach ( explode( '|', trim( (string) $bundle ) ) as $pair ) {
						$eq = strpos( $pair, '=' );
						if ( $eq === false || $eq === 0 ) continue;
						$fn = substr( $pair, 0, $eq );
						$fv = substr( $pair, $eq + 1 );
						if ( $fv === '' || $fn === '_' ) continue;
						$features[ $fn ][ $fv ] = true;
					}
				}
				if ( $features ) {
					$source = 'value-file';
					break;
				}
			}
			ksort( $features, SORT_NATURAL | SORT_FLAG_CASE );
			$outFeatures = array();
			foreach ( $features as $fn => $vals ) {
				$list = array_keys( $vals );
				natcasesort( $list );
				$outFeatures[ (string) $fn ] = array_values( array_map( 'strval', $list ) );
			}
			$base['features'] = (object) $outFeatures;
			$base['source'] = $source;
		} elseif ( $hit['ok'] ) {
			// Small open value lists (upos, deprel, a language attribute, ...): offered as a datalist,
			// so the field still takes a regular expression but shows the values that exist.
			$maxItems = 100;
			foreach ( tt_flexicorp_fn_qb_candidate_value_files( $f, (string) $hit['scope'], $project_root ) as $cand ) {
				if ( ! is_file( $cand ) || ! is_readable( $cand ) ) continue;
				$sz = @filesize( $cand );
				if ( $sz === false || $sz > 64 * 1024 ) break;
				$vals = tt_flexicorp_fn_qb_values_from_cqp_file( $cand, $maxItems + 1 );
				if ( $vals && count( $vals ) <= $maxItems ) {
					$base['input'] = 'datalist';
					foreach ( $vals as $vv ) $base['options'][] = array( 'value' => $vv, 'label' => $vv );
					$base['source'] = 'value-file';
				}
				break;
			}
		}
		if ( $debugMode ) {
			$base['debug'] = array(
				'field' => $f,
				'catalog_has_field' => isset( $fieldDetails[ $f ] ),
				'field_details' => $details,
				'type' => $type,
				'select_flag' => $isSelectFlag,
				'translate_flag' => $isTranslateFlag,
				'is_closed_type' => $isClosedType,
				'node_lookup' => array(
					'ok' => !empty( $hit['ok'] ),
					'scope' => (string) ( $hit['scope'] ?? '' ),
					'node_keys' => ( isset( $hit['node'] ) && is_array( $hit['node'] ) ) ? array_values( array_keys( $hit['node'] ) ) : array(),
					'node_type' => ( isset( $hit['node']['type'] ) ? (string) $hit['node']['type'] : '' ),
					'node_select' => ( isset( $hit['node']['select'] ) ? (string) $hit['node']['select'] : '' ),
					'node_translate' => ( isset( $hit['node']['translate'] ) ? (string) $hit['node']['translate'] : '' ),
				),
				'candidate_files' => $candidateFiles,
				'resolved_input' => (string) $base['input'],
				'resolved_source' => (string) $base['source'],
				'option_count' => is_array( $base['options'] ) ? count( $base['options'] ) : 0,
			);
		}
		return $base;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_qb_suggest_from_value_file' ) ) {
	/**
	 * Prefix suggestions from zero-separated CQP value files.
	 *
	 * @return array{items:array<int,array{value:string,label:string}>,scanned:int,stopped_early:bool}
	 */
	function tt_flexicorp_fn_qb_suggest_from_value_file( $path, $prefix, $limit = 30, $max_scan_bytes = 6291456 ) {
		$out = array();
		$seen = array();
		$scan = 0;
		$stopped = false;
		$pref = mb_strtolower( (string) $prefix, 'UTF-8' );
		$pl = mb_strlen( $pref, 'UTF-8' );
		if ( ! is_file( $path ) || ! is_readable( $path ) || $pl < 1 ) {
			return array( 'items' => $out, 'scanned' => 0, 'stopped_early' => false );
		}
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) return array( 'items' => $out, 'scanned' => 0, 'stopped_early' => false );
		$buf = '';
		$hardLimit = max( 1, intval( $limit ) );
		$scanLimit = max( 1024, intval( $max_scan_bytes ) );
		try {
			while ( ! feof( $fh ) ) {
				$chunk = fread( $fh, 65536 );
				if ( ! is_string( $chunk ) || $chunk === '' ) break;
				$scan += strlen( $chunk );
				$buf .= $chunk;
				$parts = explode( "\0", $buf );
				$buf = array_pop( $parts );
				foreach ( $parts as $raw ) {
					$v = trim( (string) $raw );
					if ( $v === '' || isset( $seen[ $v ] ) ) continue;
					$lv = mb_strtolower( $v, 'UTF-8' );
					if ( mb_substr( $lv, 0, $pl, 'UTF-8' ) !== $pref ) continue;
					$seen[ $v ] = 1;
					$out[] = array( 'value' => $v, 'label' => $v );
					if ( count( $out ) >= $hardLimit ) {
						$stopped = true;
						break 2;
					}
				}
				if ( $scan >= $scanLimit ) {
					$stopped = true;
					break;
				}
			}
		} finally {
			fclose( $fh );
		}
		natcasesort( $out );
		return array(
			'items' => array_values( $out ),
			'scanned' => $scan,
			'stopped_early' => (bool) $stopped,
		);
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_qb_field_suggestions_payload' ) ) {
	/**
	 * Suggest values for free-text QB input (simple prefix mode).
	 *
	 * @return array
	 */
	function tt_flexicorp_fn_qb_field_suggestions_payload( $field, $query, $project_root = '', $is_admin = null, $limit = 30 ) {
		$f = trim( (string) $field );
		$q = trim( (string) $query );
		$base = array(
			'ok' => true,
			'field' => $f,
			'query' => $q,
			'enabled' => false,
			'reason' => '',
			'items' => array(),
		);
		if ( $f === '' ) {
			$base['reason'] = 'missing-field';
			return $base;
		}
		// Smart disable: keep autocomplete off for regex-ish query fragments.
		if ( preg_match( '/[\\[\\](){}|*+?\\\\]/', $q ) ) {
			$base['reason'] = 'regex-fragment';
			return $base;
		}
		if ( mb_strlen( $q, 'UTF-8' ) < 2 ) {
			$base['reason'] = 'query-too-short';
			return $base;
		}
		$catalog = function_exists( 'tt_flexicorp_fn_attribute_catalog' ) ? tt_flexicorp_fn_attribute_catalog( $is_admin ) : array();
		$fieldDetails = ( is_array( $catalog ) && isset( $catalog['field_details'] ) && is_array( $catalog['field_details'] ) ) ? $catalog['field_details'] : array();
		$details = ( isset( $fieldDetails[ $f ] ) && is_array( $fieldDetails[ $f ] ) ) ? $fieldDetails[ $f ] : array();
		$type = strtolower( trim( (string) ( $details['type'] ?? '' ) ) );
		$isClosed = !empty( $details['select'] ) || !empty( $details['translate_flag'] ) || strpos( $type, 'select' ) !== false || in_array( $type, array( 'mainpos', 'dropdown', 'choice', 'enum' ), true );
		if ( $isClosed ) {
			$base['reason'] = 'closed-list-field';
			return $base;
		}
		$hit = tt_flexicorp_fn_qb_field_node_lookup( $f );
		if ( empty( $hit['ok'] ) ) {
			$base['reason'] = 'unknown-field';
			return $base;
		}
		$path = tt_flexicorp_fn_qb_primary_value_file( $f, (string) $hit['scope'], $project_root );
		if ( $path === '' ) {
			$base['reason'] = 'value-file-missing';
			return $base;
		}
		$size = @filesize( $path );
		$maxLexiconBytes = intval( function_exists( 'getset' ) ? getset( 'flexicorp/qb_autosuggest_max_lexicon_bytes', 100 * 1024 * 1024 ) : 100 * 1024 * 1024 );
		if ( $size !== false && $size > $maxLexiconBytes ) {
			$base['reason'] = 'lexicon-too-large';
			$base['file_size'] = (int) $size;
			$base['size_limit'] = (int) $maxLexiconBytes;
			return $base;
		}
		$scanCap = intval( function_exists( 'getset' ) ? getset( 'flexicorp/qb_autosuggest_max_scan_bytes', 6 * 1024 * 1024 ) : 6 * 1024 * 1024 );
		$sugg = tt_flexicorp_fn_qb_suggest_from_value_file( $path, $q, max( 1, intval( $limit ) ), max( 1024, $scanCap ) );
		$base['enabled'] = true;
		$base['reason'] = 'ok';
		$base['items'] = is_array( $sugg['items'] ) ? $sugg['items'] : array();
		$base['truncated'] = !empty( $sugg['stopped_early'] );
		$base['scanned_bytes'] = intval( $sugg['scanned'] ?? 0 );
		return $base;
	}
}

if ( ! function_exists( 'tt_flexicorp_fn_advanced_freqs_bootstrap_context' ) ) {
	/**
	 * Merge REQUEST with sticky session selection and capability hints for advanced_freqs.php.
	 *
	 * @param array $request Typically $_REQUEST.
	 * @return array{backend:string,query_language:string,corpus_format:string,query_engine:string,base_query:string,named_query_sources:array,capabilities:array{hasGeo:bool},geo_map:array}
	 */
	function tt_flexicorp_fn_advanced_freqs_bootstrap_context( $request ) {
		if ( ! is_array( $request ) ) {
			$request = array();
		}
		$sticky = tt_flexicorp_fn_read_sticky_flexicorp_selection();

		$backend = tt_flexicorp_fn_get_string( $request, 'backend', '' );
		if ( $backend === '' && $sticky['backend'] !== '' ) {
			$backend = $sticky['backend'];
		}

		$query_language = tt_flexicorp_fn_get_string( $request, 'query_language', '' );
		if ( $query_language === '' && $sticky['query_language'] !== '' ) {
			$query_language = $sticky['query_language'];
		}

		$corpus_format = tt_flexicorp_fn_get_string( $request, 'corpus_format', '' );
		if ( $corpus_format === '' && $sticky['corpus_format'] !== '' ) {
			$corpus_format = $sticky['corpus_format'];
		}

		$query_engine = tt_flexicorp_fn_get_string( $request, 'query_engine', '' );
		if ( $query_engine === '' && $sticky['query_engine'] !== '' ) {
			$query_engine = $sticky['query_engine'];
		}

		$req_geo = tt_flexicorp_fn_get_string( $request, 'has_geo', '' );
		$has_geo_req = in_array( strtolower( $req_geo ), array( '1', 'true', 'yes', 'on' ), true );
		$has_geo = $has_geo_req || tt_flexicorp_fn_teitok_geo_capability_hint();

		$base_query = tt_flexicorp_fn_get_string( $request, 'query', '' );
		if ( $base_query === '' ) {
			$base_query = tt_flexicorp_fn_read_session_last_search_query();
		}

		$geo_hints = tt_flexicorp_fn_geo_map_field_hints();
		$geo_default_agg = tt_flexicorp_fn_geo_map_default_aggregation( $geo_hints );
		$geo_runtime = tt_flexicorp_fn_geo_map_runtime_settings();

		$named_query_sources = function_exists( 'tt_flexicorp_fn_named_query_sources_bootstrap' )
			? tt_flexicorp_fn_named_query_sources_bootstrap( $query_language )
			: array(
				'last'   => array(),
				'recent' => array(),
				'stored' => array(),
			);

		return array(
			'backend'         => $backend,
			'query_language'  => $query_language,
			'corpus_format'   => $corpus_format,
			'query_engine'    => $query_engine,
			'base_query'      => $base_query,
			'named_query_sources' => $named_query_sources,
			'capabilities'    => array(
				'hasGeo' => (bool) $has_geo,
			),
			'geo_map'           => array(
				'hints'             => $geo_hints,
				'defaultAggregation'=> $geo_default_agg,
				'settings'          => $geo_runtime,
				/*
				 * Conceptual parity with ../easycorp/teitok/ode/Sources/cqp-new.php:
				 * - point layer: lat/lon from a coordinate s-attribute (e.g. text_geo),
				 * - choropleth / bars: group Matches vs group All by a region s-attribute (e.g. text_province).
				 * Execution will go through flexicorp query/freq pipelines — not hard-coded Spanish provinces.
				 */
				'sourceNote'        => 'cqp-new.php',
			),
		);
	}
}

