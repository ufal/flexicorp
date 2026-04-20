from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Dict, Optional, Tuple


def default_env_config_candidates() -> list[Path]:
    candidates: list[Path] = []
    env_path = os.environ.get("FLEXICORP_ENV_CONFIG", "").strip()
    if env_path:
        candidates.append(Path(env_path).expanduser())
    candidates.extend(
        [
            Path("/etc/flexicorp/env-config.json"),
            Path.home() / ".config" / "flexicorp" / "env-config.json",
            Path.home() / ".flexicorp" / "env-config.json",
            # Last-resort bootstrap (e.g. web server user with no writable home); keep in sync with teitok/fqs-backends.php
            Path("/tmp/flexicorp/env-config.json"),
        ]
    )
    return candidates


def load_env_config(path: Optional[str] = None) -> Tuple[Dict[str, Any], str]:
    """
    Load env-admin config for backend/front-end integration checks.

    Returns (config_dict, source_path_or_empty).
    """
    candidates: list[Path] = []
    if path:
        candidates.append(Path(path).expanduser())
    else:
        candidates.extend(default_env_config_candidates())

    seen = set()
    unique_candidates: list[Path] = []
    for c in candidates:
        key = str(c)
        if key in seen:
            continue
        seen.add(key)
        unique_candidates.append(c)

    for c in unique_candidates:
        if not c.is_file():
            continue
        try:
            raw = json.loads(c.read_text(encoding="utf-8"))
            if isinstance(raw, dict):
                return raw, str(c)
        except Exception:
            continue
    return {}, ""


# Default host-published PMLTQ HTTP API (see docs/pmltq-server-compose-runbook.md).
DEFAULT_PMLTQ_NATIVE_URL = "http://127.0.0.1:19100"


def resolve_pmltq_native_server_url(
    project: Dict[str, Any] | None = None,
    *,
    env_config: Dict[str, Any] | None = None,
) -> tuple[str, str]:
    """
    Base URL for the native PMLTQ HTTP server (e.g. ``GET /v1/treebanks``).

    Resolution order:
    1. ``project["pmltq_server"]`` ``url`` / ``base_url``
    2. ``project["pmltq"]["api"]`` or ``project["pmltq"]["server"]`` url fields
    3. ``env_config["pmltq"]["server"]`` / ``["api"]`` url (from ``--env-config`` / default paths)
    4. ``PMLTQ_URL`` environment variable
    5. :data:`DEFAULT_PMLTQ_NATIVE_URL`

    Returns ``(url, source)`` where ``source`` is ``project``, ``env_config.server``,
    ``env_config.api``, ``PMLTQ_URL``, or ``default``.
    """
    project = dict(project or {})

    merged: Dict[str, Any] = {}
    merged.update(dict(project.get("pmltq_server") or {}))
    pml_root = project.get("pmltq")
    if isinstance(pml_root, dict):
        for block in ("api", "server"):
            sub = pml_root.get(block)
            if isinstance(sub, dict):
                merged.update(sub)

    for key in ("url", "base_url"):
        v = str(merged.get(key) or "").strip().rstrip("/")
        if v:
            return v, "project"

    cfg_in: Dict[str, Any] = {}
    if env_config is not None:
        cfg_in = dict(env_config or {})
    elif project.get("env_config") and isinstance(project.get("env_config"), dict):
        cfg_in = dict(project["env_config"])
    else:
        loaded, _src = load_env_config()
        cfg_in = loaded

    pml_cfg = dict((cfg_in or {}).get("pmltq") or {})
    for block_name in ("server", "api", "http"):
        block = pml_cfg.get(block_name)
        if not isinstance(block, dict):
            continue
        for key in ("url", "base_url"):
            v = str(block.get(key) or "").strip().rstrip("/")
            if v:
                return v, f"env_config.pmltq.{block_name}"

    env_url = os.environ.get("PMLTQ_URL", "").strip().rstrip("/")
    if env_url:
        return env_url, "PMLTQ_URL"

    return DEFAULT_PMLTQ_NATIVE_URL, "default"

