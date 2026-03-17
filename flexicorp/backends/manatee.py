from __future__ import annotations

import importlib
import importlib.util
import os
import struct
import sys
import tempfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, Iterator, List, Optional

from ..config import ManateeConfig


class ManateeFormatError(RuntimeError):
    pass


def _module_origin(module_name: str) -> str:
    spec = importlib.util.find_spec(module_name)
    if spec is None:
        return "<not found>"
    origin = getattr(spec, "origin", None)
    if origin:
        return str(origin)
    locations = getattr(spec, "submodule_search_locations", None)
    if locations:
        return ", ".join(str(item) for item in locations)
    return "<unknown>"


def _load_optional_module(module_name: str) -> Any | None:
    try:
        return importlib.import_module(module_name)
    except Exception:
        return None


def _path_within(candidate: Path, roots: List[Path]) -> bool:
    for root in roots:
        try:
            candidate.relative_to(root)
            return True
        except ValueError:
            continue
    return False


def _import_module_without_shadow_paths(
    module_name: str,
    shadow_roots: List[Path],
) -> Any:
    old_sys_path = list(sys.path)
    sys.modules.pop(module_name, None)
    try:
        filtered: List[str] = []
        cwd = Path.cwd().resolve()
        for entry in old_sys_path:
            if entry == "":
                if _path_within(cwd, shadow_roots):
                    continue
                filtered.append(entry)
                continue
            try:
                entry_path = Path(entry).expanduser().resolve()
            except Exception:
                filtered.append(entry)
                continue
            if _path_within(entry_path, shadow_roots):
                continue
            filtered.append(entry)
        sys.path[:] = filtered
        importlib.invalidate_caches()
        return importlib.import_module(module_name)
    finally:
        sys.path[:] = old_sys_path
        importlib.invalidate_caches()


def _shadow_roots(project_root: Path | None) -> List[Path]:
    roots: List[Path] = []
    if project_root is not None:
        roots.append(project_root.resolve())
    try:
        cwd = Path.cwd().resolve()
        if cwd not in roots:
            roots.append(cwd)
    except Exception:
        pass
    return roots


def _is_valid_manatee_api_dir(api_dir: Path) -> bool:
    if not api_dir.is_dir() or not (api_dir / "manatee.py").is_file():
        return False
    return any(
        p.is_file()
        for p in (api_dir / ".libs" / "_manatee.so", api_dir / "_manatee.so")
    )


def _manatee_api_candidates(project_root: Path | None) -> List[Path]:
    """
    Return list of candidate Manatee API directories (each contains manatee.py and _manatee.so or .libs/_manatee.so).
    Server-wide locations are checked first, then per-project. No PYTHONPATH needed if bindings are in one of these.
    """
    candidates: List[Path] = []

    # 1) Server-wide: MANATEE_API (path to the api directory)
    env_api = os.environ.get("MANATEE_API")
    if env_api:
        api_dir = Path(env_api).expanduser().resolve()
        if _is_valid_manatee_api_dir(api_dir):
            candidates.append(api_dir)

    # 2) Server-wide: common install paths
    for prefix in ("/usr/local", "/opt"):
        api_dir = Path(prefix) / "share" / "manatee" / "api"
        if _is_valid_manatee_api_dir(api_dir):
            candidates.append(api_dir)
            break

    # 3) Per-project overrides (optional)
    if project_root:
        root = project_root.resolve()
        if root.is_dir():
            lib_manatee = root / "lib" / "manatee"
            if _is_valid_manatee_api_dir(lib_manatee):
                candidates.append(lib_manatee)
            git_dir = root / "git"
            if git_dir.is_dir():
                for child in sorted(git_dir.iterdir()):
                    if child.is_dir() and child.name.startswith("manatee-open"):
                        api = child / "api"
                        if _is_valid_manatee_api_dir(api):
                            candidates.append(api)

    return candidates


def load_manatee_bindings(*, project_root: Path | None = None) -> Any:
    shadow_roots = _shadow_roots(project_root)
    # Try import first (e.g. after "make install" into venv or system PYTHONPATH).
    try:
        module = importlib.import_module("manatee")
    except ModuleNotFoundError as exc:
        module = None
        # Not in site-packages/PYTHONPATH; try standard locations relative to project.
        if exc.name == "manatee":
            for api_dir in _manatee_api_candidates(project_root):
                prepend: List[str] = []
                libs = api_dir / ".libs"
                if libs.is_dir():
                    prepend.append(str(libs.resolve()))
                prepend.append(str(api_dir.resolve()))
                old_path = list(sys.path)
                try:
                    sys.path[:] = prepend + [p for p in old_path if p not in prepend]
                    importlib.invalidate_caches()
                    module = importlib.import_module("manatee")
                    break
                except Exception:
                    sys.path[:] = old_path
                    importlib.invalidate_caches()
                    continue
        if module is None:
            hint = (
                "For server-wide use: install into the Python that runs flexicorp (make install), "
                "or set MANATEE_API to the path of the manatee-open api directory. "
                "See docs/install-manatee-bindings.md in the flexicorp repo for details."
            )
            raise RuntimeError(
                "The pure Manatee backend requires the official Python Manatee bindings built from manatee-open. "
                "Do not install the unrelated PyPI package 'manatee'. "
                f"{hint}"
            ) from exc
    except Exception as exc:
        raise RuntimeError(
            "The pure Manatee backend requires the official Python Manatee bindings. "
            "Try `python3 -c \"import manatee\"` in the target environment."
        ) from exc

    required = ("Corpus", "Concordance", "KWICLines")
    missing = [name for name in required if not hasattr(module, name)]
    if missing:
        module_path = getattr(module, "__file__", None) or _module_origin("manatee")
        shadowed_by_project = False
        shadow_retry_error: Exception | None = None
        if module_path and module_path not in {"<unknown>", "<not found>"}:
            try:
                shadowed_by_project = _path_within(Path(module_path).resolve(), shadow_roots)
            except Exception:
                shadowed_by_project = False
        if shadowed_by_project:
            try:
                module = _import_module_without_shadow_paths("manatee", shadow_roots)
                missing = [name for name in required if not hasattr(module, name)]
                if not missing:
                    return module
                module_path = getattr(module, "__file__", None) or _module_origin("manatee")
            except Exception as exc:
                shadow_retry_error = exc
        native_module = _load_optional_module("_manatee")
        if native_module is not None and all(hasattr(native_module, name) for name in required):
            return native_module

        native_path = _module_origin("_manatee")
        public_names = sorted(name for name in dir(module) if not name.startswith("_"))
        preview = ", ".join(public_names[:12])
        if len(public_names) > 12:
            preview += ", ..."
        shadow_note = ""
        if shadowed_by_project:
            shadow_note = (
                f" A project-local path is shadowing the Python import: {module_path}."
            )
            if shadow_retry_error is not None:
                shadow_note += f" After removing that shadow path, importing real bindings still failed: {shadow_retry_error}."
        raise RuntimeError(
            "Imported module 'manatee' does not look like the official Python Manatee bindings. "
            f"Loaded from: {module_path}. Missing API: {', '.join(missing)}. "
            f"Available symbols: {preview or '<none>'}. "
            f"Native extension lookup for '_manatee': {native_path}. "
            "Kontext expects a top-level 'manatee.py' wrapper with the native '_manatee' extension behind it."
            f"{shadow_note}"
        )
    return module


# -----------------------------------------------------------------------------
# Bit reader (mirrors finlib/bitio.hh read_bits for delta/gamma decoding)
# -----------------------------------------------------------------------------

class _BitReader:
    """Read bits from a byte buffer; implements unary, binary_fix, gamma, delta like Manatee read_bits."""
    __slots__ = ("_data", "_byte_off", "_bits")

    def __init__(self, data: bytes, start_byte: int = 0, skip_bits: int = 0) -> None:
        self._data = data
        self._byte_off = start_byte
        self._bits = 8  # unread bits in current byte
        if self._byte_off < len(data):
            self._bits -= skip_bits
            if self._bits <= 0:
                self._byte_off += 1
                self._bits += 8
        else:
            self._bits = 0

    def _curr_byte(self) -> int:
        if self._byte_off >= len(self._data):
            return 0
        return self._data[self._byte_off]

    def _next_atom(self) -> None:
        if self._bits <= 0:
            self._byte_off += 1
            self._bits = 8

    def unary(self) -> int:
        """Count leading 0 bits until 1; return total count (1-based run length)."""
        x = 1
        self._next_atom()
        b = self._curr_byte()
        if b == 0:
            x += self._bits
            self._byte_off += 1
            while self._byte_off < len(self._data) and self._data[self._byte_off] == 0:
                x += 8
                self._byte_off += 1
            self._bits = 8
            if self._byte_off < len(self._data):
                b = self._data[self._byte_off]
        # count trailing zeros in b
        while b != 0 and (b & 1) == 0:
            self._bits -= 1
            x += 1
            b >>= 1
        self._bits -= 1
        if self._bits > 0:
            pass  # curr stays
        else:
            self._byte_off += 1
            self._bits = 8
        return x

    def binary_fix(self, n: int) -> int:
        """Read exactly n bits (LSB first within byte)."""
        if n <= 0:
            return 0
        x = 0
        shift = 0
        self._next_atom()
        b = self._curr_byte()
        if n > self._bits:
            x = b & ((1 << self._bits) - 1)
            n -= self._bits
            shift = self._bits
            self._byte_off += 1
            self._bits = 8
            while n > 8 and self._byte_off < len(self._data):
                x |= self._data[self._byte_off] << shift
                self._byte_off += 1
                n -= 8
                shift += 8
            if self._byte_off < len(self._data):
                b = self._data[self._byte_off]
        if n > 0:
            mask = (1 << n) - 1
            x |= (b & mask) << shift
            b >>= n
            self._bits -= n
        return x

    def gamma(self) -> int:
        """Elias gamma: unary length then binary_fix for value."""
        n = self.unary() - 1
        if n <= 0:
            return 1
        return self.binary_fix(n) | (1 << n)

    def delta(self) -> int:
        """Elias delta: gamma for length, then binary_fix for value."""
        n = self.gamma() - 1
        if n <= 0:
            return 1
        return self.binary_fix(n) | (1 << n)


# Old-style finDR reverse index signature (6 bytes)
_FINDR_SIGNATURE = b"\243finDR"


def _decode_finDR_alignmult(rev_bytes: bytes) -> int:
    """Read alignmult from old-style .rev: first value after 6-byte header is delta(alignmult+1)."""
    if len(rev_bytes) < 7 or rev_bytes[:6] != _FINDR_SIGNATURE:
        raise ManateeFormatError("Invalid or missing finDR signature in .rev file.")
    r = _BitReader(rev_bytes, start_byte=6, skip_bits=0)
    return r.delta() - 1


def _decode_finDR_postings(rev_bytes: bytes, seek: int, count: int, alignmult: int) -> List[int]:
    """
    Decode a block of postings from old-style .rev at byte offset seek.
    First delta is (first_pos + 1); each following delta is the gap to next position.
    Mirrors DeltaPosStream in finlib/compstream.hh.
    Callers should filter to positions in [0, corpus_size); .rev can contain
    out-of-range values if .rev and .text were built at different times.
    """
    if count <= 0 or seek < 0 or seek >= len(rev_bytes):
        return []
    alignmult = max(1, alignmult)
    r = _BitReader(rev_bytes, start_byte=seek, skip_bits=0)
    out: List[int] = []
    current = -1
    for _ in range(count):
        d = r.delta()
        current += d
        out.append(current)
    return out


def _read_text_size_from_header(text_path: Path) -> Optional[int]:
    """
    Read corpus size (token count) from a Manatee .text file header.
    delta_text in finlib/text.hh reads from byte 16: seg_size, text_size (delta-1).
    """
    if not text_path.is_file():
        return None
    data = text_path.read_bytes()
    if len(data) < 20:
        return None
    try:
        r = _BitReader(data, start_byte=16, skip_bits=0)
        r.delta()  # seg_size
        text_size_delta = r.delta()  # stored as delta, value is text_size+1 in some variants
        return text_size_delta - 1 if text_size_delta > 0 else None
    except Exception:
        return None


def _decode_forward_text_ids(text_path: Path, max_pos: int) -> List[int]:
    """
    Decode lexicon ids for positions 0..max_pos from a Manatee .text file (delta_text).
    Header at byte 16: two deltas (seg_size, text_size), then one delta()-1 per token.
    Returns list of length max_pos+1 (or shorter if decode runs out of data).
    """
    if max_pos < 0:
        return []
    data = text_path.read_bytes()
    if len(data) < 20:
        return []
    r = _BitReader(data, start_byte=16, skip_bits=0)
    r.delta()  # seg_size
    r.delta()  # text_size
    out: List[int] = []
    for _ in range(max_pos + 1):
        try:
            out.append(r.delta() - 1)
        except Exception:
            break
    return out


def get_token_strings_for_hits(
    lexicon: ManateeLexiconReader,
    text_path: Path,
    ranges: List[tuple[int, int]],
    corpus_size: int,
) -> List[List[str]]:
    """
    Resolve token strings for each (match_start, match_end) hit from forward text + lexicon.
    Returns one list of token strings per hit (e.g. [["v"], ["the", "cat"], ...]).
    """
    if not ranges:
        return []
    max_end = max(end for _, end in ranges)
    if max_end >= corpus_size:
        max_end = corpus_size - 1
    ids = _decode_forward_text_ids(text_path, max_end)
    result: List[List[str]] = []
    for start, end in ranges:
        toks: List[str] = []
        for i in range(start, end + 1):
            if i < len(ids):
                try:
                    toks.append(lexicon.value_for_id(ids[i]))
                except ManateeFormatError:
                    toks.append("")
            else:
                toks.append("")
        result.append(toks)
    return result


def _strip_registry_value(value: str) -> str:
    text = value.strip()
    if len(text) >= 2 and text[0] == text[-1] and text[0] in {'"', "'"}:
        return text[1:-1]
    return text


def _read_u32_le(path: Path) -> List[int]:
    data = path.read_bytes()
    if len(data) % 4 != 0:
        raise ManateeFormatError(f"Invalid 32-bit component size for '{path}'.")
    return [row[0] for row in struct.iter_unpack("<I", data)]


def _read_i64_native(path: Path) -> List[int]:
    data = path.read_bytes()
    if len(data) % 8 != 0:
        raise ManateeFormatError(f"Invalid 64-bit component size for '{path}'.")
    return [row[0] for row in struct.iter_unpack("=q", data)]


@dataclass
class ManateeRegistryAttribute:
    name: str
    dynamic: str | None = None
    from_attr: str | None = None
    type_code: str | None = None


@dataclass
class ManateeRegistryStructure:
    name: str
    attributes: List[str]


@dataclass
class ManateeRegistrySummary:
    registry_file: Path
    registry_name: str | None
    configured_data_path: Path | None
    resolved_data_path: Path | None
    encoding: str | None
    docstructure: str | None
    positional: Dict[str, ManateeRegistryAttribute]
    structural: Dict[str, ManateeRegistryStructure]


@dataclass
class ManateeLexiconReader:
    base_path: Path

    def __post_init__(self) -> None:
        self._lex_bytes = (self.base_path.with_suffix(".lex")).read_bytes()
        self._offsets = _read_u32_le(self.base_path.with_suffix(".lex.idx"))
        self._sorted_ids = _read_u32_le(self.base_path.with_suffix(".lex.srt"))
        self._string_to_id: Dict[str, int] | None = None

    @property
    def size(self) -> int:
        return len(self._offsets)

    def value_for_id(self, idx: int) -> str:
        if idx < 0 or idx >= len(self._offsets):
            raise ManateeFormatError(f"Lexicon id {idx} out of range for '{self.base_path.name}'.")
        start = self._offsets[idx]
        end = self._offsets[idx + 1] if idx + 1 < len(self._offsets) else len(self._lex_bytes)
        return self._lex_bytes[start:end].rstrip(b"\0").decode("utf-8", errors="replace")

    def id_for_string(self, value: str) -> Optional[int]:
        if self._string_to_id is None:
            self._string_to_id = {self.value_for_id(idx): idx for idx in range(len(self._offsets))}
        return self._string_to_id.get(value)


@dataclass
class ManateeOldReverseIndex:
    """Old-style finDR reverse index: .rev + .rev.idx + .rev.cnt (+ optional .rev.cnt64)."""
    base_path: Path

    def __post_init__(self) -> None:
        rev_path = self.base_path.with_suffix(".rev")
        self._rev_bytes = rev_path.read_bytes()
        self.signature = self._rev_bytes[:7]
        if self._rev_bytes[:6] != _FINDR_SIGNATURE:
            raise ManateeFormatError(
                f"Reverse index '{rev_path}' is not old-style finDR (signature mismatch)."
            )
        self._alignmult = _decode_finDR_alignmult(self._rev_bytes)
        self.index = _read_u32_le(self.base_path.with_suffix(".rev.idx"))
        self.counts = _read_u32_le(self.base_path.with_suffix(".rev.cnt"))
        cnt64_path = self.base_path.with_suffix(".rev.cnt64")
        self.overflow_counts: Dict[int, int] = {}
        if cnt64_path.is_file() and cnt64_path.stat().st_size:
            values = _read_i64_native(cnt64_path)
            for i in range(0, len(values), 2):
                if i + 1 < len(values):
                    self.overflow_counts[int(values[i])] = int(values[i + 1])

    @property
    def id_count(self) -> int:
        return len(self.counts)

    def count(self, idx: int) -> int:
        if idx < 0 or idx >= len(self.counts):
            return 0
        return self.overflow_counts.get(idx, self.counts[idx])

    def postings_for_id(self, idx: int) -> List[int]:
        """Return list of corpus positions for lexicon id idx (verbatim Manatee id2poss semantics)."""
        if idx < 0 or idx >= self.id_count:
            return []
        c = self.count(idx)
        if c <= 0:
            return []
        seek_units = self.index[idx]
        seek_bytes = seek_units * self._alignmult
        return _decode_finDR_postings(
            self._rev_bytes, seek_bytes, c, self._alignmult
        )


# -----------------------------------------------------------------------------
# Stream algebra (verbatim Manatee: FastStream + QAndNode, QOrNode, QMoveNode)
# See query/cqpeval.y, query/frsop.hh, finlib/fsop.hh
# -----------------------------------------------------------------------------

class FastStream:
    """Abstract stream of corpus positions; mirrors Manatee FastStream."""

    def peek(self) -> int:
        """Next position without consuming."""
        raise NotImplementedError

    def next_pos(self) -> int:
        """Advance and return next position; return final() when exhausted."""
        raise NotImplementedError

    def final(self) -> int:
        """Sentinel value when stream is exhausted (e.g. corpus size or max position)."""
        raise NotImplementedError

    def to_list(self) -> List[int]:
        """Consume stream and return all positions (for small results)."""
        out: List[int] = []
        fin = self.final()
        while True:
            p = self.next_pos()
            if p >= fin:
                break
            out.append(p)
        return out


class ListPosStream(FastStream):
    """Stream over a sorted list of positions."""

    def __init__(self, positions: List[int], final_val: int = 2**62) -> None:
        self._pos = sorted(positions)
        self._i = 0
        self._fin = final_val

    def peek(self) -> int:
        if self._i < len(self._pos):
            return self._pos[self._i]
        return self._fin

    def next_pos(self) -> int:
        if self._i < len(self._pos):
            p = self._pos[self._i]
            self._i += 1
            return p
        return self._fin

    def final(self) -> int:
        return self._fin


class EmptyStream(FastStream):
    """Stream with no positions."""

    def __init__(self, final_val: int = 0) -> None:
        self._fin = final_val

    def peek(self) -> int:
        return self._fin

    def next_pos(self) -> int:
        return self._fin

    def final(self) -> int:
        return self._fin


class SequenceStream(FastStream):
    """Stream of all positions in [start, end] (e.g. wildcard [])."""

    def __init__(self, start: int, end: int, final_val: int) -> None:
        self._curr = start if start <= end else final_val
        self._end = end
        self._fin = final_val

    def peek(self) -> int:
        return self._curr

    def next_pos(self) -> int:
        if self._curr > self._end or self._curr >= self._fin:
            return self._fin
        p = self._curr
        self._curr += 1
        return p

    def final(self) -> int:
        return self._fin


class QAndNode(FastStream):
    """Intersection of two position streams (both must yield same position)."""

    def __init__(self, a: FastStream, b: FastStream) -> None:
        self._a = a
        self._b = b
        self._fin = min(a.final(), b.final())
        self._advance()

    def _advance(self) -> None:
        while self._a.peek() < self._fin and self._b.peek() < self._fin:
            pa, pb = self._a.peek(), self._b.peek()
            if pa == pb:
                return
            if pa < pb:
                self._a.next_pos()
            else:
                self._b.next_pos()

    def peek(self) -> int:
        if self._a.peek() < self._fin and self._a.peek() == self._b.peek():
            return self._a.peek()
        return self._fin

    def next_pos(self) -> int:
        if self._a.peek() >= self._fin or self._a.peek() != self._b.peek():
            return self._fin
        p = self._a.next_pos()
        self._b.next_pos()
        self._advance()
        return p

    def final(self) -> int:
        return self._fin


class QOrNode(FastStream):
    """Union of two position streams (merge sorted)."""

    def __init__(self, a: FastStream, b: FastStream) -> None:
        self._a = a
        self._b = b
        self._fin = min(a.final(), b.final())
        self._next_val: Optional[int] = None
        self._pull()

    def _pull(self) -> None:
        pa = self._a.peek() if self._a.peek() < self._fin else self._fin
        pb = self._b.peek() if self._b.peek() < self._fin else self._fin
        if pa >= self._fin and pb >= self._fin:
            self._next_val = None
            return
        if pa >= self._fin:
            self._next_val = pb
            return
        if pb >= self._fin:
            self._next_val = pa
            return
        if pa <= pb:
            self._next_val = pa
        else:
            self._next_val = pb

    def peek(self) -> int:
        if self._next_val is not None:
            return self._next_val
        return self._fin

    def next_pos(self) -> int:
        if self._next_val is None:
            return self._fin
        p = self._next_val
        if self._a.peek() == p and self._a.peek() < self._fin:
            self._a.next_pos()
        if self._b.peek() == p and self._b.peek() < self._fin:
            self._b.next_pos()
        self._pull()
        return p

    def final(self) -> int:
        return self._fin


class QMoveNode(FastStream):
    """Positions from inner stream shifted by k (e.g. -1 for 'next token')."""

    def __init__(self, inner: FastStream, shift: int) -> None:
        self._inner = inner
        self._shift = shift
        self._fin = inner.final()

    def peek(self) -> int:
        p = self._inner.peek()
        if p >= self._fin:
            return self._fin
        return p + self._shift

    def next_pos(self) -> int:
        p = self._inner.next_pos()
        if p >= self._fin:
            return self._fin
        return p + self._shift

    def final(self) -> int:
        return self._fin


class QNotNode(FastStream):
    """Positions where inner stream does not yield (complement up to corpus size)."""

    def __init__(self, inner: FastStream, corpus_size: int) -> None:
        self._inner = inner
        self._fin = corpus_size
        self._curr = 0
        self._inner_next = inner.next_pos() if inner.peek() < inner.final() else self._fin

    def peek(self) -> int:
        while self._curr < self._fin and self._curr == self._inner_next:
            self._curr += 1
            if self._inner_next < self._inner.final():
                self._inner_next = self._inner.next_pos()
        if self._curr < self._fin:
            return self._curr
        return self._fin

    def next_pos(self) -> int:
        p = self.peek()
        if p >= self._fin:
            return self._fin
        self._curr += 1
        return p

    def final(self) -> int:
        return self._fin


class ManateePosAttr:
    """
    Positional attribute facade: str2id + id2poss (verbatim Manatee PosAttr semantics).
    Used to run Manatee-style queries over Manatee files.
    """

    def __init__(
        self,
        name: str,
        lexicon: ManateeLexiconReader,
        reverse: ManateeOldReverseIndex,
        corpus_size: int,
    ) -> None:
        self.name = name
        self._lex = lexicon
        self._rev = reverse
        self._corpus_size = corpus_size

    def str2id(self, s: str) -> Optional[int]:
        return self._lex.id_for_string(s)

    def id2poss(self, idx: int) -> FastStream:
        """Return a FastStream of corpus positions for lexicon id idx."""
        if idx < 0 or idx >= self._rev.id_count:
            return EmptyStream(self._corpus_size)
        c = self._rev.count(idx)
        if c <= 0:
            return EmptyStream(self._corpus_size)
        positions = self._rev.postings_for_id(idx)
        # Use large final so we yield all postings; executor filters to < corpus_size
        return ListPosStream(positions, 2**31 - 1)

    def corpus_size(self) -> int:
        return self._corpus_size


def manatee_concat_streams(
    s1: FastStream, s2: FastStream, len1: int, final_val: int
) -> FastStream:
    """
    Concat two position streams (verbatim Manatee concat for two FastStreams).
    Returns QAndNode(s1, QMoveNode(s2, -len1)).
    """
    return QAndNode(s1, QMoveNode(s2, -len1))


def manatee_eval_simple_sequence(
    pos_attrs: Dict[str, ManateePosAttr],
    default_attr_name: str,
    steps: List[tuple[str, Optional[str]]],
    corpus_size: int,
) -> List[int]:
    """
    Run a simple token sequence the Manatee way: each step is (attr_name, value_or_None).
    value_or_None = None means wildcard (any position). Otherwise exact match.
    Returns sorted list of match start positions (one per match).
    """
    if not steps:
        return []
    attr = pos_attrs.get(default_attr_name)
    if attr is None:
        return []
    streams: List[FastStream] = []
    for attr_name, value in steps:
        if value is None or value == "":
            streams.append(SequenceStream(0, corpus_size - 1, corpus_size))
        else:
            a = pos_attrs.get(attr_name or default_attr_name)
            if a is None:
                return []
            lid = a.str2id(value)
            if lid is None:
                return []
            streams.append(a.id2poss(lid))
    # Reduce: concat first two, then concat with next, etc.
    combined = streams[0]
    length_so_far = 1
    for i in range(1, len(streams)):
        combined = manatee_concat_streams(
            combined, streams[i], length_so_far, corpus_size
        )
        length_so_far += 1
    raw = combined.to_list()
    # Restrict to valid range: match [start, start+len-1] must be inside [0, corpus_size)
    seq_len = len(steps)
    return [p for p in raw if 0 <= p and p + seq_len - 1 < corpus_size]


@dataclass
class ManateeTextMetadata:
    signature: bytes
    text_path: Path
    segment_path: Path | None


@dataclass
class ManateeAttributeFiles:
    name: str
    base_path: Path
    lexicon: ManateeLexiconReader
    reverse: ManateeOldReverseIndex
    text: ManateeTextMetadata


@dataclass
class ManateeCorpusScaffold:
    summary: ManateeRegistrySummary
    positional: Dict[str, ManateeAttributeFiles]
    structures: Dict[str, Path]
    corpus_size: int = 0
    pos_attrs: Dict[str, ManateePosAttr] = field(default_factory=dict)


@dataclass
class ManateeRuntimeSetup:
    summary: ManateeRegistrySummary
    runtime_registry_dir: Path


def resolve_manatee_registry_file(cfg: ManateeConfig) -> Path:
    registry_dir = Path(cfg.registry).expanduser().resolve()
    registry_file = registry_dir / cfg.corpus
    if not registry_file.is_file():
        raise ManateeFormatError(f"Manatee registry file '{registry_file}' does not exist.")
    return registry_file


def parse_manatee_registry(registry_file: Path) -> ManateeRegistrySummary:
    registry_name: str | None = None
    path_value: Optional[str] = None
    encoding: str | None = None
    docstructure: str | None = None
    positional: Dict[str, ManateeRegistryAttribute] = {}
    structural: Dict[str, ManateeRegistryStructure] = {}
    current_attribute: ManateeRegistryAttribute | None = None
    current_structure: ManateeRegistryStructure | None = None

    with registry_file.open("r", encoding="utf-8", errors="ignore") as fh:
        for raw_line in fh:
            line = raw_line.strip()
            if not line or line.startswith("#"):
                continue
            if line == "}":
                current_attribute = None
                current_structure = None
                continue
            if line.startswith("NAME "):
                registry_name = _strip_registry_value(line.split(None, 1)[1])
                continue
            if line.startswith("PATH "):
                path_value = _strip_registry_value(line.split(None, 1)[1])
                continue
            if line.startswith("ENCODING "):
                encoding = _strip_registry_value(line.split(None, 1)[1])
                continue
            if line.startswith("DOCSTRUCTURE "):
                docstructure = _strip_registry_value(line.split(None, 1)[1])
                continue
            if line.startswith("ATTRIBUTE "):
                name = line.split()[1]
                if current_structure is not None:
                    current_structure.attributes.append(name)
                else:
                    current_attribute = ManateeRegistryAttribute(name=name)
                    positional[name] = current_attribute
                continue
            if line.startswith("STRUCTURE "):
                name = line.split()[1]
                current_structure = ManateeRegistryStructure(name=name, attributes=[])
                structural[name] = current_structure
                current_attribute = None
                continue
            if current_attribute is not None:
                if line.startswith("DYNAMIC "):
                    current_attribute.dynamic = _strip_registry_value(line.split(None, 1)[1])
                elif line.startswith("FROMATTR "):
                    current_attribute.from_attr = _strip_registry_value(line.split(None, 1)[1])
                elif line.startswith("TYPE "):
                    current_attribute.type_code = _strip_registry_value(line.split(None, 1)[1])

    configured_data_path: Path | None = None
    resolved_data_path: Path | None = None
    if path_value:
        candidate = Path(path_value).expanduser()
        if not candidate.is_absolute():
            candidate = (registry_file.parent / candidate).resolve()
        configured_data_path = candidate
        if candidate.exists():
            resolved_data_path = candidate
        else:
            local_fallback = (registry_file.parent / "corp").resolve()
            if local_fallback.exists():
                resolved_data_path = local_fallback

    return ManateeRegistrySummary(
        registry_file=registry_file,
        registry_name=registry_name,
        configured_data_path=configured_data_path,
        resolved_data_path=resolved_data_path,
        encoding=encoding,
        docstructure=docstructure,
        positional=positional,
        structural=structural,
    )


def load_manatee_corpus_scaffold(cfg: ManateeConfig) -> ManateeCorpusScaffold:
    summary = parse_manatee_registry(resolve_manatee_registry_file(cfg))
    if summary.resolved_data_path is None or not summary.resolved_data_path.is_dir():
        raise ManateeFormatError(
            f"Manatee data path '{summary.configured_data_path}' is not available and no local fallback was found."
        )

    positional: Dict[str, ManateeAttributeFiles] = {}
    for name, attr in summary.positional.items():
        if attr.dynamic:
            continue
        base = summary.resolved_data_path / name
        lex = base.with_suffix(".lex")
        lex_idx = base.with_suffix(".lex.idx")
        lex_srt = base.with_suffix(".lex.srt")
        rev = base.with_suffix(".rev")
        rev_idx = base.with_suffix(".rev.idx")
        rev_cnt = base.with_suffix(".rev.cnt")
        text = base.with_suffix(".text")
        if not all(path.is_file() for path in (lex, lex_idx, lex_srt, rev, rev_idx, rev_cnt, text)):
            continue
        segment_path = None
        seg_candidate = base.with_suffix(".text.seg")
        if seg_candidate.is_file():
            segment_path = seg_candidate
        positional[name] = ManateeAttributeFiles(
            name=name,
            base_path=base,
            lexicon=ManateeLexiconReader(base),
            reverse=ManateeOldReverseIndex(base),
            text=ManateeTextMetadata(
                signature=text.read_bytes()[:7],
                text_path=text,
                segment_path=segment_path,
            ),
        )

    structures: Dict[str, Path] = {}
    for name in summary.structural:
        rng_path = summary.resolved_data_path / f"{name}.rng"
        if rng_path.is_file():
            structures[name] = rng_path

    corpus_size = 0
    for _name, af in positional.items():
        sz = _read_text_size_from_header(af.text.text_path)
        if sz is not None and sz > 0:
            corpus_size = sz
            break

    pos_attrs: Dict[str, ManateePosAttr] = {}
    for name, af in positional.items():
        size = corpus_size or 2**31 - 1
        pos_attrs[name] = ManateePosAttr(
            name=name,
            lexicon=af.lexicon,
            reverse=af.reverse,
            corpus_size=size,
        )

    return ManateeCorpusScaffold(
        summary=summary,
        positional=positional,
        structures=structures,
        corpus_size=corpus_size,
        pos_attrs=pos_attrs,
    )


def inspect_native_files(cfg: ManateeConfig) -> Dict[str, object]:
    summary = parse_manatee_registry(resolve_manatee_registry_file(cfg))
    if summary.resolved_data_path is None or not summary.resolved_data_path.is_dir():
        raise ManateeFormatError(
            f"Manatee data path '{summary.configured_data_path}' is not available and no local fallback was found."
        )

    native_pattributes: List[str] = []
    text_signatures: Dict[str, str] = {}
    for name, attr in summary.positional.items():
        if attr.dynamic:
            continue
        base = summary.resolved_data_path / name
        required = [
            base.with_suffix(".lex"),
            base.with_suffix(".lex.idx"),
            base.with_suffix(".lex.srt"),
            base.with_suffix(".rev"),
            base.with_suffix(".rev.idx"),
            base.with_suffix(".rev.cnt"),
            base.with_suffix(".text"),
        ]
        if all(path.is_file() for path in required):
            native_pattributes.append(name)
            text_signatures[name] = base.with_suffix(".text").read_bytes()[:7].decode(
                "latin-1", errors="replace"
            )

    native_structures: List[str] = []
    for name in summary.structural:
        if (summary.resolved_data_path / f"{name}.rng").is_file():
            native_structures.append(name)

    return {
        "summary": summary,
        "native_pattributes": sorted(native_pattributes),
        "native_structures": sorted(native_structures),
        "text_signatures": text_signatures,
    }


def prepare_runtime_registry(cfg: ManateeConfig) -> ManateeRuntimeSetup:
    summary = parse_manatee_registry(resolve_manatee_registry_file(cfg))
    if summary.resolved_data_path is None or not summary.resolved_data_path.is_dir():
        raise ManateeFormatError(
            f"Manatee data path '{summary.configured_data_path}' is not available and no local fallback was found."
        )
    resolved = summary.resolved_data_path
    # Always write a runtime registry with PATH set to the absolute resolved path.
    # The Manatee C library may resolve relative PATH against CWD (e.g. PHP/server), not the
    # registry dir, so we must pass an absolute path to avoid "corp/word: No such file or directory".
    registry_text = summary.registry_file.read_text(encoding="utf-8", errors="ignore")
    patched_lines: List[str] = []
    local_vertical = summary.registry_file.parent / "corpus.vrt"
    for raw_line in registry_text.splitlines():
        line = raw_line.strip()
        if line.startswith("PATH "):
            patched_lines.append(f'PATH  "{resolved}"')
        elif line.startswith("VERTICAL ") and local_vertical.is_file():
            patched_lines.append(f'VERTICAL "{local_vertical}"')
        elif line.startswith("ATTRIBUTE ") and "{" not in line:
            # Wrap ATTRIBUTE into a block and force TYPE "FD_MI" (int_text),
            # so Manatee uses int_text instead of delta_text (no *.text.seg needed).
            parts = line.split()
            attr_name = parts[1] if len(parts) > 1 else ""
            if attr_name.startswith('"') and attr_name.endswith('"'):
                disp_name = attr_name
            else:
                disp_name = f'"{attr_name}"' if attr_name else '"word"'
            patched_lines.append(f"ATTRIBUTE {disp_name} {{")
            patched_lines.append('TYPE "FD_MI"')
            patched_lines.append("}")
        else:
            patched_lines.append(raw_line)

    runtime_dir = Path(tempfile.mkdtemp(prefix="flexicorp-manatee-registry-"))
    runtime_file = runtime_dir / summary.registry_file.name
    runtime_file.write_text("\n".join(patched_lines) + "\n", encoding="utf-8")
    return ManateeRuntimeSetup(summary=summary, runtime_registry_dir=runtime_dir)
