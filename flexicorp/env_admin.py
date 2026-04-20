from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, List

from .env_adapters import EnvContext, build_adapter_registry
from .env_config import load_env_config


SUPPORTED_ENV_BACKENDS = (
    "teitok",
    "fcs",
    "pando",
    "cqp",
    "manatee",
    "blacklab",
    "kontext",
    "clickhouse",
    "teitokxml",
    "pmltq",
)
ADAPTER_REGISTRY = build_adapter_registry()


def _coerce_backend_list(raw: str | None) -> List[str]:
    if not raw:
        return list(SUPPORTED_ENV_BACKENDS)
    out: List[str] = []
    for part in str(raw).split(","):
        key = part.strip().lower()
        if not key:
            continue
        if key in SUPPORTED_ENV_BACKENDS and key not in out:
            out.append(key)
    return out if out else list(SUPPORTED_ENV_BACKENDS)


def run_env_status(
    project: Dict[str, Any],
    *,
    backend_list: str | None = None,
    env_config_path: str | None = None,
    corpus_id: str | None = None,
) -> Dict[str, Any]:
    requested = _coerce_backend_list(backend_list)
    backends: Dict[str, Any] = {}
    env_cfg, env_cfg_source = load_env_config(env_config_path)
    ctx = EnvContext(
        project_root=Path(str(project.get("root") or ".")).resolve(),
        corpus_id=(str(corpus_id).strip() if corpus_id else None),
        env_config=env_cfg,
    )
    for key in requested:
        adapter = ADAPTER_REGISTRY.get(key)
        if adapter is None:
            backends[key] = {"available": False, "reason": f"Unknown env adapter: {key}", "checks": {}}
            continue
        backends[key] = adapter.check(ctx)
        if isinstance(backends[key], dict):
            backends[key]["kind"] = getattr(adapter, "kind", "backend")
            backends[key]["description"] = getattr(adapter, "description", "")
            reported_deps = backends[key].get("depends_on_backends")
            if isinstance(reported_deps, list):
                backends[key]["depends_on_backends"] = reported_deps
            else:
                backends[key]["depends_on_backends"] = list(getattr(adapter, "depends_on_backends", []) or [])
    failed = [k for k, v in backends.items() if not bool((v or {}).get("available"))]
    return {
        "ok": len(failed) == 0,
        "topic": "env",
        "action": "status",
        "contract_version": 1,
        "project_root": str(ctx.project_root),
        "env_config": {
            "path": env_cfg_source,
            "loaded": bool(env_cfg_source),
        },
        "requested_backends": requested,
        "failed_backends": failed,
        "backends": backends,
    }


def run_env_list() -> Dict[str, Any]:
    return {
        "ok": True,
        "topic": "env",
        "action": "list",
        "contract_version": 1,
        "supported_backends": list(SUPPORTED_ENV_BACKENDS),
        "adapters": [
            {
                "name": name,
                "description": getattr(adapter, "description", ""),
                "kind": getattr(adapter, "kind", "backend"),
                "depends_on_backends": list(getattr(adapter, "depends_on_backends", []) or []),
                "supports": ["status", "check-corpus"],
            }
            for name, adapter in ADAPTER_REGISTRY.items()
        ],
    }


def run_env_check_corpus(
    project: Dict[str, Any],
    *,
    backend_list: str | None = None,
    corpus_id: str | None = None,
    env_config_path: str | None = None,
) -> Dict[str, Any]:
    status = run_env_status(
        project,
        backend_list=backend_list,
        env_config_path=env_config_path,
        corpus_id=corpus_id,
    )
    status["action"] = "check-corpus"
    status["corpus_id"] = corpus_id or ""
    return status

