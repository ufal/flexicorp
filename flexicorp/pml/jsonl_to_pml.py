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

- ``conll2009``: emits ``conll2pml``-style files (``.pml`` + shared
  ``conll2009_schema.xml``), with technical roots and nested ``LM`` nodes.
  This mirrors the legacy PML-TQ/TrEd ecosystem more closely than ``wa/pdt3``.

Morphological ``tag`` in ``pdt3`` ``.m`` is approximated from UPOS; analytical
``afun`` comes from UD ``dep_rel`` mapped into PDT enumerations.

Requires: ``docs.jsonl``, ``toks.jsonl`` (skip tokens with ``is_empty`` truthy).
"""

from __future__ import annotations

import json
import re
import gzip
from collections import defaultdict
from pathlib import Path
from typing import Any, DefaultDict, Dict, List, Literal, Mapping, Optional, Set, Tuple

PmlLayers = Literal["pdt3", "wa", "flat", "conll2009"]


def _normalize_pml_layers(raw: Any) -> PmlLayers:
    s = str(raw or "wa").strip().lower()
    if s in ("pdt3", "pdt", "wma", "full", "3", "triple"):
        return "pdt3"
    if s in ("wa", "wa2", "compact", "2", "two"):
        return "wa"
    if s in ("flat", "1", "one", "single"):
        return "flat"
    if s in ("conll2009", "conll", "conllx", "conll-pml", "legacy-conll"):
        return "conll2009"
    return "wa"
from xml.sax.saxutils import escape

_PML_NS = "http://ufal.mff.cuni.cz/pdt/pml/"


def _xml_text(s: str) -> str:
    return escape(s, entities={"\"": "&quot;", "'": "&apos;"})


def _safe_id_segment(s: str) -> str:
    s = re.sub(r"[^\w.\-]", "_", s.strip())
    return s or "x"


def _write_xml(path: Path, content: str) -> None:
    if str(path).endswith(".gz"):
        with gzip.open(path, "wt", encoding="utf-8") as fh:
            fh.write(content)
        return
    path.write_text(content, encoding="utf-8")


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
    Return one analytical tree LM for a sentence, plus used tok positions.
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

    def is_member_for(tp: int) -> int:
        # PDT coordination validation expects conjunct members to be flagged.
        dr = str(by_pos[tp].get("dep_rel") or "").strip().lower()
        base = dr.split(":", 1)[0] if dr else ""
        return 1 if base == "conj" else 0

    def emit_a_node(tp: int) -> str:
        row = by_pos[tp]
        wid = f"w{doc_id}_{tp}"
        m_id = f"m{doc_id}_{tp}"
        aid = f"a{doc_id}_{tp}"
        ord_v = int(row.get("sent_ord", 0) or 0)
        af = afun_for(tp)
        is_member = is_member_for(tp)
        kids = children_of.get(tp, [])
        inner_children = "".join(emit_a_node(ct) for ct in kids)
        if link_to_words:
            link_rf = f"<w.rf>w#{_xml_text(wid)}</w.rf>"
        else:
            link_rf = f"<m.rf>m#{_xml_text(m_id)}</m.rf>"
        parts = [
            f'<LM id="{_xml_text(aid)}">',
            link_rf,
            f"<afun>{_xml_text(af)}</afun>",
            f"<is_member>{is_member}</is_member>",
            "<is_parenthesis_root>0</is_parenthesis_root>",
            f"<ord>{ord_v}</ord>",
        ]
        if inner_children:
            parts.append(f"<children>{inner_children}</children>")
        parts.append("</LM>")
        return "".join(parts)

    tops = children_of.get(None, [])
    if not tops:
        tops = [min(pos_set)] if pos_set else []

    top_xml = "".join(emit_a_node(tp) for tp in tops)
    sentence_text = " ".join(str(by_pos[tp].get("form", "") or "").strip() for tp in sorted(pos_set)).strip()

    if link_to_words:
        s_anchor = f"w#w{doc_id}_{first_tp}"
    else:
        s_anchor = f"m#{sid_ref}"
    root_block = (
        f'<LM id="{_xml_text(root_id)}">'
        f"<s.rf>{_xml_text(s_anchor)}</s.rf>"
        f"<ord>0</ord><children>{top_xml}</children>"
        f"{f'<sentence>{_xml_text(sentence_text)}</sentence>' if sentence_text else ''}"
        f"</LM>"
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
        f'<wdata xmlns="{_PML_NS}">',
        "<head>",
        '<schema href="wdata_30_schema.xml" />',
        "</head>",
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
    _write_xml(path, "\n".join(parts) + "\n")


def _write_m_file(
    path: Path,
    *,
    doc_id: int,
    lang: str,
    sentences: List[Tuple[int, List[Dict[str, Any]]]],
    w_ref_name: str,
) -> None:
    parts: List[str] = [
        '<?xml version="1.0" encoding="utf-8"?>',
        f'<mdata xmlns="{_PML_NS}">',
        "<head>",
        '<schema href="mdata_30_schema.xml" />',
        "<references>",
        f'<reffile id="w" name="wdata" href="{_xml_text(w_ref_name)}" />',
        "</references>",
        "</head>",
        "<meta>",
        f"<lang>{_xml_text(lang)}</lang>",
        '<annotation_info id="manual">',
        "<desc>Converted from flexencoder JSONL</desc>",
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
            parts.append("<src.rf>manual</src.rf>")
            parts.append("<w.rf>")
            parts.append(f"<LM>w#{_xml_text(wid)}</LM>")
            parts.append("</w.rf>")
            parts.append(f"<form>{_xml_text(form)}</form>")
            parts.append(f"<lemma>{_xml_text(lemma)}</lemma>")
            parts.append(f"<tag>{_xml_text(tag)}</tag>")
            parts.append("</m>")
        parts.append("</s>")
    parts.append("</mdata>")
    _write_xml(path, "\n".join(parts) + "\n")


def _write_a_file(
    path: Path,
    *,
    doc_id: int,
    trees_xml_parts: List[str],
    w_ref_name: str,
    m_ref_name: str,
    include_m_ref: bool = True,
) -> None:
    inner = "".join(trees_xml_parts)
    refs: List[str] = [f'<reffile id="w" name="wdata" href="{_xml_text(w_ref_name)}" />']
    if include_m_ref:
        refs.insert(0, f'<reffile id="m" name="mdata" href="{_xml_text(m_ref_name)}" />')
    body = "\n".join(
        [
            '<?xml version="1.0" encoding="utf-8"?>',
            f'<adata xmlns="{_PML_NS}">',
            "<head>",
            '<schema href="adata_30_schema.xml" />',
            "<references>",
            *refs,
            "</references>",
            "</head>",
            "<trees>",
            inner,
            "</trees>",
            "</adata>",
        ]
    )
    _write_xml(path, body + "\n")


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


def _write_conll2009_schema(path: Path) -> None:
    body = """<?xml version="1.0"?>
<pml_schema xmlns="http://ufal.mff.cuni.cz/pdt/pml/schema/" version="1.1">
 <root name="conll2009" type="conll.type"/>
 <type name="conll.type">
  <structure>
   <member name="body" required="1" type="body.type"/>
  </structure>
 </type>
 <type name="body.type">
  <list ordered="1" role="#TREES" type="root.type"/>
 </type>
 <type name="root.type">
  <structure role="#NODE">
   <member as_attribute="1" name="xml:id" required="1" role="#ID"><cdata format="ID"/></member>
   <member as_attribute="1" name="order" required="0" role="#ORDER"><constant>0</constant></member>
   <member name="childnodes" required="0" role="#CHILDNODES"><list ordered="1" type="node.type"/></member>
  </structure>
 </type>
 <type name="node.type">
  <structure role="#NODE">
   <member as_attribute="1" name="xml:id" required="1" role="#ID"><cdata format="ID"/></member>
   <member name="order" as_attribute="1" role="#ORDER"><cdata format="positiveInteger"/></member>
   <member name="form"><cdata format="any"/></member>
   <member name="lemma"><cdata format="any"/></member>
   <member name="pos"><cdata format="any"/></member>
   <member name="deprel"><cdata format="any"/></member>
   <member name="feat" type="feats.type"/>
   <member name="phead"><cdata format="any"/></member>
   <member name="ppos"><cdata format="any"/></member>
   <member name="pdeprel"><cdata format="any"/></member>
   <member name="fillpred"><cdata format="any"/></member>
   <member name="pred"><cdata format="any"/></member>
   <member name="childnodes" role="#CHILDNODES"><list ordered="1" type="node.type"/></member>
  </structure>
 </type>
 <type name="feats.type">
  <list ordered="0"><cdata format="any"/></list>
 </type>
</pml_schema>
"""
    path.write_text(body, encoding="utf-8")


def _write_conll2009_file(
    path: Path,
    *,
    sentences: List[Tuple[int, List[Dict[str, Any]]]],
    schema_name: str = "conll2009_schema.xml",
) -> None:
    def _parse_int(raw: Any) -> Optional[int]:
        if raw is None or str(raw) in ("", "null"):
            return None
        try:
            return int(raw)
        except Exception:
            return None

    def _feat_parts(raw: Any) -> List[str]:
        if isinstance(raw, dict):
            out: List[str] = []
            for k in sorted(raw.keys()):
                v = raw.get(k)
                if v is None or str(v).strip() == "":
                    continue
                out.append(f"{k}={v}")
            return out
        if isinstance(raw, list):
            out = []
            for item in raw:
                s = str(item or "").strip()
                if s and s != "_":
                    out.append(s)
            return out
        text = str(raw or "").strip()
        if not text or text == "_":
            return []
        return [p for p in text.split("|") if p and p != "_"]

    sent_no = 0
    parts: List[str] = [
        '<?xml version="1.0" encoding="utf-8"?>',
        '<conll2009 xmlns="http://ufal.mff.cuni.cz/pdt/pml/">',
        "<head>",
        f'<schema href="{_xml_text(schema_name)}"/>',
        "</head>",
        "<body>",
    ]

    for _sid, toks in sentences:
        if not toks:
            continue
        sent_no += 1
        root_id = f"s-{sent_no}"
        by_pos: Dict[int, Dict[str, Any]] = {}
        for t in toks:
            tp = _parse_int(t.get("tok_pos"))
            if tp is None or tp < 0:
                continue
            by_pos[tp] = t
        if not by_pos:
            continue
        children: DefaultDict[Optional[int], List[int]] = defaultdict(list)
        pos_set = set(by_pos.keys())
        for tp, row in by_pos.items():
            hp = _parse_int(row.get("head_tok_pos"))
            dr = str(row.get("dep_rel") or "").strip().lower()
            if dr == "root" or dr.startswith("root:") or hp is None or hp <= 0 or hp not in pos_set:
                children[None].append(tp)
            else:
                children[hp].append(tp)
        for lst in children.values():
            lst.sort(key=lambda p: int(by_pos[p].get("sent_ord", 0) or 0))

        def emit_node(tp: int) -> str:
            row = by_pos[tp]
            node_id = f"{root_id}-{tp}"
            ord_v = int(row.get("sent_ord", tp) or tp)
            form = str(row.get("form") or "")
            lemma = str(row.get("lemma") or form)
            pos = str(row.get("upos") or "")
            deprel = str(row.get("dep_rel") or "")
            phead_raw = _parse_int(row.get("head_tok_pos"))
            phead = str(phead_raw) if (phead_raw is not None and phead_raw > 0) else ""
            feat_vals = _feat_parts(row.get("feats"))

            inner: List[str] = [f'<LM order="{ord_v}" xml:id="{_xml_text(node_id)}">']
            if form:
                inner.append(f"<form>{_xml_text(form)}</form>")
            if lemma:
                inner.append(f"<lemma>{_xml_text(lemma)}</lemma>")
            if pos:
                inner.append(f"<pos>{_xml_text(pos)}</pos>")
            if feat_vals:
                inner.append("<feat>")
                for fv in feat_vals:
                    inner.append(f"<LM>{_xml_text(fv)}</LM>")
                inner.append("</feat>")
            if phead:
                inner.append(f"<phead>{_xml_text(phead)}</phead>")
            if deprel:
                inner.append(f"<deprel>{_xml_text(deprel)}</deprel>")
            kids = children.get(tp, [])
            if kids:
                inner.append("<childnodes>")
                for ct in kids:
                    inner.append(emit_node(ct))
                inner.append("</childnodes>")
            inner.append("</LM>")
            return "".join(inner)

        roots = children.get(None, [])
        if not roots:
            roots = [min(pos_set)]
        parts.append(f'<LM order="0" xml:id="{_xml_text(root_id)}">')
        parts.append("<childnodes>")
        for rt in roots:
            parts.append(emit_node(rt))
        parts.append("</childnodes>")
        parts.append("</LM>")

    parts.extend(["</body>", "</conll2009>"])
    path.write_text("\n".join(parts) + "\n", encoding="utf-8")


def convert_jsonl_to_pml(
    jsonl_dir: Path,
    out_dir: Path,
    *,
    lang: str = "en",
    doc_id_filter: Optional[Set[int]] = None,
    pml_layers: PmlLayers | str = "wa",
    gzip_output: bool = False,
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
    schema_written = False
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

        use_gzip = bool(gzip_output) and layers in ("wa", "pdt3")
        w_name = f"{base}.w.gz" if use_gzip else f"{base}.w"
        m_name = f"{base}.m.gz" if use_gzip else f"{base}.m"
        a_name = f"{base}.a.gz" if use_gzip else f"{base}.a"
        w_path = out_dir / w_name
        m_path = out_dir / m_name
        a_path = out_dir / a_name
        flat_path = out_dir / f"{base}.flat.xml"
        conll_path = out_dir / f"{base}.pml"
        conll_schema = out_dir / "conll2009_schema.xml"

        if layers == "flat":
            _write_flat_file(flat_path, doc_id=did, text_id=text_id, lang=lang, sentences=m_sentences)
            written.append(str(flat_path))
        elif layers == "conll2009":
            if not schema_written:
                _write_conll2009_schema(conll_schema)
                written.append(str(conll_schema))
                schema_written = True
            _write_conll2009_file(conll_path, sentences=m_sentences, schema_name=conll_schema.name)
            written.append(str(conll_path))
        elif layers == "wa":
            _write_w_file(w_path, doc_id=did, text_id=text_id, lang=lang, paras=w_paras)
            _write_a_file(
                a_path,
                doc_id=did,
                trees_xml_parts=trees_parts,
                w_ref_name=w_name,
                m_ref_name=m_name,
                include_m_ref=False,
            )
            written.extend([str(w_path), str(a_path)])
        else:
            _write_w_file(w_path, doc_id=did, text_id=text_id, lang=lang, paras=w_paras)
            _write_m_file(m_path, doc_id=did, lang=lang, sentences=m_sentences, w_ref_name=w_name)
            _write_a_file(
                a_path,
                doc_id=did,
                trees_xml_parts=trees_parts,
                w_ref_name=w_name,
                m_ref_name=m_name,
                include_m_ref=True,
            )
            written.extend([str(w_path), str(m_path), str(a_path)])

        bundles += 1

    ok = True
    layer_desc = {
        "pdt3": "PDT triplets (.w/.m/.a)",
        "wa": "compact (.w/.a)",
        "flat": "flat (.flat.xml)",
        "conll2009": "conll2pml-like (.pml + conll2009_schema.xml)",
    }.get(
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
