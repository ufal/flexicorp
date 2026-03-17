from __future__ import annotations

import bisect
import importlib
import mmap
import os
import re
import struct
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional

from ..config import CqpConfig, ManateeConfig, get_cqp_config, get_manatee_config
from ..core import FlexiRequest, register_backend
from ..querylang.cwb_cql import (
    AttributeRef,
    ComparisonConstraint,
    CwbQuery,
    SequencePattern,
    StringValue,
    TokenPattern,
    parse_cwb_cql,
)
from ..teitok import detect_teitok_cqp, detect_teitok_manatee
from ..highlight_contract import build_highlight_map, resolve_legend
from .cqp import CqpBackend
from .manatee import (
    ManateeFormatError,
    get_token_strings_for_hits,
    load_manatee_corpus_scaffold,
    load_manatee_bindings,
    manatee_eval_simple_sequence,
    inspect_native_files,
    prepare_runtime_registry,
    resolve_manatee_registry_file,
)


class FlexiExecutionError(RuntimeError):
    pass


@dataclass
class _PositionalAttributeReader:
    name: str
    corpus_path: Path
    lexicon_path: Path
    lexicon_idx_path: Path

    def __post_init__(self) -> None:
        self._corpus_file = self.corpus_path.open("rb")
        self._corpus_mm = mmap.mmap(self._corpus_file.fileno(), 0, access=mmap.ACCESS_READ)
        self._lexicon_bytes = self.lexicon_path.read_bytes()
        self._offsets = self._read_uints(self.lexicon_idx_path)
        self._string_to_id: Dict[str, int] | None = None

    @staticmethod
    def _read_uints(path: Path) -> List[int]:
        data = path.read_bytes()
        if len(data) % 4 != 0:
            raise FlexiExecutionError(f"Invalid CWB integer component size for '{path}'.")
        return [row[0] for row in struct.iter_unpack(">I", data)]

    @property
    def token_count(self) -> int:
        return len(self._corpus_mm) // 4

    def id_at(self, cpos: int) -> int:
        return struct.unpack_from(">I", self._corpus_mm, cpos * 4)[0]

    def value_for_id(self, idx: int) -> str:
        if idx < 0 or idx >= len(self._offsets):
            raise FlexiExecutionError(f"Lexicon id {idx} out of range for attribute '{self.name}'.")
        start = self._offsets[idx]
        if idx + 1 < len(self._offsets):
            end = self._offsets[idx + 1]
        else:
            end = len(self._lexicon_bytes)
        raw = self._lexicon_bytes[start:end]
        return raw.rstrip(b"\0").decode("utf-8", errors="replace")

    def value_at(self, cpos: int) -> str:
        return self.value_for_id(self.id_at(cpos))

    def id_for_string(self, value: str) -> Optional[int]:
        if self._string_to_id is None:
            self._string_to_id = {}
            for idx in range(len(self._offsets)):
                self._string_to_id[self.value_for_id(idx)] = idx
        return self._string_to_id.get(value)

    def close(self) -> None:
        try:
            self._corpus_mm.close()
        finally:
            self._corpus_file.close()


@dataclass
class _StructuralAttributeReader:
    name: str
    ranges_path: Path
    avs_path: Path | None = None
    avx_path: Path | None = None

    def __post_init__(self) -> None:
        numbers = self._read_uints(self.ranges_path)
        if len(numbers) % 2 != 0:
            raise FlexiExecutionError(f"Invalid CWB range component for '{self.ranges_path}'.")
        self.starts = numbers[0::2]
        self.ends = numbers[1::2]
        self._avs_bytes = self.avs_path.read_bytes() if self.avs_path and self.avs_path.is_file() else None
        self._avx_numbers = self._read_uints(self.avx_path) if self.avx_path and self.avx_path.is_file() else None

    @staticmethod
    def _read_uints(path: Path | None) -> List[int]:
        if path is None:
            return []
        data = path.read_bytes()
        if len(data) % 4 != 0:
            raise FlexiExecutionError(f"Invalid CWB integer component size for '{path}'.")
        return [row[0] for row in struct.iter_unpack(">I", data)]

    def region_index_at(self, cpos: int) -> Optional[int]:
        idx = bisect.bisect_right(self.starts, cpos) - 1
        if idx < 0:
            return None
        if self.ends[idx] < cpos:
            return None
        return idx

    def span_at(self, cpos: int) -> Optional[tuple[int, int]]:
        idx = self.region_index_at(cpos)
        if idx is None:
            return None
        return self.starts[idx], self.ends[idx]

    def value_at(self, cpos: int) -> Optional[str]:
        idx = self.region_index_at(cpos)
        if idx is None or self._avs_bytes is None or self._avx_numbers is None:
            return None
        pos = idx * 2 + 1
        if pos >= len(self._avx_numbers):
            return None
        avs_offset = self._avx_numbers[pos]
        end = self._avs_bytes.find(b"\0", avs_offset)
        if end < 0:
            end = len(self._avs_bytes)
        return self._avs_bytes[avs_offset:end].decode("utf-8", errors="replace")


@dataclass
class _CwbCorpusReader:
    home: Path
    positional: Dict[str, _PositionalAttributeReader]
    structural: Dict[str, _StructuralAttributeReader]

    def close(self) -> None:
        for reader in self.positional.values():
            reader.close()


@dataclass
class FlexiBackend(CqpBackend):
    name: str = "flexi"

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "flexi",
            "supported_query_languages": ["cwb-cql", "manatee-cql"],
            "supported_corpus_formats": ["cwb", "manatee"],
            "default_query_language": "auto",
            "default_corpus_format": "auto",
            "default_selection_reason": "Prefer manatee-cql/manatee when a native Manatee corpus is available; otherwise fall back to cwb-cql/cwb.",
        }

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": False,
            "freq": False,
            "info": True,
            "reindex": False,  # Flexi only reads corpus files; use manatee/cqp backend to reindex
            "raw_query": False,
            "query": True,
        }

    def _resolve_registry_file(self, cfg: CqpConfig) -> Optional[Path]:
        if not cfg.registry:
            return None
        registry_path = Path(cfg.registry).expanduser()
        if registry_path.is_dir():
            candidate = registry_path / cfg.corpus.lower()
            if candidate.is_file():
                return candidate
            return None
        if registry_path.is_file():
            return registry_path
        return None

    def _resolve_corpus_home(self, cfg: CqpConfig, project: Dict[str, Any]) -> Path:
        registry_file = self._resolve_registry_file(cfg)
        if registry_file and registry_file.is_file():
            try:
                with registry_file.open("r", encoding="utf-8", errors="ignore") as fh:
                    for line in fh:
                        line = line.strip()
                        if line.startswith("HOME"):
                            parts = line.split(None, 1)
                            if len(parts) == 2:
                                home = Path(parts[1].strip()).expanduser()
                                if not home.is_absolute():
                                    home = (registry_file.parent / home).resolve()
                                return home
            except OSError:
                pass

        if cfg.registry:
            registry_path = Path(cfg.registry).expanduser()
            if registry_path.is_dir():
                return registry_path.resolve()
            if registry_path.is_file():
                return registry_path.parent.resolve()

        root = project.get("root")
        if root:
            return (Path(root).expanduser().resolve() / "cqp").resolve()
        raise FlexiExecutionError("Could not determine CWB corpus home directory for flexi backend.")

    def _load_corpus(self, cfg: CqpConfig, project: Dict[str, Any]) -> _CwbCorpusReader:
        home = self._resolve_corpus_home(cfg, project)
        if not home.is_dir():
            raise FlexiExecutionError(f"CWB corpus home '{home}' does not exist or is not a directory.")

        def positional_reader(name: str) -> _PositionalAttributeReader:
            corpus_path = home / f"{name}.corpus"
            lexicon_path = home / f"{name}.lexicon"
            lexicon_idx_path = home / f"{name}.lexicon.idx"
            if not (corpus_path.is_file() and lexicon_path.is_file() and lexicon_idx_path.is_file()):
                raise FlexiExecutionError(
                    f"Missing positional attribute components for '{name}' in '{home}'. "
                    "The first flexi backend subset currently expects uncompressed .corpus/.lexicon/.lexicon.idx files."
                )
            return _PositionalAttributeReader(name, corpus_path, lexicon_path, lexicon_idx_path)

        positional: Dict[str, _PositionalAttributeReader] = {"word": positional_reader("word")}
        for optional_name in ("id",):
            try:
                positional[optional_name] = positional_reader(optional_name)
            except FlexiExecutionError:
                pass

        structural: Dict[str, _StructuralAttributeReader] = {}
        for path in home.glob("*.rng"):
            name = path.stem
            structural[name] = _StructuralAttributeReader(
                name=name,
                ranges_path=path,
                avs_path=(home / f"{name}.avs"),
                avx_path=(home / f"{name}.avx"),
            )

        return _CwbCorpusReader(home=home, positional=positional, structural=structural)

    def _get_manatee_config(self, project: Dict[str, Any]) -> ManateeConfig:
        cfg = get_manatee_config(project)
        if cfg is not None:
            return cfg
        root = project.get("root")
        if root:
            detected = detect_teitok_manatee(Path(root).expanduser().resolve())
            if detected:
                inferred = get_manatee_config(detected)
                if inferred is not None:
                    return inferred
        raise FlexiExecutionError(
            "Could not determine Manatee configuration for flexi backend. "
            "Provide project.manatee.registry and project.manatee.corpus, or run from a TEITOK project with a local manatee/ directory."
        )

    def _get_optional_cqp_config(self, project: Dict[str, Any]) -> CqpConfig | None:
        cfg = get_cqp_config(project)
        if cfg is not None:
            return cfg
        root = project.get("root")
        if root:
            detected = detect_teitok_cqp(Path(root).expanduser().resolve())
            if detected:
                return get_cqp_config(detected)
        return None

    @staticmethod
    def _load_manatee_module(project_root: Path | None = None) -> Any:
        try:
            return load_manatee_bindings(project_root=project_root)
        except Exception as exc:
            raise FlexiExecutionError(
                str(exc)
            ) from exc

    def _resolve_manatee_registry_file(self, cfg: ManateeConfig) -> Path:
        try:
            return resolve_manatee_registry_file(cfg)
        except ManateeFormatError as exc:
            raise FlexiExecutionError(str(exc)) from exc

    def _read_manatee_registry_summary(self, cfg: ManateeConfig) -> Dict[str, Any]:
        inspection = inspect_native_files(cfg)
        summary = inspection["summary"]
        return {
            "registry_file": summary.registry_file,
            "data_path": summary.configured_data_path,
            "resolved_data_path": summary.resolved_data_path,
            "pattributes": list(summary.positional.keys()),
            "sattributes": list(summary.structural.keys()),
            "native_pattributes": list(inspection["native_pattributes"]),
            "native_structures": list(inspection["native_structures"]),
            "text_signatures": dict(inspection["text_signatures"]),
        }

    def _open_manatee_corpus(self, cfg: ManateeConfig) -> Any:
        try:
            runtime = prepare_runtime_registry(cfg)
        except ManateeFormatError as exc:
            raise FlexiExecutionError(str(exc)) from exc
        manatee = self._load_manatee_module(Path(cfg.registry).expanduser().resolve().parent)
        os.environ["MANATEE_REGISTRY"] = str(runtime.runtime_registry_dir)
        return manatee.Corpus(cfg.corpus)

    @staticmethod
    def _get_manatee_posattr(corpus: Any, name: str) -> Any | None:
        try:
            return corpus.get_attr(name)
        except Exception:
            return None

    @staticmethod
    def _get_manatee_struct_attr(corpus: Any, struct_name: str, attr_name: str) -> Any | None:
        try:
            struct_obj = corpus.get_struct(struct_name)
            return struct_obj.get_attr(attr_name)
        except Exception:
            return None

    def _lower_to_manatee_cql(self, parsed: CwbQuery) -> str:
        if not parsed.source_text:
            raise FlexiExecutionError("Parsed cwb-cql query lost its source text before Manatee lowering.")
        return parsed.source_text.strip()

    _KWIC_RAW_DELIM = "--%%%--"

    def _build_hit(
        self,
        *,
        doc_id: Optional[str],
        sentence_id: Optional[str],
        toks: List[str],
        match_start: int,
        match_end: int,
        highlight_groups: Optional[List[Dict[str, Any]]] = None,
        left_context: Optional[str] = None,
        right_context: Optional[str] = None,
    ) -> Dict[str, Any]:
        # Use KWIC delimiter format so the frontend parseEngineKwicRaw shows left / match / right
        if toks:
            left = (left_context or "").strip()
            match = " ".join(str(t) for t in toks)
            right = (right_context or "").strip()
            raw = f"{left}{self._KWIC_RAW_DELIM}{match}{self._KWIC_RAW_DELIM}{right}"
        else:
            raw = self._make_query_hit_raw(
                doc_id=str(doc_id) if doc_id is not None else None,
                sentence_id=str(sentence_id) if sentence_id is not None else None,
                match_start=match_start,
                match_end=match_end,
                toks=[str(tok) for tok in toks],
            )
        hit: Dict[str, Any] = {
            "doc_id": doc_id,
            "sentence_id": sentence_id,
            "toks": toks,
            "row": {
                "doc_id": doc_id,
                "sentence_id": sentence_id,
                "toks": toks,
            },
            "match_start": match_start,
            "match_end": match_end,
            "raw": raw,
        }
        if toks or highlight_groups:
            hit["highlight_map"] = build_highlight_map(
                toks,
                groups=highlight_groups or [],
            )
        return hit

    def _attach_context_if_requested(
        self,
        *,
        hit: Dict[str, Any],
        cfg: CqpConfig | None,
        detected: Optional[Dict[str, Any]],
        context_spec: Optional[Dict[str, Any]],
    ) -> None:
        if not context_spec or not detected or cfg is None or not hit.get("doc_id"):
            return
        context = self._resolve_teitok_context(
            cfg=cfg,
            root_dir=Path(detected.get("root") or ".").resolve(),
            searchfolder=str((detected.get("meta") or {}).get("searchfolder") or "xmlfiles"),
            doc_id=str(hit["doc_id"]),
            sentence_id=str(hit["sentence_id"]) if hit.get("sentence_id") else None,
            tok_ids=[str(tok) for tok in hit.get("toks") or []],
            match_start=hit.get("match_start"),
            match_end=hit.get("match_end"),
            context_spec=context_spec,
        )
        if context:
            hit["context"] = context

    def _query_cwb_native(
        self,
        *,
        cfg: CqpConfig,
        project: Dict[str, Any],
        parsed: CwbQuery,
        query_lang: str,
        start: int,
        max_hits: int,
        context_spec: Optional[Dict[str, Any]],
        detected: Optional[Dict[str, Any]],
    ) -> Dict[str, Any]:
        corpus = self._load_corpus(cfg, project)
        try:
            total = 0
            hits: List[Dict[str, Any]] = []
            seq_len = len(parsed.pattern.items)
            id_reader = corpus.positional.get("id")
            text_id_reader = corpus.structural.get("text_id")
            sentence_reader = corpus.structural.get("s_id")
            for cpos in range(0, max(0, corpus.positional["word"].token_count - seq_len + 1)):
                if not self._match_sequence(parsed, corpus, cpos):
                    continue
                total += 1
                if total <= start:
                    continue
                if len(hits) >= max_hits:
                    continue

                end_cpos = cpos + seq_len - 1
                doc_id = text_id_reader.value_at(cpos) if text_id_reader else None
                if doc_id and not str(doc_id).endswith(".xml"):
                    doc_id = f"{doc_id}.xml"
                sentence_id = sentence_reader.value_at(cpos) if sentence_reader else None
                toks = [id_reader.value_at(pos) for pos in range(cpos, end_cpos + 1)] if id_reader else []
                hit = self._build_hit(
                    doc_id=doc_id,
                    sentence_id=sentence_id,
                    toks=toks,
                    match_start=cpos,
                    match_end=end_cpos,
                )
                self._attach_context_if_requested(
                    hit=hit,
                    cfg=cfg,
                    detected=detected,
                    context_spec=context_spec,
                )
                hits.append(hit)

            return {
                "total": total,
                "start": start,
                "hits": hits,
                "returned": len(hits),
                "query_lang": query_lang,
                "corpus_format": "cwb",
                "engine": "flexi-first-subset",
                "parsed": {
                    "pattern_length": len(parsed.pattern.items),
                    "within": parsed.within.scope if parsed.within else None,
                },
            }
        finally:
            corpus.close()

    def _parsed_to_manatee_steps(self, parsed: CwbQuery) -> List[tuple[str, Optional[str]]]:
        """Convert parsed cwb-cql to steps for manatee_eval_simple_sequence. Raises if unsupported."""
        if parsed.within is not None:
            raise FlexiExecutionError(
                "Manatee native executor does not yet support 'within'; use Manatee bindings or omit within."
            )
        if not isinstance(parsed.pattern, SequencePattern):
            raise FlexiExecutionError("Manatee native executor expects a sequence pattern.")
        steps: List[tuple[str, Optional[str]]] = []
        default_attr = "word"
        for token in parsed.pattern.items:
            if token.constraint is None:
                steps.append((default_attr, None))
                continue
            if not isinstance(token.constraint, ComparisonConstraint):
                raise FlexiExecutionError(
                    "Manatee native executor only supports simple comparison constraints."
                )
            if not isinstance(token.constraint.left, AttributeRef) or not isinstance(
                token.constraint.right, StringValue
            ):
                raise FlexiExecutionError(
                    "Manatee native executor only supports [attr=\"value\"] or [attr!=\"value\"]."
                )
            if token.constraint.op != "=":
                raise FlexiExecutionError(
                    "Manatee native executor only supports = (exact match) for now."
                )
            attr_name = token.constraint.left.name
            value = token.constraint.right.value
            steps.append((attr_name, value))
        return steps

    def _query_manatee_native_files(
        self,
        *,
        manatee_cfg: ManateeConfig,
        parsed: CwbQuery,
        query_lang: str,
        start: int,
        max_hits: int,
    ) -> Dict[str, Any]:
        """Run query over Manatee files using the native executor (no bindings)."""
        steps = self._parsed_to_manatee_steps(parsed)
        scaffold = load_manatee_corpus_scaffold(manatee_cfg)
        if not scaffold.pos_attrs or "word" not in scaffold.pos_attrs:
            raise FlexiExecutionError(
                "Manatee corpus has no 'word' attribute for the native executor."
            )
        corpus_size = scaffold.corpus_size or (2**31 - 1)
        all_starts = manatee_eval_simple_sequence(
            scaffold.pos_attrs, "word", steps, corpus_size
        )
        total = len(all_starts)
        seq_len = len(steps)
        slice_starts = all_starts[start : start + max_hits] if max_hits > 0 else []
        ranges = [(cpos, cpos + seq_len - 1) for cpos in slice_starts]
        word_af = scaffold.positional.get("word")
        if word_af and ranges:
            token_lists = get_token_strings_for_hits(
                word_af.lexicon,
                word_af.text.text_path,
                ranges,
                corpus_size,
            )
        else:
            token_lists = [[] for _ in ranges]
        hits: List[Dict[str, Any]] = []
        for i, cpos in enumerate(slice_starts):
            match_end = cpos + seq_len - 1
            toks = token_lists[i] if i < len(token_lists) else []
            hit = self._build_hit(
                doc_id=None,
                sentence_id=None,
                toks=toks,
                match_start=cpos,
                match_end=match_end,
            )
            hits.append(hit)
        return {
            "total": total,
            "start": start,
            "hits": hits,
            "returned": len(hits),
            "query_lang": query_lang,
            "corpus_format": "manatee",
            "engine": "flexi-manatee-files",
            "parsed": {
                "pattern_length": seq_len,
                "within": parsed.within.scope if parsed.within else None,
            },
            "registry": manatee_cfg.registry,
            "corpus": manatee_cfg.corpus,
        }

    def _query_manatee_native(
        self,
        *,
        cfg: CqpConfig | None,
        project: Dict[str, Any],
        parsed: CwbQuery,
        query_lang: str,
        start: int,
        max_hits: int,
        context_spec: Optional[Dict[str, Any]],
        detected: Optional[Dict[str, Any]],
    ) -> Dict[str, Any]:
        manatee_cfg = self._get_manatee_config(project)
        corpus = self._open_manatee_corpus(manatee_cfg)
        manatee = self._load_manatee_module(Path(manatee_cfg.registry).expanduser().resolve().parent)
        conc = manatee.Concordance(corpus, self._lower_to_manatee_cql(parsed), 0, -1)
        if hasattr(conc, "sync"):
            conc.sync()

        total = int(conc.size())
        if max_hits <= 0 or start >= total:
            return {
                "total": total,
                "start": start,
                "hits": [],
                "returned": 0,
                "query_lang": query_lang,
                "corpus_format": "manatee",
                "engine": "flexi-manatee-subset",
                "parsed": {
                    "pattern_length": len(parsed.pattern.items),
                    "within": parsed.within.scope if parsed.within else None,
                    "lowered_query": self._lower_to_manatee_cql(parsed),
                },
                "registry": manatee_cfg.registry,
                "corpus": manatee_cfg.corpus,
            }
        end = min(start + max_hits, total)
        # Prefer word/form for display; fall back to id for token identifiers
        word_attr = self._get_manatee_posattr(corpus, "word") or self._get_manatee_posattr(corpus, "form")
        id_attr = self._get_manatee_posattr(corpus, "id")
        text_id_attr = self._get_manatee_struct_attr(corpus, "text", "id")
        sentence_id_attr = self._get_manatee_struct_attr(corpus, "s", "id")
        kl = manatee.KWICLines(
            corpus,
            conc.RS(True, start, end),
            "0",
            "0",
            "",
            "",
            "",
            "",
        )

        hits: List[Dict[str, Any]] = []
        while kl.nextline():
            match_start = int(kl.get_pos())
            kwic_len_value = int(kl.get_kwiclen())
            kwic_len = kwic_len_value if kwic_len_value > 0 else len(parsed.pattern.items)
            match_end = match_start + kwic_len - 1
            doc_id = text_id_attr.pos2str(match_start) if text_id_attr else None
            sentence_id = sentence_id_attr.pos2str(match_start) if sentence_id_attr else None
            # Use word/form for display (KWIC); fall back to id if no word attr
            attr_for_toks = word_attr if word_attr is not None else id_attr
            toks = [attr_for_toks.pos2str(pos) for pos in range(match_start, match_end + 1)] if attr_for_toks else []
            hit = self._build_hit(
                doc_id=doc_id,
                sentence_id=sentence_id,
                toks=toks,
                match_start=match_start,
                match_end=match_end,
            )
            self._attach_context_if_requested(
                hit=hit,
                cfg=cfg,
                detected=detected,
                context_spec=context_spec,
            )
            hits.append(hit)

        return {
            "total": total,
            "start": start,
            "hits": hits,
            "returned": len(hits),
            "query_lang": query_lang,
            "corpus_format": "manatee",
            "engine": "flexi-manatee-subset",
            "parsed": {
                "pattern_length": len(parsed.pattern.items),
                "within": parsed.within.scope if parsed.within else None,
                "lowered_query": self._lower_to_manatee_cql(parsed),
            },
            "registry": manatee_cfg.registry,
            "corpus": manatee_cfg.corpus,
        }

    def _match_constraint(
        self,
        query: CwbQuery,
        token: TokenPattern,
        corpus: _CwbCorpusReader,
        cpos: int,
    ) -> bool:
        constraint = token.constraint
        if constraint is None:
            return True
        if not isinstance(constraint, ComparisonConstraint):
            raise FlexiExecutionError("Only simple comparison constraints are supported in the first flexi subset.")
        if not isinstance(constraint.left, AttributeRef) or not isinstance(constraint.right, StringValue):
            raise FlexiExecutionError("Only attribute-to-string token constraints are supported in the first flexi subset.")

        attr_name = constraint.left.name
        if attr_name not in corpus.positional:
            raise FlexiExecutionError(
                f"Positional attribute '{attr_name}' is not available in the first flexi backend subset."
            )
        value = corpus.positional[attr_name].value_at(cpos)
        wanted = constraint.right.value

        if constraint.right.flags:
            raise FlexiExecutionError(
                "Regex flags in cwb-cql are not yet supported by the first flexi backend subset."
            )

        has_regex_meta = bool(re.search(r"[.^$*+?{}\[\]|()]", wanted))
        if has_regex_meta:
            ok = re.fullmatch(wanted, value) is not None
        else:
            ok = value == wanted
        return ok if constraint.op == "=" else (not ok)

    def _match_sequence(self, parsed: CwbQuery, corpus: _CwbCorpusReader, start_cpos: int) -> bool:
        if not isinstance(parsed.pattern, SequencePattern):
            raise FlexiExecutionError("Only simple token sequences are supported in the first flexi subset.")

        end_cpos = start_cpos + len(parsed.pattern.items) - 1
        if end_cpos >= corpus.positional["word"].token_count:
            return False

        if parsed.within:
            struct_reader = corpus.structural.get(parsed.within.scope)
            if struct_reader is None:
                raise FlexiExecutionError(
                    f"Structural scope '{parsed.within.scope}' is not available in the current CWB corpus."
                )
            start_idx = struct_reader.region_index_at(start_cpos)
            end_idx = struct_reader.region_index_at(end_cpos)
            if start_idx is None or end_idx is None or start_idx != end_idx:
                return False

        for offset, token in enumerate(parsed.pattern.items):
            if not self._match_constraint(parsed, token, corpus, start_cpos + offset):
                return False
        return True

    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        corpus_format = str(project.get("format") or "cwb").strip().lower()
        if corpus_format == "cwb":
            cfg = self._get_config(req)
            corpus = self._load_corpus(cfg, project)
            try:
                tokens_count = corpus.positional["word"].token_count
                return {
                    "backend": self.name,
                    "file_format": corpus_format,
                    "corpus_home": str(corpus.home),
                    "tokens_count": tokens_count,
                    "struct_attributes": sorted(corpus.structural.keys()),
                    "pattributes": sorted(corpus.positional.keys()),
                }
            finally:
                corpus.close()
        if corpus_format == "manatee":
            manatee_cfg = self._get_manatee_config(project)
            summary = self._read_manatee_registry_summary(manatee_cfg)
            return {
                "backend": self.name,
                "file_format": corpus_format,
                "registry": manatee_cfg.registry,
                "corpus": manatee_cfg.corpus,
                "registry_file": str(summary["registry_file"]),
                "data_path": str(summary["data_path"]) if summary["data_path"] is not None else None,
                "data_path_exists": bool(summary["data_path"] and Path(summary["data_path"]).exists()),
                "resolved_data_path": (
                    str(summary["resolved_data_path"]) if summary["resolved_data_path"] is not None else None
                ),
                "resolved_data_path_exists": bool(
                    summary["resolved_data_path"] and Path(summary["resolved_data_path"]).exists()
                ),
                "struct_attributes": sorted(summary["sattributes"]),
                "pattributes": sorted(summary["pattributes"]),
                "native_pattributes": sorted(summary["native_pattributes"]),
                "native_structures": sorted(summary["native_structures"]),
            }
        raise RuntimeError(
            f"flexi status currently only supports corpus formats 'cwb' and 'manatee'. Got {corpus_format!r}."
        )

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        corpus_format = str(project.get("format") or "cwb").strip().lower()
        if corpus_format not in {"cwb", "manatee"}:
            raise RuntimeError(
                f"flexi list_docs currently only supports corpus formats 'cwb' and 'manatee'. Got {corpus_format!r}."
            )

        # For CWB-backed corpora, reuse the mature CQP list_docs implementation so
        # flexi and cqp expose the same document metadata view derived from CWB.
        if corpus_format == "cwb":
            return super().list_docs(req)

        # For Manatee-backed corpora, derive documents from the Manatee index
        # itself using the registry summary (text_signatures).
        params = dict(req.get("params") or {})
        limit = int(params.get("limit", 50))
        offset = int(params.get("offset", 0))
        filter_text = str(params.get("filter") or "").strip().lower()

        manatee_cfg = self._get_manatee_config(project)
        summary = self._read_manatee_registry_summary(manatee_cfg)
        text_sigs = summary.get("text_signatures") or {}

        docs = []
        for text_id, meta in text_sigs.items():
            doc_id = str(text_id)
            title = str(getattr(meta, "title", "") or meta.get("title") if isinstance(meta, dict) else "") or doc_id
            # Build a simple haystack for filtering: id, title and all meta fields if dict-like
            if filter_text:
                parts = [doc_id, title]
                if isinstance(meta, dict):
                    for v in meta.values():
                        parts.append(str(v))
                haystack = " ".join(parts).lower()
                if filter_text not in haystack:
                    continue
            doc_entry: Dict[str, Any] = {"id": doc_id, "title": title}
            if isinstance(meta, dict):
                doc_entry["meta"] = meta
            docs.append(doc_entry)

        total = len(docs)
        sliced = docs[offset : offset + limit]
        return {"docs": sliced, "total": total}

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        corpus_format = str(project.get("format") or "cwb").strip().lower()
        if corpus_format == "cwb":
            cfg = self._get_config(req)
            corpus = self._load_corpus(cfg, project)
            try:
                return {
                    "backend": self.name,
                    "descriptor": self.descriptor(),
                    "file_format": corpus_format,
                    "corpus": cfg.corpus,
                    "registry": cfg.registry,
                    "corpus_home": str(corpus.home),
                    "pattributes": sorted(corpus.positional.keys()),
                    "sattributes": sorted(corpus.structural.keys()),
                    "tokens_count": corpus.positional["word"].token_count,
                }
            finally:
                corpus.close()
        if corpus_format == "manatee":
            manatee_cfg = self._get_manatee_config(project)
            summary = self._read_manatee_registry_summary(manatee_cfg)
            return {
                "backend": self.name,
                "descriptor": self.descriptor(),
                "file_format": corpus_format,
                "corpus": manatee_cfg.corpus,
                "registry": manatee_cfg.registry,
                "registry_file": str(summary["registry_file"]),
                "data_path": str(summary["data_path"]) if summary["data_path"] is not None else None,
                "resolved_data_path": (
                    str(summary["resolved_data_path"]) if summary["resolved_data_path"] is not None else None
                ),
                "pattributes": sorted(summary["pattributes"]),
                "sattributes": sorted(summary["sattributes"]),
                "native_pattributes": sorted(summary["native_pattributes"]),
                "native_structures": sorted(summary["native_structures"]),
                "text_signatures": dict(summary["text_signatures"]),
            }
        raise RuntimeError(
            f"flexi info currently only supports corpus formats 'cwb' and 'manatee'. Got {corpus_format!r}."
        )

    def query(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        corpus_format = str(project.get("format") or "cwb").strip().lower()
        query_text = str(params.get("query") or "").strip()
        if not query_text:
            raise RuntimeError("flexi query requires params['query'] to be a non-empty cwb-cql string.")

        query_lang = str(params.get("query_language") or params.get("query_lang") or "cwb-cql")
        if query_lang not in {"cwb-cql", "cwb", "cql", "manatee-cql", "manatee"}:
            raise RuntimeError(
                "flexi currently only supports CWB/IMS CQL (query_language='cwb-cql') "
                "and Manatee CQL (query_language='manatee-cql'). "
                f"Got {query_lang!r}."
            )

        parsed = parse_cwb_cql(query_text)
        start = max(0, int(params.get("start", 0)))
        max_hits = max(0, min(int(params.get("max", 50)), 5000))
        context_spec = self._normalize_context_request(params)
        if corpus_format == "cwb":
            cfg = self._get_config(req)
            detected = self._detect_teitok(cfg, project)
            result = self._query_cwb_native(
                cfg=cfg,
                project=project,
                parsed=parsed,
                query_lang=query_lang,
                start=start,
                max_hits=max_hits,
                context_spec=context_spec,
                detected=detected,
            )
        elif corpus_format == "manatee":
            cfg = self._get_optional_cqp_config(project)
            detected = self._detect_teitok(cfg, project) if cfg is not None else None
            manatee_cfg = self._get_manatee_config(project)
            try:
                result = self._query_manatee_native_files(
                    manatee_cfg=manatee_cfg,
                    parsed=parsed,
                    query_lang=query_lang,
                    start=start,
                    max_hits=max_hits,
                )
            except (FlexiExecutionError, ManateeFormatError):
                # Fall back to Manatee bindings if native path fails (e.g. "within", or unsupported)
                result = self._query_manatee_native(
                    cfg=cfg,
                    project=project,
                    parsed=parsed,
                    query_lang=query_lang,
                    start=start,
                    max_hits=max_hits,
                    context_spec=context_spec,
                    detected=detected,
                )
        else:
            raise RuntimeError(
                f"flexi query currently only supports corpus formats 'cwb' and 'manatee'. Got {corpus_format!r}."
            )
        result["legend"] = resolve_legend(params)
        return result

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        raise NotImplementedError("Reindex is not implemented for the flexi backend.")


register_backend(FlexiBackend())

