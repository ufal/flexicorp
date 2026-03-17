"""
Syntax highlighting for Manatee CQL query strings.

Validation uses a local PEG bridge generated from Kontext's CQL grammar, while
tokenization stays lightweight so the output matches flexicorp's existing
highlight contract.
"""

from __future__ import annotations

from typing import Any, Dict, List, Literal

from .peg_bridge import ManateeCqlPegError, parse_manatee_cql

TokenKind = Literal[
    "bracket",
    "attr",
    "op",
    "string",
    "space",
    "keyword",
    "regex",
    "number",
    "token",
]

_KEYWORDS = frozenset({"within", "containing", "meet", "union", "mu", "f", "ws", "term", "swap", "ccoll"})
_OPERATORS = ("==", "<=", ">=", "!=", "~", "=", "!", "|", "&", "*", "+", "?", ".", ",", ":", "/", "#", "-")


def _escape_html(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def _match_operator(source: str, idx: int) -> str | None:
    for op in _OPERATORS:
        if source.startswith(op, idx):
            return op
    return None


def _consume_quoted(source: str, idx: int) -> int:
    i = idx + 1
    while i < len(source):
        if source[i] == "\\" and i + 1 < len(source):
            i += 2
            continue
        if source[i] == '"':
            return i + 1
        i += 1
    return len(source)


def _tokenize_manatee_cql(source: str) -> List[Dict[str, Any]]:
    tokens: List[Dict[str, Any]] = []
    i = 0
    n = len(source)
    while i < n:
        ch = source[i]
        if ch.isspace():
            start = i
            while i < n and source[i].isspace():
                i += 1
            tokens.append({"text": source[start:i], "kind": "space"})
            continue
        if ch in "[]{}()<>":
            tokens.append({"text": ch, "kind": "bracket"})
            i += 1
            continue
        if ch == '"':
            end = _consume_quoted(source, i)
            text = source[i:end]
            inner = text[1:-1]
            kind: TokenKind = "regex" if any(mark in inner for mark in ("|", "*", "+", "?", "{", "}", "[", "]", "(", ")", "\\p")) else "string"
            tokens.append({"text": text, "kind": kind})
            i = end
            continue
        op = _match_operator(source, i)
        if op is not None:
            tokens.append({"text": op, "kind": "op"})
            i += len(op)
            continue
        if ch.isdigit() or (ch == "-" and i + 1 < n and source[i + 1].isdigit()):
            start = i
            i += 1
            while i < n and (source[i].isdigit() or source[i] == "."):
                i += 1
            tokens.append({"text": source[start:i], "kind": "number"})
            continue
        if ch.isalpha() or ch in {"_", "@"}:
            start = i
            i += 1
            while i < n and (source[i].isalnum() or source[i] in {"_", "@", "."}):
                i += 1
            ident = source[start:i]
            kind: TokenKind = "keyword" if ident.lower() in _KEYWORDS else "attr"
            tokens.append({"text": ident, "kind": kind})
            continue
        tokens.append({"text": ch, "kind": "token"})
        i += 1
    return tokens


def highlight_manatee_cql(
    snippet: str,
    *,
    format: Literal["tokens", "html"] = "tokens",
    validate: bool = True,
) -> Dict[str, Any]:
    snippet = snippet or ""
    out: Dict[str, Any] = {"tokens": _tokenize_manatee_cql(snippet)}
    if validate and snippet.strip():
        try:
            parse_manatee_cql(snippet, start_rule="Query")
        except ManateeCqlPegError as exc:
            out["parse_error"] = str(exc)
            out["parse_error_type"] = exc.error_type
            if exc.error_line is not None:
                out["parse_error_line"] = exc.error_line
            if exc.error_column is not None:
                out["parse_error_column"] = exc.error_column
            if exc.error_offset is not None:
                out["parse_error_offset"] = exc.error_offset
            if exc.error_end_line is not None:
                out["parse_error_end_line"] = exc.error_end_line
            if exc.error_end_column is not None:
                out["parse_error_end_column"] = exc.error_end_column
            if exc.error_end_offset is not None:
                out["parse_error_end_offset"] = exc.error_end_offset
    if format == "html":
        parts: List[str] = []
        for token in out["tokens"]:
            kind = str(token.get("kind") or "token")
            text = str(token.get("text") or "")
            parts.append(f'<span class="flexicorp-hl-{kind}">{_escape_html(text)}</span>')
        out["html"] = "".join(parts)
    return out
