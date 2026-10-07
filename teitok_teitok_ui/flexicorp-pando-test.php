<?php
declare(strict_types=1);

/**
 * flexicorp-pando-test.php
 *
 * Default: small HTML UI with query box + AJAX.
 * API mode: ?api=1 returns JSON only.
 */

function fcpt_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function req_str(array $keys, string $default = ""): string {
    foreach ($keys as $k) {
        if (isset($_REQUEST[$k])) return trim((string)$_REQUEST[$k]);
    }
    return $default;
}

function req_int(array $keys, int $default = 0): int {
    foreach ($keys as $k) {
        if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== "") return (int)$_REQUEST[$k];
    }
    return $default;
}

function out_json(array $payload): void {
    if (!headers_sent()) header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function run_cmd(array $argv): array {
    $cmd = implode(" ", array_map("escapeshellarg", $argv));
    $spec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) return ["ok" => false, "error" => "proc_open failed", "command" => $cmd];
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = (int)proc_close($proc);
    return ["ok" => true, "command" => $cmd, "stdout" => $stdout, "stderr" => $stderr, "exit_code" => $exit];
}

function as_text_fragment(string $xml): string {
    $txt = preg_replace('/<[^>]+>/', ' ', $xml);
    $txt = preg_replace('/\s+/u', ' ', (string)$txt);
    return trim((string)$txt);
}

function xidx_read_lines(string $path): array {
    if (!is_file($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    return is_array($lines) ? $lines : [];
}

function xidx_u32le(string $raw): ?int {
    if (strlen($raw) < 4) return null;
    $v = unpack("Vv", $raw);
    return is_array($v) && isset($v["v"]) ? (int)$v["v"] : null;
}

function xidx_u64le(string $raw): ?int {
    if (strlen($raw) < 8) return null;
    $v = unpack("Pq", $raw);
    if (!is_array($v) || !isset($v["q"])) return null;
    // Corpus positions/offsets fit in signed 64-bit for our corpora.
    return (int)$v["q"];
}

function xidx_token_record_at(string $tokensBin, int $pos): ?array {
    if (!is_file($tokensBin) || $pos < 0) return null;
    $fh = @fopen($tokensBin, "rb");
    if (!$fh) return null;

    // C++ struct may be written as 32 (packed) or 40 (natural alignment) bytes.
    foreach ([32, 40] as $stride) {
        $off = $pos * $stride;
        if (@fseek($fh, $off, SEEK_SET) !== 0) continue;
        $rec = (string)@fread($fh, $stride);
        if (strlen($rec) !== $stride) continue;

        if ($stride === 32) {
            $corpusPos = xidx_u64le(substr($rec, 0, 8));
            $docIdx    = xidx_u32le(substr($rec, 8, 4));
            $xmlStart  = xidx_u64le(substr($rec, 12, 8));
            $xmlEnd    = xidx_u64le(substr($rec, 20, 8));
        } else {
            $corpusPos = xidx_u64le(substr($rec, 0, 8));
            $docIdx    = xidx_u32le(substr($rec, 8, 4));
            $xmlStart  = xidx_u64le(substr($rec, 16, 8));
            $xmlEnd    = xidx_u64le(substr($rec, 24, 8));
        }
        if ($corpusPos === null || $docIdx === null || $xmlStart === null || $xmlEnd === null) continue;
        if ($corpusPos !== $pos || $docIdx < 0 || $xmlEnd < $xmlStart) continue;

        fclose($fh);
        return [
            "corpus_pos" => $corpusPos,
            "doc_idx" => $docIdx,
            "xml_start" => $xmlStart,
            "xml_end" => $xmlEnd,
            "_stride" => $stride,
        ];
    }
    fclose($fh);
    return null;
}

function xidx_token_record_map(string $tokensBin): array {
    $map = [];
    if (!is_file($tokensBin)) return $map;
    $size = @filesize($tokensBin);
    if (!is_int($size) || $size <= 0) return $map;

    $stride = null;
    if ($size % 40 === 0) $stride = 40;
    else if ($size % 32 === 0) $stride = 32;
    else $stride = 40; // prefer natural-aligned writer layout

    $fh = @fopen($tokensBin, "rb");
    if (!$fh) return $map;
    while (!feof($fh)) {
        $rec = (string)@fread($fh, $stride);
        if (strlen($rec) !== $stride) break;
        if ($stride === 32) {
            $corpusPos = xidx_u64le(substr($rec, 0, 8));
            $docIdx    = xidx_u32le(substr($rec, 8, 4));
            $xmlStart  = xidx_u64le(substr($rec, 12, 8));
            $xmlEnd    = xidx_u64le(substr($rec, 20, 8));
        } else {
            $corpusPos = xidx_u64le(substr($rec, 0, 8));
            $docIdx    = xidx_u32le(substr($rec, 8, 4));
            $xmlStart  = xidx_u64le(substr($rec, 16, 8));
            $xmlEnd    = xidx_u64le(substr($rec, 24, 8));
        }
        if ($corpusPos === null || $docIdx === null || $xmlStart === null || $xmlEnd === null) continue;
        if ($docIdx < 0 || $xmlEnd < $xmlStart) continue;
        $map[(string)$corpusPos] = [
            "corpus_pos" => $corpusPos,
            "doc_idx" => $docIdx,
            "xml_start" => $xmlStart,
            "xml_end" => $xmlEnd,
            "_stride" => $stride,
        ];
    }
    fclose($fh);
    return $map;
}

function xidx_find_sentence_span(string $regionsBin, int $typeIdx, int $docIdx, int $corpusPos): ?array {
    if (!is_file($regionsBin) || $typeIdx < 0 || $docIdx < 0 || $corpusPos < 0) return null;
    $fh = @fopen($regionsBin, "rb");
    if (!$fh) return null;
    $stride = 40;
    while (!feof($fh)) {
        $rec = (string)@fread($fh, $stride);
        if (strlen($rec) !== $stride) break;
        $rtype = xidx_u32le(substr($rec, 0, 4));
        $rdoc  = xidx_u32le(substr($rec, 4, 4));
        $rstart = xidx_u64le(substr($rec, 16, 8));
        $rend   = xidx_u64le(substr($rec, 24, 8));
        if ($rtype === null || $rdoc === null || $rstart === null || $rend === null) continue;
        if ($rtype !== $typeIdx || $rdoc !== $docIdx) continue;
        if ($corpusPos >= $rstart && $corpusPos <= $rend) {
            fclose($fh);
            return ["start_pos" => $rstart, "end_pos" => $rend];
        }
    }
    fclose($fh);
    return null;
}

function xidx_read_xml_slice(string $xmlPath, int $xmlStart, int $xmlEnd): ?string {
    if (!is_file($xmlPath) || $xmlStart < 0 || $xmlEnd < $xmlStart) return null;
    $len = $xmlEnd - $xmlStart;
    if ($len <= 0) $len = 1;
    $fh = @fopen($xmlPath, "rb");
    if (!$fh) return null;
    if (@fseek($fh, $xmlStart, SEEK_SET) !== 0) {
        fclose($fh);
        return null;
    }
    $frag = (string)@fread($fh, $len);
    fclose($fh);
    return $frag !== "" ? $frag : null;
}

function xidx_doc_id_from_relpath(string $relPath): string {
    $base = basename($relPath);
    if (substr($base, -4) === ".xml") return substr($base, 0, -4);
    return $base;
}

function enrich_fragments_result_xidx(array &$result, string $projectRoot, string $scope, string $fmt): void {
    if (!isset($result["hits"]) || !is_array($result["hits"])) return;
    $xidxDir = rtrim($projectRoot, "/") . "/xidx";
    $tokensBin = $xidxDir . "/tokens.bin";
    $regionsBin = $xidxDir . "/regions.bin";
    $docs = xidx_read_lines($xidxDir . "/docs.tbl");
    $regionTypes = xidx_read_lines($xidxDir . "/region_types.tbl");
    if (!is_file($tokensBin) || !is_file($regionsBin) || empty($docs) || empty($regionTypes)) return;

    $sentTypeIdx = array_search("s", $regionTypes, true);
    if ($sentTypeIdx === false) $sentTypeIdx = array_search("seg", $regionTypes, true);
    if ($sentTypeIdx === false) $sentTypeIdx = -1;

    foreach ($result["hits"] as &$hit) {
        if (!is_array($hit)) continue;
        if (!isset($hit["match_start"])) continue;
        $pos = (int)$hit["match_start"];
        $tokRec = xidx_token_record_at($tokensBin, $pos);
        if (!is_array($tokRec)) continue;
        $docIdx = (int)$tokRec["doc_idx"];
        if (!isset($docs[$docIdx])) continue;
        $relPath = $docs[$docIdx];
        $xmlPath = rtrim($projectRoot, "/") . "/" . ltrim($relPath, "/");
        if (!is_file($xmlPath)) continue;

        // Flexicorp usually works with TEITOK text_id; fallback to doc_id key here.
        $textId = xidx_doc_id_from_relpath($relPath);
        if (!isset($hit["doc_id"]) || $hit["doc_id"] === null || $hit["doc_id"] === "") $hit["doc_id"] = $textId;
        if (!isset($hit["text_id"]) || $hit["text_id"] === null || $hit["text_id"] === "") $hit["text_id"] = $textId;

        $fragXml = null;
        if ($sentTypeIdx >= 0) {
            $span = xidx_find_sentence_span($regionsBin, (int)$sentTypeIdx, $docIdx, $pos);
            if (is_array($span)) {
                $startTok = xidx_token_record_at($tokensBin, (int)$span["start_pos"]);
                $endTok = xidx_token_record_at($tokensBin, (int)$span["end_pos"]);
                if (is_array($startTok) && is_array($endTok)) {
                    $fragXml = xidx_read_xml_slice($xmlPath, (int)$startTok["xml_start"], (int)$endTok["xml_end"]);
                }
            }
        }
        if (!is_string($fragXml) || $fragXml === "") {
            // Fallback: token-level snippet if sentence span is unavailable.
            $fragXml = xidx_read_xml_slice($xmlPath, (int)$tokRec["xml_start"], (int)$tokRec["xml_end"]);
        }
        if (!is_string($fragXml) || $fragXml === "") continue;

        $hit["fragment"] = [
            "scope" => $scope,
            "format" => $fmt,
            "source" => "xidx",
            "xmlfile" => $xmlPath,
            "data" => $fmt === "text" ? as_text_fragment($fragXml) : $fragXml,
        ];
    }
    unset($hit);
}

function enrich_hits_xidx(array &$hits, string $projectRoot, string $scope, string $fmt, ?array &$debug = null): void {
    $xidxDir = rtrim($projectRoot, "/") . "/xidx";
    $tokensBin = $xidxDir . "/tokens.bin";
    $regionsBin = $xidxDir . "/regions.bin";
    $docsPath = $xidxDir . "/docs.tbl";
    $typesPath = $xidxDir . "/region_types.tbl";
    $docs = xidx_read_lines($docsPath);
    $regionTypes = xidx_read_lines($typesPath);
    if (is_array($debug)) {
        $debug["xidx_dir"] = $xidxDir;
        $debug["tokens_bin_exists"] = is_file($tokensBin);
        $debug["regions_bin_exists"] = is_file($regionsBin);
        $debug["docs_tbl_exists"] = is_file($docsPath);
        $debug["region_types_tbl_exists"] = is_file($typesPath);
        $debug["docs_count"] = count($docs);
        $debug["region_types_count"] = count($regionTypes);
    }
    if (!is_file($tokensBin) || !is_file($regionsBin) || empty($docs) || empty($regionTypes)) return;
    $tokMap = xidx_token_record_map($tokensBin);
    if (is_array($debug)) {
        $debug["token_map_count"] = count($tokMap);
    }
    if (empty($tokMap)) return;

    $sentTypeIdx = array_search("s", $regionTypes, true);
    if ($sentTypeIdx === false) $sentTypeIdx = array_search("seg", $regionTypes, true);
    if ($sentTypeIdx === false) $sentTypeIdx = -1;

    foreach ($hits as &$hit) {
        if (!is_array($hit) || !isset($hit["match_start"])) continue;
        $pos = (int)$hit["match_start"];
        $tokRec = $tokMap[(string)$pos] ?? null;
        if (!is_array($tokRec) && $pos > 0) {
            // Some engines report 1-based corpus positions.
            $tokRec = $tokMap[(string)($pos - 1)] ?? null;
        }
        if (!is_array($tokRec)) continue;
        $docIdx = (int)$tokRec["doc_idx"];
        if (!isset($docs[$docIdx])) continue;
        $relPath = $docs[$docIdx];
        $xmlPath = rtrim($projectRoot, "/") . "/" . ltrim($relPath, "/");
        if (!is_file($xmlPath)) continue;

        $textId = xidx_doc_id_from_relpath($relPath);
        if (!isset($hit["doc_id"]) || $hit["doc_id"] === null || $hit["doc_id"] === "") $hit["doc_id"] = $textId;
        if (!isset($hit["text_id"]) || $hit["text_id"] === null || $hit["text_id"] === "") $hit["text_id"] = $textId;

        $fragXml = null;
        if ($sentTypeIdx >= 0) {
            $span = xidx_find_sentence_span($regionsBin, (int)$sentTypeIdx, $docIdx, $pos);
            if (is_array($span)) {
                $startTok = xidx_token_record_at($tokensBin, (int)$span["start_pos"]);
                $endTok = xidx_token_record_at($tokensBin, (int)$span["end_pos"]);
                if (!is_array($startTok)) $startTok = $tokMap[(string)((int)$span["start_pos"])] ?? null;
                if (!is_array($endTok)) $endTok = $tokMap[(string)((int)$span["end_pos"])] ?? null;
                if (is_array($startTok) && is_array($endTok)) {
                    $fragXml = xidx_read_xml_slice($xmlPath, (int)$startTok["xml_start"], (int)$endTok["xml_end"]);
                }
            }
        }
        if (!is_string($fragXml) || $fragXml === "") {
            $fragXml = xidx_read_xml_slice($xmlPath, (int)$tokRec["xml_start"], (int)$tokRec["xml_end"]);
        }
        if (!is_string($fragXml) || $fragXml === "") continue;

        $hit["fragment"] = [
            "scope" => $scope,
            "format" => $fmt,
            "source" => "xidx",
            "xmlfile" => $xmlPath,
            "data" => $fmt === "text" ? as_text_fragment($fragXml) : $fragXml,
        ];
    }
    unset($hit);
}

function find_xml_path_for_tok(string $projectRoot, string $tokId): ?string {
    $xmlRoot = rtrim($projectRoot, "/") . "/xmlfiles";
    if (!is_dir($xmlRoot)) return null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($xmlRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || strtolower($f->getExtension()) !== "xml") continue;
        $path = $f->getPathname();
        $raw = @file_get_contents($path);
        if ($raw === false) continue;
        if (strpos($raw, 'id="' . $tokId . '"') !== false || strpos($raw, "id='" . $tokId . "'") !== false) return $path;
    }
    return null;
}

function extract_sentence_fragment_from_xml(string $xmlPath, string $sentId, string $tokId): ?string {
    $raw = @file_get_contents($xmlPath);
    if ($raw === false) return null;
    $qid = preg_quote($sentId, '/');
    if (preg_match('/<s\b[^>]*\bid=(["\'])' . $qid . '\1[^>]*>.*?<\/s>/su', $raw, $m)) return $m[0];
    $qt = preg_quote($tokId, '/');
    if (preg_match('/<s\b[^>]*>.*?\bid=(["\'])' . $qt . '\1.*?<\/s>/su', $raw, $m2)) return $m2[0];
    return null;
}

function enrich_fragments_done(array &$done, string $projectRoot, string $scope, string $fmt): void {
    if (!isset($done["result"]["hits"]) || !is_array($done["result"]["hits"])) return;
    foreach ($done["result"]["hits"] as &$hit) {
        if (!is_array($hit)) continue;
        $tokId = null;
        if (isset($hit["tokens"]) && is_array($hit["tokens"]) && isset($hit["tokens"][0]) && is_array($hit["tokens"][0])) {
            $tokId = $hit["tokens"][0]["tuid"] ?? $hit["tokens"][0]["id"] ?? null;
        }
        if (!is_string($tokId) || $tokId === "") continue;
        $sentId = preg_replace('/\.[^.]+$/', '', $tokId);
        if (!is_string($sentId) || $sentId === "") $sentId = $tokId;
        $xmlPath = find_xml_path_for_tok($projectRoot, $tokId);
        if ($xmlPath === null) continue;
        $fragXml = extract_sentence_fragment_from_xml($xmlPath, $sentId, $tokId);
        if (!is_string($fragXml) || $fragXml === "") continue;
        $hit["fragment"] = [
            "scope" => $scope,
            "format" => $fmt,
            "source" => "xml-scan",
            "xmlfile" => $xmlPath,
            "sentence_id" => $sentId,
            "token_id" => $tokId,
            "data" => $fmt === "text" ? as_text_fragment($fragXml) : $fragXml,
        ];
    }
    unset($hit);
}

function daemon_try(string $daemonSocket, array $req): array {
    $sockPath = "unix://" . $daemonSocket;
    $errno = 0;
    $errstr = "";
    $fp = @stream_socket_client($sockPath, $errno, $errstr, 1.0);
    if (!$fp) {
        return ["ok" => false, "error" => "daemon socket connect failed", "daemon_socket" => $daemonSocket, "socket_error" => $errstr, "socket_errno" => $errno, "_engine" => "daemon"];
    }
    stream_set_timeout($fp, 5);
    $wire = [
        "action" => "query",
        "corpus" => ($req["index_dir"] ?? "") !== "" ? $req["index_dir"] : (rtrim((string)($req["project_root"] ?? ""), "/") . "/pando"),
        "query" => (string)($req["query"] ?? ""),
        "offset" => (int)($req["offset"] ?? 0),
        "limit" => (int)($req["limit"] ?? 20),
        "max_total" => (int)($req["max_total"] ?? 10000),
        "context" => (int)($req["context"] ?? 5),
        "attrs" => (string)($req["attrs"] ?? ""),
        "client_ip" => (string)($req["client_ip"] ?? ""),
        "request_time" => (string)($req["request_time"] ?? ""),
        "request_id" => (string)($req["request_id"] ?? ""),
        "user_agent" => (string)($req["user_agent"] ?? ""),
    ];
    if (@fwrite($fp, json_encode($wire, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) {
        fclose($fp);
        return ["ok" => false, "error" => "daemon socket write failed", "daemon_socket" => $daemonSocket, "_engine" => "daemon"];
    }
    $resp = "";
    $started = microtime(true);
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta["timed_out"])) break;
            usleep(10000);
            if ((microtime(true) - $started) > 5.0) break;
            continue;
        }
        $resp .= $line;
        $probe = json_decode($resp, true);
        if (is_array($probe)) {
            fclose($fp);
            $probe["_engine"] = "daemon";
            return $probe;
        }
    }
    fclose($fp);
    return ["ok" => false, "error" => "daemon returned non-JSON", "daemon_socket" => $daemonSocket, "raw" => $resp, "_engine" => "daemon"];
}

function ffi_try(string $ffiHeader, string $ffiLib, string $projectRoot, string $indexDir, string $query, int $offset, int $limit, int $maxTotal, int $context, string $attrs): array {
    if (!class_exists("FFI")) return ["ok" => false, "error" => "PHP FFI extension/class not available", "_engine" => "ffi"];
    if (!is_file($ffiHeader)) return ["ok" => false, "error" => "FFI header not found", "ffi_header" => $ffiHeader, "_engine" => "ffi"];
    if (!is_file($ffiLib)) return ["ok" => false, "error" => "FFI library not found", "ffi_lib" => $ffiLib, "_engine" => "ffi"];
    try {
        $ffi = FFI::cdef(file_get_contents($ffiHeader), $ffiLib);
        $ctx = $ffi->flexicorp_pando_open($projectRoot !== "" ? $projectRoot : null, $indexDir !== "" ? $indexDir : null, 0);
        if (FFI::isNull($ctx)) return ["ok" => false, "error" => "FFI open failed: " . FFI::string($ffi->flexicorp_pando_last_error(null)), "_engine" => "ffi"];
        $res = $ffi->flexicorp_pando_query($ctx, $query, max(0, $offset), max(1, $limit), max(1, $maxTotal), max(0, $context), $attrs !== "" ? $attrs : null);
        $json = FFI::string($res);
        $ffi->flexicorp_pando_free($res);
        $ffi->flexicorp_pando_close($ctx);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return ["ok" => false, "error" => "FFI returned non-JSON", "raw" => $json, "_engine" => "ffi"];
        $decoded["_engine"] = "ffi";
        return $decoded;
    } catch (Throwable $e) {
        return ["ok" => false, "error" => "FFI exception: " . $e->getMessage(), "_engine" => "ffi"];
    }
}

function cli_try(string $cppCliBin, string $projectRoot, string $indexDir, string $query, int $offset, int $limit, int $maxTotal, int $context, string $attrs): array {
    if (!is_file($cppCliBin)) return ["ok" => false, "error" => "CLI binary not found", "_engine" => "cli", "flexicorp_pando_bin" => $cppCliBin];
    $cmd = [$cppCliBin];
    if ($indexDir !== "") { $cmd[] = "--index-dir"; $cmd[] = $indexDir; }
    else { $cmd[] = "--project-root"; $cmd[] = $projectRoot; }
    $cmd[] = "-q"; $cmd[] = $query;
    $cmd[] = "--offset"; $cmd[] = (string)max(0, $offset);
    $cmd[] = "--limit"; $cmd[] = (string)max(1, $limit);
    $cmd[] = "--max-total"; $cmd[] = (string)max(1, $maxTotal);
    $cmd[] = "--context"; $cmd[] = (string)max(0, $context);
    if ($attrs !== "") { $cmd[] = "--attrs"; $cmd[] = $attrs; }
    $run = run_cmd($cmd);
    if (!($run["ok"] ?? false)) return ["ok" => false, "error" => "CLI execution failed", "_engine" => "cli", "command" => ($run["command"] ?? "")];
    $decoded = json_decode((string)$run["stdout"], true);
    if (!is_array($decoded)) return ["ok" => false, "error" => "CLI returned non-JSON on stdout", "_engine" => "cli", "command" => $run["command"], "stdout" => $run["stdout"], "stderr" => $run["stderr"], "exit_code" => $run["exit_code"]];
    if (trim((string)$run["stderr"]) !== "") $decoded["_stderr"] = trim((string)$run["stderr"]);
    $decoded["_exit_code"] = (int)$run["exit_code"];
    $decoded["_engine"] = "cli";
    return $decoded;
}

$projectRoot = req_str(["project_root", "projectRoot", "root", "project", "p", "folder"]);
$indexDir = req_str(["index_dir", "indexDir", "pando_index", "pandodir"]);
$query = req_str(["q", "query", "cql"], '[form="de"]');
$offset = req_int(["offset", "start"], 0);
$limit = req_int(["limit", "max"], 20);
$context = req_int(["context", "window"], 5);
$maxTotal = req_int(["max_total", "maxtotal"], 10000);
$attrs = req_str(["attrs"], "");
$mode = strtolower(req_str(["mode"], "daemon")); // daemon|ffi|cli|auto
// Defaults are relative to this flexicorp checkout (this test page is not installed into TEITOK).
$flexicorpRoot = dirname(__DIR__);
$libExt = (PHP_OS_FAMILY === "Darwin") ? "dylib" : "so";
$daemonSocket = req_str(["daemon_socket", "socket"], $flexicorpRoot . "/tmp/flexicorp-pando.sock");
$allowCliFallback = req_str(["allow_cli_fallback", "cli_fallback"], "0") === "1";
$ffiHeader = req_str(["ffi_header"], $flexicorpRoot . "/flexicorp_pando/flexicorp_pando_ffi.h");
$ffiLib = req_str(["ffi_lib"], $flexicorpRoot . "/flexicorp_pando/build/libflexicorp_pando." . $libExt);
$cppCliBin = req_str(["flexicorp_pando_bin"], $flexicorpRoot . "/flexicorp_pando/build/flexicorp-pando");
$extractFragments = req_str(["extract_fragments", "extract_xml"], "1") === "1";
$contextScope = req_str(["context_scope"], "s");
$contextFormat = strtolower(req_str(["context_format"], "xml"));
if (!in_array($contextFormat, ["xml", "text"], true)) $contextFormat = "xml";

if ($projectRoot === "" && $indexDir === "") $projectRoot = dirname(__DIR__);

$clientIp = "";
if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
    $parts = explode(",", (string)$_SERVER["HTTP_X_FORWARDED_FOR"]);
    $clientIp = trim((string)$parts[0]);
}
if ($clientIp === "" && !empty($_SERVER["REMOTE_ADDR"])) $clientIp = (string)$_SERVER["REMOTE_ADDR"];

$req = [
    "project_root" => $projectRoot,
    "index_dir" => $indexDir,
    "query" => $query,
    "offset" => $offset,
    "limit" => $limit,
    "max_total" => $maxTotal,
    "context" => $context,
    "attrs" => $attrs,
    "client_ip" => $clientIp,
    "request_time" => gmdate("c"),
    "request_id" => substr(sha1(uniqid("fcpp_", true)), 0, 16),
    "user_agent" => isset($_SERVER["HTTP_USER_AGENT"]) ? (string)$_SERVER["HTTP_USER_AGENT"] : "",
];

$result = null;
$attempts = [];
if ($mode === "daemon" || $mode === "auto") {
    $r = daemon_try($daemonSocket, $req);
    $attempts[] = $r;
    if (($r["ok"] ?? false) || $mode === "daemon") $result = $r;
}
if ($result === null && ($mode === "ffi" || $mode === "auto")) {
    $r = ffi_try($ffiHeader, $ffiLib, $projectRoot, $indexDir, $query, $offset, $limit, $maxTotal, $context, $attrs);
    $attempts[] = $r;
    if (($r["ok"] ?? false) || $mode === "ffi") $result = $r;
}
if ($result === null && ($mode === "cli" || ($mode === "auto" && $allowCliFallback))) {
    $r = cli_try($cppCliBin, $projectRoot, $indexDir, $query, $offset, $limit, $maxTotal, $context, $attrs);
    $attempts[] = $r;
    $result = $r;
}

$xidxDebug = [];
if (is_array($result) && ($result["ok"] ?? false) && $extractFragments && $projectRoot !== "") {
    if (isset($result["done"]["result"]["hits"]) && is_array($result["done"]["result"]["hits"])) {
        enrich_hits_xidx($result["done"]["result"]["hits"], $projectRoot, $contextScope, $contextFormat, $xidxDebug);
    } else if (isset($result["result"]["hits"]) && is_array($result["result"]["hits"])) {
        enrich_hits_xidx($result["result"]["hits"], $projectRoot, $contextScope, $contextFormat, $xidxDebug);
    } else if (isset($result["done"]) && is_array($result["done"])) {
        // Legacy fallback: token-id XML scan for odd envelopes.
        enrich_fragments_done($result["done"], $projectRoot, $contextScope, $contextFormat);
    }
}

$resultPayload = is_array($result) ? $result : [
    "ok" => false,
    "error" => "No usable execution mode. Tried daemon/ffi; CLI fallback disabled or unavailable.",
    "hint" => "Use mode=daemon, mode=ffi, or mode=auto&allow_cli_fallback=1.",
    "_attempts" => $attempts,
];
$resultPayload["_xidx_debug"] = $xidxDebug;
$resultPayload["_request_debug"] = $req;

$apiMode = req_str(["api"], "0") === "1";
if ($apiMode) out_json($resultPayload);

if (!headers_sent()) header("Content-Type: text/html; charset=utf-8");
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>flexicorp-pando test</title>
  <style>
    body { font-family: sans-serif; margin: 1rem; }
    input[type=text] { width: 100%; max-width: 1100px; }
    .row { margin-bottom: .6rem; }
    pre { background: #111; color: #eee; padding: .8rem; overflow: auto; white-space: pre-wrap; }
    .small { color: #666; font-size: .9em; }
  </style>
</head>
<body>
  <h2>flexicorp-pando test</h2>
  <p class="small">Simple query UI that mimics the AJAX flow: request to API endpoint, raw JSON shown below.</p>

  <div class="row"><label>Query</label><br /><input id="q" type="text" value="<?php echo fcpt_h($query); ?>" /></div>
  <div class="row"><label>Project root (needed for XML fragments)</label><br /><input id="project_root" type="text" value="<?php echo fcpt_h($projectRoot); ?>" /></div>
  <div class="row"><label>Index dir</label><br /><input id="index_dir" type="text" value="<?php echo fcpt_h($indexDir); ?>" /></div>
  <div class="row"><label>Daemon socket</label><br /><input id="daemon_socket" type="text" value="<?php echo fcpt_h($daemonSocket); ?>" /></div>
  <div class="row">
    <label>Mode:
      <select id="mode">
        <option value="daemon">daemon</option>
        <option value="ffi">ffi</option>
        <option value="cli">cli</option>
        <option value="auto">auto</option>
      </select>
    </label>
  </div>
  <div class="row"><label><input id="extract_fragments" type="checkbox" checked /> Extract XML fragments (demo best-effort)</label></div>
  <div class="row"><button id="submit_btn" type="button">Submit</button></div>
  <pre id="out"><?php echo fcpt_h(json_encode($resultPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>

  <script>
    (function () {
      const modeSel = document.getElementById('mode');
      modeSel.value = '<?php echo fcpt_h($mode); ?>';
      document.getElementById('submit_btn').addEventListener('click', async function () {
        const p = new URLSearchParams();
        p.set('api', '1');
        p.set('mode', modeSel.value);
        p.set('q', document.getElementById('q').value);
        p.set('project_root', document.getElementById('project_root').value);
        p.set('index_dir', document.getElementById('index_dir').value);
        p.set('daemon_socket', document.getElementById('daemon_socket').value);
        p.set('extract_fragments', document.getElementById('extract_fragments').checked ? '1' : '0');
        p.set('context_scope', 's');
        p.set('context_format', 'xml');
        const url = window.location.pathname + '?' + p.toString();
        const out = document.getElementById('out');
        out.textContent = 'Loading...';
        try {
          const res = await fetch(url, { credentials: 'same-origin' });
          const txt = await res.text();
          try { out.textContent = JSON.stringify(JSON.parse(txt), null, 2); }
          catch (_e) { out.textContent = txt; }
        } catch (e) {
          out.textContent = String(e);
        }
      });
    })();
  </script>
</body>
</html>

