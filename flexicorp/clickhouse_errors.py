"""User-facing summaries for ClickHouse driver / HTTP errors (Engine tab, overview)."""

from __future__ import annotations

import re
from typing import Union

BaseExc = Union[BaseException, str]


def format_clickhouse_error_message(
    exc: BaseExc,
    *,
    host: str = "127.0.0.1",
    port: int = 8123,
    database: str = "",
) -> str:
    """
    Map urllib3/clickhouse-connect errors to short strings for the TEITOK Engine tab.

    The full traceback-style text is kept in callers as error_detail / details when needed.
    """
    raw = str(exc).strip()
    low = raw.lower()

    if "connection refused" in low or "errno 61" in low:
        return (
            f"ClickHouse is not reachable at {host}:{port} "
            "(connection refused — server not running or wrong host/port)."
        )
    if "failed to establish a new connection" in low:
        return (
            f"ClickHouse is not reachable at {host}:{port} "
            "(could not open an HTTP connection to the server)."
        )
    if "max retries exceeded" in low:
        return (
            f"ClickHouse is not reachable at {host}:{port} "
            "(HTTP interface did not respond)."
        )
    if "name or service not known" in low or "nodename nor servname" in low:
        return f"ClickHouse host {host!r} could not be resolved (check hostname / DNS)."

    if "unknown database" in low or re.search(
        r"database\s+[`']?[\w.-]+[`']?\s+does not exist", low
    ):
        db = database or "(configured database)"
        return (
            f"ClickHouse has no database {db!r} for this corpus yet "
            "(run reindex to load the corpus, or create the database)."
        )

    if "unknown table" in low or (
        "table " in low and ("doesn't exist" in low or "does not exist" in low)
    ):
        return (
            "This corpus does not appear to be indexed in ClickHouse "
            "(expected tables are missing); try reindex."
        )

    if (
        "authentication failed" in low
        or ("password" in low and "failed" in low)
        or "unknown user" in low
        or "http error 401" in low
        or "unauthorized" in low
    ):
        return "ClickHouse authentication failed (check user and password in configuration)."

    first = raw.split("\n")[0].strip()
    if len(first) > 160:
        first = first[:157] + "..."
    return first if first else "ClickHouse reported an error (see server logs or debug output)."
