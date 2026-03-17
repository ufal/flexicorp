from __future__ import annotations

import json
import re
import shutil
import socket
import subprocess
import time
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Any, Dict, List, Optional
import importlib

from ..config import ClickHouseConfig, CqpConfig, get_clickhouse_config
from ..core import CorpusBackend, FlexiRequest, register_backend
from ..dependency_utils import ensure_package_installed
from ..highlight_contract import build_highlight_map, resolve_legend
from ..teitok import detect_teitok_cqp

clickhouse_connect = None


@dataclass
class ClickHouseBackend(CorpusBackend):
    name: str = "clickhouse"

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "clickhouse",
            "supported_query_languages": ["sql"],
            "supported_corpus_formats": ["clickhouse"],
            "default_query_language": "sql",
            "default_corpus_format": "clickhouse",
            "default_selection_reason": "Direct ClickHouse access for daemon management, status, and SQL-oriented operations.",
        }

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": True,
            "freq": True,
            "info": True,
            "daemon": True,
            "reindex": True,
            "raw_query": False,
            "query": False,
        }

    # Connection helpers -------------------------------------------------
    def _get_config(self, req: FlexiRequest) -> ClickHouseConfig:
        project = dict(req.get("project") or {})
        cfg = get_clickhouse_config(project)
        if cfg is None:
            raise RuntimeError("Missing or incomplete ClickHouse configuration in request/project.")
        return cfg

    def _get_client(self, cfg: ClickHouseConfig):
        module = self._get_clickhouse_module()
        return module.get_client(
            host=cfg.host,
            port=cfg.port,
            username=cfg.username,
            password=cfg.password,
            database=cfg.database,
        )

    def _get_clickhouse_module(self):
        global clickhouse_connect
        if clickhouse_connect is not None:
            return clickhouse_connect
        try:
            clickhouse_connect = importlib.import_module("clickhouse_connect")
            return clickhouse_connect
        except Exception:
            ensure_package_installed(
                "clickhouse-connect",
                module_name="clickhouse_connect",
                friendly_name="ClickHouse backend",
            )
            clickhouse_connect = importlib.import_module("clickhouse_connect")
            return clickhouse_connect

    def _get_table_columns(self, client: Any, database: str, table: str) -> List[str]:
        rows = client.query(
            "SELECT name FROM system.columns WHERE database = %(db)s AND table = %(table)s ORDER BY position",
            parameters={"db": database, "table": table},
        )
        return [str(row[0]) for row in rows.result_rows]

    def _cfg_for_database(self, cfg: ClickHouseConfig, database: Optional[str]) -> ClickHouseConfig:
        if not database:
            return cfg
        return replace(cfg, database=str(database))

    def _normalize_context_request(self, params: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        from .cqp import CqpBackend

        return CqpBackend()._normalize_context_request(params)

    def _resolve_teitok_context_for_hit(
        self,
        *,
        project: Dict[str, Any],
        hit: Dict[str, Any],
        context_spec: Optional[Dict[str, Any]],
    ) -> Optional[Dict[str, Any]]:
        if not context_spec or not hit.get("doc_id"):
            return None

        start_path = Path(project.get("root") or ".").resolve()
        detected = detect_teitok_cqp(start_path)
        if not detected:
            return None

        root_dir = Path(detected.get("root") or start_path).resolve()
        cqp_detected = detected.get("cqp") or {}
        searchfolder = str((detected.get("meta") or {}).get("searchfolder") or "xmlfiles")
        row_context = self._resolve_teitok_context_from_row_offsets(
            root_dir=root_dir,
            searchfolder=searchfolder,
            hit=hit,
            context_spec=context_spec,
        )
        if row_context:
            return row_context

        cqp_dir = root_dir / "cqp"
        registry = str(cqp_dir) if cqp_dir.exists() else None
        helper_cfg = CqpConfig(
            registry=registry,
            corpus=str(cqp_detected.get("corpus") or ""),
            cqp_binary=str(cqp_detected.get("cqp_binary") or "cqp"),
            encoding=str(cqp_detected.get("encoding")) if cqp_detected.get("encoding") else None,
        )

        from .cqp import CqpBackend

        return CqpBackend()._resolve_teitok_context(
            cfg=helper_cfg,
            root_dir=root_dir,
            searchfolder=searchfolder,
            doc_id=str(hit.get("doc_id") or ""),
            sentence_id=str(hit.get("sentence_id")) if hit.get("sentence_id") is not None else None,
            tok_ids=[str(tok) for tok in (hit.get("toks") or [])],
            match_start=hit.get("match_start"),
            match_end=hit.get("match_end"),
            context_spec=context_spec,
        )

    def _resolve_teitok_context_from_row_offsets(
        self,
        *,
        root_dir: Path,
        searchfolder: str,
        hit: Dict[str, Any],
        context_spec: Dict[str, Any],
    ) -> Optional[Dict[str, Any]]:
        doc_id = str(hit.get("doc_id") or "").strip()
        row = dict(hit.get("row") or {})
        if not doc_id or not row:
            return None

        scope = str(context_spec.get("scope") or "s").strip().lower()
        fmt = str(context_spec.get("format") or "xml").strip().lower()
        if fmt not in {"xml", "text"}:
            fmt = "xml"

        start_offset: Optional[int] = None
        end_offset: Optional[int] = None

        if scope == "s":
            start_offset = self._safe_int(row.get("t1_sentence_xml_start"))
            if start_offset is None:
                start_offset = self._safe_int(row.get("sentence_xml_start"))
            end_offset = self._safe_int(row.get("t1_sentence_xml_end"))
            if end_offset is None:
                end_offset = self._safe_int(row.get("sentence_xml_end"))
        elif scope == "tok":
            starts: List[int] = []
            ends: List[int] = []
            for i in range(1, 11):
                start_val = self._safe_int(row.get(f"t{i}_xml_start"))
                end_val = self._safe_int(row.get(f"t{i}_xml_end"))
                if start_val is not None:
                    starts.append(start_val)
                if end_val is not None:
                    ends.append(end_val)
            if starts and ends:
                start_offset = min(starts)
                end_offset = max(ends)
        else:
            return None

        if start_offset is None or end_offset is None or end_offset < start_offset:
            return None

        xml_path = self._resolve_teitok_xml_path(
            root_dir=root_dir,
            searchfolder=searchfolder,
            doc_id=doc_id,
        )
        if xml_path is None:
            return None

        try:
            with xml_path.open("rb") as fh:
                fh.seek(start_offset)
                fragment_bytes = fh.read(end_offset - start_offset)
        except OSError:
            return None

        from .cqp import CqpBackend

        helper = CqpBackend()
        fragment = helper._decode_cqp_output(fragment_bytes).strip()
        if not fragment:
            return None

        locator: Dict[str, Any] = {
            "token_ids": [str(tok) for tok in (hit.get("toks") or [])],
            "match_start": hit.get("match_start"),
            "match_end": hit.get("match_end"),
        }
        if hit.get("sentence_id") is not None:
            locator["sentence_id"] = str(hit.get("sentence_id"))

        return {
            "scope": scope,
            "format": fmt,
            "source": "row-offsets",
            "locator": locator,
            "data": fragment if fmt == "xml" else helper._fragment_to_text(fragment),
        }

    def _resolve_teitok_xml_path(
        self,
        *,
        root_dir: Path,
        searchfolder: str,
        doc_id: str,
    ) -> Optional[Path]:
        normalized_doc_id = doc_id.strip()
        normalized_searchfolder = searchfolder.strip("/").replace("\\", "/")
        if normalized_searchfolder and normalized_doc_id.startswith(normalized_searchfolder + "/"):
            normalized_doc_id = normalized_doc_id[len(normalized_searchfolder) + 1 :]
        elif normalized_doc_id.startswith("xmlfiles/"):
            normalized_doc_id = normalized_doc_id[len("xmlfiles/") :]

        candidates = [
            root_dir / normalized_searchfolder / normalized_doc_id,
            root_dir / doc_id,
        ]
        for candidate in candidates:
            if candidate.is_file():
                return candidate
        return None

    @staticmethod
    def _safe_int(value: Any) -> Optional[int]:
        try:
            return int(value) if value is not None else None
        except (TypeError, ValueError):
            return None

    def _require_query_schema(self, cfg: ClickHouseConfig) -> None:
        if not cfg.tokens_table or not cfg.docs_table:
            raise RuntimeError(
                "ClickHouse query operations require clickhouse tables configuration "
                "(at least tables.tokens and tables.docs, optionally tables.sentences)."
            )

    def _is_local_clickhouse(self, cfg: ClickHouseConfig) -> bool:
        return cfg.host in {"127.0.0.1", "localhost", "::1"}

    def _socket_open(self, host: str, port: int, timeout: float = 1.0) -> bool:
        try:
            with socket.create_connection((host, port), timeout=timeout):
                return True
        except OSError:
            return False

    def _run_local_command(self, args: List[str]) -> Dict[str, Any]:
        proc = subprocess.run(args, capture_output=True, text=True)
        return {
            "command": args,
            "returncode": proc.returncode,
            "stdout": proc.stdout,
            "stderr": proc.stderr,
        }

    def _find_clickhouse_config_file(self) -> Optional[str]:
        candidates = [
            "/opt/homebrew/etc/clickhouse-server/config.xml",
            "/usr/local/etc/clickhouse-server/config.xml",
            "/etc/clickhouse-server/config.xml",
        ]
        for candidate in candidates:
            if Path(candidate).is_file():
                return candidate
        return None

    def _daemon_status_payload(self, cfg: ClickHouseConfig) -> Dict[str, Any]:
        tcp_open = self._socket_open(cfg.host, cfg.port)
        payload: Dict[str, Any] = {
            "running": False,
            "corpus_ready": False,
            "host": cfg.host,
            "port": cfg.port,
            "database": cfg.database,
            "tcp_open": tcp_open,
        }
        try:
            # Use default database for connectivity check; corpus DB may not exist yet
            status_cfg = self._cfg_for_database(cfg, "default")
            client = self._get_client(status_cfg)
            version = client.command("SELECT version()")
            payload["running"] = True
            payload["server_version"] = str(version or "")
            db_count = client.query("SELECT count() FROM system.databases").first_row[0]
            payload["database_count"] = int(db_count)
            # Check if corpus database and docs table exist (for use vs reindex distinction)
            if cfg.database and cfg.docs_table:
                try:
                    corpus_client = self._get_client(cfg)
                    result = corpus_client.query(
                        "SELECT 1 FROM system.tables WHERE database = %(db)s AND name = %(table)s LIMIT 1",
                        parameters={"db": cfg.database, "table": cfg.docs_table},
                    )
                    payload["corpus_ready"] = bool(result.result_rows and len(result.result_rows) > 0)
                except Exception:
                    payload["corpus_ready"] = False
        except Exception as e:
            payload["error"] = str(e)
        return payload

    def daemon(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        params = dict(req.get("params") or {})
        action = str(params.get("action") or "status").strip().lower().replace("-", "_")
        if action == "status":
            return self._daemon_status_payload(cfg)
        if action == "list_databases":
            client = self._get_client(cfg)
            rows = client.query("SELECT name FROM system.databases ORDER BY name")
            return {
                "host": cfg.host,
                "port": cfg.port,
                "database": cfg.database,
                "databases": [str(row[0]) for row in rows.result_rows],
            }
        if action == "list_tables":
            db_name = str(params.get("database") or cfg.database)
            client = self._get_client(self._cfg_for_database(cfg, db_name))
            rows = client.query(
                "SELECT name FROM system.tables WHERE database = %(db)s ORDER BY name",
                parameters={"db": db_name},
            )
            return {
                "database": db_name,
                "tables": [str(row[0]) for row in rows.result_rows],
            }
        if action not in {"start", "restart"}:
            raise RuntimeError(
                f"Unsupported ClickHouse daemon action: {action!r}. "
                "Supported: status, list-databases, list-tables, start, restart."
            )
        if not self._is_local_clickhouse(cfg):
            raise RuntimeError("ClickHouse start/restart management is only supported for local daemons.")

        brew_bin = shutil.which("brew")
        clickhouse_bin = shutil.which("clickhouse")
        config_file = self._find_clickhouse_config_file()
        attempts: List[Dict[str, Any]] = []

        if brew_bin:
            attempts.append(self._run_local_command([brew_bin, "services", action, "clickhouse"]))
            time.sleep(1.0)
            status = self._daemon_status_payload(cfg)
            if status.get("running"):
                status["management"] = {"action": action, "method": "brew_services", "attempts": attempts}
                return status

        if action == "start" and clickhouse_bin and config_file:
            attempts.append(
                self._run_local_command(
                    [clickhouse_bin, "server", "--daemon", "--config-file", config_file]
                )
            )
            time.sleep(1.0)
            status = self._daemon_status_payload(cfg)
            if status.get("running"):
                status["management"] = {"action": action, "method": "manual_daemon", "attempts": attempts}
                return status

        raise RuntimeError(
            "Unable to manage the local ClickHouse daemon automatically. "
            "Tried Homebrew services and manual daemon start where applicable."
        )

    # Operations ---------------------------------------------------------
    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        payload = self._daemon_status_payload(cfg)
        payload["backend"] = self.name
        payload["configured_tables"] = {
            "tokens": cfg.tokens_table,
            "docs": cfg.docs_table,
            "sentences": cfg.sentences_table,
        }

        if payload.get("running") and cfg.docs_table:
            try:
                client = self._get_client(cfg)
                docs_count = client.query(f"SELECT count() AS c FROM {cfg.docs_table}").first_row[0]
                payload["docs_count"] = int(docs_count)
                token_count = None
                token_col = cfg.columns.get("doc", {}).get("token_count")
                if token_col:
                    token_count = client.query(f"SELECT sum({token_col}) AS c FROM {cfg.docs_table}").first_row[0]
                payload["tokens_count"] = int(token_count) if token_count is not None else None
            except Exception as e:
                payload["docs_count"] = None
                payload["tokens_count"] = None
                payload["table_error"] = str(e)
        else:
            payload["docs_count"] = None
            payload["tokens_count"] = None
        return payload

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        self._require_query_schema(cfg)
        client = self._get_client(cfg)
        params = dict(req.get("params") or {})

        limit = int(params.get("limit", 50))
        offset = int(params.get("offset", 0))

        doc_cols = cfg.columns.get("doc", {})
        id_col = doc_cols.get("id", "doc_id")
        title_col = doc_cols.get("title", "title")
        date_col = doc_cols.get("date", "date")
        token_count_col = doc_cols.get("token_count", "size_tokens")
        available_cols = set(self._get_table_columns(client, cfg.database, cfg.docs_table or ""))

        def has_col(name: Any) -> bool:
            return bool(name) and str(name) in available_cols

        configured_id_col = str(id_col) if has_col(id_col) else ""
        if configured_id_col and configured_id_col != "doc_id":
            safe_id_col = configured_id_col
        elif has_col("text_id"):
            # Prefer TEITOK/document ids that users recognize over ClickHouse's
            # internal numeric row id when no explicit mapping was configured.
            safe_id_col = "text_id"
        elif has_col("doc_id"):
            safe_id_col = "doc_id"
        else:
            safe_id_col = next(iter(sorted(available_cols))) if available_cols else "doc_id"

        title_expr = str(title_col) if has_col(title_col) else (
            "text_id" if has_col("text_id") else safe_id_col
        )
        date_expr = str(date_col) if has_col(date_col) else "NULL"
        token_count_expr = str(token_count_col) if has_col(token_count_col) else (
            "size" if has_col("size") else "NULL"
        )
        meta_expr = "toJSONString(metadata)" if has_col("metadata") else "NULL"
        backend_id_expr = "doc_id" if has_col("doc_id") else "NULL"

        table = cfg.docs_table
        rows = client.query(
            f"""
            SELECT
                {safe_id_col} AS id,
                {title_expr} AS title,
                {date_expr} AS date,
                {token_count_expr} AS token_count,
                {meta_expr} AS meta_json,
                {backend_id_expr} AS backend_id
            FROM {table}
            ORDER BY id
            LIMIT {limit} OFFSET {offset}
            """
        )

        docs = []
        for r in rows.result_rows:
            meta: Dict[str, Any] = {}
            raw_meta = r[4] if len(r) > 4 else None
            if isinstance(raw_meta, dict):
                meta = dict(raw_meta)
            elif isinstance(raw_meta, str) and raw_meta.strip():
                try:
                    parsed = json.loads(raw_meta)
                    if isinstance(parsed, dict):
                        meta = parsed
                except Exception:
                    meta = {}
            docs.append(
                {
                    "id": r[0],
                    "title": r[1],
                    "date": r[2],
                    "token_count": r[3],
                    "meta": meta,
                    "backend_id": r[5] if len(r) > 5 else None,
                }
            )

        total = client.query(f"SELECT count() AS c FROM {table}").first_row[0]

        return {
            "docs": docs,
            "total": int(total),
        }

    def kwic(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        self._require_query_schema(cfg)
        client = self._get_client(cfg)
        params = dict(req.get("params") or {})

        query = params.get("query")
        if not isinstance(query, dict):
            raise RuntimeError("kwic requires params['query'] to be a dict like {'field': 'lemma', 'value': 'run'}.")

        field = query.get("field")
        value = query.get("value")
        if not field or value is None:
            raise RuntimeError("kwic query must specify 'field' and 'value'.")

        window = int(params.get("window", 5))
        limit = int(params.get("limit", 50))

        token_cols = cfg.columns.get("token", {})
        doc_id_col = token_cols.get("doc_id", "doc_id")
        pos_col = token_cols.get("position", "position")
        form_col = token_cols.get("form", "form")

        field_col = token_cols.get(field, field)
        table = cfg.tokens_table

        sql = f"""
        SELECT
            {doc_id_col} AS doc_id,
            {pos_col} AS position,
            arraySlice(groupArray({form_col}), max(1, indexOf(groupArray({pos_col}), {pos_col}) - {window}), {2 * window} + 1) AS context
        FROM {table}
        WHERE {field_col} = %(value)s
        GROUP BY doc_id, position
        ORDER BY doc_id, position
        LIMIT %(limit)s
        """

        result = client.query(sql, parameters={"value": value, "limit": limit})

        hits = []
        for row in result.result_rows:
            doc_id, position, context = row
            idx = len(context) // 2
            left = context[:idx]
            match = context[idx : idx + 1]
            right = context[idx + 1 :]
            hits.append(
                {
                    "doc_id": doc_id,
                    "position": position,
                    "left": left,
                    "match": match,
                    "right": right,
                }
            )

        return {"hits": hits}

    def freq(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        self._require_query_schema(cfg)
        client = self._get_client(cfg)
        params = dict(req.get("params") or {})

        field = params.get("field", "lemma")
        limit = int(params.get("limit", 50))
        offset = int(params.get("offset", 0))

        token_cols = cfg.columns.get("token", {})
        field_col = token_cols.get(field, field)
        table = cfg.tokens_table

        sql = f"""
        SELECT {field_col} AS value, count() AS cnt
        FROM {table}
        GROUP BY value
        ORDER BY cnt DESC
        LIMIT %(limit)s OFFSET %(offset)s
        """

        result = client.query(sql, parameters={"limit": limit, "offset": offset})
        items = [{"value": r[0], "count": int(r[1])} for r in result.result_rows]
        return {
            "field": field,
            "query": None,
            "total": None,
            "items": items,
            "returned": len(items),
        }

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        return {
            "backend": self.name,
            "descriptor": self.descriptor(),
            "connection": {
                "host": cfg.host,
                "port": cfg.port,
                "database": cfg.database,
                "username": cfg.username,
                "dsn": cfg.dsn,
            },
            "tables": {
                "tokens": cfg.tokens_table,
                "docs": cfg.docs_table,
                "sentences": cfg.sentences_table,
            },
            "columns": cfg.columns,
        }

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        # Stub for now; actual indexing is expected to be orchestrated separately.
        return {
            "status": "not_implemented",
            "message": "Reindexing is not implemented yet for the ClickHouse backend.",
        }


# Register backend at import time
register_backend(ClickHouseBackend())


@dataclass
class ClickqlBackend(ClickHouseBackend):
    """
    ClickQL backend: same as ClickHouse but with a query-oriented name.

    Uses the same config (project["clickhouse"] or project["clickql"]) and
    the same schema (docs, toks, sentences, regions, etc. as in dev/cwb2sql).
    Intended for TEITOK/EasyCorp when ClickHouse is the primary or derived
    search index.

    Reindex strategies (see dev/INDEXING-STRATEGY.md):
    - CQP-first: index CQP with flexicorp (cqp reindex), then run cwb2sql
      to stream CWB → ClickHouse. Supports existing TEITOK workflow.
    - Direct XML → ClickHouse (future): stream TEITOK XML into ClickHouse
      using the cwb2sql schema for maximum efficiency on huge corpora.
    """

    name: str = "clickql"

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "clickql",
            "supported_query_languages": ["clickcql", "clickql", "sql", "cql", "cwb-cql", "cwb", "pmltq", "clickpmltq"],
            "supported_corpus_formats": ["clickhouse"],
            "default_query_language": "clickcql",
            "default_corpus_format": "clickhouse",
            "default_selection_reason": "Query-oriented ClickHouse backend; default to clickCQL translation unless explicit SQL is supplied.",
        }

    def capabilities(self) -> Dict[str, bool]:
        cap = super().capabilities()
        cap["query"] = True
        return cap

    @staticmethod
    def _rewrite_peg_sql_tables(sql: str, cfg: ClickHouseConfig) -> str:
        if not sql:
            return sql
        replacements = {
            "toks": cfg.tokens_table or "toks",
            "docs": cfg.docs_table or "docs",
            "sentences": getattr(cfg, "sentences_table", None) or "sentences",
        }
        rewritten = str(sql)
        for legacy_name, actual_name in replacements.items():
            if legacy_name == actual_name:
                continue
            rewritten = re.sub(rf"\b{re.escape(legacy_name)}\b", actual_name, rewritten)
        return rewritten

    @staticmethod
    def _augment_peg_sql_for_hit_shape(sql: str, cfg: ClickHouseConfig) -> str:
        """
        Wrap PEG-generated SQL so downstream hit/context extraction sees the same
        doc/sentence metadata aliases as the legacy Python translator.
        """
        base_sql = (sql or "").strip().rstrip(";")
        if not base_sql:
            return base_sql
        upper_sql = base_sql.upper()
        if "T1_TEXT_ID" in upper_sql and "T1_SENTENCE_XML_START" in upper_sql and "T1_SENTENCE_XML_END" in upper_sql:
            return base_sql

        projected = ["_pegq.*"]
        joins: List[str] = []

        if getattr(cfg, "sentences_table", None):
            projected.append("s.xml_start AS t1_sentence_xml_start")
            projected.append("s.xml_end AS t1_sentence_xml_end")
            joins.append(f"LEFT JOIN {cfg.sentences_table} s ON s.sentence_id = _pegq.t1_sentence_id")
            if cfg.docs_table:
                projected.append("d.text_id AS t1_text_id")
                joins.append("LEFT JOIN {docs} d ON d.doc_id = s.doc_id".format(docs=cfg.docs_table))
        elif cfg.docs_table:
            projected.append("d.text_id AS t1_text_id")
            joins.append(f"LEFT JOIN {cfg.docs_table} d ON d.doc_id = _pegq.t1_doc_id")

        if len(projected) == 1:
            return base_sql

        return "SELECT {cols}\nFROM ({base}) AS _pegq\n{joins}".format(
            cols=", ".join(projected),
            base=base_sql,
            joins="\n".join(joins),
        )

    @staticmethod
    def _strip_trailing_settings(sql: str) -> str:
        return re.sub(r"\nSETTINGS[\s\S]*$", "", (sql or "").strip(), flags=re.IGNORECASE).strip()

    def _query_rows_as_dicts(self, result: Any) -> List[Dict[str, Any]]:
        column_names = [c for c in result.column_names]
        return [dict(zip(column_names, row)) for row in result.result_rows]

    def query(self, req: FlexiRequest) -> Dict[str, Any]:
        """
        Run a CQL query (or pre-translated SQL) with pagination.
        When params.query is given and params.sql is not, CQL is translated to SQL
        in Python (flexicorp.cql) so the same logic as the JS/PHP translator is used.
        When params.sql is given, it is used as-is. See dev/QUERY-API-DESIGN.md.
        """
        cfg = self._get_config(req)
        self._require_query_schema(cfg)
        params = dict(req.get("params") or {})
        cfg = self._cfg_for_database(cfg, params.get("database"))
        client = self._get_client(cfg)
        sql = (params.get("sql") or "").strip()
        query_lang = str(params.get("query_lang") or params.get("query_language") or "clickcql").strip().lower()
        start = int(params.get("start", 0))
        max_hits = max(0, min(int(params.get("max", 50)), 5000))
        cache_key = params.get("cache_key")
        refresh_cache = bool(params.get("refresh_cache", False))
        include_sql = bool(params.get("include_sql") or params.get("show_sql") or params.get("debug"))
        raw_query = (params.get("query") or "").strip()
        legend: List[Dict[str, Any]] = []
        translator_langs = {"cql", "clickcql", "clickql", "cwb-cql", "cwb", "pmltq", "clickpmltq"}
        project = dict(req.get("project") or {})
        context_spec = self._normalize_context_request(params)
        translation_engine = "sql"
        result_type = "hits"
        sql_statements: List[str] = []

        # Security: do not accept raw SQL from the client; only CQL (params.query) is allowed.
        # Server-side translation produces SQL restricted to this corpus's database and tables.
        if sql:
            raise RuntimeError(
                "Raw SQL (params.sql) is not accepted. Use params.query with a CQL string so that "
                "queries run only against this corpus."
            )

        if not sql:
            if not raw_query:
                raise RuntimeError(
                    "ClickQL query requires params.query (CQL string) or params.sql (pre-translated SQL)."
                )
            if query_lang not in translator_langs:
                raise RuntimeError(
                    "ClickQL currently supports server-side translation for "
                    "query_language in {'clickcql', 'clickql', 'cql', 'cwb-cql', 'cwb', 'pmltq', 'clickpmltq'}. "
                    f"Got query_lang={query_lang!r}."
                )
            if query_lang in {"pmltq", "clickpmltq"}:
                try:
                    from ..pmltq import PmltqPegError, translate_pmltq
                except ImportError as e:
                    raise RuntimeError("PML-TQ translator not available; install flexicorp and try again.") from e
                try:
                    peg_result = translate_pmltq(
                        raw_query,
                        project=project,
                        debug=bool(params.get("debug")),
                    )
                except PmltqPegError as e:
                    raise RuntimeError(f"PML-TQ PEG parser error: {e}") from e
                result_type = str(peg_result.get("result_type") or "hits")
                raw_statements = peg_result.get("sql_statements")
                if isinstance(raw_statements, list) and raw_statements:
                    sql_statements = [self._rewrite_peg_sql_tables(str(stmt).strip(), cfg) for stmt in raw_statements if str(stmt).strip()]
                else:
                    sql_statements = [self._rewrite_peg_sql_tables(str(peg_result.get("sql") or "").strip(), cfg)]
                if not sql_statements:
                    raise RuntimeError("PML-TQ translator did not return any SQL statements.")
                sql = sql_statements[-1]
                if result_type == "hits":
                    sql = self._augment_peg_sql_for_hit_shape(sql, cfg)
                    sql_statements[-1] = sql
                count_sql = f"SELECT count() FROM ({self._strip_trailing_settings(sql)}) AS _q"
                translation_engine = "pmltq-peg-js"
            else:
                try:
                    from ..cql import cql_to_sql, cql_to_count_sql
                    from ..cql.cql2sql import extract_highlight_legend
                    from ..clickcql import ClickCqlPegError, translate_clickcql
                except ImportError as e:
                    raise RuntimeError(
                        "CQL translator (flexicorp.cql) not available; install flexicorp and try again."
                    ) from e
                legend = extract_highlight_legend(raw_query)
                try:
                    peg_result = translate_clickcql(
                        raw_query,
                        project=project,
                        limit=None,
                        offset=None,
                        debug=bool(params.get("debug")),
                    )
                    if peg_result.get("statement_count", 1) != 1 or peg_result.get("requires_state"):
                        ast_type = str(peg_result.get("ast_type") or "query_sequence")
                        raise RuntimeError(
                            "ClickQL PEG parsing recognized this as a stateful ClickCQL command "
                            f"({ast_type}) that current flexicorp does not execute yet. "
                            "For now, use a single concordance-style query or pre-translated SQL."
                        )
                    if str(peg_result.get("ast_type") or "") != "query":
                        ast_type = str(peg_result.get("ast_type") or "unknown")
                        raise RuntimeError(
                            f"ClickQL PEG parsing recognized this as {ast_type!r}, but the current "
                            "flexicorp clickql backend only executes concordance-style query expressions."
                        )
                    sql = self._rewrite_peg_sql_tables(str(peg_result.get("sql") or "").strip(), cfg)
                    sql = self._augment_peg_sql_for_hit_shape(sql, cfg)
                    count_sql = self._rewrite_peg_sql_tables(str(peg_result.get("count_sql") or "").strip(), cfg)
                    translation_engine = "peg-js"
                except ClickCqlPegError as e:
                    if not e.fallback_allowed:
                        raise RuntimeError(f"ClickCQL PEG parser error: {e}") from e
                    sql = cql_to_sql(
                        raw_query,
                        tokens_table=cfg.tokens_table,
                        sentences_table=getattr(cfg, "sentences_table", None) or "sentences",
                        docs_table=cfg.docs_table,
                        limit=None,
                        offset=None,
                    )
                    count_sql = cql_to_count_sql(
                        raw_query,
                        tokens_table=cfg.tokens_table,
                        sentences_table=getattr(cfg, "sentences_table", None) or "sentences",
                        docs_table=cfg.docs_table,
                    )
                    translation_engine = "python-fallback"
        else:
            count_sql = (params.get("count_sql") or "").strip()

        base_sql = sql.rstrip().rstrip(";")
        if not count_sql:
            count_sql = f"SELECT count() FROM ({base_sql}) AS _q"

        if result_type == "table":
            if len(sql_statements) > 1:
                for stmt in sql_statements[:-1]:
                    if stmt.strip():
                        client.command(stmt)
            total_result = client.query(count_sql)
            total = int(total_result.first_row[0])
            data_sql = f"SELECT * FROM ({self._strip_trailing_settings(base_sql)}) AS _q LIMIT {max_hits} OFFSET {start}"
            result = client.query(data_sql)
            rows = self._query_rows_as_dicts(result)
            out: Dict[str, Any] = {
                "total": total,
                "start": start,
                "returned": len(rows),
                "query_lang": query_lang,
                "translation_engine": translation_engine,
                "database": cfg.database,
                "result_type": "table",
                "table": {
                    "columns": [str(c) for c in result.column_names],
                    "rows": rows,
                },
            }
            if include_sql:
                out["sql"] = {
                    "query_sql": base_sql,
                    "count_sql": count_sql,
                    "data_sql": data_sql,
                    "statements": sql_statements or [base_sql],
                }
            if raw_query:
                out["query"] = raw_query
            return out

        # Optional cache table: create once, then page from it (same as PHP)
        table_name: Optional[str] = None
        if cache_key:
            table_name = self._ensure_cache_table(client, cfg, base_sql, cache_key, refresh_cache)

        if table_name:
            total_result = client.query(f"SELECT count() AS c FROM {table_name}")
            total = int(total_result.first_row[0])
            data_sql = f"SELECT * FROM {table_name} LIMIT {max_hits} OFFSET {start}"
        else:
            total_result = client.query(count_sql)
            total = int(total_result.first_row[0])
            data_sql = f"SELECT * FROM ({base_sql}) AS _q LIMIT {max_hits} OFFSET {start}"

        result = client.query(data_sql)
        column_names = [c for c in result.column_names]
        hits: List[Dict[str, Any]] = []
        group_meta_by_id = {str(item.get("id")): item for item in legend if isinstance(item, dict) and item.get("id")}
        for row in result.result_rows:
            row_dict = dict(zip(column_names, row))
            hit = self._row_to_hit(row_dict, group_meta_by_id=group_meta_by_id)
            context = self._resolve_teitok_context_for_hit(
                project=project,
                hit=hit,
                context_spec=context_spec,
            )
            if context:
                hit["context"] = context
            hits.append(hit)

        out: Dict[str, Any] = {
            "total": total,
            "start": start,
            "returned": len(hits),
            "hits": hits,
            "result_type": "hits",
            "query_lang": query_lang,
            "translation_engine": translation_engine,
            "database": cfg.database,
            "legend": resolve_legend(params) or legend,
        }
        if cache_key:
            out["cache_key"] = cache_key
        if include_sql:
            out["sql"] = {
                "query_sql": base_sql,
                "count_sql": count_sql,
                "data_sql": data_sql,
            }
        if raw_query:
            out["query"] = raw_query
        return out

    def _ensure_cache_table(
        self,
        client: Any,
        cfg: ClickHouseConfig,
        base_sql: str,
        cache_key: str,
        refresh: bool,
    ) -> Optional[str]:
        """Create a temp table for pagination without rerunning the query. Returns table name or None."""
        base_upper = base_sql.upper()
        if ";" in base_sql or "CREATE TABLE" in base_upper or "DROP TABLE" in base_upper:
            return None
        safe_key = re.sub(r"[^a-zA-Z0-9_]", "_", cache_key)
        table = f"`{cfg.database}`.`_cql_cache_{safe_key}`"
        if refresh:
            try:
                client.command(f"DROP TABLE IF EXISTS {table}")
            except Exception:
                pass
        try:
            # Check existence
            r = client.query(
                f"SELECT count() FROM system.tables WHERE database = '{cfg.database}' AND name = '_cql_cache_{safe_key}'"
            )
            if r.first_row[0] and not refresh:
                return table
        except Exception:
            pass
        try:
            create_sql = f"CREATE TABLE {table} ENGINE = MergeTree() ORDER BY tuple() AS {base_sql}"
            client.command(create_sql)
            return table
        except Exception:
            return None

    def _row_to_hit(self, row: Dict[str, Any], *, group_meta_by_id: Optional[Dict[str, Dict[str, Any]]] = None) -> Dict[str, Any]:
        """Convert a result row to a unified hit with doc_id, sentence_id, toks, highlight_map."""
        sentence_id = None
        for key in ("t1_sentence_id", "sentence_id", "t1.sentence_id"):
            if row.get(key) is not None:
                sentence_id = row.get(key)
                break
        doc_id = (
            row.get("text_id")
            or row.get("t1_text_id")
            or row.get("doc_id")
            or row.get("t1.doc_id")
        )
        match_start = None
        match_end = None
        if row.get("t1_tok_pos") is not None:
            try:
                match_start = int(row.get("t1_tok_pos"))
            except (TypeError, ValueError):
                match_start = None
        toks: List[Any] = []
        groups: List[Dict[str, Any]] = []
        for i in range(1, 11):
            tid = row.get(f"t{i}_tok_id") or row.get(f"t{i}.tok_id")
            if tid is not None and str(tid).strip():
                tid_str = str(tid)
                toks.append(tid_str)
                group_id = f"t{i}"
                group = {"id": group_id, "tok_ids": [tid_str]}
                if group_meta_by_id and group_id in group_meta_by_id:
                    group.update({
                        k: v
                        for k, v in group_meta_by_id[group_id].items()
                        if k in {"name", "label", "query_span", "color", "textColor"}
                    })
                groups.append(group)
                tok_pos = row.get(f"t{i}_tok_pos") or row.get(f"t{i}.tok_pos")
                try:
                    tok_pos_int = int(tok_pos) if tok_pos is not None else None
                except (TypeError, ValueError):
                    tok_pos_int = None
                if tok_pos_int is not None:
                    if match_start is None or tok_pos_int < match_start:
                        match_start = tok_pos_int
                    if match_end is None or tok_pos_int > match_end:
                        match_end = tok_pos_int
        hit: Dict[str, Any] = {
            "doc_id": doc_id,
            "sentence_id": sentence_id,
            "toks": toks,
            "row": row,
        }
        if match_start is not None:
            hit["match_start"] = match_start
        if match_end is not None:
            hit["match_end"] = match_end
        if toks:
            hit["highlight_map"] = build_highlight_map(toks, groups=groups)
        return hit

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        return {
            "status": "not_implemented",
            "message": (
                "ClickQL reindex is not implemented in-process. Use either: "
                "(1) CQP-first: run 'flexicorp --backend cqp reindex' then "
                "dev/cwb2sql.py to export CWB → ClickHouse; "
                "(2) Direct XML→ClickHouse (planned) for maximum efficiency. "
                "See dev/INDEXING-STRATEGY.md."
            ),
            "strategies": {
                "cqp_first": "flexicorp --backend cqp reindex && python dev/cwb2sql.py ...",
                "direct_xml": "Planned: stream TEITOK XML into ClickHouse (cwb2sql schema).",
            },
        }


register_backend(ClickqlBackend())

