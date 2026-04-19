from __future__ import annotations

from dataclasses import dataclass
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
from typing import Any, Dict, List, Optional
import xml.etree.ElementTree as ET

from ..config import CqpConfig, ManateeConfig, get_cqp_config, get_manatee_config
from ..core import CorpusBackend, FlexiRequest, register_backend
from ..highlight_contract import build_highlight_map, resolve_legend
from ..flexencoder_xidx import has_flexencoder_xidx  # text_id_stem_for_cpos intentionally NOT imported: uses xidx cpos which does not match Manatee cpos (see docs/manatee_xml_context_fix.md INV-1).
from ..teitok import detect_teitok_cqp, detect_teitok_manatee
from ..teitok_context import normalize_context_request, resolve_teitok_context
from .cqp import CqpBackend
from .manatee import (
    ManateeFormatError,
    decode_forward_text_ids,
    text_id_from_cwb_style_index_files,
    load_manatee_bindings,
    load_manatee_corpus_scaffold,
    prepare_runtime_registry,
)


class ManateeBackendError(RuntimeError):
    pass


_TAG_WITH_ATTR_RE = re.compile(r"^<([^ _>]+)_([^ >]+) (.*)>$")
_TAG_ONLY_RE = re.compile(r"^<([^ _>/]+)>$")



def _decode_text(value: Any) -> Optional[str]:
    if value is None:
        return None
    if isinstance(value, bytes):
        for encoding in ("utf-8", "latin-1"):
            try:
                return value.decode(encoding)
            except Exception:
                continue
        return value.decode("utf-8", errors="replace")
    return str(value)


def _split_conf_list(value: Any) -> List[str]:
    text = (_decode_text(value) or "").strip()
    if not text:
        return []
    return [part.strip() for part in text.split(",") if part.strip()]


@dataclass
class ManateeBackend(CorpusBackend):
    name: str = "manatee"

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "manatee",
            "supported_query_languages": ["manatee-cql", "manatee", "cql"],
            "supported_corpus_formats": ["manatee"],
            "default_query_language": "manatee-cql",
            "default_corpus_format": "manatee",
            "default_selection_reason": "Direct backend over the Python Manatee bindings.",
        }

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": False,
            "freq": False,
            "stats_freq_pattributes": False,
            "stats_freq_sattributes": False,
            "stats_relative_freq": False,
            "stats_collocations": False,
            "stats_dep_collocations": False,
            "stats_keyness": False,
            "stats_table_result": False,
            "info": True,
            "reindex": True,
            "raw_query": False,
            "query": True,
        }

    def _get_config(self, project: Dict[str, Any]) -> ManateeConfig:
        cfg = get_manatee_config(project)
        if cfg is not None:
            return cfg
        root = project.get("root")
        if root:
            detected = detect_teitok_manatee(Path(str(root)).expanduser().resolve())
            if detected:
                inferred = get_manatee_config(detected)
                if inferred is not None:
                    return inferred
        raise ManateeBackendError(
            "Could not determine Manatee configuration. "
            "Provide project.manatee.registry and project.manatee.corpus, or run from a TEITOK project with a local manatee/ directory."
        )

    @staticmethod
    def _load_manatee_module(project_root: Path | None = None) -> Any:
        try:
            return load_manatee_bindings(project_root=project_root)
        except Exception as exc:
            raise ManateeBackendError(str(exc)) from exc

    def _open_corpus(self, cfg: ManateeConfig) -> Any:
        try:
            runtime = prepare_runtime_registry(cfg)
        except ManateeFormatError as exc:
            raise ManateeBackendError(str(exc)) from exc
        manatee = self._load_manatee_module(Path(cfg.registry).expanduser().resolve().parent)
        # Use the patched runtime registry file directly so we can control PATH and
        # per-attribute TYPE (e.g. force FD_MI/int_text so no *.text.seg is required).
        registry_file = runtime.runtime_registry_dir / cfg.corpus
        return manatee.Corpus(str(registry_file))

    @staticmethod
    def _safe_get_conf(corpus: Any, key: str) -> Optional[str]:
        try:
            return _decode_text(corpus.get_conf(key))
        except Exception:
            return None

    @staticmethod
    def _safe_get_struct(corpus: Any, name: str) -> Any | None:
        try:
            return corpus.get_struct(name)
        except Exception:
            return None

    @staticmethod
    def _safe_get_struct_attr(struct_obj: Any, name: str) -> Any | None:
        if struct_obj is None:
            return None
        try:
            return struct_obj.get_attr(name)
        except Exception:
            return None

    @staticmethod
    def _safe_get_pos_attr(corpus: Any, name: str) -> Any | None:
        try:
            return corpus.get_attr(name)
        except Exception:
            return None

    @staticmethod
    def _min_pos_limit(*limits: Optional[int]) -> Optional[int]:
        vals = [v for v in limits if v is not None]
        return min(vals) if vals else None

    @staticmethod
    def _corpus_max_token_index(corpus: Any) -> Optional[int]:
        """Last valid global token index (min of ``size`` and ``search_size`` when both exist)."""
        try:
            upper: List[int] = []
            if hasattr(corpus, "search_size"):
                ss = int(corpus.search_size())
                if ss > 0:
                    upper.append(ss - 1)
            if hasattr(corpus, "size"):
                n = int(corpus.size())
                if n > 0:
                    upper.append(n - 1)
            if upper:
                return min(upper)
        except Exception:
            pass
        return None

    @staticmethod
    def _positional_attr_max_pos(attr: Any, corpus: Any) -> Optional[int]:
        """
        Positional attributes can be shorter than ``corpus.size()``; ``pos2str`` may segfault
        past ``attr.size() - 1`` even when the index is valid for the concordance.
        """
        try:
            if attr is not None and hasattr(attr, "size"):
                ns = int(attr.size())
                if ns > 0:
                    return max(0, ns - 1)
        except Exception:
            pass
        return ManateeBackend._corpus_max_token_index(corpus)

    @staticmethod
    def _struct_beg_containing(struct_obj: Any, pos: int) -> Optional[int]:
        """
        Return ``beg`` of the struct instance that contains token position ``pos``,
        or None. Struct ``id`` attributes must be read at region starts; passing an
        arbitrary token index can crash the Manatee extension.

        IMPORTANT — Manatee native API (see git/manatee-open-*/api/manatee.py,
        class Structure):
          * ``num_at_pos(pos)`` — returns struct instance index containing ``pos``
            (this is what Kontext uses: see kontext/lib/views/concordance.py).
          * ``beg(n)`` / ``end(n)`` — position bounds of instance ``n``. ``end`` is
            **EXCLUSIVE** (Kontext computes right context as ``end - pos - 1``).

        The previous implementation called ``struct_obj.num_at(pos)`` — that method
        does NOT exist in Manatee; it always raised and fell through to the linear
        scan, which additionally used ``beg <= pos <= end`` (wrong: treats the
        exclusive ``end`` as inclusive). The combination mis-resolved sentence/
        document ids at region boundaries (off-by-one sentence; wrong doc_id at
        doc edges). Do not "simplify" this back.
        """
        if struct_obj is None:
            return None
        # Preferred path: Manatee's own index lookup (O(log n) internally).
        try:
            num_at_pos = getattr(struct_obj, "num_at_pos", None)
            if callable(num_at_pos):
                ni = int(num_at_pos(pos))
                if ni >= 0:
                    return int(struct_obj.beg(ni))
        except Exception:
            pass
        # Compatibility fallback: some older/alt builds expose ``num_at``.
        try:
            num_at = getattr(struct_obj, "num_at", None)
            if callable(num_at):
                ni = int(num_at(pos))
                if ni >= 0:
                    return int(struct_obj.beg(ni))
        except Exception:
            pass
        # Last resort: binary search over beg/end. ``end`` is exclusive.
        try:
            n = int(struct_obj.size())
            if n <= 0:
                return None
            lo, hi = 0, n - 1
            while lo <= hi:
                mid = (lo + hi) // 2
                beg = int(struct_obj.beg(mid))
                end = int(struct_obj.end(mid))
                if pos < beg:
                    hi = mid - 1
                elif pos >= end:  # EXCLUSIVE end — do not change to ``pos > end``.
                    lo = mid + 1
                else:
                    return beg
        except Exception:
            return None
        return None

    @staticmethod
    def _safe_pos2str(attr: Any, pos: int, *, max_pos: Optional[int] = None) -> Optional[str]:
        if attr is None:
            return None
        if max_pos is not None and (pos < 0 or pos > max_pos):
            return None
        try:
            return _decode_text(attr.pos2str(pos))
        except Exception:
            return None

    @staticmethod
    def _tok_ids_from_fragment_by_surface_match(
        fragment_xml: str, expected_tokens: List[str]
    ) -> List[str]:
        """
        When lexicon ``id`` is unavailable, Manatee ``toks`` are surface strings. Match those
        to ``<tok xml:id>`` / ``<dtok>`` in XML fragment text and return the real ids for
        highlight_map / jmp=.
        """
        if not fragment_xml or not expected_tokens:
            return []
        exp = [str(t).strip() for t in expected_tokens if str(t).strip()]
        if not exp:
            return []
        try:
            root = ET.fromstring(fragment_xml)
        except ET.ParseError:
            return []
        flat: List[tuple[str, str]] = []
        for elem in root.iter():
            local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
            if local_tag not in {"tok", "dtok"}:
                continue
            tid = elem.get("id") or elem.get(
                "{http://www.w3.org/XML/1998/namespace}id"
            )
            text = "".join(elem.itertext()).strip()
            if tid:
                flat.append((str(tid), text))
        if len(exp) == 1:
            for tid, tx in flat:
                if tx == exp[0]:
                    return [tid]
            return []
        n = len(exp)
        if len(flat) < n:
            return []
        for i in range(0, len(flat) - n + 1):
            window = [flat[i + j][1] for j in range(n)]
            if window == exp:
                return [flat[i + j][0] for j in range(n)]
        return []

    @staticmethod
    def _tok_ids_from_sentence_fragment_by_offset(
        fragment_xml: str,
        *,
        token_offset_start: int,
        token_offset_end: int,
        expected_tokens: Optional[List[str]] = None,
    ) -> List[str]:
        """
        Extract token ids from an XML sentence fragment by token-index offsets.

        Offsets are relative to sentence start cpos. This lets us recover exact matched
        token ids even when Manatee positional attribute ``id`` is missing or malformed.
        """
        if token_offset_start < 0 or token_offset_end < token_offset_start:
            return []
        try:
            root = ET.fromstring(fragment_xml)
        except ET.ParseError:
            return []

        tok_ids_all: List[str] = []
        tok_text_all: List[str] = []
        for elem in root.iter():
            local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
            if local_tag not in {"tok", "dtok"}:
                continue
            tok_id = elem.get("id") or elem.get("{http://www.w3.org/XML/1998/namespace}id")
            if tok_id:
                tok_ids_all.append(str(tok_id))
            else:
                tok_ids_all.append("")
            tok_text_all.append("".join(elem.itertext()).strip())

        if not tok_ids_all:
            return []

        def _slice_ids(start_idx: int, end_idx: int) -> List[str]:
            out: List[str] = []
            for i in range(start_idx, min(end_idx + 1, len(tok_ids_all))):
                tid = tok_ids_all[i]
                if tid:
                    out.append(tid)
            return out

        # 1) Primary anchor: sentence-relative cpos offsets.
        if 0 <= token_offset_start < len(tok_ids_all):
            by_offset = _slice_ids(token_offset_start, token_offset_end)
        else:
            by_offset = []

        # 2) Validation/recovery: if expected surface tokens are known and offset points to
        # wrong token(s), locate the expected sequence in the XML sentence and pick nearest hit.
        exp = [str(t).strip() for t in (expected_tokens or []) if str(t).strip()]
        if exp and len(exp) <= len(tok_text_all):
            def _window_matches(i: int) -> bool:
                if i < 0 or i + len(exp) > len(tok_text_all):
                    return False
                for j, et in enumerate(exp):
                    if tok_text_all[i + j] != et:
                        return False
                return True

            if not _window_matches(token_offset_start):
                candidates = [i for i in range(0, len(tok_text_all) - len(exp) + 1) if _window_matches(i)]
                if candidates:
                    best = min(candidates, key=lambda i: abs(i - token_offset_start))
                    return _slice_ids(best, best + len(exp) - 1)

        return by_offset

    @staticmethod
    def _manatee_concordance_corpus(conc: Any, fallback: Any) -> Any:
        """
        KonText passes ``conc.corp()`` into ``KWICLines``, not the original ``Corpus`` handle
        (see ``lib/kwiclib/__init__.py`` — parallel corpora and subcorpora differ).
        """
        if conc is None:
            return fallback
        try:
            fn = getattr(conc, "corp", None)
            if callable(fn):
                c = fn()
                if c is not None:
                    return c
        except Exception:
            pass
        return fallback

    @staticmethod
    def _concordance_spans_via_cpos(conc: Any, start: int, end: int) -> Optional[List[tuple[int, int]]]:
        """
        Return per-result match spans ``(match_start, match_end_inclusive)`` for
        concordance line indices ``[start, end)``, or ``None`` to signal the
        caller to fall back to ``KWICLines``.

        Native Manatee API (see git/manatee-open-*/api/manatee.py, class
        Concordance):

          * ``beg_at(i)`` — start cpos of match line ``i``.
          * ``end_at(i)`` — end cpos of match line ``i``, **EXCLUSIVE**.

        There is no ``cpos`` / ``get_cpos`` on Manatee Concordance — earlier
        versions of this function searched for those names and always returned
        ``None``, forcing every query through ``KWICLines.nextline()``. That
        path computes ``match_end = match_start + kwiclen - 1`` from rendered
        token lengths, which can drift relative to the true end cpos and is
        also segfault-prone on some ``_manatee`` builds.

        We convert Manatee's exclusive ``end_at`` to our inclusive ``match_end``
        by subtracting 1, matching the convention used downstream in
        ``query()`` and the rest of FlexiCorp's hit shape.
        """
        if start >= end:
            return []
        beg_fn = getattr(conc, "beg_at", None)
        end_fn = getattr(conc, "end_at", None)
        if not callable(beg_fn) or not callable(end_fn):
            # Surface as None so the caller can fall back to KWICLines on
            # ancient builds. Do NOT reintroduce a probe for "cpos"/"get_cpos"
            # — those names do not exist in Manatee and probing them historically
            # masked real bugs.
            return None
        spans: List[tuple[int, int]] = []
        try:
            for i in range(start, end):
                ms = int(beg_fn(i))
                me_excl = int(end_fn(i))
                # Convert exclusive → inclusive. Empty/zero-length matches
                # collapse to a single-token span at ``ms``.
                me = me_excl - 1 if me_excl > ms else ms
                spans.append((ms, me))
            return spans
        except Exception:
            return None

    @staticmethod
    def _pick_token_attr_for_query(corpus: Any, file_scaffold: Any | None) -> tuple[str | None, Any]:
        """
        Prefer an attribute that has on-disk ``.text`` / ``.lex`` data so we can build
        KWIC without calling the Manatee extension's ``pos2str`` (which can segfault).
        """
        names = ("word", "form", "lemma", "id")
        if file_scaffold is not None:
            pos_map = getattr(file_scaffold, "positional", None) or {}
            for name in names:
                if name not in pos_map:
                    continue
                a = ManateeBackend._safe_get_pos_attr(corpus, name)
                if a is not None:
                    return name, a
            for name in sorted(pos_map.keys()):
                a = ManateeBackend._safe_get_pos_attr(corpus, name)
                if a is not None:
                    return name, a
        for name in names:
            a = ManateeBackend._safe_get_pos_attr(corpus, name)
            if a is not None:
                return name, a
        return None, None

    @staticmethod
    def _tokens_from_lexicon_files(
        file_scaffold: Any | None,
        attr_name: str | None,
        ranges: List[tuple[int, int]],
    ) -> Optional[List[List[str]]]:
        """
        Decode KWIC token strings from ``<attr>.text`` + ``<attr>.lex`` (pure Python),
        avoiding ``pos2str`` for each token.
        """
        if not attr_name or file_scaffold is None:
            return None
        if not ranges:
            return []
        pos_map = getattr(file_scaffold, "positional", None)
        if not isinstance(pos_map, dict) or attr_name not in pos_map:
            return None
        try:
            af = pos_map[attr_name]
            text_path = af.text.text_path
            max_end = max(b for _, b in ranges)
            ids = decode_forward_text_ids(text_path, max_end)
            lex = af.lexicon
            out: List[List[str]] = []
            for start, end in ranges:
                row: List[str] = []
                for pos in range(start, end + 1):
                    if pos < 0 or pos >= len(ids):
                        row.append("")
                        continue
                    try:
                        row.append(lex.value_for_id(ids[pos]))
                    except ManateeFormatError:
                        row.append("")
                out.append(row)
            return out
        except Exception:
            # Do not fall back to native pos2str for this attribute (often segfaults).
            try:
                return [[""] * (b - a + 1) for a, b in ranges]
            except Exception:
                return None

    def _doc_structure_name(self, corpus: Any) -> Optional[str]:
        doc_struct = (self._safe_get_conf(corpus, "DOCSTRUCTURE") or "").strip()
        structs = set(_split_conf_list(self._safe_get_conf(corpus, "STRUCTLIST")))
        if doc_struct and doc_struct in structs:
            return doc_struct
        for candidate in ("text", "doc"):
            if candidate in structs:
                return candidate
        return doc_struct or None

    def _doc_lookup(self, corpus: Any) -> tuple[Any | None, Any | None, Any | None]:
        doc_struct_name = self._doc_structure_name(corpus)
        doc_struct = self._safe_get_struct(corpus, doc_struct_name) if doc_struct_name else None
        doc_id_attr = self._safe_get_struct_attr(doc_struct, "id")
        title_attr = self._safe_get_struct_attr(doc_struct, "title")
        if title_attr is None:
            title_attr = self._safe_get_struct_attr(doc_struct, "name")
        return doc_struct, doc_id_attr, title_attr

    def _sentence_id_attr(self, corpus: Any) -> Any | None:
        sent_struct = self._safe_get_struct(corpus, "s")
        return self._safe_get_struct_attr(sent_struct, "id")

    def _detect_teitok(self, project: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        root = project.get("root")
        if not root:
            return None
        return detect_teitok_manatee(Path(str(root)).expanduser().resolve())

    @staticmethod
    def _find_executable(name: str, extra_candidates: Optional[List[str]] = None) -> str:
        if "/" in name:
            path = Path(name).expanduser()
            if path.is_file() and os.access(path, os.X_OK):
                return str(path)
            raise ManateeBackendError(f"Executable not found or not executable: '{name}'")
        resolved = shutil.which(name)
        if resolved:
            return resolved
        for candidate in extra_candidates or []:
            path = Path(candidate).expanduser()
            if path.is_file() and os.access(path, os.X_OK):
                return str(path)
        raise ManateeBackendError(
            f"Required executable '{name}' was not found. "
            "Install it or pass an explicit path via backend configuration/override."
        )

    @staticmethod
    def _normalize_manatee_corpus_name(value: str) -> str:
        text = str(value or "").strip().lower()
        text = re.sub(r"[-\s]+", "_", text)
        text = re.sub(r"[^a-z0-9_]+", "_", text)
        text = re.sub(r"_+", "_", text).strip("_")
        return text or "manatee_corpus"

    @staticmethod
    def _teitok_title_and_base_url(settings_path: Path, root_dir: Path) -> tuple[str, str]:
        title = root_dir.name
        baseurl = ""
        try:
            xml_root = ET.parse(settings_path).getroot()
        except Exception:
            return title, baseurl
        title_node = xml_root.find(".//defaults/title")
        if title_node is not None:
            title = str(title_node.get("display") or title_node.text or title).strip() or title
        base_node = xml_root.find(".//defaults/base")
        if base_node is not None:
            baseurl = str(base_node.get("url") or "").strip()
            folder = root_dir.name
            if "{%corpusfolder%}" in baseurl:
                baseurl = baseurl.replace("{%corpusfolder%}", folder)
        return title, baseurl

    @staticmethod
    def _baseurl_parts(baseurl: str) -> tuple[str, str]:
        text = str(baseurl or "").strip()
        if not text:
            return "", ""
        m = re.match(r"^https?://([^/]+)(/.*)?$", text)
        if not m:
            return "", ""
        return m.group(1) or "", m.group(2) or "/"

    @staticmethod
    def _build_manatee_registry_text(
        *,
        corpus_name: str,
        title: str,
        vrt_path: Path,
        corp_path: Path,
        pattributes: List[str],
        sattributes_by_region: Dict[str, List[str]],
    ) -> str:
        lines: List[str] = [
            f'NAME "{title}"',
            f'PATH "{corp_path}"',
            "ENCODING utf-8",
            f'VERTICAL "{vrt_path}"',
            "",
            "ATTRIBUTE word",
            "ATTRIBUTE lc {",
            "        DYNAMIC utf8lowercase",
            "        DYNLIB internal",
            "        FUNTYPE s",
            "        FROMATTR word",
            "        TYPE index",
            "        TRANSQUERY yes",
            "}",
            "ATTRIBUTE id",
        ]
        seen = {"word", "id"}
        for attr in pattributes:
            key = str(attr or "").strip()
            if not key or key in seen:
                continue
            seen.add(key)
            lines.append(f"ATTRIBUTE {key.lower()}")
        lines.extend(
            [
                "",
                "DOCSTRUCTURE text",
                "",
                "STRUCTURE crp {",
                "        ATTRIBUTE server",
                "        ATTRIBUTE path",
                "}",
                "",
            ]
        )
        for region, attrs in sattributes_by_region.items():
            region_name = str(region or "").strip()
            if not region_name:
                continue
            lines.append(f"STRUCTURE {region_name} {{")
            for attr in attrs:
                attr_name = str(attr or "").strip()
                if attr_name:
                    lines.append(f"        ATTRIBUTE {attr_name.lower()}")
            if region_name == "text":
                lines.append("        ATTRIBUTE id")
            lines.append("}")
            lines.append("")
        return "\n".join(lines).rstrip() + "\n"

    @staticmethod
    def _clean_cwb_decode_to_vrt(
        source_lines: Any,
        output_path: Path,
        *,
        server: str,
        path: str,
    ) -> None:
        pending_tag = ""
        pending_attrs = ""
        with output_path.open("w", encoding="utf-8") as out:
            out.write(f'<crp server="{server}" path="{path}">\n')
            for raw in source_lines:
                line = raw.rstrip("\n")
                if line.startswith("<?"):
                    continue
                if re.match(r"^</?corpus[ >]", line):
                    continue
                if re.match(r"^</[^>]+_", line):
                    continue
                if line.startswith("</"):
                    if pending_tag:
                        out.write(f"<{pending_tag}{pending_attrs}>\n")
                        pending_tag = ""
                        pending_attrs = ""
                    out.write(line + "\n")
                    continue
                m = _TAG_WITH_ATTR_RE.match(line)
                if m:
                    tag, attr_name, raw_value = m.groups()
                    if tag != pending_tag:
                        raise ManateeBackendError(
                            f"Unexpected CWB decode structure-attribute order while building Manatee VRT: {line}"
                        )
                    value = raw_value
                    if tag == "text" and attr_name == "id":
                        value = re.sub(r"\..*$", "", value)
                        value = re.sub(r"^.*\/", "", value)
                    pending_attrs += f' {attr_name}="{value}"'
                    continue
                m = _TAG_ONLY_RE.match(line)
                if m:
                    if pending_tag:
                        out.write(f"<{pending_tag}{pending_attrs}>\n")
                    pending_tag = m.group(1)
                    pending_attrs = ""
                    continue
                if pending_tag:
                    out.write(f"<{pending_tag}{pending_attrs}>\n")
                    pending_tag = ""
                    pending_attrs = ""
                out.write(line + "\n")
            if pending_tag:
                out.write(f"<{pending_tag}{pending_attrs}>\n")
            out.write("</crp>\n")

    def _source_cqp_config(self, req: FlexiRequest) -> tuple[CqpConfig, Dict[str, Any]]:
        project = dict(req.get("project") or {})
        root = Path(str(project.get("root") or ".")).expanduser().resolve()
        detected = detect_teitok_cqp(root)
        if not detected:
            raise ManateeBackendError(
                "Manatee CWB-first reindex currently requires a TEITOK project with CQP settings."
            )
        cqp_project = dict(project)
        detected_cqp = dict(detected.get("cqp") or {})
        cqp_cfg_section = dict(cqp_project.get("cqp") or {})
        merged_cqp = dict(detected_cqp)
        merged_cqp.update(cqp_cfg_section)
        cqp_project["cqp"] = merged_cqp
        cfg = get_cqp_config(cqp_project)
        if cfg is None:
            raise ManateeBackendError("Could not determine source CQP configuration for Manatee reindex.")
        # Keep compatibility with CQP backend internals across refactors.
        # Older code used `_maybe_patch_registry_home`; current code exposes
        # `_prepare_runtime_registry` for the same purpose.
        cqp_backend = CqpBackend()
        if hasattr(cqp_backend, "_maybe_patch_registry_home"):
            cfg = cqp_backend._maybe_patch_registry_home(cfg, cqp_project, debug=False)  # type: ignore[attr-defined]
        elif hasattr(cqp_backend, "_prepare_runtime_registry"):
            cfg = cqp_backend._prepare_runtime_registry(cfg, cqp_project, debug=False)  # type: ignore[attr-defined]
        return cfg, detected

    def _target_manatee_config(self, req: FlexiRequest, detected_cqp: Dict[str, Any]) -> ManateeConfig:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        cfg = get_manatee_config(project)
        if cfg is not None:
            return cfg
        root = Path(str(project.get("root") or detected_cqp.get("root") or ".")).expanduser().resolve()
        detected = detect_teitok_manatee(root)
        if detected:
            inferred = get_manatee_config(detected)
            if inferred is not None:
                return inferred
        cqp_corpus = str((detected_cqp.get("cqp") or {}).get("corpus") or "").strip()
        corpus_name = str(params.get("manatee_corpus") or self._normalize_manatee_corpus_name(cqp_corpus or root.name)).strip()
        registry_dir = str(params.get("manatee_registry") or (root / "manatee"))
        return ManateeConfig(registry=registry_dir, corpus=corpus_name)

    def _manatee_tools_path(self, project: Dict[str, Any], params: Dict[str, Any]) -> Optional[Path]:
        """Resolve path to Manatee build (src + api) for encodevert, corpinfo, mkstats, mktokencov, mksizes."""
        for candidate in (
            params.get("manatee_src"),
            params.get("manatee_tools_path"),
            (project.get("manatee") or {}).get("tools_path"),
            (project.get("manatee") or {}).get("src_path"),
            os.environ.get("MANATEE_SRC"),
        ):
            if not candidate:
                continue
            p = Path(str(candidate)).expanduser().resolve()
            if p.is_dir() and (
                (p / "src" / "encodevert").exists() or (p / "src" / "encodevert.exe").exists()
            ):
                return p
            if p.is_dir() and (p / "encodevert").exists():
                return p.parent  # p is src/
        # Fallback: walk up from this file looking for a flexicorp checkout
        # that contains ``git/manatee-open*/src/encodevert``. Historically this
        # used a hard-coded ``parents[3]`` which was off-by-one (it climbed one
        # level above the repo root to ``/Users/<you>/programming/``, so the
        # user-visible error ``encodevert not found. Set MANATEE_SRC…`` fired
        # on hosts where encodevert was sitting right inside the checkout
        # under ``git/manatee-open-2.225.8/src/encodevert``).
        #
        # The scan below is structural: it looks for any parent directory
        # whose ``git/`` child has a ``manatee-open*`` subdir with the expected
        # binary, and returns the first hit. No fragile index counting.
        try:
            here = Path(__file__).resolve()
            for ancestor in here.parents:
                git_dir = ancestor / "git"
                if not git_dir.is_dir():
                    continue
                try:
                    candidates = sorted(git_dir.glob("manatee-open*"))
                except OSError:
                    continue
                for manatee_dir in candidates:
                    if not manatee_dir.is_dir():
                        continue
                    src = manatee_dir / "src"
                    if (src / "encodevert").exists() or (src / "encodevert.exe").exists():
                        return manatee_dir
        except Exception:
            pass
        return None

    def _run_manatee_compile(
        self,
        *,
        registry_dir: Path,
        corpus_name: str,
        vrt_path: Path,
        pattributes: List[str],
        project: Dict[str, Any],
        params: Dict[str, Any],
        env: Dict[str, str],
        verbose: bool,
        prefix: str,
    ) -> None:
        """Run encodevert, mkstats, mktokencov, mksizes to build Manatee corp/ from VRT (no compilecorp)."""
        try:
            step_timeout_sec = int(params.get("manatee_step_timeout_sec", 900))
        except Exception:
            step_timeout_sec = 900
        if step_timeout_sec <= 0:
            step_timeout_sec = 900
        tools_path = self._manatee_tools_path(project, params)
        if not tools_path and env.get("MANATEE_SRC"):
            tools_path = Path(env["MANATEE_SRC"]).expanduser().resolve()
            if not tools_path.is_dir():
                tools_path = None
        if tools_path is not None:
            env = dict(env)
            env["MANATEE_SRC"] = str(tools_path)
        path_prepend: List[Path] = []
        if tools_path is not None:
            src_dir = tools_path / "src"
            api_dir = tools_path / "api"
            if src_dir.is_dir():
                path_prepend.append(src_dir)
            if api_dir.is_dir():
                path_prepend.append(api_dir)
        path_str = os.pathsep.join(str(p) for p in path_prepend)
        run_env = dict(env)
        if path_str:
            run_env["PATH"] = path_str + os.pathsep + run_env.get("PATH", os.environ.get("PATH", ""))
        if tools_path is not None and "PYTHONPATH" not in run_env:
            api = tools_path / "api"
            libs = api / ".libs"
            parts = [str(libs)] if libs.is_dir() else []
            parts.append(str(api))
            py_path = os.pathsep.join(parts)
            if run_env.get("PYTHONPATH"):
                py_path = py_path + os.pathsep + run_env["PYTHONPATH"]
            run_env["PYTHONPATH"] = py_path

        encodevert_bin = shutil.which("encodevert", path=run_env.get("PATH"))
        if not encodevert_bin:
            raise ManateeBackendError(
                "encodevert not found. Set MANATEE_SRC (or project.manatee.tools_path) to the Manatee build directory, "
                "or put encodevert on PATH."
            )
        self._run_logged_command(
            [encodevert_bin, "-m", "0", "-c", corpus_name, str(vrt_path)],
            cwd=registry_dir,
            verbose=verbose,
            prefix=prefix,
            env=run_env,
            timeout_sec=step_timeout_sec,
        )
        corpinfo_bin = shutil.which("corpinfo", path=run_env.get("PATH"))
        if corpinfo_bin:
            try:
                result = subprocess.run(
                    [corpinfo_bin, "-g", "ATTRLIST", corpus_name],
                    cwd=str(registry_dir),
                    env=run_env,
                    capture_output=True,
                    text=True,
                    timeout=10,
                )
                if result.returncode == 0 and result.stdout:
                    attrs = [a.strip() for a in result.stdout.strip().split(",") if a.strip()]
                    if attrs:
                        pattributes = attrs
            except Exception:
                pass
        for attr in pattributes:
            for stat in ("arf", "docf", "aldf"):
                mkstats_bin = shutil.which("mkstats", path=run_env.get("PATH"))
                if mkstats_bin:
                    try:
                        self._run_logged_command(
                            [mkstats_bin, corpus_name, attr, stat],
                            cwd=registry_dir,
                            verbose=verbose,
                            prefix=prefix,
                            env=run_env,
                            timeout_sec=step_timeout_sec,
                        )
                    except ManateeBackendError:
                        pass
        mktokencov_bin = shutil.which("mktokencov", path=run_env.get("PATH"))
        if mktokencov_bin:
            try:
                self._run_logged_command(
                    [mktokencov_bin, corpus_name],
                    cwd=registry_dir,
                    verbose=verbose,
                    prefix=prefix,
                    env=run_env,
                    timeout_sec=step_timeout_sec,
                )
            except ManateeBackendError:
                pass
        mksizes_bin = shutil.which("mksizes", path=run_env.get("PATH"))
        if mksizes_bin:
            try:
                self._run_logged_command(
                    [mksizes_bin, corpus_name, "--no-alignsizes"],
                    cwd=registry_dir,
                    verbose=verbose,
                    prefix=prefix,
                    env=run_env,
                    timeout_sec=step_timeout_sec,
                )
            except ManateeBackendError:
                try:
                    self._run_logged_command(
                        [mksizes_bin, corpus_name],
                        cwd=registry_dir,
                        verbose=verbose,
                        prefix=prefix,
                        env=run_env,
                        timeout_sec=step_timeout_sec,
                    )
                except ManateeBackendError:
                    pass

    def _run_logged_command(
        self,
        cmd: List[str],
        *,
        cwd: Path,
        verbose: bool,
        prefix: str,
        stdin_path: Optional[Path] = None,
        env: Optional[Dict[str, str]] = None,
        timeout_sec: Optional[int] = None,
    ) -> subprocess.CompletedProcess[str]:
        if verbose:
            print(prefix + "Running: " + " ".join(str(part) for part in cmd), file=sys.stderr)
        stdin_handle = None
        try:
            if stdin_path is not None:
                stdin_handle = stdin_path.open("r", encoding="utf-8")
            proc = subprocess.run(
                cmd,
                cwd=str(cwd),
                text=True,
                stdin=stdin_handle,
                env=env,
                capture_output=True,
                check=False,
                timeout=timeout_sec,
            )
        except subprocess.TimeoutExpired as exc:
            raise ManateeBackendError(
                f"Command timed out after {timeout_sec}s: {' '.join(str(part) for part in cmd)}"
            ) from exc
        finally:
            if stdin_handle is not None:
                stdin_handle.close()
        if verbose:
            if proc.stdout:
                print(prefix + "stdout:", file=sys.stderr)
                print(proc.stdout, file=sys.stderr)
            if proc.stderr:
                print(prefix + "stderr:", file=sys.stderr)
                print(proc.stderr, file=sys.stderr)
        if proc.returncode != 0:
            raise ManateeBackendError(
                f"Command failed with exit code {proc.returncode}: {proc.stderr or proc.stdout}"
            )
        return proc

    def _reindex_from_cwb(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        verbose = bool(params.get("verbose") or params.get("debug"))
        source_cfg, detected_cqp = self._source_cqp_config(req)
        target_cfg = self._target_manatee_config(req, detected_cqp)
        meta = dict(detected_cqp.get("meta") or {})
        root_dir = Path(str(detected_cqp.get("root") or project.get("root") or ".")).resolve()
        settings_path = Path(str(meta.get("settings_path") or (root_dir / "Resources" / "settings.xml")))
        title, baseurl = self._teitok_title_and_base_url(settings_path, root_dir)
        server, path = self._baseurl_parts(str(params.get("manatee_base_url") or baseurl))

        pattributes = [str(item) for item in list(meta.get("pattributes") or []) if str(item).strip()]
        sattributes_by_region = {
            str(region): [str(item) for item in list(attrs or []) if str(item).strip()]
            for region, attrs in dict(meta.get("sattributes_by_region") or {}).items()
            if str(region).strip()
        }
        # Keep this path independent of source CQP registry files. We always need text/s ids
        # in Manatee output for context resolution; flexencoder emits these consistently.
        if "text" in sattributes_by_region and "id" not in sattributes_by_region["text"]:
            sattributes_by_region["text"].append("id")
        if "s" in sattributes_by_region and "id" not in sattributes_by_region["s"]:
            sattributes_by_region["s"].append("id")

        if "word" not in pattributes:
            pattributes.insert(0, "word")
        if "id" not in pattributes:
            pattributes.append("id")

        registry_dir = Path(target_cfg.registry).expanduser().resolve()
        corp_dir = registry_dir / "corp"
        registry_dir.mkdir(parents=True, exist_ok=True)
        corp_dir.mkdir(parents=True, exist_ok=True)

        vrt_path = registry_dir / "corpus.vrt"
        registry_path = registry_dir / str(target_cfg.corpus)
        registry_text = self._build_manatee_registry_text(
            corpus_name=str(target_cfg.corpus),
            title=title,
            vrt_path=vrt_path,
            corp_path=corp_dir,
            pattributes=pattributes,
            sattributes_by_region=sattributes_by_region,
        )
        registry_path.write_text(registry_text, encoding="utf-8")

        cwb_decode_bin = self._find_executable(
            str(params.get("cwb_decode_binary") or "cwb-decode"),
            [
                "/usr/local/bin/cwb-decode",
                "/opt/homebrew/bin/cwb-decode",
                "/usr/bin/cwb-decode",
            ],
        )
        decode_cmd: List[str] = [cwb_decode_bin, "-Cx"]
        if source_cfg.registry:
            decode_cmd.extend(["-r", str(source_cfg.registry)])
        decode_cmd.append(str(source_cfg.corpus))
        for attr in pattributes:
            decode_cmd.extend(["-P", attr])
        seen_sopts: set[str] = set()
        for region, attrs in sattributes_by_region.items():
            region_name = str(region).strip()
            if not region_name:
                continue
            if region_name not in seen_sopts:
                decode_cmd.extend(["-S", region_name])
                seen_sopts.add(region_name)
            for attr in attrs:
                attr_name = str(attr).strip()
                if not attr_name:
                    continue
                struct_attr = f"{region_name}_{attr_name}"
                if struct_attr in seen_sopts:
                    continue
                decode_cmd.extend(["-S", struct_attr])
                seen_sopts.add(struct_attr)
        # Always preserve canonical text/s ids when corresponding regions are requested.
        if "text" in sattributes_by_region and "text_id" not in seen_sopts:
            decode_cmd.extend(["-S", "text_id"])
            seen_sopts.add("text_id")
        if "s" in sattributes_by_region and "s_id" not in seen_sopts:
            decode_cmd.extend(["-S", "s_id"])
            seen_sopts.add("s_id")

        prefix = "[flexicorp][manatee][reindex-cwb] "
        if verbose:
            print(prefix + "Streaming CWB decode into Manatee VRT", file=sys.stderr)
            print(prefix + "Running: " + " ".join(str(part) for part in decode_cmd), file=sys.stderr)

        decode_proc = subprocess.Popen(
            decode_cmd,
            cwd=str(root_dir),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        assert decode_proc.stdout is not None
        try:
            self._clean_cwb_decode_to_vrt(decode_proc.stdout, vrt_path, server=server, path=path)
        finally:
            decode_proc.stdout.close()
        decode_stderr = decode_proc.stderr.read() if decode_proc.stderr is not None else ""
        decode_return = decode_proc.wait()
        if decode_proc.stderr is not None:
            decode_proc.stderr.close()
        if verbose and decode_stderr:
            print(prefix + "cwb-decode stderr:", file=sys.stderr)
            print(decode_stderr, file=sys.stderr)
        if decode_return != 0:
            raise ManateeBackendError(
                f"cwb-decode failed with exit code {decode_return}: {decode_stderr or 'no stderr output'}"
            )

        compile_env = dict(os.environ)
        compile_env["MANATEE_REGISTRY"] = str(registry_dir)
        self._run_manatee_compile(
            registry_dir=registry_dir,
            corpus_name=str(target_cfg.corpus),
            vrt_path=vrt_path,
            pattributes=pattributes,
            project=project,
            params=params,
            env=compile_env,
            verbose=verbose,
            prefix=prefix,
        )

        return {
            "status": "ok",
            "strategy": "cwb_first",
            "source_backend": "cqp",
            "source_registry": source_cfg.registry,
            "source_corpus": source_cfg.corpus,
            "root": str(root_dir),
            "settings": str(settings_path),
            "registry": str(registry_dir),
            "registry_file": str(registry_path),
            "data_path": str(corp_dir),
            "vrt": str(vrt_path),
            "corpus": str(target_cfg.corpus),
            "message": (
                "Manatee corpus rebuilt from the existing CWB corpus via cwb-decode, "
                "encodevert, mkstats, mktokencov, and mksizes (no compilecorp)."
            ),
        }

    def _make_query_hit_raw(
        self,
        *,
        doc_id: Optional[str],
        sentence_id: Optional[str],
        match_start: Optional[int],
        match_end: Optional[int],
        toks: List[str],
    ) -> str:
        parts: List[str] = []
        if doc_id:
            parts.append(str(doc_id))
        if sentence_id:
            parts.append(str(sentence_id))
        if match_start is not None:
            parts.append(str(match_start))
        if match_end is not None:
            parts.append(str(match_end))
        if toks:
            parts.append(" ".join(str(tok) for tok in toks))
        return "\t".join(parts)

    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        cfg = self._get_config(project)
        corpus = self._open_corpus(cfg)
        doc_struct, _doc_id_attr, _title_attr = self._doc_lookup(corpus)
        return {
            "backend": self.name,
            "registry": cfg.registry,
            "corpus": cfg.corpus,
            "name": self._safe_get_conf(corpus, "NAME") or cfg.corpus,
            "encoding": self._safe_get_conf(corpus, "ENCODING"),
            "data_path": self._safe_get_conf(corpus, "PATH"),
            "doc_structure": getattr(doc_struct, "name", None),
            "corpus_size": int(corpus.size()) if hasattr(corpus, "size") else None,
            "search_size": int(corpus.search_size()) if hasattr(corpus, "search_size") else None,
            "pattributes": _split_conf_list(self._safe_get_conf(corpus, "ATTRLIST")),
            "struct_attributes": _split_conf_list(self._safe_get_conf(corpus, "STRUCTLIST")),
            "struct_attr_refs": _split_conf_list(self._safe_get_conf(corpus, "STRUCTATTRLIST")),
        }

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        cfg = self._get_config(project)
        corpus = self._open_corpus(cfg)
        payload = self.status(req)
        payload["descriptor"] = self.descriptor()
        payload["info"] = self._safe_get_conf(corpus, "INFO")
        return payload

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        cfg = self._get_config(project)
        corpus = self._open_corpus(cfg)
        limit = max(0, int(params.get("limit", 50)))
        offset = max(0, int(params.get("offset", 0)))
        search = str(params.get("search") or params.get("filter") or "").strip().lower()

        doc_struct, doc_id_attr, title_attr = self._doc_lookup(corpus)
        if doc_struct is None:
            return {"docs": [], "total": 0, "doc_structure": None}

        docs: List[Dict[str, Any]] = []
        total = 0
        struct_size = int(doc_struct.size())
        for idx in range(struct_size):
            beg = int(doc_struct.beg(idx))
            end = int(doc_struct.end(idx))
            doc_id = self._safe_pos2str(doc_id_attr, beg) or f"{doc_struct.name}:{idx + 1}"
            title = self._safe_pos2str(title_attr, beg) or doc_id
            haystack = f"{doc_id} {title}".lower()
            if search and search not in haystack:
                continue
            total += 1
            if total <= offset:
                continue
            if limit and len(docs) >= limit:
                continue
            meta: Dict[str, Any] = {
                "start_pos": beg,
                "end_pos": end,
                "length": max(0, end - beg + 1),
            }
            docs.append({"id": doc_id, "title": title, "meta": meta})
        return {"docs": docs, "total": total, "doc_structure": getattr(doc_struct, "name", None)}

    def kwic(self, req: FlexiRequest) -> Dict[str, Any]:
        """Dispatchers and older clients may use ``operation`` kwic; Manatee only implements ``query``."""
        return self.query(req)

    def query(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        # TEITOK UI may send the pattern under different keys; normalise to a single CQL string.
        query_text = str(
            params.get("query")
            or params.get("pattern")
            or params.get("cql")
            or ""
        ).strip()
        if not query_text:
            raise ManateeBackendError(
                "manatee query requires a non-empty CQL string in params['query'] (or 'pattern' / 'cql')."
            )

        query_lang = str(params.get("query_language") or params.get("query_lang") or "manatee-cql").strip().lower()
        if query_lang not in {"manatee-cql", "manatee", "cql"}:
            raise ManateeBackendError(
                "The pure Manatee backend currently only supports query_language='manatee-cql' (or 'manatee' / 'cql')."
            )

        start = max(0, int(params.get("start", 0)))
        max_hits = max(0, min(int(params.get("max", 50)), 5000))
        context_spec = normalize_context_request(params)
        detected = self._detect_teitok(project)
        cfg = self._get_config(project)
        corpus = self._open_corpus(cfg)
        manatee = self._load_manatee_module(Path(cfg.registry).expanduser().resolve().parent)
        conc = manatee.Concordance(corpus, query_text, 0, -1)
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
                "engine": "manatee-bindings",
                "registry": cfg.registry,
                "corpus": cfg.corpus,
                "legend": resolve_legend(params),
            }

        end = min(start + max_hits, total)
        corpus_kw = self._manatee_concordance_corpus(conc, corpus)
        max_pos = self._corpus_max_token_index(corpus_kw)
        file_scaffold: Any | None = None
        try:
            file_scaffold = load_manatee_corpus_scaffold(cfg)
        except Exception:
            file_scaffold = None
        token_attr_name, token_attr = self._pick_token_attr_for_query(corpus_kw, file_scaffold)
        token_lim = self._min_pos_limit(max_pos, self._positional_attr_max_pos(token_attr, corpus_kw))
        doc_struct, doc_id_attr, _title_attr = self._doc_lookup(corpus_kw)
        sentence_id_attr = self._sentence_id_attr(corpus_kw)
        sent_struct = self._safe_get_struct(corpus_kw, "s")
        data_dir: Path | None = None
        if file_scaffold is not None:
            summ = getattr(file_scaffold, "summary", None)
            if summ is not None:
                data_dir = getattr(summ, "resolved_data_path", None)
        # Optional CWB-style text_id.* files (big-endian in those components — not Manatee .text LE).
        # Order: Manatee data PATH, Manatee registry dir, TEITOK cqp registry, cqp/<corpus>/.
        text_id_fallback_dirs: List[Path] = []
        if data_dir is not None:
            text_id_fallback_dirs.append(Path(data_dir))
        text_id_fallback_dirs.append(Path(str(cfg.registry)).expanduser().resolve())
        cqp_cfg = get_cqp_config(project)
        if cqp_cfg and cqp_cfg.registry:
            text_id_fallback_dirs.append(Path(str(cqp_cfg.registry)).expanduser().resolve())
        root_raw = project.get("root")
        teitok_root: Optional[Path] = None
        if root_raw:
            teitok_root = Path(str(root_raw)).expanduser().resolve()
        # NOTE: ``has_flexencoder_xidx(teitok_root)`` is deliberately not
        # consulted on the Manatee path any more — xidx cpos and Manatee cpos
        # are different streams (INV-1). The previous ``flex_xidx`` gate only
        # guarded the removed ``text_id_stem_for_cpos`` fallback. The import is
        # kept for the raw-text context work tracked separately.
        _ = has_flexencoder_xidx  # retain the symbol; explicit no-op use.

        if root_raw:
            root_path = Path(str(root_raw)).expanduser().resolve()
            cqp_root = root_path / "cqp"
            corp_name = (cqp_cfg.corpus if cqp_cfg else None) or (project.get("manatee") or {}).get(
                "corpus"
            )
            if corp_name and cqp_root.is_dir():
                sub = cqp_root / str(corp_name).lower()
                if sub.is_dir():
                    text_id_fallback_dirs.append(sub)

        match_spans: List[tuple[int, int]] = []
        cpos_spans = self._concordance_spans_via_cpos(conc, start, end)
        if cpos_spans is not None and len(cpos_spans) == end - start:
            match_spans = cpos_spans
        else:
            # KonText uses conc.corp() for KWICLines (see lib/kwiclib/__init__.py).
            # left=right="0", empty kwica: iteration-only; tokens come from lexicon / pos2str.
            kl = manatee.KWICLines(corpus_kw, conc.RS(True, start, end), "0", "0", "", "", "", "")
            while kl.nextline():
                match_start = int(kl.get_pos())
                kwic_len_value = int(kl.get_kwiclen())
                kwic_len = kwic_len_value if kwic_len_value > 0 else 1
                match_end = match_start + kwic_len - 1
                if token_lim is not None:
                    if match_start < 0 or match_start > token_lim:
                        continue
                    match_end = min(match_end, token_lim)
                if match_start > match_end:
                    continue
                match_spans.append((match_start, match_end))

        rows: List[Dict[str, Any]] = []
        for match_start, match_end in match_spans:
            if token_lim is not None:
                if match_start < 0 or match_start > token_lim:
                    continue
                match_end = min(match_end, token_lim)
            if match_start > match_end:
                continue

            # Document id for TEITOK XML: prefer Manatee registry structures, then CWB-style
            # text_id.* beside the corpus. Do NOT prefer flexencoder xidx here: xidx/tokens.bin uses
            # flexencoder's global positions; Manatee concordance uses Manatee cpos — they are different
            # streams unless rebuilt in perfect lockstep. Using xidx first mis-mapped hits to unrelated
            # sentences (queries matched in Manatee but context showed wrong XML).
            doc_id: Optional[str] = None
            doc_beg: Optional[int] = None
            if doc_struct is not None:
                # Resolve doc_beg even when doc_id_attr is missing — the
                # ``s.id``-less / no-positional-id fallback in
                # ``resolve_teitok_context`` (xml-doc-offset) needs it to
                # compute ``match_start - doc_beg``.
                doc_beg = self._struct_beg_containing(doc_struct, match_start)
            if doc_id_attr is not None and doc_beg is not None:
                doc_id = self._safe_pos2str(doc_id_attr, doc_beg, max_pos=max_pos)
            if doc_id is None and text_id_fallback_dirs:
                # Safe: text_id.* files live next to the Manatee corpus and are
                # written in the SAME cpos stream as Manatee. Unlike xidx, they
                # honour the ``"--"`` token skip performed by the CWB writer.
                doc_id = text_id_from_cwb_style_index_files(text_id_fallback_dirs, match_start)
            # NOTE: We intentionally do NOT fall back to
            # ``text_id_stem_for_cpos(teitok_root, match_start)`` here.
            # ``text_id_stem_for_cpos`` reads flexencoder's xidx, which uses
            # a 1-based global_pos that increments on every token (no "--" skip).
            # Manatee cpos is 0-based and skips "--" — see
            # ``docs/manatee_xml_context_fix.md`` §2 (INV-1). Feeding Manatee cpos
            # into xidx caused the "wrong sentence" and "shifted highlights"
            # bugs reported against prior revisions. Do not reintroduce it
            # without first rebuilding xidx in lockstep with Manatee cpos.

            sent_beg: Optional[int] = None
            if sentence_id_attr is not None and sent_struct is not None:
                sent_beg = self._struct_beg_containing(sent_struct, match_start)
                sentence_id = (
                    self._safe_pos2str(sentence_id_attr, sent_beg, max_pos=max_pos)
                    if sent_beg is not None
                    else None
                )
            else:
                sentence_id = None

            rows.append(
                {
                    "match_start": match_start,
                    "match_end": match_end,
                    "doc_id": doc_id,
                    "doc_beg": doc_beg,
                    "sentence_id": sentence_id,
                    "sentence_start": sent_beg if sent_struct is not None else None,
                }
            )

        need_ranges = [(int(r["match_start"]), int(r["match_end"])) for r in rows]
        bulk_lex: Optional[List[List[str]]] = None
        if need_ranges and token_attr_name:
            bulk_lex = self._tokens_from_lexicon_files(file_scaffold, token_attr_name, need_ranges)
        # Token *id* values (same as TEITOK XML <tok xml:id="…">). Needed for highlight_map — using
        # surface lexicon strings breaks DOM matching in the UI (ids vs word forms).
        bulk_id_toks: Optional[List[List[str]]] = None
        if need_ranges and file_scaffold is not None:
            pos_map = getattr(file_scaffold, "positional", None) or {}
            if isinstance(pos_map, dict) and "id" in pos_map:
                bulk_id_toks = self._tokens_from_lexicon_files(file_scaffold, "id", need_ranges)
        # TOMBSTONE: previous revisions read ``text_id`` / ``s_id`` as POSITIONAL
        # attributes from the lexicon files here and used them to override
        # ``doc_id`` / ``sentence_id`` derived from the Manatee structures.
        # That was wrong on two counts:
        #   1) ``_reindex_from_cwb`` writes ``text_id`` / ``s_id`` as structural
        #      attributes on ``<text>`` and ``<s>`` (``cwb-decode -S text_id
        #      -S s_id`` → VRT attrs), not as positional columns. Under the
        #      canonical reindex path the ``"text_id" in pos_map`` guard was
        #      always false and the block was dead code.
        #   2) On hand-built corpora where someone *did* add ``text_id`` /
        #      ``s_id`` as positional attrs, the values still come from a
        #      different coordinate stream than the structural ids used
        #      elsewhere in this function — mixing them produced the "wrong
        #      sentence id at document boundaries" symptom.
        # The canonical resolution path is the Manatee structure lookup
        # (``_struct_beg_containing`` + ``_safe_pos2str`` on the struct attr)
        # performed above, with a CWB-style ``text_id.*`` file fallback.
        # See ``docs/manatee_xml_context_fix.md`` §2 (INV-5) and §3 (RC-C).

        teitok_searchfolder = "xmlfiles"
        if context_spec and detected and project.get("root"):
            cqp_side = detect_teitok_cqp(Path(str(project["root"])).expanduser().resolve())
            if cqp_side:
                sf = (cqp_side.get("meta") or {}).get("searchfolder")
                if isinstance(sf, str) and sf.strip():
                    teitok_searchfolder = sf.strip()

        hits: List[Dict[str, Any]] = []
        for row_idx, r in enumerate(rows):
            match_start = int(r["match_start"])
            match_end = int(r["match_end"])
            doc_id = r.get("doc_id")
            doc_beg = r.get("doc_beg")
            sentence_id = r.get("sentence_id")
            sentence_start = r.get("sentence_start")
            # Do NOT override doc_id / sentence_id from positional bulk reads
            # here — see the tombstone above this loop (§2 INV-5 / §3 RC-C).
            # Manatee structure lookup is the canonical source.
            toks: List[str] = []
            if bulk_lex is not None and row_idx < len(bulk_lex):
                toks = [t for t in bulk_lex[row_idx] if t]
            if not toks:
                # Native pos2str on positional attrs can segfault in _manatee (not catchable in Python).
                # Lexicon path above is the only safe source for token strings here.
                toks = []
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
                "raw": self._make_query_hit_raw(
                    doc_id=doc_id,
                    sentence_id=sentence_id,
                    match_start=match_start,
                    match_end=match_end,
                    toks=toks,
                ),
            }
            hm_ids: List[str] = [str(t) for t in toks]
            if bulk_id_toks is not None and row_idx < len(bulk_id_toks):
                id_row = [t for t in bulk_id_toks[row_idx] if t]
                if id_row:
                    hm_ids = [str(t) for t in id_row]
            if detected and doc_id is not None:
                hit["text_id"] = str(doc_id)
            if context_spec and detected and doc_id:
                tok_ids_xml = hm_ids
                # CRITICAL: For the Manatee path we resolve XML context via
                # ``<text>``/``<s>`` xml:id lookup against the on-disk TEITOK
                # XML — NOT via ``flexencoder_xidx``.
                #
                # Why: flexencoder's xidx (``tokens.bin`` + ``regions.bin``)
                # indexes by its own ``global_pos`` (1-based, no skipping). The
                # Manatee writer drops ``"--"`` placeholder tokens, so Manatee
                # cpos is a different stream from xidx cpos
                # (see docs/manatee_xml_context_fix.md §2 INV-1). Feeding
                # Manatee cpos into xidx caused the historical
                # "wrong sentence" / "shifted highlights" / "single-word
                # context" bugs reported against earlier revisions.
                #
                # The CWB backend can keep using xidx because its writer also
                # skips ``"--"`` (see flexencoder/flexencoder_cwb.cpp ~line
                # 267) — its cpos stream matches xidx by construction.
                #
                # ``prefer="xml"`` + ``xidx_resolver=None`` together force
                # ``resolve_teitok_context`` down the
                # ``extract_teitok_fragment_xml`` path, which finds the
                # ``<s xml:id="…">`` (or ``<text xml:id="…">``) directly. Do
                # NOT remove either argument unless you have first rebuilt
                # xidx in lockstep with Manatee cpos.
                ctx_spec = dict(context_spec)
                ctx_spec["prefer"] = "xml"
                context = resolve_teitok_context(
                    root_dir=Path(detected.get("root") or ".").resolve(),
                    searchfolder=teitok_searchfolder,
                    doc_id=str(doc_id),
                    sentence_id=str(sentence_id) if sentence_id else None,
                    tok_ids=tok_ids_xml,
                    match_start=match_start,
                    match_end=match_end,
                    context_spec=ctx_spec,
                    xidx_resolver=None,
                    # Doc-relative cpos offset fallback — used when
                    # ``sentence_id`` is None and ``tok_ids`` are surface
                    # forms (common on corpora without ``s.id`` or an ``id``
                    # positional attr on disk). Lets ``resolve_teitok_context``
                    # count <tok>/<dtok> in the XML and walk up to <s>.
                    doc_cpos_base=int(doc_beg) if isinstance(doc_beg, int) else None,
                )
                if context:
                    anchored_ids: Optional[List[str]] = None
                    if (
                        isinstance(context.get("data"), str)
                        and isinstance(sentence_start, int)
                        and match_start >= sentence_start
                    ):
                        rel_start = match_start - sentence_start
                        rel_end = match_end - sentence_start
                        anchored_ids = self._tok_ids_from_sentence_fragment_by_offset(
                            str(context["data"]),
                            token_offset_start=rel_start,
                            token_offset_end=rel_end,
                            expected_tokens=toks,
                        )
                    if (
                        not anchored_ids
                        and isinstance(context.get("data"), str)
                        and toks
                    ):
                        anchored_ids = self._tok_ids_from_fragment_by_surface_match(
                            str(context["data"]),
                            toks,
                        )
                    if anchored_ids:
                        hm_ids = [str(x) for x in anchored_ids]
                        locator = context.get("locator")
                        if isinstance(locator, dict):
                            locator["token_ids"] = list(anchored_ids)
                    hit["context"] = context
            if hm_ids:
                hit["highlight_map"] = build_highlight_map(hm_ids)
            hits.append(hit)

        return {
            "total": total,
            "start": start,
            "hits": hits,
            "returned": len(hits),
            "query_lang": query_lang,
            "corpus_format": "manatee",
            "engine": "manatee-bindings",
            "registry": cfg.registry,
            "corpus": cfg.corpus,
            "legend": resolve_legend(params),
        }

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        return self._reindex_from_cwb(req)


register_backend(ManateeBackend())
