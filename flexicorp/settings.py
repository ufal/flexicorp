from __future__ import annotations

"""
Simple user-level configuration for flexiCorp.

This is intentionally minimal and currently supports:
- setting and getting the default backend for the CLI,
- toggling automatic installation of optional backend dependencies.
"""

from dataclasses import dataclass, asdict
from pathlib import Path
from typing import Any, Dict
import json
import os


_CONFIG_DIR = Path.home() / ".flexicorp"
_CONFIG_FILE = _CONFIG_DIR / "config.json"


@dataclass
class FlexiConfig:
    default_backend: str = "pando"
    auto_install_optional_deps: bool = True

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "FlexiConfig":
        return cls(
            default_backend=str(data.get("default_backend", "pando")),
            auto_install_optional_deps=bool(data.get("auto_install_optional_deps", True)),
        )

    def to_dict(self) -> Dict[str, Any]:
        return asdict(self)


def _ensure_config_dir() -> None:
    _CONFIG_DIR.mkdir(parents=True, exist_ok=True)


def read_config() -> FlexiConfig:
    if not _CONFIG_FILE.is_file():
        return FlexiConfig()
    try:
        raw = json.loads(_CONFIG_FILE.read_text(encoding="utf-8"))
        if not isinstance(raw, dict):
            return FlexiConfig()
        return FlexiConfig.from_dict(raw)
    except Exception:
        return FlexiConfig()


def write_config(cfg: FlexiConfig) -> None:
    _ensure_config_dir()
    _CONFIG_FILE.write_text(json.dumps(cfg.to_dict(), indent=2), encoding="utf-8")


def get_default_backend() -> str:
    cfg = read_config()
    backend = (cfg.default_backend or "pando").strip().lower()
    # ClickHouse is deprecated: a stored default of clickhouse/clickql falls back to pando
    # unless FLEXICORP_ENABLE_CLICKHOUSE is set (see core.backend_disabled_reason).
    if backend in ("clickhouse", "clickql") and os.environ.get("FLEXICORP_ENABLE_CLICKHOUSE", "").strip().lower() not in ("1", "true", "yes", "on"):
        return "pando"
    return backend


def set_default_backend(backend: str) -> None:
    cfg = read_config()
    cfg.default_backend = backend
    write_config(cfg)


def get_auto_install_optional_deps() -> bool:
    cfg = read_config()
    return bool(cfg.auto_install_optional_deps)


def set_auto_install_optional_deps(enabled: bool) -> None:
    cfg = read_config()
    cfg.auto_install_optional_deps = bool(enabled)
    write_config(cfg)


def get_config_file() -> Path:
    return _CONFIG_FILE

