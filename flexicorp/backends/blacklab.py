from __future__ import annotations

import base64
import hashlib
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import uuid
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from ..config import BlackLabConfig, get_blacklab_settings, get_project_root
from ..core import CorpusBackend, FlexiRequest, register_backend
from ..teitok_context import normalize_context_request, resolve_teitok_context

OWNER_MARKER_FILE = "flexicorp-owner.json"
JOB_QUEUE_SUBDIR = "flexicorp-jobs/blacklab"


class BlackLabBackendError(RuntimeError):
    pass


def _first_text(value: Any) -> Optional[str]:
    if value is None:
        return None
    if isinstance(value, list):
        for item in value:
            text = _first_text(item)
            if text:
                return text
        return None
    text = str(value).strip()
    return text or None


def _list_text(value: Any) -> List[str]:
    if value is None:
        return []
    if isinstance(value, list):
        return [str(item) for item in value]
    return [str(value)]


def _stitch_kwic_part(part: Dict[str, Any]) -> str:
    words = _list_text(part.get("word"))
    punct = _list_text(part.get("punct"))
    if not words:
        return ""
    out: List[str] = []
    for idx, word in enumerate(words):
        out.append(word)
        if idx < len(punct):
            out.append(punct[idx])
        elif idx < len(words) - 1:
            out.append(" ")
    return "".join(out)


def _extract_xml_ids(fragment: str, *, tag_names: List[str]) -> List[str]:
    if not fragment:
        return []
    try:
        root = ET.fromstring(f"<root>{fragment}</root>")
    except ET.ParseError:
        return []
    tag_set = {str(name).strip().lower() for name in tag_names if str(name).strip()}
    out: List[str] = []
    for elem in root.iter():
        local = _local_name(elem.tag).lower()
        if local not in tag_set:
            continue
        elem_id = str(elem.get("id") or elem.get("{http://www.w3.org/XML/1998/namespace}id") or "").strip()
        if elem_id:
            out.append(elem_id)
    return out


def _extract_snippet_parts(xml_text: str) -> Dict[str, str]:
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError:
        return {}
    out: Dict[str, str] = {}
    for key in ("before", "match", "after"):
        elem = root.find(key)
        if elem is not None and elem.text:
            out[key] = elem.text
    return out


def _normalize_teitok_doc_id(raw_value: str, *, root_dir: Path, searchfolder: str, corpus_id: str) -> str:
    text = str(raw_value or "").strip().replace("\\", "/")
    if not text:
        return ""
    searchfolders = [
        part.strip("/").replace("\\", "/")
        for part in str(searchfolder or "xmlfiles").split(",")
        if part.strip()
    ] or ["xmlfiles"]
    markers = [f"/{folder}/" for folder in searchfolders]
    if corpus_id:
        markers.append(f"/{corpus_id}/")
    for marker in markers:
        if marker and marker in text:
            return text.rsplit(marker, 1)[1].lstrip("/")
    root_prefix = root_dir.as_posix().rstrip("/") + "/"
    if text.startswith(root_prefix):
        trimmed = text[len(root_prefix) :]
        for folder in searchfolders:
            if trimmed.startswith(folder + "/"):
                return trimmed[len(folder) + 1 :]
        return trimmed
    for folder in searchfolders:
        if text.startswith(folder + "/"):
            return text[len(folder) + 1 :]
    return text.lstrip("/")


def _local_name(value: Any) -> str:
    text = str(value or "")
    if "}" in text:
        text = text.split("}", 1)[1]
    return text


def _yaml_str(value: Any) -> str:
    return json.dumps(str(value), ensure_ascii=False)


def _slugify(value: Any) -> str:
    text = str(value or "").strip().lower()
    text = re.sub(r"[^a-z0-9._-]+", "-", text)
    text = re.sub(r"-{2,}", "-", text).strip("-")
    return text or "teitok"


def _sha1_text(value: str) -> str:
    return hashlib.sha1(value.encode("utf-8")).hexdigest()


def _derive_meta_field_name(item: ET.Element) -> Optional[str]:
    key = str(item.get("key") or "").strip()
    if key:
        return key
    cqp_name = str(item.get("cqp") or "").strip()
    if cqp_name:
        return cqp_name
    xpath = str(item.get("xpath") or "").strip()
    if not xpath:
        return None
    note_match = re.search(r'@n=["\']([^"\']+)["\']', xpath)
    if note_match:
        return note_match.group(1)
    if "/@" in xpath:
        return xpath.rsplit("/@", 1)[1].strip()
    segs = [seg for seg in xpath.strip("/").split("/") if seg and seg != "*"]
    if not segs:
        return None
    return re.sub(r"\[.*\]", "", segs[-1]).strip().lower() or None


def _settings_candidates(root: Path) -> List[Path]:
    return [
        root / "Resources" / "settings.xml",
        root / "settings.xml",
        root / "tmp" / "cqpsettings.xml",
    ]


def _split_searchfolders(value: Any) -> List[str]:
    raw = str(value or "").strip()
    if not raw:
        return ["xmlfiles"]
    return [part.strip() for part in raw.split(",") if part.strip()] or ["xmlfiles"]


def _find_settings_file(root: Path) -> Optional[Path]:
    for cand in _settings_candidates(root):
        if cand.is_file():
            return cand
    return None


def _rewrite_settings_xpath(xpath: str, *, root_name: str) -> str:
    text = str(xpath or "").strip()
    if not text:
        return text
    if text.startswith("//"):
        return "." + text
    root_prefix = f"/{root_name}/"
    if text == f"/{root_name}":
        return "."
    if text.startswith(root_prefix):
        return "./" + text[len(root_prefix) :]
    if text.startswith("/"):
        return "." + text
    return text


def _build_teitok_profile(root: Path) -> Dict[str, Any]:
    profile: Dict[str, Any] = {
        "settings_path": None,
        "searchfolder": "xmlfiles",
        "wordfld": "form",
        "corpus": "",
        "blacklab_corpus": "",
        "blacklab_case_sensitive": False,
        "display_name": "",
        "pattributes": [],
        "sattributes": [],
        "meta_fields": [],
    }
    settings_path = _find_settings_file(root)
    if settings_path is None:
        return profile
    profile["settings_path"] = str(settings_path)
    try:
        settings_root = ET.parse(settings_path).getroot()
    except ET.ParseError:
        return profile

    cqp_elem = settings_root.find(".//cqp")
    blacklab_elem = settings_root.find(".//blacklab")
    xmlfile_elem = settings_root.find(".//xmlfile")
    defaults_title = settings_root.find(".//defaults/title")

    if cqp_elem is not None:
        profile["corpus"] = str(cqp_elem.get("corpus") or "").strip()
        searchfolder = str(cqp_elem.get("searchfolder") or "").strip()
        wordfld = str(cqp_elem.get("wordfld") or "").strip()
        if searchfolder:
            profile["searchfolder"] = searchfolder
        if wordfld:
            profile["wordfld"] = wordfld
        pattributes: List[str] = []
        for item in cqp_elem.findall("./pattributes/item"):
            key = str(item.get("key") or "").strip()
            if key and key not in pattributes:
                pattributes.append(key)
        profile["pattributes"] = pattributes
        sattributes: List[str] = []
        for item in cqp_elem.findall("./sattributes/item"):
            key = str(item.get("key") or "").strip()
            if key and key not in sattributes and key.lower() != "text":
                sattributes.append(key)
        profile["sattributes"] = sattributes

    if blacklab_elem is not None:
        profile["blacklab_corpus"] = str(blacklab_elem.get("corpus") or "").strip()
        case_attr = str(blacklab_elem.get("case_sensitive") or "").strip().lower()
        profile["blacklab_case_sensitive"] = case_attr in ("1", "true", "yes")

    if not profile["pattributes"] and xmlfile_elem is not None:
        pattributes = []
        for item in xmlfile_elem.findall(".//pattributes//item"):
            key = str(item.get("key") or "").strip()
            if key and key not in pattributes:
                pattributes.append(key)
        profile["pattributes"] = pattributes
        defaultform = str(xmlfile_elem.get("defaultform") or "").strip()
        if defaultform:
            profile["wordfld"] = defaultform

    if defaults_title is not None:
        display_name = str(defaults_title.get("display") or defaults_title.text or "").strip()
        if display_name:
            profile["display_name"] = display_name

    root_name = "TEI"
    meta_fields: List[Dict[str, str]] = []
    for item in settings_root.findall(".//teiheader/item"):
        xpath = str(item.get("xpath") or "").strip()
        if not xpath:
            continue
        name = _derive_meta_field_name(item)
        if not name:
            continue
        meta_fields.append(
            {
                "name": name,
                "display": str(item.get("display") or name).strip(),
                "xpath": _rewrite_settings_xpath(xpath, root_name=root_name),
            }
        )
    profile["meta_fields"] = meta_fields
    return profile


def _build_owner_marker(root: Path, profile: Dict[str, Any], input_dir: Path, corpus_id: str) -> Dict[str, Any]:
    settings_path = Path(str(profile.get("settings_path") or "")).expanduser() if profile.get("settings_path") else None
    settings_sha1 = None
    if settings_path and settings_path.is_file():
        try:
            settings_sha1 = hashlib.sha1(settings_path.read_bytes()).hexdigest()
        except OSError:
            settings_sha1 = None
    return {
        "version": 1,
        "backend": "blacklab",
        "backend_corpus": corpus_id,
        "teitok_corpus": str(profile.get("corpus") or "").strip() or None,
        "project_root": str(root),
        "project_root_name": root.name,
        "searchfolder": str(profile.get("searchfolder") or "xmlfiles"),
        "input_dir": str(input_dir),
        "settings_path": str(settings_path) if settings_path else None,
        "settings_sha1": settings_sha1,
    }


def _owner_markers_match(current: Dict[str, Any], existing: Dict[str, Any]) -> bool:
    if str(existing.get("backend") or "").strip().lower() != "blacklab":
        return False
    if str(existing.get("backend_corpus") or "") != str(current.get("backend_corpus") or ""):
        return False
    current_root = str(current.get("project_root") or "")
    existing_root = str(existing.get("project_root") or "")
    if current_root and existing_root and current_root == existing_root:
        return True
    current_settings_sha1 = str(current.get("settings_sha1") or "")
    existing_settings_sha1 = str(existing.get("settings_sha1") or "")
    if current_settings_sha1 and existing_settings_sha1 and current_settings_sha1 == existing_settings_sha1:
        return True
    return False


def _read_json_file(path: Path) -> Optional[Dict[str, Any]]:
    try:
        if not path.is_file():
            return None
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    return data if isinstance(data, dict) else None


def _inspect_input_xml(input_dir: Path) -> Dict[str, Any]:
    info: Dict[str, Any] = {
        "root_name": "TEI",
        "namespace_uri": "",
        "word_tag": "tok",
    }
    try:
        sample = next(path for path in sorted(input_dir.rglob("*.xml")) if path.is_file())
    except StopIteration:
        return info

    try:
        xml_root = ET.parse(sample).getroot()
    except ET.ParseError:
        return info

    root_tag = str(xml_root.tag or "")
    if root_tag.startswith("{") and "}" in root_tag:
        ns_uri, _, local = root_tag[1:].partition("}")
        info["namespace_uri"] = ns_uri
        info["root_name"] = local or "TEI"
    else:
        info["root_name"] = _local_name(root_tag) or "TEI"

    present = {_local_name(elem.tag).lower() for elem in xml_root.iter()}
    if "dtok" in present:
        info["word_tag"] = "dtok"
    elif "w" in present:
        info["word_tag"] = "w"
    elif "tok" in present:
        info["word_tag"] = "tok"
    return info


def _annotation_value_path(attr_name: str, *, wordfld: str) -> str:
    name = str(attr_name or "").strip()
    if not name:
        return "."
    if name == "word":
        if wordfld and wordfld != "form":
            return f"@{wordfld}"
        return "."
    if name == "form":
        return "."
    return f"@{name}"


def _build_format_yaml(profile: Dict[str, Any], sample: Dict[str, Any], corpus_id: str) -> str:
    namespace_uri = str(sample.get("namespace_uri") or "").strip()
    root_name = str(sample.get("root_name") or "TEI").strip() or "TEI"
    word_tag = str(sample.get("word_tag") or "tok").strip() or "tok"
    prefix = "tei:" if namespace_uri else ""
    root_lower = root_name.lower()
    display_name = str(profile.get("display_name") or profile.get("corpus") or corpus_id).strip() or corpus_id
    wordfld = str(profile.get("wordfld") or "form").strip() or "form"
    pattributes = list(profile.get("pattributes") or [])
    meta_fields = list(profile.get("meta_fields") or [])

    ordered_attrs: List[str] = []
    for attr in ["word", wordfld, *pattributes]:
        clean = str(attr or "").strip()
        if clean and clean not in ordered_attrs:
            ordered_attrs.append(clean)
    if "lemma" not in ordered_attrs:
        ordered_attrs.append("lemma")
    if "pos" not in ordered_attrs:
        ordered_attrs.append("pos")

    lines: List[str] = []
    lines.append(f"displayName: {_yaml_str(display_name)}")
    lines.append(f"description: {_yaml_str('Auto-generated TEITOK input format for flexiCorp BlackLab reindex.')}")
    lines.append("type: content")
    if namespace_uri:
        lines.append("namespaces:")
        lines.append(f"  tei: {_yaml_str(namespace_uri)}")
        lines.append(f"  xml: {_yaml_str('http://www.w3.org/XML/1998/namespace')}")
    if root_lower == "tei":
        document_path = f"//{prefix}{root_name}"
        container_path = f".//{prefix}text"
    elif root_lower == "text":
        document_path = f"//{prefix}{root_name}"
        container_path = "."
    else:
        document_path = f"//{prefix}{root_name}"
        container_path = f".//{prefix}text"
    lines.append(f"documentPath: {document_path}")
    lines.append("annotatedFields:")
    lines.append("  contents:")
    lines.append("    displayName: Contents")
    lines.append("    description: Contents of the documents.")
    lines.append(f"    containerPath: {container_path}")
    lines.append(f"    wordPath: .//{prefix}{word_tag}")
    lines.append(f"    punctPath: .//text()[not(ancestor::{prefix}{word_tag})]")
    lines.append("    annotations:")
    case_sensitive = bool(profile.get("blacklab_case_sensitive"))
    sensitivity = "sensitive" if case_sensitive else "sensitive_insensitive"
    for attr in ordered_attrs:
        lines.append(f"    - name: {_yaml_str(attr)}")
        lines.append(f"      valuePath: {_yaml_str(_annotation_value_path(attr, wordfld=wordfld))}")
        if attr in {"word", "lemma"}:
            lines.append(f"      sensitivity: {sensitivity}")
    lines.append("metadata:")
    lines.append("  containerPath: .")
    lines.append("  fields:")
    lines.append("  - name: pid")
    lines.append(f"    valuePath: {_yaml_str('.//text/@id | .//@xml:id | .//@id')}")
    lines.append("  - name: title")
    lines.append('    valuePath: ' + _yaml_str('.//titleStmt/title | .//title | .//idno[@type="corpus-num"]'))
    seen_meta = {"pid", "title"}
    for field in meta_fields:
        name = str(field.get("name") or "").strip()
        xpath = str(field.get("xpath") or "").strip()
        if not name or not xpath or name in seen_meta:
            continue
        seen_meta.add(name)
        lines.append(f"  - name: {_yaml_str(name)}")
        lines.append(f"    valuePath: {_yaml_str(xpath)}")

    lines.append("corpusConfig:")
    lines.append(f"  displayName: {_yaml_str(display_name)}")
    lines.append("  contentViewable: true")
    lines.append("  specialFields:")
    lines.append("    titleField: title")
    lines.append("    pidField: pid")
    lines.append("  annotationGroups:")
    lines.append("    contents:")
    lines.append("    - name: Basic")
    basic_annotations = [attr for attr in ordered_attrs if attr in {"word", wordfld, "lemma", "pos"}]
    for attr in basic_annotations[:4]:
        lines.append(f"      annotations:")
        break
    if basic_annotations:
        for attr in basic_annotations[:4]:
            lines.append(f"      - {_yaml_str(attr)}")
    else:
        lines.append("      annotations:")
        lines.append("      - word")
    lines.append("    - name: Other")
    lines.append("      addRemainingAnnotations: true")
    lines.append("  metadataFieldGroups:")
    lines.append("  - name: Core")
    lines.append("    fields:")
    lines.append("    - pid")
    lines.append("    - title")
    lines.append("  - name: Other")
    lines.append("    addRemainingFields: true")
    return "\n".join(lines) + "\n"


def _run_command(
    cmd: List[str],
    *,
    env: Optional[Dict[str, str]] = None,
    verbose: bool = False,
    prefix: str = "",
) -> subprocess.CompletedProcess[str]:
    if verbose:
        print(f"{prefix}$ {' '.join(shlex.quote(part) for part in cmd)}", file=sys.stderr)
        proc = subprocess.Popen(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            env=env,
        )
        stdout_chunks: List[str] = []
        assert proc.stdout is not None
        for line in proc.stdout:
            stdout_chunks.append(line)
            print(f"{prefix}{line}", end="", file=sys.stderr)
        returncode = proc.wait()
        stdout = "".join(stdout_chunks)
        completed = subprocess.CompletedProcess(cmd, returncode, stdout, "")
        if completed.returncode != 0:
            detail = stdout.strip() or f"exit code {completed.returncode}"
            raise BlackLabBackendError(detail)
        return completed
    proc = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        check=False,
        env=env,
    )
    if proc.returncode != 0:
        stderr = (proc.stderr or "").strip()
        stdout = (proc.stdout or "").strip()
        detail = stderr or stdout or f"exit code {proc.returncode}"
        raise BlackLabBackendError(detail)
    return proc


def _summarize_indexer_output(stdout: str, stderr: str) -> Dict[str, Any]:
    combined = "\n".join(part for part in [stdout, stderr] if part)
    error_count = len(re.findall(r"Error while indexing input file:", combined))
    no_words_count = len(re.findall(r"No words indexed in ", combined))
    status = "ok"
    if error_count > 0:
        status = "partial"
    return {
        "status": status,
        "error_count": error_count,
        "warning_count": no_words_count,
    }


def _docker_path_exists(docker_bin: str, container: str, path: str) -> bool:
    proc = subprocess.run(
        [docker_bin, "exec", container, "/bin/bash", "-lc", f"test -e {shlex.quote(path)}"],
        capture_output=True,
        text=True,
        check=False,
    )
    return proc.returncode == 0


def _docker_read_json(docker_bin: str, container: str, path: str) -> Optional[Dict[str, Any]]:
    proc = subprocess.run(
        [docker_bin, "exec", container, "/bin/bash", "-lc", f"cat {shlex.quote(path)}"],
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        return None
    try:
        data = json.loads(proc.stdout or "")
    except Exception:
        return None
    return data if isinstance(data, dict) else None


def _find_docker_bin() -> Optional[str]:
    docker_bin = shutil.which("docker")
    if docker_bin:
        return docker_bin
    for candidate in (
        "/usr/local/bin/docker",
        "/opt/homebrew/bin/docker",
        "/Applications/Docker.app/Contents/Resources/bin/docker",
    ):
        path = Path(candidate)
        if path.is_file() and os.access(path, os.X_OK):
            return str(path)
    return None


@dataclass
class BlackLabBackend(CorpusBackend):
    name: str = "blacklab"
    raw_kwic_delimiter: str = "--%%%--"

    @staticmethod
    def _log(params: Dict[str, Any], message: str) -> None:
        if params.get("debug") or params.get("verbose"):
            print(f"[flexicorp][blacklab] {message}", file=sys.stderr)

    @staticmethod
    def _candidate_server_urls(req: FlexiRequest) -> List[str]:
        project = dict(req.get("project") or {})
        merged = get_blacklab_settings(project)
        explicit = str(merged.get("url") or merged.get("server_url") or "").strip()
        candidates: List[str] = []
        if explicit:
            candidates.append(explicit.rstrip("/"))
        candidates.extend(
            [
                "http://127.0.0.1:8080/blacklab-server",
                "http://localhost:8080/blacklab-server",
                "http://127.0.0.1:8088/blacklab-server",
                "http://localhost:8088/blacklab-server",
            ]
        )
        out: List[str] = []
        seen: set[str] = set()
        for url in candidates:
            norm = str(url).rstrip("/")
            if not norm or norm in seen:
                continue
            seen.add(norm)
            out.append(norm)
        return out

    def descriptor(self) -> Dict[str, Any]:
        return {
            "id": self.name,
            "label": "blacklab",
            "supported_query_languages": ["bcql"],
            "supported_corpus_formats": ["blacklab"],
            "default_query_language": "bcql",
            "default_corpus_format": "blacklab",
            "default_selection_reason": "HTTP adapter over self-hosted BlackLab Server.",
        }

    def _get_teitok_runtime(self, req: FlexiRequest, cfg: Optional[BlackLabConfig] = None) -> Optional[Dict[str, Any]]:
        project = dict(req.get("project") or {})
        root = get_project_root(project)
        profile = _build_teitok_profile(root)
        if not profile.get("settings_path"):
            return None
        merged = get_blacklab_settings(project)
        configured_corpus = str(merged.get("corpus") or "").strip()
        active_corpus = str(cfg.corpus if cfg is not None else configured_corpus).strip()
        if configured_corpus and active_corpus and configured_corpus != active_corpus:
            return None
        # Normalise TEITOK searchfolder: settings.xml may list multiple comma-separated
        # folders (e.g. "xmlfiles/facebook,xmlfiles/instagram,...") for CQP. For
        # context lookup we want a single root (typically "xmlfiles") so that
        # resolve_teitok_context can locate documents regardless of subfolder.
        raw_searchfolder = str(profile.get("searchfolder") or "xmlfiles").strip() or "xmlfiles"
        folders = _split_searchfolders(raw_searchfolder)
        if len(folders) > 1:
            roots = {part.split("/", 1)[0] for part in folders if part}
            searchfolder = next(iter(roots)) if roots else "xmlfiles"
        else:
            searchfolder = folders[0]
        input_dir = (root / searchfolder).resolve()
        if not input_dir.is_dir():
            return None
        sattributes = [str(item).strip() for item in list(profile.get("sattributes") or []) if str(item).strip()]
        sentence_scope = "seg" if "seg" in sattributes else ("s" if "s" in sattributes else None)
        return {
            "root_dir": root,
            "profile": profile,
            "searchfolder": searchfolder,
            "input_dir": input_dir,
            "configured_corpus": configured_corpus or active_corpus,
            "sattributes": sattributes,
            "sentence_scope": sentence_scope,
        }

    @staticmethod
    def _capabilities_payload(teitok_runtime: Optional[Dict[str, Any]]) -> Dict[str, bool]:
        return {
            # BlackLab can always return at least text context; when TEITOK
            # runtime is available we upgrade this to real XML fragments via
            # resolve_teitok_context. The frontend uses this flag only to
            # enable the XML context option, so we keep it permissive here.
            "supports_xml_context": True,
            "supports_document_links": teitok_runtime is not None,
        }

    def _request_text(
        self,
        cfg: BlackLabConfig,
        path: str,
        *,
        params: Optional[Dict[str, Any]] = None,
    ) -> str:
        base = cfg.url.rstrip("/")
        rel = path.lstrip("/")
        url = f"{base}/{rel}" if rel else base
        if params:
            clean = {str(k): v for k, v in params.items() if v is not None and str(v) != ""}
            if clean:
                url = f"{url}?{urlencode(clean, doseq=True)}"
        headers = {"Accept": "application/xml"}
        if cfg.username:
            raw = f"{cfg.username}:{cfg.password or ''}".encode("utf-8")
            headers["Authorization"] = "Basic " + base64.b64encode(raw).decode("ascii")
        request = Request(url, headers=headers)
        try:
            with urlopen(request, timeout=20) as resp:
                return resp.read().decode("utf-8")
        except Exception as exc:
            raise BlackLabBackendError(f"BlackLab request failed for {url}: {exc}") from exc

    def _fetch_match_snippet(self, cfg: BlackLabConfig, *, doc_pid: str, start: Any, end: Any) -> Dict[str, str]:
        return _extract_snippet_parts(
            self._request_text(
                cfg,
                f"{self._corpus_path(cfg)}/docs/{doc_pid}/snippet",
                params={
                    "outputformat": "xml",
                    "usecontent": "orig",
                    "hitstart": start,
                    "hitend": end,
                    "context": 0,
                },
            )
        )

    def capabilities(self) -> Dict[str, bool]:
        return {
            "status": True,
            "list_docs": True,
            "kwic": False,
            "freq": False,
            "info": True,
            "reindex": True,
            "raw_query": False,
            "query": True,
        }

    def _get_config(self, req: FlexiRequest) -> BlackLabConfig:
        cfg = self._get_server_config(req)
        if not cfg.corpus:
            raise BlackLabBackendError(
                "Missing BlackLab corpus id. Provide project.blacklab.corpus, add it to flexicorp.yaml, "
                "or choose one via the admin corpus picker."
            )
        return cfg

    def _get_server_config(self, req: FlexiRequest) -> BlackLabConfig:
        project = dict(req.get("project") or {})
        merged = get_blacklab_settings(project)
        username = str(merged.get("username") or merged.get("user") or "").strip() or None
        password = str(merged.get("password") or "").strip() or None
        default_field = str(merged.get("field") or merged.get("default_field") or "").strip() or None
        pattlang = str(merged.get("pattlang") or merged.get("query_language") or "bcql").strip() or "bcql"
        filterlang = str(merged.get("filterlang") or "luceneql").strip() or "luceneql"
        corpus = str(merged.get("corpus") or merged.get("index") or "").strip()

        last_error: Optional[str] = None
        for url in self._candidate_server_urls(req):
            cfg = BlackLabConfig(
                url=url,
                corpus=corpus,
                username=username,
                password=password,
                default_field=default_field,
                pattlang=pattlang,
                filterlang=filterlang,
            )
            try:
                self._request_json(cfg, "")
                return cfg
            except Exception as exc:
                last_error = str(exc)
                continue

        if str(merged.get("url") or merged.get("server_url") or "").strip():
            raise BlackLabBackendError(f"Configured BlackLab server is unreachable. {last_error or ''}".strip())
        raise BlackLabBackendError(
            "No BlackLab server configured and no local default responded. "
            "Tried 8080 first, then 8088 as a fallback."
        )

    @staticmethod
    def _request_json(cfg: BlackLabConfig, path: str, *, params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        query = dict(params or {})
        query.setdefault("outputformat", "json")
        url = f"{cfg.url}/{path.lstrip('/')}"
        if query:
            url = f"{url}?{urlencode(query, doseq=True)}"
        req = Request(url, headers={"Accept": "application/json"})
        if cfg.username:
            token = base64.b64encode(f"{cfg.username}:{cfg.password or ''}".encode("utf-8")).decode("ascii")
            req.add_header("Authorization", f"Basic {token}")
        try:
            with urlopen(req, timeout=30) as resp:
                data = resp.read()
        except Exception as exc:
            raise BlackLabBackendError(f"BlackLab request failed for {url}: {exc}") from exc
        try:
            payload = json.loads(data.decode("utf-8"))
        except Exception as exc:
            raise BlackLabBackendError(f"BlackLab returned invalid JSON for {url}: {exc}") from exc
        if isinstance(payload, dict) and payload.get("errors"):
            raise BlackLabBackendError(str(payload["errors"]))
        if not isinstance(payload, dict):
            raise BlackLabBackendError(f"Unexpected non-object response from BlackLab for {url}.")
        return payload

    @staticmethod
    def _corpus_path(cfg: BlackLabConfig) -> str:
        return f"corpora/{cfg.corpus}"

    def _resolve_context_value(self, cfg: BlackLabConfig, params: Dict[str, Any]) -> str:
        context_scope = str(params.get("context_scope") or params.get("context") or "").strip().lower()
        window = str(int(params.get("window", 5)))
        if not context_scope or context_scope in {"tok", "token", "tokens", "word", "words", "window"}:
            return window
        try:
            corpus_info = self._request_json(cfg, self._corpus_path(cfg))
        except Exception:
            return window
        main_field = str(corpus_info.get("mainAnnotatedField") or cfg.default_field or "")
        field_info = dict((corpus_info.get("annotatedFields") or {}).get(main_field) or {})
        span_defs = dict((field_info.get("relations") or {}).get("spans") or {})
        if context_scope in span_defs:
            return context_scope
        return window

    def _discover_local_classpath(self, merged: Dict[str, Any], params: Dict[str, Any]) -> Optional[str]:
        for key in ("blacklab_classpath", "classpath", "blacklab_tools_classpath"):
            value = str(params.get(key) or "").strip()
            if value:
                return value
        for key in ("classpath", "tools_classpath"):
            value = str(merged.get(key) or "").strip()
            if value:
                return value
        env_value = str(os.environ.get("BLACKLAB_CLASSPATH") or "").strip()
        if env_value:
            return env_value
        tools_dir = Path("/usr/local/lib/blacklab-tools")
        if tools_dir.is_dir():
            return str(tools_dir / "*")
        return None

    def _discover_blacklab_container(self, merged: Dict[str, Any], params: Dict[str, Any]) -> Optional[str]:
        docker_bin = _find_docker_bin()
        if not docker_bin:
            return None
        try:
            proc = _run_command([docker_bin, "ps", "--format", "{{.Names}}\t{{.Image}}"])
        except Exception:
            return None
        for line in (proc.stdout or "").splitlines():
            name, _tab, image = line.partition("\t")
            if "blacklab" in image.lower():
                return name.strip()
        return None

    def _resolve_docker_container_for_queue(self, merged: Dict[str, Any], params: Dict[str, Any]) -> str:
        """
        Return the container name to use when enqueueing a job.

        This is deployment-agnostic: we always try to discover a running
        BlackLab container by image name (instituutnederlandsetaal/blacklab),
        and only fall back to a generic name when Docker is unavailable.
        """
        discovered = self._discover_blacklab_container(merged, params)
        if discovered:
            return discovered
        # When Docker isn't available from this process (e.g. Tomcat-only
        # deployments), we fall back to a generic name so that any explicit
        # configuration for the runner can still work. The runner will fail
        # loudly if this name does not correspond to a container on that host.
        return "blacklab"

    def _write_reindex_job(
        self,
        root: Path,
        *,
        container: str,
        corpus_id: str,
        format_name: str,
        host_input_dir: Path,
        host_format_path: Path,
        container_input_root: str,
        container_format_dir: str,
        container_index_root: str,
        mode: str,
        owner_marker: Dict[str, Any],
        java_cmd: str,
        java_opts: str,
        params: Dict[str, Any],
    ) -> str:
        """Write a job file to the queue directory; return job_id. See dev/BLACKLAB-JOB-QUEUE.md."""
        job_id = str(uuid.uuid4())
        queue_dir = root / "tmp" / JOB_QUEUE_SUBDIR.replace("/", os.sep)
        queue_dir.mkdir(parents=True, exist_ok=True)
        job_path = queue_dir / f"job-{job_id}.json"
        job = {
            "job_id": job_id,
            "action": "blacklab-reindex",
            "container": container,
            "corpus_id": corpus_id,
            "format_name": format_name,
            "host_input_dir": str(host_input_dir.resolve()),
            "host_format_path": str(host_format_path.resolve()),
            "container_input_root": container_input_root.rstrip("/"),
            "container_format_dir": container_format_dir.rstrip("/"),
            "container_index_root": container_index_root.rstrip("/"),
            "mode": mode,
            "owner_marker": owner_marker,
            "java_cmd": java_cmd,
            "java_opts": java_opts,
        }
        job_path.write_text(json.dumps(job, ensure_ascii=False, indent=2), encoding="utf-8")
        self._log(params, f"Enqueued BlackLab reindex job {job_id} at {job_path}")
        return job_id

    def _find_runner_binary(self, root: Path, merged: Dict[str, Any], params: Dict[str, Any]) -> Optional[Path]:
        """Resolve setuid runner binary.

        Resolution order:
        - Explicit config: blacklab_runner_binary / runner_binary / setuid_runner
        - Environment: FLEXICORP_RUNNER_BINARY
        - Auto-discover:
          - {project_root}/scripts/flexicorp-blacklab-runner
          - {ttroot}/common/Scripts/flexicorp-blacklab-runner[.sh]  (TEITOK layout; ttroot is parent of project_root)
          - {flexicorp_repo}/scripts/flexicorp-blacklab-runner
          - {FLEXICORP_SCRIPTS_DIR}/flexicorp-blacklab-runner
        """
        name = "flexicorp-blacklab-runner"
        binary = str(
            params.get("blacklab_runner_binary")
            or merged.get("runner_binary")
            or merged.get("setuid_runner")
            or os.environ.get("FLEXICORP_RUNNER_BINARY")
            or ""
        ).strip()
        if binary:
            bin_path = Path(binary).expanduser()
            if not bin_path.is_absolute():
                bin_path = (root / bin_path).resolve()
            if bin_path.is_file() and os.access(bin_path, os.X_OK):
                return bin_path
            return None
        # Auto-discover: project scripts/, TEITOK common/Scripts (TT_ROOT), flexicorp repo scripts/, then FLEXICORP_SCRIPTS_DIR
        scripts_dir_env = os.environ.get("FLEXICORP_SCRIPTS_DIR")
        tt_root_env = os.environ.get("TT_ROOT")
        candidates: List[Path] = []
        # {project_root}/scripts
        candidates.append(root / "scripts" / name)
        # TEITOK: ttroot/common/Scripts (ttroot is parent of project_root)
        ttroot = root.parent
        candidates.append(ttroot / "common" / "Scripts" / name)
        candidates.append(ttroot / "common" / "Scripts" / f"{name}.sh")
        # TEITOK: TT_ROOT/common/Scripts (from environment)
        if tt_root_env:
            ttroot_env_path = Path(tt_root_env).expanduser().resolve()
            candidates.append(ttroot_env_path / "common" / "Scripts" / name)
            candidates.append(ttroot_env_path / "common" / "Scripts" / f"{name}.sh")
        # flexicorp repo scripts (when running from a checkout)
        candidates.append(Path(__file__).resolve().parent.parent.parent / "scripts" / name)
        # Environment override directory
        if scripts_dir_env:
            candidates.append(Path(scripts_dir_env).expanduser().resolve() / name)
        for candidate in candidates:
            if candidate.is_file() and os.access(candidate, os.X_OK):
                return candidate
        return None

    def _try_run_runner_setuid(
        self, queue_dir: Path, merged: Dict[str, Any], params: Dict[str, Any], root: Path
    ) -> bool:
        """If configured or found, run the setuid wrapper binary (no sudo/sudoers). Returns True if triggered."""
        bin_path = self._find_runner_binary(root, merged, params)
        if bin_path is None:
            return False
        queue_str = str(queue_dir.resolve())
        try:
            subprocess.Popen(
                [str(bin_path), queue_str],
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                start_new_session=True,
            )
            self._log(params, "Runner triggered via setuid binary (running in background)")
            return True
        except FileNotFoundError:
            return False
        except Exception as e:
            self._log(params, f"Setuid runner failed: {e}")
            return False

    def _try_run_runner_via_sudo(
        self, queue_dir: Path, merged: Dict[str, Any], params: Dict[str, Any], root: Path
    ) -> bool:
        """If configured, run the job-runner script via sudo (fallback; setuid binary preferred). Returns True if triggered."""
        runner_script = str(
            params.get("blacklab_runner_script") or merged.get("runner_script") or ""
        ).strip()
        runner_user = str(
            params.get("blacklab_runner_user") or merged.get("runner_user") or ""
        ).strip()
        if not runner_script or not runner_user:
            return False
        if not re.match(r"^[a-zA-Z0-9_.-]+$", runner_user):
            return False
        script_path = Path(runner_script).expanduser()
        if not script_path.is_absolute():
            script_path = (root / script_path).resolve()
        if not script_path.is_file() or not os.access(script_path, os.X_OK):
            self._log(params, f"Runner script not executable or missing: {script_path}")
            return False
        queue_str = str(queue_dir.resolve())
        try:
            subprocess.Popen(
                ["sudo", "-n", "-u", runner_user, str(script_path), queue_str],
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                start_new_session=True,
            )
            self._log(params, "Runner triggered via sudo (running in background)")
            return True
        except FileNotFoundError:
            return False
        except Exception as e:
            self._log(params, f"Runner via sudo failed: {e}")
            return False

    def _resolve_reindex_corpus_id(self, profile: Dict[str, Any], merged: Dict[str, Any], params: Dict[str, Any], root: Path) -> str:
        explicit = str(
            params.get("blacklab_corpus")
            or params.get("corpus")
            or merged.get("corpus")
            or merged.get("index")
            or ""
        ).strip()
        if explicit:
            return explicit
        blacklab_specific = str(profile.get("blacklab_corpus") or "").strip()
        if blacklab_specific:
            return blacklab_specific
        derived = str(profile.get("corpus") or "").strip()
        if derived:
            return derived
        return f"tt-{_slugify(root.name)}"

    def _resolve_input_dir(self, root: Path, profile: Dict[str, Any], params: Dict[str, Any]) -> Path:
        raw = str(params.get("input_folder") or profile.get("searchfolder") or "xmlfiles").strip()
        folders = _split_searchfolders(raw)
        if len(folders) > 1:
            resolved_dirs: List[Path] = []
            for folder in folders:
                path = Path(folder).expanduser()
                if not path.is_absolute():
                    path = (root / path).resolve()
                resolved_dirs.append(path)
            if all(path.is_dir() for path in resolved_dirs):
                try:
                    common = Path(os.path.commonpath([str(path) for path in resolved_dirs]))
                except ValueError:
                    common = root
                if common.is_dir():
                    return common
        base = Path(raw).expanduser()
        if not base.is_absolute():
            base = (root / base).resolve()
        return base

    def _write_generated_format(self, root: Path, corpus_id: str, profile: Dict[str, Any], input_dir: Path) -> Dict[str, Any]:
        sample = _inspect_input_xml(input_dir)
        format_root = root / "tmp" / "blacklab"
        formats_dir = format_root / "formats"
        formats_dir.mkdir(parents=True, exist_ok=True)
        format_name = f"teitok-{_slugify(corpus_id)}"
        format_path = formats_dir / f"{format_name}.blf.yaml"
        format_text = _build_format_yaml(profile, sample, corpus_id)
        format_path.write_text(format_text, encoding="utf-8")
        return {
            "format_root": format_root,
            "formats_dir": formats_dir,
            "format_name": format_name,
            "format_path": format_path,
            "sample": sample,
        }

    def _run_local_reindex(
        self,
        *,
        merged: Dict[str, Any],
        params: Dict[str, Any],
        input_dir: Path,
        corpus_id: str,
        format_root: Path,
        format_name: str,
        format_path: Path,
        owner_marker: Dict[str, Any],
    ) -> Dict[str, Any]:
        classpath = self._discover_local_classpath(merged, params)
        if not classpath:
            raise BlackLabBackendError("No local BlackLab tools found. Configure blacklab.classpath or BLACKLAB_CLASSPATH.")
        verbose = bool(params.get("debug") or params.get("verbose"))

        java_bin = str(params.get("blacklab_java") or merged.get("java") or "java").strip() or "java"
        java_opts_raw = str(params.get("blacklab_java_opts") or merged.get("java_opts") or "").strip()
        java_opts = shlex.split(java_opts_raw) if java_opts_raw else []
        index_dir_raw = str(params.get("blacklab_index_dir") or merged.get("index_dir") or "").strip()
        index_root_raw = str(params.get("blacklab_index_root") or merged.get("index_root") or "").strip()
        if index_dir_raw:
            index_dir = Path(index_dir_raw).expanduser()
        elif index_root_raw:
            index_dir = Path(index_root_raw).expanduser() / corpus_id
        else:
            index_dir = format_root.parent / corpus_id
        if not index_dir.is_absolute():
            index_dir = (format_root.parent.parent / index_dir).resolve()
        mode = str(params.get("blacklab_reindex_mode") or merged.get("reindex_mode") or "replace").strip().lower()
        allow_unowned = str(params.get("blacklab_allow_unowned_reuse") or params.get("blacklab_claim_existing") or "").strip().lower() in {"1", "true", "yes", "on"}
        force_replace = str(params.get("blacklab_force_replace") or "").strip().lower() in {"1", "true", "yes", "on"}
        marker_path = index_dir / OWNER_MARKER_FILE
        if index_dir.exists():
            existing_marker = _read_json_file(marker_path)
            if existing_marker is None:
                if not allow_unowned:
                    raise BlackLabBackendError(
                        "BlackLab corpus directory already exists but has no flexiCorp ownership marker. "
                        "Refusing to overwrite it. If this is the same corpus and you want to claim it, rerun with "
                        "-O blacklab-claim-existing=true."
                    )
                self._log(params, f"Claiming existing unowned index at {index_dir}")
            elif not _owner_markers_match(owner_marker, existing_marker):
                if not force_replace:
                    raise BlackLabBackendError(
                        "BlackLab corpus directory already exists and belongs to a different TEITOK/flexiCorp project. "
                        "Refusing to overwrite it. If you really want to replace it, rerun with "
                        "-O blacklab-force-replace=true."
                    )
                self._log(params, f"Forcing replacement of index owned by {existing_marker.get('project_root')}")
        action = "add" if mode == "add" and index_dir.exists() else "create"
        if mode == "replace" and index_dir.exists():
            shutil.rmtree(index_dir)

        env = dict(os.environ)
        env["BLACKLAB_CONFIG_DIR"] = str(format_root)
        self._log(params, f"Using local BlackLab tools with BLACKLAB_CONFIG_DIR={format_root}")
        self._log(params, f"Indexing {input_dir} into {index_dir} as corpus '{corpus_id}' using format '{format_name}'")
        cmd = [
            java_bin,
            *java_opts,
            "-cp",
            classpath,
            "nl.inl.blacklab.tools.IndexTool",
            action,
            str(index_dir),
            str(input_dir),
            format_name,
        ]
        proc = _run_command(cmd, env=env, verbose=verbose, prefix="[flexicorp][blacklab] ")
        index_dir.mkdir(parents=True, exist_ok=True)
        marker_path.write_text(json.dumps(owner_marker, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        summary = _summarize_indexer_output((proc.stdout or "").strip(), (proc.stderr or "").strip())
        return {
            "engine": "local",
            "command": " ".join(shlex.quote(part) for part in cmd),
            "index_dir": str(index_dir),
            "stdout": (proc.stdout or "").strip(),
            "stderr": (proc.stderr or "").strip(),
            "format_path": str(format_path),
            "result": summary,
        }

    def _run_docker_reindex(
        self,
        *,
        container: str,
        merged: Dict[str, Any],
        params: Dict[str, Any],
        input_dir: Path,
        corpus_id: str,
        format_name: str,
        format_path: Path,
        owner_marker: Dict[str, Any],
    ) -> Dict[str, Any]:
        docker_bin = _find_docker_bin()
        if not docker_bin:
            raise BlackLabBackendError("Docker is not available.")
        verbose = bool(params.get("debug") or params.get("verbose"))

        container_input_root = str(params.get("blacklab_docker_input_root") or merged.get("docker_input_root") or "/tmp/flexicorp-blacklab-input").strip()
        container_format_dir = str(params.get("blacklab_docker_format_dir") or merged.get("docker_format_dir") or "/etc/blacklab/formats").strip()
        container_index_root = str(params.get("blacklab_docker_index_root") or merged.get("docker_index_root") or "/data/index").strip()
        java_opts_raw = str(params.get("blacklab_java_opts") or merged.get("java_opts") or "").strip()
        container_java_cmd = str(params.get("blacklab_docker_java_cmd") or merged.get("docker_java_cmd") or "java").strip() or "java"
        mode = str(params.get("blacklab_reindex_mode") or merged.get("reindex_mode") or "replace").strip().lower()
        allow_unowned = str(params.get("blacklab_allow_unowned_reuse") or params.get("blacklab_claim_existing") or "").strip().lower() in {"1", "true", "yes", "on"}
        force_replace = str(params.get("blacklab_force_replace") or "").strip().lower() in {"1", "true", "yes", "on"}

        container_input_dir = f"{container_input_root.rstrip('/')}/{corpus_id}"
        container_format_path = f"{container_format_dir.rstrip('/')}/{format_name}.blf.yaml"
        container_index_dir = str(params.get("blacklab_docker_index_dir") or merged.get("docker_index_dir") or f"{container_index_root.rstrip('/')}/{corpus_id}").strip()
        container_marker_path = f"{container_index_dir.rstrip('/')}/{OWNER_MARKER_FILE}"

        if _docker_path_exists(docker_bin, container, container_index_dir):
            existing_marker = _docker_read_json(docker_bin, container, container_marker_path)
            if existing_marker is None:
                if not allow_unowned:
                    raise BlackLabBackendError(
                        "BlackLab corpus already exists in Docker but has no flexiCorp ownership marker. "
                        "Refusing to overwrite it. If this is the same corpus and you want to claim it, rerun with "
                        "-O blacklab-claim-existing=true."
                    )
                self._log(params, f"Claiming existing unowned Docker index at {container_index_dir}")
            elif not _owner_markers_match(owner_marker, existing_marker):
                if not force_replace:
                    raise BlackLabBackendError(
                        "BlackLab corpus already exists in Docker and belongs to a different TEITOK/flexiCorp project. "
                        "Refusing to overwrite it. If you really want to replace it, rerun with "
                        "-O blacklab-force-replace=true."
                    )
                self._log(params, f"Forcing replacement of Docker index owned by {existing_marker.get('project_root')}")

        self._log(params, f"Using Docker container '{container}'")
        self._log(params, f"Copying XML input from {input_dir} to {container}:{container_input_dir}")
        self._log(params, f"Copying generated format {format_path} to {container}:{container_format_path}")
        _run_command([docker_bin, "exec", container, "mkdir", "-p", container_input_dir, container_format_dir], verbose=verbose, prefix="[flexicorp][blacklab] ")
        _run_command([docker_bin, "exec", container, "rm", "-rf", container_input_dir], verbose=verbose, prefix="[flexicorp][blacklab] ")
        _run_command([docker_bin, "exec", container, "mkdir", "-p", container_input_dir], verbose=verbose, prefix="[flexicorp][blacklab] ")
        _run_command([docker_bin, "cp", f"{input_dir}{os.sep}.", f"{container}:{container_input_dir}"], verbose=verbose, prefix="[flexicorp][blacklab] ")
        _run_command([docker_bin, "cp", str(format_path), f"{container}:{container_format_path}"], verbose=verbose, prefix="[flexicorp][blacklab] ")
        if mode == "replace":
            self._log(params, f"Replacing existing index at {container_index_dir}")
            _run_command([docker_bin, "exec", container, "rm", "-rf", container_index_dir], verbose=verbose, prefix="[flexicorp][blacklab] ")
        action = "add" if mode == "add" else "create"
        shell_cmd = [
            "cd /usr/local/lib/blacklab-tools",
            f"{container_java_cmd} {java_opts_raw} -cp '*' nl.inl.blacklab.tools.IndexTool {action} {shlex.quote(container_index_dir)} {shlex.quote(container_input_dir)} {shlex.quote(format_name)}".strip(),
        ]
        cmd = [docker_bin, "exec", container, "/bin/bash", "-lc", " && ".join(shell_cmd)]
        self._log(params, f"Running BlackLab IndexTool in Docker with action='{action}'")
        proc = _run_command(cmd, verbose=verbose, prefix="[flexicorp][blacklab] ")
        marker_json = json.dumps(owner_marker, ensure_ascii=False, indent=2)
        marker_cmd = [
            docker_bin,
            "exec",
            container,
            "/bin/bash",
            "-lc",
            f"mkdir -p {shlex.quote(container_index_dir)} && cat > {shlex.quote(container_marker_path)} <<'EOF'\n{marker_json}\nEOF",
        ]
        _run_command(marker_cmd, verbose=verbose, prefix="[flexicorp][blacklab] ")
        summary = _summarize_indexer_output((proc.stdout or "").strip(), (proc.stderr or "").strip())
        return {
            "engine": "docker",
            "container": container,
            "command": " ".join(shlex.quote(part) for part in cmd),
            "index_dir": container_index_dir,
            "stdout": (proc.stdout or "").strip(),
            "stderr": (proc.stderr or "").strip(),
            "format_path": str(format_path),
            "container_format_path": container_format_path,
            "container_input_dir": container_input_dir,
            "result": summary,
        }

    def status(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        teitok_runtime = self._get_teitok_runtime(req, cfg)
        root = self._request_json(cfg, "")
        corpus_info = self._request_json(cfg, self._corpus_path(cfg))
        return {
            "backend": self.name,
            **self._capabilities_payload(teitok_runtime),
            "server": {
                "url": cfg.url,
                "apiVersion": root.get("apiVersion"),
                "blacklabVersion": root.get("blacklabVersion"),
                "blacklabBuildTime": root.get("blacklabBuildTime"),
                "blacklabScmRevision": root.get("blacklabScmRevision"),
            },
            "corpus": {
                "name": corpus_info.get("corpusName") or cfg.corpus,
                "status": corpus_info.get("status") or "available",
                "mainAnnotatedField": corpus_info.get("mainAnnotatedField"),
                "documentFormat": corpus_info.get("documentFormat"),
                "count": corpus_info.get("count") or {},
            },
        }

    def info(self, req: FlexiRequest) -> Dict[str, Any]:
        params = dict(req.get("params") or {})
        topic = str(params.get("topic") or "corpus").strip().lower()
        if topic == "corpora":
            cfg = self._get_server_config(req)
            teitok_runtime = self._get_teitok_runtime(req, cfg)
            root_info = self._request_json(cfg, "")
            corpora_map = dict(root_info.get("corpora") or {})
            corpora: List[Dict[str, Any]] = []
            for corpus_id, corpus_info in corpora_map.items():
                info = dict(corpus_info or {})
                corpora.append(
                    {
                        "id": str(corpus_id),
                        "status": str(info.get("status") or ""),
                        "documentFormat": str(info.get("documentFormat") or ""),
                        "count": dict(info.get("count") or {}),
                        "timeModified": info.get("timeModified"),
                    }
                )
            corpora.sort(key=lambda item: item["id"])
            return {
                "backend": self.name,
                **self._capabilities_payload(teitok_runtime),
                "server": {
                    "url": cfg.url,
                    "apiVersion": root_info.get("apiVersion"),
                    "blacklabVersion": root_info.get("blacklabVersion"),
                },
                "corpora": corpora,
            }

        cfg = self._get_config(req)
        teitok_runtime = self._get_teitok_runtime(req, cfg)
        corpus_info = self._request_json(cfg, self._corpus_path(cfg))
        out = {
            "backend": self.name,
            "descriptor": self.descriptor(),
            **self._capabilities_payload(teitok_runtime),
            "server": {"url": cfg.url},
            "corpus": corpus_info,
        }
        if teitok_runtime is not None:
            sattributes = list(teitok_runtime.get("sattributes") or [])
            out["sattributes"] = sattributes
            out["sattributes_by_region"] = {name: [] for name in sattributes}
        return out

    def list_docs(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        teitok_runtime = self._get_teitok_runtime(req, cfg)
        params = dict(req.get("params") or {})
        limit = max(0, int(params.get("limit", 50)))
        offset = max(0, int(params.get("offset", 0)))
        filter_query = str(params.get("filter") or "").strip()
        query_params: Dict[str, Any] = {
            "first": offset,
            "number": limit,
            "waitfortotal": "true",
            "listmetadatavalues": "*",
        }
        doc_pid = str(params.get("doc") or params.get("docpid") or "").strip()
        if doc_pid:
            query_params["docpid"] = doc_pid
        if filter_query:
            query_params["filter"] = filter_query
            query_params["filterlang"] = str(params.get("filter_language") or params.get("filterlang") or cfg.filterlang)
        payload = self._request_json(cfg, f"{self._corpus_path(cfg)}/docs", params=query_params)
        docs_out: List[Dict[str, Any]] = []
        for doc in payload.get("docs") or []:
            if not isinstance(doc, dict):
                continue
            doc_pid = str(doc.get("docPid") or "")
            doc_info = dict(doc.get("docInfo") or {})
            metadata = dict(doc_info.get("metadata") or {})
            meta_simple = {str(k): _first_text(v) for k, v in metadata.items() if _first_text(v) is not None}
            display_doc_id = doc_pid
            if teitok_runtime is not None:
                display_doc_id = _normalize_teitok_doc_id(
                    str(meta_simple.get("fromInputFile") or doc_pid),
                    root_dir=Path(teitok_runtime["root_dir"]),
                    searchfolder=str(teitok_runtime["searchfolder"]),
                    corpus_id=str(cfg.corpus),
                ) or doc_pid
            token_counts = list(doc_info.get("tokenCounts") or [])
            docs_out.append(
                {
                    "id": display_doc_id,
                    "title": meta_simple.get("title") or display_doc_id,
                    "meta": {**meta_simple, "backend_doc_pid": doc_pid},
                    "token_counts": token_counts,
                    "may_view": bool(doc_info.get("mayView", True)),
                }
            )
        summary = dict(payload.get("summary") or {})
        results_stats = dict(summary.get("resultsStats") or {})
        total = int(results_stats.get("documents") or summary.get("numberOfDocs") or len(docs_out))
        return {"docs": docs_out, "total": total}

    def query(self, req: FlexiRequest) -> Dict[str, Any]:
        cfg = self._get_config(req)
        teitok_runtime = self._get_teitok_runtime(req, cfg)
        params = dict(req.get("params") or {})
        context_spec = normalize_context_request(params)
        query_text = str(params.get("query") or "").strip()
        if not query_text:
            raise BlackLabBackendError("BlackLab query requires params['query'] to be a non-empty BCQL string.")

        query_lang = str(params.get("query_language") or params.get("query_lang") or cfg.pattlang).strip().lower()
        if query_lang not in {"bcql", "corpusql"}:
            raise BlackLabBackendError("The BlackLab backend currently only supports query_language='bcql'.")

        start = max(0, int(params.get("start", 0)))
        max_hits = max(0, min(int(params.get("max", 50)), 5000))
        context = self._resolve_context_value(cfg, params)

        query_params: Dict[str, Any] = {
            "patt": query_text,
            "pattlang": "bcql",
            "first": start,
            "number": max_hits,
            "waitfortotal": "true",
            "context": context,
            "listmetadatavalues": "*",
        }
        field = str(params.get("field") or cfg.default_field or "").strip()
        if field:
            query_params["field"] = field
        filter_query = str(params.get("filter") or "").strip()
        if filter_query:
            query_params["filter"] = filter_query
            query_params["filterlang"] = str(params.get("filter_language") or params.get("filterlang") or cfg.filterlang)

        payload = self._request_json(cfg, f"{self._corpus_path(cfg)}/hits", params=query_params)
        doc_infos = dict(payload.get("docInfos") or {})
        hits_out: List[Dict[str, Any]] = []
        for hit in payload.get("hits") or []:
            if not isinstance(hit, dict):
                continue
            doc_pid = str(hit.get("docPid") or "")
            before = dict(hit.get("before") or {})
            match = dict(hit.get("match") or {})
            after = dict(hit.get("after") or {})
            doc_info = dict(doc_infos.get(doc_pid) or {})
            metadata = dict(doc_info.get("metadata") or {})
            meta_simple = {str(k): _first_text(v) for k, v in metadata.items() if _first_text(v) is not None}
            word_before = _stitch_kwic_part(before)
            word_match = _stitch_kwic_part(match)
            word_after = _stitch_kwic_part(after)
            display_doc_id = doc_pid
            tok_ids: List[str] = []
            sentence_id: Optional[str] = None
            if teitok_runtime is not None:
                display_doc_id = _normalize_teitok_doc_id(
                    str(meta_simple.get("fromInputFile") or doc_pid),
                    root_dir=Path(teitok_runtime["root_dir"]),
                    searchfolder=str(teitok_runtime["searchfolder"]),
                    corpus_id=str(cfg.corpus),
                ) or doc_pid
                snippet_parts = self._fetch_match_snippet(
                    cfg,
                    doc_pid=doc_pid,
                    start=hit.get("start"),
                    end=hit.get("end"),
                )
                tok_ids = _extract_xml_ids(str(snippet_parts.get("match") or ""), tag_names=["tok", "dtok"])
            groups: List[Dict[str, Any]] = []
            for group_id, group_info in dict(hit.get("matchInfos") or {}).items():
                if isinstance(group_info, dict):
                    groups.append(
                        {
                            "id": str(group_id),
                            "name": str(group_id),
                            "type": str(group_info.get("type") or "span"),
                            "start": group_info.get("start"),
                            "end": group_info.get("end"),
                        }
                    )
            hit_out: Dict[str, Any] = {
                "doc_id": display_doc_id,
                "sentence_id": sentence_id,
                "toks": tok_ids or _list_text(match.get("word")),
                "row": {
                    "doc_id": display_doc_id,
                    "doc_pid": doc_pid,
                    "start": hit.get("start"),
                    "end": hit.get("end"),
                },
                "match_start": hit.get("start"),
                "match_end": hit.get("end"),
                "raw": self.raw_kwic_delimiter.join([word_before, word_match, word_after]),
                "context": {
                    "format": "text",
                    "scope": context,
                    "left": word_before,
                    "match": word_match,
                    "right": word_after,
                },
                "meta": meta_simple,
                "doc": {
                    "id": display_doc_id,
                    "title": meta_simple.get("title") or display_doc_id,
                    "meta": {**meta_simple, "backend_doc_pid": doc_pid},
                    "may_view": bool(doc_info.get("mayView", True)),
                },
            }
            if teitok_runtime is not None and context_spec and display_doc_id:
                local_context_spec = dict(context_spec)
                if local_context_spec.get("scope") == "s" and teitok_runtime.get("sentence_scope"):
                    local_context_spec["scope"] = teitok_runtime["sentence_scope"]
                context_data = resolve_teitok_context(
                    root_dir=Path(teitok_runtime["root_dir"]),
                    searchfolder=str(teitok_runtime["searchfolder"]),
                    doc_id=display_doc_id,
                    sentence_id=sentence_id,
                    tok_ids=[str(tok) for tok in tok_ids if str(tok)],
                    match_start=hit.get("start"),
                    match_end=hit.get("end"),
                    context_spec=local_context_spec,
                )
                if context_data:
                    hit_out["context"] = context_data
                    locator = context_data.get("locator") if isinstance(context_data, dict) else None
                    if isinstance(locator, dict) and locator.get("sentence_id"):
                        hit_out["sentence_id"] = str(locator.get("sentence_id"))
            if groups:
                hit_out["highlight_map"] = {"groups": groups}
            hits_out.append(hit_out)

        summary = dict(payload.get("summary") or {})
        results_stats = dict(summary.get("resultsStats") or {})
        total = int(results_stats.get("hits") or summary.get("numberOfHits") or len(hits_out))
        out: Dict[str, Any] = {
            "total": total,
            "start": start,
            "returned": len(hits_out),
            "hits": hits_out,
            "result_type": "hits",
            "query_lang": "bcql",
            "engine": "blacklab-server",
            **self._capabilities_payload(teitok_runtime),
            "server_url": cfg.url,
            "corpus": cfg.corpus,
            "pattern": summary.get("pattern"),
        }
        if teitok_runtime is not None:
            sattributes = list(teitok_runtime.get("sattributes") or [])
            out["sattributes"] = sattributes
            out["sattributes_by_region"] = {name: [] for name in sattributes}
        return out

    def reindex(self, req: FlexiRequest) -> Dict[str, Any]:
        project = dict(req.get("project") or {})
        params = dict(req.get("params") or {})
        root = get_project_root(project)
        merged = get_blacklab_settings(project)
        profile = _build_teitok_profile(root)
        input_dir = self._resolve_input_dir(root, profile, params)
        self._log(params, f"Detected project root: {root}")
        if profile.get("settings_path"):
            self._log(params, f"Using TEITOK settings: {profile['settings_path']}")
        self._log(params, f"Resolved input folder: {input_dir}")
        if not input_dir.is_dir():
            raise BlackLabBackendError(f"BlackLab reindex input folder does not exist: {input_dir}")

        corpus_id = self._resolve_reindex_corpus_id(profile, merged, params, root)
        self._log(params, f"Resolved BlackLab corpus id: {corpus_id}")
        generated = self._write_generated_format(root, corpus_id, profile, input_dir)
        format_root = Path(generated["format_root"])
        format_name = str(generated["format_name"])
        format_path = Path(generated["format_path"])
        owner_marker = _build_owner_marker(root, profile, input_dir, corpus_id)
        self._log(params, f"Generated BlackLab format: {format_path}")

        engine = str(params.get("blacklab_reindex_engine") or merged.get("reindex_engine") or "").strip().lower()
        result: Dict[str, Any]
        if engine == "local":
            result = self._run_local_reindex(
                merged=merged,
                params=params,
                input_dir=input_dir,
                corpus_id=corpus_id,
                format_root=format_root,
                format_name=format_name,
                format_path=format_path,
                owner_marker=owner_marker,
            )
        else:
            container = self._discover_blacklab_container(merged, params)
            if engine == "docker" or container:
                if not container:
                    raise BlackLabBackendError(
                        "BlackLab Docker reindex was requested, but no accessible running BlackLab container "
                        "was found from this process. Ensure the web/PHP process can run Docker and see the "
                        "BlackLab container, or configure a specific container name."
                    )
                result = self._run_docker_reindex(
                    container=container,
                    merged=merged,
                    params=params,
                    input_dir=input_dir,
                    corpus_id=corpus_id,
                    format_name=format_name,
                    format_path=format_path,
                    owner_marker=owner_marker,
                )
            else:
                if _find_docker_bin() is not None:
                    # Docker exists but this process cannot access the container; enqueue for job-runner script.
                    container = self._resolve_docker_container_for_queue(merged, params)
                    container_input_root = str(params.get("blacklab_docker_input_root") or merged.get("docker_input_root") or "/tmp/flexicorp-blacklab-input").strip()
                    container_format_dir = str(params.get("blacklab_docker_format_dir") or merged.get("docker_format_dir") or "/etc/blacklab/formats").strip()
                    container_index_root = str(params.get("blacklab_docker_index_root") or merged.get("docker_index_root") or "/data/index").strip()
                    mode = str(params.get("blacklab_reindex_mode") or merged.get("reindex_mode") or "replace").strip().lower()
                    if mode not in ("replace", "add"):
                        mode = "replace"
                    java_cmd = str(params.get("blacklab_docker_java_cmd") or merged.get("docker_java_cmd") or "java").strip() or "java"
                    java_opts = str(params.get("blacklab_java_opts") or merged.get("java_opts") or "").strip()
                    job_id = self._write_reindex_job(
                        root,
                        container=container,
                        corpus_id=corpus_id,
                        format_name=format_name,
                        host_input_dir=input_dir,
                        host_format_path=format_path,
                        container_input_root=container_input_root,
                        container_format_dir=container_format_dir,
                        container_index_root=container_index_root,
                        mode=mode,
                        owner_marker=owner_marker,
                        java_cmd=java_cmd,
                        java_opts=java_opts,
                        params=params,
                    )
                    # Create a symlink in the project tree pointing to the runtime lock in /tmp
                    try:
                        runtime_root = Path("/tmp") / "flexicorp-blacklab" / root.name
                        runtime_lock = runtime_root / "blacklab-reindex.lock"
                        link_dir = root / "tmp" / "flexicorp-locks"
                        link_dir.mkdir(parents=True, exist_ok=True)
                        link_path = link_dir / "blacklab-reindex.lock"
                        if not link_path.exists():
                            link_path.symlink_to(runtime_lock)
                    except Exception:
                        pass
                    queue_dir = root / "tmp" / JOB_QUEUE_SUBDIR.replace("/", os.sep)
                    runner_triggered = self._try_run_runner_setuid(
                        queue_dir, merged, params, root
                    ) or self._try_run_runner_via_sudo(queue_dir, merged, params, root)
                    return {
                        "status": "enqueued",
                        "backend": self.name,
                        "corpus": corpus_id,
                        "input_dir": str(input_dir),
                        "settings_path": profile.get("settings_path"),
                        "generated_format": {"name": format_name, "path": str(format_path)},
                        "indexer": {
                            "enqueued": True,
                            "job_id": job_id,
                            "runner_triggered": runner_triggered,
                            "message": "Reindex enqueued. Run the job runner script to process the queue."
                            if not runner_triggered
                            else "Reindex enqueued and runner triggered; job is processing.",
                        },
                        "server_visibility": {"server_checked": False},
                        "message": "Reindex enqueued. A background job runner must process the queue."
                        if not runner_triggered
                        else "Reindex enqueued and runner started; poll for status.",
                    }
                result = self._run_local_reindex(
                    merged=merged,
                    params=params,
                    input_dir=input_dir,
                    corpus_id=corpus_id,
                    format_root=format_root,
                    format_name=format_name,
                    format_path=format_path,
                    owner_marker=owner_marker,
                )

        visibility: Dict[str, Any] = {"server_checked": False}
        try:
            server_cfg = self._get_server_config(req)
            root_info = self._request_json(server_cfg, "")
            corpora = dict(root_info.get("corpora") or {})
            visibility = {
                "server_checked": True,
                "server_url": server_cfg.url,
                "visible_in_server": corpus_id in corpora,
            }
        except Exception as exc:
            visibility = {
                "server_checked": False,
                "warning": str(exc),
            }

        reindex_status = str((result.get("result") or {}).get("status") or "ok")
        return {
            "status": reindex_status,
            "backend": self.name,
            "corpus": corpus_id,
            "input_dir": str(input_dir),
            "settings_path": profile.get("settings_path"),
            "generated_format": {
                "name": format_name,
                "path": str(format_path),
            },
            "indexer": result,
            "server_visibility": visibility,
            "message": (
                "BlackLab reindex finished using a TEITOK-derived format. "
                "The generated .blf.yaml is kept under tmp/blacklab/formats for inspection."
            ),
        }


register_backend(BlackLabBackend())
