from __future__ import annotations

import re
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional

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

    if sentence_id:
        for elem in xml_root.iter():
            local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
            eid = _elem_xml_id(elem)
            if local_tag == target_tag and eid == sentence_id:
                return ET.tostring(elem, encoding="unicode"), target_tag

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

    return None, scope


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
) -> Optional[Dict[str, Any]]:
    scope = str(context_spec.get("scope") or "s")
    fmt = str(context_spec.get("format") or "xml")
    prefer = str(context_spec.get("prefer") or "xidx")
    allow_fallback = bool(context_spec.get("fallback", True))

    fragment: Optional[str] = None
    source: Optional[str] = None
    resolved_scope = scope

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

    if fragment is None or source is None:
        return None

    locator: Dict[str, Any] = {}
    derived_sentence_id = sentence_id
    if not derived_sentence_id and resolved_scope != "tok":
        try:
            parsed_fragment = ET.fromstring(fragment)
            derived_sentence_id = _elem_xml_id(parsed_fragment)
        except ET.ParseError:
            derived_sentence_id = sentence_id
    if derived_sentence_id:
        locator["sentence_id"] = derived_sentence_id
    if tok_ids:
        locator["token_ids"] = list(tok_ids)
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
