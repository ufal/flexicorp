from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional

from ..config import get_clickcql_assets_dir, get_clickcql_node_binary


@dataclass
class PmltqPegError(RuntimeError):
    message: str
    error_type: str = "runtime"
    fallback_allowed: bool = False
    error_line: Optional[int] = None
    error_column: Optional[int] = None
    error_offset: Optional[int] = None
    error_end_line: Optional[int] = None
    error_end_column: Optional[int] = None
    error_end_offset: Optional[int] = None

    def __str__(self) -> str:
        return self.message


def _bridge_script_path() -> Path:
    return Path(__file__).with_name("node_bridge.mjs")


def _run_bridge(action: str, query: str, *, project: Optional[Dict[str, Any]] = None, debug: bool = False) -> Dict[str, Any]:
    project = dict(project or {})
    node_bin = get_clickcql_node_binary(project)
    if not node_bin:
        raise PmltqPegError(
            "PML-TQ PEG bridge requires a local Node.js binary ('node' or 'nodejs').",
            error_type="unavailable",
            fallback_allowed=False,
        )
    assets_dir = get_clickcql_assets_dir(project)
    if assets_dir is None:
        raise PmltqPegError(
            "PML-TQ PEG assets were not found. Set clickql.peg_assets_dir or keep a sibling 'clickcql/web' checkout available.",
            error_type="unavailable",
            fallback_allowed=False,
        )
    payload = {
        "action": action,
        "query": query,
        "assets_dir": str(assets_dir),
        "debug": bool(debug),
    }
    proc = subprocess.run(
        [node_bin, str(_bridge_script_path())],
        input=json.dumps(payload),
        capture_output=True,
        text=True,
    )
    stdout = (proc.stdout or "").strip()
    stderr = (proc.stderr or "").strip()
    try:
        lines = [line.strip() for line in stdout.splitlines() if line.strip()]
        data = json.loads(lines[-1]) if lines else {}
    except Exception as exc:
        raise PmltqPegError(
            f"PML-TQ PEG bridge returned invalid JSON: {stderr or stdout or exc}",
            error_type="runtime",
            fallback_allowed=False,
        ) from exc
    if proc.returncode != 0 or not data.get("ok"):
        raise PmltqPegError(
            str(data.get("error") or stderr or "PML-TQ PEG bridge failed."),
            error_type=str(data.get("error_type") or "runtime"),
            fallback_allowed=False,
            error_line=data.get("error_line"),
            error_column=data.get("error_column"),
            error_offset=data.get("error_offset"),
            error_end_line=data.get("error_end_line"),
            error_end_column=data.get("error_end_column"),
            error_end_offset=data.get("error_end_offset"),
        )
    return data


def parse_pmltq(query: str, *, project: Optional[Dict[str, Any]] = None, debug: bool = False) -> Dict[str, Any]:
    return _run_bridge("parse", query, project=project, debug=debug)


def translate_pmltq(query: str, *, project: Optional[Dict[str, Any]] = None, debug: bool = False) -> Dict[str, Any]:
    return _run_bridge("translate", query, project=project, debug=debug)
