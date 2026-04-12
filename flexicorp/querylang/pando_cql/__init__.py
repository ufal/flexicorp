"""
Provisional PegJS/Peggy grammar for Pando / pando-cql (see pando_cql.pegjs).

Syntax highlighting is implemented in highlight.py (lexer-style; no Peggy runtime).
"""

from pathlib import Path

from .highlight import highlight_pando_cql

PEG_PATH = Path(__file__).with_name("pando_cql.pegjs")

__all__ = ["PEG_PATH", "highlight_pando_cql"]
