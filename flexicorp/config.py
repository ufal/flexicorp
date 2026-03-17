from __future__ import annotations

from dataclasses import dataclass
import shutil
from pathlib import Path
from typing import Any, Dict, Optional
from urllib.parse import urlparse
from .teitok import detect_teitok_blacklab, detect_teitok_clickhouse

try:
    import yaml  # type: ignore[import]
except Exception:  # pragma: no cover - optional dependency
    yaml = None  # type: ignore[assignment]

JsonDict = Dict[str, Any]


def _merge_clickhouse_section(target: Dict[str, Any], source: Any) -> None:
    if not isinstance(source, dict):
        return
    for key, value in source.items():
        if key in {"tables", "columns"} and isinstance(value, dict):
            merged_nested = dict(target.get(key) or {})
            merged_nested.update(value)
            target[key] = merged_nested
        else:
            target[key] = value


@dataclass
class ClickHouseConfig:
    host: str
    port: int
    database: str
    username: str
    password: str
    dsn: Optional[str]
    tokens_table: Optional[str]
    docs_table: Optional[str]
    columns: Dict[str, Any]
    # Optional for query + fragment extraction (cwb2sql schema)
    sentences_table: Optional[str] = None


@dataclass
class CqpConfig:
    """
    Minimal configuration for the CQP backend.

    `registry` may be None to indicate that the backend should rely on CQP's
    default registry directory (centralized setup).
    """

    registry: str | None
    corpus: str
    cqp_binary: str
    encoding: str | None = None
    original_registry: str | None = None
    registry_patched: bool = False


@dataclass
class ManateeConfig:
    registry: str
    corpus: str


@dataclass
class BlackLabConfig:
    url: str
    corpus: str
    username: Optional[str] = None
    password: Optional[str] = None
    default_field: Optional[str] = None
    pattlang: str = "bcql"
    filterlang: str = "luceneql"


def _load_project_sidecar_config(project_root: Path) -> JsonDict:
    """
    Optionally load flexicorp.{yaml,json} from the project root.

    For initial implementation this is *best effort*: absence of a config file
    is not an error as long as required settings are present in the request.
    """
    candidates = [
        project_root / "flexicorp.yaml",
        project_root / "flexicorp.yml",
        project_root / "flexicorp.json",
    ]
    for path in candidates:
        if not path.is_file():
            continue
        try:
            if path.suffix in {".yaml", ".yml"} and yaml is not None:
                with path.open("r", encoding="utf-8") as f:
                    return yaml.safe_load(f) or {}
            if path.suffix == ".json":
                import json

                with path.open("r", encoding="utf-8") as f:
                    return json.load(f) or {}
        except Exception:
            # Treat config loading as non-fatal; errors will surface as missing keys later.
            return {}
    return {}


def get_project_root(project: JsonDict) -> Path:
    root = project.get("root") or "."
    return Path(root).expanduser().resolve()


def get_clickcql_assets_dir(project: JsonDict) -> Optional[Path]:
    """
    Locate a local ClickCQL PEG asset directory.

    Expected contents:
    - Scripts/cql-parser-umd.js
    - Scripts/cql2sql-peg-optimized.js

    Search order:
    1. Explicit project/sidecar config in clickql/clickcql sections
    2. A sibling clickcql checkout next to the current project root
    3. A sibling clickcql checkout next to the flexicorp repository
    """
    root = get_project_root(project)
    sidecar = _load_project_sidecar_config(root)
    candidates: list[Path] = []

    def add_candidate(value: Any) -> None:
        if not value:
            return
        try:
            candidates.append(Path(str(value)).expanduser().resolve())
        except Exception:
            return

    for cfg in (
        sidecar.get("clickql"),
        sidecar.get("clickcql"),
        project.get("clickql"),
        project.get("clickcql"),
    ):
        if not isinstance(cfg, dict):
            continue
        for key in ("peg_assets_dir", "assets_dir", "clickcql_assets_dir", "clickcql_web_root"):
            add_candidate(cfg.get(key))

    repo_root = Path(__file__).resolve().parents[1]
    candidates.extend(
        [
            (root / "clickcql" / "web").resolve(),
            (root.parent / "clickcql" / "web").resolve(),
            (repo_root.parent / "clickcql" / "web").resolve(),
        ]
    )

    seen: set[str] = set()
    for candidate in candidates:
        marker = str(candidate)
        if marker in seen:
            continue
        seen.add(marker)
        parser_js = candidate / "Scripts" / "cql-parser-umd.js"
        translator_js = candidate / "Scripts" / "cql2sql-peg-optimized.js"
        if parser_js.is_file() and translator_js.is_file():
            return candidate
    return None


def get_clickcql_node_binary(project: JsonDict) -> Optional[str]:
    """
    Resolve the local Node.js binary used for ClickCQL PEG execution.
    """
    root = get_project_root(project)
    sidecar = _load_project_sidecar_config(root)
    explicit = None
    for cfg in (
        sidecar.get("clickql"),
        sidecar.get("clickcql"),
        project.get("clickql"),
        project.get("clickcql"),
    ):
        if not isinstance(cfg, dict):
            continue
        explicit = cfg.get("node_binary") or cfg.get("clickcql_node_binary")
        if explicit:
            break
    if explicit:
        return str(explicit)
    return shutil.which("node") or shutil.which("nodejs")


def _parse_clickhouse_dsn(dsn: str) -> Dict[str, Any]:
    parsed = urlparse(dsn)
    out: Dict[str, Any] = {}
    if parsed.hostname:
        out["host"] = parsed.hostname
    if parsed.port:
        out["port"] = int(parsed.port)
    if parsed.username:
        out["username"] = parsed.username
    if parsed.password:
        out["password"] = parsed.password
    db = (parsed.path or "").lstrip("/")
    if db:
        out["database"] = db
    return out


def _merge_blacklab_section(target: Dict[str, Any], source: Any) -> None:
    if not isinstance(source, dict):
        return
    for key, value in source.items():
        target[key] = value


def get_clickhouse_config(project: JsonDict) -> Optional[ClickHouseConfig]:
    """
    Merge ClickHouse-related configuration from the request and optional
    sidecar config files.
    """
    root = get_project_root(project)
    detected = detect_teitok_clickhouse(root) or {}
    sidecar = _load_project_sidecar_config(root)
    merged_clickhouse: Dict[str, Any] = {}
    _merge_clickhouse_section(merged_clickhouse, detected.get("clickhouse", {}))
    _merge_clickhouse_section(merged_clickhouse, sidecar.get("clickhouse", {}))
    _merge_clickhouse_section(merged_clickhouse, sidecar.get("clickql", {}))  # clickql uses same schema as clickhouse
    _merge_clickhouse_section(merged_clickhouse, project.get("clickhouse", {}))
    _merge_clickhouse_section(merged_clickhouse, project.get("clickql", {}))

    dsn = merged_clickhouse.get("dsn")
    dsn_parts = _parse_clickhouse_dsn(str(dsn)) if dsn else {}
    tables = merged_clickhouse.get("tables", {})
    columns = merged_clickhouse.get("columns", {})
    tokens_table = tables.get("tokens") or "toks"
    docs_table = tables.get("docs") or "docs"
    sentences_table = tables.get("sentences") or "sentences"
    host = str(merged_clickhouse.get("host") or dsn_parts.get("host") or "127.0.0.1")
    port = int(merged_clickhouse.get("port") or dsn_parts.get("port") or 8123)
    database = str(merged_clickhouse.get("database") or dsn_parts.get("database") or "default")
    username = str(
        merged_clickhouse.get("username")
        or merged_clickhouse.get("user")
        or dsn_parts.get("username")
        or "default"
    )
    password = str(merged_clickhouse.get("password") or dsn_parts.get("password") or "")

    return ClickHouseConfig(
        host=host,
        port=port,
        database=database,
        username=username,
        password=password,
        dsn=str(dsn) if dsn else None,
        tokens_table=str(tokens_table),
        docs_table=str(docs_table),
        columns=dict(columns),
        sentences_table=str(sentences_table),
    )


def get_cqp_config(project: JsonDict) -> Optional[CqpConfig]:
    """
    Extract minimal CQP configuration from the project section.
    """
    cqp = dict(project.get("cqp") or {})
    corpus = cqp.get("corpus")
    registry = cqp.get("registry")
    cqp_binary = cqp.get("cqp_binary") or "cqp"
    encoding = cqp.get("encoding")

    if not corpus:
        return None

    return CqpConfig(
        corpus=str(corpus),
        registry=str(registry) if registry is not None else None,
        cqp_binary=str(cqp_binary),
        encoding=str(encoding) if encoding else None,
        original_registry=str(registry) if registry is not None else None,
    )


def get_manatee_config(project: JsonDict) -> Optional[ManateeConfig]:
    """
    Extract minimal Manatee configuration from the project section.
    """
    manatee = dict(project.get("manatee") or {})
    corpus = manatee.get("corpus")
    registry = manatee.get("registry")
    if not corpus or not registry:
        return None
    return ManateeConfig(
        corpus=str(corpus),
        registry=str(registry),
    )


def get_blacklab_config(project: JsonDict) -> Optional[BlackLabConfig]:
    """
    Extract minimal BlackLab configuration from request/sidecar project config.
    """
    merged = get_blacklab_settings(project)
    url = str(merged.get("url") or merged.get("server_url") or "").strip()
    corpus = str(merged.get("corpus") or merged.get("index") or "").strip()
    if not url or not corpus:
        return None

    return BlackLabConfig(
        url=url.rstrip("/"),
        corpus=corpus,
        username=str(merged.get("username") or merged.get("user") or "").strip() or None,
        password=str(merged.get("password") or "").strip() or None,
        default_field=str(merged.get("field") or merged.get("default_field") or "").strip() or None,
        pattlang=str(merged.get("pattlang") or merged.get("query_language") or "bcql").strip() or "bcql",
        filterlang=str(merged.get("filterlang") or "luceneql").strip() or "luceneql",
    )


def get_blacklab_settings(project: JsonDict) -> Dict[str, Any]:
    """
    Return merged BlackLab settings even when only the server URL is known.
    """
    root = get_project_root(project)
    detected = detect_teitok_blacklab(root) or {}
    sidecar = _load_project_sidecar_config(root)
    merged: Dict[str, Any] = {}
    _merge_blacklab_section(merged, detected.get("blacklab", {}))
    _merge_blacklab_section(merged, sidecar.get("blacklab", {}))
    _merge_blacklab_section(merged, project.get("blacklab", {}))
    return merged

