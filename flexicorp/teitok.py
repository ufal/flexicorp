from __future__ import annotations

"""
Helpers for working with TEITOK corpus folders.

For flexiCorp we only need a very small subset of TEITOK's settings:
- locate a TEITOK project root given an arbitrary path,
- read the CQP corpus name from settings.xml,
- derive the local CQP registry directory (cqp/<lowercase-corpus> when present).
- read ClickHouse defaults from settings.xml when a TEITOK corpus exposes them.

The full TEITOK parsing logic lives in flexipipe; this module intentionally
implements only the minimal pieces needed for the CQP backend.
"""

from pathlib import Path
from typing import Any, Dict, Optional
import xml.etree.ElementTree as ET
import re


def _find_settings_xml(start: Path) -> Optional[Path]:
    """
    Find a TEITOK settings XML file starting from `start` and walking up.

    Search order (similar to flexipipe.teitok_settings.find_settings_xml):
    1. tmp/cqpsettings.xml
    2. Resources/settings.xml
    3. settings.xml in the root
    4. Parent directories, up to a few levels
    """
    if start.is_file():
        search_dir = start.parent
    else:
        search_dir = start

    # Prefer project-local Resources/settings.xml over tmp/cqpsettings.xml
    # for CLI use, so that example/test projects behave as configured.
    candidates: list[Path] = []

    # Current level: Resources/settings.xml, then settings.xml, then tmp/cqpsettings.xml
    candidates.extend(
        [
            search_dir / "Resources" / "settings.xml",
            search_dir / "settings.xml",
            search_dir / "tmp" / "cqpsettings.xml",
        ]
    )

    # Walk up a few levels and add the same pattern
    current = search_dir
    for _ in range(3):
        current = current.parent
        candidates.extend(
            [
                current / "Resources" / "settings.xml",
                current / "settings.xml",
                current / "tmp" / "cqpsettings.xml",
            ]
        )

    for cand in candidates:
        if cand.is_file():
            return cand
    return None


def _infer_teitok_root(settings_path: Path) -> Path:
    """
    Infer TEITOK project root from the located settings XML path.
    """
    if settings_path.name == "cqpsettings.xml" and settings_path.parent.name == "tmp":
        return settings_path.parent.parent
    if settings_path.parent.name == "Resources":
        return settings_path.parent.parent
    return settings_path.parent


def _load_teitok_settings(start: Path) -> tuple[Path, Path, ET.Element | None] | None:
    settings_path = _find_settings_xml(start)
    if not settings_path:
        return None
    root_dir = _infer_teitok_root(settings_path).resolve()
    try:
        xml_root = ET.parse(settings_path).getroot()
    except ET.ParseError:
        xml_root = None
    return root_dir, settings_path, xml_root


def detect_teitok_cqp(start: Path) -> Optional[Dict[str, Any]]:
    """
    Detect TEITOK CQP configuration given a starting path.

    Returns a dict suitable for merging into the flexiCorp project section:

        {
            "root": "<teitok_root>",
            "cqp": {
                "registry": "<registry_dir>|None",
                "corpus": "<CORPUS_NAME>",
                "cqp_binary": "cqp",
            },
        }

    If no TEITOK settings are found, returns None.
    """
    loaded = _load_teitok_settings(start)
    if not loaded:
        return None
    root_dir, settings_path, xml_root = loaded
    if xml_root is None:
        return {
            "root": str(root_dir),
            "cqp": {},
        }

    def _pick_main_cqp_element(root: ET.Element) -> ET.Element | None:
        """
        TEITOK's settings.xml may contain multiple <cqp> elements:
        - the main CQP config under <ttsettings><cqp corpus="...">
        - nested dictionary/aux structures where <cqp> might have different attrs
          (e.g. <cqp pos="" lemma="lemma" .../>).

        For corpus access we must select the one that carries the `corpus` attribute.
        """
        # Prefer the top-level direct child (fast path).
        direct = root.find("cqp")
        if direct is not None and direct.get("corpus"):
            return direct

        # Otherwise search for any <cqp> element with a corpus attribute.
        candidates = root.findall(".//cqp")
        for cand in candidates:
            if cand.get("corpus"):
                return cand

        # Fallback: keep prior behavior for incomplete settings (but at least stable).
        return direct or (candidates[0] if candidates else None)

    cqp_elem = _pick_main_cqp_element(xml_root)
    corpus = cqp_elem.get("corpus") if cqp_elem is not None else None

    # Basic TEITOK CQP metadata -------------------------------------------
    pattributes = []
    sattributes_by_region: Dict[str, list[str]] = {}
    word_attribute: Optional[str] = None
    searchfolder: Optional[str] = None
    docs_count: Optional[int] = None
    xml_encoding: Optional[str] = None
    fragment_context_scope: Optional[str] = None

    if cqp_elem is not None:
        # Positional attributes from <pattributes><item key="...">
        pattr_elem = cqp_elem.find("pattributes")
        if pattr_elem is not None:
            for item in pattr_elem.findall("item"):
                key = item.get("key")
                if key:
                    pattributes.append(key)

        # Structural attributes grouped by region from <sattributes><item key="region"><item key="attr"/>
        sattr_elem = cqp_elem.find("sattributes")
        if sattr_elem is not None:
            for region in sattr_elem.findall("item"):
                region_name = region.get("key")
                if not region_name:
                    continue
                attrs: list[str] = []
                for item in region.findall("item"):
                    key = item.get("key")
                    if key:
                        attrs.append(key)
                if attrs:
                    sattributes_by_region[region_name] = attrs

        # TEITOK-specific hints
        word_attribute = cqp_elem.get("wordfld")
        searchfolder = cqp_elem.get("searchfolder")
        # Optional: default XML fragment scope for corpora without <s>/<u> (see teitok_context).
        for attr in ("fragment_context_scope", "flexicorp_fragment_context_scope"):
            v = (cqp_elem.get(attr) or "").strip()
            if v:
                fragment_context_scope = v
                break

    # Approximate document count by counting XML files in searchfolder
    if searchfolder:
        xml_dir = root_dir / searchfolder
        if xml_dir.is_dir():
            xml_files = list(xml_dir.glob("*.xml"))
            docs_count = len(xml_files)
            if xml_files:
                try:
                    with xml_files[0].open("rb") as fh:
                        head = fh.read(256)
                    m = re.search(br'encoding=["\']([^"\']+)["\']', head)
                    if m:
                        xml_encoding = m.group(1).decode("ascii", errors="ignore") or None
                except OSError:
                    xml_encoding = None

    registry_dir: Optional[Path] = None
    cqp_root = root_dir / "cqp"
    if cqp_root.is_dir():
        if corpus:
            corpus_entry = cqp_root / corpus.lower()
            # Some installations use a subdirectory per corpus, others a single
            # registry file per corpus in the cqp folder.
            if corpus_entry.is_dir():
                registry_dir = corpus_entry
            else:
                registry_dir = cqp_root
        if registry_dir is None:
            registry_dir = cqp_root

    cqp_cfg: Dict[str, Any] = {
        "cqp_binary": "cqp",
    }
    if corpus:
        cqp_cfg["corpus"] = corpus
    if registry_dir is not None:
        cqp_cfg["registry"] = str(registry_dir)
    if xml_encoding:
        cqp_cfg["encoding"] = xml_encoding

    meta: Dict[str, Any] = {
        "settings_path": str(settings_path),
        "pattributes": pattributes,
        "sattributes_by_region": sattributes_by_region,
        "word_attribute": word_attribute,
        "searchfolder": searchfolder,
        "docs_count": docs_count,
        "encoding": xml_encoding,
    }
    if fragment_context_scope:
        meta["fragment_context_scope"] = fragment_context_scope

    return {
        "root": str(root_dir),
        "cqp": cqp_cfg,
        "meta": meta,
    }


def cqp_registry_dir_for_corpus(cqp_root: Path, corpus: Optional[str]) -> Path:
    """
    Registry directory under ``cqp_root`` for a named corpus (mirrors detect_teitok_cqp).

    Used when flexencoder writes to a staging ``cqp/`` tree so Manatee/CWB tools can
    point at the in-progress build before it replaces the live ``project/cqp``.
    """
    if corpus:
        corpus_entry = cqp_root / str(corpus).lower()
        if corpus_entry.is_dir():
            return corpus_entry
    return cqp_root


def detect_teitok_manatee(start: Path) -> Optional[Dict[str, Any]]:
    """
    Detect a TEITOK-local Manatee registry/configuration.

    This is intentionally best-effort: many TEITOK projects only expose CQP in
    settings.xml today, so we look for a local `manatee/` directory and infer
    the corpus config file name from the files present there.
    """
    loaded = _load_teitok_settings(start)
    if not loaded:
        return None
    root_dir, _settings_path, xml_root = loaded
    manatee_dir = root_dir / "manatee"
    if not manatee_dir.is_dir():
        return None

    def _pick_main_cqp_element(root: ET.Element) -> ET.Element | None:
        direct = root.find("cqp")
        if direct is not None and direct.get("corpus"):
            return direct
        candidates = root.findall(".//cqp")
        for cand in candidates:
            if cand.get("corpus"):
                return cand
        return direct or (candidates[0] if candidates else None)

    cqp_elem = _pick_main_cqp_element(xml_root) if xml_root is not None else None
    cqp_corpus = cqp_elem.get("corpus") if cqp_elem is not None else None

    registry_files = [
        path
        for path in sorted(manatee_dir.iterdir())
        if path.is_file() and path.name != "corpus.vrt" and not path.name.startswith(".")
    ]
    if not registry_files:
        return None

    def _normalize(name: str) -> str:
        return re.sub(r"[-\s]+", "_", name.strip().lower())

    selected = None
    if cqp_corpus:
        normalized_target = _normalize(cqp_corpus)
        for candidate in registry_files:
            if _normalize(candidate.name) == normalized_target:
                selected = candidate
                break
    if selected is None and len(registry_files) == 1:
        selected = registry_files[0]
    if selected is None:
        for candidate in registry_files:
            if "." not in candidate.name:
                selected = candidate
                break
    if selected is None:
        selected = registry_files[0]

    return {
        "root": str(root_dir),
        "manatee": {
            "registry": str(manatee_dir),
            "corpus": selected.name,
        },
    }


def detect_teitok_clickhouse(start: Path) -> Optional[Dict[str, Any]]:
    """
    Detect ClickHouse-related TEITOK settings.

    This mirrors legacy clickcql defaults:
    - database comes from cqp/@corpus (lowercased),
    - connection defaults come from defaults/clickhouse,
    - the query schema defaults to docs/toks/sentences unless overridden later.
    """
    loaded = _load_teitok_settings(start)
    if not loaded:
        return None
    root_dir, settings_path, xml_root = loaded
    if xml_root is None:
        return {
            "root": str(root_dir),
            "clickhouse": {},
            "meta": {"settings_path": str(settings_path)},
        }

    def _pick_main_cqp_element(root: ET.Element) -> ET.Element | None:
        direct = root.find("cqp")
        if direct is not None and direct.get("corpus"):
            return direct
        candidates = root.findall(".//cqp")
        for cand in candidates:
            if cand.get("corpus"):
                return cand
        return direct or (candidates[0] if candidates else None)

    cqp_elem = _pick_main_cqp_element(xml_root) if xml_root is not None else None
    clickhouse_elem = xml_root.find(".//defaults/clickhouse")
    corpus = (cqp_elem.get("corpus") if cqp_elem is not None else None) or None

    if clickhouse_elem is None and not corpus:
        return None

    host = clickhouse_elem.get("host") if clickhouse_elem is not None else None
    port_raw = clickhouse_elem.get("port") if clickhouse_elem is not None else None
    user = clickhouse_elem.get("user") if clickhouse_elem is not None else None
    password = clickhouse_elem.get("password") if clickhouse_elem is not None else None
    css = clickhouse_elem.get("css") if clickhouse_elem is not None else None
    js = clickhouse_elem.get("js") if clickhouse_elem is not None else None
    context = clickhouse_elem.get("context") if clickhouse_elem is not None else None
    threads = clickhouse_elem.get("threads") if clickhouse_elem is not None else None

    clickhouse_cfg: Dict[str, Any] = {
        "host": host or "localhost",
        "port": int(port_raw) if port_raw else 8123,
        "user": user or "www",
        "password": password or "localpwd",
        "tables": {
            "tokens": "toks",
            "docs": "docs",
            "sentences": "sentences",
        },
    }
    if corpus:
        clickhouse_cfg["database"] = corpus.lower()

    meta: Dict[str, Any] = {
        "settings_path": str(settings_path),
        "database_source": "cqp/@corpus" if corpus else None,
        "css": css,
        "js": js,
        "context": context,
        "threads": threads,
    }

    return {
        "root": str(root_dir),
        "clickhouse": clickhouse_cfg,
        "meta": meta,
    }


def detect_teitok_blacklab(start: Path) -> Optional[Dict[str, Any]]:
    """
    Detect TEITOK-local defaults for a BlackLab corpus.

    This does not prove the BlackLab index exists; it just derives the default
    corpus id that flexiCorp should try when a TEITOK project switches to the
    BlackLab backend without an explicit override.
    """
    loaded = _load_teitok_settings(start)
    if not loaded:
        return None
    root_dir, settings_path, xml_root = loaded
    if xml_root is None:
        return {
            "root": str(root_dir),
            "blacklab": {},
            "meta": {"settings_path": str(settings_path)},
        }

    cqp_elem = xml_root.find(".//cqp")
    blacklab_elem = xml_root.find(".//blacklab")
    corpus = str(cqp_elem.get("corpus") or "").strip() if cqp_elem is not None else ""
    blacklab_corpus = str(blacklab_elem.get("corpus") or "").strip() if blacklab_elem is not None else ""
    field = str(blacklab_elem.get("field") or "").strip() if blacklab_elem is not None else ""
    query_language = (
        str(blacklab_elem.get("query_language") or blacklab_elem.get("query-language") or "").strip()
        if blacklab_elem is not None
        else ""
    )
    effective_corpus = blacklab_corpus or corpus
    if not effective_corpus:
        return None

    return {
        "root": str(root_dir),
        "blacklab": {
            "corpus": effective_corpus,
            "field": field or "contents",
            "query_language": query_language or "bcql",
        },
        "meta": {
            "settings_path": str(settings_path),
            "corpus_source": "blacklab/@corpus" if blacklab_corpus else "cqp/@corpus",
        },
    }

