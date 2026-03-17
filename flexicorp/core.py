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
        else:
            return _make_error_response(
                backend=backend_name,
                operation=operation,
                message=f"Unknown or unsupported query language for highlight: {query_lang!r}. Supported: cwb-cql, manatee-cql, clickcql, bcql, pmltq.",
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
    for p in ["/usr/local/bin/flexencoder", "/opt/homebrew/bin/flexencoder", "/usr/bin/flexencoder"]:
        cand = Path(p)
        if cand.is_file() and os.access(cand, os.X_OK):
            return str(cand)
    return shutil.which("flexencoder")


def _run_flexencoder_reindex(
    project_root: Path,
    settings_path: Path,
    output_cwb: Optional[Path] = None,
    output_clickhouse: Optional[Path] = None,
    debug: bool = False,
) -> Dict[str, Any]:
    """Run flexencoder with optional CWB and/or ClickHouse output. Returns result dict."""
    flexencoder_bin = _find_flexencoder(project_root)
    if not flexencoder_bin:
        raise RuntimeError(
            "flexencoder not found (required for multi-backend reindex). "
            "Install under project Scripts/, TT_ROOT/Scripts/, or PATH."
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
        raise RuntimeError(
            f"flexencoder failed with exit code {proc.returncode}: {proc.stderr or proc.stdout}"
        )
    return {
        "engine": "flexencoder",
        "output_cwb": str(output_cwb) if output_cwb else None,
        "output_clickhouse": str(output_clickhouse) if output_clickhouse else None,
    }


def _handle_reindex_multi(req: FlexiRequest) -> FlexiResponse:
    """
    Run reindex for multiple backends in one go. Uses flexencoder for CQP and/or
    ClickHouse (single extraction pass), then runs BlackLab reindex separately if requested.
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
            message="reindex_backends is required (list or comma-separated, e.g. cqp,clickhouse,manatee).",
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

    flexencoder_backends = [b for b in backends if b in ("cqp", "clickhouse", "clickql", "manatee")]
    if flexencoder_backends:
        # CWB output only when CQP requested; Manatee/ClickHouse use JSONL only (no CWB required)
        output_cwb = root_dir / "cqp" if "cqp" in flexencoder_backends else None
        needs_jsonl = any(b in ("clickhouse", "clickql", "manatee") for b in flexencoder_backends)
        output_clickhouse = (root_dir / "tmp" / "clickhouse") if needs_jsonl else None
        output_manatee = (root_dir / "manatee") if "manatee" in flexencoder_backends else None
        if output_clickhouse:
            output_clickhouse.mkdir(parents=True, exist_ok=True)
        if output_manatee:
            output_manatee.mkdir(parents=True, exist_ok=True)
        try:
            r = _run_flexencoder_reindex(
                root_dir, settings_path,
                output_cwb=output_cwb,
                output_clickhouse=output_clickhouse,
                debug=debug,
            )
            results.append(r)
            if output_clickhouse and r.get("output_clickhouse"):
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
                if output_manatee:
                    from .manatee_writer import convert_jsonl_to_manatee
                    from .teitok import detect_teitok_cqp
                    detected = detect_teitok_cqp(root_dir) or {}
                    corpus_name = (detected.get("cqp") or {}).get("corpus") or project.get("cqp", {}).get("corpus") or "corpus"
                    if isinstance(corpus_name, str):
                        corpus_name = corpus_name.strip().lower().replace("-", "_")
                    manatee_result = convert_jsonl_to_manatee(
                        jsonl_dir,
                        output_manatee / "corp",
                        corpus_name=corpus_name,
                        settings_path=settings_path,
                    )
                    if not manatee_result.get("ok"):
                        errors.append(manatee_result.get("error", "Manatee conversion failed"))
                    else:
                        r["manatee_output"] = manatee_result
        except Exception as e:
            errors.append(f"flexencoder: {e}")

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
        if backend_name in ("cqp", "clickhouse", "clickql", "manatee", "pando"):
            return _handle_reindex_multi({**req, "params": {**params, "reindex_backends": [backend_name]}})

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

