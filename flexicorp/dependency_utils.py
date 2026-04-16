from __future__ import annotations

import importlib
import importlib.util
import subprocess
import sys
from pathlib import Path

from .settings import get_auto_install_optional_deps


def module_available(module_name: str) -> bool:
    return importlib.util.find_spec(module_name) is not None


def _is_externally_managed_environment() -> bool:
    """
    True when PEP 668 marks this interpreter as externally managed (e.g. Debian/Ubuntu
    system Python). In that case ``pip install`` into site-packages is blocked; skip
    attempting it to avoid noisy stderr and a guaranteed failure.
    """
    try:
        import sysconfig

        for key in ("stdlib", "platstdlib"):
            try:
                marker = Path(sysconfig.get_path(key)) / "EXTERNALLY-MANAGED"
                if marker.is_file():
                    return True
            except Exception:
                continue
    except Exception:
        pass
    return False


def _run_pip_install(package_name: str) -> bool:
    cmd = [sys.executable, "-m", "pip", "install", package_name]
    print(f"[flexicorp] Installing optional dependency via: {' '.join(cmd)}", file=sys.stderr)
    try:
        subprocess.check_call(cmd, stdout=sys.stderr, stderr=sys.stderr)
    except subprocess.CalledProcessError:
        return False
    importlib.invalidate_caches()
    return True


def ensure_package_installed(
    package_name: str,
    *,
    module_name: str,
    friendly_name: str,
) -> None:
    if module_available(module_name):
        return

    hint = (
        f"Install it manually with: pip install {package_name}. "
        "You can disable automatic installs via "
        "`python -m flexicorp config --set-auto-install-optional-deps false`."
    )
    pep668_hint = (
        f"{friendly_name} requires optional dependency '{package_name}'. "
        "This Python is externally managed (PEP 668): install the package in a venv, "
        "use your OS package manager, or disable auto-install with "
        "`python -m flexicorp config --set-auto-install-optional-deps false`."
    )

    if get_auto_install_optional_deps():
        if _is_externally_managed_environment():
            raise ImportError(pep668_hint)
        if _run_pip_install(package_name) and module_available(module_name):
            return
        raise ImportError(
            f"{friendly_name} requires optional dependency '{package_name}', "
            f"but automatic installation failed. {hint}"
        )

    raise ImportError(
        f"{friendly_name} requires optional dependency '{package_name}'. {hint}"
    )
