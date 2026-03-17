from __future__ import annotations

import hashlib
import json
import os
import re
import sqlite3
import time
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence, Set, Tuple

from ..core import BACKENDS, CorpusBackend, FlexiRequest, register_backend
from ..teitok import detect_teitok_cqp, detect_teitok_manatee


DOCS_DB_NAME = "tmp/doclist.sqlite"
SCHEMA_VERSION = "teitokxml-v2"
COMMON_TAG_ATTRS = ["lemma", "pos", "upos", "xpos", "msd", "feats", "morph"]
COMMON_NORMALIZE_ATTRS = ["nform", "reg"]
COMMON_PARSE_ATTRS = ["head", "deprel", "dephead", "deptype", "deps"]
COMMON_NER_ATTRS = ["ne", "ner", "nertype", "name", "entity"]


STEP_PATTERNS: List[Tuple[str, List[str]]] = [
    ("converted", [r"flexiconv", r"convert", r"exported to tei/xml", r"export to tei/xml"]),
    ("xml_review", [r"xml review", r"corrected xml", r"fixed xml", r"manual xml"]),
    ("tokenized", [r"tokeniz"]),
    ("segmented", [r"segment"]),
    ("normalized", [r"normaliz", r"\\bnform\\b", r"\\breg\\b"]),
    ("normalize_review", [r"normaliz.*review", r"review.*normaliz", r"normaliz.*correct", r"correct.*normaliz"]),
    ("tagged", [r"\\btagg", r"\\bpos\\b", r"\\bupos\\b", r"\\bxpos\\b", r"\\bmsd\\b", r"lemmat"]),
    ("tag_review", [r"(review|correct).*(tag|pos|lemma|upos|xpos|msd)", r"(tag|pos|lemma|upos|xpos|msd).*(review|correct)"]),
    ("parsed", [r"\\bparse", r"dependency", r"deprel", r"\\budapi\\b"]),
    ("parse_review", [r"(review|correct).*(parse|dependency|deprel)", r"(parse|dependency|deprel).*(review|correct)"]),
    ("ner", [r"\\bner\\b", r"named entit", r"entity recogn"]),
    ("ner_review", [r"(review|correct).*(ner|named entit)", r"(ner|named entit).*(review|correct)"]),
    ("metadata_review", [r"metadata", r"teiheader"]),
]


def _get_project_root(project: Dict[str, Any]) -> Path:
    root = project.get("root") or project.get("project_root") or "."
    return Path(root).expanduser().resolve()


def _open_docs_db(root: Path) -> Tuple[sqlite3.Connection, Path]:
    tmp_dir = root / "tmp"
    tmp_dir.mkdir(parents=True, exist_ok=True)
    db_path = tmp_dir / "doclist.sqlite"
    conn = sqlite3.connect(str(db_path))
    conn.row_factory = sqlite3.Row
    return conn, db_path


def _init_schema(conn: sqlite3.Connection) -> None:
    cur = conn.cursor()
    cur.executescript(
        """
        CREATE TABLE IF NOT EXISTS docs (
            id               TEXT PRIMARY KEY,
            relative_path    TEXT NOT NULL,
            xml_ok           INTEGER,
            deleted          INTEGER NOT NULL DEFAULT 0,
            xml_token_count  INTEGER,
            has_tokens       INTEGER,
            title            TEXT,
            orgfile          TEXT,
            size_bytes       INTEGER,
            mtime            INTEGER,
            last_change_when TEXT,
            last_change_who  TEXT,
            last_change_text TEXT,
            scan_time        TEXT,
            xml_phase        TEXT
        );

        CREATE TABLE IF NOT EXISTS doc_token_attr_status (
            doc_id            TEXT NOT NULL,
            attr_name         TEXT NOT NULL,
            tokens_with_attr  INTEGER,
            tokens_total      INTEGER,
            coverage          TEXT,
            present           INTEGER NOT NULL,
            PRIMARY KEY (doc_id, attr_name)
        );

        CREATE TABLE IF NOT EXISTS doc_meta_field_status (
            doc_id      TEXT NOT NULL,
            field_name  TEXT NOT NULL,
            present     INTEGER NOT NULL,
            value_text  TEXT,
            source      TEXT,
            PRIMARY KEY (doc_id, field_name)
        );

        CREATE TABLE IF NOT EXISTS doc_step_status (
            doc_id      TEXT NOT NULL,
            step_name   TEXT NOT NULL,
            state       TEXT NOT NULL,
            source      TEXT NOT NULL,
            updated_at  TEXT,
            updated_by  TEXT,
            note        TEXT,
            PRIMARY KEY (doc_id, step_name)
        );

        CREATE TABLE IF NOT EXISTS doc_index_status (
            backend   TEXT NOT NULL,
            doc_id    TEXT NOT NULL,
            indexed   INTEGER NOT NULL,
            PRIMARY KEY (backend, doc_id)
        );

        CREATE TABLE IF NOT EXISTS inventory_meta (
            key        TEXT PRIMARY KEY,
            value_text TEXT
        );

        CREATE TABLE IF NOT EXISTS doc_summary (
            doc_id                    TEXT PRIMARY KEY,
            xml_phase                 TEXT,
            workflow_phase            TEXT,
            blocking_step             TEXT,
            missing_required_steps    INTEGER,
            missing_required_meta     INTEGER,
            partial_required_attrs    INTEGER,
            ready_for_annotation      INTEGER,
            ready_for_indexing        INTEGER,
            completed_for_settings    INTEGER,
            summary_settings_hash     TEXT,
            updated_at                TEXT
        );

        CREATE INDEX IF NOT EXISTS idx_docs_path ON docs(relative_path);
        CREATE INDEX IF NOT EXISTS idx_docs_phase ON docs(xml_phase);
        CREATE INDEX IF NOT EXISTS idx_token_attr_name ON doc_token_attr_status(attr_name);
        CREATE INDEX IF NOT EXISTS idx_token_attr_cov ON doc_token_attr_status(attr_name, coverage);
        CREATE INDEX IF NOT EXISTS idx_meta_field_name ON doc_meta_field_status(field_name);
        CREATE INDEX IF NOT EXISTS idx_step_name_state ON doc_step_status(step_name, state);
        CREATE INDEX IF NOT EXISTS idx_index_backend ON doc_index_status(backend);
        CREATE INDEX IF NOT EXISTS idx_summary_workflow ON doc_summary(workflow_phase);
        """
    )
    conn.commit()


def _now_iso() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def _hash_json(data: Dict[str, Any]) -> str:
    blob = json.dumps(data, ensure_ascii=True, sort_keys=True, separators=(",", ":"))
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()


def _scan_xmlfiles(root: Path, searchfolder: Optional[str]) -> List[Tuple[str, str, int, int]]:
    folder = searchfolder or "xmlfiles"
    xml_root = (root / folder).resolve()
    if not xml_root.is_dir():
        return []

    results: List[Tuple[str, str, int, int]] = []
    for dirpath, _dirnames, filenames in os.walk(xml_root):
        for fn in filenames:
            if not fn.lower().endswith(".xml"):
                continue
            full = Path(dirpath) / fn
            rel = full.relative_to(root).as_posix()
            rel_from_xml = full.relative_to(xml_root).as_posix()
            try:
                st = full.stat()
                size = int(st.st_size)
                mtime = int(st.st_mtime)
            except OSError:
                size = 0
                mtime = 0
            results.append((rel_from_xml, rel, size, mtime))
    return results


def _get_searchfolder(project: Dict[str, Any]) -> Optional[str]:
    teitok_cfg = project.get("teitok") or {}
    sf = teitok_cfg.get("searchfolder")
    if isinstance(sf, str) and sf:
        return sf
    return "xmlfiles"


def _local_name(name: Any) -> str:
    text = str(name or "")
    if "}" in text:
        text = text.split("}", 1)[1]
    return text.lower()


def _text_value(elem: ET.Element) -> str:
    return " ".join(part.strip() for part in elem.itertext() if part and part.strip()).strip()


def _parse_boolish(value: Any) -> bool:
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def _normalize_doc_id(value: Any) -> str:
    doc_id = str(value or "").strip().replace("\\", "/")
    while doc_id.startswith("./"):
        doc_id = doc_id[2:]
    if doc_id.startswith("xmlfiles/"):
        doc_id = doc_id[len("xmlfiles/") :]
    return doc_id


def _doc_lookup_keys(doc_id: str) -> List[str]:
    normalized = _normalize_doc_id(doc_id)
    keys: List[str] = []
    if normalized:
        keys.append(normalized)
        if normalized.endswith(".xml"):
            keys.append(normalized[:-4])
        else:
            keys.append(f"{normalized}.xml")
    return list(dict.fromkeys(k for k in keys if k))


def _split_list(value: Any) -> List[str]:
    text = str(value or "").strip()
    if not text:
        return []
    return [part.strip() for part in text.split(",") if part and part.strip()]


def _settings_candidates(root: Path) -> List[Path]:
    return [
        root / "Resources" / "settings.xml",
        root / "settings.xml",
        root / "tmp" / "cqpsettings.xml",
    ]


def _find_settings_file(root: Path) -> Optional[Path]:
    for cand in _settings_candidates(root):
        if cand.is_file():
            return cand
    return None


def _derive_meta_field_name(item: ET.Element) -> Optional[str]:
    cqp_name = str(item.get("cqp") or "").strip()
    if cqp_name:
        return cqp_name
    xpath = str(item.get("xpath") or "").strip()
    if not xpath:
        return None
    note_match = re.search(r'@n=["\']([^"\']+)["\']', xpath)
    if note_match:
        return note_match.group(1)
    if "/@" in xpath:
        return xpath.rsplit("/@", 1)[1].strip()
    segs = [seg for seg in xpath.strip("/").split("/") if seg and seg != "*"]
    if not segs:
        return None
    return re.sub(r"\[.*\]", "", segs[-1]).strip().lower() or None


def _load_settings_profile(root: Path, searchfolder: Optional[str]) -> Dict[str, Any]:
    profile: Dict[str, Any] = {
        "settings_path": None,
        "token_attrs": [],
        "meta_fields": [],
        "searchfolder": searchfolder or "xmlfiles",
    }
    settings_path = _find_settings_file(root)
    if settings_path is None:
        return profile

    profile["settings_path"] = str(settings_path)
    try:
        tree = ET.parse(settings_path)
    except ET.ParseError:
        return profile

    settings_root = tree.getroot()
    token_attrs: List[str] = []
    for item in settings_root.findall(".//xmlfile//pattributes//item"):
        key = str(item.get("key") or "").strip()
        if key and key not in token_attrs:
            token_attrs.append(key)
    profile["token_attrs"] = token_attrs

    meta_fields: List[Dict[str, str]] = []
    for item in settings_root.findall(".//teiheader/item"):
        xpath = str(item.get("xpath") or "").strip()
        if not xpath:
            continue
        name = _derive_meta_field_name(item)
        if not name:
            continue
        meta_fields.append(
            {
                "name": name,
                "display": str(item.get("display") or name).strip(),
                "xpath": xpath,
            }
        )
    profile["meta_fields"] = meta_fields

    cqp_elem = settings_root.find(".//cqp")
    if cqp_elem is not None:
        sf = str(cqp_elem.get("searchfolder") or "").strip()
        if sf:
            profile["searchfolder"] = sf
    return profile


def _parse_xpath_segment(segment: str) -> Tuple[str, Optional[str], Optional[str]]:
    clean = segment.strip()
    attr_name = None
    attr_value = None
    match = re.match(r'^([^\[]+)(\[@([^=]+)=["\']([^"\']+)["\']\])?$', clean)
    if match:
        name = match.group(1).strip()
        attr_name = match.group(3)
        attr_value = match.group(4)
        return name, attr_name, attr_value
    return clean, None, None


def _eval_simple_xpath(root: ET.Element, xpath: str) -> List[str]:
    text = str(xpath or "").strip()
    if not text:
        return []
    attr_terminal: Optional[str] = None
    if "/@" in text:
        text, attr_terminal = text.rsplit("/@", 1)
        attr_terminal = attr_terminal.strip()
    segments = [seg for seg in text.strip("/").split("/") if seg]
    if not segments:
        return []

    current = [root]
    if _local_name(segments[0]) == _local_name(root.tag):
        segments = segments[1:]

    for segment in segments:
        name, pred_attr, pred_value = _parse_xpath_segment(segment)
        next_nodes: List[ET.Element] = []
        for node in current:
            for child in list(node):
                if _local_name(child.tag) != _local_name(name):
                    continue
                if pred_attr is not None:
                    child_attrib = {_local_name(k): v for k, v in child.attrib.items()}
                    if child_attrib.get(_local_name(pred_attr)) != pred_value:
                        continue
                next_nodes.append(child)
        current = next_nodes
        if not current:
            return []

    values: List[str] = []
    for node in current:
        if attr_terminal:
            for key, value in node.attrib.items():
                if _local_name(key) == _local_name(attr_terminal):
                    text_val = str(value).strip()
                    if text_val:
                        values.append(text_val)
        else:
            text_val = _text_value(node)
            if text_val:
                values.append(text_val)
    return values


def _collect_effective_tokens(elem: ET.Element) -> List[ET.Element]:
    name = _local_name(elem.tag)
    if name == "dtok":
        return [elem]
    if name == "w":
        return [elem]
    if name == "tok":
        nested: List[ET.Element] = []
        for child in list(elem):
            nested.extend(_collect_effective_tokens(child))
        return nested or [elem]
    result: List[ET.Element] = []
    for child in list(elem):
        result.extend(_collect_effective_tokens(child))
    return result


def _coverage_from_counts(tokens_with_attr: int, tokens_total: int) -> str:
    if tokens_total <= 0:
        return "none"
    if tokens_with_attr <= 0:
        return "none"
    if tokens_with_attr >= tokens_total:
        return "all"
    return "partial"


def _is_root_token(token_attrib: Dict[str, Any]) -> bool:
    rel = str(token_attrib.get("deprel") or token_attrib.get("deptype") or "").strip().lower()
    return rel == "root"


def _infer_change_state(change_text: str) -> str:
    lower = change_text.lower()
    if any(term in lower for term in ("fail", "failed", "error", "aborted")):
        return "failed"
    if any(term in lower for term in ("blocked", "waiting", "paused")):
        return "blocked"
    if any(term in lower for term in ("partial", "halfway", "in progress", "in-progress")):
        return "partial"
    return "done"


def _canonical_step_name(raw: str) -> Optional[str]:
    text = str(raw or "").strip().lower()
    if not text:
        return None
    aliases = {
        "convert": "converted",
        "converted": "converted",
        "tokenize": "tokenized",
        "tokenized": "tokenized",
        "segment": "segmented",
        "segmented": "segmented",
        "normalize": "normalized",
        "normalized": "normalized",
        "tag": "tagged",
        "tagged": "tagged",
        "parse": "parsed",
        "parsed": "parsed",
        "ner": "ner",
    }
    return aliases.get(text)


def _infer_steps_from_change(change_text: str, attrib: Dict[str, str]) -> List[Tuple[str, str]]:
    steps: List[Tuple[str, str]] = []
    state = _infer_change_state(change_text)
    for key in ("type", "subtype", "n"):
        step_name = _canonical_step_name(attrib.get(key, ""))
        if step_name:
            steps.append((step_name, state))
    lower = change_text.lower()
    for step_name, patterns in STEP_PATTERNS:
        if any(re.search(pattern, lower) for pattern in patterns):
            steps.append((step_name, state))
    return list(dict.fromkeys(steps))


def _upsert_step(
    step_rows: Dict[str, Dict[str, Any]],
    *,
    step_name: str,
    state: str,
    source: str,
    updated_at: Optional[str] = None,
    updated_by: Optional[str] = None,
    note: Optional[str] = None,
    priority: int = 0,
) -> None:
    existing = step_rows.get(step_name)
    if existing is not None and int(existing.get("priority", 0)) > priority:
        return
    step_rows[step_name] = {
        "step_name": step_name,
        "state": state,
        "source": source,
        "updated_at": updated_at,
        "updated_by": updated_by,
        "note": note,
        "priority": priority,
    }


def _group_state(attr_rows: Dict[str, Dict[str, Any]], names: Sequence[str]) -> str:
    relevant = [attr_rows[name]["coverage"] for name in names if name in attr_rows]
    if not relevant:
        return "missing"
    if all(cov == "all" for cov in relevant):
        return "done"
    if any(cov in {"all", "partial"} for cov in relevant):
        return "partial"
    return "missing"


def _extract_doc_facts(path: Path, settings_profile: Dict[str, Any]) -> Tuple[Dict[str, Any], List[Dict[str, Any]], List[Dict[str, Any]], List[Dict[str, Any]]]:
    scan_time = _now_iso()
    doc_row: Dict[str, Any] = {
        "xml_ok": 0,
        "deleted": 0,
        "xml_token_count": 0,
        "has_tokens": 0,
        "title": None,
        "orgfile": None,
        "last_change_when": None,
        "last_change_who": None,
        "last_change_text": None,
        "scan_time": scan_time,
        "xml_phase": "raw",
    }
    token_rows: List[Dict[str, Any]] = []
    meta_rows: List[Dict[str, Any]] = []
    step_rows_map: Dict[str, Dict[str, Any]] = {}

    try:
        xml_root = ET.parse(path).getroot()
    except ET.ParseError as exc:
        _upsert_step(
            step_rows_map,
            step_name="xml_parse",
            state="failed",
            source="xml",
            updated_at=scan_time,
            note=str(exc),
            priority=100,
        )
        return doc_row, token_rows, meta_rows, list(step_rows_map.values())

    doc_row["xml_ok"] = 1

    tokens = _collect_effective_tokens(xml_root)
    doc_row["xml_token_count"] = len(tokens)
    doc_row["has_tokens"] = 1 if tokens else 0

    for elem in xml_root.iter():
        name = _local_name(elem.tag)
        if doc_row["title"] is None and name == "title":
            title_val = _text_value(elem)
            if title_val:
                doc_row["title"] = title_val
        if doc_row["orgfile"] is None and name in {"tei", "document"}:
            attrib = {_local_name(k): v for k, v in elem.attrib.items()}
            orgfile_val = str(attrib.get("orgfile") or attrib.get("source") or "").strip()
            if orgfile_val:
                doc_row["orgfile"] = orgfile_val
        if doc_row["orgfile"] is None and name == "orgfile":
            orgfile_val = _text_value(elem)
            if orgfile_val:
                doc_row["orgfile"] = orgfile_val

    configured_attrs = list(settings_profile.get("token_attrs") or [])
    observed_attrs: List[str] = []
    if not configured_attrs:
        for token in tokens:
            for raw_key in token.attrib.keys():
                key = _local_name(raw_key)
                if key not in observed_attrs and key not in {"id", "ord", "n"}:
                    observed_attrs.append(key)
    attr_names = list(dict.fromkeys(configured_attrs + observed_attrs))
    attr_counts = {name: 0 for name in attr_names}
    has_seg = False
    has_sent = False
    has_name_markup = False
    for elem in xml_root.iter():
        name = _local_name(elem.tag)
        if name == "seg":
            has_seg = True
        if name == "s":
            has_sent = True
        if name in {"name", "rs"}:
            has_name_markup = True

    for token in tokens:
        token_attrib = {_local_name(k): v for k, v in token.attrib.items()}
        for name in attr_names:
            if name == "form":
                value = _text_value(token)
            else:
                value = str(token_attrib.get(name) or "").strip()
            if value or (name in {"head", "dephead"} and _is_root_token(token_attrib)):
                attr_counts[name] += 1

    attr_rows_map: Dict[str, Dict[str, Any]] = {}
    for name in attr_names:
        count = int(attr_counts.get(name, 0))
        coverage = _coverage_from_counts(count, len(tokens))
        row = {
            "attr_name": name,
            "tokens_with_attr": count,
            "tokens_total": len(tokens),
            "coverage": coverage,
            "present": 1 if count > 0 else 0,
        }
        token_rows.append(row)
        attr_rows_map[name] = row

    for spec in settings_profile.get("meta_fields") or []:
        values = _eval_simple_xpath(xml_root, spec.get("xpath") or "")
        unique_values = list(dict.fromkeys(v for v in values if v))
        meta_rows.append(
            {
                "field_name": spec["name"],
                "present": 1 if unique_values else 0,
                "value_text": " | ".join(unique_values) if unique_values else None,
                "source": "xml",
            }
        )

    latest_change: Optional[Tuple[str, str, str]] = None
    for elem in xml_root.iter():
        if _local_name(elem.tag) != "change":
            continue
        change_text = _text_value(elem)
        attrib = {_local_name(k): str(v) for k, v in elem.attrib.items()}
        when = str(attrib.get("when") or "").strip() or None
        who = str(attrib.get("who") or "").strip() or None
        latest_change = (when or "", who or "", change_text)
        for step_name, state in _infer_steps_from_change(change_text, attrib):
            _upsert_step(
                step_rows_map,
                step_name=step_name,
                state=state,
                source="xml",
                updated_at=when,
                updated_by=who,
                note=change_text,
                priority=50,
            )

    if latest_change:
        doc_row["last_change_when"] = latest_change[0] or None
        doc_row["last_change_who"] = latest_change[1] or None
        doc_row["last_change_text"] = latest_change[2] or None

    _upsert_step(step_rows_map, step_name="tokenized", state="done" if tokens else "missing", source="xml", updated_at=scan_time, note="Derived from TEI token inventory.", priority=10)
    _upsert_step(
        step_rows_map,
        step_name="segmented",
        state="done" if (has_seg or has_sent) else "missing",
        source="xml",
        updated_at=scan_time,
        note="Derived from <seg> or <s> presence.",
        priority=10,
    )
    _upsert_step(step_rows_map, step_name="tagged", state=_group_state(attr_rows_map, [name for name in COMMON_TAG_ATTRS if name in attr_rows_map]), source="xml", updated_at=scan_time, note="Derived from TEITOK token-attribute coverage.", priority=10)
    _upsert_step(step_rows_map, step_name="normalized", state=_group_state(attr_rows_map, [name for name in COMMON_NORMALIZE_ATTRS if name in attr_rows_map]), source="xml", updated_at=scan_time, note="Derived from normalization-related token attributes.", priority=10)
    _upsert_step(step_rows_map, step_name="parsed", state=_group_state(attr_rows_map, [name for name in COMMON_PARSE_ATTRS if name in attr_rows_map]), source="xml", updated_at=scan_time, note="Derived from dependency-annotation token attributes.", priority=10)
    ner_attr_state = _group_state(attr_rows_map, [name for name in COMMON_NER_ATTRS if name in attr_rows_map])
    if has_name_markup and ner_attr_state == "missing":
        ner_attr_state = "done"
    _upsert_step(step_rows_map, step_name="ner", state=ner_attr_state, source="xml", updated_at=scan_time, note="Derived from NER-related token attributes or name markup.", priority=10)
    if "converted" not in step_rows_map:
        _upsert_step(step_rows_map, step_name="converted", state="missing", source="xml", updated_at=scan_time, note="No conversion marker found in TEI header changes.", priority=1)

    converted_done = step_rows_map.get("converted", {}).get("state") == "done"
    tagged_state = step_rows_map.get("tagged", {}).get("state")
    parsed_state = step_rows_map.get("parsed", {}).get("state")
    normalized_state = step_rows_map.get("normalized", {}).get("state")
    ner_state = step_rows_map.get("ner", {}).get("state")
    annotated = any(state in {"done", "partial"} for state in (tagged_state, parsed_state, normalized_state, ner_state))
    if not tokens:
        doc_row["xml_phase"] = "converted" if converted_done else "raw"
    elif annotated:
        doc_row["xml_phase"] = "annotated"
    else:
        doc_row["xml_phase"] = "tokenized"

    return doc_row, token_rows, meta_rows, list(step_rows_map.values())


def _summary_config(params: Dict[str, Any], settings_profile: Dict[str, Any]) -> Dict[str, Any]:
    required_attrs = _split_list(params.get("summary_required_attrs") or params.get("required_attrs"))
    required_meta = _split_list(params.get("summary_required_meta") or params.get("required_meta"))
    required_steps = _split_list(params.get("summary_required_steps") or params.get("required_steps"))
    payload = {
        "settings_path": settings_profile.get("settings_path"),
        "token_attrs": settings_profile.get("token_attrs") or [],
        "meta_fields": [row.get("name") for row in settings_profile.get("meta_fields") or []],
        "required_attrs": required_attrs,
        "required_meta": required_meta,
        "required_steps": required_steps,
    }
    return {
        "required_attrs": required_attrs,
        "required_meta": required_meta,
        "required_steps": required_steps,
        "has_explicit_requirements": bool(required_attrs or required_meta or required_steps),
        "summary_settings_hash": _hash_json(payload),
        "settings_hash": _hash_json(
            {
                "settings_path": settings_profile.get("settings_path"),
                "token_attrs": settings_profile.get("token_attrs") or [],
                "meta_fields": [row.get("name") for row in settings_profile.get("meta_fields") or []],
            }
        ),
    }


def _build_summary(
    doc_row: Dict[str, Any],
    token_rows: List[Dict[str, Any]],
    meta_rows: List[Dict[str, Any]],
    step_rows: List[Dict[str, Any]],
    summary_cfg: Dict[str, Any],
) -> Dict[str, Any]:
    attr_map = {row["attr_name"]: row for row in token_rows}
    meta_map = {row["field_name"]: row for row in meta_rows}
    step_map = {row["step_name"]: row for row in step_rows}

    missing_required_steps = 0
    blocking_step: Optional[str] = None
    for step_name in summary_cfg["required_steps"]:
        state = str((step_map.get(step_name) or {}).get("state") or "missing")
        if state not in {"done", "not_applicable"}:
            missing_required_steps += 1
            if blocking_step is None:
                blocking_step = step_name

    partial_required_attrs = 0
    for attr_name in summary_cfg["required_attrs"]:
        coverage = str((attr_map.get(attr_name) or {}).get("coverage") or "none")
        if coverage != "all":
            partial_required_attrs += 1
            if blocking_step is None:
                blocking_step = f"attr:{attr_name}"

    missing_required_meta = 0
    for field_name in summary_cfg["required_meta"]:
        present = int((meta_map.get(field_name) or {}).get("present") or 0)
        if not present:
            missing_required_meta += 1
            if blocking_step is None:
                blocking_step = f"meta:{field_name}"

    has_failed = any(str(row.get("state") or "") == "failed" for row in step_rows)
    has_blocked = any(str(row.get("state") or "") == "blocked" for row in step_rows)
    if has_failed:
        blocking_step = blocking_step or next((row["step_name"] for row in step_rows if str(row.get("state") or "") == "failed"), None)
    elif has_blocked:
        blocking_step = blocking_step or next((row["step_name"] for row in step_rows if str(row.get("state") or "") == "blocked"), None)

    completed_for_settings = 1 if (
        summary_cfg["has_explicit_requirements"]
        and not int(doc_row.get("deleted") or 0)
        and not has_failed
        and not has_blocked
        and missing_required_steps == 0
        and missing_required_meta == 0
        and partial_required_attrs == 0
    ) else 0

    ready_for_annotation = 1 if int(doc_row.get("xml_ok") or 0) and not int(doc_row.get("deleted") or 0) else 0
    ready_for_indexing = 1 if (
        int(doc_row.get("has_tokens") or 0)
        and not int(doc_row.get("deleted") or 0)
        and not has_failed
        and not has_blocked
        and missing_required_steps == 0
        and missing_required_meta == 0
        and partial_required_attrs == 0
    ) else 0

    if int(doc_row.get("deleted") or 0):
        workflow_phase = "deleted"
    elif not int(doc_row.get("xml_ok") or 0):
        workflow_phase = "failed"
    elif has_failed:
        workflow_phase = "failed"
    elif has_blocked:
        workflow_phase = "blocked"
    elif completed_for_settings:
        workflow_phase = "completed"
    elif summary_cfg["has_explicit_requirements"] and blocking_step:
        workflow_phase = "in_progress"
    else:
        workflow_phase = str(doc_row.get("xml_phase") or "inventory")

    return {
        "xml_phase": doc_row.get("xml_phase"),
        "workflow_phase": workflow_phase,
        "blocking_step": blocking_step,
        "missing_required_steps": missing_required_steps,
        "missing_required_meta": missing_required_meta,
        "partial_required_attrs": partial_required_attrs,
        "ready_for_annotation": ready_for_annotation,
        "ready_for_indexing": ready_for_indexing,
        "completed_for_settings": completed_for_settings,
        "summary_settings_hash": summary_cfg["summary_settings_hash"],
        "updated_at": _now_iso(),
    }


def _clear_scan_tables(conn: sqlite3.Connection) -> None:
    cur = conn.cursor()
    for table_name in ("doc_token_attr_status", "doc_meta_field_status", "doc_step_status", "doc_summary"):
        cur.execute(f"DELETE FROM {table_name}")
    conn.commit()


def _write_inventory_meta(cur: sqlite3.Cursor, values: Dict[str, Any]) -> None:
    for key, value in values.items():
        cur.execute("REPLACE INTO inventory_meta (key, value_text) VALUES (?, ?)", (key, None if value is None else str(value)))


def _legacy_missing_clause(missing_filter: str) -> Optional[Tuple[str, List[Any]]]:
    mapping = {
        "tokens": ("(COALESCE(d.has_tokens, 0) = 0)", []),
        "morph": ("EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'tagged' AND st.state != 'done') OR NOT EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'tagged')", []),
        "deps": ("EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'parsed' AND st.state != 'done') OR NOT EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'parsed')", []),
        "names": ("EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'ner' AND st.state != 'done') OR NOT EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'ner')", []),
        "ner": ("EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'ner' AND st.state != 'done') OR NOT EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'ner')", []),
        "normalize": ("EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'normalized' AND st.state != 'done') OR NOT EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = 'normalized')", []),
    }
    return mapping.get(missing_filter)


def _sync_index_status(
    conn: sqlite3.Connection,
    *,
    project: Dict[str, Any],
    backend_name: str,
    params: Dict[str, Any],
) -> Dict[str, Any]:
    backend = BACKENDS.get(backend_name)
    if backend is None:
        raise RuntimeError(f"Cannot sync index status for unknown backend {backend_name!r}.")
    if backend_name == "teitokxml":
        raise RuntimeError("Use another backend name for sync_index_backend; teitokxml is the source inventory.")

    cur = conn.cursor()
    rows = cur.execute("SELECT id FROM docs WHERE deleted = 0").fetchall()
    actual_ids = {str(row["id"]) for row in rows}
    lookup: Dict[str, str] = {}
    for actual_id in actual_ids:
        for key in _doc_lookup_keys(actual_id):
            lookup.setdefault(key, actual_id)

    synced_ids: Set[str] = set()
    batch_size = max(100, int(params.get("sync_batch") or 1000))
    offset = 0
    sync_project = dict(project)
    root = project.get("root")
    if root:
        root_path = Path(str(root)).expanduser().resolve()
        if backend_name == "cqp" or (backend_name == "flexi" and str(project.get("format") or "cwb").strip().lower() == "cwb"):
            detected = detect_teitok_cqp(root_path)
            if detected:
                sync_project.setdefault("root", detected.get("root") or sync_project.get("root"))
                if detected.get("cqp"):
                    sync_project["cqp"] = dict(detected["cqp"])
        if backend_name in {"flexi", "manatee"} and (backend_name == "manatee" or str(project.get("format") or "").strip().lower() == "manatee"):
            detected = detect_teitok_manatee(root_path)
            if detected:
                sync_project.setdefault("root", detected.get("root") or sync_project.get("root"))
                if detected.get("manatee"):
                    sync_project["manatee"] = dict(detected["manatee"])

    while True:
        backend_req: FlexiRequest = {"version": 1, "backend": backend_name, "operation": "list_docs", "project": sync_project, "params": {"limit": batch_size, "offset": offset}}
        if params.get("query_language"):
            backend_req["params"]["query_language"] = params["query_language"]
            backend_req["params"]["query_lang"] = params["query_language"]

        result = backend.list_docs(backend_req)
        docs = list(result.get("docs") or [])
        if not docs:
            break

        for doc in docs:
            doc_id = _normalize_doc_id(doc.get("id"))
            actual_id = None
            for key in _doc_lookup_keys(doc_id):
                actual_id = lookup.get(key)
                if actual_id:
                    break
            if actual_id:
                synced_ids.add(actual_id)

        total = int(result.get("total") or 0)
        offset += len(docs)
        if len(docs) < batch_size or (total and offset >= total):
            break

    cur.execute("DELETE FROM doc_index_status WHERE backend = ?", (backend_name,))
    cur.executemany("INSERT INTO doc_index_status (backend, doc_id, indexed) VALUES (?, ?, 1)", [(backend_name, doc_id) for doc_id in sorted(synced_ids)])
    missing_ids = sorted(actual_ids - synced_ids)
    cur.executemany("INSERT INTO doc_index_status (backend, doc_id, indexed) VALUES (?, ?, 0)", [(backend_name, doc_id) for doc_id in missing_ids])
    return {"backend": backend_name, "indexed_docs": len(synced_ids), "not_indexed_docs": len(missing_ids)}


@dataclass
class TeitokXmlBackend(CorpusBackend):
    name: str = "teitokxml"

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "teitokxml",
            "supported_query_languages": ["teitok"],
            "supported_corpus_formats": ["xml"],
            "default_query_language": "teitok",
            "default_corpus_format": "xml",
            "default_selection_reason": "Lightweight backend over TEITOK xmlfiles/ and doclist.sqlite inventory.",
        }

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": False,
            "freq": False,
            "info": True,
            "reindex": True,
            "raw_query": False,
            "query": False,
        }

    def _status_summary(self, conn: sqlite3.Connection) -> Dict[str, Any]:
        cur = conn.cursor()
        total = int(cur.execute("SELECT COUNT(*) FROM docs").fetchone()[0])
        by_xml_phase = dict(
            (row["phase"], row["cnt"])
            for row in cur.execute(
                "SELECT COALESCE(NULLIF(xml_phase, ''), 'unknown') AS phase, COUNT(*) AS cnt FROM docs GROUP BY COALESCE(NULLIF(xml_phase, ''), 'unknown')"
            )
        )
        by_workflow_phase = dict(
            (row["phase"], row["cnt"])
            for row in cur.execute(
                "SELECT COALESCE(NULLIF(workflow_phase, ''), 'unknown') AS phase, COUNT(*) AS cnt FROM doc_summary GROUP BY COALESCE(NULLIF(workflow_phase, ''), 'unknown')"
            )
        )
        inventory_meta = {str(row["key"]): row["value_text"] for row in cur.execute("SELECT key, value_text FROM inventory_meta")}
        return {
            "total_docs": total,
            "by_xml_phase": by_xml_phase,
            "by_workflow_phase": by_workflow_phase,
            "inventory_meta": inventory_meta,
        }

    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        root = _get_project_root(project)
        conn, db_path = _open_docs_db(root)
        try:
            _init_schema(conn)
            summary = self._status_summary(conn)
        finally:
            conn.close()
        return {
            "backend": self.name,
            "project_root": str(root),
            "db_path": str(db_path),
            "docs_total": summary["total_docs"],
            "docs_by_xml_phase": summary["by_xml_phase"],
            "docs_by_workflow_phase": summary["by_workflow_phase"],
            "inventory_meta": summary["inventory_meta"],
        }

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        payload = self.status(req)
        payload["descriptor"] = self.descriptor()
        return payload

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        root = _get_project_root(project)
        conn, _db_path = _open_docs_db(root)
        try:
            _init_schema(conn)
            limit = int(params.get("limit", 50))
            offset = int(params.get("offset", 0))
            status_filter = str(params.get("status") or "").strip()
            xml_phase_filter = str(params.get("xml_phase") or "").strip()
            workflow_phase_filter = str(params.get("workflow_phase") or "").strip()
            search = str(params.get("search") or "").strip().lower()
            doc_id_filter = str(params.get("doc") or "").strip()
            missing_filter = str(params.get("missing") or "").strip().lower()
            indexed_filter = str(params.get("indexed") or "").strip().lower()
            indexed_backend = str(params.get("indexed_backend") or "").strip()
            attr_name = str(params.get("attr") or "").strip()
            attr_coverage = str(params.get("attr_coverage") or "").strip().lower()
            missing_attr = str(params.get("missing_attr") or "").strip()
            meta_field = str(params.get("meta_field") or "").strip()
            missing_meta = str(params.get("missing_meta") or "").strip()
            step_name = str(params.get("step") or "").strip()
            step_state = str(params.get("step_state") or "").strip().lower()
            completed_filter = str(params.get("completed_for_current_settings") or "").strip().lower()
            ready_filter = str(params.get("ready_for_indexing") or "").strip().lower()
            sort_by = str(params.get("sort_by") or "id").strip().lower()
            sort_order = str(params.get("sort_order") or params.get("sort_dir") or "asc").strip().lower()

            sortable_fields = {
                "id": "d.id",
                "relative_path": "d.relative_path",
                "xml_ok": "d.xml_ok",
                "deleted": "d.deleted",
                "xml_token_count": "d.xml_token_count",
                "has_tokens": "d.has_tokens",
                "title": "COALESCE(d.title, d.id)",
                "orgfile": "d.orgfile",
                "size_bytes": "d.size_bytes",
                "mtime": "d.mtime",
                "last_change_when": "d.last_change_when",
                "last_change_who": "d.last_change_who",
                "last_change_text": "d.last_change_text",
                "scan_time": "d.scan_time",
                "xml_phase": "COALESCE(s.xml_phase, d.xml_phase)",
                "workflow_phase": "s.workflow_phase",
                "blocking_step": "s.blocking_step",
                "missing_required_steps": "s.missing_required_steps",
                "missing_required_meta": "s.missing_required_meta",
                "partial_required_attrs": "s.partial_required_attrs",
                "ready_for_annotation": "s.ready_for_annotation",
                "ready_for_indexing": "s.ready_for_indexing",
                "completed_for_settings": "s.completed_for_settings",
            }
            sort_expr = sortable_fields.get(sort_by, "d.id")
            sort_direction = "DESC" if sort_order == "desc" else "ASC"
            order_sql = f"{sort_expr} {sort_direction}, d.id ASC" if sort_expr != "d.id" else f"{sort_expr} {sort_direction}"

            where_clauses: List[str] = []
            args: List[Any] = []
            joins: List[str] = ["LEFT JOIN doc_summary s ON s.doc_id = d.id"]

            if doc_id_filter:
                where_clauses.append("d.id = ?")
                args.append(doc_id_filter)
            if status_filter:
                where_clauses.append("(COALESCE(s.workflow_phase, '') = ? OR COALESCE(d.xml_phase, '') = ?)")
                args.extend([status_filter, status_filter])
            if xml_phase_filter:
                where_clauses.append("COALESCE(d.xml_phase, '') = ?")
                args.append(xml_phase_filter)
            if workflow_phase_filter:
                where_clauses.append("COALESCE(s.workflow_phase, '') = ?")
                args.append(workflow_phase_filter)
            if search:
                where_clauses.append("(d.id LIKE ? OR COALESCE(d.title, '') LIKE ? OR COALESCE(d.orgfile, '') LIKE ? OR d.relative_path LIKE ? OR COALESCE(s.blocking_step, '') LIKE ?)")
                pat = f"%{search}%"
                args.extend([pat, pat, pat, pat, pat])
            if missing_filter:
                clause = _legacy_missing_clause(missing_filter)
                if clause is not None:
                    where_clauses.append(clause[0])
                    args.extend(clause[1])
            if indexed_filter in {"yes", "no"}:
                backend_name = indexed_backend or self.name
                if indexed_filter == "yes":
                    joins.append("INNER JOIN doc_index_status dis ON dis.doc_id = d.id AND dis.backend = ? AND dis.indexed = 1")
                else:
                    joins.append("LEFT JOIN doc_index_status dis ON dis.doc_id = d.id AND dis.backend = ?")
                    where_clauses.append("(dis.indexed IS NULL OR dis.indexed = 0)")
                args.insert(0, backend_name)
            if attr_name and attr_coverage:
                where_clauses.append("EXISTS (SELECT 1 FROM doc_token_attr_status tas WHERE tas.doc_id = d.id AND tas.attr_name = ? AND tas.coverage = ?)")
                args.extend([attr_name, attr_coverage])
            elif attr_name:
                where_clauses.append("EXISTS (SELECT 1 FROM doc_token_attr_status tas WHERE tas.doc_id = d.id AND tas.attr_name = ?)")
                args.append(attr_name)
            if missing_attr:
                where_clauses.append("(EXISTS (SELECT 1 FROM doc_token_attr_status tas WHERE tas.doc_id = d.id AND tas.attr_name = ? AND tas.coverage != 'all') OR NOT EXISTS (SELECT 1 FROM doc_token_attr_status tas WHERE tas.doc_id = d.id AND tas.attr_name = ?))")
                args.extend([missing_attr, missing_attr])
            if meta_field:
                where_clauses.append("EXISTS (SELECT 1 FROM doc_meta_field_status mfs WHERE mfs.doc_id = d.id AND mfs.field_name = ?)")
                args.append(meta_field)
            if missing_meta:
                where_clauses.append("(EXISTS (SELECT 1 FROM doc_meta_field_status mfs WHERE mfs.doc_id = d.id AND mfs.field_name = ? AND mfs.present = 0) OR NOT EXISTS (SELECT 1 FROM doc_meta_field_status mfs WHERE mfs.doc_id = d.id AND mfs.field_name = ?))")
                args.extend([missing_meta, missing_meta])
            if step_name and step_state:
                if step_state == "missing":
                    where_clauses.append("(EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = ? AND st.state = 'missing') OR NOT EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = ?))")
                    args.extend([step_name, step_name])
                else:
                    where_clauses.append("EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = ? AND st.state = ?)")
                    args.extend([step_name, step_state])
            elif step_name:
                where_clauses.append("EXISTS (SELECT 1 FROM doc_step_status st WHERE st.doc_id = d.id AND st.step_name = ?)")
                args.append(step_name)
            if completed_filter in {"yes", "no"}:
                where_clauses.append("COALESCE(s.completed_for_settings, 0) = ?")
                args.append(1 if completed_filter == "yes" else 0)
            if ready_filter in {"yes", "no"}:
                where_clauses.append("COALESCE(s.ready_for_indexing, 0) = ?")
                args.append(1 if ready_filter == "yes" else 0)

            join_sql = " ".join(joins)
            where_sql = "WHERE " + " AND ".join(where_clauses) if where_clauses else ""
            cur = conn.cursor()
            total = int(cur.execute(f"SELECT COUNT(*) FROM docs d {join_sql} {where_sql}", args).fetchone()[0])
            rows = cur.execute(
                f"""
                SELECT d.id, d.relative_path, d.xml_ok, d.deleted, d.xml_token_count, d.has_tokens,
                       d.title, d.orgfile, d.size_bytes, d.mtime, d.last_change_when,
                       d.last_change_who, d.last_change_text, d.scan_time, d.xml_phase,
                       s.workflow_phase, s.blocking_step, s.missing_required_steps,
                       s.missing_required_meta, s.partial_required_attrs,
                       s.ready_for_annotation, s.ready_for_indexing, s.completed_for_settings
                FROM docs d
                {join_sql}
                {where_sql}
                ORDER BY {order_sql}
                LIMIT ? OFFSET ?
                """,
                args + [limit, offset],
            ).fetchall()

            doc_ids = [str(row["id"]) for row in rows]
            attr_map: Dict[str, Dict[str, str]] = {}
            step_map: Dict[str, Dict[str, str]] = {}
            meta_map: Dict[str, Dict[str, str]] = {}
            if doc_ids:
                placeholders = ",".join("?" for _ in doc_ids)
                for row in cur.execute(f"SELECT doc_id, attr_name, coverage FROM doc_token_attr_status WHERE doc_id IN ({placeholders}) ORDER BY attr_name", doc_ids):
                    attr_map.setdefault(str(row["doc_id"]), {})[str(row["attr_name"])] = str(row["coverage"] or "")
                for row in cur.execute(f"SELECT doc_id, step_name, state FROM doc_step_status WHERE doc_id IN ({placeholders}) ORDER BY step_name", doc_ids):
                    step_map.setdefault(str(row["doc_id"]), {})[str(row["step_name"])] = str(row["state"] or "")
                for row in cur.execute(f"SELECT doc_id, field_name, value_text, present FROM doc_meta_field_status WHERE doc_id IN ({placeholders}) ORDER BY field_name", doc_ids):
                    value = str(row["value_text"] or "") if int(row["present"] or 0) else ""
                    meta_map.setdefault(str(row["doc_id"]), {})[str(row["field_name"])] = value

            docs: List[Dict[str, Any]] = []
            for row in rows:
                doc_id = str(row["id"])
                meta: Dict[str, Any] = {
                    "xml_ok": row["xml_ok"],
                    "deleted": row["deleted"],
                    "xml_phase": row["xml_phase"],
                    "workflow_phase": row["workflow_phase"],
                    "has_tokens": row["has_tokens"],
                    "xml_token_count": row["xml_token_count"],
                    "relative_path": row["relative_path"],
                    "orgfile": row["orgfile"],
                    "last_change_when": row["last_change_when"],
                    "last_change_who": row["last_change_who"],
                    "last_change_text": row["last_change_text"],
                    "size_bytes": row["size_bytes"],
                    "mtime": row["mtime"],
                    "scan_time": row["scan_time"],
                    "blocking_step": row["blocking_step"],
                    "missing_required_steps": row["missing_required_steps"],
                    "missing_required_meta": row["missing_required_meta"],
                    "partial_required_attrs": row["partial_required_attrs"],
                    "ready_for_annotation": row["ready_for_annotation"],
                    "ready_for_indexing": row["ready_for_indexing"],
                    "completed_for_settings": row["completed_for_settings"],
                }
                if doc_id in attr_map:
                    meta["attr_coverage"] = attr_map[doc_id]
                if doc_id in step_map:
                    meta["step_states"] = step_map[doc_id]
                if doc_id in meta_map:
                    meta["meta_fields"] = meta_map[doc_id]
                meta = {k: v for k, v in meta.items() if v not in (None, "", 0, {})}
                docs.append({"id": doc_id, "title": row["title"] or doc_id, "meta": meta})
            return {"docs": docs, "total": total}
        finally:
            conn.close()


    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        root = _get_project_root(project)
        searchfolder = _get_searchfolder(project)
        fast_only = _parse_boolish(params.get("fast"))
        sync_backends = [name.strip() for name in str(params.get("sync_index_backend") or "").split(",") if name and name.strip()]
        settings_profile = _load_settings_profile(root, searchfolder)
        if settings_profile.get("searchfolder"):
            searchfolder = str(settings_profile["searchfolder"])
        summary_cfg = _summary_config(params, settings_profile)

        conn, db_path = _open_docs_db(root)
        try:
            _init_schema(conn)
            _clear_scan_tables(conn)
            cur = conn.cursor()
            files = _scan_xmlfiles(root, searchfolder)
            seen_ids: Set[str] = set()
            now_iso = _now_iso()
            existing_ids = {str(row["id"]) for row in cur.execute("SELECT id FROM docs").fetchall()}

            for doc_id, rel_path, size_bytes, mtime in files:
                seen_ids.add(doc_id)
                if fast_only:
                    doc_row = {
                        "xml_ok": None,
                        "deleted": 0,
                        "xml_token_count": None,
                        "has_tokens": None,
                        "title": None,
                        "orgfile": None,
                        "size_bytes": size_bytes,
                        "mtime": mtime,
                        "last_change_when": None,
                        "last_change_who": None,
                        "last_change_text": None,
                        "scan_time": now_iso,
                        "xml_phase": None,
                    }
                    token_rows: List[Dict[str, Any]] = []
                    meta_rows: List[Dict[str, Any]] = []
                    step_rows: List[Dict[str, Any]] = []
                    summary_row = {
                        "xml_phase": None,
                        "workflow_phase": "inventory",
                        "blocking_step": None,
                        "missing_required_steps": 0,
                        "missing_required_meta": 0,
                        "partial_required_attrs": 0,
                        "ready_for_annotation": 0,
                        "ready_for_indexing": 0,
                        "completed_for_settings": 0,
                        "summary_settings_hash": summary_cfg["summary_settings_hash"],
                        "updated_at": now_iso,
                    }
                else:
                    doc_row, token_rows, meta_rows, step_rows = _extract_doc_facts(root / rel_path, settings_profile)
                    doc_row["size_bytes"] = size_bytes
                    doc_row["mtime"] = mtime
                    summary_row = _build_summary(doc_row, token_rows, meta_rows, step_rows, summary_cfg)

                cur.execute(
                    """
                    INSERT INTO docs (
                        id, relative_path, xml_ok, deleted, xml_token_count, has_tokens,
                        title, orgfile, size_bytes, mtime, last_change_when,
                        last_change_who, last_change_text, scan_time, xml_phase
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(id) DO UPDATE SET
                        relative_path = excluded.relative_path,
                        xml_ok = excluded.xml_ok,
                        deleted = excluded.deleted,
                        xml_token_count = excluded.xml_token_count,
                        has_tokens = excluded.has_tokens,
                        title = excluded.title,
                        orgfile = excluded.orgfile,
                        size_bytes = excluded.size_bytes,
                        mtime = excluded.mtime,
                        last_change_when = excluded.last_change_when,
                        last_change_who = excluded.last_change_who,
                        last_change_text = excluded.last_change_text,
                        scan_time = excluded.scan_time,
                        xml_phase = excluded.xml_phase
                    """,
                    (
                        doc_id, rel_path, doc_row.get("xml_ok"), doc_row.get("deleted", 0), doc_row.get("xml_token_count"),
                        doc_row.get("has_tokens"), doc_row.get("title"), doc_row.get("orgfile"), doc_row.get("size_bytes"),
                        doc_row.get("mtime"), doc_row.get("last_change_when"), doc_row.get("last_change_who"),
                        doc_row.get("last_change_text"), doc_row.get("scan_time"), doc_row.get("xml_phase"),
                    ),
                )

                cur.execute(
                    """
                    INSERT INTO doc_summary (
                        doc_id, xml_phase, workflow_phase, blocking_step,
                        missing_required_steps, missing_required_meta, partial_required_attrs,
                        ready_for_annotation, ready_for_indexing, completed_for_settings,
                        summary_settings_hash, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(doc_id) DO UPDATE SET
                        xml_phase = excluded.xml_phase,
                        workflow_phase = excluded.workflow_phase,
                        blocking_step = excluded.blocking_step,
                        missing_required_steps = excluded.missing_required_steps,
                        missing_required_meta = excluded.missing_required_meta,
                        partial_required_attrs = excluded.partial_required_attrs,
                        ready_for_annotation = excluded.ready_for_annotation,
                        ready_for_indexing = excluded.ready_for_indexing,
                        completed_for_settings = excluded.completed_for_settings,
                        summary_settings_hash = excluded.summary_settings_hash,
                        updated_at = excluded.updated_at
                    """,
                    (
                        doc_id, summary_row.get("xml_phase"), summary_row.get("workflow_phase"), summary_row.get("blocking_step"),
                        summary_row.get("missing_required_steps", 0), summary_row.get("missing_required_meta", 0),
                        summary_row.get("partial_required_attrs", 0), summary_row.get("ready_for_annotation", 0),
                        summary_row.get("ready_for_indexing", 0), summary_row.get("completed_for_settings", 0),
                        summary_row.get("summary_settings_hash"), summary_row.get("updated_at"),
                    ),
                )

                for row in token_rows:
                    cur.execute(
                        "INSERT INTO doc_token_attr_status (doc_id, attr_name, tokens_with_attr, tokens_total, coverage, present) VALUES (?, ?, ?, ?, ?, ?)",
                        (doc_id, row["attr_name"], row["tokens_with_attr"], row["tokens_total"], row["coverage"], row["present"]),
                    )
                for row in meta_rows:
                    cur.execute(
                        "INSERT INTO doc_meta_field_status (doc_id, field_name, present, value_text, source) VALUES (?, ?, ?, ?, ?)",
                        (doc_id, row["field_name"], row["present"], row["value_text"], row["source"]),
                    )
                for row in step_rows:
                    cur.execute(
                        "INSERT INTO doc_step_status (doc_id, step_name, state, source, updated_at, updated_by, note) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        (doc_id, row["step_name"], row["state"], row["source"], row.get("updated_at"), row.get("updated_by"), row.get("note")),
                    )

            deleted_ids = sorted(existing_ids - seen_ids)
            for doc_id in deleted_ids:
                cur.execute(
                    """
                    UPDATE docs
                    SET deleted = 1,
                        xml_ok = NULL,
                        xml_token_count = NULL,
                        has_tokens = NULL,
                        title = NULL,
                        orgfile = NULL,
                        size_bytes = NULL,
                        mtime = NULL,
                        last_change_when = NULL,
                        last_change_who = NULL,
                        last_change_text = NULL,
                        scan_time = ?,
                        xml_phase = 'deleted'
                    WHERE id = ?
                    """,
                    (now_iso, doc_id),
                )
                cur.execute(
                    """
                    INSERT INTO doc_summary (
                        doc_id, xml_phase, workflow_phase, blocking_step,
                        missing_required_steps, missing_required_meta, partial_required_attrs,
                        ready_for_annotation, ready_for_indexing, completed_for_settings,
                        summary_settings_hash, updated_at
                    ) VALUES (?, 'deleted', 'deleted', NULL, 0, 0, 0, 0, 0, 0, ?, ?)
                    ON CONFLICT(doc_id) DO UPDATE SET
                        xml_phase = 'deleted', workflow_phase = 'deleted', blocking_step = NULL,
                        missing_required_steps = 0, missing_required_meta = 0, partial_required_attrs = 0,
                        ready_for_annotation = 0, ready_for_indexing = 0, completed_for_settings = 0,
                        summary_settings_hash = excluded.summary_settings_hash, updated_at = excluded.updated_at
                    """,
                    (doc_id, summary_cfg["summary_settings_hash"], now_iso),
                )

            _write_inventory_meta(
                cur,
                {
                    "schema_version": SCHEMA_VERSION,
                    "searchfolder": searchfolder,
                    "settings_path": settings_profile.get("settings_path"),
                    "settings_hash": summary_cfg["settings_hash"],
                    "summary_settings_hash": summary_cfg["summary_settings_hash"],
                    "last_fast_scan": now_iso if fast_only else None,
                    "last_full_scan": None if fast_only else now_iso,
                },
            )

            index_sync: List[Dict[str, Any]] = []
            for backend_name in sync_backends:
                index_sync.append(_sync_index_status(conn, project=project, backend_name=backend_name, params=params))

            conn.commit()
        finally:
            conn.close()

        return {
            "backend": self.name,
            "project_root": str(root),
            "db_path": str(db_path),
            "searchfolder": searchfolder,
            "fast_only": fast_only,
            "settings_path": settings_profile.get("settings_path"),
            "settings_hash": summary_cfg["settings_hash"],
            "summary_settings_hash": summary_cfg["summary_settings_hash"],
            "sync_index_backend": sync_backends,
            "index_sync": index_sync,
        }


register_backend(TeitokXmlBackend())
