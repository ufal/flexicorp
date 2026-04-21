"""JSON job files under ``<project>/tmp/flexicorp-reindex-jobs/``."""

from __future__ import annotations

import json
import os
import tempfile
import time
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Dict, Optional


@dataclass
class JobState:
    job_id: str
    status: str  # queued | running | completed | failed
    created_ts: float
    updated_ts: float
    project_root: str
    request: Dict[str, Any]
    error: Optional[str] = None
    progress: Dict[str, Any] = field(default_factory=dict)
    result: Optional[Dict[str, Any]] = None
    started_ts: Optional[float] = None
    finished_ts: Optional[float] = None

    def to_json_dict(self) -> Dict[str, Any]:
        d = asdict(self)
        return d


def jobs_dir(project_root: Path) -> Path:
    return project_root / "tmp" / "flexicorp-reindex-jobs"


def job_path(project_root: Path, job_id: str) -> Path:
    return jobs_dir(project_root) / f"{job_id}.json"


def write_job(project_root: Path, state: JobState) -> None:
    p = job_path(project_root, state.job_id)
    p.parent.mkdir(parents=True, exist_ok=True)
    state.updated_ts = time.time()
    payload = json.dumps(state.to_json_dict(), ensure_ascii=False, indent=2)
    fd, tmp = tempfile.mkstemp(
        dir=str(p.parent), prefix=f".{state.job_id}.", suffix=".tmp", text=True
    )
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(payload)
        os.replace(tmp, p)
    finally:
        try:
            if os.path.exists(tmp):
                os.unlink(tmp)
        except OSError:
            pass


def read_job(project_root: Path, job_id: str) -> Optional[JobState]:
    p = job_path(project_root, job_id)
    if not p.is_file():
        return None
    try:
        raw = p.read_text(encoding="utf-8")
        data = json.loads(raw)
    except Exception:
        return None
    try:
        return JobState(
            job_id=str(data.get("job_id") or job_id),
            status=str(data.get("status") or "unknown"),
            created_ts=float(data.get("created_ts") or 0),
            updated_ts=float(data.get("updated_ts") or 0),
            project_root=str(data.get("project_root") or ""),
            request=dict(data.get("request") or {}),
            error=data.get("error"),
            progress=dict(data.get("progress") or {}),
            result=data.get("result") if isinstance(data.get("result"), dict) else None,
            started_ts=float(data["started_ts"]) if data.get("started_ts") is not None else None,
            finished_ts=float(data["finished_ts"]) if data.get("finished_ts") is not None else None,
        )
    except Exception:
        return None


def update_job_progress(project_root: Path, job_id: str, progress: Dict[str, Any]) -> None:
    st = read_job(project_root, job_id)
    if st is None:
        return
    st.progress = {**st.progress, **progress}
    write_job(project_root, st)
