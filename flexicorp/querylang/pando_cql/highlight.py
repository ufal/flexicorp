"""
Syntax highlighting for Pando CQL query strings (flexicorp-pando).

Tokenization follows the provisional grammar in pando_cql.pegjs; it does not
require a Peggy runtime. Validation is optional (lightweight bracket check).
"""

from __future__ import annotations

import re
from typing import Any, Dict, List, Literal

TokenKind = Literal[
    "bracket",
    "attr",
    "op",
    "string",
    "space",
    "keyword",
    "regex",
    "wildcard",
    "literal",
    "semicolon",
    "token",
]

_COMMAND_KEYWORDS = frozenset(
    {
        "count",
        "group",
        "sort",
        "freq",
        "coll",
        "dcoll",
        "cat",
        "size",
        "raw",
        "show",
        "tabulate",
        "drop",
    }
)

_OTHER_KEYWORDS = frozenset(
    {
        "with",
        "within",
        "containing",
        "having",
        "not",
        "child",
        "parent",
        "sibling",
        "descendant",
        "ancestor",
        "subtree",
        "match",
    }
)

_IDENT_RE = re.compile(r"[A-Za-z_][A-Za-z0-9_-]*")

_TWO_CHAR_OPS = (">>", "<<", "!>", "!<", "!=", "<=", ">=", "::")


def _classify_ident(text: str) -> TokenKind:
    low = text.lower()
    if low in _COMMAND_KEYWORDS or low in _OTHER_KEYWORDS:
        return "keyword"
    return "attr"


def _consume_string(source: str, start: int) -> int:
    """Double-quoted string starting at start (index of opening quote). Returns index past closing quote."""
    i = start + 1
    n = len(source)
    while i < n:
        if source[i] == "\\" and i + 1 < n:
            i += 2
            continue
        if source[i] == '"':
            return i + 1
        i += 1
    return n


def _consume_regex(source: str, start: int) -> int:
    """Regex /.../ starting at start (opening /). Returns index past closing /."""
    i = start + 1
    n = len(source)
    while i < n:
        if source[i] == "\\" and i + 1 < n:
            i += 2
            continue
        if source[i] == "/":
            return i + 1
        i += 1
    return n


def _light_validate(source: str) -> str | None:
    """Return error message if brackets/quotes look unbalanced, else None."""
    stack: List[str] = []
    i = 0
    n = len(source)
    while i < n:
        ch = source[i]
        if ch.isspace():
            i += 1
            continue
        if ch == '"':
            i = _consume_string(source, i)
            continue
        if ch == "/":
            nxt = source[i + 1] if i + 1 < n else ""
            if nxt in ("/", "*"):
                i += 1
                continue
            i = _consume_regex(source, i)
            continue
        if ch == "(":
            stack.append(")")
            i += 1
            continue
        if ch == "[":
            stack.append("]")
            i += 1
            continue
        if ch in ")":
            if not stack or stack[-1] != ")":
                return f"Unbalanced {ch!r} at offset {i}"
            stack.pop()
            i += 1
            continue
        if ch in "]":
            if not stack or stack[-1] != "]":
                return f"Unbalanced {ch!r} at offset {i}"
            stack.pop()
            i += 1
            continue
        if ch == "<":
            if source.startswith("</", i):
                i += 2
                m = _IDENT_RE.match(source, i)
                if m:
                    i = m.end()
                if i < n and source[i] == ">":
                    i += 1
                continue
            j = i + 1
            while j < n and source[j] != ">":
                j += 1
            if j >= n:
                return "Unclosed region start <...> at end of query"
            i = j + 1
            continue
        i += 1
    if stack:
        return f"Unclosed bracket: expected {stack[-1]!r}"
    return None


def _tokenize_pando_cql(source: str) -> List[Dict[str, Any]]:
    if not source:
        return []
    tokens: List[Dict[str, Any]] = []
    i = 0
    n = len(source)
    while i < n:
        if source[i].isspace():
            start = i
            while i < n and source[i].isspace():
                i += 1
            tokens.append({"text": source[start:i], "kind": "space"})
            continue

        if source[i] == '"':
            end = _consume_string(source, i)
            tokens.append({"text": source[i:end], "kind": "string"})
            i = end
            continue

        if source[i] == "/":
            nxt = source[i + 1] if i + 1 < n else ""
            if nxt in ("/", "*"):
                tokens.append({"text": source[i], "kind": "token"})
                i += 1
                continue
            end = _consume_regex(source, i)
            tokens.append({"text": source[i:end], "kind": "regex"})
            i = end
            continue

        if source[i] == "-" and i + 1 < n and source[i + 1].isdigit():
            start = i
            i += 1
            while i < n and source[i].isdigit():
                i += 1
            tokens.append({"text": source[start:i], "kind": "literal"})
            continue

        if source[i].isdigit():
            start = i
            while i < n and source[i].isdigit():
                i += 1
            tokens.append({"text": source[start:i], "kind": "literal"})
            continue

        two = source[i : i + 2] if i + 1 < n else ""
        if two in _TWO_CHAR_OPS:
            tokens.append({"text": two, "kind": "op"})
            i += 2
            continue

        if source.startswith("</", i):
            tokens.append({"text": "</", "kind": "bracket"})
            i += 2
            m = _IDENT_RE.match(source, i)
            if m:
                ident = m.group(0)
                tokens.append({"text": ident, "kind": _classify_ident(ident)})
                i = m.end()
            if i < n and source[i] == ">":
                tokens.append({"text": ">", "kind": "bracket"})
                i += 1
            continue

        if source[i] == "<":
            tokens.append({"text": "<", "kind": "bracket"})
            i += 1
            start_inner = i
            while i < n and source[i] != ">":
                i += 1
            if i < n:
                inner = source[start_inner:i]
                if inner:
                    tokens.append({"text": inner, "kind": "attr"})
                tokens.append({"text": ">", "kind": "bracket"})
                i += 1
            continue

        if source[i] in "|&%":
            tokens.append({"text": source[i], "kind": "op"})
            i += 1
            continue

        if source[i] in "()[]+*?{},":
            if source[i] == "[" and i + 1 < n and source[i + 1] == "]":
                tokens.append({"text": "[]", "kind": "wildcard"})
                i += 2
                continue
            tokens.append({"text": source[i], "kind": "bracket"})
            i += 1
            continue

        if source[i] == ";":
            tokens.append({"text": ";", "kind": "semicolon"})
            i += 1
            continue

        if source[i] in "=!<>":
            tokens.append({"text": source[i], "kind": "op"})
            i += 1
            continue

        if source[i] in ":.":
            tokens.append({"text": source[i], "kind": "op"})
            i += 1
            continue

        m = _IDENT_RE.match(source, i)
        if m:
            ident = m.group(0)
            tokens.append({"text": ident, "kind": _classify_ident(ident)})
            i = m.end()
            continue

        tokens.append({"text": source[i], "kind": "token"})
        i += 1

    return tokens


def highlight_pando_cql(
    snippet: str,
    *,
    format: Literal["tokens", "html"] = "tokens",
    validate: bool = True,
) -> Dict[str, Any]:
    snippet = (snippet or "").strip()
    out: Dict[str, Any] = {"tokens": _tokenize_pando_cql(snippet)}
    if validate and snippet:
        err = _light_validate(snippet)
        if err:
            out["parse_error"] = err
    if format == "html":
        parts: List[str] = []
        for t in out["tokens"]:
            text = t["text"]
            kind = t.get("kind", "attr")
            cls = f"flexicorp-hl-{kind}"
            parts.append(f'<span class="{cls}">{text}</span>')
        out["html"] = "".join(parts)
    return out
