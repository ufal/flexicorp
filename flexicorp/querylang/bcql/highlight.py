"""
Syntax highlighting for BlackLab Corpus Query Language (BCQL).

This is a lightweight tokenizer derived from the published BlackLab ANTLR
grammar. It is aimed at query-editor highlighting, not full query execution.
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
    "literal",
    "name",
    "token",
    "wildcard",
]

_KEYWORDS = frozenset({"within", "containing", "overlap", "in"})
_LITERALS = frozenset({"true", "false"})
_NAME_RE = re.compile(r"[A-Za-z_][A-Za-z0-9_\-]*")
_NUMBER_RE = re.compile(r"[0-9]+")
_ROOT_DEP_OP_RE = re.compile(r"\^-(?:[^\- '\"](?:[^\- '\"]|-(?!>))*)?->[A-Za-z0-9_\-]*")
_DEP_OP_RE = re.compile(r"!?-(?:[^\- '\"](?:[^\- '\"]|-(?!>))*)?->[A-Za-z0-9_\-]*")
_ALIGNMENT_OP_RE = re.compile(r"=(?:[^= '\"](?:[^= '\"]|=(?!>))*)?=>[A-Za-z0-9_\-]*\??")
_LOOKAHEAD_OPS = ("?<=", "?<!", "?=", "?!")
_OPERATORS = ("::", "!=", ">=", "<=", "->", "&", "|", "!", "=", ">", "<", "/", ":", "*", "+", "?", ",", ";", ".")


def _escape_html(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def _peek_nonspace(source: str, idx: int) -> str:
    while idx < len(source) and source[idx].isspace():
        idx += 1
    return source[idx] if idx < len(source) else ""


def _consume_comment(source: str, idx: int) -> int:
    if source.startswith("#", idx):
        end = source.find("\n", idx)
        return len(source) if end == -1 else end
    if source.startswith("/*", idx):
        end = source.find("*/", idx + 2)
        return len(source) if end == -1 else end + 2
    return idx


def _consume_quoted(source: str, idx: int) -> int:
    quote = source[idx]
    i = idx + 1
    while i < len(source):
        if source[i] == "\\" and i + 1 < len(source):
            i += 2
            continue
        if source[i] == quote:
            return i + 1
        i += 1
    return len(source)


def _match_special_operator(source: str, idx: int) -> str | None:
    for op in _LOOKAHEAD_OPS:
        if source.startswith(op, idx):
            return op
    for regex in (_ROOT_DEP_OP_RE, _DEP_OP_RE, _ALIGNMENT_OP_RE):
        m = regex.match(source, idx)
        if m:
            return m.group(0)
    for op in _OPERATORS:
        if source.startswith(op, idx):
            return op
    return None


def _tokenize_bcql(source: str) -> List[Dict[str, Any]]:
    if not source:
        return []
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
        if source.startswith("#", i) or source.startswith("/*", i):
            end = _consume_comment(source, i)
            tokens.append({"text": source[i:end], "kind": "token"})
            i = end
            continue
        if ch == "_" and (i + 1 == n or not (source[i + 1].isalnum() or source[i + 1] == "_")):
            tokens.append({"text": "_", "kind": "wildcard"})
            i += 1
            continue
        if ch in {'"', "'"} or (ch == "l" and i + 1 < n and source[i + 1] in {'"', "'"}):
            start = i
            if ch == "l":
                i += 1
            end = _consume_quoted(source, i)
            tokens.append({"text": source[start:end], "kind": "string"})
            i = end
            continue
        if ch in "[]{}()":
            if ch == "[" and i + 1 < n and source[i + 1] == "]":
                tokens.append({"text": "[]", "kind": "wildcard"})
                i += 2
            else:
                tokens.append({"text": ch, "kind": "bracket"})
                i += 1
            continue
        if ch in "<>":
            tokens.append({"text": ch, "kind": "bracket"})
            i += 1
            continue
        op = _match_special_operator(source, i)
        if op is not None:
            tokens.append({"text": op, "kind": "op"})
            i += len(op)
            continue
        m_num = _NUMBER_RE.match(source, i)
        if m_num:
            text = m_num.group(0)
            tokens.append({"text": text, "kind": "literal"})
            i = m_num.end()
            continue
        m_name = _NAME_RE.match(source, i)
        if m_name:
            text = m_name.group(0)
            lower = text.lower()
            next_char = _peek_nonspace(source, m_name.end())
            if lower in _KEYWORDS:
                kind: TokenKind = "keyword"
            elif lower in _LITERALS:
                kind = "literal"
            elif next_char == ":":
                kind = "name"
            else:
                kind = "attr"
            tokens.append({"text": text, "kind": kind})
            i = m_name.end()
            continue
        tokens.append({"text": ch, "kind": "token"})
        i += 1
    return tokens


def highlight_bcql(
    snippet: str,
    *,
    format: Literal["tokens", "html"] = "tokens",
    validate: bool = True,
) -> Dict[str, Any]:
    del validate  # Reserved for future parser-backed validation.
    snippet = snippet or ""
    out: Dict[str, Any] = {"tokens": _tokenize_bcql(snippet)}
    if format == "html":
        parts: List[str] = []
        for token in out["tokens"]:
            kind = str(token.get("kind") or "token")
            text = str(token.get("text") or "")
            parts.append(f'<span class="flexicorp-hl-{kind}">{_escape_html(text)}</span>')
        out["html"] = "".join(parts)
    return out
