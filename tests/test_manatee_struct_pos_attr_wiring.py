"""
Regression test for the Manatee structural-attr lookup.

Why this file exists
--------------------
Flexicorp previously used ``Structure.get_attr("id")`` to get the
``text.id`` / ``s.id`` handles. That returns the **raw region-indexed**
``PosAttr`` whose ``pos2str(n)`` expects ``n`` to be a region index
(0, 1, 2, … across the list of ``<text>`` elements on disk). Calling
it with a cpos — including the region-start ``beg(num_at_pos(cpos))``
that flexicorp was passing — reads out of bounds on any doc past the
first one and **segfaults the Manatee extension** (no Python
exception; ``_safe_pos2str``'s try/except can't catch a SIGSEGV).

Kontext uses the dotted form ``Corpus.get_attr("text.id")`` which
returns the ``StructPosAttr`` cpos-indexed wrapper (see Manatee
``corp/struct.cc``). Its ``pos2str(cpos)`` internally calls
``locate_rng(cpos) → num_at_pos → pa.pos2str(region_index)``. That's
the safe, Kontext-aligned path, and flexicorp now uses it too.

This test doesn't exercise the native library — it verifies the
**wiring**: that ``_doc_lookup`` and ``_sentence_id_attr`` ask the
corpus for the dotted name, and that ``_safe_pos2str`` is handed a
cpos (``match_start``), not a region-start (``doc_beg``).
"""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any, Dict, List, Tuple


REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT))

from flexicorp.backends.manatee_backend import ManateeBackend  # noqa: E402


class _FakeStructPosAttr:
    """Mimics Manatee's cpos-indexed ``StructPosAttr``.

    ``regions`` is a list of ``(beg_cpos, end_cpos_exclusive, value)``.
    ``pos2str(cpos)`` returns the value for whichever region contains cpos,
    or ``""`` (Manatee's "no match" return). Calls are recorded so the test
    can assert the caller passed a cpos, not a region index or a beg value.
    """

    def __init__(self, regions: List[Tuple[int, int, str]]) -> None:
        self.regions = regions
        self.calls: List[int] = []

    def pos2str(self, pos: int) -> str:
        self.calls.append(int(pos))
        for beg, end_excl, val in self.regions:
            if beg <= pos < end_excl:
                return val
        return ""


class _FakeStruct:
    """Mimics ``Structure`` with a region table for ``num_at_pos`` / ``beg``."""

    def __init__(self, regions: List[Tuple[int, int]]) -> None:
        self.regions = regions  # (beg, end_excl)

    def num_at_pos(self, pos: int) -> int:
        for i, (beg, end_excl) in enumerate(self.regions):
            if beg <= pos < end_excl:
                return i
        return -1

    def beg(self, n: int) -> int:
        return self.regions[n][0]

    def end(self, n: int) -> int:
        return self.regions[n][1]

    def size(self) -> int:
        return len(self.regions)


class _FakeCorpus:
    """Minimal stand-in for ``manatee.Corpus`` supporting the conf / attr
    access patterns used by :meth:`_doc_lookup` and :meth:`_sentence_id_attr`.

    Records every ``get_attr`` / ``get_struct`` call so the test can verify
    that the flexicorp code asks for the dotted names (``text.id``, ``s.id``)
    and NOT the raw struct-attr form (``struct.get_attr("id")``).
    """

    def __init__(
        self,
        conf: Dict[str, str],
        structs: Dict[str, _FakeStruct],
        pos_attrs: Dict[str, _FakeStructPosAttr],
    ) -> None:
        self._conf = conf
        self._structs = structs
        self._pos_attrs = pos_attrs
        self.get_attr_calls: List[str] = []
        self.get_struct_calls: List[str] = []

    def get_conf(self, key: str) -> str:
        return self._conf.get(key, "")

    def get_struct(self, name: str) -> _FakeStruct:
        self.get_struct_calls.append(name)
        if name in self._structs:
            return self._structs[name]
        raise KeyError(name)

    def get_attr(self, name: str) -> _FakeStructPosAttr:
        self.get_attr_calls.append(name)
        if name in self._pos_attrs:
            return self._pos_attrs[name]
        raise KeyError(name)


def _make_corpus() -> _FakeCorpus:
    # Two documents; two sentences per document.
    doc_regions = [(0, 50), (50, 100)]  # doc 0: cpos 0..49; doc 1: cpos 50..99
    s_regions = [(0, 25), (25, 50), (50, 75), (75, 100)]
    text_id_attr = _FakeStructPosAttr(
        [(0, 50, "doc-A"), (50, 100, "doc-B")]
    )
    s_id_attr = _FakeStructPosAttr(
        [
            (0, 25, "s-1"),
            (25, 50, "s-2"),
            (50, 75, "s-3"),
            (75, 100, "s-4"),
        ]
    )
    return _FakeCorpus(
        conf={"DOCSTRUCTURE": "text", "STRUCTLIST": "text,s"},
        structs={
            "text": _FakeStruct(doc_regions),
            "s": _FakeStruct(s_regions),
        },
        pos_attrs={
            "text.id": text_id_attr,
            "s.id": s_id_attr,
        },
    )


def test_doc_lookup_uses_corpus_get_attr_with_dotted_name() -> None:
    """
    Proof-of-wiring: :meth:`_doc_lookup` must ask the corpus for
    ``"text.id"`` (the ``StructPosAttr`` wrapper), not rely on
    ``Structure.get_attr("id")`` which returns a region-indexed attr.
    """
    corpus = _make_corpus()
    backend = ManateeBackend()

    doc_struct, doc_id_attr, _title_attr = backend._doc_lookup(corpus)

    assert doc_struct is not None
    assert doc_id_attr is not None
    # Crucial: the fetch came via ``corpus.get_attr("text.id")`` — that's
    # how we get the cpos-indexed wrapper. If this assertion fails, the
    # segfault will be back as soon as there's more than one document.
    assert "text.id" in corpus.get_attr_calls, (
        f"expected corpus.get_attr('text.id'); saw {corpus.get_attr_calls!r}"
    )


def test_sentence_id_attr_uses_corpus_get_attr_with_dotted_name() -> None:
    corpus = _make_corpus()
    backend = ManateeBackend()

    sentence_id_attr = backend._sentence_id_attr(corpus)

    assert sentence_id_attr is not None
    assert "s.id" in corpus.get_attr_calls, (
        f"expected corpus.get_attr('s.id'); saw {corpus.get_attr_calls!r}"
    )


def test_doc_id_pos2str_receives_cpos_not_region_index_or_beg() -> None:
    """
    Pass a cpos from the *second* document (50..99) through the
    :meth:`_safe_pos2str` path. The returned value must be ``"doc-B"`` —
    only possible if ``pos2str`` got the cpos (``53``), not:

      * the region index (which would be ``1`` → out of bounds for an
        attr with only two *value slots* on some builds, or would pick
        the wrong value if treated as a cpos), or
      * ``doc_beg`` (``50`` → would still pick doc-B here because our
        fake is tolerant, but the real raw struct attr would crash).

    We also assert the recorded call argument to prove the cpos path.
    """
    corpus = _make_corpus()
    backend = ManateeBackend()
    _doc_struct, doc_id_attr, _ = backend._doc_lookup(corpus)

    match_start = 53
    value = backend._safe_pos2str(doc_id_attr, match_start)

    assert value == "doc-B"
    assert doc_id_attr.calls == [53], (
        "expected pos2str to be called with cpos=53; got "
        f"{doc_id_attr.calls!r}"
    )


def test_sentence_id_pos2str_also_gets_cpos() -> None:
    corpus = _make_corpus()
    backend = ManateeBackend()
    sentence_id_attr = backend._sentence_id_attr(corpus)

    # cpos 80 falls inside s-4 (75..100).
    value = backend._safe_pos2str(sentence_id_attr, 80)
    assert value == "s-4"
    assert sentence_id_attr.calls == [80]


def test_regression_raw_struct_attr_would_have_crashed_second_doc() -> None:
    """
    Demonstration of *why* the old code crashed: if we had used the raw
    struct attr (region-indexed), passing ``doc_beg`` (a cpos) as the
    argument to ``pos2str`` would be read as a region index. On a corpus
    with just two regions, ``pos2str(doc_beg=50)`` would index past the
    region array. This fake mimics that failure mode by raising
    IndexError — the real Manatee extension segfaults instead.
    """

    class _RawRegionIndexedAttr:
        """Emulates ``Structure.get_attr('id')`` — expects a region index."""

        def __init__(self, values: List[str]) -> None:
            self.values = values
            self.calls: List[int] = []

        def pos2str(self, n: int) -> str:
            self.calls.append(n)
            if n < 0 or n >= len(self.values):
                raise IndexError(f"region index {n} out of range")
            return self.values[n]

    raw = _RawRegionIndexedAttr(["doc-A", "doc-B"])

    # This is what the OLD code did: computed doc_beg via _struct_beg_containing
    # (returns the cpos where the region starts — here, 50) and passed THAT
    # to pos2str. On the real extension, this reads out of bounds → SIGSEGV.
    backend = ManateeBackend()
    corpus = _make_corpus()
    doc_struct = corpus.get_struct("text")
    doc_beg = backend._struct_beg_containing(doc_struct, 53)
    assert doc_beg == 50  # cpos where doc-B starts

    # Simulate the OLD behaviour end-to-end.
    try:
        raw.pos2str(doc_beg)
    except IndexError as exc:
        err = str(exc)
    else:
        err = None
    assert err is not None and "out of range" in err, (
        "the pre-fix path must fail against a region-indexed attr; "
        "if this assertion fails, the test's premise is wrong."
    )

    # And the NEW behaviour: cpos-indexed wrapper handles it cleanly.
    _doc_struct, doc_id_attr, _ = backend._doc_lookup(corpus)
    assert backend._safe_pos2str(doc_id_attr, 53) == "doc-B"


TESTS = [
    test_doc_lookup_uses_corpus_get_attr_with_dotted_name,
    test_sentence_id_attr_uses_corpus_get_attr_with_dotted_name,
    test_doc_id_pos2str_receives_cpos_not_region_index_or_beg,
    test_sentence_id_pos2str_also_gets_cpos,
    test_regression_raw_struct_attr_would_have_crashed_second_doc,
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
