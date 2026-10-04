<?php
/**
 * flexicorp_pando — PHP FFI bridge (same pattern as flexicorp/flexicorp_pando/example_ffi.php).
 *
 * Resolves libflexicorp_pando.{dylib,so} + flexicorp_pando_ffi.h from:
 *   - FLEXICORP_PANDO_HOME, FLEXICORP_PANDO_LIB, FLEXICORP_PANDO_HEADER
 *   - Sibling ../flexicorp/flexicorp_pando/build/ (from this EasyCorp tree)
 *
 * Requires PHP ffi extension and ffi.enable (or preload) where applicable.
 */

if (!function_exists('easycorp_flexicorp_pando_paths')) {
	function easycorp_flexicorp_pando_paths(): ?array {
		$envHeader = getenv('FLEXICORP_PANDO_HEADER');
		$envLib = getenv('FLEXICORP_PANDO_LIB');
		$envHome = getenv('FLEXICORP_PANDO_HOME');
		if ($envHeader && is_readable($envHeader) && $envLib && is_readable($envLib)) {
			return ['header' => $envHeader, 'lib' => $envLib, 'home' => $envHome ?: dirname($envHeader)];
		}
		$isDarwin = defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Darwin';
		$name = $isDarwin ? 'libflexicorp_pando.dylib' : 'libflexicorp_pando.so';

		$bases = [];
		if ($envHome && is_dir($envHome)) {
			$bases[] = rtrim($envHome, '/\\');
		}
		// teitok/easycorp/Sources -> workspace root is dirname(..., 3)
		$sourcesDir = __DIR__;
		$workspaceRoot = dirname($sourcesDir, 3);
		$bases[] = dirname($workspaceRoot) . '/flexicorp/flexicorp_pando';
		$bases[] = $workspaceRoot . '/flexicorp/flexicorp_pando';

		$bases = array_values(array_unique(array_filter($bases)));
		foreach ($bases as $home) {
			$header = $home . '/flexicorp_pando_ffi.h';
			$lib = $home . '/build/' . $name;
			if (is_readable($header) && is_readable($lib)) {
				return ['header' => $header, 'lib' => $lib, 'home' => $home];
			}
		}
		return null;
	}
}

if (!function_exists('easycorp_flexicorp_pando_ffi_available')) {
	function easycorp_flexicorp_pando_ffi_available(): bool {
		return extension_loaded('ffi') && class_exists('FFI');
	}
}

if (!function_exists('easycorp_flexicorp_pando_ffi')) {
	function easycorp_flexicorp_pando_ffi(): ?FFI {
		static $ffi = false;
		if ($ffi !== false) {
			return $ffi;
		}
		if (!easycorp_flexicorp_pando_ffi_available()) {
			$ffi = null;
			return null;
		}
		$paths = easycorp_flexicorp_pando_paths();
		if ($paths === null) {
			$ffi = null;
			return null;
		}
		try {
			$cdef = file_get_contents($paths['header']);
			if ($cdef === false || $cdef === '') {
				$ffi = null;
				return null;
			}
			$ffi = FFI::cdef($cdef, $paths['lib']);
		} catch (Throwable $e) {
			$ffi = null;
			return null;
		}
		return $ffi;
	}
}

if (!function_exists('easycorp_flexicorp_pando_ctx_get')) {
	/**
	 * Open or reuse a flexicorp_pando context for this project root (TEITOK root).
	 *
	 * @return array{ffi: FFI, ctx: CData}|null
	 */
	function easycorp_flexicorp_pando_ctx_get(string $projectRoot): ?array {
		static $byRoot = [];
		$real = realpath($projectRoot);
		if ($real === false || $real === '') {
			return null;
		}
		if (isset($byRoot[$real])) {
			return $byRoot[$real];
		}
		$ffi = easycorp_flexicorp_pando_ffi();
		if ($ffi === null) {
			return null;
		}
		try {
			$ver = (int) $ffi->flexicorp_pando_api_version();
			if ($ver !== 1) {
				return null;
			}
			$ctx = $ffi->flexicorp_pando_open($real, null, 0);
			if (FFI::isNull($ctx)) {
				return null;
			}
			$byRoot[$real] = ['ffi' => $ffi, 'ctx' => $ctx];
			return $byRoot[$real];
		} catch (Throwable $e) {
			return null;
		}
	}
}

if (!function_exists('easycorp_flexicorp_pando_last_error_string')) {
	function easycorp_flexicorp_pando_last_error_string(): string {
		$ffi = easycorp_flexicorp_pando_ffi();
		if ($ffi === null) {
			if (!easycorp_flexicorp_pando_ffi_available()) {
				return 'PHP FFI extension not available or disabled (set ffi.enable in php.ini).';
			}
			if (easycorp_flexicorp_pando_paths() === null) {
				return 'flexicorp_pando library/header not found; set FLEXICORP_PANDO_HOME or FLEXICORP_PANDO_LIB / FLEXICORP_PANDO_HEADER.';
			}
			return 'Could not load flexicorp_pando shared library.';
		}
		try {
			$ptr = $ffi->flexicorp_pando_last_error(null);
			if ($ptr === null) {
				return '';
			}
			return FFI::string($ptr);
		} catch (Throwable $e) {
			return $e->getMessage();
		}
	}
}

if (!function_exists('easycorp_flexicorp_pando_index_dir_nonempty')) {
	function easycorp_flexicorp_pando_index_dir_nonempty(string $projectRoot): bool {
		$p = rtrim($projectRoot, '/\\') . '/pando';
		if (!is_dir($p)) {
			return false;
		}
		try {
			$it = new FilesystemIterator($p, FilesystemIterator::SKIP_DOTS);
			return $it->valid();
		} catch (Exception $e) {
			return false;
		}
	}
}

if (!function_exists('easycorp_flexicorp_pando_status_for_project')) {
	/**
	 * Snapshot for EasyCorp status JSON: FFI + library + corpus info from flexicorp_pando_info when possible.
	 *
	 * @return array<string, mixed>
	 */
	function easycorp_flexicorp_pando_status_for_project(string $projectRoot): array {
		$paths = easycorp_flexicorp_pando_paths();
		$out = [
			'ffi_extension'  => easycorp_flexicorp_pando_ffi_available(),
			'library_found'  => $paths !== null,
			'flexicorp_pando_home' => $paths['home'] ?? null,
		];
		if (!easycorp_flexicorp_pando_index_dir_nonempty($projectRoot)) {
			$out['status'] = 'skipped';
			$out['reason'] = 'No non-empty pando/ directory.';
			return $out;
		}
		if (!easycorp_flexicorp_pando_ffi_available()) {
			$out['status'] = 'unavailable';
			$out['reason'] = 'PHP FFI not available (enable extension ffi / ffi.enable).';
			return $out;
		}
		if ($paths === null) {
			$out['status'] = 'unavailable';
			$out['reason'] = 'flexicorp_pando shared library or flexicorp_pando_ffi.h not found; set FLEXICORP_PANDO_HOME.';
			return $out;
		}
		$info = easycorp_flexicorp_pando_info($projectRoot);
		if (!empty($info['ok'])) {
			$out['status'] = 'ok';
			$out['corpus'] = $info['result'] ?? null;
			return $out;
		}
		$out['status'] = 'error';
		$out['reason'] = (string) ($info['error'] ?? easycorp_flexicorp_pando_last_error_string());
		return $out;
	}
}

if (!function_exists('easycorp_flexicorp_pando_info')) {
	/**
	 * @return array{ok: bool, result?: array|null, raw?: string, error?: string}
	 */
	function easycorp_flexicorp_pando_info(string $projectRoot): array {
		$pair = easycorp_flexicorp_pando_ctx_get($projectRoot);
		if ($pair === null) {
			return [
				'ok'    => false,
				'error' => easycorp_flexicorp_pando_last_error_string(),
			];
		}
		$ffi = $pair['ffi'];
		$ctx = $pair['ctx'];
		try {
			$ptr = $ffi->flexicorp_pando_info($ctx);
			if ($ptr === null) {
				return ['ok' => false, 'error' => 'flexicorp_pando_info returned null.'];
			}
			$json = FFI::string($ptr);
			$ffi->flexicorp_pando_free($ptr);
			$data = json_decode($json, true);
			$inner = null;
			if (is_array($data) && isset($data['done']['result']) && is_array($data['done']['result'])) {
				$inner = $data['done']['result'];
			}
			return ['ok' => true, 'result' => $inner, 'raw' => $json];
		} catch (Throwable $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		}
	}
}

if (!function_exists('easycorp_flexicorp_pando_query')) {
	/**
	 * Run a Pando CQL query (same ABI as example_ffi.php).
	 *
	 * @return array{ok: bool, result?: mixed, raw?: string, error?: string}
	 */
	function easycorp_flexicorp_pando_query(
		string $projectRoot,
		string $query,
		int $offset = 0,
		int $limit = 25,
		int $maxTotal = 10000,
		int $context = 5,
		?string $attrs = null
	): array {
		$pair = easycorp_flexicorp_pando_ctx_get($projectRoot);
		if ($pair === null) {
			return [
				'ok'    => false,
				'error' => easycorp_flexicorp_pando_last_error_string(),
			];
		}
		$ffi = $pair['ffi'];
		$ctx = $pair['ctx'];
		try {
			$ptr = $ffi->flexicorp_pando_query(
				$ctx,
				$query,
				$offset,
				$limit,
				$maxTotal,
				$context,
				$attrs
			);
			if ($ptr === null) {
				return ['ok' => false, 'error' => 'flexicorp_pando_query returned null.'];
			}
			$json = FFI::string($ptr);
			$ffi->flexicorp_pando_free($ptr);
			$data = json_decode($json, true);
			return ['ok' => true, 'result' => $data, 'raw' => $json];
		} catch (Throwable $e) {
			return ['ok' => false, 'error' => $e->getMessage()];
		}
	}
}
