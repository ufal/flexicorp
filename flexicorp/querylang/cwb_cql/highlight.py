"""
Syntax highlighting for CWB-CQL query strings.

Produces token lists or HTML fragments from the same grammar concepts as the
parser, so the TEITOK interface can style query editors consistently.
"""

from __future__ import annotations

import re
from typing import Any, Dict, List, Literal

from .parser import parse_cwb_cql

TokenKind = Literal[
    "bracket",
    "attr",
    "op",
    "string",
    "space",
    "wildcard",
    "keyword",
]

# Identifier: attribute names and reserved words (within, etc.)
_IDENT_RE = re.compile(r"[A-Za-z_][A-Za-z0-9_\-.:]*")
_KEYWORDS = frozenset({"within"})


def _escape_html(text: str) -> str:
    return (
        text.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def _tokenize_cwb_cql(source: str) -> List[Dict[str, Any]]:
    """Tokenize a CWB-CQL snippet into {text, kind} spans. No validation."""
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
        if source[i] == "[":
            if i + 1 < n and source[i + 1] == "]":
                tokens.append({"text": "[]", "kind": "wildcard"})
                i += 2
            else:
                tokens.append({"text": "[", "kind": "bracket"})
                i += 1
            continue
        if source[i] == "]":
            tokens.append({"text": "]", "kind": "bracket"})
            i += 1
            continue
        if source[i] in "\"'":
            quote = source[i]
            start = i
            i += 1
            while i < n:
                if source[i] == "\\":
                    i += 2
                    continue
                if source[i] == quote:
                    i += 1
                    break
                i += 1
            tokens.append({"text": source[start:i], "kind": "string"})
            continue
        if source[i] == "=":
            tokens.append({"text": "=", "kind": "op"})
            i += 1
            continue
        if source[i] == "!" and i + 1 < n and source[i + 1] == "=":
            tokens.append({"text": "!=", "kind": "op"})
            i += 2
            continue
        m = _IDENT_RE.match(source, i)
        if m:
            ident = m.group(0)
            kind: TokenKind = "keyword" if ident.lower() in _KEYWORDS else "attr"
            tokens.append({"text": ident, "kind": kind})
            i = m.end()
            continue
        # Single unknown char: treat as bracket or pass through as "op" for safety
        tokens.append({"text": source[i], "kind": "bracket"})
        i += 1
    return tokens


def highlight_cwb_cql(
    snippet: str,
    *,
    format: Literal["tokens", "html"] = "tokens",
    validate: bool = True,
) -> Dict[str, Any]:
    """
    Highlight a CWB-CQL query snippet using the same language as the parser.

    Args:
        snippet: Raw query string (e.g. '[word="the"] []').
        format: 'tokens' -> list of {text, kind}; 'html' -> single HTML fragment
                with spans using class "flexicorp-hl-<kind>". When all tags are
                removed, the remaining text is character-identical to the original.
        validate: If True, run the parser and attach parse_error when invalid.

    Returns:
        Dict with "tokens" (list of {text, kind}), and optionally "html" when
        format is "html", and "parse_error" when validate=True and parse failed.

    HTML format uses classes: flexicorp-hl-bracket, flexicorp-hl-attr,
    flexicorp-hl-op, flexicorp-hl-string, flexicorp-hl-space, flexicorp-hl-wildcard,
    flexicorp-hl-keyword. Token text is HTML-escaped before insertion so operators
    like "<" render safely inside spans.
    """
    snippet = (snippet or "").strip()
    out: Dict[str, Any] = {"tokens": _tokenize_cwb_cql(snippet)}
    if validate:
        try:
            parse_cwb_cql(snippet)
        except Exception as e:
            out["parse_error"] = str(e)
    if format == "html":
        parts: List[str] = []
        for t in out["tokens"]:
            text = t["text"]
            kind = t.get("kind", "attr")
            cls = f"flexicorp-hl-{kind}"
            parts.append(f'<span class="{cls}">{_escape_html(text)}</span>')
        out["html"] = "".join(parts)
    return out
