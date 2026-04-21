"""
Regression test for the conditional ``TYPE "FD_MI"`` override in
``prepare_runtime_registry``.

Why this file exists
====================

Flexicorp's manatee writer (``flexicorp/manatee_writer.py``) emits the
``.text`` file in *int_text* format (FINIT header + int32 LE per token)
and needs the runtime registry to advertise ``TYPE "FD_MI"`` — without
that override Manatee defaults to *delta_text* and fails to open the
corpus.

But the TEITOK / Kontext build path (``cwb-decode`` + ``encodevert`` +
``compilecorp``) writes ``.text`` in delta_text format. In that case,
forcing ``FD_MI`` tells Manatee to read a delta_text file as if it were
int_text → it reads garbage offsets → SIGSEGV inside ``_manatee.so`` the
moment a ``Concordance`` touches that attribute.

Symptom that triggered the fix: ``[form="bez"]`` crashed Manatee on a
TEITOK-built corpus while Kontext — reading the exact same files —
worked perfectly. ``[word="bez"]`` happened to work because ``word`` was
typed by encodevert in a way that made the misread benign.

The fix (``flexicorp/backends/manatee.py::prepare_runtime_registry``)
decides per-attribute using the **ground-truth format of the on-disk
``.text`` file**:

  1. If the parsed registry summary already has an explicit ``TYPE``
     for the attr — honour it (Kontext/encodevert may set one).
  2. Otherwise, peek at ``<name>.text``:
       - FINIT header (``\\xa3finIT``) → int_text → force ``TYPE "FD_MI"``.
       - No FINIT header               → delta_text → pass through
         (Manatee default ``MD_MD`` handles the segment index file).
       - File missing                  → pass through (let Manatee
         error naturally).

These tests pin that behaviour down so it never silently regresses.

S14 (the earlier incarnation) used the presence of ``<name>.text.seg``
as a weaker proxy. Some Kontext corpora don't ship ``.text.seg`` next
to ``.text`` — that broke the proxy and brought the SIGSEGV back. S15
replaces the proxy with the FINIT-header probe on the ``.text`` file,
which is authoritative.
"""

from __future__ import annotations

import sys
import tempfile
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT))

from flexicorp.backends.manatee import (  # noqa: E402
    _FINIT_TEXT_HEADER,
    _text_file_format_code,
    prepare_runtime_registry,
)
from flexicorp.config import ManateeConfig  # noqa: E402


# A minimal realistic int_text payload: FINIT header (15 bytes) +
# one 4-byte int32 LE token id. Shape matches what flexicorp's own
# ``manatee_writer`` produces.
_INT_TEXT_SAMPLE = _FINIT_TEXT_HEADER + b"\x00\x00\x00\x00"


def _write_registry(
    registry_dir: Path,
    corpus_name: str,
    data_path: Path,
    attr_lines: str,
) -> Path:
    """Write a minimal Manatee registry file and return its path."""
    body = (
        f'NAME "{corpus_name}"\n'
        f'PATH  "{data_path}"\n'
        'ENCODING "utf-8"\n'
        f"{attr_lines}\n"
    )
    reg_file = registry_dir / corpus_name
    reg_file.write_text(body, encoding="utf-8")
    return reg_file


def _read_runtime_registry(setup) -> str:
    """Read back the runtime registry that ``prepare_runtime_registry`` wrote."""
    runtime_file = (
        setup.runtime_registry_dir / setup.summary.registry_file.name
    )
    return runtime_file.read_text(encoding="utf-8")


# ---------------------------------------------------------------------------
# Ground-truth helper
# ---------------------------------------------------------------------------

def test_text_file_format_code_recognises_finit_header() -> None:
    """A ``.text`` with the FINIT magic is classified as int_text."""
    with tempfile.TemporaryDirectory() as td:
        p = Path(td) / "form.text"
        p.write_bytes(_INT_TEXT_SAMPLE)
        assert _text_file_format_code(p) == "int_text"


def test_text_file_format_code_recognises_delta_text() -> None:
    """A ``.text`` that does NOT start with FINIT is classified as delta_text."""
    with tempfile.TemporaryDirectory() as td:
        p = Path(td) / "form.text"
        # Anything that isn't the FINIT prefix — use a few arbitrary
        # bytes that could be the start of a delta_text payload.
        p.write_bytes(b"\x01\x02\x03\x04\x05\x06\x07\x08")
        assert _text_file_format_code(p) == "delta_text"


def test_text_file_format_code_returns_none_when_missing() -> None:
    """A missing ``.text`` yields None (caller passes declaration through)."""
    with tempfile.TemporaryDirectory() as td:
        p = Path(td) / "does_not_exist.text"
        assert _text_file_format_code(p) is None


# ---------------------------------------------------------------------------
# prepare_runtime_registry integration
# ---------------------------------------------------------------------------

def test_int_text_on_disk_forces_fdmi_override() -> None:
    """Flexicorp-built corpus: ``.text`` begins with FINIT → force FD_MI.

    This is the one case where we MUST rewrite the bare declaration;
    without it, Manatee tries to read the int_text file as delta_text
    and fails (either ENOENT on ``.text.seg`` or garbage offsets).
    """
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        (data_dir / "word.text").write_bytes(_INT_TEXT_SAMPLE)
        _write_registry(registry_dir, "demo", data_dir, "ATTRIBUTE word")

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        assert 'ATTRIBUTE "word" {' in runtime_text, runtime_text
        assert 'TYPE "FD_MI"' in runtime_text, runtime_text


def test_delta_text_on_disk_passes_through_verbatim() -> None:
    """Kontext-built corpus: ``.text`` does NOT start with FINIT.

    S15 regression: an earlier heuristic keyed on ``.text.seg``
    presence, but some Kontext corpora don't ship ``.text.seg`` next to
    ``.text``. We now read the ``.text`` header directly — no FINIT →
    delta_text → pass through. Forcing FD_MI here is what produced the
    ``[form="bez"]`` SIGSEGV.
    """
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        # Delta-text shaped bytes, no FINIT header.
        (data_dir / "form.text").write_bytes(b"\x01\x02\x03\x04\x05\x06\x07\x08")
        # Deliberately NO form.text.seg: this is the exact shape where
        # the S14 heuristic would have wrongly forced FD_MI.
        _write_registry(registry_dir, "demo", data_dir, "ATTRIBUTE form")

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        assert "ATTRIBUTE form" in runtime_text, runtime_text
        assert 'TYPE "FD_MI"' not in runtime_text, runtime_text
        assert 'ATTRIBUTE "form" {' not in runtime_text, runtime_text


def test_delta_text_on_disk_with_seg_still_passes_through() -> None:
    """Belt-and-braces: when ``.text.seg`` DOES exist, delta_text on disk
    must still pass through. This was already correct under S14; ensure
    S15's stricter probe doesn't accidentally flip it."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        (data_dir / "form.text").write_bytes(b"\x01\x02\x03\x04\x05\x06\x07\x08")
        (data_dir / "form.text.seg").write_bytes(b"\x00" * 16)
        _write_registry(registry_dir, "demo", data_dir, "ATTRIBUTE form")

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        assert "ATTRIBUTE form" in runtime_text
        assert 'TYPE "FD_MI"' not in runtime_text


def test_bare_attribute_with_explicit_type_passes_through() -> None:
    """Attr with an explicit multi-line ``TYPE`` block keeps its declaration.

    If the existing registry already declares a ``TYPE`` we MUST NOT
    double-wrap it — even if the on-disk ``.text`` format would suggest
    a different type. Honouring the author's explicit declaration also
    matches Kontext's behaviour.
    """
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        # Deliberately int_text on disk but explicit TYPE "FD_FGD" in
        # the registry — the registry wins.
        (data_dir / "lemma.text").write_bytes(_INT_TEXT_SAMPLE)
        attr_block = (
            "ATTRIBUTE lemma {\n"
            '    TYPE "FD_FGD"\n'
            "}"
        )
        _write_registry(registry_dir, "demo", data_dir, attr_block)

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        assert 'TYPE "FD_FGD"' in runtime_text, runtime_text
        assert 'TYPE "FD_MI"' not in runtime_text, runtime_text


def test_missing_text_file_passes_declaration_through() -> None:
    """``<name>.text`` doesn't exist → don't guess, pass through.

    The rewrite logic used to inject ``TYPE "FD_MI"`` whenever it
    couldn't detect delta_text. S15 fixes that: when we can't see the
    file we leave the declaration alone so Manatee reports its own
    error instead of us silently advertising a wrong format.
    """
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        # No ghost.text at all.
        _write_registry(registry_dir, "demo", data_dir, "ATTRIBUTE ghost")

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        assert "ATTRIBUTE ghost" in runtime_text
        assert 'TYPE "FD_MI"' not in runtime_text


def test_mixed_attrs_get_per_attribute_ground_truth_decision() -> None:
    """Different attrs in the same registry get classified independently
    based on each one's ``.text`` file."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        registry_dir = root / "reg"
        registry_dir.mkdir()
        data_dir = root / "corp"
        data_dir.mkdir()
        # form: delta_text (Kontext style).
        (data_dir / "form.text").write_bytes(b"\x01\x02\x03\x04\x05\x06\x07\x08")
        # word: int_text (flexicorp style).
        (data_dir / "word.text").write_bytes(_INT_TEXT_SAMPLE)
        _write_registry(
            registry_dir,
            "demo",
            data_dir,
            "ATTRIBUTE form\nATTRIBUTE word",
        )

        cfg = ManateeConfig(registry=str(registry_dir), corpus="demo")
        setup = prepare_runtime_registry(cfg)
        runtime_text = _read_runtime_registry(setup)

        # form: pass-through, no FD_MI.
        assert "ATTRIBUTE form" in runtime_text
        # word: FD_MI forced.
        assert 'ATTRIBUTE "word" {' in runtime_text
        assert 'TYPE "FD_MI"' in runtime_text


if __name__ == "__main__":
    # Allow running this file directly without pytest.
    test_text_file_format_code_recognises_finit_header()
    test_text_file_format_code_recognises_delta_text()
    test_text_file_format_code_returns_none_when_missing()
    test_int_text_on_disk_forces_fdmi_override()
    test_delta_text_on_disk_passes_through_verbatim()
    test_delta_text_on_disk_with_seg_still_passes_through()
    test_bare_attribute_with_explicit_type_passes_through()
    test_missing_text_file_passes_declaration_through()
    test_mixed_attrs_get_per_attribute_ground_truth_decision()
    print("All S15 ground-truth conditional FD_MI regression tests passed.")
