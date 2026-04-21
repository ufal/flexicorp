"""Advisory lock file per (project root, backend set) to block overlapping reindexes."""

from __future__ import annotations

import errno
import os
import signal
import time
from pathlib import Path
from typing import Optional


def locks_dir(project_root: Path) -> Path:
    return project_root / "tmp" / "flexicorp-reindex-locks"


def lock_path(project_root: Path, key: str) -> Path:
    return locks_dir(project_root) / f"{key}.lock"


def _pid_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    except OSError:
        return False
    return True


def try_acquire_lock(path: Path, pid: int) -> bool:
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
        try:
            os.write(fd, str(pid).encode())
        finally:
            os.close(fd)
        return True
    except OSError as e:
        if e.errno != errno.EEXIST:
            raise
        old = read_lock_pid(path)
        if old is not None and not _pid_alive(old):
            try:
                path.unlink()
            except OSError:
                return False
            return try_acquire_lock(path, pid)
        return False


def read_lock_pid(path: Path) -> Optional[int]:
    try:
        raw = path.read_text(encoding="utf-8").strip()
        return int(raw.split()[0])
    except Exception:
        return None


def release_lock(path: Path) -> None:
    try:
        path.unlink(missing_ok=True)
    except OSError:
        pass


def force_clear_lock(path: Path) -> None:
    pid = read_lock_pid(path)
    if pid is not None and _pid_alive(pid):
        try:
            os.kill(pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        except PermissionError:
            pass
        # brief wait for exit
        for _ in range(20):
            if not _pid_alive(pid):
                break
            time.sleep(0.1)
    release_lock(path)
