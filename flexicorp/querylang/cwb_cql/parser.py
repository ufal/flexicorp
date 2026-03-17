from __future__ import annotations

import re

from .ast import AttributeRef, ComparisonConstraint, CwbQuery, SequencePattern, StringValue, TokenPattern, WithinSpec


_WITHIN_RE = re.compile(r"^(?P<body>.*?)(?:\s+within\s+(?P<scope>[A-Za-z_][A-Za-z0-9_\-]*))?\s*$", re.IGNORECASE)
_TOKEN_RE = re.compile(r"\[(.*?)\]", re.DOTALL)
_SIMPLE_CONSTRAINT_RE = re.compile(
    r"^\s*(?P<attr>[A-Za-z_][A-Za-z0-9_\-.:]*)\s*(?P<op>=|!=)\s*\"(?P<value>(?:\\.|[^\"])*)\"\s*(?:%(?P<flags>[A-Za-z]+))?\s*$"
)


class CwbCqlParseError(ValueError):
    pass


def _unescape_string(value: str) -> str:
    return bytes(value, "utf-8").decode("unicode_escape")


def _parse_token(inner: str) -> TokenPattern:
    inner = inner.strip()
    if not inner:
        return TokenPattern(constraint=None)

    m = _SIMPLE_CONSTRAINT_RE.match(inner)
    if not m:
        raise CwbCqlParseError(
            "Only simple token constraints like [attr=\"value\"] or [attr!=\"value\"] "
            "are supported in the first cwb-cql parser subset."
        )

    attr = AttributeRef(name=m.group("attr"))
    value = StringValue(value=_unescape_string(m.group("value")), flags=m.group("flags") or "")
    return TokenPattern(
        constraint=ComparisonConstraint(
            op=m.group("op"),
            left=attr,
            right=value,
        )
    )


def parse_cwb_cql(source: str) -> CwbQuery:
    text = (source or "").strip()
    if not text:
        raise CwbCqlParseError("Empty cwb-cql query.")

    m = _WITHIN_RE.match(text)
    if not m:
        raise CwbCqlParseError("Could not parse cwb-cql query.")
    body = (m.group("body") or "").strip()
    scope = m.group("scope")

    matches = list(_TOKEN_RE.finditer(body))
    if not matches:
        raise CwbCqlParseError("The first cwb-cql parser subset only supports bracketed token patterns.")

    rebuilt = "".join(match.group(0) for match in matches)
    if rebuilt.replace(" ", "") != re.sub(r"\s+", "", body):
        raise CwbCqlParseError(
            "The first cwb-cql parser subset currently supports token sequences only."
        )

    items = [_parse_token(match.group(1)) for match in matches]
    return CwbQuery(
        pattern=SequencePattern(items=items),
        within=WithinSpec(scope=scope) if scope else None,
        source_text=source,
    )

