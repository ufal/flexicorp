<?php
/**
 * TEITOK setup wizard (corpus configuration assistant).
 *
 * Corpus paths are always relative to the TEITOK corpus root — the directory that
 * contains index.php for that corpus (see tt_fc_wizard_project_root()).
 *
 * A lightweight corpus-profile assistant that samples XML files and gives
 * concrete setup guidance for spoken data, facsimile, alignment, and geolocation.
 */

/*
 * The wizard is independent of Corpus Search (<code>flexicorp.php</code>). If TEITOK has already
 * registered flexicorp CLI helpers (<code>tt_flexicorp_run</code>, …) before rendering the wizard,
 * the Index(es) tab may show Python-backed overview/freq; otherwise those sections stay empty and
 * XML/CQP-based checks still run.
 */

if (!function_exists('tt_fc_wizard_h')) {
	function tt_fc_wizard_h($v) {
		return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('tt_fc_wizard_send_preview_ajax_json')) {
	/**
	 * Respond to automated-fix “preview merged XML” XHR with JSON (no full-page navigation).
	 */
	function tt_fc_wizard_send_preview_ajax_json($ok, $message, $xml = null) {
		if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		header('Content-Type: application/json; charset=UTF-8');
		$payload = array(
			'ok' => (bool) $ok,
			'message' => (string) $message,
		);
		if (is_string($xml) && $xml !== '') {
			$payload['xml'] = $xml;
		}
		echo json_encode($payload, JSON_UNESCAPED_UNICODE);
		exit;
	}
}

if (!function_exists('tt_fc_wizard_project_root')) {
	/**
	 * TEITOK corpus root: the directory that contains the executed index.php (no alternate corpus root).
	 * Fallback: getcwd() when SCRIPT_FILENAME is not index.php (e.g. CLI).
	 */
	function tt_fc_wizard_project_root() {
		if (!empty($_SERVER['SCRIPT_FILENAME'])) {
			$sf = realpath((string) $_SERVER['SCRIPT_FILENAME']);
			if ($sf !== false && is_file($sf) && strtolower(basename($sf)) === 'index.php') {
				$dir = dirname($sf);
				if ($dir !== '' && is_dir($dir)) {
					return $dir;
				}
			}
		}
		$cwd = getcwd();
		return ($cwd && is_dir($cwd)) ? $cwd : '.';
	}
}

/**
 * Session log of automated settings merges applied during this browser session (per corpus root).
 */
if (!function_exists('tt_fc_wizard_session_ensure')) {
	function tt_fc_wizard_session_ensure() {
		if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
			@session_start();
		}
	}
}

if (!function_exists('tt_fc_wizard_fix_applied_storage_key')) {
	function tt_fc_wizard_fix_applied_storage_key($projectRoot) {
		$r = trim((string) $projectRoot);
		if ($r === '' && function_exists('tt_fc_wizard_project_root')) {
			$r = tt_fc_wizard_project_root();
		}
		$r = trim((string) $r);
		if ($r !== '' && is_dir($r)) {
			$rp = realpath($r);
			if ($rp !== false && $rp !== '') {
				$r = $rp;
			}
		}
		return 'tt_fc_wizard_automated_fixes_' . md5($r !== '' ? $r : '.');
	}
}

if (!function_exists('tt_fc_wizard_project_scope_key')) {
	function tt_fc_wizard_project_scope_key($projectRoot) {
		$r = trim((string) $projectRoot);
		if ($r === '' && function_exists('tt_fc_wizard_project_root')) {
			$r = tt_fc_wizard_project_root();
		}
		$r = trim((string) $r);
		if ($r !== '' && is_dir($r)) {
			$rp = realpath($r);
			if ($rp !== false && $rp !== '') {
				$r = $rp;
			}
		}
		return md5($r !== '' ? $r : '.');
	}
}

if (!function_exists('tt_fc_wizard_fix_code_label')) {
	function tt_fc_wizard_fix_code_label($code) {
		$c = strtolower(trim((string) $code));
		$map = array(
			'alignment_merge' => 'Alignment: merge CQP alignment entries (tuid / s_tuid / optional text_tuid) into Resources/settings.xml',
			'allowlist_u' => 'Spoken: flexicorp context_scope_regions — append or materialize utterance (u)',
			'cqp_u_stub' => 'Spoken: CQP region u with TEI ⟨u⟩ @start / @stop',
			'wavesurfer_tok' => 'Spoken: xmlfile/speech/highlight default TOK',
			'wavesurfer_utt' => 'Spoken: xmlfile/speech/highlight default to utterance tag',
			'cqp_u_audio' => 'Spoken: TEITOK native u_audio (CQP region u + nested audio)',
			'files_audio' => 'Spoken: files/audio upload slot (Audio/ · *.wav, *.mp3)',
			'cqp_deprel_head' => 'Dependencies: CQP p-attributes deprel + head (@deprel / @head on tokens)',
			'cqp_s_region' => 'Dependencies: CQP structural region s (sentence)',
			'cqp_s_tuid' => 'Dependencies: CQP sentence id s_tuid (@tuid on ⟨s⟩)',
			'xmlfile_deprel_head' => 'Dependencies: xmlfile/pattributes deprel + head (XML editor)',
			'cqp_ud_morph' => 'Dependencies: CQP p-attributes UD morphology (lemma / upos / …)',
		);
		return isset($map[$c]) ? $map[$c] : $c;
	}
}

if (!function_exists('tt_fc_wizard_fix_applied_log_set')) {
	function tt_fc_wizard_fix_applied_log_set($projectRoot, $code, $label = '') {
		$code = strtolower(trim((string) $code));
		if ($code === '') {
			return;
		}
		tt_fc_wizard_session_ensure();
		$k = tt_fc_wizard_fix_applied_storage_key($projectRoot);
		if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) {
			$_SESSION[$k] = array();
		}
		$lbl = trim((string) $label);
		if ($lbl === '') {
			$lbl = tt_fc_wizard_fix_code_label($code);
		}
		$_SESSION[$k][$code] = array(
			'label' => $lbl,
			'ts' => time(),
		);
	}
}

if (!function_exists('tt_fc_wizard_fix_applied_has')) {
	function tt_fc_wizard_fix_applied_has($projectRoot, $code) {
		$code = strtolower(trim((string) $code));
		if ($code === '') {
			return false;
		}
		tt_fc_wizard_session_ensure();
		$k = tt_fc_wizard_fix_applied_storage_key($projectRoot);
		return isset($_SESSION[$k][$code]);
	}
}

/**
 * Automated “Wizard fix” codes that may appear in <code>wizard_select_fix</code> (comma / whitespace separated).
 *
 * @return list<string>
 */
if (!function_exists('tt_fc_wizard_wizard_select_fix_codes_allowed')) {
	function tt_fc_wizard_wizard_select_fix_codes_allowed() {
		return array(
			'alignment_merge',
			'allowlist_u',
			'cqp_u_stub',
			'wavesurfer_tok',
			'wavesurfer_utt',
			'cqp_u_audio',
			'files_audio',
			'cqp_deprel_head',
			'cqp_s_region',
			'cqp_s_tuid',
			'xmlfile_deprel_head',
			'cqp_ud_morph',
		);
	}
}

/**
 * Parse <code>wizard_select_fix</code> query/form value into an ordered, deduped list of allowed codes.
 *
 * @return list<string>
 */
if (!function_exists('tt_fc_wizard_parse_wizard_select_fix_list')) {
	function tt_fc_wizard_parse_wizard_select_fix_list($raw) {
		$allow = array_flip(tt_fc_wizard_wizard_select_fix_codes_allowed());
		$out = array();
		foreach (preg_split('/[\s,]+/', strtolower(trim((string) $raw)), -1, PREG_SPLIT_NO_EMPTY) as $p) {
			if (isset($allow[$p]) && !in_array($p, $out, true)) {
				$out[] = $p;
			}
		}
		return $out;
	}
}

if (!function_exists('tt_fc_wizard_fix_applied_log_list')) {
	function tt_fc_wizard_fix_applied_log_list($projectRoot) {
		tt_fc_wizard_session_ensure();
		$k = tt_fc_wizard_fix_applied_storage_key($projectRoot);
		$raw = (isset($_SESSION[$k]) && is_array($_SESSION[$k])) ? $_SESSION[$k] : array();
		$out = array();
		foreach ($raw as $c => $meta) {
			if (!is_array($meta)) {
				continue;
			}
			$out[] = array(
				'code' => (string) $c,
				'label' => isset($meta['label']) ? (string) $meta['label'] : (string) $c,
				'ts' => isset($meta['ts']) ? (int) $meta['ts'] : 0,
			);
		}
		usort($out, function ($a, $b) {
			return ($b['ts'] <=> $a['ts']);
		});
		return $out;
	}
}

/**
 * Resolve TEITOK installation root (directory that contains <code>common/Sources/venv.php</code>) by walking upward from seeds.
 * Corpus <code>index.php</code> normally sets global <code>$ttroot</code>; this covers wizard-only / CLI-like requests.
 */
if (!function_exists('tt_fc_wizard_guess_teitok_install_root')) {
	function tt_fc_wizard_guess_teitok_install_root() {
		$marker = '/common/Sources/venv.php';
		$seeds = array(__DIR__);
		if (function_exists('tt_fc_wizard_project_root')) {
			$seeds[] = tt_fc_wizard_project_root();
		}
		foreach ($seeds as $seed) {
			if (!is_string($seed) || $seed === '') {
				continue;
			}
			$dir = rtrim(str_replace('\\', '/', $seed), '/');
			for ($i = 0; $i < 16; $i++) {
				if ($dir === '' || $dir === '/') {
					break;
				}
				if (is_file($dir . $marker)) {
					return $dir;
				}
				$next = @dirname($dir);
				if ($next === $dir) {
					break;
				}
				$dir = $next;
			}
		}
		return '';
	}
}

if (!function_exists('tt_fc_wizard_resolve_xml_dir')) {
	function tt_fc_wizard_resolve_xml_dir($projectRoot) {
		$candidates = array(
			rtrim($projectRoot, '/\\') . '/xmlfiles',
			rtrim($projectRoot, '/\\') . '/XML',
			rtrim($projectRoot, '/\\') . '/xml',
		);
		foreach ($candidates as $dir) {
			if (is_dir($dir)) return $dir;
		}
		return '';
	}
}

/**
 * Canonical corpus-local TEITOK settings path (always under the project tree).
 */
if (!function_exists('tt_fc_wizard_teitok_canonical_settings_path')) {
	function tt_fc_wizard_teitok_canonical_settings_path($projectRoot) {
		$root = rtrim((string) $projectRoot, '/\\');
		if ($root === '') return '';
		return $root . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'settings.xml';
	}
}

/**
 * Readable corpus settings XML if present — TEITOK uses exactly
 * <code>Resources/settings.xml</code> under the project root (not alternate roots).
 */
if (!function_exists('tt_fc_wizard_resolve_teitok_settings_xml')) {
	function tt_fc_wizard_resolve_teitok_settings_xml($projectRoot) {
		$path = tt_fc_wizard_teitok_canonical_settings_path($projectRoot);
		if ($path !== '' && is_file($path) && is_readable($path)) {
			return $path;
		}
		return '';
	}
}

/**
 * Copy canonical <code>Resources/settings.xml</code> to <code>backups/settings-YYYYMMDD.xml</code> under the corpus root
 * (one file per calendar day; same-day runs overwrite that file). Creates <code>backups/</code> if needed.
 *
 * @param string $settingsPath Absolute path to the settings file being written
 * @return array{ok:bool,backup_rel:string,backup_abs:string,error:string}
 */
if (!function_exists('tt_fc_wizard_backup_settings_xml_daily')) {
	function tt_fc_wizard_backup_settings_xml_daily($settingsPath) {
		$out = array(
			'ok' => false,
			'backup_rel' => '',
			'backup_abs' => '',
			'error' => '',
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not readable for backup.';
			return $out;
		}
		$real = @realpath($settingsPath);
		$baseFile = ($real !== false && is_string($real) && $real !== '') ? $real : $settingsPath;
		$parentDir = dirname($baseFile);
		$projectRoot = (strtolower(basename($parentDir)) === 'resources')
			? dirname($parentDir)
			: $parentDir;
		$backupDir = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'backups';
		if (!is_dir($backupDir)) {
			if (!@mkdir($backupDir, 0775, true)) {
				$out['error'] = 'Could not create backups directory.';
				return $out;
			}
		}
		if (!is_writable($backupDir)) {
			$out['error'] = 'Backups directory is not writable.';
			return $out;
		}
		$day = date('Ymd');
		$out['backup_rel'] = 'backups/settings-' . $day . '.xml';
		$out['backup_abs'] = $backupDir . DIRECTORY_SEPARATOR . 'settings-' . $day . '.xml';
		if (!@copy($baseFile, $out['backup_abs'])) {
			$out['error'] = 'Could not write daily backup to ' . $out['backup_rel'] . '.';
			return $out;
		}
		$out['ok'] = true;
		return $out;
	}
}

if (!function_exists('tt_fc_wizard_merge_opts')) {
	/**
	 * @param mixed $opts
	 * @return array{commit:bool,xml_in:?string}
	 */
	function tt_fc_wizard_merge_opts($opts) {
		$commit = true;
		$xmlIn = null;
		if (is_array($opts)) {
			if (array_key_exists('commit', $opts)) {
				$commit = (bool) $opts['commit'];
			}
			if (isset($opts['xml']) && is_string($opts['xml'])) {
				$xmlIn = $opts['xml'];
			}
		}
		return array(
			'commit' => $commit,
			'xml_in' => $xmlIn,
		);
	}
}

if (!function_exists('tt_fc_wizard_merge_resolve_raw')) {
	/**
	 * Load settings XML from disk or from an in-memory chain (preview).
	 *
	 * @param mixed $opts
	 * @return array{ok:bool,error:string,raw:?string,commit:bool}
	 */
	function tt_fc_wizard_merge_resolve_raw($settingsPath, $opts) {
		$mo = tt_fc_wizard_merge_opts($opts);
		$commit = $mo['commit'];
		$xmlIn = $mo['xml_in'];
		$virtual = (is_string($xmlIn) && $xmlIn !== '');
		$pathOk = (is_string($settingsPath) && $settingsPath !== '' && is_readable($settingsPath));
		if (!$virtual && !$pathOk) {
			return array(
				'ok' => false,
				'error' => 'Settings file not found or unreadable.',
				'raw' => null,
				'commit' => $commit,
			);
		}
		if ($commit && !$pathOk) {
			return array(
				'ok' => false,
				'error' => 'Settings file not found or unreadable.',
				'raw' => null,
				'commit' => true,
			);
		}
		if ($commit && $pathOk && !is_writable($settingsPath)) {
			return array(
				'ok' => false,
				'error' => 'Corpus settings file is not writable.',
				'raw' => null,
				'commit' => true,
			);
		}
		$raw = $virtual ? $xmlIn : @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			return array(
				'ok' => false,
				'error' => 'Empty settings file.',
				'raw' => null,
				'commit' => $commit,
			);
		}
		return array(
			'ok' => true,
			'error' => '',
			'raw' => $raw,
			'commit' => $commit,
		);
	}
}

if (!function_exists('tt_fc_wizard_teitok_adminsettings_url')) {
	/**
	 * TEITOK corpus admin settings (core module): <code>index.php?action=adminsettings</code>.
	 */
	function tt_fc_wizard_teitok_adminsettings_url() {
		return 'index.php?action=adminsettings';
	}
}

/**
 * In-page link into TEITOK admin settings: base route + standard fragment (typical TEITOK layout).
 *
 * @param string $logicalKey Section key: <code>flexicorp</code>, <code>cqp</code>, <code>files</code>, …
 */
if (!function_exists('tt_fc_wizard_adminsettings_section_url')) {
	function tt_fc_wizard_adminsettings_section_url($logicalKey) {
		$logicalKey = strtolower(trim((string) $logicalKey));
		if ($logicalKey === '') {
			return '';
		}
		$base = tt_fc_wizard_teitok_adminsettings_url();
		$defaultSuffix = array(
			'flexicorp' => '#flexicorp',
			'cqp' => '#cqp',
			'cqp_defaults' => '#cqpdefaults',
			'encoding' => '#encoding',
			'files' => '#files',
			'uploads' => '#uploads',
			'corpus' => '#corpus',
		);
		if (isset($defaultSuffix[$logicalKey])) {
			return $base . $defaultSuffix[$logicalKey];
		}
		return $base;
	}
}

if (!function_exists('tt_fc_wizard_teitok_cqp_settings_url')) {
	/**
	 * Link to TEITOK CQP / indexed-corpus settings (pattributes, sattributes).
	 * Default matches {@see tt_fc_wizard_adminsettings_section_url}('cqp') — not <code>action=cqpmng</code>,
	 * which is absent on many TEITOK installs.
	 */
	function tt_fc_wizard_teitok_cqp_settings_url() {
		$env = getenv('TEITOK_CQP_SETTINGS_URL');
		if ($env !== false && trim((string) $env) !== '') {
			return trim((string) $env);
		}
		return tt_fc_wizard_adminsettings_section_url('cqp');
	}
}

if (!function_exists('tt_fc_wizard_teitok_metadata_url')) {
	function tt_fc_wizard_teitok_metadata_url() {
		$env = getenv('TEITOK_METADATA_URL');
		if ($env !== false && trim((string) $env) !== '') {
			return trim((string) $env);
		}
		return 'index.php?action=metadata';
	}
}

/** Static help page (corpus-relative). Override with env TEITOK_WIZARD_HELP_URL (legacy: TEITOK_WIZZARD_HELP_URL) for absolute URL. */
if (!function_exists('tt_fc_wizard_help_url')) {
	function tt_fc_wizard_help_url() {
		$env = getenv('TEITOK_WIZARD_HELP_URL');
		if ($env === false || trim((string) $env) === '') {
			$env = getenv('TEITOK_WIZZARD_HELP_URL');
		}
		if ($env !== false && trim((string) $env) !== '') {
			return trim((string) $env);
		}
		return 'wizard_help.html';
	}
}

/**
 * Case-insensitive hit for a token attribute name in aggregated sample counts.
 */
if (!function_exists('tt_fc_wizard_token_attr_hit_ci')) {
	function tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, $want) {
		if (!is_array($tokenAttrHits)) {
			return false;
		}
		$w = strtolower(trim((string) $want));
		if ($w === '') {
			return false;
		}
		foreach (array_keys($tokenAttrHits) as $k) {
			if (strtolower(trim((string) $k)) === $w) {
				return true;
			}
		}
		return false;
	}
}

/**
 * List indexed token p-attribute keys from corpus <code>Resources/settings.xml</code> main &lt;cqp&gt; block.
 */
if (!function_exists('tt_fc_wizard_parse_cqp_pattribute_keys')) {
	function tt_fc_wizard_parse_cqp_pattribute_keys($settingsPath) {
		$out = array(
			'ok' => false,
			'error' => '',
			'keys' => array(),
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not found or unreadable';
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$out['error'] = 'No <cqp> element found';
			return $out;
		}
		$pitems = $xp->query('./pattributes/item', $cqp);
		if ($pitems instanceof DOMNodeList) {
			for ($i = 0; $i < $pitems->length; $i++) {
				$n = $pitems->item($i);
				if (!$n instanceof DOMElement) {
					continue;
				}
				$key = strtolower(trim($n->getAttribute('key')));
				if ($key !== '') {
					$out['keys'][] = $key;
				}
			}
		}
		$out['keys'] = array_values(array_unique($out['keys']));
		$out['ok'] = true;
		return $out;
	}
}

/**
 * Parse main &lt;cqp&gt; block for alignment-related CQP fields (pattribute tuid or p_tuid, sattribute s_tuid on sentence).
 */
if (!function_exists('tt_fc_wizard_parse_cqp_alignment')) {
	function tt_fc_wizard_parse_cqp_alignment($settingsPath) {
		$out = array(
			'ok' => false,
			'error' => '',
			'pattr_tuid' => false,
			'pattr_tuid_xpath' => '',
			'pattr_p_tuid' => false,
			'pattr_p_tuid_xpath' => '',
			'sattr_s_tuid' => false,
			'sattr_s_tuid_xpath' => '',
			'sattr_sentence_regions' => array(),
			'sattr_text_tuid' => false,
			'sattr_text_tuid_xpath' => '',
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not found or unreadable';
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$out['error'] = 'No <cqp> element found';
			return $out;
		}

		$pitems = $xp->query('./pattributes/item', $cqp);
		if ($pitems instanceof DOMNodeList) {
			for ($i = 0; $i < $pitems->length; $i++) {
				$n = $pitems->item($i);
				if (!$n instanceof DOMElement) continue;
				$key = strtolower(trim($n->getAttribute('key')));
				if ($key === 'tuid') {
					$out['pattr_tuid'] = true;
					$x = trim($n->getAttribute('xpath'));
					if ($x !== '' && $out['pattr_tuid_xpath'] === '') {
						$out['pattr_tuid_xpath'] = $x;
					}
				} elseif ($key === 'p_tuid') {
					$out['pattr_p_tuid'] = true;
					$x = trim($n->getAttribute('xpath'));
					if ($x !== '' && $out['pattr_p_tuid_xpath'] === '') {
						$out['pattr_p_tuid_xpath'] = $x;
					}
				}
			}
		}

		$sroot = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sroot = $ch;
				break;
			}
		}
		if ($sroot instanceof DOMElement) {
			foreach ($sroot->childNodes as $region) {
				if (!$region instanceof DOMElement || $region->localName !== 'item') continue;
				$level = strtolower(trim($region->getAttribute('level')));
				$rkey = strtolower(trim($region->getAttribute('key')));
				$is_sentence = ($level === 's' || $level === 'seg' || $rkey === 's' || $rkey === 'sentence');
				if (!$is_sentence) continue;
				foreach ($region->childNodes as $sub) {
					if (!$sub instanceof DOMElement || $sub->localName !== 'item') continue;
					$sk = strtolower(trim($sub->getAttribute('key')));
					if ($sk !== 's_tuid' && $sk !== 'tuid') {
						continue;
					}
					$out['sattr_s_tuid'] = true;
					$label = $rkey !== '' ? $rkey : ($level !== '' ? $level : 'sentence');
					$out['sattr_sentence_regions'][] = $label;
					$x = trim($sub->getAttribute('xpath'));
					if ($x !== '' && $out['sattr_s_tuid_xpath'] === '') {
						$out['sattr_s_tuid_xpath'] = $x;
					}
					break 2;
				}
			}
		}

		if ($sroot instanceof DOMElement) {
			foreach ($sroot->childNodes as $region) {
				if (!$region instanceof DOMElement || $region->localName !== 'item') continue;
				$level = strtolower(trim($region->getAttribute('level')));
				$rkey = strtolower(trim($region->getAttribute('key')));
				$is_text = ($level === 'text' || $rkey === 'text');
				if (!$is_text) continue;
				foreach ($region->childNodes as $sub) {
					if (!$sub instanceof DOMElement || $sub->localName !== 'item') continue;
					$sk = strtolower(trim($sub->getAttribute('key')));
					if ($sk !== 'text_tuid') {
						continue;
					}
					$out['sattr_text_tuid'] = true;
					$x = trim($sub->getAttribute('xpath'));
					if ($x !== '') {
						$out['sattr_text_tuid_xpath'] = $x;
					}
					break 2;
				}
			}
		}

		$out['ok'] = true;
		return $out;
	}
}

/**
 * Structural region ids declared under &lt;cqp&gt;&lt;sattributes&gt; (TEITOK indexed regions).
 *
 * @return array{ok:bool,error:string,keys:list<string>}
 */
if (!function_exists('tt_fc_wizard_parse_cqp_sattribute_keys')) {
	function tt_fc_wizard_parse_cqp_sattribute_keys($settingsPath) {
		$out = array(
			'ok' => false,
			'error' => '',
			'keys' => array(),
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not found or unreadable';
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$out['error'] = 'No <cqp> element found';
			return $out;
		}
		$sitems = $xp->query('./sattributes/item', $cqp);
		if ($sitems instanceof DOMNodeList) {
			for ($i = 0; $i < $sitems->length; $i++) {
				$n = $sitems->item($i);
				if (!$n instanceof DOMElement) {
					continue;
				}
				$key = strtolower(trim($n->getAttribute('key')));
				$level = strtolower(trim($n->getAttribute('level')));
				if ($key !== '') {
					$out['keys'][] = $key;
				} elseif ($level !== '') {
					$out['keys'][] = $level;
				}
			}
		}
		$out['keys'] = array_values(array_unique($out['keys']));
		$out['ok'] = true;
		return $out;
	}
}

/**
 * Parse the first &lt;geomap&gt; block under corpus settings (merged tmp/cqpsettings.xml or canonical Resources/settings.xml).
 * Shape mirrors TEITOK / flexicorp runtime expectations (see {@link tt_flexicorp_fn_geo_map_runtime_settings} in flexicorp_functions.php).
 *
 * @return array{
 *   ok:bool,
 *   error:string,
 *   present:bool,
 *   startpos:string,
 *   zoom:int,
 *   osmlayer:string,
 *   markertype:string,
 *   cqp_place:string,
 *   cqp_geo:string,
 *   cqp_title:string,
 *   xml_node:string,
 *   xml_geo:string,
 *   xml_name:string,
 *   xml_desc:string,
 *   areas_total:int,
 *   areas_incomplete:int,
 *   regions_total:int
 * }
 */
if (!function_exists('tt_fc_wizard_parse_geomap_block')) {
	function tt_fc_wizard_parse_geomap_block($settingsPath) {
		$out = array(
			'ok' => false,
			'error' => '',
			'present' => false,
			'startpos' => '',
			'zoom' => 0,
			'osmlayer' => '',
			'markertype' => '',
			'cqp_place' => '',
			'cqp_geo' => '',
			'cqp_title' => '',
			'xml_node' => '',
			'xml_geo' => '',
			'xml_name' => '',
			'xml_desc' => '',
			'areas_total' => 0,
			'areas_incomplete' => 0,
			'regions_total' => 0,
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not found or unreadable';
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$gm = null;
		$gn = $xp->query('//ttsettings/geomap');
		if ($gn instanceof DOMNodeList && $gn->length > 0) {
			$gm = $gn->item(0);
		}
		if (!$gm instanceof DOMElement) {
			$gn = $xp->query('//geomap');
			if ($gn instanceof DOMNodeList && $gn->length > 0) {
				$gm = $gn->item(0);
			}
		}
		if (!$gm instanceof DOMElement) {
			$out['ok'] = true;
			return $out;
		}
		$out['present'] = true;
		$out['startpos'] = trim((string) $gm->getAttribute('startpos'));
		$out['zoom'] = (int) $gm->getAttribute('zoom');
		$out['osmlayer'] = trim((string) $gm->getAttribute('osmlayer'));
		$out['markertype'] = trim((string) $gm->getAttribute('markertype'));
		foreach ($gm->childNodes as $ch) {
			if (!$ch instanceof DOMElement) {
				continue;
			}
			$ln = strtolower($ch->localName);
			if ($ln === 'cqp') {
				$out['cqp_place'] = trim((string) $ch->getAttribute('place'));
				$out['cqp_geo'] = trim((string) $ch->getAttribute('geo'));
				$out['cqp_title'] = trim((string) $ch->getAttribute('title'));
			} elseif ($ln === 'xml') {
				$out['xml_node'] = trim((string) $ch->getAttribute('node'));
				$out['xml_geo'] = trim((string) $ch->getAttribute('geo'));
				$out['xml_name'] = trim((string) $ch->getAttribute('name'));
				$out['xml_desc'] = trim((string) $ch->getAttribute('desc'));
			} elseif ($ln === 'areas') {
				foreach ($ch->childNodes as $ar) {
					if (!$ar instanceof DOMElement || strtolower($ar->localName) !== 'item') {
						continue;
					}
					$out['areas_total']++;
					$k = trim((string) $ar->getAttribute('key'));
					$d = trim((string) $ar->getAttribute('display'));
					$sp = trim((string) $ar->getAttribute('startpos'));
					$z = (int) $ar->getAttribute('zoom');
					$hasShortcutIntent = ($k !== '' || $d !== '');
					if ($hasShortcutIntent && ($sp === '' || $z <= 0)) {
						$out['areas_incomplete']++;
					}
				}
			} elseif ($ln === 'regions') {
				foreach ($ch->childNodes as $rg) {
					if (!$rg instanceof DOMElement || strtolower($rg->localName) !== 'item') {
						continue;
					}
					$rk = trim((string) $rg->getAttribute('key'));
					if ($rk !== '') {
						$out['regions_total']++;
					}
				}
			}
		}
		$out['ok'] = true;
		return $out;
	}
}

/**
 * Nested field keys under a CQP structural region (&lt;sattributes&gt;&lt;item key|level="REGION"&gt; … child &lt;item key="…"/&gt;).
 *
 * @return array{ok:bool,error:string,keys:list<string>,region_found:bool}
 */
if (!function_exists('tt_fc_wizard_parse_cqp_region_nested_field_keys')) {
	function tt_fc_wizard_parse_cqp_region_nested_field_keys($settingsPath, $wantRegion) {
		$out = array(
			'ok' => false,
			'error' => '',
			'keys' => array(),
			'region_found' => false,
		);
		$wantRegion = strtolower(trim((string) $wantRegion));
		if ($wantRegion === '') {
			$out['error'] = 'Empty region id';
			return $out;
		}
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not found or unreadable';
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$out['error'] = 'No <cqp> element found';
			return $out;
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			$out['ok'] = true;
			return $out;
		}
		foreach ($sattrs->childNodes as $regEl) {
			if (!$regEl instanceof DOMElement || $regEl->localName !== 'item') {
				continue;
			}
			$rk = strtolower(trim($regEl->getAttribute('key')));
			$rl = strtolower(trim($regEl->getAttribute('level')));
			$region = ($rk !== '') ? $rk : $rl;
			if ($region !== $wantRegion) {
				continue;
			}
			$out['region_found'] = true;
			foreach ($regEl->childNodes as $sub) {
				if (!$sub instanceof DOMElement || $sub->localName !== 'item') {
					continue;
				}
				$ck = strtolower(trim($sub->getAttribute('key')));
				if ($ck !== '') {
					$out['keys'][] = $ck;
				}
			}
			break;
		}
		$out['keys'] = array_values(array_unique($out['keys']));
		$out['ok'] = true;
		return $out;
	}
}

/**
 * Nested field keys under CQP structural region <code>text</code> (&lt;sattributes&gt;&lt;item key|level="text"&gt; … &lt;item key="…"/&gt;).
 *
 * @return array{ok:bool,error:string,keys:list<string>,text_region_found:bool}
 */
if (!function_exists('tt_fc_wizard_parse_cqp_text_region_field_keys')) {
	function tt_fc_wizard_parse_cqp_text_region_field_keys($settingsPath) {
		$r = tt_fc_wizard_parse_cqp_region_nested_field_keys($settingsPath, 'text');
		return array(
			'ok' => !empty($r['ok']),
			'error' => isset($r['error']) ? (string) $r['error'] : '',
			'keys' => isset($r['keys']) && is_array($r['keys']) ? $r['keys'] : array(),
			'text_region_found' => !empty($r['region_found']),
		);
	}
}

/**
 * Top-level CQP structural item for region <code>audio</code> (recommended indexed media: e.g. xpath/href <code>//recording/media/@url</code>).
 *
 * @return array{ok:bool,error:string,audio_region_found:bool,xpath_href_wired:bool}
 */
if (!function_exists('tt_fc_wizard_parse_cqp_audio_region_item')) {
	function tt_fc_wizard_parse_cqp_audio_region_item($settingsPath) {
		$out = array(
			'ok' => false,
			'error' => '',
			'audio_region_found' => false,
			'xpath_href_wired' => false,
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not found or unreadable';
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$out['error'] = 'No <cqp> element found';
			return $out;
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			$out['ok'] = true;
			return $out;
		}
		foreach ($sattrs->childNodes as $regEl) {
			if (!$regEl instanceof DOMElement || $regEl->localName !== 'item') {
				continue;
			}
			$rk = strtolower(trim($regEl->getAttribute('key')));
			$rl = strtolower(trim($regEl->getAttribute('level')));
			$region = ($rk !== '') ? $rk : $rl;
			if ($region !== 'audio') {
				continue;
			}
			$out['audio_region_found'] = true;
			$xpathRaw = trim((string) $regEl->getAttribute('xpath'));
			$hrefRaw = trim((string) $regEl->getAttribute('href'));
			if ($xpathRaw !== '' || $hrefRaw !== '') {
				$out['xpath_href_wired'] = true;
			}
			break;
		}
		$out['ok'] = true;
		return $out;
	}
}

/**
 * TEITOK native CQP (<code>common/Sources/cqp.php</code>): any structural region whose merged settings carry <code>audio</code> so tabulate adds <code>match &lt;region&gt;_audio</code> for hit playback.
 *
 * @return array{ok:bool,error:string,item_audio_attr:bool}
 */
if (!function_exists('tt_fc_wizard_parse_cqp_sattributes_item_audio_attr')) {
	function tt_fc_wizard_parse_cqp_sattributes_item_audio_attr($settingsPath) {
		$out = array(
			'ok' => false,
			'error' => '',
			'item_audio_attr' => false,
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['error'] = 'Settings file not found or unreadable';
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$out['error'] = 'No <cqp> element found';
			return $out;
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			$out['ok'] = true;
			return $out;
		}
		foreach ($sattrs->childNodes as $regEl) {
			if (!$regEl instanceof DOMElement || $regEl->localName !== 'item') {
				continue;
			}
			$aud = strtolower(trim((string) $regEl->getAttribute('audio')));
			if ($aud === '' || $aud === '0' || $aud === 'false' || $aud === 'no') {
				continue;
			}
			$out['item_audio_attr'] = true;
			break;
		}
		$out['ok'] = true;
		return $out;
	}
}

/**
 * Whether CQP wires indexed media for spoken TEI: TEITOK-style utterance fields under region <code>u</code> (e.g. <code>chunk_url</code>, <code>media</code>, <code>u_media</code>), native <code>audio</code> on an s-attribute item (cqp.php <code>match &lt;region&gt;_audio</code>), top-level <code>audio</code> with <code>xpath</code>/<code>href</code>, or nested <code>text_media</code> under <code>text</code>.
 *
 * @return array{wired:bool,parse_ok:bool,error:string,text_region_found:bool,keys_hint:string,u_region_found:bool,u_keys_hint:string,audio_region_found:bool,audio_xpath_href_wired:bool,teitok_sattr_audio:bool,wired_via:string}
 */
if (!function_exists('tt_fc_wizard_cqp_text_media_status')) {
	function tt_fc_wizard_cqp_text_media_status($settingsPath) {
		$out = array(
			'wired' => false,
			'parse_ok' => false,
			'error' => '',
			'text_region_found' => false,
			'keys_hint' => '',
			'u_region_found' => false,
			'u_keys_hint' => '',
			'audio_region_found' => false,
			'audio_xpath_href_wired' => false,
			'teitok_sattr_audio' => false,
			'wired_via' => '',
		);
		$p = tt_fc_wizard_parse_cqp_text_region_field_keys($settingsPath);
		$ur = tt_fc_wizard_parse_cqp_region_nested_field_keys($settingsPath, 'u');
		$ar = tt_fc_wizard_parse_cqp_audio_region_item($settingsPath);
		$am = tt_fc_wizard_parse_cqp_sattributes_item_audio_attr($settingsPath);
		$out['parse_ok'] = !empty($p['ok']) || !empty($ur['ok']) || !empty($ar['ok']) || !empty($am['ok']);
		if (!empty($p['error']) && (string) $p['error'] !== '') {
			$out['error'] = (string) $p['error'];
		} elseif (!empty($ur['error']) && (string) $ur['error'] !== '') {
			$out['error'] = (string) $ur['error'];
		} elseif (!empty($ar['error']) && (string) $ar['error'] !== '') {
			$out['error'] = (string) $ar['error'];
		} elseif (!empty($am['error']) && (string) $am['error'] !== '') {
			$out['error'] = (string) $am['error'];
		}
		$out['text_region_found'] = !empty($p['text_region_found']);
		$out['u_region_found'] = !empty($ur['region_found']);
		$out['audio_region_found'] = !empty($ar['audio_region_found']);
		$out['audio_xpath_href_wired'] = !empty($ar['xpath_href_wired']);

		$keys = (isset($p['keys']) && is_array($p['keys'])) ? $p['keys'] : array();
		$flip = array();
		foreach ($keys as $k) {
			$flip[strtolower(trim((string) $k))] = true;
		}
		$ukeys = (isset($ur['keys']) && is_array($ur['keys'])) ? $ur['keys'] : array();
		$uflip = array();
		foreach ($ukeys as $k) {
			$uflip[strtolower(trim((string) $k))] = true;
		}
		$textMediaXml = isset($flip['text_media']);
		$audioXml = !empty($ar['xpath_href_wired']);
		$uMediaXml = isset($uflip['chunk_url']) || isset($uflip['media']) || isset($uflip['u_media']);

		$textMediaGetset = false;
		$audioGetset = false;
		$uMediaGetset = false;
		$teitokSattrAudioGetset = false;
		if (function_exists('getset')) {
			$allSattr = getset('cqp/sattributes', array());
			if (is_array($allSattr)) {
				foreach ($allSattr as $_rn => $satt) {
					if (is_array($satt) && !empty($satt['audio'])) {
						$teitokSattrAudioGetset = true;
						break;
					}
				}
			}
			$nested = getset('cqp/sattributes/text', null);
			if (is_array($nested)) {
				foreach ($nested as $nk => $_nv) {
					if (strtolower(trim((string) $nk)) === 'text_media') {
						$textMediaGetset = true;
						break;
					}
				}
			}
			if (!$textMediaGetset && tt_fc_wizard_cqp_sattribute_defined('text_media')) {
				$textMediaGetset = true;
			}
			$ug = getset('cqp/sattributes/u', null);
			if (is_array($ug)) {
				foreach (array('chunk_url', 'media', 'u_media') as $uk) {
					if (isset($ug[$uk])) {
						$uMediaGetset = true;
						$out['u_region_found'] = true;
						break;
					}
				}
			}
			$ag = getset('cqp/sattributes/audio', null);
			if (is_array($ag)) {
				foreach (array('xpath', 'href') as $ak) {
					if (isset($ag[$ak]) && trim((string) $ag[$ak]) !== '') {
						$audioGetset = true;
						$out['audio_region_found'] = true;
						break;
					}
				}
			}
		}

		$teitokSattrAudioXml = !empty($am['item_audio_attr']);
		$out['teitok_sattr_audio'] = $teitokSattrAudioXml || $teitokSattrAudioGetset;
		$teitokSattrAudioWired = $out['teitok_sattr_audio'];

		$audioWired = $audioXml || $audioGetset;
		$textMediaWired = $textMediaXml || $textMediaGetset;
		$uWired = $uMediaXml || $uMediaGetset;
		$out['wired'] = $uWired || $teitokSattrAudioWired || $audioWired || $textMediaWired;
		if ($out['wired']) {
			if ($uWired) {
				$out['wired_via'] = 'u';
			} elseif ($teitokSattrAudioWired) {
				$out['wired_via'] = 'teitok_sattr_audio';
			} elseif ($audioWired) {
				$out['wired_via'] = 'audio';
				$out['audio_xpath_href_wired'] = true;
			} else {
				$out['wired_via'] = 'text_media';
			}
		}

		if (count($keys) > 0) {
			$out['keys_hint'] = implode(', ', array_slice($keys, 0, 14));
			if (count($keys) > 14) {
				$out['keys_hint'] .= ', …';
			}
		}
		if (count($ukeys) > 0) {
			$out['u_keys_hint'] = implode(', ', array_slice($ukeys, 0, 14));
			if (count($ukeys) > 14) {
				$out['u_keys_hint'] .= ', …';
			}
		}
		return $out;
	}
}

/**
 * Normalize TEITOK &lt;files&gt;&lt;item&gt; <code>extension</code> attribute (comma-separated <code>*.ext</code> or <code>ext</code>).
 *
 * @return list<string> distinct lowercase suffixes without dot (e.g. mp3, wav, mp4).
 */
if (!function_exists('tt_fc_wizard_parse_teitok_extension_attribute')) {
	function tt_fc_wizard_parse_teitok_extension_attribute($extRaw) {
		$extRaw = trim((string) $extRaw);
		if ($extRaw === '') {
			return array();
		}
		$parts = preg_split('/\s*,\s*/', $extRaw);
		$out = array();
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p === '') {
				continue;
			}
			$p = preg_replace('/^\*\./', '', $p);
			$p = strtolower(trim($p, " \t\n\r\0\x0B."));
			if ($p !== '' && preg_match('/^[a-z0-9]{1,12}$/', $p)) {
				$out[] = $p;
			}
		}
		return array_values(array_unique($out));
	}
}

/**
 * Whether TEITOK file uploads include an audio slot (e.g. &lt;files&gt;&lt;item key="audio" folder="Audio" …/&gt;).
 * The <code>extension</code> attribute is optional; when present it restricts uploads to those suffixes (TEITOK MIME / accept behaviour).
 *
 * @return array{ok:bool,configured:bool,error:string,hint:string,extension_raw:string,extensions_normalized:list<string>,extensions_nonempty:bool}
 */
if (!function_exists('tt_fc_wizard_parse_settings_files_audio_upload')) {
	function tt_fc_wizard_parse_settings_files_audio_upload($settingsPath, $xmlRaw = null) {
		$out = array(
			'ok' => false,
			'configured' => false,
			'error' => '',
			'hint' => '',
			'extension_raw' => '',
			'extensions_normalized' => array(),
			'extensions_nonempty' => false,
		);
		if ($xmlRaw !== null && is_string($xmlRaw) && $xmlRaw !== '') {
			$raw = $xmlRaw;
		} else {
			if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
				$out['error'] = 'Settings file not found or unreadable';
				return $out;
			}
			$raw = @file_get_contents($settingsPath);
		}
		if (!is_string($raw) || trim($raw) === '') {
			$out['error'] = 'Empty settings file';
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['error'] = 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$queries = array(
			'//ttsettings/files/item',
			'//files/item',
		);
		foreach ($queries as $q) {
			$nl = $xp->query($q);
			if (!$nl instanceof DOMNodeList) {
				continue;
			}
			for ($i = 0; $i < $nl->length; $i++) {
				$n = $nl->item($i);
				if (!$n instanceof DOMElement || $n->localName !== 'item') {
					continue;
				}
				$key = strtolower(trim($n->getAttribute('key')));
				$folderRaw = trim($n->getAttribute('folder'));
				$folder = strtolower(str_replace('\\', '/', $folderRaw));
				$isAudioKey = ($key === 'audio');
				$isAudioFolder = ($folder === 'audio' || preg_match('#(^|/)audio/?$#', $folder) === 1);
				if (!$isAudioKey && !$isAudioFolder) {
					continue;
				}
				$out['configured'] = true;
				$display = trim($n->getAttribute('display'));
				$ext = trim($n->getAttribute('extension'));
				$extNorm = tt_fc_wizard_parse_teitok_extension_attribute($ext);
				$out['extension_raw'] = $ext;
				$out['extensions_normalized'] = $extNorm;
				$out['extensions_nonempty'] = ($ext !== '');
				$bits = array();
				if ($display !== '') {
					$bits[] = $display;
				} elseif ($isAudioKey) {
					$bits[] = 'audio';
				}
				if ($folderRaw !== '') {
					$bits[] = 'folder=' . $folderRaw;
				}
				if (count($extNorm) > 0) {
					$bits[] = 'allowed suffixes: .' . implode(', .', $extNorm);
				} elseif ($ext !== '') {
					$bits[] = 'extension=' . $ext;
				} else {
					$bits[] = 'no extension filter (optional)';
				}
				$out['hint'] = implode(' · ', array_filter($bits, static function ($x) {
					return $x !== '';
				}));
				$out['ok'] = true;
				return $out;
			}
		}
		$out['ok'] = true;
		return $out;
	}
}

/**
 * Pick TEITOK <code>xmlfile/sattributes</code> subtree for a structural region key (e.g. <code>u</code>).
 *
 * @param mixed $node
 * @return array<string,mixed>|null
 */
if (!function_exists('tt_fc_wizard_xmlfile_pick_region_subtree')) {
	function tt_fc_wizard_xmlfile_pick_region_subtree($node, $want) {
		$want = strtolower(trim((string) $want));
		if ($want === '' || !is_array($node)) {
			return null;
		}
		foreach ($node as $k => $v) {
			if (strtolower((string) $k) === $want && is_array($v)) {
				return $v;
			}
		}
		foreach ($node as $k => $v) {
			if (is_array($v)) {
				$found = tt_fc_wizard_xmlfile_pick_region_subtree($v, $want);
				if ($found !== null) {
					return $found;
				}
			}
		}
		return null;
	}
}

/**
 * Collect associative keys from nested TEITOK getset-style arrays (for xmlfile/sattributes inspection).
 *
 * @param mixed $arr
 * @return list<string>
 */
if (!function_exists('tt_fc_wizard_flatten_string_keys_recursive')) {
	function tt_fc_wizard_flatten_string_keys_recursive($arr) {
		$keys = array();
		if (!is_array($arr)) {
			return $keys;
		}
		foreach ($arr as $k => $v) {
			if (is_string($k) && $k !== '') {
				$keys[] = $k;
			}
			if (is_array($v)) {
				foreach (tt_fc_wizard_flatten_string_keys_recursive($v) as $sk) {
					$keys[] = $sk;
				}
			}
		}
		return $keys;
	}
}

/**
 * TEITOK xmlfile section: which ⟨u⟩-side fields the XML editor exposes (<code>xmlfile/sattributes</code>).
 * Expect speaker (<code>who</code>) and a media / medianode-style URL field for hand editing.
 * Utterance timing (<code>@start</code> / <code>@stop</code>) is intentionally not required here — those are usually not edited via this list.
 *
 * @return array{parse_ok:bool,has_region_branch:bool,who:bool,media_url:bool,keys_hint:string,utt_region:string}
 */
if (!function_exists('tt_fc_wizard_xmlfile_u_editor_attrs_status')) {
	function tt_fc_wizard_xmlfile_u_editor_attrs_status() {
		$out = array(
			'parse_ok' => false,
			'has_region_branch' => false,
			'who' => false,
			'media_url' => false,
			'keys_hint' => '',
			'utt_region' => 'u',
		);
		if (!function_exists('getset')) {
			return $out;
		}
		$out['parse_ok'] = true;
		$sat = getset('xmlfile/sattributes', array());
		if (!is_array($sat) || count($sat) === 0) {
			return $out;
		}
		$utt = trim((string) getset('xmlfile/defaults/speechturn', 'U'));
		$utt = preg_replace('/^<|>$/', '', $utt);
		$utt = strtolower($utt !== '' ? $utt : 'u');
		$out['utt_region'] = $utt;
		$sub = null;
		foreach (array_unique(array($utt, 'u')) as $reg) {
			$sub = tt_fc_wizard_xmlfile_pick_region_subtree($sat, $reg);
			if ($sub !== null) {
				break;
			}
		}
		if ($sub === null && is_array($sat)) {
			foreach ($sat as $el) {
				if (!is_array($el)) {
					continue;
				}
				$rid = '';
				if (isset($el['region'])) {
					$rid = strtolower(trim((string) $el['region']));
				} elseif (isset($el['id'])) {
					$rid = strtolower(trim((string) $el['id']));
				} elseif (isset($el['key'])) {
					$rid = strtolower(trim((string) $el['key']));
				}
				if ($rid === 'u' || ($utt !== '' && $rid === $utt)) {
					$sub = $el;
					break;
				}
			}
		}
		if ($sub === null || !is_array($sub)) {
			return $out;
		}
		$out['has_region_branch'] = true;
		$keys = tt_fc_wizard_flatten_string_keys_recursive($sub);
		$keysLow = array();
		foreach ($keys as $k) {
			$lk = strtolower(trim((string) $k));
			if ($lk !== '') {
				$keysLow[] = $lk;
			}
		}
		$keysLow = array_values(array_unique($keysLow));
		$out['keys_hint'] = implode(', ', array_slice($keysLow, 0, 28));
		foreach ($keysLow as $lk) {
			if ($lk === 'who') {
				$out['who'] = true;
			}
			if (preg_match('/^(medianode|medianodeurl|medianode_url|chunk_url|media_url|u_media|recording_url|audiopath|media)$/', $lk)) {
				$out['media_url'] = true;
			}
		}
		return $out;
	}
}

/**
 * Parse &lt;cqp&gt;&lt;defaults&gt; items (searchtype, subtype, kwic, context, registry) from corpus settings XML.
 *
 * @return array<string,string>
 */
if (!function_exists('tt_fc_wizard_parse_cqp_defaults_xml')) {
	function tt_fc_wizard_parse_cqp_defaults_xml($settingsPath) {
		$defaults = array(
			'searchtype' => '',
			'subtype' => '',
			'kwic' => '',
			'context' => '',
			'registry' => '',
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			return $defaults;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			return $defaults;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			return $defaults;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			return $defaults;
		}
		$ditems = $xp->query('./defaults/item', $cqp);
		if (!($ditems instanceof DOMNodeList)) {
			return $defaults;
		}
		for ($i = 0; $i < $ditems->length; $i++) {
			$n = $ditems->item($i);
			if (!$n instanceof DOMElement) {
				continue;
			}
			$k = strtolower(trim($n->getAttribute('key')));
			if ($k === '' || !isset($defaults[$k])) {
				continue;
			}
			$v = trim($n->getAttribute('default'));
			if ($v === '') {
				$v = trim($n->getAttribute('value'));
			}
			if ($v === '') {
				$v = trim($n->textContent);
			}
			if ($v !== '') {
				$defaults[$k] = $v;
			}
		}
		return $defaults;
	}
}

/**
 * Effective TEITOK CQP UI defaults: prefers runtime getset (merged cqpsettings), then XML (merged file, else canonical).
 *
 * @return array{searchtype:string,subtype:string,kwic:string,context:string,registry:string}
 */
if (!function_exists('tt_fc_wizard_resolve_cqp_search_defaults')) {
	function tt_fc_wizard_resolve_cqp_search_defaults($canonicalSettingsPath, $mergedCqpPath) {
		$keys = array('searchtype', 'subtype', 'kwic', 'context', 'registry');
		$out = array();
		foreach ($keys as $k) {
			$out[$k] = '';
		}
		if (function_exists('getset')) {
			foreach ($keys as $k) {
				$gk = 'cqp/defaults/' . $k;
				$v = getset($gk, '');
				if (is_array($v)) {
					continue;
				}
				$out[$k] = trim((string) $v);
			}
		}
		$xmlPath = (is_string($mergedCqpPath) && $mergedCqpPath !== '' && is_readable($mergedCqpPath))
			? $mergedCqpPath
			: $canonicalSettingsPath;
		$parsed = tt_fc_wizard_parse_cqp_defaults_xml($xmlPath);
		foreach ($keys as $k) {
			if ($out[$k] === '' && isset($parsed[$k]) && trim((string) $parsed[$k]) !== '') {
				$out[$k] = trim((string) $parsed[$k]);
			}
		}
		return $out;
	}
}

/**
 * Whether TEITOK exposes an indexed structural region for flexicorp/CQP (getset wins when loaded).
 */
if (!function_exists('tt_fc_wizard_cqp_sattribute_defined')) {
	function tt_fc_wizard_cqp_sattribute_defined($regionKey) {
		$regionKey = strtolower(trim((string) $regionKey));
		if ($regionKey === '') {
			return false;
		}
		if (function_exists('getset')) {
			$v = getset('cqp/sattributes/' . $regionKey, null);
			if (is_array($v)) {
				return count($v) > 0;
			}
			return $v !== null && $v !== '';
		}
		return false;
	}
}

/**
 * Whether the utterance / &lt;u&gt; CQP side is covered: region <code>u</code>, or paired <code>u_start</code> + <code>u_stop</code> (alternate TEITOK wiring in settings.xml).
 *
 * @param list<string> $sattrKeys Keys from merged/canonical CQP XML + optional TEITOK getset when loaded.
 * @return array{wired:bool,mode:string,hint:string}
 */
if (!function_exists('tt_fc_wizard_cqp_utterance_region_wired')) {
	function tt_fc_wizard_cqp_utterance_region_wired($sattrKeys) {
		$out = array(
			'wired' => false,
			'mode' => '',
			'hint' => '',
		);
		$sattrKeys = is_array($sattrKeys) ? $sattrKeys : array();
		$sflip = array();
		foreach ($sattrKeys as $sk) {
			$sflip[strtolower(trim((string) $sk))] = true;
		}
		$has = static function ($k) use ($sflip) {
			$k = strtolower(trim((string) $k));
			if ($k === '') {
				return false;
			}
			if (function_exists('getset') && tt_fc_wizard_cqp_sattribute_defined($k)) {
				return true;
			}
			return isset($sflip[$k]);
		};
		if ($has('u')) {
			$out['wired'] = true;
			$out['mode'] = 'u';
			$out['hint'] = 'u';
			return $out;
		}
		if ($has('u_start') && $has('u_stop')) {
			$out['wired'] = true;
			$out['mode'] = 'u_bounds';
			$out['hint'] = 'u_start + u_stop';
			return $out;
		}
		return $out;
	}
}

/**
 * Section heading for the Spoken checklist table (optional <code>section</code> key overrides).
 */
if (!function_exists('tt_fc_wizard_spoken_row_section')) {
	function tt_fc_wizard_spoken_row_section($row) {
		if (is_array($row) && isset($row['section'])) {
			$s = trim((string) $row['section']);
			if ($s !== '') {
				return $s;
			}
		}
		$id = (is_array($row) && isset($row['id'])) ? trim((string) $row['id']) : '';
		if ($id === 'spoken-media' || $id === 'spoken-timing-u' || $id === 'spoken-timing-tok') {
			return 'Corpus TEI (sample)';
		}
		if ($id === 'spoken-cqp-u' || $id === 'spoken-allowlist') {
			return 'Utterance & search';
		}
		return 'Defaults & media';
	}
}

/**
 * Checks TEITOK search/KWIC defaults vs sample XML: wrong subtype, missing indexed region, ambiguous &lt;s&gt;+&lt;u&gt; when defaults unset.
 *
 * @param array<string,int> $regionElemHits
 * @param array<string,string> $resolvedDefaults from tt_fc_wizard_resolve_cqp_search_defaults
 * @param array{ok:bool,keys:list<string>} $sattrParse
 * @param array<string,int> $totals profile totals (spoken_docs, …)
 * @return list<array{group:string,label:string,ok:bool,detail:string,severity?:string}>
 */
if (!function_exists('tt_fc_wizard_cqp_search_defaults_probe')) {
	function tt_fc_wizard_cqp_search_defaults_probe($regionElemHits, $resolvedDefaults, $sattrParse, $totals) {
		$rows = array();
		if (!is_array($resolvedDefaults)) {
			$resolvedDefaults = array();
		}
		$searchRaw = isset($resolvedDefaults['searchtype']) ? trim((string) $resolvedDefaults['searchtype']) : '';
		$subRaw = isset($resolvedDefaults['subtype']) ? trim((string) $resolvedDefaults['subtype']) : '';
		$search = strtolower($searchRaw);
		$sub = strtolower($subRaw);
		$sattrKeys = (isset($sattrParse['keys']) && is_array($sattrParse['keys'])) ? $sattrParse['keys'] : array();
		$sflip = array();
		foreach ($sattrKeys as $sk) {
			$sflip[strtolower((string) $sk)] = true;
		}

		$regionElemHits = is_array($regionElemHits) ? $regionElemHits : array();
		$hitS = !empty($regionElemHits['s']);
		$hitU = !empty($regionElemHits['u']);
		$spokenDocs = isset($totals['spoken_docs']) ? (int) $totals['spoken_docs'] : 0;
		$mediaDocs = isset($totals['media_docs']) ? (int) $totals['media_docs'] : 0;
		$oralSample = ($spokenDocs > 0 || $mediaDocs > 0);

		$indexed = function ($k) use ($sflip) {
			$k = strtolower(trim((string) $k));
			if ($k === '') {
				return false;
			}
			if (function_exists('getset') && tt_fc_wizard_cqp_sattribute_defined($k)) {
				return true;
			}
			return isset($sflip[$k]);
		};
		$uWire = tt_fc_wizard_cqp_utterance_region_wired($sattrKeys);
		$subtypeIndexed = ($sub === 'u')
			? !empty($uWire['wired'])
			: $indexed($sub);

		if ($sub !== '' && $sub !== 'tok' && $sub !== 'token' && $sub !== 'tokens' && !$subtypeIndexed) {
			$rows[] = array(
				'group' => 'CQP defaults',
				'label' => 'cqp/defaults/subtype',
				'ok' => false,
				'severity' => 'hard',
				'detail' => 'Default display region is "' . $subRaw . '" but there is no matching indexed CQP &lt;sattributes&gt; entry (TEITOK admin config check). For subtype <code>u</code>, either declare region <code>u</code> or paired <code>u_start</code> + <code>u_stop</code>. Flexicorp cannot mirror this until the region exists — see index.php?action=admin&amp;act=configcheck pattern.',
			);
		} elseif ($sub !== '') {
			$detailOk = 'Structural default "' . $subRaw . '" matches an indexed s-attribute region.';
			if ($sub === 'u' && isset($uWire['mode']) && (string) $uWire['mode'] === 'u_bounds') {
				$detailOk = 'Default subtype u is covered by indexed <code>u_start</code> + <code>u_stop</code> (bounds), not a single <code>u</code> region.';
			}
			$rows[] = array(
				'group' => 'CQP defaults',
				'label' => 'cqp/defaults/subtype',
				'ok' => true,
				'severity' => 'hard',
				'detail' => $detailOk,
			);
		}

		$mismatchOral = ($sub === 's' && $search === 'context' && $spokenDocs > 0 && $hitU);
		if ($mismatchOral) {
			$rows[] = array(
				'group' => 'CQP defaults',
				'label' => 'oral / spoken context',
				'ok' => false,
				'severity' => 'hard',
				'detail' => 'Sample has &lt;u&gt; and defaults use context + sentence (&lt;s&gt;). For utterance-sized playback/KWIC, set TEITOK &lt;cqp&gt; defaults: searchtype=context, subtype=u (and ensure indexed regions + flexicorp allowlist include u).',
			);
		}

		$ambiguousSu = ($hitS && $hitU && ($sub === '' || $sub === 'tok' || $sub === 'token' || $sub === 'tokens')
			&& ($search === '' || $search === 'kwic'));
		if ($ambiguousSu) {
			$rows[] = array(
				'group' => 'CQP defaults',
				'label' => 'sentence vs utterance',
				'ok' => false,
				'severity' => 'hard',
				'detail' => 'Sample XML contains both &lt;s&gt; and &lt;u&gt;, but TEITOK does not set cqp/defaults/subtype (and searchtype is not context). Flexicorp then falls back to token window scope (tok/window), which may be wrong for listening-sized hits. Set explicit TEITOK defaults: e.g. searchtype=context and subtype=u for spoken material, or subtype=s for written-only sentence context.',
			);
		}

		if ($hitS && $hitU && $search === 'context' && ($sub === '' || $sub === 'tok' || $sub === 'token')) {
			$rows[] = array(
				'group' => 'CQP defaults',
				'label' => 'sentence vs utterance',
				'ok' => false,
				'severity' => 'hard',
				'detail' => 'Both &lt;s&gt; and &lt;u&gt; appear in the sample; context mode is on but subtype is unset or token-wide. Choose a default structural region (subtype=s or subtype=u) under TEITOK CQP defaults so native CQP and flexicorp agree.',
			);
		}

		if ($oralSample && !($search === 'context' && $sub === 'u')) {
			$rows[] = array(
				'group' => 'CQP defaults — playback',
				'label' => 'listening-sized default context',
				'ok' => false,
				'severity' => 'soft',
				'detail' => 'Oral/spoken markers are present but TEITOK CQP defaults are not searchtype=context with subtype=u. Sentence or KWIC defaults suit dependency trees; flexicorp will not open utterance-wide, listenable snippets by default — operators must change context scope in the UI unless you set these TEITOK defaults explicitly.',
			);
		}

		return $rows;
	}
}

/**
 * Whether a CWB-style word.corpus exists under the corpus tree (registry-aware).
 *
 * @return array{present:bool,hint:string}
 */
if (!function_exists('tt_fc_wizard_spoken_corpus_word_corpus_present')) {
	function tt_fc_wizard_spoken_corpus_word_corpus_present($projectRoot) {
		$root = rtrim((string) $projectRoot, '/\\');
		$out = array(
			'present' => false,
			'hint' => '',
		);
		if ($root === '') {
			return $out;
		}
		$reg = 'cqp';
		if (function_exists('getset')) {
			$g = trim((string) getset('cqp/defaults/registry', ''));
			if ($g !== '') {
				$reg = str_replace('\\', '/', $g);
			}
		}
		$reg = trim($reg, '/');
		$parts = ($reg !== '') ? explode('/', $reg) : array();
		$candidates = array(
			$root . DIRECTORY_SEPARATOR . 'cqp' . DIRECTORY_SEPARATOR . 'word.corpus',
		);
		if ($reg !== '' && $reg !== 'cqp') {
			$candidates[] = $root . DIRECTORY_SEPARATOR . 'cqp' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $reg) . DIRECTORY_SEPARATOR . 'word.corpus';
			$candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $reg) . DIRECTORY_SEPARATOR . 'word.corpus';
		}
		foreach ($candidates as $p) {
			if ($p !== '' && is_file($p)) {
				$out['present'] = true;
				$rel = str_replace($root . DIRECTORY_SEPARATOR, '', $p);
				$out['hint'] = str_replace('\\', '/', $rel);
				return $out;
			}
		}
		$out['hint'] = $reg !== '' ? ('expected near cqp/' . $reg . '/ or cqp/') : 'cqp/word.corpus';
		return $out;
	}
}

/**
 * Short prerequisite hints for the Spoken tab footer (index/XML readiness — not spoken checklist rows).
 *
 * @param array<string,mixed> $profile
 * @param array<string,int> $totals
 * @return list<string>
 */
if (!function_exists('tt_fc_wizard_spoken_footer_notes')) {
	function tt_fc_wizard_spoken_footer_notes($projectRoot, $xmlDir, $profile, $totals) {
		$notes = array();
		$scanned = isset($totals['scanned']) ? (int) $totals['scanned'] : 0;
		$wc = tt_fc_wizard_spoken_corpus_word_corpus_present($projectRoot);
		if (!$wc['present']) {
			$notes[] = 'No CWB index yet (`word.corpus` not found — expected near ' . $wc['hint'] . '). Encode/reindex when TEI and CQP settings match; see the Index(es) tab for backends and corpus detail.';
		}

		$xmlRoot = is_string($xmlDir) ? trim((string) $xmlDir) : '';
		if ($xmlRoot === '' || !is_dir($xmlRoot)) {
			$notes[] = 'No TEITOK XML folder resolved (e.g. `xmlfiles/` beside `index.php`). Add transcripts there before encoding.';
		} elseif ($scanned === 0) {
			$notes[] = 'XML folder exists but this scan found no `.xml` files — add TEI files or check paths.';
		}

		$tokHits = isset($profile['tokenAttrHits']) && is_array($profile['tokenAttrHits']) ? $profile['tokenAttrHits'] : array();
		if ($scanned > 0 && count($tokHits) === 0) {
			$notes[] = 'Sampled TEI has no token layer in this scan (no attributes on `<tok>`). Tokenize before relying on search/CWB.';
		}

		return $notes;
	}
}

/**
 * Single checklist model for the Spoken tab: XML expectations, project/index, concise notes, optional remedies (not for per-file TEI).
 *
 * @param array<string,int> $totals
 * @param array<string,mixed> $scopeRegs
 * @param array{ok:bool,keys:list<string>} $cqpSattrParse
 * @param array<string,string> $cqpDefaultsResolved
 * @param array<string,int> $regionElemHits
 * @param array{ok?:bool,configured?:bool,error?:string,hint?:string,extension_raw?:string,extensions_normalized?:list<string>,extensions_nonempty?:bool} $audioUpload From tt_fc_wizard_parse_settings_files_audio_upload
 * @param string $cqpSattrXmlPath Readable merged or canonical CQP settings XML (same file as $cqpSattrParse)
 * @return list<array{id:string,label:string,xml:string,project:string,status:string,summary:string,detail:string,info_anchor:string,remedy_url:string,remedy_label:string,probe_soft:bool,settings_fix?:bool}>
 */
if (!function_exists('tt_fc_wizard_spoken_unified_rows')) {
	function tt_fc_wizard_spoken_unified_rows(
		$projectRoot,
		$totals,
		$audioFolderPresent,
		$audioUpload,
		$scopeRegs,
		$spokenHardAllowlist,
		$spokenSoftImplicit,
		$cqpSattrParse,
		$cqpDefaultsResolved,
		$regionElemHits,
		$mergedCqpReadable,
		$cqpSattrXmlPath
	) {
		$cqpUrl = tt_fc_wizard_teitok_cqp_settings_url();
		$admUrl = tt_fc_wizard_teitok_adminsettings_url();
		$audioUploadConfigured = is_array($audioUpload) && !empty($audioUpload['configured']);
		$audioUploadHint = (is_array($audioUpload) && isset($audioUpload['hint'])) ? trim((string) $audioUpload['hint']) : '';
		$audioUploadParseOk = !is_array($audioUpload) || !empty($audioUpload['ok']);
		$audioExtNorm = (isset($audioUpload['extensions_normalized']) && is_array($audioUpload['extensions_normalized']))
			? $audioUpload['extensions_normalized']
			: array();
		$audioExtNonempty = is_array($audioUpload) && !empty($audioUpload['extensions_nonempty']);
		$audioUploadOkDetail = '';
		if ($audioUploadConfigured) {
			if (!$audioExtNonempty) {
				$audioUploadOkDetail = 'Optional: add extension="*.mp3,*.wav,*.ogg" (and *.mp4 if you use video) on the files/audio item so TEITOK only accepts those suffixes for upload (audio/video MIME handling). Omit the attribute if you do not need a whitelist.';
			} elseif (count($audioExtNorm) > 0) {
				$audioUploadOkDetail = 'Upload suffix whitelist: .' . implode(', .', $audioExtNorm) . '.';
			} else {
				$audioUploadOkDetail = 'Extension attribute is set; TEITOK applies it when accepting uploads.';
			}
		}
		$spokenDocs = isset($totals['spoken_docs']) ? (int) $totals['spoken_docs'] : 0;
		$mediaDocs = isset($totals['media_docs']) ? (int) $totals['media_docs'] : 0;
		$mediaHdr = isset($totals['media_header_docs']) ? (int) $totals['media_header_docs'] : 0;
		$uTiming = isset($totals['u_timing_docs']) ? (int) $totals['u_timing_docs'] : 0;
		$tokTiming = isset($totals['tok_timing_docs']) ? (int) $totals['tok_timing_docs'] : 0;
		$scanned = isset($totals['scanned']) ? (int) $totals['scanned'] : 0;
		$oralSample = ($spokenDocs > 0 || $mediaDocs > 0);
		$spokenPrepAudio = ($scanned > 0 && !$oralSample);
		$mediaSinglePat = isset($totals['media_single_url_pattern']) ? trim((string) $totals['media_single_url_pattern']) : '';
		$mediaFsMissingTot = isset($totals['media_fs_missing']) ? (int) $totals['media_fs_missing'] : 0;

		$skeys = (isset($cqpSattrParse['keys']) && is_array($cqpSattrParse['keys'])) ? $cqpSattrParse['keys'] : array();
		$uRegion = tt_fc_wizard_cqp_utterance_region_wired($skeys);
		$uIndexed = !empty($uRegion['wired']);
		$uMode = isset($uRegion['mode']) ? (string) $uRegion['mode'] : '';
		$uHint = isset($uRegion['hint']) ? trim((string) $uRegion['hint']) : '';
		$uInXmlOnly = false;
		foreach ($skeys as $sk) {
			$sl = strtolower(trim((string) $sk));
			if ($sl === 'u' || $sl === 'u_start' || $sl === 'u_stop') {
				$uInXmlOnly = true;
				break;
			}
		}

		$explicit = is_array($scopeRegs) && !empty($scopeRegs['explicit']);
		$uAllowed = is_array($scopeRegs) && !empty($scopeRegs['u_allowed']);
		$resolvedList = (isset($scopeRegs['resolved_allowlist']) && is_array($scopeRegs['resolved_allowlist']))
			? $scopeRegs['resolved_allowlist']
			: array();
		$listStr = implode(', ', $resolvedList);

		$st = isset($cqpDefaultsResolved['searchtype']) ? strtolower(trim((string) $cqpDefaultsResolved['searchtype'])) : '';
		$sub = isset($cqpDefaultsResolved['subtype']) ? strtolower(trim((string) $cqpDefaultsResolved['subtype'])) : '';
		if ($st === '') {
			$st = 'kwic';
		}

		$defProj = 'searchtype=' . ($cqpDefaultsResolved['searchtype'] !== '' ? $cqpDefaultsResolved['searchtype'] : '—')
			. ' · subtype=' . ($cqpDefaultsResolved['subtype'] !== '' ? $cqpDefaultsResolved['subtype'] : '—');
		if ($mergedCqpReadable) {
			$defProj .= ' · merged CQP XML ok';
		}

		$cqpPathForTextFields = is_string($cqpSattrXmlPath) ? trim((string) $cqpSattrXmlPath) : '';
		$textMediaSt = ($cqpPathForTextFields !== '')
			? tt_fc_wizard_cqp_text_media_status($cqpPathForTextFields)
			: array(
				'wired' => false,
				'parse_ok' => false,
				'error' => 'No CQP settings path',
				'text_region_found' => false,
				'keys_hint' => '',
				'u_region_found' => false,
				'u_keys_hint' => '',
				'audio_region_found' => false,
				'audio_xpath_href_wired' => false,
				'teitok_sattr_audio' => false,
				'wired_via' => '',
			);

		$rows = array();

		// Media references (TEI + CQP: utterance u_* fields per TEITOK/corpus practice; optional audio/text_media)
		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-media',
				'label' => 'Media pointers',
				'xml' => 'TEI media + CQP region u (e.g. chunk_url / media) or text/text_media / audio.',
				'project' => '—',
				'status' => 'irrelevant',
				'summary' => 'No ⟨u⟩ / media in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-media',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($mediaDocs > 0 || $mediaHdr > 0) {
			$srcCx = $mergedCqpReadable ? 'merged cqpsettings.xml' : 'canonical settings.xml';
			if (!empty($textMediaSt['wired'])) {
				$via = isset($textMediaSt['wired_via']) ? (string) $textMediaSt['wired_via'] : '';
				if ($via === 'u') {
					$projMedia = 'region u nested fields (e.g. chunk_url, media) (' . $srcCx . ')';
					if (!empty($textMediaSt['u_keys_hint'])) {
						$projMedia .= ' · u fields: ' . $textMediaSt['u_keys_hint'];
					}
					$xmlMedia = 'TEI: media/@url or ⟨media⟩ · CQP: u-level indexed fields → corpus.';
					$sumMedia = 'Media in TEI sample; CQP utterance (u) media fields declared — TEITOK-style.';
					$detMedia = 'Reindex after CQP changes. Indexed paths follow TEITOK/corpus configuration (e.g. ParCzech-style chunk_url under u for multiple audios per file). Unreleased flexicorp UI layers read TEI snippets separately.';
				} elseif ($via === 'teitok_sattr_audio') {
					$projMedia = 'cqp/sattributes: region(s) with audio flag → match &lt;region&gt;_audio (' . $srcCx . ')';
					$xmlMedia = 'TEI: media/@url or ⟨media⟩ · CQP: TEITOK native cqp.php audio column.';
					$sumMedia = 'Media in TEI; CQP s-attribute(s) marked audio (native hit playback).';
					$detMedia = 'TEITOK cqp.php adds a tabulate column and play control when a structural region has the audio mark; reindex after changes. See Help (TEITOK CQP / WaveSurfer).';
				} elseif ($via === 'audio') {
					$projMedia = 'audio region with xpath/href (' . $srcCx . ')';
					$xmlMedia = 'TEI: media/@url or ⟨media⟩ · CQP: audio region → index.';
					$sumMedia = 'Media in TEI sample; CQP audio region wired for indexed media.';
					$detMedia = 'Reindex after CQP changes so the indexed corpus exposes the media URL as a structural field (CQP queries/freqs/TEITOK tooling). Prefer u-level fields when that matches your TEITOK corpus pattern.';
				} else {
					$projMedia = 'text_media under CQP region text (' . $srcCx . ')';
					$xmlMedia = 'TEI: media/@url or ⟨media⟩ · CQP: text/text_media → index (legacy).';
					$sumMedia = 'Media in TEI sample; text_media declared under text region.';
					$detMedia = 'Reindex after changing CQP. Utterance-level (u) media fields are usually a better fit for spoken TEI than text_media when configuring new corpora.';
				}
				$rows[] = array(
					'id' => 'spoken-media',
					'label' => 'Media pointers',
					'xml' => $xmlMedia,
					'project' => $projMedia,
					'status' => 'ok',
					'summary' => $sumMedia,
					'detail' => $detMedia,
					'info_anchor' => 'spoken-media',
					'remedy_url' => '',
					'remedy_label' => '',
					'probe_soft' => false,
					'settings_fix' => false,
				);
			} elseif (!empty($textMediaSt['parse_ok'])) {
				$detailTm = 'TEITOK/cqp.php and existing spoken corpora usually index media at utterance scope (region u: e.g. chunk_url, media, u_media). Add those only if you need the path as a CQP field. Optional with respect to TEI-only playback in snippets. See Help.';
				if (!empty($textMediaSt['audio_region_found']) && empty($textMediaSt['audio_xpath_href_wired'])) {
					$detailTm = 'CQP has a top-level audio region item but xpath/href are empty — fill them if you rely on that region; many corpora use u-level fields instead (TEITOK convention).';
				} elseif (!empty($textMediaSt['u_region_found']) && empty($textMediaSt['u_keys_hint'])) {
					$detailTm = 'CQP declares region u but no nested field items for media URL — add nested items (e.g. chunk_url) per your TEITOK/corpus pattern, then reindex.';
				} elseif (empty($textMediaSt['text_region_found']) && empty($textMediaSt['audio_region_found']) && empty($textMediaSt['u_region_found'])) {
					$detailTm = 'No CQP u-/audio-/text-region wiring for indexed media yet — only needed for CQP-side use of the URL; TEI in snippets can still drive playback.';
				} elseif (!empty($textMediaSt['text_region_found']) && empty($textMediaSt['keys_hint'])) {
					$detailTm = 'CQP region text has no nested field items — if you need indexed text-level media, add text_media; for spoken utterances prefer u-level fields (see Help).';
				}
				if ($mediaSinglePat !== '') {
					$detailTm .= ' One consistent TEI media/@url path in this scan (' . $mediaSinglePat . ') — Wizard fix can merge TEITOK native &lt;region&gt;_audio wiring (region u + nested item key=&quot;audio&quot; xpath) for cqp.php playback column; refine under TEITOK CQP, then reindex.';
				}
				$projLine = 'No indexed media field wired (' . $srcCx . ')';
				if ($textMediaSt['u_keys_hint'] !== '') {
					$projLine .= ' · u fields seen: ' . $textMediaSt['u_keys_hint'];
				}
				if ($textMediaSt['keys_hint'] !== '') {
					$projLine .= ' · text fields: ' . $textMediaSt['keys_hint'];
				}
				$rows[] = array(
					'id' => 'spoken-media',
					'label' => 'Media pointers',
					'xml' => 'TEI: media/@url or header ⟨media⟩ · sample: ' . $mediaDocs . ' docs (url), ' . $mediaHdr . ' (header).',
					'project' => $projLine,
					'status' => 'optional',
					'summary' => 'Media in TEI; CQP index field for media URL not wired (soft if XML snippets carry media).',
					'detail' => $detailTm,
					'info_anchor' => 'spoken-media',
					'remedy_url' => $cqpUrl,
					'remedy_label' => 'CQP s-attributes',
					'probe_soft' => true,
					'settings_fix' => true,
				);
			} else {
				$rows[] = array(
					'id' => 'spoken-media',
					'label' => 'Media pointers',
					'xml' => 'TEI: media/@url or header ⟨media⟩ · sample: ' . $mediaDocs . ' docs (url), ' . $mediaHdr . ' (header).',
					'project' => 'Could not read CQP settings for audio/text media: ' . (isset($textMediaSt['error']) ? (string) $textMediaSt['error'] : ''),
					'status' => 'optional',
					'summary' => 'Media in TEI; CQP settings unreadable.',
					'detail' => 'Fix or expose corpus CQP settings XML so the wizard can verify u-level media fields, audio-region xpath/href, or legacy text_media under region text.',
					'info_anchor' => 'spoken-media',
					'remedy_url' => $cqpUrl,
					'remedy_label' => 'CQP settings',
					'probe_soft' => true,
					'settings_fix' => true,
				);
			}
		} elseif ($spokenPrepAudio) {
			$rows[] = array(
				'section' => 'Corpus TEI (sample)',
				'id' => 'spoken-media',
				'label' => 'Media pointers',
				'xml' => 'TEI: no ⟨u⟩/media in sample yet — add media/@url or ⟨media⟩ when you attach audio.',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'Prepare CQP utterance fields + Audio/; add TEI media when files exist.',
				'detail' => 'Greenfield spoken corpus: configure region u (and nested media fields per TEITOK pattern), disk Audio/, and files/audio uploads before or alongside TEI encoding. See Help: spoken media.',
				'info_anchor' => 'spoken-media',
				'remedy_url' => $cqpUrl,
				'remedy_label' => 'CQP s-attributes',
				'probe_soft' => true,
				'settings_fix' => true,
			);
		} else {
			$rows[] = array(
				'id' => 'spoken-media',
				'label' => 'Media pointers',
				'xml' => 'TEI: media/@url or teiHeader ⟨media⟩.',
				'project' => '—',
				'status' => 'wrong',
				'summary' => 'Oral XML without media pointers in sample.',
				'detail' => 'Add media references per transcript in TEI (e.g. media/@url or header ⟨media⟩); relative paths often resolve via Audio/. For CQP-side media fields (chunk_url, …), configure CQP after TEI carries URLs — then reindex.',
				'info_anchor' => 'spoken-media',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		}

		// Timeline: ⟨u⟩ (utterance) — separate row from ⟨tok⟩ (token) for clarity
		if ($spokenPrepAudio) {
			$rows[] = array(
				'section' => 'Corpus TEI (sample)',
				'id' => 'spoken-timing-u',
				'label' => 'Utterance timeline (⟨u⟩)',
				'xml' => 'TEI + index: ⟨u⟩ times ↔ corpus u / u_start / u_stop.',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'No ⟨u⟩ in TEI yet — add utterances when you transcribe.',
				'detail' => 'Wizard lists this row so you wire CQP utterance bounds (below) before ⟨u⟩ appears in XML; inline ⟨u⟩ @start/@stop in TEI is optional refinement.',
				'info_anchor' => 'spoken-timing-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => true,
				'settings_fix' => false,
			);
		} elseif ($spokenDocs <= 0) {
			$rows[] = array(
				'id' => 'spoken-timing-u',
				'label' => 'Utterance timeline (⟨u⟩)',
				'xml' => 'TEI + index: ⟨u⟩ times ↔ corpus u / u_start / u_stop.',
				'project' => '—',
				'status' => 'irrelevant',
				'summary' => 'No ⟨u⟩ elements in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-timing-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($uTiming > 0) {
			$rows[] = array(
				'id' => 'spoken-timing-u',
				'label' => 'Utterance timeline (⟨u⟩)',
				'xml' => 'Sample TEI: ⟨u⟩ with @start + (@stop|@end) (encoder/index still must expose u / u_start / u_stop).',
				'project' => 'Primary: CQP + settings.xml → index utterance bounds; this row is a TEI sample hint.',
				'status' => 'ok',
				'summary' => 'Inline ⟨u⟩ times in ' . $uTiming . ' doc(s) (sample).',
				'detail' => 'Deployed behaviour depends on the corpus index (reindex). If Utterance region (CQP) is wrong or missing, fix settings there first—not only TEI.',
				'info_anchor' => 'spoken-timing-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} else {
			$rows[] = array(
				'id' => 'spoken-timing-u',
				'label' => 'Utterance timeline (⟨u⟩)',
				'xml' => 'Sample: no ⟨u⟩ @start/@stop in scan — common while transcribing.',
				'project' => 'Indexed utterance bounds still come from CQP settings + encoder + reindex (see Utterance region (CQP)).',
				'status' => 'optional',
				'summary' => 'No inline ⟨u⟩ times in sample — optional TEI signal.',
				'detail' => 'Add ⟨u⟩ @start/@stop in TEI when you want inline times in files. Ensure u / u_start / u_stop are declared in CQP settings and materialized in the index (Utterance region (CQP) row; Wizard fix when applicable).',
				'info_anchor' => 'spoken-timing-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => true,
				'settings_fix' => false,
			);
		}

		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-timing-tok',
				'label' => 'Token timeline (⟨tok⟩)',
				'xml' => 'TEI-only check: ⟨tok⟩ @start / @stop (JS refinement).',
				'project' => '—',
				'status' => 'irrelevant',
				'summary' => 'No oral/spoken markers in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-timing-tok',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($spokenPrepAudio) {
			$rows[] = array(
				'section' => 'Corpus TEI (sample)',
				'id' => 'spoken-timing-tok',
				'label' => 'Token timeline (⟨tok⟩)',
				'xml' => 'TEI-only: ⟨tok⟩ @start / @stop (WaveSurfer word-level refinement).',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'No timed ⟨tok⟩ yet — optional until you align words.',
				'detail' => 'Utterance playback works without token times; add ⟨tok⟩ @start/@stop in TEI when you want word-level sync. Set xmlfile/speech/highlight to TOK in corpus view settings when using token-driven WaveSurfer.',
				'info_anchor' => 'spoken-timing-tok',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => true,
				'settings_fix' => false,
			);
		} elseif ($tokTiming > 0) {
			$rows[] = array(
				'id' => 'spoken-timing-tok',
				'label' => 'Token timeline (⟨tok⟩)',
				'xml' => 'TEI only: @start + (@stop|@end) on ⟨tok⟩ (client JS refinement; not an index row like u_start/u_stop).',
				'project' => 'Browser / WaveSurfer — not pulled from CQP index here.',
				'status' => 'ok',
				'summary' => '⟨tok⟩ times in ' . $tokTiming . ' doc(s).',
				'detail' => 'Used to refine word-level sync in the UI; no separate wizard check that these exist in the corpus index.',
				'info_anchor' => 'spoken-timing-tok',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} else {
			$rows[] = array(
				'id' => 'spoken-timing-tok',
				'label' => 'Token timeline (⟨tok⟩)',
				'xml' => 'TEI only: ⟨tok⟩ @start / @stop — optional refinement for JS (not indexed like ⟨u⟩ bounds).',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'Oral sample but no token-level time spans in scan.',
				'detail' => 'Optional: coarse utterance playback uses ⟨u⟩ / index bounds; add ⟨tok⟩ times in XML when you want word-level WaveSurfer alignment.',
				'info_anchor' => 'spoken-timing-tok',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => true,
				'settings_fix' => false,
			);
		}

		// CQP utterance region (u or u_start + u_stop)
		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-cqp-u',
				'label' => 'Utterance region (CQP)',
				'xml' => 'Index: utterance s-region matching TEI ⟨u⟩ (single u or u_start/u_stop).',
				'project' => '—',
				'status' => 'irrelevant',
				'summary' => 'No oral markers in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-cqp-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($uIndexed) {
			if ($uMode === 'u_bounds') {
				$projU = $uInXmlOnly
					? ('merged CQP XML: ' . ($uHint !== '' ? $uHint : 'u_start + u_stop'))
					: 'runtime getset: u_start + u_stop';
			} else {
				$projU = $uInXmlOnly
					? ('merged CQP XML: ' . ($uHint !== '' ? $uHint : 'region u'))
					: 'runtime getset: region u';
			}
			$rows[] = array(
				'id' => 'spoken-cqp-u',
				'label' => 'Utterance region (CQP)',
				'xml' => 'Index: ⟨u⟩ ↔ CQP s-attributes (u or u_start + u_stop).',
				'project' => $projU,
				'status' => 'ok',
				'summary' => ($uMode === 'u_bounds')
					? 'Utterance indexed via u_start + u_stop.'
					: 'Utterance region u is indexed.',
				'detail' => ($uMode === 'u_bounds')
					? 'Defaults subtype u is satisfied by paired bounds keys; no separate s-attribute key "u" required.'
					: '',
				'info_anchor' => 'spoken-cqp-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($spokenPrepAudio) {
			$rows[] = array(
				'section' => 'Utterance & search',
				'id' => 'spoken-cqp-u',
				'label' => 'Utterance region (CQP)',
				'xml' => 'Index: utterance s-region matching TEI ⟨u⟩ (single u or u_start/u_stop).',
				'project' => 'No utterance s-region wired in CQP settings.',
				'status' => 'wrong',
				'summary' => 'Prepare index: no utterance CQP region yet (no ⟨u⟩ in TEI).',
				'detail' => 'Declare region u or paired u_start/u_stop in TEITOK CQP settings, complete xpath(s), reindex when spoken TEI exists — required for utterance-scoped search and many spoken features.',
				'info_anchor' => 'spoken-cqp-u',
				'remedy_url' => $cqpUrl,
				'remedy_label' => 'CQP s-attributes',
				'probe_soft' => false,
				'settings_fix' => true,
			);
		} else {
			$rows[] = array(
				'id' => 'spoken-cqp-u',
				'label' => 'Utterance region (CQP)',
				'xml' => 'Index: declare utterance s-region (u or u_start + u_stop).',
				'project' => 'No utterance s-region wired in CQP settings.',
				'status' => 'wrong',
				'summary' => 'Spoken XML but no utterance CQP region.',
				'detail' => 'Add region u, or paired u_start and u_stop per your TEITOK settings.xml, then reindex — else flexicorp cannot scope hits to utterances.',
				'info_anchor' => 'spoken-cqp-u',
				'remedy_url' => $cqpUrl,
				'remedy_label' => 'CQP s-attributes',
				'probe_soft' => false,
				'settings_fix' => true,
			);
		}

		// Search context regions (TEITOK context_scope_regions for flexicorp-backed search)
		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-allowlist',
				'label' => 'Search context regions',
				'xml' => '—',
				'project' => $listStr !== '' ? $listStr : '(implicit default)',
				'status' => 'irrelevant',
				'summary' => 'No oral markers in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-allowlist',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($spokenHardAllowlist) {
			$allowSumHard = $spokenPrepAudio
				? 'Explicit allowlist excludes u — fix before spoken TEI.'
				: 'Allowlist excludes u while oral XML exists.';
			$allowDetHard = $spokenPrepAudio
				? 'Append u to flexicorp/context_scope_regions (wizard or admin) so utterance-wide search context is allowed when you add ⟨u⟩ transcripts.'
				: 'Search cannot expand to utterance-wide hits — add u or relax the list.';
			$rows[] = array(
				'id' => 'spoken-allowlist',
				'label' => 'Search context regions',
				'xml' => '—',
				'project' => $listStr !== '' ? $listStr : 'explicit list without u',
				'status' => 'wrong',
				'summary' => $allowSumHard,
				'detail' => $allowDetHard,
				'info_anchor' => 'spoken-allowlist',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Corpus settings',
				'probe_soft' => false,
				'settings_fix' => true,
			);
		} elseif ($spokenSoftImplicit) {
			$allowDetSoft = $spokenPrepAudio
				? 'Implicit defaults already include u (same as flexicorp when unset). Materialize an explicit list via wizard only if you need a frozen set before encoding.'
				: 'Record explicit search context regions in corpus settings if you need frozen behaviour.';
			$rows[] = array(
				'id' => 'spoken-allowlist',
				'label' => 'Search context regions',
				'xml' => '—',
				'project' => 'implicit defaults (includes u): ' . $listStr,
				'status' => 'optional',
				'summary' => 'Implicit allowlist.',
				'detail' => $allowDetSoft,
				'info_anchor' => 'spoken-allowlist',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Record allowlist',
				'probe_soft' => true,
				'settings_fix' => true,
			);
		} elseif ($explicit && $uAllowed) {
			$rows[] = array(
				'id' => 'spoken-allowlist',
				'label' => 'Search context regions',
				'xml' => '—',
				'project' => $listStr,
				'status' => 'ok',
				'summary' => 'Explicit list includes u.',
				'detail' => '',
				'info_anchor' => 'spoken-allowlist',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} else {
			$rows[] = array(
				'id' => 'spoken-allowlist',
				'label' => 'Search context regions',
				'xml' => '—',
				'project' => $listStr !== '' ? $listStr : '(review)',
				'status' => 'optional',
				'summary' => 'Review resolved allowlist.',
				'detail' => 'Ensure utterance scope is intended for this corpus.',
				'info_anchor' => 'spoken-allowlist',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Corpus settings',
				'probe_soft' => true,
				'settings_fix' => true,
			);
		}

		// Audio folder + TEITOK &lt;files&gt; audio upload slot (Resources/settings.xml)
		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-audio-dir',
				'label' => 'Audio folder & uploads',
				'xml' => 'Disk Audio/ + &lt;files&gt; item for audio (TEITOK uploads).',
				'project' => '—',
				'status' => 'irrelevant',
				'summary' => 'No oral markers in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-audio-dir',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($audioFolderPresent && $audioUploadConfigured) {
			$rows[] = array(
				'id' => 'spoken-audio-dir',
				'label' => 'Audio folder & uploads',
				'xml' => 'TEI relative paths → corpus Audio/; TEITOK allows audio uploads.',
				'project' => 'Audio/ on disk · uploads: ' . ($audioUploadHint !== '' ? $audioUploadHint : 'configured'),
				'status' => 'ok',
				'summary' => 'Audio folder present; file-upload audio slot in settings.',
				'detail' => $audioUploadOkDetail,
				'info_anchor' => 'spoken-audio-dir',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($audioFolderPresent && !$audioUploadConfigured) {
			$audioDiskHard = ($oralSample && $mediaFsMissingTot > 0);
			$rows[] = array(
				'id' => 'spoken-audio-dir',
				'label' => 'Audio folder & uploads',
				'xml' => 'Add &lt;files&gt;&lt;item key="audio" folder="Audio" …/&gt; (extension optional).',
				'project' => 'Audio/ on disk · no audio slot in &lt;files&gt; (settings.xml).',
				'status' => $audioDiskHard ? 'wrong' : 'optional',
				'summary' => $audioDiskHard
					? 'Local media paths in this sample do not resolve — add TEITOK files/audio (wizard) so uploads and Audio/ resolve under the corpus.'
					: 'Folder exists; TEITOK may not allow audio uploads.',
				'detail' => $audioUploadParseOk
					? ($audioDiskHard
						? 'Sample TEI references relative media/@url paths that were not found beside XML or under Audio/. Wizard fix merges the standard files/audio item into Resources/settings.xml and creates Audio/ when you save (same settings TEITOK uses for uploads). Remote-only http(s) media does not need this row.'
						: 'Define an audio file upload under ttsettings/files in Resources/settings.xml so operators can upload into Audio/. The extension attribute is optional; when set, list allowed suffixes (e.g. *.mp3,*.wav,*.ogg or *.mp4) so TEITOK restricts uploads to those types.')
					: ('Could not read settings.xml: ' . (isset($audioUpload['error']) ? (string) $audioUpload['error'] : '')),
				'info_anchor' => 'spoken-audio-dir',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Files / uploads',
				'probe_soft' => !$audioDiskHard,
				'settings_fix' => true,
			);
		} elseif (!$audioFolderPresent && $audioUploadConfigured) {
			$rows[] = array(
				'id' => 'spoken-audio-dir',
				'label' => 'Audio folder & uploads',
				'xml' => 'Create Audio/ beside index.php (or match folder= in &lt;files&gt;).',
				'project' => 'Uploads configured · disk: no Audio/ · ' . ($audioUploadHint !== '' ? $audioUploadHint : 'audio item'),
				'status' => 'optional',
				'summary' => 'Upload slot in settings; Audio/ folder missing on disk.',
				'detail' => 'Create the folder TEI paths resolve against on disk, or align TEI media URLs in XML.',
				'info_anchor' => 'spoken-audio-dir',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Files / uploads',
				'probe_soft' => true,
				'settings_fix' => true,
			);
		} else {
			$audioDiskHard = ($oralSample && $mediaFsMissingTot > 0);
			$rows[] = array(
				'id' => 'spoken-audio-dir',
				'label' => 'Audio folder & uploads',
				'xml' => 'Disk Audio/ + &lt;files&gt; audio item for TEITOK uploads.',
				'project' => 'No Audio/ folder · no audio upload item in &lt;files&gt;',
				'status' => $audioDiskHard ? 'wrong' : 'optional',
				'summary' => $audioDiskHard
					? 'Relative media in TEI but no Audio/ + no files/audio — required for on-disk hosting unless you switch to remote URLs.'
					: 'No Audio/ folder and no audio upload slot (or unreadable settings).',
				'detail' => $audioDiskHard
					? 'This scan found local-style media references that do not resolve on disk. Use Wizard fix to add &lt;files&gt;&lt;item key="audio" folder="Audio" display="audio"/&gt; to Resources/settings.xml and create Audio/ beside index.php (TEITOK upload convention). Optional only when all media are absolute http(s) URLs.'
					: 'Optional if media URLs are absolute. Otherwise add an on-disk Audio/ folder (TEI paths) and a files/audio item in Resources/settings.xml.',
				'info_anchor' => 'spoken-audio-dir',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Files / uploads',
				'probe_soft' => !$audioDiskHard,
				'settings_fix' => true,
			);
		}

		$xfU = tt_fc_wizard_xmlfile_u_editor_attrs_status();

		// TEITOK XML editor (xmlfile/sattributes): operator-editable ⟨u⟩ fields — who + media / medianode URL. Do not expect @start/@stop here (timing is not usually edited via this list).
		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-xmlfile-u',
				'label' => 'XML editor: ⟨u⟩ fields',
				'xml' => 'xmlfile/sattributes — speaker + media/medianode URL for ⟨u⟩.',
				'project' => '—',
				'status' => 'irrelevant',
				'summary' => 'No oral markers in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-xmlfile-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif (!$xfU['parse_ok']) {
			$rows[] = array(
				'section' => 'Defaults & media',
				'id' => 'spoken-xmlfile-u',
				'label' => 'XML editor: ⟨u⟩ fields',
				'xml' => 'xmlfile/sattributes — who + media URL on utterance.',
				'project' => 'getset() unavailable — cannot read xmlfile/sattributes.',
				'status' => 'optional',
				'summary' => 'Runtime settings bridge missing.',
				'detail' => 'Cannot inspect TEITOK xmlfile/sattributes. Configure speaker (who) and media/medianode URL for ⟨u⟩ in corpus XML-editor settings (Resources/settings.xml). Timing (@start/@stop) usually stays outside this attribute list.',
				'info_anchor' => 'spoken-xmlfile-u',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Corpus settings',
				'probe_soft' => true,
				'settings_fix' => false,
			);
		} elseif (!$xfU['has_region_branch']) {
			$rows[] = array(
				'section' => 'Defaults & media',
				'id' => 'spoken-xmlfile-u',
				'label' => 'XML editor: ⟨u⟩ fields',
				'xml' => 'xmlfile/sattributes — utterance region «' . tt_fc_wizard_h($xfU['utt_region']) . '» + editable attrs.',
				'project' => 'No xmlfile/sattributes branch found for utterance region.',
				'status' => 'optional',
				'summary' => 'Missing xmlfile utterance attribute map.',
				'detail' => 'Declare which attributes on ⟨u⟩ appear in TEITOK’s XML editor: include who (speaker) and your media/medianode URL field. Do not expect @start/@stop in this list — those are normally maintained by transcription/index tooling, not as hand-edited xmlfile fields.',
				'info_anchor' => 'spoken-xmlfile-u',
				'remedy_url' => $admUrl,
				'remedy_label' => 'XML editor / xmlfile',
				'probe_soft' => true,
				'settings_fix' => false,
			);
		} elseif ($xfU['who'] && $xfU['media_url']) {
			$rows[] = array(
				'section' => 'Defaults & media',
				'id' => 'spoken-xmlfile-u',
				'label' => 'XML editor: ⟨u⟩ fields',
				'xml' => 'xmlfile/sattributes · keys: ' . ($xfU['keys_hint'] !== '' ? tt_fc_wizard_h($xfU['keys_hint']) : '—') . '.',
				'project' => 'who + media URL field listed for region «' . tt_fc_wizard_h($xfU['utt_region']) . '».',
				'status' => 'ok',
				'summary' => 'Speaker (who) and media/medianode-style URL are exposed for the utterance region.',
				'detail' => '@start/@stop are intentionally not required in xmlfile/sattributes — TEITOK typically does not expose utterance timing there.',
				'info_anchor' => 'spoken-xmlfile-u',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} else {
			$missBits = array();
			if (!$xfU['who']) {
				$missBits[] = 'who (speaker)';
			}
			if (!$xfU['media_url']) {
				$missBits[] = 'media/medianode URL';
			}
			$missHuman = implode(' · ', $missBits);
			$xfWrong = $oralSample && (!$xfU['who'] || !$xfU['media_url']);
			$rows[] = array(
				'section' => 'Defaults & media',
				'id' => 'spoken-xmlfile-u',
				'label' => 'XML editor: ⟨u⟩ fields',
				'xml' => 'xmlfile/sattributes · keys seen: ' . ($xfU['keys_hint'] !== '' ? tt_fc_wizard_h($xfU['keys_hint']) : '—') . '.',
				'project' => 'Utterance «' . tt_fc_wizard_h($xfU['utt_region']) . '» — missing: ' . tt_fc_wizard_h($missHuman) . '.',
				'status' => $xfWrong ? 'wrong' : 'optional',
				'summary' => 'Add ' . tt_fc_wizard_h($missHuman) . ' to xmlfile/sattributes for ⟨u⟩ editing.',
				'detail' => 'TEITOK’s xmlfile section should list fields editors change directly: speaker (who) and the media/medianode URL on ⟨u⟩. Timing attributes (@start/@stop) belong to transcription/index pipelines — they are not part of this checklist.',
				'info_anchor' => 'spoken-xmlfile-u',
				'remedy_url' => $admUrl,
				'remedy_label' => 'XML editor / xmlfile',
				'probe_soft' => !$xfWrong,
				'settings_fix' => false,
			);
		}

		// WaveSurfer (wavesurfer.php): xmlfile/defaults/speechturn, xmlfile/speech/highlight; allttag elsewhere (flexicorp). See wizard_help #spoken-wavesurfer.
		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-wavesurfer-allttag',
				'label' => 'WaveSurfer (speech / highlight)',
				'xml' => '—',
				'project' => '—',
				'status' => 'irrelevant',
				'summary' => 'No oral markers in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-wavesurfer',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($spokenPrepAudio) {
			$wsHlPrep = function_exists('getset') ? trim((string) getset('xmlfile/speech/highlight', '')) : '';
			$uttPrep = function_exists('getset') ? trim((string) getset('xmlfile/defaults/speechturn', 'U')) : 'U';
			$projWsPrep = 'speech/highlight: ' . ($wsHlPrep !== '' ? $wsHlPrep : '(empty)') . ' · speechturn → ' . ($uttPrep !== '' ? strtoupper($uttPrep) : 'U');
			$rows[] = array(
				'section' => 'Defaults & media',
				'id' => 'spoken-wavesurfer-allttag',
				'label' => 'WaveSurfer (speech / highlight)',
				'xml' => '—',
				'project' => $projWsPrep,
				'status' => 'optional',
				'summary' => 'No timed TEI yet — set defaults before first playback.',
				'detail' => 'Configure xmlfile/speech/highlight (utterance vs TOK) and xmlfile/defaults/speechturn in corpus view settings (Resources/settings.xml); adjust again after ⟨tok⟩ or ⟨u⟩ times exist in TEI.',
				'info_anchor' => 'spoken-wavesurfer',
				'remedy_url' => $admUrl,
				'remedy_label' => 'Corpus / view settings',
				'probe_soft' => true,
				'settings_fix' => true,
			);
		} else {
			$tokTimedWs = isset($totals['tok_timing_docs']) ? (int) $totals['tok_timing_docs'] : 0;
			$wsHl = function_exists('getset') ? trim((string) getset('xmlfile/speech/highlight', '')) : '';
			$wsWantsTok = ($wsHl !== '' && strcasecmp($wsHl, 'TOK') === 0);
			$uttDefLbl = function_exists('getset') ? trim((string) getset('xmlfile/defaults/speechturn', 'U')) : 'U';
			$uttTagLbl = strtoupper($uttDefLbl !== '' ? $uttDefLbl : 'U');

			if ($tokTimedWs === 0 && !$wsWantsTok) {
				$rows[] = array(
					'section' => 'Defaults & media',
					'id' => 'spoken-wavesurfer-allttag',
					'label' => 'WaveSurfer (speech / highlight)',
					'xml' => 'No ⟨tok⟩ @start + (@stop|@end) in sample; corpus speech/highlight is not TOK.',
					'project' => 'Utterance-level waveform — word highlight not required by settings.',
					'status' => 'irrelevant',
					'summary' => 'Token times absent; settings do not request word-level (TOK) highlight.',
					'detail' => 'Add ⟨tok⟩ times in TEI for word-level sync, or set speech/highlight to TOK in corpus settings when you are ready. Until then utterance timing is enough; no admin fix suggested here.',
					'info_anchor' => 'spoken-wavesurfer',
					'remedy_url' => '',
					'remedy_label' => '',
					'probe_soft' => false,
					'settings_fix' => false,
				);
			} elseif ($tokTimedWs > 0 && $wsWantsTok) {
				$rows[] = array(
					'section' => 'Defaults & media',
					'id' => 'spoken-wavesurfer-allttag',
					'label' => 'WaveSurfer (speech / highlight)',
					'xml' => 'Sample has ⟨tok⟩ with @start + (@stop|@end); xmlfile/speech/highlight = TOK.',
					'project' => 'wavesurfer.php: token highlight matches timed ⟨tok⟩; speechturn still selects utterance tag.',
					'status' => 'ok',
					'summary' => 'Token times in TEI and speech/highlight=TOK — WaveSurfer can follow words.',
					'detail' => 'Corpus/view allttag applies outside wavesurfer.php. See Help.',
					'info_anchor' => 'spoken-wavesurfer',
					'remedy_url' => '',
					'remedy_label' => '',
					'probe_soft' => false,
					'settings_fix' => false,
				);
			} elseif ($tokTimedWs > 0 && !$wsWantsTok) {
				$rows[] = array(
					'section' => 'Defaults & media',
					'id' => 'spoken-wavesurfer-allttag',
					'label' => 'WaveSurfer (speech / highlight)',
					'xml' => 'Sample has ⟨tok⟩ times — set xmlfile/speech/highlight to TOK for word-level WaveSurfer.',
					'project' => 'speech/highlight is empty or not TOK; utterance tag: xmlfile/defaults/speechturn.',
					'status' => 'optional',
					'summary' => 'Token times present — align speech/highlight to TOK (wizard or admin).',
					'detail' => 'Timed ⟨tok⟩ enables word sync; TEITOK highlight must be TOK when you want WaveSurfer driven by tokens. Use Wizard fix or Corpus / view settings.',
					'info_anchor' => 'spoken-wavesurfer',
					'remedy_url' => $admUrl,
					'remedy_label' => 'Corpus / view settings',
					'probe_soft' => false,
					'settings_fix' => true,
				);
			} else {
				$rows[] = array(
					'section' => 'Defaults & media',
					'id' => 'spoken-wavesurfer-allttag',
					'label' => 'WaveSurfer (speech / highlight)',
					'xml' => 'speech/highlight requests TOK but this sample has no ⟨tok⟩ time spans.',
					'project' => 'Mismatch: word-level highlight without token timing (add ⟨tok⟩ times or switch highlight).',
					'status' => 'wrong',
					'summary' => 'speech/highlight=TOK but no ⟨tok⟩ times in scan — fix TEI or realign highlight.',
					'detail' => 'Either add ⟨tok⟩ @start + (@stop|@end) in TEI, or set speech/highlight to the utterance tag from xmlfile/defaults/speechturn (' . $uttTagLbl . ') so WaveSurfer follows ⟨' . strtolower($uttTagLbl) . '⟩ until token times exist.',
					'info_anchor' => 'spoken-wavesurfer',
					'remedy_url' => $admUrl,
					'remedy_label' => 'Corpus / view settings',
					'probe_soft' => false,
					'settings_fix' => true,
				);
			}
		}

		// Listening-sized TEITOK defaults (merged with probe below)
		if (!$oralSample && !$spokenPrepAudio) {
			$rows[] = array(
				'id' => 'spoken-cqp-listen',
				'label' => 'TEITOK default snippet scope',
				'xml' => '—',
				'project' => $defProj,
				'status' => 'irrelevant',
				'summary' => 'No oral markers in sample.',
				'detail' => '',
				'info_anchor' => 'spoken-cqp-listen',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} elseif ($st === 'context' && $sub === 'u') {
			$rows[] = array(
				'id' => 'spoken-cqp-listen',
				'label' => 'TEITOK default snippet scope',
				'xml' => '—',
				'project' => $defProj,
				'status' => 'ok',
				'summary' => 'Defaults: context + subtype u.',
				'detail' => '',
				'info_anchor' => 'spoken-cqp-listen',
				'remedy_url' => '',
				'remedy_label' => '',
				'probe_soft' => false,
				'settings_fix' => false,
			);
		} else {
			$rows[] = array(
				'id' => 'spoken-cqp-listen',
				'label' => 'TEITOK default snippet scope',
				'xml' => '—',
				'project' => $defProj,
				'status' => 'optional',
				'summary' => 'Defaults are not context+u.',
				'detail' => 'KWIC/sentence defaults suit trees; spoken listening opens utterance-wide only after UI scope change unless you set context+u.',
				'info_anchor' => 'spoken-cqp-listen',
				'remedy_url' => $cqpUrl,
				'remedy_label' => 'CQP defaults',
				'probe_soft' => true,
				'settings_fix' => true,
			);
		}

		// CQP defaults probe (subtype vs index, s vs u, listening soft)
		$probeRows = tt_fc_wizard_cqp_search_defaults_probe($regionElemHits, $cqpDefaultsResolved, $cqpSattrParse, $totals);
		$anchorMap = array(
			'cqp/defaults/subtype' => 'spoken-cqp-defaults-subtype',
			'oral / spoken context' => 'spoken-cqp-defaults-oral',
			'sentence vs utterance' => 'spoken-cqp-defaults-su',
			'listening-sized default context' => 'spoken-cqp-defaults-listen-soft',
		);
		foreach ($probeRows as $pr) {
			$lbl = isset($pr['label']) ? (string) $pr['label'] : '';
			if ($lbl === 'listening-sized default context') {
				continue;
			}
			$ok = !empty($pr['ok']);
			$sev = isset($pr['severity']) ? strtolower((string) $pr['severity']) : 'hard';
			$anch = isset($anchorMap[$lbl]) ? $anchorMap[$lbl] : 'spoken-cqp-defaults';
			$detailPr = isset($pr['detail']) ? (string) $pr['detail'] : '';
			$soft = ($sev === 'soft');
			if ($ok) {
				$rows[] = array(
					'id' => $anch,
					'label' => isset($pr['group']) ? ((string) $pr['group'] . ': ' . $lbl) : $lbl,
					'xml' => 'Sample regions vs cqp/defaults — see Help.',
					'project' => $defProj,
					'status' => 'ok',
					'summary' => $detailPr !== '' ? $detailPr : 'OK.',
					'detail' => '',
					'info_anchor' => $anch,
					'remedy_url' => '',
					'remedy_label' => '',
					'probe_soft' => false,
					'settings_fix' => false,
				);
				continue;
			}
			$stRow = $soft ? 'optional' : 'wrong';
			$rows[] = array(
				'id' => $anch,
				'label' => isset($pr['group']) ? ((string) $pr['group'] . ': ' . $lbl) : $lbl,
				'xml' => 'Compare TEI ⟨s⟩/⟨u⟩ with indexed regions + defaults.',
				'project' => $defProj,
				'status' => $stRow,
				'summary' => $soft ? 'Review default scope.' : 'Fix defaults or index.',
				'detail' => $detailPr,
				'info_anchor' => $anch,
				'remedy_url' => $cqpUrl,
				'remedy_label' => 'CQP defaults',
				'probe_soft' => $soft,
				'settings_fix' => true,
			);
		}

		return $rows;
	}
}

/**
 * Unwrap flexicorp --api JSON envelope to done.result (associative array or null).
 *
 * @param array<string,mixed>|null $call Return value of tt_flexicorp_run when present.
 * @return array<string,mixed>|null
 */
if (!function_exists('tt_fc_wizard_flexicorp_api_result')) {
	function tt_fc_wizard_flexicorp_api_result($call) {
		if (!is_array($call) || empty($call['ok'])) {
			return null;
		}
		$data = isset($call['data']) && is_array($call['data']) ? $call['data'] : array();
		$done = isset($data['done']) && is_array($data['done']) ? $data['done'] : array();
		$res = isset($done['result']) ? $done['result'] : null;
		return is_array($res) ? $res : null;
	}
}

/**
 * Read a single &lt;item key="…"/&gt; value under &lt;ttsettings&gt; (TEITOK Resources/settings.xml shape).
 *
 * @param string $settingsPath Absolute path to settings XML
 * @param string $itemKey Full key path (e.g. defaults/flexicorp/backend)
 * @return string Trimmed value or empty string
 */
if (!function_exists('tt_fc_wizard_settings_ttsettings_item_value')) {
	function tt_fc_wizard_settings_ttsettings_item_value($settingsPath, $itemKey) {
		$itemKey = trim((string) $itemKey);
		if ($itemKey === '' || !is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			return '';
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			return '';
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			return '';
		}
		$xp = new DOMXPath($dom);
		$want = strtolower($itemKey);
		$nodes = $xp->query('//ttsettings//item');
		if (!($nodes instanceof DOMNodeList)) {
			return '';
		}
		for ($i = 0; $i < $nodes->length; $i++) {
			$n = $nodes->item($i);
			if (!$n instanceof DOMElement) {
				continue;
			}
			$k = strtolower(trim($n->getAttribute('key')));
			if ($k !== $want) {
				continue;
			}
			$v = trim($n->getAttribute('default'));
			if ($v === '') {
				$v = trim($n->getAttribute('value'));
			}
			if ($v === '') {
				$v = trim($n->textContent);
			}
			return $v;
		}
		return '';
	}
}

/**
 * Where TEITOK / disk record the default flexicorp backend (multiple sources can disagree until merged).
 *
 * @return array{rows: array<int, array<string,string>>, effective_raw: string}
 */
if (!function_exists('tt_fc_wizard_backend_default_sources')) {
	function tt_fc_wizard_backend_default_sources($projectRoot, $canonicalSettingsPath, $mergedCqpPath, $mergedCqpReadable) {
		$rows = array();
		$key = 'defaults/flexicorp/backend';

		if (function_exists('tt_flexicorp_backend_default')) {
			$v = trim((string) tt_flexicorp_backend_default());
			$rows[] = array(
				'label' => 'tt_flexicorp_backend_default()',
				'location' => 'Registered PHP helper (when flexicorp.php is loaded)',
				'value' => $v !== '' ? $v : '(empty)',
			);
		}

		if (function_exists('getset')) {
			$v = trim((string) getset($key, ''));
			$rows[] = array(
				'label' => "getset('" . $key . "')",
				'location' => 'TEITOK merged settings view (runtime)',
				'value' => $v !== '' ? $v : '(empty)',
			);
		}

		$mergedPath = ($mergedCqpReadable && is_string($mergedCqpPath) && $mergedCqpPath !== '') ? $mergedCqpPath : '';
		if ($mergedPath !== '') {
			$v = tt_fc_wizard_settings_ttsettings_item_value($mergedPath, $key);
			$rows[] = array(
				'label' => 'Resources/settings.xml (merged cqpsettings)',
				'location' => $mergedPath,
				'value' => $v !== '' ? $v : '(no item or empty)',
			);
		}

		$canon = is_string($canonicalSettingsPath) ? trim($canonicalSettingsPath) : '';
		if ($canon !== '' && $canon !== $mergedPath) {
			$v = tt_fc_wizard_settings_ttsettings_item_value($canon, $key);
			$rows[] = array(
				'label' => 'Canonical corpus settings.xml on disk',
				'location' => $canon,
				'value' => $v !== '' ? $v : '(no item or empty)',
			);
		}

		$effective = '';
		if (function_exists('tt_flexicorp_backend_default')) {
			$effective = trim((string) tt_flexicorp_backend_default());
		}
		if ($effective === '' && function_exists('getset')) {
			$effective = trim((string) getset($key, ''));
		}
		if ($effective === '' && $mergedPath !== '') {
			$effective = trim((string) tt_fc_wizard_settings_ttsettings_item_value($mergedPath, $key));
		}
		if ($effective === '' && $canon !== '') {
			$effective = trim((string) tt_fc_wizard_settings_ttsettings_item_value($canon, $key));
		}

		return array('rows' => $rows, 'effective_raw' => $effective);
	}
}

/**
 * Probe common TEITOK filenames that register flexicorp CLI / in-browser API helpers (paths relative to corpus root).
 *
 * @return array<int, array<string, string|bool>>
 */
if (!function_exists('tt_fc_wizard_probe_flexicorp_helper_files')) {
	function tt_fc_wizard_probe_flexicorp_helper_files($projectRoot) {
		$root = is_string($projectRoot) ? rtrim($projectRoot, '/\\') : '';
		$candidates = array(
			'flexicorp.php',
			'flexicorp_FUNCTIONS.php',
			'flexicorp_functions.php',
			'Scripts/flexicorp.php',
			'common/flexicorp_FUNCTIONS.php',
			'common/flexicorp_functions.php',
		);
		$out = array();
		foreach ($candidates as $rel) {
			$full = $root !== '' ? ($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)) : '';
			$ok = ($full !== '' && is_file($full) && is_readable($full));
			$out[] = array(
				'relpath' => $rel,
				'present' => $ok,
			);
		}
		return $out;
	}
}

/**
 * Build index / backend diagnostics for the Index(es) wizard tab.
 * Uses <code>tt_flexicorp_run</code> / <code>tt_flexicorp_info_backends</code> only when TEITOK has already registered them;
 * the wizard does not load flexicorp.php.
 *
 * @param array<string,mixed> $profile From tt_fc_wizard_profile_from_sample
 * @return array<string,mixed>
 */
if (!function_exists('tt_fc_wizard_indexes_collect')) {
	function tt_fc_wizard_indexes_collect($projectRoot, $profile, $canonicalSettingsPath, $mergedCqpPath, $mergedCqpReadable) {
		$out = array(
			'flexicorp_loaded' => function_exists('tt_flexicorp_run'),
			'cli_bridge_absent' => false,
			'backend_default_sources' => array(),
			'helper_script_probe' => array(),
			'overview_error' => '',
			'overview' => null,
			'default_backend_raw' => '',
			'default_backend' => '',
			'default_maps_index' => null,
			'default_maps_detail' => '',
			'primary_info' => null,
			'primary_info_error' => '',
			'pattr_keys' => array(),
			'sattr_keys' => array(),
			'token_xml_attrs' => array(),
			'region_elems_sample' => array(),
			'token_in_xml_not_indexed' => array(),
			'token_indexed_not_in_sample' => array(),
			'region_in_xml_not_indexed' => array(),
			'combo_rows' => array(),
			'vocab_token' => array(),
			'vocab_region' => array(),
			'vocab_note' => '',
		);

		$settingsPathForCqp = ($mergedCqpReadable && is_string($mergedCqpPath) && $mergedCqpPath !== '')
			? $mergedCqpPath
			: $canonicalSettingsPath;

		$pattrParse = tt_fc_wizard_parse_cqp_pattribute_keys($settingsPathForCqp);
		$sattrParse = tt_fc_wizard_parse_cqp_sattribute_keys($settingsPathForCqp);
		$out['pattr_keys'] = (isset($pattrParse['keys']) && is_array($pattrParse['keys'])) ? $pattrParse['keys'] : array();
		$out['sattr_keys'] = (isset($sattrParse['keys']) && is_array($sattrParse['keys'])) ? $sattrParse['keys'] : array();

		$tokenHits = isset($profile['tokenAttrHits']) && is_array($profile['tokenAttrHits']) ? array_keys($profile['tokenAttrHits']) : array();
		sort($tokenHits);
		$out['token_xml_attrs'] = $tokenHits;

		$regionHits = isset($profile['regionElemHits']) && is_array($profile['regionElemHits']) ? array_keys($profile['regionElemHits']) : array();
		sort($regionHits);
		$out['region_elems_sample'] = $regionHits;

		$pFlip = array();
		foreach ($out['pattr_keys'] as $pk) {
			$pFlip[strtolower(trim((string) $pk))] = true;
		}
		foreach ($tokenHits as $tk) {
			$tl = strtolower(trim((string) $tk));
			if ($tl !== '' && !isset($pFlip[$tl])) {
				$out['token_in_xml_not_indexed'][] = (string) $tk;
			}
		}
		$sampleFlip = array();
		foreach ($tokenHits as $tk) {
			$sampleFlip[strtolower(trim((string) $tk))] = true;
		}
		foreach ($out['pattr_keys'] as $pk) {
			$pl = strtolower(trim((string) $pk));
			if ($pl !== '' && !isset($sampleFlip[$pl])) {
				$out['token_indexed_not_in_sample'][] = (string) $pk;
			}
		}

		$sFlip = array();
		foreach ($out['sattr_keys'] as $sk) {
			$sFlip[strtolower(trim((string) $sk))] = true;
		}
		$utteranceWiredIdx = tt_fc_wizard_cqp_utterance_region_wired($out['sattr_keys']);
		foreach ($regionHits as $rk) {
			$rl = strtolower(trim((string) $rk));
			if ($rl === '') {
				continue;
			}
			if (isset($sFlip[$rl])) {
				continue;
			}
			if ($rl === 'u' && !empty($utteranceWiredIdx['wired'])) {
				continue;
			}
			$out['region_in_xml_not_indexed'][] = (string) $rk;
		}

		if (!function_exists('tt_flexicorp_run')) {
			$out['cli_bridge_absent'] = true;
			$sources = tt_fc_wizard_backend_default_sources($projectRoot, $canonicalSettingsPath, $mergedCqpPath, $mergedCqpReadable);
			$out['backend_default_sources'] = isset($sources['rows']) && is_array($sources['rows']) ? $sources['rows'] : array();
			$out['helper_script_probe'] = tt_fc_wizard_probe_flexicorp_helper_files($projectRoot);
			$eff = isset($sources['effective_raw']) ? trim((string) $sources['effective_raw']) : '';
			$out['default_backend_raw'] = $eff;
			$db = $eff !== '' ? $eff : 'cqp';
			if (function_exists('tt_flexicorp_backend_canonical')) {
				$db = tt_flexicorp_backend_canonical($db);
			} else {
				$db = strtolower(trim((string) $db));
			}
			$out['default_backend'] = $db;
			$out['default_maps_index'] = null;
			$out['default_maps_detail'] = 'Python CLI bridge (tt_flexicorp_run) not registered in this PHP request — cannot run flexicorp info/freq from the wizard.';
			$out['overview_error'] = 'CLI bridge not loaded — Index(es) tab lists where defaults/flexicorp/backend is stored and which flexicorp helper files exist under the corpus root.';
			return $out;
		}
		if (!function_exists('tt_flexicorp_info_backends')) {
			$out['overview_error'] = 'tt_flexicorp_info_backends() is missing — flexicorp CLI helpers are incomplete in this request.';
			return $out;
		}

		$defaultRaw = '';
		if (function_exists('tt_flexicorp_backend_default')) {
			$defaultRaw = trim((string) tt_flexicorp_backend_default());
		} elseif (function_exists('getset')) {
			$defaultRaw = trim((string) getset('defaults/flexicorp/backend', ''));
		}
		$out['default_backend_raw'] = $defaultRaw;

		$db = $defaultRaw !== '' ? $defaultRaw : 'cqp';
		if (function_exists('tt_flexicorp_backend_canonical')) {
			$db = tt_flexicorp_backend_canonical($db);
		}
		$out['default_backend'] = $db;

		$ovCall = tt_flexicorp_info_backends($projectRoot);
		$overview = tt_fc_wizard_flexicorp_api_result($ovCall);
		if ($overview === null) {
			$err = isset($ovCall['error']) ? trim((string) $ovCall['error']) : '';
			$out['overview_error'] = $err !== '' ? $err : 'flexicorp info backends failed.';
			return $out;
		}
		$out['overview'] = $overview;

		$bs = isset($overview['backendStatus']) && is_array($overview['backendStatus']) ? $overview['backendStatus'] : array();
		if ($db === 'pando' && function_exists('tt_flexicorp_pando_status')) {
			$pst = tt_flexicorp_pando_status($projectRoot);
			$out['default_maps_index'] = !empty($pst['available']);
			$out['default_maps_detail'] = isset($pst['reason']) ? (string) $pst['reason'] : '';
		} elseif (isset($bs[$db]) && is_array($bs[$db])) {
			$row = $bs[$db];
			$out['default_maps_index'] = !empty($row['available']);
			$out['default_maps_detail'] = isset($row['reason']) ? trim((string) $row['reason']) : '';
		} else {
			$out['default_maps_index'] = false;
			$out['default_maps_detail'] = 'Backend "' . $db . '" not listed in flexicorp overview — check flexicorp / TEITOK versions.';
		}

		$combos = isset($overview['backendCombos']) && is_array($overview['backendCombos']) ? $overview['backendCombos'] : array();
		foreach ($combos as $c) {
			if (!is_array($c)) {
				continue;
			}
			$out['combo_rows'][] = array(
				'label' => isset($c['comboLabel']) ? (string) $c['comboLabel'] : '',
				'backend' => isset($c['backend']) ? (string) $c['backend'] : '',
				'available' => !empty($c['available']),
				'reason' => isset($c['reason']) ? (string) $c['reason'] : '',
			);
		}

		@set_time_limit(180);
		$infoCall = tt_flexicorp_run(array('info', 'corpus'), $db, $projectRoot, array(), null, null, null);
		if (empty($infoCall['ok'])) {
			$out['primary_info_error'] = isset($infoCall['error']) ? trim((string) $infoCall['error']) : 'info corpus failed.';
			$raw = isset($infoCall['raw']) ? (string) $infoCall['raw'] : '';
			if ($out['primary_info_error'] === '' && $raw !== '') {
				$out['primary_info_error'] = 'info corpus: no valid JSON (see flexicorp logs).';
			}
		} else {
			$out['primary_info'] = tt_fc_wizard_flexicorp_api_result($infoCall);
		}

		$cwbish = in_array($db, array('cqp', 'flexi', 'pando'), true);
		if (!$cwbish) {
			$out['vocab_note'] = 'Distinct-value counts use CWB-style freq — primary backend is "' . $db . '"; switch to cqp/flexi/pando for CWB lexicon stats here.';

			return $out;
		}

		$freqBackend = $db;
		$maxAttrs = 14;
		$limitFreq = '12000';
		$keysFreq = $out['pattr_keys'];
		if (count($keysFreq) > $maxAttrs) {
			$keysFreq = array_slice($keysFreq, 0, $maxAttrs);
			$out['vocab_note'] = 'Showing first ' . $maxAttrs . ' indexed token attributes (cap).';
		}

		foreach ($keysFreq as $pk) {
			$pk = trim((string) $pk);
			if ($pk === '') {
				continue;
			}
			$extra = array(
				'--field',
				escapeshellarg($pk),
				'--limit',
				$limitFreq,
				'--offset',
				'0',
				'--pattern',
				escapeshellarg('[]'),
			);
			$fq = tt_flexicorp_run(array('freq'), $freqBackend, $projectRoot, $extra, null, null, null);
			$payload = tt_fc_wizard_flexicorp_api_result($fq);
			if ($payload === null || empty($fq['ok'])) {
				$out['vocab_token'][] = array(
					'name' => $pk,
					'types' => '',
					'note' => 'freq failed',
				);
				continue;
			}
			$n = 0;
			if (isset($payload['returned'])) {
				$n = (int) $payload['returned'];
			} elseif (isset($payload['items']) && is_array($payload['items'])) {
				$n = count($payload['items']);
			}
			$suffix = ($n >= (int) $limitFreq) ? ('≥' . $limitFreq) : (string) $n;
			$out['vocab_token'][] = array(
				'name' => $pk,
				'types' => $suffix,
				'note' => 'distinct types (freq group; cap ' . $limitFreq . ')',
			);
		}

		$structNames = array();
		$pi = $out['primary_info'];
		if (is_array($pi) && isset($pi['struct_attributes']) && is_array($pi['struct_attributes'])) {
			$structNames = $pi['struct_attributes'];
		}
		$maxStruct = 8;
		if (count($structNames) > $maxStruct) {
			$structNames = array_slice($structNames, 0, $maxStruct);
			if ($out['vocab_note'] === '') {
				$out['vocab_note'] = 'Showing first ' . $maxStruct . ' registry structural lines (cap).';
			}
		}
		foreach ($structNames as $sn) {
			$sn = trim((string) $sn);
			if ($sn === '') {
				continue;
			}
			$extra = array(
				'--field',
				escapeshellarg($sn),
				'--limit',
				$limitFreq,
				'--offset',
				'0',
				'--pattern',
				escapeshellarg('[]'),
			);
			$fq = tt_flexicorp_run(array('freq'), $freqBackend, $projectRoot, $extra, null, null, null);
			$payload = tt_fc_wizard_flexicorp_api_result($fq);
			if ($payload === null || empty($fq['ok'])) {
				$out['vocab_region'][] = array(
					'name' => $sn,
					'types' => '',
					'note' => 'freq failed or not a groupable field',
				);
				continue;
			}
			$n = 0;
			if (isset($payload['returned'])) {
				$n = (int) $payload['returned'];
			} elseif (isset($payload['items']) && is_array($payload['items'])) {
				$n = count($payload['items']);
			}
			$suffix = ($n >= (int) $limitFreq) ? ('≥' . $limitFreq) : (string) $n;
			$out['vocab_region'][] = array(
				'name' => $sn,
				'types' => $suffix,
				'note' => 'distinct via group match (registry field)',
			);
		}

		return $out;
	}
}

/**
 * Merge missing alignment-related CQP items into settings.xml (only adds; never removes).
 */
if (!function_exists('tt_fc_wizard_apply_alignment_settings')) {
	function tt_fc_wizard_apply_alignment_settings($settingsPath, $need_s_tuid, $need_tok_tuid, $include_text_tuid) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$result['message'] = 'Settings file not found or unreadable.';
			return $result;
		}
		if (!is_writable($settingsPath)) {
			$result['message'] = 'Corpus settings file (Resources/settings.xml) is not writable.';
			return $result;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$result['message'] = 'Empty settings file.';
			return $result;
		}
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$result['message'] = 'No <cqp> element found — cannot merge alignment settings.';
			return $result;
		}

		$before = tt_fc_wizard_parse_cqp_alignment($settingsPath);

		$pattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'pattributes') {
				$pattrs = $ch;
				break;
			}
		}

		if ($need_tok_tuid && !$before['pattr_tuid'] && !$before['pattr_p_tuid']) {
			if (!$pattrs instanceof DOMElement) {
				$pattrs = $dom->createElement('pattributes');
				$cqp->appendChild($pattrs);
				$result['changes'][] = 'Created <pattributes>.';
			}
			$has = false;
			foreach ($pattrs->childNodes as $ch) {
				if (!$ch instanceof DOMElement || $ch->localName !== 'item') continue;
				$pk = strtolower(trim($ch->getAttribute('key')));
				if ($pk === 'tuid' || $pk === 'p_tuid') {
					$has = true;
					break;
				}
			}
			if (!$has) {
				$it = $dom->createElement('item');
				$it->setAttribute('key', 'tuid');
				$it->setAttribute('xpath', '@tuid');
				$pattrs->appendChild($it);
				$result['changes'][] = 'Added p-attribute <item key="tuid" xpath="@tuid"/>.';
			}
		}

		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		$need_sattrs_container = ($need_s_tuid && !$before['sattr_s_tuid']) || ($include_text_tuid && !$before['sattr_text_tuid']);
		if ($need_sattrs_container && !$sattrs instanceof DOMElement) {
			$sattrs = $dom->createElement('sattributes');
			$cqp->appendChild($sattrs);
			$result['changes'][] = 'Created <sattributes>.';
		}

		if ($need_s_tuid && !$before['sattr_s_tuid']) {
			$sentence_region = null;
			foreach ($sattrs->childNodes as $region) {
				if (!$region instanceof DOMElement || $region->localName !== 'item') continue;
				$level = strtolower(trim($region->getAttribute('level')));
				$rkey = strtolower(trim($region->getAttribute('key')));
				if ($level === 's' || $level === 'seg' || $rkey === 's' || $rkey === 'sentence') {
					$sentence_region = $region;
					break;
				}
			}
			if (!$sentence_region instanceof DOMElement) {
				$sentence_region = $dom->createElement('item');
				$sentence_region->setAttribute('key', 'sentence');
				$sentence_region->setAttribute('level', 's');
				$sattrs->appendChild($sentence_region);
				$result['changes'][] = 'Created sentence region <item key="sentence" level="s">.';
			}
			$has_st = false;
			foreach ($sentence_region->childNodes as $sub) {
				if (!$sub instanceof DOMElement || $sub->localName !== 'item') continue;
				if (strtolower(trim($sub->getAttribute('key'))) === 's_tuid') {
					$has_st = true;
					break;
				}
			}
			if (!$has_st) {
				$st = $dom->createElement('item');
				$st->setAttribute('key', 's_tuid');
				$st->setAttribute('xpath', '@tuid');
				$sentence_region->appendChild($st);
				$result['changes'][] = 'Added s-attribute <item key="s_tuid" xpath="@tuid"/> under sentence region.';
			}
		}

		if ($include_text_tuid && !$before['sattr_text_tuid']) {
			$text_region = null;
			foreach ($sattrs->childNodes as $region) {
				if (!$region instanceof DOMElement || $region->localName !== 'item') continue;
				$level = strtolower(trim($region->getAttribute('level')));
				$rkey = strtolower(trim($region->getAttribute('key')));
				if ($level === 'text' || $rkey === 'text') {
					$text_region = $region;
					break;
				}
			}
			if (!$text_region instanceof DOMElement) {
				$text_region = $dom->createElement('item');
				$text_region->setAttribute('key', 'text');
				$text_region->setAttribute('level', 'text');
				$sattrs->appendChild($text_region);
				$result['changes'][] = 'Created text region <item key="text" level="text">.';
			}
			$has_tt = false;
			foreach ($text_region->childNodes as $sub) {
				if (!$sub instanceof DOMElement || $sub->localName !== 'item') continue;
				if (strtolower(trim($sub->getAttribute('key'))) === 'text_tuid') {
					$has_tt = true;
					break;
				}
			}
			if (!$has_tt) {
				$tt = $dom->createElement('item');
				$tt->setAttribute('key', 'text_tuid');
				$tt->setAttribute('xpath', '@text_tuid');
				$text_region->appendChild($tt);
				$result['changes'][] = 'Added text-level <item key="text_tuid" xpath="@text_tuid"/> (parallel text grouping).';
			}
		}

		if (count($result['changes']) === 0) {
			$result['ok'] = true;
			$result['message'] = 'Nothing to write — alignment-related entries are already present (or not requested).';
			return $result;
		}

		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}

		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}

		$result['ok'] = true;
		$result['message'] = 'Updated ' . basename($settingsPath) . '. Backup: ' . $bkr['backup_rel'] . '. Reindex the corpus so CWB/Pando pick up new attributes.';
		return $result;
	}
}

/**
 * Ensure utterance region <code>u</code> is allowed in flexicorp <code>context_scope_regions</code> (settings.xml),
 * aligned with <code>tt_flexicorp_context_scope_region_allowlist_resolved()</code> in flexicorp.php / getset.
 *
 * - Implicit stored value (empty, <code>-</code>, <code>default</code>): writes the built-in explicit list
 *   (<code>s, u, lb, l, p, seg</code>) — same resolution as implicit getset.
 * - Explicit list missing <code>u</code>: appends <code>u</code>.
 *
 * @return array{ok:bool,message:string,changes:list<string>}
 */
if (!function_exists('tt_fc_wizard_apply_merge_flexicorp_allowlist_append_u')) {
	function tt_fc_wizard_apply_merge_flexicorp_allowlist_append_u($settingsPath, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$root = $xp->query('//ttsettings')->item(0);
		if (!$root instanceof DOMElement) {
			$result['message'] = 'No <ttsettings> root — cannot merge flexicorp allowlist.';
			return $result;
		}
		$flex = null;
		foreach ($root->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'flexicorp') {
				$flex = $ch;
				break;
			}
		}
		if (!$flex instanceof DOMElement) {
			$flex = $dom->createElement('flexicorp');
			$root->appendChild($flex);
			$result['changes'][] = 'Created <flexicorp>.';
		}
		$item = null;
		foreach ($flex->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			if (strtolower(trim($ch->getAttribute('key'))) === 'context_scope_regions') {
				$item = $ch;
				break;
			}
		}
		if (!$item instanceof DOMElement) {
			$item = $dom->createElement('item');
			$item->setAttribute('key', 'context_scope_regions');
			$flex->appendChild($item);
			$result['changes'][] = 'Created flexicorp item context_scope_regions.';
		}
		$cur = trim($item->getAttribute('default'));
		if ($cur === '') {
			$cur = trim($item->getAttribute('value'));
		}
		if ($cur === '') {
			$cur = trim($item->textContent);
		}
		$lr = strtolower($cur);
		if ($cur === '' || $cur === '-' || $lr === 'default') {
			$def = tt_fc_wizard_context_scope_builtin_default_allowlist();
			$new = implode(', ', $def);
			$item->setAttribute('default', $new);
			$result['changes'][] = 'Set flexicorp context_scope_regions to explicit built-in default (' . $new . '), matching flexicorp.php implicit resolution.';
		} else {
			$parts = preg_split('/[\s,;]+/', $cur, -1, PREG_SPLIT_NO_EMPTY);
			$seen = array();
			foreach ($parts as $p) {
				$k = tt_fc_wizard_normalize_context_scope_region_key($p);
				if ($k === '') {
					continue;
				}
				$seen[$k] = true;
			}
			if (isset($seen['u'])) {
				$result['ok'] = true;
				$result['message'] = 'Allowlist already includes region u — nothing to write.';
				if (!$commit) {
					$result['preview_xml'] = $raw;
				}
				return $result;
			}
			$parts[] = 'u';
			$new = implode(', ', $parts);
			$item->setAttribute('default', $new);
			$result['changes'][] = 'Set flexicorp context_scope_regions to include u (' . $new . ').';
		}

		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Updated flexicorp search context regions (preview only — not saved).';
			return $result;
		}

		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Updated flexicorp search context regions. Backup: ' . $bkr['backup_rel'] . '. Reload TEITOK / flexicorp may be required.';
		return $result;
	}
}

/**
 * Add or complete TEITOK CQP structural region <code>u</code> with nested timing fields for TEI <code>&lt;u&gt;</code>: child items <code>start</code> / <code>stop</code> with xpaths <code>@start</code> and <code>@stop</code> (relative to indexed utterance nodes). Top-level <code>u_start</code> + <code>u_stop</code> wiring is left unchanged when already present.
 *
 * @return array{ok:bool,message:string,changes:list<string>}
 */
if (!function_exists('tt_fc_wizard_apply_merge_cqp_sattr_u_stub')) {
	function tt_fc_wizard_apply_merge_cqp_sattr_u_stub($settingsPath, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$result['message'] = 'No <cqp> element found — cannot add utterance region.';
			return $result;
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			$sattrs = $dom->createElement('sattributes');
			$cqp->appendChild($sattrs);
			$result['changes'][] = 'Created <sattributes>.';
		}
		$uReg = null;
		$hasStartTop = false;
		$hasStopTop = false;
		foreach ($sattrs->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			$lk = strtolower(trim($ch->getAttribute('key')));
			$lv = strtolower(trim($ch->getAttribute('level')));
			if ($lk === 'u' || $lv === 'u') {
				if (!$uReg instanceof DOMElement) {
					$uReg = $ch;
				}
				continue;
			}
			if ($lk === 'u_start' || $lv === 'u_start') {
				$hasStartTop = true;
			}
			if ($lk === 'u_stop' || $lv === 'u_stop') {
				$hasStopTop = true;
			}
		}
		if ($hasStartTop && $hasStopTop) {
			$result['ok'] = true;
			$result['message'] = 'CQP already declares utterance bounds (u_start + u_stop) — no region u merge needed.';
			if (!$commit) {
				$snap = $dom->saveXML();
				if (is_string($snap) && $snap !== '') {
					$result['preview_xml'] = $snap;
				} else {
					$result['preview_xml'] = $raw;
				}
			}
			return $result;
		}
		$nested_timing_ok = static function (DOMElement $regEl) {
			$hasStart = false;
			$hasEnd = false;
			foreach ($regEl->childNodes as $sub) {
				if (!$sub instanceof DOMElement || $sub->localName !== 'item') {
					continue;
				}
				$ck = strtolower(trim($sub->getAttribute('key')));
				$xpa = trim((string) $sub->getAttribute('xpath'));
				if ($xpa === '') {
					continue;
				}
				if ($ck === 'start') {
					$hasStart = true;
				}
				if ($ck === 'stop' || $ck === 'end') {
					$hasEnd = true;
				}
			}
			return $hasStart && $hasEnd;
		};
		if ($uReg instanceof DOMElement && $nested_timing_ok($uReg)) {
			$result['ok'] = true;
			$result['message'] = 'CQP region u already has nested start/stop timing xpaths — nothing to write.';
			if (!$commit) {
				$snap = $dom->saveXML();
				if (is_string($snap) && $snap !== '') {
					$result['preview_xml'] = $snap;
				} else {
					$result['preview_xml'] = $raw;
				}
			}
			return $result;
		}
		$append_u_timing = static function (DOMDocument $dom, DOMElement $regEl, array &$result) {
			$hasStart = false;
			$hasStop = false;
			foreach ($regEl->childNodes as $sub) {
				if (!$sub instanceof DOMElement || $sub->localName !== 'item') {
					continue;
				}
				$ck = strtolower(trim($sub->getAttribute('key')));
				$xpa = trim((string) $sub->getAttribute('xpath'));
				if ($ck === 'start' && $xpa !== '') {
					$hasStart = true;
				}
				if (($ck === 'stop' || $ck === 'end') && $xpa !== '') {
					$hasStop = true;
				}
			}
			if (!$hasStart) {
				$n = $dom->createElement('item');
				$n->setAttribute('key', 'start');
				$n->setAttribute('xpath', '@start');
				$regEl->appendChild($n);
				$result['changes'][] = 'Added nested &lt;item key="start" xpath="@start"/&gt; under CQP region u (TEI ⟨u⟩ utterance start).';
			}
			if (!$hasStop) {
				$n = $dom->createElement('item');
				$n->setAttribute('key', 'stop');
				$n->setAttribute('xpath', '@stop');
				$regEl->appendChild($n);
				$result['changes'][] = 'Added nested &lt;item key="stop" xpath="@stop"/&gt; under CQP region u (TEI ⟨u⟩ utterance end).';
			}
		};
		if ($uReg instanceof DOMElement) {
			$append_u_timing($dom, $uReg, $result);
		} else {
			$it = $dom->createElement('item');
			$it->setAttribute('key', 'u');
			$it->setAttribute('level', 'u');
			$sattrs->appendChild($it);
			$result['changes'][] = 'Added CQP structural region u for TEI ⟨u⟩.';
			$append_u_timing($dom, $it, $result);
		}
		if (count($result['changes']) === 0) {
			$result['ok'] = true;
			$result['message'] = 'No CQP region u changes needed.';
			if (!$commit) {
				$snap = $dom->saveXML();
				$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
			}
			return $result;
		}

		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged CQP region u with @start / @stop on TEI ⟨u⟩ (preview only — not saved). Edit xpaths only if your TEI uses different timing attributes (e.g. @end instead of @stop).';
			return $result;
		}

		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Merged CQP region u with nested start/stop timing. Backup: ' . $bkr['backup_rel'] . '. Reindex so utterance times appear in the index. Adjust nested xpaths only if your TEI differs.';
		return $result;
	}
}

/**
 * Append UD dependency token p-attributes <code>deprel</code> and <code>head</code> (<code>@deprel</code>, <code>@head</code>) under main &lt;cqp&gt;/&lt;pattributes&gt; when missing.
 *
 * @return array{ok:bool,message:string,changes?:list<string>,preview_xml?:string}
 */
if (!function_exists('tt_fc_wizard_apply_merge_cqp_pattr_deprel_head')) {
	function tt_fc_wizard_apply_merge_cqp_pattr_deprel_head($settingsPath, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$result['message'] = 'No <cqp> element found — cannot add p-attributes.';
			return $result;
		}
		$pattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'pattributes') {
				$pattrs = $ch;
				break;
			}
		}
		if (!$pattrs instanceof DOMElement) {
			$pattrs = $dom->createElement('pattributes');
			$cqp->appendChild($pattrs);
			$result['changes'][] = 'Created <pattributes>.';
		}
		$have = array();
		$existing = array();
		foreach ($pattrs->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			$k = strtolower(trim($ch->getAttribute('key')));
			if ($k !== '') {
				$have[$k] = true;
				$existing[$k] = $ch;
			}
		}
		$add = array(
			'deprel' => array(
				'xpath' => '@deprel',
				'display' => 'Dependency relation',
			),
			'head' => array(
				'xpath' => '@head',
				'nosearch' => '1',
				'noshow' => '1',
			),
		);
		foreach ($add as $attrKey => $attrs) {
			if (!isset($have[$attrKey])) {
				$it = $dom->createElement('item');
				$it->setAttribute('key', $attrKey);
				foreach ($attrs as $an => $av) {
					$it->setAttribute($an, $av);
				}
				$pattrs->appendChild($it);
				$result['changes'][] = 'Added CQP p-attribute <item key="' . $attrKey . '"/> with dependency defaults.';
				continue;
			}
			$it = isset($existing[$attrKey]) && $existing[$attrKey] instanceof DOMElement ? $existing[$attrKey] : null;
			if (!$it instanceof DOMElement) {
				continue;
			}
			foreach ($attrs as $an => $av) {
				if ($it->getAttribute($an) !== $av) {
					$it->setAttribute($an, $av);
					$result['changes'][] = 'Updated CQP p-attribute `' . $attrKey . '` attr `' . $an . '`.';
				}
			}
		}
		if (count($result['changes']) === 0) {
			$result['ok'] = true;
			$result['message'] = 'CQP pattributes already list deprel and head — nothing to write.';
			if (!$commit) {
				$snap = $dom->saveXML();
				$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
			}
			return $result;
		}
		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged CQP p-attributes deprel/head (preview only — not saved).';
			return $result;
		}
		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Added CQP p-attributes for UD deprel/head. Backup: ' . $bkr['backup_rel'] . '. Reindex the corpus so tokens carry dependency arcs.';
		return $result;
	}
}

/**
 * Ensure top-level CQP s-attribute registry exposes structural region <code>s</code> (matches TT fc wizard index check for sentence regions).
 */
if (!function_exists('tt_fc_wizard_apply_merge_cqp_sattr_s_region')) {
	function tt_fc_wizard_apply_merge_cqp_sattr_s_region($settingsPath, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$result['message'] = 'No <cqp> element found — cannot merge sentence region s.';
			return $result;
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			$sattrs = $dom->createElement('sattributes');
			$cqp->appendChild($sattrs);
			$result['changes'][] = 'Created <sattributes>.';
		}
		$has_s_top = false;
		foreach ($sattrs->childNodes as $region) {
			if (!$region instanceof DOMElement || $region->localName !== 'item') {
				continue;
			}
			$rk = strtolower(trim($region->getAttribute('key')));
			$lv = strtolower(trim($region->getAttribute('level')));
			if ($rk === 's' || ($rk === '' && $lv === 's')) {
				$has_s_top = true;
				break;
			}
		}
		if (!$has_s_top) {
			$it = $dom->createElement('item');
			$it->setAttribute('key', 's');
			$it->setAttribute('level', 's');
			$sattrs->appendChild($it);
			$result['changes'][] = 'Added top-level CQP structural region <item key="s" level="s"/> under &lt;sattributes&gt;.';
		}
		if (count($result['changes']) === 0) {
			$result['ok'] = true;
			$result['message'] = 'Sentence region `s` already declared — nothing to write.';
			if (!$commit) {
				$snap = $dom->saveXML();
				$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
			}
			return $result;
		}
		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged CQP sentence region `s` (preview only — not saved).';
			return $result;
		}
		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Added CQP sentence region `s`. Backup: ' . $bkr['backup_rel'] . '. Reindex the corpus.';
		return $result;
	}
}

/**
 * Sentence region + nested <code>s_tuid</code> under TEITOK CQP sattributes (same wiring as alignment wizard merge).
 */
if (!function_exists('tt_fc_wizard_apply_merge_cqp_dep_s_tuid')) {
	function tt_fc_wizard_apply_merge_cqp_dep_s_tuid($settingsPath, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$result['message'] = 'No <cqp> element found — cannot merge s_tuid.';
			return $result;
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			$sattrs = $dom->createElement('sattributes');
			$cqp->appendChild($sattrs);
			$result['changes'][] = 'Created <sattributes>.';
		}
		$sentence_region = null;
		foreach ($sattrs->childNodes as $region) {
			if (!$region instanceof DOMElement || $region->localName !== 'item') {
				continue;
			}
			$level = strtolower(trim($region->getAttribute('level')));
			$rkey = strtolower(trim($region->getAttribute('key')));
			if ($level === 's' || $level === 'seg' || $rkey === 's' || $rkey === 'sentence') {
				$sentence_region = $region;
				break;
			}
		}
		if (!$sentence_region instanceof DOMElement) {
			$sentence_region = $dom->createElement('item');
			$sentence_region->setAttribute('key', 'sentence');
			$sentence_region->setAttribute('level', 's');
			$sattrs->appendChild($sentence_region);
			$result['changes'][] = 'Created sentence region <item key="sentence" level="s">.';
		}
		$has_st = false;
		foreach ($sentence_region->childNodes as $sub) {
			if (!$sub instanceof DOMElement || $sub->localName !== 'item') {
				continue;
			}
			if (strtolower(trim($sub->getAttribute('key'))) === 's_tuid') {
				$has_st = true;
				break;
			}
		}
		if (!$has_st) {
			$st = $dom->createElement('item');
			$st->setAttribute('key', 's_tuid');
			$st->setAttribute('xpath', '@tuid');
			$sentence_region->appendChild($st);
			$result['changes'][] = 'Added nested &lt;item key="s_tuid" xpath="@tuid"/&gt; under sentence region.';
		}
		if (count($result['changes']) === 0) {
			$result['ok'] = true;
			$result['message'] = 'CQP s_tuid already present — nothing to write.';
			if (!$commit) {
				$snap = $dom->saveXML();
				$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
			}
			return $result;
		}
		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged CQP s_tuid (preview only — not saved).';
			return $result;
		}
		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Merged CQP sentence id (s_tuid). Backup: ' . $bkr['backup_rel'] . '. Reindex the corpus.';
		return $result;
	}
}

/**
 * Append token-field entries under <code>xmlfile/pattributes</code> for TEITOK XML editor (add-only).
 *
 * @param list<string> $keys Lower-case TEI attribute keys (e.g. deprel, lemma).
 */
if (!function_exists('tt_fc_wizard_apply_merge_xmlfile_pattr_keys')) {
	function tt_fc_wizard_apply_merge_xmlfile_pattr_keys($settingsPath, $keys, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$keyList = array();
		if (is_array($keys)) {
			foreach ($keys as $k) {
				$k = strtolower(trim((string) $k));
				if ($k !== '') {
					$keyList[$k] = true;
				}
			}
		}
		if (count($keyList) === 0) {
			$result['message'] = 'No xmlfile p-attribute keys to merge.';
			return $result;
		}
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$ttsettings = $xp->query("//ttsettings")->item(0);
		if (!$ttsettings instanceof DOMElement) {
			if ($dom->documentElement instanceof DOMElement && strtolower($dom->documentElement->localName) === 'ttsettings') {
				$ttsettings = $dom->documentElement;
			}
		}
		if (!$ttsettings instanceof DOMElement) {
			$result['message'] = 'No &lt;ttsettings&gt; root — cannot merge xmlfile/pattributes.';
			return $result;
		}
		$xf = null;
		foreach ($ttsettings->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'xmlfile') {
				$xf = $ch;
				break;
			}
		}
		if (!$xf instanceof DOMElement) {
			$xf = $dom->createElement('xmlfile');
			$ttsettings->appendChild($xf);
			$result['changes'][] = 'Created &lt;xmlfile&gt;.';
		}
		$pattrs = null;
		foreach ($xf->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'pattributes') {
				$pattrs = $ch;
				break;
			}
		}
		if (!$pattrs instanceof DOMElement) {
			$pattrs = $dom->createElement('pattributes');
			$xf->appendChild($pattrs);
			$result['changes'][] = 'Created &lt;pattributes&gt; under xmlfile.';
		}
		$have = array();
		foreach ($pattrs->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			$hk = strtolower(trim($ch->getAttribute('key')));
			if ($hk !== '') {
				$have[$hk] = true;
			}
		}
		foreach (array_keys($keyList) as $want) {
			if (isset($have[$want])) {
				continue;
			}
			$it = $dom->createElement('item');
			$it->setAttribute('key', $want);
			$it->setAttribute('xpath', '@' . $want);
			$pattrs->appendChild($it);
			$result['changes'][] = 'Added xmlfile &lt;item key="' . $want . '" xpath="@' . $want . '"/&gt;.';
		}

		/* Keep deprel visible as a token tag where xmlfile/pattributes/tags is used. */
		if (isset($keyList['deprel'])) {
			$tags = null;
			foreach ($pattrs->childNodes as $ch) {
				if ($ch instanceof DOMElement && $ch->localName === 'tags') {
					$tags = $ch;
					break;
				}
			}
			if (!$tags instanceof DOMElement) {
				$tags = $dom->createElement('tags');
				$pattrs->appendChild($tags);
				$result['changes'][] = 'Created &lt;tags&gt; under xmlfile/pattributes.';
			}
			$hasDeprelTag = false;
			foreach ($tags->childNodes as $tg) {
				if (!$tg instanceof DOMElement || $tg->localName !== 'item') {
					continue;
				}
				if (strtolower(trim($tg->getAttribute('key'))) === 'deprel') {
					$hasDeprelTag = true;
					if ($tg->getAttribute('xpath') !== '@deprel') {
						$tg->setAttribute('xpath', '@deprel');
						$result['changes'][] = 'Updated xmlfile/pattributes/tags deprel xpath to @deprel.';
					}
					break;
				}
			}
			if (!$hasDeprelTag) {
				$tg = $dom->createElement('item');
				$tg->setAttribute('key', 'deprel');
				$tg->setAttribute('xpath', '@deprel');
				$tags->appendChild($tg);
				$result['changes'][] = 'Added xmlfile/pattributes/tags deprel entry.';
			}
		}
		if (count($result['changes']) === 0) {
			$result['ok'] = true;
			$result['message'] = 'xmlfile/pattributes already list requested keys — nothing to write.';
			if (!$commit) {
				$snap = $dom->saveXML();
				$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
			}
			return $result;
		}
		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged xmlfile pattributes (preview only — not saved).';
			return $result;
		}
		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Updated xmlfile/pattributes. Backup: ' . $bkr['backup_rel'] . '.';
		return $result;
	}
}

/**
 * Append indexed token p-attributes for UD morphology keys present in TEI but missing from CQP list.
 *
 * @param list<string> $keys Lower-case keys (subset of upos, xpos, lemma, feats).
 */
if (!function_exists('tt_fc_wizard_apply_merge_cqp_pattr_morph_keys')) {
	function tt_fc_wizard_apply_merge_cqp_pattr_morph_keys($settingsPath, $keys, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$keyList = array();
		if (is_array($keys)) {
			foreach ($keys as $k) {
				$k = strtolower(trim((string) $k));
				if ($k !== '') {
					$keyList[$k] = true;
				}
			}
		}
		if (count($keyList) === 0) {
			$result['message'] = 'No morphology p-attribute keys to merge.';
			return $result;
		}
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$result['message'] = 'No <cqp> element found — cannot add pattributes.';
			return $result;
		}
		$pattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'pattributes') {
				$pattrs = $ch;
				break;
			}
		}
		if (!$pattrs instanceof DOMElement) {
			$pattrs = $dom->createElement('pattributes');
			$cqp->appendChild($pattrs);
			$result['changes'][] = 'Created &lt;pattributes&gt;.';
		}
		$have = array();
		foreach ($pattrs->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			$hk = strtolower(trim($ch->getAttribute('key')));
			if ($hk !== '') {
				$have[$hk] = true;
			}
		}
		foreach (array_keys($keyList) as $want) {
			if (isset($have[$want])) {
				continue;
			}
			$it = $dom->createElement('item');
			$it->setAttribute('key', $want);
			$it->setAttribute('xpath', '@' . $want);
			$pattrs->appendChild($it);
			$result['changes'][] = 'Added CQP &lt;item key="' . $want . '" xpath="@' . $want . '"/&gt;.';
		}
		if (count($result['changes']) === 0) {
			$result['ok'] = true;
			$result['message'] = 'CQP pattributes already list requested morphology keys — nothing to write.';
			if (!$commit) {
				$snap = $dom->saveXML();
				$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
			}
			return $result;
		}
		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged CQP morphology p-attributes (preview only — not saved).';
			return $result;
		}
		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Added CQP morphology p-attributes. Backup: ' . $bkr['backup_rel'] . '. Reindex the corpus.';
		return $result;
	}
}

/**
 * TEITOK <code>cqp.php</code> native playback column: structural region <code>u</code> with <code>audio</code> plus nested <code>&lt;item key="audio" xpath="…"/&gt;</code> (<code>match u_audio</code>).
 *
 * @param string $mediaXpath XPath to TEI media URL (e.g. <code>//recordingStmt/media/@url</code>).
 * @return array{ok:bool,message:string,changes:list<string>}
 */
if (!function_exists('tt_fc_wizard_apply_merge_cqp_region_u_audio')) {
	function tt_fc_wizard_apply_merge_cqp_region_u_audio($settingsPath, $mediaXpath, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$xpathVal = trim((string) $mediaXpath);
		if ($xpathVal === '') {
			$result['message'] = 'Empty media xpath.';
			return $result;
		}
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$result['message'] = 'No <cqp> element found — cannot merge u_audio.';
			return $result;
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			$sattrs = $dom->createElement('sattributes');
			$cqp->appendChild($sattrs);
			$result['changes'][] = 'Created &lt;sattributes&gt;.';
		}
		$regEl = null;
		foreach ($sattrs->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			$lk = strtolower(trim($ch->getAttribute('key')));
			$lv = strtolower(trim($ch->getAttribute('level')));
			if ($lk === 'u' || $lv === 'u') {
				$regEl = $ch;
				break;
			}
		}
		if (!$regEl instanceof DOMElement) {
			$regEl = $dom->createElement('item');
			$regEl->setAttribute('key', 'u');
			$regEl->setAttribute('level', 'u');
			$sattrs->appendChild($regEl);
			$result['changes'][] = 'Created CQP structural item for region u.';
		}
		$hadAudioAttr = trim((string) $regEl->getAttribute('audio')) !== '';
		if (!$hadAudioAttr) {
			$regEl->setAttribute('audio', '1');
			$result['changes'][] = 'Set audio flag on region u (TEITOK u_audio column).';
		}
		$audioChild = null;
		foreach ($regEl->childNodes as $sub) {
			if (!$sub instanceof DOMElement || $sub->localName !== 'item') {
				continue;
			}
			if (strtolower(trim($sub->getAttribute('key'))) === 'audio') {
				$audioChild = $sub;
				break;
			}
		}
		if (!$audioChild instanceof DOMElement) {
			$audioChild = $dom->createElement('item');
			$audioChild->setAttribute('key', 'audio');
			$audioChild->setAttribute('xpath', $xpathVal);
			$regEl->appendChild($audioChild);
			$result['changes'][] = 'Added nested &lt;item key="audio"&gt; with xpath for indexed media URL.';
		} else {
			$cur = trim((string) $audioChild->getAttribute('xpath'));
			if ($cur === $xpathVal) {
				if (!$hadAudioAttr) {
					$regEl->setAttribute('audio', '1');
					$result['changes'][] = 'Set audio flag on region u (TEITOK u_audio column).';
				} else {
					$result['ok'] = true;
					$result['message'] = 'Region u already has audio flag and xpath — nothing to write.';
					if (!$commit) {
						$snap = $dom->saveXML();
						$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
					}
					return $result;
				}
			} else {
				$audioChild->setAttribute('xpath', $xpathVal);
				$result['changes'][] = 'Updated nested audio xpath under region u.';
			}
		}

		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged CQP u_audio wiring (preview only — not saved). Complete or adjust xpaths under TEITOK CQP if needed.';
			return $result;
		}

		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Merged CQP u_audio wiring. Backup: ' . $bkr['backup_rel'] . '. Complete or adjust xpaths under TEITOK CQP if needed.';
		return $result;
	}
}

/**
 * Set TEITOK xmlfile speech highlight default (getset path <code>xmlfile/speech/highlight</code>).
 *
 * @param string $highlightValue Uppercase token (e.g. TOK, U) written as the item default.
 * @return array{ok:bool,message:string,changes:list<string>}
 */
if (!function_exists('tt_fc_wizard_apply_merge_xmlfile_speech_highlight')) {
	function tt_fc_wizard_apply_merge_xmlfile_speech_highlight($settingsPath, $highlightValue, $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$highlightNorm = strtoupper(trim((string) $highlightValue));
		if ($highlightNorm === '') {
			$result['message'] = 'Empty speech highlight value.';
			return $result;
		}
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$root = $xp->query('//ttsettings')->item(0);
		if (!$root instanceof DOMElement) {
			$result['message'] = 'No <ttsettings> root — cannot merge xmlfile speech highlight.';
			return $result;
		}
		$xf = null;
		foreach ($root->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'xmlfile') {
				$xf = $ch;
				break;
			}
		}
		if (!$xf instanceof DOMElement) {
			$xf = $dom->createElement('xmlfile');
			$root->appendChild($xf);
			$result['changes'][] = 'Created &lt;xmlfile&gt;.';
		}
		$speech = null;
		foreach ($xf->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'speech') {
				$speech = $ch;
				break;
			}
		}
		if (!$speech instanceof DOMElement) {
			$speech = $dom->createElement('speech');
			$xf->appendChild($speech);
			$result['changes'][] = 'Created &lt;speech&gt; under &lt;xmlfile&gt;.';
		}
		$item = null;
		foreach ($speech->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			if (strtolower(trim($ch->getAttribute('key'))) === 'highlight') {
				$item = $ch;
				break;
			}
		}
		if (!$item instanceof DOMElement) {
			$item = $dom->createElement('item');
			$item->setAttribute('key', 'highlight');
			$speech->appendChild($item);
			$result['changes'][] = 'Created &lt;item key="highlight"&gt; under speech.';
		}
		$cur = trim($item->getAttribute('default'));
		if ($cur === '') {
			$cur = trim($item->getAttribute('value'));
		}
		if (strtoupper(trim($cur)) === $highlightNorm) {
			$result['ok'] = true;
			$result['message'] = 'xmlfile/speech/highlight already set to ' . $highlightNorm . ' — nothing to write.';
			if (!$commit) {
				$snap = $dom->saveXML();
				$result['preview_xml'] = (is_string($snap) && $snap !== '') ? $snap : $raw;
			}
			return $result;
		}
		$item->setAttribute('default', $highlightNorm);
		$result['changes'][] = 'Set xmlfile/speech/highlight default to ' . $highlightNorm . '.';

		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Updated xmlfile/speech/highlight (preview only — not saved). Reload TEITOK if settings are cached.';
			return $result;
		}

		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Updated xmlfile/speech/highlight. Backup: ' . $bkr['backup_rel'] . '. Reload TEITOK if settings are cached.';
		return $result;
	}
}

/**
 * Add TEITOK &lt;files&gt;&lt;item key="audio" folder="Audio" display="audio" extension="*.wav,*.mp3"/&gt; under &lt;ttsettings&gt; when missing; optionally create an on-disk Audio/ folder beside the corpus root.
 *
 * @param string $projectRoot TEITOK corpus root (directory containing index.php), or '' to skip mkdir
 * @return array{ok:bool,message:string,changes:list<string>}
 */
if (!function_exists('tt_fc_wizard_apply_merge_files_audio_slot')) {
	function tt_fc_wizard_apply_merge_files_audio_slot($settingsPath, $projectRoot = '', $opts = null) {
		$result = array(
			'ok' => false,
			'message' => '',
			'changes' => array(),
		);
		$rr = tt_fc_wizard_merge_resolve_raw($settingsPath, $opts);
		if (!$rr['ok']) {
			$result['message'] = $rr['error'];
			return $result;
		}
		$raw = $rr['raw'];
		$commit = $rr['commit'];
		$probe = tt_fc_wizard_parse_settings_files_audio_upload($settingsPath, $raw);
		if (empty($probe['ok'])) {
			$result['message'] = 'Cannot read files/audio settings: ' . (isset($probe['error']) ? (string) $probe['error'] : 'unknown error');
			return $result;
		}
		if (!empty($probe['configured'])) {
			$result['ok'] = true;
			$result['message'] = 'files/audio item already present — nothing to write.';
			if (!$commit) {
				$result['preview_xml'] = $raw;
			}
			return $result;
		}
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$result['message'] = 'Invalid XML in settings file.';
			return $result;
		}
		$xp = new DOMXPath($dom);
		$root = $xp->query('//ttsettings')->item(0);
		if (!$root instanceof DOMElement) {
			$result['message'] = 'No <ttsettings> root — cannot merge files/audio.';
			return $result;
		}
		$filesEl = null;
		foreach ($root->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'files') {
				$filesEl = $ch;
				break;
			}
		}
		if (!$filesEl instanceof DOMElement) {
			$filesEl = $dom->createElement('files');
			$root->appendChild($filesEl);
			$result['changes'][] = 'Created &lt;files&gt; under &lt;ttsettings&gt;.';
		}
		$item = $dom->createElement('item');
		$item->setAttribute('key', 'audio');
		$item->setAttribute('folder', 'Audio');
		$item->setAttribute('display', 'audio');
		$item->setAttribute('extension', '*.wav,*.mp3');
		$filesEl->appendChild($item);
		$result['changes'][] = 'Added &lt;files&gt; audio upload item (folder=Audio, extension *.wav / *.mp3).';

		$rootNorm = is_string($projectRoot) ? rtrim((string) $projectRoot, '/\\') : '';
		if ($commit && $rootNorm !== '') {
			$audioDir = $rootNorm . DIRECTORY_SEPARATOR . 'Audio';
			if (!is_dir($audioDir)) {
				$parent = dirname($audioDir);
				if (is_dir($parent) && is_writable($parent)) {
					if (@mkdir($audioDir, 0775)) {
						$result['changes'][] = 'Created on-disk folder Audio/.';
					} else {
						$result['changes'][] = 'Could not create Audio/ on disk (permissions?) — create it manually beside index.php.';
					}
				} else {
					$result['changes'][] = 'Corpus root not writable — create Audio/ manually if you use relative TEI media paths.';
				}
			}
		} elseif (!$commit && $rootNorm !== '') {
			$result['changes'][] = 'Preview: on-disk Audio/ folder would be created on save when missing.';
		}

		$xml_out = $dom->saveXML();
		if (!is_string($xml_out) || $xml_out === '') {
			$result['message'] = 'Failed to serialize XML.';
			return $result;
		}
		if (!$commit) {
			$result['ok'] = true;
			$result['preview_xml'] = $xml_out;
			$result['message'] = 'Merged files/audio upload slot (preview only — not saved). Reload TEITOK if settings are cached.';
			return $result;
		}

		$bkr = tt_fc_wizard_backup_settings_xml_daily($settingsPath);
		if (!$bkr['ok']) {
			$result['message'] = $bkr['error'];
			return $result;
		}
		if (@file_put_contents($settingsPath, $xml_out) === false) {
			$result['message'] = 'Failed to write settings file.';
			return $result;
		}
		$result['ok'] = true;
		$result['message'] = 'Merged files/audio upload slot. Backup: ' . $bkr['backup_rel'] . '. Reload TEITOK if settings are cached.';
		return $result;
	}
}

if (!function_exists('tt_fc_wizard_collect_xml_files')) {
	function tt_fc_wizard_collect_xml_files($xmlDir, $limit = 12) {
		$out = array();
		if (!is_dir($xmlDir)) return $out;
		$max = max(1, (int) $limit);
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($xmlDir, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($it as $f) {
				if (count($out) >= $max) break;
				$path = (string) $f->getPathname();
				if (!is_file($path)) continue;
				if (!preg_match('/\.xml$/i', $path)) continue;
				$out[] = $path;
			}
		} catch (Exception $e) {
			return $out;
		}
		sort($out, SORT_STRING);
		return $out;
	}
}

if (!function_exists('tt_fc_wizard_xpath_count')) {
	function tt_fc_wizard_xpath_count($xp, $query) {
		$nodes = @$xp->query($query);
		return ($nodes instanceof DOMNodeList) ? (int) $nodes->length : 0;
	}
}

/**
 * Whether a TEI media/@url value is treated as remote (no local existence check).
 */
if (!function_exists('tt_fc_wizard_media_href_is_remote')) {
	function tt_fc_wizard_media_href_is_remote($href) {
		$h = trim((string) $href);
		if ($h === '') {
			return false;
		}
		return (bool) preg_match('#^(https?://|ftp://|//|file:|mailto:)#i', $h);
	}
}

/**
 * Candidate filesystem paths for a relative media href (corpus root, Audio/, beside XML).
 *
 * @return list<string>
 */
if (!function_exists('tt_fc_wizard_media_resolve_target_candidates')) {
	function tt_fc_wizard_media_resolve_target_candidates($href, $xmlPath, $projectRoot) {
		$hrefRaw = str_replace('\\', '/', trim((string) $href));
		if ($hrefRaw === '') {
			return array();
		}
		$hrefPath = $hrefRaw;
		if (($p = strpos($hrefPath, '?')) !== false) {
			$hrefPath = substr($hrefPath, 0, $p);
		}
		if (($p = strpos($hrefPath, '#')) !== false) {
			$hrefPath = substr($hrefPath, 0, $p);
		}
		if (tt_fc_wizard_media_href_is_remote($hrefPath)) {
			return array();
		}
		// Absolute filesystem path (unusual in TEI but handle).
		if ($hrefPath !== '' && $hrefPath[0] === '/') {
			return array($hrefPath);
		}
		if (preg_match('#^[a-zA-Z]:[/\\\\]#', $hrefPath)) {
			return array(str_replace('\\', '/', $hrefPath));
		}

		$hrefPath = ltrim($hrefPath, '/');
		$root = $projectRoot !== '' ? rtrim(str_replace('\\', '/', (string) $projectRoot), '/') : '';
		$xmlDir = $xmlPath !== '' ? rtrim(str_replace('\\', '/', dirname((string) $xmlPath)), '/') : '';

		$candidates = array();
		if ($root !== '') {
			$candidates[] = $root . '/' . $hrefPath;
			if (stripos($hrefPath, 'audio/') !== 0) {
				$candidates[] = $root . '/Audio/' . $hrefPath;
			}
		}
		if ($xmlDir !== '') {
			$candidates[] = $xmlDir . '/' . $hrefPath;
			if (stripos($hrefPath, 'audio/') !== 0) {
				$candidates[] = $xmlDir . '/../Audio/' . $hrefPath;
			}
		}
		$out = array();
		$seen = array();
		foreach ($candidates as $c) {
			$c = preg_replace('#/+#', '/', $c);
			$nk = strtolower($c);
			if (isset($seen[$nk])) {
				continue;
			}
			$seen[$nk] = true;
			$out[] = $c;
		}
		return $out;
	}
}

if (!function_exists('tt_fc_wizard_collect_attr_name_hits')) {
	function tt_fc_wizard_collect_attr_name_hits($xp, $query, $maxNodes = 300) {
		$out = array();
		$nodes = @$xp->query($query);
		if (!($nodes instanceof DOMNodeList)) return $out;
		$n = min((int) $nodes->length, max(1, (int) $maxNodes));
		for ($i = 0; $i < $n; $i++) {
			$node = $nodes->item($i);
			if (!($node instanceof DOMElement) || !$node->hasAttributes()) continue;
			foreach ($node->attributes as $attr) {
				if (!($attr instanceof DOMAttr)) continue;
				$name = trim((string) $attr->nodeName);
				if ($name === '') continue;
				$out[$name] = true;
			}
		}
		return array_keys($out);
	}
}

if (!function_exists('tt_fc_wizard_collect_element_name_hits')) {
	function tt_fc_wizard_collect_element_name_hits($xp, $query, $maxNodes = 300) {
		$out = array();
		$nodes = @$xp->query($query);
		if (!($nodes instanceof DOMNodeList)) return $out;
		$n = min((int) $nodes->length, max(1, (int) $maxNodes));
		for ($i = 0; $i < $n; $i++) {
			$node = $nodes->item($i);
			if (!($node instanceof DOMElement)) continue;
			$name = trim((string) $node->localName);
			if ($name === '') continue;
			$out[$name] = true;
		}
		return array_keys($out);
	}
}

if (!function_exists('tt_fc_wizard_count_buckets_add')) {
	function tt_fc_wizard_count_buckets_add(&$bucket, $keys) {
		if (!is_array($bucket)) $bucket = array();
		if (!is_array($keys)) return;
		$seen = array();
		foreach ($keys as $k) {
			$key = trim((string) $k);
			if ($key === '' || isset($seen[$key])) continue;
			$seen[$key] = true;
			if (!isset($bucket[$key])) $bucket[$key] = 0;
			$bucket[$key]++;
		}
	}
}

/**
 * TEI-local XPath fingerprint for a media URL attribute (element names only, {@link …} style).
 *
 * Example: <code>//recordingStmt/media/@url</code> (element names as in the TEI file).
 */
if (!function_exists('tt_fc_wizard_media_url_attr_xpath_string')) {
	function tt_fc_wizard_media_url_attr_xpath_string($attr) {
		if (!($attr instanceof DOMAttr)) {
			return '';
		}
		$name = trim((string) $attr->name);
		if ($name === '') {
			return '';
		}
		$el = $attr->ownerElement;
		if (!$el instanceof DOMElement) {
			return '';
		}
		$parts = array();
		while ($el instanceof DOMElement) {
			$ln = trim((string) $el->localName);
			if ($ln !== '') {
				$parts[] = $ln;
			}
			$p = $el->parentNode;
			if (!$p instanceof DOMElement) {
				break;
			}
			$el = $p;
		}
		if (count($parts) === 0) {
			return '';
		}
		$parts = array_reverse($parts);
		return '//' . implode('/', $parts) . '/@' . $name;
	}
}

/**
 * XPath on nested <code>&lt;item key="audio" …/&gt;</code> under CQP structural region <code>u</code> (canonical settings.xml).
 *
 * @return string non-empty when wired
 */
if (!function_exists('tt_fc_wizard_cqp_region_nested_audio_xpath')) {
	function tt_fc_wizard_cqp_region_nested_audio_xpath($settingsPath, $region = 'u') {
		$region = strtolower(trim((string) $region));
		if ($region === '') {
			return '';
		}
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			return '';
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			return '';
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			return '';
		}
		$xp = new DOMXPath($dom);
		$cqp = null;
		$nodes = $xp->query("//ttsettings/cqp[@corpus != '']");
		if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
			$cqp = $nodes->item(0);
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//cqp[@corpus != '']");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			$nodes = $xp->query("//ttsettings/cqp");
			if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
				$cqp = $nodes->item(0);
			}
		}
		if (!$cqp instanceof DOMElement) {
			return '';
		}
		$sattrs = null;
		foreach ($cqp->childNodes as $ch) {
			if ($ch instanceof DOMElement && $ch->localName === 'sattributes') {
				$sattrs = $ch;
				break;
			}
		}
		if (!$sattrs instanceof DOMElement) {
			return '';
		}
		$regEl = null;
		foreach ($sattrs->childNodes as $ch) {
			if (!$ch instanceof DOMElement || $ch->localName !== 'item') {
				continue;
			}
			$rk = strtolower(trim($ch->getAttribute('key')));
			$rl = strtolower(trim($ch->getAttribute('level')));
			$reg = ($rk !== '') ? $rk : $rl;
			if ($reg === $region) {
				$regEl = $ch;
				break;
			}
		}
		if (!$regEl instanceof DOMElement) {
			return '';
		}
		foreach ($regEl->childNodes as $sub) {
			if (!$sub instanceof DOMElement || $sub->localName !== 'item') {
				continue;
			}
			$ck = strtolower(trim($sub->getAttribute('key')));
			if ($ck !== 'audio') {
				continue;
			}
			$xw = trim((string) $sub->getAttribute('xpath'));
			if ($xw !== '') {
				return $xw;
			}
			$hw = trim((string) $sub->getAttribute('href'));
			if ($hw !== '') {
				return $hw;
			}
		}
		return '';
	}
}

if (!function_exists('tt_fc_wizard_analyze_file')) {
	function tt_fc_wizard_analyze_file($path, $projectRoot = '') {
		$raw = @file_get_contents($path);
		if (!is_string($raw) || trim($raw) === '') {
			return array('ok' => false, 'error' => 'Empty or unreadable XML file');
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$ok = @$dom->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		if (!$ok) {
			return array('ok' => false, 'error' => 'Invalid XML');
		}

		$xp = new DOMXPath($dom);
		$uCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='u']");
		$mediaUrlCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='media']/@url");
		$mediaInHeaderCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='teiHeader']//*[local-name()='media']");
		// Utterance span: @start plus either @stop (common in TEITOK) or @end (common TEI-style).
		$uStartStopCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='u'][@start][@stop or @end]");
		// Token-level timeline on encoded words (alternative/complement to ⟨u⟩ timing).
		$tokWStartStopCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='tok' or local-name()='w'][@start][@stop or @end]");
		$facsAttrCount = tt_fc_wizard_xpath_count($xp, "//*[@facs]");
		$pbFacsCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='pb'][@facs]");
		$sameAsCount = tt_fc_wizard_xpath_count($xp, "//*[@sameAs]");
		$correspCount = tt_fc_wizard_xpath_count($xp, "//*[@corresp]");
		$tuidCount = tt_fc_wizard_xpath_count($xp, "//*[@tuid]");
		$sTuidCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='s'][@tuid]");
		$tokW_TuidCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='tok' or local-name()='w'][@tuid]");
		$geoElemCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='geo']");
		$geoAttrCount = tt_fc_wizard_xpath_count($xp, "//*[@lat or @lon or @latitude or @longitude]");
		$placeRefCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='placeName'][@ref]");
		$sentCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='s']");
		$textTextTuidCount = tt_fc_wizard_xpath_count($xp, "//*[local-name()='text'][@text_tuid]");
		$tokenAttrNames = tt_fc_wizard_collect_attr_name_hits($xp, "//*[local-name()='tok' or local-name()='w']");
		$headerAttrNames = tt_fc_wizard_collect_attr_name_hits($xp, "//*[local-name()='teiHeader']//*[local-name()!='tok' and local-name()!='w']");
		$headerElementNames = tt_fc_wizard_collect_element_name_hits($xp, "//*[local-name()='teiHeader']//*");
		$regionElementNames = tt_fc_wizard_collect_element_name_hits($xp, "//*[local-name()='text']//*[local-name()='s' or local-name()='u' or local-name()='p' or local-name()='div' or local-name()='seg' or local-name()='head']");

		$mediaRemoteRefs = 0;
		$mediaLocalChecked = 0;
		$mediaLocalFound = 0;
		$mediaLocalMissing = 0;
		$mediaMissingHints = array();
		$mediaUrlPatterns = array();
		$urlNodes = @$xp->query("//*[local-name()='media']/@url");
		if ($urlNodes instanceof DOMNodeList && $urlNodes->length > 0) {
			for ($mi = 0; $mi < (int) $urlNodes->length; $mi++) {
				$attrPat = $urlNodes->item($mi);
				if (!($attrPat instanceof DOMAttr)) {
					continue;
				}
				$xps = tt_fc_wizard_media_url_attr_xpath_string($attrPat);
				if ($xps !== '') {
					$mediaUrlPatterns[$xps] = true;
				}
			}
			$maxUrls = min(80, (int) $urlNodes->length);
			for ($mi = 0; $mi < $maxUrls; $mi++) {
				$attrNode = $urlNodes->item($mi);
				if (!($attrNode instanceof DOMAttr)) {
					continue;
				}
				$href = trim((string) $attrNode->value);
				if ($href === '') {
					continue;
				}
				if (tt_fc_wizard_media_href_is_remote($href)) {
					$mediaRemoteRefs++;
					continue;
				}
				$mediaLocalChecked++;
				$hit = false;
				foreach (tt_fc_wizard_media_resolve_target_candidates($href, $path, $projectRoot) as $tryPath) {
					if ($tryPath !== '' && is_file($tryPath)) {
						$hit = true;
						break;
					}
				}
				if ($hit) {
					$mediaLocalFound++;
				} else {
					$mediaLocalMissing++;
					if (count($mediaMissingHints) < 4) {
						$bn = basename(str_replace('\\', '/', $href));
						if ($bn !== '') {
							$mediaMissingHints[] = $bn;
						}
					}
				}
			}
		}

		return array(
			'ok' => true,
			'uCount' => $uCount,
			'mediaUrlCount' => $mediaUrlCount,
			'mediaInHeaderCount' => $mediaInHeaderCount,
			'mediaRemoteRefs' => $mediaRemoteRefs,
			'mediaLocalChecked' => $mediaLocalChecked,
			'mediaLocalFound' => $mediaLocalFound,
			'mediaLocalMissing' => $mediaLocalMissing,
			'mediaMissingHints' => $mediaMissingHints,
			'uStartStopCount' => $uStartStopCount,
			'tokWStartStopCount' => $tokWStartStopCount,
			'facsAttrCount' => $facsAttrCount,
			'pbFacsCount' => $pbFacsCount,
			'sameAsCount' => $sameAsCount,
			'correspCount' => $correspCount,
			'tuidCount' => $tuidCount,
			'sTuidCount' => $sTuidCount,
			'tokW_TuidCount' => $tokW_TuidCount,
			'geoElemCount' => $geoElemCount,
			'geoAttrCount' => $geoAttrCount,
			'placeRefCount' => $placeRefCount,
			'sentCount' => $sentCount,
			'textTextTuidCount' => $textTextTuidCount,
			'tokenAttrNames' => $tokenAttrNames,
			'headerAttrNames' => $headerAttrNames,
			'headerElementNames' => $headerElementNames,
			'regionElementNames' => $regionElementNames,
			'media_url_patterns' => array_keys($mediaUrlPatterns),
		);
	}
}

if (!function_exists('tt_fc_wizard_profile_from_sample')) {
	function tt_fc_wizard_profile_from_sample($paths, $projectRoot = '') {
		$totals = array(
			'scanned' => 0,
			'errors' => 0,
			'spoken_docs' => 0,
			'media_docs' => 0,
			'facs_docs' => 0,
			'aligned_docs' => 0,
			'tuid_docs' => 0,
			's_tuid_docs' => 0,
			'tok_tuid_docs' => 0,
			'text_tuid_docs' => 0,
			'geo_docs' => 0,
			'media_header_docs' => 0,
			'u_timing_docs' => 0,
			'tok_timing_docs' => 0,
			'media_fs_checked' => 0,
			'media_fs_found' => 0,
			'media_fs_missing' => 0,
			'media_remote_refs' => 0,
			'media_url_pattern_count' => 0,
			'media_single_url_pattern' => '',
		);
		$rows = array();
		$tokenAttrHits = array();
		$headerAttrHits = array();
		$headerElemHits = array();
		$regionElemHits = array();
		$mediaPatAccum = array();

		foreach ($paths as $path) {
			$totals['scanned']++;
			$r = tt_fc_wizard_analyze_file($path, $projectRoot);
			$row = array(
				'file' => $path,
				'ok' => !empty($r['ok']),
				'error' => isset($r['error']) ? (string) $r['error'] : '',
				'u' => (int) (isset($r['uCount']) ? $r['uCount'] : 0),
				'media' => (int) (isset($r['mediaUrlCount']) ? $r['mediaUrlCount'] : 0),
				'media_hdr' => (int) (isset($r['mediaInHeaderCount']) ? $r['mediaInHeaderCount'] : 0),
				'media_remote' => (int) (isset($r['mediaRemoteRefs']) ? $r['mediaRemoteRefs'] : 0),
				'media_fs_checked' => (int) (isset($r['mediaLocalChecked']) ? $r['mediaLocalChecked'] : 0),
				'media_fs_found' => (int) (isset($r['mediaLocalFound']) ? $r['mediaLocalFound'] : 0),
				'media_fs_missing' => (int) (isset($r['mediaLocalMissing']) ? $r['mediaLocalMissing'] : 0),
				'u_tim' => (int) (isset($r['uStartStopCount']) ? $r['uStartStopCount'] : 0),
				'tok_tim' => (int) (isset($r['tokWStartStopCount']) ? $r['tokWStartStopCount'] : 0),
				'facs' => (int) ((isset($r['facsAttrCount']) ? $r['facsAttrCount'] : 0) + (isset($r['pbFacsCount']) ? $r['pbFacsCount'] : 0)),
				'st_uid' => (int) (isset($r['sTuidCount']) ? $r['sTuidCount'] : 0),
				'tok_tuid' => (int) (isset($r['tokW_TuidCount']) ? $r['tokW_TuidCount'] : 0),
				'tuid_any' => (int) (isset($r['tuidCount']) ? $r['tuidCount'] : 0),
				'text_text_tuid' => (int) (isset($r['textTextTuidCount']) ? $r['textTextTuidCount'] : 0),
				'alignment' => (int) ((isset($r['sameAsCount']) ? $r['sameAsCount'] : 0) + (isset($r['correspCount']) ? $r['correspCount'] : 0)),
				'geo' => (int) ((isset($r['geoElemCount']) ? $r['geoElemCount'] : 0) + (isset($r['geoAttrCount']) ? $r['geoAttrCount'] : 0) + (isset($r['placeRefCount']) ? $r['placeRefCount'] : 0)),
				's' => (int) (isset($r['sentCount']) ? $r['sentCount'] : 0),
			);
			if (!$row['ok']) {
				$totals['errors']++;
				$rows[] = $row;
				continue;
			}
			$totals['media_fs_checked'] += $row['media_fs_checked'];
			$totals['media_fs_found'] += $row['media_fs_found'];
			$totals['media_fs_missing'] += $row['media_fs_missing'];
			$totals['media_remote_refs'] += $row['media_remote'];
			if ($row['u'] > 0) $totals['spoken_docs']++;
			if ($row['media'] > 0) $totals['media_docs']++;
			if (!empty($row['media_hdr']) && (int) $row['media_hdr'] > 0) {
				$totals['media_header_docs']++;
			}
			if (!empty($row['u_tim']) && (int) $row['u_tim'] > 0) {
				$totals['u_timing_docs']++;
			}
			if (!empty($row['tok_tim']) && (int) $row['tok_tim'] > 0) {
				$totals['tok_timing_docs']++;
			}
			if ($row['facs'] > 0) $totals['facs_docs']++;
			if ($row['st_uid'] > 0) {
				$totals['s_tuid_docs']++;
			}
			if ($row['tok_tuid'] > 0) {
				$totals['tok_tuid_docs']++;
			}
			if (!empty($row['text_text_tuid']) && (int) $row['text_text_tuid'] > 0) {
				$totals['text_tuid_docs']++;
			}
			if ($row['st_uid'] > 0 || $row['tok_tuid'] > 0) {
				$totals['tuid_docs']++;
			}
			if ($row['alignment'] > 0 || $row['st_uid'] > 0 || $row['tok_tuid'] > 0) {
				$totals['aligned_docs']++;
			}
			if ($row['geo'] > 0) $totals['geo_docs']++;
			if (!empty($r['media_url_patterns']) && is_array($r['media_url_patterns'])) {
				foreach ($r['media_url_patterns'] as $_mpx) {
					$_mpx = trim((string) $_mpx);
					if ($_mpx !== '') {
						$mediaPatAccum[$_mpx] = true;
					}
				}
			}
			tt_fc_wizard_count_buckets_add($tokenAttrHits, isset($r['tokenAttrNames']) ? $r['tokenAttrNames'] : array());
			tt_fc_wizard_count_buckets_add($headerAttrHits, isset($r['headerAttrNames']) ? $r['headerAttrNames'] : array());
			tt_fc_wizard_count_buckets_add($headerElemHits, isset($r['headerElementNames']) ? $r['headerElementNames'] : array());
			tt_fc_wizard_count_buckets_add($regionElemHits, isset($r['regionElementNames']) ? $r['regionElementNames'] : array());
			$rows[] = $row;
		}

		arsort($tokenAttrHits);
		arsort($headerAttrHits);
		arsort($headerElemHits);
		arsort($regionElemHits);
		$patList = array_keys($mediaPatAccum);
		$totals['media_url_pattern_count'] = count($patList);
		if (count($patList) === 1) {
			$totals['media_single_url_pattern'] = $patList[0];
		}
		return array(
			'totals' => $totals,
			'rows' => $rows,
			'tokenAttrHits' => $tokenAttrHits,
			'headerAttrHits' => $headerAttrHits,
			'headerElemHits' => $headerElemHits,
			'regionElemHits' => $regionElemHits,
		);
	}
}

/**
 * Token-attribute keys TEITOK may expose in the XML editor (<code>xmlfile/pattributes</code>) — merged/corpus <code>Resources/settings.xml</code>.
 *
 * @return array{ok:bool,error:string,keys:list<string>}
 */
if (!function_exists('tt_fc_wizard_parse_xmlfile_pattribute_keys')) {
	function tt_fc_wizard_parse_xmlfile_pattribute_keys($settingsPath) {
		$out = array(
			'ok' => false,
			'error' => '',
			'keys' => array(),
		);
		$keysFromGetset = array();
		if (function_exists('getset')) {
			$g = getset('xmlfile/pattributes', array());
			if (is_array($g)) {
				foreach (tt_fc_wizard_flatten_string_keys_recursive($g) as $gk) {
					$gk = strtolower(trim((string) $gk));
					if ($gk !== '') {
						$keysFromGetset[] = $gk;
					}
				}
				foreach ($g as $el) {
					if (is_array($el) && isset($el['key'])) {
						$kk = strtolower(trim((string) $el['key']));
						if ($kk !== '') {
							$keysFromGetset[] = $kk;
						}
					}
				}
			}
		}
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			$out['keys'] = array_values(array_unique($keysFromGetset));
			$out['ok'] = count($out['keys']) > 0 || function_exists('getset');
			if (!$out['ok']) {
				$out['error'] = 'Settings file not found or unreadable';
			}
			return $out;
		}
		$raw = @file_get_contents($settingsPath);
		if (!is_string($raw) || trim($raw) === '') {
			$out['keys'] = array_values(array_unique($keysFromGetset));
			$out['ok'] = count($out['keys']) > 0;
			if (!$out['ok']) {
				$out['error'] = 'Empty settings file';
			}
			return $out;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($raw, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			$out['keys'] = array_values(array_unique($keysFromGetset));
			$out['ok'] = count($out['keys']) > 0;
			$out['error'] = $out['ok'] ? '' : 'Invalid XML in settings file';
			return $out;
		}
		$xp = new DOMXPath($dom);
		$nodes = $xp->query("//ttsettings/xmlfile//pattributes/item[@key]|//xmlfile//pattributes/item[@key]");
		if ($nodes instanceof DOMNodeList) {
			for ($i = 0; $i < (int) $nodes->length; $i++) {
				$n = $nodes->item($i);
				if (!$n instanceof DOMElement) {
					continue;
				}
				$key = strtolower(trim($n->getAttribute('key')));
				if ($key !== '') {
					$out['keys'][] = $key;
				}
			}
		}
		$out['keys'] = array_values(array_unique(array_merge($out['keys'], $keysFromGetset)));
		sort($out['keys']);
		$out['ok'] = true;
		return $out;
	}
}

/**
 * @param array<string,int> $regionElemHits
 * @param array<string,string> $cqpDefaultsResolved
 * @param array{ok?:bool,keys?:list<string>} $cqpSattrParse
 * @return list<array{id:string,section:string,label:string,xml:string,project:string,status:string,summary:string,detail:string,info_anchor:string,remedy_url:string,remedy_label:string,probe_soft:bool,settings_fix:bool}>
 */
if (!function_exists('tt_fc_wizard_dependencies_unified_rows')) {
	function tt_fc_wizard_dependencies_unified_rows(
		$tokenAttrHits,
		$totals,
		$canonicalSettingsPath,
		$mergedCqpPath,
		$mergedCqpReadable,
		$regionElemHits,
		$cqpDefaultsResolved,
		$cqpSattrParse
	) {
		$scanned = isset($totals['scanned']) ? (int) $totals['scanned'] : 0;
		$hasDeprelXml = tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, 'deprel');
		$hasHeadXml = tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, 'head');
		$bothTok = ($hasDeprelXml && $hasHeadXml);
		$depIntent = ($hasDeprelXml || $hasHeadXml);

		$settingsPathForCqp = ($mergedCqpReadable && is_string($mergedCqpPath) && $mergedCqpPath !== '')
			? $mergedCqpPath
			: $canonicalSettingsPath;
		$cqp = tt_fc_wizard_parse_cqp_pattribute_keys($settingsPathForCqp);
		$pkeys = (isset($cqp['keys']) && is_array($cqp['keys'])) ? $cqp['keys'] : array();
		$pflip = array();
		foreach ($pkeys as $pk) {
			$pflip[strtolower(trim((string) $pk))] = true;
		}
		$hasDeprelCqp = isset($pflip['deprel']);
		$hasHeadCqp = isset($pflip['head']);
		$cqpErr = isset($cqp['error']) ? trim((string) $cqp['error']) : '';

		$xfPat = tt_fc_wizard_parse_xmlfile_pattribute_keys($canonicalSettingsPath);
		$xfKeys = (isset($xfPat['keys']) && is_array($xfPat['keys'])) ? $xfPat['keys'] : array();
		$xfflip = array();
		foreach ($xfKeys as $xk) {
			$xfflip[strtolower(trim((string) $xk))] = true;
		}
		$hasDeprelXf = isset($xfflip['deprel']);
		$hasHeadXf = isset($xfflip['head']);

		$sattrKeys = (isset($cqpSattrParse['keys']) && is_array($cqpSattrParse['keys'])) ? $cqpSattrParse['keys'] : array();
		$sflip = array();
		foreach ($sattrKeys as $sk) {
			$sflip[strtolower(trim((string) $sk))] = true;
		}
		$hasSIndexed = isset($sflip['s']);

		$regionElemHits = is_array($regionElemHits) ? $regionElemHits : array();
		$hitS = !empty($regionElemHits['s']);
		$sTuidDocs = isset($totals['s_tuid_docs']) ? (int) $totals['s_tuid_docs'] : 0;

		$cqpAlign = ($settingsPathForCqp !== '') ? tt_fc_wizard_parse_cqp_alignment($settingsPathForCqp) : array('ok' => false, 'sattr_s_tuid' => false);

		$stDef = isset($cqpDefaultsResolved['searchtype']) ? trim((string) $cqpDefaultsResolved['searchtype']) : '';
		$subDef = isset($cqpDefaultsResolved['subtype']) ? trim((string) $cqpDefaultsResolved['subtype']) : '';
		$stLo = strtolower($stDef);
		$subLo = strtolower($subDef);

		$rows = array();
		$emptyRem = array('remedy_url' => '', 'remedy_label' => '', 'probe_soft' => false, 'settings_fix' => false);

		// --- UD token arcs (deprel / head) ---
		if ($scanned <= 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-tok-xml',
				'section' => 'UD token arcs (deprel / head)',
				'label' => '⟨tok⟩ in TEI sample',
				'xml' => 'No files in this scan.',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'No TEI sampled — raise sample size or add XML under the corpus folder.',
				'detail' => '',
				'info_anchor' => 'dependencies-deprel',
			), array_merge($emptyRem, array('probe_soft' => true)));
		} elseif ($bothTok) {
			$rows[] = array_merge(array(
				'id' => 'dep-tok-xml',
				'section' => 'UD token arcs (deprel / head)',
				'label' => '⟨tok⟩ in TEI sample',
				'xml' => 'Sample has both @deprel and @head on ⟨tok⟩/⟨w⟩.',
				'project' => '—',
				'status' => 'ok',
				'summary' => 'Dependency arcs are present in TEI (UD-style).',
				'detail' => '',
				'info_anchor' => 'dependencies-deprel',
			), $emptyRem);
		} elseif ($hasDeprelXml xor $hasHeadXml) {
			$rows[] = array_merge(array(
				'id' => 'dep-tok-xml',
				'section' => 'UD token arcs (deprel / head)',
				'label' => '⟨tok⟩ in TEI sample',
				'xml' => $hasDeprelXml ? 'deprel without head' : 'head without deprel',
				'project' => '—',
				'status' => 'wrong',
				'summary' => 'UD trees need both governor links and labels on tokens.',
				'detail' => $hasDeprelXml
					? 'Add @head on tokens (governor index) for each dependent.'
					: 'Add @deprel (UD relation label) on each token.',
				'info_anchor' => 'dependencies-deprel',
			), $emptyRem);
		} else {
			$rows[] = array_merge(array(
				'id' => 'dep-tok-xml',
				'section' => 'UD token arcs (deprel / head)',
				'label' => '⟨tok⟩ in TEI sample',
				'xml' => 'No deprel/head on ⟨tok⟩ in this scan.',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'No UD-style dependency markup indicated in the sample.',
				'detail' => '',
				'info_anchor' => 'dependencies-deprel',
			), array_merge($emptyRem, array('probe_soft' => true)));
		}

		if (empty($cqp['ok'])) {
			$rows[] = array_merge(array(
				'id' => 'dep-cqp-pattr',
				'section' => 'UD token arcs (deprel / head)',
				'label' => 'CQP p-attributes (corpus index)',
				'xml' => '—',
				'project' => 'Cannot read cqp/pattributes: ' . ($cqpErr !== '' ? $cqpErr : 'unknown'),
				'status' => 'wrong',
				'summary' => 'Fix CQP settings so deprel/head can be indexed.',
				'detail' => 'Uses merged tmp/cqpsettings.xml when present; otherwise canonical Resources/settings.xml.',
				'info_anchor' => 'dependencies-deprel',
			), $emptyRem);
		} elseif (!$bothTok) {
			/* Sample may miss both attrs on ⟨tok⟩ while UD exists elsewhere or TEI is incomplete (xor/partial). */
			if ($hasDeprelCqp && $hasHeadCqp) {
				$rows[] = array_merge(array(
					'id' => 'dep-cqp-pattr',
					'section' => 'UD token arcs (deprel / head)',
					'label' => 'CQP p-attributes (corpus index)',
					'xml' => '—',
					'project' => 'pattributes list includes deprel and head.',
					'status' => 'ok',
					'summary' => 'Correctly configured.',
					'detail' => '',
					'info_anchor' => 'dependencies-deprel',
				), $emptyRem);
			} elseif ($depIntent && (!$hasDeprelCqp || !$hasHeadCqp)) {
				$missNt = array();
				if (!$hasDeprelCqp) {
					$missNt[] = 'deprel';
				}
				if (!$hasHeadCqp) {
					$missNt[] = 'head';
				}
				$rows[] = array_merge(array(
					'id' => 'dep-cqp-pattr',
					'section' => 'UD token arcs (deprel / head)',
					'label' => 'CQP p-attributes (corpus index)',
					'xml' => '—',
					'project' => 'Missing: ' . implode(', ', $missNt),
					'status' => 'wrong',
					'summary' => 'Index mapping must list deprel and head under cqp/pattributes, then reindex.',
					'detail' => $bothTok ? '' : 'This scan did not show both @deprel and @head on ⟨tok⟩/⟨w⟩ — if your TEI is fully UD elsewhere, raise sample size; use Wizard fix to add default entries to Resources/settings.xml.',
					'info_anchor' => 'dependencies-deprel',
				), array_merge($emptyRem, array('settings_fix' => true)));
			} else {
				$missPre = array();
				if (!$hasDeprelCqp) {
					$missPre[] = 'deprel';
				}
				if (!$hasHeadCqp) {
					$missPre[] = 'head';
				}
				$rows[] = array_merge(array(
					'id' => 'dep-cqp-pattr',
					'section' => 'UD token arcs (deprel / head)',
					'label' => 'CQP p-attributes (corpus index)',
					'xml' => '—',
					'project' => count($missPre) ? ('Missing: ' . implode(', ', $missPre)) : '—',
					'status' => 'optional',
					'summary' => 'Preparing the corpus: no deprel/head in this sample yet — you can still add default CQP index entries now (same idea as preparing spoken/audio wiring before transcripts land).',
					'detail' => 'Use Wizard fix to append standard token mappings (@deprel / @head); reindex after you add UD in TEI so hits match.',
					'info_anchor' => 'dependencies-deprel',
				), array_merge($emptyRem, array('settings_fix' => true, 'probe_soft' => true)));
			}
		} elseif ($hasDeprelCqp && $hasHeadCqp) {
			$rows[] = array_merge(array(
				'id' => 'dep-cqp-pattr',
				'section' => 'UD token arcs (deprel / head)',
				'label' => 'CQP p-attributes (corpus index)',
				'xml' => '—',
				'project' => 'pattributes list includes deprel and head.',
				'status' => 'ok',
				'summary' => 'Correctly configured.',
				'detail' => '',
				'info_anchor' => 'dependencies-deprel',
			), $emptyRem);
		} else {
			$miss = array();
			if (!$hasDeprelCqp) {
				$miss[] = 'deprel';
			}
			if (!$hasHeadCqp) {
				$miss[] = 'head';
			}
			$rows[] = array_merge(array(
				'id' => 'dep-cqp-pattr',
				'section' => 'UD token arcs (deprel / head)',
				'label' => 'CQP p-attributes (corpus index)',
				'xml' => '—',
				'project' => 'Missing: ' . implode(', ', $miss),
				'status' => 'wrong',
				'summary' => 'TEI has deprel/head but CQP pattributes do not list both keys.',
				'detail' => 'Add xpath items under cqp/pattributes, then reindex.',
				'info_anchor' => 'dependencies-deprel',
			), array_merge($emptyRem, array('settings_fix' => true)));
		}

		if (empty($xfPat['ok']) && count($xfKeys) === 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-xmlfile-pattr',
				'section' => 'UD token arcs (deprel / head)',
				'label' => 'XML editor (xmlfile/pattributes)',
				'xml' => '—',
				'project' => ($xfPat['error'] !== '' ? $xfPat['error'] : 'Could not read xmlfile/pattributes'),
				'status' => 'optional',
				'summary' => 'Optional: expose deprel/head in TEITOK’s token editor when you hand-edit UD.',
				'detail' => 'Declare keys under xmlfile/pattributes in corpus settings so the XML editor matches your TEI.',
				'info_anchor' => 'dependencies-deprel',
			), array_merge($emptyRem, array('probe_soft' => true)));
		} elseif (!$bothTok) {
			if (!$hasDeprelXf || !$hasHeadXf) {
				$mxf = array();
				if (!$hasDeprelXf) {
					$mxf[] = 'deprel';
				}
				if (!$hasHeadXf) {
					$mxf[] = 'head';
				}
				$rows[] = array_merge(array(
					'id' => 'dep-xmlfile-pattr',
					'section' => 'UD token arcs (deprel / head)',
					'label' => 'XML editor (xmlfile/pattributes)',
					'xml' => '—',
					'project' => 'Missing xmlfile keys: ' . implode(', ', $mxf),
					'status' => 'optional',
					'summary' => 'Optional editor prep: no UD token attrs in this sample — you can still declare deprel/head for the XML editor before TEI encodes them.',
					'detail' => 'Wizard fix adds xmlfile/pattributes entries matching the usual @deprel / @head token attributes.',
					'info_anchor' => 'dependencies-deprel',
				), array_merge($emptyRem, array('settings_fix' => true, 'probe_soft' => true)));
			} else {
				$rows[] = array_merge(array(
					'id' => 'dep-xmlfile-pattr',
					'section' => 'UD token arcs (deprel / head)',
					'label' => 'XML editor (xmlfile/pattributes)',
					'xml' => '—',
					'project' => count($xfKeys) ? implode(', ', $xfKeys) : '(none parsed)',
					'status' => 'optional',
					'summary' => 'xmlfile already lists deprel and head — sample simply did not show UD on tokens.',
					'detail' => '',
					'info_anchor' => 'dependencies-deprel',
				), array_merge($emptyRem, array('probe_soft' => true)));
			}
		} elseif ($hasDeprelXf && $hasHeadXf) {
			$rows[] = array_merge(array(
				'id' => 'dep-xmlfile-pattr',
				'section' => 'UD token arcs (deprel / head)',
				'label' => 'XML editor (xmlfile/pattributes)',
				'xml' => '—',
				'project' => 'xmlfile lists deprel and head.',
				'status' => 'ok',
				'summary' => 'Hand-editing paths align with UD arcs.',
				'detail' => '',
				'info_anchor' => 'dependencies-deprel',
			), $emptyRem);
		} else {
			$mx = array();
			if (!$hasDeprelXf) {
				$mx[] = 'deprel';
			}
			if (!$hasHeadXf) {
				$mx[] = 'head';
			}
			$rows[] = array_merge(array(
				'id' => 'dep-xmlfile-pattr',
				'section' => 'UD token arcs (deprel / head)',
				'label' => 'XML editor (xmlfile/pattributes)',
				'xml' => '—',
				'project' => 'Missing xmlfile keys: ' . implode(', ', $mx),
				'status' => 'optional',
				'summary' => 'Optional: add missing keys to xmlfile/pattributes for parity with TEI + index.',
				'detail' => 'Indexed corpora still search deprel/head; this row only affects the native XML editor field list.',
				'info_anchor' => 'dependencies-deprel',
			), array_merge($emptyRem, array(
				'probe_soft' => true,
				'settings_fix' => true,
			)));
		}

		// --- Sentence ⟨s⟩ & sentence ids ---
		if ($scanned <= 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-s-xml',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => '⟨s⟩ in TEI sample',
				'xml' => '—',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'No sample files.',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), array_merge($emptyRem, array('probe_soft' => true)));
		} elseif ($hitS) {
			$rows[] = array_merge(array(
				'id' => 'dep-s-xml',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => '⟨s⟩ in TEI sample',
				'xml' => '⟨s⟩ elements appear under ⟨text⟩ in the scan.',
				'project' => '—',
				'status' => 'ok',
				'summary' => 'Sentence regions are present for indexing and tree context.',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), $emptyRem);
		} else {
			$rows[] = array_merge(array(
				'id' => 'dep-s-xml',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => '⟨s⟩ in TEI sample',
				'xml' => 'No ⟨s⟩ in this scan.',
				'project' => '—',
				'status' => $depIntent ? 'wrong' : 'optional',
				'summary' => $depIntent
					? 'UD trees usually need sentence-sized regions in TEI.'
					: 'No sentence markers in sample.',
				'detail' => $depIntent ? 'Add ⟨s⟩ (or align TEITOK region labels with your segmentation) so CQP can align sentences.' : '',
				'info_anchor' => 'dependencies-s',
			), array_merge($emptyRem, array('probe_soft' => !$depIntent)));
		}

		if (!$hasSIndexed) {
			$rows[] = array_merge(array(
				'id' => 'dep-s-cqp',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => 'CQP ⟨s⟩ region (corpus index)',
				'xml' => '—',
				'project' => 'No top-level s-attribute key `s` in CQP sattributes list.',
				'status' => $hitS ? 'wrong' : 'optional',
				'summary' => $hitS
					? 'TEI has ⟨s⟩ but the indexed corpus does not expose region `s` — fix CQP sattributes and reindex.'
					: 'When you add ⟨s⟩ in TEI, declare region `s` under cqp/sattributes.',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), array_merge($emptyRem, array(
				'settings_fix' => $hitS ? true : false,
				'probe_soft' => !$hitS,
			)));
		} else {
			$rows[] = array_merge(array(
				'id' => 'dep-s-cqp',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => 'CQP ⟨s⟩ region (corpus index)',
				'xml' => '—',
				'project' => 'Region `s` is declared for indexing.',
				'status' => 'ok',
				'summary' => 'Sentence structural region is wired in CQP.',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), $emptyRem);
		}

		if ($scanned <= 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-s-tuid-xml',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => 'Sentence id on ⟨s⟩ (@tuid)',
				'xml' => '—',
				'project' => '—',
				'status' => 'optional',
				'summary' => '—',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), array_merge($emptyRem, array('probe_soft' => true)));
		} elseif ($sTuidDocs > 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-s-tuid-xml',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => 'Sentence id on ⟨s⟩ (@tuid)',
				'xml' => $sTuidDocs . ' file(s) with ⟨s @tuid⟩ in sample.',
				'project' => '—',
				'status' => 'ok',
				'summary' => 'Sentence-level ids are present for alignment / hit anchoring.',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), $emptyRem);
		} else {
			$rows[] = array_merge(array(
				'id' => 'dep-s-tuid-xml',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => 'Sentence id on ⟨s⟩ (@tuid)',
				'xml' => 'No ⟨s @tuid⟩ in this scan.',
				'project' => '—',
				'status' => 'optional',
				'summary' => $hitS
					? 'Optional but recommended: stable sentence ids (e.g. @tuid) for parallel lookup and tree hooks.'
					: 'No sentence id attributes detected.',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), array_merge($emptyRem, array('probe_soft' => true)));
		}

		$sTuidCqp = !empty($cqpAlign['ok']) && !empty($cqpAlign['sattr_s_tuid']);
		if (!$sTuidCqp) {
			$rows[] = array_merge(array(
				'id' => 'dep-s-tuid-cqp',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => 'CQP sentence id (s_tuid)',
				'xml' => '—',
				'project' => 'No nested s_tuid (or tuid) field under the sentence region in CQP sattributes.',
				'status' => ($sTuidDocs > 0 || $hitS) ? 'wrong' : 'optional',
				'summary' => 'Index sentence ids so search/concordancers can target the same sentence as XML.',
				'detail' => 'Declare s_tuid xpath under the sentence region in cqp/sattributes (then reindex).',
				'info_anchor' => 'dependencies-s',
			), array_merge($emptyRem, array(
				'settings_fix' => true,
				'probe_soft' => !(($sTuidDocs > 0 || $hitS)),
			)));
		} else {
			$sr = isset($cqpAlign['sattr_sentence_regions']) && is_array($cqpAlign['sattr_sentence_regions'])
				? implode(', ', $cqpAlign['sattr_sentence_regions'])
				: 's';
			$rows[] = array_merge(array(
				'id' => 'dep-s-tuid-cqp',
				'section' => 'Sentence ⟨s⟩ & ids',
				'label' => 'CQP sentence id (s_tuid)',
				'xml' => '—',
				'project' => 's_tuid wired for region(s): ' . $sr,
				'status' => 'ok',
				'summary' => 'Indexed corpus carries sentence-level ids alongside TEI.',
				'detail' => '',
				'info_anchor' => 'dependencies-s',
			), $emptyRem);
		}

		// --- Search context & dependency trees ---
		$probeRows = tt_fc_wizard_cqp_search_defaults_probe($regionElemHits, $cqpDefaultsResolved, $cqpSattrParse, $totals);
		$probeHard = array();
		foreach ($probeRows as $pr) {
			if (!is_array($pr) || !empty($pr['ok'])) {
				continue;
			}
			$sev = isset($pr['severity']) ? (string) $pr['severity'] : '';
			if ($sev === 'hard') {
				$probeHard[] = isset($pr['detail']) ? (string) $pr['detail'] : '';
			}
		}
		$sentenceContextOk = ($stLo === 'context' && $subLo === 's')
			|| ($stLo === 'kwic' || $stLo === '')
			|| ($stLo === 'context' && $subLo === '' && !$depIntent);
		$probeDetail = count($probeHard) ? implode(' ', array_slice(array_filter($probeHard), 0, 2)) : '';

		if ($sentenceContextOk && count($probeHard) === 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-search-defaults',
				'section' => 'Search hits & dependency trees',
				'label' => 'TEITOK default hit shape',
				'xml' => '—',
				'project' => 'searchtype=' . ($stDef !== '' ? $stDef : '—') . ' · subtype=' . ($subDef !== '' ? $subDef : '—'),
				'status' => 'ok',
				'summary' => 'Defaults suit dependency viewing (sentence via ⟨s⟩ or KWIC token windows). Operators can still widen scope in flexicorp.',
				'detail' => 'Native TEITOK / deptree-style views typically expect sentence- or token-scoped snippets; oral corpora may override defaults to ⟨u⟩.',
				'info_anchor' => 'dependencies-search',
			), $emptyRem);
		} elseif (count($probeHard) > 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-search-defaults',
				'section' => 'Search hits & dependency trees',
				'label' => 'TEITOK default hit shape',
				'xml' => '—',
				'project' => 'searchtype=' . ($stDef !== '' ? $stDef : '—') . ' · subtype=' . ($subDef !== '' ? $subDef : '—'),
				'status' => 'wrong',
				'summary' => 'CQP defaults conflict with indexed regions or mixed ⟨s⟩/⟨u⟩ — fix before relying on hit trees.',
				'detail' => $probeDetail,
				'info_anchor' => 'dependencies-search',
			), $emptyRem);
		} else {
			$rows[] = array_merge(array(
				'id' => 'dep-search-defaults',
				'section' => 'Search hits & dependency trees',
				'label' => 'TEITOK default hit shape',
				'xml' => '—',
				'project' => 'searchtype=' . ($stDef !== '' ? $stDef : '—') . ' · subtype=' . ($subDef !== '' ? $subDef : '—'),
				'status' => 'optional',
				'summary' => 'Consider context + subtype=s for sentence-sized snippets, or KWIC for narrow windows — see Help.',
				'detail' => 'Goal: ⟨s⟩-sized (or token KWIC) hit context for dependency trees; spoken corpora often need subtype=u instead.',
				'info_anchor' => 'dependencies-search',
			), array_merge($emptyRem, array('probe_soft' => true)));
		}

		// --- Optional UD morphology ---
		$morph = array('upos', 'xpos', 'lemma', 'feats');
		$missXml = array();
		foreach ($morph as $m) {
			if (tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, $m)) {
				$haveXml[] = $m;
			} else {
				$missXml[] = $m;
			}
		}
		$missCqp = array();
		foreach ($morph as $m) {
			if (!isset($pflip[$m])) {
				$missCqp[] = $m;
			}
		}
		$morphFixKeys = array();
		foreach ($morph as $m) {
			if (!isset($pflip[$m]) && ($depIntent ? tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, $m) : true)) {
				$morphFixKeys[] = $m;
			}
		}
		$missXf = array();
		foreach ($morph as $m) {
			if (!isset($xfflip[$m])) {
				$missXf[] = $m;
			}
		}
		if (!$depIntent) {
			$rows[] = array_merge(array(
				'id' => 'dep-morph',
				'section' => 'Optional UD morphology',
				'label' => 'upos · xpos · lemma · feats',
				'xml' => '—',
				'project' => count($missCqp) ? ('Index missing: ' . implode(', ', $missCqp)) : 'CQP pattributes already list all four.',
				'status' => count($missCqp) ? 'optional' : 'ok',
				'summary' => count($missCqp)
					? 'No dependency markup in sample yet — you can still pre-add UD morphology keys now.'
					: 'Morphology defaults are already present; no action needed until UD markup lands in TEI.',
				'detail' => '',
				'info_anchor' => 'dependencies-morph',
			), array_merge($emptyRem, array(
				'probe_soft' => true,
				'settings_fix' => count($morphFixKeys) > 0,
			)));
		} elseif (count($missXml) === 0 && count($missCqp) === 0) {
			$rows[] = array_merge(array(
				'id' => 'dep-morph',
				'section' => 'Optional UD morphology',
				'label' => 'upos · xpos · lemma · feats',
				'xml' => 'Sample TEI includes all four attrs on tokens.',
				'project' => 'CQP pattributes lists all four.',
				'status' => count($missXf) === 0 ? 'ok' : 'optional',
				'summary' => count($missXf) === 0
					? 'Full UD-style morphology is indexed and exposed for editing.'
					: 'Indexed + TEI OK; xmlfile may omit: ' . implode(', ', $missXf) . ' (editor-only).',
				'detail' => 'xmlfile/pattributes: ' . (count($xfKeys) ? implode(', ', $xfKeys) : '—'),
				'info_anchor' => 'dependencies-morph',
			), array_merge($emptyRem, array('probe_soft' => count($missXf) > 0)));
		} else {
			$rows[] = array_merge(array(
				'id' => 'dep-morph',
				'section' => 'Optional UD morphology',
				'label' => 'upos · xpos · lemma · feats',
				'xml' => 'TEI missing: ' . (count($missXml) ? implode(', ', $missXml) : '—'),
				'project' => 'Index missing: ' . (count($missCqp) ? implode(', ', $missCqp) : '—'),
				'status' => 'optional',
				'summary' => 'Typical UD corpora include these on ⟨tok⟩ and in pattributes for search/freq.',
				'detail' => 'xmlfile optional keys missing: ' . (count($missXf) ? implode(', ', $missXf) : 'none'),
				'info_anchor' => 'dependencies-morph',
			), array_merge($emptyRem, array(
				'probe_soft' => true,
				'settings_fix' => count($morphFixKeys) > 0,
			)));
		}

		return $rows;
	}
}

if (!function_exists('tt_fc_wizard_dependencies_row_section')) {
	function tt_fc_wizard_dependencies_row_section($row) {
		if (is_array($row) && isset($row['section']) && trim((string) $row['section']) !== '') {
			return (string) $row['section'];
		}
		return 'Dependencies';
	}
}

/**
 * Geolocation / maps checklist rows (TEI sample, indexed structural fields, geomap wiring).
 *
 * @param array<string,mixed> $totals Profile totals (expects scanned, geo_docs).
 * @param array<string,mixed> $indexesData Output of {@link tt_fc_wizard_indexes_collect} (uses sattr_keys).
 * @param array<string,mixed> $geomapParse Output of {@link tt_fc_wizard_parse_geomap_block}.
 * @return list<array<string,mixed>>
 */
if (!function_exists('tt_fc_wizard_geo_unified_rows')) {
	function tt_fc_wizard_geo_unified_rows($totals, $indexesData, $geomapParse) {
		$rows = array();
		$emptyRem = array('remedy_url' => '', 'remedy_label' => '', 'probe_soft' => false, 'settings_fix' => false);
		$scanned = isset($totals['scanned']) ? (int) $totals['scanned'] : 0;
		$geoDocs = isset($totals['geo_docs']) ? (int) $totals['geo_docs'] : 0;
		$geoSample = $geoDocs > 0;

		$sattrKeys = (isset($indexesData['sattr_keys']) && is_array($indexesData['sattr_keys'])) ? $indexesData['sattr_keys'] : array();
		$sflip = array();
		foreach ($sattrKeys as $sk) {
			$skl = strtolower(trim((string) $sk));
			if ($skl !== '') {
				$sflip[$skl] = true;
			}
		}
		$hasTextGeo = isset($sflip['text_geo']);
		$hasTextPlace = isset($sflip['text_place']);

		$gp = is_array($geomapParse) ? $geomapParse : array();
		$gmOk = !empty($gp['ok']);
		$gmPresent = !empty($gp['present']);
		$gmStart = isset($gp['startpos']) ? trim((string) $gp['startpos']) : '';
		$gmZoom = isset($gp['zoom']) ? (int) $gp['zoom'] : 0;
		$gmOsml = isset($gp['osmlayer']) ? trim((string) $gp['osmlayer']) : '';
		$gmMark = isset($gp['markertype']) ? trim((string) $gp['markertype']) : '';
		$cqPlace = isset($gp['cqp_place']) ? trim((string) $gp['cqp_place']) : '';
		$cqGeo = isset($gp['cqp_geo']) ? trim((string) $gp['cqp_geo']) : '';
		$cqTitle = isset($gp['cqp_title']) ? trim((string) $gp['cqp_title']) : '';
		$xn = isset($gp['xml_node']) ? trim((string) $gp['xml_node']) : '';
		$xg = isset($gp['xml_geo']) ? trim((string) $gp['xml_geo']) : '';
		$xname = isset($gp['xml_name']) ? trim((string) $gp['xml_name']) : '';
		$xdesc = isset($gp['xml_desc']) ? trim((string) $gp['xml_desc']) : '';
		$areasTotal = isset($gp['areas_total']) ? (int) $gp['areas_total'] : 0;
		$areasBad = isset($gp['areas_incomplete']) ? (int) $gp['areas_incomplete'] : 0;
		$regionsTotal = isset($gp['regions_total']) ? (int) $gp['regions_total'] : 0;

		// --- TEI sample ---
		if ($scanned <= 0) {
			$rows[] = array_merge(array(
				'id' => 'geo-tei',
				'section' => 'TEI sample (places & coordinates)',
				'label' => 'Geo / place signals in XML',
				'xml' => '—',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'No XML in this scan — rescan or add files under the corpus TEI folder.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => true)));
		} elseif ($geoSample) {
			$rows[] = array_merge(array(
				'id' => 'geo-tei',
				'section' => 'TEI sample (places & coordinates)',
				'label' => 'Geo / place signals in XML',
				'xml' => (string) $geoDocs . ' file(s) with geo / place markers in sample.',
				'project' => '—',
				'status' => 'ok',
				'summary' => 'Sample shows encoding that can back map points or place refs.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), $emptyRem);
		} else {
			$rows[] = array_merge(array(
				'id' => 'geo-tei',
				'section' => 'TEI sample (places & coordinates)',
				'label' => 'Geo / place signals in XML',
				'xml' => 'No geo / place markers in this scan.',
				'project' => '—',
				'status' => 'optional',
				'summary' => 'Maps still work if indexed structural fields carry coordinates; raise sample size if places exist only in some files.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => true)));
		}

		// --- Indexed corpus (structural s-attributes) ---
		if (!$gmOk) {
			$rows[] = array_merge(array(
				'id' => 'geo-text-geo',
				'section' => 'Indexed corpus (CQP / registry)',
				'label' => 'Structural field text_geo',
				'xml' => '—',
				'project' => isset($gp['error']) ? (string) $gp['error'] : 'Could not read settings for s-attribute list.',
				'status' => 'wrong',
				'summary' => 'Cannot verify text_geo — fix settings readability / XML.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), $emptyRem);
		} elseif ($hasTextGeo) {
			$rows[] = array_merge(array(
				'id' => 'geo-text-geo',
				'section' => 'Indexed corpus (CQP / registry)',
				'label' => 'Structural field text_geo',
				'xml' => '—',
				'project' => 'text_geo appears in CQP s-attribute registry list.',
				'status' => 'ok',
				'summary' => 'Point maps can aggregate lat/lon strings from this field (advanced maps / flexicorp).',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), $emptyRem);
		} else {
			$rows[] = array_merge(array(
				'id' => 'geo-text-geo',
				'section' => 'Indexed corpus (CQP / registry)',
				'label' => 'Structural field text_geo',
				'xml' => '—',
				'project' => 'No top-level s-attribute key text_geo in merged CQP sattributes list.',
				'status' => $geoSample ? 'wrong' : 'optional',
				'summary' => $geoSample
					? 'TEI shows geo-like markers but the index has no text_geo line — add under cqp/sattributes (text level), then reindex.'
					: 'Recommended for map points: expose a coordinate string (e.g. "lat lon") as text_geo after encoding + reindex.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => !$geoSample)));
		}

		if (!$gmOk) {
			/* duplicate wrong row avoided — text_geo row already surfaced parse error */
		} elseif ($hasTextPlace) {
			$rows[] = array_merge(array(
				'id' => 'geo-text-place',
				'section' => 'Indexed corpus (CQP / registry)',
				'label' => 'Structural field text_place',
				'xml' => '—',
				'project' => 'text_place appears in CQP s-attribute registry list.',
				'status' => 'ok',
				'summary' => 'Placenames can accompany coordinates in map tooltips and hits.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), $emptyRem);
		} else {
			$rows[] = array_merge(array(
				'id' => 'geo-text-place',
				'section' => 'Indexed corpus (CQP / registry)',
				'label' => 'Structural field text_place',
				'xml' => '—',
				'project' => 'No text_place key in CQP sattributes list.',
				'status' => 'optional',
				'summary' => 'Optional: add text_place for human-readable place labels alongside text_geo.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => true)));
		}

		// --- geomap block (runtime map wiring) ---
		if (!$gmOk) {
			/* covered */
		} elseif (!$gmPresent) {
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-root',
				'section' => 'geomap settings (maps UI)',
				'label' => 'geomap block in settings',
				'xml' => '—',
				'project' => 'No &lt;geomap&gt; element found under corpus settings.',
				'status' => 'optional',
				'summary' => 'flexicorp reads geomap from getset(xmlfile/geomap, …) — add a block when you want default map centre, tiles, and field wiring.',
				'detail' => 'See flexicorp_functions.php tt_flexicorp_fn_geo_map_runtime_settings() and advanced_maps.js for consumed keys.',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => true)));
		} else {
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-root',
				'section' => 'geomap settings (maps UI)',
				'label' => 'geomap block in settings',
				'xml' => '—',
				'project' => 'geomap element present.',
				'status' => 'ok',
				'summary' => 'Runtime map configuration is declared for this corpus.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), $emptyRem);
		}

		$viewBad = ($gmPresent && ($gmStart === '' || $gmZoom <= 0));
		if ($gmPresent) {
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-view',
				'section' => 'geomap settings (maps UI)',
				'label' => 'Default map view (startpos + zoom)',
				'xml' => '—',
				'project' => 'startpos=' . ($gmStart !== '' ? '`' . $gmStart . '`' : '(empty)') . ' · zoom=' . ($gmZoom > 0 ? (string) $gmZoom : '(unset)'),
				'status' => $viewBad ? 'wrong' : 'ok',
				'summary' => $viewBad
					? 'There is no safe default — set startpos ("lat lon") and zoom, e.g. from your focus country/region (geocode once, paste values).'
					: 'Initial map centre and zoom are set.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), $emptyRem);
		}

		if ($gmPresent) {
			$basemapOk = ($gmOsml !== '' && $gmMark !== '');
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-basemap',
				'section' => 'geomap settings (maps UI)',
				'label' => 'Basemap + marker style',
				'xml' => '—',
				'project' => 'osmlayer=' . ($gmOsml !== '' ? 'set' : '—') . ' · markertype=' . ($gmMark !== '' ? $gmMark : '—'),
				'status' => $basemapOk ? 'ok' : 'optional',
				'summary' => $basemapOk
					? 'Tile template and marker style are declared.'
					: 'Optional: set osmlayer (tile URL template) and markertype (e.g. pie) for consistent styling.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => !$basemapOk)));
		}

		if ($gmPresent) {
			$cqpOk = ($cqPlace !== '' && $cqGeo !== '' && $cqTitle !== '');
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-cqp',
				'section' => 'geomap settings (maps UI)',
				'label' => 'geomap / CQP field wiring',
				'xml' => '—',
				'project' => 'place=' . ($cqPlace !== '' ? $cqPlace : '—') . ' · geo=' . ($cqGeo !== '' ? $cqGeo : '—') . ' · title=' . ($cqTitle !== '' ? $cqTitle : '—'),
				'status' => $cqpOk ? 'ok' : 'wrong',
				'summary' => $cqpOk
					? 'CQP side of geomap names the structural keys used for place, coordinates, and document title.'
					: 'Add a &lt;cqp place="…" geo="…" title="…"/&gt; child under geomap (must align with indexed s-attribute keys).',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), $emptyRem);
		}

		if ($gmPresent) {
			$xmlOk = ($xn !== '' && $xg !== '' && $xname !== '');
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-xml',
				'section' => 'geomap settings (maps UI)',
				'label' => 'geomap / XML xpath wiring',
				'xml' => '—',
				'project' => 'node=' . ($xn !== '' ? 'set' : '—') . ' · geo=' . ($xg !== '' ? 'set' : '—') . ' · name=' . ($xname !== '' ? 'set' : '—') . ' · desc=' . ($xdesc !== '' ? 'set' : '—'),
				'status' => $xmlOk ? 'ok' : 'optional',
				'summary' => $xmlOk
					? 'XML xpath hooks for placename, coordinates, title (and optional desc) are declared.'
					: 'Optional: add &lt;xml node="…" geo="…" name="…" desc="…"/&gt; so TEITOK can resolve labels from TEI outside the index.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => !$xmlOk)));
		}

		if ($gmPresent && $areasTotal > 0) {
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-areas',
				'section' => 'geomap settings (maps UI)',
				'label' => 'Area shortcuts (jump links)',
				'xml' => '—',
				'project' => (string) $areasTotal . ' shortcut(s) · ' . (string) $areasBad . ' missing startpos or zoom.',
				'status' => ($areasBad > 0) ? 'optional' : 'ok',
				'summary' => $areasBad > 0
					? 'Only shortcuts with incomplete startpos/zoom are flagged — they are optional map jump links, not corpus data.'
					: 'All declared area shortcuts include startpos and zoom.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => $areasBad > 0)));
		} elseif ($gmPresent) {
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-areas',
				'section' => 'geomap settings (maps UI)',
				'label' => 'Area shortcuts (jump links)',
				'xml' => '—',
				'project' => 'No &lt;areas&gt; shortcuts declared.',
				'status' => 'optional',
				'summary' => 'Optional: add areas/items for quick jumps (each needs key/display + startpos + zoom).',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => true)));
		}

		if ($gmPresent) {
			$rows[] = array_merge(array(
				'id' => 'geo-geomap-regions',
				'section' => 'geomap settings (maps UI)',
				'label' => 'Region overlay definitions',
				'xml' => '—',
				'project' => (string) $regionsTotal . ' region layer(s) declared.',
				'status' => $regionsTotal > 0 ? 'ok' : 'optional',
				'summary' => $regionsTotal > 0
					? 'Boundary overlays are configured (level/group/provider vary by corpus).'
					: 'Optional: add regions/items for subdivisions (ADM level, aliases, etc.) — not derivable by default.',
				'detail' => '',
				'info_anchor' => 'geolocation',
			), array_merge($emptyRem, array('probe_soft' => $regionsTotal === 0)));
		}

		return $rows;
	}
}

if (!function_exists('tt_fc_wizard_geo_row_section')) {
	function tt_fc_wizard_geo_row_section($row) {
		if (is_array($row) && isset($row['section']) && trim((string) $row['section']) !== '') {
			return (string) $row['section'];
		}
		return 'Geolocation';
	}
}

/**
 * Must stay aligned with flexicorp context-scope region allowlist behaviour (search integration UI).
 *
 * @return list<string>
 */
if (!function_exists('tt_fc_wizard_context_scope_builtin_default_allowlist')) {
	function tt_fc_wizard_context_scope_builtin_default_allowlist() {
		return array('s', 'u', 'lb', 'l', 'p', 'seg');
	}
}

if (!function_exists('tt_fc_wizard_normalize_context_scope_region_key')) {
	function tt_fc_wizard_normalize_context_scope_region_key($r) {
		$r = strtolower(trim((string) $r));
		if ($r === '') {
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
		if (isset($map[$r])) {
			return (string) $map[$r];
		}
		return $r;
	}
}

/**
 * Same rules as tt_flexicorp_context_scope_region_allowlist_resolved — computed here from raw settings text only.
 *
 * @return list<string>
 */
if (!function_exists('tt_fc_wizard_resolve_context_scope_regions_allowlist_from_raw')) {
	function tt_fc_wizard_resolve_context_scope_regions_allowlist_from_raw($raw) {
		$raw = trim((string) $raw);
		$default = tt_fc_wizard_context_scope_builtin_default_allowlist();
		$lraw = strtolower($raw);
		if ($raw === '' || $raw === '-' || $lraw === 'default') {
			return $default;
		}
		$parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
		$out = array();
		$seen = array();
		foreach ($parts as $p) {
			$k = tt_fc_wizard_normalize_context_scope_region_key($p);
			if ($k === '') {
				continue;
			}
			if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $k)) {
				continue;
			}
			if (!isset($seen[$k])) {
				$seen[$k] = true;
				$out[] = $k;
			}
		}
		if (!count($out)) {
			return $default;
		}
		return $out;
	}
}

/**
 * Read TEITOK corpus XML for flexicorp context-scope regions when getset is unavailable.
 *
 * @return string raw value or ''
 */
if (!function_exists('tt_fc_wizard_parse_flexicorp_context_scope_regions_xml')) {
	function tt_fc_wizard_parse_flexicorp_context_scope_regions_xml($settingsPath) {
		if (!is_string($settingsPath) || $settingsPath === '' || !is_readable($settingsPath)) {
			return '';
		}
		$rawfile = @file_get_contents($settingsPath);
		if (!is_string($rawfile) || trim($rawfile) === '') {
			return '';
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = @$dom->loadXML($rawfile, LIBXML_NONET);
		libxml_clear_errors();
		if (!$loaded) {
			return '';
		}
		$xp = new DOMXPath($dom);
		$queries = array(
			'//ttsettings/flexicorp/item[@key="context_scope_regions"]',
			'//flexicorp/item[@key="context_scope_regions"]',
			'//*[local-name()="flexicorp"]/*[local-name()="item"][@key="context_scope_regions"]',
		);
		foreach ($queries as $q) {
			$nl = $xp->query($q);
			if (!$nl instanceof DOMNodeList || $nl->length < 1) {
				continue;
			}
			$el = $nl->item(0);
			if (!$el instanceof DOMElement) {
				continue;
			}
			$v = trim($el->getAttribute('default'));
			if ($v === '') {
				$v = trim($el->getAttribute('value'));
			}
			if ($v === '') {
				$v = trim($el->textContent);
			}
			if ($v !== '') {
				return $v;
			}
		}
		return '';
	}
}

/**
 * Resolve flexicorp/context_scope_regions from TEITOK merged runtime (getset) and/or XML — no flexicorp.php helpers.
 *
 * @return array{
 *   raw:string,
 *   raw_source:string,
 *   explicit:bool,
 *   resolved_allowlist:list<string>,
 *   u_allowed:bool
 * }
 */
if (!function_exists('tt_fc_wizard_resolve_flexicorp_context_scope_regions')) {
	function tt_fc_wizard_resolve_flexicorp_context_scope_regions($canonicalSettingsPath, $mergedCqpPath, $mergedReadable) {
		$raw = '';
		$src = '';
		if (function_exists('getset')) {
			$raw = trim((string) getset('flexicorp/context_scope_regions', ''));
			$src = 'getset';
		}
		if ($raw === '' && $mergedReadable && is_string($mergedCqpPath) && $mergedCqpPath !== '') {
			$try = tt_fc_wizard_parse_flexicorp_context_scope_regions_xml($mergedCqpPath);
			if ($try !== '') {
				$raw = $try;
				$src = 'merged_xml';
			}
		}
		if ($raw === '' && is_string($canonicalSettingsPath) && $canonicalSettingsPath !== '') {
			$try = tt_fc_wizard_parse_flexicorp_context_scope_regions_xml($canonicalSettingsPath);
			if ($try !== '') {
				$raw = $try;
				$src = 'canonical_xml';
			}
		}
		$lr = strtolower($raw);
		$explicit = ($raw !== '' && $raw !== '-' && $lr !== 'default');
		$resolved = tt_fc_wizard_resolve_context_scope_regions_allowlist_from_raw($raw);
		$u_allowed = false;
		foreach ($resolved as $r) {
			if ((string) $r === 'u') {
				$u_allowed = true;
				break;
			}
		}
		return array(
			'raw' => $raw,
			'raw_source' => $src,
			'explicit' => $explicit,
			'resolved_allowlist' => $resolved,
			'u_allowed' => $u_allowed,
		);
	}
}

if (!function_exists('tt_fc_wizard_render')) {
	function tt_fc_wizard_render() {
		$projectRoot = tt_fc_wizard_project_root();
		$wizardFixAppliedList = tt_fc_wizard_fix_applied_log_list($projectRoot);
		$audioFolderPresent = (trim((string) $projectRoot) !== ''
			&& is_dir(rtrim((string) $projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'Audio'));
		$xmlDir = tt_fc_wizard_resolve_xml_dir($projectRoot);
		$sampleSize = isset($_REQUEST['sample']) ? (int) $_REQUEST['sample'] : 8;
		if ($sampleSize <= 0) $sampleSize = 8;
		if ($sampleSize > 50) $sampleSize = 50;

		$sampleFiles = ($xmlDir !== '') ? tt_fc_wizard_collect_xml_files($xmlDir, $sampleSize) : array();
		$profile = tt_fc_wizard_profile_from_sample($sampleFiles, $projectRoot);
		$t = $profile['totals'];
		$rows = $profile['rows'];
		$settingsXmlPath = tt_fc_wizard_resolve_teitok_settings_xml($projectRoot);
		$cqpAlign = tt_fc_wizard_parse_cqp_alignment($settingsXmlPath);
		$need_s_tuid = !empty($t['s_tuid_docs']) && (int) $t['s_tuid_docs'] > 0;
		$need_tok_tuid = !empty($t['tok_tuid_docs']) && (int) $t['tok_tuid_docs'] > 0;
		$cqp_ok_s_tuid = !$need_s_tuid || !empty($cqpAlign['sattr_s_tuid']);
		$cqp_ok_tok_tuid = !$need_tok_tuid || !empty($cqpAlign['pattr_tuid']) || !empty($cqpAlign['pattr_p_tuid']);
		$alignCqpWired = ($settingsXmlPath !== '' && $cqpAlign['ok'] && $cqp_ok_s_tuid && $cqp_ok_tok_tuid);
		$need_text_tuid_xml = !empty($t['text_tuid_docs']) && (int) $t['text_tuid_docs'] > 0;
		$text_tuid_parallel_nudge = ((int) $t['aligned_docs'] > 0 || (int) $t['tuid_docs'] > 0) && empty($cqpAlign['sattr_text_tuid']);
		$settings_writable = ($settingsXmlPath !== '' && is_writable($settingsXmlPath));
		$settings_readable = ($settingsXmlPath !== '' && is_readable($settingsXmlPath));
		$canonicalSettingsPath = tt_fc_wizard_teitok_canonical_settings_path($projectRoot);
		$mergedCqpPath = (trim((string) $projectRoot) !== '')
			? rtrim((string) $projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cqpsettings.xml'
			: '';
		$mergedCqpReadable = ($mergedCqpPath !== '' && is_file($mergedCqpPath) && is_readable($mergedCqpPath));
		$wizard_sharedfolder = isset($GLOBALS['sharedfolder']) ? trim((string) $GLOBALS['sharedfolder']) : '';
		$sharedCanonicalProbe = ($wizard_sharedfolder !== '')
			? rtrim($wizard_sharedfolder, '/\\') . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'settings.xml'
			: '';
		$sharedCanonicalReadable = ($sharedCanonicalProbe !== '' && is_file($sharedCanonicalProbe) && is_readable($sharedCanonicalProbe));
		$flash = null;
		if (!empty($GLOBALS['tt_fc_wizard_flash']) && is_array($GLOBALS['tt_fc_wizard_flash'])) {
			$flash = $GLOBALS['tt_fc_wizard_flash'];
			$GLOBALS['tt_fc_wizard_flash'] = null;
		}
		if ($flash === null && isset($_GET['applied']) && (string) $_GET['applied'] === '1') {
			$flash = array(
				'type' => 'success',
				'msg' => 'Alignment entries were written where needed. Reindex the corpus so indexed attributes match settings.',
			);
		}
		$tokenAttrHits = isset($profile['tokenAttrHits']) && is_array($profile['tokenAttrHits']) ? $profile['tokenAttrHits'] : array();
		$headerAttrHits = isset($profile['headerAttrHits']) && is_array($profile['headerAttrHits']) ? $profile['headerAttrHits'] : array();
		$headerElemHits = isset($profile['headerElemHits']) && is_array($profile['headerElemHits']) ? $profile['headerElemHits'] : array();
		$regionElemHits = isset($profile['regionElemHits']) && is_array($profile['regionElemHits']) ? $profile['regionElemHits'] : array();
		$tokenAttrTop = array_slice(array_keys($tokenAttrHits), 0, 18);
		$headerAttrTop = array_slice(array_keys($headerAttrHits), 0, 18);
		$headerElemTop = array_slice(array_keys($headerElemHits), 0, 18);
		$regionElemTop = array_slice(array_keys($regionElemHits), 0, 18);

		$spokenPartial = ($t['spoken_docs'] > 0 || $t['media_docs'] > 0);
		$spokenPrepAudio = ((int) $t['scanned'] > 0 && (int) $t['spoken_docs'] === 0 && (int) $t['media_docs'] === 0);
		$spokenConcern = ($spokenPartial || $spokenPrepAudio);
		$scopeRegs = tt_fc_wizard_resolve_flexicorp_context_scope_regions($canonicalSettingsPath, $mergedCqpPath, $mergedCqpReadable);
		$spokenScopeExplicit = !empty($scopeRegs['explicit']);
		$spokenUAllowed = !empty($scopeRegs['u_allowed']);
		$spokenHardAllowlist = ($spokenConcern && $spokenScopeExplicit && !$spokenUAllowed);
		$spokenSoftImplicit = ($spokenConcern && !$spokenScopeExplicit && $spokenUAllowed);
		$cqpDefaultsResolved = tt_fc_wizard_resolve_cqp_search_defaults($canonicalSettingsPath, $mergedCqpPath);
		$cqpSattrXmlPath = ($mergedCqpReadable && $mergedCqpPath !== '') ? $mergedCqpPath : $settingsXmlPath;
		$cqpSattrParse = tt_fc_wizard_parse_cqp_sattribute_keys($cqpSattrXmlPath);
		$audioUploadInfo = ($settingsXmlPath !== '')
			? tt_fc_wizard_parse_settings_files_audio_upload($settingsXmlPath)
			: array(
				'ok' => false,
				'configured' => false,
				'error' => 'No canonical settings.xml path',
				'hint' => '',
			);
		$spokenUnifiedRows = tt_fc_wizard_spoken_unified_rows(
			$projectRoot,
			$t,
			$audioFolderPresent,
			$audioUploadInfo,
			$scopeRegs,
			$spokenHardAllowlist,
			$spokenSoftImplicit,
			$cqpSattrParse,
			$cqpDefaultsResolved,
			$regionElemHits,
			$mergedCqpReadable,
			$cqpSattrXmlPath
		);
		$spokenFooterNotes = tt_fc_wizard_spoken_footer_notes($projectRoot, $xmlDir, $profile, $t);
		$spokenHasWrong = false;
		$spokenHasOptional = false;
		foreach ($spokenUnifiedRows as $_ur) {
			$ust = isset($_ur['status']) ? strtolower((string) $_ur['status']) : '';
			if ($ust === 'wrong') {
				$spokenHasWrong = true;
			} elseif ($ust === 'optional') {
				$spokenHasOptional = true;
			}
		}
		$spokenFixAllowlistMerge = (($spokenHardAllowlist || $spokenSoftImplicit) && $settings_readable && $settingsXmlPath !== '');
		$spokenFixCqpUMerge = false;
		foreach ($spokenUnifiedRows as $_sx) {
			if (isset($_sx['id']) && (string) $_sx['id'] === 'spoken-cqp-u' && isset($_sx['status']) && strtolower((string) $_sx['status']) === 'wrong') {
				$spokenFixCqpUMerge = true;
				break;
			}
		}
		$spokenFixCqpUMerge = $spokenFixCqpUMerge && $settings_readable && $settingsXmlPath !== '';
		$tokTimedWsMain = isset($t['tok_timing_docs']) ? (int) $t['tok_timing_docs'] : 0;
		$wsHlMain = function_exists('getset') ? trim((string) getset('xmlfile/speech/highlight', '')) : '';
		$wsWantsTokMain = ($wsHlMain !== '' && strcasecmp($wsHlMain, 'TOK') === 0);
		$highlightIsTokMain = $wsWantsTokMain;
		$spokenFixWavesurferTok = ($spokenConcern && $tokTimedWsMain > 0 && !$highlightIsTokMain && $settings_readable && $settingsXmlPath !== '');
		$spokenFixWavesurferUtt = ($spokenConcern && $wsWantsTokMain && $tokTimedWsMain === 0 && $settings_readable && $settingsXmlPath !== '');
		$wizardSpeechHighlightUttTag = function_exists('getset') ? strtoupper(trim((string) getset('xmlfile/defaults/speechturn', 'U'))) : 'U';
		if ($wizardSpeechHighlightUttTag === '') {
			$wizardSpeechHighlightUttTag = 'U';
		}
		$msspMain = isset($t['media_single_url_pattern']) ? trim((string) $t['media_single_url_pattern']) : '';
		$uAudioXpathMain = ($settingsXmlPath !== '') ? tt_fc_wizard_cqp_region_nested_audio_xpath($settingsXmlPath, 'u') : '';
		$spokenFixMediaUAudio = ($spokenPartial && $msspMain !== '' && $uAudioXpathMain === '' && $settings_readable && $settingsXmlPath !== '');
		$wizardMediaUAudioXpathPreview = $msspMain;
		$spokenFixFilesAudio = (
			$spokenConcern
			&& $settings_readable
			&& $settingsXmlPath !== ''
			&& empty($audioUploadInfo['configured'])
		);
		$wizardAlignMergeOffer = ($settings_writable && $settingsXmlPath !== '' && (
			!$alignCqpWired
			|| (empty($cqpAlign['sattr_text_tuid']) && ($need_text_tuid_xml || $text_tuid_parallel_nudge))
		));
		$wizard_align_include_text_tuid_checked = $need_text_tuid_xml;
		$scannedCount = isset($t['scanned']) ? (int) $t['scanned'] : 0;
		if ($spokenHasWrong) {
			$spokenTabDotClass = 'fc-wizard-status-dot--red';
			$spokenTabTitle = 'Spoken data: fix blocking row(s) in checklist';
		} elseif ($scannedCount === 0) {
			$spokenTabDotClass = 'fc-wizard-status-dot--grey';
			$spokenTabTitle = 'Spoken data: no XML in sample (configure project before transcribing)';
		} elseif ($spokenHasOptional) {
			$spokenTabDotClass = 'fc-wizard-status-dot--amber';
			$spokenTabTitle = 'Spoken data: optional / review row(s)';
		} elseif ($spokenPrepAudio) {
			$spokenTabDotClass = 'fc-wizard-status-dot--amber';
			$spokenTabTitle = 'Spoken data: prepare for audio (no ⟨u⟩/media in TEI yet)';
		} elseif ($spokenPartial) {
			$spokenTabDotClass = 'fc-wizard-status-dot--green';
			$spokenTabTitle = 'Spoken data: no blocking issues for this scan';
		} else {
			$spokenTabDotClass = 'fc-wizard-status-dot--blue';
			$spokenTabTitle = 'Spoken data: no ⟨u⟩/media markers in this sample — add oral TEI if you want playback/sync tooling';
		}
		$indexesData = tt_fc_wizard_indexes_collect($projectRoot, $profile, $canonicalSettingsPath, $mergedCqpPath, $mergedCqpReadable);
		$dependenciesUnifiedRows = tt_fc_wizard_dependencies_unified_rows(
			$tokenAttrHits,
			$t,
			$canonicalSettingsPath,
			$mergedCqpPath,
			$mergedCqpReadable,
			$regionElemHits,
			$cqpDefaultsResolved,
			$cqpSattrParse
		);
		$settingsPathForGeo = ($mergedCqpReadable && is_string($mergedCqpPath) && $mergedCqpPath !== '')
			? $mergedCqpPath
			: $canonicalSettingsPath;
		$geomapParse = tt_fc_wizard_parse_geomap_block($settingsPathForGeo);
		$geoUnifiedRows = tt_fc_wizard_geo_unified_rows($t, $indexesData, $geomapParse);
		$settingsPathForCqpDep = ($mergedCqpReadable && is_string($mergedCqpPath) && $mergedCqpPath !== '')
			? $mergedCqpPath
			: $canonicalSettingsPath;
		$cqpPattrParseForDepFix = tt_fc_wizard_parse_cqp_pattribute_keys($settingsPathForCqpDep);
		$hasDeprelXmlMain = tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, 'deprel');
		$hasHeadXmlMain = tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, 'head');
		$bothTokMain = ($hasDeprelXmlMain && $hasHeadXmlMain);
		$depIntentMain = ($hasDeprelXmlMain || $hasHeadXmlMain);
		$pflipDepFix = array();
		if (!empty($cqpPattrParseForDepFix['keys']) && is_array($cqpPattrParseForDepFix['keys'])) {
			foreach ($cqpPattrParseForDepFix['keys'] as $_pk) {
				$pflipDepFix[strtolower(trim((string) $_pk))] = true;
			}
		}
		$depsFixCqpPattrsMerge = $settings_readable && $settingsXmlPath !== ''
			&& !empty($cqpPattrParseForDepFix['ok'])
			&& (!isset($pflipDepFix['deprel']) || !isset($pflipDepFix['head']));

		$cqpAlignForDepFix = tt_fc_wizard_parse_cqp_alignment($settingsPathForCqpDep);
		$sattrForDepFix = tt_fc_wizard_parse_cqp_sattribute_keys($settingsPathForCqpDep);
		$sflipSForDep = array();
		if (!empty($sattrForDepFix['keys']) && is_array($sattrForDepFix['keys'])) {
			foreach ($sattrForDepFix['keys'] as $_sk) {
				$sflipSForDep[strtolower(trim((string) $_sk))] = true;
			}
		}
		$hasSIndexedForDep = isset($sflipSForDep['s']);
		$hitSForDep = !empty($regionElemHits['s']);
		$sTuidDocsForDep = isset($t['s_tuid_docs']) ? (int) $t['s_tuid_docs'] : 0;
		$sTuidCqpForDep = !empty($cqpAlignForDepFix['ok']) && !empty($cqpAlignForDepFix['sattr_s_tuid']);
		$depsFixCqpSRegionMerge = $settings_readable && $settingsXmlPath !== ''
			&& !empty($sattrForDepFix['ok'])
			&& $hitSForDep
			&& !$hasSIndexedForDep;
		$depsFixCqpSTuidMerge = $settings_readable && $settingsXmlPath !== ''
			&& !empty($cqpAlignForDepFix['ok'])
			&& !$sTuidCqpForDep;

		$xfPatForDepFix = tt_fc_wizard_parse_xmlfile_pattribute_keys($canonicalSettingsPath);
		$xfKeysForDep = (isset($xfPatForDepFix['keys']) && is_array($xfPatForDepFix['keys'])) ? $xfPatForDepFix['keys'] : array();
		$xfflipForDep = array();
		foreach ($xfKeysForDep as $xk) {
			$xfflipForDep[strtolower(trim((string) $xk))] = true;
		}
		$depsFixXmlfileDeprelHeadMerge = $settings_readable && $settingsXmlPath !== ''
			&& !empty($xfPatForDepFix['ok'])
			&& (!isset($xfflipForDep['deprel']) || !isset($xfflipForDep['head']));

		$morphKeysMorph = array('upos', 'xpos', 'lemma', 'feats');
		$depsMorphFixKeys = array();
		foreach ($morphKeysMorph as $mk) {
			if (!isset($pflipDepFix[$mk]) && ($depIntentMain ? tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, $mk) : true)) {
				$depsMorphFixKeys[] = $mk;
			}
		}

		$scannedForDep = isset($t['scanned']) ? (int) $t['scanned'] : 0;
		$hasDeprelXmlDot = tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, 'deprel');
		$hasHeadXmlDot = tt_fc_wizard_token_attr_hit_ci($tokenAttrHits, 'head');
		$depIntentDot = ($hasDeprelXmlDot || $hasHeadXmlDot);
		$dependenciesPrepBannerShow = ($scannedForDep > 0)
			&& !$depIntentDot
			&& ($depsFixCqpPattrsMerge || $depsFixXmlfileDeprelHeadMerge || $depsFixCqpSRegionMerge || $depsFixCqpSTuidMerge);
		$depsFixCqpMorphMerge = $settings_readable && $settingsXmlPath !== ''
			&& !empty($cqpPattrParseForDepFix['ok'])
			&& count($depsMorphFixKeys) > 0;
		$dependenciesTabDotClass = 'fc-wizard-status-dot--grey';
		$dependenciesTabTitle = 'Dependencies (UD): no deprel/head in sample';
		if ($scannedForDep === 0) {
			$dependenciesTabDotClass = 'fc-wizard-status-dot--grey';
			$dependenciesTabTitle = 'Dependencies (UD): no XML in sample';
		} elseif (!$depIntentDot) {
			$dependenciesTabDotClass = 'fc-wizard-status-dot--blue';
			$dependenciesTabTitle = 'Dependencies (UD): no deprel/head in this sample — add UD annotation if you want tree-style search';
		} else {
			$depHasWrong = false;
			$depHasWarn = false;
			foreach ($dependenciesUnifiedRows as $_dr) {
				$ds = isset($_dr['status']) ? strtolower((string) $_dr['status']) : '';
				if ($ds === 'wrong') {
					$depHasWrong = true;
				} elseif ($ds === 'warn' || $ds === 'optional') {
					$depHasWarn = true;
				}
			}
			if ($depHasWrong) {
				$dependenciesTabDotClass = 'fc-wizard-status-dot--red';
				$dependenciesTabTitle = 'Dependencies (UD): fix blocking prerequisite(s)';
			} elseif ($depHasWarn) {
				$dependenciesTabDotClass = 'fc-wizard-status-dot--amber';
				$dependenciesTabTitle = 'Dependencies (UD): review warning(s)';
			} else {
				$dependenciesTabDotClass = 'fc-wizard-status-dot--green';
				$dependenciesTabTitle = 'Dependencies (UD): prerequisites OK for this scan';
			}
		}
		$facsReady = ($t['facs_docs'] > 0);

		// Tab nav dots (Overview, Core, Index(es), XML sample have no indicator blob).
		if ($facsReady) {
			$facsTabDotClass = 'fc-wizard-status-dot--green';
			$facsTabTitle = 'Facsimile: facs-related markers found in sample XML';
		} else {
			$facsTabDotClass = 'fc-wizard-status-dot--blue';
			$facsTabTitle = 'Facsimile: no facs markers in this sample — add if you use page images / facsimile';
		}
		$alignNeedsCqpFromSample = ($need_s_tuid || $need_tok_tuid);
		if ($alignCqpWired) {
			$alignTabDotClass = 'fc-wizard-status-dot--green';
			$alignTabTitle = 'Alignment: CQP settings match sampled XML';
		} elseif ($alignNeedsCqpFromSample) {
			$alignTabDotClass = 'fc-wizard-status-dot--red';
			$alignTabTitle = 'Alignment: sample uses alignment ids — indexed CQP entries must match under corpus settings';
		} else {
			$alignTabDotClass = 'fc-wizard-status-dot--blue';
			$alignTabTitle = 'Alignment: no alignment id markers in this sample — configure when you add parallel / linked texts';
		}
		$geoTabDotClass = 'fc-wizard-status-dot--grey';
		$geoTabTitle = 'Geolocation: no XML in sample';
		$scannedForGeo = isset($t['scanned']) ? (int) $t['scanned'] : 0;
		if ($scannedForGeo === 0) {
			$geoTabDotClass = 'fc-wizard-status-dot--grey';
			$geoTabTitle = 'Geolocation: no XML in sample';
		} else {
			$geoHasWrong = false;
			$geoHasWarn = false;
			foreach ($geoUnifiedRows as $_gr) {
				$gs = isset($_gr['status']) ? strtolower((string) $_gr['status']) : '';
				if ($gs === 'wrong') {
					$geoHasWrong = true;
				} elseif ($gs === 'warn' || $gs === 'optional') {
					$geoHasWarn = true;
				}
			}
			if ($geoHasWrong) {
				$geoTabDotClass = 'fc-wizard-status-dot--red';
				$geoTabTitle = 'Geolocation: fix blocking row(s)';
			} elseif ($geoHasWarn) {
				$geoTabDotClass = 'fc-wizard-status-dot--amber';
				$geoTabTitle = 'Geolocation: review optional or incomplete wiring';
			} else {
				$geoTabDotClass = 'fc-wizard-status-dot--green';
				$geoTabTitle = 'Geolocation: no blocking issues for this scan';
			}
		}
		$tab = isset($_REQUEST['tab']) ? strtolower(trim((string) $_REQUEST['tab'])) : 'overview';
		$allowedTabs = array('overview', 'core', 'indexes', 'dependencies', 'spoken', 'facs', 'alignment', 'geo', 'samples');
		if (!in_array($tab, $allowedTabs, true)) $tab = 'overview';

		$wizard_select_fix_list = array();
		$wizard_scope_current = tt_fc_wizard_project_scope_key($projectRoot);
		$wizard_scope_in_request = isset($_REQUEST['wizard_scope']) ? trim((string) $_REQUEST['wizard_scope']) : '';
		if ($wizard_scope_in_request !== '' && hash_equals($wizard_scope_current, $wizard_scope_in_request) && isset($_REQUEST['wizard_select_fix'])) {
			$wizard_select_fix_list = tt_fc_wizard_parse_wizard_select_fix_list((string) $_REQUEST['wizard_select_fix']);
		}
		$wizard_select_fix = implode(',', $wizard_select_fix_list);

		$wizardSettingsFixDockOpen = (count($wizard_select_fix_list) > 0 || count($wizardFixAppliedList) > 0);
		$dock_show_alignment_merge = in_array('alignment_merge', $wizard_select_fix_list, true) && $wizardAlignMergeOffer;
		$dock_show_allowlist_u = in_array('allowlist_u', $wizard_select_fix_list, true) && $spokenFixAllowlistMerge;
		$dock_show_cqp_u_stub = in_array('cqp_u_stub', $wizard_select_fix_list, true) && $spokenFixCqpUMerge;
		$dock_show_wavesurfer_tok = in_array('wavesurfer_tok', $wizard_select_fix_list, true) && $spokenFixWavesurferTok;
		$dock_show_wavesurfer_utt = in_array('wavesurfer_utt', $wizard_select_fix_list, true) && $spokenFixWavesurferUtt;
		$dock_show_cqp_u_audio = in_array('cqp_u_audio', $wizard_select_fix_list, true) && $spokenFixMediaUAudio;
		$dock_show_files_audio = in_array('files_audio', $wizard_select_fix_list, true) && $spokenFixFilesAudio;

		if ($dock_show_alignment_merge && tt_fc_wizard_fix_applied_has($projectRoot, 'alignment_merge')) {
			$dock_show_alignment_merge = false;
		}
		if ($dock_show_allowlist_u && tt_fc_wizard_fix_applied_has($projectRoot, 'allowlist_u')) {
			$dock_show_allowlist_u = false;
		}
		if ($dock_show_cqp_u_stub && tt_fc_wizard_fix_applied_has($projectRoot, 'cqp_u_stub')) {
			$dock_show_cqp_u_stub = false;
		}
		if ($dock_show_wavesurfer_tok && tt_fc_wizard_fix_applied_has($projectRoot, 'wavesurfer_tok')) {
			$dock_show_wavesurfer_tok = false;
		}
		if ($dock_show_wavesurfer_utt && tt_fc_wizard_fix_applied_has($projectRoot, 'wavesurfer_utt')) {
			$dock_show_wavesurfer_utt = false;
		}
		if ($dock_show_cqp_u_audio && tt_fc_wizard_fix_applied_has($projectRoot, 'cqp_u_audio')) {
			$dock_show_cqp_u_audio = false;
		}
		if ($dock_show_files_audio && tt_fc_wizard_fix_applied_has($projectRoot, 'files_audio')) {
			$dock_show_files_audio = false;
		}
		$dock_show_cqp_deprel_head = in_array('cqp_deprel_head', $wizard_select_fix_list, true) && $depsFixCqpPattrsMerge;
		if ($dock_show_cqp_deprel_head && tt_fc_wizard_fix_applied_has($projectRoot, 'cqp_deprel_head')) {
			$dock_show_cqp_deprel_head = false;
		}
		$dock_show_cqp_s_region = in_array('cqp_s_region', $wizard_select_fix_list, true) && $depsFixCqpSRegionMerge;
		if ($dock_show_cqp_s_region && tt_fc_wizard_fix_applied_has($projectRoot, 'cqp_s_region')) {
			$dock_show_cqp_s_region = false;
		}
		$dock_show_cqp_s_tuid = in_array('cqp_s_tuid', $wizard_select_fix_list, true) && $depsFixCqpSTuidMerge;
		if ($dock_show_cqp_s_tuid && tt_fc_wizard_fix_applied_has($projectRoot, 'cqp_s_tuid')) {
			$dock_show_cqp_s_tuid = false;
		}
		$dock_show_xmlfile_deprel_head = in_array('xmlfile_deprel_head', $wizard_select_fix_list, true) && $depsFixXmlfileDeprelHeadMerge;
		if ($dock_show_xmlfile_deprel_head && tt_fc_wizard_fix_applied_has($projectRoot, 'xmlfile_deprel_head')) {
			$dock_show_xmlfile_deprel_head = false;
		}
		$dock_show_cqp_ud_morph = in_array('cqp_ud_morph', $wizard_select_fix_list, true) && $depsFixCqpMorphMerge;
		if ($dock_show_cqp_ud_morph && tt_fc_wizard_fix_applied_has($projectRoot, 'cqp_ud_morph')) {
			$dock_show_cqp_ud_morph = false;
		}

		$dock_has_actionable_fix = ($dock_show_alignment_merge || $dock_show_allowlist_u || $dock_show_cqp_u_stub || $dock_show_wavesurfer_tok
			|| $dock_show_wavesurfer_utt || $dock_show_cqp_u_audio || $dock_show_files_audio || $dock_show_cqp_deprel_head
			|| $dock_show_cqp_s_region || $dock_show_cqp_s_tuid || $dock_show_xmlfile_deprel_head || $dock_show_cqp_ud_morph);

		$wizard_fix_queue_fully_applied = false;
		if (count($wizard_select_fix_list) > 0) {
			$wizard_fix_queue_fully_applied = true;
			foreach ($wizard_select_fix_list as $_wq) {
				if (!tt_fc_wizard_fix_applied_has($projectRoot, $_wq)) {
					$wizard_fix_queue_fully_applied = false;
					break;
				}
			}
		}
		$wizard_fix_queue_all_applied_no_action = (count($wizard_select_fix_list) > 0 && !$dock_has_actionable_fix && $wizard_fix_queue_fully_applied);

		$wizardAutomatedFixesPanelVisible = (count($wizard_select_fix_list) > 0 || count($wizardFixAppliedList) > 0);

		ob_start();
		?>
		<style>
			/* Button-like tab switches (flexicorp seg-btn + toolbar shell) */
			.fc-wizard-tabseg {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 0.4rem 0.45rem;
				margin: 0.35rem 0 0.9rem 0;
				padding: 0.4rem 0.5rem;
				background: #eceff1;
				border: 1px solid #c5cad3;
				border-radius: 6px;
			}
			.fc-wizard-tabseg button.flexicorp-seg-btn {
				text-decoration: none;
				display: inline-block;
				white-space: nowrap;
				padding: 0.35rem 0.65rem;
				font-size: 0.85rem;
				box-shadow: 0 1px 0 rgba(15, 23, 42, 0.06);
				border: 1px solid #cfd8dc;
				background: #fff;
				color: inherit;
				font-family: inherit;
				cursor: pointer;
				border-radius: 4px;
				line-height: 1.25;
			}
			.fc-wizard-tabseg button.flexicorp-seg-btn:not(.flexicorp-seg-btn--active):hover {
				background: #f8f9fa;
			}
			.fc-wizard-tabseg button.flexicorp-seg-btn.flexicorp-seg-btn--active {
				border-color: #1565c0;
				background: #e3f2fd;
				font-weight: 600;
			}
			/* In-prose “tab” openers: submit #fc-wizard-sample-form so the current sample field is kept */
			button.fc-wizard-tab-inline-link {
				background: none;
				border: none;
				padding: 0;
				margin: 0;
				font: inherit;
				color: #1565c0;
				cursor: pointer;
				text-decoration: underline;
			}
			button.fc-wizard-tab-inline-link:hover {
				color: #0d47a1;
			}
			.fc-wizard-status-dot {
				display: inline-block;
				width: 0.55rem;
				height: 0.55rem;
				border-radius: 50%;
				margin-right: 0.4rem;
				vertical-align: middle;
			}
			.fc-wizard-status-dot--green { background: #2e7d32; }
			.fc-wizard-status-dot--amber { background: #ef6c00; }
			.fc-wizard-status-dot--red { background: #c62828; }
			.fc-wizard-status-dot--blue { background: #1565c0; }
			.fc-wizard-status-dot--grey { background: #9e9e9e; }
			/* Spoken tab checklist: zebra rows + hover so scan reads row-by-row */
			#rollovertable {
				border-collapse: collapse;
				width: 100%;
				min-width: 920px;
				margin: 0;
			}
			#rollovertable thead tr {
				background: #eceff1;
				border-bottom: 2px solid #cfd8dc;
			}
			#rollovertable thead th {
				font-weight: 600;
				padding: 0.45rem 0.55rem;
				vertical-align: bottom;
				border-color: #cfd8dc;
			}
			#rollovertable tbody tr:nth-child(odd) {
				background: #ffffff;
			}
			#rollovertable tbody tr:nth-child(even) {
				background: #f5f7fa;
			}
			#rollovertable tbody tr:hover {
				background: #e3f2fd;
			}
			#rollovertable tbody td {
				padding: 0.45rem 0.55rem;
				vertical-align: top;
				border-color: #e0e4e8;
			}
			a.fc-wizard-help-icon {
				float: right;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 1.2rem;
				height: 1.2rem;
				margin: 0 0 0.35rem 0.5rem;
				border: 1px solid #90a4ae;
				border-radius: 50%;
				font-size: 0.68rem;
				font-weight: 700;
				font-style: italic;
				font-family: Georgia, "Times New Roman", serif;
				line-height: 1;
				color: #455a64;
				text-decoration: none;
				background: #fafafa;
				flex-shrink: 0;
			}
			a.fc-wizard-help-icon:hover {
				border-color: #1565c0;
				color: #1565c0;
				background: #fff;
			}
			a.fc-wizard-help-icon--header {
				float: none;
				width: 1.38rem;
				height: 1.38rem;
				font-size: 0.74rem;
				margin: 0;
				margin-left: auto;
				align-self: flex-start;
			}
		</style>
		<div class="fc-wizard-shell" style="padding:1rem 1.2rem;max-width:1200px;">
			<?php if (!empty($flash) && !empty($flash['msg'])): ?>
				<?php
				$fc_wizard_flash_type = isset($flash['type']) ? (string) $flash['type'] : 'success';
				$fc_wizard_flash_alert = 'success';
				if ($fc_wizard_flash_type === 'danger') {
					$fc_wizard_flash_alert = 'danger';
				} elseif ($fc_wizard_flash_type === 'info') {
					$fc_wizard_flash_alert = 'info';
				}
				?>
				<div class="alert alert-<?php echo tt_fc_wizard_h($fc_wizard_flash_alert); ?>" style="margin:0 0 1rem 0;">
					<?php echo tt_fc_wizard_h($flash['msg']); ?>
				</div>
			<?php endif; ?>
			<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.5rem .75rem;margin:0 0 .75rem 0;width:100%;box-sizing:border-box;">
				<h2 style="margin:0;flex:1;min-width:12rem;">TEITOK Corpus Setup Wizard</h2>
				<a href="<?php echo tt_fc_wizard_h(tt_fc_wizard_help_url()); ?>" target="_blank" rel="noopener noreferrer" class="fc-wizard-help-icon fc-wizard-help-icon--header" aria-label="Open setup wizard help" title="Help"><span aria-hidden="true">i</span></a>
			</div>
			<p style="margin:0 0 1rem 0;color:#444;font-size:.92rem;">
				Samples XML + settings, highlights gaps, links remedies. Not a pass/fail audit — full notes: use the <strong style="font-weight:600;">i</strong> icon top right.
				The wizard targets a <strong>typical TEITOK corpus layout</strong> (e.g. <code>index.php?action=adminsettings</code>, <code>Resources/settings.xml</code>). Custom or non-standard setups are out of scope — adjust those by editing settings and TEI by hand.
			</p>

			<form id="fc-wizard-sample-form" method="get" style="display:flex;gap:.7rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem;">
				<input type="hidden" name="action" value="wizard">
				<input type="hidden" name="tab" value="<?php echo tt_fc_wizard_h($tab); ?>">
				<input type="hidden" name="wizard_scope" value="<?php echo tt_fc_wizard_h($wizard_scope_current); ?>">
				<?php if ($wizard_select_fix !== ''): ?>
				<input type="hidden" name="wizard_select_fix" value="<?php echo tt_fc_wizard_h($wizard_select_fix); ?>">
				<?php endif; ?>
				<div>
					<label style="display:block;font-weight:600;margin-bottom:.15rem;">Sample XML files</label>
					<input type="number" min="1" max="50" name="sample" value="<?php echo tt_fc_wizard_h($sampleSize); ?>" style="width:7rem;">
				</div>
				<div>
					<button type="submit" class="btn btn-sm btn-primary">Rescan</button>
				</div>
				<div style="color:#555;font-size:.88rem;">Corpus = directory containing <code>index.php</code>.</div>
			</form>

			<nav class="fc-wizard-tabseg" role="tablist" aria-label="Setup wizard sections">
				<button type="button" role="tab" id="fc-wizard-tab-overview" class="flexicorp-seg-btn<?php echo $tab === 'overview' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="overview" aria-selected="<?php echo $tab === 'overview' ? 'true' : 'false'; ?>"<?php echo $tab === 'overview' ? ' aria-current="true"' : ''; ?> title="Overview">Overview</button>
				<button type="button" role="tab" id="fc-wizard-tab-core" class="flexicorp-seg-btn<?php echo $tab === 'core' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="core" aria-selected="<?php echo $tab === 'core' ? 'true' : 'false'; ?>"<?php echo $tab === 'core' ? ' aria-current="true"' : ''; ?> title="Core indexing and metadata">Core</button>
				<button type="button" role="tab" id="fc-wizard-tab-indexes" class="flexicorp-seg-btn<?php echo $tab === 'indexes' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="indexes" aria-selected="<?php echo $tab === 'indexes' ? 'true' : 'false'; ?>"<?php echo $tab === 'indexes' ? ' aria-current="true"' : ''; ?> title="Indexed backends, attributes, vocabulary">Index(es)</button>
				<button type="button" role="tab" id="fc-wizard-tab-dependencies" class="flexicorp-seg-btn<?php echo $tab === 'dependencies' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="dependencies" aria-selected="<?php echo $tab === 'dependencies' ? 'true' : 'false'; ?>"<?php echo $tab === 'dependencies' ? ' aria-current="true"' : ''; ?> title="<?php echo tt_fc_wizard_h($dependenciesTabTitle); ?>"><span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($dependenciesTabDotClass); ?>" aria-hidden="true"></span>Dependencies</button>
				<button type="button" role="tab" id="fc-wizard-tab-spoken" class="flexicorp-seg-btn<?php echo $tab === 'spoken' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="spoken" aria-selected="<?php echo $tab === 'spoken' ? 'true' : 'false'; ?>"<?php echo $tab === 'spoken' ? ' aria-current="true"' : ''; ?> title="<?php echo tt_fc_wizard_h($spokenTabTitle); ?>"><span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($spokenTabDotClass); ?>" aria-hidden="true"></span>Spoken Data</button>
				<button type="button" role="tab" id="fc-wizard-tab-facs" class="flexicorp-seg-btn<?php echo $tab === 'facs' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="facs" aria-selected="<?php echo $tab === 'facs' ? 'true' : 'false'; ?>"<?php echo $tab === 'facs' ? ' aria-current="true"' : ''; ?> title="<?php echo tt_fc_wizard_h($facsTabTitle); ?>"><span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($facsTabDotClass); ?>" aria-hidden="true"></span>Facsimile</button>
				<button type="button" role="tab" id="fc-wizard-tab-alignment" class="flexicorp-seg-btn<?php echo $tab === 'alignment' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="alignment" aria-selected="<?php echo $tab === 'alignment' ? 'true' : 'false'; ?>"<?php echo $tab === 'alignment' ? ' aria-current="true"' : ''; ?> title="<?php echo tt_fc_wizard_h($alignTabTitle); ?>"><span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($alignTabDotClass); ?>" aria-hidden="true"></span>Alignment</button>
				<button type="button" role="tab" id="fc-wizard-tab-geo" class="flexicorp-seg-btn<?php echo $tab === 'geo' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="geo" aria-selected="<?php echo $tab === 'geo' ? 'true' : 'false'; ?>"<?php echo $tab === 'geo' ? ' aria-current="true"' : ''; ?> title="<?php echo tt_fc_wizard_h($geoTabTitle); ?>"><span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($geoTabDotClass); ?>" aria-hidden="true"></span>Geolocation</button>
				<button type="button" role="tab" id="fc-wizard-tab-samples" class="flexicorp-seg-btn<?php echo $tab === 'samples' ? ' flexicorp-seg-btn--active' : ''; ?>" data-fc-wizard-tab="samples" aria-selected="<?php echo $tab === 'samples' ? 'true' : 'false'; ?>"<?php echo $tab === 'samples' ? ' aria-current="true"' : ''; ?> title="Per-file marker counts">XML sample</button>
			</nav>
			<p style="margin:-0.25rem 0 0.85rem 0;color:#666;font-size:.78rem;line-height:1.4;">
				<span class="fc-wizard-status-dot fc-wizard-status-dot--green" style="vertical-align:middle;margin-right:.15rem;" aria-hidden="true"></span> ok
				<span class="fc-wizard-status-dot fc-wizard-status-dot--amber" style="vertical-align:middle;margin:.15rem;" aria-hidden="true"></span> review
				<span class="fc-wizard-status-dot fc-wizard-status-dot--red" style="vertical-align:middle;margin:.15rem;" aria-hidden="true"></span> fix
				<span class="fc-wizard-status-dot fc-wizard-status-dot--blue" style="vertical-align:middle;margin:.15rem;" aria-hidden="true"></span> optional
				<span class="fc-wizard-status-dot fc-wizard-status-dot--grey" style="vertical-align:middle;margin:.15rem;" aria-hidden="true"></span> no data
			</p>
			<p style="margin:0 0 .85rem 0;max-width:48rem;color:#666;font-size:.82rem;line-height:1.45;">
				Where <strong>Wizard fix</strong> appears on a tab, use it to queue add-only merges — each click appends to <code>wizard_select_fix</code> in the URL; <strong>Automated fixes for Resources/settings.xml</strong> opens when the queue is non-empty or something was saved this session. Everything else must be fixed manually in TEITOK corpus admin and/or <code>Resources/settings.xml</code> — admin URLs and screens differ by TEITOK build, so use each row’s notes and the <strong>i</strong> (Help) link for the right place to edit, rather than a generic admin shortcut.
			</p>

			<section id="fc-wizard-panel-overview" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-overview"<?php echo $tab !== 'overview' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #cfd8dc;border-radius:7px;padding:.75rem 1rem;margin-bottom:1rem;background:#fafcfd;">
				<h4 style="margin:.1rem 0 .45rem 0;">Settings files</h4>
				<ul style="margin:.15rem 0 .35rem 1.15rem;color:#333;line-height:1.35;font-size:.92rem;">
					<li>
						<code>Resources/settings.xml</code> (corpus)
						<?php if ($canonicalSettingsPath !== '' && is_file($canonicalSettingsPath)): ?>
							<span style="color:#2e7d32;font-weight:600;"> · present</span>
						<?php elseif ($canonicalSettingsPath !== ''): ?>
							<span style="color:#9e9e9e;"> · not created yet</span>
						<?php endif; ?>
					</li>
					<li>
						<code>tmp/cqpsettings.xml</code> (merged snapshot)
						<?php if ($mergedCqpReadable): ?>
							<span style="color:#1565c0;font-weight:600;"> · present</span>
						<?php elseif ($mergedCqpPath !== ''): ?>
							<span style="color:#9e9e9e;"> · not generated yet</span>
						<?php endif; ?>
					</li>
					<li>
						<code>$sharedfolder</code>
						<?php if ($wizard_sharedfolder !== ''): ?>
							<?php if ($sharedCanonicalReadable): ?>
								<span style="color:#2e7d32;font-weight:600;"> · present</span>
							<?php else: ?>
								<span style="color:#9e9e9e;"> · not found</span>
							<?php endif; ?>
						<?php else: ?>
							<span style="color:#757575;"> · unset</span>
						<?php endif; ?>
					</li>
				</ul>
			</div>
			<div style="border:1px solid #cfd8dc;border-radius:7px;padding:.75rem 1rem;margin-bottom:1rem;background:#fafcfd;">
				<h4 style="margin:.1rem 0 .35rem 0;">Workflow order</h4>
				<p style="margin:0;color:#444;font-size:.88rem;line-height:1.45;">
					Ideally configure CQP regions, flexicorp allowlists, and NLP pipelines (e.g. parses you plan to query) <strong>before</strong> exporting or transcribing large batches of TEI.
					<?php if ((int) $t['scanned'] <= 0): ?>
						This scan found <strong>no XML files</strong> — that is not a failure; the corpus may still be in preparation.
					<?php else: ?>
						This scan sampled <strong><?php echo (int) $t['scanned']; ?></strong> file(s).
					<?php endif; ?>
					<a href="<?php echo tt_fc_wizard_h(tt_fc_wizard_help_url()); ?>#overview-workflow" target="_blank" rel="noopener noreferrer">Help</a>.
				</p>
			</div>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.6rem;margin-bottom:1rem;">
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Scanned docs</strong><br><?php echo (int) $t['scanned']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Errors</strong><br><?php echo (int) $t['errors']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Spoken markers (&lt;u&gt;)</strong><br><?php echo (int) $t['spoken_docs']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Media URLs</strong><br><?php echo (int) $t['media_docs']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Media files (local)</strong><br><?php
				$_mfc = isset($t['media_fs_checked']) ? (int) $t['media_fs_checked'] : 0;
				$_mff = isset($t['media_fs_found']) ? (int) $t['media_fs_found'] : 0;
				$_mfm = isset($t['media_fs_missing']) ? (int) $t['media_fs_missing'] : 0;
				$_mr = isset($t['media_remote_refs']) ? (int) $t['media_remote_refs'] : 0;
				if ($_mfc > 0) {
					echo (int) $_mff . '/' . (int) $_mfc . ' on disk';
					if ($_mfm > 0) {
						echo '<span style="color:#c62828;"> · ' . (int) $_mfm . ' missing</span>';
					}
				} else {
					echo '<span style="color:#757575;">—</span>';
				}
				if ($_mr > 0) {
					echo '<br><span style="font-size:.82rem;color:#555;">' . (int) $_mr . ' remote skipped</span>';
				}
				?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>media in teiHeader</strong><br><?php echo (int) $t['media_header_docs']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Timeline (sample docs)</strong><br>&lt;u&gt; @start+(@stop|@end): <?php echo (int) $t['u_timing_docs']; ?><br>&lt;tok&gt;: <?php echo isset($t['tok_timing_docs']) ? (int) $t['tok_timing_docs'] : 0; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Facsimile markers</strong><br><?php echo (int) $t['facs_docs']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Alignment docs</strong><br><?php echo (int) $t['aligned_docs']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>tuid docs</strong><br><?php echo (int) $t['tuid_docs']; ?></div>
				<div style="border:1px solid #ddd;border-radius:6px;padding:.6rem .7rem;"><strong>Geo markers</strong><br><?php echo (int) $t['geo_docs']; ?></div>
			</div>
			</section>

			<section id="fc-wizard-panel-indexes" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-indexes"<?php echo $tab !== 'indexes' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;">Index(es)</h4>
				<p style="margin:0 0 .55rem 0;color:#555;font-size:.86rem;line-height:1.45;">
					Searchable backends (flexicorp), whether the default backend maps to a reachable index for this corpus (on-disk CWB/Pando trees, FQS where used, ClickHouse/ClickQL or other configured stores), then how merged CQP settings line up with this XML sample — plus approximate distinct-value counts from <code>flexicorp freq</code> (capped). See <a href="<?php echo tt_fc_wizard_h(tt_fc_wizard_help_url()); ?>#indexes" target="_blank" rel="noopener noreferrer">Help</a>.
				</p>
				<?php if (empty($indexesData['flexicorp_loaded']) && !empty($indexesData['cli_bridge_absent'])): ?>
				<p style="margin:0 0 .55rem 0;color:#555;font-size:.86rem;line-height:1.45;">This request does not register the Python flexicorp CLI bridge (<code>tt_flexicorp_run</code>). The tables below list where the default search backend is stored, files checked under the corpus root, and (when those scripts load) how to use in-browser flexicorp API tools instead of the terminal CLI.</p>
				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Default backend (<code>defaults/flexicorp/backend</code>) — sources</h5>
				<p style="margin:0 0 .35rem 0;color:#666;font-size:.8rem;">Resolution order (first non-empty wins): <code>tt_flexicorp_backend_default()</code> (if present) → <code>getset('defaults/flexicorp/backend')</code> → merged <code>Resources/settings.xml</code> item → canonical on-disk settings (when paths differ).</p>
				<div style="overflow:auto;margin-bottom:.65rem;">
					<table class="table table-sm table-bordered" style="min-width:720px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Source</th>
								<th scope="col">Where</th>
								<th scope="col">Value</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$bds = isset($indexesData['backend_default_sources']) && is_array($indexesData['backend_default_sources']) ? $indexesData['backend_default_sources'] : array();
						foreach ($bds as $br):
						?>
							<tr>
								<td style="font-size:.85rem;"><?php echo tt_fc_wizard_h(isset($br['label']) ? (string) $br['label'] : ''); ?></td>
								<td style="font-size:.8rem;color:#555;word-break:break-word;"><?php echo tt_fc_wizard_h(isset($br['location']) ? (string) $br['location'] : ''); ?></td>
								<td><code style="font-size:.82rem;"><?php echo tt_fc_wizard_h(isset($br['value']) ? (string) $br['value'] : ''); ?></code></td>
							</tr>
						<?php endforeach; ?>
						<?php if (!count($bds)): ?>
							<tr><td colspan="3" style="color:#888;">No rows (missing <code>getset()</code> or settings paths).</td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Resolved default for this wizard</h5>
				<table class="table table-sm table-bordered" style="max-width:880px;margin:0 0 .65rem 0;">
					<tbody>
						<tr>
							<th scope="row" style="width:14rem;">Raw → canonical</th>
							<td><code><?php echo tt_fc_wizard_h($indexesData['default_backend_raw'] !== '' ? $indexesData['default_backend_raw'] : '(unset → cqp)'); ?></code>
								→ <code><?php echo tt_fc_wizard_h($indexesData['default_backend']); ?></code></td>
						</tr>
						<tr>
							<th scope="row">Backend overview / freq</th>
							<td style="color:#555;font-size:.86rem;line-height:1.45;"><?php echo tt_fc_wizard_h($indexesData['default_maps_detail']); ?> Use Corpus Search (or any TEITOK page that loads <code>flexicorp_FUNCTIONS.php</code> and its JS): those bundles typically expose an API runner and an attribute catalog for this corpus when flexicorp is wired there.</td>
						</tr>
					</tbody>
				</table>
				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Corpus-side helper files</h5>
				<p style="margin:0 0 .35rem 0;color:#666;font-size:.8rem;">Presence under the corpus root does not load them into this wizard request — it shows whether installing or linking <code>flexicorp.php</code> here would enable CLI helpers for TEITOK routes that include it.</p>
				<div style="overflow:auto;margin-bottom:.65rem;">
					<table class="table table-sm table-bordered" style="min-width:560px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Relative path</th>
								<th scope="col">Readable file</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$hsp = isset($indexesData['helper_script_probe']) && is_array($indexesData['helper_script_probe']) ? $indexesData['helper_script_probe'] : array();
						foreach ($hsp as $hp):
						?>
							<tr>
								<td><code><?php echo tt_fc_wizard_h(isset($hp['relpath']) ? (string) $hp['relpath'] : ''); ?></code></td>
								<td><?php echo !empty($hp['present']) ? '<span style="color:#2e7d32;font-weight:600;">yes</span>' : '<span style="color:#757575;">no</span>'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php elseif (empty($indexesData['flexicorp_loaded'])): ?>
					<p style="color:#9b111e;font-size:.88rem;"><?php echo tt_fc_wizard_h($indexesData['overview_error']); ?></p>
				<?php else: ?>
				<?php if ($indexesData['overview_error'] !== ''): ?>
					<p style="color:#9b111e;font-size:.88rem;margin-bottom:.65rem;"><?php echo tt_fc_wizard_h($indexesData['overview_error']); ?></p>
				<?php endif; ?>
				<?php if ($indexesData['overview_error'] === ''): ?>
				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Search backends (flexicorp overview)</h5>
				<div style="overflow:auto;margin-bottom:.75rem;">
					<table class="table table-sm table-bordered" style="min-width:720px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Route</th>
								<th scope="col">Backend</th>
								<th scope="col">Indexed / reachable</th>
								<th scope="col">Reason</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($indexesData['combo_rows'] as $cr): ?>
							<tr>
								<td><?php echo tt_fc_wizard_h(isset($cr['label']) ? (string) $cr['label'] : ''); ?></td>
								<td><code><?php echo tt_fc_wizard_h(isset($cr['backend']) ? (string) $cr['backend'] : ''); ?></code></td>
								<td><?php echo !empty($cr['available']) ? '<span style="color:#2e7d32;font-weight:600;">yes</span>' : '<span style="color:#c62828;font-weight:600;">no</span>'; ?></td>
								<td style="color:#444;font-size:.85rem;line-height:1.35;"><?php echo tt_fc_wizard_h(isset($cr['reason']) ? (string) $cr['reason'] : ''); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if (!count($indexesData['combo_rows'])): ?>
							<tr><td colspan="4" style="color:#888;">—</td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Default backend vs index</h5>
				<table class="table table-sm table-bordered" style="max-width:880px;margin:0 0 .75rem 0;">
					<tbody>
						<tr>
							<th scope="row" style="width:14rem;">TEITOK default</th>
							<td><code><?php echo tt_fc_wizard_h($indexesData['default_backend_raw'] !== '' ? $indexesData['default_backend_raw'] : '(unset → flexicorp default)'); ?></code>
								→ canonical <code><?php echo tt_fc_wizard_h($indexesData['default_backend']); ?></code></td>
						</tr>
						<tr>
							<th scope="row">Maps to reachable index</th>
							<td><?php
							if ($indexesData['default_maps_index'] === null) {
								echo '<span style="color:#757575;">unknown</span>';
							} elseif ($indexesData['default_maps_index']) {
								echo '<span style="color:#2e7d32;font-weight:600;">yes</span>';
							} else {
								echo '<span style="color:#c62828;font-weight:600;">no</span>';
							}
							?>
								<span style="color:#555;font-size:.85rem;"> · <?php echo tt_fc_wizard_h($indexesData['default_maps_detail']); ?></span></td>
						</tr>
					</tbody>
				</table>

				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Primary index (<code>flexicorp info corpus</code>)</h5>
				<?php if ($indexesData['primary_info_error'] !== ''): ?>
					<p style="color:#c62828;font-size:.86rem;margin:0 0 .5rem 0;"><?php echo tt_fc_wizard_h($indexesData['primary_info_error']); ?></p>
				<?php endif; ?>
				<?php
				$pi = isset($indexesData['primary_info']) && is_array($indexesData['primary_info']) ? $indexesData['primary_info'] : array();
				$piTok = isset($pi['tokens_count']) ? $pi['tokens_count'] : '';
				$piCorp = isset($pi['corpus']) ? (string) $pi['corpus'] : '';
				$piReg = isset($pi['registry']) ? (string) $pi['registry'] : '';
				?>
				<ul style="margin:0 0 .65rem 1rem;color:#444;font-size:.86rem;line-height:1.45;">
					<?php if ($piCorp !== ''): ?><li>Corpus: <code><?php echo tt_fc_wizard_h($piCorp); ?></code></li><?php endif; ?>
					<?php if ($piReg !== ''): ?><li>Registry: <code><?php echo tt_fc_wizard_h($piReg); ?></code></li><?php endif; ?>
					<?php if ($piTok !== '' && $piTok !== null): ?><li>Tokens (estimate): <strong><?php echo tt_fc_wizard_h((string) $piTok); ?></strong></li><?php endif; ?>
					<?php if (!count($pi) && $indexesData['primary_info_error'] === ''): ?><li style="color:#888;">No fields returned.</li><?php endif; ?>
				</ul>
				<?php endif; ?>

				<?php if (!empty($indexesData['flexicorp_loaded'])): ?>
				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Vocabulary size (distinct types, freq)</h5>
				<?php if ($indexesData['vocab_note'] !== ''): ?>
					<p style="margin:0 0 .4rem 0;color:#666;font-size:.8rem;"><?php echo tt_fc_wizard_h($indexesData['vocab_note']); ?></p>
				<?php endif; ?>
				<div style="overflow:auto;margin-bottom:.45rem;">
					<table class="table table-sm table-bordered" style="min-width:560px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Token attribute</th>
								<th scope="col">Distinct types (approx.)</th>
								<th scope="col">Note</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($indexesData['vocab_token'] as $vr): ?>
							<tr>
								<td><code><?php echo tt_fc_wizard_h(isset($vr['name']) ? (string) $vr['name'] : ''); ?></code></td>
								<td><?php echo tt_fc_wizard_h(isset($vr['types']) ? (string) $vr['types'] : ''); ?></td>
								<td style="font-size:.82rem;color:#555;"><?php echo tt_fc_wizard_h(isset($vr['note']) ? (string) $vr['note'] : ''); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if (!count($indexesData['vocab_token'])): ?>
							<tr><td colspan="3" style="color:#888;font-size:.85rem;">—</td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<div style="overflow:auto;">
					<table class="table table-sm table-bordered" style="min-width:560px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Structural / registry field</th>
								<th scope="col">Distinct types (approx.)</th>
								<th scope="col">Note</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($indexesData['vocab_region'] as $vr): ?>
							<tr>
								<td><code><?php echo tt_fc_wizard_h(isset($vr['name']) ? (string) $vr['name'] : ''); ?></code></td>
								<td><?php echo tt_fc_wizard_h(isset($vr['types']) ? (string) $vr['types'] : ''); ?></td>
								<td style="font-size:.82rem;color:#555;"><?php echo tt_fc_wizard_h(isset($vr['note']) ? (string) $vr['note'] : ''); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if (!count($indexesData['vocab_region'])): ?>
							<tr><td colspan="3" style="color:#888;font-size:.85rem;">— (needs <code>info corpus</code> struct attributes or non-CWB backend)</td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
				<?php endif; ?>

				<h5 style="margin:.65rem 0 .3rem 0;font-size:.92rem;">Indexed settings vs XML sample</h5>
				<p style="margin:0 0 .35rem 0;color:#666;font-size:.8rem;">Merged / canonical CQP lists vs attributes and region tags seen in the sample scan (does not require the flexicorp Python CLI).</p>
				<div style="overflow:auto;margin-bottom:.65rem;">
					<table class="table table-sm table-bordered" style="min-width:680px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Layer</th>
								<th scope="col">Indexed (settings)</th>
								<th scope="col">XML sample</th>
								<th scope="col">Gaps</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>Token p-attributes</td>
								<td style="font-size:.85rem;"><code><?php echo tt_fc_wizard_h(implode(', ', $indexesData['pattr_keys'])); ?></code></td>
								<td style="font-size:.85rem;"><code><?php echo tt_fc_wizard_h(implode(', ', $indexesData['token_xml_attrs'])); ?></code></td>
								<td style="font-size:.85rem;">
									<?php if (count($indexesData['token_in_xml_not_indexed'])): ?>
										<span style="color:#c62828;">in XML, not indexed:</span> <code><?php echo tt_fc_wizard_h(implode(', ', $indexesData['token_in_xml_not_indexed'])); ?></code><br>
									<?php endif; ?>
									<?php if (count($indexesData['token_indexed_not_in_sample'])): ?>
										<span style="color:#757575;">indexed, not in sample:</span> <code><?php echo tt_fc_wizard_h(implode(', ', $indexesData['token_indexed_not_in_sample'])); ?></code>
									<?php endif; ?>
									<?php if (!count($indexesData['token_in_xml_not_indexed']) && !count($indexesData['token_indexed_not_in_sample'])): ?>
										<span style="color:#2e7d32;">none flagged</span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td>Regions (s-attributes / elements)</td>
								<td style="font-size:.85rem;"><code><?php echo tt_fc_wizard_h(implode(', ', $indexesData['sattr_keys'])); ?></code></td>
								<td style="font-size:.85rem;"><code><?php echo tt_fc_wizard_h(implode(', ', $indexesData['region_elems_sample'])); ?></code></td>
								<td style="font-size:.85rem;">
									<?php if (count($indexesData['region_in_xml_not_indexed'])): ?>
										<span style="color:#c62828;">elements in sample, no s-attr key:</span> <code><?php echo tt_fc_wizard_h(implode(', ', $indexesData['region_in_xml_not_indexed'])); ?></code>
									<?php else: ?>
										<span style="color:#2e7d32;">none flagged</span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
			</section>

			<section id="fc-wizard-panel-dependencies" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-dependencies"<?php echo $tab !== 'dependencies' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .6rem;">
					<span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($dependenciesTabDotClass); ?>" aria-hidden="true"></span>
					<span>Dependencies (UD)</span>
				</h4>
				<p style="margin:0 0 .65rem 0;color:#444;font-size:.88rem;line-height:1.45;">
					<strong>Dependency trees &amp; search:</strong> with <code>deprel</code>/<code>head</code> indexed you can draw UD-style trees, see them alongside hits in flexicorp / TEITOK, and query dependency labels in <abbr title="Corpus Query Protocol">CQP</abbr> / flexicorp. This tab lists only dependency-related checks (not PHP modules). Indexed-backend tables and token vocab: <button type="button" class="fc-wizard-tab-inline-link" onclick="fcWizardGoTab('indexes');">Index(es)</button>. <a href="<?php echo tt_fc_wizard_h(tt_fc_wizard_help_url()); ?>#dependencies" target="_blank" rel="noopener noreferrer">Help</a> (<code>deptree.php</code> / native TEITOK views rely on the same CQP + defaults wiring summarized here).
				</p>
				<?php if (!empty($dependenciesPrepBannerShow)): ?>
				<p style="margin:0 0 .55rem 0;padding:.45rem .6rem;border:1px solid #90caf9;background:#e3f2fd;border-radius:6px;font-size:.82rem;color:#0d47a1;line-height:1.45;">
					<strong>Preparing for dependencies:</strong> this sample did not show <code>@deprel</code>/<code>@head</code> on tokens, and at least one dependency-related setting still needs wiring (see rows below). If your corpus is already UD-annotated, raise the sample size so the scan can hit annotated files; then reindex after TEI and CQP lists match.
				</p>
				<?php endif; ?>
				<div style="overflow:auto;">
					<table class="table table-sm table-bordered" style="min-width:880px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Component</th>
								<th scope="col">XML (sample / TEI)</th>
								<th scope="col">Project / index</th>
								<th scope="col">Status</th>
								<th scope="col">Notes</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$dependenciesSectionLast = '';
						foreach ($dependenciesUnifiedRows as $dr):
							$depSec = tt_fc_wizard_dependencies_row_section($dr);
							if ($depSec !== $dependenciesSectionLast):
								$dependenciesSectionLast = $depSec;
								?>
							<tr class="fc-wizard-spoken-section">
								<td colspan="5" style="background:#f4f6f8;font-weight:600;font-size:0.82rem;padding:0.38rem 0.45rem;border-top:1px solid #dee3e8;color:#37474f;"><?php echo tt_fc_wizard_h($depSec); ?></td>
							</tr>
								<?php
							endif;
							$dst = isset($dr['status']) ? strtolower((string) $dr['status']) : '';
							$ia = isset($dr['info_anchor']) ? trim((string) $dr['info_anchor']) : '';
							$helpBase = tt_fc_wizard_help_url();
							$infoHref = ($ia !== '') ? (preg_replace('/#.*$/', '', $helpBase) . '#' . $ia) : $helpBase;
							?>
							<tr>
								<td><?php echo tt_fc_wizard_h(isset($dr['label']) ? (string) $dr['label'] : ''); ?></td>
								<td style="color:#333;font-size:.88rem;line-height:1.4;"><?php echo tt_fc_wizard_h(isset($dr['xml']) ? (string) $dr['xml'] : ''); ?></td>
								<td style="color:#333;font-size:.88rem;line-height:1.4;"><?php echo tt_fc_wizard_h(isset($dr['project']) ? (string) $dr['project'] : ''); ?></td>
								<td><?php
								if ($dst === 'ok') {
									echo '<span style="color:#2e7d32;font-weight:600;">OK</span>';
								} elseif ($dst === 'irrelevant') {
									echo '<span style="color:#9e9e9e;font-weight:600;">n/a</span>';
								} elseif ($dst === 'optional') {
									echo '<span style="color:#ef6c00;font-weight:600;">Review</span>';
								} elseif ($dst === 'wrong') {
									echo '<span style="color:#c62828;font-weight:600;">Fix</span>';
								} else {
									echo tt_fc_wizard_h($dst !== '' ? $dst : '—');
								}
								?></td>
								<td style="line-height:1.4;">
									<div style="overflow:hidden;">
										<?php if ($ia !== ''): ?>
										<a class="fc-wizard-help-icon" href="<?php echo tt_fc_wizard_h($infoHref); ?>" target="_blank" rel="noopener noreferrer" aria-label="Help: <?php echo tt_fc_wizard_h(isset($dr['label']) ? (string) $dr['label'] : 'this row'); ?>" title="Help"><span aria-hidden="true">i</span></a>
										<?php endif; ?>
										<div style="color:#222;font-size:.88rem;"><?php echo tt_fc_wizard_h(isset($dr['summary']) ? (string) $dr['summary'] : ''); ?></div>
										<?php if (!empty($dr['detail'])): ?>
										<div style="color:#555;font-size:.82rem;margin-top:.2rem;"><?php echo tt_fc_wizard_h((string) $dr['detail']); ?></div>
										<?php endif; ?>
										<?php
										$depRowId = isset($dr['id']) ? trim((string) $dr['id']) : '';
										$depSettingsFix = !empty($dr['settings_fix']);
										$depWizardCode = '';
										if ($depSettingsFix) {
											if ($depRowId === 'dep-cqp-pattr' && in_array($dst, array('wrong', 'optional'), true) && !empty($depsFixCqpPattrsMerge)) {
												$depWizardCode = 'cqp_deprel_head';
											} elseif ($depRowId === 'dep-s-cqp' && $dst === 'wrong' && !empty($depsFixCqpSRegionMerge)) {
												$depWizardCode = 'cqp_s_region';
											} elseif ($depRowId === 'dep-s-tuid-cqp' && in_array($dst, array('wrong', 'optional'), true) && !empty($depsFixCqpSTuidMerge)) {
												$depWizardCode = 'cqp_s_tuid';
											} elseif ($depRowId === 'dep-xmlfile-pattr' && $dst === 'optional' && !empty($depsFixXmlfileDeprelHeadMerge)) {
												$depWizardCode = 'xmlfile_deprel_head';
											} elseif ($depRowId === 'dep-morph' && $dst === 'optional' && !empty($depsFixCqpMorphMerge)) {
												$depWizardCode = 'cqp_ud_morph';
											}
										}
										$depWizardDone = ($depWizardCode !== '' && tt_fc_wizard_fix_applied_has($projectRoot, $depWizardCode));
										$showDepWizardBtn = $depSettingsFix && in_array($dst, array('optional', 'wrong'), true)
											&& $depWizardCode !== '' && !$depWizardDone;
										?>
										<?php if ($depWizardDone && $depSettingsFix && in_array($dst, array('optional', 'wrong'), true) && $depWizardCode !== ''): ?>
										<div style="margin-top:.35rem;font-size:.8rem;color:#2e7d32;">Automated fix saved this session — see <strong>Automated fixes for Resources/settings.xml</strong> below.</div>
										<?php endif; ?>
										<?php if ($showDepWizardBtn): ?>
										<div style="margin-top:.35rem;clear:both;display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;">
											<button type="button" class="btn btn-sm btn-outline-primary fc-wizard-open-automated-fix" data-fc-wizard-fix="<?php echo tt_fc_wizard_h((string) $depWizardCode); ?>" data-fc-wizard-tab="dependencies">Wizard fix</button>
										</div>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			</section>

			<section id="fc-wizard-panel-spoken" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-spoken"<?php echo $tab !== 'spoken' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .6rem;">
					<span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($spokenTabDotClass); ?>" aria-hidden="true"></span>
					<span>Spoken data</span>
				</h4>
				<p style="margin:0 0 .65rem 0;color:#444;font-size:.88rem;line-height:1.45;">
					Spoken TEI lets audio match text at utterance or token level. With the right project + index setup you get playback above the text, WaveSurfer sync, and audio in search hits; video sources show video as well. CQP / backend wiring is summarized on <button type="button" class="fc-wizard-tab-inline-link" onclick="fcWizardGoTab('indexes');">Index(es)</button>. Longer background: <a href="<?php echo tt_fc_wizard_h(tt_fc_wizard_help_url()); ?>#spoken-intro" target="_blank" rel="noopener noreferrer">Help</a>.
				</p>
				<?php if (!empty($spokenPrepAudio)): ?>
				<p style="margin:0 0 .55rem 0;padding:.45rem .6rem;border:1px solid #90caf9;background:#e3f2fd;border-radius:6px;font-size:.82rem;color:#0d47a1;line-height:1.45;">
					<strong>Preparing for spoken/audio:</strong> this scan has XML but no ⟨u⟩ or media in TEI yet. Use the rows below to configure <strong>CQP utterance region</strong>, <strong>search context regions</strong>, <strong>Audio/</strong> and uploads, and WaveSurfer defaults — then add transcripts and media.
				</p>
				<?php endif; ?>
				<?php if (!empty($t['media_fs_missing']) && (int) $t['media_fs_missing'] > 0): ?>
				<p style="margin:0 0 .55rem 0;padding:.45rem .6rem;border:1px solid #ffcdd2;background:#fff8f8;border-radius:6px;font-size:.82rem;color:#8d2f2f;line-height:1.45;">
					<strong>Media paths:</strong> <?php echo (int) $t['media_fs_missing']; ?> non-remote <code>media/@url</code> reference(s) in this sample did not resolve to a file under the corpus tree, <code>Audio/</code>, or beside the XML file. Remote <code>http(s)</code> URLs are not checked on disk.
				</p>
				<?php endif; ?>
				<div style="overflow:auto;">
					<table id="rollovertable" class="table table-sm table-bordered">
						<thead>
							<tr>
								<th scope="col">Component</th>
								<th scope="col">XML (expect / sample)</th>
								<th scope="col">Project / index</th>
								<th scope="col">Status</th>
								<th scope="col">Notes</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$spokenSectionLast = '';
						foreach ($spokenUnifiedRows as $sr):
							$secCurrent = tt_fc_wizard_spoken_row_section($sr);
							if ($secCurrent !== $spokenSectionLast):
								$spokenSectionLast = $secCurrent;
								?>
							<tr class="fc-wizard-spoken-section">
								<td colspan="5" style="background:#f4f6f8;font-weight:600;font-size:0.82rem;padding:0.38rem 0.45rem;border-top:1px solid #dee3e8;color:#37474f;"><?php echo tt_fc_wizard_h($secCurrent); ?></td>
							</tr>
								<?php
							endif;
							?>
							<?php
							$sst = isset($sr['status']) ? strtolower((string) $sr['status']) : '';
							$ia = isset($sr['info_anchor']) ? trim((string) $sr['info_anchor']) : '';
							$rowIdForAdmin = isset($sr['id']) ? trim((string) $sr['id']) : '';
							$helpBase = tt_fc_wizard_help_url();
							$infoHref = ($ia !== '') ? (preg_replace('/#.*$/', '', $helpBase) . '#' . $ia) : $helpBase;
							?>
							<tr>
								<td><?php echo tt_fc_wizard_h(isset($sr['label']) ? (string) $sr['label'] : ''); ?></td>
								<td style="color:#333;font-size:.88rem;line-height:1.4;"><?php echo tt_fc_wizard_h(isset($sr['xml']) ? (string) $sr['xml'] : ''); ?></td>
								<td style="color:#333;font-size:.88rem;line-height:1.4;"><?php echo tt_fc_wizard_h(isset($sr['project']) ? (string) $sr['project'] : ''); ?></td>
								<td><?php
								if ($sst === 'ok') {
									echo '<span style="color:#2e7d32;font-weight:600;">OK</span>';
								} elseif ($sst === 'irrelevant') {
									echo '<span style="color:#9e9e9e;font-weight:600;">n/a</span>';
								} elseif ($sst === 'optional') {
									echo '<span style="color:#ef6c00;font-weight:600;">Review</span>';
								} elseif ($sst === 'wrong') {
									echo '<span style="color:#c62828;font-weight:600;">Fix</span>';
								} elseif ($sst === 'info') {
									echo '<span style="color:#1565c0;font-weight:600;">Info</span>';
								} else {
									echo tt_fc_wizard_h($sst !== '' ? $sst : '—');
								}
								?></td>
								<td style="line-height:1.4;">
									<div style="overflow:hidden;">
										<?php if ($ia !== ''): ?>
										<a class="fc-wizard-help-icon" href="<?php echo tt_fc_wizard_h($infoHref); ?>" target="_blank" rel="noopener noreferrer" aria-label="Help: <?php echo tt_fc_wizard_h(isset($sr['label']) ? (string) $sr['label'] : 'this row'); ?>" title="Help"><span aria-hidden="true">i</span></a>
										<?php endif; ?>
										<div style="color:#222;font-size:.88rem;"><?php echo tt_fc_wizard_h(isset($sr['summary']) ? (string) $sr['summary'] : ''); ?></div>
										<?php if (!empty($sr['detail'])): ?>
										<div style="color:#555;font-size:.82rem;margin-top:.2rem;"><?php echo tt_fc_wizard_h((string) $sr['detail']); ?></div>
										<?php endif; ?>
										<?php
										$settingsFixRow = !empty($sr['settings_fix']);
										$wizardFixCode = '';
										if ($settingsFixRow) {
											if ($rowIdForAdmin === 'spoken-allowlist' && !empty($spokenFixAllowlistMerge) && ($sst === 'wrong' || $sst === 'optional')) {
												$wizardFixCode = 'allowlist_u';
											} elseif ($sst === 'wrong') {
												if ($rowIdForAdmin === 'spoken-cqp-u' && !empty($spokenFixCqpUMerge)) {
													$wizardFixCode = 'cqp_u_stub';
												} elseif ($rowIdForAdmin === 'spoken-wavesurfer-allttag' && !empty($spokenFixWavesurferUtt)) {
													$wizardFixCode = 'wavesurfer_utt';
												} elseif ($rowIdForAdmin === 'spoken-audio-dir' && !empty($spokenFixFilesAudio)) {
													$wizardFixCode = 'files_audio';
												}
											} elseif ($sst === 'optional') {
												if ($rowIdForAdmin === 'spoken-wavesurfer-allttag' && !empty($spokenFixWavesurferTok)) {
													$wizardFixCode = 'wavesurfer_tok';
												} elseif ($rowIdForAdmin === 'spoken-media' && !empty($spokenFixMediaUAudio)) {
													$wizardFixCode = 'cqp_u_audio';
												} elseif ($rowIdForAdmin === 'spoken-audio-dir' && !empty($spokenFixFilesAudio)) {
													$wizardFixCode = 'files_audio';
												}
											}
										}
										$wizardFixDoneSession = ($wizardFixCode !== '' && tt_fc_wizard_fix_applied_has($projectRoot, $wizardFixCode));
										$showRowActions = $settingsFixRow && in_array($sst, array('optional', 'wrong'), true)
											&& ($wizardFixCode !== '') && !$wizardFixDoneSession;
										?>
										<?php if ($wizardFixDoneSession && $settingsFixRow && in_array($sst, array('optional', 'wrong'), true) && $wizardFixCode !== ''): ?>
										<div style="margin-top:.35rem;font-size:.8rem;color:#2e7d32;">Automated fix saved this session — see <strong>Automated fixes for Resources/settings.xml</strong> below.</div>
										<?php endif; ?>
										<?php if ($showRowActions): ?>
										<div style="margin-top:.35rem;clear:both;display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;">
											<?php if ($wizardFixCode !== ''): ?>
											<button type="button" class="btn btn-sm btn-outline-primary fc-wizard-open-automated-fix" data-fc-wizard-fix="<?php echo tt_fc_wizard_h((string) $wizardFixCode); ?>" data-fc-wizard-tab="spoken">Wizard fix</button>
											<?php endif; ?>
										</div>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p style="margin:.65rem 0 0 0;color:#666;font-size:.8rem;">
					Scan: <?php echo (int) $t['scanned']; ?> files · <code>&lt;u&gt;</code> <?php echo (int) $t['spoken_docs']; ?> · media/@url <?php echo (int) $t['media_docs']; ?><?php if (!empty($t['media_fs_checked'])): ?> · local media files <?php echo (int) $t['media_fs_found']; ?>/<?php echo (int) $t['media_fs_checked']; ?> on disk<?php if (!empty($t['media_fs_missing'])): ?><span style="color:#c62828;"> (<?php echo (int) $t['media_fs_missing']; ?> missing)</span><?php endif; ?><?php endif; ?> · timed <code>&lt;u&gt;</code> <?php echo (int) $t['u_timing_docs']; ?> · timed &lt;tok&gt; <?php echo isset($t['tok_timing_docs']) ? (int) $t['tok_timing_docs'] : 0; ?> · Audio/ <?php echo $audioFolderPresent ? 'yes' : 'no'; ?> · files/audio <?php echo (!empty($audioUploadInfo['configured'])) ? 'yes' : 'no'; ?> · CQP utterance <?php
						$_uw = tt_fc_wizard_cqp_utterance_region_wired(
							(isset($cqpSattrParse['keys']) && is_array($cqpSattrParse['keys'])) ? $cqpSattrParse['keys'] : array()
						);
						echo !empty($_uw['wired']) ? ('ok (' . ($_uw['hint'] !== '' ? $_uw['hint'] : 'wired') . ')') : 'missing';
					?>
				</p>
				<?php if (count($spokenFooterNotes) > 0): ?>
				<div style="border:1px dashed #cfd8dc;border-radius:6px;padding:.5rem .65rem;margin-top:.55rem;background:#fafafa;">
					<h5 style="margin:0 0 .35rem 0;font-size:.85rem;">Corpus &amp; TEI prerequisites</h5>
					<p style="margin:0 0 .35rem 0;font-size:.76rem;color:#666;">These are not spoken-audio checks; they explain missing index or TEI inputs. Full backend/index detail: <button type="button" class="fc-wizard-tab-inline-link" onclick="fcWizardGoTab('indexes');">Index(es)</button>.</p>
					<ul style="margin:0;padding-left:1.1rem;font-size:.8rem;line-height:1.45;color:#555;">
					<?php foreach ($spokenFooterNotes as $sfn): ?>
						<li><?php echo tt_fc_wizard_h((string) $sfn); ?></li>
					<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
			</div>
			</section>

			<section id="fc-wizard-panel-core" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-core"<?php echo $tab !== 'core' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;">Header metadata</h4>
				<p style="margin:.2rem 0 .5rem 0;color:#444;">Detected header elements (sample):</p>
				<p style="margin:.2rem 0 .6rem 0;">
					<?php if (count($headerElemTop)): ?>
						<code><?php echo tt_fc_wizard_h(implode(', ', $headerElemTop)); ?></code>
					<?php else: ?>
						<span style="color:#9b111e;">No teiHeader elements detected in sampled files.</span>
					<?php endif; ?>
				</p>
				<p style="margin:.2rem 0 .4rem 0;color:#444;">Detected header attributes (sample):</p>
				<p style="margin:.2rem 0 .6rem 0;">
					<?php if (count($headerAttrTop)): ?>
						<code><?php echo tt_fc_wizard_h(implode(', ', $headerAttrTop)); ?></code>
					<?php else: ?>
						<span style="color:#555;">No header attributes detected.</span>
					<?php endif; ?>
				</p>
				<p style="margin:.25rem 0 0 0;color:#666;font-size:.8rem;">Wire fields to TEITOK metadata — Help.</p>
			</div>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;">Token data</h4>
				<p style="margin:.2rem 0 .5rem 0;color:#444;">Detected token attributes (sample):</p>
				<p style="margin:.2rem 0 .6rem 0;">
					<?php if (count($tokenAttrTop)): ?>
						<code><?php echo tt_fc_wizard_h(implode(', ', $tokenAttrTop)); ?></code>
					<?php else: ?>
						<span style="color:#9b111e;">No token attributes detected from &lt;tok&gt; in sample.</span>
					<?php endif; ?>
				</p>
				<p style="margin:.25rem 0 0 0;color:#666;font-size:.8rem;">Align names across files; match CQP index — Help.</p>
			</div>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;">Indexed data for the corpus</h4>
				<p style="margin:.2rem 0 .6rem 0;color:#444;">
					Potential region levels seen in sample:
					<?php if (count($regionElemTop)): ?>
						<code><?php echo tt_fc_wizard_h(implode(', ', $regionElemTop)); ?></code>
					<?php else: ?>
						<span style="color:#555;">(none detected from default region probe)</span>
					<?php endif; ?>
				</p>
				<p style="margin:.25rem 0 0 0;color:#666;font-size:.8rem;">p-attrs vs regions vs scopes — Help.</p>
			</div>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;">Other admin areas</h4>
				<p style="margin:.2rem 0 0 0;color:#666;font-size:.8rem;line-height:1.45;">Uploads, search defaults, UI modules, performance, access — Help.</p>
			</div>
			</section>

			<section id="fc-wizard-panel-facs" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-facs"<?php echo $tab !== 'facs' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .6rem;">
					<span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($facsTabDotClass); ?>" aria-hidden="true"></span>
					<span>Facsimile</span>
				</h4>
				<p style="margin:.2rem 0 0 0;color:#666;font-size:.82rem;"><code>@facs</code>, stable image URIs — Help.</p>
			</div>
			</section>

			<section id="fc-wizard-panel-alignment" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-alignment"<?php echo $tab !== 'alignment' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .6rem;">
					<span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($alignTabDotClass); ?>" aria-hidden="true"></span>
					<span>Alignment (XML + CQP)</span>
				</h4>
				<p style="margin:.15rem 0 .45rem 0;color:#555;font-size:.85rem;">
					XML alignment ids ↔ <code>Resources/settings.xml</code> CQP entries → reindex. Default backend, index reachability, and CQP/sample alignment are on <button type="button" class="fc-wizard-tab-inline-link" onclick="fcWizardGoTab('indexes');">Index(es)</button>. Runtime behaviour: <a href="<?php echo tt_fc_wizard_h(tt_fc_wizard_help_url()); ?>" target="_blank" rel="noopener noreferrer">Help</a>.
				</p>
				<p style="margin:.2rem 0 .4rem 0;font-size:.85rem;">
					<code>Resources/settings.xml</code>
					<?php if ($settingsXmlPath !== ''): ?>
						<span style="color:#2e7d32;font-weight:600;">readable</span>
					<?php else: ?>
						<span style="color:#9b111e;">missing</span>
					<?php endif; ?>
				</p>
				<h5 style="margin:.65rem 0 .25rem 0;font-size:.9rem;">Sample counts</h5>
				<ul style="margin:.15rem 0 .45rem 1.1rem;font-size:.85rem;line-height:1.35;">
					<li><code>s/@tuid</code> docs: <strong><?php echo (int) $t['s_tuid_docs']; ?></strong><?php if ($need_s_tuid): ?> · needs <code>s_tuid</code><?php endif; ?></li>
					<li><code>tok|w/@tuid</code> docs: <strong><?php echo (int) $t['tok_tuid_docs']; ?></strong><?php if ($need_tok_tuid): ?> · needs token p-attr<?php endif; ?></li>
					<li><code>text/@text_tuid</code> docs: <strong><?php echo (int) $t['text_tuid_docs']; ?></strong><?php if ($need_text_tuid_xml): ?> · optional layer<?php endif; ?></li>
				</ul>
				<?php if ($text_tuid_parallel_nudge && empty($cqpAlign['sattr_text_tuid'])): ?>
				<div style="border:1px dashed #c9a227;background:#fffbeb;border-radius:6px;padding:.45rem .65rem;margin:.45rem 0 .65rem 0;font-size:.82rem;">
					Consider <code>text_tuid</code> when parallel texts share a logical id — use <strong>Wizard fix</strong> below when alignment merge is offered; optional checkbox appears only there.
				</div>
				<?php endif; ?>
				<h5 style="margin:.55rem 0 .25rem 0;font-size:.9rem;">CQP wiring</h5>
				<ul style="margin:.2rem 0 .6rem 1.2rem;">
					<li>P-attribute for token alignment id (<code>tuid</code> or <code>p_tuid</code>): <?php echo (!empty($cqpAlign['pattr_tuid']) || !empty($cqpAlign['pattr_p_tuid'])) ? '<strong style="color:#2a7d2e;">present</strong>' : '<strong style="color:#9b111e;">missing</strong>'; ?>
						<?php if (!empty($cqpAlign['pattr_tuid']) && !empty($cqpAlign['pattr_tuid_xpath'])): ?> · <code>tuid</code> xpath <code><?php echo tt_fc_wizard_h($cqpAlign['pattr_tuid_xpath']); ?></code><?php endif; ?>
						<?php if (!empty($cqpAlign['pattr_p_tuid']) && !empty($cqpAlign['pattr_p_tuid_xpath'])): ?> · <code>p_tuid</code> xpath <code><?php echo tt_fc_wizard_h($cqpAlign['pattr_p_tuid_xpath']); ?></code><?php endif; ?>
					</li>
					<li>S-attribute <code>s_tuid</code> on sentence region: <?php echo !empty($cqpAlign['sattr_s_tuid']) ? '<strong style="color:#2a7d2e;">present</strong>' : '<strong style="color:#9b111e;">missing</strong>'; ?>
						<?php if (!empty($cqpAlign['sattr_s_tuid_xpath'])): ?> · xpath <code><?php echo tt_fc_wizard_h($cqpAlign['sattr_s_tuid_xpath']); ?></code><?php endif; ?>
						<?php if (!empty($cqpAlign['sattr_sentence_regions'])): ?> · regions: <code><?php echo tt_fc_wizard_h(implode(', ', array_unique($cqpAlign['sattr_sentence_regions']))); ?></code><?php endif; ?>
					</li>
					<li>S-attribute <code>text_tuid</code> on text region (optional): <?php echo !empty($cqpAlign['sattr_text_tuid']) ? '<strong style="color:#2a7d2e;">present</strong>' : '<strong style="color:#666;">not configured</strong>'; ?>
						<?php if (!empty($cqpAlign['sattr_text_tuid_xpath'])): ?> · xpath <code><?php echo tt_fc_wizard_h($cqpAlign['sattr_text_tuid_xpath']); ?></code><?php endif; ?>
					</li>
				</ul>
				<?php if (!$cqpAlign['ok'] && !empty($cqpAlign['error'])): ?>
					<p style="color:#9b111e;">Settings parse: <?php echo tt_fc_wizard_h($cqpAlign['error']); ?></p>
				<?php endif; ?>
				<?php if ($settingsXmlPath !== '' && !$settings_writable): ?>
					<p style="color:#9b111e;font-size:.85rem;">Settings not writable — fix permissions or edit offline.</p>
				<?php elseif ($wizardAlignMergeOffer && !tt_fc_wizard_fix_applied_has($projectRoot, 'alignment_merge')): ?>
				<p style="margin:.65rem 0 0 0;font-size:.82rem;color:#555;line-height:1.45;display:flex;flex-wrap:wrap;align-items:center;gap:.45rem;">
					<span>If you want this sample’s alignment ids reflected in CQP settings:</span>
					<button type="button" class="btn btn-sm btn-outline-primary fc-wizard-open-automated-fix" data-fc-wizard-fix="alignment_merge" data-fc-wizard-tab="alignment">Wizard fix</button>
					<span style="color:#666;">Loads <strong>Automated fixes</strong> below with only the alignment merge for this scan.</span>
				</p>
				<?php elseif ($wizardAlignMergeOffer && tt_fc_wizard_fix_applied_has($projectRoot, 'alignment_merge')): ?>
				<p style="margin:.65rem 0 0 0;font-size:.82rem;color:#2e7d32;line-height:1.45;">Alignment automated merge was saved this session — see <strong>Automated fixes for Resources/settings.xml</strong> below.</p>
				<?php elseif ($settings_writable && $alignCqpWired && !empty($cqpAlign['sattr_text_tuid'])): ?>
				<p style="margin:.6rem 0 0 0;color:#2a7d2e;font-size:.85rem;">CQP alignment entries complete for this sample (incl. <code>text_tuid</code>). Reindex after edits.</p>
				<?php elseif ($settings_writable && $alignCqpWired && empty($cqpAlign['sattr_text_tuid'])): ?>
				<p style="margin:.6rem 0 0 0;color:#2a7d2e;font-size:.85rem;">Core alignment wiring ok. Add <code>text_tuid</code> via merge if needed.</p>
				<?php endif; ?>
			</div>
			</section>

			<section id="fc-wizard-panel-geo" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-geo"<?php echo $tab !== 'geo' ? ' hidden' : ''; ?>>
			<div style="border:1px solid #e2e2e2;border-radius:7px;padding:.8rem 1rem;margin-bottom:.7rem;">
				<h4 style="margin:.1rem 0 .45rem 0;display:flex;align-items:center;flex-wrap:wrap;gap:.35rem .6rem;">
					<span class="fc-wizard-status-dot <?php echo tt_fc_wizard_h($geoTabDotClass); ?>" aria-hidden="true"></span>
					<span>Geolocation</span>
				</h4>
				<p style="margin:0 0 .65rem 0;color:#444;font-size:.88rem;line-height:1.45;">
					<strong>Maps &amp; search:</strong> with geolocation you can display documents on the map and visualize search results on the map as well. The checklist below follows what flexicorp / TEITOK expect from corpus <code>&lt;geomap&gt;</code> plus indexed structural fields (<code>text_geo</code>, optionally <code>text_place</code>) and TEI signals in the sample. Backend attribute lists: <button type="button" class="fc-wizard-tab-inline-link" onclick="fcWizardGoTab('indexes');">Index(es)</button>. Runtime details: <a href="<?php echo tt_fc_wizard_h(tt_fc_wizard_help_url()); ?>#geolocation" target="_blank" rel="noopener noreferrer">Help</a> (and <code>flexicorp_functions.php</code> / <code>advanced_maps.js</code>).
				</p>
				<div style="overflow:auto;">
					<table class="table table-sm table-bordered" style="min-width:880px;margin:0;">
						<thead>
							<tr>
								<th scope="col">Component</th>
								<th scope="col">XML (sample / TEI)</th>
								<th scope="col">Project / index</th>
								<th scope="col">Status</th>
								<th scope="col">Notes</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$geoSectionLast = '';
						foreach ($geoUnifiedRows as $gr):
							$geoSec = tt_fc_wizard_geo_row_section($gr);
							if ($geoSec !== $geoSectionLast):
								$geoSectionLast = $geoSec;
								?>
							<tr class="fc-wizard-spoken-section">
								<td colspan="5" style="background:#f4f6f8;font-weight:600;font-size:0.82rem;padding:0.38rem 0.45rem;border-top:1px solid #dee3e8;color:#37474f;"><?php echo tt_fc_wizard_h($geoSec); ?></td>
							</tr>
								<?php
							endif;
							$gst = isset($gr['status']) ? strtolower((string) $gr['status']) : '';
							$ia = isset($gr['info_anchor']) ? trim((string) $gr['info_anchor']) : '';
							$helpBase = tt_fc_wizard_help_url();
							$infoHref = ($ia !== '') ? (preg_replace('/#.*$/', '', $helpBase) . '#' . $ia) : $helpBase;
							?>
							<tr>
								<td><?php echo tt_fc_wizard_h(isset($gr['label']) ? (string) $gr['label'] : ''); ?></td>
								<td style="color:#333;font-size:.88rem;line-height:1.4;"><?php echo tt_fc_wizard_h(isset($gr['xml']) ? (string) $gr['xml'] : ''); ?></td>
								<td style="color:#333;font-size:.88rem;line-height:1.4;"><?php echo tt_fc_wizard_h(isset($gr['project']) ? (string) $gr['project'] : ''); ?></td>
								<td><?php
								if ($gst === 'ok') {
									echo '<span style="color:#2e7d32;font-weight:600;">OK</span>';
								} elseif ($gst === 'irrelevant') {
									echo '<span style="color:#9e9e9e;font-weight:600;">n/a</span>';
								} elseif ($gst === 'optional') {
									echo '<span style="color:#ef6c00;font-weight:600;">Review</span>';
								} elseif ($gst === 'wrong') {
									echo '<span style="color:#c62828;font-weight:600;">Fix</span>';
								} elseif ($gst === 'info') {
									echo '<span style="color:#1565c0;font-weight:600;">Info</span>';
								} else {
									echo tt_fc_wizard_h($gst !== '' ? $gst : '—');
								}
								?></td>
								<td style="line-height:1.4;">
									<div style="overflow:hidden;">
										<?php if ($ia !== ''): ?>
										<a class="fc-wizard-help-icon" href="<?php echo tt_fc_wizard_h($infoHref); ?>" target="_blank" rel="noopener noreferrer" aria-label="Help: <?php echo tt_fc_wizard_h(isset($gr['label']) ? (string) $gr['label'] : 'this row'); ?>" title="Help"><span aria-hidden="true">i</span></a>
										<?php endif; ?>
										<div style="color:#222;font-size:.88rem;"><?php echo tt_fc_wizard_h(isset($gr['summary']) ? (string) $gr['summary'] : ''); ?></div>
										<?php if (!empty($gr['detail'])): ?>
										<div style="color:#555;font-size:.82rem;margin-top:.2rem;"><?php echo tt_fc_wizard_h((string) $gr['detail']); ?></div>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			</section>

			<section id="fc-wizard-panel-samples" class="fc-wizard-panel" role="tabpanel" aria-labelledby="fc-wizard-tab-samples"<?php echo $tab !== 'samples' ? ' hidden' : ''; ?>>
			<h3 style="margin-top:1.3rem;">Sample inspection</h3>
			<?php if (!$xmlDir): ?>
				<p style="color:#9b111e;">No XML directory found. Expected e.g. <code>xmlfiles/</code> under project root.</p>
			<?php elseif (!count($rows)): ?>
				<p style="color:#9b111e;">No XML files found in the corpus XML folder.</p>
			<?php else: ?>
				<div style="overflow:auto;">
					<table class="table table-sm table-bordered" style="min-width:900px;">
						<thead>
							<tr>
								<th>File</th>
								<th>&lt;u&gt;</th>
								<th>media/@url</th>
								<th>local media files</th>
								<th>media∈header</th>
								<th>u @start+stop</th>
								<th>facs markers</th>
								<th>s/@tuid</th>
								<th>tok/@tuid</th>
								<th>text/@text_tuid</th>
								<th>sameAs/corresp</th>
								<th>geo markers</th>
								<th>&lt;s&gt;</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($rows as $r): ?>
							<tr>
								<td><code><?php echo tt_fc_wizard_h(basename($r['file'])); ?></code></td>
								<td><?php echo (int) $r['u']; ?></td>
								<td><?php echo (int) $r['media']; ?></td>
								<td style="font-size:.85rem;"><?php
								$mchk = isset($r['media_fs_checked']) ? (int) $r['media_fs_checked'] : 0;
								$mfnd = isset($r['media_fs_found']) ? (int) $r['media_fs_found'] : 0;
								$mmis = isset($r['media_fs_missing']) ? (int) $r['media_fs_missing'] : 0;
								$mrem = isset($r['media_remote']) ? (int) $r['media_remote'] : 0;
								if ($mchk > 0) {
									echo (int) $mfnd . '/' . (int) $mchk . ' ok';
									if ($mmis > 0) {
										echo ' <span style="color:#c62828;">(' . (int) $mmis . ' missing)</span>';
									}
								} else {
									echo $mrem > 0 ? ((int) $mrem . ' remote') : '—';
								}
								?></td>
								<td><?php echo (int) (isset($r['media_hdr']) ? $r['media_hdr'] : 0); ?></td>
								<td><?php echo (int) (isset($r['u_tim']) ? $r['u_tim'] : 0); ?></td>
								<td><?php echo (int) $r['facs']; ?></td>
								<td><?php echo (int) $r['st_uid']; ?></td>
								<td><?php echo (int) $r['tok_tuid']; ?></td>
								<td><?php echo (int) $r['text_text_tuid']; ?></td>
								<td><?php echo (int) $r['alignment']; ?></td>
								<td><?php echo (int) $r['geo']; ?></td>
								<td><?php echo (int) $r['s']; ?></td>
								<td>
									<?php if (!empty($r['ok'])): ?>
										<span style="color:#2a7d2e;">OK</span>
									<?php else: ?>
										<span style="color:#9b111e;"><?php echo tt_fc_wizard_h($r['error']); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			</section>

			<?php if ($wizardAutomatedFixesPanelVisible): ?>
			<div id="fc-wizard-settings-fixes" style="margin-top:1.35rem;padding-top:1rem;border-top:2px solid #cfd8dc;">
				<details id="fc-wizard-settings-fixes-details" style="border:1px solid #cfd8dc;border-radius:7px;padding:.55rem .75rem;background:#fafafa;"<?php echo $wizardSettingsFixDockOpen ? ' open' : ''; ?>>
					<summary style="cursor:pointer;font-weight:600;font-size:.88rem;outline:none;">Automated fixes for Resources/settings.xml</summary>
					<p style="margin:.45rem 0 .35rem 0;color:#555;font-size:.8rem;line-height:1.45;">Add-only merges into <code>Resources/settings.xml</code> (daily backup <code>backups/settings-YYYYMMDD.xml</code>). Queued by <strong>Wizard fix</strong> on whatever checklist row offered it — custom setups stay manual.</p>
					<?php if ($settingsXmlPath === ''): ?>
						<p style="margin:.35rem 0;font-size:.84rem;color:#9b111e;">Canonical <code>Resources/settings.xml</code> not found — cannot merge.</p>
					<?php elseif (!$settings_readable): ?>
						<p style="margin:.35rem 0;font-size:.84rem;color:#9b111e;">Settings file is not readable.</p>
					<?php else: ?>
						<?php if (!$settings_writable): ?>
						<p style="margin:.35rem 0 .6rem 0;font-size:.82rem;color:#8a6d3b;">Settings file is not writable — saving merges to disk is disabled. In-memory preview still runs when available below.</p>
						<?php endif; ?>

						<?php if (count($wizardFixAppliedList) > 0): ?>
						<div style="margin:.35rem 0 .55rem 0;padding:.5rem .65rem;border:1px solid #c8e6c9;background:#f1f8f4;border-radius:7px;">
							<strong style="font-size:.85rem;">Applied automated fixes (this browser session)</strong>
							<ul style="margin:.35rem 0 0 1rem;padding:0;font-size:.82rem;color:#333;line-height:1.45;">
								<?php foreach ($wizardFixAppliedList as $wal): ?>
								<li style="list-style:disc;margin:.2rem 0;">
									<code><?php echo tt_fc_wizard_h($wal['code']); ?></code> — <?php echo tt_fc_wizard_h($wal['label']); ?>
									<span style="color:#666;font-size:.78rem;"> · <?php echo tt_fc_wizard_h(date('Y-m-d H:i', $wal['ts'])); ?></span>
								</li>
								<?php endforeach; ?>
							</ul>
						</div>
						<?php endif; ?>

						<?php if ($wizard_fix_queue_all_applied_no_action): ?>
						<p style="margin:.35rem 0 0 0;color:#2e7d32;font-size:.82rem;line-height:1.45;">Everything in your <strong>Wizard fix</strong> queue (<code><?php echo tt_fc_wizard_h($wizard_select_fix); ?></code>) was already saved this session — see <strong>Applied automated fixes</strong> above. Open another checklist row and click <strong>Wizard fix</strong> again to append merges (they accumulate in the URL).</p>

						<?php elseif (count($wizard_select_fix_list) > 0 && !$dock_has_actionable_fix): ?>
						<p style="margin:.35rem 0 0 0;color:#9b111e;font-size:.82rem;">Nothing left to merge for <code><?php echo tt_fc_wizard_h($wizard_select_fix); ?></code> on this scan — prerequisites not met, already satisfied, or codes unknown. Check the relevant tab(s); clear <code>wizard_select_fix</code> from the URL to reset the queue.</p>

						<?php elseif (count($wizard_select_fix_list) > 0 && $dock_has_actionable_fix): ?>
						<ul style="margin:.35rem 0 0 0;padding:0;list-style:none;">
							<?php if ($dock_show_alignment_merge): ?>
							<li style="margin:0 0 .85rem 0;padding:.65rem .75rem;border:1px solid #e2e2e2;border-radius:7px;background:#fff;">
								<p style="margin:0 0 .45rem 0;font-size:.78rem;color:#666;line-height:1.4;"><strong>Source:</strong> <strong>Alignment</strong> tab — this sample uses alignment-related ids that are not yet wired in CQP settings for this corpus (see that tab for counts).</p>
								<?php if ($settings_writable): ?>
								<form id="fc-wizard-form-alignment" method="post" action="" style="margin:0;">
									<input type="hidden" name="action" value="wizard">
									<input type="hidden" name="wizard_write_alignment" value="1">
									<input type="hidden" name="wizard_scope" value="<?php echo tt_fc_wizard_h($wizard_scope_current); ?>">
									<input type="hidden" name="wizard_select_fix" value="<?php echo tt_fc_wizard_h($wizard_select_fix); ?>">
									<input type="hidden" name="sample" value="<?php echo (int) $sampleSize; ?>">
									<input type="hidden" name="wizard_return_tab" value="<?php echo tt_fc_wizard_h($tab); ?>">
									<label style="display:flex;align-items:flex-start;gap:.5rem;margin:.35rem 0;cursor:pointer;font-size:.85rem;">
										<input type="checkbox" name="wizard_include_text_tuid" value="1"<?php echo $wizard_align_include_text_tuid_checked ? ' checked' : ''; ?>>
										<span>Include <code>text_tuid</code></span>
									</label>
									<label style="display:flex;align-items:flex-start;gap:.5rem;margin:.5rem 0 .65rem 0;cursor:pointer;font-size:.85rem;">
										<input type="checkbox" name="wizard_confirm_write" value="1" required>
										<span>Confirm write to <code>Resources/settings.xml</code></span>
									</label>
									<button type="submit" class="btn btn-sm btn-primary">Merge alignment entries</button>
								</form>
								<?php else: ?>
								<p style="margin:0;color:#9b111e;font-size:.82rem;">Cannot merge — <code>Resources/settings.xml</code> is not writable.</p>
								<?php endif; ?>
							</li>
							<?php endif; ?>

							<?php
							$dock_spoken_only = ($dock_show_allowlist_u || $dock_show_cqp_u_stub || $dock_show_wavesurfer_tok || $dock_show_wavesurfer_utt || $dock_show_cqp_u_audio || $dock_show_files_audio);
							$dock_deps_merge = ($dock_show_cqp_deprel_head || $dock_show_cqp_s_region || $dock_show_cqp_s_tuid || $dock_show_xmlfile_deprel_head || $dock_show_cqp_ud_morph);
							$dock_settings_merge_block = ($dock_spoken_only || $dock_deps_merge);
							?>
							<?php if ($dock_settings_merge_block): ?>
							<li style="margin:0;padding:.65rem .75rem;border:1px solid #e2e2e2;border-radius:7px;background:#fff;">
								<?php if ($dock_spoken_only): ?>
								<p style="margin:0 0 .45rem 0;font-size:.78rem;color:#666;line-height:1.4;"><strong>Source:</strong> <strong>Spoken data</strong> checklist — each checked merge below was queued via <strong>Wizard fix</strong> (queue in URL: <code><?php echo tt_fc_wizard_h($wizard_select_fix); ?></code>).</p>
								<?php endif; ?>
								<?php if ($dock_deps_merge): ?>
								<p style="margin:0 0 .45rem 0;font-size:.78rem;color:#666;line-height:1.4;"><strong>Source:</strong> <strong>Dependencies (UD)</strong> — queued merges adjust <code>Resources/settings.xml</code> for CQP / xmlfile wiring (defaults such as <code>@tuid</code> on ⟨s⟩, <code>@deprel</code>/<code>@head</code> on tokens, optional UD morphology attrs). Reindex after saving so the encoded corpus matches settings.</p>
								<?php endif; ?>
								<form id="fc-wizard-form-settings-fixes" method="post" action="" style="margin:0;">
									<input type="hidden" name="action" value="wizard">
									<input type="hidden" name="wizard_scope" value="<?php echo tt_fc_wizard_h($wizard_scope_current); ?>">
									<input type="hidden" name="wizard_select_fix" value="<?php echo tt_fc_wizard_h($wizard_select_fix); ?>">
									<input type="hidden" name="sample" value="<?php echo (int) $sampleSize; ?>">
									<input type="hidden" name="wizard_return_tab" value="<?php echo tt_fc_wizard_h($tab); ?>">
									<ul style="margin:0 0 .5rem 1.1rem;padding:0;font-size:.82rem;line-height:1.45;color:#333;">
										<?php if ($dock_show_allowlist_u): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="allowlist_u" checked>
												<?php if (!empty($spokenHardAllowlist)): ?>
												Append <code>u</code> to explicit <code>flexicorp/context_scope_regions</code> (corpus <code>Resources/settings.xml</code> — same key as <code>getset('flexicorp/context_scope_regions')</code>).
												<?php else: ?>
												Write explicit built-in search context regions (<code>s, u, lb, l, p, seg</code>) — materializes the same list flexicorp.php uses when the setting is implicit (empty / <code>-</code> / <code>default</code>).
												<?php endif; ?>
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_cqp_u_stub): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="cqp_u_stub" checked>
												Add CQP structural region <code>u</code> for TEI <code>&lt;u&gt;</code> with nested timing: <code>start</code> → <code>@start</code>, <code>stop</code> → <code>@stop</code> (index bounds). Speaker (<code>who</code>) and media/medianode URL are <strong>xmlfile</strong> editor fields — use the <strong>XML editor: ⟨u⟩ fields</strong> checklist row, not this merge.
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_wavesurfer_tok): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="wavesurfer_tok" checked>
												Set <code>xmlfile/speech/highlight</code> default to <code>TOK</code> (word-level WaveSurfer when ⟨tok⟩ times exist).
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_wavesurfer_utt): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="wavesurfer_utt" checked>
												Set <code>xmlfile/speech/highlight</code> default to utterance tag <code><?php echo tt_fc_wizard_h($wizardSpeechHighlightUttTag); ?></code> (from <code>xmlfile/defaults/speechturn</code>) — use when TOK highlight is set but the sample has no token times.
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_cqp_u_audio): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="cqp_u_audio" checked>
												Add TEITOK native <code>u_audio</code>: CQP region <code>u</code> with <code>audio</code> flag + nested <code>&lt;item key=&quot;audio&quot; xpath=&quot;…&quot;/&gt;</code> using sample path <code><?php echo tt_fc_wizard_h($wizardMediaUAudioXpathPreview); ?></code> (single fingerprint in scan).
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_files_audio): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="files_audio" checked>
												Add TEITOK <code>&lt;files&gt;&lt;item key=&quot;audio&quot; folder=&quot;Audio&quot; display=&quot;audio&quot; extension=&quot;*.wav,*.mp3&quot;/&gt;</code> for uploads (creates on-disk <code>Audio/</code> beside the corpus when possible).
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_cqp_deprel_head): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="cqp_deprel_head" checked>
												Add CQP p-attribute entries <code>deprel</code> → <code>@deprel</code> and <code>head</code> → <code>@head</code> (edit in <code>Resources/settings.xml</code> if your TEI uses different attribute names).
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_cqp_s_region): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="cqp_s_region" checked>
												Add top-level CQP structural region <code>s</code> (sentence) under <code>cqp/sattributes</code>.
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_cqp_s_tuid): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="cqp_s_tuid" checked>
												Add sentence region (if needed) and nested <code>s_tuid</code> → <code>@tuid</code> under <code>cqp/sattributes</code>.
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_xmlfile_deprel_head): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="xmlfile_deprel_head" checked>
												Add <code>xmlfile/pattributes</code> entries for <code>deprel</code> / <code>head</code> (XML editor token fields).
											</label>
										</li>
										<?php endif; ?>
										<?php if ($dock_show_cqp_ud_morph): ?>
										<li style="list-style:square;margin:.25rem 0;">
											<label style="cursor:pointer;display:inline;">
												<input type="checkbox" name="wizard_fix[]" value="cqp_ud_morph" checked>
												Add missing CQP p-attributes for recommended UD morphology keys (<code><?php echo tt_fc_wizard_h(implode(', ', $depsMorphFixKeys)); ?></code>), even if dependency markup is not yet in the sample.
											</label>
										</li>
										<?php endif; ?>
									</ul>
									<label style="display:flex;align-items:flex-start;gap:.45rem;margin:.45rem 0 .55rem 0;cursor:pointer;font-size:.82rem;">
										<input type="checkbox" name="wizard_confirm_settings_fixes" value="1">
										<span>I confirm writing merged XML to <code>Resources/settings.xml</code> (daily backup under <code>backups/settings-YYYYMMDD.xml</code>). Not required for preview.</span>
									</label>
									<div style="display:flex;flex-wrap:wrap;gap:.45rem;align-items:center;margin:.25rem 0 0 0;">
										<button type="button" id="fc-wizard-preview-settings-btn" class="btn btn-sm btn-primary">Preview merged XML</button>
										<button type="submit" name="wizard_apply_fixes" value="1" class="btn btn-sm btn-danger"<?php echo $settings_writable ? '' : ' disabled title="Settings file is not writable."'; ?>>Save to settings.xml</button>
									</div>
									<p style="margin:.45rem 0 0 0;color:#555;font-size:.78rem;line-height:1.4;">Preview applies merges in memory only and logs the merged XML to the browser console (DevTools). Save writes the file (requires confirmation + writable settings).</p>
								</form>
							</li>
							<?php endif; ?>
						</ul>
						<?php endif; ?>
					<?php endif; ?>
				</details>
			</div>
			<?php endif; ?>

		<script>
		(function () {
			var MSG = 'You have pending wizard merges or fixes selected that are not applied yet. Leave without applying?';
			var WIZARD_SCOPE = '<?php echo tt_fc_wizard_h($wizard_scope_current); ?>';

			function serializeForm(form) {
				var fd = new FormData(form);
				var pairs = [];
				fd.forEach(function (val, key) {
					pairs.push(key + '=' + String(val));
				});
				pairs.sort();
				return pairs.join('|');
			}

			function bindInitial(form) {
				if (!form) {
					return;
				}
				form.setAttribute('data-wizard-initial', serializeForm(form));
			}

			function isDirtyForm(form) {
				if (!form || !form.getAttribute('data-wizard-initial')) {
					return false;
				}
				return serializeForm(form) !== form.getAttribute('data-wizard-initial');
			}

			function isWizardPendingDirty() {
				var dirty = false;
				document.querySelectorAll('#fc-wizard-form-settings-fixes, #fc-wizard-form-alignment').forEach(function (f) {
					if (isDirtyForm(f)) {
						dirty = true;
					}
				});
				return dirty;
			}

			var WIZARD_SELECT_FIX_ALLOW = ['alignment_merge', 'allowlist_u', 'cqp_u_stub', 'wavesurfer_tok', 'wavesurfer_utt', 'cqp_u_audio', 'files_audio', 'cqp_deprel_head', 'cqp_s_region', 'cqp_s_tuid', 'xmlfile_deprel_head', 'cqp_ud_morph'];

			function fcWizardMergeWizardSelectFix(sp, fixCode) {
				var cur = [];
				var raw = sp.get('wizard_select_fix');
				if (raw) {
					String(raw).split(/[\s,]+/).forEach(function (p) {
						p = String(p).trim().toLowerCase();
						if (p && WIZARD_SELECT_FIX_ALLOW.indexOf(p) !== -1 && cur.indexOf(p) === -1) {
							cur.push(p);
						}
					});
				}
				var fc = String(fixCode || '').trim().toLowerCase();
				if (fc && WIZARD_SELECT_FIX_ALLOW.indexOf(fc) !== -1 && cur.indexOf(fc) === -1) {
					cur.push(fc);
				}
				if (cur.length) {
					sp.set('wizard_select_fix', cur.join(','));
				} else {
					sp.delete('wizard_select_fix');
				}
			}

			function fcWizardOpenAutomatedFix(fixCode, tabName) {
				if (!fixCode) {
					return;
				}
				if (!window.fcWizardBypassNavWarn && isWizardPendingDirty()) {
					if (!window.confirm(MSG)) {
						return;
					}
				}
				var sp = new URLSearchParams(window.location.search);
				sp.set('action', 'wizard');
				sp.set('tab', tabName || 'spoken');
				sp.set('wizard_scope', WIZARD_SCOPE);
				fcWizardMergeWizardSelectFix(sp, fixCode);
				var sf = document.getElementById('fc-wizard-sample-form');
				if (sf) {
					var sm = sf.querySelector('input[name="sample"]');
					if (sm && String(sm.value) !== '') {
						sp.set('sample', sm.value);
					}
					var ti = sf.querySelector('input[name="tab"]');
					if (ti) {
						ti.value = tabName || 'spoken';
					}
				}
				if (!sp.get('sample')) {
					sp.set('sample', '8');
				}
				window.fcWizardBypassNavWarn = true;
				window.location.assign('index.php?' + sp.toString() + '#fc-wizard-settings-fixes-details');
			}

			window.fcWizardOpenAutomatedFix = fcWizardOpenAutomatedFix;

			function fcWizardGoTab(tabName) {
				if (!tabName) {
					return;
				}
				document.querySelectorAll('.fc-wizard-panel').forEach(function (p) {
					var want = 'fc-wizard-panel-' + tabName;
					p.hidden = (p.id !== want);
				});
				document.querySelectorAll('[data-fc-wizard-tab]').forEach(function (btn) {
					var on = btn.getAttribute('data-fc-wizard-tab') === tabName;
					btn.classList.toggle('flexicorp-seg-btn--active', on);
					btn.setAttribute('aria-selected', on ? 'true' : 'false');
					if (on) {
						btn.setAttribute('aria-current', 'true');
					} else {
						btn.removeAttribute('aria-current');
					}
				});
				var f = document.getElementById('fc-wizard-sample-form');
				if (f) {
					var ti = f.querySelector('input[name="tab"]');
					if (ti) {
						ti.value = tabName;
					}
				}
				if (window.history && window.history.replaceState) {
					try {
						var u = new URL(window.location.href);
						u.searchParams.set('tab', tabName);
						var sf = document.getElementById('fc-wizard-sample-form');
						if (sf) {
							var sm = sf.querySelector('input[name="sample"]');
							if (sm && String(sm.value) !== '') {
								u.searchParams.set('sample', sm.value);
							}
						}
						window.history.replaceState(null, '', u.pathname + u.search + u.hash);
					} catch (e2) {}
				}
			}

			window.fcWizardGoTab = fcWizardGoTab;

			function init() {
				bindInitial(document.getElementById('fc-wizard-form-settings-fixes'));
				bindInitial(document.getElementById('fc-wizard-form-alignment'));

				var previewBtn = document.getElementById('fc-wizard-preview-settings-btn');
				var previewForm = document.getElementById('fc-wizard-form-settings-fixes');
				if (previewBtn && previewForm) {
					previewBtn.addEventListener('click', function () {
						var fd = new FormData(previewForm);
						fd.set('wizard_preview_fixes', '1');
						fd.set('wizard_preview_ajax', '1');
						/* POST must hit the same routed entry as the visible wizard page. A bare index.php POST often omits ?action=wizard, so TEITOK returns full HTML instead of running wizard.php's JSON handler. */
						var previewUrl = new URL(window.location.href);
						previewUrl.hash = '';
						if (!previewUrl.searchParams.get('action')) {
							previewUrl.searchParams.set('action', 'wizard');
						}
						fetch(previewUrl.toString(), {
							method: 'POST',
							body: fd,
							credentials: 'same-origin',
							headers: { 'X-Requested-With': 'XMLHttpRequest' }
						}).then(function (r) {
							var ct = (r.headers.get('Content-Type') || '');
							if (ct.indexOf('application/json') === -1) {
								return r.text().then(function (t) {
									throw new Error('Expected JSON from preview; got: ' + String(t).slice(0, 240));
								});
							}
							return r.json();
						}).then(function (data) {
							if (data && data.xml) {
								console.log('[flexicorp wizard] merged settings XML preview (not saved)', data.xml);
							}
							if (data && data.message) {
								if (data.ok) {
									console.log('[flexicorp wizard]', data.message);
								} else {
									console.error('[flexicorp wizard]', data.message);
								}
							} else if (data && !data.ok) {
								console.error('[flexicorp wizard] preview failed');
							}
						}).catch(function (err) {
							console.error('[flexicorp wizard] preview request failed', err);
						});
					});
				}

				document.addEventListener('submit', function (e) {
					var t = e.target;
					if (!t || !t.tagName || t.tagName.toLowerCase() !== 'form') {
						return;
					}
					if (t.id === 'fc-wizard-form-settings-fixes' || t.id === 'fc-wizard-form-alignment' || t.id === 'fc-wizard-sample-form') {
						window.fcWizardBypassNavWarn = true;
					}
				}, true);

				window.addEventListener('beforeunload', function (e) {
					if (window.fcWizardBypassNavWarn) {
						return;
					}
					if (!isWizardPendingDirty()) {
						return;
					}
					e.preventDefault();
					e.returnValue = '';
				});

				document.querySelectorAll('.fc-wizard-tabseg [data-fc-wizard-tab]').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var tab = btn.getAttribute('data-fc-wizard-tab');
						fcWizardGoTab(tab);
					});
				});

				document.querySelectorAll('.fc-wizard-open-automated-fix').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var code = btn.getAttribute('data-fc-wizard-fix');
						var wtab = btn.getAttribute('data-fc-wizard-tab');
						if (!code) {
							return;
						}
						fcWizardOpenAutomatedFix(code, wtab || 'spoken');
					});
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>

		</div>
		<?php
		return (string) ob_get_clean();
	}
}

global $maintext;
if (!isset($maintext)) $maintext = '';

if (function_exists('check_login')) {
	@check_login();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['wizard_write_alignment'])) {
	$confirm = isset($_POST['wizard_confirm_write']) && (string) $_POST['wizard_confirm_write'] === '1';
	$sample_post = isset($_POST['sample']) ? (int) $_POST['sample'] : 8;
	if ($sample_post <= 0) {
		$sample_post = 8;
	}
	if ($sample_post > 50) {
		$sample_post = 50;
	}
	$include_text_tuid = isset($_POST['wizard_include_text_tuid']) && (string) $_POST['wizard_include_text_tuid'] === '1';
	$root = tt_fc_wizard_project_root();
	$xml_dir = tt_fc_wizard_resolve_xml_dir($root);
	$sample_files = ($xml_dir !== '') ? tt_fc_wizard_collect_xml_files($xml_dir, $sample_post) : array();
	$prof = tt_fc_wizard_profile_from_sample($sample_files, $root);
	$tot = $prof['totals'];
	$need_s = !empty($tot['s_tuid_docs']) && (int) $tot['s_tuid_docs'] > 0;
	$need_tok = !empty($tot['tok_tuid_docs']) && (int) $tot['tok_tuid_docs'] > 0;
	$path = tt_fc_wizard_resolve_teitok_settings_xml($root);
	if (!$confirm) {
		$GLOBALS['tt_fc_wizard_flash'] = array(
			'type' => 'danger',
			'msg' => 'Confirm the checkbox to write settings.',
		);
	} else {
		$res = tt_fc_wizard_apply_alignment_settings($path, $need_s, $need_tok, $include_text_tuid);
		$GLOBALS['tt_fc_wizard_flash'] = array(
			'type' => $res['ok'] ? 'success' : 'danger',
			'msg' => $res['message'],
		);
		if ($res['ok']) {
			tt_fc_wizard_fix_applied_log_set($root, 'alignment_merge');
		}
		if ($res['ok'] && !headers_sent()) {
			$allowed_ret = array('overview', 'core', 'indexes', 'dependencies', 'spoken', 'facs', 'alignment', 'geo', 'samples');
			$ret_tab = isset($_POST['wizard_return_tab']) ? strtolower(trim((string) $_POST['wizard_return_tab'])) : 'alignment';
			if (!in_array($ret_tab, $allowed_ret, true)) {
				$ret_tab = 'alignment';
			}
			$redir = array(
				'action' => 'wizard',
				'tab' => $ret_tab,
				'sample' => $sample_post,
				'wizard_scope' => tt_fc_wizard_project_scope_key($root),
				'applied' => '1',
			);
			$wsf_list = array();
			$wsf_scope = isset($_POST['wizard_scope']) ? trim((string) $_POST['wizard_scope']) : '';
			if ($wsf_scope !== '' && hash_equals(tt_fc_wizard_project_scope_key($root), $wsf_scope) && isset($_POST['wizard_select_fix'])) {
				$wsf_list = tt_fc_wizard_parse_wizard_select_fix_list((string) $_POST['wizard_select_fix']);
			}
			$remaining_wsf = array_values(array_filter($wsf_list, static function ($c) {
				return $c !== 'alignment_merge';
			}));
			if (count($remaining_wsf) > 0) {
				$redir['wizard_select_fix'] = implode(',', $remaining_wsf);
			}
			if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
				session_write_close();
			}
			header('Location: index.php?' . http_build_query($redir));
			exit;
		}
	}
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (isset($_POST['wizard_apply_fixes']) || isset($_POST['wizard_preview_fixes']))) {
	$preview_only = isset($_POST['wizard_preview_fixes']) && (string) $_POST['wizard_preview_fixes'] === '1';
	$wizard_preview_ajax = isset($_POST['wizard_preview_ajax']) && (string) $_POST['wizard_preview_ajax'] === '1';
	$confirm = isset($_POST['wizard_confirm_settings_fixes']) && (string) $_POST['wizard_confirm_settings_fixes'] === '1';
	$sample_post = isset($_POST['sample']) ? (int) $_POST['sample'] : 8;
	if ($sample_post <= 0) {
		$sample_post = 8;
	}
	if ($sample_post > 50) {
		$sample_post = 50;
	}
	$fixes = (isset($_POST['wizard_fix']) && is_array($_POST['wizard_fix'])) ? $_POST['wizard_fix'] : array();
	$root = tt_fc_wizard_project_root();
	$settingsXmlPathPost = tt_fc_wizard_resolve_teitok_settings_xml($root);
	$canonicalSettingsPathPost = tt_fc_wizard_teitok_canonical_settings_path($root);
	$mergedCqpPathPost = (trim((string) $root) !== '')
		? rtrim((string) $root, '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cqpsettings.xml'
		: '';
	$mergedCqpReadablePost = ($mergedCqpPathPost !== '' && is_file($mergedCqpPathPost) && is_readable($mergedCqpPathPost));
	$cqpSattrXmlPathPost = ($mergedCqpReadablePost && $mergedCqpPathPost !== '') ? $mergedCqpPathPost : $settingsXmlPathPost;
	$cqpSattrParsePost = tt_fc_wizard_parse_cqp_sattribute_keys($cqpSattrXmlPathPost);
	$skeysPost = (isset($cqpSattrParsePost['keys']) && is_array($cqpSattrParsePost['keys'])) ? $cqpSattrParsePost['keys'] : array();
	$uRegionPost = tt_fc_wizard_cqp_utterance_region_wired($skeysPost);
	$uIndexedPost = !empty($uRegionPost['wired']);
	$xml_dir_post = tt_fc_wizard_resolve_xml_dir($root);
	$sample_files_post = ($xml_dir_post !== '') ? tt_fc_wizard_collect_xml_files($xml_dir_post, $sample_post) : array();
	$prof_post = tt_fc_wizard_profile_from_sample($sample_files_post, $root);
	$tot_post = $prof_post['totals'];
	$scopeRegsPost = tt_fc_wizard_resolve_flexicorp_context_scope_regions($canonicalSettingsPathPost, $mergedCqpPathPost, $mergedCqpReadablePost);
	$spokenPartialPost = (!empty($tot_post['spoken_docs']) && (int) $tot_post['spoken_docs'] > 0)
		|| (!empty($tot_post['media_docs']) && (int) $tot_post['media_docs'] > 0);
	$spokenPrepAudioPost = ((int) $tot_post['scanned'] > 0 && (int) $tot_post['spoken_docs'] === 0 && (int) $tot_post['media_docs'] === 0);
	$spokenConcernPost = ($spokenPartialPost || $spokenPrepAudioPost);
	$spokenHardAllowlistPost = ($spokenConcernPost && !empty($scopeRegsPost['explicit']) && empty($scopeRegsPost['u_allowed']));
	$spokenSoftImplicitPost = ($spokenConcernPost && empty($scopeRegsPost['explicit']) && !empty($scopeRegsPost['u_allowed']));
	$needCqpUStubPost = $spokenConcernPost && !$uIndexedPost;
	$tokTimedPost = isset($tot_post['tok_timing_docs']) ? (int) $tot_post['tok_timing_docs'] : 0;
	$wsHlPost = function_exists('getset') ? trim((string) getset('xmlfile/speech/highlight', '')) : '';
	$wsWantsTokPost = ($wsHlPost !== '' && strcasecmp($wsHlPost, 'TOK') === 0);
	$highlightIsTokPost = $wsWantsTokPost;
	$spokenFixWavesurferTokPost = ($spokenConcernPost && $tokTimedPost > 0 && !$highlightIsTokPost);
	$spokenFixWavesurferUttPost = ($spokenConcernPost && $wsWantsTokPost && $tokTimedPost === 0);
	$msspPost = isset($tot_post['media_single_url_pattern']) ? trim((string) $tot_post['media_single_url_pattern']) : '';
	$uAudioXpathPost = ($settingsXmlPathPost !== '') ? tt_fc_wizard_cqp_region_nested_audio_xpath($settingsXmlPathPost, 'u') : '';
	$spokenFixMediaUAudioPost = ($spokenPartialPost && $msspPost !== '' && $uAudioXpathPost === '');
	$audioUploadPost = ($settingsXmlPathPost !== '') ? tt_fc_wizard_parse_settings_files_audio_upload($settingsXmlPathPost) : array('ok' => false, 'configured' => false);
	$spokenFixFilesAudioPost = (
		$spokenConcernPost
		&& $settingsXmlPathPost !== ''
		&& is_readable($settingsXmlPathPost)
		&& empty($audioUploadPost['configured'])
	);
	$spokenFixFilesAudioPreviewPost = $spokenFixFilesAudioPost;

	$tokenAttrHitsPost = isset($prof_post['tokenAttrHits']) && is_array($prof_post['tokenAttrHits']) ? $prof_post['tokenAttrHits'] : array();
	$settingsPathForCqpDepPost = ($mergedCqpReadablePost && $mergedCqpPathPost !== '') ? $mergedCqpPathPost : $canonicalSettingsPathPost;
	$cqpPattrDepPost = tt_fc_wizard_parse_cqp_pattribute_keys($settingsPathForCqpDepPost);
	$pflipDepPost = array();
	if (!empty($cqpPattrDepPost['keys']) && is_array($cqpPattrDepPost['keys'])) {
		foreach ($cqpPattrDepPost['keys'] as $_pk) {
			$pflipDepPost[strtolower(trim((string) $_pk))] = true;
		}
	}
	$bothTokPost = tt_fc_wizard_token_attr_hit_ci($tokenAttrHitsPost, 'deprel') && tt_fc_wizard_token_attr_hit_ci($tokenAttrHitsPost, 'head');
	$depIntentPostEarly = tt_fc_wizard_token_attr_hit_ci($tokenAttrHitsPost, 'deprel')
		|| tt_fc_wizard_token_attr_hit_ci($tokenAttrHitsPost, 'head');
	$depsFixCqpPattrsPost = ($settingsXmlPathPost !== '' && is_readable($settingsXmlPathPost))
		&& !empty($cqpPattrDepPost['ok'])
		&& (!isset($pflipDepPost['deprel']) || !isset($pflipDepPost['head']));

	$cqpAlignDepPost = tt_fc_wizard_parse_cqp_alignment($settingsPathForCqpDepPost);
	$sattrDepPost = tt_fc_wizard_parse_cqp_sattribute_keys($settingsPathForCqpDepPost);
	$sflipSPost = array();
	if (!empty($sattrDepPost['keys']) && is_array($sattrDepPost['keys'])) {
		foreach ($sattrDepPost['keys'] as $_sk) {
			$sflipSPost[strtolower(trim((string) $_sk))] = true;
		}
	}
	$hasSIndexedPost = isset($sflipSPost['s']);
	$regionElemHitsPost = isset($prof_post['regionElemHits']) && is_array($prof_post['regionElemHits']) ? $prof_post['regionElemHits'] : array();
	$hitSPost = !empty($regionElemHitsPost['s']);
	$sTuidDocsPost = isset($tot_post['s_tuid_docs']) ? (int) $tot_post['s_tuid_docs'] : 0;
	$sTuidCqpPost = !empty($cqpAlignDepPost['ok']) && !empty($cqpAlignDepPost['sattr_s_tuid']);
	$depsFixCqpSRegionPost = ($settingsXmlPathPost !== '' && is_readable($settingsXmlPathPost))
		&& !empty($sattrDepPost['ok'])
		&& $hitSPost
		&& !$hasSIndexedPost;
	$depsFixCqpSTuidPost = ($settingsXmlPathPost !== '' && is_readable($settingsXmlPathPost))
		&& !empty($cqpAlignDepPost['ok'])
		&& !$sTuidCqpPost;

	$xfPatDepPost = tt_fc_wizard_parse_xmlfile_pattribute_keys($canonicalSettingsPathPost);
	$xfKeysPost = (isset($xfPatDepPost['keys']) && is_array($xfPatDepPost['keys'])) ? $xfPatDepPost['keys'] : array();
	$xfflipPost = array();
	foreach ($xfKeysPost as $_xk) {
		$xfflipPost[strtolower(trim((string) $_xk))] = true;
	}
	$depsFixXmlfileDeprelHeadPost = ($settingsXmlPathPost !== '' && is_readable($settingsXmlPathPost))
		&& !empty($xfPatDepPost['ok'])
		&& (!isset($xfflipPost['deprel']) || !isset($xfflipPost['head']));
	$depIntentPost = $depIntentPostEarly;
	$depsMorphFixKeysPost = array();
	foreach (array('upos', 'xpos', 'lemma', 'feats') as $_mk) {
		if (!isset($pflipDepPost[$_mk]) && ($depIntentPost ? tt_fc_wizard_token_attr_hit_ci($tokenAttrHitsPost, $_mk) : true)) {
			$depsMorphFixKeysPost[] = $_mk;
		}
	}
	$depsFixCqpMorphPost = ($settingsXmlPathPost !== '' && is_readable($settingsXmlPathPost))
		&& !empty($cqpPattrDepPost['ok'])
		&& count($depsMorphFixKeysPost) > 0;

	if (count($fixes) === 0) {
		if ($wizard_preview_ajax && $preview_only) {
			tt_fc_wizard_send_preview_ajax_json(false, 'Select at least one automated fix.');
		}
		$GLOBALS['tt_fc_wizard_flash'] = array(
			'type' => 'danger',
			'msg' => 'Select at least one automated fix.',
		);
	} elseif ($settingsXmlPathPost === '' || !is_readable($settingsXmlPathPost)) {
		if ($wizard_preview_ajax && $preview_only) {
			tt_fc_wizard_send_preview_ajax_json(false, 'Resources/settings.xml missing or unreadable.');
		}
		$GLOBALS['tt_fc_wizard_flash'] = array(
			'type' => 'danger',
			'msg' => 'Resources/settings.xml missing or unreadable.',
		);
	} elseif (!$preview_only && !is_writable($settingsXmlPathPost)) {
		$GLOBALS['tt_fc_wizard_flash'] = array(
			'type' => 'danger',
			'msg' => 'Resources/settings.xml is not writable.',
		);
	} elseif (!$preview_only && !$confirm) {
		$GLOBALS['tt_fc_wizard_flash'] = array(
			'type' => 'danger',
			'msg' => 'Confirm the checkbox to write settings.',
		);
	} elseif ($preview_only) {
		$workingXml = @file_get_contents($settingsXmlPathPost);
		if (!is_string($workingXml) || trim($workingXml) === '') {
			if ($wizard_preview_ajax) {
				tt_fc_wizard_send_preview_ajax_json(false, 'Settings file is empty — nothing to preview.');
			}
			$GLOBALS['tt_fc_wizard_flash'] = array(
				'type' => 'danger',
				'msg' => 'Settings file is empty — nothing to preview.',
			);
		} else {
			$msgs = array();
			$had_fail = false;
			$apply_opts = array(
				'commit' => false,
				'xml' => $workingXml,
			);
			foreach ($fixes as $fx) {
				$fx = strtolower(trim((string) $fx));
				$apply_opts['xml'] = $workingXml;
				if ($fx === 'allowlist_u') {
					if (!$spokenHardAllowlistPost && !$spokenSoftImplicitPost) {
						$msgs[] = 'Skipped allowlist merge (not applicable for this scan — flexicorp context_scope_regions already explicit or u already required).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_flexicorp_allowlist_append_u($settingsXmlPathPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'cqp_u_stub') {
					if (!$needCqpUStubPost) {
						$msgs[] = 'Skipped CQP u stub (region already declared for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_cqp_sattr_u_stub($settingsXmlPathPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'wavesurfer_tok') {
					if (!$spokenFixWavesurferTokPost) {
						$msgs[] = 'Skipped speech/highlight=TOK merge (not applicable for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_xmlfile_speech_highlight($settingsXmlPathPost, 'TOK', $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'wavesurfer_utt') {
					if (!$spokenFixWavesurferUttPost) {
						$msgs[] = 'Skipped speech/highlight utterance realignment (not applicable for this scan).';
						continue;
					}
					$uttDefPost = function_exists('getset') ? trim((string) getset('xmlfile/defaults/speechturn', 'U')) : 'U';
					$uttTagPost = strtoupper($uttDefPost !== '' ? $uttDefPost : 'U');
					$r = tt_fc_wizard_apply_merge_xmlfile_speech_highlight($settingsXmlPathPost, $uttTagPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'cqp_u_audio') {
					if (!$spokenFixMediaUAudioPost) {
						$msgs[] = 'Skipped CQP u_audio merge (not applicable for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_cqp_region_u_audio($settingsXmlPathPost, $msspPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'files_audio') {
					if (!$spokenFixFilesAudioPreviewPost) {
						$msgs[] = 'Skipped files/audio merge (not applicable for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_files_audio_slot($settingsXmlPathPost, $root, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'cqp_deprel_head') {
					if (!$depsFixCqpPattrsPost) {
						$msgs[] = 'Skipped CQP deprel/head p-attributes merge (not applicable for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_cqp_pattr_deprel_head($settingsXmlPathPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'cqp_s_region') {
					if (!$depsFixCqpSRegionPost) {
						$msgs[] = 'Skipped CQP sentence region `s` merge (not applicable for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_cqp_sattr_s_region($settingsXmlPathPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'cqp_s_tuid') {
					if (!$depsFixCqpSTuidPost) {
						$msgs[] = 'Skipped CQP s_tuid merge (not applicable for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_cqp_dep_s_tuid($settingsXmlPathPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'xmlfile_deprel_head') {
					if (!$depsFixXmlfileDeprelHeadPost) {
						$msgs[] = 'Skipped xmlfile deprel/head merge (not applicable for this scan).';
						continue;
					}
					$xfNeedPost = array();
					if (!isset($xfflipPost['deprel'])) {
						$xfNeedPost[] = 'deprel';
					}
					if (!isset($xfflipPost['head'])) {
						$xfNeedPost[] = 'head';
					}
					$r = tt_fc_wizard_apply_merge_xmlfile_pattr_keys($settingsXmlPathPost, $xfNeedPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				} elseif ($fx === 'cqp_ud_morph') {
					if (!$depsFixCqpMorphPost) {
						$msgs[] = 'Skipped CQP UD morphology p-attributes merge (not applicable for this scan).';
						continue;
					}
					$r = tt_fc_wizard_apply_merge_cqp_pattr_morph_keys($settingsXmlPathPost, $depsMorphFixKeysPost, $apply_opts);
					$msgs[] = $r['message'];
					if (empty($r['ok'])) {
						$had_fail = true;
					}
					if (!empty($r['ok']) && isset($r['preview_xml']) && is_string($r['preview_xml']) && $r['preview_xml'] !== '') {
						$workingXml = $r['preview_xml'];
					}
				}
			}
			$msg_join = implode(' ', $msgs);
			if ($had_fail) {
				if ($wizard_preview_ajax) {
					tt_fc_wizard_send_preview_ajax_json(false, $msg_join);
				}
				$GLOBALS['tt_fc_wizard_flash'] = array(
					'type' => 'danger',
					'msg' => $msg_join,
				);
			} else {
				$preview_msg = $msg_join !== '' ? ($msg_join . ' ') : '';
				$preview_msg .= 'Merged XML preview — Resources/settings.xml was not modified (see browser console for full XML when using Preview).';
				if ($wizard_preview_ajax) {
					$ajax_msg = $msg_join !== '' ? ($msg_join . ' ') : '';
					$ajax_msg .= 'Resources/settings.xml was not modified.';
					tt_fc_wizard_send_preview_ajax_json(true, $ajax_msg, $workingXml);
				}
				$GLOBALS['tt_fc_wizard_flash'] = array(
					'type' => 'info',
					'msg' => $preview_msg,
				);
			}
		}
	} else {
		$msgs = array();
		$any_change = false;
		$had_fail = false;
		$applied_fix_codes = array();
		foreach ($fixes as $fx) {
			$fx = strtolower(trim((string) $fx));
			if ($fx === 'allowlist_u') {
				if (!$spokenHardAllowlistPost && !$spokenSoftImplicitPost) {
					$msgs[] = 'Skipped allowlist merge (not applicable for this scan — flexicorp context_scope_regions already explicit or u already required).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_flexicorp_allowlist_append_u($settingsXmlPathPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'cqp_u_stub') {
				if (!$needCqpUStubPost) {
					$msgs[] = 'Skipped CQP u stub (region already declared for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_cqp_sattr_u_stub($settingsXmlPathPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'wavesurfer_tok') {
				if (!$spokenFixWavesurferTokPost) {
					$msgs[] = 'Skipped speech/highlight=TOK merge (not applicable for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_xmlfile_speech_highlight($settingsXmlPathPost, 'TOK');
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'wavesurfer_utt') {
				if (!$spokenFixWavesurferUttPost) {
					$msgs[] = 'Skipped speech/highlight utterance realignment (not applicable for this scan).';
					continue;
				}
				$uttDefPost = function_exists('getset') ? trim((string) getset('xmlfile/defaults/speechturn', 'U')) : 'U';
				$uttTagPost = strtoupper($uttDefPost !== '' ? $uttDefPost : 'U');
				$r = tt_fc_wizard_apply_merge_xmlfile_speech_highlight($settingsXmlPathPost, $uttTagPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'cqp_u_audio') {
				if (!$spokenFixMediaUAudioPost) {
					$msgs[] = 'Skipped CQP u_audio merge (not applicable for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_cqp_region_u_audio($settingsXmlPathPost, $msspPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'files_audio') {
				if (!$spokenFixFilesAudioPost) {
					$msgs[] = 'Skipped files/audio merge (not applicable for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_files_audio_slot($settingsXmlPathPost, $root);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'cqp_deprel_head') {
				if (!$depsFixCqpPattrsPost) {
					$msgs[] = 'Skipped CQP deprel/head p-attributes merge (not applicable for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_cqp_pattr_deprel_head($settingsXmlPathPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'cqp_s_region') {
				if (!$depsFixCqpSRegionPost) {
					$msgs[] = 'Skipped CQP sentence region `s` merge (not applicable for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_cqp_sattr_s_region($settingsXmlPathPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'cqp_s_tuid') {
				if (!$depsFixCqpSTuidPost) {
					$msgs[] = 'Skipped CQP s_tuid merge (not applicable for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_cqp_dep_s_tuid($settingsXmlPathPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'xmlfile_deprel_head') {
				if (!$depsFixXmlfileDeprelHeadPost) {
					$msgs[] = 'Skipped xmlfile deprel/head merge (not applicable for this scan).';
					continue;
				}
				$xfNeedSave = array();
				if (!isset($xfflipPost['deprel'])) {
					$xfNeedSave[] = 'deprel';
				}
				if (!isset($xfflipPost['head'])) {
					$xfNeedSave[] = 'head';
				}
				$r = tt_fc_wizard_apply_merge_xmlfile_pattr_keys($settingsXmlPathPost, $xfNeedSave);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			} elseif ($fx === 'cqp_ud_morph') {
				if (!$depsFixCqpMorphPost) {
					$msgs[] = 'Skipped CQP UD morphology p-attributes merge (not applicable for this scan).';
					continue;
				}
				$r = tt_fc_wizard_apply_merge_cqp_pattr_morph_keys($settingsXmlPathPost, $depsMorphFixKeysPost);
				$msgs[] = $r['message'];
				if (empty($r['ok'])) {
					$had_fail = true;
				}
				if (!empty($r['ok'])) {
					$applied_fix_codes[] = $fx;
				}
				if (!empty($r['ok']) && !empty($r['changes']) && is_array($r['changes']) && count($r['changes']) > 0) {
					$any_change = true;
				}
			}
		}
		foreach (array_unique($applied_fix_codes) as $_fxlog) {
			tt_fc_wizard_fix_applied_log_set($root, $_fxlog);
		}
		$GLOBALS['tt_fc_wizard_flash'] = array(
			'type' => $had_fail ? 'danger' : 'success',
			'msg' => implode(' ', $msgs),
		);
		if (($any_change || count($applied_fix_codes) > 0) && !headers_sent()) {
			$allowed_ret = array('overview', 'core', 'indexes', 'dependencies', 'spoken', 'facs', 'alignment', 'geo', 'samples');
			$ret_tab = isset($_POST['wizard_return_tab']) ? strtolower(trim((string) $_POST['wizard_return_tab'])) : 'spoken';
			if (!in_array($ret_tab, $allowed_ret, true)) {
				$ret_tab = 'spoken';
			}
			$redir = array(
				'action' => 'wizard',
				'tab' => $ret_tab,
				'sample' => $sample_post,
				'wizard_scope' => tt_fc_wizard_project_scope_key($root),
				'applied' => '1',
			);
			$wsf_list_redir = array();
			$wsf_scope_redir = isset($_POST['wizard_scope']) ? trim((string) $_POST['wizard_scope']) : '';
			if ($wsf_scope_redir !== '' && hash_equals(tt_fc_wizard_project_scope_key($root), $wsf_scope_redir) && isset($_POST['wizard_select_fix'])) {
				$wsf_list_redir = tt_fc_wizard_parse_wizard_select_fix_list((string) $_POST['wizard_select_fix']);
			}
			$applied_run = array_values(array_unique($applied_fix_codes));
			$remaining_wsf = array();
			foreach ($wsf_list_redir as $wc) {
				if (!in_array($wc, $applied_run, true)) {
					$remaining_wsf[] = $wc;
				}
			}
			if (count($remaining_wsf) > 0) {
				$redir['wizard_select_fix'] = implode(',', $remaining_wsf);
			}
			if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
				session_write_close();
			}
			header('Location: index.php?' . http_build_query($redir));
			exit;
		}
	}
}

$maintext .= tt_fc_wizard_render();

