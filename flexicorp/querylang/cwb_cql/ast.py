from __future__ import annotations

from dataclasses import dataclass, field
from typing import Union


@dataclass
class WithinSpec:
    scope: str


@dataclass
class AttributeRef:
    name: str


@dataclass
class StringValue:
    value: str
    flags: str = ""


ExprNode = Union[AttributeRef, StringValue]


@dataclass
class ComparisonConstraint:
    op: str
    left: ExprNode
    right: ExprNode


ConstraintNode = ComparisonConstraint


@dataclass
class TokenPattern:
    constraint: ConstraintNode | None = None
    lookahead: bool = False


@dataclass
class SequencePattern:
    items: list[TokenPattern]


PatternNode = SequencePattern


@dataclass
class CwbQuery:
    pattern: PatternNode
    within: WithinSpec | None = None
    global_constraints: list[ConstraintNode] = field(default_factory=list)
    match_selector: object | None = None
    matching_strategy: str | None = None
    cut: int | None = None
    source_text: str | None = None

