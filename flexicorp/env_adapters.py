from __future__ import annotations

import importlib
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Protocol
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlparse
from urllib.request import Request, urlopen

from .env_config import resolve_pmltq_native_server_url

from .clickhouse_errors import format_clickhouse_error_message


def _parse_native_pmltq_treebanks_payload(payload: Any) -> List[str]:
    """Extract treebank ids from ``GET /v1/treebanks`` JSON (PMLTQ HTTP server)."""
    out: List[str] = []
    if payload is None:
        return out
    if isinstance(payload, list):
        for item in payload:
            if isinstance(item, dict):
                nid = item.get("id") or item.get("name") or item.get("treebank_id")
                if nid is not None and str(nid).strip():
                    out.append(str(nid).strip())
            elif isinstance(item, str) and item.strip():
                out.append(item.strip())
        return out
    if isinstance(payload, dict):
        for key in ("data", "treebanks", "items", "results"):
            sub = payload.get(key)
            if sub is not None:
                out.extend(_parse_native_pmltq_treebanks_payload(sub))
    return out


def _probe_native_pmltq_api(base_url: str) -> Dict[str, Any]:
    """
    GET ``/v1/treebanks``: HTTP must succeed and JSON must list at least one treebank
    (implies PostgreSQL behind the server is populated for corpora).
    """
    url = f"{base_url.rstrip('/')}/v1/treebanks"
    out: Dict[str, Any] = {
        "http_ok": False,
        "http_code": 0,
        "parse_ok": False,
        "treebank_ids": [],
        "error": "",
        "body_excerpt": "",
    }
    raw: bytes = b""
    code = 0
    try:
        req = Request(url, method="GET", headers={"Accept": "application/json"})
        with urlopen(req, timeout=2.5) as resp:
            code = int(getattr(resp, "status", 0) or 0)
            raw = resp.read()
    except HTTPError as exc:
        code = int(getattr(exc, "code", 0) or 0)
        try:
            raw = exc.read()
        except Exception:
            raw = b""
        out["error"] = f"HTTP {code}"
    except Exception as exc:
        out["error"] = str(exc)
        return out

    out["http_ok"] = 200 <= code < 500
    out["http_code"] = code
    if not raw:
        out["error"] = out["error"] or "empty response body"
        return out
    try:
        parsed = json.loads(raw.decode("utf-8", errors="replace"))
    except Exception as exc:
        out["error"] = f"invalid JSON: {exc}"
        if len(raw) > 200:
            out["body_excerpt"] = raw[:200].decode("utf-8", errors="replace") + "…"
        return out
    out["parse_ok"] = True
    ids = _parse_native_pmltq_treebanks_payload(parsed)
    out["treebank_ids"] = ids
    if not ids:
        out["catalog_error"] = (
            "no treebanks in API response (PostgreSQL may be empty or migrations incomplete)"
        )
    return out
from .teitok import (
    detect_teitok_blacklab,
    detect_teitok_clickhouse,
    detect_teitok_cqp,
    detect_teitok_manatee,
)


def cmd_check(name: str) -> Dict[str, Any]:
    path = shutil.which(name) or ""
    info: Dict[str, Any] = {
        "name": name,
        "found": bool(path),
        "path": path,
        "runnable": False,
    }
    if not path:
        return info
    info["runnable"] = os.access(path, os.X_OK)
    try:
        proc = subprocess.run(
            ["file", "-L", path],
            capture_output=True,
            text=True,
            check=False,
        )
        if proc.stdout:
            info["file"] = proc.stdout.strip()
    except Exception:
        pass
    return info


def _run_json_command(argv: List[str], *, timeout: float = 3.0) -> Dict[str, Any]:
    out: Dict[str, Any] = {
        "ok": False,
        "argv": list(argv),
        "raw": "",
        "json": None,
        "error": "",
    }
    try:
        proc = subprocess.run(
            argv,
            capture_output=True,
            text=True,
            check=False,
            timeout=timeout,
        )
    except Exception as exc:
        out["error"] = str(exc)
        return out

    raw = (proc.stdout or "") + ("\n" + proc.stderr if proc.stderr else "")
    out["raw"] = raw.strip()
    if proc.returncode != 0:
        out["error"] = f"exit code {proc.returncode}"
        return out
    try:
        out["json"] = json.loads(proc.stdout or "")
        out["ok"] = True
    except Exception as exc:
        out["error"] = f"invalid JSON: {exc}"
    return out


def _coerce_corpora_list(payload: Any) -> List[Dict[str, Any]]:
    if isinstance(payload, list):
        return [row for row in payload if isinstance(row, dict)]
    if isinstance(payload, dict):
        corpora = payload.get("corpora")
        if isinstance(corpora, list):
            return [row for row in corpora if isinstance(row, dict)]
    return []


def _fcs_enabled(corpus: Dict[str, Any]) -> bool:
    caps = corpus.get("capabilities")
    if not isinstance(caps, dict):
        return False
    fcs = caps.get("fcs")
    if not isinstance(fcs, dict):
        return False
    return bool(fcs.get("enabled"))


def _resolve_fqs_bin(env_cfg: Dict[str, Any] | None = None) -> str:
    cfg = dict((env_cfg or {}).get("fqs") or {})
    for key in ("bin", "path", "binary"):
        cand = str(cfg.get(key) or "").strip()
        if cand and Path(cand).is_file() and os.access(cand, os.X_OK):
            return cand
    for cand in (os.environ.get("FQS_BIN", "").strip(), shutil.which("fqs") or ""):
        if cand and Path(cand).is_file() and os.access(cand, os.X_OK):
            return cand
    return ""


@dataclass
class EnvContext:
    project_root: Path
    corpus_id: str | None = None
    env_config: Dict[str, Any] | None = None


class EnvAdapter(Protocol):
    name: str
    description: str
    kind: str
    depends_on_backends: List[str]

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        ...


class TeitokEnvAdapter:
    name = "teitok"
    description = "TEITOK project structure and settings checks."
    kind = "frontend"
    # Dynamic per project; computed in check() from settings/detection.
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        root = ctx.project_root
        settings_path = root / "Resources" / "settings.xml"
        detected_cqp = detect_teitok_cqp(root) or {}
        detected_manatee = detect_teitok_manatee(root) or {}
        detected_blacklab = detect_teitok_blacklab(root) or {}
        checks: Dict[str, Any] = {
            "project_root": {"ok": root.is_dir(), "path": str(root)},
            "settings_xml": {"ok": settings_path.is_file(), "path": str(settings_path)},
            "xmlfiles_dir": {"ok": (root / "xmlfiles").is_dir(), "path": str(root / "xmlfiles")},
            "cqp_dir": {"ok": (root / "cqp").is_dir(), "path": str(root / "cqp")},
            "pando_dir": {"ok": (root / "pando").is_dir(), "path": str(root / "pando")},
            "manatee_dir": {"ok": (root / "manatee").is_dir(), "path": str(root / "manatee")},
        }
        configured_backends: List[str] = []
        if detected_cqp:
            configured_backends.append("cqp")
        if detected_manatee:
            configured_backends.append("manatee")
        if detected_blacklab:
            configured_backends.append("blacklab")
        meta: Dict[str, Any] = {
            "title": "",
            "default_lang": "",
        }
        if settings_path.is_file():
            try:
                xroot = ET.parse(settings_path).getroot()
                title = xroot.find(".//defaults/title")
                if title is not None:
                    meta["title"] = str(title.get("display") or "").strip()
                defaults = xroot.find(".//defaults")
                if defaults is not None:
                    meta["default_lang"] = str(defaults.get("lang") or defaults.get("language") or "").strip()
                if not meta["default_lang"]:
                    lang_node = xroot.find(".//languages")
                    if lang_node is not None:
                        meta["default_lang"] = str(lang_node.get("default") or "").strip()
                # Pando can be present without dedicated XML settings; accept either.
                has_pando_xml = xroot.find(".//pando") is not None
                if has_pando_xml or checks["pando_dir"]["ok"]:
                    configured_backends.append("pando")
            except Exception as exc:
                checks["settings_parse"] = {"ok": False, "error": str(exc)}
        elif checks["pando_dir"]["ok"]:
            configured_backends.append("pando")

        # Keep order stable and remove duplicates.
        configured_backends = list(dict.fromkeys(configured_backends))

        required = [checks["project_root"], checks["settings_xml"]]
        ok = all(bool(c.get("ok")) for c in required)
        reason = (
            "TEITOK project structure looks valid."
            if ok
            else "TEITOK project root/settings.xml missing or unreadable."
        )
        return {
            "available": ok,
            "reason": reason,
            "checks": checks,
            "meta": meta,
            "depends_on_backends": configured_backends,
        }


class PandoEnvAdapter:
    name = "pando"
    description = "Pando index and binary checks."
    kind = "backend"
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        root = ctx.project_root
        pando_dir = root / "pando"
        checks = {
            "index_dir": {"ok": pando_dir.is_dir(), "path": str(pando_dir)},
            "flexicorp_pando": cmd_check("flexicorp-pando"),
            "pando": cmd_check("pando"),
        }
        available = bool(checks["index_dir"]["ok"] and checks["flexicorp_pando"].get("runnable"))
        reason = (
            "Pando index folder and flexicorp-pando executable are available."
            if available
            else "Missing Pando index folder and/or runnable flexicorp-pando executable."
        )
        return {"available": available, "reason": reason, "checks": checks}


class CqpEnvAdapter:
    name = "cqp"
    description = "CQP registry and executable checks."
    kind = "backend"
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        detected = detect_teitok_cqp(ctx.project_root) or {}
        cqp_binary = cmd_check("cqp")
        registry = str((detected.get("cqp") or {}).get("registry") or "").strip()
        reg_ok = bool(registry and Path(registry).is_dir())
        available = bool(detected and cqp_binary.get("runnable") and reg_ok)
        reason = (
            f"Detected TEITOK CQP setup (registry={registry})."
            if available
            else "CQP setup incomplete (detection, cqp binary, or registry path missing)."
        )
        return {
            "available": available,
            "reason": reason,
            "checks": {
                "teitok_detected": {"ok": bool(detected), "details": detected},
                "registry_dir": {"ok": reg_ok, "path": registry},
                "cqp_binary": cqp_binary,
            },
        }


class ManateeEnvAdapter:
    name = "manatee"
    description = "Manatee registry/tools checks."
    kind = "backend"
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        detected = detect_teitok_manatee(ctx.project_root) or {}
        manatee_registry = str((detected.get("manatee") or {}).get("registry") or "").strip()
        corpus = str((detected.get("manatee") or {}).get("corpus") or "").strip()
        reg_file_ok = bool(manatee_registry and corpus and Path(manatee_registry, corpus).is_file())
        available = bool(detected and reg_file_ok)
        reason = (
            "Detected TEITOK Manatee registry and corpus config."
            if available
            else "Manatee setup incomplete (manatee folder or registry corpus file missing)."
        )
        return {
            "available": available,
            "reason": reason,
            "checks": {
                "teitok_detected": {"ok": bool(detected), "details": detected},
                "registry_corpus_file": {
                    "ok": reg_file_ok,
                    "path": str(Path(manatee_registry, corpus)) if manatee_registry and corpus else "",
                },
                "encodevert": cmd_check("encodevert"),
                "mkstats": cmd_check("mkstats"),
            },
        }


class ClickhouseEnvAdapter:
    name = "clickhouse"
    description = "ClickHouse backend reachability and TEITOK defaults checks."
    kind = "backend"
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        root = ctx.project_root
        detected = detect_teitok_clickhouse(root) or {}
        cfg = dict(detected.get("clickhouse") or {})
        env_cfg = dict((ctx.env_config or {}).get("clickhouse") or {})
        host = str(env_cfg.get("host") or cfg.get("host") or "127.0.0.1")
        try:
            port = int(env_cfg.get("port") or cfg.get("port") or 8123)
        except Exception:
            port = 8123
        database = str(env_cfg.get("database") or cfg.get("database") or "")
        user = str(env_cfg.get("user") or cfg.get("user") or "")
        password = str(env_cfg.get("password") or cfg.get("password") or "")
        tables = dict(cfg.get("tables") or {})
        docs_table = str(env_cfg.get("docs_table") or tables.get("docs") or "docs")
        tcp_ok = False
        tcp_error = ""
        try:
            with socket.create_connection((host, port), timeout=1.0):
                tcp_ok = True
        except Exception as exc:
            tcp_error = str(exc)

        has_project_cfg = bool(cfg)
        dep_ok = True
        dep_error = ""
        try:
            importlib.import_module("clickhouse_connect")
        except Exception as exc:
            dep_ok = False
            dep_error = str(exc)
        corpus_ready = False
        corpus_error = ""
        if dep_ok and tcp_ok and database and docs_table:
            db_esc = database.replace("'", "''")
            table_esc = docs_table.replace("'", "''")
            query = (
                "SELECT count() FROM system.tables "
                f"WHERE database = '{db_esc}' "
                f"AND name = '{table_esc}'"
            )
            params = {"query": query}
            if user:
                params["user"] = user
            if password:
                params["password"] = password
            probe_url = f"http://{host}:{port}/?{urlencode(params)}"
            try:
                with urlopen(probe_url, timeout=2.0) as resp:
                    body = resp.read(256).decode("utf-8", errors="ignore").strip()
                    corpus_ready = body.startswith("1")
                    if not corpus_ready:
                        corpus_error = f"table {database}.{docs_table} missing"
            except Exception as exc:
                corpus_error = str(exc)
        elif dep_ok and tcp_ok:
            corpus_error = "database or docs table is not configured"

        available = bool(has_project_cfg and dep_ok and tcp_ok and corpus_ready)
        if not dep_ok:
            reason = "ClickHouse backend requires optional dependency 'clickhouse-connect'."
        elif not has_project_cfg and tcp_ok:
            reason = "ClickHouse daemon is reachable, but no TEITOK ClickHouse corpus config was detected for this project root."
        elif has_project_cfg and tcp_ok and not corpus_ready and corpus_error:
            reason = format_clickhouse_error_message(
                corpus_error,
                host=host,
                port=port,
                database=database,
            )
        elif available:
            reason = f"ClickHouse backend reachable at {host}:{port}; corpus database/table is ready."
        elif has_project_cfg and tcp_ok:
            reason = f"ClickHouse daemon is reachable at {host}:{port}, but corpus database/table is not ready yet."
        else:
            reason = "ClickHouse backend not ready (missing TEITOK clickhouse defaults and/or daemon unreachable)."
        return {
            "available": available,
            "reason": reason,
            "checks": {
                "teitok_detected": {"ok": has_project_cfg, "details": detected},
                "clickhouse_connect_dep": {
                    "ok": dep_ok,
                    "module": "clickhouse_connect",
                    "error": dep_error,
                },
                "clickhouse_tcp": {
                    "ok": tcp_ok,
                    "host": host,
                    "port": port,
                    "database": database,
                    "error": tcp_error,
                },
                "corpus_table": {
                    "ok": corpus_ready,
                    "database": database,
                    "table": docs_table,
                    "error": corpus_error,
                },
            },
        }


class TeitokXmlEnvAdapter:
    name = "teitokxml"
    description = "TEITOK XML inventory backend (mainly EasyCorp), backed by tmp/doclist.sqlite."
    kind = "backend"
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        root = ctx.project_root
        settings_path = root / "Resources" / "settings.xml"
        xmlfiles_dir = root / "xmlfiles"
        tmp_dir = root / "tmp"
        db_path = tmp_dir / "doclist.sqlite"
        settings_ok = settings_path.is_file()
        xmlfiles_ok = xmlfiles_dir.is_dir()
        db_exists = db_path.is_file()
        db_ok = False
        db_error = ""
        if db_exists:
            try:
                conn = sqlite3.connect(str(db_path))
                try:
                    conn.execute("SELECT name FROM sqlite_master LIMIT 1").fetchall()
                    db_ok = True
                finally:
                    conn.close()
            except Exception as exc:
                db_error = str(exc)
        else:
            # teitokxml creates this DB lazily; treat writable tmp/ as sufficient.
            try:
                tmp_dir.mkdir(parents=True, exist_ok=True)
            except Exception:
                pass
            db_ok = tmp_dir.is_dir() and os.access(tmp_dir, os.W_OK)

        available = bool(settings_ok and xmlfiles_ok and db_ok)
        reason = (
            "TEITOK XML source inventory is available (xmlfiles + doclist.sqlite)."
            if available
            else "TEITOK XML backend not ready (settings/xmlfiles/doclist.sqlite path issue)."
        )
        return {
            "available": available,
            "reason": reason,
            "checks": {
                "settings_xml": {"ok": settings_ok, "path": str(settings_path)},
                "xmlfiles_dir": {"ok": xmlfiles_ok, "path": str(xmlfiles_dir)},
                "doclist_sqlite": {
                    "ok": db_ok,
                    "path": str(db_path),
                    "exists": db_exists,
                    "error": db_error,
                },
            },
        }


class KontextEnvAdapter:
    name = "kontext"
    description = "KonText config/registry/http checks."
    kind = "frontend"
    depends_on_backends = ["manatee"]

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        root = ctx.project_root
        cfg = dict((ctx.env_config or {}).get("kontext") or {})
        links_cfg = dict((ctx.env_config or {}).get("links") or {})
        candidates = []
        cfg_conf = str(cfg.get("config_xml") or cfg.get("config") or "").strip()
        if cfg_conf:
            candidates.append(Path(cfg_conf).expanduser())
        env_conf = os.environ.get("KONTEXT_CONF", "").strip()
        if env_conf:
            candidates.append(Path(env_conf))
        candidates.extend(
            [
                Path("/opt/kontext/conf/config.xml"),
                Path("/etc/kontext/config.xml"),
            ]
        )
        conf_path = None
        for c in candidates:
            if c.is_file():
                conf_path = c
                break

        out: Dict[str, Any] = {
            "ok": False,
            "available": False,
            "project_root": str(root),
            "kontext_conf": str(conf_path) if conf_path else "",
            "checks": {},
            "reason": "",
        }
        if conf_path is None:
            out["reason"] = "KonText config.xml not found (/opt/kontext/conf or /etc/kontext)."
            return out

        out["checks"]["config_xml"] = {"ok": conf_path.is_file(), "path": str(conf_path)}
        corplist_path = conf_path.parent / "corplist.xml"
        out["checks"]["corplist_xml"] = {"ok": corplist_path.is_file(), "path": str(corplist_path)}

        registry_dir = None
        try:
            xml_root = ET.parse(conf_path).getroot()
            node = xml_root.find(".//plugins/default_corparch/manatee_registry")
            if node is not None and node.text and node.text.strip():
                registry_dir = Path(node.text.strip()).expanduser()
        except Exception as exc:
            out["checks"]["config_parse"] = {"ok": False, "error": str(exc)}
        if registry_dir is not None:
            out["checks"]["manatee_registry"] = {
                "ok": registry_dir.is_dir(),
                "path": str(registry_dir),
            }

        corpus_name = ""
        detected = detect_teitok_manatee(root)
        if detected:
            corpus_name = str((detected.get("manatee") or {}).get("corpus") or "").strip()
        if corpus_name and registry_dir is not None:
            reg_file = registry_dir / corpus_name
            out["checks"]["corpus_registry_file"] = {"ok": reg_file.is_file(), "path": str(reg_file)}

        target_id = str(ctx.corpus_id or "").strip()
        target_candidates: List[str] = []
        if target_id:
            target_candidates.append(target_id)
        if corpus_name and corpus_name not in target_candidates:
            target_candidates.append(corpus_name)
        corplist_idents: List[str] = []
        if corplist_path.is_file():
            try:
                cp_root = ET.parse(corplist_path).getroot()
                for node in cp_root.findall(".//corpus"):
                    for attr in ("ident", "id", "name"):
                        val = str(node.get(attr) or "").strip()
                        if val:
                            corplist_idents.append(val)
                    txt = str(node.text or "").strip()
                    if txt:
                        corplist_idents.append(txt)
            except Exception as exc:
                out["checks"]["corplist_parse"] = {"ok": False, "error": str(exc)}
        if target_candidates:
            corplist_target_ok = any(c in corplist_idents for c in target_candidates)
            out["checks"]["corplist_target_corpus"] = {
                "ok": corplist_target_ok,
                "requested_corpus_id": target_id,
                "candidate_ids": target_candidates,
                "known_idents_sample": corplist_idents[:20],
            }
            if registry_dir is not None:
                reg_candidates = [str((registry_dir / c)) for c in target_candidates]
                reg_target_ok = any((registry_dir / c).is_file() for c in target_candidates)
                out["checks"]["registry_target_corpus"] = {
                    "ok": reg_target_ok,
                    "candidate_paths": reg_candidates,
                }

        # HTTP probe is best-effort only: in multi-container setups KonText may be
        # reachable from host/browser but not from the current container via 127.0.0.1.
        candidate_base_urls: List[str] = []
        cfg_url = str(cfg.get("url") or "").strip()
        if cfg_url:
            candidate_base_urls.append(cfg_url)
        links_url = str(links_cfg.get("kontext_live_url") or "").strip()
        if links_url:
            candidate_base_urls.append(links_url)
        env_url = os.environ.get("KONTEXT_URL", "").strip()
        if env_url:
            candidate_base_urls.append(env_url)
        candidate_base_urls.extend(
            [
                "http://127.0.0.1:18080",
                "http://localhost:18080",
                "http://127.0.0.1:8080",
                "http://localhost:8080",
                "http://kontext:8080",
            ]
        )
        # de-duplicate while preserving order
        seen_urls = set()
        candidate_base_urls = [u for u in candidate_base_urls if not (u in seen_urls or seen_urls.add(u))]

        http_ok = False
        ok_url = ""
        errors: List[str] = []
        for base in candidate_base_urls:
            corplist_url = base.rstrip("/") + "/corpora/corplist"
            try:
                with urlopen(corplist_url, timeout=2.0) as resp:
                    if 200 <= int(getattr(resp, "status", 0)) < 300:
                        http_ok = True
                        ok_url = corplist_url
                        break
            except URLError as exc:
                errors.append(f"{corplist_url}: {exc}")
            except Exception as exc:
                errors.append(f"{corplist_url}: {exc}")

        # In containerized setups, an externally mapped/public URL can be valid for
        # users but unreachable from this runtime. Treat configured public live URL
        # as a non-fatal success hint so checks don't keep reporting false negatives.
        if not http_ok and links_url:
            try:
                parsed = urlparse(links_url)
                if parsed.scheme in {"http", "https"} and parsed.netloc:
                    http_ok = True
                    ok_url = (
                        links_url
                        if links_url.rstrip("/").endswith("/corpora/corplist")
                        else links_url.rstrip("/") + "/corpora/corplist"
                    )
                    errors.append("runtime probe failed; using links.kontext_live_url as external endpoint hint")
            except Exception:
                pass
        out["checks"]["http_corplist"] = {
            "ok": http_ok,
            "url": ok_url,
            "candidates": [u.rstrip("/") + "/corpora/corplist" for u in candidate_base_urls],
            "error": " ; ".join(errors[:3]),
        }

        # config/corplist are required; http probe is diagnostic (non-fatal).
        required_keys = ["config_xml", "corplist_xml"]
        if target_id:
            required_keys.append("corplist_target_corpus")
            if registry_dir is not None:
                required_keys.append("registry_target_corpus")
        required_failed = [
            k
            for k in required_keys
            if not bool((out["checks"].get(k) or {}).get("ok"))
        ]
        out["ok"] = len(required_failed) == 0
        out["available"] = out["ok"]
        if out["ok"]:
            out["reason"] = (
                "KonText core configuration checks passed."
                + ("" if http_ok else " HTTP probe failed from this runtime (diagnostic only).")
            )
        elif target_id and not bool((out["checks"].get("corplist_target_corpus") or {}).get("ok")):
            out["reason"] = f"KonText is reachable/configured, but corpus '{target_id}' is not listed in corplist/registry."
        else:
            out["reason"] = "KonText checks found configuration issues."
        return out


class FcsEnvAdapter:
    name = "fcs"
    description = "FCS endpoint availability and corpus exposure checks via FQS."
    kind = "frontend"
    # Dynamic per FQS catalogue; computed in check().
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        cfg = dict((ctx.env_config or {}).get("fcs") or {})
        fqs_bin = _resolve_fqs_bin(ctx.env_config or {})
        checks: Dict[str, Any] = {
            "fqs_binary": {
                "ok": bool(fqs_bin),
                "path": fqs_bin,
            }
        }
        if not fqs_bin:
            return {
                "available": False,
                "reason": "FCS check requires a runnable fqs binary (configure fqs.bin/path or PATH).",
                "checks": checks,
                "depends_on_backends": [],
            }

        status_res = _run_json_command([fqs_bin, "status"])
        status_payload = status_res.get("json") if isinstance(status_res.get("json"), dict) else {}
        status_ok = bool(status_payload.get("ok")) if isinstance(status_payload, dict) else False
        checks["fqs_status"] = {
            "ok": status_ok,
            "error": status_res.get("error", ""),
        }

        host = str(cfg.get("host") or "").strip()
        port_raw = cfg.get("port")
        if not host and isinstance(status_payload, dict):
            host = str(status_payload.get("host") or "").strip()
        port = 0
        if port_raw is not None:
            try:
                port = int(port_raw)
            except Exception:
                port = 0
        if not port and isinstance(status_payload, dict):
            try:
                port = int(status_payload.get("port") or 0)
            except Exception:
                port = 0
        if not host:
            host = "127.0.0.1"
        if not port:
            port = 8787

        params = urlencode({"operation": "explain"})
        fcs_url = str(cfg.get("url") or "").strip() or f"http://{host}:{port}/fcs?{params}"
        http_ok = False
        http_error = ""
        try:
            with urlopen(fcs_url, timeout=2.5) as resp:
                status_code = int(getattr(resp, "status", 0))
                body = resp.read(2048).decode("utf-8", errors="ignore")
                http_ok = 200 <= status_code < 300 and ("explainResponse" in body or "searchRetrieve" in body or "<" in body)
        except Exception as exc:
            http_error = str(exc)
        checks["fcs_explain"] = {"ok": http_ok, "url": fcs_url, "error": http_error}

        list_res = _run_json_command([fqs_bin, "corpora", "list"])
        corpora = _coerce_corpora_list(list_res.get("json"))
        enabled = [row for row in corpora if _fcs_enabled(row)]
        target_id = str(ctx.corpus_id or "").strip()
        target_row: Dict[str, Any] | None = None
        if target_id:
            for row in corpora:
                if str(row.get("id") or "").strip() == target_id:
                    target_row = row
                    break
        target_found = target_row is not None
        target_fcs_enabled = bool(target_row is not None and _fcs_enabled(target_row))
        used_backends: List[str] = []
        for row in enabled:
            preferred = str(row.get("preferred_backend") or "").strip().lower()
            if preferred and preferred != "auto":
                used_backends.append(preferred)
            settings = row.get("settings")
            if isinstance(settings, dict):
                query_backend = str(settings.get("query_backend") or "").strip().lower()
                if query_backend:
                    used_backends.append(query_backend)
                avail = settings.get("available_backends")
                if isinstance(avail, list):
                    for b in avail:
                        bs = str(b or "").strip().lower()
                        if bs:
                            used_backends.append(bs)
        used_backends = list(dict.fromkeys(used_backends))
        checks["fcs_catalog"] = {
            "ok": bool(list_res.get("ok")),
            "fcs_enabled_count": len(enabled),
            "total_corpora": len(corpora),
            "backend_dependencies": used_backends,
            "error": list_res.get("error", ""),
        }
        if target_id:
            checks["fcs_target_corpus"] = {
                "ok": target_fcs_enabled,
                "id": target_id,
                "found": target_found,
                "fcs_enabled": target_fcs_enabled,
            }

        if target_id:
            available = bool(status_ok and http_ok and target_fcs_enabled)
        else:
            available = bool(status_ok and http_ok and len(enabled) > 0)
        if available:
            reason = "FCS endpoint is reachable and exposes corpora."
        elif target_id and status_ok and http_ok:
            if not target_found:
                reason = f"FCS endpoint is reachable, but corpus '{target_id}' is not in the FQS catalog."
            else:
                reason = f"FCS endpoint is reachable, but corpus '{target_id}' is not FCS-enabled."
        elif status_ok and http_ok:
            reason = "FCS endpoint is reachable but no corpus is currently enabled for FCS."
        elif not status_ok:
            reason = "FQS status check failed; FCS frontend not available."
        else:
            reason = "FCS endpoint probe failed."
        return {
            "available": available,
            "reason": reason,
            "checks": checks,
            "depends_on_backends": used_backends,
        }


class PmltqEnvAdapter:
    name = "pmltq"
    description = (
        "Two independent stacks: (1) PML-TQ query language translated to SQL on your existing "
        "ClickHouse index (same DB as ClickQL); (2) optional native PML-TQ HTTP API with its own "
        "PostgreSQL corpus DB. They do not depend on each other."
    )
    # Use "backend" so TEITOK fqs-backends (and similar UIs) that only render
    # kind "frontend" / "backend" still list this adapter; it is not a TEITOK UI frontend.
    kind = "backend"
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        root = ctx.project_root
        detected = detect_teitok_clickhouse(root) or {}
        # Same ClickHouse corpus/DB as clickhouse/clickql; env-config uses top-level
        # ``clickhouse`` (optional legacy overlay: ``pmltq.clickhouse``).
        cfg = dict(detected.get("clickhouse") or {})
        env_root = dict((ctx.env_config or {}).get("clickhouse") or {})
        cfg.update(env_root)
        pmltq_block = dict((ctx.env_config or {}).get("pmltq") or {})
        skip_native_http = pmltq_block.get("native_http") is False
        legacy = pmltq_block.get("clickhouse")
        if isinstance(legacy, dict):
            cfg.update(dict(legacy))
        host = str(cfg.get("host") or "127.0.0.1")
        try:
            port = int(cfg.get("port") or 8123)
        except Exception:
            port = 8123
        database = str(cfg.get("database") or "")
        tcp_ok = False
        tcp_error = ""
        try:
            with socket.create_connection((host, port), timeout=1.0):
                tcp_ok = True
        except Exception as exc:
            tcp_error = str(exc)
        sql_hint = root / "tmp" / "pmltqload.sql"
        has_hint = sql_hint.is_file()
        clickql_path_ok = bool(tcp_ok and has_hint)
        available = clickql_path_ok

        if skip_native_http:
            native_base, native_src = "", "env_config.native_http_false"
            nat = {
                "http_ok": False,
                "parse_ok": False,
                "treebank_ids": [],
                "error": "",
                "catalog_error": "",
            }
            tb_ids = []
            transport_ok = False
            catalog_err = ""
        else:
            native_base, native_src = resolve_pmltq_native_server_url(
                None, env_config=ctx.env_config
            )
            nat = _probe_native_pmltq_api(native_base)
            tb_ids = list(nat.get("treebank_ids") or [])
            transport_ok = bool(
                nat.get("http_ok") and nat.get("parse_ok") and not (nat.get("error") or "")
            )
            catalog_err = str(nat.get("catalog_error") or "")
        # Same canonical corpus id as ClickHouse / flexencoder (TEITOK cqp/@corpus → database, e.g. tt_infov).
        expected_tb = str(database or "").strip()
        expected_ok = True
        catalog_extra = ""
        if expected_tb:
            exp_l = expected_tb.lower()
            expected_ok = any(str(x).strip().lower() == exp_l for x in tb_ids)
            if not expected_ok:
                catalog_extra = (
                    f"expected native treebank id {expected_tb!r} (TEITOK corpus / ClickHouse DB name) "
                    f"not in API list {tb_ids[:12]!s}"
                    + ("…" if len(tb_ids) > 12 else "")
                )
        native_db_ok = bool(tb_ids) and expected_ok
        native_err = str(nat.get("error") or "")
        if not skip_native_http and not native_db_ok:
            if catalog_extra:
                native_err = native_err + ("; " if native_err else "") + catalog_extra
            elif catalog_err:
                native_err = native_err + ("; " if native_err else "") + catalog_err
        native_stack_ok = bool(not skip_native_http and transport_ok and native_db_ok)

        reason_parts: List[str] = []
        if clickql_path_ok:
            reason_parts.append(
                f"PML-TQ→ClickHouse (query language on indexed data): OK — TCP {host}:{port}, "
                f"tmp/pmltqload.sql present (same ClickHouse DB as ClickQL)."
            )
        else:
            reason_parts.append(
                "PML-TQ→ClickHouse path not ready: need reachable ClickHouse and tmp/pmltqload.sql "
                "(query language is translated to SQL; not the native PML-TQ server)."
            )
        if skip_native_http:
            reason_parts.append(
                "Native PML-TQ HTTP disabled via env-config (pmltq.native_http=false); "
                "optional perl-pmltq-server stack was not probed."
            )
        elif native_stack_ok:
            reason_parts.append(
                f"Native PML-TQ HTTP at {native_base} (source {native_src}): OK — "
                f"{len(tb_ids)} treebank(s) in PostgreSQL-backed catalog."
            )
        else:
            detail = native_err or "unreachable or empty treebank list"
            reason_parts.append(
                f"Native PML-TQ HTTP (separate backend, PostgreSQL DB): not OK — {detail}. "
                f"Set pmltq.server.url / PMLTQ_URL; treebank id should match TEITOK corpus (ClickHouse DB), e.g. {expected_tb!r}."
                if expected_tb
                else (
                    f"Native PML-TQ HTTP (separate backend, PostgreSQL DB): not OK — {detail}. "
                    f"Set pmltq.server.url / PMLTQ_URL."
                )
            )

        return {
            "available": available,
            "reason": " ".join(reason_parts),
            "checks": {
                "clickhouse_tcp": {
                    "ok": tcp_ok,
                    "host": host,
                    "port": port,
                    "database": database,
                    "error": tcp_error,
                    "note": "Same host/port/DB as the clickhouse env adapter (PML-TQ QL only differs).",
                },
                "pmltq_sql_hint": {"ok": has_hint, "path": str(sql_hint)},
                "pmltq_clickql_path": {
                    "ok": clickql_path_ok,
                    "note": "PML-TQ query language → SQL on this ClickHouse corpus (not native PML-TQ HTTP).",
                },
                "native_pmltq_http": {
                    "ok": transport_ok,
                    "base_url": native_base,
                    "url_source": native_src,
                    "probe": (
                        ""
                        if skip_native_http
                        else f"{native_base.rstrip('/')}/v1/treebanks"
                    ),
                    "http_code": nat.get("http_code"),
                    "error": nat.get("error") or None,
                    "note": (
                        "Skipped (env-config pmltq.native_http=false)."
                        if skip_native_http
                        else "HTTP+JSON transport only; catalog/DB is native_pmltq_postgres."
                    ),
                },
                "native_pmltq_postgres": {
                    "ok": native_db_ok,
                    "treebank_count": len(tb_ids),
                    "treebank_ids": tb_ids[:24],
                    "expected_treebank": expected_tb or None,
                    "expected_found": expected_ok if expected_tb else None,
                    "expected_from": (
                        "TEITOK cqp/@corpus → ClickHouse database (same as flexencoder / other backends)"
                        if expected_tb
                        else None
                    ),
                    "detail": None if native_db_ok else (native_err or None),
                    "note": "Native treebank id should match the corpus database name when TEITOK is configured.",
                },
            },
        }


class BlacklabEnvAdapter:
    name = "blacklab"
    description = "BlackLab backend defaults and endpoint checks."
    kind = "backend"
    depends_on_backends: List[str] = []

    def check(self, ctx: EnvContext) -> Dict[str, Any]:
        root = ctx.project_root
        detected = detect_teitok_blacklab(root) or {}
        blacklab_cfg = dict(detected.get("blacklab") or {})
        corpus = str(blacklab_cfg.get("corpus") or "").strip()
        field = str(blacklab_cfg.get("field") or "").strip()
        query_language = str(blacklab_cfg.get("query_language") or "").strip()
        cfg = dict((ctx.env_config or {}).get("blacklab") or {})
        base_url = (
            str(cfg.get("url") or "").strip()
            or os.environ.get("BLACKLAB_URL", "").strip()
            or "http://127.0.0.1:8080/blacklab-server"
        )
        ping_url = base_url.rstrip("/") + "/"
        http_ok = False
        http_error = ""
        try:
            with urlopen(ping_url, timeout=2.0) as resp:
                http_ok = 200 <= int(getattr(resp, "status", 0)) < 500
        except Exception as exc:
            http_error = str(exc)
        corpus_ok = corpus != ""
        available = bool(corpus_ok and http_ok)
        reason = (
            f"BlackLab endpoint reachable and TEITOK BlackLab corpus defaults detected ({corpus})."
            if available
            else "BlackLab backend not ready (endpoint unreachable and/or missing corpus defaults)."
        )
        return {
            "available": available,
            "reason": reason,
            "checks": {
                "teitok_detected": {"ok": bool(detected), "details": detected},
                "corpus_default": {"ok": corpus_ok, "corpus": corpus, "field": field, "query_language": query_language},
                "endpoint_http": {"ok": http_ok, "url": ping_url, "error": http_error},
            },
        }


def build_adapter_registry() -> Dict[str, EnvAdapter]:
    adapters: List[EnvAdapter] = [
        TeitokEnvAdapter(),
        FcsEnvAdapter(),
        PandoEnvAdapter(),
        CqpEnvAdapter(),
        ManateeEnvAdapter(),
        ClickhouseEnvAdapter(),
        TeitokXmlEnvAdapter(),
        BlacklabEnvAdapter(),
        KontextEnvAdapter(),
        PmltqEnvAdapter(),
    ]
    return {a.name: a for a in adapters}

