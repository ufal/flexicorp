"""
TEITOK Pando + flexencoder xidx index freshness (mtime-based).

TEITOK / PHP checks often compare the newest file under ``xmlfiles/`` to the
**directory** mtime of ``pando/`` or ``xidx/``. On Unix, a directory's mtime
usually changes only when **entries are added, removed, or renamed** — not when
an **existing** index file is overwritten in place. After reindex, XML can
still look "newer" than those directories even when the index is up to date.

**Correct** comparison: ``max(mtime(f) for f in files under pando/ and xidx/)``
against ``max(mtime)`` of XML (or a curated doc list).
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any, Dict, Optional, Tuple


def _ignore_path_relative_parts(rel: Path) -> bool:
    return any(p.startswith(".") for p in rel.parts)


def _max_mtime_files_under(
    root: Path,
    *,
    suffixes: Optional[Tuple[str, ...]] = None,
) -> Optional[float]:
    if not root.is_dir():
        return None
    best: Optional[float] = None
    for dirpath, dirnames, filenames in os.walk(root, topdown=True):
        dirnames[:] = [d for d in dirnames if not d.startswith(".")]
        rel = Path(dirpath).relative_to(root) if dirpath != str(root) else Path()
        if _ignore_path_relative_parts(rel):
            continue
        for name in filenames:
            if name.startswith("."):
                continue
            if suffixes and not name.endswith(suffixes):
                continue
            p = Path(dirpath) / name
            try:
                m = p.stat().st_mtime
            except OSError:
                continue
            if best is None or m > best:
                best = m
    return best


def teitok_pando_xidx_freshness(project_root: Path) -> Dict[str, Any]:
    """
    Compare newest ``xmlfiles/**/*.xml`` mtime to the newest on-disk file mtime
    under ``pando/`` and ``xidx/`` (not directory mtimes).

    Returns JSON-serializable facts plus ``indexes_current`` when indexes are at
    least as new as the newest indexed XML (or when paths are missing).
    """
    root = project_root.expanduser().resolve()
    xmlfiles = root / "xmlfiles"
    pando = root / "pando"
    xidx = root / "xidx"

    xml_newest = _max_mtime_files_under(xmlfiles, suffixes=(".xml",))
    pando_newest = _max_mtime_files_under(pando)
    xidx_newest = _max_mtime_files_under(xidx)

    index_candidates = [m for m in (pando_newest, xidx_newest) if m is not None]
    index_newest = max(index_candidates) if index_candidates else None

    xml_newer = False
    if xml_newest is not None and index_newest is not None:
        # Small epsilon avoids float noise from same-second writes.
        xml_newer = xml_newest > index_newest + 1e-6

    indexes_current = True
    if xml_newest is not None and index_newest is None:
        indexes_current = False
    elif xml_newest is not None and index_newest is not None:
        indexes_current = not xml_newer

    warning: Optional[str] = None
    if xml_newer:
        warning = (
            "Files under xmlfiles/ are newer than the newest file under pando/ or xidx/. "
            "Re-run flexencoder + Pando indexing so queries and TEITOK XML fragments stay aligned."
        )

    return {
        "xmlfiles_newest_mtime": xml_newest,
        "pando_newest_file_mtime": pando_newest,
        "xidx_newest_file_mtime": xidx_newest,
        "index_newest_mtime": index_newest,
        "xml_newer_than_index_files": xml_newer,
        "indexes_current_vs_xmlfiles": indexes_current,
        "warning": warning,
        "note": (
            "Compares max file mtimes under pando/ and xidx/, not directory mtimes "
            "(directories often do not change when index files are overwritten in place)."
        ),
    }
