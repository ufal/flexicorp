"""
Syntax highlighting for PML-TQ query strings.

Validation uses the local PEG parser bridge; tokenization is lightweight and
keeps the frontend contract compatible with flexicorp's existing highlight UI.
"""

from __future__ import annotations

from typing import Any, Dict, List, Literal, Optional

from .peg_bridge import PmltqPegError, parse_pmltq

TokenKind = Literal[
    "variable",
    "field",
    "operator",
    "string",
    "keyword",
    "output_keyword",
    "node_type",
    "assignment",
    "bracket",
    "paren",
    "comma",
    "semicolon",
    "space",
    "token",
]

_KEYWORDS = frozenset({"child", "parent", "sibling", "ancestor", "descendant"})
_OUTPUT_KEYWORDS = frozenset({"for", "give", "sort", "by", "desc", "asc", "count", "group", "freq", "tabulate", "filter", "where", "distinct"})
_NODE_TYPES = frozenset({"a-node", "m-node", "w-node", "a-root"})


def _escape_html(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def _tokenize_pmltq(source: str) -> List[Dict[str, Any]]:
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
        if source.startswith(":=", i):
            tokens.append({"text": ":=", "kind": "assignment"})
            i += 2
            continue
        if source.startswith(">>", i):
            tokens.append({"text": ">>", "kind": "operator"})
            i += 2
            continue
        if ch in {'"', "'"}:
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
        if ch == "$":
            start = i
            i += 1
            while i < n and (source[i].isalnum() or source[i] == "_"):
                i += 1
            tokens.append({"text": source[start:i], "kind": "variable"})
            continue
        if ch in "[]":
            tokens.append({"text": ch, "kind": "bracket"})
            i += 1
            continue
        if ch in "()":
            tokens.append({"text": ch, "kind": "paren"})
            i += 1
            continue
        if ch == ",":
            tokens.append({"text": ch, "kind": "comma"})
            i += 1
            continue
        if ch == ";":
            tokens.append({"text": ch, "kind": "semicolon"})
            i += 1
            continue
        if source.startswith("!=", i) or source.startswith("=~", i) or source.startswith("!~", i):
            tokens.append({"text": source[i:i + 2], "kind": "operator"})
            i += 2
            continue
        if ch in "=<>~":
            tokens.append({"text": ch, "kind": "operator"})
            i += 1
            continue
        if ch.isalpha() or ch in {"_", "-"}:
            start = i
            i += 1
            while i < n and (source[i].isalnum() or source[i] in {"_", "-", "/", "."}):
                i += 1
            text = source[start:i]
            lower = text.lower()
            if lower in _NODE_TYPES:
                kind: TokenKind = "node_type"
            elif lower in _OUTPUT_KEYWORDS:
                kind = "output_keyword"
            elif lower in _KEYWORDS:
                kind = "keyword"
            else:
                kind = "field"
            tokens.append({"text": text, "kind": kind})
            continue
        tokens.append({"text": ch, "kind": "token"})
        i += 1
    return tokens


def highlight_pmltq(
    snippet: str,
    *,
    format: Literal["tokens", "html"] = "tokens",
    validate: bool = True,
    project: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    snippet = snippet or ""
    out: Dict[str, Any] = {"tokens": _tokenize_pmltq(snippet)}
    if validate and snippet.strip():
        try:
            parse_result = parse_pmltq(snippet, project=project)
            out["ast_type"] = parse_result.get("ast_type")
            out["result_type"] = parse_result.get("result_type")
            out["has_output_filters"] = bool(parse_result.get("has_output_filters"))
        except PmltqPegError as exc:
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
