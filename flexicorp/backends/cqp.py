from __future__ import annotations

import subprocess
import bisect
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
import sys
import tempfile
import shutil
import os
import locale
import re
import json
import hashlib
import struct
import xml.etree.ElementTree as ET
from functools import lru_cache

from ..config import CqpConfig, get_cqp_config, get_project_root
from ..core import CorpusBackend, FlexiRequest, register_backend
from ..highlight_contract import build_highlight_map, resolve_legend
from ..teitok import detect_teitok_cqp
from ..teitok_context import normalize_context_request, resolve_teitok_context
from ..querylang.cwb_cql import SequencePattern, parse_cwb_cql


@dataclass
class CqpBackend(CorpusBackend):
    name: str = "cqp"
    # Bump to invalidate cached query results when the tabulate schema changes.
    query_cache_version: int = 7

    def _normalize_simple_cqp_token_query(self, query_text: str) -> str:
        """
        Normalize common user input to a CQP token query.

        Many user-facing UIs pass bare tokens like `Ridder`, but CQP needs token
        values to be written as string literals, e.g. `"Ridder"`.
        """
        q = (query_text or "").strip()
        if not q:
            return q

        # Allow interactive-style snippets ending in ';'
        while q.endswith(";"):
            q = q[:-1].rstrip()
            if not q:
                return q

        if q.startswith('"') or q.startswith("'") or q.startswith("["):
            return q

        # Only quote a simple single token (avoid breaking more complex CQP).
        if re.search(r"\s", q):
            return q
        if re.search(r'["\'\\[\\]\\;]', q):
            return q
        if any(ch in q for ch in "*?+()|/"):
            return q

        escaped = q.replace("\\", "\\\\").replace('"', '\\"')
        return f"\"{escaped}\""

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "cqp",
            "supported_query_languages": ["cwb-cql", "cqp"],
            "supported_corpus_formats": ["cwb"],
            "default_query_language": "cwb-cql",
            "default_corpus_format": "cwb",
            "default_selection_reason": "Direct CQP backend over a CWB index.",
        }

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": True,
            "freq": True,
            "stats_freq_pattributes": True,
            "stats_freq_sattributes": True,
            "stats_relative_freq": True,
            "stats_collocations": False,
            "stats_dep_collocations": False,
            "stats_keyness": False,
            "stats_table_result": False,
            "info": True,
            "reindex": True,
            "raw_query": False,
            "query": True,
        }

    def _get_config(self, req: FlexiRequest) -> CqpConfig:
        project = dict(req.get("project") or {})
        cfg = get_cqp_config(project)
        if cfg is None:
            raise RuntimeError("Missing or incomplete CQP configuration in request/project.")
        debug = bool((req.get("params") or {}).get("debug"))
        cfg = self._prepare_runtime_registry(cfg, project, debug=debug)
        return cfg

    def _resolve_registry_file_path(self, registry: str | None, corpus: str) -> Optional[Path]:
        if not registry:
            return None
        registry_path = Path(registry).expanduser()
        if registry_path.is_dir():
            candidate = registry_path / corpus.lower()
            if candidate.is_file():
                return candidate
            return None
        if registry_path.is_file():
            return registry_path
        return None

    def _prepare_runtime_registry(self, cfg: CqpConfig, project: Dict[str, Any], *, debug: bool = False) -> CqpConfig:
        registry_file = self._resolve_registry_file_path(cfg.original_registry or cfg.registry, cfg.corpus)
        if registry_file is None or not registry_file.is_file():
            return cfg

        try:
            original_text = registry_file.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            return cfg

        local_home_candidates: List[Path] = []
        root = project.get("root")
        if root:
            local_home_candidates.append((Path(root).expanduser().resolve() / "cqp").resolve())
        local_home_candidates.append(registry_file.parent.resolve())

        local_home = next((candidate for candidate in local_home_candidates if candidate.is_dir()), None)
        if local_home is None:
            return cfg

        lines = original_text.splitlines()
        new_lines: List[str] = []
        changed = False
        home_seen = False
        info_seen = False
        home_invalid = False
        local_info = local_home / ".info"

        for line in lines:
            stripped = line.strip()
            if stripped.startswith("HOME "):
                home_seen = True
                current_home = stripped.split(None, 1)[1].strip() if len(stripped.split(None, 1)) == 2 else ""
                current_home_path = Path(current_home).expanduser() if current_home else None
                if current_home_path is None or not current_home_path.exists():
                    new_lines.append(f"HOME {local_home}")
                    changed = True
                    home_invalid = True
                else:
                    new_lines.append(line)
                continue
            if stripped.startswith("INFO "):
                info_seen = True
                current_info = stripped.split(None, 1)[1].strip() if len(stripped.split(None, 1)) == 2 else ""
                current_info_path = Path(current_info).expanduser() if current_info else None
                if (current_info_path is None or not current_info_path.exists()) and local_info.is_file():
                    new_lines.append(f"INFO {local_info}")
                    changed = True
                else:
                    new_lines.append(line)
                continue
            new_lines.append(line)

        if home_invalid and info_seen and local_info.is_file():
            # If the corpus was moved and both HOME and INFO pointed into the old location,
            # make sure INFO follows the same local cqp folder.
            new_lines = [f"INFO {local_info}" if ln.strip().startswith("INFO ") else ln for ln in new_lines]
            changed = True

        if not home_seen:
            return cfg
        if not changed:
            return cfg

        patched_text = "\n".join(new_lines) + ("\n" if original_text.endswith("\n") else "")
        digest = hashlib.sha256(
            json.dumps(
                {
                    "registry_file": str(registry_file.resolve()),
                    "local_home": str(local_home),
                    "patched_text": patched_text,
                },
                sort_keys=True,
                ensure_ascii=False,
            ).encode("utf-8")
        ).hexdigest()[:24]
        runtime_dir = Path(tempfile.gettempdir()) / "flexicorp-runtime-registry" / digest
        runtime_dir.mkdir(parents=True, exist_ok=True)
        runtime_file = runtime_dir / registry_file.name
        try:
            runtime_file.write_text(patched_text, encoding="utf-8")
        except OSError:
            return cfg

        if debug:
            print(
                "[flexicorp][cqp] Using patched runtime registry: "
                f"{runtime_file} (original: {registry_file})",
                file=sys.stderr,
            )

        cfg.registry = str(runtime_dir)
        cfg.registry_patched = True
        return cfg

    def _resolve_cqp_binary(self, cfg: CqpConfig) -> str:
        binary = str(cfg.cqp_binary or "cqp").strip()
        if not binary:
            binary = "cqp"

        # Explicit path from config/request.
        if "/" in binary:
            path = Path(binary).expanduser()
            if path.is_file() and os.access(path, os.X_OK):
                return str(path)
            raise RuntimeError(f"CQP executable not found or not executable: '{binary}'")

        return self._find_executable(
            binary,
            [
                f"/usr/local/bin/{binary}",
                f"/opt/homebrew/bin/{binary}",
                f"/usr/bin/{binary}",
            ],
        )

    def _run_cqp(self, cfg: CqpConfig, script: str, *, debug: bool = False, label: str = "") -> str:
        """
        Run a small CQP script and return its stdout.

        This is intentionally minimal; TEITOK already provides most of the
        heavy lifting. We rely on existing registry/corpus settings.
        """
        # CQP does not reliably read commands from stdin without a script file,
        # so we write the script to a temporary file and invoke `cqp -f <file>`.
        with tempfile.NamedTemporaryFile(mode="w", suffix=".cqp", delete=False, encoding="utf-8") as tmp:
            tmp.write(script)
            tmp_path = Path(tmp.name)

        cqp_bin = self._resolve_cqp_binary(cfg)
        cmd = [cqp_bin]
        if cfg.registry:
            cmd.extend(["-r", cfg.registry])
        # Use TEITOK corpus name exactly as configured (e.g. "TT-TEST")
        # so we don't need CORPUS statements in the script.
        if cfg.corpus:
            cmd.extend(["-D", cfg.corpus])
        cmd.extend(["-f", str(tmp_path)])

        prefix = f"[flexicorp][cqp][{label}] " if label else "[flexicorp][cqp] "
        if debug:
            print(
                prefix
                + "Running CQP command: "
                + " ".join(cmd),
                file=sys.stderr,
            )
            print(prefix + "CQP script:", file=sys.stderr)
            print(script.strip(), file=sys.stderr)

        try:
            proc = subprocess.run(
                cmd,
                capture_output=True,
                check=False,
            )
        finally:
            try:
                tmp_path.unlink(missing_ok=True)
            except OSError:
                pass

        stdout = self._decode_cqp_output(proc.stdout, cfg.encoding)
        stderr = self._decode_cqp_output(proc.stderr, cfg.encoding)

        if debug:
            if stdout:
                print(prefix + "CQP stdout:", file=sys.stderr)
                print(stdout, file=sys.stderr)
            if stderr:
                print(prefix + "CQP stderr:", file=sys.stderr)
                print(stderr, file=sys.stderr)

        if proc.returncode != 0 or (stderr and "CQP Error:" in stderr):
            raise RuntimeError(f"CQP command failed: {stderr or stdout}")
        return stdout

    def _run_cqp_capture_both(
        self, cfg: CqpConfig, script: str, *, debug: bool = False, label: str = ""
    ) -> Tuple[str, str]:
        """Run CQP script and return (stdout, stderr) as decoded strings. Use for SIZE when output may be on either stream."""
        with tempfile.NamedTemporaryFile(mode="w", suffix=".cqp", delete=False, encoding="utf-8") as tmp:
            tmp.write(script)
            tmp_path = Path(tmp.name)
        cqp_bin = self._resolve_cqp_binary(cfg)
        cmd = [cqp_bin]
        if cfg.registry:
            cmd.extend(["-r", cfg.registry])
        if cfg.corpus:
            cmd.extend(["-D", cfg.corpus])
        cmd.extend(["-f", str(tmp_path)])
        prefix = f"[flexicorp][cqp][{label}] " if label else "[flexicorp][cqp] "
        if debug:
            print(prefix + "Running CQP command: " + " ".join(cmd), file=sys.stderr)
            print(prefix + "CQP script:", file=sys.stderr)
            print(script.strip(), file=sys.stderr)
        try:
            proc = subprocess.run(cmd, capture_output=True, check=False)
        finally:
            try:
                tmp_path.unlink(missing_ok=True)
            except OSError:
                pass
        stdout = self._decode_cqp_output(proc.stdout, cfg.encoding)
        stderr = self._decode_cqp_output(proc.stderr, cfg.encoding)
        if debug:
            if stdout:
                print(prefix + "CQP stdout:", file=sys.stderr)
                print(stdout, file=sys.stderr)
            if stderr:
                print(prefix + "CQP stderr:", file=sys.stderr)
                print(stderr, file=sys.stderr)
        if proc.returncode != 0 or (stderr and "CQP Error:" in stderr):
            raise RuntimeError(f"CQP command failed: {stderr or stdout}")
        return (stdout or "", stderr or "")

    def _run_cqp_to_file(
        self,
        cfg: CqpConfig,
        script: str,
        output_path: Path,
        *,
        debug: bool = False,
        label: str = "",
    ) -> None:
        """
        Run a CQP script and stream stdout to a cache file.

        The cache file is stored as raw bytes so we can decode line-by-line
        later using the same robust decoder as `_run_cqp`.
        """
        with tempfile.NamedTemporaryFile(mode="w", suffix=".cqp", delete=False, encoding="utf-8") as tmp:
            tmp.write(script)
            tmp_path = Path(tmp.name)

        cqp_bin = self._resolve_cqp_binary(cfg)
        cmd = [cqp_bin]
        if cfg.registry:
            cmd.extend(["-r", cfg.registry])
        if cfg.corpus:
            cmd.extend(["-D", cfg.corpus])
        cmd.extend(["-f", str(tmp_path)])

        prefix = f"[flexicorp][cqp][{label}] " if label else "[flexicorp][cqp] "
        if debug:
            print(prefix + "Running CQP command: " + " ".join(cmd), file=sys.stderr)
            print(prefix + "CQP script:", file=sys.stderr)
            print(script.strip(), file=sys.stderr)

        try:
            with output_path.open("wb") as out_handle:
                proc = subprocess.run(
                    cmd,
                    stdout=out_handle,
                    stderr=subprocess.PIPE,
                    check=False,
                )
        finally:
            try:
                tmp_path.unlink(missing_ok=True)
            except OSError:
                pass

        stderr = self._decode_cqp_output(proc.stderr, cfg.encoding)
        if debug and stderr:
            print(prefix + "CQP stderr:", file=sys.stderr)
            print(stderr, file=sys.stderr)
        if proc.returncode != 0 or (stderr and "CQP Error:" in stderr):
            raise RuntimeError(f"CQP command failed: {stderr}")

    def _run_cqp_collect_lines(
        self,
        cfg: CqpConfig,
        script: str,
        *,
        max_lines: int,
        debug: bool = False,
        label: str = "",
    ) -> tuple[List[str], bool]:
        """
        Run a CQP script and collect up to max_lines + 1 lines.

        Returns:
            (lines, completed)

        - lines: collected non-empty output lines
        - completed: True when the CQP process finished naturally before exceeding
          the requested line budget; False when we terminated it after collecting
          enough lines to know there are more results.
        """
        if max_lines < 0:
            max_lines = 0
        collect_limit = max_lines + 1

        with tempfile.NamedTemporaryFile(mode="w", suffix=".cqp", delete=False, encoding="utf-8") as tmp:
            tmp.write(script)
            tmp_path = Path(tmp.name)

        cqp_bin = self._resolve_cqp_binary(cfg)
        cmd = [cqp_bin]
        if cfg.registry:
            cmd.extend(["-r", cfg.registry])
        if cfg.corpus:
            cmd.extend(["-D", cfg.corpus])
        cmd.extend(["-f", str(tmp_path)])

        prefix = f"[flexicorp][cqp][{label}] " if label else "[flexicorp][cqp] "
        if debug:
            print(prefix + "Running CQP command: " + " ".join(cmd), file=sys.stderr)
            print(prefix + "CQP script:", file=sys.stderr)
            print(script.strip(), file=sys.stderr)

        proc: Optional[subprocess.Popen[bytes]] = None
        completed = False
        lines: List[str] = []
        stderr_text = ""
        try:
            proc = subprocess.Popen(
                cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            assert proc.stdout is not None

            while len(lines) < collect_limit:
                raw_line = proc.stdout.readline()
                if not raw_line:
                    break
                line = self._decode_cqp_output(raw_line, cfg.encoding).strip()
                if line:
                    lines.append(line)

            if len(lines) < collect_limit:
                _, stderr_bytes = proc.communicate()
                stderr_text = self._decode_cqp_output(stderr_bytes, cfg.encoding)
                completed = proc.returncode == 0
            else:
                proc.terminate()
                try:
                    _, stderr_bytes = proc.communicate(timeout=1)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    _, stderr_bytes = proc.communicate()
                stderr_text = self._decode_cqp_output(stderr_bytes, cfg.encoding)
                completed = False
        finally:
            try:
                tmp_path.unlink(missing_ok=True)
            except OSError:
                pass

        if debug and stderr_text:
            print(prefix + "CQP stderr:", file=sys.stderr)
            print(stderr_text, file=sys.stderr)

        if completed and proc is not None and proc.returncode != 0:
            raise RuntimeError(f"CQP command failed: {stderr_text}")
        return lines, completed

    def _ensure_writable_cache_dir(self, path: Path) -> Optional[Path]:
        """
        Ensure a cache directory exists and is writable by creating a tiny probe file.
        Returns the usable path or None when the directory cannot be used.
        """
        try:
            path.mkdir(parents=True, exist_ok=True)
            probe = path / ".flexicorp-write-test"
            with probe.open("w", encoding="utf-8") as fh:
                fh.write("ok")
            probe.unlink(missing_ok=True)
            return path
        except OSError:
            return None

    def _get_query_cache_dir(self, project: Dict[str, Any]) -> Path:
        candidates: List[Path] = []
        root = project.get("root")
        if root:
            candidates.append(Path(root).expanduser().resolve() / "tmp" / "flexicorp-query-cache")

        # Per-user cache locations
        home = Path.home()
        candidates.append(home / "Library" / "Caches" / "flexicorp-query-cache")
        candidates.append(home / ".cache" / "flexicorp-query-cache")

        # Last resort: system temp dir
        candidates.append(Path(tempfile.gettempdir()) / "flexicorp-query-cache")

        for candidate in candidates:
            usable = self._ensure_writable_cache_dir(candidate)
            if usable is not None:
                return usable

        raise RuntimeError(
            "Could not create a writable cache directory for CQP query results. "
            "Tried project tmp, user cache, and system temp."
        )

    def _make_query_id(
        self,
        cfg: CqpConfig,
        query: str,
        *,
        mode: str,
        level: str,
    ) -> str:
        digest = hashlib.sha256(
            json.dumps(
                {
                    "cache_version": self.query_cache_version,
                    "backend": self.name,
                    "corpus": cfg.corpus,
                    "registry": cfg.registry,
                    "query": query,
                    "mode": mode,
                    "level": level,
                },
                sort_keys=True,
                ensure_ascii=False,
            ).encode("utf-8")
        ).hexdigest()
        return digest[:24]

    def _get_query_cache_paths(self, cache_dir: Path, qid: str) -> tuple[Path, Path]:
        return cache_dir / f"{qid}.bin", cache_dir / f"{qid}.json"

    @staticmethod
    def _uses_xml_context(context_spec: Optional[Dict[str, Any]]) -> bool:
        if not context_spec:
            return False
        return str(context_spec.get("format") or "xml").strip().lower() == "xml"

    @staticmethod
    def _cqp_kwic_delimiter() -> str:
        return "--%%%--"

    @staticmethod
    def _strip_cqp_cat_position_prefix(line: str) -> str:
        return re.sub(r"^\s*\d+:\s*", "", line, count=1)

    def _cqp_context_command(self, context_spec: Optional[Dict[str, Any]], *, window: int) -> Optional[str]:
        if not context_spec:
            return None
        scope = str(context_spec.get("scope") or "").strip().lower()
        if not scope:
            scope = "s"
        if scope.startswith("<") and scope.endswith(">") and len(scope) > 2:
            scope = scope[1:-1].strip().lower()
        if scope in {"window", "tok", "token", "tokens", "word", "words"}:
            return f"set Context {max(0, window)} words;"
        if re.fullmatch(r"\d+", scope):
            return f"set Context {int(scope)} words;"
        return f"set Context {scope};"

    def _parse_cqp_assignment(self, query: str) -> tuple[str, str]:
        """
        If the query is a named assignment like `A = [lemma="a.*"]`,
        returns ("A", query). Otherwise returns ("Matches", f"Matches = {query};").
        """
        q = query.strip()
        m = re.match(r"^([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$", q)
        if m:
            return m.group(1), q + ";" if not q.endswith(";") else q
        return "Matches", f"Matches = {q};"

    def _build_cqp_cat_script(
        self,
        query: str,
        *,
        start: int,
        max_hits: int,
        context_spec: Optional[Dict[str, Any]],
        window: int,
    ) -> str:
        lines: List[str] = []
        context_cmd = self._cqp_context_command(context_spec, window=window)
        if context_cmd:
            lines.append(context_cmd)
        kwic_delim = self._cqp_kwic_delimiter()
        lines.append(f'set LeftKWICDelim "{kwic_delim}";')
        lines.append(f'set RightKWICDelim "{kwic_delim}";')
        target_name, assignment_stmt = self._parse_cqp_assignment(query)
        lines.append(assignment_stmt)
        start_idx = max(1, start + 1)
        end_idx = max(start_idx, start + max_hits)
        lines.append(f"cat {target_name} {start_idx} {end_idx};")
        return "\n".join(lines) + "\n"

    def _fetch_cqp_cat_slice(
        self,
        cfg: CqpConfig,
        query: str,
        *,
        start: int,
        max_hits: int,
        context_spec: Optional[Dict[str, Any]],
        window: int,
        debug: bool,
        label: str,
    ) -> List[str]:
        if max_hits <= 0:
            return []
        script = self._build_cqp_cat_script(
            query,
            start=start,
            max_hits=max_hits,
            context_spec=context_spec,
            window=window,
        )
        out = self._run_cqp(cfg, script, debug=debug, label=label)
        lines: List[str] = []
        for raw_line in out.splitlines():
            if raw_line.strip():
                lines.append(self._strip_cqp_cat_position_prefix(raw_line.rstrip("\r\n")))
        return lines

    def _build_query_token_groups(
        self,
        *,
        query_text: str,
        query_lang: str,
        toks: List[str],
        legend: Optional[List[Dict[str, Any]]] = None,
    ) -> List[Dict[str, Any]]:
        if not toks:
            return []
        normalized_lang = str(query_lang or "").strip().lower()
        if normalized_lang not in {"cwb-cql", "cwb", "cql", "cqp"}:
            return []
        try:
            parsed = parse_cwb_cql(query_text)
        except Exception:
            return []
        if not isinstance(parsed.pattern, SequencePattern):
            return []
        items = list(parsed.pattern.items or [])
        if not items:
            return []
        legend_by_id = {
            str(item.get("id")): item
            for item in (legend or [])
            if isinstance(item, dict) and item.get("id")
        }
        groups: List[Dict[str, Any]] = []
        for idx, tok_id in enumerate(toks[: len(items)]):
            group_id = f"t{idx + 1}"
            group: Dict[str, Any] = {
                "id": group_id,
                "name": group_id,
                "tok_ids": [str(tok_id)],
            }
            legend_item = legend_by_id.get(group_id)
            if legend_item:
                for key in ("label", "query_span", "color", "textColor"):
                    if key in legend_item:
                        group[key] = legend_item[key]
            groups.append(group)
        return groups

    def _read_query_cache_meta(self, meta_path: Path) -> Optional[Dict[str, Any]]:
        if not meta_path.is_file():
            return None
        try:
            with meta_path.open("r", encoding="utf-8") as fh:
                return json.load(fh)
        except Exception:
            return None

    def _write_query_cache_meta(self, meta_path: Path, meta: Dict[str, Any]) -> None:
        with meta_path.open("w", encoding="utf-8") as fh:
            json.dump(meta, fh, ensure_ascii=False, indent=2)

    def _read_query_cache_meta_by_qid(self, cache_dir: Path, qid: str) -> Optional[Dict[str, Any]]:
        if not qid:
            return None
        _, meta_path = self._get_query_cache_paths(cache_dir, qid)
        meta = self._read_query_cache_meta(meta_path)
        if not isinstance(meta, dict):
            return None
        if meta.get("cache_version") != self.query_cache_version:
            return None
        query = meta.get("query")
        if not isinstance(query, str) or not query.strip():
            return None
        return meta

    def _is_query_cache_meta_valid(
        self,
        meta: Optional[Dict[str, Any]],
        *,
        mode: str,
        query: str,
        level: Optional[str] = None,
    ) -> bool:
        if not isinstance(meta, dict):
            return False
        if meta.get("cache_version") != self.query_cache_version:
            return False
        if meta.get("mode") != mode:
            return False
        if meta.get("query") != query:
            return False
        if level is not None and meta.get("level") != level:
            return False
        total = meta.get("total")
        return isinstance(total, int) and total >= 0

    def _count_cached_result_lines(self, data_path: Path) -> int:
        if not data_path.is_file():
            return 0
        with data_path.open("rb") as fh:
            return sum(1 for raw_line in fh if raw_line.strip())

    def _normalize_context_request(self, params: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        return normalize_context_request(params)

    def _read_query_cache_slice(
        self,
        data_path: Path,
        *,
        start: int,
        max_hits: int,
        preferred_encoding: str | None,
    ) -> List[str]:
        lines: List[str] = []
        if not data_path.is_file():
            return lines
        with data_path.open("rb") as fh:
            for idx, raw_line in enumerate(fh):
                if idx < start:
                    continue
                if len(lines) >= max_hits:
                    break
                line = self._decode_cqp_output(raw_line, preferred_encoding).strip()
                if line:
                    lines.append(line)
        return lines

    def _decode_cqp_output(self, data: bytes | str | None, preferred_encoding: str | None = None) -> str:
        """
        Decode CQP output robustly.

        Many local corpora are not UTF-8 clean, and some outputs are effectively
        mixed: most lines are UTF-8 but a few can be in a legacy Central European
        encoding. Decode line-by-line so valid UTF-8 lines stay intact even if a
        later line needs a fallback codec.
        """
        if data is None:
            return ""
        if isinstance(data, str):
            return data

        encodings: List[str] = ["utf-8"]
        if preferred_encoding and preferred_encoding.lower() not in {"utf-8", "utf8"}:
            encodings.append(preferred_encoding)
        preferred = locale.getpreferredencoding(False)
        if preferred and preferred.lower() not in {"utf-8", "utf8"}:
            encodings.append(preferred)
        # Common legacy encodings for TEITOK/CQP corpora in Central Europe.
        encodings.extend(["cp1250", "iso-8859-2", "latin-2", "latin-1", "cp1252"])

        def decode_chunk(chunk: bytes) -> str:
            for encoding in encodings:
                try:
                    return chunk.decode(encoding)
                except UnicodeDecodeError:
                    continue
            return chunk.decode("utf-8", errors="replace")

        parts: List[str] = []
        for chunk in data.splitlines(keepends=True):
            parts.append(decode_chunk(chunk))
        if parts:
            return "".join(parts)

        for encoding in encodings:
            try:
                return data.decode(encoding)
            except UnicodeDecodeError:
                continue
        return data.decode("utf-8", errors="replace")

    def _estimate_tokens_count(
        self, cfg: CqpConfig, project_root: Optional[str]
    ) -> Optional[int]:
        """
        Estimate token count directly from CWB index files.

        This does not depend on TEITOK; it only needs a registry directory/file
        and the corpus name. It looks for the registry file, parses the HOME
        directive, and then inspects word.corpus to compute:
            tokens_count = filesize(word.corpus) / 4
        """
        registry_path: Optional[Path]
        if cfg.registry:
            registry_path = Path(cfg.registry)
        else:
            registry_path = None

        registry_file: Optional[Path] = None
        if registry_path:
            if registry_path.is_dir():
                candidate = registry_path / cfg.corpus.lower()
                if candidate.is_file():
                    registry_file = candidate
            elif registry_path.is_file():
                registry_file = registry_path

        home_dir: Optional[Path] = None
        if registry_file and registry_file.is_file():
            try:
                with registry_file.open("r", encoding="utf-8", errors="ignore") as f:
                    for line in f:
                        line = line.strip()
                        if not line or line.startswith("#"):
                            continue
                        if line.startswith("HOME"):
                            parts = line.split(None, 1)
                            if len(parts) == 2:
                                home_dir = Path(parts[1].strip())
                            break
            except OSError:
                home_dir = None

        candidates: list[Path] = []
        if home_dir:
            candidates.append(home_dir)
        if registry_file:
            candidates.append(registry_file.parent)
        if project_root:
            pr = Path(project_root)
            candidates.append(pr / "cqp")
            candidates.append(pr)

        for base in candidates:
            word_file = base / "word.corpus"
            if word_file.is_file():
                try:
                    size = word_file.stat().st_size
                    return size // 4
                except OSError:
                    continue
        return None

    def _find_executable(self, name: str, extra_candidates: Optional[List[str]] = None) -> str:
        """
        Locate an executable, trying PATH first and then a list of explicit paths.

        This mirrors TEITOK's behaviour of preferring installed tools in
        standard locations like /usr/local/bin but is slightly more forgiving
        by also checking PATH.
        """
        path = shutil.which(name)
        if path:
            return path

        for cand in extra_candidates or []:
            cand_path = Path(cand)
            if cand_path.is_file() and os.access(cand_path, os.X_OK):
                return str(cand_path)

        searched = [p for p in (extra_candidates or []) if p]
        raise RuntimeError(
            f"Required executable '{name}' not found on PATH"
            + (f" or at: {', '.join(searched)}" if searched else "")
        )

    def _find_flexencoder(self, project_root: Path) -> Optional[str]:
        """
        Locate flexencoder for TEITOK reindex (optional). Prefer project Scripts,
        then TT_ROOT/Scripts, then PATH. Returns path or None if not found.
        """
        candidates: List[Path] = []
        scripts = project_root / "Scripts" / "flexencoder"
        if scripts.is_file() and os.access(scripts, os.X_OK):
            return str(scripts)
        tt_root = os.environ.get("TT_ROOT")
        if tt_root:
            candidates.append(Path(tt_root) / "Scripts" / "flexencoder")
        candidates.extend([
            Path("/usr/local/bin/flexencoder"),
            Path("/opt/homebrew/bin/flexencoder"),
            Path("/usr/bin/flexencoder"),
        ])
        for cand in candidates:
            if cand.is_file() and os.access(cand, os.X_OK):
                return str(cand)
        path = shutil.which("flexencoder")
        return path if path else None

    @staticmethod
    @lru_cache(maxsize=256)
    def _read_be_uints_file(path_str: str) -> tuple[int, ...]:
        path = Path(path_str)
        data = path.read_bytes()
        if len(data) % 4 != 0:
            raise RuntimeError(f"Invalid CWB integer component size for '{path}'.")
        return tuple(row[0] for row in struct.iter_unpack(">I", data))

    @staticmethod
    @lru_cache(maxsize=256)
    def _read_binary_file(path_str: str) -> bytes:
        return Path(path_str).read_bytes()

    @staticmethod
    def _read_binary_range(path: Path, start: int, end: int) -> bytes:
        if start < 0 or end < start:
            return b""
        length = end - start
        if length <= 0:
            return b""
        with path.open("rb") as fh:
            fh.seek(start)
            return fh.read(length)

    @staticmethod
    def _read_c_string(data: bytes, offset: int) -> str:
        if offset < 0 or offset >= len(data):
            return ""
        end = data.find(b"\0", offset)
        if end < 0:
            end = len(data)
        return data[offset:end].decode("utf-8", errors="replace")

    def _range_index_at(self, range_path: Path, pos: int) -> Optional[int]:
        numbers = self._read_be_uints_file(str(range_path))
        if not numbers:
            return None
        starts = numbers[0::2]
        idx = bisect.bisect_right(starts, pos) - 1
        if idx < 0:
            return None
        end = numbers[idx * 2 + 1]
        if end < pos:
            return None
        return idx

    def _resolve_xidx_xml_path(self, root_dir: Path, xmlfile: str, doc_id: str) -> Optional[Path]:
        candidates: List[Path] = []
        raw_xmlfile = (xmlfile or "").strip()
        raw_doc_id = (doc_id or "").strip()

        if raw_xmlfile:
            xml_path = Path(raw_xmlfile).expanduser()
            if xml_path.is_absolute():
                candidates.append(xml_path)
            else:
                candidates.append((root_dir / raw_xmlfile).resolve())
        if raw_doc_id:
            doc_path = Path(raw_doc_id).expanduser()
            if doc_path.is_absolute():
                candidates.append(doc_path)
            else:
                candidates.append((root_dir / raw_doc_id).resolve())

        seen: set[str] = set()
        for candidate in candidates:
            key = str(candidate)
            if key in seen:
                continue
            seen.add(key)
            if candidate.is_file():
                return candidate
        return None

    def _read_struct_attributes(
        self, cfg: CqpConfig, project_root: Optional[str]
    ) -> List[str]:
        """
        Read structural attributes from the CWB registry file, if available.

        Returns the raw STRUCTURE names as defined in the registry, e.g.:
            ["text", "text_title", "text_summary", "text_year", "text_id", ...]
        """
        registry_path: Optional[Path]
        if cfg.registry:
            registry_path = Path(cfg.registry)
        else:
            registry_path = None

        registry_file: Optional[Path] = None
        if registry_path:
            if registry_path.is_dir():
                candidate = registry_path / cfg.corpus.lower()
                if candidate.is_file():
                    registry_file = candidate
            elif registry_path.is_file():
                registry_file = registry_path

        if not registry_file or not registry_file.is_file():
            return []

        struct_names: List[str] = []
        try:
            with registry_file.open("r", encoding="utf-8", errors="ignore") as f:
                for line in f:
                    line = line.strip()
                    if not line or line.startswith("#"):
                        continue
                    if line.startswith("STRUCTURE"):
                        parts = line.split()
                        if len(parts) >= 2:
                            struct_names.append(parts[1])
        except OSError:
            return []

        return struct_names

    def _cqp_freq_group_field_variants(self, field: str) -> List[str]:
        """
        TEITOK exposes document s-attributes as text_<name> (e.g. text_genre). The CWB registry
        typically has a single STRUCTURE line 'text_genre', but some CQP builds parse
        'group ... match text_genre' as structure 'text' + attribute 'genre', which fails
        when the index only has the compound name. Try a double-quoted identifier first so the
        lexer keeps 'text_genre' as one token, then fall back to the bare name.
        """
        raw = str(field).strip()
        if not raw:
            return []
        out: List[str] = []
        if raw.startswith("text_"):
            esc = raw.replace("\\", "\\\\").replace('"', '\\"')
            out.append(f'"{esc}"')
        if raw not in out:
            out.append(raw)
        return out

    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        params = dict(req.get("params") or {})
        debug = bool(params.get("debug"))
        # Minimal stub: rely on CQP to report corpus size. We rely on -D
        # to select the corpus, so no CORPUS statement is needed here.
        script = "SIZE;"
        out = self._run_cqp(cfg, script, debug=debug, label="status")
        # Output parsing is backend-specific; for now just return raw text.
        return {
            "backend": self.name,
            "raw_status": out,
        }

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})

        limit = int(params.get("limit", 50))
        offset = int(params.get("offset", 0))

        verbose = bool(params.get("verbose"))
        debug = bool(params.get("debug"))
        filter_text = str(params.get("filter") or "").strip().lower()

        # Canonical implementation: use CQP tabulate over <text> to get document
        # IDs and text-level metadata via text_id and friends. This reflects what
        # is actually present in the CWB index, independent of any TEITOK xmlfiles/.
        try:
            struct_names = self._read_struct_attributes(cfg, project.get("root"))
            text_structs = [name for name in struct_names if name.startswith("text_")]
            if not text_structs:
                raise RuntimeError("No text_* structural attributes found in registry.")

            # Derive attribute keys (id, title, year, ...) from text_* names.
            attr_keys: List[str] = []
            for name in text_structs:
                _, suffix = name.split("_", 1)
                if suffix not in attr_keys:
                    attr_keys.append(suffix)
            # Ensure 'id' is first if present; this will become the primary doc id.
            if "id" in attr_keys:
                attr_keys.remove("id")
                attr_keys.insert(0, "id")

            # Build tabulate columns: match text_id, match text_title, ...
            tab_cols: List[str] = []
            for key in attr_keys:
                if key == "id":
                    tab_cols.append("match text_id")
                else:
                    tab_cols.append(f"match text_{key}")

            cols_expr = ", ".join(tab_cols)
            script = f"""
            Matches = <text> [];
            tabulate Matches {cols_expr};
            """
            out = self._run_cqp(cfg, script, debug=debug, label="list_docs_verbose")
            docs: List[Dict[str, Any]] = []
            for line in out.strip().splitlines():
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                parts = line.split("\t")
                if len(parts) < 1:
                    continue
                # Map columns back to attribute keys
                meta: Dict[str, Any] = {}
                for idx, key in enumerate(attr_keys):
                    if idx < len(parts):
                        meta[key] = parts[idx]

                doc_id = meta.get("id") or parts[0]
                title = meta.get("title") or doc_id
                doc: Dict[str, Any] = {
                    "id": doc_id,
                    "title": title,
                    "meta": meta,
                }
                # Only include date if we actually have a value (e.g. from text_year)
                date = meta.get("year")
                if date:
                    doc["date"] = date
                docs.append(doc)

            # Apply simple metadata filter if provided (id/title/meta values).
            if filter_text:
                def _matches_filter(d: Dict[str, Any]) -> bool:
                    buf = [str(d.get("id", "")), str(d.get("title", ""))]
                    meta = d.get("meta") or {}
                    for v in meta.values():
                        if isinstance(v, list):
                            buf.extend(str(x) for x in v)
                        else:
                            buf.append(str(v))
                    haystack = " ".join(buf).lower()
                    return filter_text in haystack

                docs = [d for d in docs if _matches_filter(d)]

            total = len(docs)
            sliced = docs[offset : offset + limit]
            if sliced:
                return {"docs": sliced, "total": total}
        except Exception:
            # Fall back to stub if grouping is not possible.
            pass

        # Final fallback: stub for non-TEITOK corpora without text_id grouping.
        return {
            "docs": [],
            "total": 0,
            "warnings": [
                "CQP list_docs could not be derived for this corpus (no text_id grouping and no TEITOK xmlfiles folder).",
            ],
        }

    def query(self, req: FlexiRequest) -> Dict[str, Any]:
        """
        Run a CQP query with pagination. Returns the same result shape as clickql
        (total, start, hits) so the query API can be tested without ClickHouse.
        """
        cfg = self._get_config(req)
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        start = max(0, int(params.get("start", 0)))
        max_hits = max(0, min(int(params.get("max", 50)), 5000))
        window = max(0, min(int(params.get("window", 5)), 5000))
        debug = bool(params.get("debug"))
        refresh_cache = bool(params.get("refresh_cache"))
        cache_dir = self._get_query_cache_dir(project)
        requested_qid = str(params.get("qid") or "").strip()
        cached_meta = None if refresh_cache else self._read_query_cache_meta_by_qid(cache_dir, requested_qid)

        query = params.get("query")
        if isinstance(query, str) and query.strip():
            query_text = query.strip()
        elif cached_meta and isinstance(cached_meta.get("query"), str):
            query_text = str(cached_meta["query"]).strip()
        else:
            raise RuntimeError(
                "CQP query requires params['query'] to be a non-empty CQP query string, "
                "or params['qid'] referencing a cached query result."
            )

        query_text = self._normalize_simple_cqp_token_query(query_text)

        context_spec = self._normalize_context_request(params)
        if context_spec is None and cached_meta and isinstance(cached_meta.get("context_spec"), dict):
            context_spec = self._normalize_context_request({"context": cached_meta.get("context_spec")})

        query_lang = params.get("query_lang")
        if not isinstance(query_lang, str) or not query_lang.strip():
            if cached_meta and isinstance(cached_meta.get("query_lang"), str) and str(cached_meta["query_lang"]).strip():
                query_lang = str(cached_meta["query_lang"]).strip()
            else:
                query_lang = "cqp"
        legend = resolve_legend(params)

        detected = self._detect_teitok(cfg, project)
        level = str((context_spec or {}).get("scope") or (cached_meta or {}).get("level") or params.get("lvl") or "s")
        mode = str((cached_meta or {}).get("mode") or ("teitok" if detected else "generic"))
        if mode not in {"teitok", "generic"}:
            mode = "teitok" if detected else "generic"
        qid = requested_qid or self._make_query_id(cfg, query_text, mode=mode, level=level)

        hits: List[Dict[str, Any]]
        total: Optional[int]
        qid_result: Optional[str] = qid
        total_exact = True
        cache_mode = "cached"
        if mode == "teitok":
            if not detected:
                raise RuntimeError(
                    "Cached query expects a TEITOK-backed corpus, but TEITOK metadata could not be detected "
                    "for the current project."
                )
            teitok_result = self._query_teitok_hits(
                cfg,
                project,
                detected,
                query_text,
                qid=qid,
                cache_dir=cache_dir,
                start=start,
                max_hits=max_hits,
                refresh_cache=refresh_cache,
                debug=debug,
                context_spec=context_spec,
                window=window,
                level=level,
                query_lang=query_lang,
                legend=legend,
            )
            hits = teitok_result["hits"]
            total = teitok_result["total"]
            qid_result = teitok_result.get("qid")
            total_exact = bool(teitok_result.get("total_exact", total is not None))
            cache_mode = str(teitok_result.get("cache_mode") or cache_mode)
        else:
            generic_result = self._query_generic_hits(
                cfg,
                query_text,
                qid=qid,
                cache_dir=cache_dir,
                start=start,
                max_hits=max_hits,
                refresh_cache=refresh_cache,
                debug=debug,
                query_lang=query_lang,
                context_spec=context_spec,
            )
            total = generic_result["total"]
            slice_lines = generic_result["lines"]
            qid_result = generic_result.get("qid")
            total_exact = bool(generic_result.get("total_exact", total is not None))
            cache_mode = str(generic_result.get("cache_mode") or cache_mode)
            hits = []
            for line in slice_lines:
                hit = self._parse_cqp_cat_line(line)
                hits.append(hit)

        result: Dict[str, Any] = {
            "start": start,
            "hits": hits,
            "returned": len(hits),
            "query_lang": query_lang,
            "legend": legend,
            "total_exact": total_exact,
            "cache_mode": cache_mode,
        }
        result["total"] = total
        if qid_result:
            result["qid"] = qid_result
        return result

    def _query_teitok_hits(
        self,
        cfg: CqpConfig,
        project: Dict[str, Any],
        detected: Dict[str, Any],
        query: str,
        *,
        qid: str,
        cache_dir: Path,
        start: int,
        max_hits: int,
        refresh_cache: bool,
        debug: bool,
        context_spec: Optional[Dict[str, Any]],
        window: int,
        level: str,
        query_lang: str,
        legend: List[Dict[str, Any]],
    ) -> Dict[str, Any]:
        """
        TEITOK-mode query extraction.

        Mirrors TEITOK's `query-CQL.php` logic, but also includes corpus
        positions (`match`, `matchend`) so `tt-cwb-xidx --expand=s` can extract
        the fragment directly from the CWB/xidx data when available.

        We count the tabulated lines directly for the total, which is slower
        than SIZE but avoids misparsing noisy CQP output and is exact.
        but only requests `match s_id` when that attribute really exists in the
        CWB registry. `text_id` and token `id` are treated as the stable TEITOK
        identifiers; `s_id` is optional.
        """
        struct_names = self._read_struct_attributes(cfg, detected.get("root"))
        sentence_attr = f"{level}_id"
        has_sentence_id = sentence_attr in struct_names

        # Prefer probing what the CQP registry actually exports.
        # TEITOK's fwsearch expects positional `bbox` and `facs` columns; in some
        # corpora those are compiled from <pb/> (not stored on tokens in the XML),
        # but they still show up as CQP positional attributes.
        include_bbox_facs = False
        cqp_folder = Path(cfg.registry) if cfg.registry else None
        if cqp_folder is None:
            root_dir = Path(detected.get("root") or ".").resolve()
            cqp_folder = root_dir / "cqp"
        if cqp_folder.is_file():
            cqp_folder = cqp_folder.parent
        try:
            include_bbox_facs = (cqp_folder / "bbox.lexicon").is_file() and (cqp_folder / "facs.lexicon").is_file()
        except Exception:
            include_bbox_facs = False

        if has_sentence_id:
            # Optionally include match bbox/facs so the frontend can show a bbox cutout
            # even when the XML context fragment doesn't carry @facs (observed for MNL).
            if include_bbox_facs:
                tabulate_expr = (
                    f"match text_id, match {sentence_attr}, match, matchend, match bbox, match facs, match[0]..matchend[0] id"
                )
            else:
                tabulate_expr = f"match text_id, match {sentence_attr}, match, matchend, match[0]..matchend[0] id"
        else:
            if include_bbox_facs:
                tabulate_expr = "match text_id, match, matchend, match bbox, match facs, match[0]..matchend[0] id"
            else:
                tabulate_expr = "match text_id, match, matchend, match[0]..matchend[0] id"
        data_path, meta_path = self._get_query_cache_paths(cache_dir, qid)
        meta = None if refresh_cache else self._read_query_cache_meta(meta_path)
        if not self._is_query_cache_meta_valid(meta, mode="teitok", query=query, level=level):
            meta = None

        target_name, assignment_stmt = self._parse_cqp_assignment(query)
        script = (
            f"{assignment_stmt}\n"
            f"tabulate {target_name} {tabulate_expr};\n"
        )
        if meta is None or not data_path.is_file():
            if start == 0 and max_hits > 0 and not refresh_cache:
                # Get total hit count so the UI can show "Returned X of Y hit(s)".
                preview_total: Optional[int] = None
                try:
                    size_script = f"{assignment_stmt}\nsize {target_name};\n"
                    size_stdout, size_stderr = self._run_cqp_capture_both(
                        cfg, size_script, debug=debug, label="query-size-preview"
                    )
                    preview_total = (
                        self._parse_cqp_size(size_stdout)
                        or self._parse_cqp_size(size_stderr)
                        or self._parse_cqp_size(f"{size_stdout}\n{size_stderr}")
                    )
                except Exception:
                    preview_total = None
                if preview_total is not None:
                    # We have a total; use fast preview (tabulate first N lines only).
                    try:
                        preview_lines, completed = self._run_cqp_collect_lines(
                            cfg,
                            script,
                            max_lines=max_hits,
                            debug=debug,
                            label="query-tabulate-preview",
                        )
                    except RuntimeError as e:
                        raise RuntimeError(f"CQP tabulate failed: {e}") from e
                    if completed and len(preview_lines) != preview_total:
                        preview_total = len(preview_lines)
                    preview_slice = preview_lines[:max_hits]
                    raw_lines = (
                        self._fetch_cqp_cat_slice(
                            cfg,
                            query,
                            start=start,
                            max_hits=len(preview_slice),
                            context_spec=context_spec,
                            window=window,
                            debug=debug,
                            label="query-cat-preview",
                        )
                        if context_spec and not self._uses_xml_context(context_spec)
                        else None
                    )
                    return self._build_teitok_hits_result(
                        cfg=cfg,
                        detected=detected,
                        has_sentence_id=has_sentence_id,
                        slice_lines=preview_slice,
                        raw_lines=raw_lines,
                        context_spec=context_spec,
                        total=preview_total,
                        qid=None,
                        total_exact=True,
                        cache_mode="preview",
                        query_text=query,
                        query_lang=query_lang,
                        legend=legend,
                    )
                # SIZE parse failed: fall through to full path so total comes from file count.

            size_script = f"{assignment_stmt}\nsize {target_name};\n"
            try:
                size_stdout, size_stderr = self._run_cqp_capture_both(
                    cfg, size_script, debug=debug, label="query-size"
                )
                total = (
                    self._parse_cqp_size(size_stdout)
                    or self._parse_cqp_size(size_stderr)
                    or self._parse_cqp_size(f"{size_stdout}\n{size_stderr}")
                )
            except RuntimeError as e:
                raise RuntimeError(f"CQP query failed: {e}") from e
            try:
                self._run_cqp_to_file(cfg, script, data_path, debug=debug, label="query-tabulate-cache")
            except RuntimeError as e:
                raise RuntimeError(f"CQP tabulate failed: {e}") from e
            if total is None:
                total = self._count_cached_result_lines(data_path)
            meta = {
                "cache_version": self.query_cache_version,
                "qid": qid,
                "mode": "teitok",
                "query": query,
                "query_lang": query_lang,
                "context_spec": context_spec,
                "level": level,
                "has_sentence_id": has_sentence_id,
                "total": total,
                "cache_file": str(data_path),
                "project_root": str(project.get("root") or detected.get("root") or ""),
            }
            self._write_query_cache_meta(meta_path, meta)

        total = meta["total"] if isinstance(meta, dict) else 0
        slice_lines = self._read_query_cache_slice(
            data_path,
            start=start,
            max_hits=max_hits,
            preferred_encoding=cfg.encoding,
        )
        raw_lines = (
            self._fetch_cqp_cat_slice(
                cfg,
                query,
                start=start,
                max_hits=len(slice_lines),
                context_spec=context_spec,
                window=window,
                debug=debug,
                label="query-cat-slice",
            )
            if context_spec and not self._uses_xml_context(context_spec)
            else None
        )

        return self._build_teitok_hits_result(
            cfg=cfg,
            detected=detected,
            has_sentence_id=has_sentence_id,
            slice_lines=slice_lines,
            raw_lines=raw_lines,
            context_spec=context_spec,
            total=total,
            qid=qid,
            total_exact=True,
            cache_mode="cached",
            query_text=query,
            query_lang=query_lang,
            legend=legend,
        )

    def _build_teitok_hits_result(
        self,
        *,
        cfg: CqpConfig,
        detected: Dict[str, Any],
        has_sentence_id: bool,
        slice_lines: List[str],
        raw_lines: Optional[List[str]],
        context_spec: Optional[Dict[str, Any]],
        total: Optional[int],
        qid: Optional[str],
        total_exact: bool,
        cache_mode: str,
        query_text: str,
        query_lang: str,
        legend: List[Dict[str, Any]],
    ) -> Dict[str, Any]:
        root_dir = Path(detected.get("root") or ".").resolve()
        meta = detected.get("meta") or {}
        searchfolder = str(meta.get("searchfolder") or "xmlfiles")

        hits: List[Dict[str, Any]] = []
        for idx, line in enumerate(slice_lines):
            hit = self._parse_cqp_tabulate_line(line, has_sentence_id=has_sentence_id)
            if hit.get("toks"):
                hit["highlight_map"] = build_highlight_map(
                    hit["toks"],
                    groups=self._build_query_token_groups(
                        query_text=query_text,
                        query_lang=query_lang,
                        toks=[str(tok) for tok in hit["toks"]],
                        legend=legend,
                    ),
                )
            if raw_lines and idx < len(raw_lines) and raw_lines[idx].strip():
                hit["raw"] = raw_lines[idx].strip()
            if self._uses_xml_context(context_spec) and hit.get("doc_id"):
                context = self._resolve_teitok_context(
                    cfg=cfg,
                    root_dir=root_dir,
                    searchfolder=searchfolder,
                    doc_id=str(hit["doc_id"]),
                    sentence_id=str(hit["sentence_id"]) if hit.get("sentence_id") else None,
                    tok_ids=[str(tok) for tok in (hit.get("toks") or [])],
                    match_start=hit.get("match_start"),
                    match_end=hit.get("match_end"),
                    context_spec=context_spec,
                )
                if context:
                    hit["context"] = context
            hits.append(hit)
        return {
            "total": total,
            "hits": hits,
            "qid": qid,
            "total_exact": total_exact,
            "cache_mode": cache_mode,
        }

    def _query_generic_hits(
        self,
        cfg: CqpConfig,
        query: str,
        *,
        qid: str,
        cache_dir: Path,
        start: int,
        max_hits: int,
        refresh_cache: bool,
        debug: bool,
        query_lang: str,
        context_spec: Optional[Dict[str, Any]],
    ) -> Dict[str, Any]:
        """
        Generic CQP query caching: use SIZE for totals and cache full `cat total`
        output to a file so later pages do not rerun the query.
        """
        data_path, meta_path = self._get_query_cache_paths(cache_dir, qid)
        meta = None if refresh_cache else self._read_query_cache_meta(meta_path)
        if not self._is_query_cache_meta_valid(meta, mode="generic", query=query):
            meta = None

        if meta is None or not data_path.is_file():
            if start == 0 and max_hits > 0 and not refresh_cache:
                cat_preview_script = f"{query};\ncat {max_hits + 1};\n"
                try:
                    preview_out = self._run_cqp(cfg, cat_preview_script, debug=debug, label="query-cat-preview")
                except RuntimeError as e:
                    raise RuntimeError(f"CQP cat failed: {e}") from e
                preview_lines = [line.strip() for line in preview_out.strip().splitlines() if line.strip()]
                preview_has_more = len(preview_lines) > max_hits
                return {
                    "total": None if preview_has_more else len(preview_lines),
                    "lines": preview_lines[:max_hits],
                    "qid": None,
                    "total_exact": not preview_has_more,
                    "cache_mode": "preview",
                }

            size_script = f"{query};\nSIZE;\n"
            try:
                size_out = self._run_cqp(cfg, size_script, debug=debug, label="query-size")
            except RuntimeError as e:
                raise RuntimeError(f"CQP query failed: {e}") from e
            total = self._parse_cqp_size(size_out)
            if total is None:
                raise RuntimeError("Could not parse CQP SIZE output for total hit count.")
            if total == 0:
                self._write_query_cache_meta(
                    meta_path,
                    {
                        "cache_version": self.query_cache_version,
                        "qid": qid,
                        "mode": "generic",
                        "query": query,
                        "query_lang": query_lang,
                        "context_spec": context_spec,
                        "total": 0,
                    },
                )
                return {"total": 0, "lines": []}
            cat_script = f"{query};\ncat {total};\n"
            try:
                self._run_cqp_to_file(cfg, cat_script, data_path, debug=debug, label="query-cat-cache")
            except RuntimeError as e:
                raise RuntimeError(f"CQP cat failed: {e}") from e
            meta = {
                "cache_version": self.query_cache_version,
                "qid": qid,
                "mode": "generic",
                "query": query,
                "query_lang": query_lang,
                "context_spec": context_spec,
                "total": total,
                "cache_file": str(data_path),
            }
            self._write_query_cache_meta(meta_path, meta)

        total = meta["total"] if isinstance(meta, dict) else 0
        lines = self._read_query_cache_slice(
            data_path,
            start=start,
            max_hits=max_hits,
            preferred_encoding=cfg.encoding,
        )
        return {
            "total": total,
            "lines": lines,
            "qid": qid,
            "total_exact": True,
            "cache_mode": "cached",
        }

    def _parse_cqp_size(self, out: str) -> Optional[int]:
        """Parse CQP SIZE command output to an integer without concatenating unrelated digits."""
        last_plain: Optional[int] = None
        for line in out.strip().splitlines():
            line = line.strip()
            if not line:
                continue
            # Plain integer line (e.g. "3445" from batch SIZE) – keep last so we skip prompts/echo
            m = re.match(r"^\s*(\d+)\s*$", line)
            if m:
                last_plain = int(m.group(1))
                continue
            # size: 3445 or size=3445
            m = re.match(r"^(?:size\s*[:=]\s*)?(\d+)\s*$", line, flags=re.IGNORECASE)
            if m:
                return int(m.group(1))
            # "3445 matches"
            m = re.match(r"^(\d+)\s+matches\.?$", line, flags=re.IGNORECASE)
            if m:
                return int(m.group(1))
            # Prompt-style line (e.g. "TT-TICO19> 3445" from interactive/batch)
            m = re.search(r">\s*(\d+)\s*$", line)
            if m:
                return int(m.group(1))
            if re.search(r"\b(size|matches?)\b", line, flags=re.IGNORECASE):
                numbers = re.findall(r"\d+", line)
                if len(numbers) == 1:
                    return int(numbers[0])
        return last_plain

    def _parse_cqp_group_output(self, out: str) -> List[Dict[str, Any]]:
        items: List[Dict[str, Any]] = []
        for line in out.strip().splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            parts = line.split("\t")
            if len(parts) < 2:
                continue
            count_text = parts[-1].strip()
            if not re.fullmatch(r"\d+", count_text):
                continue
            values = [part.strip() for part in parts[:-1]]
            if values:
                first = values[0]
                # CQP group output can prefix rows with "(all)" and pad columns with wide spaces,
                # e.g. "(all)                         o". Keep the actual grouped value.
                m = re.match(r"^\(all\)\s*(.*)$", first, flags=re.IGNORECASE)
                if m:
                    rest = m.group(1).strip()
                    if rest:
                        # CQP often separates padded columns with 2+ spaces.
                        cols = [c.strip() for c in re.split(r"\s{2,}", rest) if c.strip()]
                        values[0] = cols[-1] if cols else rest
                    else:
                        values[0] = ""
            item: Dict[str, Any] = {
                "value": values[0] if values else "",
                "count": int(count_text),
            }
            if len(values) > 1:
                item["values"] = values
            items.append(item)
        return items

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

    def _parse_cqp_cat_line(self, line: str) -> Dict[str, Any]:
        """
        Parse one line of CQP cat output into a hit dict.
        If line is tab-separated (e.g. textid\\tsentid\\tokens\\ttext from TEITOK-style),
        return doc_id, sentence_id, toks; otherwise return raw.
        """
        line = line.strip()
        if "\t" in line:
            parts = line.split("\t")
            if len(parts) >= 3:
                textid = parts[0].strip()
                sentid = parts[1].strip()
                toks_str = parts[2].strip()
                toks = [t.strip() for t in toks_str.split(",") if t.strip()] if toks_str else []
                if not textid.endswith(".xml"):
                    textid = textid + ".xml" if textid else ""
                return {
                    "doc_id": textid or None,
                    "sentence_id": sentid or None,
                    "toks": toks,
                    "row": {"doc_id": textid, "sentence_id": sentid, "toks": toks},
                    "raw": line,
                }
        return {"doc_id": None, "sentence_id": None, "toks": [], "raw": line}

    def _parse_cqp_tabulate_line(self, line: str, *, has_sentence_id: bool) -> Dict[str, Any]:
        """
        Parse one TEITOK-style CQP tabulate line:
            text_id <TAB> s_id <TAB> match <TAB> matchend <TAB> tok1 tok2 ...
        or, when sentence IDs are not present in CWB:
            text_id <TAB> match <TAB> matchend <TAB> tok1 tok2 ...
        """
        line = line.strip()
        parts = line.split("\t")
        min_parts = 5 if has_sentence_id else 4
        if len(parts) < min_parts:
            return {"doc_id": None, "sentence_id": None, "toks": [], "raw": line}

        textid = parts[0].strip()
        bbox_raw = ""
        facs_raw = ""
        if has_sentence_id:
            sentid = parts[1].strip()
            match_start = parts[2].strip()
            match_end = parts[3].strip()
            # New schema (optional bbox/facs columns):
            # text_id, s_id, match, matchend, bbox, facs, toks
            if len(parts) >= 7:
                bbox_raw = parts[4].strip()
                facs_raw = parts[5].strip()
                toks_str = parts[6].strip()
            else:
                toks_str = parts[4].strip()
        else:
            sentid = ""
            match_start = parts[1].strip()
            match_end = parts[2].strip()
            # New schema (optional bbox/facs columns):
            # text_id, match, matchend, bbox, facs, toks
            if len(parts) >= 6:
                bbox_raw = parts[3].strip()
                facs_raw = parts[4].strip()
                toks_str = parts[5].strip()
            else:
                toks_str = parts[3].strip()
        toks = [tok.strip() for tok in re.split(r"[,\s]+", toks_str) if tok.strip()]

        if textid and not textid.endswith(".xml"):
            textid += ".xml"
        sentence_id = sentid or None
        parsed_match_start = int(match_start) if match_start.isdigit() else None
        parsed_match_end = int(match_end) if match_end.isdigit() else None

        parsed_bbox: List[float] | None = None
        if bbox_raw and facs_raw:
            # fwsearch/tabulate uses a whitespace-separated bbox: "x1 y1 x2 y2".
            bbox_nums: List[float] = []
            for p in bbox_raw.split():
                try:
                    bbox_nums.append(float(p))
                except ValueError:
                    break
                if len(bbox_nums) >= 4:
                    break
            if len(bbox_nums) >= 4:
                parsed_bbox = bbox_nums[:4]
        normalized_raw = self._make_query_hit_raw(
            doc_id=textid or None,
            sentence_id=sentence_id,
            match_start=parsed_match_start,
            match_end=parsed_match_end,
            toks=toks,
        )

        return {
            "doc_id": textid or None,
            "sentence_id": sentence_id,
            "toks": toks,
            "row": {
                "doc_id": textid,
                "sentence_id": sentence_id,
                "toks": toks,
            },
            "match_start": parsed_match_start,
            "match_end": parsed_match_end,
            # Used by the frontend facsimile bbox cutout popup.
            "facs": facs_raw or None,
            "bbox": parsed_bbox,
            "raw": normalized_raw,
        }

    def _resolve_teitok_context(
        self,
        *,
        cfg: CqpConfig,
        root_dir: Path,
        searchfolder: str,
        doc_id: str,
        sentence_id: Optional[str],
        tok_ids: List[str],
        match_start: Optional[int],
        match_end: Optional[int],
        context_spec: Dict[str, Any],
    ) -> Optional[Dict[str, Any]]:
        return resolve_teitok_context(
            root_dir=root_dir,
            searchfolder=searchfolder,
            doc_id=doc_id,
            sentence_id=sentence_id,
            tok_ids=tok_ids,
            match_start=match_start,
            match_end=match_end,
            context_spec=context_spec,
            xidx_resolver=lambda doc_id_value, start_value, end_value, expand_level: self._extract_teitok_fragment_xidx(
                cfg=cfg,
                root_dir=root_dir,
                doc_id=doc_id_value,
                match_start=start_value,
                match_end=end_value,
                expand_level=expand_level,
            ),
        )

    def _extract_teitok_fragment_xml(
        self,
        *,
        root_dir: Path,
        searchfolder: str,
        doc_id: str,
        sentence_id: Optional[str],
        tok_ids: List[str],
        scope: str,
    ) -> tuple[Optional[str], str]:
        normalized_doc_id = doc_id.strip()
        searchfolder = searchfolder.strip("/").replace("\\", "/")
        if normalized_doc_id.startswith(searchfolder + "/"):
            normalized_doc_id = normalized_doc_id[len(searchfolder) + 1 :]
        elif normalized_doc_id.startswith("xmlfiles/"):
            normalized_doc_id = normalized_doc_id[len("xmlfiles/") :]

        xml_path = root_dir / searchfolder / normalized_doc_id
        if not xml_path.is_file():
            # Fall back to the raw doc_id relative to project root in case the
            # corpus stores a different folder prefix than searchfolder.
            alt_path = root_dir / doc_id
            if alt_path.is_file():
                xml_path = alt_path
            else:
                return None
        try:
            tree = ET.parse(xml_path)
            xml_root = tree.getroot()
        except ET.ParseError:
            return None, scope

        target_tag = scope
        parent_map = {child: parent for parent in xml_root.iter() for child in parent}
        matched_tokens: List[ET.Element] = []
        tok_id_set = set(tok_ids)
        if tok_ids:
            for elem in xml_root.iter():
                local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
                if local_tag in {"tok", "dtok"} and elem.get("id") in tok_id_set:
                    matched_tokens.append(elem)

        if scope == "tok":
            if matched_tokens:
                xml_parts = [ET.tostring(tok, encoding="unicode") for tok in matched_tokens]
                return " ".join(xml_parts), "tok"
            return None, "tok"

        if sentence_id:
            for elem in xml_root.iter():
                local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
                if local_tag == target_tag and elem.get("id") == sentence_id:
                    return ET.tostring(elem, encoding="unicode"), target_tag

        if tok_ids:
            for elem in xml_root.iter():
                local_tag = elem.tag.split("}", 1)[-1] if "}" in elem.tag else elem.tag
                if local_tag != target_tag:
                    continue
                same_as = elem.get("sameAs") or ""
                if same_as:
                    same_as_ids = {part.lstrip("#") for part in same_as.split() if part.strip()}
                    if tok_id_set & same_as_ids:
                        return ET.tostring(elem, encoding="unicode"), target_tag
            if matched_tokens:
                for tok in matched_tokens:
                    current = tok
                    while current is not None:
                        local_tag = current.tag.split("}", 1)[-1] if "}" in current.tag else current.tag
                        if local_tag == target_tag:
                            return ET.tostring(current, encoding="unicode"), target_tag
                        current = parent_map.get(current)
                # No enclosing sentence: fall back to the closest parent block.
                first_tok = matched_tokens[0]
                parent = parent_map.get(first_tok)
                if parent is not None:
                    local_tag = parent.tag.split("}", 1)[-1] if "}" in parent.tag else parent.tag
                    return ET.tostring(parent, encoding="unicode"), local_tag
                return ET.tostring(first_tok, encoding="unicode"), "tok"

        return None, scope

    def _extract_teitok_fragment_xidx(
        self,
        *,
        cfg: CqpConfig,
        root_dir: Path,
        doc_id: str,
        match_start: int,
        match_end: int,
        expand_level: Optional[str],
    ) -> Optional[str]:
        """Resolve TEITOK XML fragments directly from xidx/CWB files without spawning tt-cwb-xidx."""
        cqp_folder = Path(cfg.registry) if cfg.registry else (root_dir / "cqp")
        if cqp_folder.is_file():
            cqp_folder = cqp_folder.parent
        if not cqp_folder.exists():
            return None

        try:
            text_idx = self._read_be_uints_file(str(cqp_folder / "text_id.idx"))
            if match_start < 0 or match_end < 0 or match_start >= len(text_idx) or match_end >= len(text_idx):
                return None
            textid1 = text_idx[match_start]
            textid2 = text_idx[match_end]
            if textid1 != textid2:
                return None

            xmlfile = doc_id
            text_avx_path = cqp_folder / "text_id.avx"
            text_avs_path = cqp_folder / "text_id.avs"
            if text_avx_path.is_file() and text_avs_path.is_file():
                text_avx = self._read_be_uints_file(str(text_avx_path))
                text_avs = self._read_binary_file(str(text_avs_path))
                pos = textid1 * 2 + 1
                if pos < len(text_avx):
                    xmlfile = self._read_c_string(text_avs, text_avx[pos]) or doc_id

            if expand_level:
                range_path = cqp_folder / f"{expand_level}.rng"
                xidx_range_path = cqp_folder / f"{expand_level}_xidx.rng"
                if not range_path.is_file() or not xidx_range_path.is_file():
                    return None
                start_idx = self._range_index_at(range_path, match_start)
                end_idx = self._range_index_at(range_path, match_end)
                if start_idx is None or end_idx is None:
                    return None
                xidx_ranges = self._read_be_uints_file(str(xidx_range_path))
                start_pos = start_idx * 2
                end_pos = end_idx * 2 + 1
                if end_pos >= len(xidx_ranges):
                    return None
                rpos1 = xidx_ranges[start_pos]
                rpos2 = xidx_ranges[end_pos]
            else:
                xidx_ranges = self._read_be_uints_file(str(cqp_folder / "xidx.rng"))
                start_pos = match_start * 2
                end_pos = match_end * 2 + 1
                if end_pos >= len(xidx_ranges):
                    return None
                rpos1 = xidx_ranges[start_pos]
                rpos2 = xidx_ranges[end_pos]

            xml_path = self._resolve_xidx_xml_path(root_dir, xmlfile, doc_id)
            if xml_path is None:
                return None
            try:
                xml_size = xml_path.stat().st_size
            except OSError:
                return None
            if rpos1 < 0 or rpos2 < rpos1 or rpos2 > xml_size:
                return None
            fragment_bytes = self._read_binary_range(xml_path, rpos1, rpos2)
            fragment = self._decode_cqp_output(fragment_bytes, cfg.encoding).strip()
            return fragment or None
        except Exception:
            return None

    def _fragment_to_text(self, fragment: str) -> str:
        wrapped = f"<root>{fragment}</root>"
        try:
            root = ET.fromstring(wrapped)
            return " ".join(part.strip() for part in root.itertext() if part.strip())
        except ET.ParseError:
            plain = re.sub(r"<[^>]+>", " ", fragment)
            return " ".join(plain.split())

    def kwic(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        params = dict(req.get("params") or {})
        query = params.get("query")
        if not isinstance(query, str):
            raise RuntimeError("CQP kwic expects params['query'] to be a CQP query string.")

        query = self._normalize_simple_cqp_token_query(query)

        limit = int(params.get("limit", 50))
        debug = bool(params.get("debug"))

        script = f"""
        {query};
        cat {limit};
        """
        out = self._run_cqp(cfg, script, debug=debug, label="kwic")
        hits: List[Dict[str, Any]] = []
        # Parse out the leading corpus position (CWB 'cat' style: optional
        # leading spaces, then digits, then ':') so API consumers don't have
        # to re-parse it. Keep the original raw line as well.
        import re

        pos_re = re.compile(r"^\s*(\d+):\s*(.*)$")
        for line in out.strip().splitlines():
            m = pos_re.match(line)
            if m:
                corpus_pos = int(m.group(1))
                rest = m.group(2)
                hits.append(
                    {
                        "raw": line,
                        "corpus_pos": corpus_pos,
                        "text": rest,
                    }
                )
            else:
                hits.append({"raw": line})
        return {"hits": hits}

    def freq(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        params = dict(req.get("params") or {})

        field = str(params.get("field", "lemma")).strip()
        if not field:
            raise RuntimeError("freq requires params['field'] to be a non-empty CQP attribute name.")
        limit = max(0, min(int(params.get("limit", 50)), 5000))
        offset = max(0, int(params.get("offset", 0)))
        query = str(params.get("query") or "[]").strip()
        debug = bool(params.get("debug"))

        target_name, assignment_stmt = self._parse_cqp_assignment(query)
        size_script = f"{assignment_stmt}\nsize {target_name};\n"
        try:
            size_out = self._run_cqp(cfg, size_script, debug=debug, label="freq-size")
        except RuntimeError as e:
            raise RuntimeError(f"CQP frequency preselection failed: {e}") from e
        total = self._parse_cqp_size(size_out)

        last_group_err: Optional[RuntimeError] = None
        group_out = ""
        for group_field in self._cqp_freq_group_field_variants(field):
            group_script = f"{assignment_stmt}\ngroup {target_name} match {group_field};\n"
            try:
                group_out = self._run_cqp(cfg, group_script, debug=debug, label="freq-group")
                last_group_err = None
                break
            except RuntimeError as e:
                last_group_err = e
                continue
        if last_group_err is not None:
            raise RuntimeError(f"CQP frequency grouping failed: {last_group_err}") from last_group_err

        items = self._parse_cqp_group_output(group_out)
        sliced = items[offset : offset + limit] if limit else []
        return {
            "field": field,
            "query": query,
            "total": total if total is not None else 0,
            "items": sliced,
            "returned": len(sliced),
        }

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        project = dict(req.get("project") or {})

        base: Dict[str, Any] = {
            "backend": self.name,
            "descriptor": self.descriptor(),
            "registry": cfg.registry,
            "corpus": cfg.corpus,
            "cqp_binary": cfg.cqp_binary,
            "cqp_binary_resolved": self._resolve_cqp_binary(cfg),
        }
        if cfg.original_registry and cfg.original_registry != cfg.registry:
            base["registry_original"] = cfg.original_registry
        if cfg.registry_patched:
            base["registry_patched"] = True

        # Try to enrich with TEITOK-specific metadata if available.
        start_path = Path(project.get("root") or cfg.registry or ".")
        detected = detect_teitok_cqp(start_path)
        if detected:
            meta = detected.get("meta") or {}
            docs_count = meta.get("docs_count")
            sattributes_by_region = meta.get("sattributes_by_region") or {}

            # Augment sattributes_by_region with information from the registry
            # so attributes like text_id are not missed.
            struct_names = self._read_struct_attributes(cfg, project.get("root"))
            for name in struct_names:
                if name.startswith("text_"):
                    region = "text"
                    attr = name.split("_", 1)[1]
                    region_list = sattributes_by_region.setdefault(region, [])
                    if attr not in region_list:
                        region_list.append(attr)

            base.update(
                {
                    "docs_count": docs_count,
                    "pattributes": meta.get("pattributes") or [],
                    "sattributes_by_region": sattributes_by_region,
                    "word_attribute": meta.get("word_attribute"),
                    "searchfolder": meta.get("searchfolder"),
                    "settings_path": meta.get("settings_path"),
                    "struct_attributes": struct_names,
                }
            )

        # Always try to provide tokens_count from the CWB index if possible.
        tokens_count = self._estimate_tokens_count(cfg, project.get("root"))
        base["tokens_count"] = tokens_count

        return base

    def _detect_teitok(self, cfg: CqpConfig, project: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        """
        Helper to (re)run TEITOK detection for operations that need richer info
        than was already folded into project["cqp"].
        """
        start_path = Path(project.get("root") or cfg.registry or ".")
        return detect_teitok_cqp(start_path)

    def _reindex_teitok(self, cfg: CqpConfig, project: Dict[str, Any], params: Dict[str, Any]) -> Dict[str, Any]:
        """
        TEITOK-mode reindex: delegate to tt-cwb-encode using the TEITOK
        settings.xml, then finalize with cwb-makeall.
        """
        detected = self._detect_teitok(cfg, project)
        if not detected:
            raise RuntimeError("TEITOK configuration not detected; cannot run TEITOK-mode reindex.")

        meta = detected.get("meta") or {}
        root_dir = Path(detected.get("root") or project.get("root") or ".").resolve()

        # Decide which settings file to pass to tt-cwb-encode.
        # Priority:
        #   1. Explicit override from environment (so TEITOK/EasyCorp can point
        #      us at a merged/shared settings file if needed).
        #   2. Project-local tmp/cqpsettings.xml (this is what recqp.php writes
        #      when combining shared + local settings).
        #   3. Path detected earlier by detect_teitok_cqp (typically
        #      Resources/settings.xml).
        env_settings = os.environ.get("TEITOK_CQP_SETTINGS") or os.environ.get("FLEXICORP_CQP_SETTINGS")
        if env_settings:
            settings_path = Path(env_settings)
        else:
            tmp_settings = root_dir / "tmp" / "cqpsettings.xml"
            if tmp_settings.is_file():
                settings_path = tmp_settings
            else:
                settings_path = Path(meta.get("settings_path") or (root_dir / "Resources" / "settings.xml"))
        if not settings_path.is_file():
            raise RuntimeError(f"TEITOK settings file not found at '{settings_path}'.")

        debug = bool(params.get("debug"))

        # Ensure tmp folder exists for logs, mirroring recqp behaviour.
        tmp_dir = root_dir / "tmp"
        tmp_dir.mkdir(parents=True, exist_ok=True)
        log_path = tmp_dir / "recqp.log"

        # Prefer flexencoder when available (single run: encode + makeall); else tt-cwb-encode + cwb-makeall.
        registry_arg = cfg.registry or str(root_dir / "cqp")
        prefix = "[flexicorp][cqp][reindex-teitok] "

        flexencoder_bin = self._find_flexencoder(root_dir)
        if flexencoder_bin:
            encode_cmd = [
                flexencoder_bin,
                "--project-root", str(root_dir),
                "--output", str(registry_arg),
                "--settings", str(settings_path),
            ]
            if debug:
                print(prefix + "Running flexencoder:", " ".join(str(p) for p in encode_cmd), file=sys.stderr)

            encode_proc = subprocess.run(
                encode_cmd,
                cwd=str(root_dir),
                text=True,
                capture_output=True,
                check=False,
            )
            if debug:
                if encode_proc.stdout:
                    print(prefix + "flexencoder stdout:", file=sys.stderr)
                    print(encode_proc.stdout, file=sys.stderr)
                if encode_proc.stderr:
                    print(prefix + "flexencoder stderr:", file=sys.stderr)
                    print(encode_proc.stderr, file=sys.stderr)
            if encode_proc.returncode != 0:
                raise RuntimeError(
                    "flexencoder failed with exit code "
                    f"{encode_proc.returncode}: {encode_proc.stderr or encode_proc.stdout}"
                )
            return {
                "mode": "teitok",
                "engine": "flexencoder",
                "root": str(root_dir),
                "settings": str(settings_path),
                "registry_dir": str(registry_arg),
            }
        # Fallback: tt-cwb-encode then cwb-makeall.
        encode_bin = self._find_executable(
            "tt-cwb-encode",
            ["/usr/local/bin/tt-cwb-encode", "/usr/bin/tt-cwb-encode"],
        )
        encode_cmd = [encode_bin, "-r", str(registry_arg), f"--settings={settings_path}", f"--log={log_path}"]
        if debug:
            encode_cmd.append("--verbose")

        if debug:
            print(prefix + "Running tt-cwb-encode:", " ".join(str(p) for p in encode_cmd), file=sys.stderr)

        encode_proc = subprocess.run(
            encode_cmd,
            cwd=str(root_dir),
            text=True,
            capture_output=True,
            check=False,
        )
        if debug:
            if encode_proc.stdout:
                print(prefix + "tt-cwb-encode stdout:", file=sys.stderr)
                print(encode_proc.stdout, file=sys.stderr)
            if encode_proc.stderr:
                print(prefix + "tt-cwb-encode stderr:", file=sys.stderr)
                print(encode_proc.stderr, file=sys.stderr)
        if encode_proc.returncode != 0:
            raise RuntimeError(
                "tt-cwb-encode failed with exit code "
                f"{encode_proc.returncode}: {encode_proc.stderr or encode_proc.stdout}"
            )

        corpus_name = cfg.corpus
        if not corpus_name:
            corpus_name = (detected.get("cqp") or {}).get("corpus")
        if not corpus_name:
            raise RuntimeError("Cannot determine CQP corpus name for cwb-makeall.")

        registry_dir = Path(cfg.registry) if cfg.registry else (root_dir / "cqp")
        makeall_bin = self._find_executable(
            "cwb-makeall",
            ["/usr/local/bin/cwb-makeall", "/usr/bin/cwb-makeall"],
        )
        makeall_cmd = [makeall_bin, "-r", str(registry_dir), corpus_name]
        if debug:
            print(prefix + "Running cwb-makeall:", " ".join(str(p) for p in makeall_cmd), file=sys.stderr)

        makeall_proc = subprocess.run(
            makeall_cmd,
            cwd=str(root_dir),
            text=True,
            capture_output=True,
            check=False,
        )
        if debug:
            if makeall_proc.stdout:
                print(prefix + "cwb-makeall stdout:", file=sys.stderr)
                print(makeall_proc.stdout, file=sys.stderr)
            if makeall_proc.stderr:
                print(prefix + "cwb-makeall stderr:", file=sys.stderr)
                print(makeall_proc.stderr, file=sys.stderr)
        if makeall_proc.returncode != 0:
            raise RuntimeError(
                "cwb-makeall failed with exit code "
                f"{makeall_proc.returncode}: {makeall_proc.stderr or makeall_proc.stdout}"
            )

        return {
            "mode": "teitok",
            "engine": "tt-cwb-encode",
            "root": str(root_dir),
            "settings": str(settings_path),
            "log": str(log_path),
            "registry_dir": str(registry_dir),
            "corpus": corpus_name,
        }

    def _reindex_plain_cwb(self, cfg: CqpConfig, project: Dict[str, Any], params: Dict[str, Any]) -> Dict[str, Any]:
        """
        Non-TEITOK reindex: expect a folder with .vrt/.vert files and run
        cwb-encode followed by cwb-makeall.
        """
        input_folder = params.get("input_folder") or project.get("root")
        if not input_folder:
            raise RuntimeError(
                "CQP reindex in non-TEITOK mode requires an input folder "
                "(set project.root, --folder, or params['input_folder'])."
            )

        input_dir = Path(input_folder).resolve()
        if not input_dir.is_dir():
            raise RuntimeError(f"Input folder '{input_dir}' does not exist or is not a directory.")

        vrt_files: List[Path] = sorted(list(input_dir.glob("*.vrt")) + list(input_dir.glob("*.vert")))
        if not vrt_files:
            raise RuntimeError(f"No .vrt/.vert files found in input folder '{input_dir}'.")

        pattrs = list(params.get("pattributes") or [])
        sattrs = list(params.get("sattributes") or [])
        if not pattrs:
            raise RuntimeError(
                "At least one positional attribute must be provided for cwb-encode "
                "(params['pattributes'] / --pattribute)."
            )

        # Determine registry file and corpus HOME, mirroring _estimate_tokens_count.
        registry_path: Optional[Path]
        if cfg.registry:
            registry_path = Path(cfg.registry)
        else:
            registry_path = None

        registry_file: Optional[Path] = None
        if registry_path:
            if registry_path.is_dir():
                candidate = registry_path / cfg.corpus.lower()
                registry_file = candidate
            elif registry_path.is_file():
                registry_file = registry_path

        if not registry_file:
            raise RuntimeError(
                "Cannot determine CWB registry file. Provide a registry directory or file in project['cqp']['registry']."
            )

        registry_dir = registry_file.parent

        home_dir: Optional[Path] = None
        if registry_file.is_file():
            try:
                with registry_file.open("r", encoding="utf-8", errors="ignore") as f:
                    for line in f:
                        line = line.strip()
                        if not line or line.startswith("#"):
                            continue
                        if line.startswith("HOME"):
                            parts = line.split(None, 1)
                            if len(parts) == 2:
                                home_dir = Path(parts[1].strip())
                            break
            except OSError:
                home_dir = None

        if home_dir is None:
            # Fall back to project-local 'cqp' directory or project root.
            root = Path(project.get("root") or ".")
            home_dir = root / "cqp"

        home_dir.mkdir(parents=True, exist_ok=True)

        # Concatenate all VRT files into a temporary file for a single encode pass.
        import tempfile

        tmp_vrt = Path(
            tempfile.NamedTemporaryFile(mode="w", suffix=".vrt", delete=False, encoding="utf-8").name
        )
        try:
            with tmp_vrt.open("w", encoding="utf-8") as out_handle:
                for vf in vrt_files:
                    with vf.open("r", encoding="utf-8", errors="ignore") as in_handle:
                        for line in in_handle:
                            out_handle.write(line)

            encode_bin = self._find_executable(
                "cwb-encode",
                ["/usr/local/bin/cwb-encode", "/usr/bin/cwb-encode"],
            )
            encode_cmd: List[str] = [
                encode_bin,
                "-d",
                str(home_dir),
                "-R",
                str(registry_file),
                "-c",
                "utf8",
                "-f",
                str(tmp_vrt),
            ]
            for p in pattrs:
                encode_cmd.extend(["-P", str(p)])
            for s in sattrs:
                encode_cmd.extend(["-S", str(s)])

            debug = bool(params.get("debug"))
            prefix = "[flexicorp][cqp][reindex-cwb] "
            if debug:
                print(prefix + "Running cwb-encode:", " ".join(encode_cmd), file=sys.stderr)

            encode_proc = subprocess.run(
                encode_cmd,
                text=True,
                capture_output=True,
                check=False,
            )
            if debug:
                if encode_proc.stdout:
                    print(prefix + "cwb-encode stdout:", file=sys.stderr)
                    print(encode_proc.stdout, file=sys.stderr)
                if encode_proc.stderr:
                    print(prefix + "cwb-encode stderr:", file=sys.stderr)
                    print(encode_proc.stderr, file=sys.stderr)

            if encode_proc.returncode != 0:
                raise RuntimeError(
                    "cwb-encode failed with exit code "
                    f"{encode_proc.returncode}: {encode_proc.stderr or encode_proc.stdout}"
                )

            corpus_name = cfg.corpus
            if not corpus_name:
                raise RuntimeError("CQP configuration is missing 'corpus' name for cwb-makeall.")

            makeall_bin = self._find_executable(
                "cwb-makeall",
                ["/usr/local/bin/cwb-makeall", "/usr/bin/cwb-makeall"],
            )
            makeall_cmd = [makeall_bin, "-r", str(registry_dir), corpus_name]
            if debug:
                print(prefix + "Running cwb-makeall:", " ".join(makeall_cmd), file=sys.stderr)

            makeall_proc = subprocess.run(
                makeall_cmd,
                text=True,
                capture_output=True,
                check=False,
            )
            if debug:
                if makeall_proc.stdout:
                    print(prefix + "cwb-makeall stdout:", file=sys.stderr)
                    print(makeall_proc.stdout, file=sys.stderr)
                if makeall_proc.stderr:
                    print(prefix + "cwb-makeall stderr:", file=sys.stderr)
                    print(makeall_proc.stderr, file=sys.stderr)

            if makeall_proc.returncode != 0:
                raise RuntimeError(
                    "cwb-makeall failed with exit code "
                    f"{makeall_proc.returncode}: {makeall_proc.stderr or makeall_proc.stdout}"
                )

        finally:
            try:
                tmp_vrt.unlink(missing_ok=True)
            except OSError:
                pass

        return {
            "mode": "plain",
            "input_folder": str(input_dir),
            "vrt_files": [str(p) for p in vrt_files],
            "registry_file": str(registry_file),
            "registry_dir": str(registry_dir),
            "home": str(home_dir),
            "corpus": cfg.corpus,
            "pattributes": pattrs,
            "sattributes": sattrs,
        }

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        """
        Rebuild CWB indices for the current CQP corpus.

        - In TEITOK mode, delegate to tt-cwb-encode + cwb-makeall using the
          TEITOK settings and project structure.
        - In non-TEITOK mode, expect a folder with .vrt/.vert files and run
          cwb-encode + cwb-makeall, driven by explicit pattribute/sattribute
          lists provided in params.

        This operation now uses a simple lock file so that only one CQP
        reindex runs per project at a time (visible to the overview UI).
        """
        cfg = self._get_config(req)
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})

        # Acquire a per-project reindex lock so we don't run multiple CQP
        # reindexes in parallel for the same corpus.
        root = get_project_root(project)
        lock_dir = root / "tmp" / "flexicorp-locks"
        lock_dir.mkdir(parents=True, exist_ok=True)
        lock_path = lock_dir / "cqp-reindex.lock"
        if lock_path.is_file():
            # For now, treat any existing lock as active; overview exposes it
            # to the UI so the user can see that a reindex is already running.
            raise RuntimeError(
                f"CQP reindex is already running for this project (lock file exists at {lock_path})."
            )

        log_path = root / "tmp" / "recqp.log"
        try:
            now = __import__("datetime").datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
            lock_data = {
                "backend": "cqp",
                "project_root": str(root),
                "status": "running",
                "started": now,
                "updated": now,
                "log_path": str(log_path),
            }
            try:
                lock_path.write_text(
                    __import__("json").dumps(lock_data, ensure_ascii=False, indent=2),
                    encoding="utf-8",
                )
            except Exception:
                # Lock is best-effort; failure should not prevent reindex.
                pass

            detected = self._detect_teitok(cfg, project)
            if detected:
                return self._reindex_teitok(cfg, project, params)
            return self._reindex_plain_cwb(cfg, project, params)
        finally:
            try:
                if lock_path.is_file():
                    lock_path.unlink()
            except OSError:
                pass


register_backend(CqpBackend())

