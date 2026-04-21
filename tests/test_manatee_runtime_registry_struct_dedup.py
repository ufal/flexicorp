"""
Regression test for S16: duplicate ``ATTRIBUTE`` inside a ``STRUCTURE``
block.

Why this file exists
====================

Flexicorp's own corpus re-indexer (``manatee_backend._build_registry_text``)
used to unconditionally append ``ATTRIBUTE id`` to the ``text`` structure,
on the assumption that ``text.id`` had to be declared. When the caller's
``sattributes_by_region['text']`` already contained ``id``, the result
was a registry with two identical ``ATTRIBUTE id`` lines in the same
``STRUCTURE text`` block.

Manatee's native corpus-open path then allocated one ``PosAttr`` per
declaration, keyed by name. The second declaration clobbered the first,
leaving a dangling internal pointer; the next ``Concordance`` build
SIGSEGVd inside ``_manatee.so`` (caught on the host with faulthandler
at ``manatee.py:1052``).

The live repro was ``[form="bez"]`` on the TEITOK corpus
``infoveillance``: S14 + S15 cleared the ``FD_MI`` mis-declaration, the
debug trace (``FLEXICORP_MANATEE_DEBUG=1``) then showed the duplicate
``ATTRIBUTE id`` under ``STRUCTURE text`` — matching this test.

Two fixes, both exercised here:

  * ``prepare_runtime_registry`` now silently drops duplicate
    ``ATTRIBUTE`` lines inside any ``STRUCTURE { … }`` block, so
    already-built corpora stop crashing without a rebuild.
  * ``ManateeBackend._build_registry_text`` dedupes at write time so
    newly-built corpora never emit the duplicate in the first place.
"""

from __future__ import annotations

import sys
import tempfile
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT))

from flexicorp.backends.manatee import prepare_runtime_registry  # noqa: E402
from flexicorp.backends.manatee_backend import ManateeBackend  # noqa: E402
from flexicorp.config import ManateeConfig  # noqa: E402


def _write_registry(
    registry_dir: Path,
    corpus_name: str,
    data_path: Path,
    body: str,
) -> Path:
    reg_file = registry_dir / corpus_name
    reg_file.write_text(body, encoding="utf-8")
    return reg_file


def _read_runtime_registry(setup) -> str:
    runtime_file = setup.runtime_registry_dir / setup.summary.registry_file.name
    return runtime_file.read_text(encoding="utf-8")


# ---------------------------------------------------------------------------
# prepare_runtime_registry rewrite: drop duplicate struct ATTRIBUTE lines
# ---------------------------------------------------------------------------

def test_duplicate_struct_attribute_is_deduped_in_runtime_registry() -> None:
    """Reproduces the live ``infoveillance`` scenario: ``STRUCTURE text``
    has two ``ATTRIBUTE id`` lines. The runtime registry must keep only
    one."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        body = (
            'NAME "demo"\n'
            f'PATH "{data_dir}"\n'
            'ENCODING "utf-8"\n'
            "\n"
            "STRUCTURE text {\n"
            "    ATTRIBUTE pubdate\n"
            "    ATTRIBUTE id\n"
            "    ATTRIBUTE id\n"
            "}\n"
        )
        _write_registry(registry_dir, "demo", data_dir, body)

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        # Exactly one ATTRIBUTE id line inside STRUCTURE text.
        assert runtime_text.count("ATTRIBUTE id") == 1, runtime_text
        # pubdate is preserved (the dedupe is name-scoped, not line-scoped).
        assert "ATTRIBUTE pubdate" in runtime_text, runtime_text


def test_duplicate_struct_attribute_at_end_is_deduped() -> None:
    """Exact shape of the live registry: the duplicate is the LAST line
    in the block (builder appended it unconditionally)."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        body = (
            'NAME "demo"\n'
            f'PATH "{data_dir}"\n'
            'ENCODING "utf-8"\n'
            "\n"
            "STRUCTURE text {\n"
            "    ATTRIBUTE pubdate\n"
            "    ATTRIBUTE pubtime\n"
            "    ATTRIBUTE source\n"
            "    ATTRIBUTE author\n"
            "    ATTRIBUTE type\n"
            "    ATTRIBUTE media\n"
            "    ATTRIBUTE id\n"
            "    ATTRIBUTE id\n"
            "}\n"
        )
        _write_registry(registry_dir, "demo", data_dir, body)

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        assert runtime_text.count("ATTRIBUTE id") == 1, runtime_text
        # All the original non-duplicate attrs survive.
        for name in ("pubdate", "pubtime", "source", "author", "media"):
            assert f"ATTRIBUTE {name}" in runtime_text, name


def test_same_attribute_name_in_different_structures_is_fine() -> None:
    """``s.id`` and ``text.id`` are both valid — dedupe must be
    per-structure, not global."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        body = (
            'NAME "demo"\n'
            f'PATH "{data_dir}"\n'
            'ENCODING "utf-8"\n'
            "\n"
            "STRUCTURE s {\n"
            "    ATTRIBUTE id\n"
            "}\n"
            "\n"
            "STRUCTURE text {\n"
            "    ATTRIBUTE id\n"
            "}\n"
        )
        _write_registry(registry_dir, "demo", data_dir, body)

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        # Two ATTRIBUTE id lines total, each in its own structure.
        assert runtime_text.count("ATTRIBUTE id") == 2, runtime_text
        assert "STRUCTURE s {" in runtime_text
        assert "STRUCTURE text {" in runtime_text


def test_positional_attribute_dedupe_is_not_applied() -> None:
    """Positional-level ATTRIBUTE lines must NOT be dedupe-dropped.

    Manatee does not actually allow duplicate positional declarations
    either, but flexicorp shouldn't silently swallow them — the caller
    should see Manatee's own diagnostic. The dedupe logic only fires
    inside STRUCTURE blocks.
    """
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        # Two bare positional declarations.
        body = (
            'NAME "demo"\n'
            f'PATH "{data_dir}"\n'
            'ENCODING "utf-8"\n'
            "ATTRIBUTE form\n"
            "ATTRIBUTE form\n"
        )
        _write_registry(registry_dir, "demo", data_dir, body)

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        # Both lines survived — flexicorp did NOT dedupe positionals.
        assert runtime_text.count("ATTRIBUTE form") == 2, runtime_text


# ---------------------------------------------------------------------------
# _build_registry_text: never emit duplicate ATTRIBUTE in the first place
# ---------------------------------------------------------------------------

def _render_registry(
    *,
    pattributes,
    sattributes_by_region,
    corpus_name="demo",
    title="Demo",
):
    backend = ManateeBackend()
    # _build_registry_text is @staticmethod on ManateeBackend.
    return backend._build_manatee_registry_text(  # type: ignore[attr-defined]
        corpus_name=corpus_name,
        title=title,
        vrt_path=Path("/tmp/vrt.vrt"),
        corp_path=Path("/tmp/corp"),
        pattributes=pattributes,
        sattributes_by_region=sattributes_by_region,
    )


def test_build_registry_dedupe_id_when_caller_already_provided_it() -> None:
    """If the caller already listed ``id`` in ``sattributes_by_region['text']``,
    the builder must NOT append a second ``ATTRIBUTE id`` line."""
    text = _render_registry(
        pattributes=["form"],
        sattributes_by_region={
            "text": ["pubdate", "pubtime", "source", "author", "media", "id"],
        },
    )
    # Find the STRUCTURE text block and count ATTRIBUTE id lines inside.
    block = text.split("STRUCTURE text {", 1)[1].split("}", 1)[0]
    assert block.count("ATTRIBUTE id") == 1, block


def test_build_registry_adds_id_when_caller_forgot_it() -> None:
    """If the caller didn't include ``id``, the builder still injects
    it — that's the legitimate use case of the append logic."""
    text = _render_registry(
        pattributes=["form"],
        sattributes_by_region={
            "text": ["pubdate", "pubtime"],
        },
    )
    block = text.split("STRUCTURE text {", 1)[1].split("}", 1)[0]
    assert block.count("ATTRIBUTE id") == 1, block


def test_build_registry_dedupes_arbitrary_struct_attrs() -> None:
    """Dedupe isn't special-cased for ``id`` — any duplicate struct attr
    gets collapsed."""
    text = _render_registry(
        pattributes=["form"],
        sattributes_by_region={
            "text": ["source", "source", "id"],
        },
    )
    block = text.split("STRUCTURE text {", 1)[1].split("}", 1)[0]
    assert block.count("ATTRIBUTE source") == 1, block
    assert block.count("ATTRIBUTE id") == 1, block


if __name__ == "__main__":
    test_duplicate_struct_attribute_is_deduped_in_runtime_registry()
    test_duplicate_struct_attribute_at_end_is_deduped()
    test_same_attribute_name_in_different_structures_is_fine()
    test_positional_attribute_dedupe_is_not_applied()
    test_build_registry_dedupe_id_when_caller_already_provided_it()
    test_build_registry_adds_id_when_caller_forgot_it()
    test_build_registry_dedupes_arbitrary_struct_attrs()
    print("All S16 struct-dedupe regression tests passed.")
