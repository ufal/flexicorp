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
            "pmltq_lang",
            "pmltq_pml_dir",
            "pmltq_pml_layers",
            "pmltq_import_cmd",
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
            "flexencoder": str(cfg.get("flexencoder") or cfg.get("pmltq_flexencoder") or "").strip(),
            "pmltq_write_pml": _as_bool(cfg.get("pmltq_write_pml", True)),
            "pmltq_run_import": _as_bool(cfg.get("pmltq_run_import", True)),
            "pmltq_lang": str(cfg.get("pmltq_lang") or "en").strip() or "en",
            "pmltq_pml_dir": str(cfg.get("pmltq_pml_dir") or "").strip(),
            "pmltq_pml_layers": str(cfg.get("pmltq_pml_layers") or "wa").strip().lower() or "wa",
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

    def _resolve_hit_context(
        self,
        *,
        project: Dict[str, Any],
        teitok_detected: Optional[Dict[str, Any]],
        context_spec: Optional[Dict[str, Any]],
        node_id: str,
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
            sentence_id=None,
            tok_ids=[node_id] if node_id else [],
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

    def _row_to_hit(
        self,
        *,
        row: Any,
        row_index: int,
        node_schema: List[Dict[str, Any]],
        project: Dict[str, Any],
        teitok_detected: Optional[Dict[str, Any]],
        context_spec: Optional[Dict[str, Any]],
    ) -> Dict[str, Any]:
        cells = row if isinstance(row, list) else [row]
        bindings: List[Dict[str, Any]] = []
        primary_doc_id = ""
        primary_node_id = ""
        primary_node_type = ""
        for idx, cell in enumerate(cells):
            node_info = node_schema[idx] if idx < len(node_schema) else {"index": idx, "alias": "", "node_type": ""}
            parsed = self._split_node_ref(str(cell or ""))
            if not primary_node_id and parsed.get("node_id"):
                primary_node_id = str(parsed.get("node_id") or "")
                primary_node_type = str(parsed.get("node_type") or node_info.get("node_type") or "")
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

        context: Optional[Dict[str, Any]] = None
        for candidate in self._candidate_doc_ids(primary_node_id):
            context = self._resolve_hit_context(
                project=project,
                teitok_detected=teitok_detected,
                context_spec=context_spec,
                node_id=primary_node_id,
                doc_id=candidate,
            )
            if context:
                primary_doc_id = candidate
                break
        if not primary_doc_id:
            candidates = self._candidate_doc_ids(primary_node_id)
            primary_doc_id = candidates[0] if candidates else ""

        hit: Dict[str, Any] = {
            "doc_id": primary_doc_id or None,
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
        }
        if context:
            hit["context"] = context
        return hit

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
                "write_pml": _as_bool(cfg.get("pmltq_write_pml", True)),
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
        if cfg.get("pmltq_write_pml", True):
            pml_raw = str(cfg.get("pmltq_pml_dir") or "").strip()
            if pml_raw:
                pml_out = Path(pml_raw).expanduser()
                pml_out = (root_dir / pml_out).resolve() if not pml_out.is_absolute() else pml_out.resolve()
            else:
                pml_out = (export_dir / "pml").resolve()
            pml_result = convert_jsonl_to_pml(
                export_dir,
                pml_out,
                lang=str(cfg.get("pmltq_lang") or "en"),
                pml_layers=str(cfg.get("pmltq_pml_layers") or "wa"),
            )
            if not pml_result.get("ok"):
                raise RuntimeError(pml_result.get("message") or "JSONL to PML conversion failed.")

        run_import = bool(cfg.get("pmltq_run_import", True))
        import_cmd = ""
        if run_import:
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
            "PML: default is compact .w + .a (no .m); use -O pmltq_pml_layers=pdt3 for full PDT triplets or flat for one .flat.xml per doc.",
            "Default post step: python -m flexicorp.pml.pml_post_export (override with -O pmltq_import_cmd=... or disable with -O pmltq_run_import=no).",
        ]

        out: Dict[str, Any] = {
            "backend": self.name,
            "project_root": str(root_dir),
            "treebank": str(cfg.get("treebank") or ""),
            "flexencoder": flexencoder_bin,
            "settings_xml": str(settings_xml),
            "export": export_stats,
            "import": import_result,
            "notes": notes,
        }
        if pml_result is not None and pml_out is not None:
            out["pml"] = {"dir": str(pml_out), **pml_result}
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

        response = self._request_json(
            method="POST",
            url=self._query_url(cfg, treebank),
            cfg=cfg,
            payload=payload,
        )
        rows = response.get("results") if isinstance(response.get("results"), list) else []
        nodes = response.get("nodes") if isinstance(response.get("nodes"), list) else []
        node_schema = self._normalize_nodes(nodes)

        context_spec = None
        if _as_bool(params.get("extract_fragments")) or params.get("context_scope") or params.get("context_format") or params.get("context_level"):
            scope = str(params.get("context_scope") or params.get("context_level") or "s").strip() or "s"
            fmt = str(params.get("context_format") or "xml").strip() or "xml"
            context_spec = {"scope": scope, "format": fmt, "prefer": "xml", "fallback": True}
        teitok_detected = detect_teitok_cqp(Path(str(project.get("root") or ".")).resolve()) if context_spec else None

        hits = [
            self._row_to_hit(
                row=row,
                row_index=idx + start,
                node_schema=node_schema,
                project=project,
                teitok_detected=teitok_detected,
                context_spec=context_spec,
            )
            for idx, row in enumerate(rows)
        ]

        return {
            "treebank": treebank,
            "query": query_text,
            "start": start,
            "limit": limit,
            "returned": len(hits),
            "total": None,
            "total_exact": False,
            "hits": hits,
            "nodes": nodes,
            "results": rows,
            "raw": response,
        }


register_backend(PmltqBackend())

