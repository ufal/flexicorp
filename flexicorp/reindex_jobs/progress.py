"""Reindex progress helpers: preflight XML workload + live xidx/CWB signals."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, Optional, Tuple
import json


def sum_word_corpus_bytes(cqp_tree: Path) -> int:
    """Sum byte sizes of ``word.corpus`` files under a CQP tree (0 if none)."""
    if not cqp_tree.is_dir():
        return 0
    total = 0
    try:
        for p in cqp_tree.rglob("word.corpus"):
            if p.is_file():
                try:
                    total += p.stat().st_size
                except OSError:
                    pass
    except OSError:
        return 0
    return total


def estimate_tokens_from_cqp_tree(cqp_tree: Path) -> Tuple[int, int]:
    """
    Return (estimated_tokens, total_bytes).

    Classical CWB heuristic: positional stream is roughly 4 bytes per token for ``word``.
    """
    nbytes = sum_word_corpus_bytes(cqp_tree)
    return (max(0, nbytes // 4), nbytes)


def split_searchfolders(raw: Optional[str]) -> list[str]:
    txt = str(raw or "").strip()
    if not txt:
        return ["xmlfiles"]
    parts = [p.strip() for p in txt.split(",")]
    out = [p for p in parts if p]
    return out or ["xmlfiles"]


def collect_xml_files_for_root(xml_root: Path) -> list[Path]:
    """
    Match flexencoder search semantics:
    - if ``index.txt`` exists: read listed XML basenames/relative paths
    - else: recursive ``*.xml``
    """
    files: list[Path] = []
    idx = xml_root / "index.txt"
    if idx.is_file():
        try:
            for line in idx.read_text(encoding="utf-8", errors="ignore").splitlines():
                rel = line.strip()
                if not rel:
                    continue
                p = xml_root / rel
                if p.is_file() and p.suffix.lower() == ".xml":
                    files.append(p)
        except OSError:
            pass
        return files
    if not xml_root.is_dir():
        return files
    try:
        for p in xml_root.rglob("*.xml"):
            if p.is_file():
                files.append(p)
    except OSError:
        return files
    return files


def preflight_xml_workload(project_root: Path, searchfolder: Optional[str]) -> Dict[str, Any]:
    """
    Preflight estimate from source XML files.

    Returns totals and a relpath->size map used to build weighted progress while
    flexencoder writes staged ``xidx/docs.tbl``.
    """
    root = project_root.resolve()
    rel_sizes: Dict[str, int] = {}
    files_total = 0
    bytes_total = 0
    folders = split_searchfolders(searchfolder)
    for folder in folders:
        base = root / folder
        for p in collect_xml_files_for_root(base):
            try:
                rel = str(p.resolve().relative_to(root))
                sz = int(p.stat().st_size)
            except Exception:
                continue
            if rel in rel_sizes:
                continue
            rel_sizes[rel] = max(0, sz)
            files_total += 1
            bytes_total += max(0, sz)
    return {
        "files_total": files_total,
        "bytes_total": bytes_total,
        "searchfolder": ",".join(folders),
        "rel_sizes": rel_sizes,
    }


def _docs_progress_from_xidx(
    xidx_docs_tbl: Path,
    rel_sizes: Dict[str, int],
) -> Tuple[int, int]:
    if not xidx_docs_tbl.is_file():
        return (0, 0)
    done_files = 0
    done_bytes = 0
    seen: set[str] = set()
    try:
        raw = xidx_docs_tbl.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return (0, 0)
    for line in raw.splitlines():
        rel = line.strip()
        if not rel or rel in seen:
            continue
        seen.add(rel)
        done_files += 1
        done_bytes += int(rel_sizes.get(rel, 0))
    return (done_files, done_bytes)


def _read_manatee_progress(project_root: Path, job_id: Optional[str]) -> Dict[str, Any]:
    jid = str(job_id or "").strip()
    if not jid:
        return {}
    p = project_root / "tmp" / "flexicorp-reindex-jobs" / f"{jid}.manatee.json"
    if not p.is_file():
        return {}
    try:
        raw = p.read_text(encoding="utf-8")
        data = json.loads(raw)
    except Exception:
        return {}
    if not isinstance(data, dict):
        return {}
    return data


def build_progress_payload(
    *,
    project_root: Path,
    staging_dir: Optional[Path],
    baseline_tokens_est: int,
    preflight_files_total: int = 0,
    preflight_bytes_total: int = 0,
    preflight_rel_sizes: Optional[Dict[str, int]] = None,
    job_id: Optional[str] = None,
    phase: str = "running",
) -> Dict[str, Any]:
    """Snapshot for job JSON ``progress`` field."""
    cqp_target: Optional[Path] = None
    if staging_dir is not None:
        cand = staging_dir / "cqp"
        if cand.is_dir():
            cqp_target = cand
    if cqp_target is None:
        cqp_target = project_root / "cqp"
    tokens_est, nbytes = estimate_tokens_from_cqp_tree(cqp_target)
    pct: Optional[float] = None
    source = "word.corpus_size_over_4"
    phase_out = phase
    files_done: Optional[int] = None
    files_total_out: Optional[int] = None
    bytes_done = 0
    bytes_total_out: Optional[int] = preflight_bytes_total if preflight_bytes_total > 0 else None
    rel_sizes = preflight_rel_sizes or {}
    if preflight_files_total > 0 and staging_dir is not None and rel_sizes:
        docs_tbl = staging_dir / "xidx" / "docs.tbl"
        if docs_tbl.is_file():
            files_done, bytes_done = _docs_progress_from_xidx(docs_tbl, rel_sizes)
            files_total_out = preflight_files_total
            if preflight_bytes_total > 0:
                pct = min(99.9, round(100.0 * float(bytes_done) / float(preflight_bytes_total), 2))
                source = "preflight_xml_bytes_weighted_from_xidx_docs"
            else:
                pct = min(99.9, round(100.0 * float(files_done) / float(preflight_files_total), 2))
                source = "preflight_xml_files_from_xidx_docs"
            # XML docs may be fully processed while slower backend post-processing
            # (e.g. Manatee mkstats/mksizes) is still running.
            if phase == "running" and files_done >= preflight_files_total:
                pct = min(99.0, max(95.0, float(pct if pct is not None else 95.0)))
                phase_out = "finalizing"
    if baseline_tokens_est > 0:
        fallback_pct = min(99.9, round(100.0 * float(tokens_est) / float(baseline_tokens_est), 2))
        if pct is None:
            pct = fallback_pct
            source = "word.corpus_size_over_4"
    manatee = _read_manatee_progress(project_root, job_id)
    manatee_phase = str(manatee.get("phase") or "").strip()
    manatee_done = int(manatee.get("done") or 0) if str(manatee.get("done") or "").strip() != "" else 0
    manatee_total = int(manatee.get("total") or 0) if str(manatee.get("total") or "").strip() != "" else 0
    manatee_current = str(manatee.get("current") or "").strip()
    if phase == "running" and manatee_phase in ("mkstats", "mktokencov", "mksizes"):
        phase_out = "finalizing"
        # Reserve the final 10% for Manatee post-processing.
        if manatee_total > 0:
            finalizing_frac = min(1.0, max(0.0, float(manatee_done) / float(manatee_total)))
            pct = round(90.0 + 9.0 * finalizing_frac, 2)
            source = "manatee_finalize_steps"
        elif pct is None:
            pct = 95.0
            source = "manatee_finalize_phase"
    if phase in ("completed", "done"):
        pct = 100.0
        phase_out = "completed"
    return {
        "phase": phase_out,
        "files_done": files_done,
        "files_total": files_total_out,
        "bytes_done_est": bytes_done,
        "bytes_total_est": bytes_total_out,
        "tokens_est": tokens_est,
        "bytes_word_corpus": nbytes,
        "manatee_phase": manatee_phase,
        "manatee_done": manatee_done if manatee_total > 0 else None,
        "manatee_total": manatee_total if manatee_total > 0 else None,
        "manatee_current": manatee_current or None,
        "pct": pct,
        "source": source,
    }
