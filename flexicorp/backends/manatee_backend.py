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
from ..teitok import detect_teitok_cqp, detect_teitok_manatee
from ..teitok_context import normalize_context_request, resolve_teitok_context
from .cqp import CqpBackend
from .manatee import (
    ManateeFormatError,
    _decode_forward_text_ids,
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
        """
        if struct_obj is None:
            return None
        try:
            if hasattr(struct_obj, "num_at"):
                ni = int(struct_obj.num_at(pos))
                if ni >= 0:
                    return int(struct_obj.beg(ni))
        except Exception:
            pass
        try:
            n = int(struct_obj.size())
            for idx in range(n):
                beg = int(struct_obj.beg(idx))
                end = int(struct_obj.end(idx))
                if beg <= pos <= end:
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
        Bonito/Manatee ``Concordance`` exposes ``cpos(i)`` per result line. Using it avoids
        ``KWICLines.nextline()``, which can segfault on some ``_manatee`` builds/corpora.

        Optional end-of-match APIs (when not returned as a pair from ``cpos``): ``cpos_end``,
        ``endpos``, ``cend`` — some builds expose one of these with the line index.

        Returns ``None`` if ``cpos`` is missing or any call raises (caller falls back to KWICLines).
        """
        if start >= end:
            return []
        cpos_fn = getattr(conc, "cpos", None)
        if not callable(cpos_fn):
            cpos_fn = getattr(conc, "get_cpos", None)
        if not callable(cpos_fn):
            return None
        spans: List[tuple[int, int]] = []
        try:
            for i in range(start, end):
                raw = cpos_fn(i)
                if isinstance(raw, (tuple, list)) and len(raw) >= 2:
                    ms, me = int(raw[0]), int(raw[1])
                    if me < ms:
                        me = ms
                    spans.append((ms, me))
                    continue
                ms = int(raw)
                me = ms
                for name in ("cpos_end", "endpos", "cend"):
                    fn = getattr(conc, name, None)
                    if not callable(fn):
                        continue
                    try:
                        me = int(fn(i))
                        break
                    except Exception:
                        continue
                if me < ms:
                    me = ms
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
            ids = _decode_forward_text_ids(text_path, max_end)
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
        cfg = CqpBackend()._maybe_patch_registry_home(cfg, cqp_project, debug=False)
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
        # Fallback: flexicorp repo layout with git/manatee-open-*
        try:
            repo_root = Path(__file__).resolve().parents[3]
            for name in ("manatee-open-2.225.8", "manatee-open"):
                manatee_dir = repo_root / "git" / name
                if manatee_dir.is_dir():
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
                )
            except ManateeBackendError:
                try:
                    self._run_logged_command(
                        [mksizes_bin, corpus_name],
                        cwd=registry_dir,
                        verbose=verbose,
                        prefix=prefix,
                        env=run_env,
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
            )
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
        for region, attrs in sattributes_by_region.items():
            decode_cmd.extend(["-S", region])
            for attr in attrs:
                decode_cmd.extend(["-S", f"{region}_{attr}"])
        if "text" in sattributes_by_region:
            decode_cmd.extend(["-S", "text_id"])

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

            # Struct id attributes: use the region start that contains the match. Calling
            # pos2str on a struct attribute at an arbitrary token index can segfault in _manatee.
            if doc_id_attr is not None and doc_struct is not None:
                doc_beg = self._struct_beg_containing(doc_struct, match_start)
                doc_id = (
                    self._safe_pos2str(doc_id_attr, doc_beg, max_pos=max_pos)
                    if doc_beg is not None
                    else None
                )
            else:
                doc_id = None

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
                    "sentence_id": sentence_id,
                }
            )

        need_ranges = [(int(r["match_start"]), int(r["match_end"])) for r in rows]
        bulk_lex: Optional[List[List[str]]] = None
        if need_ranges and token_attr_name:
            bulk_lex = self._tokens_from_lexicon_files(file_scaffold, token_attr_name, need_ranges)

        hits: List[Dict[str, Any]] = []
        bidx = 0
        for r in rows:
            match_start = int(r["match_start"])
            match_end = int(r["match_end"])
            doc_id = r.get("doc_id")
            sentence_id = r.get("sentence_id")
            toks: List[str] = []
            if bulk_lex is not None and bidx < len(bulk_lex):
                toks = [t for t in bulk_lex[bidx] if t]
            bidx += 1
            if not toks:
                toks = [
                    self._safe_pos2str(token_attr, pos, max_pos=token_lim) or ""
                    for pos in range(match_start, match_end + 1)
                ]
                toks = [tok for tok in toks if tok]
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
            if toks:
                hit["highlight_map"] = build_highlight_map(toks)
            if context_spec and detected and doc_id:
                context = resolve_teitok_context(
                    root_dir=Path(detected.get("root") or ".").resolve(),
                    searchfolder="xmlfiles",
                    doc_id=str(doc_id),
                    sentence_id=str(sentence_id) if sentence_id else None,
                    tok_ids=[str(tok) for tok in toks],
                    match_start=match_start,
                    match_end=match_end,
                    context_spec=context_spec,
                )
                if context:
                    hit["context"] = context
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
