"""Spawn background reindex subprocess with lock + job record."""

from __future__ import annotations

import hashlib
import subprocess
import sys
import time
import uuid
from pathlib import Path
from typing import Any, Dict, List, Tuple

from .progress import estimate_tokens_from_cqp_tree
from .store import JobState, job_path, write_job


def reindex_lock_key(req: Dict[str, Any]) -> str:
    """Short hash for lock filename (backend + sorted reindex_backends)."""
    backend = str(req.get("backend") or "")
    params = req.get("params") or {}
    rb = params.get("reindex_backends")
    parts: List[str]
    if isinstance(rb, str):
        parts = sorted([x.strip().lower() for x in rb.split(",") if x.strip()])
    elif isinstance(rb, list):
        parts = sorted([str(x).strip().lower() for x in rb if str(x).strip()])
    else:
        parts = []
    raw = f"{backend}\n{','.join(parts)}"
    return hashlib.sha256(raw.encode()).hexdigest()[:24]


def enqueue_background_reindex(
    req: Dict[str, Any],
    *,
    project_root: Path,
    force: bool = False,
    staging: bool = False,
) -> Tuple[bool, Dict[str, Any], List[str]]:
    """
    Create a job file, acquire the per-corpus lock, spawn the worker.

    Returns (ok, payload, errors) where payload is suitable for CLI ``done.result``.
    """
    errs: List[str] = []
    project_root = project_root.resolve()
    job_id = str(uuid.uuid4())
    params = dict(req.get("params") or {})
    params["reindex_job_id"] = job_id
    if staging:
        params["reindex_staging"] = True
    if force:
        params["reindex_force_lock"] = True
    req_job = {**req, "params": params}

    baseline_tokens = 0
    live_cqp = project_root / "cqp"
    if live_cqp.is_dir():
        baseline_tokens, _ = estimate_tokens_from_cqp_tree(live_cqp)

    now = time.time()
    state = JobState(
        job_id=job_id,
        status="queued",
        created_ts=now,
        updated_ts=now,
        project_root=str(project_root),
        request=req_job,
        progress={
            "phase": "queued",
            "baseline_tokens_est": baseline_tokens,
        },
    )
    write_job(project_root, state)

    cmd = [
        sys.executable,
        "-m",
        "flexicorp.reindex_jobs",
        "worker",
        job_id,
        str(project_root),
    ]
    try:
        subprocess.Popen(
            cmd,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            cwd=str(project_root),
            start_new_session=True,
        )
    except Exception as e:
        jp = job_path(project_root, job_id)
        try:
            jp.unlink(missing_ok=True)
        except OSError:
            pass
        return (
            False,
            {
                "reindex_backends": params.get("reindex_backends"),
                "indexer": {"enqueued": False, "job_id": None, "kind": "flexicorp"},
                "message": f"Failed to spawn reindex worker: {e}",
            },
            [str(e)],
        )

    ok_payload: Dict[str, Any] = {
        "reindex_backends": params.get("reindex_backends"),
        "message": "Reindex enqueued (background worker).",
        "indexer": {
            "enqueued": True,
            "job_id": job_id,
            "kind": "flexicorp",
            "staging": staging,
        },
    }
    if staging:
        ok_payload["reindex_staging"] = str(
            project_root / "tmp" / "flexicorp-reindex-staging" / job_id
        )
    return True, ok_payload, []
