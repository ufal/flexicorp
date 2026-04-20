"""
Load flexencoder JSONL output into ClickHouse.

After flexencoder runs with --output-clickhouse, the JSONL files (docs.jsonl,
sentences.jsonl, regions.jsonl, toks.jsonl, dep_edges.jsonl) must be loaded
into ClickHouse. This module creates the database/tables and performs the load.

Schema matches cwb2sql.create_core_tables_dynamic plus flexencoder extensions
(is_empty, inner_text in toks).
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Dict

from .config import ClickHouseConfig, get_clickhouse_config

MIN_CLICKHOUSE_VERSION = (22, 3, 0)

def _get_clickhouse_client(cfg: ClickHouseConfig):
    import clickhouse_connect
    return clickhouse_connect.get_client(
        host=cfg.host,
        port=cfg.port,
        username=cfg.username,
        password=cfg.password,
        database="default",
    )


def _parse_clickhouse_version_tuple(raw: Any) -> tuple[int, int, int]:
    text = str(raw or "").strip()
    parts = text.split(".")
    if len(parts) < 3:
        return (0, 0, 0)
    try:
        return (int(parts[0]), int(parts[1]), int(parts[2].split("-", 1)[0]))
    except Exception:
        return (0, 0, 0)


def _assert_supported_clickhouse_version(client: Any) -> None:
    raw_version = client.command("SELECT version()")
    server_version = _parse_clickhouse_version_tuple(raw_version)
    if server_version >= MIN_CLICKHOUSE_VERSION:
        return
    min_text = ".".join(str(x) for x in MIN_CLICKHOUSE_VERSION)
    raise RuntimeError(
        "ClickHouse server version is too old for flexicorp ClickHouse indexing. "
        f"Detected {raw_version}, required >= {min_text}. Please upgrade ClickHouse."
    )


def _supports_map_type(client: Any) -> bool:
    """
    Return True when this ClickHouse server supports Map(String, String).

    Older ClickHouse releases (common in legacy single-container TEITOK demos)
    reject Map with:
      "Unknown data type family: Map"
    """
    try:
        client.query("SELECT CAST(map('k', 'v') AS Map(String, String))")
        return True
    except Exception:
        return False


def _create_tables(
    client: Any,
    database: str,
    feats_present: bool = True,
    map_supported: bool = True,
) -> None:
    """Create core tables (docs, sentences, regions, toks, dep_edges)."""
    map_or_string = "Map(String, String)" if map_supported else "String"
    toks_feats_col = f", `feats` {map_or_string}" if feats_present else ""
    client.command(f"CREATE DATABASE IF NOT EXISTS `{database}`")
    client.command(f"""
        CREATE TABLE IF NOT EXISTS `{database}`.`docs` (
            `doc_id` UInt64,
            `text_id` String,
            `metadata` {map_or_string}
        ) ENGINE = MergeTree() ORDER BY (doc_id)
    """)
    client.command(f"""
        CREATE TABLE IF NOT EXISTS `{database}`.`sentences` (
            `sentence_id` UInt64,
            `doc_id` UInt64,
            `sent_id` String,
            `sent_pos` UInt64,
            `xml_start` Nullable(UInt64),
            `xml_end` Nullable(UInt64),
            `fulltext` String,
            `metadata` {map_or_string}
        ) ENGINE = MergeTree() ORDER BY (doc_id, sentence_id)
    """)
    client.command(f"""
        CREATE TABLE IF NOT EXISTS `{database}`.`regions` (
            `seq_id` UInt64,
            `region_id` UInt64,
            `start_pos` UInt64,
            `end_pos` UInt64,
            `region_type` String,
            `props` Array(String),
            `xml_start` Nullable(UInt64),
            `xml_end` Nullable(UInt64),
            `metadata` {map_or_string}
        ) ENGINE = MergeTree() ORDER BY (seq_id, region_id)
    """)
    client.command(f"""
        CREATE TABLE IF NOT EXISTS `{database}`.`toks` (
            `seq_id` UInt64,
            `doc_id` UInt64,
            `sentence_id` UInt64,
            `doc_pos` UInt64,
            `tok_pos` UInt64,
            `sent_ord` UInt64,
            `tok_id` String,
            `form` String,
            `lemma` String,
            `upos` String,
            `dep_rel` String,
            `head_tok_pos` Nullable(UInt64){toks_feats_col},
            `region_ids` Array(UInt64),
            `metadata` {map_or_string},
            `xml_start` Nullable(UInt64),
            `xml_end` Nullable(UInt64),
            `is_empty` Nullable(UInt8),
            `inner_text` String
        ) ENGINE = MergeTree() ORDER BY (doc_id, doc_pos)
    """)
    client.command(f"""
        CREATE TABLE IF NOT EXISTS `{database}`.`dep_edges` (
            `seq_id` UInt64,
            `tok_id` String,
            `tok_pos` UInt64,
            `head_tok_id` String,
            `head_tok_pos` Nullable(UInt64),
            `dep_rel` String
        ) ENGINE = MergeTree() ORDER BY (seq_id, tok_pos)
    """)


def _recreate_tables(client: Any, database: str, feats_present: bool, map_supported: bool) -> None:
    """Drop and recreate tables for clean reindex (handles schema changes)."""
    for table in ("dep_edges", "toks", "regions", "sentences", "docs"):
        try:
            client.command(f"DROP TABLE IF EXISTS `{database}`.`{table}`")
        except Exception:
            pass
    _create_tables(client, database, feats_present=feats_present, map_supported=map_supported)


def _normalize_jsonl_for_legacy_clickhouse(path: Path, table: str) -> bytes:
    """
    Convert map-like dict fields to JSON strings for old ClickHouse versions.

    Fields normalized:
      - metadata (docs/sentences/regions/toks)
      - feats (toks)
    """
    out_lines = []
    with path.open("r", encoding="utf-8") as f:
        for raw in f:
            line = raw.strip()
            if not line:
                continue
            row = json.loads(line)
            if isinstance(row, dict):
                if isinstance(row.get("metadata"), dict):
                    row["metadata"] = json.dumps(row["metadata"], ensure_ascii=False)
                if table == "toks" and isinstance(row.get("feats"), dict):
                    row["feats"] = json.dumps(row["feats"], ensure_ascii=False)
            out_lines.append(json.dumps(row, ensure_ascii=False))
    if not out_lines:
        return b""
    return ("\n".join(out_lines) + "\n").encode("utf-8")


def _detect_feats_in_toks(path: Path) -> bool:
    """Peek at first line of toks.jsonl to see if feats is present."""
    if not path.is_file():
        return True
    try:
        with path.open("r", encoding="utf-8") as f:
            line = f.readline()
        if line:
            row = json.loads(line)
            return "feats" in row
    except Exception:
        pass
    return True


def load_jsonl_into_clickhouse(
    output_dir: Path,
    project: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Load flexencoder JSONL files from output_dir into ClickHouse.

    Uses project config for connection (host, port, user, password, database).
    Creates database and tables if needed, truncates, then loads each file.

    Returns dict with loaded table counts and any errors.
    """
    cfg = get_clickhouse_config(project)
    if cfg is None:
        return {"ok": False, "error": "No ClickHouse configuration available."}
    try:
        client = _get_clickhouse_client(cfg)
        _assert_supported_clickhouse_version(client)
    except Exception as e:
        return {"ok": False, "error": f"ClickHouse connection failed: {e}"}
    database = cfg.database
    toks_path = output_dir / "toks.jsonl"
    feats_present = _detect_feats_in_toks(toks_path)
    map_supported = _supports_map_type(client)
    try:
        _recreate_tables(
            client,
            database,
            feats_present=feats_present,
            map_supported=map_supported,
        )
    except Exception as e:
        return {"ok": False, "error": f"Failed to create tables: {e}"}
    tables = [
        ("docs", "docs.jsonl"),
        ("sentences", "sentences.jsonl"),
        ("regions", "regions.jsonl"),
        ("toks", "toks.jsonl"),
        ("dep_edges", "dep_edges.jsonl"),
    ]
    loaded: Dict[str, int] = {}
    for table, filename in tables:
        path = output_dir / filename
        if not path.is_file():
            continue
        try:
            if map_supported:
                data = path.read_bytes()
            else:
                data = _normalize_jsonl_for_legacy_clickhouse(path, table)
            if data.strip():
                client.raw_insert(
                    table=f"`{database}`.`{table}`",
                    insert_block=data,
                    fmt="JSONEachRow",
                )
            loaded[table] = sum(1 for _ in path.open("rb") if _.strip())
        except Exception as e:
            return {"ok": False, "error": f"Failed to load {filename}: {e}", "loaded": loaded}
    return {"ok": True, "database": database, "loaded": loaded}
