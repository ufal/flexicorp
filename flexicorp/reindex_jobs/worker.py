"""Child process: run handle_request(reindex) and update job file."""

from __future__ import annotations

import os
import sys
import threading
import time
from pathlib import Path
from typing import Any, Dict, List, Optional

from ..core import handle_request
from ..teitok import detect_teitok_cqp
from .locks import force_clear_lock, lock_path, release_lock, try_acquire_lock
from .progress import build_progress_payload, preflight_xml_workload
from .runner import reindex_lock_key
from .store import JobState, read_job, update_job_progress, write_job


def _progress_thread(
    project_root: Path,
    job_id: str,
    staging: Optional[Path],
    baseline_tokens_est: int,
    preflight_files_total: int,
    preflight_bytes_total: int,
    preflight_rel_sizes: Dict[str, int],
    stop: threading.Event,
) -> None:
    while not stop.wait(2.0):
        payload = build_progress_payload(
            project_root=project_root,
            staging_dir=staging,
            baseline_tokens_est=baseline_tokens_est,
            preflight_files_total=preflight_files_total,
            preflight_bytes_total=preflight_bytes_total,
            preflight_rel_sizes=preflight_rel_sizes,
            job_id=job_id,
            phase="running",
        )
        update_job_progress(project_root, job_id, payload)


def run_worker(job_id: str, project_root_s: str) -> int:
    project_root = Path(project_root_s).resolve()
    st = read_job(project_root, job_id)
    if st is None:
        print(f"[flexicorp-reindex] unknown job_id {job_id}", file=sys.stderr)
        return 1

    req = st.request
    params = dict(req.get("params") or {})
    lock_file = lock_path(project_root, reindex_lock_key(req))
    if params.get("reindex_force_lock"):
        force_clear_lock(lock_file)
    if not try_acquire_lock(lock_file, os.getpid()):
        st.status = "failed"
        st.error = "Another reindex is already running for this corpus/backends (or stale lock; retry with --force)."
        st.updated_ts = time.time()
        write_job(project_root, st)
        return 1

    exit_code = 1
    try:
        staging: Optional[Path] = None
        if params.get("reindex_staging"):
            jid = str(params.get("reindex_job_id") or job_id).strip()
            staging = project_root / "tmp" / "flexicorp-reindex-staging" / jid

        baseline = int(st.progress.get("baseline_tokens_est") or 0)
        detected = detect_teitok_cqp(project_root)
        searchfolder = None
        if isinstance(detected, dict):
            meta = dict(detected.get("meta") or {})
            sf = meta.get("searchfolder")
            if isinstance(sf, str) and sf.strip():
                searchfolder = sf.strip()
        preflight = preflight_xml_workload(project_root, searchfolder)
        preflight_files_total = int(preflight.get("files_total") or 0)
        preflight_bytes_total = int(preflight.get("bytes_total") or 0)
        preflight_rel_sizes = dict(preflight.get("rel_sizes") or {})

        st.status = "running"
        st.started_ts = time.time()
        st.updated_ts = time.time()
        st.progress = {
            **st.progress,
            "phase": "running",
            "searchfolder": str(preflight.get("searchfolder") or ""),
            # Keep preflight estimates for diagnostics, but do not expose them
            # as live done/total progress counters until we have real done counts.
            "preflight_files_total_est": preflight_files_total,
            "preflight_bytes_total_est": preflight_bytes_total,
        }
        write_job(project_root, st)

        stop = threading.Event()
        th = threading.Thread(
            target=_progress_thread,
            args=(
                project_root,
                job_id,
                staging,
                baseline,
                preflight_files_total,
                preflight_bytes_total,
                preflight_rel_sizes,
                stop,
            ),
            daemon=True,
        )
        th.start()
        res: Dict[str, Any] = {}
        errs: List[str] = []
        try:
            res = handle_request(st.request)
            if not res.get("ok"):
                err_list = res.get("errors") or []
                errs = [str(e) for e in err_list] if isinstance(err_list, list) else [str(err_list)]
                if not errs:
                    errs = ["reindex failed"]
        except Exception as e:
            errs = [str(e)]
            res = {
                "ok": False,
                "backend": "flexencoder",
                "operation": "reindex",
                "errors": errs,
                "result": None,
            }
        finally:
            stop.set()
            try:
                th.join(timeout=3.0)
            except Exception:
                pass

        st2 = read_job(project_root, job_id)
        if st2 is None:
            st2 = st
        if errs:
            st2.status = "failed"
            st2.error = errs[0]
        else:
            st2.status = "completed"
            st2.error = None
        st2.finished_ts = time.time()
        st2.result = res.get("result") if isinstance(res.get("result"), dict) else res
        st2.progress = {
            **st2.progress,
            **build_progress_payload(
                project_root=project_root,
                staging_dir=staging,
                baseline_tokens_est=max(baseline, 1),
                preflight_files_total=preflight_files_total,
                preflight_bytes_total=preflight_bytes_total,
                preflight_rel_sizes=preflight_rel_sizes,
                job_id=job_id,
                phase=st2.status,
            ),
        }
        write_job(project_root, st2)
        exit_code = 0 if not errs else 1
        return exit_code
    finally:
        release_lock(lock_file)


def main(argv: Optional[List[str]] = None) -> int:
    argv = argv if argv is not None else sys.argv[1:]
    if len(argv) < 2:
        print("usage: flexicorp-reindex-worker <job_id> <project_root>", file=sys.stderr)
        return 2
    return run_worker(argv[0], argv[1])
