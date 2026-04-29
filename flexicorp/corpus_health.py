"""TEITOK project corpus health checks (CWB registry paths and related on-disk issues)."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Dict

from .config import get_project_root
from .cqp_registry_fixup import rewrite_cqp_registries_after_reindex_swap, scan_cqp_registry_path_issues


def run_corpus_health(project: Dict[str, Any], *, fix: bool = False) -> Dict[str, Any]:
    """
    Inspect ``<project_root>/cqp`` for CWB registry HOME/INFO problems.

    With ``fix=True``, rewrites stale lines in place (same rules as post-reindex
    swap fixup). Re-run without ``fix`` to confirm a clean scan.
    """
    root = get_project_root(project)
    cqp = root / "cqp"
    out: Dict[str, Any] = {
        "ok": True,
        "project_root": str(root),
        "cqp_dir": str(cqp),
        "cqp": {
            "present": cqp.is_dir(),
            "issues_before": [],
            "issues_after": [],
            "registry_files_fixed": 0,
        },
    }
    if not cqp.is_dir():
        out["cqp"]["note"] = "No cqp/ directory; nothing to scan for CWB registry paths."
        return out

    issues_before = scan_cqp_registry_path_issues(cqp)
    out["cqp"]["issues_before"] = issues_before
    err_before = sum(1 for i in issues_before if i.get("severity") == "error")
    warn_before = sum(1 for i in issues_before if i.get("severity") == "warning")
    out["cqp"]["error_count"] = err_before
    out["cqp"]["warning_count"] = warn_before
    out["ok"] = err_before == 0

    fixed = 0
    if fix:
        fixed = rewrite_cqp_registries_after_reindex_swap(cqp, None)
        out["cqp"]["registry_files_fixed"] = fixed
        issues_after = scan_cqp_registry_path_issues(cqp)
        out["cqp"]["issues_after"] = issues_after
        err_after = sum(1 for i in issues_after if i.get("severity") == "error")
        out["cqp"]["error_count_after"] = err_after
        out["ok"] = err_after == 0
    return out
