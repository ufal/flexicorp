## flexiCorp

flexiCorp is a multi-backend corpus interface for TEITOK / EasyCorp and stand-alone corpus backends. It provides a unified JSON/CLI API over CQP/CWB, Manatee, BlackLab, ClickHouse, TEITOK XML, and other corpus engines, so tools and UIs don’t need to care which backend is used underneath.

**Full documentation:** will live in the repository wiki (installation, backends, TEITOK integration, JSON API, and UI integration). This README only covers the essentials.

---

## Install

Install from PyPI:

```bash
pip install flexicorp
```

This installs the `flexicorp` Python package and the `flexicorp` CLI entry point.

Runtime requirements depend on which backends you use (e.g. CQP/CWB, Manatee, BlackLab, ClickHouse). See the wiki for backend-specific installation instructions.

---

## Quick start

### As a CLI

Describe or inspect a corpus project:

```bash
flexicorp overview --project-root /path/to/project
```

Run a corpus query via a configured backend:

```bash
flexicorp query \
  --backend cqp \
  --folder /path/to/project \
  --query '[word="example"]'
```

Most commands accept `--backend`, `--project-root`/`--folder`, and `--api` (JSON envelope) to make integration with other tools straightforward. Use:

```bash
flexicorp --help
flexicorp <subcommand> --help
```

for details.

### As a JSON-over-STDIO service

flexiCorp can also be run as a small JSON-over-STDIO daemon, suitable for use from web frontends or TEITOK:

```bash
python -m flexicorp
```

Send JSON requests on stdin and read JSON responses on stdout; the core entry point is `flexicorp.handle_request`. The wiki will contain the full request/response schema and examples.

---

## What flexiCorp does

- **Unified API over multiple corpus backends**
  - CQP/CWB
  - Manatee
  - BlackLab
  - ClickHouse
  - TEITOK XML files
- **Project-aware configuration**
  - Detects TEITOK/EasyCorp projects (CQP registry, Manatee corpora, TEITOK XML layout).
  - Reads project configuration and merges it with explicit CLI/JSON parameters.
- **Overview and health checks**
  - `overview` endpoints/commands report which backends are available for a given project and why (missing registry, missing bindings, etc.).
- **Designed for UI integration**
  - JSON API suitable for TEITOK, EasyCorp, or other web frontends.
  - Optional debug information for troubleshooting backend setups.

See the wiki for a complete list of commands, backends, and JSON schemas.

---

## Project layout

- `flexicorp/` — main Python package (CLI, JSON API, backends, configuration helpers)
- `flexencoder/` — C++ helpers for TEITOK integration (not required for pure Python use)
- `teitok/` — TEITOK-side integration (PHP/JS; distributed with TEITOK rather than via PyPI)

Long-form documentation (backends, TEITOK configuration, JSON API reference, contributing) will be maintained in the GitHub wiki for `ufal/flexicorp`.

