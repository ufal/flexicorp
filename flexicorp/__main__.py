from __future__ import annotations

"""
Module entry point for `python -m flexicorp`.

For now this delegates to the CLI so you can run commands like:

    python -m flexicorp query --backend cqp '[word="the"]'
"""

from .cli import main as cli_main


def main() -> int:
    return cli_main()


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())

