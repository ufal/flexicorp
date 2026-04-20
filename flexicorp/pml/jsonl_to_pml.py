"""
Convert flexencoder ClickHouse JSONL (docs, sentences, toks, dep_edges) to PML-like XML.

**Layers** (``pml_layers`` argument):

- ``wa`` (default): two files per document — ``.w`` (tokens) and ``.a`` (trees).
  Analytical nodes use ``w.rf`` → ``w#…`` instead of a separate ``.m`` layer.
  ``a-root`` / ``s.rf`` points at the first token of the sentence as a spine anchor.
  Suited to TEITOK-style “flat” workflows that do not need a distinct m-layer file.

- ``pdt3``: classic PDT-style triplets ``.w``, ``.m``, ``.a`` (morphology in ``.m``,
  analytical nodes use ``m.rf``).

- ``flat``: one ``.flat.xml`` per document — sentences and tokens with
  ``head`` / ``deprel`` attributes (no separate analytical tree XML). For custom
  loaders; not PDT-valid.

Morphological ``tag`` in ``pdt3`` ``.m`` is approximated from UPOS; analytical
``afun`` comes from UD ``dep_rel`` mapped into PDT enumerations.

Requires: ``docs.jsonl``, ``toks.jsonl`` (skip tokens with ``is_empty`` truthy).
"""

from __future__ import annotations

import json
import re
from collections import defaultdict
from pathlib import Path
from typing import Any, DefaultDict, Dict, List, Literal, Mapping, Optional, Set, Tuple

PmlLayers = Literal["pdt3", "wa", "flat"]


def _normalize_pml_layers(raw: Any) -> PmlLayers:
    s = str(raw or "wa").strip().lower()
    if s in ("pdt3", "pdt", "wma", "full", "3", "triple"):
        return "pdt3"
    if s in ("wa", "wa2", "compact", "2", "two"):
        return "wa"
    if s in ("flat", "1", "one", "single"):
        return "flat"
    return "wa"
from xml.sax.saxutils import escape


def _xml_text(s: str) -> str:
    return escape(s, entities={"\"": "&quot;", "'": "&apos;"})


def _safe_id_segment(s: str) -> str:
    s = re.sub(r"[^\w.\-]", "_", s.strip())
    return s or "x"


def _pdt_tag_from_upos(upos: str) -> str:
    """Pad / fake a 15-character PDT-style tag slot so the schema has a string."""
    u = (upos or "X").strip() or "X"
    if len(u) >= 15:
        return u[:15]
    return u.ljust(15, "-")


# UD deprel (possibly with subtype) -> PDT analytical function (enum in adata_schema)
_UD_DEP_TO_AFUN: Mapping[str, str] = {
    "root": "Pred",
    "nsubj": "Sb",
    "nsubj:pass": "Sb",
    "nsubj:outer": "Sb",
    "csubj": "Sb",
    "csubj:pass": "Sb",
    "obj": "Obj",
    "iobj": "Obj",
    "obl": "Adv",
    "obl:unmarked": "Adv",
    "obl:arg": "Adv",
    "advmod": "Adv",
    "advcl": "Adv",
    "obl:cmp": "Adv",
    "ccomp": "Obj",
    "xcomp": "Obj",
    "amod": "Atr",
    "det": "Atr",
    "nmod": "Atr",
    "acl": "Atr",
    "acl:relcl": "Atr",
    "compound": "Atr",
    "fixed": "Atr",
    "flat": "Atr",
    "flat:foreign": "Atr",
    "conj": "Coord",
    "cc": "AuxC",
    "mark": "AuxC",
    "cop": "Pnom",
    "aux": "AuxV",
    "aux:pass": "AuxV",
    "expl": "Obj",
    "vocative": "ExD",
    "discourse": "Adv",
    "dislocated": "ExD",
    "case": "Atr",
    "punct": "AuxK",
    "dep": "ExD",
    "appos": "Apos",
    "list": "ExD",
    "parataxis": "ExD",
    "orphan": "ExD",
    "goeswith": "Atr",
    "reparandum": "ExD",
}


def _afun_from_deprel(dep_rel: str) -> str:
    dr = (dep_rel or "").strip().lower()
    if not dr:
        return "Atr"
    if dr in _UD_DEP_TO_AFUN:
        return _UD_DEP_TO_AFUN[dr]
    base = dr.split(":", 1)[0]
    return _UD_DEP_TO_AFUN.get(base, "Atr")


def _load_jsonl_objects(path: Path) -> List[Dict[str, Any]]:
    rows: List[Dict[str, Any]] = []
    if not path.is_file():
        return rows
    with path.open("r", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                rows.append(json.loads(line))
            except json.JSONDecodeError:
                continue
    return rows


def _group_toks_by_doc_sent(
    toks: List[Dict[str, Any]],
) -> Tuple[Dict[int, List[Dict[str, Any]]], Dict[Tuple[int, int], List[Dict[str, Any]]]]:
    by_doc: Dict[int, List[Dict[str, Any]]] = defaultdict(list)
    by_sent: Dict[Tuple[int, int], List[Dict[str, Any]]] = defaultdict(list)
    for row in toks:
        if not isinstance(row, dict):
            continue
        if row.get("is_empty") in (1, "1", True, "true"):
            continue
        try:
            did = int(row.get("doc_id", -1))
            sid = int(row.get("sentence_id", -1))
        except (TypeError, ValueError):
            continue
        if did < 0 or sid < 0:
            continue
        by_doc[did].append(row)
        by_sent[(did, sid)].append(row)
    for lst in by_doc.values():
        lst.sort(key=lambda r: (int(r.get("tok_pos", 0) or 0)))
    for key in list(by_sent.keys()):
        by_sent[key].sort(key=lambda r: (int(r.get("sent_ord", 0) or 0), int(r.get("tok_pos", 0) or 0)))
    return dict(by_doc), dict(by_sent)


def _build_a_subtree(
    *,
    sentence_id: int,
    doc_id: int,
    toks_sorted: List[Dict[str, Any]],
    link_to_words: bool = False,
) -> Tuple[str, Set[int]]:
    """
    Return one analytical tree LM (``a-root`` …) for a sentence, plus used tok positions.
    ``afun`` is the analytical function toward the parent (PDT-style). Root depends on ``AuxS`` with ``Pred``.

    If ``link_to_words`` is True (``wa`` mode), nodes use ``w.rf`` → ``w#…`` and
    ``s.rf`` on the technical root points at the first token of the sentence.
    Otherwise (``pdt3``), nodes use ``m.rf`` and ``s.rf`` → ``m#s…``.
    """
    if not toks_sorted:
        return "", set()

    by_pos: Dict[int, Dict[str, Any]] = {}
    for t in toks_sorted:
        by_pos[int(t.get("tok_pos", 0) or 0)] = t
    pos_set = set(by_pos.keys())

    def _parse_head(raw: Any) -> Optional[int]:
        if raw is None or str(raw) in ("null", ""):
            return None
        try:
            v = int(raw)
        except (TypeError, ValueError):
            return None
        return v if v >= 0 else None

    # parent token position (within sentence) per tok_pos; None => hang under AuxS
    parent_of: Dict[int, Optional[int]] = {}
    for tp, row in by_pos.items():
        hp = _parse_head(row.get("head_tok_pos"))
        dr = str(row.get("dep_rel") or "").strip().lower()
        rootish = dr == "root" or dr.startswith("root:")
        if rootish:
            parent_of[tp] = None
        elif hp is None or hp not in pos_set:
            parent_of[tp] = None
        else:
            parent_of[tp] = hp

    children_of: DefaultDict[Optional[int], List[int]] = defaultdict(list)
    for tp, par in parent_of.items():
        children_of[par].append(tp)
    for lst in children_of.values():
        lst.sort(key=lambda p: int(by_pos[p].get("sent_ord", 0) or 0))

    sid_ref = f"s{doc_id}_{sentence_id}"
    root_id = f"ar{doc_id}_{sentence_id}"
    first_tp = min(pos_set) if pos_set else 0

    def afun_for(tp: int) -> str:
        dr = str(by_pos[tp].get("dep_rel") or "")
        dlow = dr.strip().lower()
        if dlow == "root" or dlow.startswith("root:"):
            return "Pred"
        return _afun_from_deprel(dr)

    def emit_a_node(tp: int) -> str:
        row = by_pos[tp]
        wid = f"w{doc_id}_{tp}"
        m_id = f"m{doc_id}_{tp}"
        aid = f"a{doc_id}_{tp}"
        ord_v = int(row.get("sent_ord", 0) or 0)
        af = afun_for(tp)
        kids = children_of.get(tp, [])
        inner_children = "".join(f"<LM>{emit_a_node(ct)}</LM>" for ct in kids)
        if link_to_words:
            link_rf = f"<w.rf><LM>w#{_xml_text(wid)}</LM></w.rf>"
        else:
            link_rf = f"<m.rf><LM>m#{_xml_text(m_id)}</LM></m.rf>"
        return (
            f'<a-node id="{_xml_text(aid)}">{link_rf}'
            f"<afun>{_xml_text(af)}</afun><is_member>0</is_member><is_parenthesis_root>0</is_parenthesis_root>"
            f"<ord>{ord_v}</ord><children>{inner_children}</children></a-node>"
        )

    tops = children_of.get(None, [])
    if not tops:
        tops = [min(pos_set)] if pos_set else []

    top_xml = "".join(f"<LM>{emit_a_node(tp)}</LM>" for tp in tops)

    if link_to_words:
        s_anchor = f"w#w{doc_id}_{first_tp}"
    else:
        s_anchor = f"m#{sid_ref}"
    root_block = (
        f'<a-root id="{_xml_text(root_id)}">'
        f"<s.rf><LM>{_xml_text(s_anchor)}</LM></s.rf>"
        f"<afun>AuxS</afun><ord>0</ord><children>{top_xml}</children></a-root>"
    )

    used = set(by_pos.keys())
    return root_block, used


def _write_w_file(path: Path, *, doc_id: int, text_id: str, lang: str, paras: List[Dict[str, str]]) -> None:
    """One ``para`` per token (PDT w-schema: para = othermarkup + single w).

    ``no_space_after`` is only emitted when ``1`` (no following space); the default
    spaced case omits the member, matching typical PML serializations.
    """
    parts: List[str] = [
        '<?xml version="1.0" encoding="utf-8"?>',
        "<wdata>",
        "<meta>",
        f"<lang>{_xml_text(lang)}</lang>",
        "<original_format>teitok-flexencoder</original_format>",
        "</meta>",
        f'<doc id="d{_xml_text(str(doc_id))}" source_id="{_xml_text(text_id)}">',
        '<docmeta><othermeta origin="flexicorp">jsonl_to_pml</othermeta></docmeta>',
    ]
    for i, tok in enumerate(paras):
        tok_xml = tok.get("token", "")
        wid = tok.get("id", f"w{doc_id}_{i+1}")
        parts.append("<para>")
        parts.append('<othermarkup origin=""/>')
        parts.append(f'<w id="{_xml_text(wid)}">')
        parts.append(f"<token>{_xml_text(tok_xml)}</token>")
        if tok.get("no_space_after") == "1":
            parts.append("<no_space_after>1</no_space_after>")
        parts.append("</w>")
        parts.append("</para>")
    parts.append("</doc>")
    parts.append("</wdata>")
    path.write_text("\n".join(parts) + "\n", encoding="utf-8")


def _write_m_file(
    path: Path,
    *,
    doc_id: int,
    lang: str,
    sentences: List[Tuple[int, List[Dict[str, Any]]]],
) -> None:
    parts: List[str] = [
        '<?xml version="1.0" encoding="utf-8"?>',
        "<mdata>",
        "<meta>",
        f"<lang>{_xml_text(lang)}</lang>",
        "<annotation_info>",
        '<LM><m-annotation-info id="manual">'
        "<version_info>flexicorp-jsonl_to_pml</version_info>"
        "<desc>Converted from flexencoder JSONL</desc>"
        "</m-annotation-info></LM>",
        "</annotation_info>",
        "</meta>",
    ]
    for sentence_id, toks in sentences:
        sid = f"s{doc_id}_{sentence_id}"
        parts.append(f'<s id="{_xml_text(sid)}">')
        for t in toks:
            tp = int(t.get("tok_pos", 0) or 0)
            mid = f"m{doc_id}_{tp}"
            form = str(t.get("form", "") or "")
            lemma = str(t.get("lemma", "") or form)
            tag = _pdt_tag_from_upos(str(t.get("upos", "") or ""))
            wid = f"w{doc_id}_{tp}"
            parts.append(f'<m id="{_xml_text(mid)}">')
            parts.append('<src.rf><LM>manual#manual</LM></src.rf>')
            parts.append("<w.rf>")
            parts.append(f"<LM>w#{_xml_text(wid)}</LM>")
            parts.append("</w.rf>")
            parts.append(f"<form>{_xml_text(form)}</form>")
            parts.append(f"<lemma>{_xml_text(lemma)}</lemma>")
            parts.append(f"<tag>{_xml_text(tag)}</tag>")
            parts.append("</m>")
        parts.append("</s>")
    parts.append("</mdata>")
    path.write_text("\n".join(parts) + "\n", encoding="utf-8")


def _write_a_file(path: Path, *, doc_id: int, trees_xml_parts: List[str]) -> None:
    inner = "".join(f"<LM>{tx}</LM>" for tx in trees_xml_parts)
    body = "\n".join(
        [
            '<?xml version="1.0" encoding="utf-8"?>',
            "<adata>",
            "<trees>",
            inner,
            "</trees>",
            "</adata>",
        ]
    )
    path.write_text(body + "\n", encoding="utf-8")


def _write_flat_file(
    path: Path,
    *,
    doc_id: int,
    text_id: str,
    lang: str,
    sentences: List[Tuple[int, List[Dict[str, Any]]]],
) -> None:
    """One TEITOK-style flat file: sentences + tokens with head/deprel (no separate a/m)."""
    parts: List[str] = [
        '<?xml version="1.0" encoding="utf-8"?>',
        '<flexicorp-flat xmlns="http://flexicorp/ns/pml-flat">',
        "<meta>",
        f"<lang>{_xml_text(lang)}</lang>",
        "<source>flexencoder-jsonl</source>",
        "</meta>",
        f'<doc id="d{_xml_text(str(doc_id))}" source_id="{_xml_text(text_id)}">',
    ]
    for sentence_id, toks in sentences:
        sid = f"s{doc_id}_{sentence_id}"
        parts.append(f'<s id="{_xml_text(sid)}">')
        for t in toks:
            tp = int(t.get("tok_pos", 0) or 0)
            wid = f"w{doc_id}_{tp}"
            form = str(t.get("form", "") or "")
            lemma = str(t.get("lemma", "") or form)
            upos = str(t.get("upos", "") or "")
            dr = str(t.get("dep_rel") or "")
            hp_raw = t.get("head_tok_pos")
            try:
                hp = int(hp_raw) if hp_raw is not None and str(hp_raw) != "null" else -1
            except (TypeError, ValueError):
                hp = -1
            head_id = f"w{doc_id}_{hp}" if hp >= 0 else ""
            ord_v = int(t.get("sent_ord", 0) or 0)
            parts.append(
                f'<w id="{_xml_text(wid)}" form="{_xml_text(form)}" lemma="{_xml_text(lemma)}" '
                f'upos="{_xml_text(upos)}" deprel="{_xml_text(dr)}" head="{_xml_text(head_id)}" ord="{ord_v}"/>'
            )
        parts.append("</s>")
    parts.extend(["</doc>", "</flexicorp-flat>"])
    path.write_text("\n".join(parts) + "\n", encoding="utf-8")


def convert_jsonl_to_pml(
    jsonl_dir: Path,
    out_dir: Path,
    *,
    lang: str = "en",
    doc_id_filter: Optional[Set[int]] = None,
    pml_layers: PmlLayers | str = "wa",
) -> Dict[str, Any]:
    """
    Write PML-like output per document (see module docstring for ``pml_layers``).

    Returns a result dict with counts and output directory.
    """
    layers: PmlLayers = _normalize_pml_layers(pml_layers)
    jsonl_dir = Path(jsonl_dir).resolve()
    out_dir = Path(out_dir).resolve()
    out_dir.mkdir(parents=True, exist_ok=True)

    docs = _load_jsonl_objects(jsonl_dir / "docs.jsonl")
    toks = _load_jsonl_objects(jsonl_dir / "toks.jsonl")
    if not docs:
        return {
            "ok": False,
            "message": "No rows in docs.jsonl; cannot emit PML.",
            "out_dir": str(out_dir),
            "files": [],
        }
    if not toks:
        return {
            "ok": False,
            "message": "No rows in toks.jsonl; cannot emit PML.",
            "out_dir": str(out_dir),
            "files": [],
        }

    _, by_sent = _group_toks_by_doc_sent(toks)
    by_doc: DefaultDict[int, List[Dict[str, Any]]] = defaultdict(list)
    for row in toks:
        if not isinstance(row, dict):
            continue
        try:
            did = int(row.get("doc_id", -1))
        except (TypeError, ValueError):
            continue
        if did < 0:
            continue
        if doc_id_filter is not None and did not in doc_id_filter:
            continue
        if row.get("is_empty") in (1, "1", True, "true"):
            continue
        by_doc[did].append(row)

    written: List[str] = []
    bundles = 0
    link_w = layers == "wa"
    for doc in docs:
        try:
            did = int(doc.get("doc_id", -1))
        except (TypeError, ValueError):
            continue
        if did < 0:
            continue
        if doc_id_filter is not None and did not in doc_id_filter:
            continue
        text_id = str(doc.get("text_id") or f"doc{did}")
        base = f"d{did}"
        toks_doc = by_doc.get(did, [])
        if not toks_doc:
            continue
        toks_doc.sort(key=lambda r: int(r.get("tok_pos", 0) or 0))

        w_paras: List[Dict[str, str]] = []
        for ti, t in enumerate(toks_doc):
            tp = int(t.get("tok_pos", 0) or 0)
            form = str(t.get("form", "") or "")
            wid = f"w{did}_{tp}"
            meta = t.get("metadata")
            nospace = False
            if isinstance(meta, dict) and str(meta.get("SpaceAfter", "")).lower() == "no":
                nospace = True
            wrow: Dict[str, str] = {"id": wid, "token": form}
            if nospace:
                wrow["no_space_after"] = "1"
            w_paras.append(wrow)

        sentence_ids = sorted({int(t.get("sentence_id", 0) or 0) for t in toks_doc})
        m_sentences: List[Tuple[int, List[Dict[str, Any]]]] = []
        trees_parts: List[str] = []
        for sid in sentence_ids:
            stoks = by_sent.get((did, sid), [])
            if not stoks:
                continue
            m_sentences.append((sid, stoks))
            if layers != "flat":
                tree_lm, _ = _build_a_subtree(
                    sentence_id=sid,
                    doc_id=did,
                    toks_sorted=stoks,
                    link_to_words=link_w,
                )
                if tree_lm:
                    trees_parts.append(tree_lm)

        # Avoid zero files on missing s-level — skip doc if empty
        if not m_sentences or not w_paras:
            continue

        w_path = out_dir / f"{base}.w"
        m_path = out_dir / f"{base}.m"
        a_path = out_dir / f"{base}.a"
        flat_path = out_dir / f"{base}.flat.xml"

        if layers == "flat":
            _write_flat_file(flat_path, doc_id=did, text_id=text_id, lang=lang, sentences=m_sentences)
            written.append(str(flat_path))
        elif layers == "wa":
            _write_w_file(w_path, doc_id=did, text_id=text_id, lang=lang, paras=w_paras)
            _write_a_file(a_path, doc_id=did, trees_xml_parts=trees_parts)
            written.extend([str(w_path), str(a_path)])
        else:
            _write_w_file(w_path, doc_id=did, text_id=text_id, lang=lang, paras=w_paras)
            _write_m_file(m_path, doc_id=did, lang=lang, sentences=m_sentences)
            _write_a_file(a_path, doc_id=did, trees_xml_parts=trees_parts)
            written.extend([str(w_path), str(m_path), str(a_path)])

        bundles += 1

    ok = True
    layer_desc = {"pdt3": "PDT triplets (.w/.m/.a)", "wa": "compact (.w/.a)", "flat": "flat (.flat.xml)"}.get(
        layers, layers
    )
    msg = f"Wrote {bundles} document bundle(s) ({layer_desc}, {len(written)} files) to {out_dir}."
    if docs and bundles == 0:
        ok = False
        msg = "No PML output emitted (toks.jsonl empty or no tokens matched docs)."
    return {
        "ok": ok,
        "out_dir": str(out_dir),
        "pml_layers": layers,
        "triplets": bundles,
        "bundles": bundles,
        "files": written,
        "message": msg,
    }
