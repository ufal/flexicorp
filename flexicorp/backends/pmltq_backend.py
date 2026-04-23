"""
Native PML-TQ HTTP backend (``PmltqBackend``): HTTP client for a PMLTQ server
(``/v1/treebanks``, ``.../query``).

PML-TQ *as a query language* on the indexed ClickHouse corpus is implemented in
``backends/clickhouse.py`` (``ClickqlBackend``, query languages ``pmltq`` / ``clickpmltq``),
not in this module.

``project["pmltq"]`` may contain an ``api`` block for this backend and other keys for
ClickHouse/translation; those code paths stay separate.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen

from ..env_config import resolve_pmltq_native_server_url
from ..highlight_contract import build_highlight_map
from ..teitok import detect_teitok_clickhouse, detect_teitok_cqp
from ..teitok_context import resolve_teitok_context
from ..core import CorpusBackend, FlexiRequest, _flexicorp_scripts_flexencoder, register_backend
from ..pml.jsonl_to_pml import convert_jsonl_to_pml


def _as_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


@dataclass
class PmltqBackend(CorpusBackend):
    """
    Native PML-TQ HTTP backend.

    Talks to a PMLTQ server exposing /v1/treebanks/:id/query and returns a
    normalized flexicorp query result payload wrapped by core.handle_request().
    """

    name: str = "pmltq"

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "pmltq",
            "supported_query_languages": ["pmltq"],
            "supported_corpus_formats": ["pmltq"],
            "default_query_language": "pmltq",
            "default_corpus_format": "pmltq",
            "default_selection_reason": "Native PML-TQ HTTP query backend.",
        }

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": True,
            "freq": False,
            "stats_freq_pattributes": False,
            "stats_freq_sattributes": False,
            "stats_relative_freq": False,
            "stats_collocations": False,
            "stats_dep_collocations": False,
            "stats_keyness": False,
            "stats_table_result": False,
            "info": True,
            "daemon": False,
            "reindex": True,
            "raw_query": True,
            "query": True,
        }

    def _resolve_cfg(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})

        cfg: Dict[str, Any] = {}
        if isinstance(project.get("pmltq_server"), dict):
            cfg.update(dict(project.get("pmltq_server") or {}))
        # project.pmltq.api: HTTP endpoint for *this* backend only. (Other keys under
        # project.pmltq may be consumed by the clickql/ClickHouse path — not merged here.)
        pmltq_root = project.get("pmltq")
        if isinstance(pmltq_root, dict) and isinstance(pmltq_root.get("api"), dict):
            cfg.update(dict(pmltq_root.get("api") or {}))

        for key in (
            "pmltq_url",
            "pmltq_treebank",
            "pmltq_token",
            "pmltq_cookie",
            "pmltq_timeout",
            "pmltq_username",
            "pmltq_password",
            "pmltq_verify_tls",
            "pmltq_export_dir",
            "pmltq_flexencoder",
        ):
            if key in params and params.get(key) not in (None, ""):
                cfg[key] = params.get(key)
        for key in (
            "pmltq_write_pml",
            "pmltq_run_import",
            "pmltq_run_sql",
            "pmltq_lang",
            "pmltq_pml_dir",
            "pmltq_pml_layers",
            "pmltq_pml_gzip",
            "pmltq_import_cmd",
            "pmltq_sql_cmd",
            "pmltq_conversion_mode",
            "pmltq_converter_cmd",
            "pmltq_converter_workdir",
            "pmltq_schema_dir",
            "pmltq_schema_mode",
            "pmltq_schema_bundle_dir",
            "pmltq_schema_file",
            "pmltq_data_dir",
            "pmltq_files_mode",
            "pmltq_files_suffix",
        ):
            if key in params:
                cfg[key] = params[key]

        explicit_base = str(
            cfg.get("url") or cfg.get("base_url") or cfg.get("pmltq_url") or ""
        ).strip().rstrip("/")
        if explicit_base:
            base_url = explicit_base
        else:
            base_url, _ = resolve_pmltq_native_server_url(project)
        treebank = str(
            cfg.get("treebank")
            or cfg.get("pmltq_treebank")
            or params.get("treebank")
            or params.get("corpus")
            or os.environ.get("PMLTQ_TREEBANK")
            or ""
        ).strip()
        if not treebank:
            try:
                root = Path(str(project.get("root") or ".")).resolve()
                det = detect_teitok_clickhouse(root) or {}
                db = str(dict(det.get("clickhouse") or {}).get("database") or "").strip()
                if db:
                    treebank = db
            except Exception:
                pass
        token = str(cfg.get("token") or cfg.get("pmltq_token") or "").strip()
        cookie = str(cfg.get("cookie") or cfg.get("pmltq_cookie") or "").strip()
        username = str(cfg.get("username") or cfg.get("pmltq_username") or "").strip()
        password = str(cfg.get("password") or cfg.get("pmltq_password") or "").strip()
        timeout_raw = cfg.get("timeout", cfg.get("pmltq_timeout", 8))
        try:
            timeout = max(1.0, float(timeout_raw))
        except Exception:
            timeout = 8.0
        verify_tls = _as_bool(cfg.get("verify_tls", cfg.get("pmltq_verify_tls", True)))

        return {
            "base_url": base_url,
            "treebank": treebank,
            "token": token,
            "cookie": cookie,
            "username": username,
            "password": password,
            "timeout": timeout,
            "verify_tls": verify_tls,
            "export_dir": str(cfg.get("export_dir") or cfg.get("pmltq_export_dir") or "").strip(),
            "import_cmd": str(cfg.get("import_cmd") or cfg.get("pmltq_import_cmd") or "").strip(),
            "sql_cmd": str(cfg.get("sql_cmd") or cfg.get("pmltq_sql_cmd") or "").strip(),
            "flexencoder": str(cfg.get("flexencoder") or cfg.get("pmltq_flexencoder") or "").strip(),
            # Keep SQL and PML stages explicitly separated:
            # - SQL stage is for flexicorp queryability.
            # - PML stage is optional server-facing export/import.
            "pmltq_run_sql": _as_bool(cfg.get("pmltq_run_sql", True)),
            "pmltq_write_pml": _as_bool(cfg.get("pmltq_write_pml", False)),
            "pmltq_run_import": _as_bool(cfg.get("pmltq_run_import", True)),
            "pmltq_lang": str(cfg.get("pmltq_lang") or "en").strip() or "en",
            "pmltq_pml_dir": str(cfg.get("pmltq_pml_dir") or "").strip(),
            "pmltq_pml_layers": str(cfg.get("pmltq_pml_layers") or "pdt3").strip().lower() or "pdt3",
            "pmltq_pml_gzip": _as_bool(cfg.get("pmltq_pml_gzip", True)),
            "pmltq_conversion_mode": str(cfg.get("pmltq_conversion_mode") or "legacy").strip().lower() or "legacy",
            "pmltq_converter_cmd": str(cfg.get("pmltq_converter_cmd") or "").strip(),
            "pmltq_converter_workdir": str(cfg.get("pmltq_converter_workdir") or "").strip(),
            "pmltq_schema_dir": str(cfg.get("pmltq_schema_dir") or "").strip(),
            "pmltq_schema_mode": str(cfg.get("pmltq_schema_mode") or "reference").strip().lower() or "reference",
            "pmltq_schema_bundle_dir": str(cfg.get("pmltq_schema_bundle_dir") or "").strip(),
            "pmltq_schema_file": str(cfg.get("pmltq_schema_file") or "").strip(),
            "pmltq_data_dir": str(cfg.get("pmltq_data_dir") or "").strip(),
            "pmltq_files_mode": str(cfg.get("pmltq_files_mode") or "id").strip().lower() or "id",
            "pmltq_files_suffix": str(cfg.get("pmltq_files_suffix") or "").strip(),
        }

    def _headers(self, cfg: Dict[str, Any]) -> Dict[str, str]:
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
        }
        token = str(cfg.get("token") or "").strip()
        cookie = str(cfg.get("cookie") or "").strip()
        if token:
            headers["Authorization"] = f"Bearer {token}"
        if cookie:
            headers["Cookie"] = cookie
        return headers

    def _request_json(
        self,
        *,
        method: str,
        url: str,
        cfg: Dict[str, Any],
        payload: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        data = None if payload is None else json.dumps(payload).encode("utf-8")
        req = Request(url, data=data, method=method.upper(), headers=self._headers(cfg))
        timeout = float(cfg.get("timeout") or 8.0)
        try:
            with urlopen(req, timeout=timeout) as resp:
                raw = resp.read().decode("utf-8", errors="replace")
        except HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")
            msg = body.strip() or str(exc)
            raise RuntimeError(f"PMLTQ HTTP {exc.code} for {url}: {msg}") from exc
        except URLError as exc:
            raise RuntimeError(f"PMLTQ connection error for {url}: {exc}") from exc
        except Exception as exc:
            raise RuntimeError(f"PMLTQ request failed for {url}: {exc}") from exc

        if raw.strip() == "":
            return {}
        try:
            parsed = json.loads(raw)
        except Exception as exc:
            raise RuntimeError(f"PMLTQ returned non-JSON response for {url}: {raw[:300]}") from exc
        if isinstance(parsed, dict) and parsed.get("error"):
            raise RuntimeError(f"PMLTQ API error: {parsed.get('error')}")
        if not isinstance(parsed, dict):
            return {"data": parsed}
        return parsed

    def _treebanks_url(self, cfg: Dict[str, Any]) -> str:
        return f"{cfg['base_url']}/v1/treebanks"

    def _query_url(self, cfg: Dict[str, Any], treebank: str) -> str:
        return f"{cfg['base_url']}/v1/treebanks/{quote(treebank, safe='')}/query"

    def _node_url(self, cfg: Dict[str, Any], treebank: str) -> str:
        return f"{cfg['base_url']}/v1/treebanks/{quote(treebank, safe='')}/node"

    def _normalize_nodes(self, nodes: List[Any]) -> List[Dict[str, Any]]:
        out: List[Dict[str, Any]] = []
        for idx, item in enumerate(nodes):
            alias = ""
            node_type = ""
            if isinstance(item, list):
                if len(item) > 0:
                    alias = str(item[0] or "")
                if len(item) > 1:
                    node_type = str(item[1] or "")
            elif isinstance(item, dict):
                alias = str(item.get("alias") or "")
                node_type = str(item.get("type") or item.get("label") or "")
            else:
                node_type = str(item or "")
            out.append({"index": idx, "alias": alias, "node_type": node_type})
        return out

    def _split_node_ref(self, value: str) -> Dict[str, Any]:
        raw = str(value or "").strip()
        ordinal = None
        ref = raw
        m = re.match(r"^(\d+)/(.*)$", raw)
        if m:
            try:
                ordinal = int(m.group(1))
            except Exception:
                ordinal = None
            ref = m.group(2)
        node_type = ""
        node_id = ref
        if "@" in ref:
            left, right = ref.split("@", 1)
            node_type = left.strip()
            node_id = right.strip()
        return {
            "raw": raw,
            "ordinal": ordinal,
            "ref": ref,
            "node_type": node_type,
            "node_id": node_id,
        }

    def _candidate_doc_ids(self, node_id: str) -> List[str]:
        nid = str(node_id or "").strip()
        if not nid:
            return []
        candidates: List[str] = []
        if nid.endswith(".xml"):
            candidates.append(nid)
        # Heuristic for PDT-like ids, e.g. a-ln94210-2-p1s1w1 -> ln94210-2.xml
        m = re.match(r"^[a-z]-([^-]+-\d+)-p\d+s\d+w\d+$", nid)
        if m:
            doc_base = m.group(1).strip()
            if doc_base:
                candidates.extend([f"{doc_base}.xml", doc_base, f"xmlfiles/{doc_base}.xml"])
        # Fallback: try node_id directly as potential XML id/path.
        candidates.append(nid)
        out: List[str] = []
        seen = set()
        for c in candidates:
            cc = str(c or "").strip()
            if not cc or cc in seen:
                continue
            seen.add(cc)
            out.append(cc)
        return out

    def _resolve_node_locator(
        self, *, cfg: Dict[str, Any], treebank: str, idx_ref: str
    ) -> Dict[str, Any]:
        idx_text = str(idx_ref or "").strip()
        if not idx_text:
            return {}
        # Node endpoint expects idx=<ordinal>/<type>.
        url = f"{self._node_url(cfg, treebank)}?idx={quote(idx_text, safe='')}"
        resp = self._request_json(method="GET", url=url, cfg=cfg)
        handle = str(resp.get("node") or "").strip()
        if not handle:
            return {}
        doc_id = ""
        sentence_id = ""
        token_ord = ""
        if "##" in handle:
            left, right = handle.split("##", 1)
            doc_id = left.strip()
            parts = right.strip().split(".")
            if len(parts) > 0:
                sentence_id = parts[0].strip()
            if len(parts) > 1:
                token_ord = parts[1].strip()
        return {
            "handle": handle,
            "doc_id": doc_id,
            "sentence_id": sentence_id,
            "token_ord": token_ord,
        }

    def _resolve_hit_context(
        self,
        *,
        project: Dict[str, Any],
        teitok_detected: Optional[Dict[str, Any]],
        context_spec: Optional[Dict[str, Any]],
        sentence_id: str,
        tok_ids: List[str],
        doc_id: str,
    ) -> Optional[Dict[str, Any]]:
        if not context_spec:
            return None
        root = Path(str(project.get("root") or ".")).resolve()
        detected = teitok_detected
        if detected is None:
            detected = detect_teitok_cqp(root)
        if not detected:
            return None
        searchfolder = str((detected.get("meta") or {}).get("searchfolder") or "xmlfiles")
        return resolve_teitok_context(
            root_dir=Path(str(detected.get("root") or root)).resolve(),
            searchfolder=searchfolder,
            doc_id=doc_id,
            sentence_id=sentence_id or None,
            tok_ids=[t for t in tok_ids if str(t).strip()],
            match_start=None,
            match_end=None,
            context_spec=context_spec,
            xidx_resolver=None,
        )

    def _find_flexencoder(self, root_dir: Path, cfg: Dict[str, Any]) -> Optional[str]:
        explicit = str(cfg.get("flexencoder") or "").strip()
        if explicit:
            candidate = Path(explicit).expanduser()
            if candidate.is_file() and os.access(candidate, os.X_OK):
                return str(candidate.resolve())
            which = shutil.which(explicit)
            if which:
                return which
        local = root_dir / "Scripts" / "flexencoder"
        if local.is_file() and os.access(local, os.X_OK):
            return str(local)
        tt_root = os.environ.get("TT_ROOT", "").strip()
        if tt_root:
            tt_candidate = Path(tt_root) / "Scripts" / "flexencoder"
            if tt_candidate.is_file() and os.access(tt_candidate, os.X_OK):
                return str(tt_candidate)
        which = shutil.which("flexencoder")
        if which:
            return which
        for p in ("/opt/homebrew/bin/flexencoder", "/usr/local/bin/flexencoder", "/usr/bin/flexencoder"):
            cand = Path(p)
            if cand.is_file() and os.access(cand, os.X_OK):
                return str(cand)
        dev = _flexicorp_scripts_flexencoder()
        if dev:
            return dev
        return None

    def _resolve_settings_xml(self, root_dir: Path) -> Optional[Path]:
        candidates = [root_dir / "tmp" / "cqpsettings.xml", root_dir / "Resources" / "settings.xml"]
        for candidate in candidates:
            if candidate.is_file():
                return candidate
        return None

    def _export_stats(self, export_dir: Path) -> Dict[str, Any]:
        files = {}
        for name in ("docs.jsonl", "sentences.jsonl", "regions.jsonl", "toks.jsonl", "dep_edges.jsonl"):
            path = export_dir / name
            if not path.is_file():
                continue
            line_count = 0
            try:
                with path.open("r", encoding="utf-8", errors="ignore") as fh:
                    for _ in fh:
                        line_count += 1
            except Exception:
                line_count = -1
            files[name] = {"path": str(path), "lines": line_count, "bytes": path.stat().st_size}
        return {"export_dir": str(export_dir), "files": files}

    def _normalize_conversion_mode(self, value: Any) -> str:
        mode = str(value or "legacy").strip().lower()
        if mode in {"legacy", "jsonl", "jsonl_to_pml", "direct"}:
            return "legacy"
        if mode in {"external", "wrapper", "upstream"}:
            return "external"
        return "legacy"

    def _collect_pml_outputs(self, pml_dir: Path) -> Dict[str, Any]:
        files: List[str] = []
        if pml_dir.is_dir():
            for patt in ("*.pml", "*.a", "*.m", "*.w", "*.xml"):
                for fp in sorted(pml_dir.glob(patt)):
                    if fp.is_file():
                        files.append(str(fp.resolve()))
        return {
            "ok": bool(files),
            "out_dir": str(pml_dir),
            "files": files,
            "triplets": 0,
            "bundles": 0,
            "message": f"Detected {len(files)} converter output file(s) in {pml_dir}.",
        }

    def _run_external_converter(
        self,
        *,
        root_dir: Path,
        export_dir: Path,
        pml_out: Path,
        cfg: Dict[str, Any],
        params: Dict[str, Any],
    ) -> Dict[str, Any]:
        cmd = str(params.get("pmltq_converter_cmd") or cfg.get("pmltq_converter_cmd") or "").strip()
        if not cmd:
            raise RuntimeError(
                "PMLTQ external conversion mode requires -O pmltq_converter_cmd='...'. "
                "This path is opt-in and leaves non-PMLTQ backends untouched."
            )

        workdir_raw = str(params.get("pmltq_converter_workdir") or cfg.get("pmltq_converter_workdir") or "").strip()
        workdir = Path(workdir_raw).expanduser() if workdir_raw else root_dir
        if not workdir.is_absolute():
            workdir = (root_dir / workdir).resolve()
        pml_out.mkdir(parents=True, exist_ok=True)
        env = os.environ.copy()
        files_mode = str(cfg.get("pmltq_files_mode") or "").strip().lower()
        files_suffix = str(cfg.get("pmltq_files_suffix") or "").strip()
        if not files_mode:
            files_mode = "ordinal" if bool(cfg.get("pmltq_write_pml", False)) else "id"
        if not files_suffix:
            files_suffix = ".a.gz" if bool(cfg.get("pmltq_write_pml", False)) else ""
        env.update(
            {
                "FLEXICORP_PROJECT_ROOT": str(root_dir),
                "FLEXICORP_PMLTQ_EXPORT_DIR": str(export_dir),
                "FLEXICORP_PML_DIR": str(pml_out),
                "FLEXICORP_PMLTQ_TREEBANK": str(cfg.get("treebank") or ""),
                "FLEXICORP_PMLTQ_SCHEMA_DIR": str(cfg.get("pmltq_schema_dir") or ""),
                "FLEXICORP_PMLTQ_SCHEMA_MODE": str(cfg.get("pmltq_schema_mode") or "reference"),
            }
        )
        proc = subprocess.run(
            cmd,
            cwd=str(workdir),
            text=True,
            capture_output=True,
            check=False,
            shell=True,
            env=env,
        )
        if proc.returncode != 0:
            raise RuntimeError(
                f"PMLTQ converter command failed (exit {proc.returncode}): {(proc.stderr or proc.stdout).strip()}"
            )
        out = self._collect_pml_outputs(pml_out)
        if not out.get("ok"):
            raise RuntimeError(
                f"PMLTQ converter command succeeded but no PML-like output files were found in {pml_out}."
            )
        out["converter"] = {
            "mode": "external",
            "command": cmd,
            "workdir": str(workdir),
            "stdout_tail": (proc.stdout or "")[-1200:],
            "stderr_tail": (proc.stderr or "")[-1200:],
        }
        return out

    def _run_sql_stage(
        self,
        *,
        root_dir: Path,
        export_dir: Path,
        pml_out: Optional[Path],
        cfg: Dict[str, Any],
        params: Dict[str, Any],
    ) -> Dict[str, Any]:
        sql_cmd = str(params.get("pmltq_sql_cmd") or cfg.get("sql_cmd") or "").strip()
        if not sql_cmd:
            # Default integrated SQL builder: TEITOK/CWB VRT -> PMLTQ SQL tables.
            sql_cmd = (
                f'"{sys.executable}" -m flexicorp.pmltq.sql_from_vrt '
                "--load --recreate-db"
            )
        if not sql_cmd:
            raise RuntimeError(
                "PMLTQ SQL stage is enabled but no SQL builder command is configured. "
                "Set -O pmltq_sql_cmd='...' (or disable with -O pmltq_run_sql=no)."
            )

        settings_xml = self._resolve_settings_xml(root_dir)
        cqp_detected = detect_teitok_cqp(root_dir) or {}
        cqp_cfg = dict(cqp_detected.get("cqp") or {})
        # Prefer the same VRT conventions Manatee/CWB uses in TEITOK projects.
        vrt_candidates = [
            root_dir / "manatee" / "corpus.vrt",
            root_dir / "cqp" / "corpus.vrt",
            root_dir / "tmp" / "corpus.vrt",
        ]
        vrt_path = next((p for p in vrt_candidates if p.is_file()), None)

        files_mode = str(cfg.get("pmltq_files_mode") or "").strip().lower()
        files_suffix = str(cfg.get("pmltq_files_suffix") or "").strip()
        if not files_mode:
            files_mode = "ordinal" if bool(cfg.get("pmltq_write_pml", False)) else "id"
        if not files_suffix:
            files_suffix = ".a.gz" if bool(cfg.get("pmltq_write_pml", False)) else ""

        env = os.environ.copy()
        env.update(
            {
                "FLEXICORP_PROJECT_ROOT": str(root_dir),
                "FLEXICORP_PMLTQ_EXPORT_DIR": str(export_dir),
                "FLEXICORP_PML_DIR": str(pml_out) if pml_out is not None else "",
                "FLEXICORP_PMLTQ_TREEBANK": str(cfg.get("treebank") or ""),
                "FLEXICORP_PMLTQ_URL": str(cfg.get("base_url") or ""),
                # Inputs expected by CWB/VRT-driven builders (teitok2pmltq-style).
                "FLEXICORP_PMLTQ_SETTINGS_XML": str(settings_xml) if settings_xml is not None else "",
                "FLEXICORP_PMLTQ_VRT": str(vrt_path) if vrt_path is not None else "",
                "FLEXICORP_PMLTQ_CQP_REGISTRY": str(cqp_cfg.get("registry") or ""),
                "FLEXICORP_PMLTQ_CQP_CORPUS": str(cqp_cfg.get("corpus") or ""),
                "FLEXICORP_PMLTQ_SCHEMA_FILE": str(cfg.get("pmltq_schema_file") or ""),
                "FLEXICORP_PMLTQ_DATA_DIR": str(cfg.get("pmltq_data_dir") or ""),
                "FLEXICORP_PMLTQ_FILES_MODE": files_mode,
                "FLEXICORP_PMLTQ_FILES_SUFFIX": files_suffix,
            }
        )
        # Ensure `python -m flexicorp...` SQL commands can resolve this checkout.
        flexicorp_parent = Path(__file__).resolve().parents[2]
        pp_parts = [p for p in str(env.get("PYTHONPATH", "")).split(os.pathsep) if p]
        if str(flexicorp_parent) not in pp_parts:
            pp_parts.insert(0, str(flexicorp_parent))
            env["PYTHONPATH"] = os.pathsep.join(pp_parts)
        proc = subprocess.run(
            sql_cmd,
            cwd=str(root_dir),
            text=True,
            capture_output=True,
            check=False,
            shell=True,
            env=env,
        )
        if proc.returncode != 0:
            raise RuntimeError(
                f"PMLTQ SQL stage command failed (exit {proc.returncode}): {(proc.stderr or proc.stdout).strip()}"
            )
        return {
            "configured": True,
            "executed": True,
            "command": sql_cmd,
            "exit_code": proc.returncode,
            "inputs": {
                "settings_xml": str(settings_xml) if settings_xml is not None else "",
                "vrt": str(vrt_path) if vrt_path is not None else "",
                "cqp_registry": str(cqp_cfg.get("registry") or ""),
                "cqp_corpus": str(cqp_cfg.get("corpus") or ""),
            },
            "stdout_tail": (proc.stdout or "")[-1200:],
            "stderr_tail": (proc.stderr or "")[-1200:],
        }

    def _emit_schema_bundle(
        self, *, root_dir: Path, pml_out: Path, cfg: Dict[str, Any], params: Dict[str, Any]
    ) -> Dict[str, Any]:
        bundle_raw = str(
            params.get("pmltq_schema_bundle_dir")
            or cfg.get("pmltq_schema_bundle_dir")
            or cfg.get("pmltq_schema_dir")
            or ""
        ).strip()
        if not bundle_raw:
            return {"configured": False, "copied": [], "missing": []}
        bundle_dir = Path(bundle_raw).expanduser()
        if not bundle_dir.is_absolute():
            bundle_dir = (root_dir / bundle_dir).resolve()
        if not bundle_dir.is_dir():
            return {
                "configured": True,
                "source": str(bundle_dir),
                "copied": [],
                "missing": ["bundle_dir_not_found"],
            }
        wanted = [
            "adata_30_schema.xml",
            "mdata_30_schema.xml",
            "wdata_30_schema.xml",
            "conll2009_schema.xml",
            "tdata_30_schema.xml",
        ]
        copied: List[str] = []
        missing: List[str] = []
        pml_out.mkdir(parents=True, exist_ok=True)
        for name in wanted:
            src = bundle_dir / name
            if not src.is_file():
                missing.append(name)
                continue
            dst = pml_out / name
            shutil.copy2(src, dst)
            copied.append(str(dst))
        return {
            "configured": True,
            "source": str(bundle_dir),
            "copied": copied,
            "missing": missing,
        }

    def _row_to_hit(
        self,
        *,
        row: Any,
        row_index: int,
        node_schema: List[Dict[str, Any]],
    ) -> Dict[str, Any]:
        cells = row if isinstance(row, list) else [row]
        bindings: List[Dict[str, Any]] = []
        primary_node_id = ""
        primary_node_type = ""
        primary_idx_ref = ""
        for idx, cell in enumerate(cells):
            node_info = node_schema[idx] if idx < len(node_schema) else {"index": idx, "alias": "", "node_type": ""}
            parsed = self._split_node_ref(str(cell or ""))
            if not primary_node_id and parsed.get("node_id"):
                primary_node_id = str(parsed.get("node_id") or "")
                primary_node_type = str(parsed.get("node_type") or node_info.get("node_type") or "")
                ordinal = parsed.get("ordinal")
                if ordinal is not None and primary_node_type:
                    primary_idx_ref = f"{int(ordinal)}/{primary_node_type}"
            bindings.append(
                {
                    "index": idx,
                    "alias": str(node_info.get("alias") or ""),
                    "node_type": str(parsed.get("node_type") or node_info.get("node_type") or ""),
                    "node_id": str(parsed.get("node_id") or ""),
                    "ordinal": parsed.get("ordinal"),
                    "raw": parsed.get("raw"),
                }
            )

        hit: Dict[str, Any] = {
            "doc_id": None,
            "sentence_id": None,
            "match_start": None,
            "match_end": None,
            "left": [],
            "match": [primary_node_id] if primary_node_id else [],
            "right": [],
            "toks": [primary_node_id] if primary_node_id else [],
            "row_index": row_index,
            "node_type": primary_node_type or None,
            "bindings": bindings,
            "raw": row,
            "_primary_node_id": primary_node_id,
            "_primary_idx_ref": primary_idx_ref,
        }
        return hit

    def _attach_print_context(
        self,
        *,
        hits: List[Dict[str, Any]],
        cfg: Dict[str, Any],
        treebank: str,
        project: Dict[str, Any],
        context_spec: Optional[Dict[str, Any]],
    ) -> None:
        def _uniq(values: List[str]) -> List[str]:
            out: List[str] = []
            seen = set()
            for raw in values:
                v = str(raw or "").strip()
                if not v or v in seen:
                    continue
                seen.add(v)
                out.append(v)
            return out

        if not hits:
            return
        if not context_spec:
            for hit in hits:
                hit.pop("_primary_node_id", None)
                hit.pop("_primary_idx_ref", None)
            return
        teitok_detected = detect_teitok_cqp(Path(str(project.get("root") or ".")).resolve())
        node_locator_cache: Dict[str, Dict[str, Any]] = {}
        for hit in hits:
            primary_node_id = str(hit.get("_primary_node_id") or "").strip()
            idx_ref = str(hit.get("_primary_idx_ref") or "").strip()
            resolved_doc_id = ""
            resolved_sentence_id = ""
            tok_ids_for_context: List[str] = [primary_node_id] if primary_node_id else []
            if idx_ref:
                locator = node_locator_cache.get(idx_ref)
                if locator is None:
                    try:
                        locator = self._resolve_node_locator(cfg=cfg, treebank=treebank, idx_ref=idx_ref)
                    except Exception:
                        locator = {}
                    node_locator_cache[idx_ref] = locator
                if locator:
                    resolved_doc_id = str(locator.get("doc_id") or "").strip()
                    resolved_sentence_id = str(locator.get("sentence_id") or "").strip()
            tok_ids_for_context = _uniq(tok_ids_for_context)

            doc_candidates: List[str] = []
            if resolved_doc_id:
                doc_candidates.extend(
                    [resolved_doc_id, f"{resolved_doc_id}.xml", f"xmlfiles/{resolved_doc_id}.xml"]
                )
            doc_candidates.extend(self._candidate_doc_ids(primary_node_id))

            context: Optional[Dict[str, Any]] = None
            seen_docs: set[str] = set()
            for candidate in doc_candidates:
                candidate = str(candidate or "").strip()
                if not candidate or candidate in seen_docs:
                    continue
                seen_docs.add(candidate)
                context = self._resolve_hit_context(
                    project=project,
                    teitok_detected=teitok_detected,
                    context_spec=context_spec,
                    sentence_id=resolved_sentence_id,
                    tok_ids=tok_ids_for_context,
                    doc_id=candidate,
                )
                if context:
                    hit["doc_id"] = candidate
                    hit["context"] = context
                    if resolved_sentence_id:
                        hit["sentence_id"] = resolved_sentence_id
                    locator = context.get("locator") if isinstance(context, dict) else None
                    if isinstance(locator, dict):
                        ctx_sentence_id = str(locator.get("sentence_id") or "").strip()
                        if ctx_sentence_id:
                            hit["sentence_id"] = ctx_sentence_id
                        tok_ids_ctx = locator.get("token_ids")
                        if isinstance(tok_ids_ctx, list) and tok_ids_ctx:
                            hit["toks"] = _uniq([str(t) for t in tok_ids_ctx if str(t or "").strip()])
                    break
            if not hit.get("doc_id"):
                fallback = next((c for c in doc_candidates if str(c or "").strip()), "")
                hit["doc_id"] = fallback or None
            hit_tok_ids = _uniq([str(t) for t in (hit.get("toks") or []) if str(t or "").strip()])
            if hit_tok_ids:
                # Keep highlighting centralized in the UI: emit the same highlight_map
                # contract used by other backends.
                hit["highlight_map"] = build_highlight_map(hit_tok_ids)
        for hit in hits:
            hit.pop("_primary_node_id", None)
            hit.pop("_primary_idx_ref", None)

    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._resolve_cfg(req)
        treebanks = self._request_json(method="GET", url=self._treebanks_url(cfg), cfg=cfg)
        listed = treebanks.get("data") if isinstance(treebanks.get("data"), list) else None
        if listed is None:
            listed = treebanks if isinstance(treebanks, list) else []
        names = []
        for item in listed:
            if isinstance(item, dict) and item.get("name"):
                names.append(str(item["name"]))
        target = str(cfg.get("treebank") or "").strip()
        return {
            "ok": True,
            "base_url": cfg["base_url"],
            "treebank": target,
            "treebanks": names,
            "treebank_found": (target in names) if target else None,
            "auth_configured": bool(cfg.get("token") or cfg.get("cookie") or cfg.get("username")),
        }

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._resolve_cfg(req)
        treebanks = self._request_json(method="GET", url=self._treebanks_url(cfg), cfg=cfg)
        listed = treebanks.get("data") if isinstance(treebanks.get("data"), list) else None
        if listed is None:
            listed = treebanks if isinstance(treebanks, list) else []
        docs = []
        for item in listed:
            if not isinstance(item, dict):
                continue
            name = str(item.get("name") or "").strip()
            if not name:
                continue
            docs.append(
                {
                    "id": name,
                    "title": str(item.get("title") or name),
                    "meta": {
                        "isPublic": item.get("isPublic"),
                        "isAllLogged": item.get("isAllLogged"),
                        "isFree": item.get("isFree"),
                    },
                }
            )
        return {"docs": docs, "total": len(docs)}

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._resolve_cfg(req)
        return {
            "backend": self.name,
            "base_url": cfg["base_url"],
            "treebank": cfg.get("treebank"),
            "auth_configured": bool(cfg.get("token") or cfg.get("cookie") or cfg.get("username")),
            "supports": ["status", "list_docs", "query", "kwic", "raw_query", "reindex"],
            "reindex": {
                "flexencoder": str(cfg.get("flexencoder") or "auto"),
                "export_dir": str(cfg.get("export_dir") or "tmp/pmltq-export"),
                "run_sql": _as_bool(cfg.get("pmltq_run_sql", True)),
                "sql_cmd_configured": bool(cfg.get("sql_cmd")),
                "sql_cmd_default": "python -m flexicorp.pmltq.sql_from_vrt --load --recreate-db",
                "write_pml": _as_bool(cfg.get("pmltq_write_pml", False)),
                "conversion_mode": self._normalize_conversion_mode(cfg.get("pmltq_conversion_mode")),
                "run_import": _as_bool(cfg.get("pmltq_run_import", True)),
                "import_cmd_configured": bool(cfg.get("import_cmd") or cfg.get("pmltq_run_import", True)),
            },
        }

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        cfg = self._resolve_cfg(req)

        root_dir = Path(str(project.get("root") or ".")).resolve()
        if not root_dir.is_dir():
            raise RuntimeError(f"PMLTQ reindex project root not found: {root_dir}")
        settings_xml = self._resolve_settings_xml(root_dir)
        if settings_xml is None:
            raise RuntimeError(
                f"PMLTQ reindex requires TEITOK settings XML at {root_dir / 'tmp' / 'cqpsettings.xml'} "
                f"or {root_dir / 'Resources' / 'settings.xml'}."
            )

        flexencoder_bin = self._find_flexencoder(root_dir, cfg)
        if not flexencoder_bin:
            raise RuntimeError(
                "PMLTQ reindex requires flexencoder, but no executable was found in project Scripts/, TT_ROOT, "
                "the flexicorp checkout (scripts/flexencoder), FLEXICORP_ROOT, or PATH. "
                "Use -O pmltq_flexencoder=/path/to/flexencoder to override."
            )

        export_dir_raw = str(
            params.get("pmltq_export_dir")
            or cfg.get("export_dir")
            or (root_dir / "tmp" / "pmltq-export")
        )
        export_dir = Path(export_dir_raw).expanduser()
        if not export_dir.is_absolute():
            export_dir = (root_dir / export_dir).resolve()
        export_dir.mkdir(parents=True, exist_ok=True)

        cmd = [
            flexencoder_bin,
            "--project-root",
            str(root_dir),
            "--settings",
            str(settings_xml),
            "--output-clickhouse",
            str(export_dir),
        ]
        proc = subprocess.run(
            cmd,
            cwd=str(root_dir),
            text=True,
            capture_output=True,
            check=False,
        )
        if proc.returncode != 0:
            raise RuntimeError(
                f"flexencoder failed for PMLTQ reindex (exit {proc.returncode}): "
                f"{(proc.stderr or proc.stdout).strip()}"
            )

        export_stats = self._export_stats(export_dir)

        pml_out: Optional[Path] = None
        pml_result: Optional[Dict[str, Any]] = None
        schema_bundle_result: Dict[str, Any] = {"configured": False, "copied": [], "missing": []}
        if cfg.get("pmltq_write_pml", False):
            conversion_mode = self._normalize_conversion_mode(cfg.get("pmltq_conversion_mode"))
            pml_raw = str(cfg.get("pmltq_pml_dir") or "").strip()
            if pml_raw:
                pml_out = Path(pml_raw).expanduser()
                pml_out = (root_dir / pml_out).resolve() if not pml_out.is_absolute() else pml_out.resolve()
            else:
                pml_out = (export_dir / "pml").resolve()
            if conversion_mode == "external":
                pml_result = self._run_external_converter(
                    root_dir=root_dir,
                    export_dir=export_dir,
                    pml_out=pml_out,
                    cfg=cfg,
                    params=params,
                )
            else:
                pml_result = convert_jsonl_to_pml(
                    export_dir,
                    pml_out,
                    lang=str(cfg.get("pmltq_lang") or "en"),
                    pml_layers=str(cfg.get("pmltq_pml_layers") or "pdt3"),
                    gzip_output=bool(cfg.get("pmltq_pml_gzip", True)),
                )
            if not pml_result.get("ok"):
                raise RuntimeError(pml_result.get("message") or "JSONL to PML conversion failed.")
            schema_bundle_result = self._emit_schema_bundle(
                root_dir=root_dir,
                pml_out=pml_out,
                cfg=cfg,
                params=params,
            )

        sql_result: Dict[str, Any] = {"configured": False, "executed": False}
        if bool(cfg.get("pmltq_run_sql", True)):
            sql_result = self._run_sql_stage(
                root_dir=root_dir,
                export_dir=export_dir,
                pml_out=pml_out,
                cfg=cfg,
                params=params,
            )

        run_import = bool(cfg.get("pmltq_run_import", True))
        import_cmd = ""
        if run_import and cfg.get("pmltq_write_pml", False):
            if "pmltq_import_cmd" in params:
                import_cmd = str(params.get("pmltq_import_cmd") or "").strip()
            else:
                import_cmd = str(cfg.get("import_cmd") or "").strip()
            if not import_cmd and "pmltq_import_cmd" not in params:
                import_cmd = f'"{sys.executable}" -m flexicorp.pml.pml_post_export'

        import_result: Dict[str, Any] = {"configured": bool(import_cmd), "executed": False}
        if import_cmd and run_import:
            env = os.environ.copy()
            env.update(
                {
                    "FLEXICORP_PROJECT_ROOT": str(root_dir),
                    "FLEXICORP_PMLTQ_EXPORT_DIR": str(export_dir),
                    "FLEXICORP_PML_DIR": str(pml_out) if pml_out is not None else "",
                    "FLEXICORP_PML_LANG": str(cfg.get("pmltq_lang") or "en"),
                    "FLEXICORP_PMLTQ_TREEBANK": str(cfg.get("treebank") or ""),
                    "FLEXICORP_PMLTQ_URL": str(cfg.get("base_url") or ""),
                }
            )
            # So ``python -m flexicorp....`` in the hook sees the same package as this process
            # (dev checkouts are often run with PYTHONPATH only on the parent shell invocation).
            flexicorp_parent = Path(__file__).resolve().parents[2]
            pp_parts = [p for p in str(env.get("PYTHONPATH", "")).split(os.pathsep) if p]
            if str(flexicorp_parent) not in pp_parts:
                pp_parts.insert(0, str(flexicorp_parent))
                env["PYTHONPATH"] = os.pathsep.join(pp_parts)

            imp = subprocess.run(
                import_cmd,
                cwd=str(root_dir),
                text=True,
                capture_output=True,
                check=False,
                shell=True,
                env=env,
            )
            import_result.update(
                {
                    "executed": True,
                    "command": import_cmd,
                    "exit_code": imp.returncode,
                    "stdout_tail": (imp.stdout or "")[-1200:],
                    "stderr_tail": (imp.stderr or "")[-1200:],
                }
            )
            if imp.returncode != 0:
                raise RuntimeError(
                    f"PMLTQ import command failed (exit {imp.returncode}): {(imp.stderr or imp.stdout).strip()}"
                )

        notes = [
            "Export payload mirrors ClickHouse flexencoder JSONL schema (docs/sentences/regions/toks/dep_edges).",
            "SQL stage is the flexicorp-query path and should stay enabled by default (configure via -O pmltq_sql_cmd='...').",
            "PML/PMLTQ-server export is an optional separate stage (enable with -O pmltq_write_pml=yes).",
            "Conversion mode defaults to legacy JSONL->PML in flexicorp; opt into wrapper mode with -O pmltq_conversion_mode=external.",
            "PML: default is PDT-like .w + .m + .a triplets (pdt3); use -O pmltq_pml_layers=wa for compact .w + .a, flat for one .flat.xml per doc, or conll2009 for conll2pml-like .pml + schema output.",
            "Default post step: python -m flexicorp.pml.pml_post_export (override with -O pmltq_import_cmd=... or disable with -O pmltq_run_import=no).",
        ]

        out: Dict[str, Any] = {
            "backend": self.name,
            "project_root": str(root_dir),
            "treebank": str(cfg.get("treebank") or ""),
            "flexencoder": flexencoder_bin,
            "settings_xml": str(settings_xml),
            "export": export_stats,
            "sql": sql_result,
            "import": import_result,
            "notes": notes,
        }
        if pml_result is not None and pml_out is not None:
            out["pml"] = {"dir": str(pml_out), **pml_result}
            out["pml"]["schemas"] = schema_bundle_result
        return out

    def raw_query(self, req: FlexiRequest) -> Dict[str, Any]:
        return self.query(req)

    def kwic(self, req: FlexiRequest) -> Dict[str, Any]:
        return self.query(req)

    def freq(self, req: FlexiRequest) -> Dict[str, Any]:
        raise NotImplementedError("PMLTQ backend does not implement freq yet.")

    def query(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._resolve_cfg(req)
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})

        treebank = str(cfg.get("treebank") or "").strip()
        if not treebank:
            raise RuntimeError(
                "PMLTQ backend requires a treebank id. Provide --corpus <treebank> "
                "or -O pmltq_treebank=<treebank> (or env PMLTQ_TREEBANK)."
            )

        raw_query = params.get("query", "")
        if isinstance(raw_query, dict):
            raise RuntimeError("PMLTQ backend expects params['query'] as a PML-TQ string, not a dict.")
        query_text = str(raw_query or params.get("pattern") or "").strip()
        if not query_text:
            raise RuntimeError("PMLTQ query requires params['query'] with a non-empty PML-TQ expression.")

        start = max(0, int(params.get("start", 0)))
        limit = max(1, min(int(params.get("max", params.get("limit", 50))), 5000))
        payload: Dict[str, Any] = {"query": query_text, "limit": limit}
        if start > 0:
            payload["offset"] = start

        treebank_used = treebank
        try:
            response = self._request_json(
                method="POST",
                url=self._query_url(cfg, treebank_used),
                cfg=cfg,
                payload=payload,
            )
        except RuntimeError as exc:
            alt_treebank = ""
            if "-" in treebank:
                alt_treebank = treebank.replace("-", "_")
            elif "_" in treebank:
                alt_treebank = treebank.replace("_", "-")
            err = str(exc)
            if (
                alt_treebank
                and alt_treebank != treebank
                and "Treebank" in err
                and "not found" in err
            ):
                response = self._request_json(
                    method="POST",
                    url=self._query_url(cfg, alt_treebank),
                    cfg=cfg,
                    payload=payload,
                )
                treebank_used = alt_treebank
            else:
                raise
        rows = response.get("results") if isinstance(response.get("results"), list) else []
        nodes = response.get("nodes") if isinstance(response.get("nodes"), list) else []
        node_schema = self._normalize_nodes(nodes)

        context_spec = None
        if _as_bool(params.get("extract_fragments")) or params.get("context_scope") or params.get("context_format") or params.get("context_level"):
            scope = str(params.get("context_scope") or params.get("context_level") or "s").strip() or "s"
            fmt = str(params.get("context_format") or "xml").strip() or "xml"
            context_spec = {"scope": scope, "format": fmt, "prefer": "xml", "fallback": True}
        hits = [
            self._row_to_hit(
                row=row,
                row_index=idx + start,
                node_schema=node_schema,
            )
            for idx, row in enumerate(rows)
        ]
        self._attach_print_context(
            hits=hits,
            cfg=cfg,
            treebank=treebank_used,
            project=project,
            context_spec=context_spec,
        )

        total_raw = response.get("total")
        if total_raw is None:
            total_raw = response.get("count")
        if total_raw is None:
            total_raw = response.get("totalResults")
        total: Optional[int] = None
        total_exact = False
        if isinstance(total_raw, int):
            total = int(total_raw)
            total_exact = True

        return {
            "treebank": treebank_used,
            "query": query_text,
            "start": start,
            "limit": limit,
            "returned": len(hits),
            "total": total,
            "total_exact": total_exact,
            "hits": hits,
            "nodes": nodes,
            "results": rows,
            "raw": response,
        }


register_backend(PmltqBackend())

