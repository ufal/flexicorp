"""
Transport-agnostic post-export adapter for PML-TQ deployments.

This module is intended to be used as ``pmltq_import_cmd`` from the PMLTQ backend
reindex flow. It supports:

- local copy/sync into a host path mounted by Docker/Kubernetes/etc.
- ssh+rsync delivery to a remote VM/cluster node.

Defaults are driven by environment variables emitted by ``PmltqBackend.reindex``.
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Iterable, List


def _as_bool(raw: str | None, *, default: bool = False) -> bool:
    if raw is None:
        return default
    s = str(raw).strip().lower()
    if s == "":
        return default
    return s in {"1", "true", "yes", "on"}


def _list_payload_files(pml_dir: Path) -> List[Path]:
    if not pml_dir.is_dir():
        return []
    wanted_suffixes = (".a", ".m", ".t", ".w", ".pml", ".xml")
    out: List[Path] = []
    for fp in sorted(pml_dir.rglob("*")):
        if not fp.is_file():
            continue
        if fp.name.endswith(".flat.xml"):
            out.append(fp)
            continue
        if fp.suffix.lower() in wanted_suffixes:
            out.append(fp)
    return out


def _ensure_parent(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)


def _copy_local(*, src_root: Path, files: Iterable[Path], dest_root: Path) -> int:
    copied = 0
    for src in files:
        rel = src.relative_to(src_root)
        dst = dest_root / rel
        _ensure_parent(dst)
        shutil.copy2(src, dst)
        copied += 1
    return copied


def _run_activate_cmd(*, cmd: str, cwd: Path, env: dict, remote: str = "") -> None:
    if not cmd.strip():
        return
    if remote:
        qcmd = cmd.replace('"', '\\"')
        full = f'ssh {remote} "{qcmd}"'
    else:
        full = cmd
    proc = subprocess.run(full, cwd=str(cwd), text=True, capture_output=True, check=False, shell=True, env=env)
    if proc.returncode != 0:
        raise RuntimeError(
            f"Activation command failed (exit {proc.returncode}): {(proc.stderr or proc.stdout).strip()}"
        )
    if proc.stdout.strip():
        print(proc.stdout.strip())


def main(argv: List[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="PML-TQ import adapter (local/ssh).")
    ap.add_argument("--mode", default=os.environ.get("PMLTQ_IMPORT_MODE", "local"), choices=["local", "ssh"])
    ap.add_argument("--pml", type=Path, default=None, help="PML source directory (default: FLEXICORP_PML_DIR).")
    ap.add_argument(
        "--target-root",
        type=Path,
        default=None,
        help="Target root path. Required unless PMLTQ_IMPORT_TARGET_DIR is set.",
    )
    ap.add_argument(
        "--append-treebank",
        action="store_true",
        default=_as_bool(os.environ.get("PMLTQ_IMPORT_APPEND_TREEBANK", "1"), default=True),
        help="Append treebank id as subdirectory under target root.",
    )
    ap.add_argument(
        "--remote",
        default=os.environ.get("PMLTQ_IMPORT_REMOTE", "").strip(),
        help="Remote ssh target for mode=ssh (e.g. user@host).",
    )
    ap.add_argument(
        "--activate-cmd",
        default=os.environ.get("PMLTQ_IMPORT_ACTIVATE_CMD", "").strip(),
        help="Optional activation command after sync.",
    )
    args = ap.parse_args(argv)

    pml_raw = args.pml or Path(os.environ.get("FLEXICORP_PML_DIR", "").strip() or ".")
    pml_dir = pml_raw.expanduser().resolve()
    if not pml_dir.is_dir():
        print(f"pmltq_import_adapter: PML source dir does not exist: {pml_dir}", file=sys.stderr)
        return 1

    files = _list_payload_files(pml_dir)
    if not files:
        print(f"pmltq_import_adapter: no payload files found in {pml_dir}", file=sys.stderr)
        return 1

    target_root = args.target_root or Path(os.environ.get("PMLTQ_IMPORT_TARGET_DIR", "").strip() or "")
    if str(target_root).strip() == "":
        print("pmltq_import_adapter: set --target-root or PMLTQ_IMPORT_TARGET_DIR", file=sys.stderr)
        return 1
    target_root = target_root.expanduser().resolve()

    treebank = str(os.environ.get("FLEXICORP_PMLTQ_TREEBANK", "")).strip()
    dest_root = target_root / treebank if (args.append_treebank and treebank) else target_root

    if args.mode == "local":
        dest_root.mkdir(parents=True, exist_ok=True)
        copied = _copy_local(src_root=pml_dir, files=files, dest_root=dest_root)
        print(f"pmltq_import_adapter: copied {copied} file(s) to {dest_root}")
        _run_activate_cmd(cmd=args.activate_cmd, cwd=Path.cwd(), env=os.environ.copy())
        return 0

    # mode == ssh
    remote = str(args.remote or "").strip()
    if not remote:
        print("pmltq_import_adapter: mode=ssh requires --remote or PMLTQ_IMPORT_REMOTE", file=sys.stderr)
        return 1
    rsync = shutil.which("rsync")
    if not rsync:
        print("pmltq_import_adapter: rsync is required for mode=ssh", file=sys.stderr)
        return 1

    remote_dest = f"{remote}:{str(dest_root)}/"
    cmd = f'{rsync} -a --delete "{pml_dir}/" "{remote_dest}"'
    proc = subprocess.run(cmd, text=True, capture_output=True, check=False, shell=True, env=os.environ.copy())
    if proc.returncode != 0:
        print(
            f"pmltq_import_adapter: rsync failed (exit {proc.returncode}): {(proc.stderr or proc.stdout).strip()}",
            file=sys.stderr,
        )
        return 1
    print(f"pmltq_import_adapter: synced {pml_dir} -> {remote_dest}")
    _run_activate_cmd(cmd=args.activate_cmd, cwd=Path.cwd(), env=os.environ.copy(), remote=remote)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

