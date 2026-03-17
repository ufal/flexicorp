"""
Convert flexencoder JSONL output to Manatee corpus format.

Reads docs.jsonl, sentences.jsonl, regions.jsonl, toks.jsonl from a directory
and writes Manatee binary format (.lex, .text, .rev, .rng, registry) that Manatee
can open directly.

Schema is read from TEITOK CQP settings (same as CWB/flexencoder) so the output
matches CWB: same p-attributes, same structures, same core data.
"""

from __future__ import annotations

import json
import struct
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple

# Manatee signatures
FINIT_SIGNATURE = b"\xa3finIT\x00\x00\x00\x00\x00\x00\x00\x00\x00"  # 16 bytes
FINDR_SIGNATURE = b"\xa3finDR"  # 6 bytes for old reverse index


def _floorlog2(x: int) -> int:
    """floor(log2(x)) for x > 0."""
    if x <= 0:
        raise ValueError("floorlog2 requires positive x")
    return x.bit_length() - 1


def _ceilog2(x: int) -> int:
    """ceil(log2(x)) for x > 0."""
    if x <= 0:
        raise ValueError("ceilog2 requires positive x")
    return (x - 1).bit_length()


class BitWriter:
    """Write bits to a buffer, output as bytes."""

    def __init__(self) -> None:
        self._bits: List[int] = []

    def _next_atom(self) -> None:
        while len(self._bits) % 8 != 0:
            self._bits.append(0)

    def unary(self, val: int) -> None:
        """Unary encoding: val zeros then 1."""
        for _ in range(val - 1):
            self._bits.append(0)
        self._bits.append(1)

    def binary_fix(self, x: int, length: int) -> None:
        """Fixed-length binary encoding, LSB first in bit stream."""
        for _ in range(length):
            self._bits.append(x & 1)
            x >>= 1

    def gamma(self, x: int) -> None:
        """Elias gamma: for x > 0."""
        if x <= 0:
            raise ValueError("gamma requires positive x")
        length = _floorlog2(x)
        self.unary(length + 1)
        self.binary_fix(x ^ (1 << length), length)

    def delta(self, x: int) -> None:
        """Elias delta: for x > 0."""
        if x <= 0:
            raise ValueError("delta requires positive x")
        length = _floorlog2(x)
        self.gamma(length + 1)
        self.binary_fix(x ^ (1 << length), length)

    def to_bytes(self) -> bytes:
        """Return buffer as bytes (pad to byte boundary)."""
        self._next_atom()
        result = bytearray()
        for i in range(0, len(self._bits), 8):
            byte = 0
            for j in range(8):
                if i + j < len(self._bits):
                    byte |= self._bits[i + j] << j
            result.append(byte)
        return bytes(result)

    def tell_bits(self) -> int:
        return len(self._bits)

    def tell_bytes(self) -> int:
        return (len(self._bits) + 7) // 8


def load_cqp_schema_from_settings(settings_path: Path) -> Dict[str, Any]:
    """
    Load CQP schema (pattributes, wordfld, structures) from TEITOK settings XML.

    Returns:
        {
            "pattributes": ["form", "lemma", ...],
            "wordfld": "form",
            "structure_names": ["text", "seg", "s", ...],
        }
    """
    result: Dict[str, Any] = {
        "pattributes": [],
        "wordfld": "form",
        "structure_names": [],
    }
    if not settings_path or not Path(settings_path).is_file():
        return result
    try:
        root = ET.parse(settings_path).getroot()
    except ET.ParseError:
        return result
    cqp = root.find(".//cqp")
    if cqp is None:
        return result
    result["wordfld"] = cqp.get("wordfld") or "form"
    pattr_elem = cqp.find("pattributes")
    if pattr_elem is not None:
        for item in pattr_elem.findall("item"):
            key = item.get("key")
            if key:
                result["pattributes"].append(key)
    sattr_elem = cqp.find("sattributes")
    if sattr_elem is not None:
        for region in sattr_elem.findall("item"):
            name = region.get("key")
            if name:
                result["structure_names"].append(name)
    return result


def _write_pattribute(
    output_dir: Path,
    attr_name: str,
    tokens: List[Dict[str, Any]],
    jsonl_key: str,
) -> Tuple[List[str], int]:
    """Write .lex, .lex.idx, .lex.srt, .text, .rev, .rev.idx, .rev.cnt for one attribute."""
    lex_map: Dict[str, int] = {}
    lex_list: List[str] = []
    for t in tokens:
        val = str(t.get(jsonl_key, t.get("form", "")))
        if val not in lex_map:
            lex_map[val] = len(lex_list)
            lex_list.append(val)

    text_path = output_dir / f"{attr_name}.text"
    with text_path.open("wb") as f:
        f.write(FINIT_SIGNATURE)
        for t in tokens:
            val = str(t.get(jsonl_key, t.get("form", "")))
            lid = lex_map[val]
            f.write(struct.pack("<i", lid))

    lex_path = output_dir / f"{attr_name}.lex"
    idx_path = output_dir / f"{attr_name}.lex.idx"
    srt_path = output_dir / f"{attr_name}.lex.srt"
    offsets: List[int] = []
    offset = 0
    with lex_path.open("wb") as lexf:
        for s in lex_list:
            offsets.append(offset)
            data = (s + "\0").encode("utf-8")
            lexf.write(data)
            offset += len(data)
    with idx_path.open("wb") as f:
        for off in offsets:
            f.write(struct.pack("<I", off))
    sorted_ids = sorted(range(len(lex_list)), key=lambda i: lex_list[i])
    with srt_path.open("wb") as f:
        for i in sorted_ids:
            f.write(struct.pack("<I", i))

    id_positions: Dict[int, List[int]] = {i: [] for i in range(len(lex_list))}
    for pos, t in enumerate(tokens):
        val = str(t.get(jsonl_key, t.get("form", "")))
        lid = lex_map[val]
        id_positions[lid].append(pos)

    rev_path = output_dir / f"{attr_name}.rev"
    rev_idx_path = output_dir / f"{attr_name}.rev.idx"
    rev_cnt_path = output_dir / f"{attr_name}.rev.cnt"
    alignmult = 1
    align_bw = BitWriter()
    align_bw.delta(alignmult + 1)
    align_bytes = align_bw.to_bytes()
    header_len = 6 + len(align_bytes)
    rev_bytes = bytearray()
    rev_idx: List[int] = []
    rev_cnt: List[int] = []
    for lid in range(len(lex_list)):
        positions = id_positions[lid]
        rev_cnt.append(len(positions))
        rev_idx.append(len(rev_bytes))
        if not positions:
            continue
        bw = BitWriter()
        bw.delta(positions[0] + 1)
        prev = positions[0]
        for p in positions[1:]:
            bw.delta(p - prev + 1)
            prev = p
        rev_bytes.extend(bw.to_bytes())
    with rev_path.open("wb") as f:
        f.write(FINDR_SIGNATURE)
        f.write(align_bytes)
        f.write(rev_bytes)
    with rev_idx_path.open("wb") as f:
        for off in rev_idx:
            f.write(struct.pack("<I", header_len + off))
    with rev_cnt_path.open("wb") as f:
        for cnt in rev_cnt:
            f.write(struct.pack("<I", cnt))

    return lex_list, len(lex_list)


def convert_jsonl_to_manatee(
    jsonl_dir: Path,
    output_dir: Path,
    corpus_name: str = "corpus",
    word_attr: Optional[str] = None,
    settings_path: Optional[Path] = None,
    schema: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    """
    Convert flexencoder JSONL to Manatee format.

    Uses the same CQP schema as CWB (from settings or explicit schema) so the
    output has the same p-attributes and structures.

    Args:
        jsonl_dir: Directory with docs.jsonl, toks.jsonl, regions.jsonl
        output_dir: Output directory for Manatee corpus (e.g. manatee/corp)
        corpus_name: Registry corpus name (e.g. tt_tico19)
        word_attr: Deprecated; use settings_path or schema.
        settings_path: Path to TEITOK cqpsettings.xml / settings.xml (for schema)
        schema: Explicit schema {"pattributes", "wordfld", "structure_names"}

    Returns:
        Dict with ok, message, token_count, etc.
    """
    toks_path = jsonl_dir / "toks.jsonl"
    regions_path = jsonl_dir / "regions.jsonl"
    docs_path = jsonl_dir / "docs.jsonl"

    if not toks_path.is_file():
        return {"ok": False, "error": f"Missing {toks_path}"}

    output_dir = Path(output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    # Load schema: settings_path > schema > defaults
    if schema is None and settings_path:
        schema = load_cqp_schema_from_settings(Path(settings_path))
    if schema is None:
        schema = {"pattributes": [], "wordfld": "form", "structure_names": []}
    wordfld = schema.get("wordfld") or word_attr or "form"
    pattributes = schema.get("pattributes") or []

    # Attributes to write: "word" (from wordfld) + all pattributes
    attr_specs: List[Tuple[str, str]] = []
    seen: Set[str] = set()
    attr_specs.append(("word", wordfld))
    seen.add("word")
    for attr in pattributes:
        if attr not in seen:
            attr_specs.append((attr, attr))
            seen.add(attr)

    # 1. Load tokens in document order
    tokens: List[Dict[str, Any]] = []
    with toks_path.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            tokens.append(json.loads(line))
    tokens.sort(key=lambda t: (t.get("doc_id", 0), t.get("doc_pos", 0)))

    # 2. Write all p-attributes (same as CWB)
    word_lex_size = 0
    for attr_name, jsonl_key in attr_specs:
        _, lex_size = _write_pattribute(output_dir, attr_name, tokens, jsonl_key)
        if attr_name == "word":
            word_lex_size = lex_size

    # 3. Structures: .rng files from regions
    regions_by_type: Dict[str, List[Tuple[int, int]]] = {}
    doc_offsets: Dict[int, int] = {}
    global_pos = 0
    for t in tokens:
        doc_id = t.get("doc_id", 0)
        if doc_id not in doc_offsets:
            doc_offsets[doc_id] = global_pos
        global_pos += 1

    if regions_path.is_file():
        regions_by_type: Dict[str, List[Tuple[int, int]]] = {}
        with regions_path.open("r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                r = json.loads(line)
                rtype = r.get("region_type", "s")
                start = int(r.get("start_pos", 0))
                end = int(r.get("end_pos", 0))
                doc_id = r.get("doc_id", r.get("seq_id", 0))
                offset = doc_offsets.get(doc_id, 0)
                g_start = offset + start
                g_end = offset + end
                if rtype not in regions_by_type:
                    regions_by_type[rtype] = []
                regions_by_type[rtype].append((g_start, g_end))

        for rtype, ranges in regions_by_type.items():
            rng_path = output_dir / f"{rtype}.rng"
            with rng_path.open("wb") as f:
                for beg, end in ranges:
                    f.write(struct.pack("<i", beg))
                    f.write(struct.pack("<i", end))

    # 4. Sizes file (Manatee expects PATH/sizes)
    doc_count = 0
    if docs_path.is_file():
        with docs_path.open("r", encoding="utf-8") as f:
            doc_count = sum(1 for line in f if line.strip())
    sizes_path = output_dir / "sizes"
    with sizes_path.open("w", encoding="utf-8") as f:
        f.write(f"tokencount {len(tokens)}\n")
        f.write(f"wordcount {word_lex_size}\n")
        f.write(f"doccount {doc_count}\nparcount 0\nsentcount 0\n")

    # 5. Registry file (same ATTR/STRUCT as CWB)
    written_structures = set(regions_by_type.keys()) if regions_path.is_file() else set()
    registry_dir = output_dir.parent
    registry_dir.mkdir(parents=True, exist_ok=True)
    registry_path = registry_dir / corpus_name
    path_rel = output_dir.name  # e.g. "corp"
    with registry_path.open("w", encoding="utf-8") as f:
        f.write(f'NAME \"{corpus_name}\"\n')
        f.write(f'PATH \"{path_rel}\"\n')
        f.write("ENCODING utf-8\n")
        f.write("DEFAULTATTR word\n")
        for attr_name, _ in attr_specs:
            # ATTRIBUTE block with TYPE \"FD_MI\" (int_text; no *.text.seg)
            f.write(f'ATTRIBUTE \"{attr_name}\" {{\n')
            f.write('TYPE \"FD_MI\"\n')
            f.write('}\n')
        if written_structures:
            f.write("DOCSTRUCTURE text\n\n" if "text" in written_structures else "")
        for sname in sorted(written_structures):
            f.write(f"STRUCTURE {sname} {{\n}}\n")

    return {
        "ok": True,
        "output_dir": str(output_dir),
        "registry": str(registry_path),
        "token_count": len(tokens),
        "lexicon_size": word_lex_size,
    }
