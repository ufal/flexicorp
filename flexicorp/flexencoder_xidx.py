"""
flexencoder ``xidx/`` index (backend-agnostic).

flexencoder writes ``<project_root>/xidx/`` with ``tokens.bin``, ``docs.tbl``,
``regions.bin``, and optional per-region-type ``{scope}.rng`` / ``{scope}_xidx.rng``.
This is independent of whether a CWB corpus exists under ``cqp/``; any backend
that reports global corpus positions (Manatee, Pando, etc.) can map hits back
to TEITOK XML using these files.

Token keys in ``tokens.bin`` are **0-based** corpus positions (aligned with Pando).
Older flexencoder builds keyed by **1-based** ``global_pos``; readers detect that
when key ``0`` is absent and adjust query positions.

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


def _xidx_legacy_one_based_keys(tmap: Dict[int, XidxTokenRec]) -> bool:
    """True if tokens.bin uses legacy flexencoder keys (1-based global_pos, no key 0)."""
    if not tmap:
        return False
    return 0 not in tmap


def _xidx_key_from_pando_pos(p: int, legacy_one_based: bool) -> int:
    return p + 1 if legacy_one_based else p


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


def read_xidx_docs_lines(project_root: Path) -> List[str]:
    """
    Relative paths from ``xidx/docs.tbl`` (one non-empty line per indexed document),
    or an empty list if the file is missing. Same ordering as flexencoder / xidx token stream.
    """
    root = Path(project_root).expanduser().resolve()
    docs_tbl = flexencoder_xidx_dir(root) / "docs.tbl"
    if not docs_tbl.is_file():
        return []
    return [line.strip() for line in _read_lines_cached(str(docs_tbl.resolve())) if line.strip()]


def xidx_rel_to_doc_id(rel: str) -> str:
    """TEITOK-style document id: basename of path, ``.xml`` stripped when present."""
    return _stem_from_rel(rel)


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
    legacy = _xidx_legacy_one_based_keys(tmap)
    k_primary = _xidx_key_from_pando_pos(cpos, legacy)
    it = tmap.get(k_primary)
    if it is None and k_primary > 0:
        it = tmap.get(k_primary - 1)
    if it is None:
        it = tmap.get(k_primary + 1)
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


# ---------------------------------------------------------------------------
# by-id fragment lookup (see docs/xidx_by_id_index.md — Phase 1)
#
# Build a (scope_name_verbatim, xml_id) -> (doc_idx, xml_start, xml_end) map
# by sweeping regions.bin once and joining with the three .tbl dictionaries.
# The source of truth is regions.bin stride-56 records; stride-40 corpora
# lack the xml_start/xml_end columns and therefore cannot use this fast path
# (callers fall back to extract_teitok_fragment_xml).
#
# Design decisions captured in docs/xidx_by_id_index.md §8:
#   * D-1: scope names are matched VERBATIM; aliasing is the caller's job.
#   * D-2: returned bytes are verbatim from the source XML; no parsing, no
#     whitespace normalisation.
#   * D-3: scope="text" returns the <text> element bytes, not the whole
#     document (regions.bin already stores element-level bounds).
# ---------------------------------------------------------------------------

_BY_ID_STRIDE = 56  # only stride-56 region records carry xml_start/xml_end


@lru_cache(maxsize=16)
def _load_id_map_cached(
    root_str: str,
) -> Dict[Tuple[str, str], Tuple[int, int, int]]:
    """
    Build ``(scope_name, xml_id) -> (doc_idx, xml_start, xml_end)`` for one
    flexencoder project root. Cached per root via ``lru_cache`` so repeated
    callers (e.g. successive hits in a KWIC page) share the build cost.

    Returns an empty dict if the xidx directory is missing, incomplete, or
    uses stride-40 region records (no byte-range columns).
    """
    root = Path(root_str)
    xidx = flexencoder_xidx_dir(root)
    regions_path = xidx / "regions.bin"
    region_types_tbl = xidx / "region_types.tbl"
    region_ids_tbl = xidx / "region_ids.tbl"
    docs_tbl = xidx / "docs.tbl"
    if not regions_path.is_file():
        return {}
    if not region_types_tbl.is_file() or not region_ids_tbl.is_file():
        return {}
    if not docs_tbl.is_file():
        return {}

    regions_blob = _read_binary(regions_path)
    if not regions_blob or len(regions_blob) % _BY_ID_STRIDE != 0:
        # stride-40 (no xml bounds) or corrupted file — cannot serve by-id.
        return {}

    region_types = _read_lines_cached(str(region_types_tbl.resolve()))
    region_ids = _read_lines_cached(str(region_ids_tbl.resolve()))
    if not region_types or not region_ids:
        return {}

    out: Dict[Tuple[str, str], Tuple[int, int, int]] = {}
    stride = _BY_ID_STRIDE
    n_types = len(region_types)
    n_ids = len(region_ids)
    off = 0
    while off + stride <= len(regions_blob):
        rec = regions_blob[off : off + stride]
        off += stride
        rtype = _u32le(rec, 0)
        rdoc = _u32le(rec, 4)
        xml_start = _i64le(rec, 32)
        xml_end = _i64le(rec, 40)
        region_id_idx = _u32le(rec, 48)
        if rtype >= n_types or region_id_idx >= n_ids:
            continue
        if xml_start < 0 or xml_end <= xml_start:
            continue
        scope_name = region_types[rtype]
        xml_id = region_ids[region_id_idx]
        if not scope_name or not xml_id:
            continue
        key = (scope_name, xml_id)
        # If the same (scope, id) appears more than once (it shouldn't in a
        # well-formed TEITOK corpus), keep the first occurrence — stable
        # behaviour under rebuilds.
        if key not in out:
            out[key] = (rdoc, xml_start, xml_end)
    return out


def has_xidx_by_id_index(project_root: Path) -> bool:
    """True when all files needed for the by-id fast path are present."""
    xidx = flexencoder_xidx_dir(project_root)
    return (
        (xidx / "regions.bin").is_file()
        and (xidx / "region_types.tbl").is_file()
        and (xidx / "region_ids.tbl").is_file()
        and (xidx / "docs.tbl").is_file()
    )


def fragment_by_id(
    project_root: Path,
    scope: str,
    xml_id: str,
) -> Optional[bytes]:
    """
    Return the raw byte slice of the region identified by ``(scope, xml_id)``,
    or ``None`` if the id is unknown for that scope.

    Behaviour is the contract documented in ``docs/xidx_by_id_index.md``:

      * scope name is matched VERBATIM against ``region_types.tbl`` (no
        aliasing; see D-1).
      * bytes are returned verbatim from the source XML; no parsing, no
        whitespace normalisation (D-2).
      * for ``scope="text"``, returns the ``<text>`` element bytes, not the
        whole document (D-3).

    First call per project root builds an in-memory index by sweeping
    ``regions.bin``; subsequent calls are an ``O(1)`` dict lookup plus a
    single ``pread`` from the source file.
    """
    if not scope or not xml_id:
        return None
    root = Path(project_root).expanduser().resolve()
    m = _load_id_map_cached(str(root))
    hit = m.get((scope, xml_id))
    if hit is None:
        return None
    doc_idx, xml_start, xml_end = hit
    docs_tbl = flexencoder_xidx_dir(root) / "docs.tbl"
    docs = _read_lines_cached(str(docs_tbl.resolve()))
    if doc_idx >= len(docs):
        return None
    xml_path = root / docs[doc_idx]
    if not xml_path.is_file():
        return None
    try:
        with xml_path.open("rb") as fh:
            fh.seek(xml_start)
            data = fh.read(xml_end - xml_start)
    except OSError:
        return None
    if len(data) != xml_end - xml_start:
        return None
    return data


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
    legacy = _xidx_legacy_one_based_keys(tmap)
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

                def pick_narrowest_sentence_idx(pos: int) -> int:
                    """Match flexicorp_json.h / regions.bin: narrowest [start,end] containing pos."""
                    best_j = -1
                    best_w = 2**63
                    for j in range(n):
                        sj = _i64le(rng_blob, j * 16)
                        ej = _i64le(rng_blob, j * 16 + 8)
                        if pos < sj or pos > ej:
                            continue
                        w = ej - sj if ej >= sj else 0
                        if w < best_w:
                            best_w = w
                            best_j = j
                    return best_j

                adj_s = _xidx_key_from_pando_pos(corpus_pos_start, legacy)
                adj_e = _xidx_key_from_pando_pos(corpus_pos_end, legacy)
                idx_s = pick_narrowest_sentence_idx(adj_s)
                idx_e = pick_narrowest_sentence_idx(adj_e)
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
    k_primary = _xidx_key_from_pando_pos(corpus_pos_start, legacy)
    it = tmap.get(k_primary)
    if it is None and k_primary > 0:
        it = tmap.get(k_primary - 1)
    if it is None:
        it = tmap.get(k_primary + 1)
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

    if scope in {"tok", "dtok"}:
        xs_p = _xidx_key_from_pando_pos(corpus_pos_start, legacy)
        xe_p = _xidx_key_from_pando_pos(corpus_pos_end, legacy)
        xr_tok = _xml_bounds_for_corpus_range(by_doc, tr.doc_idx, xs_p, xe_p)
        if xr_tok:
            frag_xml_start, frag_xml_end = xr_tok
        try:
            xsize_tok = xml_path.stat().st_size
        except OSError:
            return None
        if frag_xml_start < 0 or frag_xml_end < frag_xml_start or frag_xml_end > xsize_tok:
            return None
        return xml_path.read_bytes()[frag_xml_start:frag_xml_end].decode(
            "utf-8", errors="replace"
        )

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
