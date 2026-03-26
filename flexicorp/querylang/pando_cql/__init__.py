"""
Provisional PegJS/Peggy grammar for Manatree / pando-cql (see pando_cql.pegjs).

Wire-up (highlight bridge, CLI) is not implemented yet; compile the .pegjs with peggy when needed.
"""

from pathlib import Path

PEG_PATH = Path(__file__).with_name("pando_cql.pegjs")

__all__ = ["PEG_PATH"]
