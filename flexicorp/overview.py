from __future__ import annotations

from pathlib import Path
import json
from typing import Any, Dict, List

from .backends.manatee import load_manatee_bindings
from .config import get_blacklab_settings, get_clickhouse_config, get_project_root
from .core import available_backend_names, backend_descriptor, ensure_backend_loaded
from .teitok import detect_teitok_cqp, detect_teitok_manatee

_STATS_CAPABILITY_DEFAULTS: Dict[str, bool] = {
    "stats_freq_pattributes": False,
    "stats_freq_sattributes": False,
    "stats_relative_freq": False,
    "stats_collocations": False,
    "stats_dep_collocations": False,
    "stats_keyness": False,
    "stats_table_result": False,
}


def _normalize_stats_capabilities(capabilities: Dict[str, Any]) -> Dict[str, Any]:
    out = dict(capabilities or {})
    for key, default in _STATS_CAPABILITY_DEFAULTS.items():
        out[key] = bool(out.get(key, default))
    return out


def _system_cwb_registry_candidates() -> List[Path]:
    return [
        Path("/usr/local/share/cwb/registry"),
        Path("/opt/homebrew/share/cwb/registry"),
    ]


def _cqp_status(project: Dict[str, Any]) -> Dict[str, Any]:
    root = get_project_root(project)
    raw_root_value = project.get("root")
    raw_root = Path(str(raw_root_value)).expanduser() if raw_root_value else root
    if not raw_root.is_absolute():
        raw_root = raw_root.resolve()
    if not root.is_dir():
        return {"available": False, "reason": "Project root missing or not a directory."}

    # Reindex lock (visible to UI so CQP reindex can disable the backend while running)
    reindex_info: Dict[str, Any] = {"locked": False}
    try:
        lock_path = root / "tmp" / "flexicorp-locks" / "cqp-reindex.lock"
        if lock_path.is_file():
            raw = lock_path.read_text(encoding="utf-8")
            data = json.loads(raw)
            if isinstance(data, dict):
                reindex_info = {"locked": True, "data": data}
    except Exception as exc:  # pragma: no cover - defensive
        reindex_info = {"locked": True, "error": str(exc)}

    detected = detect_teitok_cqp(root) or {}
    cqp_cfg = dict(detected.get("cqp") or {})
    cqp_cfg.update(dict(project.get("cqp") or {}))
    corpus = str(cqp_cfg.get("corpus") or "").strip().lower()
    registry = cqp_cfg.get("registry")
    if not corpus:
        return {"available": False, "reason": "No CQP corpus name configured."}

    reg_path = Path(str(registry)).expanduser() if registry else None
    reg_file: Path | None = None
    if reg_path:
        if reg_path.is_dir():
            candidate = reg_path / corpus
            if candidate.is_file():
                reg_file = candidate
        elif reg_path.is_file():
            reg_file = reg_path

    if reg_file is None:
        local_roots = []
        for candidate_root in [raw_root, root]:
            if candidate_root not in local_roots:
                local_roots.append(candidate_root)
        detected_root_value = detected.get("root")
        if detected_root_value:
            detected_root = Path(str(detected_root_value)).expanduser()
            if not detected_root.is_absolute():
                detected_root = detected_root.resolve()
            if detected_root not in local_roots:
                local_roots.append(detected_root)

        local_registry_tried: List[str] = []
        for base in local_roots:
            cqp_dir = base / "cqp"
            local_registry_tried.append(str(cqp_dir / corpus))
            if (cqp_dir / corpus).is_file():
                return {
                    "available": True,
                    "reason": f"Registry file found in local cqp folder: {cqp_dir / corpus}.",
                }

        for system_dir in _system_cwb_registry_candidates():
            candidate = system_dir / corpus
            if candidate.is_file():
                return {
                    "available": True,
                    "reason": "Registry file found in system registry.",
                }
        if detected:
            return {
                "available": False,
                "reason": "No local CQP registry file found for the detected TEITOK corpus "
                f"'{corpus}'. Tried: {', '.join(local_registry_tried)}.",
                "reindex": reindex_info,
            }
        reg_desc = str(registry) if registry else "registry/<corpus>"
        return {
            "available": False,
            "reason": f"Registry file not found: {reg_desc}.",
            "reindex": reindex_info,
        }

    cqp_folder = root / "cqp"
    if (cqp_folder / "xidx.rng").is_file():
        return {
            "available": True,
            "reason": "CQP folder has xidx.rng: cqp/.",
            "reindex": reindex_info,
        }
    word_corpus = cqp_folder / "word.corpus"
    if word_corpus.is_file() and word_corpus.stat().st_size > 0:
        return {
            "available": True,
            "reason": "CQP folder has word.corpus: cqp/.",
            "reindex": reindex_info,
        }
    return {
        "available": False,
        "reason": "No word.corpus or xidx.rng in cqp/.",
        "reindex": reindex_info,
    }


def _manatee_status(project: Dict[str, Any]) -> Dict[str, Any]:
    root = get_project_root(project)
    if not root.is_dir():
        return {"available": False, "reason": "Project root missing or not a directory."}

    detected = detect_teitok_manatee(root) or {}
    manatee_cfg = dict(detected.get("manatee") or {})
    manatee_cfg.update(dict(project.get("manatee") or {}))
    registry = manatee_cfg.get("registry")
    if not registry:
        manatee_dir = root / "manatee"
    else:
        manatee_dir = Path(str(registry)).expanduser()
        if not manatee_dir.is_absolute():
            manatee_dir = (root / manatee_dir).resolve()

    if not manatee_dir.is_dir():
        return {"available": False, "corpus_available": False, "reason": f"Manatee folder not found: {manatee_dir.name}."}

    candidates = [
        manatee_dir / "corp" / "word.lex",
        manatee_dir / "word.lex",
        manatee_dir / "word",
        manatee_dir / "word.frq",
        manatee_dir / "corp" / "word",
        manatee_dir / "corp" / "word.frq",
    ]
    for path in candidates:
        if path.is_file() and (path.suffix in {".lex", ".frq"} or path.stat().st_size > 0):
            try:
                load_manatee_bindings(project_root=root)
            except Exception as e:
                # Corpus files are present (Flexi can use them); bindings missing (Manatee backend cannot).
                return {
                    "available": False,
                    "corpus_available": True,
                    "reason": "Corpus available, but install the Manatee bindings.",
                    "details": {"error": str(e), "help_url": "install-manatee-bindings.md"},
                }
            try:
                rel = path.relative_to(root)
                shown = str(rel)
            except ValueError:
                shown = str(path)
            return {"available": True, "corpus_available": True, "reason": f"Words table found: {shown}."}
    return {
        "available": False,
        "corpus_available": False,
        "reason": "No words table (word.lex / word / word.frq) in manatee/ or manatee/corp/.",
    }


def _teitokxml_status(project: Dict[str, Any]) -> Dict[str, Any]:
    root = get_project_root(project)
    if not root.is_dir():
        return {"available": False, "reason": "Project root missing or not a directory."}
    xml_dir = root / "xmlfiles"
    if xml_dir.is_dir():
        return {
            "available": True,
            "reason": "TEITOK XML files backend available via xmlfiles/ and tmp/doclist.sqlite.",
        }
    return {"available": True, "reason": "TEITOK XML files backend available (xmlfiles/ not checked strictly)."}


def _clickhouse_status(project: Dict[str, Any]) -> Dict[str, Any]:
    cfg = get_clickhouse_config(project)
    if cfg is None:
        return {
            "available": False,
            "daemon_reachable": False,
            "reason": "No ClickHouse configuration available.",
        }
    from .core import ensure_backend_loaded

    backend = ensure_backend_loaded("clickhouse")
    if backend is None:
        return {
            "available": False,
            "daemon_reachable": False,
            "reason": "ClickHouse backend is not implemented.",
        }
    try:
        payload = backend.daemon(  # type: ignore[attr-defined]
            {
                "version": 1,
                "backend": "clickhouse",
                "operation": "daemon",
                "project": project,
                "params": {"action": "status"},
            }
        )
        daemon_reachable = bool(payload.get("running"))
        corpus_ready = bool(payload.get("corpus_ready"))
        if daemon_reachable and corpus_ready:
            reason = f"ClickHouse daemon reachable at {cfg.host}:{cfg.port}/{cfg.database}; corpus ready."
            return {"available": True, "daemon_reachable": True, "reason": reason, "details": payload}
        if daemon_reachable and not corpus_ready:
            reason = (
                f"ClickHouse daemon reachable at {cfg.host}:{cfg.port}, "
                f"but corpus database '{cfg.database}' is not ready (reindex to create)."
            )
            return {"available": False, "daemon_reachable": True, "reason": reason, "details": payload}
        err = payload.get("error", "")
        reason = err if err else "ClickHouse daemon unavailable or authentication failed."
        return {"available": False, "daemon_reachable": False, "reason": reason, "details": payload}
    except Exception as e:
        err = str(e)
        reason = err if err else "ClickHouse daemon unavailable or authentication failed."
        return {"available": False, "daemon_reachable": False, "reason": reason, "details": {"error": err}}


def _clickql_status(project: Dict[str, Any], clickhouse_status: Dict[str, Any]) -> Dict[str, Any]:
    cfg = get_clickhouse_config(project)
    if cfg is None:
        return {"available": False, "daemon_reachable": False, "reason": "No ClickHouse configuration available."}
    if not clickhouse_status.get("daemon_reachable", clickhouse_status.get("available")):
        return {
            "available": False,
            "daemon_reachable": False,
            "reason": clickhouse_status.get("reason", "ClickHouse daemon unavailable or authentication failed."),
        }
    if not cfg.tokens_table or not cfg.docs_table:
        return {
            "available": False,
            "daemon_reachable": True,
            "reason": "ClickQL requires ClickHouse tables.tokens and tables.docs configuration.",
        }
    corpus_ready = bool(clickhouse_status.get("details", {}).get("corpus_ready"))
    if corpus_ready:
        return {
            "available": True,
            "daemon_reachable": True,
            "reason": "ClickHouse daemon reachable and ClickQL tables configured.",
        }
    return {
        "available": False,
        "daemon_reachable": True,
        "reason": (
            f"ClickHouse daemon reachable, but corpus database '{cfg.database}' is not ready (reindex to create)."
        ),
    }


def _blacklab_status(project: Dict[str, Any]) -> Dict[str, Any]:
    backend = ensure_backend_loaded("blacklab")
    if backend is None:
        return {"available": False, "reason": "BlackLab backend is not implemented."}
    root = get_project_root(project)
    lock_info: Dict[str, Any] = {"locked": False}
    try:
        lock_path = root / "tmp" / "flexicorp-locks" / "blacklab-reindex.lock"
        if lock_path.is_file():
            raw = lock_path.read_text(encoding="utf-8")
            data = json.loads(raw)
            if isinstance(data, dict):
                lock_info = {"locked": True, "data": data}
    except Exception as exc:  # pragma: no cover - defensive
        # If the lock target has disappeared (e.g. dangling symlink), treat as unlocked.
        lock_info = {"locked": False, "error": str(exc)}
    try:
        payload = backend.info(  # type: ignore[attr-defined]
            {
                "version": 1,
                "backend": "blacklab",
                "operation": "info",
                "project": project,
                "params": {"topic": "corpora"},
            }
        )
        corpora = list(payload.get("corpora") or [])
        selected = str((get_blacklab_settings(project) or {}).get("corpus") or "").strip()
        corpus_ids = {
            str(item.get("id") or "").strip()
            for item in corpora
            if isinstance(item, dict) and str(item.get("id") or "").strip()
        }
        if selected:
            if selected in corpus_ids:
                reason = f"BlackLab server reachable; selected corpus is '{selected}'."
                available = True
            else:
                available = False
                if corpus_ids:
                    reason = (
                        f"BlackLab server reachable, but selected corpus '{selected}' was not found. "
                        f"Server reports {len(corpus_ids)} corpus/corpora."
                    )
                else:
                    reason = (
                        f"BlackLab server reachable, but selected corpus '{selected}' was not found and "
                        "the server reported no corpora."
                    )
        elif corpora:
            reason = f"BlackLab server reachable; {len(corpora)} corpus/corpora available."
            available = True
        else:
            reason = "BlackLab server reachable, but no corpora were reported."
            available = True
        # If a reindex lock is present, update the reason to reflect that.
        if lock_info.get("locked"):
            data = lock_info.get("data") or {}
            started = data.get("started") or "unknown time"
            reason = f"BlackLab reindex in progress (started {started})."
        return {
            "available": available,
            "reason": reason,
            "details": payload,
            "reindex": lock_info,
        }
    except Exception as e:
        return {
            "available": False,
            "reason": "BlackLab server unavailable or misconfigured.",
            "details": {"error": str(e)},
            "reindex": lock_info,
        }


def _flexi_status(cqp_status: Dict[str, Any], manatee_status: Dict[str, Any]) -> Dict[str, Any]:
    cwb_available = bool(cqp_status.get("available"))
    # Native manatee backend needs bindings; flexi can still use manatee corpus files without them.
    manatee_corpus = bool(manatee_status.get("corpus_available", manatee_status.get("available")))
    manatee_bindings = bool(manatee_status.get("available"))
    if cwb_available and manatee_bindings:
        return {
            "available": True,
            "reason": "flexi can query this corpus via both the CWB/CQP and Manatee paths.",
        }
    if cwb_available and manatee_corpus:
        return {
            "available": True,
            "reason": "flexi can query this corpus via the CWB/CQP path; Manatee index files are also present.",
        }
    if cwb_available:
        return {
            "available": True,
            "reason": "flexi can query this corpus via the CWB/CQP path.",
        }
    if manatee_corpus:
        return {
            "available": True,
            "reason": "flexi can query this corpus via the Manatee file path (native flexi reader).",
        }
    return {
        "available": False,
        "reason": "flexi is implemented, but no usable CWB/CQP or Manatee corpus is available for this project.",
    }


def build_backend_overview(project: Dict[str, Any]) -> Dict[str, Any]:
    implemented_backends: Dict[str, Any] = {}
    for name in available_backend_names():
        backend = ensure_backend_loaded(name)
        descriptor = backend_descriptor(backend) if backend is not None else {
            "id": name,
            "label": name,
            "supported_query_languages": [],
            "supported_corpus_formats": [],
            "default_query_language": None,
            "default_corpus_format": None,
            "default_selection_reason": None,
            "notes": None,
        }
        implemented_backends[name] = {
            "label": descriptor["label"],
            "implemented": backend is not None,
            "capabilities": _normalize_stats_capabilities(backend.capabilities() if backend is not None else {}),
            "descriptor": descriptor,
        }

    cqp_status = _cqp_status(project)
    manatee_status = _manatee_status(project)
    teitokxml_status = _teitokxml_status(project)
    clickhouse_status = _clickhouse_status(project)
    clickql_status = _clickql_status(project, clickhouse_status)
    blacklab_status = _blacklab_status(project)
    flexi_status = _flexi_status(cqp_status, manatee_status)

    backend_status: Dict[str, Dict[str, Any]] = {
        "flexi": {
            "label": "flexi",
            "implemented": bool(implemented_backends.get("flexi", {}).get("implemented")),
            "available": bool(flexi_status.get("available")),
            "reason": flexi_status.get("reason", ""),
            "capabilities": implemented_backends.get("flexi", {}).get("capabilities", {}),
            "descriptor": implemented_backends.get("flexi", {}).get("descriptor", {}),
        },
        "cqp": {
            "label": "cqp",
            "implemented": bool(implemented_backends.get("cqp", {}).get("implemented")),
            "available": bool(cqp_status.get("available")),
            "reason": cqp_status.get("reason", ""),
            "capabilities": implemented_backends.get("cqp", {}).get("capabilities", {}),
            "descriptor": implemented_backends.get("cqp", {}).get("descriptor", {}),
        },
        "manatee": {
            "label": "manatee",
            "implemented": bool(implemented_backends.get("manatee", {}).get("implemented")),
            "available": bool(manatee_status.get("available")),
            "reason": manatee_status.get("reason", ""),
            "capabilities": implemented_backends.get("manatee", {}).get("capabilities", {}),
            "descriptor": implemented_backends.get("manatee", {}).get("descriptor", {}),
        },
        "teitokxml": {
            "label": "teitokxml",
            "implemented": bool(implemented_backends.get("teitokxml", {}).get("implemented")),
            "available": bool(teitokxml_status.get("available")),
            "reason": teitokxml_status.get("reason", ""),
            "capabilities": implemented_backends.get("teitokxml", {}).get("capabilities", {}),
            "descriptor": implemented_backends.get("teitokxml", {}).get("descriptor", {}),
        },
        "clickhouse": {
            "label": "clickhouse",
            "implemented": bool(implemented_backends.get("clickhouse", {}).get("implemented")),
            "available": bool(clickhouse_status.get("available")),
            "daemon_reachable": bool(clickhouse_status.get("daemon_reachable", clickhouse_status.get("available"))),
            "reason": clickhouse_status.get("reason", ""),
            "capabilities": implemented_backends.get("clickhouse", {}).get("capabilities", {}),
            "descriptor": implemented_backends.get("clickhouse", {}).get("descriptor", {}),
        },
        "clickql": {
            "label": "clickql",
            "implemented": bool(implemented_backends.get("clickql", {}).get("implemented")),
            "available": bool(clickql_status.get("available")),
            "daemon_reachable": bool(clickql_status.get("daemon_reachable", clickql_status.get("available"))),
            "reason": clickql_status.get("reason", ""),
            "capabilities": implemented_backends.get("clickql", {}).get("capabilities", {}),
            "descriptor": implemented_backends.get("clickql", {}).get("descriptor", {}),
        },
        "blacklab": {
            "label": "blacklab",
            "implemented": bool(implemented_backends.get("blacklab", {}).get("implemented")),
            "available": bool(blacklab_status.get("available")),
            "reason": blacklab_status.get("reason", ""),
            "capabilities": implemented_backends.get("blacklab", {}).get("capabilities", {}),
            "descriptor": implemented_backends.get("blacklab", {}).get("descriptor", {}),
        },
    }

    query_engines: Dict[str, Dict[str, Any]] = {
        "cqp": {
            "label": "cqp",
            "available": bool(cqp_status.get("available")),
            "reason": cqp_status.get("reason", ""),
        },
        "manatee": {
            "label": "manatee",
            "available": bool(manatee_status.get("available")),
            "reason": manatee_status.get("reason", ""),
        },
        "teitokxml": {
            "label": "teitokxml",
            "available": bool(teitokxml_status.get("available")),
            "reason": teitokxml_status.get("reason", ""),
        },
        "clickhouse": {
            "label": "clickhouse",
            "available": bool(clickhouse_status.get("available")),
            "reason": clickhouse_status.get("reason", ""),
        },
        "blacklab": {
            "label": "blacklab",
            "available": bool(blacklab_status.get("available")),
            "reason": blacklab_status.get("reason", ""),
        },
    }

    backend_combos: List[Dict[str, Any]] = [
        {
            "id": "flexi:cwb-cql:cwb",
            "backend": "flexi",
            "queryLanguage": "cwb-cql",
            "corpusFormat": "cwb",
            "available": bool(cqp_status.get("available")),
            "reason": "CWB/CQP index available (CQP backend usable)." if cqp_status.get("available") else "CWB/CQP index not available for this corpus.",
        },
        {
            "id": "flexi:manatee-cql:manatee",
            "backend": "flexi",
            "queryLanguage": "manatee-cql",
            "corpusFormat": "manatee",
            "available": bool(manatee_status.get("corpus_available", manatee_status.get("available"))),
            "reason": "Manatee index available for this corpus." if manatee_status.get("corpus_available", manatee_status.get("available")) else "Manatee index not available for this corpus.",
        },
        {
            "id": "manatee:manatee-cql:manatee",
            "backend": "manatee",
            "queryLanguage": "manatee-cql",
            "corpusFormat": "manatee",
            "available": bool(manatee_status.get("available")),
            "reason": manatee_status.get("reason", ""),
        },
        {
            "id": "cqp:cwb-cql:cwb",
            "backend": "cqp",
            "queryLanguage": "cwb-cql",
            "corpusFormat": "cwb",
            "available": bool(cqp_status.get("available")),
            "reason": cqp_status.get("reason", ""),
        },
        {
            "id": "teitokxml:teitok:xml",
            "backend": "teitokxml",
            "queryLanguage": "teitok",
            "corpusFormat": "xml",
            "available": bool(teitokxml_status.get("available")),
            "reason": teitokxml_status.get("reason", ""),
        },
        {
            "id": "clickql:clickcql:clickhouse",
            "backend": "clickql",
            "queryLanguage": "clickcql",
            "corpusFormat": "clickhouse",
            "available": bool(clickql_status.get("available")),
            "reason": clickql_status.get("reason", ""),
        },
        {
            "id": "clickql:pmltq:clickhouse",
            "backend": "clickql",
            "queryLanguage": "pmltq",
            "corpusFormat": "clickhouse",
            "available": bool(clickql_status.get("available")),
            "reason": "ClickHouse daemon reachable for PML-TQ translation over the ClickQL schema." if clickql_status.get("available") else clickql_status.get("reason", ""),
        },
        {
            "id": "clickql:sql:clickhouse",
            "backend": "clickql",
            "queryLanguage": "sql",
            "corpusFormat": "clickhouse",
            "available": bool(clickhouse_status.get("available")),
            "reason": "ClickHouse daemon reachable for direct SQL execution." if clickhouse_status.get("available") else clickhouse_status.get("reason", ""),
        },
        {
            "id": "blacklab:bcql:blacklab",
            "backend": "blacklab",
            "queryLanguage": "bcql",
            "corpusFormat": "blacklab",
            "available": bool(blacklab_status.get("available")),
            "reason": blacklab_status.get("reason", ""),
        },
    ]

    for combo in backend_combos:
        backend_name = str(combo.get("backend") or "")
        st = backend_status.get(backend_name) or {}
        combo["capabilities"] = dict(st.get("capabilities") or {})
        combo["descriptor"] = dict(st.get("descriptor") or {})
        reinfo = dict(st.get("reindex") or {})
        combo["reindex"] = reinfo
        combo["reindexLocked"] = bool(reinfo.get("locked"))
        help_url = (st.get("details") or {}).get("help_url")
        if help_url:
            combo["help_url"] = help_url
        # Reindex allowed when daemon reachable (ClickHouse/ClickQL) or always (others)
        if backend_name in {"clickhouse", "clickql"}:
            combo["reindexAvailable"] = bool(st.get("daemon_reachable", st.get("available")))
        else:
            combo["reindexAvailable"] = bool(combo.get("capabilities", {}).get("reindex"))

    return {
        "implementedBackends": implemented_backends,
        "availableBackends": [name for name, st in backend_status.items() if st.get("available")],
        "availableQueryEngines": [name for name, st in query_engines.items() if st.get("available")],
        "backendStatus": backend_status,
        "queryEngines": query_engines,
        "backendCombos": backend_combos,
    }
