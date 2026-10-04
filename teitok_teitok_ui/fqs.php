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
				'fcs' => array(
					'enabled' => true,
					'resource_pid' => 'local:' . $id,
					'supports_dataviews' => array( 'hits' ),
					'languages' => !empty( $languages ) ? $languages : array( 'und' ),
				),
			);

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
				'http_policy_mode' => 'public_query',
				'http_allowed_operations' => array( 'query', 'catalog' ),
				'interfaces' => array( 'query' ),
				'labels' => array(),
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
					$langs = isset( $fcs['languages'] ) && is_array( $fcs['languages'] ) ? implode( ', ', $fcs['languages'] ) : 'und';
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
						$valCmd = escapeshellarg( $fqsapp ) . ' corpora validate --full --strict-full --id '
							. escapeshellarg( $payload['id'] ) . ' 2>&1';
						$valOut = shell_exec( $valCmd );
						if ( is_string( $valOut ) && $valOut !== '' ) {
							$maintext .= "<h3>Post-registration validation</h3><p><small><code>fqs corpora validate --full --strict-full</code></small></p>"
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
								if ( empty( $fcsLangs ) ) {
									$fcsLangs = array( 'und' );
								}
								$fcsCfg['languages'] = $fcsLangs;
								$caps['fcs'] = $fcsCfg;
								$entry['capabilities'] = $caps;
								$st['languages'] = $fcsLangs;
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
					if ( empty( $fcsLangsForm ) ) {
						$fcsLangsForm = array( 'und' );
					}
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

				$maintext .= "<h3>Full record</h3><table cellpadding='2'>";
				foreach ( $entry as $ckey => $cval ) {
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
	
		# Run FQS to get the registered corpora
		$fqsapp = findapp("fqs");
		$cmd = "$fqsapp corpora list"; # $maintext .= "$cmd";
		$tmp = shell_exec($cmd);
		$listDecoded = json_decode($tmp);
		$corplist = array();
		if ( is_array($listDecoded) ) {
			// Legacy shape: raw JSON array of corpus rows.
			$corplist = $listDecoded;
		} elseif ( is_object($listDecoded) && isset($listDecoded->corpora) && is_array($listDecoded->corpora) ) {
			// Current shape: {"ok":true,"corpora":[...],...}
			$corplist = $listDecoded->corpora;
		}
		if ( !is_array($corplist) ) {
			$corplist = array();
		}
		
		if ( $username ) $tt = "<td>";
		$maintext .= "<table>
			<tr>$tt<th>Name<th>Language(s)<th>Size";
		$havethiscorpus = false;
		foreach ( $corplist as $corp ) {
			$cname = $corp->label; $thisc = false;
			if ( 
				$corp->project_root == getcwd() # Folder matches
				|| $corp->project_url == $baseurl
			 ) {
				$havethiscorpus = true;
				$thisc = true;
				$cname = "<b>$cname</b>";
			};
			if ( $corp->project_url ) $cname = "<a href='{$corp->project_url}'>$cname</a>";
			if ( $username && ( $thisc || $isshared ) ) $tt = "<td><a href='index.php?action=$action&act=edit&id=$corp->id'>edit</a>";
			$langs = 'und';
			if ( isset( $corp->capabilities ) && is_object( $corp->capabilities ) && isset( $corp->capabilities->fcs ) && is_object( $corp->capabilities->fcs ) ) {
				if ( isset( $corp->capabilities->fcs->languages ) && is_array( $corp->capabilities->fcs->languages ) && count( $corp->capabilities->fcs->languages ) > 0 ) {
					$langs = implode( ', ', $corp->capabilities->fcs->languages );
				} elseif ( isset( $corp->capabilities->fcs->language ) && trim( (string) $corp->capabilities->fcs->language ) !== '' ) {
					$langs = trim( (string) $corp->capabilities->fcs->language );
				}
			}
			if ( $langs === 'und' && isset( $corp->settings ) && is_object( $corp->settings ) && isset( $corp->settings->languages ) && is_array( $corp->settings->languages ) && count( $corp->settings->languages ) > 0 ) {
				$langs = implode( ', ', $corp->settings->languages );
			}
			if ( $corp->source_kind == "teitok" || $corp->source_kind == "teitok_pando" || $wewanttoseemore || $thisc  ) {
				$maintext .= "<tr>$tt<td>$cname</td><td>" . htmlspecialchars( (string) $langs, ENT_QUOTES, 'UTF-8' ) . "</td><td style='text-align: right;'>".hrnum($corp->corpus_size);
			};
		};
		$maintext .= "</table>";
		if ( $username && $isshared ) $maintext .= "<hr><p><a href='index.php?action=$action&act=admin'>admin mode</a>";
		
		if ( !$isshared && !$havethiscorpus ) {
			$maintext .= "<p class=warning>The current corpus is not included in the corpus list.";
			if ( $username ) {
				$maintext .= " <a href='index.php?action=$action&amp;act=addcorpus'>Register this corpus in FQS</a></p>";
			} else {
				$maintext .= "</p>";
			}
		}
	};
	
?>	
