"""On-disk CWB registry path fixes (HOME/INFO) after reindex staging swaps or moves."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

# Skip obvious non-registry blobs when walking under cqp/
_SKIP_SUFFIXES = frozenset(
    {
        ".corpus",
        ".corpus.pos",
        ".lexicon",
        ".lexicon.idx",
        ".rng",
        ".avs",
        ".avx",
        ".idx",
        ".info",
    }
)


def _dir_has_cwb_binaries(path: Path) -> bool:
    if not path.is_dir():
        return False
    try:
        return any(path.glob("*.corpus"))
    except OSError:
        return False


def preferred_cqp_local_home(registry_file: Path, project_root_cqp: Optional[Path]) -> Path:
    """
    Directory that should be CWB HOME for this registry file.

    Prefer the folder that actually holds *.corpus (usually registry_file.parent)
    over bare project ``cqp/``, which may exist but is the wrong HOME for corpora
    in subdirectories.
    """
    candidates: List[Path] = []
    parent = registry_file.parent.resolve()
    if _dir_has_cwb_binaries(parent):
        candidates.append(parent)
    if project_root_cqp is not None:
        pr = project_root_cqp.resolve()
        if pr.is_dir():
            candidates.append(pr)
    if parent not in candidates:
        candidates.append(parent)
    for c in candidates:
        if c.is_dir():
            return c
    return parent


def _looks_like_registry_text(text: str) -> bool:
    return "HOME " in text and ("ATTRIBUTE" in text or "STRUCTURE" in text)


def _parse_home_info_from_registry_text(text: str) -> Tuple[Optional[str], Optional[str]]:
    home_val: Optional[str] = None
    info_val: Optional[str] = None
    for line in text.splitlines():
        s = line.strip()
        if s.startswith("HOME "):
            parts = s.split(None, 1)
            home_val = parts[1].strip() if len(parts) == 2 else None
        elif s.startswith("INFO "):
            parts = s.split(None, 1)
            info_val = parts[1].strip() if len(parts) == 2 else None
    return home_val, info_val


def _info_line_needs_attention(info_value: str, staging_root: Optional[Path]) -> bool:
    """True when INFO clearly points at staging or an old job tree (not merely a missing .info file)."""
    raw = info_value.strip()
    if not raw:
        return False
    if "flexicorp-reindex-staging" in raw:
        return True
    if staging_root is None:
        return False
    try:
        ip = Path(raw).expanduser().resolve()
        return bool(ip.is_relative_to(staging_root.resolve()))
    except (ValueError, OSError):
        return False


def _home_line_is_stale(home_value: str, staging_root: Optional[Path]) -> bool:
    raw = home_value.strip()
    if not raw:
        return True
    if "flexicorp-reindex-staging" in raw:
        return True
    try:
        hp = Path(raw).expanduser().resolve()
    except OSError:
        return True
    if staging_root is not None:
        try:
            staging_resolved = staging_root.resolve()
            if hp.is_relative_to(staging_resolved):
                return True
        except (ValueError, OSError):
            pass
    try:
        return not hp.exists()
    except OSError:
        return True


def scan_cqp_registry_path_issues(cqp_root: Path) -> List[Dict[str, Any]]:
    """
    Inspect on-disk CWB registry files under ``cqp_root`` for broken HOME/INFO
    (reindex staging paths, missing directories after host moves, etc.).

    Returns a flat list of issue dicts with ``severity`` ``error`` or ``warning``.
    """
    if not cqp_root.is_dir():
        return []
    issues: List[Dict[str, Any]] = []
    for path in cqp_root.rglob("*"):
        if not path.is_file():
            continue
        if path.suffix.lower() in _SKIP_SUFFIXES:
            continue
        try:
            size = path.stat().st_size
        except OSError:
            continue
        if size > 512_000 or size == 0:
            continue
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        if not _looks_like_registry_text(text):
            continue
        home_val, info_val = _parse_home_info_from_registry_text(text)
        reg_path = str(path.resolve())
        parent = path.parent.resolve()

        if not home_val:
            issues.append(
                {
                    "registry_file": reg_path,
                    "severity": "error",
                    "code": "home_missing_line",
                    "message": "Registry has no HOME line or empty path.",
                }
            )
        else:
            if _home_line_is_stale(home_val, None):
                if "flexicorp-reindex-staging" in home_val:
                    msg = "HOME still points at flexicorp reindex staging (or under deleted staging)."
                else:
                    msg = "HOME path does not exist (typical after copying corpus to another machine or path)."
                issues.append(
                    {
                        "registry_file": reg_path,
                        "severity": "error",
                        "code": "home_stale_or_missing",
                        "message": msg,
                        "home": home_val.strip(),
                    }
                )
            else:
                try:
                    hp = Path(home_val.strip()).expanduser().resolve()
                    if _dir_has_cwb_binaries(parent) and hp != parent:
                        issues.append(
                            {
                                "registry_file": reg_path,
                                "severity": "warning",
                                "code": "home_differs_from_registry_parent",
                                "message": "HOME exists but differs from this registry file's parent; "
                                "fine if intentional (symlinks); otherwise run corpus-health --fix.",
                                "home": str(hp),
                                "expected_home": str(parent),
                            }
                        )
                except OSError:
                    pass

        if info_val and _info_line_needs_attention(info_val, None):
            issues.append(
                {
                    "registry_file": reg_path,
                    "severity": "error",
                    "code": "info_stale_or_missing",
                    "message": "INFO path missing, under staging, or otherwise invalid.",
                    "info": info_val.strip(),
                }
            )
    return issues


def rewrite_cqp_registries_after_reindex_swap(
    live_cqp: Path, staging_dir: Optional[Path] = None
) -> int:
    """
    Rewrite registry files under ``live_cqp`` whose HOME/INFO still point at
    ``staging_dir`` (or any path under ``flexicorp-reindex-staging``).

    flexencoder writes absolute HOME via realpath; after ``shutil.move`` from
    staging to live, those paths are wrong for tools that read the on-disk registry.

    ``staging_dir`` may be omitted; stale paths are still detected when they
    contain ``flexicorp-reindex-staging`` or no longer exist on disk.
    """
    if not live_cqp.is_dir():
        return 0
    staging_root = staging_dir.resolve() if staging_dir is not None else None
    updated = 0
    for path in live_cqp.rglob("*"):
        if not path.is_file():
            continue
        if path.suffix.lower() in _SKIP_SUFFIXES:
            continue
        try:
            size = path.stat().st_size
        except OSError:
            continue
        if size > 512_000 or size == 0:
            continue
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        if not _looks_like_registry_text(text):
            continue
        lines = text.splitlines()
        new_lines: List[str] = []
        changed = False
        new_home = str(path.parent.resolve())
        new_info = str(path.parent / ".info")
        info_target_exists = (path.parent / ".info").is_file()
        for line in lines:
            stripped = line.strip()
            if stripped.startswith("HOME "):
                parts = stripped.split(None, 1)
                current = parts[1].strip() if len(parts) == 2 else ""
                if _home_line_is_stale(current, staging_root):
                    new_lines.append(f"HOME {new_home}")
                    changed = True
                else:
                    new_lines.append(line)
                continue
            if stripped.startswith("INFO "):
                parts = stripped.split(None, 1)
                current = parts[1].strip() if len(parts) == 2 else ""
                if current and _home_line_is_stale(current, staging_root):
                    if info_target_exists:
                        new_lines.append(f"INFO {new_info}")
                    else:
                        new_lines.append(f"INFO {new_home}/.info")
                    changed = True
                else:
                    new_lines.append(line)
                continue
            new_lines.append(line)
        if changed:
            try:
                path.write_text("\n".join(new_lines) + ("\n" if text.endswith("\n") else ""), encoding="utf-8")
                updated += 1
            except OSError:
                continue
    return updated
