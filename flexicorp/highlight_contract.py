"""
Unified highlight_map and legend contract for query results.

Enables colour matching between named parts of the query (e.g. ClickCQL
subject/object) and token IDs in each hit. Simple backends provide only
default.tok_ids; backends that support named groups fill groups + optional legend.
"""

from __future__ import annotations

from typing import Any, Dict, List


def build_highlight_map(
    tok_ids: List[str],
    *,
    groups: List[Dict[str, Any]] | None = None,
) -> Dict[str, Any]:
    """
    Build the standard highlight_map for one hit.

    Args:
        tok_ids: All token IDs that matched (union). Used for default.tok_ids
                 and for legacy "match" when groups is empty.
        groups: Optional list of { id, name?, label?, query_span?, tok_ids, color?, textColor? }.
                When absent or empty, UI can only use default (single highlight).

    Returns:
        {
          "groups": [ { "id", "name?", "label?", "query_span?", "tok_ids", "color?", "textColor?" }, ... ],
          "default": { "tok_ids": [...] }  // union of all matched tokens
        }
        Plus "match" key with same list as default.tok_ids when groups is empty,
        for backward compatibility.
    """
    tok_ids = [str(t) for t in tok_ids]
    if not groups:
        return {
            "groups": [],
            "default": {"tok_ids": list(tok_ids)},
            "match": list(tok_ids),  # legacy
        }
    out_groups: List[Dict[str, Any]] = []
    for g in groups:
        out_groups.append({
            "id": g.get("id", ""),
            "tok_ids": [str(t) for t in g.get("tok_ids", [])],
            **{k: g[k] for k in ("name", "label", "query_span", "color", "textColor") if k in g},
        })
    return {
        "groups": out_groups,
        "default": {"tok_ids": list(tok_ids)},
    }


def resolve_legend(params: Dict[str, Any]) -> List[Dict[str, Any]]:
    """
    Resolve result-level legend for named groups (e.g. from ClickCQL).

    If the client sends params.highlight_legend (or params.legend), return it
    so the response can include result.legend for the UI. Otherwise return [].

    Returns:
        [ { "id": "g1", "name": "subject", "color": "#ffcc66" }, ... ]
    """
    legend = params.get("highlight_legend") or params.get("legend")
    if not isinstance(legend, list):
        return []
    return [
        {k: item[k] for k in ("id", "name", "label", "query_span", "color", "textColor") if k in item}
        for item in legend
        if isinstance(item, dict) and item.get("id")
    ]
