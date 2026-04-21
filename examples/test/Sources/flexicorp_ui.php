<?php

declare(strict_types=1);

namespace FlexiCorpUI;

const STATS_CAPABILITY_KEYS = [
    'stats_freq_pattributes',
    'stats_freq_sattributes',
    'stats_relative_freq',
    'stats_collocations',
    'stats_dep_collocations',
    'stats_keyness',
    'stats_table_result',
];

function _joinPath(string $base, string $leaf): string
{
    return rtrim($base, "/") . "/" . ltrim($leaf, "/");
}

function _joinUrl(string $base, string $leaf): string
{
    return rtrim($base, "/") . "/" . ltrim($leaf, "/");
}

/**
 * Project-first PHP loader:
 * 1) project Sources/
 * 2) $sharedfolder/Sources
 * 3) $ttroot/common/Sources
 */
function loadsource(string $phpname): bool
{
    global $sharedfolder;
    global $ttroot;

    $tryfolders = [
        "Sources",
        (string) ($sharedfolder ? _joinPath((string) $sharedfolder, "Sources") : ""),
        (string) ($ttroot ? _joinPath((string) $ttroot, "common/Sources") : ""),
    ];

    foreach ($tryfolders as $tryfolder) {
        $tryfolder = trim((string) $tryfolder);
        if ($tryfolder === "") {
            continue;
        }
        $candidate = _joinPath($tryfolder, $phpname . ".php");
        if (file_exists($candidate)) {
            require_once $candidate;
            return true;
        }
    }

    return false;
}

/**
 * Project-first JS URL resolver:
 * 1) project Scripts/ + $scriptname.js (URL from $projecturl or relative fallback)
 * 2) $sharedfolder/Scripts + $scriptname.js => $sharedurl/Scripts/$scriptname.js
 * 3) $ttroot/common/Scripts + $scriptname.js => $jsurl/$scriptname.js
 *
 * Returns null when not found.
 */
function getjsurl(string $scriptname): ?string
{
    global $projecturl;
    global $sharedfolder;
    global $sharedurl;
    global $ttroot;
    global $jsurl;

    $scriptFile = $scriptname . ".js";

    $projectScriptPath = _joinPath("Scripts", $scriptFile);
    if (file_exists($projectScriptPath)) {
        $projectBaseUrl = trim((string) ($projecturl ?? ""));
        if ($projectBaseUrl !== "") {
            return _joinUrl(_joinUrl($projectBaseUrl, "Scripts"), $scriptFile);
        }
        return _joinUrl("Scripts", $scriptFile);
    }

    if (!empty($sharedfolder) && !empty($sharedurl)) {
        $sharedScriptPath = _joinPath(_joinPath((string) $sharedfolder, "Scripts"), $scriptFile);
        if (file_exists($sharedScriptPath)) {
            return _joinUrl(_joinUrl((string) $sharedurl, "Scripts"), $scriptFile);
        }
    }

    if (!empty($ttroot) && !empty($jsurl)) {
        $coreScriptPath = _joinPath(_joinPath((string) $ttroot, "common/Scripts"), $scriptFile);
        if (file_exists($coreScriptPath)) {
            return _joinUrl((string) $jsurl, $scriptFile);
        }
    }

    return null;
}

/**
 * Debug helper: returns where PHP/JS module resolution ran from, plus all attempts.
 */
function getFlexicorpSourceDebug(string $phpname, string $scriptname): array
{
    global $projecturl;
    global $sharedfolder;
    global $sharedurl;
    global $ttroot;
    global $jsurl;

    $phpAttempts = [];
    $phpResolved = null;
    $phpFolders = [
        ["label" => "project", "folder" => "Sources"],
        ["label" => "shared", "folder" => (string) ($sharedfolder ? _joinPath((string) $sharedfolder, "Sources") : "")],
        ["label" => "teitok_core", "folder" => (string) ($ttroot ? _joinPath((string) $ttroot, "common/Sources") : "")],
    ];
    foreach ($phpFolders as $row) {
        $folder = trim((string) ($row["folder"] ?? ""));
        if ($folder === "") {
            continue;
        }
        $path = _joinPath($folder, $phpname . ".php");
        $exists = file_exists($path);
        $entry = [
            "label" => (string) ($row["label"] ?? "unknown"),
            "path" => $path,
            "exists" => $exists,
        ];
        $phpAttempts[] = $entry;
        if ($exists && $phpResolved === null) {
            $phpResolved = $entry;
        }
    }

    $scriptFile = $scriptname . ".js";
    $projectJsUrl = trim((string) ($projecturl ?? "")) !== ""
        ? _joinUrl(_joinUrl((string) $projecturl, "Scripts"), $scriptFile)
        : _joinUrl("Scripts", $scriptFile);
    $jsCandidates = [
        [
            "label" => "project",
            "path" => _joinPath("Scripts", $scriptFile),
            "url" => $projectJsUrl,
        ],
        [
            "label" => "shared",
            "path" => (!empty($sharedfolder) ? _joinPath(_joinPath((string) $sharedfolder, "Scripts"), $scriptFile) : ""),
            "url" => (!empty($sharedurl) ? _joinUrl(_joinUrl((string) $sharedurl, "Scripts"), $scriptFile) : ""),
        ],
        [
            "label" => "teitok_core",
            "path" => (!empty($ttroot) ? _joinPath(_joinPath((string) $ttroot, "common/Scripts"), $scriptFile) : ""),
            "url" => (!empty($jsurl) ? _joinUrl((string) $jsurl, $scriptFile) : ""),
        ],
    ];
    $jsAttempts = [];
    $jsResolved = null;
    foreach ($jsCandidates as $row) {
        $path = trim((string) ($row["path"] ?? ""));
        if ($path === "") {
            continue;
        }
        $exists = file_exists($path);
        $entry = [
            "label" => (string) ($row["label"] ?? "unknown"),
            "path" => $path,
            "url" => (string) ($row["url"] ?? ""),
            "exists" => $exists,
        ];
        $jsAttempts[] = $entry;
        if ($exists && $jsResolved === null) {
            $jsResolved = $entry;
        }
    }

    return [
        "php" => [
            "module" => $phpname . ".php",
            "resolved" => $phpResolved,
            "attempts" => $phpAttempts,
        ],
        "js" => [
            "module" => $scriptFile,
            "resolved" => $jsResolved,
            "attempts" => $jsAttempts,
        ],
    ];
}

/**
 * Mirrors flexicorp query policy role detection in a PHP-friendly way.
 */
function isAdminUser(mixed $user): bool
{
    if (is_bool($user)) {
        return $user;
    }
    if ($user === null) {
        return false;
    }
    $text = strtolower(trim((string) $user));
    if ($text === '') {
        return false;
    }
    return !in_array($text, ['0', 'false', 'no', 'n', 'off', 'none', 'null'], true);
}

function roleFromUser(mixed $user): string
{
    return isAdminUser($user) ? 'admin' : 'visitor';
}

/**
 * Normalize Search/Stats routing data from flexicorp query responses.
 */
function extractQueryRoutingMeta(array $response): array
{
    $result = [];
    if (isset($response['result']) && is_array($response['result'])) {
        $result = $response['result'];
    } elseif (isset($response['done']['result']) && is_array($response['done']['result'])) {
        $result = $response['done']['result'];
    }

    return [
        'request_role' => (string) ($result['request_role'] ?? 'visitor'),
        'input_query_mode' => (string) ($result['input_query_mode'] ?? 'search'),
        'query_mode' => (string) ($result['query_mode'] ?? 'search'),
        'query_sanitized' => (bool) ($result['query_sanitized'] ?? false),
        'sanitized_query' => $result['sanitized_query'] ?? null,
        'suggested_tab' => (string) ($result['suggested_tab'] ?? 'search'),
        'result_type' => (string) ($result['result_type'] ?? 'hits'),
    ];
}

function resolveActiveTab(array $routingMeta, bool $hasBaseQuery): string
{
    $suggested = (string) ($routingMeta['suggested_tab'] ?? 'search');
    if ($suggested === 'stats' && $hasBaseQuery) {
        return 'stats';
    }
    return 'search';
}

function normalizeStatsCapabilities(array $capabilities): array
{
    $normalized = [];
    foreach (STATS_CAPABILITY_KEYS as $key) {
        $normalized[$key] = (bool) ($capabilities[$key] ?? false);
    }
    return $normalized;
}

function hasBaseQuery(?string $query): bool
{
    return trim((string) ($query ?? '')) !== '';
}

function isStatsTabEnabled(?string $query, array $capabilities): bool
{
    if (!hasBaseQuery($query)) {
        return false;
    }
    return count(listEnabledStatsOptions($capabilities)) > 0;
}

function isRunFrequencyEnabled(?string $query, array $capabilities): bool
{
    if (!hasBaseQuery($query)) {
        return false;
    }
    $caps = normalizeStatsCapabilities($capabilities);
    return $caps['stats_freq_pattributes'] || $caps['stats_freq_sattributes'];
}

function listEnabledStatsOptions(array $capabilities): array
{
    $caps = normalizeStatsCapabilities($capabilities);
    $options = [];

    if ($caps['stats_freq_pattributes']) {
        $options[] = 'freq_pattributes';
    }
    if ($caps['stats_freq_sattributes']) {
        $options[] = 'freq_sattributes';
    }
    if ($caps['stats_relative_freq']) {
        $options[] = 'relative_freq';
    }
    if ($caps['stats_collocations']) {
        $options[] = 'collocations';
    }
    if ($caps['stats_dep_collocations']) {
        $options[] = 'dep_collocations';
    }
    if ($caps['stats_keyness']) {
        $options[] = 'keyness';
    }
    if ($caps['stats_table_result']) {
        $options[] = 'table_result';
    }

    return $options;
}

