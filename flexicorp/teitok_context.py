from __future__ import annotations

import re
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional

from .flexencoder_xidx import fragment_by_id as _xidx_fragment_by_id

# ElementTree exposes TEI/TEITOK `xml:id` as this Clark notation key, not `id`.
_XML_NS_ID = "{http://www.w3.org/XML/1998/namespace}id"


def _elem_xml_id(elem: ET.Element) -> Optional[str]:
    v = elem.get("id") or elem.get(_XML_NS_ID)
    if v is None:
        return None
    s = str(v).strip()
    return s if s else None


def normalize_context_request(params: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    raw_context = params.get("context")
    has_context_flags = any(
        key in params for key in ("context", "context_scope", "context_format", "context_level", "lvl")
    )
    if not raw_context and not has_context_flags and not (
        params.get("extract_fragments") or params.get("extract_xml")
    ):
        return None

    context: Dict[str, Any] = dict(raw_context) if isinstance(raw_context, dict) else {}
    if params.get("extract_fragments") or params.get("extract_xml"):
        context.setdefault("scope", "s")
        context.setdefault("format", "xml")
    if params.get("context_scope"):
        context["scope"] = params.get("context_scope")
    if params.get("context_format"):
        context["format"] = params.get("context_format")
    if params.get("context_level") or params.get("lvl"):
        context.setdefault("scope", params.get("context_level") or params.get("lvl"))

    scope = str(context.get("scope") or "s").strip().lower()
    if scope.startswith("<") and scope.endswith(">") and len(scope) > 2:
        scope = scope[1:-1].strip().lower()
    scope_aliases = {
        "sentence": "s",
        "sent": "s",
        "tokens": "tok",
        "token": "tok",
        "word": "tok",
        "words": "tok",
    }
    scope = scope_aliases.get(scope, scope) or "s"

    fmt = str(context.get("format") or "xml").strip().lower()
    if fmt not in {"xml", "text"}:
        fmt = "xml"

    prefer = str(context.get("prefer") or "xidx").strip().lower()
    fallback = bool(context.get("fallback", True))
    return {
        "scope": scope,
        "format": fmt,
        "prefer": prefer,
        "fallback": fallback,
    }


def fragment_to_text(fragment: str) -> str:
    wrapped = f"<root>{fragment}</root>"
    try:
        root = ET.fromstring(wrapped)
        return " ".join(part.strip() for part in root.itertext() if part.strip())
    except ET.ParseError:
        plain = re.sub(r"<[^>]+>", " ", fragment)
        return " ".join(plain.split())


def _doc_path_candidates(root_dir: Path, searchfolder: str, doc_id: str) -> List[Path]:
    searchfolder = searchfolder.strip("/").replace("\\", "/")
    normalized_doc_id = doc_id.strip().replace("\\", "/")
    stripped = normalized_doc_id
    if stripped.startswith(searchfolder + "/"):
        stripped = stripped[len(searchfolder) + 1 :]
    elif stripped.startswith("xmlfiles/"):
        stripped = stripped[len("xmlfiles/") :]

    candidates: List[Path] = [
        root_dir / searchfolder / stripped,
        root_dir / normalized_doc_id,
    ]
    if stripped and not stripped.endswith(".xml"):
        candidates.append(root_dir / searchfolder / f"{stripped}.xml")
        candidates.append(root_dir / f"{normalized_doc_id}.xml")
    seen: set[str] = set()
    out: List[Path] = []
    for candidate in candidates:
        marker = str(candidate.resolve()) if candidate.is_absolute() else str(candidate)
        if marker not in seen:
            seen.add(marker)
            out.append(candidate)
    return out


def extract_teitok_fragment_xml(
    *,
    root_dir: Path,
    searchfolder: str,
    doc_id: str,
    sentence_id: Optional[str],
    tok_ids: List[str],
    scope: str,
) -> tuple[Optional[str], str]:
    # Fast path: if the flexencoder xidx by-id index is present and we already
    # know the target xml:id, we can return the raw element bytes directly
    # (single pread; no DOM parse). See docs/xidx_by_id_index.md §4.
    #
    # Only applies when scope is structural (not "tok") and the target id is
    # known. For "tok" scope and the tok_ids-only walk-up case, we fall
    # through to the ElementTree path below — those code paths look across
    # multiple elements and aren't served by a pure (scope, id) lookup.
    #
    # Back-compat: fragment_by_id() returns None on corpora without xidx or
    # with stride-40 regions.bin, so the fallback path is always available.
    if scope and scope != "tok" and sentence_id:
        raw = _xidx_fragment_by_id(root_dir, scope, sentence_id)
        if raw:
            try:
                # D-2 keeps bytes verbatim; decode preserves whitespace.
                return raw.decode("utf-8"), scope
            except UnicodeDecodeError:
                # Corrupt UTF-8 in the source XML — fall through to the
                # ElementTree path, which has its own error handling.
                pass

    xml_path = next((path for path in _doc_path_candidates(root_dir, searchfolder, doc_id) if path.is_file()), None)
    if xml_path is None:
        return None, scope
    try:
        tree = ET.parse(xml_path)
        xml_root = tree.getroot()
    except ET.ParseError:
        return None, scope

    target_tag = scope
    parent_map = {child: parent for parent in xml_root.iter() for child in parent}
    matched_tokens: List[ET.Element] = []
    tok_id_set = set(tok_ids)
    if tok_ids:
        for elem in xml_root.iter():
            local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
            eid = _elem_xml_id(elem)
            if local_tag in {"tok", "dtok"} and eid is not None and eid in tok_id_set:
                matched_tokens.append(elem)

    if scope == "tok":
        if matched_tokens:
            xml_parts = [ET.tostring(tok, encoding="unicode") for tok in matched_tokens]
            return " ".join(xml_parts), "tok"
        return None, "tok"

    if tok_ids:
        for elem in xml_root.iter():
            local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
            if local_tag != target_tag:
                continue
            same_as = elem.get("sameAs") or ""
            if same_as:
                same_as_ids = {part.lstrip("#") for part in same_as.split() if part.strip()}
                if tok_id_set & same_as_ids:
                    return ET.tostring(elem, encoding="unicode"), target_tag
        if matched_tokens:
            for tok in matched_tokens:
                current = tok
                while current is not None:
                    local_tag = current.tag.split("}", 1)[-1] if "}" in current.tag else current.tag
                    if local_tag == target_tag:
                        return ET.tostring(current, encoding="unicode"), target_tag
                    current = parent_map.get(current)
            first_tok = matched_tokens[0]
            parent = parent_map.get(first_tok)
            if parent is not None:
                local_tag = parent.tag.split("}", 1)[-1] if "}" in parent.tag else parent.tag
                return ET.tostring(parent, encoding="unicode"), local_tag
            return ET.tostring(first_tok, encoding="unicode"), "tok"

    if sentence_id:
        for elem in xml_root.iter():
            local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
            eid = _elem_xml_id(elem)
            if local_tag == target_tag and eid == sentence_id:
                return ET.tostring(elem, encoding="unicode"), target_tag

    return None, scope


def extract_teitok_fragment_xml_by_doc_offset(
    *,
    root_dir: Path,
    searchfolder: str,
    doc_id: str,
    token_offset_start: int,
    token_offset_end: int,
    scope: str,
) -> tuple[Optional[str], str, Optional[str], List[str]]:
    """
    Locate a TEITOK XML fragment using a token offset *relative to the document start*.

    Why this exists
    ---------------
    The primary XML lookup path (``extract_teitok_fragment_xml``) requires either
    a known ``sentence_id`` (``<s xml:id="...">``) or valid ``tok_ids`` (``<tok
    xml:id="...">``) that line up with what's in the XML. On Manatee corpora
    that were built without ``s.id`` as a structural attribute, or without an
    ``id`` positional attribute on disk, neither is available — all the caller
    has are match cpos bounds (``match_start`` / ``match_end``) and the cpos
    ``beg`` of the containing document (via ``doc_struct.num_at_pos`` +
    ``doc_struct.beg``). In that situation we still want to return the right
    sentence, because the information is *in* the XML — we just need a
    cpos-based hook into it.

    Approach
    --------
    By construction, the flexencoder CWB writer and the downstream Manatee
    encoder both drop ``"--"`` placeholder tokens (see
    ``flexencoder/flexencoder_cwb.cpp`` ~line 267 and ``docs/
    manatee_xml_context_fix.md`` §2 INV-1). A TEITOK XML file contains one
    ``<tok>`` / ``<dtok>`` per real token in document order, and does not have
    ``--`` placeholders — so Manatee cpos within a document maps 1:1 to the
    Nth ``<tok>``/``<dtok>`` element inside that document's XML.

    Callers therefore pass ``token_offset_start = match_start - doc_beg`` (and
    similarly for ``_end``). This function walks the XML in document order,
    picks out the matching ``<tok>`` / ``<dtok>`` elements, and walks up the
    parent chain to find the ``<s>`` (or whichever scope was requested).

    Returns
    -------
    ``(fragment_xml, resolved_scope, sentence_xml_id, matched_tok_ids)``.
    ``fragment_xml`` is None if the XML can't be opened / parsed or if the
    offset falls outside the document's token count.
    """
    if token_offset_start < 0 or token_offset_end < token_offset_start:
        return None, scope, None, []
    xml_path = next(
        (path for path in _doc_path_candidates(root_dir, searchfolder, doc_id) if path.is_file()),
        None,
    )
    if xml_path is None:
        return None, scope, None, []
    try:
        tree = ET.parse(xml_path)
        xml_root = tree.getroot()
    except ET.ParseError:
        return None, scope, None, []

    # Collect <tok>/<dtok> elements in document order. Manatee cpos == index
    # into this list, offset by doc_beg (which the caller has already applied).
    token_elems: List[ET.Element] = []
    for elem in xml_root.iter():
        local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
        if local_tag in {"tok", "dtok"}:
            token_elems.append(elem)

    if not token_elems or token_offset_start >= len(token_elems):
        return None, scope, None, []

    clamped_end = min(token_offset_end, len(token_elems) - 1)
    matched_tokens = token_elems[token_offset_start : clamped_end + 1]
    matched_tok_ids: List[str] = []
    for tok in matched_tokens:
        tid = _elem_xml_id(tok)
        if tid:
            matched_tok_ids.append(tid)

    if scope == "tok":
        if matched_tokens:
            parts = [ET.tostring(tok, encoding="unicode") for tok in matched_tokens]
            return " ".join(parts), "tok", None, matched_tok_ids
        return None, "tok", None, matched_tok_ids

    # Walk up from the first matched token to the requested scope element.
    parent_map = {child: parent for parent in xml_root.iter() for child in parent}
    target_tag = scope
    current: Optional[ET.Element] = matched_tokens[0]
    while current is not None:
        local_tag = current.tag.split("}", 1)[-1] if "}" in current.tag else current.tag
        if local_tag == target_tag:
            return (
                ET.tostring(current, encoding="unicode"),
                target_tag,
                _elem_xml_id(current),
                matched_tok_ids,
            )
        current = parent_map.get(current)

    # No enclosing <s> found. Fall back to the parent (e.g. <p>) or the token
    # itself rather than returning nothing — the caller can decide whether a
    # degraded scope is acceptable.
    parent = parent_map.get(matched_tokens[0])
    if parent is not None:
        local_tag = parent.tag.split("}", 1)[-1] if "}" in parent.tag else parent.tag
        return (
            ET.tostring(parent, encoding="unicode"),
            local_tag,
            _elem_xml_id(parent),
            matched_tok_ids,
        )
    return (
        ET.tostring(matched_tokens[0], encoding="unicode"),
        "tok",
        None,
        matched_tok_ids,
    )


def resolve_teitok_context(
    *,
    root_dir: Path,
    searchfolder: str,
    doc_id: str,
    sentence_id: Optional[str],
    tok_ids: List[str],
    match_start: Optional[int],
    match_end: Optional[int],
    context_spec: Dict[str, Any],
    xidx_resolver: Optional[Callable[[str, int, int, Optional[str]], Optional[str]]] = None,
    doc_cpos_base: Optional[int] = None,
) -> Optional[Dict[str, Any]]:
    """
    ``doc_cpos_base``
        Optional cpos of the *start* of the containing document (Manatee's
        ``doc_struct.beg(num_at_pos(match_start))``). When the primary
        extraction paths fail (e.g. Manatee corpora without ``s.id`` /
        ``id`` positional attrs), ``resolve_teitok_context`` uses
        ``match_start - doc_cpos_base`` as the Nth-token offset into the
        doc's XML and walks up to the requested scope. See
        ``extract_teitok_fragment_xml_by_doc_offset`` for the rationale.
    """
    scope = str(context_spec.get("scope") or "s")
    fmt = str(context_spec.get("format") or "xml")
    prefer = str(context_spec.get("prefer") or "xidx")
    allow_fallback = bool(context_spec.get("fallback", True))

    fragment: Optional[str] = None
    source: Optional[str] = None
    resolved_scope = scope
    # Tok ids discovered by the doc-offset fallback; callers (e.g. the
    # Manatee backend) want to prefer these real xml:id values over the
    # surface-form tokens they passed in.
    offset_tok_ids: List[str] = []
    offset_sentence_id: Optional[str] = None

    if prefer == "xidx" and xidx_resolver is not None and match_start is not None:
        xidx_fragment = xidx_resolver(
            doc_id,
            match_start,
            match_end if match_end is not None else match_start,
            None if scope == "tok" else scope,
        )
        if xidx_fragment:
            fragment = xidx_fragment
            source = "xidx"

    if fragment is None and allow_fallback:
        xml_fragment, resolved_scope = extract_teitok_fragment_xml(
            root_dir=root_dir,
            searchfolder=searchfolder,
            doc_id=doc_id,
            sentence_id=sentence_id,
            tok_ids=tok_ids,
            scope=scope,
        )
        if xml_fragment:
            fragment = xml_fragment
            source = "xml-fallback"

    if (
        fragment is None
        and allow_fallback
        and doc_cpos_base is not None
        and match_start is not None
    ):
        offset_end = match_end if match_end is not None else match_start
        (
            offset_fragment,
            offset_scope,
            offset_sentence_id,
            offset_tok_ids,
        ) = extract_teitok_fragment_xml_by_doc_offset(
            root_dir=root_dir,
            searchfolder=searchfolder,
            doc_id=doc_id,
            token_offset_start=match_start - doc_cpos_base,
            token_offset_end=offset_end - doc_cpos_base,
            scope=scope,
        )
        if offset_fragment:
            fragment = offset_fragment
            resolved_scope = offset_scope
            source = "xml-doc-offset"

    if fragment is None or source is None:
        return None

    locator: Dict[str, Any] = {}
    derived_sentence_id = sentence_id or offset_sentence_id
    if not derived_sentence_id and resolved_scope != "tok":
        try:
            parsed_fragment = ET.fromstring(fragment)
            derived_sentence_id = _elem_xml_id(parsed_fragment)
        except ET.ParseError:
            derived_sentence_id = sentence_id
    if derived_sentence_id:
        locator["sentence_id"] = derived_sentence_id
    # Prefer real xml:ids discovered via the doc-offset walk over whatever
    # surface-form tokens the caller may have supplied — the TEITOK UI
    # matches highlights against <tok xml:id>, not surface forms.
    effective_tok_ids = offset_tok_ids if offset_tok_ids else tok_ids
    if effective_tok_ids:
        locator["token_ids"] = list(effective_tok_ids)
    if match_start is not None:
        locator["match_start"] = match_start
    if match_end is not None:
        locator["match_end"] = match_end
    data = fragment if fmt == "xml" else fragment_to_text(fragment)
    context: Dict[str, Any] = {
        "scope": scope,
        "format": fmt,
        "source": source,
        "locator": locator,
        "data": data,
    }
    if resolved_scope != scope:
        context["resolved_scope"] = resolved_scope
    return context
