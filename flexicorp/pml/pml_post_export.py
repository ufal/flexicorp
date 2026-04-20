"""
Hook run after flexencoder + JSONL→PML conversion (``flexicorp reindex --backend pmltq``).

Default import step: print deployment hints. With ``--convert``, (re-)runs
:class:`flexicorp.pml.jsonl_to_pml.convert_jsonl_to_pml` so the hook can be
used standalone.
"""

from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path


def _print_followup(export_dir: Path, pml_dir: Path, treebank: str) -> None:
    print(f"PML directory: {pml_dir}")
    print(f"JSONL export: {export_dir}")
    if treebank:
        print(f"Treebank id (flexicorp): {treebank}")
    print("")
    print(
        "PML-TQ Server treebanks reference on-disk data (see dataSources / layer paths). "
        "Copy or symlink the PML directory into the layout your deployment expects, "
        "then run your database compile/load step (admin UI or pmltq tooling)."
    )


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="PML-TQ post-export hook for flexicorp.")
    p.add_argument(
        "--convert",
        action="store_true",
        help="Run JSONL→PML conversion (default: only print guidance).",
    )
    p.add_argument(
        "--jsonl",
        type=Path,
        metavar="DIR",
        help="Directory with docs.jsonl / toks.jsonl (default: FLEXICORP_PMLTQ_EXPORT_DIR).",
    )
    p.add_argument(
        "--pml",
        type=Path,
        metavar="DIR",
        help="PML output directory (default: FLEXICORP_PML_DIR or <jsonl>/pml).",
    )
    args = p.parse_args(argv)

    ex = args.jsonl
    if ex is None:
        raw = os.environ.get("FLEXICORP_PMLTQ_EXPORT_DIR", "").strip()
        if not raw:
            print("flexicorp.pml.pml_post_export: set --jsonl or FLEXICORP_PMLTQ_EXPORT_DIR", file=sys.stderr)
            return 1
        ex = Path(raw)
    ex = ex.resolve()

    pml = args.pml
    if pml is None:
        raw = os.environ.get("FLEXICORP_PML_DIR", "").strip()
        pml = Path(raw) if raw else (ex / "pml")
    pml = pml.resolve()

    treebank = os.environ.get("FLEXICORP_PMLTQ_TREEBANK", "").strip()

    if args.convert:
        from flexicorp.pml.jsonl_to_pml import convert_jsonl_to_pml

        lang = os.environ.get("FLEXICORP_PML_LANG", "en").strip() or "en"
        result = convert_jsonl_to_pml(ex, pml, lang=lang)
        print(result.get("message", result))
        if not result.get("ok"):
            return 1

    _print_followup(ex, pml, treebank)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
