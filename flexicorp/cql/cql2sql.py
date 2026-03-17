"""
CQL → SQL translator for ClickQL.

Ported from PHP (query-clickalign.php: translateCQLToSQL, translateMultiTokenCQL,
translateDependencyCQL, parseAndTranslateCQL, parseCQLQuery, parseTokenConditions)
and JS (clickfunctions.js: getToksColumnList, makeSQLfromToken, etc.) so that
translation matches the existing implementation by the letter. When
cql2sql-peg-optimized.js is available, this module should be kept in sync for
identical output.

Schema: toks (tok_id, sentence_id, doc_id, form, lemma, upos, feats, tok_pos,
sent_ord, xml_start, xml_end, head_tok_pos, dep_rel), sentences, docs.
"""

from __future__ import annotations

import re
from typing import Any, Dict, List, Optional, Tuple

# Default toks columns (same as getToksColumnList in PHP/JS; doc_pos used for sequence join in cwb2sql)
# is_empty: flexencoder/cwb2sql toks may have is_empty UInt8 (1 = deleted/empty token, e.g. form="--")
TOKS_COLUMNS = [
    "tok_id",
    "sentence_id",
    "doc_id",
    "form",
    "lemma",
    "upos",
    "feats",
    "tok_pos",
    "doc_pos",
    "sent_ord",
    "xml_start",
    "xml_end",
    "head_tok_pos",
    "dep_rel",
    "is_empty",
    "inner_text",
]

GROUP_PALETTE = [
    {"color": "#ff6b6b", "textColor": "#ffffff"},
    {"color": "#4c6ef5", "textColor": "#ffffff"},
    {"color": "#2f9e44", "textColor": "#ffffff"},
    {"color": "#7950f2", "textColor": "#ffffff"},
    {"color": "#e67700", "textColor": "#ffffff"},
]


def _normalize_token_attr(attr: str) -> str:
    normalized = attr.strip()
    alias_map = {
        "word": "form",
        "deprel": "dep_rel",
    }
    return alias_map.get(normalized, normalized)


def _escape_sql(s: str) -> str:
    """Escape single quotes for ClickHouse SQL."""
    return s.replace("'", "''")


def _translate_token_condition_to_sql(cql_bracket_content: str, table_alias: str) -> str:
    """
    Translate the inside of a single token bracket to a SQL condition.
    Mirrors translateCQLToSQL() in query-clickalign.php.
    """
    cql = cql_bracket_content.strip()
    if not cql:
        return "1=1"

    # Simple attr="value"
    m = re.match(r'^(\w+)\s*=\s*"([^"]*)"$', cql)
    if m:
        attr, val = _normalize_token_attr(m.group(1)), m.group(2)
        return f"{table_alias}.{attr} = '{_escape_sql(val)}'"

    # feats.Number="Plur" -> table.feats['Number'] = 'Plur'
    m = re.match(r'^(\w+)\.(\w+)\s*=\s*"([^"]*)"$', cql)
    if m:
        container, feature, val = _normalize_token_attr(m.group(1)), m.group(2), m.group(3)
        return f"{table_alias}.{container}['{feature}'] = '{_escape_sql(val)}'"

    # attr != "value"
    m = re.match(r'^(\w+)\s*!=\s*"([^"]*)"$', cql)
    if m:
        attr, val = _normalize_token_attr(m.group(1)), m.group(2)
        return f"{table_alias}.{attr} != '{_escape_sql(val)}'"

    # AND: part1 & part2
    if " & " in cql:
        parts = [p.strip() for p in cql.split(" & ")]
        conds = [_translate_token_condition_to_sql(p, table_alias) for p in parts]
        return "(" + " AND ".join(conds) + ")"

    # OR: part1 | part2
    if " | " in cql:
        parts = [p.strip() for p in cql.split(" | ")]
        conds = [_translate_token_condition_to_sql(p, table_alias) for p in parts]
        return "(" + " OR ".join(conds) + ")"

    return "1=0"


def _parse_token_conditions(condition_string: str) -> List[Dict[str, Any]]:
    """Parse conditions inside [ ... ]. Mirrors parseTokenConditions in PHP."""
    conditions: List[Dict[str, Any]] = []
    # Match field="value" or field='value'
    for m in re.finditer(r'(\w+)=(["\']?)([^"\']*)\2', condition_string):
        conditions.append({
            "type": "condition",
            "field": {"name": _normalize_token_attr(m.group(1))},
            "value": {"value": m.group(3)},
            "operator": "=",
        })
    return conditions


def _is_multi_token_query(cql: str) -> bool:
    """True if query has 2+ token brackets (and no dependency op). Mirrors isMultiTokenQuery."""
    cql = cql.strip()
    if not cql or cql == "[]":
        return False
    if ";" in cql:
        first = cql.split(";", 1)[0].strip()
        if re.match(r"^[A-Za-z_][A-Za-z0-9_]*\s*=\s*(.+)$", first):
            cql = re.match(r"^[A-Za-z_][A-Za-z0-9_]*\s*=\s*(.+)$", first).group(1).strip()
        else:
            cql = first
    if "::" in cql:
        cql = cql.split("::", 1)[0].strip()
    if re.search(r"[><]", cql):
        return False
    n = len(re.findall(r"\[[^\]]*\]", cql))
    return n >= 2


def _is_dependency_query(cql: str) -> bool:
    """True if query contains dependency operator > or <."""
    if "::" in cql:
        cql = cql.split("::", 1)[0].strip()
    return ">" in cql or "<" in cql


def _strip_outer_query_wrapping(cql: str) -> Tuple[str, str]:
    """Return (base_cql, global_filter) after legacy assignment/semicolon stripping."""
    cql = cql.strip()
    if ";" in cql:
        first = cql.split(";", 1)[0].strip()
        assign_match = re.match(r"^[A-Za-z_][A-Za-z0-9_]*\s*=\s*(.+)$", first)
        if assign_match:
            cql = assign_match.group(1).strip()
        else:
            cql = first
    base_cql = cql
    global_filter = ""
    if "::" in cql:
        base_cql, global_filter = cql.split("::", 1)
        base_cql = base_cql.strip()
        global_filter = global_filter.strip()
    return base_cql, global_filter


def extract_highlight_legend(cql: str) -> List[Dict[str, Any]]:
    """
    Derive stable query-group metadata from the same token parse used for SQL generation.

    Each token bracket becomes one legend/group entry:
      [lemma="struggle"]                -> t1 / query_span: lemma="struggle"
      subj:[upos="NOUN"] obj:[upos="V"] -> t1 / subj, t2 / obj
    """
    base_cql, _global_filter = _strip_outer_query_wrapping(cql)
    token_matches = list(re.finditer(r"(?:([a-zA-Z_][a-zA-Z0-9_]*):)?\[([^\]]*)\]", base_cql))
    legend: List[Dict[str, Any]] = []
    for i, match in enumerate(token_matches):
        alias = f"t{i + 1}"
        user_name = (match.group(1) or "").strip()
        query_span = match.group(2).strip()
        palette = GROUP_PALETTE[i % len(GROUP_PALETTE)]
        label = query_span or user_name or alias
        entry: Dict[str, Any] = {
            "id": alias,
            "name": user_name or alias,
            "label": label,
            "query_span": query_span,
            "color": palette["color"],
            "textColor": palette["textColor"],
        }
        legend.append(entry)
    return legend


def _parse_and_translate_cql_condition(cql: str, table_alias: str) -> str:
    """
    Parse a single-token or condition part and return SQL WHERE fragment.
    Handles [form="man"], [form="man" & upos="NOUN"], etc.
    """
    cql = cql.strip()
    if cql == "[]" or cql == "":
        return "1=1"
    if not cql.startswith("["):
        cql = "[" + cql + "]"
    inner = cql[1:-1].strip() if cql.startswith("[") and cql.endswith("]") else cql
    return _translate_token_condition_to_sql(inner, table_alias)


def _translate_global_filter_simple(global_filter: str, table_alias: str) -> str:
    """Translate global filter (e.g. text_year=2020) to SQL. Simple match on doc/sentence metadata."""
    global_filter = global_filter.strip()
    if not global_filter:
        return ""
    # match.text_id, match.s_id, match.text_year etc. -> docs/sentences columns
    # Simple: match.attr="val" -> use doc or sentence scope; we use d.text_id, s.sentence_id, and docs/sentences metadata
    # For now support only simple attr=value that map to docs/sentences (e.g. text_year -> d metadata)
    parts = []
    for token in re.split(r"\s+&\s+", global_filter):
        token = token.strip()
        m = re.match(r"(\w+)\.(\w+)\s*=\s*\"([^\"]*)\"", token)
        if m:
            scope, attr, val = m.group(1), m.group(2), m.group(3)
            if scope == "match" or scope == "text":
                # Assume doc-level for text_*, sentence-level for s_*
                if attr.startswith("text_"):
                    col = attr[5:] if attr.startswith("text_") else attr
                    parts.append(f"d.{col} = '{_escape_sql(val)}'")
                elif attr.startswith("s_") or attr == "s_id":
                    col = attr
                    parts.append(f"s.{col} = '{_escape_sql(val)}'")
                else:
                    parts.append(f"d.{attr} = '{_escape_sql(val)}'")
    return " AND ".join(parts) if parts else ""


def _build_single_token_sql(
    where_condition: str,
    tokens_table: str,
    sentences_table: Optional[str],
    docs_table: Optional[str],
    limit: Optional[int],
    offset: Optional[int],
) -> str:
    """Build full SQL for a single-token query."""
    cols = [f"t1.{c} AS t1_{c}" for c in TOKS_COLUMNS]
    if sentences_table and docs_table:
        cols.append("s.xml_start AS t1_sentence_xml_start")
        cols.append("s.xml_end AS t1_sentence_xml_end")
        cols.append("d.text_id AS t1_text_id")
    select_list = ", ".join(cols)
    from_clause = f"FROM {tokens_table} t1"
    if sentences_table and docs_table:
        from_clause += f"\nINNER JOIN {sentences_table} s ON s.sentence_id = t1.sentence_id"
        from_clause += f"\nINNER JOIN {docs_table} d ON d.doc_id = s.doc_id"
    sql = f"SELECT {select_list}\n{from_clause}\nWHERE {where_condition}"
    sql += "\nORDER BY t1.sentence_id, t1.tok_pos"
    if limit is not None:
        sql += f" LIMIT {limit}"
    if offset is not None and offset > 0:
        sql += f" OFFSET {offset}"
    return sql


def _build_multi_token_sql(
    cql: str,
    tokens_table: str,
    sentences_table: Optional[str],
    docs_table: Optional[str],
    limit: Optional[int],
    offset: Optional[int],
) -> str:
    """Build full SQL for multi-token (sequence) query. Mirrors translateMultiTokenCQL."""
    global_filter = ""
    if "::" in cql:
        cql, global_filter = cql.split("::", 1)
        cql = cql.strip()
        global_filter = global_filter.strip()

    # Parse tokens: (name:)?[condition] ...
    token_matches = list(re.finditer(r"(?:([a-zA-Z_][a-zA-Z0-9_]*):)?\[([^\]]*)\]", cql))
    if len(token_matches) < 2:
        raise ValueError("Multi-token query must have at least 2 tokens")

    tokens: List[Dict[str, Any]] = []
    for i, m in enumerate(token_matches):
        name = m.group(1) or f"t{i + 1}"
        cond_inner = m.group(2)
        alias = f"t{i + 1}"
        sql_cond = _translate_token_condition_to_sql(cond_inner, alias)
        tokens.append({"name": name, "alias": alias, "sql_condition": sql_cond})

    select_parts: List[str] = []
    for t in tokens:
        a = t["alias"]
        for c in TOKS_COLUMNS:
            select_parts.append(f"{a}.{c} AS {a}_{c}")
    if sentences_table and docs_table:
        select_parts.append("s.xml_start AS t1_sentence_xml_start")
        select_parts.append("s.xml_end AS t1_sentence_xml_end")
        select_parts.append("d.text_id AS t1_text_id")

    joins: List[str] = [f"FROM {tokens_table} t1"]
    for i in range(1, len(tokens)):
        prev = f"t{i}"
        curr = f"t{i + 1}"
        # Sequence: same sentence, consecutive doc_pos (matches PHP and cwb2sql ORDER BY doc_id, doc_pos)
        joins.append(
            f"INNER JOIN {tokens_table} {curr} ON {curr}.sentence_id = {prev}.sentence_id AND {curr}.doc_pos = {prev}.doc_pos + 1"
        )
    if sentences_table and docs_table:
        joins.append(f"INNER JOIN {sentences_table} s ON s.sentence_id = t1.sentence_id")
        joins.append(f"INNER JOIN {docs_table} d ON d.doc_id = s.doc_id")

    where_parts = [f"({t['sql_condition']})" for t in tokens]
    if global_filter:
        gf_sql = _translate_global_filter_simple(global_filter, "t1")
        if gf_sql:
            where_parts.append(f"({gf_sql})")

    sql = "SELECT " + ", ".join(select_parts) + "\n" + "\n".join(joins)
    sql += "\nWHERE " + " AND ".join(where_parts)
    sql += "\nORDER BY t1.sentence_id, t1.tok_pos"
    if limit is not None:
        sql += f" LIMIT {limit}"
    if offset is not None and offset > 0:
        sql += f" OFFSET {offset}"
    return sql


def _build_dependency_sql(
    cql: str,
    tokens_table: str,
    sentences_table: Optional[str],
    docs_table: Optional[str],
    limit: Optional[int],
    offset: Optional[int],
) -> str:
    """Build full SQL for dependency query [cond1] > [cond2] or [cond1] < [cond2]. Uses head_tok_pos."""
    global_filter = ""
    if "::" in cql:
        cql, global_filter = cql.split("::", 1)
        cql = cql.strip()
        global_filter = global_filter.strip()

    # [a] > [b] or [a] < [b]
    m = re.match(r"^\[([^\]]*)\]\s*>\s*\[([^\]]*)\]$", cql)
    if m:
        head_cond = _translate_token_condition_to_sql(m.group(1), "t1")
        dep_cond = _translate_token_condition_to_sql(m.group(2), "t2")
        # head is t1, dependent is t2: t2.head_tok_pos = t1.tok_pos
        join_on = "t2.head_tok_pos = t1.tok_pos AND t2.sentence_id = t1.sentence_id"
    else:
        m = re.match(r"^\[([^\]]*)\]\s*<\s*\[([^\]]*)\]$", cql)
        if m:
            dep_cond = _translate_token_condition_to_sql(m.group(1), "t1")
            head_cond = _translate_token_condition_to_sql(m.group(2), "t2")
            join_on = "t1.head_tok_pos = t2.tok_pos AND t1.sentence_id = t2.sentence_id"
        else:
            raise ValueError(f"Unsupported dependency query: {cql}")

    cols = [f"t1.{c} AS t1_{c}" for c in TOKS_COLUMNS]
    cols += [f"t2.{c} AS t2_{c}" for c in TOKS_COLUMNS]
    if sentences_table and docs_table:
        cols.append("s.xml_start AS t1_sentence_xml_start")
        cols.append("s.xml_end AS t1_sentence_xml_end")
        cols.append("d.text_id AS t1_text_id")
    select_list = ", ".join(cols)
    sql = f"SELECT {select_list}\nFROM {tokens_table} t1\nINNER JOIN {tokens_table} t2 ON {join_on}"
    if sentences_table and docs_table:
        sql += f"\nINNER JOIN {sentences_table} s ON s.sentence_id = t1.sentence_id"
        sql += f"\nINNER JOIN {docs_table} d ON d.doc_id = s.doc_id"
    sql += f"\nWHERE ({head_cond}) AND ({dep_cond})"
    if global_filter:
        gf_sql = _translate_global_filter_simple(global_filter, "t1")
        if gf_sql:
            sql += f" AND ({gf_sql})"
    sql += "\nORDER BY t1.sentence_id, t1.tok_pos"
    if limit is not None:
        sql += f" LIMIT {limit}"
    if offset is not None and offset > 0:
        sql += f" OFFSET {offset}"
    return sql


def cql_to_sql(
    cql: str,
    *,
    tokens_table: str = "toks",
    sentences_table: Optional[str] = "sentences",
    docs_table: Optional[str] = "docs",
    limit: Optional[int] = 50,
    offset: Optional[int] = 0,
) -> str:
    """
    Translate a CQL query to full ClickHouse SQL (SELECT ... FROM toks ... WHERE ... ORDER BY ... LIMIT/OFFSET).

    Follows the same logic as PHP parseAndTranslateCQL + translateMultiTokenCQL/translateDependencyCQL
    and JS clickfunctions.js so that output is identical. Supports:
    - Single token: [lemma="run"], [form="man" & upos="NOUN"]
    - Global filter: [lemma="run"] :: text_year=2020 (when docs/sentences have metadata)
    - Multi-token sequence: [upos="ADJ"] [form="and"] [upos="ADJ"]
    - Dependency: [lemma="fish"] > [upos="DET"]

    Raises ValueError on unsupported or parse-error input.
    """
    cql = cql.strip()
    if not cql:
        raise ValueError("Empty CQL query")

    base_cql, global_filter = _strip_outer_query_wrapping(cql)
    cql = base_cql if not global_filter else f"{base_cql} :: {global_filter}"

    if base_cql == "[]" or not base_cql:
        where = "1=1"
        if global_filter:
            gf = _translate_global_filter_simple(global_filter, "t1")
            if gf:
                where = gf
            else:
                where = "1=1"
        return _build_single_token_sql(
            where, tokens_table, sentences_table, docs_table, limit, offset
        )

    if _is_dependency_query(base_cql):
        return _build_dependency_sql(
            cql, tokens_table, sentences_table, docs_table, limit, offset
        )
    if _is_multi_token_query(base_cql):
        return _build_multi_token_sql(
            cql, tokens_table, sentences_table, docs_table, limit, offset
        )

    # Single token (with optional global filter)
    where = _parse_and_translate_cql_condition(base_cql, "t1")
    if global_filter:
        gf_sql = _translate_global_filter_simple(global_filter, "t1")
        if gf_sql:
            where = f"({where}) AND ({gf_sql})"
    return _build_single_token_sql(
        where, tokens_table, sentences_table, docs_table, limit, offset
    )


def cql_to_count_sql(
    cql: str,
    *,
    tokens_table: str = "toks",
    sentences_table: Optional[str] = "sentences",
    docs_table: Optional[str] = "docs",
) -> str:
    """
    Return a SQL query that returns the total count for the same CQL query (no LIMIT/OFFSET).
    Used for pagination total. Wraps the base SELECT in SELECT count() FROM (base) AS q.
    """
    base = cql_to_sql(
        cql,
        tokens_table=tokens_table,
        sentences_table=sentences_table,
        docs_table=docs_table,
        limit=None,
        offset=None,
    )
    return f"SELECT count() FROM ({base}) AS q"
