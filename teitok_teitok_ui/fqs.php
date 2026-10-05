<?php

	// the corpus list (no act) writes its own, editable, heading
	if ( $act == "admin" || $act == "addcorpus" || $act == "edit" ) $maintext .= "<h1>{%Available Corpora}</h1>";

	// the shared helpers: registration payload, listing data (also used by flexicorp.php)
	require_once __DIR__ . '/fqs-lib.php';

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
		// the server's path of this project (/teitok/<project>/index.php): corpus lists make it
		// a relative link, which also works behind a path-prefix proxy
		$projectUrl = tt_fqs_project_url();

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
		$iface = isset( $_GET['iface'] ) ? strtolower( preg_replace( '/[^a-z]/i', '', (string) $_GET['iface'] ) ) : '';
		$role = ( ! empty( $username ) ) ? 'user' : 'visitor';
		if ( isset( $user['permissions'] ) && $user['permissions'] === 'admin' ) {
			$role = 'admin';
		}
		// Admins see the full FQS catalogue here (this module); visitors only TEITOK-openable,
		// unless the installation lists the corpora of every interface (corpuslist="all").
		// Catalog edits belong in action=fqsadmin, not this list.
		$showAllFqs = ( $role === 'admin' );
		$listAll = function_exists( 'getset' ) && strtolower( trim( (string) getset( 'flexicorp/corpuslist', '' ) ) ) === 'all';
		$searchFrom = function_exists( 'getset' ) ? (int) getset( 'flexicorp/searchfrom', 8 ) : 8;
		if ( $searchFrom < 1 ) $searchFrom = 8;

		$corplist = array();
		$facetDict = array();
		$listSource = '';
		$listError = '';

		$base = tt_fqs_browse_url();
		$qs = array(
			'view' => 'browse',
			'request_role' => $role,
		);
		if ( ! $showAllFqs && ! $listAll ) {
			$qs['frontend'] = 'teitok';
		}
		if ( $qSearch !== '' ) $qs['q'] = $qSearch;
		$listUrl = $base . '/corpora?' . http_build_query( $qs );
		foreach ( $selectedFacets as $f ) {
			$listUrl .= '&facet=' . rawurlencode( $f );
		}
		$labelsQs = array( 'request_role' => $role );
		if ( ! $showAllFqs && ! $listAll ) {
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
				if ( ! $showAllFqs && ! $listAll && empty( $GLOBALS['wewanttoseemore'] )
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

		// The public list: TEITOK projects, or with corpuslist="all" every corpus FQS lets
		// this user see. Admins additionally get the other catalogue rows in a table below.
		$publicRows = array();
		$otherRows = array();
		foreach ( $corplist as $corp ) {
			if ( is_object( $corp ) ) $corp = (array) json_decode( json_encode( $corp ), true );
			if ( ! is_array( $corp ) ) continue;
			if ( $listAll || tt_fqs_row_is_teitok_listable( $corp ) ) {
				$publicRows[] = $corp;
			} elseif ( $showAllFqs || ! empty( $GLOBALS['wewanttoseemore'] ) ) {
				$otherRows[] = $corp;
			}
		}
		usort( $publicRows, function ( $a, $b ) {
			$la = isset( $a['label'] ) ? (string) $a['label'] : ( isset( $a['id'] ) ? (string) $a['id'] : '' );
			$lb = isset( $b['label'] ) ? (string) $b['label'] : ( isset( $b['id'] ) ? (string) $b['id'] : '' );
			return strcasecmp( $la, $lb );
		} );

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

		/* ---- The public corpus list ------------------------------------------------
		 *
		 * Plain HTML (h1/h2, ul/li, p, a, a GET form) with class names only, so that the
		 * TEITOK skin of the installation (templates/main.tpl and its CSS) decides how it looks.
		 * The few default rules below use :where(), which has no specificity, so any rule in
		 * the skin overrides them; only the list reset (no bullets, no indent) is a plain
		 * ul.corpuslist rule, to win over a skin's general ul rules.
		 *
		 * Texts the installation can edit (TEITOK pages, per language like other pages:
		 * corpuslist-cs.html, ...; looked for in the project, then in shared/Pages):
		 *   Pages/corpuslist.html         replaces the default heading and introduction
		 *   Pages/corpuslist-footer.html  shown below the list
		 *
		 * Settings (<flexicorp .../> in the settings of the project that shows the list):
		 *   corpuslist="all"   list every corpus in FQS this user may see, also those served
		 *                      by KonText, CQPweb, Korp or only through FCS; the default
		 *                      ("teitok") lists only TEITOK projects
		 *   searchfrom="8"     show the search box and filters from this many corpora on
		 *
		 * The description of a corpus is, in this order: Pages/description.html of the
		 * project (per language, like other pages), the description in the FQS catalogue,
		 * the first paragraph of the project's home page, or else a description put
		 * together from the project itself (documents, tokens, annotation, metadata).
		 */

		if ( ! function_exists( 'tt_fqs_plain_text' ) ) {
			function tt_fqs_plain_text( $html ) {
				$t = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/si', ' ', (string) $html );
				$t = html_entity_decode( strip_tags( $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return trim( preg_replace( '/\s+/u', ' ', $t ) );
			}
		}

		if ( ! function_exists( 'tt_fqs_shorten' ) ) {
			/** Cut a plain text at a sentence (or else word) boundary near $max characters. */
			function tt_fqs_shorten( $text, $max = 320 ) {
				if ( mb_strlen( $text, 'UTF-8' ) <= $max ) return $text;
				$cut = mb_substr( $text, 0, $max, 'UTF-8' );
				if ( preg_match( '/^(.{120,}[.!?])\s/su', $cut, $m ) ) return $m[1];
				$sp = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
				if ( $sp !== false && $sp > 80 ) $cut = mb_substr( $cut, 0, $sp, 'UTF-8' );
				return rtrim( $cut, " ,;:-" ) . '…';
			}
		}

		if ( ! function_exists( 'tt_fqs_number' ) ) {
			function tt_fqs_number( $n ) {
				$n = (float) $n;
				if ( $n >= 1e9 ) return rtrim( rtrim( number_format( $n / 1e9, 1 ), '0' ), '.' ) . ' {%billion}';
				if ( $n >= 1e7 ) return rtrim( rtrim( number_format( $n / 1e6, 1 ), '0' ), '.' ) . ' {%million}';
				return number_format( $n );
			}
		}

		if ( ! function_exists( 'tt_fqs_tr' ) ) {
			/** A display text from a settings file as a TEITOK translation key: {%Lemma}. */
			function tt_fqs_tr( $display ) {
				$d = trim( preg_replace( '/\{%(.*?)\}/', '$1', (string) $display ) );
				return $d === '' ? '' : '{%' . htmlspecialchars( $d, ENT_QUOTES, 'UTF-8' ) . '}';
			}
		}

		if ( ! function_exists( 'tt_fqs_language_name' ) ) {
			function tt_fqs_language_name( $code ) {
				global $lang;
				$code = trim( (string) $code );
				if ( $code !== '' && class_exists( 'Locale' ) ) {
					$name = Locale::getDisplayLanguage( $code, $lang ? $lang : 'en' );
					if ( is_string( $name ) && $name !== '' && strcasecmp( $name, $code ) !== 0 ) {
						return mb_strtoupper( mb_substr( $name, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $name, 1, null, 'UTF-8' );
					}
				}
				// without PHP's intl extension: the more common languages, in English
				static $names = array( 'ar' => 'Arabic', 'bg' => 'Bulgarian', 'ca' => 'Catalan', 'cs' => 'Czech',
					'cy' => 'Welsh', 'da' => 'Danish', 'de' => 'German', 'el' => 'Greek', 'en' => 'English',
					'es' => 'Spanish', 'et' => 'Estonian', 'eu' => 'Basque', 'fa' => 'Persian', 'fi' => 'Finnish',
					'fr' => 'French', 'ga' => 'Irish', 'gl' => 'Galician', 'grc' => 'Ancient Greek', 'he' => 'Hebrew',
					'hi' => 'Hindi', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'hy' => 'Armenian', 'is' => 'Icelandic',
					'it' => 'Italian', 'ja' => 'Japanese', 'ka' => 'Georgian', 'ko' => 'Korean', 'la' => 'Latin',
					'lt' => 'Lithuanian', 'lv' => 'Latvian', 'mt' => 'Maltese', 'nl' => 'Dutch', 'no' => 'Norwegian',
					'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian', 'sk' => 'Slovak',
					'sl' => 'Slovenian', 'sq' => 'Albanian', 'sr' => 'Serbian', 'sv' => 'Swedish', 'ta' => 'Tamil',
					'tr' => 'Turkish', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'vi' => 'Vietnamese', 'zh' => 'Chinese' );
				$lc = strtolower( $code );
				return isset( $names[ $lc ] ) ? '{%' . $names[ $lc ] . '}' : $code;
			}
		}

		if ( ! function_exists( 'tt_fqs_row_languages' ) ) {
			function tt_fqs_row_languages( array $corp ) {
				$langs = array();
				if ( ! empty( $corp['facets']['lang'] ) && is_array( $corp['facets']['lang'] ) ) $langs = $corp['facets']['lang'];
				if ( empty( $langs ) && isset( $corp['capabilities']['fcs']['languages'] ) && is_array( $corp['capabilities']['fcs']['languages'] ) ) $langs = $corp['capabilities']['fcs']['languages'];
				if ( empty( $langs ) && isset( $corp['settings']['languages'] ) && is_array( $corp['settings']['languages'] ) ) $langs = $corp['settings']['languages'];
				$out = array();
				foreach ( $langs as $lc ) {
					$lc = strtolower( trim( (string) $lc ) );
					if ( $lc === '' || in_array( $lc, array( 'und', 'unk', 'unknown', 'zxx', 'mul' ), true ) ) continue;
					$out[ $lc ] = $lc;
				}
				return array_values( $out );
			}
		}

		if ( ! function_exists( 'tt_fqs_local_project_dir' ) ) {
			/**
			 * The folder of a TEITOK project on this server, from its project_url
			 * (/teitok/name/index.php or http://host/teitok/name/), or false.
			 */
			function tt_fqs_local_project_dir( $url ) {
				$url = trim( (string) $url );
				if ( $url === '' ) return false;
				$path = (string) parse_url( $url, PHP_URL_PATH );
				$host = (string) parse_url( $url, PHP_URL_HOST );
				if ( $host !== '' && isset( $_SERVER['HTTP_HOST'] )
					&& strcasecmp( preg_replace( '/:\d+$/', '', $host ), preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) !== 0 ) {
					return false;
				}
				$path = preg_replace( '#/(index\.php)?$#', '', $path );
				if ( $path === '' || strpos( $path, '..' ) !== false ) return false;
				$cands = array();
				if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) $cands[] = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' ) . $path;
				// projects are usually siblings of the project showing the list (behind a
				// proxy the URL path need not match the document root)
				$cwd = getcwd();
				if ( $cwd !== false ) $cands[] = dirname( $cwd ) . '/' . basename( $path );
				foreach ( $cands as $d ) {
					if ( is_file( "$d/index.php" ) && is_dir( "$d/Resources" ) ) return realpath( $d );
				}
				return false;
			}
		}

		if ( ! function_exists( 'tt_fqs_project_page' ) ) {
			/** A page of another project, in the current language if there is one. */
			function tt_fqs_project_page( $dir, $id ) {
				global $lang;
				$deflang = function_exists( 'getset' ) ? getset( 'languages/default', 'en' ) : 'en';
				foreach ( array_unique( array( "$id-$lang", $id, "$id-$deflang" ) ) as $p ) {
					if ( is_file( "$dir/Pages/$p.html" ) ) return array( (string) file_get_contents( "$dir/Pages/$p.html" ), "$dir/Pages/$p.html" );
					if ( is_file( "$dir/Pages/$p.md" ) ) {
						$md = (string) file_get_contents( "$dir/Pages/$p.md" );
						return array( function_exists( 'md2html' ) ? md2html( $md ) : nl2br( htmlspecialchars( $md ) ), "$dir/Pages/$p.md" );
					}
				}
				return array( '', '' );
			}
		}

		if ( ! function_exists( 'tt_fqs_count_documents' ) ) {
			/** XML files under xmlfiles/, cached in tmp/ of the project showing the list. */
			function tt_fqs_count_documents( $dir ) {
				static $cache = null;
				$cfile = 'tmp/fqs-corpuslist-cache.json';
				if ( $cache === null ) {
					$cache = is_readable( $cfile ) ? json_decode( (string) file_get_contents( $cfile ), true ) : array();
					if ( ! is_array( $cache ) ) $cache = array();
				}
				if ( ! is_dir( "$dir/xmlfiles" ) ) return null;
				$sig = @filemtime( "$dir/xmlfiles" ) . '/' . @filemtime( "$dir/pando/corpus.info" ) . '/' . @filemtime( "$dir/cqp" );
				if ( isset( $cache[ $dir ] ) && $cache[ $dir ]['sig'] === $sig ) return $cache[ $dir ]['documents'];
				$n = 0;
				try {
					$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$dir/xmlfiles", FilesystemIterator::SKIP_DOTS ) );
					foreach ( $it as $f ) {
						if ( substr( $f->getFilename(), -4 ) === '.xml' ) $n++;
					}
				} catch ( Exception $e ) {
					return null;
				}
				$cache[ $dir ] = array( 'sig' => $sig, 'documents' => $n );
				if ( is_dir( 'tmp' ) || @mkdir( 'tmp' ) ) {
					@file_put_contents( $cfile, json_encode( $cache ) );
				}
				return $n;
			}
		}

		if ( ! function_exists( 'tt_fqs_project_summary' ) ) {
			/**
			 * What a local TEITOK project says about itself: its description (and where it
			 * came from), number of documents, annotation layers, metadata fields, extra
			 * material, and the menu actions for searching and for the documents.
			 */
			function tt_fqs_project_summary( $dir ) {
				$sum = array( 'description' => '', 'source' => '', 'documents' => null,
					'annotation' => array(), 'metadata' => array(), 'material' => array(),
					'search' => '', 'docs' => '' );

				list( $html, $file ) = tt_fqs_project_page( $dir, 'description' );
				if ( trim( tt_fqs_plain_text( $html ) ) !== '' ) {
					$sum['description'] = trim( strip_tags( $html, '<a><em><i><b><strong><br>' ) );
					$sum['source'] = 'page';
				} else {
					list( $home, $file ) = tt_fqs_project_page( $dir, 'home' );
					$home = preg_replace( '/<h1[^>]*>.*?<\/h1>/si', '', $home );
					$para = preg_match( '/<p[^>]*>(.*?)(<\/p>|<p[\s>]|$)/si', $home, $m ) ? $m[1] : $home;
					$text = tt_fqs_plain_text( $para );
					// the texts TEITOK and the installer put on a new project's home page
					$stock = '/^(New TEITOK project|This is a new TEITOK project|Welcome to (your|the) new)|please check (in )?back later|created by the installer/i';
					// a bare URL reads badly in a short description: keep only its host
					$text = preg_replace( '#\bhttps?://([^/\s]+)[^\s;,)]*#i', '$1', $text );
					if ( $text !== '' && ! preg_match( $stock, $text ) ) {
						$sum['description'] = htmlspecialchars( tt_fqs_shorten( $text ), ENT_QUOTES, 'UTF-8' );
						$sum['source'] = 'home';
					}
				}

				$sum['documents'] = tt_fqs_count_documents( $dir );

				$xml = is_readable( "$dir/Resources/settings.xml" ) ? @simplexml_load_file( "$dir/Resources/settings.xml" ) : false;
				if ( $xml ) {
					$seen = array();
					$add = function ( &$list, $node, $skip = array() ) use ( &$seen ) {
						$key = (string) $node['key'];
						if ( $key === '' || in_array( $key, $skip, true ) || isset( $seen[ $key ] ) ) return;
						if ( (string) $node['admin'] === '1' || (string) $node['noshow'] === '1' ) return;
						$seen[ $key ] = 1;
						$d = (string) $node['display'];
						$list[] = $d !== '' ? $d : $key;
					};
					// annotation: the token attributes of the XML files, else those of the index
					foreach ( $xml->xpath( '/ttsettings/xmlfile/pattributes/forms/item' ) as $it ) $add( $sum['annotation'], $it, array( 'form', 'pform' ) );
					foreach ( $xml->xpath( '/ttsettings/xmlfile/pattributes/tags/item' ) as $it ) $add( $sum['annotation'], $it, array( 'head', 'ohead', 'id', 'ord' ) );
					if ( empty( $sum['annotation'] ) ) {
						foreach ( $xml->xpath( '/ttsettings/cqp/pattributes/item' ) as $it ) $add( $sum['annotation'], $it, array( 'word', 'form', 'pform', 'id', 'head', 'ohead', 'ord' ) );
					}
					// metadata: the document-level fields of the corpus index
					foreach ( $xml->xpath( '/ttsettings/cqp/sattributes/item[@level="text" or @key="text"]/item' ) as $it ) {
						$add( $sum['metadata'], $it, array( 'id' ) );
					}
					// search and documents: what the project's own menu offers
					$menu = array();
					foreach ( $xml->xpath( '/ttsettings/menu//item' ) as $it ) $menu[] = (string) $it['key'];
					foreach ( array( 'flexicorp', 'cqp', 'multisearch', 'search', 'fwsearch' ) as $k ) {
						if ( in_array( $k, $menu, true ) ) { $sum['search'] = $k; break; }
					}
					foreach ( array( 'browser', 'docsearch', 'files' ) as $k ) {
						if ( in_array( $k, $menu, true ) ) { $sum['docs'] = $k; break; }
					}
				}
				if ( is_dir( "$dir/Facsimile" ) && count( (array) @scandir( "$dir/Facsimile" ) ) > 2 ) $sum['material'][] = 'Facsimile images';
				if ( is_dir( "$dir/Audio" ) && count( (array) @scandir( "$dir/Audio" ) ) > 2 ) $sum['material'][] = 'Audio';
				if ( is_dir( "$dir/Video" ) && count( (array) @scandir( "$dir/Video" ) ) > 2 ) $sum['material'][] = 'Video';
				return $sum;
			}
		}

		if ( ! function_exists( 'tt_fqs_join' ) ) {
			function tt_fqs_join( array $items ) {
				$items = array_values( array_filter( array_map( 'tt_fqs_tr', $items ) ) );
				if ( count( $items ) < 2 ) return implode( '', $items );
				$last = array_pop( $items );
				return implode( ', ', $items ) . ' {%and} ' . $last;
			}
		}

		if ( ! function_exists( 'tt_fqs_row_interface' ) ) {
			/**
			 * Where a catalogue row opens: array( kind, label, url ). TEITOK projects first,
			 * then KonText, CQPweb, Korp, NoSketch Engine; else FCS only.
			 */
			function tt_fqs_row_interface( array $corp ) {
				if ( tt_fqs_row_is_teitok_listable( $corp ) ) {
					return array( 'teitok', 'TEITOK', tt_fqs_select_url( $corp ) );
				}
				$fe = isset( $corp['frontends'] ) && is_array( $corp['frontends'] ) ? $corp['frontends'] : array();
				foreach ( array( 'kontext', 'cqpweb', 'korp', 'noske' ) as $want ) {
					foreach ( $fe as $f ) {
						if ( ! is_array( $f ) || ( isset( $f['kind'] ) ? $f['kind'] : '' ) !== $want || empty( $f['url'] ) ) continue;
						$url = (string) $f['url'];
						$c = isset( $f['corpus'] ) ? (string) $f['corpus'] : '';
						if ( $want === 'kontext' && $c !== '' ) $url .= '/query?corpname=' . rawurlencode( $c );
						return array( $want, isset( $f['label'] ) ? (string) $f['label'] : $want, $url );
					}
				}
				$pref = isset( $corp['interface_preference'] ) ? strtolower( trim( (string) $corp['interface_preference'] ) ) : '';
				$purl = isset( $corp['project_url'] ) ? trim( (string) $corp['project_url'] ) : '';
				if ( $pref !== '' && $purl !== '' && $pref !== 'teitok' ) {
					$labels = array( 'kontext' => 'KonText', 'cqpweb' => 'CQPweb', 'korp' => 'Korp', 'noske' => 'NoSketch Engine' );
					return array( $pref, isset( $labels[ $pref ] ) ? $labels[ $pref ] : $pref, $purl );
				}
				return array( 'fcs', 'CLARIN FCS', '' );
			}
		}

		if ( ! function_exists( 'tt_fqs_relative_href' ) ) {
			/**
			 * A server path (/teitok/x/index.php) as a link relative to this page, so that it
			 * also works when a proxy serves the site under another prefix
			 * (https://host/services/test-kontext/teitok/). Other URLs stay as they are.
			 */
			function tt_fqs_relative_href( $url ) {
				$url = (string) $url;
				if ( $url === '' || $url[0] !== '/' || substr( $url, 0, 2 ) === '//' ) return $url;
				$q = '';
				if ( ( $i = strpos( $url, '?' ) ) !== false ) { $q = substr( $url, $i ); $url = substr( $url, 0, $i ); }
				$sn = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
				if ( $sn === '' ) return $url . $q;
				$from = array_values( array_filter( explode( '/', trim( str_replace( '\\', '/', dirname( $sn ) ), '/' ) ), 'strlen' ) );
				$to = array_values( array_filter( explode( '/', trim( $url, '/' ) ), 'strlen' ) );
				$trail = substr( $url, -1 ) === '/';
				while ( $from && $to && $from[0] === $to[0] ) { array_shift( $from ); array_shift( $to ); }
				$rel = str_repeat( '../', count( $from ) ) . implode( '/', $to );
				if ( $rel === '' ) $rel = './';
				elseif ( $trail && substr( $rel, -1 ) !== '/' ) $rel .= '/';
				return $rel . $q;
			}
		}

		if ( ! function_exists( 'tt_fqs_render_corpus_item' ) ) {
			function tt_fqs_render_corpus_item( array $corp, $showInterface ) {
				global $username;
				$id = isset( $corp['id'] ) ? (string) $corp['id'] : '';
				$label = isset( $corp['label'] ) && trim( (string) $corp['label'] ) !== '' ? (string) $corp['label'] : $id;
				list( $kind, $ifLabel, $url ) = tt_fqs_row_interface( $corp );
				$h = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };

				$dir = $kind === 'teitok' ? tt_fqs_local_project_dir( $url ) : false;
				// a project on this server: a relative link (also right behind a path-prefix proxy)
				if ( $kind === 'teitok' ) $url = tt_fqs_relative_href( $url );
				$sum = $dir ? tt_fqs_project_summary( $dir ) : null;
				$base = $url;
				if ( $base !== '' && substr( $base, -1 ) === '/' ) $base .= 'index.php';

				$current = tt_fqs_corpus_is_current_project( $corp );
				$out = '<li class="corpus corpus-' . $h( $kind ) . ( $current ? ' current' : '' ) . '"'
					. ( $id !== '' ? ' id="corpus-' . $h( preg_replace( '/[^A-Za-z0-9_.-]/', '_', $id ) ) . '"' : '' ) . '>';

				// name and languages
				$out .= '<div class="corpus-head"><h2>' . ( $url !== '' ? '<a href="' . $h( $url ) . '">' . $h( $label ) . '</a>' : $h( $label ) ) . '</h2>';
				$langs = array_map( 'tt_fqs_language_name', tt_fqs_row_languages( $corp ) );
				if ( $langs ) {
					$out .= '<p class="corpus-langs">';
					foreach ( $langs as $i => $ln ) $out .= ( $i ? '<span class="corpus-sep">, </span>' : '' ) . '<span class="corpus-lang">' . $h( $ln ) . '</span>';
					$out .= '</p>';
				}
				$out .= '</div>';

				// description: project page > catalogue > home page > made from the project
				$desc = '';
				$auto = false;
				if ( $sum && $sum['source'] === 'page' ) {
					$desc = $sum['description'];
				} elseif ( ! empty( $corp['description'] ) ) {
					$desc = $h( tt_fqs_shorten( tt_fqs_plain_text( $corp['description'] ), 600 ) );
				} elseif ( $sum && $sum['source'] === 'home' ) {
					$desc = $sum['description'];
					$auto = true;
				}
				$size = isset( $corp['corpus_size'] ) && is_numeric( $corp['corpus_size'] ) ? (float) $corp['corpus_size'] : null;
				$docs = $sum ? $sum['documents'] : null;
				if ( $desc === '' && $sum ) {
					// nothing written about the corpus: say what is in it (the annotation and
					// the size are shown below as tags and figures)
					$auto = true;
					if ( ! $docs && ! $size ) {
						$desc = '{%There are no documents in this corpus yet.}';
					} else {
						$parts = array();
						if ( $sum['metadata'] ) $parts[] = '{%Documents with metadata on} ' . tt_fqs_join( $sum['metadata'] );
						if ( $sum['material'] ) $parts[] = ( $parts ? '{%with}' : '{%With}' ) . ' ' . tt_fqs_join( $sum['material'] );
						if ( $parts ) $desc = implode( ', ', $parts ) . '.';
					}
				}
				if ( $desc !== '' ) {
					$out .= '<p class="corpus-description' . ( $auto ? ' corpus-description-auto' : '' ) . '">' . $desc;
					if ( $auto && $username && $sum ) {
						// the people who can write it see how to replace the automatic text
						$out .= ' <span class="adminpart"><a href="' . $h( $base . '?action=pageedit&id=description' ) . '">{%write a description}</a></span>';
					}
					$out .= '</p>';
				}

				// tags: the annotation of a local project, else the catalogue's feature labels
				$tags = array();
				$empty = $sum && ! $docs && ! $size;   // annotation of an empty project is only its template
				if ( $empty ) {
				} elseif ( $sum && $sum['annotation'] ) {
					foreach ( array_slice( $sum['annotation'], 0, 6 ) as $a ) $tags[] = tt_fqs_tr( $a );
				} elseif ( ! empty( $corp['facets']['feature'] ) && is_array( $corp['facets']['feature'] ) ) {
					foreach ( $corp['facets']['feature'] as $f ) $tags[] = tt_fqs_feature_name( $f );
				}
				if ( $sum && $sum['material'] ) foreach ( $sum['material'] as $m ) $tags[] = tt_fqs_tr( $m );
				if ( ! empty( $corp['facets']['other'] ) && is_array( $corp['facets']['other'] ) && in_array( 'demo', $corp['facets']['other'], true ) ) {
					$tags[] = '<span class="corpus-tag corpus-tag-demo">{%Demo}</span>';
				}
				if ( $tags ) {
					$out .= '<p class="corpus-tags">';
					foreach ( $tags as $i => $t ) {
						$out .= ( $i ? '<span class="corpus-sep">, </span>' : '' ) . ( strpos( $t, '<span' ) === 0 ? $t : '<span class="corpus-tag">' . $t . '</span>' );
					}
					$out .= '</p>';
				}

				// figures and links, at the bottom of the card
				$facts = array();
				if ( $size ) $facts[] = '<span><b>' . tt_fqs_number( $size ) . '</b> {%tokens}</span>';
				if ( $docs ) $facts[] = '<span><b>' . tt_fqs_number( $docs ) . '</b> ' . ( $docs == 1 ? '{%document}' : '{%documents}' ) . '</span>';
				if ( $showInterface ) $facts[] = '<span class="corpus-interface">' . $h( $ifLabel ) . '</span>';

				$links = array();
				$icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>';
				if ( $kind === 'teitok' ) {
					// the first link is the main one (a filled button in the default style)
					if ( $sum && $sum['search'] !== '' ) $links[] = '<a class="corpus-main" href="' . $h( $base . '?action=' . $sum['search'] ) . '">' . $icon . '<span>{%Search}</span></a>';
					if ( $sum && $sum['docs'] !== '' ) $links[] = '<a class="corpus-second" href="' . $h( $base . '?action=' . $sum['docs'] ) . '">{%Browse documents}</a>';
					if ( $url !== '' ) $links[] = '<a class="' . ( $links ? 'corpus-more' : 'corpus-main' ) . '" href="' . $h( $url ) . '">' . ( $links ? '{%About}' : '<span>{%Open}</span>' ) . '</a>';
				} elseif ( $url !== '' ) {
					$links[] = '<a class="corpus-main" href="' . $h( $url ) . '"><span>{%Open in} ' . $h( $ifLabel ) . '</span></a>';
				}
				if ( $facts || $links ) {
					$out .= '<div class="corpus-foot">';
					if ( $facts ) $out .= '<p class="corpus-facts">' . implode( '<span class="corpus-sep"> · </span>', $facts ) . '</p>';
					if ( $links ) $out .= '<p class="corpus-links">' . implode( ' ', $links ) . '</p>';
					$out .= '</div>';
				}
				return $out . "</li>\n";
			}
		}

		if ( ! function_exists( 'tt_fqs_feature_name' ) ) {
			/** The feature labels FQS's enrich sets, in words. */
			function tt_fqs_feature_name( $val ) {
				// FQS's enrich sets dependencies, ud, spoken, facsimile, video, ner, parallel, geolocation
				$names = array( 'deps' => 'Dependency syntax', 'dependencies' => 'Dependency syntax',
					'ud' => 'Universal Dependencies', 'spoken' => 'Spoken', 'audio' => 'Audio', 'video' => 'Video',
					'facs' => 'Facsimile images', 'facsimile' => 'Facsimile images', 'ner' => 'Named entities',
					'aligned' => 'Parallel', 'parallel' => 'Parallel', 'geolocation' => 'Geolocation',
					'lemma' => 'Lemmas', 'pos' => 'Parts of speech', 'morph' => 'Morphology', 'demo' => 'Demo' );
				$k = strtolower( trim( (string) $val ) );
				return isset( $names[ $k ] ) ? '{%' . $names[ $k ] . '}' : htmlspecialchars( (string) $val, ENT_QUOTES, 'UTF-8' );
			}
		}

		// Heading and introduction: Pages/corpuslist.html of this project (or of shared),
		// editable like any other TEITOK page; else a plain default.
		global $getlangfile_lastfolder;
		$intro = function_exists( 'getlangfile' ) ? getlangfile( 'corpuslist' ) : '';
		if ( empty( $getlangfile_lastfolder ) ) {
			$intro = '<h1>{%Available Corpora}</h1>' . $intro;
		}

		// Default look: cards, close to a designed landing page, but tied to the
		// installation's own style:
		// - fonts and the page heading are the installation's (htmlstyles.css); the accent
		//   colour (buttons, language chips, selected filters) is its link colour, read by
		//   the small script below;
		// - all rules are scoped to .corpuslist-page, so the general rules of htmlstyles.css
		//   (p, ul, li, h2, a) do not leak into the cards, while any rule in htmlstyles.css
		//   that names these classes with a little more weight (body .corpuslist-page ...,
		//   #main .corpus ...) wins;
		// - and variables, for htmlstyles.css: --corpus-accent (else the link colour),
		//   --corpus-bg, --corpus-border, --corpus-radius, --corpus-shadow, --corpus-title,
		//   --corpus-text, --corpus-muted, --corpus-title-font, --corpus-font-size,
		//   --corpuslist-min (card width), --corpuslist-gap.
		// <flexicorp corpuslist_css="none"/> leaves all of it out: plain HTML for a style of its own.
		$corpusCss = ! function_exists( 'getset' ) || getset( 'flexicorp/corpuslist_css', '' ) !== 'none';
		if ( $corpusCss ) {
			$P = '.corpuslist-page';
			$maintext .= '<style>'
				. "$P{--ca:var(--corpus-accent,var(--corpus-link,#0b5e63));--cb:var(--corpus-border,#dce2e8);"
				. '--ct:var(--corpus-text,#3a4654);--cm:var(--corpus-muted,#5a6675);--cs:var(--corpus-title,#121a24);'
				. 'font-size:var(--corpus-font-size,1rem);line-height:1.5;max-width:75em}'
				. "$P .corpuslist-intro p{font-size:1.125em;line-height:1.55;color:var(--ct);max-width:46em;margin:.5em 0}"
				. "$P p.corpus-summary{display:flex;flex-wrap:wrap;gap:.3em 1.5em;margin:1em 0 1.75em;font-size:.95em;color:var(--ct)}"
				. "$P .corpus-summary b,$P .corpus-facts b{color:var(--cs);font-weight:600}"
				. "$P .corpus-sep{display:none}"
				// cards
				. "$P ul.corpuslist{list-style:none;list-style-image:none;margin:0 0 2em;padding:0;display:grid;"
				. 'grid-template-columns:repeat(auto-fill,minmax(min(100%,var(--corpuslist-min,20em)),1fr));gap:var(--corpuslist-gap,1.5em)}'
				. "$P li.corpus{margin:0;padding:1.5em;display:flex;flex-direction:column;gap:1em;min-width:0;overflow-wrap:anywhere;"
				. 'background:var(--corpus-bg,#fff);border:1px solid var(--cb);border-radius:var(--corpus-radius,12px);'
				. 'box-shadow:var(--corpus-shadow,0 1px 2px rgba(16,24,40,.05),0 4px 14px rgba(16,24,40,.06))}'
				. "$P li.corpus.current{border-color:var(--ca)}"
				. "$P li.corpus p{margin:0;font-size:1em;font-family:inherit}"
				. "$P .corpus-head{display:flex;justify-content:space-between;align-items:flex-start;gap:.75em}"
				. "$P .corpus h2{margin:0;padding:0;border:0;width:auto;font-size:1.45em;line-height:1.25;font-weight:600;color:var(--cs);font-family:var(--corpus-title-font,inherit)}"
				. "$P .corpus h2 a{color:var(--cs);text-decoration:none}"
				. "$P .corpus h2 a:hover{color:var(--ca)}"
				. "$P p.corpus-langs{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.3em;flex-shrink:0;max-width:50%}"
				. "$P .corpus-lang{font-size:.8125em;font-weight:600;padding:.25em .75em;border-radius:999px;white-space:nowrap;"
				. 'color:var(--ca);background:#eef2f4;background:color-mix(in srgb,var(--ca) 10%,#fff)}'
				. "$P p.corpus-description{color:var(--ct);line-height:1.55}"
				. "$P p.corpus-tags{display:flex;flex-wrap:wrap;gap:.5em}"
				. "$P .corpus-tag{font-size:.8125em;padding:.2em .65em;border:1px solid var(--cb);border-radius:6px;color:var(--ct)}"
				. "$P .corpus-tag-demo{background:#fff4e5;border-color:#f1d6ae;color:#7a4a0b}"
				. "$P .corpus-foot{margin-top:auto;display:flex;flex-direction:column;gap:1em}"
				. "$P p.corpus-facts{display:flex;flex-wrap:wrap;gap:.3em 1.5em;font-size:.875em;color:var(--cm);border-top:1px solid #edf0f3;padding-top:.9em}"
				. "$P .corpus-interface{margin-left:auto}"
				. "$P p.corpus-links{display:flex;flex-wrap:wrap;gap:.75em;align-items:center}"
				. "$P .corpus-links a{display:inline-flex;align-items:center;gap:.5em;min-height:2.75em;padding:0 1.1em;box-sizing:border-box;"
				. 'border:1px solid #c9d2db;border-radius:8px;font-size:.95em;font-weight:500;text-decoration:none;color:var(--cs);background:transparent}'
				. "$P .corpus-links a:hover{border-color:var(--ca);color:var(--ca)}"
				. "$P .corpus-links a.corpus-main{background:var(--ca);border-color:var(--ca);color:var(--corpus-button-text,#fff);font-weight:600}"
				. "$P .corpus-links a.corpus-main:hover{filter:brightness(.9);color:var(--corpus-button-text,#fff)}"
				. "$P .corpus-links a.corpus-more{border-color:transparent;padding:0 .4em;color:var(--ca)}"
				. "$P .corpus-links a.corpus-more:hover{text-decoration:underline}"
				// search and filters
				. "$P form.corpusfilter{margin:0 0 1.75em;display:flex;flex-direction:column;gap:.75em}"
				. "$P form.corpusfilter p{margin:0;display:flex;flex-wrap:wrap;gap:.6em;align-items:center}"
				. "$P .corpusfilter label.corpusfilter-q{flex:1 1 22em;display:flex}"
				. "$P .corpusfilter input[type=search]{flex:1;min-height:2.9em;padding:0 1em;box-sizing:border-box;border:1px solid #c9d2db;border-radius:8px;font:inherit;background:#fff}"
				. "$P .corpusfilter input[type=submit]{min-height:2.9em;padding:0 1.3em;border:1px solid var(--ca);border-radius:8px;background:var(--ca);color:var(--corpus-button-text,#fff);font:inherit;font-weight:600;cursor:pointer}"
				. "$P .corpusfilter fieldset{border:0;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.5em;align-items:center}"
				. "$P .corpusfilter legend{float:left;padding:0;margin-right:.5em;font-size:.875em;color:var(--cm)}"
				. "$P .corpusfilter fieldset label{display:inline-flex;align-items:center;gap:.35em;padding:.3em .85em;border:1px solid #c9d2db;border-radius:999px;background:#fff;font-size:.875em;cursor:pointer}"
				. "$P .corpusfilter fieldset label:has(input:checked){border-color:var(--ca);color:var(--ca);font-weight:600;background:color-mix(in srgb,var(--ca) 10%,#fff)}"
				. "$P .corpusfilter fieldset input{margin:0}"
				. "$P .corpus-vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}"
				. "$P .corpuslist-footer{border-top:1px solid var(--cb);padding-top:1em;color:var(--cm);font-size:.9em}"
				. '</style>';
		}
		$maintext .= "<div class='corpuslist-page'><div class='corpuslist-intro'>" . $intro . '</div>';


		// Interfaces among the corpora (only with corpuslist="all"); the choice is only
		// offered when there is more than one.
		$ifaceKinds = array();
		if ( $listAll ) {
			foreach ( $publicRows as $corp ) {
				list( $k, $l ) = tt_fqs_row_interface( $corp );
				$ifaceKinds[ $k ] = $l;
			}
		}
		$showInterface = count( $ifaceKinds ) > 1;
		$listRows = $publicRows;
		if ( $showInterface && $iface !== '' && isset( $ifaceKinds[ $iface ] ) ) {
			$listRows = array_values( array_filter( $publicRows, function ( $c ) use ( $iface ) {
				list( $k ) = tt_fqs_row_interface( $c );
				return $k === $iface;
			} ) );
		} else {
			$iface = '';
		}

		$filtering = ( $qSearch !== '' || $selectedFacets || $iface !== '' );
		$total = count( $publicRows );

		// Summary line, for more than one corpus
		if ( ! $filtering && $total > 1 ) {
			$tokens = 0;
			$langset = array();
			foreach ( $publicRows as $corp ) {
				if ( isset( $corp['corpus_size'] ) && is_numeric( $corp['corpus_size'] ) ) $tokens += (float) $corp['corpus_size'];
				foreach ( tt_fqs_row_languages( $corp ) as $lc ) $langset[ $lc ] = 1;
			}
			$bits = array( '<span><b>' . $total . '</b> {%corpora}</span>' );
			if ( $tokens > 0 ) $bits[] = '<span><b>' . tt_fqs_number( $tokens ) . '</b> {%tokens}</span>';
			if ( count( $langset ) > 1 ) $bits[] = '<span><b>' . count( $langset ) . '</b> {%languages}</span>';
			$maintext .= '<p class="corpus-summary">' . implode( '<span class="corpus-sep"> · </span>', $bits ) . '</p>';
		}

		// Search and filters: only from $searchFrom corpora on (or while filtering), and a
		// filter group only when it tells corpora apart.
		if ( $filtering || $total >= $searchFrom ) {
			$self = 'index.php?action=' . rawurlencode( (string) $action );
			$maintext .= "<form method='get' action='index.php' class='corpusfilter'>";
			$maintext .= "<input type='hidden' name='action' value='" . htmlspecialchars( (string) $action, ENT_QUOTES, 'UTF-8' ) . "' />";
			$maintext .= '<p><label class="corpusfilter-q"><span class="corpus-vh">{%Find a corpus}</span>'
				. '<input type="search" name="q" placeholder="{%Find a corpus by name}" value="'
				. htmlspecialchars( $qSearch, ENT_QUOTES, 'UTF-8' ) . '" /></label> '
				. '<input type="submit" value="{%Search}" />'
				. ( $filtering ? " <a href='" . htmlspecialchars( $self, ENT_QUOTES, 'UTF-8' ) . "'>{%Show all}</a>" : '' )
				. '</p>';
			$groups = array(
				'lang' => 'Language',
				'feature' => 'Features',
				'genre' => 'Genre',
				'other' => 'Other',
			);
			foreach ( $groups as $gid => $glabel ) {
				if ( empty( $facetDict[ $gid ] ) || ! is_array( $facetDict[ $gid ] ) ) continue;
				$items = array();
				foreach ( $facetDict[ $gid ] as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['value'] ) || (string) $item['value'] === '' ) continue;
					$items[] = $item;
				}
				$selectedHere = false;
				foreach ( $items as $item ) {
					if ( in_array( $gid . ':' . $item['value'], $selectedFacets, true ) ) $selectedHere = true;
				}
				// a single value that every corpus has does not filter anything
				if ( ! $selectedHere && ( count( $items ) === 0
					|| ( count( $items ) === 1 && ( empty( $items[0]['count'] ) || (int) $items[0]['count'] >= $total ) ) ) ) {
					continue;
				}
				$maintext .= '<fieldset><legend>{%' . $glabel . '}</legend>';
				foreach ( $items as $item ) {
					$val = (string) $item['value'];
					$token = $gid . ':' . $val;
					$cnt = isset( $item['count'] ) ? (int) $item['count'] : 0;
					$chk = in_array( $token, $selectedFacets, true ) ? " checked='checked'" : '';
					$shown = $gid === 'lang' ? htmlspecialchars( tt_fqs_language_name( $val ), ENT_QUOTES, 'UTF-8' ) : tt_fqs_feature_name( $val );
					$maintext .= '<label><input type="checkbox" name="facet[]" value="'
						. htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' ) . '"' . $chk . ' /> '
						. $shown
						. ( $cnt ? ' <small>(' . $cnt . ')</small>' : '' )
						. '</label> ';
				}
				$maintext .= '</fieldset>';
			}
			if ( $showInterface ) {
				$maintext .= '<fieldset><legend>{%Interface}</legend>'
					. '<label><input type="radio" name="iface" value=""' . ( $iface === '' ? " checked='checked'" : '' ) . ' /> {%All}</label> ';
				foreach ( $ifaceKinds as $k => $l ) {
					$maintext .= '<label><input type="radio" name="iface" value="' . htmlspecialchars( $k, ENT_QUOTES, 'UTF-8' ) . '"'
						. ( $iface === $k ? " checked='checked'" : '' ) . ' /> ' . htmlspecialchars( $l, ENT_QUOTES, 'UTF-8' ) . '</label> ';
				}
				$maintext .= '</fieldset>';
			}
			$maintext .= '</form>';
		}

		if ( $listError !== '' ) {
			$maintext .= "<p class=warning>FQS browse unavailable (" . htmlspecialchars( $listError, ENT_QUOTES, 'UTF-8' )
				. '); showing CLI fallback if any.</p>';
		}

		$havethiscorpus = false;
		if ( empty( $listRows ) ) {
			$maintext .= $filtering
				? '<p><em>{%No corpora match your search.}</em></p>'
				: '<p><em>{%There are no corpora to show yet.}</em></p>';
		} else {
			$maintext .= "<ul class='corpuslist'>\n";
			foreach ( $listRows as $corp ) {
				$maintext .= tt_fqs_render_corpus_item( $corp, $showInterface );
				if ( tt_fqs_corpus_is_current_project( $corp ) ) $havethiscorpus = true;
			}
			$maintext .= "</ul>\n";
		}

		if ( function_exists( 'getlangfile' ) ) {
			$footer = getlangfile( 'corpuslist-footer' );
			if ( trim( $footer ) !== '' ) $maintext .= "<div class='corpuslist-footer'>" . $footer . '</div>';
		}
		$maintext .= '</div>';
		if ( $corpusCss ) {
			// the accent colour: the installation's link colour, unless --corpus-accent is set
			$maintext .= "<script>(function(){var w=document.querySelector('.corpuslist-page');if(!w||!window.getComputedStyle)return;"
				. "var a=document.createElement('a');a.href='#';w.parentNode.insertBefore(a,w);var c=getComputedStyle(a).color;"
				. "a.parentNode.removeChild(a);if(c)w.style.setProperty('--corpus-link',c);})();</script>";
		}

		if ( $showAllFqs && ! empty( $otherRows ) ) {
			$maintext .= '<h3>Other corpora in FQS</h3>';
			$maintext .= '<p><small>Registered for query/FCS/KonText etc., but not openable as a TEITOK project from this list'
				. ' (<code>&lt;flexicorp corpuslist="all"/&gt;</code> lists them for everyone).</small></p>';
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

		// a project without corpus data (e.g. the "site" start page) has nothing to register
		$thisIsCorpus = is_dir( 'xmlfiles' ) || is_dir( 'pando' ) || is_dir( 'cqp' );
		if ( empty( $isshared ) && ! $havethiscorpus && $thisIsCorpus ) {
			$maintext .= "<p class=warning>The current corpus is not included in the corpus list.";
			if ( $username ) {
				$maintext .= " <a href='index.php?action=$action&amp;act=addcorpus'>Register this corpus in FQS</a></p>";
			} else {
				$maintext .= '</p>';
			}
		}
	};
	
?>	
