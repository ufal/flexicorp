"""
Regression test for the Kontext-aligned ``KWICLines`` call in
``ManateeBackend.query``.

Why this file exists
--------------------
Earlier revisions of the Manatee backend read token strings directly from
``<attr>.text`` + ``<attr>.lex`` files on disk and derived ``doc_id`` /
``sentence_id`` via per-hit ``StructPosAttr.pos2str`` calls. That duplicated
logic Manatee already implements for Kontext — and diverged from it in a
few places, which showed up as:

* ``toks: []`` on a query that clearly matched something (the scaffold
  read returned all-empty strings and the downstream filter dropped them);
* ``sentence_id`` differing from the one Kontext shows when you toggle
  "View → show structures: s_id" (Kontext uses ``KWICLines`` ``refs`` for
  that, we used our own ``pos2str`` path).

The user's directive is: for everything standard-Manatee, follow Kontext
*exactly*, and only deviate for TEITOK XML context. The canonical Kontext
reference is ``lib/kwiclib/__init__.py::kwiclines`` (the
``manatee.KWICLines(..., refs)`` call) and ``lib/conclib/__init__.py::
get_full_ref`` (which uses ``corp.get_attr(n).pos2str(pos)``).

This test doesn't exercise the native library. It pins down the **wiring**:

* :meth:`_flat_surface_tokens` correctly turns the native flat ``(str, cls,
  str, cls, …)`` sequence into a list of surface strings, dropping the
  ``strc`` / ``attr`` class markers exactly like Kontext's
  ``tokens2strclass`` + ``split_chunk``.
* ``ManateeBackend.query`` constructs ``KWICLines`` with the Kontext-style
  argument profile (``attrs="word"``, ``refs="=text.id,=s.id"``, a leading
  ``-`` on ``left_ctx``) and reads ``doc_id`` / ``sentence_id`` from
  ``kl.get_ref_list()``.
"""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any, Dict, List, Tuple


REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT))

from flexicorp.backends.manatee_backend import ManateeBackend  # noqa: E402


# --------------------------------------------------------------------------- #
# _flat_surface_tokens
# --------------------------------------------------------------------------- #

def test_flat_surface_tokens_drops_strc_and_attr_markers() -> None:
    # Kontext ``tokens2strclass`` input is a flat sequence of alternating
    # (str, class) pairs. ``strc`` marks a structural tag like ``<s>``;
    # ``attr`` marks an attribute row. Neither is a real token.
    pairs = [
        "dog", "",
        "<s>", "{strc}",
        "ran", "",
        "fast", "",
        "[pos=NN]", "{attr}",
        "</s>", "{strc}",
    ]
    assert ManateeBackend._flat_surface_tokens(pairs) == ["dog", "ran", "fast"]


def test_flat_surface_tokens_splits_glued_words() -> None:
    # With multiple ``attrs`` Manatee can glue tokens like "the quick" into a
    # single chunk (Kontext's ``split_chunk`` re-splits on whitespace).
    pairs = ["the quick brown", "", "fox", ""]
    assert ManateeBackend._flat_surface_tokens(pairs) == [
        "the", "quick", "brown", "fox",
    ]


def test_flat_surface_tokens_handles_bytes_values() -> None:
    pairs = [b"caf\xc3\xa9", "", "au", "", b"lait", ""]
    assert ManateeBackend._flat_surface_tokens(pairs) == ["café", "au", "lait"]


def test_flat_surface_tokens_handles_empty_input() -> None:
    assert ManateeBackend._flat_surface_tokens([]) == []
    assert ManateeBackend._flat_surface_tokens(()) == []


def test_flat_surface_tokens_odd_length_input() -> None:
    # Defensive: a trailing token without a class marker shouldn't crash.
    pairs = ["lone", "", "tail"]
    assert ManateeBackend._flat_surface_tokens(pairs) == ["lone", "tail"]


# --------------------------------------------------------------------------- #
# query() wiring: KWICLines args + refs-based doc_id / sentence_id
# --------------------------------------------------------------------------- #

class _FakeKWICLines:
    """Mimics ``manatee.KWICLines`` for one scripted page of results.

    Records its own constructor args so the test can assert the Kontext
    argument profile, and yields successive lines via :meth:`nextline`.
    """

    last_ctor_args: Tuple[Any, ...] = ()

    def __init__(
        self,
        corpus: Any,
        rs: Any,
        leftctx: str,
        rightctx: str,
        attrs: str,
        ctxattrs: str,
        structs: str,
        refs: str,
    ) -> None:
        _FakeKWICLines.last_ctor_args = (
            corpus, rs, leftctx, rightctx, attrs, ctxattrs, structs, refs,
        )
        # Two canned hits — match spans in two different documents, so the
        # doc_id path is also exercised across a region boundary.
        self._lines: List[Dict[str, Any]] = [
            {
                "pos": 10, "kwiclen": 1,
                "left": ["the", "", "big", ""],
                "kwic": ["bez", ""],
                "right": ["ran", "", "fast", ""],
                "refs": ["doc-A", "s-1"],
            },
            {
                "pos": 57, "kwiclen": 1,
                "left": ["slow", "", "fox", ""],
                "kwic": ["bez", ""],
                "right": ["eats", "", "lunch", ""],
                "refs": ["doc-B", "s-3"],
            },
        ]
        self._cursor = -1

    def nextline(self) -> bool:
        self._cursor += 1
        return self._cursor < len(self._lines)

    def _cur(self) -> Dict[str, Any]:
        return self._lines[self._cursor]

    def get_pos(self) -> int:
        return self._cur()["pos"]

    def get_kwiclen(self) -> int:
        return self._cur()["kwiclen"]

    def get_left(self) -> List[str]:
        return self._cur()["left"]

    def get_kwic(self) -> List[str]:
        return self._cur()["kwic"]

    def get_right(self) -> List[str]:
        return self._cur()["right"]

    def get_ref_list(self) -> List[str]:
        return self._cur()["refs"]


class _FakeConcordance:
    def __init__(self, corpus: Any) -> None:
        self._size = 2
        self._corpus = corpus

    def size(self) -> int:
        return self._size

    def sync(self) -> None:
        return None

    def RS(self, *_args: Any, **_kwargs: Any) -> Dict[str, Any]:
        return {"rs": True}

    def corp(self) -> Any:
        # KonText's ``kwiclines()`` uses ``self.conc.corp()`` rather than the
        # original corpus handle (parallel corpora / subcorpora); return the
        # same underlying object so identity checks against the backing
        # corpus still hold in tests.
        return self._corpus


class _FakeStructPosAttr:
    """Minimal cpos-indexed struct-attr wrapper."""

    def __init__(self, regions: List[Tuple[int, int, str]]) -> None:
        self.regions = regions

    def pos2str(self, cpos: int) -> str:
        for beg, end_excl, val in self.regions:
            if beg <= cpos < end_excl:
                return val
        return ""


class _FakeStruct:
    def __init__(self, regions: List[Tuple[int, int]]) -> None:
        self.regions = regions

    def num_at_pos(self, cpos: int) -> int:
        for i, (beg, end_excl) in enumerate(self.regions):
            if beg <= cpos < end_excl:
                return i
        return -1

    def beg(self, n: int) -> int:
        return self.regions[n][0]

    def end(self, n: int) -> int:
        return self.regions[n][1]

    def size(self) -> int:
        return len(self.regions)


class _FakeCorpus:
    """Enough surface area to satisfy ``ManateeBackend.query`` wiring.

    Not all ``query()`` code paths are exercised — we stub out the config /
    scaffold / open_corpus bits via monkeypatches in the test, and only the
    parts that hang off the real ``corpus_kw`` stay live.
    """

    def __init__(self) -> None:
        self._conf = {
            "DOCSTRUCTURE": "text",
            "STRUCTLIST": "text,s",
            "ENCODING": "utf-8",
        }
        self._structs = {
            "text": _FakeStruct([(0, 50), (50, 100)]),
            "s": _FakeStruct([(0, 25), (25, 50), (50, 75), (75, 100)]),
        }
        self._pos_attrs = {
            "text.id": _FakeStructPosAttr([(0, 50, "doc-A"), (50, 100, "doc-B")]),
            "s.id": _FakeStructPosAttr([
                (0, 25, "s-1"), (25, 50, "s-2"),
                (50, 75, "s-3"), (75, 100, "s-4"),
            ]),
        }

    def get_conf(self, key: str) -> str:
        return self._conf.get(key, "")

    def get_struct(self, name: str) -> _FakeStruct:
        if name in self._structs:
            return self._structs[name]
        raise KeyError(name)

    def get_attr(self, name: str) -> _FakeStructPosAttr:
        if name in self._pos_attrs:
            return self._pos_attrs[name]
        raise KeyError(name)

    def size(self) -> int:
        return 100

    def search_size(self) -> int:
        return 100


class _FakeManateeModule:
    """Stand-in for the ``manatee`` Python module; swapped into the backend
    via monkeypatching :meth:`_load_manatee_module`."""

    Concordance = None  # filled in per-test

    def __init__(self) -> None:
        self.KWICLines = _FakeKWICLines


def _install_fakes(monkeypatch_targets: List[Tuple[Any, str, Any]]) -> None:
    """Install temporary attribute replacements on a target object."""
    for target, name, value in monkeypatch_targets:
        setattr(target, name, value)


def test_query_uses_kontext_kwiclines_argument_profile() -> None:
    """
    Prove that :meth:`ManateeBackend.query` constructs ``KWICLines`` with the
    Kontext argument profile — attrs="word", ctxattrs="word", structs="",
    refs="=text.id,=s.id", and a signed left context ("-5").
    """
    backend = ManateeBackend()
    fake_corpus = _FakeCorpus()
    fake_conc = _FakeConcordance(fake_corpus)
    fake_manatee = _FakeManateeModule()

    # Capture calls to ``manatee.Concordance(corpus, query, 0, -1)``.
    def _conc_factory(*_args: Any, **_kwargs: Any) -> _FakeConcordance:
        return fake_conc
    fake_manatee.Concordance = _conc_factory  # type: ignore[assignment]

    # Stub the pieces of ``query()`` that hang off disk / runtime.
    orig_get_config = backend._get_config
    orig_open_corpus = backend._open_corpus
    orig_load_manatee_module = backend._load_manatee_module
    orig_detect_teitok = backend._detect_teitok
    try:
        backend._get_config = lambda _p: type(  # type: ignore[assignment]
            "Cfg",
            (),
            {"registry": "/tmp/reg", "corpus": "c"},
        )()
        backend._open_corpus = lambda _cfg: fake_corpus  # type: ignore[assignment]
        backend._load_manatee_module = lambda _p: fake_manatee  # type: ignore[assignment]
        backend._detect_teitok = lambda _p: None  # type: ignore[assignment]

        # Also neutralise the scaffold load — we don't need lexicon files
        # for this test and the real loader would hit the filesystem.
        from flexicorp.backends import manatee_backend as mb_mod

        orig_scaffold = mb_mod.load_manatee_corpus_scaffold
        mb_mod.load_manatee_corpus_scaffold = lambda _cfg: None  # type: ignore[assignment]
        try:
            result = backend.query({
                "project": {},
                "params": {"query": '[word="bez"]', "max": 10},
            })
        finally:
            mb_mod.load_manatee_corpus_scaffold = orig_scaffold  # type: ignore[assignment]
    finally:
        backend._get_config = orig_get_config  # type: ignore[assignment]
        backend._open_corpus = orig_open_corpus  # type: ignore[assignment]
        backend._load_manatee_module = orig_load_manatee_module  # type: ignore[assignment]
        backend._detect_teitok = orig_detect_teitok  # type: ignore[assignment]

    # -------- argument profile --------
    (
        corpus_arg, _rs, leftctx, rightctx,
        attrs, ctxattrs, structs, refs,
    ) = _FakeKWICLines.last_ctor_args
    assert corpus_arg is fake_corpus, "KWICLines must get conc.corp()-derived corpus."
    assert leftctx.startswith("-"), (
        f"left context must be signed per Kontext; got {leftctx!r}."
    )
    assert rightctx and rightctx[0] not in "-", (
        f"right context must be a positive magnitude; got {rightctx!r}."
    )
    assert attrs == "word" and ctxattrs == "word", (
        f"attrs/ctxattrs should both be 'word'; got {attrs!r}/{ctxattrs!r}."
    )
    assert structs == "", (
        f"structs must be empty (no inline <s>/<text>); got {structs!r}."
    )
    assert refs == "=text.id,=s.id", (
        f"refs must push structural ids via Kontext pattern; got {refs!r}."
    )

    # -------- shape + values --------
    hits = result["hits"]
    assert len(hits) == 2, f"expected 2 hits; got {len(hits)}."
    # Hit 0 spans doc-A; hit 1 spans doc-B. Both come from refs, not pos2str.
    assert hits[0]["doc_id"] == "doc-A"
    assert hits[0]["sentence_id"] == "s-1"
    assert hits[0]["toks"] == ["bez"]
    assert hits[0]["left_toks"] == ["the", "big"]
    assert hits[0]["right_toks"] == ["ran", "fast"]
    assert hits[1]["doc_id"] == "doc-B"
    assert hits[1]["sentence_id"] == "s-3"


def test_query_left_context_gets_sign_prefix_when_caller_omits_it() -> None:
    """
    Manatee treats a bare positive ``leftctx`` as "N structural units" rather
    than "N tokens back" and can segfault when there's no matching struct.
    Kontext sidesteps this by always signing the value; we normalise any
    user-supplied value to match. This test pins down the normalisation —
    see the comment block above the ``KWICLines`` call.
    """
    backend = ManateeBackend()
    fake_corpus = _FakeCorpus()
    fake_conc = _FakeConcordance(fake_corpus)
    fake_manatee = _FakeManateeModule()
    fake_manatee.Concordance = lambda *_a, **_kw: fake_conc  # type: ignore[assignment]

    orig_get_config = backend._get_config
    orig_open_corpus = backend._open_corpus
    orig_load_manatee_module = backend._load_manatee_module
    orig_detect_teitok = backend._detect_teitok
    from flexicorp.backends import manatee_backend as mb_mod

    orig_scaffold = mb_mod.load_manatee_corpus_scaffold
    try:
        backend._get_config = lambda _p: type(  # type: ignore[assignment]
            "Cfg",
            (),
            {"registry": "/tmp/reg", "corpus": "c"},
        )()
        backend._open_corpus = lambda _cfg: fake_corpus  # type: ignore[assignment]
        backend._load_manatee_module = lambda _p: fake_manatee  # type: ignore[assignment]
        backend._detect_teitok = lambda _p: None  # type: ignore[assignment]
        mb_mod.load_manatee_corpus_scaffold = lambda _cfg: None  # type: ignore[assignment]

        backend.query({
            "project": {},
            "params": {
                "query": '[word="bez"]',
                "max": 10,
                # Caller passes a bare positive — we must normalise.
                "left_context": "7",
                "right_context": "7",
            },
        })
    finally:
        backend._get_config = orig_get_config  # type: ignore[assignment]
        backend._open_corpus = orig_open_corpus  # type: ignore[assignment]
        backend._load_manatee_module = orig_load_manatee_module  # type: ignore[assignment]
        backend._detect_teitok = orig_detect_teitok  # type: ignore[assignment]
        mb_mod.load_manatee_corpus_scaffold = orig_scaffold  # type: ignore[assignment]

    leftctx = _FakeKWICLines.last_ctor_args[2]
    rightctx = _FakeKWICLines.last_ctor_args[3]
    assert leftctx == "-7", f"leftctx should be auto-signed; got {leftctx!r}"
    assert rightctx == "7", f"rightctx should stay unsigned; got {rightctx!r}"


TESTS = [
    test_flat_surface_tokens_drops_strc_and_attr_markers,
    test_flat_surface_tokens_splits_glued_words,
    test_flat_surface_tokens_handles_bytes_values,
    test_flat_surface_tokens_handles_empty_input,
    test_flat_surface_tokens_odd_length_input,
    test_query_uses_kontext_kwiclines_argument_profile,
    test_query_left_context_gets_sign_prefix_when_caller_omits_it,
]


def _run() -> int:
    passed = failed = 0
    for t in TESTS:
        try:
            t()
        except Exception as exc:
            import traceback
            print(f"[FAIL] {t.__name__}: {type(exc).__name__}: {exc}")
            traceback.print_exc()
            failed += 1
        else:
            print(f"[ok]   {t.__name__}")
            passed += 1
    print(f"{passed}/{len(TESTS)} passed")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(_run())
