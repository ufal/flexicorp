from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Protocol, runtime_checkable
import importlib
import json
import os
import shutil
import subprocess
import sys
import time

from .clickhouse_errors import format_clickhouse_error_message
from .config import get_clickhouse_config
from .query_policy import apply_query_policy


FlexiRequest = Dict[str, Any]
FlexiResponse = Dict[str, Any]


@runtime_checkable
class CorpusBackend(Protocol):
    """
    Common interface for all corpus backends.

    Backends should accept a validated FlexiRequest and return only the
    operation-specific result payload as a dict. The core dispatcher wraps
    this into a FlexiResponse with ok/errors/warnings.
    """

    name: str  # e.g. "clickhouse", "cqp"

    def descriptor(self) -> Dict[str, Any]:
        ...

    def capabilities(self) -> Dict[str, bool]:
        ...

    # Required operations
    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        ...

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        ...

    # Optional operations; default implementations in concrete backends
    def kwic(self, req: FlexiRequest) -> Dict[str, Any]:  # pragma: no cover - interface only
        raise NotImplementedError

    def freq(self, req: FlexiRequest) -> Dict[str, Any]:  # pragma: no cover - interface only
        raise NotImplementedError

    def info(self, req: FlexiRequest) -> Dict[str, Any]:  # pragma: no cover - interface only
        raise NotImplementedError

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:  # pragma: no cover - interface only
        raise NotImplementedError

    def raw_query(self, req: FlexiRequest) -> Dict[str, Any]:  # pragma: no cover - interface only
        raise NotImplementedError

    def query(self, req: FlexiRequest) -> Dict[str, Any]:  # pragma: no cover - interface only
        """Corpus query with pagination and optional fragment extraction.
        See dev/QUERY-API-DESIGN.md for request/response contract."""
        raise NotImplementedError


@dataclass
class BackendRegistry:
    backends: Dict[str, CorpusBackend] = field(default_factory=dict)

    def register(self, backend: CorpusBackend) -> None:
        key = backend.name.lower()
        self.backends[key] = backend

    def get(self, name: str) -> CorpusBackend | None:
        return self.backends.get(name.lower())

    def list_capabilities(self) -> Dict[str, Dict[str, bool]]:
        return {name: b.capabilities() for name, b in self.backends.items()}


BACKENDS = BackendRegistry()

BUILTIN_BACKEND_MODULES: Dict[str, str] = {
    "blacklab": "flexicorp.backends.blacklab",
    "clickhouse": "flexicorp.backends.clickhouse",
    "clickql": "flexicorp.backends.clickhouse",
    "cqp": "flexicorp.backends.cqp",
    "flexi": "flexicorp.backends.flexi",
    "manatee": "flexicorp.backends.manatee_backend",
    "pmltq": "flexicorp.backends.pmltq_backend",
    "teitokxml": "flexicorp.backends.teitokxml",
}


def register_backend(backend: CorpusBackend) -> None:
    """
    Register a backend instance.

    Backends should call this at import time, e.g.:

        backend = ClickHouseBackend()
        register_backend(backend)
    """

    BACKENDS.register(backend)


def ensure_backend_loaded(name: str) -> CorpusBackend | None:
    """Load a built-in backend module on demand and return the backend instance."""
    key = (name or "").strip().lower()
    if not key:
        return None

    backend = BACKENDS.get(key)
    if backend is not None:
        return backend

    module_name = BUILTIN_BACKEND_MODULES.get(key)
    if module_name is None:
        return None

    importlib.import_module(module_name)
    return BACKENDS.get(key)


def available_backend_names() -> List[str]:
    """Return known backend ids, including lazy-loadable built-ins."""
    names = set(BACKENDS.backends)
    names.update(BUILTIN_BACKEND_MODULES)
    return sorted(names)


def backend_descriptor(backend: CorpusBackend) -> Dict[str, Any]:
    """
    Return a normalized descriptor for a backend.
    """
    desc: Dict[str, Any] = {}
    raw = getattr(backend, "descriptor", None)
    if callable(raw):
        try:
            desc = dict(raw() or {})
        except Exception:
            desc = {}
    return {
        "id": str(desc.get("id") or backend.name),
        "label": str(desc.get("label") or backend.name),
        "supported_query_languages": list(desc.get("supported_query_languages") or []),
        "supported_corpus_formats": list(desc.get("supported_corpus_formats") or []),
        "default_query_language": desc.get("default_query_language"),
        "default_corpus_format": desc.get("default_corpus_format"),
        "default_selection_reason": desc.get("default_selection_reason"),
        "notes": desc.get("notes"),
    }


def _normalize_request(req: FlexiRequest) -> FlexiRequest:
    """Apply basic defaults and sanity checks to an incoming request dict."""
    if "version" not in req:
        req["version"] = 1
    if "backend" not in req:
        # Default backend can be made configurable later; ClickHouse is primary.
        req["backend"] = "clickhouse"
    if "project" not in req:
        req["project"] = {}
    if "params" not in req:
        req["params"] = {}
    return req


def _make_error_response(
    backend: str,
    operation: str,
    message: str,
    *,
    warnings: List[str] | None = None,
) -> FlexiResponse:
    return {
        "ok": False,
        "backend": backend,
        "operation": operation,
        "result": None,
        "warnings": warnings or [],
        "errors": [message],
    }


def _handle_highlight(req: FlexiRequest) -> FlexiResponse:
    """
    Highlight a query snippet by query language. Does not use a corpus backend.
    """
    params = dict(req.get("params") or {})
    snippet = (params.get("snippet") or params.get("query") or "").strip()
    query_lang = (params.get("query_language") or params.get("query_lang") or "cwb-cql").strip().lower()
    fmt = (params.get("format") or "tokens").strip().lower()
    if fmt not in ("tokens", "html"):
        fmt = "tokens"
    backend_name = str(req.get("backend", "flexi"))
    operation = "highlight"
    if not snippet:
        return _make_error_response(
            backend=backend_name,
            operation=operation,
            message="Highlight requires params.snippet (or params.query) with a non-empty query string.",
        )
    try:
        if query_lang in ("cwb-cql", "cwb", "cql"):
            from .querylang.cwb_cql import highlight_cwb_cql
            result = highlight_cwb_cql(snippet, format=fmt, validate=True)
        elif query_lang in ("manatee-cql", "manatee"):
            from .querylang.manatee_cql import highlight_manatee_cql
            result = highlight_manatee_cql(snippet, format=fmt, validate=True)
        elif query_lang in ("clickcql", "clickql"):
            from .clickcql import highlight_clickcql
            result = highlight_clickcql(
                snippet,
                format=fmt,
                validate=True,
                project=dict(req.get("project") or {}),
            )
        elif query_lang in ("bcql", "corpusql"):
            from .querylang.bcql import highlight_bcql
            result = highlight_bcql(snippet, format=fmt, validate=True)
        elif query_lang in ("pmltq", "clickpmltq"):
            from .pmltq import highlight_pmltq
            result = highlight_pmltq(
                snippet,
                format=fmt,
                validate=True,
                project=dict(req.get("project") or {}),
            )
        elif query_lang in ("pando-cql", "pando"):
            from .querylang.pando_cql import highlight_pando_cql
            result = highlight_pando_cql(snippet, format=fmt, validate=True)
        else:
            return _make_error_response(
                backend=backend_name,
                operation=operation,
                message=f"Unknown or unsupported query language for highlight: {query_lang!r}. Supported: cwb-cql, manatee-cql, clickcql, bcql, pmltq, pando-cql.",
            )
        return {
            "ok": True,
            "backend": backend_name,
            "operation": operation,
            "result": result,
            "warnings": [],
            "errors": [],
        }
    except Exception as e:
        return _make_error_response(
            backend=backend_name,
            operation=operation,
            message=str(e),
        )


def _handle_info(req: FlexiRequest) -> FlexiResponse | None:
    """
    Handle shared info topics that are not tied to a single backend instance.
    """
    params = dict(req.get("params") or {})
    topic = str(params.get("topic") or "corpus").strip().lower()
    if topic != "backends":
        return None

    from .overview import build_backend_overview

    backend_name = str(req.get("backend", "clickhouse"))
    operation = "info"
    try:
        result = build_backend_overview(dict(req.get("project") or {}))
        result["topic"] = "backends"
        return {
            "ok": True,
            "backend": backend_name,
            "operation": operation,
            "result": result,
            "warnings": [],
            "errors": [],
        }
    except Exception as e:
        return _make_error_response(
            backend=backend_name,
            operation=operation,
            message=str(e),
        )


def _flexicorp_scripts_flexencoder() -> Optional[str]:
    """
    Last-resort: <repo>/scripts/flexencoder beside the ``flexicorp`` package
    (``flexicorp/core.py`` -> parent.parent / scripts/flexencoder).
    Override with FLEXICORP_ROOT or FLEXICORP_HOME pointing at the repository root.

    May be the wrong OS/arch (e.g. Linux binary in a macOS checkout); callers should
    prefer ``PATH`` / Homebrew before this (see ``_find_flexencoder``).
    """
    for key in ("FLEXICORP_ROOT", "FLEXICORP_HOME"):
        raw = os.environ.get(key, "").strip()
        if not raw:
            continue
        cand = Path(raw).expanduser() / "scripts" / "flexencoder"
        if cand.is_file() and os.access(cand, os.X_OK):
            return str(cand.resolve())
    try:
        pkg_dir = Path(__file__).resolve().parent
        repo_root = pkg_dir.parent
        cand = repo_root / "scripts" / "flexencoder"
        if cand.is_file() and os.access(cand, os.X_OK):
            return str(cand.resolve())
    except Exception:
        pass
    return None


def _find_flexencoder(project_root: Path) -> Optional[str]:
    """Locate flexencoder binary for multi-backend reindex. Returns path or None."""
    scripts = project_root / "Scripts" / "flexencoder"
    if scripts.is_file() and os.access(scripts, os.X_OK):
        return str(scripts)
    tt_root = os.environ.get("TT_ROOT")
    if tt_root:
        cand = Path(tt_root) / "Scripts" / "flexencoder"
        if cand.is_file() and os.access(cand, os.X_OK):
            return str(cand)
    # Prefer PATH / Homebrew before <repo>/scripts/flexencoder: the committed script may be
    # built for another OS (e.g. Linux ELF) and raises Exec format error on macOS.
    which = shutil.which("flexencoder")
    if which:
        return which
    for p in ("/opt/homebrew/bin/flexencoder", "/usr/local/bin/flexencoder", "/usr/bin/flexencoder"):
        cand = Path(p)
        if cand.is_file() and os.access(cand, os.X_OK):
            return str(cand)
    dev = _flexicorp_scripts_flexencoder()
    if dev:
        return dev
    return None


def _run_flexencoder_reindex(
    project_root: Path,
    settings_path: Path,
    output_cwb: Optional[Path] = None,
    output_clickhouse: Optional[Path] = None,
    output_pando: Optional[Path] = None,
    output_xidx: Optional[Path] = None,
    debug: bool = False,
) -> Dict[str, Any]:
    """Run flexencoder with optional CWB/ClickHouse/Pando outputs. Returns result dict."""
    flexencoder_bin = _find_flexencoder(project_root)
    if not flexencoder_bin:
        raise RuntimeError(
            "flexencoder not found (required for multi-backend reindex). "
            "Install under project Scripts/, TT_ROOT/Scripts/, flexicorp scripts/flexencoder, FLEXICORP_ROOT, or PATH."
        )
    cmd = [
        flexencoder_bin,
        "--project-root", str(project_root),
        "--settings", str(settings_path),
    ]
    if output_cwb:
        cmd.extend(["--output", str(output_cwb)])
    if output_clickhouse:
        cmd.extend(["--output-clickhouse", str(output_clickhouse)])
    if output_pando:
        cmd.extend(["--output-pando", str(output_pando)])
    if output_xidx:
        cmd.extend(["--output-xidx", str(output_xidx)])
    proc = subprocess.run(
        cmd,
        cwd=str(project_root),
        text=True,
        capture_output=True,
        check=False,
    )
    if debug and proc.stdout:
        print(proc.stdout, file=sys.stderr)
    if debug and proc.stderr:
        print(proc.stderr, file=sys.stderr)
    if proc.returncode != 0:
        err_text = (proc.stderr or proc.stdout or "").strip()
        if output_xidx and "--output-xidx" in err_text and "Unknown argument" in err_text:
            raise RuntimeError(
                "flexencoder does not support --output-xidx yet, so staging-safe xidx cannot be guaranteed. "
                "Please rebuild/install the updated flexencoder binary from this checkout."
            )
        raise RuntimeError(
            f"flexencoder failed with exit code {proc.returncode}: {err_text}"
        )
    return {
        "engine": "flexencoder",
        "binary": str(flexencoder_bin),
        "output_cwb": str(output_cwb) if output_cwb else None,
        "output_clickhouse": str(output_clickhouse) if output_clickhouse else None,
        "output_pando": str(output_pando) if output_pando else None,
        "output_xidx": str(output_xidx) if output_xidx else None,
    }


def _swap_staged_trees_atomically(
    swap_items: List[Dict[str, Any]],
) -> List[Dict[str, str]]:
    """
    Swap multiple staged trees in one transaction-like operation.

    Each item must contain: {"label": str, "staging": Path, "live": Path}.
    On failure, attempts rollback for all items already touched.
    Returns metadata for successful swaps.
    """
    ts = int(time.time())
    plan: List[Dict[str, Any]] = []
    for item in swap_items:
        label = str(item["label"])
        staging = Path(item["staging"])
        live = Path(item["live"])
        if not staging.is_dir():
            raise RuntimeError(f"Staging {label} directory missing: {staging}")
        live.parent.mkdir(parents=True, exist_ok=True)
        backup = live.parent / f"{label}.bak_before_reindex.{ts}"
        plan.append(
            {
                "label": label,
                "staging": staging,
                "live": live,
                "backup": backup,
                "had_live": live.exists(),
            }
        )

    moved_backups: List[Dict[str, Any]] = []
    moved_staging: List[Dict[str, Any]] = []
    try:
        for step in plan:
            if step["had_live"]:
                shutil.move(str(step["live"]), str(step["backup"]))
                moved_backups.append(step)
        for step in plan:
            shutil.move(str(step["staging"]), str(step["live"]))
            moved_staging.append(step)
    except Exception as e:
        rollback_errors: List[str] = []
        for step in reversed(moved_staging):
            try:
                if step["live"].exists():
                    shutil.move(str(step["live"]), str(step["staging"]))
            except Exception as re:
                rollback_errors.append(f"restore_staging:{step['label']}={re}")
        for step in reversed(moved_backups):
            try:
                if step["backup"].exists() and not step["live"].exists():
                    shutil.move(str(step["backup"]), str(step["live"]))
            except Exception as re:
                rollback_errors.append(f"restore_live:{step['label']}={re}")
        if rollback_errors:
            raise RuntimeError(f"staging swap failed: {e}; rollback issues: {'; '.join(rollback_errors)}")
        raise RuntimeError(f"staging swap failed: {e}")

    for step in moved_backups:
        try:
            if step["backup"].is_dir():
                shutil.rmtree(step["backup"], ignore_errors=True)
        except Exception:
            pass

    return [
        {"label": str(step["label"]), "staging": str(step["staging"]), "live": str(step["live"])}
        for step in plan
    ]


def _handle_reindex_multi(req: FlexiRequest) -> FlexiResponse:
    """
    Run reindex for multiple backends in one go.

    - Uses flexencoder for CQP/ClickHouse/Pando (single extraction pass).
    - Uses native Manatee backend reindex for Manatee output (encodevert/mkstats/mksizes path).
    - Runs BlackLab reindex separately if requested.
    - Runs native PML-TQ export/import when ``pmltq`` is requested (flexencoder JSONL →
      ``tmp/pmltq-export``, optional ``pmltq_import_cmd``).

    When ``params.reindex_staging`` is true (typically with ``reindex_job_id``), flexencoder
    writes under ``tmp/flexicorp-reindex-staging/<job_id>/``; staged xidx/CWB/Pando trees can then be swapped into ``project/xidx``, ``project/cqp``,
    and ``project/pando`` before ClickHouse JSONL is loaded into the live database.
    """
    params = req.get("params") or {}
    backends_raw = params.get("reindex_backends")
    if isinstance(backends_raw, str):
        backends = [b.strip() for b in backends_raw.split(",") if b.strip()]
    elif isinstance(backends_raw, list):
        backends = [str(b).strip().lower() for b in backends_raw if b]
    else:
        backends = []
    if not backends:
        return _make_error_response(
            backend="flexencoder",
            operation="reindex",
            message="reindex_backends is required (list or comma-separated, e.g. cqp,clickhouse,pando,manatee).",
        )

    project = dict(req.get("project") or {})
    root_str = project.get("root") or "."
    root_dir = Path(root_str).resolve()
    if not root_dir.is_dir():
        return _make_error_response(
            backend="flexencoder",
            operation="reindex",
            message=f"Project root not found: {root_dir}",
        )

    tmp_settings = root_dir / "tmp" / "cqpsettings.xml"
    resources_settings = root_dir / "Resources" / "settings.xml"
    settings_path = tmp_settings if tmp_settings.is_file() else resources_settings
    if not settings_path.is_file():
        return _make_error_response(
            backend="flexencoder",
            operation="reindex",
            message=f"TEITOK settings not found at {tmp_settings} or {resources_settings}.",
        )

    debug = bool(params.get("debug"))
    results: List[Dict[str, Any]] = []
    errors: List[str] = []

    use_staging = bool(params.get("reindex_staging"))
    staging_dir: Optional[Path] = None
    if use_staging:
        raw_sd = params.get("reindex_staging_dir")
        if isinstance(raw_sd, str) and raw_sd.strip():
            staging_dir = Path(raw_sd.strip()).resolve()
        else:
            job_id = str(params.get("reindex_job_id") or "").strip()
            if job_id:
                staging_dir = (root_dir / "tmp" / "flexicorp-reindex-staging" / job_id).resolve()
            else:
                use_staging = False
    if use_staging and staging_dir is not None:
        try:
            if staging_dir.exists():
                shutil.rmtree(staging_dir, ignore_errors=True)
            staging_dir.mkdir(parents=True, exist_ok=True)
        except Exception as e:
            return _make_error_response(
                backend="flexencoder",
                operation="reindex",
                message=f"Could not prepare reindex staging directory {staging_dir}: {e}",
            )

    flexencoder_backends = [b for b in backends if b in ("cqp", "clickhouse", "clickql", "pando")]
    flex_out_base = staging_dir if use_staging and staging_dir is not None else root_dir

    if flexencoder_backends:
        # CWB/Pando output and ClickHouse JSONL can share the same flexencoder extraction pass.
        output_cwb = (flex_out_base / "cqp") if "cqp" in flexencoder_backends else None
        needs_jsonl = any(b in ("clickhouse", "clickql") for b in flexencoder_backends)
        output_clickhouse = (flex_out_base / "tmp" / "clickhouse") if needs_jsonl else None
        output_pando = (flex_out_base / "pando") if "pando" in flexencoder_backends else None
        output_xidx = (flex_out_base / "xidx") if (use_staging and staging_dir is not None) else None
        defer_ch_load = bool(use_staging and needs_jsonl)
        if output_clickhouse:
            output_clickhouse.mkdir(parents=True, exist_ok=True)
        try:
            r = _run_flexencoder_reindex(
                root_dir,
                settings_path,
                output_cwb=output_cwb,
                output_clickhouse=output_clickhouse,
                output_pando=output_pando,
                output_xidx=output_xidx,
                debug=debug,
            )
            results.append(r)
            if (
                output_clickhouse
                and r.get("output_clickhouse")
                and not defer_ch_load
            ):
                jsonl_dir = Path(r["output_clickhouse"])
                if any(b in ("clickhouse", "clickql") for b in flexencoder_backends):
                    from .clickhouse_loader import load_jsonl_into_clickhouse

                    load_result = load_jsonl_into_clickhouse(
                        jsonl_dir,
                        {"root": str(root_dir), **project},
                    )
                    if not load_result.get("ok"):
                        errors.append(load_result.get("error", "ClickHouse load failed"))
                    else:
                        r["clickhouse_loaded"] = load_result.get("loaded", {})
        except Exception as e:
            errors.append(f"flexencoder: {e}")

    staging_cqp = staging_dir / "cqp" if staging_dir is not None else None
    live_cqp = root_dir / "cqp"
    staging_pando = staging_dir / "pando" if staging_dir is not None else None
    live_pando = root_dir / "pando"
    staging_xidx = staging_dir / "xidx" if staging_dir is not None else None
    live_xidx = root_dir / "xidx"

    for name in backends:
        if name == "blacklab":
            try:
                bl = ensure_backend_loaded("blacklab")
                if bl and hasattr(bl, "reindex"):
                    req_single = {**req, "backend": "blacklab"}
                    r = bl.reindex(req_single)
                    results.append({"backend": "blacklab", **r})
            except Exception as e:
                errors.append(f"blacklab: {e}")
        elif name == "manatee":
            try:
                man = ensure_backend_loaded("manatee")
                if man and hasattr(man, "reindex"):
                    req_single = {**req, "backend": "manatee"}
                    r = man.reindex(req_single)
                    results.append({"backend": "manatee", **r})
            except Exception as e:
                errors.append(f"manatee: {e}")
        elif name == "pmltq":
            try:
                pm = ensure_backend_loaded("pmltq")
                if pm and hasattr(pm, "reindex"):
                    req_single = {**req, "backend": "pmltq"}
                    r = pm.reindex(req_single)
                    results.append({"backend": "pmltq", **r})
            except Exception as e:
                errors.append(f"pmltq: {e}")

    if errors:
        message = f"Reindex for {', '.join(backends)} finished."
        message += " " + "; ".join(errors)
        return {
            "ok": False,
            "backend": "flexencoder",
            "operation": "reindex",
            "result": {
                "reindex_backends": backends,
                "results": results,
                "message": message,
            },
            "warnings": [],
            "errors": errors,
        }

    # Transactional staging swap + deferred ClickHouse load after other tools consumed staged CWB.
    if not errors and use_staging and staging_dir is not None:
        swap_items: List[Dict[str, Any]] = []
        if staging_xidx is not None and staging_xidx.is_dir():
            swap_items.append({"label": "xidx", "staging": staging_xidx, "live": live_xidx})
        if staging_cqp is not None and staging_cqp.is_dir() and "cqp" in flexencoder_backends:
            swap_items.append({"label": "cqp", "staging": staging_cqp, "live": live_cqp})
        if staging_pando is not None and staging_pando.is_dir() and "pando" in flexencoder_backends:
            swap_items.append({"label": "pando", "staging": staging_pando, "live": live_pando})
        if swap_items:
            try:
                swapped = _swap_staged_trees_atomically(swap_items)
                for step in swapped:
                    results.append(
                        {
                            "reindex_step": f"swap_{step['label']}",
                            "staging": step["staging"],
                            "live": step["live"],
                        }
                    )
                if any(str(s.get("label")) == "cqp" for s in swapped):
                    from .cqp_registry_fixup import rewrite_cqp_registries_after_reindex_swap

                    try:
                        n_reg = rewrite_cqp_registries_after_reindex_swap(live_cqp, staging_dir)
                        if n_reg:
                            results.append(
                                {
                                    "reindex_step": "cqp_registry_home_fixup",
                                    "registry_files_updated": n_reg,
                                    "live_cqp": str(live_cqp),
                                }
                            )
                    except Exception as e:
                        errors.append(f"cqp_registry_home_fixup: {e}")
            except Exception as e:
                errors.append(f"staging_swap: {e}")

    needs_jsonl = any(b in ("clickhouse", "clickql") for b in flexencoder_backends)
    if (
        not errors
        and use_staging
        and staging_dir is not None
        and needs_jsonl
        and flexencoder_backends
    ):
        jsonl_dir = staging_dir / "tmp" / "clickhouse"
        if jsonl_dir.is_dir():
            try:
                from .clickhouse_loader import load_jsonl_into_clickhouse

                load_result = load_jsonl_into_clickhouse(
                    jsonl_dir,
                    {"root": str(root_dir), **project},
                )
                if load_result.get("ok"):
                    for item in results:
                        if isinstance(item, dict) and item.get("output_clickhouse"):
                            item["clickhouse_loaded"] = load_result.get("loaded", {})
                            break
                    results.append({"reindex_step": "clickhouse_load", "loaded": load_result.get("loaded", {})})
                else:
                    errors.append(load_result.get("error", "ClickHouse load failed"))
            except Exception as e:
                errors.append(f"clickhouse_load: {e}")

    message = f"Reindex for {', '.join(backends)} finished."
    if errors:
        message += " " + "; ".join(errors)
    return {
        "ok": len(errors) == 0,
        "backend": "flexencoder",
        "operation": "reindex",
        "result": {
            "reindex_backends": backends,
            "results": results,
            "message": message,
            **({"reindex_staging": str(staging_dir)} if staging_dir is not None else {}),
        },
        "warnings": [],
        "errors": errors,
    }


def handle_request(req: FlexiRequest) -> FlexiResponse:
    """
    Main dispatcher used by TEITOK / EasyCorp and the CLI.

    Expects a request dict compatible with the structure documented in
    FLEXICORP-CURSOR-GUIDE.md and FLEXICORP-DESIGN.md.
    """
    req = _normalize_request(dict(req))  # work on a shallow copy
    backend_name = str(req.get("backend", "clickhouse"))
    operation = str(req.get("operation", "status"))

    if operation == "highlight":
        return _handle_highlight(req)

    if operation == "info":
        shared_info = _handle_info(req)
        if shared_info is not None:
            return shared_info

    if operation == "reindex":
        params = dict(req.get("params") or {})
        reindex_backends = params.get("reindex_backends")
        if isinstance(reindex_backends, str) and reindex_backends.strip():
            reindex_backends = [b.strip() for b in reindex_backends.split(",") if b.strip()]
        if isinstance(reindex_backends, list) and len(reindex_backends) > 0:
            return _handle_reindex_multi({**req, "params": {**params, "reindex_backends": reindex_backends}})
        if backend_name in ("cqp", "clickhouse", "clickql", "manatee", "pando", "pmltq"):
            return _handle_reindex_multi({**req, "params": {**params, "reindex_backends": [backend_name]}})

    req, query_policy_meta = apply_query_policy(req)
    backend_name = str(req.get("backend", backend_name))
    backend = ensure_backend_loaded(backend_name)
    if backend is None:
        return _make_error_response(
            backend=backend_name,
            operation=operation,
            message=f"Unknown backend '{backend_name}'. Available: {', '.join(available_backend_names())}",
        )

    # Resolve operation method on backend
    op_method = getattr(backend, operation, None)
    if op_method is None or not callable(op_method):
        return _make_error_response(
            backend=backend_name,
            operation=operation,
            message=f"Backend '{backend_name}' does not support operation '{operation}'.",
        )

    try:
        result = op_method(req)
        if operation == "query" and isinstance(result, dict):
            result = {
                **result,
                "request_role": query_policy_meta.request_role,
                "input_query_mode": query_policy_meta.input_query_mode,
                "query_mode": query_policy_meta.query_mode,
                "query_sanitized": query_policy_meta.query_sanitized,
                "sanitized_query": query_policy_meta.sanitized_query if query_policy_meta.query_sanitized else None,
                "suggested_tab": query_policy_meta.suggested_tab,
            }
        return {
            "ok": True,
            "backend": backend_name,
            "operation": operation,
            "result": result,
            "warnings": [],
            "errors": [],
        }
    except NotImplementedError as e:
        return _make_error_response(
            backend=backend_name,
            operation=operation,
            message=str(e) or f"Operation '{operation}' not implemented for backend '{backend_name}'.",
        )
    except Exception as e:  # pragma: no cover - defensive
        if backend_name in {"clickhouse", "clickql"}:
            project = dict(req.get("project") or {})
            ch = get_clickhouse_config(project)
            if ch is not None:
                msg = format_clickhouse_error_message(
                    e, host=ch.host, port=ch.port, database=ch.database
                )
            else:
                msg = format_clickhouse_error_message(e)
        else:
            # Avoid leaking secrets; keep message high-level.
            msg = f"Internal error in backend '{backend_name}' during '{operation}': {e}"
        return _make_error_response(
            backend=backend_name,
            operation=operation,
            message=msg,
        )


def main_stdio() -> None:
    """
    JSON stdin/stdout entrypoint.

    Read a single JSON object from stdin, dispatch it, and write the JSON
    response to stdout.
    """
    req = json.load(sys.stdin)
    res = handle_request(req)
    json.dump(res, sys.stdout, ensure_ascii=False)


def _main() -> None:
    """
    Internal convenience for `python -m flexicorp`.

    This simply delegates to main_stdio for now. If we later want a richer
    CLI under `python -m flexicorp`, we can extend this.
    """
    main_stdio()


if __name__ == "__main__":  # pragma: no cover
    _main()

