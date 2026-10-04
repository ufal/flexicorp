<?php

	$maintext .= "<h1>Available Corpora</h1>";

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

	if ( $act == "admin") {
		check_login();
		if ( empty( $isshared ) ) {
			$maintext .= "<p class=warning>Admin mode is intended for the shared/global corpus folder.</p>";
			$maintext .= "<p><a href='index.php?action=$action'>back to list</a></p>";
		} else {
			$fqsapp = findapp("fqs");
			$listRaw = shell_exec( escapeshellarg( $fqsapp ) . " corpora list 2>&1" );
			$listDecoded = is_string( $listRaw ) ? json_decode( $listRaw, true ) : null;
			$corpora = array();
			if ( is_array( $listDecoded ) ) {
				if ( isset( $listDecoded['corpora'] ) && is_array( $listDecoded['corpora'] ) ) {
					$corpora = $listDecoded['corpora'];
				} else {
					$corpora = $listDecoded;
				}
			}
			$fcsEnabled = array();
			foreach ( $corpora as $row ) {
				if ( !is_array( $row ) ) continue;
				$fcs = isset( $row['capabilities']['fcs'] ) && is_array( $row['capabilities']['fcs'] ) ? $row['capabilities']['fcs'] : array();
				if ( !empty( $fcs['enabled'] ) ) {
					$fcsEnabled[] = $row;
				}
			}

			$statusCmd = escapeshellarg( $fqsapp ) . " status 2>&1";
			$statusRaw = shell_exec( $statusCmd );

			$maintext .= "<h2>Global FQS administration</h2>";
			$maintext .= "<p><small>Shared-folder mode: manage server-wide corpus catalogue, including FCS exposure.</small></p>";
			if ( function_exists( 'getset' ) && trim( (string) getset( 'flexicorp/fqs_admin_users', '' ) ) !== '' ) {
				// the full FQS admin UI through TEITOK's login (fqsadmin.php signs the API calls)
				$maintext .= "<p><a href='index.php?action=fqsadmin' target='_blank' rel='noopener'>Open the FQS admin interface</a></p>";
			}
			$maintext .= "<table cellpadding='6' style='margin-bottom:1em'>"
				. "<tr><th align='left'>Registered corpora</th><td>" . htmlspecialchars( (string) count( $corpora ), ENT_QUOTES, 'UTF-8' ) . "</td></tr>"
				. "<tr><th align='left'>FCS enabled</th><td>" . htmlspecialchars( (string) count( $fcsEnabled ), ENT_QUOTES, 'UTF-8' ) . "</td></tr>"
				. "</table>";

			$maintext .= "<h3>FCS-enabled corpora</h3>";
			if ( empty( $fcsEnabled ) ) {
				$maintext .= "<p><em>No corpora currently marked as FCS-enabled.</em></p>";
			} else {
				$maintext .= "<table><tr><th>ID<th>Label<th>Resource PID<th>Data views<th>Languages<th></tr>";
				foreach ( $fcsEnabled as $row ) {
					$id = isset( $row['id'] ) ? (string) $row['id'] : '';
					$label = isset( $row['label'] ) ? (string) $row['label'] : $id;
					$fcs = isset( $row['capabilities']['fcs'] ) && is_array( $row['capabilities']['fcs'] ) ? $row['capabilities']['fcs'] : array();
					$pid = isset( $fcs['resource_pid'] ) ? (string) $fcs['resource_pid'] : 'local:' . $id;
					$views = isset( $fcs['supports_dataviews'] ) && is_array( $fcs['supports_dataviews'] ) ? implode( ', ', $fcs['supports_dataviews'] ) : 'hits';
					$langList = isset( $fcs['languages'] ) && is_array( $fcs['languages'] ) ? $fcs['languages'] : array();
					$langList = array_values( array_filter( array_map( 'strval', $langList ), function ( $lc ) {
						$lc = strtolower( trim( $lc ) );
						return $lc !== '' && ! in_array( $lc, array( 'und', 'unk', 'unknown', 'zxx' ), true );
					} ) );
					$langs = empty( $langList ) ? '—' : implode( ', ', $langList );
					$edit = "index.php?action=" . rawurlencode( (string) $action ) . "&amp;act=edit&amp;id=" . rawurlencode( $id );
					$maintext .= "<tr><td><a href='" . $edit . "'>" . htmlspecialchars( $id, ENT_QUOTES, 'UTF-8' ) . "</a></td>"
						. "<td>" . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . "</td>"
						. "<td><code>" . htmlspecialchars( $pid, ENT_QUOTES, 'UTF-8' ) . "</code></td>"
						. "<td>" . htmlspecialchars( $views, ENT_QUOTES, 'UTF-8' ) . "</td>"
						. "<td>" . htmlspecialchars( $langs, ENT_QUOTES, 'UTF-8' ) . "</td></tr>";
				}
				$maintext .= "</table>";
			}

			$maintext .= "<h3>Global checks</h3>";
			$maintext .= "<p><small><code>" . htmlspecialchars( $statusCmd, ENT_QUOTES, 'UTF-8' ) . "</code></small></p>";
			$maintext .= "<pre style='white-space:pre-wrap;max-height:24em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>"
				. htmlspecialchars( (string) $statusRaw, ENT_QUOTES, 'UTF-8' ) . "</pre>";
			$maintext .= "<p><a href='index.php?action=$action'>back to list</a></p>";
		}
	} else if ( $act == "addcorpus") {
		check_login();

		$projectRoot = getcwd();
		$projectUrl = isset($baseurl) ? rtrim((string) $baseurl, '/') . '/' : '';

		$payload = tt_fqs_build_registration_payload( $projectRoot, $projectUrl );
		if ( isset($payload['error']) ) {
			$maintext .= "<p class=warning>Cannot register: " . htmlspecialchars($payload['error']) . "</p>";
			$maintext .= "<p><a href='index.php?action=$action'>back to list</a></p>";
		} else {
			$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

			if ( !$confirm ) {
				$jsonPreview = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
				$maintext .= "<h2>Register this corpus in FQS</h2>";
				$maintext .= "<p>This will add the current corpus to the Flexicorp Query Service, which serves as the central server-wide corpus registry. "
					. "Review the data, then confirm.</p>";
				$maintext .= "<pre style='max-height:24em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>" 
					. htmlspecialchars($jsonPreview) . "</pre>";
				$maintext .= "<p><a class='button' href='index.php?action=$action&amp;act=addcorpus&amp;confirm=1'>Confirm registration</a> "
					. "&nbsp; <a href='index.php?action=$action'>Cancel</a></p>";
			} else {
				$fqsapp = findapp("fqs");
				$tmp = @tempnam( sys_get_temp_dir(), 'fqsreg' );
				if ( $tmp === false ) {
					$maintext .= "<p class=warning>Could not create temp file.</p>";
				} else {
					file_put_contents( $tmp, json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
					$cmd = escapeshellarg( $fqsapp ) . ' corpora upsert-json --json-file ' . escapeshellarg( $tmp ) . ' 2>&1';
					$out = shell_exec( $cmd );
					@unlink( $tmp );

					$decoded = is_string( $out ) ? json_decode( $out, true ) : null;
					if ( is_array( $decoded ) && !empty( $decoded['ok'] ) ) {
						$maintext .= "<p class=ok>Registered corpus <strong>" . htmlspecialchars( $payload['id'] ) . "</strong> in FQS.</p>";
						if ( isset( $decoded['inserted'] ) && isset( $decoded['updated'] ) ) {
							$ins = $decoded['inserted'];
							$upd = $decoded['updated'];
							if ( is_array( $ins ) && is_array( $upd ) ) {
								$maintext .= "<p><small>Inserted: " . htmlspecialchars( json_encode( $ins ) )
									. " · Updated: " . htmlspecialchars( json_encode( $upd ) ) . "</small></p>";
							}
						}
						$valCmd = escapeshellarg( $fqsapp ) . ' corpora validate --full --strict-full --enrich --id '
							. escapeshellarg( $payload['id'] ) . ' 2>&1';
						$valOut = shell_exec( $valCmd );
						if ( is_string( $valOut ) && $valOut !== '' ) {
							$maintext .= "<h3>Post-registration validation + enrich</h3><p><small><code>fqs corpora validate --full --strict-full --enrich</code></small></p>"
								. "<pre style='white-space:pre-wrap;max-height:24em;overflow:auto;background:#f8f8f8;padding:8px;border:1px solid #ccc'>"
								. htmlspecialchars( $valOut ) . "</pre>";
						}
					} else {
						$maintext .= "<p class=warning>Registration failed.</p><pre style='white-space:pre-wrap'>" 
							. htmlspecialchars( (string) $out ) . "</pre>";
					}
				}
				$maintext .= "<p><a href='index.php?action=$action'>back to list</a></p>";
			}
		}

	} else if ( $act == "edit") {
		check_login();

		$fqsapp = findapp( "fqs" );
		$editId = isset( $_GET['id'] ) ? trim( (string) $_GET['id'] ) : '';
		if ( $editId === '' ) {
			$maintext .= "<p class=warning>Missing corpus id.</p><p><a href='index.php?action=$action'>back to list</a></p>";
		} else {
			$cmdShow = escapeshellarg( $fqsapp ) . ' corpora show --id ' . escapeshellarg( $editId ) . ' 2>&1';
			$jsonShow = shell_exec( $cmdShow );
			$entry = is_string( $jsonShow ) ? json_decode( $jsonShow, true ) : null;
			$entryOk = is_string( $jsonShow ) && json_last_error() === JSON_ERROR_NONE && is_array( $entry ) && isset( $entry['id'] );
			if ( ! $entryOk ) {
				$maintext .= "<p class=warning>Could not load corpus from FQS.</p><pre style='white-space:pre-wrap'>"
					. htmlspecialchars( (string) $jsonShow ) . "</pre><p><a href='index.php?action=$action'>back to list</a></p>";
			} else {
				$own = tt_fqs_corpus_is_current_project( $entry );
				$globalMode = !empty( $isshared );
				$canEdit = $own || $globalMode;
				$saveErr = '';
				$saveOk = false;
				$upOut = null;

				if ( isset( $_POST['fqs_edit_save'] ) && (string) $_POST['fqs_edit_save'] === '1' ) {
					if ( ! $canEdit ) {
						$saveErr = 'You can only edit the catalogue entry for this TEITOK project (unless using shared/global mode).';
					} elseif ( !isset( $_POST['corpus_id'] ) || (string) $_POST['corpus_id'] !== $editId ) {
						$saveErr = 'Invalid form submission.';
					} else {
						$label = isset( $_POST['label'] ) ? trim( (string) $_POST['label'] ) : '';
						if ( $label === '' ) {
							$saveErr = 'Display name (label) cannot be empty.';
						} else {
							$entry['label'] = $label;
							$pu = isset( $_POST['project_url'] ) ? trim( (string) $_POST['project_url'] ) : '';
							$entry['project_url'] = ( $pu === '' ) ? null : $pu;
							$env = isset( $_POST['environment'] ) ? trim( (string) $_POST['environment'] ) : '';
							if ( $env === '' ) {
								$env = 'live';
							}
							if ( strlen( $env ) > 128 ) {
								$saveErr = 'Environment label is too long (max 128 characters).';
							} else {
								$entry['environment'] = $env;
							}
							if ( $saveErr === '' ) {
								$vis = isset( $_POST['visibility'] ) ? trim( (string) $_POST['visibility'] ) : '';
								if ( in_array( $vis, array( 'published', 'staging', 'draft' ), true ) ) {
									$entry['visibility'] = $vis;
								}
								$listVis = isset( $_POST['listing_visibility'] ) ? trim( (string) $_POST['listing_visibility'] ) : '';
								if ( in_array( $listVis, array( 'public', 'server_admin' ), true ) ) {
									$entry['listing_visibility'] = $listVis;
								}
								$httpPol = isset( $_POST['http_policy_mode'] ) ? trim( (string) $_POST['http_policy_mode'] ) : '';
								if ( in_array( $httpPol, array( 'public_query', 'auth_required', 'disabled' ), true ) ) {
									$entry['http_policy_mode'] = $httpPol;
								}
								$pb = isset( $_POST['preferred_backend'] ) ? trim( (string) $_POST['preferred_backend'] ) : '';
								if ( in_array( $pb, array( 'auto', 'pando', 'cqp' ), true ) ) {
									$entry['preferred_backend'] = $pb;
								}
								$projRoot = isset( $entry['project_root'] ) ? (string) $entry['project_root'] : '';
								$onDisk = tt_fqs_detect_backends_on_disk( $projRoot );
								$avail = array();
								if ( !empty( $_POST['backend_pando'] ) && in_array( 'pando', $onDisk, true ) ) {
									$avail[] = 'pando';
								}
								if ( !empty( $_POST['backend_cqp'] ) && in_array( 'cqp', $onDisk, true ) ) {
									$avail[] = 'cqp';
								}
								$st = tt_fqs_entry_settings_array( $entry );
								$st['available_backends'] = $avail;
								$entry['settings'] = $st;
								$caps = tt_fqs_entry_capabilities_array( $entry );
								$fcsCfg = ( isset( $caps['fcs'] ) && is_array( $caps['fcs'] ) ) ? $caps['fcs'] : array();
								$fcsEnabled = !empty( $_POST['fcs_enabled'] );
								$fcsPid = isset( $_POST['fcs_resource_pid'] ) ? trim( (string) $_POST['fcs_resource_pid'] ) : '';
								if ( $fcsPid === '' ) {
									$fcsPid = 'local:' . $editId;
								}
								$fcsViews = array();
								if ( !empty( $_POST['fcs_view_hits'] ) ) {
									$fcsViews[] = 'hits';
								}
								if ( !empty( $_POST['fcs_view_kwic'] ) ) {
									$fcsViews[] = 'kwic';
								}
								if ( empty( $fcsViews ) ) {
									$fcsViews = array( 'hits' );
								}
								$fcsCfg['enabled'] = $fcsEnabled;
								$fcsCfg['resource_pid'] = $fcsPid;
								$fcsCfg['supports_dataviews'] = array_values( array_unique( $fcsViews ) );
								$fcsLangRaw = isset( $_POST['fcs_languages'] ) ? (string) $_POST['fcs_languages'] : '';
								$fcsLangs = tt_fqs_parse_language_codes( $fcsLangRaw );
								$fcsCfg['languages'] = $fcsLangs;
								$caps['fcs'] = $fcsCfg;
								$entry['capabilities'] = $caps;
								if ( ! empty( $fcsLangs ) ) {
									$st['languages'] = $fcsLangs;
								}
								$kontextCfg = ( isset( $st['kontext'] ) && is_array( $st['kontext'] ) ) ? $st['kontext'] : array();
								$kontextEnabled = !empty( $_POST['kontext_enabled'] );
								$kontextPublic = !empty( $_POST['kontext_public'] );
								$kontextCorpusId = isset( $_POST['kontext_corpus_id'] ) ? trim( (string) $_POST['kontext_corpus_id'] ) : '';
								if ( $kontextCorpusId === '' ) {
									$kontextCorpusId = $editId;
								}
								$kontextCfg['enabled'] = $kontextEnabled;
								$kontextCfg['public'] = $kontextPublic;
								$kontextCfg['corpus_id'] = $kontextCorpusId;
								$st['kontext'] = $kontextCfg;
								$pmltqCfg = ( isset( $st['pmltq'] ) && is_array( $st['pmltq'] ) ) ? $st['pmltq'] : array();
								$pmltqEnabled = !empty( $_POST['pmltq_enabled'] );
								$pmltqPublic = !empty( $_POST['pmltq_public'] );
								$pmltqTreebank = isset( $_POST['pmltq_treebank'] ) ? trim( (string) $_POST['pmltq_treebank'] ) : '';
								if ( $pmltqTreebank === '' ) {
									$pmltqTreebank = $editId;
								}
								$pmltqCfg['enabled'] = $pmltqEnabled;
								$pmltqCfg['public'] = $pmltqPublic;
								$pmltqCfg['treebank'] = $pmltqTreebank;
								$st['pmltq'] = $pmltqCfg;
								$entry['settings'] = $st;

								$prefErr = '';
								if ( isset( $entry['preferred_backend'] ) && $entry['preferred_backend'] === 'pando' && ! in_array( 'pando', $avail, true ) ) {
									$prefErr = 'Preferred backend is Pando, but Pando is not selected as available. Enable the Pando checkbox or set preferred backend to Auto.';
								} elseif ( isset( $entry['preferred_backend'] ) && $entry['preferred_backend'] === 'cqp' && ! in_array( 'cqp', $avail, true ) ) {
									$prefErr = 'Preferred backend is CQP, but CQP is not selected as available. Enable the CQP checkbox or set preferred backend to Auto.';
								}
								if ( $prefErr !== '' ) {
									$saveErr = $prefErr;
								} else {
									$tmp = @tempnam( sys_get_temp_dir(), 'fqsedit' );
									if ( $tmp === false ) {
										$saveErr = 'Could not create temp file.';
									} else {
										file_put_contents( $tmp, json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
										$upCmd = escapeshellarg( $fqsapp ) . ' corpora upsert-json --json-file ' . escapeshellarg( $tmp ) . ' 2>&1';
										$upOut = shell_exec( $upCmd );
										@unlink( $tmp );
										$upDec = is_string( $upOut ) ? json_decode( $upOut, true ) : null;
										if ( is_array( $upDec ) && !empty( $upDec['ok'] ) ) {
											$saveOk = true;
											$jsonShow = shell_exec( $cmdShow );
											$entry = is_string( $jsonShow ) ? json_decode( $jsonShow, true ) : $entry;
										} else {
											$saveErr = 'Save failed.';
											$maintext .= "<p class=warning>" . htmlspecialchars( $saveErr ) . "</p><pre style='white-space:pre-wrap'>"
												. htmlspecialchars( (string) $upOut ) . "</pre>";
										}
									}
								}
							}
						}
					}
				}

				if ( $saveOk ) {
					$maintext .= "<p class=ok>Catalogue entry updated.</p>";
				} elseif ( $saveErr !== '' && $upOut === null ) {
					$maintext .= "<p class=warning>" . htmlspecialchars( $saveErr ) . "</p>";
				}

				$maintext .= "<h2>FQS corpus: " . htmlspecialchars( (string) $entry['id'] ) . "</h2>";

				if ( $canEdit ) {
					$lab = isset( $entry['label'] ) ? (string) $entry['label'] : '';
					$pu = isset( $entry['project_url'] ) && $entry['project_url'] !== null ? (string) $entry['project_url'] : '';
					$env = isset( $entry['environment'] ) ? (string) $entry['environment'] : 'live';
					$vis = isset( $entry['visibility'] ) ? (string) $entry['visibility'] : 'published';
					$listVis = isset( $entry['listing_visibility'] ) ? (string) $entry['listing_visibility'] : 'public';
					$httpPol = isset( $entry['http_policy_mode'] ) ? (string) $entry['http_policy_mode'] : 'public_query';
					$pb = isset( $entry['preferred_backend'] ) ? (string) $entry['preferred_backend'] : 'auto';
					$projPath = isset( $entry['project_root'] ) ? (string) $entry['project_root'] : '';
					$onDiskBackends = tt_fqs_detect_backends_on_disk( $projPath );
					$stForm = tt_fqs_entry_settings_array( $entry );
					$curAvail = ( isset( $stForm['available_backends'] ) && is_array( $stForm['available_backends'] ) )
						? $stForm['available_backends']
						: $onDiskBackends;
					$capsForm = tt_fqs_entry_capabilities_array( $entry );
					$fcsForm = ( isset( $capsForm['fcs'] ) && is_array( $capsForm['fcs'] ) ) ? $capsForm['fcs'] : array();
					$fcsEnabledForm = !empty( $fcsForm['enabled'] );
					$fcsPidForm = isset( $fcsForm['resource_pid'] ) ? trim( (string) $fcsForm['resource_pid'] ) : '';
					if ( $fcsPidForm === '' ) {
						$fcsPidForm = 'local:' . $editId;
					}
					$fcsViewsForm = isset( $fcsForm['supports_dataviews'] ) && is_array( $fcsForm['supports_dataviews'] ) ? $fcsForm['supports_dataviews'] : array( 'hits' );
					$fcsViewHits = in_array( 'hits', $fcsViewsForm, true );
					$fcsViewKwic = in_array( 'kwic', $fcsViewsForm, true );
					$fcsLangsForm = isset( $fcsForm['languages'] ) && is_array( $fcsForm['languages'] ) ? $fcsForm['languages'] : array();
					if ( empty( $fcsLangsForm ) && isset( $stForm['languages'] ) && is_array( $stForm['languages'] ) ) {
						$fcsLangsForm = $stForm['languages'];
					}
					$fcsLangsForm = array_values( array_filter( array_map( 'strval', $fcsLangsForm ), function ( $lc ) {
						$lc = strtolower( trim( $lc ) );
						return $lc !== '' && ! in_array( $lc, array( 'und', 'unk', 'unknown', 'zxx' ), true );
					} ) );
					$fcsLangText = implode( ', ', $fcsLangsForm );
					$kontextForm = isset( $stForm['kontext'] ) && is_array( $stForm['kontext'] ) ? $stForm['kontext'] : array();
					$kontextEnabledForm = !empty( $kontextForm['enabled'] );
					$kontextPublicForm = !empty( $kontextForm['public'] );
					$kontextCorpusIdForm = isset( $kontextForm['corpus_id'] ) ? trim( (string) $kontextForm['corpus_id'] ) : '';
					if ( $kontextCorpusIdForm === '' ) {
						$kontextCorpusIdForm = $editId;
					}
					$pmltqForm = isset( $stForm['pmltq'] ) && is_array( $stForm['pmltq'] ) ? $stForm['pmltq'] : array();
					$pmltqEnabledForm = !empty( $pmltqForm['enabled'] );
					$pmltqPublicForm = !empty( $pmltqForm['public'] );
					$pmltqTreebankForm = isset( $pmltqForm['treebank'] ) ? trim( (string) $pmltqForm['treebank'] ) : '';
					if ( $pmltqTreebankForm === '' ) {
						$pmltqTreebankForm = $editId;
					}
					$chkPando = in_array( 'pando', $curAvail, true );
					$chkCqp = in_array( 'cqp', $curAvail, true );
					$formAction = 'index.php?action=' . rawurlencode( (string) $action ) . '&amp;act=edit&amp;id=' . rawurlencode( $editId );
					if ( $globalMode && !$own ) {
						$maintext .= "<p><small>Shared/global mode: editing a corpus row from another TEITOK project.</small></p>";
					} else {
						$maintext .= "<p>This catalogue row is tied to <strong>this</strong> TEITOK project. The <strong>project folder</strong> is fixed (moving a corpus requires re-registration from the new location). Other fields update the FQS catalogue.</p>";
					}
					$maintext .= "<form method='post' action='" . $formAction . "'>"
						. "<input type='hidden' name='fqs_edit_save' value='1' />"
						. "<input type='hidden' name='corpus_id' value='" . htmlspecialchars( $editId, ENT_QUOTES, 'UTF-8' ) . "' />"
						. "<table cellpadding='6'>"
						. "<tr><th align='left'>Project folder</th><td><code>"
						. htmlspecialchars( $projPath, ENT_QUOTES, 'UTF-8' )
						. "</code> <small>(not editable)</small></td></tr>"
						. "<tr><th align='left'>Display name (label)</th><td><input type='text' name='label' size='60' value='"
						. htmlspecialchars( $lab, ENT_QUOTES, 'UTF-8' ) . "' /></td></tr>"
						. "<tr><th align='left'>Project URL</th><td><input type='text' name='project_url' size='60' value='"
						. htmlspecialchars( $pu, ENT_QUOTES, 'UTF-8' ) . "' placeholder='https://…'/></td></tr>"
						. "<tr><th align='left'>Environment</th><td>"
						. "<input type='text' name='environment' id='tt_fqs_environment' size='32' maxlength='128' value='"
						. htmlspecialchars( $env, ENT_QUOTES, 'UTF-8' ) . "' list='tt_fqs_environment_presets' />"
						. "<datalist id='tt_fqs_environment_presets'>"
						. "<option value='dev'></option><option value='live'></option><option value='stable'></option>"
						. "</datalist>"
						. "<br/><small>Short label for catalog filtering (common presets: dev / live / stable — use any value your deployment uses).</small>"
						. "</td></tr>"
						. "<tr><th align='left'>Visibility</th><td><select name='visibility'>";
					foreach ( array( 'published', 'staging', 'draft' ) as $v ) {
						$sel = ( $v === $vis ) ? " selected='selected'" : '';
						$maintext .= "<option value='" . htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' ) . "'" . $sel . ">"
							. htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' ) . "</option>";
					}
					$maintext .= "</select></td></tr>"
						. "<tr><th align='left'>Listing visibility</th><td><select name='listing_visibility'>";
					foreach ( array( 'public', 'server_admin' ) as $lv ) {
						$sel = ( $lv === $listVis ) ? " selected='selected'" : '';
						$maintext .= "<option value='" . htmlspecialchars( $lv, ENT_QUOTES, 'UTF-8' ) . "'" . $sel . ">"
							. htmlspecialchars( $lv, ENT_QUOTES, 'UTF-8' ) . "</option>";
					}
					$maintext .= "</select></td></tr>"
						. "<tr><th align='left'>HTTP policy</th><td><select name='http_policy_mode'>";
					foreach ( array(
						'public_query' => 'Public query',
						'auth_required' => 'Auth required',
						'disabled' => 'Disabled',
					) as $hk => $hv ) {
						$sel = ( $hk === $httpPol ) ? " selected='selected'" : '';
						$maintext .= "<option value='" . htmlspecialchars( $hk, ENT_QUOTES, 'UTF-8' ) . "'" . $sel . ">"
							. htmlspecialchars( $hv, ENT_QUOTES, 'UTF-8' ) . "</option>";
					}
					$maintext .= "</select></td></tr>"
						. "<tr><th align='left'>Preferred backend</th><td><select name='preferred_backend'>";
					foreach ( array(
						'auto' => 'Auto (from settings / index)',
						'pando' => 'Pando',
						'cqp' => 'CQP',
					) as $bk => $bv ) {
						$sel = ( $bk === $pb ) ? " selected='selected'" : '';
						$maintext .= "<option value='" . htmlspecialchars( $bk, ENT_QUOTES, 'UTF-8' ) . "'" . $sel . ">"
							. htmlspecialchars( $bv, ENT_QUOTES, 'UTF-8' ) . "</option>";
					}
					$maintext .= "</select></td></tr>"
						. "<tr><th align='left' style='vertical-align:top'>Available backends</th><td>";
					$maintext .= "<small>Catalogue snapshot of which engines this entry may use. Folders on disk: "
						. ( empty( $onDiskBackends ) ? '<em>none</em>' : htmlspecialchars( implode( ', ', $onDiskBackends ), ENT_QUOTES, 'UTF-8' ) )
						. ".</small><br/>";
					if ( in_array( 'pando', $onDiskBackends, true ) ) {
						$maintext .= "<label><input type='checkbox' name='backend_pando' value='1'"
							. ( $chkPando ? " checked='checked'" : '' ) . " /> Pando</label> &nbsp; ";
					}
					if ( in_array( 'cqp', $onDiskBackends, true ) ) {
						$maintext .= "<label><input type='checkbox' name='backend_cqp' value='1'"
							. ( $chkCqp ? " checked='checked'" : '' ) . " /> CQP</label>";
					}
					if ( empty( $onDiskBackends ) ) {
						$maintext .= "<em>No <code>pando</code> or <code>cqp</code> folder under this project.</em>";
					}
					$maintext .= "<br/><small>Stored in FQS as <code>settings.available_backends</code> (JSON array).</small>"
						. "</td></tr>"
						. "<tr><th align='left' style='vertical-align:top'>FCS</th><td>"
						. "<label><input type='checkbox' name='fcs_enabled' value='1'" . ( $fcsEnabledForm ? " checked='checked'" : '' ) . " /> Enabled</label>"
						. "<br/>Resource PID: <input type='text' name='fcs_resource_pid' size='40' value='" . htmlspecialchars( $fcsPidForm, ENT_QUOTES, 'UTF-8' ) . "' />"
						. "<br/>Data views: "
						. "<label><input type='checkbox' name='fcs_view_hits' value='1'" . ( $fcsViewHits ? " checked='checked'" : '' ) . " /> hits</label> "
						. "<label><input type='checkbox' name='fcs_view_kwic' value='1'" . ( $fcsViewKwic ? " checked='checked'" : '' ) . " /> kwic</label>"
						. "<br/>Languages: <input type='text' name='fcs_languages' size='30' value='" . htmlspecialchars( $fcsLangText, ENT_QUOTES, 'UTF-8' ) . "' placeholder='en, cs' />"
						. "<br/><small>Stored as <code>capabilities.fcs</code>. If disabled, corpus is hidden from FCS explain/search context discovery.</small>"
						. "</td></tr>"
						. "<tr><th align='left' style='vertical-align:top'>KonText</th><td>"
						. "<label><input type='checkbox' name='kontext_enabled' value='1'" . ( $kontextEnabledForm ? " checked='checked'" : '' ) . " /> Enabled</label> "
						. "<label><input type='checkbox' name='kontext_public' value='1'" . ( $kontextPublicForm ? " checked='checked'" : '' ) . " /> Public</label>"
						. "<br/>Corpus id: <input type='text' name='kontext_corpus_id' size='30' value='" . htmlspecialchars( $kontextCorpusIdForm, ENT_QUOTES, 'UTF-8' ) . "' />"
						. "<br/><small>Stored in <code>settings.kontext</code>. Used as corpus-specific visibility/config metadata; operational sync still needs KonText-side checks/fixes.</small>"
						. "</td></tr>"
						. "<tr><th align='left' style='vertical-align:top'>PML-TQ</th><td>"
						. "<label><input type='checkbox' name='pmltq_enabled' value='1'" . ( $pmltqEnabledForm ? " checked='checked'" : '' ) . " /> Enabled</label> "
						. "<label><input type='checkbox' name='pmltq_public' value='1'" . ( $pmltqPublicForm ? " checked='checked'" : '' ) . " /> Public</label>"
						. "<br/>Treebank id: <input type='text' name='pmltq_treebank' size='30' value='" . htmlspecialchars( $pmltqTreebankForm, ENT_QUOTES, 'UTF-8' ) . "' />"
						. "<br/><small>Stored in <code>settings.pmltq</code> for future native PML-TQ publication workflow.</small>"
						. "</td></tr>"
						. "<tr><td></td><td><button type='submit'>Save</button></td></tr>"
						. "</table></form>";
				} else {
					$maintext .= "<p class=warning>This row is not the catalogue entry for the current TEITOK project (folder / base URL do not match). Editing is disabled unless you use shared/global mode.</p>";
				}

				// Always show what is stored in FQS (not a failed POST payload left in $entry).
				$catalogJson = shell_exec( $cmdShow );
				$catalogEntry = is_string( $catalogJson ) ? json_decode( $catalogJson, true ) : null;
				if ( ! is_array( $catalogEntry ) || ! isset( $catalogEntry['id'] ) ) {
					$catalogEntry = $entry;
				}
				$maintext .= "<h3>Full record <small>(catalogue)</small></h3><table cellpadding='2'>";
				foreach ( $catalogEntry as $ckey => $cval ) {
					if ( is_array( $cval ) ) {
						$valtxt = json_encode( $cval, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					} else {
						$valtxt = $cval === null ? '' : (string) $cval;
					}
					$maintext .= "<tr><th style='vertical-align:top;text-align:left'>" . htmlspecialchars( (string) $ckey )
						. "</th><td><pre style='white-space:pre-wrap;margin:0'>" . htmlspecialchars( $valtxt ) . "</pre></td></tr>";
				}
				$maintext .= "</table><hr><p><a href='index.php?action=$action'>back to list</a></p>";
			}
		}

	} else {

		if ( ! function_exists( 'tt_fqs_browse_url' ) ) {
			/** Public FQS base URL (same discovery as fqs_query / flexicorp). */
			function tt_fqs_browse_url() {
				$url = '';
				if ( function_exists( 'getset' ) ) {
					$url = trim( (string) getset( 'flexicorp/fqs_url', '' ) );
				}
				if ( $url === '' ) {
					$e = getenv( 'FQS_URL' );
					if ( is_string( $e ) ) $url = trim( $e );
				}
				if ( $url === '' && function_exists( 'tt_flexicorp_fqs_url_from_runtime_file' ) ) {
					$url = (string) tt_flexicorp_fqs_url_from_runtime_file();
				}
				if ( $url === '' ) {
					$cands = array();
					$e = getenv( 'FQS_HTTP_JSON' );
					if ( is_string( $e ) && trim( $e ) !== '' ) $cands[] = trim( $e );
					$db = getenv( 'FQS_DB_PATH' );
					if ( is_string( $db ) && $db !== '' ) $cands[] = rtrim( dirname( $db ), '/' ) . '/fqs-http.json';
					$cands[] = '/usr/local/var/fqs/fqs-http.json';
					$cands[] = '/var/lib/fqs/fqs-http.json';
					foreach ( $cands as $c ) {
						if ( ! is_readable( $c ) ) continue;
						$j = json_decode( (string) @file_get_contents( $c ), true );
						if ( is_array( $j ) && ! empty( $j['url'] ) ) {
							$url = trim( (string) $j['url'] );
							break;
						}
					}
				}
				if ( $url === '' ) $url = 'http://127.0.0.1:8787';
				return rtrim( $url, '/' );
			}
		}

		if ( ! function_exists( 'tt_fqs_http_get_json' ) ) {
			function tt_fqs_http_get_json( $url, $timeout = 8 ) {
				$body = false;
				$ctype = '';
				$status = 0;
				if ( function_exists( 'curl_init' ) ) {
					$ch = curl_init( $url );
					curl_setopt_array( $ch, array(
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_FOLLOWLOCATION => true,
						CURLOPT_CONNECTTIMEOUT => 3,
						CURLOPT_TIMEOUT => (int) $timeout,
						CURLOPT_HTTPHEADER => array( 'Accept: application/json' ),
					) );
					$body = curl_exec( $ch );
					$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
					$ctype = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
					curl_close( $ch );
				} else {
					$ctx = stream_context_create( array(
						'http' => array(
							'method' => 'GET',
							'timeout' => (int) $timeout,
							'header' => "Accept: application/json\r\n",
						),
					) );
					$body = @file_get_contents( $url, false, $ctx );
					if ( isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ) {
						$status = (int) $m[1];
					}
				}
				if ( ! is_string( $body ) || $body === '' ) {
					return array( 'ok' => false, 'error' => 'empty response', 'status' => $status );
				}
				$j = json_decode( $body, true );
				if ( ! is_array( $j ) ) {
					return array( 'ok' => false, 'error' => 'invalid json', 'status' => $status, 'raw' => $body );
				}
				$j['_http_status'] = $status;
				$j['_content_type'] = $ctype;
				return $j;
			}
		}

		if ( ! function_exists( 'tt_fqs_looks_like_teitok_url' ) ) {
			function tt_fqs_looks_like_teitok_url( $url ) {
				$u = strtolower( trim( (string) $url ) );
				return $u !== '' && ( strpos( $u, '/teitok/' ) !== false || strpos( $u, 'teitok.' ) !== false || strpos( $u, 'teitok-' ) !== false );
			}
		}

		if ( ! function_exists( 'tt_fqs_teitok_project_dir' ) ) {
			function tt_fqs_teitok_project_dir( $dir ) {
				$dir = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
				if ( $dir === '' || ! is_dir( $dir ) || ! is_file( $dir . '/index.php' ) ) {
					return false;
				}
				foreach ( array( 'Scripts', 'Pages', 'xmlfiles', 'cqpsettings.xml', 'Resources/settings.xml', 'pando', 'cqp', 'manatee', 'xidx' ) as $m ) {
					if ( file_exists( $dir . '/' . $m ) ) {
						return true;
					}
				}
				return false;
			}
		}

		if ( ! function_exists( 'tt_fqs_row_is_teitok_listable' ) ) {
			/** Same idea as FQS frontend=teitok (CLI fallback when browse API unavailable). */
			function tt_fqs_row_is_teitok_listable( array $row ) {
				// Browse DTO already computed this server-side (no project_root in public JSON).
				if ( array_key_exists( 'teitok_listable', $row ) ) {
					return ! empty( $row['teitok_listable'] );
				}
				if ( isset( $row['capabilities']['teitok_listing'] ) && $row['capabilities']['teitok_listing'] === false ) {
					return false;
				}
				$purl = isset( $row['project_url'] ) ? trim( (string) $row['project_url'] ) : '';
				$sk = isset( $row['source_kind'] ) ? strtolower( (string) $row['source_kind'] ) : '';
				$pref = isset( $row['interface_preference'] ) ? strtolower( trim( (string) $row['interface_preference'] ) ) : '';
				$teitok_signal = ( strpos( $sk, 'teitok' ) !== false )
					|| ( $pref === 'teitok' )
					|| ! empty( $row['capabilities']['teitok_integration'] )
					|| ( isset( $row['settings']['teitok'] ) );
				$proot = isset( $row['project_root'] ) ? (string) $row['project_root'] : '';
				$root_ok = tt_fqs_teitok_project_dir( $proot );
				if ( ! $root_ok && $proot !== '' ) {
					$parent = dirname( rtrim( str_replace( '\\', '/', $proot ), '/' ) );
					$root_ok = tt_fqs_teitok_project_dir( $parent );
				}
				if ( isset( $row['capabilities']['teitok_listing'] ) && $row['capabilities']['teitok_listing'] === true ) {
					return ( $purl !== '' ) || $root_ok;
				}
				if ( $purl !== '' && ( tt_fqs_looks_like_teitok_url( $purl ) || $teitok_signal ) ) {
					return true;
				}
				// Disk TEITOK tree (incl. dummy index.php+pando) is enough without
				// catalogue teitok_* flags — those are filled by one-shot enrich.
				return $root_ok;
			}
		}

		if ( ! function_exists( 'tt_fqs_select_url' ) ) {
			/** URL to open this TEITOK project, or '' if none. */
			function tt_fqs_select_url( array $row ) {
				$purl = isset( $row['project_url'] ) ? trim( (string) $row['project_url'] ) : '';
				if ( $purl === '' ) {
					return '';
				}
				// Any http(s) project URL is fine — many vhosts omit "teitok" in the path.
				if ( preg_match( '#^https?://#i', $purl ) || $purl[0] === '/' ) {
					return $purl;
				}
				return '';
			}
		}

		if ( ! function_exists( 'tt_fqs_selected_facets' ) ) {
			function tt_fqs_selected_facets() {
				$out = array();
				if ( isset( $_GET['facet'] ) ) {
					$raw = $_GET['facet'];
					if ( ! is_array( $raw ) ) $raw = array( $raw );
					foreach ( $raw as $f ) {
						$f = strtolower( trim( (string) $f ) );
						if ( $f !== '' ) $out[] = $f;
					}
				}
				if ( isset( $_GET['facets'] ) && trim( (string) $_GET['facets'] ) !== '' ) {
					foreach ( preg_split( '/\s*,\s*/', (string) $_GET['facets'] ) as $f ) {
						$f = strtolower( trim( $f ) );
						if ( $f !== '' ) $out[] = $f;
					}
				}
				return array_values( array_unique( $out ) );
			}
		}

		$selectedFacets = tt_fqs_selected_facets();
		$qSearch = isset( $_GET['q'] ) ? trim( (string) $_GET['q'] ) : '';
		$role = ( ! empty( $username ) ) ? 'user' : 'visitor';
		if ( isset( $user['permissions'] ) && $user['permissions'] === 'admin' ) {
			$role = 'admin';
		}
		// Admins see the full FQS catalogue here (this module); visitors only TEITOK-openable.
		// Catalog edits belong in action=fqsadmin, not this list.
		$showAllFqs = ( $role === 'admin' );

		$corplist = array();
		$facetDict = array();
		$listSource = '';
		$listError = '';

		$base = tt_fqs_browse_url();
		$qs = array(
			'view' => 'browse',
			'request_role' => $role,
		);
		if ( ! $showAllFqs ) {
			$qs['frontend'] = 'teitok';
		}
		if ( $qSearch !== '' ) $qs['q'] = $qSearch;
		$listUrl = $base . '/corpora?' . http_build_query( $qs );
		foreach ( $selectedFacets as $f ) {
			$listUrl .= '&facet=' . rawurlencode( $f );
		}
		$labelsQs = array( 'request_role' => $role );
		if ( ! $showAllFqs ) {
			$labelsQs['frontend'] = 'teitok';
		}
		$labelsUrl = $base . '/labels?' . http_build_query( $labelsQs );
		foreach ( $selectedFacets as $f ) {
			$labelsUrl .= '&facet=' . rawurlencode( $f );
		}

		$listJson = tt_fqs_http_get_json( $listUrl );
		$labelsJson = tt_fqs_http_get_json( $labelsUrl );
		if ( ! empty( $listJson['ok'] ) && isset( $listJson['corpora'] ) && is_array( $listJson['corpora'] ) ) {
			$corplist = $listJson['corpora'];
			$listSource = 'http';
			if ( ! empty( $labelsJson['ok'] ) && isset( $labelsJson['facets'] ) && is_array( $labelsJson['facets'] ) ) {
				$facetDict = $labelsJson['facets'];
			}
		} else {
			// Fallback: CLI list (older FQS without browse params).
			$fqsapp = findapp( 'fqs' );
			$tmp = is_string( $fqsapp ) && $fqsapp !== ''
				? shell_exec( escapeshellarg( $fqsapp ) . ' corpora list 2>&1' )
				: '';
			$listDecoded = is_string( $tmp ) ? json_decode( $tmp, true ) : null;
			$rows = array();
			if ( is_array( $listDecoded ) ) {
				$rows = isset( $listDecoded['corpora'] ) && is_array( $listDecoded['corpora'] )
					? $listDecoded['corpora']
					: $listDecoded;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) continue;
				if ( ! $showAllFqs && empty( $GLOBALS['wewanttoseemore'] )
					&& ! tt_fqs_row_is_teitok_listable( $row ) ) {
					continue;
				}
				$corplist[] = $row;
			}
			$listSource = 'cli';
			if ( empty( $corplist ) && ! empty( $listJson['error'] ) ) {
				$listError = (string) $listJson['error'];
			}
		}

		// Split: main table = TEITOK-openable (same as visitors); admin sees other FQS below.
		$teitokRows = array();
		$otherRows = array();
		foreach ( $corplist as $corp ) {
			if ( is_object( $corp ) ) $corp = (array) json_decode( json_encode( $corp ), true );
			if ( ! is_array( $corp ) ) continue;
			if ( tt_fqs_row_is_teitok_listable( $corp ) ) {
				$teitokRows[] = $corp;
			} elseif ( $showAllFqs || ! empty( $GLOBALS['wewanttoseemore'] ) ) {
				$otherRows[] = $corp;
			}
		}

		if ( ! function_exists( 'tt_fqs_render_corpus_table' ) ) {
			/**
			 * @param array $rows
			 * @param bool  $withSelect  TEITOK Select / current / no URL column
			 * @param bool  $adminOther  FQS-only rows: show backend/id hint, link to fqsadmin when possible
			 */
			function tt_fqs_render_corpus_table( $rows, $withSelect, $adminOther = false ) {
				global $baseurl;
				$html = '<table><tr><th>Name</th><th>Language(s)</th><th>Features</th><th>Size</th>';
				if ( $adminOther ) {
					$html .= '<th>Backend</th>';
				}
				$html .= '<th></th></tr>';
				$havethis = false;
				foreach ( $rows as $corp ) {
					if ( ! is_array( $corp ) ) continue;
					$id = isset( $corp['id'] ) ? (string) $corp['id'] : '';
					$cname = isset( $corp['label'] ) ? (string) $corp['label'] : $id;
					$thisc = false;
					$proot = isset( $corp['project_root'] ) ? (string) $corp['project_root'] : '';
					$purl = isset( $corp['project_url'] ) ? trim( (string) $corp['project_url'] ) : '';
					$cwd = getcwd();
					if ( ( $proot !== '' && $cwd !== false && @realpath( $proot ) === @realpath( $cwd ) )
						|| ( $purl !== '' && isset( $baseurl ) && rtrim( $purl, '/' ) === rtrim( (string) $baseurl, '/' ) ) ) {
						$havethis = true;
						$thisc = true;
						$cname = '<b>' . htmlspecialchars( $cname, ENT_QUOTES, 'UTF-8' ) . '</b>';
					} else {
						$cname = htmlspecialchars( $cname, ENT_QUOTES, 'UTF-8' );
					}

					$langs = array();
					$feats = array();
					if ( isset( $corp['facets'] ) && is_array( $corp['facets'] ) ) {
						if ( ! empty( $corp['facets']['lang'] ) && is_array( $corp['facets']['lang'] ) ) {
							$langs = $corp['facets']['lang'];
						}
						if ( ! empty( $corp['facets']['feature'] ) && is_array( $corp['facets']['feature'] ) ) {
							$feats = $corp['facets']['feature'];
						}
					}
					if ( empty( $langs ) && isset( $corp['capabilities']['fcs']['languages'] ) && is_array( $corp['capabilities']['fcs']['languages'] ) ) {
						$langs = $corp['capabilities']['fcs']['languages'];
					}
					if ( empty( $langs ) && isset( $corp['settings']['languages'] ) && is_array( $corp['settings']['languages'] ) ) {
						$langs = $corp['settings']['languages'];
					}
					$langs = array_values( array_filter( array_map( 'strval', is_array( $langs ) ? $langs : array() ), function ( $lc ) {
						$lc = strtolower( trim( $lc ) );
						return $lc !== '' && ! in_array( $lc, array( 'und', 'unk', 'unknown', 'zxx', 'mul' ), true );
					} ) );
					$size = isset( $corp['corpus_size'] ) ? $corp['corpus_size'] : null;
					$sizeTxt = ( $size !== null && $size !== '' && function_exists( 'hrnum' ) )
						? hrnum( $size )
						: ( $size !== null ? htmlspecialchars( (string) $size, ENT_QUOTES, 'UTF-8' ) : '—' );

					$actionCell = '—';
					if ( $withSelect ) {
						$selectUrl = tt_fqs_select_url( $corp );
						if ( $selectUrl !== '' ) {
							$actionCell = $thisc
								? '<em>current</em>'
								: "<a href='" . htmlspecialchars( $selectUrl, ENT_QUOTES, 'UTF-8' ) . "'>Select</a>";
							if ( ! $thisc ) {
								$cname = "<a href='" . htmlspecialchars( $selectUrl, ENT_QUOTES, 'UTF-8' ) . "'>" . $cname . '</a>';
							}
						} else {
							$actionCell = '<small title="Set project_url in FQS admin / corpus edit">no URL</small>';
						}
					} elseif ( $adminOther && $id !== '' ) {
						$actionCell = "<a href='index.php?action=fqsadmin'>fqsadmin</a>"
							. " <small>(<code>" . htmlspecialchars( $id, ENT_QUOTES, 'UTF-8' ) . "</code>)</small>";
					}

					$html .= '<tr><td>' . $cname . '</td><td>'
						. htmlspecialchars( empty( $langs ) ? '—' : implode( ', ', $langs ), ENT_QUOTES, 'UTF-8' )
						. '</td><td>'
						. htmlspecialchars( empty( $feats ) ? '—' : implode( ', ', $feats ), ENT_QUOTES, 'UTF-8' )
						. '</td><td>' . $sizeTxt . '</td>';
					if ( $adminOther ) {
						$be = isset( $corp['preferred_backend'] ) ? (string) $corp['preferred_backend'] : '—';
						$html .= '<td>' . htmlspecialchars( $be, ENT_QUOTES, 'UTF-8' ) . '</td>';
					}
					$html .= '<td>' . $actionCell . '</td></tr>';
				}
				if ( empty( $rows ) ) {
					$cols = $adminOther ? '6' : '5';
					$html .= '<tr><td colspan="' . $cols . '"><em>No corpora in this list.</em></td></tr>';
				}
				$html .= '</table>';
				return array( $html, $havethis );
			}
		}

		// Facet form — plain markup so the host TEITOK skin styles it.
		$self = 'index.php?action=' . rawurlencode( (string) $action );
		$maintext .= '<p>TEITOK corpora from FQS'
			. ( $listSource !== '' ? ' <small>(' . htmlspecialchars( $listSource, ENT_QUOTES, 'UTF-8' ) . ')</small>' : '' )
			. '.';
		if ( $showAllFqs && function_exists( 'getset' ) && trim( (string) getset( 'flexicorp/fqs_admin_users', '' ) ) !== '' ) {
			$maintext .= ' Catalogue edits: <a href="index.php?action=fqsadmin">fqsadmin</a>.';
		}
		$maintext .= '</p>';

		$maintext .= "<form method='get' action='index.php'>";
		$maintext .= "<input type='hidden' name='action' value='" . htmlspecialchars( (string) $action, ENT_QUOTES, 'UTF-8' ) . "' />";
		$maintext .= '<p><label>Search <input type="search" name="q" value="'
			. htmlspecialchars( $qSearch, ENT_QUOTES, 'UTF-8' ) . '" /></label></p>';

		$groups = array(
			'lang' => 'Language',
			'feature' => 'Features',
			'genre' => 'Genre',
			'other' => 'Other',
		);
		foreach ( $groups as $gid => $glabel ) {
			if ( empty( $facetDict[ $gid ] ) || ! is_array( $facetDict[ $gid ] ) ) continue;
			$maintext .= '<fieldset><legend>' . htmlspecialchars( $glabel, ENT_QUOTES, 'UTF-8' ) . '</legend><p>';
			foreach ( $facetDict[ $gid ] as $item ) {
				if ( ! is_array( $item ) ) continue;
				$val = isset( $item['value'] ) ? (string) $item['value'] : '';
				if ( $val === '' ) continue;
				$token = $gid . ':' . $val;
				$cnt = isset( $item['count'] ) ? (int) $item['count'] : 0;
				$chk = in_array( $token, $selectedFacets, true ) ? " checked='checked'" : '';
				$maintext .= '<label><input type="checkbox" name="facet[]" value="'
					. htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' ) . '"' . $chk . ' /> '
					. htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' )
					. ( $cnt ? ' <small>(' . $cnt . ')</small>' : '' )
					. '</label> ';
			}
			$maintext .= '</p></fieldset>';
		}
		$maintext .= '<p><input type="submit" value="Filter" /> '
			. "<a href='" . htmlspecialchars( $self, ENT_QUOTES, 'UTF-8' ) . "'>Clear</a></p>";
		$maintext .= '</form>';

		if ( $listError !== '' ) {
			$maintext .= "<p class=warning>FQS browse unavailable (" . htmlspecialchars( $listError, ENT_QUOTES, 'UTF-8' )
				. '); showing CLI fallback if any.</p>';
		}

		if ( empty( $teitokRows ) && ( $selectedFacets || $qSearch !== '' ) ) {
			$maintext .= '<p><em>No TEITOK corpora match these filters.</em></p>';
		}
		list( $teitokHtml, $haveTeitokCurrent ) = tt_fqs_render_corpus_table( $teitokRows, true, false );
		$maintext .= $teitokHtml;
		$havethiscorpus = $haveTeitokCurrent;

		if ( $showAllFqs && ! empty( $otherRows ) ) {
			$maintext .= '<h3>Other corpora in FQS</h3>';
			$maintext .= '<p><small>Registered for query/FCS/KonText etc., but not openable as a TEITOK project from this list.</small></p>';
			list( $otherHtml, $haveOtherCurrent ) = tt_fqs_render_corpus_table( $otherRows, false, true );
			$maintext .= $otherHtml;
			if ( $haveOtherCurrent ) {
				$havethiscorpus = true;
			}
		}
		if ( $username && ! empty( $isshared ) ) {
			$maintext .= '<hr><p>';
			if ( function_exists( 'getset' ) && trim( (string) getset( 'flexicorp/fqs_admin_users', '' ) ) !== '' ) {
				$maintext .= "<a href='index.php?action=fqsadmin'>FQS admin</a>";
			} else {
				$maintext .= "<a href='index.php?action=$action&amp;act=admin'>admin mode</a>"
					. ' <small>(legacy; prefer configuring <code>fqsadmin</code>)</small>';
			}
			$maintext .= '</p>';
		}

		if ( empty( $isshared ) && ! $havethiscorpus ) {
			$maintext .= "<p class=warning>The current corpus is not included in the corpus list.";
			if ( $username ) {
				$maintext .= " <a href='index.php?action=$action&amp;act=addcorpus'>Register this corpus in FQS</a></p>";
			} else {
				$maintext .= '</p>';
			}
		}
	};
	
?>	
