"""
Syntax highlighting for ClickCQL query strings.

Validation is delegated to the local PEG bridge so CLI/backend use gets the same
parser as the legacy ClickCQL frontend, without requiring a browser.
"""

from __future__ import annotations

from typing import Any, Dict, List, Literal, Optional

from .peg_bridge import ClickCqlPegError, parse_clickcql

TokenKind = Literal[
    "bracket",
    "field",
    "op",
    "string",
    "space",
    "keyword",
    "name",
    "regex",
    "literal",
    "token",
]

_KEYWORDS = frozenset(
    {
        "within",
        "show",
        "named",
        "group",
        "freq",
        "count",
        "sort",
        "by",
        "tabulate",
        "coll",
        "dcoll",
        "cat",
        "raw",
        "size",
    }
)
_OPERATORS = ("::", "<<", ">>", "!<", "!>", "!~", "~*", "~=", "!=", "<=", ">=", "&", "|", ">", "<", "=", ".", ":", ",", ";", "(", ")")


def _escape_html(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def _peek_nonspace(source: str, idx: int) -> str:
    n = len(source)
    while idx < n and source[idx].isspace():
        idx += 1
    return source[idx] if idx < n else ""


def _match_operator(source: str, idx: int) -> Optional[str]:
    for op in _OPERATORS:
        if source.startswith(op, idx):
            return op
    return None


def _tokenize_clickcql(source: str) -> List[Dict[str, Any]]:
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
        if ch == "[":
            if i + 1 < n and source[i + 1] == "]":
                tokens.append({"text": "[]", "kind": "token"})
                i += 2
            else:
                tokens.append({"text": "[", "kind": "bracket"})
                i += 1
            continue
        if ch == "]":
            tokens.append({"text": "]", "kind": "bracket"})
            i += 1
            continue
        if ch in {"'", '"'}:
            quote = ch
            start = i
            i += 1
            while i < n:
                if source[i] == "\\" and i + 1 < n:
                    i += 2
                    continue
                if source[i] == quote:
                    i += 1
                    break
                i += 1
            tokens.append({"text": source[start:i], "kind": "string"})
            continue
        if ch == "/":
            start = i
            i += 1
            while i < n:
                if source[i] == "\\" and i + 1 < n:
                    i += 2
                    continue
                if source[i] == "/":
                    i += 1
                    break
                i += 1
            tokens.append({"text": source[start:i], "kind": "regex"})
            continue
        op = _match_operator(source, i)
        if op is not None:
            tokens.append({"text": op, "kind": "op"})
            i += len(op)
            continue
        if ch.isdigit():
            start = i
            while i < n and (source[i].isdigit() or source[i] == "."):
                i += 1
            tokens.append({"text": source[start:i], "kind": "literal"})
            continue
        if ch.isalpha() or ch == "_":
            start = i
            i += 1
            while i < n and (source[i].isalnum() or source[i] in {"_", "-", "."}):
                i += 1
            ident = source[start:i]
            next_char = _peek_nonspace(source, i)
            lower_ident = ident.lower()
            if lower_ident in _KEYWORDS:
                kind: TokenKind = "keyword"
            elif next_char == ":":
                kind = "name"
            else:
                kind = "field"
            tokens.append({"text": ident, "kind": kind})
            continue
        tokens.append({"text": ch, "kind": "token"})
        i += 1
    return tokens


def highlight_clickcql(
    snippet: str,
    *,
    format: Literal["tokens", "html"] = "tokens",
    validate: bool = True,
    project: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    snippet = snippet or ""
    out: Dict[str, Any] = {"tokens": _tokenize_clickcql(snippet)}
    if validate and snippet.strip():
        try:
            parse_result = parse_clickcql(snippet, project=project)
            out["ast_type"] = parse_result.get("ast_type")
            out["statement_count"] = parse_result.get("statement_count")
        except ClickCqlPegError as exc:
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
