from __future__ import annotations

import json
import shutil
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List

from ..config import get_project_root
from ..core import CorpusBackend, FlexiRequest, register_backend
from ..flexencoder_xidx import read_xidx_docs_lines, xidx_rel_to_doc_id


@dataclass
class PandoBackend(CorpusBackend):
    """
    Pando (tree-aware) backend.

    CLI-based:
    - reindex: JSONL events + `pando-index` into ``<project>/pando/``.
    - query: ``pando <index> <ClickCQL> --json``.
    - list_docs: document IDs from flexencoder ``xidx/docs.tbl`` (same list Pando uses with ``xidx/``),
      so teitokxml can sync ``doc_index_status`` for the ``pando`` backend.
    """

    name: str = "pando"

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "pando",
            "supported_query_languages": ["clickcql"],
            "supported_corpus_formats": ["pando"],
            "default_query_language": "clickcql",
            "default_corpus_format": "pando",
            "default_selection_reason": "Tree-aware Pando engine (ClickCQL over dependencies).",
        }

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": False,
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
            "raw_query": False,
            "query": True,
        }

    def _index_dir(self, project: Dict[str, Any]) -> Path:
        root = get_project_root(project)
        return root / "pando"

    def _resolve_pando_query_binary(self) -> str:
        """
        Prefer flexicorp-pando (TEITOK-aligned wrapper), fall back to pando.
        """
        for name in ("flexicorp-pando", "pando"):
            resolved = shutil.which(name)
            if resolved:
                return resolved
        return "flexicorp-pando"

    def _parse_corpus_info(self, index_dir: Path) -> Dict[str, Any]:
        """
        Parse pando/corpus.info (key=value with comma-separated lists).
        """
        out: Dict[str, Any] = {}
        info_path = index_dir / "corpus.info"
        if not info_path.is_file():
            return out
        try:
            lines = info_path.read_text(encoding="utf-8", errors="replace").splitlines()
        except OSError:
            return out
        for line in lines:
            s = line.strip()
            if not s or s.startswith("#") or "=" not in s:
                continue
            key, raw = s.split("=", 1)
            key = key.strip()
            val = raw.strip()
            if not key:
                continue
            if key in {"positional", "region_attrs", "structural"}:
                parts = [p.strip() for p in val.split(",") if p.strip()]
                out[key] = parts
            else:
                out[key] = val
        return out

    # ------------------------------------------------------------------ status/info
    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        root = get_project_root(project)
        index_dir = self._index_dir(project)
        available = index_dir.is_dir()
        reason = (
            f"Pando index found at {index_dir}"
            if available
            else f"Pando index directory not found: {index_dir}"
        )
        return {
            "backend": self.name,
            "project_root": str(root),
            "index_dir": str(index_dir),
            "available": available,
            "reason": reason,
        }

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        root = get_project_root(project)
        index_dir = self._index_dir(project)
        if not index_dir.is_dir():
            raise RuntimeError(f"Pando index directory not found: {index_dir}")
        info = self._parse_corpus_info(index_dir)

        pattrs = info.get("positional") if isinstance(info.get("positional"), list) else []
        structures = info.get("structural") if isinstance(info.get("structural"), list) else []
        region_attrs = info.get("region_attrs") if isinstance(info.get("region_attrs"), list) else []
        sattributes_by_region: Dict[str, List[str]] = {}
        for ra in region_attrs:
            if not isinstance(ra, str):
                continue
            if "_" in ra:
                reg, attr = ra.split("_", 1)
                if reg and attr:
                    sattributes_by_region.setdefault(reg, []).append(attr)

        docs = read_xidx_docs_lines(root)
        docs_count = len(docs)
        tokens_count = None
        for key in ("tokens_count", "corpus_tokens", "size", "tokens"):
            raw = info.get(key)
            if raw is None:
                continue
            try:
                n = int(str(raw))
            except (TypeError, ValueError):
                continue
            if n >= 0:
                tokens_count = n
                break

        result: Dict[str, Any] = {
            "backend": self.name,
            "descriptor": self.descriptor(),
            "index_dir": str(index_dir),
            "corpus": root.name.upper(),
            "docs_count": docs_count,
            "pattributes": pattrs,
            "struct_attributes": structures,
            "sattributes_by_region": sattributes_by_region,
            "corpus_info": info,
        }
        if tokens_count is not None:
            result["tokens_count"] = tokens_count
        return result

    # ------------------------------------------------------------------ reindex
    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        """
        Build a Pando index for this TEITOK project.

        Expected wiring (to be implemented in flexencoder / Pando in parallel):
        - flexencoder walks TEITOK XML and writes Pando events as JSONL under tmp/,
          OR streams them to pando-index via stdin.
        - Here we call `pando-index` on that JSONL to build the index in root/pando.

        For now we assume a file tmp/pando-events.jsonl; if it is missing we fail
        with a clear message so callers see that Pando reindex is not wired yet.
        """
        project = dict(req.get("project") or {})
        root = get_project_root(project)
        params = dict(req.get("params") or {})

        output_dir = self._index_dir(project)
        output_dir.mkdir(parents=True, exist_ok=True)

        # Location for the JSONL events; can be overridden via params, otherwise
        # we assume flexencoder wrote root/tmp/pando-events.jsonl.
        override_path = params.get("pando_events_path")
        if override_path:
            events_path = Path(str(override_path)).expanduser()
        else:
            events_path = root / "tmp" / "pando-events.jsonl"
        if not events_path.is_file():
            raise RuntimeError(
                f"Pando reindex expects JSONL events at {events_path}, "
                "but flexencoder has not written them yet. "
                "Once PANDO-INDEX-INTEGRATION is implemented, flexencoder should "
                "either stream events to pando-index or write them here."
            )

        # CLI: pando-index [options] <input> <output_dir> (JSONL file or '-' for stdin with --format jsonl)
        cmd = [
            "pando-index",
            "--format",
            "jsonl",
            str(events_path),
            str(output_dir),
        ]
        try:
            completed = subprocess.run(
                cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                check=True,
            )
        except subprocess.CalledProcessError as exc:
            raise RuntimeError(
                f"pando-index failed (exit {exc.returncode}): {exc.stderr.strip()}"
            )

        return {
            "backend": self.name,
            "output_dir": str(output_dir),
            "stdout": completed.stdout,
        }

    # ------------------------------------------------------------------ list_docs
    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        """
        Enumerate documents from flexencoder ``xidx/docs.tbl`` (same TEITOK document list
        Pando uses with ``xidx/`` for XML fragments). Requires a flexencoder-built ``xidx/``
        next to the ``pando/`` index directory.
        """
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        root = get_project_root(project)
        raw_limit = int(params.get("limit", 50))
        offset = max(0, int(params.get("offset", 0)))
        # limit <= 0 means "no cap" for this page (teitokxml sync uses large batches).
        limit = raw_limit if raw_limit > 0 else 10**9
        filter_text = str(params.get("filter") or "").strip().lower()

        index_dir = self._index_dir(project)
        if not index_dir.is_dir():
            raise RuntimeError(f"Pando index directory not found: {index_dir}")

        rel_paths = read_xidx_docs_lines(root)
        if not rel_paths:
            docs_tbl = root / "xidx" / "docs.tbl"
            return {
                "docs": [],
                "total": 0,
                "warnings": [
                    f"Pando list_docs: no document list at {docs_tbl}. "
                    "Run flexencoder so xidx/docs.tbl exists beside the Pando index.",
                ],
            }

        docs: List[Dict[str, Any]] = []
        for rel in rel_paths:
            doc_id = xidx_rel_to_doc_id(rel)
            title = doc_id
            row = {
                "id": doc_id,
                "title": title,
                "meta": {"relative_path": rel},
            }
            if filter_text:
                hay = f"{doc_id} {rel} {title}".lower()
                if filter_text not in hay:
                    continue
            docs.append(row)

        total = len(docs)
        sliced = docs[offset : offset + limit]
        return {"docs": sliced, "total": total}

    # ------------------------------------------------------------------ query
    def query(self, req: FlexiRequest) -> Dict[str, Any]:
        """
        Run a Pando ClickCQL query using the CLI.

        Once Python bindings are available we can add an in-process mode and fall
        back to this CLI path when bindings are missing.
        """
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})

        query_text = str(
            params.get("query") or params.get("pattern") or params.get("cql") or ""
        ).strip()
        if not query_text:
            raise RuntimeError(
                "Pando query requires a non-empty ClickCQL string in params['query'] "
                "(or 'pattern' / 'cql')."
            )

        start = max(0, int(params.get("start", 0)))
        limit = int(params.get("max", params.get("limit", 50)))

        index_dir = self._index_dir(project)
        if not index_dir.is_dir():
            raise RuntimeError(f"Pando index directory not found: {index_dir}")

        cmd = [
            self._resolve_pando_query_binary(),
            "--index-dir",
            str(index_dir),
            "-q",
            query_text,
            "--limit",
            str(limit),
            "--offset",
            str(start),
            "--max-total",
            "10000",
            "--context",
            str(int(params.get("window", 5))),
        ]
        completed = subprocess.run(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        if completed.returncode != 0:
            raise RuntimeError(
                f"Pando CLI failed (exit {completed.returncode}): {completed.stderr.strip()}"
            )

        try:
            payload = json.loads(completed.stdout)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"pando CLI did not return valid JSON: {exc}") from exc

        # For now we return the raw Pando JSON under 'raw'; when the concrete
        # result schema is final we can adapt it to the standard flexicorp hit
        # structure (total/start/returned/hits with doc_id/match_start/match_end/context).
        return {"raw": payload}


register_backend(PandoBackend())

