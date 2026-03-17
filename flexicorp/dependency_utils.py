from __future__ import annotations

import importlib
import importlib.util
import subprocess
import sys

from .settings import get_auto_install_optional_deps


def module_available(module_name: str) -> bool:
    return importlib.util.find_spec(module_name) is not None


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

    if get_auto_install_optional_deps():
        if _run_pip_install(package_name) and module_available(module_name):
            return
        raise ImportError(
            f"{friendly_name} requires optional dependency '{package_name}', "
            f"but automatic installation failed. {hint}"
        )

    raise ImportError(
        f"{friendly_name} requires optional dependency '{package_name}'. {hint}"
    )
