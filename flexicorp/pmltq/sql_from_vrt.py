from __future__ import annotations

import argparse
import os
import re
import subprocess
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, List, Optional, Tuple


def _slugify(text: str) -> str:
    out = (text or "").strip().lower()
    out = out.replace("-", "_")
    out = re.sub(r"[^a-z0-9_]+", "_", out)
    out = re.sub(r"_+", "_", out).strip("_")
    return out or "teitok_corpus"


def _parse_tag_attrs(line: str) -> Dict[str, str]:
    attrs: Dict[str, str] = {}
    for key, val in re.findall(r'([^\s=]+)="(.*?)"', line):
        attrs[key] = val
    return attrs


@dataclass
class SettingsInfo:
    corpus_name: str
    pattributes: List[str]
    text_attrs: List[str]
    sent_attrs: List[str]


def _read_settings(settings_xml: Path, treebank_fallback: str) -> SettingsInfo:
    try:
        root = ET.parse(settings_xml).getroot()
    except Exception:
        return SettingsInfo(corpus_name=_slugify(treebank_fallback), pattributes=["word", "id"], text_attrs=[], sent_attrs=[])

    cqp = root.find("cqp")
    if cqp is None:
        for cand in root.findall(".//cqp"):
            if cand.get("corpus"):
                cqp = cand
                break

    corpus_name = _slugify((cqp.get("corpus") if cqp is not None else "") or treebank_fallback)

    pattributes: List[str] = ["word", "id"]
    text_attrs: List[str] = []
    sent_attrs: List[str] = []

    if cqp is not None:
        pattr = cqp.find("pattributes")
        if pattr is not None:
            for item in pattr.findall("item"):
                key = (item.get("key") or "").strip()
                if not key:
                    continue
                pmltq_key = (item.get("pmltq") or key).strip()
                if pmltq_key == "--":
                    continue
                if pmltq_key not in pattributes:
                    pattributes.append(pmltq_key)

        sattr = cqp.find("sattributes")
        if sattr is not None:
            for region in sattr.findall("item"):
                rkey = (region.get("key") or "").strip()
                vals: List[str] = []
                for item in region.findall("item"):
                    key = (item.get("key") or "").strip()
                    if key:
                        vals.append(key)
                if rkey == "text":
                    text_attrs.extend(vals)
                elif rkey == "s":
                    sent_attrs.extend(vals)

    return SettingsInfo(
        corpus_name=corpus_name,
        pattributes=pattributes,
        text_attrs=list(dict.fromkeys(text_attrs)),
        sent_attrs=list(dict.fromkeys(sent_attrs)),
    )


def _read_manatee_registry_pattributes(project_root: Path, treebank: str) -> List[str]:
    manatee_dir = project_root / "manatee"
    if not manatee_dir.is_dir():
        return []
    candidates = [
        treebank,
        treebank.replace("_", "-"),
        treebank.replace("-", "_"),
    ]
    reg_file: Optional[Path] = None
    for cand in candidates:
        p = manatee_dir / cand
        if p.is_file():
            reg_file = p
            break
    if reg_file is None:
        return []

    attrs: List[str] = []
    try:
        for raw in reg_file.read_text(encoding="utf-8", errors="replace").splitlines():
            line = raw.strip()
            if line.startswith("DOCSTRUCTURE ") or line.startswith("STRUCTURE "):
                break
            if not line.startswith("ATTRIBUTE "):
                continue
            # Skip dynamic attributes declared as blocks, e.g. "ATTRIBUTE lc {".
            if "{" in line:
                continue
            name = line.split(None, 1)[1].strip()
            if not name:
                continue
            if name not in attrs:
                attrs.append(name)
    except Exception:
        return []

    return attrs


def _qident(name: str) -> str:
    return '"' + name.replace('"', '""') + '"'


def _schema_xml() -> str:
    # Minimal PML-style dependency schema (a-root / a-node).
    return """<pml_schema version="1.1" xmlns="http://ufal.mff.cuni.cz/pdt/pml/schema/">
 <revision>1.0.0</revision>
 <root name="teitok_document" type="teitok_document.type"/>
 <type name="teitok_document.type">
  <structure>
   <member name="trees" role="#TREES" required="1">
    <list type="a-root.type" ordered="1"/>
   </member>
  </structure>
 </type>
 <type name="a-root.type">
  <structure role="#NODE" name="a-root">
   <member name="id" role="#ID" as_attribute="1" required="1"><cdata format="ID"/></member>
   <member name="ord" role="#ORDER" required="1"><cdata format="nonNegativeInteger"/></member>
   <member name="children" role="#CHILDNODES"><list type="a-node.type" ordered="1"/></member>
  </structure>
 </type>
 <type name="a-node.type">
  <structure role="#NODE" name="a-node">
   <member name="id" role="#ID" as_attribute="1" required="1"><cdata format="ID"/></member>
   <member name="ord" role="#ORDER" required="1"><cdata format="nonNegativeInteger"/></member>
   <member name="form"><cdata format="any"/></member>
   <member name="lemma"><cdata format="any"/></member>
   <member name="pos"><cdata format="any"/></member>
   <member name="deprel"><cdata format="any"/></member>
   <member name="phead"><cdata format="any"/></member>
   <member name="children" role="#CHILDNODES"><list type="a-node.type" ordered="1"/></member>
  </structure>
 </type>
</pml_schema>"""


def _tsv_escape(value: str) -> str:
    return (value or "").replace("\t", " ").replace("\n", " ")


def _sql_literal(value: str) -> str:
    return "'" + str(value or "").replace("'", "''") + "'"


def _resolve_storage_file_name(
    *,
    fileid: str,
    text_idx: int,
    files_mode: str,
    files_suffix: str,
) -> str:
    mode = str(files_mode or "id").strip().lower()
    suffix = str(files_suffix or "").strip()
    base = str(fileid or "").strip()
    if mode in {"ordinal", "d_ordinal", "d"}:
        base = f"d{text_idx}"
        if not suffix:
            suffix = ".a"
    elif mode in {"id_xml", "xml"}:
        if not suffix:
            suffix = ".xml"
    if not base:
        base = f"d{text_idx}"
    if suffix and not base.endswith(suffix):
        base = f"{base}{suffix}"
    return base


def _looks_tok_id(value: str) -> bool:
    v = (value or "").strip()
    return bool(re.match(r"^(?:w-\d+|\d+)$", v))


def _parse_token_fields(vals: List[str]) -> Dict[str, str]:
    """
    Parse TEITOK/CWB VRT token rows in the two common layouts:

    1) form form upos xpos lemma feats head deprel id
    2) id   form upos xpos lemma feats head deprel [deps] [misc]
    """
    out: Dict[str, str] = {
        "id": "",
        "word": "",
        "form": "",
        "upos": "",
        "xpos": "",
        "lemma": "",
        "feats": "",
        "head": "",
        "deprel": "",
        "deps": "",
        "misc": "",
    }
    if not vals:
        return out

    id_first = _looks_tok_id(vals[0]) and not _looks_tok_id(vals[-1])
    id_last = _looks_tok_id(vals[-1])

    if id_first:
        out["id"] = vals[0] if len(vals) > 0 else ""
        out["form"] = vals[1] if len(vals) > 1 else ""
        out["word"] = out["form"]
        out["upos"] = vals[2] if len(vals) > 2 else ""
        out["xpos"] = vals[3] if len(vals) > 3 else ""
        out["lemma"] = vals[4] if len(vals) > 4 else ""
        out["feats"] = vals[5] if len(vals) > 5 else ""
        out["head"] = vals[6] if len(vals) > 6 else ""
        out["deprel"] = vals[7] if len(vals) > 7 else ""
        out["deps"] = vals[8] if len(vals) > 8 else ""
        out["misc"] = vals[9] if len(vals) > 9 else ""
        return out

    if id_last:
        out["word"] = vals[0] if len(vals) > 0 else ""
        out["form"] = vals[1] if len(vals) > 1 else out["word"]
        out["upos"] = vals[2] if len(vals) > 2 else ""
        out["xpos"] = vals[3] if len(vals) > 3 else ""
        out["lemma"] = vals[4] if len(vals) > 4 else ""
        out["feats"] = vals[5] if len(vals) > 5 else ""
        out["head"] = vals[6] if len(vals) > 6 else ""
        out["deprel"] = vals[7] if len(vals) > 7 else ""
        out["id"] = vals[-1]
        # Optional extended columns between deprel and id.
        if len(vals) > 9:
            out["deps"] = vals[8]
        if len(vals) > 10:
            out["misc"] = vals[9]
        return out

    # Fallback: assume a CoNLL-U-like order with ID first.
    out["id"] = vals[0] if len(vals) > 0 else ""
    out["form"] = vals[1] if len(vals) > 1 else ""
    out["word"] = out["form"]
    out["upos"] = vals[2] if len(vals) > 2 else ""
    out["xpos"] = vals[3] if len(vals) > 3 else ""
    out["lemma"] = vals[4] if len(vals) > 4 else ""
    out["feats"] = vals[5] if len(vals) > 5 else ""
    out["head"] = vals[6] if len(vals) > 6 else ""
    out["deprel"] = vals[7] if len(vals) > 7 else ""
    out["deps"] = vals[8] if len(vals) > 8 else ""
    out["misc"] = vals[9] if len(vals) > 9 else ""
    return out


def build_from_vrt(
    vrt_path: Path,
    settings: SettingsInfo,
    out_dir: Path,
    *,
    files_mode: str = "id",
    files_suffix: str = "",
) -> Dict[str, Path]:
    out_dir.mkdir(parents=True, exist_ok=True)
    tok_copy = out_dir / "tok.copy"
    s_copy = out_dir / "s.copy"
    text_copy = out_dir / "text.copy"
    files_copy = out_dir / "files.copy"
    trees_copy = out_dir / "trees.copy"

    tok_attrs = ["lemma", "pos", "deprel", "phead", "xpos", "feats", "head"]
    text_attrs = list(settings.text_attrs)
    sent_attrs = [a for a in settings.sent_attrs if a != "id"]

    next_idx = 1
    cpos = 0
    text_idx = 1
    current_text_id = ""
    current_text_storage_file = ""
    file_tree_no: Dict[str, int] = {}

    with (
        vrt_path.open("r", encoding="utf-8", errors="replace") as fin,
        tok_copy.open("w", encoding="utf-8") as tok_out,
        s_copy.open("w", encoding="utf-8") as s_out,
        text_copy.open("w", encoding="utf-8") as text_out,
        files_copy.open("w", encoding="utf-8") as files_out,
        trees_copy.open("w", encoding="utf-8") as trees_out,
    ):
        in_sentence = False
        sent_meta: Dict[str, str] = {}
        sent_tokens: List[Dict[str, str]] = []

        def finalize_sentence() -> None:
            nonlocal next_idx, cpos, sent_meta, sent_tokens
            if not in_sentence:
                return

            s_idx = next_idx
            next_idx += 1
            sid = sent_meta.get("id", "")
            fileid = current_text_id or sent_meta.get("fileid", "")
            storage_file = current_text_storage_file or fileid
            sent_id = sent_meta.get("sent_id", "")
            root_id = f"{fileid}#{sid}" if fileid and sid else (sid or fileid or f"s-{s_idx}")

            by_tokid: Dict[str, Dict[str, str]] = {}
            for t in sent_tokens:
                by_tokid[str(t.get("tokid") or "")] = t

            children: Dict[str, List[Dict[str, str]]] = {}
            roots: List[Dict[str, str]] = []
            for t in sent_tokens:
                parent_tok = str(t.get("head") or "").strip()
                tokid = str(t.get("tokid") or "").strip()
                if not parent_tok or parent_tok == "0" or parent_tok == "_" or parent_tok == tokid or parent_tok not in by_tokid:
                    roots.append(t)
                else:
                    children.setdefault(parent_tok, []).append(t)

            for vals in children.values():
                vals.sort(key=lambda x: int(x.get("ord") or "0"))
            roots.sort(key=lambda x: int(x.get("ord") or "0"))

            node_idx: Dict[str, int] = {}
            node_r: Dict[str, int] = {}
            node_parent: Dict[str, int] = {}
            node_lvl: Dict[str, int] = {}
            node_chord: Dict[str, int] = {}
            node_chld: Dict[str, int] = {}

            def walk(nodes: List[Dict[str, str]], parent_idx: int, lvl: int) -> int:
                nonlocal next_idx
                max_r = parent_idx
                for chord, n in enumerate(nodes):
                    tid = str(n.get("tokid") or "")
                    idx = next_idx
                    next_idx += 1
                    node_idx[tid] = idx
                    node_parent[tid] = parent_idx
                    node_lvl[tid] = lvl
                    node_chord[tid] = chord
                    kids = children.get(tid, [])
                    node_chld[tid] = len(kids)
                    sub_r = walk(kids, idx, lvl + 1) if kids else idx
                    node_r[tid] = sub_r
                    if sub_r > max_r:
                        max_r = sub_r
                return max_r

            sentence_r = walk(roots, s_idx, 1) if roots else s_idx
            token_count = len(sent_tokens)

            s_cols = [
                str(s_idx),
                str(sentence_r),
                "0",
                str(token_count),
                "0",
                "0",
                str(s_idx),
                "",
                "a-root",
                "0",
                "0",
                root_id,
                str(text_idx - 1),
                sid,
                fileid,
            ] + [_tsv_escape(sent_meta.get(a, "")) for a in sent_attrs]
            s_out.write("\t".join(s_cols) + "\n")
            trees_out.write("\t".join(s_cols[:11]) + "\n")

            file_tree_no[storage_file] = file_tree_no.get(storage_file, -1) + 1
            files_out.write("\t".join([str(s_idx), storage_file, str(file_tree_no[storage_file]), "t"]) + "\n")

            for t in sorted(sent_tokens, key=lambda x: int(x.get("ord") or "0")):
                cpos += 1
                tid = str(t.get("tokid") or "")
                idx = node_idx.get(tid)
                if not idx:
                    continue
                ord_val = int(t.get("ord") or "0")
                tok_id = t.get("id") or (f"{fileid}#{sid}#{tid}" if fileid or sid else tid)
                cols = [
                    str(idx),
                    str(node_r.get(tid, idx)),
                    str(node_lvl.get(tid, 1)),
                    str(node_chld.get(tid, 0)),
                    str(node_chord.get(tid, 0)),
                    str(node_parent.get(tid, s_idx)),
                    str(s_idx),
                    "",
                    "a-node",
                    str(ord_val),
                    str(ord_val),
                    str(cpos),
                    tok_id,
                    str(ord_val),
                    str(text_idx - 1),
                    str(s_idx),
                    tid,
                    fileid,
                    _tsv_escape(t.get("form", "")),
                    _tsv_escape(t.get("lemma", "")),
                    _tsv_escape(t.get("upos", "")),
                    _tsv_escape(t.get("deprel", "")),
                    _tsv_escape(t.get("head", "")),
                    _tsv_escape(t.get("xpos", "")),
                    _tsv_escape(t.get("feats", "")),
                    _tsv_escape(t.get("head", "")),
                ]
                tok_out.write("\t".join(cols) + "\n")
                trees_out.write("\t".join(cols[:11]) + "\n")

            sent_meta = {}
            sent_tokens = []

        for raw in fin:
            line = raw.rstrip("\n")
            if not line:
                continue

            if line.startswith("<text") and not line.startswith("</text"):
                attrs = _parse_tag_attrs(line)
                current_text_id = attrs.get("id", "")
                current_text_storage_file = _resolve_storage_file_name(
                    fileid=current_text_id,
                    text_idx=text_idx,
                    files_mode=files_mode,
                    files_suffix=files_suffix,
                )
                tcols = [str(text_idx), current_text_id, current_text_id] + [_tsv_escape(attrs.get(a, "")) for a in text_attrs]
                text_out.write("\t".join(tcols) + "\n")
                text_idx += 1
                continue

            if line.startswith("<s") and not line.startswith("</s"):
                in_sentence = True
                sent_meta = _parse_tag_attrs(line)
                sent_tokens = []
                continue

            if line.startswith("</s>"):
                finalize_sentence()
                in_sentence = False
                continue

            if line.startswith("<"):
                continue

            if not in_sentence:
                continue

            vals = line.split("\t")
            if not vals:
                continue
            tok_map = _parse_token_fields(vals)
            tok_map["tokid"] = tok_map.get("id", "")
            # Keep TEITOK/CWB first column as the canonical token form.
            tok_map["form"] = tok_map.get("word", "")
            tok_map["ord"] = str(len(sent_tokens) + 1)
            sent_tokens.append(tok_map)

        if in_sentence:
            finalize_sentence()

    return {
        "tok_copy": tok_copy,
        "s_copy": s_copy,
        "text_copy": text_copy,
        "files_copy": files_copy,
        "trees_copy": trees_copy,
    }


def write_sql_files(
    out_dir: Path,
    treebank: str,
    settings: SettingsInfo,
    copies: Dict[str, Path],
    copy_prefix: str = "",
    schema_file: str = "",
    data_dir: str = "",
) -> Tuple[Path, Path]:
    schema_sql = out_dir / "schema.sql"
    data_sql = out_dir / "data.sql"
    tok_attrs = ["lemma", "pos", "deprel", "phead", "xpos", "feats", "head"]
    text_attrs = list(settings.text_attrs)
    sent_attrs = [a for a in settings.sent_attrs if a != "id"]

    tok_attr_cols = ",\n".join([f"    {_qident(a)} text" for a in tok_attrs])
    text_attr_cols = ",\n".join([f"    {_qident(a)} text" for a in text_attrs])
    sent_attr_cols = ",\n".join([f"    {_qident(a)} text" for a in sent_attrs])

    with schema_sql.open("w", encoding="utf-8") as fh:
        schema_escaped = _schema_xml().replace("'", "''")
        schema_file_sql = _sql_literal(schema_file)
        data_dir_sql = _sql_literal(data_dir)
        fh.write(
            f"""CREATE TABLE "#PML" (
    root character varying(128),
    schema_file character varying(512),
    data_dir character varying(512),
    schema text,
    last_idx integer,
    last_node_idx integer,
    flags integer
);
CREATE TABLE "#PMLTYPES" (type character varying(64), root character varying(128));
CREATE TABLE "#PMLTABLES" (type character varying(128), "table" character varying(128));
CREATE TABLE "#PML_USR_REL" (
    relname character varying(64) NOT NULL,
    reverse character varying(64),
    node_type character varying(128),
    target_node_type character varying(128),
    tbl character varying(128)
);
CREATE TABLE "teitok_document__#pmlref_map" (
    ref_type character varying(128),
    ref_table character varying(64),
    target_layer character varying(128),
    target_table character varying(64),
    target_type character varying(128)
);
CREATE TABLE "teitok_document__#files" (
    "#idx" integer,
    file character varying(1024),
    tree_no integer,
    top boolean
);
CREATE TABLE "teitok_document__#trees" (
    "#idx" integer,
    "#r" integer,
    "#lvl" integer,
    "#chld" integer,
    "#chord" integer,
    "#parent_idx" integer,
    "#root_idx" integer,
    "#name" character varying(16),
    "#type" character varying(16),
    "#min_ord" integer,
    "#max_ord" integer
);
CREATE TABLE "a-root" (
    "#idx" integer,
    "#r" integer,
    "#lvl" integer,
    "#chld" integer,
    "#chord" integer,
    "#parent_idx" integer,
    "#root_idx" integer,
    "#name" character varying(16),
    "#type" character varying(16),
    "#min_ord" integer,
    "#max_ord" integer,
    id text,
    text integer,
    sid text,
    fileid text{("," + chr(10) + sent_attr_cols) if sent_attr_cols else ""}
);
CREATE TABLE "a-node" (
    "#idx" integer,
    "#r" integer,
    "#lvl" integer,
    "#chld" integer,
    "#chord" integer,
    "#parent_idx" integer,
    "#root_idx" integer,
    "#name" character varying(16),
    "#type" character varying(16),
    "#min_ord" integer,
    "#max_ord" integer,
    "#cpos" integer,
    id text,
    ord integer,
    text integer,
    s integer,
    tokid text,
    fileid text,
    form text{("," + chr(10) + tok_attr_cols) if tok_attr_cols else ""}
);
CREATE TABLE "text" (
    "#idx" integer,
    id text,
    fileid text{("," + chr(10) + text_attr_cols) if text_attr_cols else ""}
);
INSERT INTO "#PML" (root, schema_file, data_dir, flags, schema)
VALUES ('teitok_document', {schema_file_sql}, {data_dir_sql}, 13, '{schema_escaped}');
INSERT INTO "#PMLTYPES" (type, root) VALUES ('a-node', 'teitok_document'), ('a-root', 'teitok_document');
INSERT INTO "#PMLTABLES" (type, "table") VALUES ('a-node', 'a-node'), ('a-root', 'a-root'), ('text', 'text');
"""
        )

    with data_sql.open("w", encoding="utf-8") as fh:
        def copy_path(key: str) -> str:
            if copy_prefix:
                pref = copy_prefix.rstrip("/")
                return f"{pref}/{copies[key].name}"
            return str(copies[key])

        fh.write(
            f"""\\copy "a-node" FROM '{copy_path("tok_copy")}' WITH (FORMAT csv, DELIMITER E'\\t');
\\copy "a-root" FROM '{copy_path("s_copy")}' WITH (FORMAT csv, DELIMITER E'\\t');
\\copy text FROM '{copy_path("text_copy")}' WITH (FORMAT csv, DELIMITER E'\\t');
\\copy "teitok_document__#files" FROM '{copy_path("files_copy")}' WITH (FORMAT csv, DELIMITER E'\\t');
\\copy "teitok_document__#trees" FROM '{copy_path("trees_copy")}' WITH (FORMAT csv, DELIMITER E'\\t');

CREATE INDEX a_node_idx_idx ON "a-node"("#idx");
CREATE INDEX a_node_idx_root ON "a-node"("#root_idx");
CREATE INDEX a_node_idx_parent ON "a-node"("#parent_idx");
CREATE INDEX a_node_idx_type ON "a-node"("#type");
CREATE INDEX a_node_idx_ord ON "a-node"(ord);
CREATE INDEX a_root_idx_idx ON "a-root"("#idx");
CREATE INDEX a_root_idx_file ON "a-root"(fileid);
CREATE INDEX files_idx_idx ON "teitok_document__#files"("#idx");
"""
        )

    return schema_sql, data_sql


def _run_psql(psql_bin: str, dbname: str, sql_file: Path) -> None:
    cmd = [psql_bin, "-v", "ON_ERROR_STOP=1", "-d", dbname, "-f", str(sql_file)]
    proc = subprocess.run(cmd, text=True, capture_output=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"psql failed for {sql_file}: {(proc.stderr or proc.stdout).strip()}")


def main() -> int:
    ap = argparse.ArgumentParser(description="Build PMLTQ SQL artifacts from TEITOK/CWB VRT.")
    ap.add_argument("--project-root", default=os.environ.get("FLEXICORP_PROJECT_ROOT", "."), help="TEITOK project root")
    ap.add_argument(
        "--settings",
        default=os.environ.get("FLEXICORP_PMLTQ_SETTINGS_XML", ""),
        help="Path to settings XML (defaults to FLEXICORP_PMLTQ_SETTINGS_XML)",
    )
    ap.add_argument("--vrt", default=os.environ.get("FLEXICORP_PMLTQ_VRT", ""), help="Path to corpus.vrt")
    ap.add_argument("--treebank", default=os.environ.get("FLEXICORP_PMLTQ_TREEBANK", "tt_infov"), help="Treebank/DB name")
    ap.add_argument(
        "--schema-file",
        default=os.environ.get("FLEXICORP_PMLTQ_SCHEMA_FILE", ""),
        help="Absolute schema_file stored in #PML for print-server path resolution.",
    )
    ap.add_argument(
        "--data-dir",
        default=os.environ.get("FLEXICORP_PMLTQ_DATA_DIR", ""),
        help="Absolute data_dir stored in #PML for print-server file lookup.",
    )
    ap.add_argument(
        "--files-mode",
        default=os.environ.get("FLEXICORP_PMLTQ_FILES_MODE", "id"),
        help="How to write #files.file: id|id_xml|ordinal (dN + suffix, default .a).",
    )
    ap.add_argument(
        "--files-suffix",
        default=os.environ.get("FLEXICORP_PMLTQ_FILES_SUFFIX", ""),
        help="Optional suffix appended to #files.file when missing (e.g. .xml, .a, .a.gz).",
    )
    ap.add_argument("--out-dir", default="", help="Output directory for generated sql/copy files")
    ap.add_argument(
        "--copy-prefix",
        default="",
        help="Override copy-file paths written to data.sql (e.g. /tmp/flexicorp-pmltq-sql for container loading).",
    )
    ap.add_argument("--load", action="store_true", help="Load generated SQL into PostgreSQL via psql")
    ap.add_argument("--recreate-db", action="store_true", help="Drop/create target database before loading")
    ap.add_argument("--psql-bin", default=os.environ.get("PSQL_BIN", "psql"), help="psql executable path")
    args = ap.parse_args()

    root = Path(args.project_root).expanduser().resolve()
    settings_xml = Path(args.settings).expanduser().resolve() if args.settings else None
    vrt = Path(args.vrt).expanduser().resolve() if args.vrt else None

    if settings_xml is None or not settings_xml.is_file():
        for cand in (root / "tmp" / "cqpsettings.xml", root / "Resources" / "settings.xml"):
            if cand.is_file():
                settings_xml = cand
                break
    if vrt is None or not vrt.is_file():
        for cand in (root / "manatee" / "corpus.vrt", root / "cqp" / "corpus.vrt", root / "tmp" / "corpus.vrt"):
            if cand.is_file():
                vrt = cand
                break
    if settings_xml is None or not settings_xml.is_file():
        raise RuntimeError("No TEITOK settings XML found (use --settings or FLEXICORP_PMLTQ_SETTINGS_XML).")
    if vrt is None or not vrt.is_file():
        raise RuntimeError("No corpus.vrt found (use --vrt or FLEXICORP_PMLTQ_VRT).")

    out_dir = Path(args.out_dir).expanduser().resolve() if args.out_dir else (root / "tmp" / "pmltq-sql")
    settings = _read_settings(settings_xml, args.treebank)
    reg_attrs = _read_manatee_registry_pattributes(root, args.treebank)
    if reg_attrs:
        settings = SettingsInfo(
            corpus_name=settings.corpus_name,
            pattributes=reg_attrs,
            text_attrs=settings.text_attrs,
            sent_attrs=settings.sent_attrs,
        )
    copies = build_from_vrt(
        vrt,
        settings,
        out_dir,
        files_mode=args.files_mode,
        files_suffix=args.files_suffix,
    )
    schema_sql, data_sql = write_sql_files(
        out_dir,
        args.treebank,
        settings,
        copies,
        copy_prefix=args.copy_prefix,
        schema_file=str(args.schema_file or ""),
        data_dir=str(args.data_dir or ""),
    )

    print(f"wrote copies+sql to {out_dir}")
    print(f"schema={schema_sql}")
    print(f"data={data_sql}")

    if args.load:
        dbname = _slugify(args.treebank)
        if args.recreate_db:
            admin = [args.psql_bin, "-v", "ON_ERROR_STOP=1", "-d", "postgres", "-c", f'DROP DATABASE IF EXISTS "{dbname}";']
            d1 = subprocess.run(admin, text=True, capture_output=True, check=False)
            if d1.returncode != 0:
                raise RuntimeError(f"failed to drop db {dbname}: {(d1.stderr or d1.stdout).strip()}")
            create = [args.psql_bin, "-v", "ON_ERROR_STOP=1", "-d", "postgres", "-c", f'CREATE DATABASE "{dbname}";']
            d2 = subprocess.run(create, text=True, capture_output=True, check=False)
            if d2.returncode != 0:
                raise RuntimeError(f"failed to create db {dbname}: {(d2.stderr or d2.stdout).strip()}")
        _run_psql(args.psql_bin, dbname, schema_sql)
        _run_psql(args.psql_bin, dbname, data_sql)
        print(f"loaded postgres db {dbname}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
