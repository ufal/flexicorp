"""
Regression tests for ``ManateeBackend._manatee_tools_path``.

The repo-root fallback used ``Path(__file__).resolve().parents[3]``, which
resolved to *one level above* the flexicorp checkout on typical layouts —
so the bundled ``git/manatee-open-*/src/encodevert`` was never picked up.
Users then saw::

    manatee: encodevert not found. Set MANATEE_SRC
    (or project.manatee.tools_path) to the Manatee build directory,
    or put encodevert on PATH.

…even though ``encodevert`` was sitting right there inside their checkout.

The fix is a structural scan: walk up from this file and return the first
ancestor whose ``git/`` subdir contains ``manatee-open*/src/encodevert``.
This test uses a temporary mock layout so it runs anywhere, regardless of
whether the real ``git/manatee-open-*`` tree is present.
"""

from __future__ import annotations

import os
import stat
import sys
import tempfile
import unittest.mock as mock
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT))

from flexicorp.backends.manatee_backend import ManateeBackend  # noqa: E402


def _make_mock_manatee_tree(parent: Path, name: str = "manatee-open-2.225.8") -> Path:
    """Build a throwaway ``<parent>/git/<name>/src/encodevert`` executable."""
    manatee_dir = parent / "git" / name
    (manatee_dir / "src").mkdir(parents=True, exist_ok=True)
    encodevert = manatee_dir / "src" / "encodevert"
    encodevert.write_text("#!/bin/sh\necho mock encodevert\n")
    encodevert.chmod(
        encodevert.stat().st_mode | stat.S_IEXEC | stat.S_IXGRP | stat.S_IXOTH
    )
    return manatee_dir


def test_tools_path_discovers_nearby_git_manatee_open() -> None:
    """Emulate the real flexicorp layout: ``<root>/flexicorp/backends/manatee_backend.py``
    with encodevert sitting under ``<root>/git/manatee-open-2.225.8/src/``."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        fake_pkg = root / "flexicorp" / "backends"
        fake_pkg.mkdir(parents=True)
        mock_file = fake_pkg / "manatee_backend.py"
        mock_file.write_text("# mock\n")
        manatee_dir = _make_mock_manatee_tree(root)

        backend = ManateeBackend()
        # Patch __file__ so the discovery walk starts from the fake layout.
        with mock.patch(
            "flexicorp.backends.manatee_backend.__file__", str(mock_file)
        ), mock.patch.dict(os.environ, {}, clear=False):
            # Ensure no MANATEE_SRC env var leaks in from the caller.
            os.environ.pop("MANATEE_SRC", None)
            result = backend._manatee_tools_path({}, {})

        assert result is not None, (
            "Expected discovery to find the bundled manatee-open tree; "
            "got None (this is the off-by-one regression)."
        )
        assert result == manatee_dir.resolve(), f"Got {result!r}"
        assert (result / "src" / "encodevert").is_file()


def test_tools_path_prefers_explicit_project_tools_path() -> None:
    """Explicit config wins over the auto-discovery walk."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        # Build two different trees; explicit config points at ``explicit``.
        explicit = root / "explicit"
        explicit.mkdir()
        (explicit / "src").mkdir()
        (explicit / "src" / "encodevert").write_text("")
        (explicit / "src" / "encodevert").chmod(0o755)

        fake_pkg = root / "flexicorp" / "backends"
        fake_pkg.mkdir(parents=True)
        mock_file = fake_pkg / "manatee_backend.py"
        mock_file.write_text("# mock\n")
        _make_mock_manatee_tree(root)  # auto-discoverable option

        backend = ManateeBackend()
        with mock.patch(
            "flexicorp.backends.manatee_backend.__file__", str(mock_file)
        ):
            os.environ.pop("MANATEE_SRC", None)
            result = backend._manatee_tools_path(
                {"manatee": {"tools_path": str(explicit)}}, {}
            )
        assert result == explicit.resolve(), (
            f"Expected explicit config to win over auto-discovery; got {result!r}"
        )


def test_tools_path_returns_none_when_nothing_available() -> None:
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        # A layout with NO git/manatee-open tree anywhere in its ancestry.
        fake_pkg = root / "isolated" / "pkg"
        fake_pkg.mkdir(parents=True)
        mock_file = fake_pkg / "manatee_backend.py"
        mock_file.write_text("# mock\n")

        backend = ManateeBackend()
        with mock.patch(
            "flexicorp.backends.manatee_backend.__file__", str(mock_file)
        ):
            os.environ.pop("MANATEE_SRC", None)
            result = backend._manatee_tools_path({}, {})
        assert result is None


def test_tools_path_picks_any_manatee_open_variant() -> None:
    """The glob ``manatee-open*`` must match version-suffixed names, not just
    the bare ``manatee-open`` directory. Older code only tried two fixed
    names."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        fake_pkg = root / "flexicorp" / "backends"
        fake_pkg.mkdir(parents=True)
        mock_file = fake_pkg / "manatee_backend.py"
        mock_file.write_text("# mock\n")
        # Variant name: encodevert only in manatee-open-2.300.0
        manatee_dir = _make_mock_manatee_tree(root, name="manatee-open-2.300.0")

        backend = ManateeBackend()
        with mock.patch(
            "flexicorp.backends.manatee_backend.__file__", str(mock_file)
        ):
            os.environ.pop("MANATEE_SRC", None)
            result = backend._manatee_tools_path({}, {})
        assert result == manatee_dir.resolve()


TESTS = [
    test_tools_path_discovers_nearby_git_manatee_open,
    test_tools_path_prefers_explicit_project_tools_path,
    test_tools_path_returns_none_when_nothing_available,
    test_tools_path_picks_any_manatee_open_variant,
]


def _run() -> int:
    passed = failed = 0
    for t in TESTS:
        try:
            t()
        except Exception as exc:
            print(f"[FAIL] {t.__name__}: {type(exc).__name__}: {exc}")
            failed += 1
        else:
            print(f"[ok]   {t.__name__}")
            passed += 1
    print(f"{passed}/{len(TESTS)} passed")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(_run())
