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


def _get_clickhouse_client(cfg: ClickHouseConfig):
    import clickhouse_connect
    return clickhouse_connect.get_client(
        host=cfg.host,
        port=cfg.port,
        username=cfg.username,
        password=cfg.password,
        database="default",
    )


def _create_tables(client: Any, database: str, feats_present: bool = True) -> None:
    """Create core tables (docs, sentences, regions, toks, dep_edges)."""
    toks_feats_col = ", `feats` Map(String, String)" if feats_present else ""
    client.command(f"CREATE DATABASE IF NOT EXISTS `{database}`")
    client.command(f"""
        CREATE TABLE IF NOT EXISTS `{database}`.`docs` (
            `doc_id` UInt64,
            `text_id` String,
            `metadata` Map(String, String)
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
            `metadata` Map(String, String)
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
            `metadata` Map(String, String)
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
            `metadata` Map(String, String),
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


def _recreate_tables(client: Any, database: str, feats_present: bool) -> None:
    """Drop and recreate tables for clean reindex (handles schema changes)."""
    for table in ("dep_edges", "toks", "regions", "sentences", "docs"):
        try:
            client.command(f"DROP TABLE IF EXISTS `{database}`.`{table}`")
        except Exception:
            pass
    _create_tables(client, database, feats_present=feats_present)


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
    except Exception as e:
        return {"ok": False, "error": f"ClickHouse connection failed: {e}"}
    database = cfg.database
    toks_path = output_dir / "toks.jsonl"
    feats_present = _detect_feats_in_toks(toks_path)
    try:
        _recreate_tables(client, database, feats_present=feats_present)
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
            data = path.read_bytes()
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
