<?php
/**
 * fqs-lib.php — what TEITOK modules share about a project's entry in the FQS catalogue:
 * the registration payload (fqs.php's "Register this corpus", and flexicorp.php when it
 * indexes a corpus), the project's URL and description, and registering / updating the
 * entry. Only functions: opened as an action it shows nothing.
 */

	if ( !function_exists("getBaseUrl") ) {
		function getBaseUrl() {
			$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
			$host = $_SERVER['HTTP_HOST'];
			$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
			
			return $protocol . '://' . $host . $scriptDir."/";
		};
	};

	/**
	 * Stable corpus id for FQS: last path segment of the TEITOK project directory, sanitized.
	 */
	if ( !function_exists('tt_fqs_corpus_id_from_root') ) {
		function tt_fqs_corpus_id_from_root( $projectRoot ) {
			$projectRoot = str_replace('\\', '/', (string) $projectRoot);
			$base = basename(rtrim($projectRoot, '/'));
			$base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $base);
			$base = trim($base, '_');
			return $base !== '' ? $base : 'corpus';
		}
	}

	/**
	 * Build one CorpusEntry-shaped object for fqs corpora upsert-json (see fqs README / Rust CorpusEntry).
	 */
	if ( !function_exists('tt_fqs_build_registration_payload') ) {
		function tt_fqs_build_registration_payload( $projectRoot, $projectUrl ) {
			$projectRoot = realpath($projectRoot);
			if ( $projectRoot === false || !is_dir($projectRoot) ) {
				return array( 'error' => 'Invalid project root' );
			}
			$hasPando = is_dir($projectRoot . DIRECTORY_SEPARATOR . 'pando');
			$hasCqp = is_dir($projectRoot . DIRECTORY_SEPARATOR . 'cqp');
			$xidx = $projectRoot . DIRECTORY_SEPARATOR . 'xidx' . DIRECTORY_SEPARATOR . 'xidx.rng';
			$hasXidx = is_file($xidx);

			$foldername = basename( str_replace( '\\', '/', rtrim( (string) $projectRoot, '/' ) ) );
			$id = tt_fqs_corpus_id_from_root( $projectRoot );
			$label = $foldername;
			if ( function_exists( 'getset' ) ) {
				$label = trim( (string) getset( 'defaults/title/display', $foldername ) );
			}
			if ( $label === '' ) {
				$label = $id;
			}

			$settings = array(
				'teitok_project_root' => $projectRoot,
			);
			if ( function_exists( 'findapp' ) ) {
				$pandoCli = trim( (string) findapp( 'flexicorp-pando' ) );
				if ( $pandoCli !== '' ) {
					$settings['pando_cli'] = $pandoCli;
				}
			}
			if ( $hasCqp && function_exists('getset') ) {
				$corp = trim((string) getset('cqp/corpus', ''));
				if ( $corp !== '' ) {
					$settings['corpus_name'] = strtolower($corp);
				}
				$reg = trim((string) getset('cqp/registry', ''));
				if ( $reg !== '' ) {
					$settings['registry_hint'] = $reg;
				}
			}
			if ( $hasPando && !$hasCqp ) {
				$settings['query_backend'] = 'pando';
			} elseif ( $hasCqp && !$hasPando ) {
				$settings['query_backend'] = 'cqp';
			}

			$availableBackends = array();
			if ( $hasPando ) {
				$availableBackends[] = 'pando';
			}
			if ( $hasCqp ) {
				$availableBackends[] = 'cqp';
			}
			if ( !empty( $availableBackends ) ) {
				$settings['available_backends'] = $availableBackends;
			}
			$languages = tt_fqs_detect_project_languages( $projectRoot );
			if ( !empty( $languages ) ) {
				$settings['languages'] = $languages;
			}
			// the corpus description the project wrote (Pages/description.html), so that
			// corpus lists elsewhere (FCS, KonText, other servers) can show it too
			foreach ( array( 'description.html', 'description-en.html', 'description.md' ) as $df ) {
				$dp = $projectRoot . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . $df;
				if ( ! is_file( $dp ) ) continue;
				$dt = html_entity_decode( strip_tags( (string) file_get_contents( $dp ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$dt = trim( preg_replace( '/\s+/u', ' ', $dt ) );
				if ( $dt !== '' ) {
					$settings['description'] = $dt;
					break;
				}
			}
			$uiLanguages = tt_fqs_detect_interface_languages( $projectRoot );
			if ( !empty( $uiLanguages ) ) {
				$settings['ui_languages'] = $uiLanguages;
			}
			$settings['kontext'] = array(
				'enabled' => (bool) $hasCqp,
				'public' => false,
				'corpus_id' => $id,
			);
			$settings['pmltq'] = array(
				'enabled' => false,
				'public' => false,
				'treebank' => $id,
			);

			$capabilities = array(
				'teitok_integration' => true,
				'fcs' => array(
					'enabled' => true,
					'resource_pid' => 'local:' . $id,
					'supports_dataviews' => array( 'hits' ),
					'languages' => ! empty( $languages ) ? array_values( $languages ) : array(),
				),
			);

			$labels = array();
			foreach ( $languages as $lc ) {
				$lc = strtolower( trim( (string) $lc ) );
				if ( $lc === '' || $lc === 'und' ) continue;
				$labels[] = 'lang:' . $lc;
			}
			// Cheap folder heuristics only; full langs/features come from
			// post-registration `fqs corpora validate … --enrich` (see enrich.rs).
			// Never treat Pages/ as facsimile — that is TEITOK site PHP/HTML.
			if ( is_dir( $projectRoot . DIRECTORY_SEPARATOR . 'Audio' )
				|| is_dir( $projectRoot . DIRECTORY_SEPARATOR . 'audio' )
				|| is_dir( $projectRoot . DIRECTORY_SEPARATOR . 'Media' ) ) {
				$labels[] = 'feature:spoken';
			}
			if ( is_dir( $projectRoot . DIRECTORY_SEPARATOR . 'Facsimile' )
				|| is_dir( $projectRoot . DIRECTORY_SEPARATOR . 'facsimile' ) ) {
				$labels[] = 'feature:facsimile';
			}
			if ( is_dir( $projectRoot . DIRECTORY_SEPARATOR . 'Video' )
				|| is_dir( $projectRoot . DIRECTORY_SEPARATOR . 'video' ) ) {
				$labels[] = 'feature:video';
			}

			return array(
				'id' => $id,
				'label' => $label,
				'project_root' => $projectRoot,
				'project_url' => $projectUrl !== '' ? $projectUrl : null,
				'preferred_backend' => 'auto',
				'environment' => 'live',
				'visibility' => 'published',
				'listing_visibility' => 'public',
				'source_kind' => 'teitok',
				'supports_xml' => (bool) ( $hasCqp || $hasXidx ),
				'interface_preference' => 'teitok',
				'http_policy_mode' => 'public_query',
				'http_allowed_operations' => array( 'query', 'catalog' ),
				'interfaces' => array( 'query' ),
				'labels' => array_values( array_unique( $labels ) ),
				'capabilities' => $capabilities,
				'settings' => $settings,
				'is_current' => true,
			);
		}
	}

	/**
	 * Which query backends exist under a TEITOK project directory (pando / cqp folders).
	 */
	if ( !function_exists( 'tt_fqs_detect_backends_on_disk' ) ) {
		function tt_fqs_detect_backends_on_disk( $projectRoot ) {
			$out = array();
			$root = rtrim( str_replace( '\\', '/', (string) $projectRoot ), '/' );
			if ( $root === '' ) {
				return $out;
			}
			if ( is_dir( $root . '/pando' ) ) {
				$out[] = 'pando';
			}
			if ( is_dir( $root . '/cqp' ) ) {
				$out[] = 'cqp';
			}
			return $out;
		}
	}

	/**
	 * Ensure settings is a mutable PHP array (FQS JSON object).
	 */
	if ( !function_exists( 'tt_fqs_entry_settings_array' ) ) {
		function tt_fqs_entry_settings_array( array &$entry ) {
			if ( !isset( $entry['settings'] ) || !is_array( $entry['settings'] ) ) {
				$entry['settings'] = array();
			}
			return $entry['settings'];
		}
	}

	/**
	 * Ensure capabilities is a mutable PHP array (FQS JSON object).
	 */
	if ( !function_exists( 'tt_fqs_entry_capabilities_array' ) ) {
		function tt_fqs_entry_capabilities_array( array &$entry ) {
			if ( !isset( $entry['capabilities'] ) || !is_array( $entry['capabilities'] ) ) {
				$entry['capabilities'] = array();
			}
			return $entry['capabilities'];
		}
	}

	if ( !function_exists( 'tt_fqs_parse_language_codes' ) ) {
		function tt_fqs_parse_language_codes( $raw ) {
			$out = array();
			if ( is_array( $raw ) ) {
				$parts = $raw;
			} else {
				$txt = trim( (string) $raw );
				if ( $txt === '' ) {
					return $out;
				}
				$parts = preg_split( '/[\s,;|]+/', $txt );
			}
			if ( !is_array( $parts ) ) {
				return $out;
			}
			foreach ( $parts as $part ) {
				$code = strtolower( trim( (string) $part ) );
				if ( $code === '' ) continue;
				$code = preg_replace( '/[^a-z0-9_-]+/', '', $code );
				if ( $code === '' ) continue;
				if ( !in_array( $code, $out, true ) ) {
					$out[] = $code;
				}
			}
			return $out;
		}
	}

	if ( !function_exists( 'tt_fqs_detect_project_languages' ) ) {
		function tt_fqs_detect_project_languages( $projectRoot ) {
			$langs = array();
			$add = function( $value ) use ( &$langs ) {
				$parsed = tt_fqs_parse_language_codes( $value );
				foreach ( $parsed as $code ) {
					if ( !in_array( $code, $langs, true ) ) {
						$langs[] = $code;
					}
				}
			};

			if ( function_exists( 'getset' ) ) {
				$add( getset( 'defaults/lang', '' ) );
				$add( getset( 'defaults/language', '' ) );
			}

			$settingsXml = rtrim( (string) $projectRoot, '/\\' ) . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'settings.xml';
			if ( is_file( $settingsXml ) && function_exists( 'simplexml_load_file' ) ) {
				$xml = @simplexml_load_file( $settingsXml );
				if ( $xml !== false ) {
					if ( isset( $xml->defaults ) ) {
						$attrs = $xml->defaults->attributes();
						if ( isset( $attrs['lang'] ) ) {
							$add( (string) $attrs['lang'] );
						}
						if ( isset( $attrs['language'] ) ) {
							$add( (string) $attrs['language'] );
						}
					}
				}
			}

			return $langs;
		}
	}

	/**
	 * Detect TEITOK UI/interface languages (i18n), not corpus content languages.
	 */
	if ( !function_exists( 'tt_fqs_detect_interface_languages' ) ) {
		function tt_fqs_detect_interface_languages( $projectRoot ) {
			$langs = array();
			$add = function( $value ) use ( &$langs ) {
				$parsed = tt_fqs_parse_language_codes( $value );
				foreach ( $parsed as $code ) {
					if ( !in_array( $code, $langs, true ) ) {
						$langs[] = $code;
					}
				}
			};
			$settingsXml = rtrim( (string) $projectRoot, '/\\' ) . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'settings.xml';
			if ( !is_file( $settingsXml ) || !function_exists( 'simplexml_load_file' ) ) {
				return $langs;
			}
			$xml = @simplexml_load_file( $settingsXml );
			if ( $xml === false || !isset( $xml->languages ) ) {
				return $langs;
			}
			$lAttrs = $xml->languages->attributes();
			if ( isset( $lAttrs['default'] ) ) {
				$add( (string) $lAttrs['default'] );
			}
			if ( isset( $xml->languages->options->item ) ) {
				foreach ( $xml->languages->options->item as $it ) {
					$itAttrs = $it->attributes();
					if ( isset( $itAttrs['key'] ) ) {
						$add( (string) $itAttrs['key'] );
					}
				}
			}
			return $langs;
		}
	}

	/**
	 * True when this catalogue row belongs to the TEITOK instance (same folder or same base URL).
	 */
	if ( !function_exists( 'tt_fqs_corpus_is_current_project' ) ) {
		function tt_fqs_corpus_is_current_project( $entry ) {
			if ( !is_array( $entry ) ) {
				return false;
			}
			$cwd = getcwd();
			if ( $cwd !== false && isset( $entry['project_root'] ) ) {
				$pr = $entry['project_root'];
				$rp = @realpath( (string) $pr );
				$rc = @realpath( (string) $cwd );
				if ( $rp !== false && $rc !== false && $rp === $rc ) {
					return true;
				}
			}
			global $baseurl;
			if ( isset( $baseurl, $entry['project_url'] ) ) {
				$pu = trim( (string) $entry['project_url'] );
				if ( $pu !== '' && rtrim( $pu, '/' ) === rtrim( (string) $baseurl, '/' ) ) {
					return true;
				}
			}
			return false;
		}
	}

	/** The project's URL as the server sees it (/teitok/<project>/index.php). Corpus
	 * lists turn it into a relative link, which also works behind a path-prefix proxy. */
	if ( !function_exists( 'tt_fqs_project_url' ) ) {
		function tt_fqs_project_url() {
			$sn = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
			if ( $sn === '' ) return '';
			return rtrim( str_replace( '\\', '/', dirname( $sn ) ), '/' ) . '/index.php';
		}
	}

	/** The project's own description for corpus lists (Pages/description.html, in the
	 * default language or any), as plain text; '' when there is none. */
	if ( !function_exists( 'tt_fqs_project_description' ) ) {
		function tt_fqs_project_description( $projectRoot ) {
			$root = rtrim( (string) $projectRoot, '/' );
			$files = array_merge( array( "$root/Pages/description.html", "$root/Pages/description.md" ),
				(array) glob( "$root/Pages/description-*.html" ) );
			foreach ( $files as $f ) {
				if ( ! is_string( $f ) || ! is_file( $f ) ) continue;
				$t = html_entity_decode( strip_tags( (string) file_get_contents( $f ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$t = trim( preg_replace( '/\s+/u', ' ', $t ) );
				if ( $t !== '' ) return $t;
			}
			return '';
		}
	}

	/** Write the project's description (Pages/description.html) from plain text. */
	if ( !function_exists( 'tt_fqs_save_project_description' ) ) {
		function tt_fqs_save_project_description( $projectRoot, $text ) {
			$text = trim( (string) $text );
			if ( $text === '' ) return false;
			$dir = rtrim( (string) $projectRoot, '/' ) . '/Pages';
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0775, true ) ) return false;
			$html = '';
			foreach ( preg_split( '/\R\s*\R/u', $text ) as $para ) {
				$para = trim( $para );
				if ( $para !== '' ) $html .= '<p>' . htmlspecialchars( $para, ENT_QUOTES, 'UTF-8' ) . "</p>\n";
			}
			return @file_put_contents( "$dir/description.html", $html ) !== false;
		}
	}

	if ( !function_exists( 'tt_fqs_cli' ) ) {
		function tt_fqs_cli() {
			$bin = function_exists( 'findapp' ) ? (string) findapp( 'fqs' ) : '';
			if ( $bin === '' && is_executable( '/usr/local/bin/fqs' ) ) $bin = '/usr/local/bin/fqs';
			return $bin;
		}
	}

	/**
	 * Register the current TEITOK project in FQS, or bring its entry up to date: title,
	 * URL, description, languages, backends. FQS keeps what the admin chose (FCS on/off,
	 * KonText name) and fields this payload leaves out. Returns array( ok, id, error ).
	 */
	if ( !function_exists( 'tt_fqs_register_project' ) ) {
		function tt_fqs_register_project( $projectRoot = null ) {
			$projectRoot = $projectRoot !== null ? $projectRoot : getcwd();
			$payload = tt_fqs_build_registration_payload( $projectRoot, tt_fqs_project_url() );
			if ( isset( $payload['error'] ) ) return array( 'ok' => false, 'id' => '', 'error' => $payload['error'] );
			$bin = tt_fqs_cli();
			if ( $bin === '' ) return array( 'ok' => false, 'id' => $payload['id'], 'error' => 'fqs CLI not found' );
			$tmp = tempnam( sys_get_temp_dir(), 'fqsreg' );
			if ( $tmp === false ) return array( 'ok' => false, 'id' => $payload['id'], 'error' => 'no temp file' );
			file_put_contents( $tmp, json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$out = (string) shell_exec( escapeshellarg( $bin ) . ' corpora upsert-json --json-file ' . escapeshellarg( $tmp ) . ' 2>&1' );
			@unlink( $tmp );
			$dec = json_decode( $out, true );
			if ( is_array( $dec ) && ! empty( $dec['ok'] ) ) return array( 'ok' => true, 'id' => $payload['id'], 'error' => '' );
			$last = trim( (string) preg_replace( '/^.*\n/s', '', trim( $out ) ) );
			return array( 'ok' => false, 'id' => $payload['id'], 'error' => $last !== '' ? $last : 'upsert failed' );
		}
	}
?>
