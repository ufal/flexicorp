"""
flexencoder ``xidx/`` index (backend-agnostic).

flexencoder writes ``<project_root>/xidx/`` with ``tokens.bin``, ``docs.tbl``,
``regions.bin``, and optional per-region-type ``{scope}.rng`` / ``{scope}_xidx.rng``.
This is independent of whether a CWB corpus exists under ``cqp/``; any backend
that reports global corpus positions (Manatee, Pando, etc.) can map hits back
to TEITOK XML using these files.

Layout matches ``flexencoder_xidx.cpp`` and the Pando helper
``flexicorp_pando/flexicorp_json.h`` (little-endian records).
"""

from __future__ import annotations

import bisect
import struct
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Dict, List, Optional, Tuple


def _u32le(data: bytes, off: int) -> int:
    return struct.unpack_from("<I", data, off)[0]


def _i64le(data: bytes, off: int) -> int:
    return struct.unpack_from("<q", data, off)[0]


@dataclass(frozen=True)
class XidxTokenRec:
    corpus_pos: int
    doc_idx: int
    xml_start: int
    xml_end: int


@lru_cache(maxsize=32)
def _load_tokens_map_cached(path_str: str) -> Dict[int, XidxTokenRec]:
    path = Path(path_str)
    if not path.is_file():
        return {}
    raw = path.read_bytes()
    if not raw:
        return {}
    stride = 40 if len(raw) % 40 == 0 else 32
    out: Dict[int, XidxTokenRec] = {}
    off = 0
    while off + stride <= len(raw):
        chunk = raw[off : off + stride]
        if stride == 32:
            corpus_pos = _i64le(chunk, 0)
            doc_idx = _u32le(chunk, 8)
            xml_start = _i64le(chunk, 12)
            xml_end = _i64le(chunk, 20)
        else:
            corpus_pos = _i64le(chunk, 0)
            doc_idx = _u32le(chunk, 8)
            xml_start = _i64le(chunk, 16)
            xml_end = _i64le(chunk, 24)
        if corpus_pos >= 0 and xml_end >= xml_start:
            out[corpus_pos] = XidxTokenRec(corpus_pos, doc_idx, xml_start, xml_end)
        off += stride
    return out


def load_tokens_map(tokens_bin: Path) -> Dict[int, XidxTokenRec]:
    return dict(_load_tokens_map_cached(str(tokens_bin.resolve())))


@lru_cache(maxsize=32)
def _read_lines_cached(path_str: str) -> Tuple[str, ...]:
    path = Path(path_str)
    if not path.is_file():
        return ()
    lines: List[str] = []
    with path.open("r", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            lines.append(line.rstrip("\n\r"))
    return tuple(lines)


def _build_doc_sorted(
    tmap: Dict[int, XidxTokenRec],
) -> Dict[int, List[Tuple[int, XidxTokenRec]]]:
    by_doc: Dict[int, List[Tuple[int, XidxTokenRec]]] = {}
    for cpos, rec in tmap.items():
        by_doc.setdefault(rec.doc_idx, []).append((cpos, rec))
    for rows in by_doc.values():
        rows.sort(key=lambda pr: pr[0])
    return by_doc


def _find_scope_type_idx(region_types: Tuple[str, ...], context_scope: str) -> int:
    normalized = context_scope
    for i, rt in enumerate(region_types):
        if rt == normalized:
            return i
    if normalized in {"s", "seg", "sentence"}:
        for alias in ("s", "seg", "l", "lb"):
            for i, rt in enumerate(region_types):
                if rt == alias:
                    return i
    return -1


def _find_region_span_for_pos(
    regions_blob: bytes,
    type_idx: int,
    doc_idx: int,
    corpus_pos: int,
) -> Optional[Tuple[int, int, int, int, int, int]]:
    """
    Return (rstart, rend, region_id_idx, region_xml_start, region_xml_end, best_width)
    for the narrowest region of given type/doc containing corpus_pos.
    """
    if not regions_blob:
        return None
    stride56 = 56
    stride40 = 40
    stride = stride56 if len(regions_blob) % stride56 == 0 else stride40
    has_xml = stride == stride56
    best_width = 2**63
    best: Optional[Tuple[int, int, int, int, int, int]] = None
    off = 0
    while off + stride <= len(regions_blob):
        rec = regions_blob[off : off + stride]
        rtype = _u32le(rec, 0)
        rdoc = _u32le(rec, 4)
        rstart = _i64le(rec, 16)
        rend = _i64le(rec, 24)
        region_id_idx = _u32le(rec, 48) if has_xml else _u32le(rec, 32)
        rxml_s = _i64le(rec, 32) if has_xml else -1
        rxml_e = _i64le(rec, 40) if has_xml else -1
        off += stride
        if rtype != type_idx or rdoc != doc_idx:
            continue
        if rstart <= corpus_pos <= rend:
            width = rend - rstart if rend >= rstart else 0
            if width < best_width:
                best_width = width
                best = (rstart, rend, region_id_idx, rxml_s, rxml_e, width)
    return best


def _xml_bounds_for_corpus_range(
    by_doc: Dict[int, List[Tuple[int, XidxTokenRec]]],
    doc_idx: int,
    rstart: int,
    rend: int,
) -> Optional[Tuple[int, int]]:
    rows = by_doc.get(doc_idx)
    if not rows:
        return None
    cpos_keys = [pr[0] for pr in rows]
    lo = bisect.bisect_left(cpos_keys, rstart)
    if lo >= len(rows) or rows[lo][0] > rend:
        return None
    xs = rows[lo][1].xml_start
    xe = rows[lo][1].xml_end
    for j in range(lo, len(rows)):
        if rows[j][0] > rend:
            break
        if rows[j][1].xml_start < xs:
            xs = rows[j][1].xml_start
        if rows[j][1].xml_end > xe:
            xe = rows[j][1].xml_end
    return xs, xe


def _stem_from_rel(rel: str) -> str:
    slash = rel.rfind("/")
    base = rel if slash < 0 else rel[slash + 1 :]
    if len(base) > 4 and base.endswith(".xml"):
        return base[:-4]
    return base


def flexencoder_xidx_dir(project_root: Path) -> Path:
    return Path(project_root).expanduser().resolve() / "xidx"


def has_flexencoder_xidx(project_root: Path) -> bool:
    d = flexencoder_xidx_dir(project_root)
    return (d / "tokens.bin").is_file() and (d / "docs.tbl").is_file()


def text_id_stem_for_cpos(project_root: Path, cpos: int) -> Optional[str]:
    """
    TEITOK document identifier (basename without ``.xml``) for a global corpus position,
    from flexencoder ``xidx/tokens.bin`` + ``docs.tbl`` only.
    """
    if cpos < 0:
        return None
    root = Path(project_root).expanduser().resolve()
    xidx = flexencoder_xidx_dir(root)
    tokens_bin = xidx / "tokens.bin"
    docs_tbl = xidx / "docs.tbl"
    if not tokens_bin.is_file() or not docs_tbl.is_file():
        return None
    tmap = load_tokens_map(tokens_bin)
    it = tmap.get(cpos)
    if it is None and cpos > 0:
        it = tmap.get(cpos - 1)
    if it is None:
        it = tmap.get(cpos + 1)
    if it is None:
        return None
    docs = _read_lines_cached(str(docs_tbl.resolve()))
    if it.doc_idx >= len(docs):
        return None
    return _stem_from_rel(docs[it.doc_idx])


def _read_binary(path: Path) -> bytes:
    if not path.is_file():
        return b""
    return path.read_bytes()


def lookup_xml_fragment(
    project_root: Path,
    corpus_pos_start: int,
    corpus_pos_end: int,
    context_scope: str,
) -> Optional[str]:
    """
    Extract an XML fragment for ``[corpus_pos_start, corpus_pos_end]`` at the given
    structural ``context_scope`` (e.g. ``s``), using only ``xidx/`` data.
    Returns None if files are missing or resolution fails.
    """
    root = Path(project_root).expanduser().resolve()
    xidx = flexencoder_xidx_dir(root)
    tokens_bin = xidx / "tokens.bin"
    docs_tbl = xidx / "docs.tbl"
    regions_path = xidx / "regions.bin"
    region_types_tbl = xidx / "region_types.tbl"
    region_ids_tbl = xidx / "region_ids.tbl"
    if not tokens_bin.is_file() or not docs_tbl.is_file():
        return None

    tmap = load_tokens_map(tokens_bin)
    if not tmap:
        return None
    by_doc = _build_doc_sorted(tmap)
    docs = _read_lines_cached(str(docs_tbl.resolve()))
    region_types = _read_lines_cached(str(region_types_tbl.resolve()))
    region_ids = _read_lines_cached(str(region_ids_tbl.resolve()))
    regions_blob = _read_binary(regions_path)

    scope = (context_scope or "s").strip().lower()
    if scope in {"sentence", "sent"}:
        scope = "s"

    # Fast path: per-type .rng + _xidx.rng next to flexencoder xidx (not CWB cqp/).
    if scope not in {"tok", "dtok"}:
        rng_path = xidx / f"{scope}.rng"
        xidx_path = xidx / f"{scope}_xidx.rng"
        if rng_path.is_file() and xidx_path.is_file():
            rng_blob = _read_binary(rng_path)
            xidx_blob = _read_binary(xidx_path)
            if (
                rng_blob
                and xidx_blob
                and len(rng_blob) % 16 == 0
                and len(xidx_blob) % 8 == 0
                and len(rng_blob) // 16 == len(xidx_blob) // 8
            ):
                n = len(rng_blob) // 16

                def pick_idx(pos: int) -> int:
                    starts = [
                        _i64le(rng_blob, j * 16) for j in range(n)
                    ]
                    it2 = bisect.bisect_right(starts, pos)
                    if it2 == 0:
                        return -1
                    idx = it2 - 1
                    endv = _i64le(rng_blob, idx * 16 + 8)
                    if pos < starts[idx] or pos > endv:
                        return -1
                    return idx

                idx_s = pick_idx(corpus_pos_start)
                idx_e = pick_idx(corpus_pos_end)
                if (
                    idx_s >= 0
                    and idx_e >= 0
                    and regions_blob
                    and len(regions_blob) % 56 == 0
                ):
                    stride = 56
                    ri_s = int(_i64le(xidx_blob, idx_s * 8))
                    ri_e = int(_i64le(xidx_blob, idx_e * 8))
                    roff_s = ri_s * stride
                    roff_e = ri_e * stride
                    if roff_s + stride <= len(regions_blob) and roff_e + stride <= len(regions_blob):
                        ds = _u32le(regions_blob, roff_s + 4)
                        de = _u32le(regions_blob, roff_e + 4)
                        if ds < len(docs) and ds == de:
                            xms = _i64le(regions_blob, roff_s + 32)
                            xme = _i64le(regions_blob, roff_e + 40)
                            rel = docs[ds]
                            xml_path = root / rel
                            if xms >= 0 and xme > xms and xml_path.is_file():
                                xsize = xml_path.stat().st_size
                                if xme <= xsize:
                                    return xml_path.read_bytes()[xms:xme].decode(
                                        "utf-8", errors="replace"
                                    )

    # Fallback: token map + regions.bin (mirrors flexicorp_json.h xidx_lookup_fragment).
    it = tmap.get(corpus_pos_start)
    if it is None and corpus_pos_start > 0:
        it = tmap.get(corpus_pos_start - 1)
    if it is None:
        it = tmap.get(corpus_pos_start + 1)
    if it is None:
        return None
    tr = it
    effective_pos = tr.corpus_pos
    if tr.doc_idx >= len(docs):
        return None
    rel = docs[tr.doc_idx]
    xml_path = root / rel
    if not xml_path.is_file():
        return None

    frag_xml_start = tr.xml_start
    frag_xml_end = tr.xml_end

    scope_idx = _find_scope_type_idx(region_types, scope)
    if scope_idx >= 0 and regions_blob:
        span = _find_region_span_for_pos(
            regions_blob, scope_idx, tr.doc_idx, effective_pos
        )
        if span:
            rstart, rend, region_id_idx, region_xml_start, region_xml_end, _w = span
            want_container = scope in {"s", "seg", "l", "lb", "u"}
            if want_container and region_xml_start >= 0 and region_xml_end > region_xml_start:
                frag_xml_start = min(region_xml_start, tr.xml_start)
                frag_xml_end = max(region_xml_end, tr.xml_end)
            else:
                xs, xe = frag_xml_start, frag_xml_end
                xr = _xml_bounds_for_corpus_range(by_doc, tr.doc_idx, rstart, rend)
                if xr:
                    xs, xe = xr
                    frag_xml_start, frag_xml_end = xs, xe
                else:
                    st_it = tmap.get(rstart)
                    if st_it is None and rstart > 0:
                        st_it = tmap.get(rstart - 1)
                    en_it = tmap.get(rend)
                    if en_it is None and rend > 0:
                        en_it = tmap.get(rend - 1)
                    if st_it is not None and en_it is not None:
                        frag_xml_start = st_it.xml_start
                        frag_xml_end = en_it.xml_end
                if frag_xml_start > tr.xml_start or frag_xml_end < tr.xml_end:
                    frag_xml_start = min(frag_xml_start, tr.xml_start)
                    frag_xml_end = max(frag_xml_end, tr.xml_end)

            _ = region_id_idx  # reserved for stricter id= checks; optional later

    xsize = xml_path.stat().st_size
    if frag_xml_start < 0 or frag_xml_end < frag_xml_start or frag_xml_end > xsize:
        return None
    return xml_path.read_bytes()[frag_xml_start:frag_xml_end].decode(
        "utf-8", errors="replace"
    )
