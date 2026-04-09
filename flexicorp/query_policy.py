from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any, Dict, Tuple

FlexiRequest = Dict[str, Any]


_AGGREGATION_HINT_RE = re.compile(
    r"\b(tabulate|group\s+by|having|colloc(?:ation)?s?|keyness|frequency|frequencies)\b",
    flags=re.IGNORECASE,
)


@dataclass(frozen=True)
class QueryPolicyMeta:
    request_role: str
    input_query_mode: str
    query_mode: str
    query_sanitized: bool
    suggested_tab: str
    sanitized_query: str


def _truthy(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return False
    text = str(value).strip().lower()
    if not text:
        return False
    if text in {"0", "false", "no", "n", "off", "none", "null"}:
        return False
    return True


def detect_request_role(req: FlexiRequest) -> str:
    params = dict(req.get("params") or {})
    project = dict(req.get("project") or {})

    explicit_role = (
        params.get("request_role")
        or params.get("user_role")
        or params.get("role")
        or project.get("request_role")
        or project.get("user_role")
        or project.get("role")
    )
    if isinstance(explicit_role, str) and explicit_role.strip().lower() in {"admin", "visitor"}:
        return explicit_role.strip().lower()

    # TEITOK/flexicorp.php can pass through $user; treat any truthy user marker as admin.
    if _truthy(params.get("user")) or _truthy(project.get("user")) or _truthy(params.get("is_admin")):
        return "admin"
    return "visitor"


def detect_query_mode(query_text: str) -> str:
    q = (query_text or "").strip()
    if not q:
        return "search"
    return "aggregation" if _AGGREGATION_HINT_RE.search(q) else "search"


def sanitize_aggregation_query(query_text: str) -> Tuple[str, bool]:
    q = (query_text or "").strip()
    if not q:
        return q, False

    # CQP-style tabulate clause: keep left side as the base query.
    tabulate_split = re.split(r"\btabulate\b", q, maxsplit=1, flags=re.IGNORECASE)
    if len(tabulate_split) > 1:
        base = tabulate_split[0].strip().rstrip(";")
        return (base if base else q), bool(base)

    # SQL-like aggregations: cut at GROUP BY / HAVING and keep pre-aggregation query.
    group_split = re.split(r"\bgroup\s+by\b|\bhaving\b", q, maxsplit=1, flags=re.IGNORECASE)
    if len(group_split) > 1:
        base = group_split[0].strip().rstrip(";")
        return (base if base else q), bool(base)

    # Fallback: no safe rewrite found.
    return q, False


def apply_query_policy(req: FlexiRequest) -> Tuple[FlexiRequest, QueryPolicyMeta]:
    if str(req.get("operation") or "").strip().lower() != "query":
        role = detect_request_role(req)
        meta = QueryPolicyMeta(
            request_role=role,
            input_query_mode="search",
            query_mode="search",
            query_sanitized=False,
            suggested_tab="search",
            sanitized_query="",
        )
        return req, meta

    params = dict(req.get("params") or {})
    query_text = str(params.get("query") or params.get("pattern") or params.get("cql") or "").strip()
    role = detect_request_role(req)
    input_mode = detect_query_mode(query_text)
    query_mode = input_mode
    query_sanitized = False
    sanitized_query = query_text
    suggested_tab = "stats" if (role == "admin" and input_mode == "aggregation") else "search"

    # Default policy: visitors cannot submit direct aggregation queries.
    if role == "visitor" and input_mode == "aggregation":
        sanitized_query, changed = sanitize_aggregation_query(query_text)
        if changed:
            params["query"] = sanitized_query
            req = {**req, "params": params}
            query_sanitized = True
            query_mode = "search"
        suggested_tab = "search"

    meta = QueryPolicyMeta(
        request_role=role,
        input_query_mode=input_mode,
        query_mode=query_mode,
        query_sanitized=query_sanitized,
        suggested_tab=suggested_tab,
        sanitized_query=sanitized_query,
    )
    return req, meta

