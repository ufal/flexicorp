"""
flexiCorp: multi-backend corpus interface for TEITOK / EasyCorp.

This package exposes:
- a JSON-over-STDIO entry point (`python -m flexicorp`),
- a small programmatic API (`handle_request`),
- and a CLI (`flexicorp ...`) for manual use.

Backends live under `flexicorp.backends` and implement the `CorpusBackend`
interface defined in `flexicorp.core`.
"""

from .core import handle_request, main_stdio

__all__ = ["handle_request", "main_stdio"]

