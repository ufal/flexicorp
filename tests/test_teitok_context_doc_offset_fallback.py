"""
Regression tests for the doc-offset XML fallback in ``flexicorp.teitok_context``.

Why this file exists
--------------------
On Manatee corpora that were built without ``s.id`` as a structural attribute
(or without ``id`` as a positional attribute on disk), the Manatee backend
cannot tell ``resolve_teitok_context`` either:

* a known sentence ``xml:id``, or
* the matched tokens' real ``<tok xml:id="…">`` values.

All it has are cpos bounds (Manatee's ``match_start`` / ``match_end``) plus
the cpos ``beg`` of the containing ``<text>`` document (via
``doc_struct.num_at_pos`` + ``doc_struct.beg``). Before the fallback was
added, ``extract_teitok_fragment_xml`` was given surface-form tokens (e.g.
``["bez"]``) as ``tok_ids``, found nothing matching ``<tok xml:id="bez">``
in the XML, and returned None — so the produced hits had no ``context``
field at all and the TEITOK UI rendered single-token context only.

The new ``extract_teitok_fragment_xml_by_doc_offset`` walks the XML in
document order, picks out the Nth ``<tok>``/``<dtok>`` (where N =
``match_start - doc_beg``), and walks up to the requested scope
(typically ``<s>``).

These tests pin both the helper and its wiring through
``resolve_teitok_context``.
"""

from __future__ import annotations

import sys
import tempfile
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT))

from flexicorp.teitok_context import (  # noqa: E402
    extract_teitok_fragment_xml_by_doc_offset,
    resolve_teitok_context,
)


_TEITOK_DOC = """<?xml version="1.0" encoding="UTF-8"?>
<text xml:id="d1">
  <s xml:id="s-1">
    <tok xml:id="t-1">Hello</tok>
    <tok xml:id="t-2">there</tok>
    <tok xml:id="t-3">.</tok>
  </s>
  <s xml:id="s-2">
    <tok xml:id="t-4">Bez</tok>
    <tok xml:id="t-5">prizn\u00e1n\u00ed</tok>
    <tok xml:id="t-6">to</tok>
    <tok xml:id="t-7">nep\u016fjde</tok>
    <tok xml:id="t-8">.</tok>
  </s>
  <s xml:id="s-3">
    <tok xml:id="t-9">Konec</tok>
    <tok xml:id="t-10">.</tok>
  </s>
</text>
"""


def _make_doc(tmpdir: Path, name: str = "d1.xml") -> Path:
    xml_dir = tmpdir / "xmlfiles"
    xml_dir.mkdir(parents=True, exist_ok=True)
    p = xml_dir / name
    p.write_text(_TEITOK_DOC, encoding="utf-8")
    return p


def test_doc_offset_finds_sentence_for_match_in_middle_sentence() -> None:
    """offset 3 (the 4th <tok> = ``Bez``) belongs to ``<s xml:id="s-2">``."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        fragment, scope, sid, tok_ids = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            token_offset_start=3,
            token_offset_end=3,
            scope="s",
        )
        assert fragment is not None, "expected a fragment for offset=3"
        assert scope == "s"
        assert sid == "s-2", f"expected sentence id s-2; got {sid!r}"
        assert tok_ids == ["t-4"], f"unexpected tok_ids {tok_ids!r}"
        # The returned XML must be the entire <s xml:id="s-2"> element.
        assert "Bez" in fragment
        assert "s-2" in fragment
        assert "Hello" not in fragment


def test_doc_offset_handles_first_token() -> None:
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        fragment, scope, sid, tok_ids = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            token_offset_start=0,
            token_offset_end=0,
            scope="s",
        )
        assert fragment is not None
        assert sid == "s-1"
        assert tok_ids == ["t-1"]


def test_doc_offset_multi_token_match_keeps_all_tok_ids() -> None:
    """A multi-token match returns all the matched tok ids in order."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        # offsets 3..6 = "Bez", "prizn\u00e1n\u00ed", "to", "nep\u016fjde"
        fragment, scope, sid, tok_ids = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            token_offset_start=3,
            token_offset_end=6,
            scope="s",
        )
        assert fragment is not None
        assert sid == "s-2"
        assert tok_ids == ["t-4", "t-5", "t-6", "t-7"]


def test_doc_offset_out_of_range_returns_none() -> None:
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        fragment, _scope, _sid, _tok_ids = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            token_offset_start=999,
            token_offset_end=999,
            scope="s",
        )
        assert fragment is None


def test_doc_offset_negative_offset_returns_none() -> None:
    """Defensive: a negative offset (e.g. match_start < doc_beg) is rejected."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        fragment, _scope, _sid, _tok_ids = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            token_offset_start=-1,
            token_offset_end=0,
            scope="s",
        )
        assert fragment is None


def test_doc_offset_scope_tok_returns_just_tokens() -> None:
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        fragment, scope, sid, tok_ids = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            token_offset_start=3,
            token_offset_end=4,
            scope="tok",
        )
        assert fragment is not None
        assert scope == "tok"
        # No enclosing element; sid is None for tok scope.
        assert sid is None
        assert tok_ids == ["t-4", "t-5"]
        assert "Bez" in fragment
        assert "prizn" in fragment
        # Should NOT include the surrounding <s> wrapper text.
        assert "<s " not in fragment


def test_doc_offset_missing_xml_file_returns_none() -> None:
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        # Note: no _make_doc(root) call — file doesn't exist.
        fragment, _scope, _sid, _tok_ids = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="missing.xml",
            token_offset_start=0,
            token_offset_end=0,
            scope="s",
        )
        assert fragment is None


def test_resolve_uses_doc_offset_when_no_sentence_id_or_tok_ids() -> None:
    """End-to-end: simulates the Manatee no-s.id case the user reported.

    Caller has ``doc_id``, ``match_start``, ``match_end``, ``doc_cpos_base`` —
    but no ``sentence_id`` and only surface-form tokens. The primary path
    (``extract_teitok_fragment_xml``) must fail; the offset fallback must
    pick up.
    """
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        # Doc starts at cpos 100 (arbitrary). Match at cpos 103 = the 4th tok = "Bez".
        ctx = resolve_teitok_context(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            sentence_id=None,
            tok_ids=["bez"],  # surface form — won't match <tok xml:id>
            match_start=103,
            match_end=103,
            context_spec={"scope": "s", "format": "xml", "prefer": "xml", "fallback": True},
            xidx_resolver=None,
            doc_cpos_base=100,
        )
        assert ctx is not None, "expected context from doc-offset fallback"
        assert ctx["source"] == "xml-doc-offset"
        assert ctx["scope"] == "s"
        assert ctx["format"] == "xml"
        # Locator must reflect what we discovered, not the surface-form input.
        loc = ctx["locator"]
        assert loc.get("sentence_id") == "s-2"
        assert loc.get("token_ids") == ["t-4"]
        assert "Bez" in ctx["data"]
        assert "s-2" in ctx["data"]


def test_resolve_prefers_primary_path_when_sentence_id_known() -> None:
    """When the caller already has a real sentence_id, the primary path wins
    and the offset fallback isn't consulted (its source label would otherwise
    leak through). Pin this to avoid silent regression of the dispatch order."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        ctx = resolve_teitok_context(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            sentence_id="s-2",
            tok_ids=[],  # let sentence_id alone drive lookup
            match_start=103,
            match_end=103,
            context_spec={"scope": "s", "format": "xml", "prefer": "xml", "fallback": True},
            xidx_resolver=None,
            doc_cpos_base=100,
        )
        assert ctx is not None
        assert ctx["source"] == "xml-fallback"
        assert ctx["locator"].get("sentence_id") == "s-2"


def test_resolve_returns_none_without_doc_cpos_base() -> None:
    """No doc_cpos_base + no sentence_id + bogus tok_ids → no context. This
    is the pre-fix behaviour and is still correct when the caller can't
    supply the doc base offset (e.g. CWB backend, which has its own xidx)."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _make_doc(root)
        ctx = resolve_teitok_context(
            root_dir=root,
            searchfolder="xmlfiles",
            doc_id="d1.xml",
            sentence_id=None,
            tok_ids=["bez"],
            match_start=103,
            match_end=103,
            context_spec={"scope": "s", "format": "xml", "prefer": "xml", "fallback": True},
            xidx_resolver=None,
            doc_cpos_base=None,
        )
        assert ctx is None


TESTS = [
    test_doc_offset_finds_sentence_for_match_in_middle_sentence,
    test_doc_offset_handles_first_token,
    test_doc_offset_multi_token_match_keeps_all_tok_ids,
    test_doc_offset_out_of_range_returns_none,
    test_doc_offset_negative_offset_returns_none,
    test_doc_offset_scope_tok_returns_just_tokens,
    test_doc_offset_missing_xml_file_returns_none,
    test_resolve_uses_doc_offset_when_no_sentence_id_or_tok_ids,
    test_resolve_prefers_primary_path_when_sentence_id_known,
    test_resolve_returns_none_without_doc_cpos_base,
]


def _run() -> int:
    passed = failed = 0
    for t in TESTS:
        try:
            t()
        except Exception as exc:
            print(f"[FAIL] {t.__name__}: {type(exc).__name__}: {exc}")
            failed += 1
        else:
            print(f"[ok]   {t.__name__}")
            passed += 1
    print(f"{passed}/{len(TESTS)} passed")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(_run())
