"""
Regression tests for the JSON-encoded quote tolerance in the CWB-CQL parser.

Motivation
----------
Some TEITOK UI code paths stringify the query as JSON but forget to decode it
before placing it on argv. What the parser ends up seeing is therefore e.g.:

    [form=\u0022bez\u0022]      (literal 6-char sequence \\u0022, not a `"`)

…instead of the intended `[form="bez"]`. Without tolerance for this, the
parser rejects the input as a non-simple constraint, and the user sees "0
hits" + "flexicorp did not return valid JSON" in the UI.

The parser now decodes `\\u0022` / `\\u0027` at the top of the pipeline. This
is strictly safe — those byte sequences are never meaningful inside a
CWB-CQL token, since CWB-CQL uses the raw quote characters as delimiters.

These tests pin the intended behavior so it doesn't silently regress.
"""

from __future__ import annotations

import sys
from pathlib import Path

# Make `flexicorp` importable when running this file directly.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from flexicorp.querylang.cwb_cql import parse_cwb_cql
from flexicorp.querylang.cwb_cql.parser import (
    _JSON_QUOTE_ESCAPES,
    _normalize_json_unicode_quotes,
)


def test_double_encoded_double_quote_decodes() -> None:
    # This is the literal 22-char string TEITOK has been shipping.
    src = r"[form=\u0022bez\u0022]"
    q = parse_cwb_cql(src)
    assert len(q.pattern.items) == 1
    c = q.pattern.items[0].constraint
    assert c.left.name == "form"
    assert c.op == "="
    assert c.right.value == "bez", f"expected 'bez', got {c.right.value!r}"


def test_double_encoded_single_quote_decodes() -> None:
    # Single quotes aren't the primary CWB-CQL delimiter but we still forgive
    # the encoded form for symmetry — the caller may have quoted with ' and
    # then stringified. This is a future-proofing test.
    src = r"[form=\u0022o\u0027clock\u0022]"
    q = parse_cwb_cql(src)
    c = q.pattern.items[0].constraint
    assert c.right.value == "o'clock", f"unexpected value {c.right.value!r}"


def test_normal_quoted_query_untouched() -> None:
    src = '[form="bez"]'
    q = parse_cwb_cql(src)
    c = q.pattern.items[0].constraint
    assert c.left.name == "form"
    assert c.right.value == "bez"


def test_non_quote_unicode_escape_in_regex_is_left_alone() -> None:
    # A legitimate regex pattern inside a CQL string that happens to contain
    # some `\u...` sequence that is NOT `\u0022` / `\u0027` must be preserved
    # verbatim (it's up to downstream / _unescape_string to decide what to do
    # with it). This pins the narrow scope of our normalisation.
    src = r'[word="a\u00e9b"]'    # \u00e9 is é — not one of our targets
    normalized = _normalize_json_unicode_quotes(src)
    assert r"\u00e9" in normalized, (
        "Expected \\u00e9 to be preserved verbatim; got " + repr(normalized)
    )


def test_input_without_unicode_escapes_is_passthrough() -> None:
    # Short-circuit: if there's no \\u / \\U in the input, return it verbatim
    # without even running the replace chain.
    src = '[form="bez" & tag="N.*"]'
    assert _normalize_json_unicode_quotes(src) is src


def test_empty_and_none_inputs() -> None:
    assert _normalize_json_unicode_quotes("") == ""
    assert _normalize_json_unicode_quotes(None) is None  # type: ignore[arg-type]


def test_cli_end_to_end_does_not_return_parse_error() -> None:
    # Exercises the exact failure mode the user reported: a query with
    # literal \u0022 in it must NOT produce the "Only simple token
    # constraints" parse error any more. Folder is nonexistent so we don't
    # actually run CWB — we just check that the parser accepts the input.
    import json
    import subprocess
    proc = subprocess.run(
        [
            sys.executable, "-m", "flexicorp",
            "query", r"[form=\u0022bez\u0022]",
            "--api", "--backend", "flexi",
            "--folder", "/tmp/flexicorp-test-nonexistent",
            "--teitok", "yes",
            "--query-language", "cwb-cql",
            "--corpus-format", "cwb",
            "--limit", "5", "--window", "2", "--start", "0",
            "--context-format", "xml", "--context-scope", "s",
        ],
        capture_output=True, text=True, cwd=str(Path(__file__).resolve().parent.parent),
    )
    assert proc.returncode == 0, f"CLI exited {proc.returncode}; stderr: {proc.stderr!r}"
    # stdout must be valid JSON on its own (no stderr contamination).
    doc = json.loads(proc.stdout)
    errs = doc.get("done", {}).get("errors", [])
    # The specific parse error must NOT appear any more.
    for e in errs:
        assert "Only simple token constraints" not in e, (
            f"Parse error not suppressed; got: {e}"
        )


TESTS = [
    test_double_encoded_double_quote_decodes,
    test_double_encoded_single_quote_decodes,
    test_normal_quoted_query_untouched,
    test_non_quote_unicode_escape_in_regex_is_left_alone,
    test_input_without_unicode_escapes_is_passthrough,
    test_empty_and_none_inputs,
    test_cli_end_to_end_does_not_return_parse_error,
]


def _run() -> int:
    passed = 0
    failed = 0
    for t in TESTS:
        try:
            t()
        except Exception as e:
            print(f"[FAIL] {t.__name__}: {type(e).__name__}: {e}")
            failed += 1
        else:
            print(f"[ok]   {t.__name__}")
            passed += 1
    print(f"{passed}/{len(TESTS)} passed")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(_run())
