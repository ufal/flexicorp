from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict

from ..config import get_project_root
from ..core import CorpusBackend, FlexiRequest, register_backend


@dataclass
class PandoBackend(CorpusBackend):
    """
    Pando (tree-aware) backend.

    Initial implementation is CLI-based:
    - reindex: expect JSONL events written by flexencoder and call `pando-index`.
    - query: call `pando ... --json` and adapt the result.

    Later we can add an in-process bindings mode (import pando.IndexBuilder / Corpus)
    and fall back to the CLI when bindings are not available.
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
            "status": False,
            "list_docs": False,
            "kwic": False,
            "freq": False,
            "stats_freq_pattributes": False,
            "stats_freq_sattributes": False,
            "stats_relative_freq": False,
            "stats_collocations": False,
            "stats_dep_collocations": False,
            "stats_keyness": False,
            "stats_table_result": False,
            "info": False,
            "daemon": False,
            "reindex": True,
            "raw_query": False,
            "query": True,
        }

    def _index_dir(self, project: Dict[str, Any]) -> Path:
        root = get_project_root(project)
        return root / "pando"

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
            "pando",
            str(index_dir),
            query_text,
            "--json",
            "--limit",
            str(limit),
            "--offset",
            str(start),
            "--total",
            "--max-total",
            "10000",
        ]
        completed = subprocess.run(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        if completed.returncode != 0:
            raise RuntimeError(
                f"pando CLI failed (exit {completed.returncode}): {completed.stderr.strip()}"
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

