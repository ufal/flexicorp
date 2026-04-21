"""``python -m flexicorp.reindex_jobs worker <job_id> <project_root>``."""

from __future__ import annotations

import sys

from .worker import main as worker_main


def main() -> int:
    args = sys.argv[1:]
    if not args or args[0] != "worker":
        print(
            "usage: python -m flexicorp.reindex_jobs worker <job_id> <project_root>",
            file=sys.stderr,
        )
        return 2
    return worker_main(args[1:])


if __name__ == "__main__":
    raise SystemExit(main())
