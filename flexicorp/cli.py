from __future__ import annotations

import argparse
import contextlib
import faulthandler
import importlib.util
import json
import os
import sys
import tempfile
from pathlib import Path
from typing import Any, Dict

from .core import handle_request

# Install a Python-level handler for fatal signals (SIGSEGV, SIGFPE, SIGBUS,
# SIGILL, SIGABRT) so native crashes in dependencies like the Manatee
# ``_manatee.so`` C++ bindings emit a Python traceback on stderr *before*
# the process dies. Without this the only signal a user sees is ``zsh:
# segmentation fault``, which offers no way to tell whether the crash was
# in Python, in a dependency's native code, or inside the CLI itself.
#
# ``faulthandler`` is stdlib; the cost is one stderr file descriptor held
# open for the life of the process. It can be disabled by setting
# ``FLEXICORP_NO_FAULTHANDLER=1`` if it interferes with an embedding that
# installs its own signal handlers. Respect ``PYTHONFAULTHANDLER`` too —
# running with ``-X faulthandler`` already enables it, and re-enabling is
# a no-op but the env-var toggle is explicit.
if not os.environ.get("FLEXICORP_NO_FAULTHANDLER"):
    try:
        faulthandler.enable()
    except Exception:
        # ``enable()`` can fail if stderr is closed; we just skip silently —
        # worst case is the pre-fix behaviour (silent segfault).
        pass


@contextlib.contextmanager
def _isolate_stdout_for_api():
    """
    Guarantee that stdout carries ONLY our JSON envelope when --api is set.

    Motivation
    ----------
    Several native dependencies (notably the Manatee `_manatee.so` C++ bindings,
    but also some CWB utilities) write warnings or error messages directly to
    the process's stdout via C stdio / C++ iostreams. Those writes bypass
    Python's `sys.stdout` object entirely — they go straight to file
    descriptor 1 at the OS level. If any such write happens while we're
    preparing the response, it lands *before* our `json.dump(...)` output on
    stdout, and the TEITOK PHP wrapper then reports
    "flexicorp did not return valid JSON" even though the Python side
    produced a perfectly well-formed envelope.

    Fix
    ---
    For the duration of request handling we swap file descriptor 1 to point
    at a temporary file. Anything that native code (or stray Python) writes
    to "stdout" during that window goes into the temp file. On exit we
    restore the original stdout FD, copy the captured bytes to stderr as
    diagnostic output (prefixed so it's obvious where they came from), and
    then the caller can write the JSON envelope cleanly to the real stdout.

    This is strictly defence-in-depth: on a well-behaved code path the temp
    file is empty and we skip the copy. It costs one temp-file open per
    request and is invisible to downstream tooling.
    """
    # Ensure Python's own stdout buffer is flushed before we swap the FD,
    # so nothing we've already written gets redirected into the trap file.
    try:
        sys.stdout.flush()
    except Exception:
        pass

    saved_fd = os.dup(1)
    tmp = tempfile.TemporaryFile(mode="w+b")
    try:
        os.dup2(tmp.fileno(), 1)
        try:
            yield
        finally:
            # Flush the Python-level stdout that currently points at the trap
            # file, then restore the real stdout FD.
            try:
                sys.stdout.flush()
            except Exception:
                pass
            os.dup2(saved_fd, 1)
    finally:
        os.close(saved_fd)
        try:
            tmp.seek(0)
            captured = tmp.read()
        except Exception:
            captured = b""
        finally:
            try:
                tmp.close()
            except Exception:
                pass
        if captured:
            try:
                sys.stderr.buffer.write(
                    b"[flexicorp] captured stray stdout (routed to stderr to keep --api output valid JSON):\n"
                )
                sys.stderr.buffer.write(captured)
                if not captured.endswith(b"\n"):
                    sys.stderr.buffer.write(b"\n")
                sys.stderr.flush()
            except Exception:
                pass

def _load_local_teitok_detectors() -> tuple[Any, Any]:
    """
    Load TEITOK detector helpers from this package's local ``teitok.py``.

    In mixed bind-mount setups, ``flexicorp`` can become a namespace package and
    ``from .teitok import ...`` may resolve to top-level ``/opt/flexicorp/teitok``
    (PHP assets) instead of ``flexicorp/teitok.py``.
    """
    module_path = Path(__file__).with_name("teitok.py")
    spec = importlib.util.spec_from_file_location("_flexicorp_cli_teitok_local", module_path)
    if spec is None or spec.loader is None:
        raise ImportError(f"Unable to load local teitok helpers from {module_path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[assignment]
    return module.detect_teitok_cqp, module.detect_teitok_manatee


try:
    from .teitok import detect_teitok_cqp, detect_teitok_manatee
except Exception:
    detect_teitok_cqp, detect_teitok_manatee = _load_local_teitok_detectors()
from .settings import (
    get_auto_install_optional_deps,
    get_default_backend,
    set_auto_install_optional_deps,
    set_default_backend,
    get_config_file,
)
from .env_admin import run_env_check_corpus, run_env_list, run_env_status


def _brief_backend_overview_payload(result: Dict[str, Any]) -> Dict[str, Any]:
    """Smaller ``info backends`` JSON: drop heavy repeated blocks."""
    out: Dict[str, Any] = {
        "topic": "backends",
        "availableBackends": result.get("availableBackends"),
        "availableQueryEngines": result.get("availableQueryEngines"),
        "backendStatus": {},
        "queryEngines": result.get("queryEngines"),
    }
    bs = result.get("backendStatus") or {}
    for name, st in bs.items():
        if isinstance(st, dict):
            out["backendStatus"][name] = {k: v for k, v in st.items() if k not in ("capabilities", "descriptor")}
        else:
            out["backendStatus"][name] = st
    return out


def _maybe_brief_info_backends(res: Dict[str, Any], args: argparse.Namespace) -> Dict[str, Any]:
    if (
        getattr(args, "operation", None) != "info"
        or getattr(args, "info_topic", None) != "backends"
        or not getattr(args, "brief", False)
    ):
        return res
    if not res.get("ok"):
        return res
    inner = res.get("result")
    if not isinstance(inner, dict) or inner.get("topic") != "backends":
        return res
    return {**res, "result": _brief_backend_overview_payload(inner)}


def _add_shared_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument(
        "--api",
        action="store_true",
        help="Emit an API-style JSON envelope describing what was requested, what was done, and whether it succeeded.",
    )
    parser.add_argument(
        "--backend",
        "-b",
        default=None,
        help="Backend to use (e.g. blacklab, clickhouse, clickql, cqp, flexi, manatee, pmltq). "
        "When omitted, kwic/query can infer the backend from --query-language / --corpus-format "
        "(e.g. manatee-cql + manatee → manatee). Otherwise defaults to flexicorp settings "
        "(or 'clickhouse' if unset).",
    )
    parser.add_argument(
        "--project-root",
        "-p",
        help="Project root path (used in the 'project.root' field). "
        "For TEITOK projects this is typically the TEITOK corpus folder.",
    )
    parser.add_argument(
        "--project-json",
        help="Path to a JSON file providing the full 'project' section. "
        "Overrides --project-root when set.",
    )
    parser.add_argument(
        "--folder",
        "-f",
        help="Folder to inspect for TEITOK settings (defaults to CWD when using the CQP backend).",
    )
    parser.add_argument(
        "--teitok",
        choices=["auto", "yes", "no"],
        default="auto",
        help="TEITOK detection mode for the CQP backend. "
        "'auto' (default) tries to detect a TEITOK project from --folder or CWD; "
        "'yes' forces TEITOK detection; 'no' disables TEITOK logic and assumes "
        "a standard CQP setup (central registry or explicit --registry/--corpus).",
    )
    parser.add_argument(
        "--registry",
        help="CQP registry directory (overrides TEITOK detection when provided).",
    )
    parser.add_argument(
        "--corpus",
        help="CQP corpus name (overrides TEITOK detection when provided).",
    )
    parser.add_argument(
        "--cqp-binary",
        default="cqp",
        help="Path to the cqp binary when using the CQP backend (default: 'cqp').",
    )
    parser.add_argument(
        "--cqp-encoding",
        help="Preferred encoding for CQP stdout/stderr (e.g. utf-8, cp1250, iso-8859-2).",
    )
    parser.add_argument(
        "--debug",
        action="store_true",
        help="Enable debug logging (prints backend-specific debug information to stderr).",
    )
    parser.add_argument(
        "--options",
        "-O",
        action="append",
        help="Backend-specific option as key=value (can be repeated). "
        "Example: -O status=raw -O missing=morph -O indexed=no",
    )
    parser.add_argument(
        "--query-language",
        "--query-lang",
        "--cql",
        dest="query_language",
        help="Logical query language to use (e.g. bcql, cwb-cql, cqp, cql, clickql, clickcql, pmltq). "
        "Alias: --cql.",
    )
    parser.add_argument(
        "--corpus-format",
        "--file-format",
        dest="corpus_format",
        help="Corpus storage format for native backends (e.g. cwb, manatee).",
    )
    parser.add_argument(
        "--clickhouse-dsn",
        help="ClickHouse DSN, e.g. clickhouse://user:pass@127.0.0.1:8123/database",
    )
    parser.add_argument(
        "--clickhouse-host",
        help="ClickHouse host (default: 127.0.0.1).",
    )
    parser.add_argument(
        "--clickhouse-port",
        type=int,
        help="ClickHouse HTTP port (default: 8123).",
    )
    parser.add_argument(
        "--clickhouse-database",
        help="ClickHouse database to use.",
    )
    parser.add_argument(
        "--clickhouse-user",
        help="ClickHouse username.",
    )
    parser.add_argument(
        "--clickhouse-password",
        help="ClickHouse password.",
    )
    parser.add_argument(
        "--clickhouse-tokens-table",
        help="ClickHouse tokens table name.",
    )
    parser.add_argument(
        "--clickhouse-docs-table",
        help="ClickHouse docs table name.",
    )
    parser.add_argument(
        "--clickhouse-sentences-table",
        help="ClickHouse sentences table name.",
    )
def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="flexicorp",
        description="flexiCorp: multi-backend corpus interface for TEITOK / EasyCorp.",
        # Avoid confusing '--query' (positional/CQP) with '--query-language'.
        allow_abbrev=False,
    )
    _add_shared_args(parser)

    shared_parent = argparse.ArgumentParser(add_help=False)
    _add_shared_args(shared_parent)

    info_brief_parent = argparse.ArgumentParser(add_help=False)
    info_brief_parent.add_argument(
        "--brief",
        action="store_true",
        help="For 'info backends': omit implementedBackends, backendCombos, and per-backend capabilities/descriptor "
        "(smaller JSON for quick inspection).",
    )

    subparsers = parser.add_subparsers(dest="operation", required=True)

    # status --------------------------------------------------------------
    subparsers.add_parser("status", help="Show corpus/backend status.", parents=[shared_parent])

    # list-docs -----------------------------------------------------------
    list_docs = subparsers.add_parser("list-docs", help="List documents.", parents=[shared_parent])
    list_docs.add_argument("--limit", type=int, default=50, help="Maximum number of documents to return (default: 50).")
    list_docs.add_argument("--offset", type=int, default=0, help="Offset into the document list (for pagination).")
    list_docs.add_argument(
        "--verbose",
        action="store_true",
        help="Request backend-computed details (e.g. token counts) which may be slower on large corpora.",
    )

    # kwic / query --------------------------------------------------------
    kwic = subparsers.add_parser(
        "kwic",
        help="Run a KWIC/query operation.",
        aliases=["query"],
        parents=[shared_parent],
    )
    kwic.add_argument(
        "pattern",
        nargs="?",
        help="Query string (e.g. CQP query for the CQP backend).",
    )
    kwic.add_argument(
        "--field",
        help="Field to query (ClickHouse backend, default 'lemma').",
    )
    kwic.add_argument(
        "--value",
        help="Field value to match (ClickHouse backend).",
    )
    kwic.add_argument(
        "--query",
        help="Backend-specific raw query string (e.g. CQP). "
        "If set, overrides --field/--value.",
    )
    kwic.add_argument("--window", type=int, default=5)
    kwic.add_argument("--limit", type=int, default=50)
    kwic.add_argument("--start", type=int, default=0, help="Offset for query operation (pagination).")
    kwic.add_argument("--qid", help="Reuse an existing cached query result set for pagination.")
    kwic.add_argument("--refresh-cache", action="store_true", help="Force rebuilding any cached result set for this query.")
    kwic.add_argument(
        "--extract-fragments",
        action="store_true",
        help="For TEITOK-backed query results, extract the matching XML fragment (typically the sentence).",
    )
    kwic.add_argument(
        "--context-scope",
        help="Requested query context scope for TEITOK-backed results, e.g. s, p, tok, or word.",
    )
    kwic.add_argument(
        "--context-format",
        choices=["xml", "text"],
        help="Requested query context format for TEITOK-backed results.",
    )
    kwic.add_argument(
        "--context-level",
        help="Deprecated alias for --context-scope; use a region name such as s or p.",
    )
    kwic.add_argument(
        "--sql",
        help="Pre-translated SQL to run directly (ClickQL).",
    )
    kwic.add_argument(
        "--count-sql",
        help="Explicit count SQL matching --sql (ClickQL).",
    )
    kwic.add_argument(
        "--cache-key",
        help="Cache key for ClickHouse temp-table paging.",
    )
    kwic.add_argument(
        "--show-sql",
        action="store_true",
        help="Include translated/executed SQL in the result JSON.",
    )

    # freq ----------------------------------------------------------------
    freq = subparsers.add_parser("freq", help="Compute simple frequency list.", parents=[shared_parent])
    freq.add_argument(
        "pattern",
        nargs="?",
        help="Optional backend-specific preselection query (e.g. CQP query for the CQP backend).",
    )
    freq.add_argument(
        "--query",
        help="Optional backend-specific preselection query. Overrides the positional pattern when set.",
    )
    freq.add_argument("--field", default="lemma")
    freq.add_argument("--limit", type=int, default=50)
    freq.add_argument("--offset", type=int, default=0)

    # reindex -------------------------------------------------------------
    reindex = subparsers.add_parser(
        "reindex",
        help="Rebuild backend indices (admin / maintenance operation).",
        parents=[shared_parent],
    )
    reindex.add_argument(
        "--input-folder",
        help=(
            "For the CQP backend in non-TEITOK mode: folder containing one or more .vrt/.vert files "
            "to be indexed with CWB. Defaults to project root or --folder when omitted."
        ),
    )
    reindex.add_argument(
        "--pattribute",
        dest="pattributes",
        action="append",
        help=(
            "Positional attribute name for CWB encoding in non-TEITOK mode (can be given multiple times). "
            "Example: --pattribute=word --pattribute=lemma"
        ),
    )
    reindex.add_argument(
        "--sattribute",
        dest="sattributes",
        action="append",
        help=(
            "Structural attribute name for CWB encoding in non-TEITOK mode (can be given multiple times). "
            "Example: --sattribute=text --sattribute=sentence"
        ),
    )
    reindex.add_argument(
        "--verbose",
        action="store_true",
        help="Show detailed reindex progress and backend command output on stderr.",
    )
    reindex.add_argument(
        "--reindex-backends",
        metavar="BACKENDS",
        help=(
            "Comma-separated list of backends to reindex in one run "
            "(e.g. cqp,clickhouse,clickql,pando,manatee,blacklab,pmltq). "
            "Uses flexencoder for CQP/ClickHouse/ClickQL/Pando; BlackLab, Manatee, and PML-TQ each use their backend reindex."
        ),
    )
    reindex.add_argument(
        "--background",
        action="store_true",
        help=(
            "Enqueue a detached reindex worker instead of blocking. "
            "Writes job state under project/tmp/flexicorp-reindex-jobs/; poll via ``flexicorp reindex-status``."
        ),
    )
    reindex.add_argument(
        "--force",
        action="store_true",
        help="With --background: terminate any existing reindex lock for this corpus/backend set, then start.",
    )
    reindex.add_argument(
        "--staging",
        action="store_true",
        help=(
            "With --background: build CWB (and flexencoder outputs) under tmp/flexicorp-reindex-staging/<job_id>/, "
            "then swap into project/cqp and load ClickHouse after the swap."
        ),
    )

    # reindex-status -------------------------------------------------------
    reindex_status = subparsers.add_parser(
        "reindex-status",
        help="Show status for a background reindex job (tmp/flexicorp-reindex-jobs/<job_id>.json).",
        parents=[shared_parent],
    )
    reindex_status.add_argument(
        "--job-id",
        required=True,
        dest="job_id",
        help="Job id returned in done.result.indexer.job_id when using --background.",
    )

    # highlight -----------------------------------------------------------
    highlight = subparsers.add_parser(
        "highlight",
        help="Return syntax-highlight tokens or HTML for a query snippet (by query language).",
        parents=[shared_parent],
    )
    highlight.add_argument(
        "--snippet",
        required=True,
        help="Query string to highlight (e.g. '[word=\"the\"] []').",
    )
    highlight.add_argument(
        "--format",
        choices=["tokens", "html"],
        default="tokens",
        help="Output format: 'tokens' (JSON list of {text, kind}) or 'html' (span fragment with cql-* classes).",
    )

    # daemon --------------------------------------------------------------
    daemon = subparsers.add_parser(
        "daemon",
        help="Inspect or manage a local ClickHouse daemon.",
        parents=[shared_parent],
    )
    daemon.add_argument(
        "action",
        choices=["status", "list-databases", "list-tables", "start", "restart"],
        help="Daemon action to perform.",
    )
    daemon.add_argument(
        "--database",
        help="Database override for list-tables.",
    )

    # info ----------------------------------------------------------------
    info = subparsers.add_parser("info", help="Show backend or corpus information.", parents=[shared_parent])
    info_sub = info.add_subparsers(dest="info_topic", required=False)
    info_sub.add_parser("corpus", help="Show corpus/backend configuration details.", parents=[shared_parent])
    info_sub.add_parser(
        "backends",
        help="Show implemented backends and per-corpus availability overview.",
        parents=[shared_parent, info_brief_parent],
    )

    # config --------------------------------------------------------------
    config = subparsers.add_parser("config", help="Manage flexicorp CLI configuration.", parents=[shared_parent])
    config.add_argument(
        "--set-default-backend",
        choices=["blacklab", "clickhouse", "clickql", "cqp", "flexi", "manatee", "pmltq"],
        metavar="BACKEND",
        help="Set the default backend to use when --backend is not provided.",
    )
    config.add_argument(
        "--set-auto-install-optional-deps",
        dest="set_auto_install_optional_deps",
        choices=["true", "false"],
        metavar="BOOL",
        help="Enable or disable automatic installation of optional backend dependencies on first use.",
    )
    config.add_argument(
        "--show",
        action="store_true",
        help="Show current flexicorp configuration for the CLI.",
    )

    # env -----------------------------------------------------------------
    env = subparsers.add_parser(
        "env",
        help="Inspect environment adapters (frontends + backends; e.g. teitok/fcs/pando/cqp/manatee/blacklab/kontext/pmltq, plus optional clickhouse/teitokxml).",
        parents=[shared_parent],
    )
    env.add_argument(
        "env_action",
        nargs="?",
        choices=["status", "check-corpus", "list"],
        default="status",
        help="Environment operation (default: status).",
    )
    env.add_argument(
        "--env-backends",
        default="teitok,fcs,pando,cqp,manatee,blacklab,kontext,pmltq",
        help="Comma-separated subset of environment backends to inspect.",
    )
    env.add_argument(
        "--corpus-id",
        help="Optional corpus id label for check-corpus reporting.",
    )
    env.add_argument(
        "--env-config",
        help="Optional env-admin config JSON path (defaults: FLEXICORP_ENV_CONFIG, /etc/flexicorp/env-config.json, ~/.config/flexicorp/env-config.json, ~/.flexicorp/env-config.json, /tmp/flexicorp/env-config.json).",
    )

    return parser


def _load_project(args: argparse.Namespace) -> Dict[str, Any]:
    project: Dict[str, Any] = {}
    if args.project_json:
        with open(args.project_json, "r", encoding="utf-8") as f:
            project = json.load(f)

    root: str | None = None
    if args.project_root:
        root = args.project_root
    elif args.folder:
        root = args.folder
    if root:
        project["root"] = root
    if getattr(args, "corpus_format", None):
        project["format"] = args.corpus_format
    requested_format = str(project.get("format") or "").strip().lower()

    # CQP-specific TEITOK / registry handling ----------------------------
    if args.backend in {"cqp", "flexi"}:
        cqp_cfg: Dict[str, Any] = dict(project.get("cqp") or {})

        # Auto-detect TEITOK project unless explicitly disabled.
        if args.teitok in {"auto", "yes"}:
            start_path = Path(args.folder or args.project_root or ".").resolve()
            detected = detect_teitok_cqp(start_path)
            if detected:
                project.setdefault("root", detected["root"])
                detected_cqp = detected.get("cqp") or {}
                # Detected settings provide a base; CLI options can override below.
                for key, value in detected_cqp.items():
                    cqp_cfg.setdefault(key, value)

        # Explicit CLI overrides
        if args.registry:
            cqp_cfg["registry"] = args.registry
        if args.corpus:
            cqp_cfg["corpus"] = args.corpus
        if args.cqp_binary:
            cqp_cfg["cqp_binary"] = args.cqp_binary
        if getattr(args, "cqp_encoding", None):
            cqp_cfg["encoding"] = args.cqp_encoding

        if cqp_cfg:
            project["cqp"] = cqp_cfg

    if args.backend == "manatee" and not requested_format:
        project["format"] = "manatee"
        requested_format = "manatee"

    # Manatee-specific TEITOK / registry handling ------------------------
    if args.backend in {"flexi", "manatee"} and requested_format == "manatee":
        manatee_cfg: Dict[str, Any] = dict(project.get("manatee") or {})

        if args.teitok in {"auto", "yes"}:
            start_path = Path(args.folder or args.project_root or ".").resolve()
            detected = detect_teitok_manatee(start_path)
            if detected:
                project.setdefault("root", detected["root"])
                detected_manatee = detected.get("manatee") or {}
                for key, value in detected_manatee.items():
                    manatee_cfg.setdefault(key, value)

        if args.registry:
            manatee_cfg["registry"] = args.registry
        if args.corpus:
            manatee_cfg["corpus"] = args.corpus

        if manatee_cfg:
            project["manatee"] = manatee_cfg

        # CQP index files (text.rng, text_id.avs, …) live under the CQP registry dir, not the
        # Manatee data PATH. Merge TEITOK CQP detection so manatee query can resolve doc_id/context.
        if args.backend == "manatee" and args.teitok in {"auto", "yes"}:
            start_cqp = Path(args.folder or args.project_root or ".").resolve()
            detected_cqp = detect_teitok_cqp(start_cqp)
            if detected_cqp:
                cqp_merge: Dict[str, Any] = dict(project.get("cqp") or {})
                for key, value in (detected_cqp.get("cqp") or {}).items():
                    cqp_merge.setdefault(key, value)
                if cqp_merge:
                    project["cqp"] = cqp_merge

    # ClickHouse / ClickQL connection handling ---------------------------
    if args.backend in {"clickhouse", "clickql", None}:
        ch_cfg: Dict[str, Any] = dict(project.get("clickhouse") or {})
        tables_cfg: Dict[str, Any] = dict(ch_cfg.get("tables") or {})
        if getattr(args, "clickhouse_dsn", None):
            ch_cfg["dsn"] = args.clickhouse_dsn
        if getattr(args, "clickhouse_host", None):
            ch_cfg["host"] = args.clickhouse_host
        if getattr(args, "clickhouse_port", None) is not None:
            ch_cfg["port"] = int(args.clickhouse_port)
        if getattr(args, "clickhouse_database", None):
            ch_cfg["database"] = args.clickhouse_database
        if getattr(args, "clickhouse_user", None):
            ch_cfg["user"] = args.clickhouse_user
        if getattr(args, "clickhouse_password", None) is not None:
            ch_cfg["password"] = args.clickhouse_password
        if getattr(args, "clickhouse_tokens_table", None):
            tables_cfg["tokens"] = args.clickhouse_tokens_table
        if getattr(args, "clickhouse_docs_table", None):
            tables_cfg["docs"] = args.clickhouse_docs_table
        if getattr(args, "clickhouse_sentences_table", None):
            tables_cfg["sentences"] = args.clickhouse_sentences_table
        if tables_cfg:
            ch_cfg["tables"] = tables_cfg
        if ch_cfg:
            project["clickhouse"] = ch_cfg

    # BlackLab connection handling ---------------------------------------
    if args.backend in {"blacklab", None}:
        opt_cfg = _parse_options(getattr(args, "options", None))
        bl_cfg: Dict[str, Any] = dict(project.get("blacklab") or {})
        if opt_cfg.get("blacklab_url"):
            bl_cfg["url"] = opt_cfg["blacklab_url"]
        if opt_cfg.get("blacklab_corpus"):
            bl_cfg["corpus"] = opt_cfg["blacklab_corpus"]
        if opt_cfg.get("blacklab_user"):
            bl_cfg["user"] = opt_cfg["blacklab_user"]
        if "blacklab_password" in opt_cfg:
            bl_cfg["password"] = opt_cfg["blacklab_password"]
        if opt_cfg.get("blacklab_field"):
            bl_cfg["field"] = opt_cfg["blacklab_field"]
        if bl_cfg:
            project["blacklab"] = bl_cfg

    # Native PMLTQ HTTP backend connection handling ----------------------
    if args.backend in {"pmltq", None}:
        opt_cfg = _parse_options(getattr(args, "options", None))
        pml_cfg: Dict[str, Any] = dict(project.get("pmltq_server") or {})
        if opt_cfg.get("pmltq_url"):
            pml_cfg["url"] = opt_cfg["pmltq_url"]
        if opt_cfg.get("pmltq_treebank"):
            pml_cfg["treebank"] = opt_cfg["pmltq_treebank"]
        elif args.backend == "pmltq" and getattr(args, "corpus", None):
            pml_cfg["treebank"] = args.corpus
        if opt_cfg.get("pmltq_token"):
            pml_cfg["token"] = opt_cfg["pmltq_token"]
        if opt_cfg.get("pmltq_cookie"):
            pml_cfg["cookie"] = opt_cfg["pmltq_cookie"]
        if opt_cfg.get("pmltq_username"):
            pml_cfg["username"] = opt_cfg["pmltq_username"]
        if "pmltq_password" in opt_cfg:
            pml_cfg["password"] = opt_cfg["pmltq_password"]
        if opt_cfg.get("pmltq_timeout"):
            pml_cfg["timeout"] = opt_cfg["pmltq_timeout"]
        if opt_cfg.get("pmltq_verify_tls"):
            pml_cfg["verify_tls"] = opt_cfg["pmltq_verify_tls"]
        if pml_cfg:
            project["pmltq_server"] = pml_cfg

    return project


def _infer_backend_for_cli(args: argparse.Namespace) -> str | None:
    """
    When ``--backend`` is omitted, infer the backend from ``--query-language`` /
    ``--corpus-format`` for kwic/query so users are not stuck on the configured
    default (often clickhouse) when they clearly asked for another engine.
    """
    op = getattr(args, "operation", None)
    if op not in ("kwic", "query"):
        return None
    ql = (getattr(args, "query_language", None) or "").strip().lower()
    cf = (getattr(args, "corpus_format", None) or "").strip().lower()
    if cf == "manatee" or ql in ("manatee-cql", "manatee"):
        return "manatee"
    if cf == "cwb" or ql in ("cwb-cql", "cqp"):
        return "cqp"
    if ql == "bcql" or cf == "blacklab":
        return "blacklab"
    if cf == "clickhouse" or ql in ("clickcql", "clickql", "pmltq", "clickpmltq"):
        return "clickql"
    if ql == "teitok" or cf == "xml":
        return "teitokxml"
    return None


def _resolve_backend(arg_backend: str | None, args: argparse.Namespace | None = None) -> str:
    """
    Resolve the backend to use for this CLI invocation.

    Order of precedence:
    1. Explicit --backend CLI argument.
    2. Inferred from query language / corpus format (kwic and query subcommands only).
    3. User configuration (settings.default_backend).
    4. Hard-coded default "clickhouse".
    """
    if arg_backend:
        return arg_backend
    if args is not None:
        inferred = _infer_backend_for_cli(args)
        if inferred:
            return inferred
    return get_default_backend()


def _parse_options(raw: list[str] | None) -> Dict[str, str]:
    """Parse ``-O key=value`` items into a dict passed to backends as extra options.

    Hyphens in keys are normalised to underscores so that ``-O indexed-backend=cqp``
    ends up as ``params["indexed_backend"]``, matching Python conventions.
    """
    opts: Dict[str, str] = {}
    if not raw:
        return opts
    for item in raw:
        if "=" not in item:
            opts[item.replace("-", "_")] = "true"
        else:
            k, v = item.split("=", 1)
            opts[k.replace("-", "_")] = v
    return opts


def _repair_shared_args_from_argv(args: argparse.Namespace, argv_tokens: list[str]) -> None:
    """
    Shared args are defined both on the top-level parser and on subparsers.
    Argparse can therefore lose values supplied before the subcommand when the
    subparser redefines the same destination with a default. Repair these from
    the raw argv so users can place shared flags before or after the subcommand.
    """
    value_map = {
        "--backend": "backend",
        "-b": "backend",
        "--project-root": "project_root",
        "-p": "project_root",
        "--project-json": "project_json",
        "--folder": "folder",
        "-f": "folder",
        "--teitok": "teitok",
        "--registry": "registry",
        "--corpus": "corpus",
        "--cqp-binary": "cqp_binary",
        "--cqp-encoding": "cqp_encoding",
        "--query-language": "query_language",
        "--query-lang": "query_language",
        "--corpus-format": "corpus_format",
        "--file-format": "corpus_format",
        "--clickhouse-dsn": "clickhouse_dsn",
        "--clickhouse-host": "clickhouse_host",
        "--clickhouse-port": "clickhouse_port",
        "--clickhouse-database": "clickhouse_database",
        "--clickhouse-user": "clickhouse_user",
        "--clickhouse-password": "clickhouse_password",
        "--clickhouse-tokens-table": "clickhouse_tokens_table",
        "--clickhouse-docs-table": "clickhouse_docs_table",
        "--clickhouse-sentences-table": "clickhouse_sentences_table",
    }
    bool_map = {
        "--api": "api",
        "--debug": "debug",
        "--background": "background",
        "--force": "force",
        "--staging": "staging",
    }
    append_map = {
        "--options": "options",
        "-O": "options",
    }

    i = 0
    while i < len(argv_tokens):
        token = argv_tokens[i]
        if token in bool_map:
            setattr(args, bool_map[token], True)
            i += 1
            continue
        if token in append_map:
            values = list(getattr(args, append_map[token], None) or [])
            if i + 1 < len(argv_tokens):
                values.append(argv_tokens[i + 1])
                setattr(args, append_map[token], values)
                i += 2
                continue
        if "=" in token:
            key, value = token.split("=", 1)
            if key in value_map:
                target = value_map[key]
                if target == "clickhouse_port":
                    value = int(value)
                setattr(args, target, value)
                i += 1
                continue
        if token in value_map and i + 1 < len(argv_tokens):
            target = value_map[token]
            value = argv_tokens[i + 1]
            if target == "clickhouse_port":
                value = int(value)
            setattr(args, target, value)
            i += 2
            continue
        i += 1


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    raw_argv = argv if argv is not None else sys.argv[1:]
    args = parser.parse_args(raw_argv)
    _repair_shared_args_from_argv(args, raw_argv)

    # Handle local config command without going through the backend dispatcher.
    if args.operation == "config":
        if args.set_default_backend:
            set_default_backend(args.set_default_backend)
            print(f"[flexicorp] Default backend set to: {args.set_default_backend}")
            print(f"[flexicorp] Configuration saved to: {get_config_file()}")
            return 0
        if args.set_auto_install_optional_deps is not None:
            enabled = args.set_auto_install_optional_deps == "true"
            set_auto_install_optional_deps(enabled)
            status = "enabled" if enabled else "disabled"
            print(f"[flexicorp] Auto-install optional deps: {status}")
            print(f"[flexicorp] Configuration saved to: {get_config_file()}")
            return 0
        if args.show:
            cfg_backend = get_default_backend()
            auto_install_optional_deps = get_auto_install_optional_deps()
            print(
                json.dumps(
                    {
                        "default_backend": cfg_backend,
                        "auto_install_optional_deps": bool(auto_install_optional_deps),
                        "config_file": str(get_config_file()),
                    },
                    indent=2,
                    ensure_ascii=False,
                )
            )
            return 0
        # If no flags were provided, show help for config.
        config_parser = next(
            sp for sp in parser._subparsers._group_actions[0].choices.values() if sp.prog.endswith(" config")  # type: ignore[attr-defined]
        )
        config_parser.print_help()
        return 0

    if args.operation == "env":
        project = _load_project(args)
        action = getattr(args, "env_action", "status")
        if action == "list":
            res = run_env_list()
        elif action == "check-corpus":
            res = run_env_check_corpus(
                project,
                backend_list=getattr(args, "env_backends", None),
                corpus_id=getattr(args, "corpus_id", None),
                env_config_path=getattr(args, "env_config", None),
            )
        else:
            res = run_env_status(
                project,
                backend_list=getattr(args, "env_backends", None),
                env_config_path=getattr(args, "env_config", None),
            )
        if getattr(args, "api", False):
            envelope = {
                "tool": "flexicorp",
                "version": 1,
                "success": bool(res.get("ok")),
                "asked": {
                    "argv": raw_argv,
                    "request": {
                        "operation": "env",
                        "action": action,
                        "project": project,
                        "params": {
                            "env_backends": getattr(args, "env_backends", None),
                            "corpus_id": getattr(args, "corpus_id", None),
                            "env_config": getattr(args, "env_config", None),
                        },
                    },
                },
                "done": {
                    "backend": "env",
                    "operation": "env",
                    "result": res,
                    "warnings": [],
                    "errors": [] if res.get("ok") else [f"Environment checks failed: {res.get('failed_backends', [])}"],
                },
            }
            json.dump(envelope, fp=sys.stdout, ensure_ascii=False, indent=2)
            print()
        else:
            json.dump(res, fp=sys.stdout, ensure_ascii=False, indent=2)
            print()
        return 0

    project = _load_project(args)

    if args.operation == "reindex-status":
        from .reindex_jobs.store import read_job

        root = Path(project.get("root") or args.folder or ".").resolve()
        job_id = str(getattr(args, "job_id", "") or "").strip()
        st = read_job(root, job_id) if job_id else None
        payload: Dict[str, Any]
        if st is None:
            payload = {
                "status": "unknown",
                "job_id": job_id,
                "message": "No such job (or project root mismatch).",
            }
            out_ok = False
        else:
            payload = {
                "status": st.status,
                "job_id": st.job_id,
                "progress": st.progress,
                "error": st.error,
                "result": st.result,
            }
            out_ok = True
        if getattr(args, "api", False):
            envelope = {
                "tool": "flexicorp",
                "version": 1,
                "success": out_ok,
                "asked": {"argv": raw_argv, "request": {"operation": "reindex-status", "job_id": job_id}},
                "done": {
                    "backend": "flexencoder",
                    "operation": "reindex_status",
                    "result": payload,
                    "warnings": [],
                    "errors": [] if out_ok else ["job not found"],
                },
            }
            json.dump(envelope, fp=sys.stdout, ensure_ascii=False, indent=2)
            print()
        else:
            json.dump(
                {"ok": out_ok, "backend": "flexencoder", "operation": "reindex_status", "result": payload},
                fp=sys.stdout,
                ensure_ascii=False,
                indent=2,
            )
            print()
        return 0 if out_ok else 1

    params: Dict[str, Any] = {}
    # Merge backend-specific -O key=value options into params (backends inspect as needed).
    params.update(_parse_options(getattr(args, "options", None)))

    if args.operation == "list-docs":
        params["limit"] = args.limit
        params["offset"] = args.offset
        if getattr(args, "verbose", False):
            params["verbose"] = True
    elif args.operation == "kwic":
        # For CQP backend this is typically a raw CQP query.
        # The ``query`` subcommand is registered as an alias of ``kwic``; when
        # the user types ``flexicorp query``, argparse sets ``operation`` to
        # ``query`` (see the separate branch below).
        if args.query:
            params["query"] = args.query
        elif getattr(args, "pattern", None):
            params["query"] = args.pattern
        elif args.field and args.value:
            # ClickHouse-style structured query
            params["query"] = {"field": args.field, "value": args.value}
        params["window"] = args.window
        params["limit"] = args.limit
        # Unified query API (pagination, ClickQL extras): needed when ``kwic`` is
        # invoked via the ``query`` alias and for backends that implement ``query``.
        params["start"] = getattr(args, "start", 0)
        params["max"] = getattr(args, "limit", 50)
        if getattr(args, "qid", None):
            params["qid"] = args.qid
        if getattr(args, "refresh_cache", False):
            params["refresh_cache"] = True
        if getattr(args, "extract_fragments", False):
            params["extract_fragments"] = True
        if getattr(args, "context_scope", None):
            params["context_scope"] = args.context_scope
        if getattr(args, "context_format", None):
            params["context_format"] = args.context_format
        if getattr(args, "context_level", None):
            params["context_level"] = args.context_level
        if getattr(args, "sql", None):
            params["sql"] = args.sql
        if getattr(args, "count_sql", None):
            params["count_sql"] = args.count_sql
        if getattr(args, "cache_key", None):
            params["cache_key"] = args.cache_key
        if getattr(args, "show_sql", False):
            params["include_sql"] = True
    elif args.operation == "query":
        # ``flexicorp query`` (alias of kwic): same params as the kwic branch.
        if args.query:
            params["query"] = args.query
        elif getattr(args, "pattern", None):
            params["query"] = args.pattern
        elif args.field and args.value:
            params["query"] = {"field": args.field, "value": args.value}
        else:
            params["query"] = ""
        params["start"] = getattr(args, "start", 0)
        params["max"] = getattr(args, "limit", 50)
        params["window"] = args.window
        params["limit"] = args.limit
        if getattr(args, "qid", None):
            params["qid"] = args.qid
        if getattr(args, "refresh_cache", False):
            params["refresh_cache"] = True
        if getattr(args, "extract_fragments", False):
            params["extract_fragments"] = True
        if getattr(args, "context_scope", None):
            params["context_scope"] = args.context_scope
        if getattr(args, "context_format", None):
            params["context_format"] = args.context_format
        if getattr(args, "context_level", None):
            params["context_level"] = args.context_level
        if getattr(args, "sql", None):
            params["sql"] = args.sql
        if getattr(args, "count_sql", None):
            params["count_sql"] = args.count_sql
        if getattr(args, "cache_key", None):
            params["cache_key"] = args.cache_key
        if getattr(args, "show_sql", False):
            params["include_sql"] = True
    elif args.operation == "freq":
        if getattr(args, "query", None):
            params["query"] = args.query
        elif getattr(args, "pattern", None):
            params["query"] = args.pattern
        params["field"] = args.field
        params["limit"] = args.limit
        params["offset"] = args.offset
    elif args.operation == "reindex":
        if getattr(args, "input_folder", None):
            params["input_folder"] = args.input_folder
        if getattr(args, "pattributes", None):
            params["pattributes"] = list(args.pattributes)
        if getattr(args, "sattributes", None):
            params["sattributes"] = list(args.sattributes)
        if getattr(args, "verbose", False):
            params["verbose"] = True
        if getattr(args, "reindex_backends", None):
            raw = args.reindex_backends.strip()
            params["reindex_backends"] = [b.strip() for b in raw.split(",") if b.strip()]
    elif args.operation == "info":
        if getattr(args, "info_topic", None):
            params["topic"] = args.info_topic
    elif args.operation == "highlight":
        params["snippet"] = getattr(args, "snippet", "") or ""
        params["format"] = getattr(args, "format", "tokens") or "tokens"
        if getattr(args, "query_language", None):
            params["query_language"] = args.query_language
            params["query_lang"] = args.query_language
    elif args.operation == "daemon":
        params["action"] = getattr(args, "action", "status")
        if getattr(args, "database", None):
            params["database"] = args.database

    backend = _resolve_backend(args.backend, args)
    if args.operation == "daemon" and not args.backend:
        backend = "clickhouse"

    # Global debug flag propagated into params for backends that support it.
    if getattr(args, "debug", False):
        params["debug"] = True
    if getattr(args, "query_language", None):
        params["query_language"] = args.query_language
        params["query_lang"] = args.query_language
    elif args.operation in ("kwic", "query") and backend == "manatee":
        params["query_language"] = "manatee-cql"
        params["query_lang"] = "manatee-cql"

    operation_name = args.operation.replace("-", "_")
    # ``kwic`` subcommand dispatches ``operation`` kwic, but backends such as
    # manatee/flexi/blacklab implement the unified ``query`` API only.
    if operation_name in ("kwic", "query") and backend in ("manatee", "flexi", "blacklab", "pmltq"):
        operation_name = "query"

    req = {
        "version": 1,
        "backend": backend,
        "operation": operation_name,
        "project": project,
        "params": params,
    }
    if args.operation == "reindex" and getattr(args, "background", False):
        from .reindex_jobs.runner import enqueue_background_reindex

        p = dict(req.get("params") or {})
        if not p.get("reindex_backends"):
            rb_arg = getattr(args, "reindex_backends", None)
            if isinstance(rb_arg, str) and rb_arg.strip():
                p["reindex_backends"] = [b.strip() for b in rb_arg.split(",") if b.strip()]
            elif backend in ("cqp", "clickhouse", "clickql", "manatee", "pmltq"):
                p["reindex_backends"] = [backend]
        req = {**req, "params": p}

        root = Path(project.get("root") or args.folder or ".").resolve()
        ok, payload, enqueue_errors = enqueue_background_reindex(
            req,
            project_root=root,
            force=bool(getattr(args, "force", False)),
            staging=bool(getattr(args, "staging", False)),
        )
        if getattr(args, "api", False):
            envelope = {
                "tool": "flexicorp",
                "version": 1,
                "success": ok,
                "asked": {"argv": raw_argv, "request": req},
                "done": {
                    "backend": "flexencoder",
                    "operation": "reindex",
                    "result": payload,
                    "warnings": [],
                    "errors": enqueue_errors,
                },
            }
            json.dump(envelope, fp=sys.stdout, ensure_ascii=False, indent=2)
            print()
        else:
            json.dump(
                {
                    "ok": ok,
                    "backend": "flexencoder",
                    "operation": "reindex",
                    "result": payload,
                    "errors": enqueue_errors,
                    "warnings": [],
                },
                fp=sys.stdout,
                ensure_ascii=False,
                indent=2,
            )
            print()
        return 0 if ok else 1

    # In --api mode we must guarantee stdout ends up as a single JSON document,
    # so native libraries (Manatee _manatee.so, certain CWB utilities) can't
    # corrupt it with status messages. See _isolate_stdout_for_api docstring.
    if getattr(args, "api", False):
        with _isolate_stdout_for_api():
            res = handle_request(req)
    else:
        res = handle_request(req)
    res = _maybe_brief_info_backends(res, args)
    if getattr(args, "api", False):
        envelope = {
            "tool": "flexicorp",
            "version": 1,
            "success": bool(res.get("ok")),
            "asked": {
                "argv": raw_argv,
                "request": req,
            },
            "done": {
                "backend": res.get("backend"),
                "operation": res.get("operation"),
                "result": res.get("result"),
                "warnings": res.get("warnings", []),
                "errors": res.get("errors", []),
            },
        }
        json.dump(envelope, fp=sys.stdout, ensure_ascii=False, indent=2)
        print()
    else:
        json.dump(res, fp=sys.stdout, ensure_ascii=False, indent=2)
        print()
    return 0


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())


