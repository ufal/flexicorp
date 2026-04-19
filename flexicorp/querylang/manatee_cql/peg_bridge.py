from __future__ import annotations

import json
import shutil
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict

# JSON-style unicode escapes we forgive in incoming query strings. Same
# rationale as `flexicorp.querylang.cwb_cql.parser._JSON_QUOTE_ESCAPES`: some
# TEITOK UI paths ship the query as JSON but forget to decode it, so the
# bridge sees `[form=\u0022bez\u0022]` instead of `[form="bez"]`. Decoding
# only quote characters is strictly safe because `\u0022` / `\u0027` are never
# valid tokens in Manatee CQL.
_JSON_QUOTE_ESCAPES = {
    r"\u0022": '"',
    r"\u0027": "'",
    r"\U00000022": '"',
    r"\U00000027": "'",
}


def _normalize_json_unicode_quotes(source: str) -> str:
    if not source or ("\\u" not in source and "\\U" not in source):
        return source
    out = source
    for needle, replacement in _JSON_QUOTE_ESCAPES.items():
        if needle in out:
            out = out.replace(needle, replacement)
    return out


@dataclass
class ManateeCqlPegError(RuntimeError):
    message: str
    error_type: str = "runtime"
    error_line: int | None = None
    error_column: int | None = None
    error_offset: int | None = None
    error_end_line: int | None = None
    error_end_column: int | None = None
    error_end_offset: int | None = None

    def __str__(self) -> str:
        return self.message


def _bridge_script_path() -> Path:
    return Path(__file__).with_name("node_bridge.mjs")


def _run_bridge(query: str, *, start_rule: str = "Query") -> Dict[str, Any]:
    node_bin = shutil.which("node") or shutil.which("nodejs")
    if not node_bin:
        raise ManateeCqlPegError(
            "Manatee CQL PEG bridge requires a local Node.js binary ('node' or 'nodejs').",
            error_type="unavailable",
        )
    # Tolerate JSON-encoded quote characters before handing the query to the
    # Node PEG parser (which does not unescape source-level \uXXXX on its own).
    query = _normalize_json_unicode_quotes(query)
    proc = subprocess.run(
        [node_bin, str(_bridge_script_path())],
        input=json.dumps({"query": query, "start_rule": start_rule}),
        capture_output=True,
        text=True,
    )
    stdout = (proc.stdout or "").strip()
    stderr = (proc.stderr or "").strip()
    try:
        data = json.loads(stdout) if stdout else {}
    except Exception as exc:
        raise ManateeCqlPegError(
            f"Manatee CQL PEG bridge returned invalid JSON: {stderr or stdout or exc}",
            error_type="runtime",
        ) from exc
    if proc.returncode != 0 or not data.get("ok"):
        raise ManateeCqlPegError(
            str(data.get("error") or stderr or "Manatee CQL PEG bridge failed."),
            error_type=str(data.get("error_type") or "runtime"),
            error_line=data.get("error_line"),
            error_column=data.get("error_column"),
            error_offset=data.get("error_offset"),
            error_end_line=data.get("error_end_line"),
            error_end_column=data.get("error_end_column"),
            error_end_offset=data.get("error_end_offset"),
        )
    return data


def parse_manatee_cql(query: str, *, start_rule: str = "Query") -> Dict[str, Any]:
    return _run_bridge(query, start_rule=start_rule)
