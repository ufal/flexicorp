from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional

from ..config import get_clickcql_assets_dir, get_clickcql_node_binary


@dataclass
class ClickCqlPegError(RuntimeError):
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


def _run_bridge(action: str, query: str, *, project: Optional[Dict[str, Any]] = None, limit: Optional[int] = None, offset: Optional[int] = None, debug: bool = False) -> Dict[str, Any]:
    project = dict(project or {})
    node_bin = get_clickcql_node_binary(project)
    if not node_bin:
        raise ClickCqlPegError(
            "ClickCQL PEG bridge requires a local Node.js binary ('node' or 'nodejs').",
            error_type="unavailable",
            fallback_allowed=True,
        )
    assets_dir = get_clickcql_assets_dir(project)
    if assets_dir is None:
        raise ClickCqlPegError(
            "ClickCQL PEG assets were not found. Set clickql.peg_assets_dir or keep a sibling 'clickcql/web' checkout available.",
            error_type="unavailable",
            fallback_allowed=True,
        )

    payload = {
        "action": action,
        "query": query,
        "assets_dir": str(assets_dir),
        "limit": limit,
        "offset": offset,
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
    data: Dict[str, Any]
    try:
        if stdout:
            lines = [line.strip() for line in stdout.splitlines() if line.strip()]
            payload_text = lines[-1] if lines else ""
            data = json.loads(payload_text) if payload_text else {}
        else:
            data = {}
    except Exception as exc:
        raise ClickCqlPegError(
            f"ClickCQL PEG bridge returned invalid JSON: {stderr or stdout or exc}",
            error_type="runtime",
            fallback_allowed=True,
        ) from exc
    if proc.returncode != 0 or not data.get("ok"):
        error_type = str(data.get("error_type") or "runtime")
        fallback_allowed = error_type == "unavailable"
        raise ClickCqlPegError(
            str(data.get("error") or stderr or "ClickCQL PEG bridge failed."),
            error_type=error_type,
            fallback_allowed=fallback_allowed,
                    error_line=data.get("error_line"),
                    error_column=data.get("error_column"),
                    error_offset=data.get("error_offset"),
                    error_end_line=data.get("error_end_line"),
                    error_end_column=data.get("error_end_column"),
                    error_end_offset=data.get("error_end_offset"),
        )
    return data


def parse_clickcql(query: str, *, project: Optional[Dict[str, Any]] = None, debug: bool = False) -> Dict[str, Any]:
    return _run_bridge("parse", query, project=project, debug=debug)


def translate_clickcql(query: str, *, project: Optional[Dict[str, Any]] = None, limit: Optional[int] = None, offset: Optional[int] = None, debug: bool = False) -> Dict[str, Any]:
    return _run_bridge("translate", query, project=project, limit=limit, offset=offset, debug=debug)
