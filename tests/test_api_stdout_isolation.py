"""
Regression tests for the --api stdout isolator.

Native bindings (Manatee `_manatee.so` in particular, but also some CWB
utilities) write warnings/errors straight to the process's stdout file
descriptor via C stdio / C++ iostreams. Those writes bypass Python's
`sys.stdout` object — they land on FD 1 directly. In --api mode that
corrupts the JSON envelope on stdout; the TEITOK PHP wrapper then reports
"flexicorp did not return valid JSON" even though the Python side emitted a
perfectly valid envelope.

`cli._isolate_stdout_for_api()` swaps FD 1 for a temp file during request
handling and forwards any captured bytes to stderr with a diagnostic
prefix. These tests simulate native-library leakage from Python by using
`os.write(1, ...)` — which is the same FD-level write native code does —
and verify that stdout remains clean JSON.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import textwrap
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

REPO_ROOT = Path(__file__).resolve().parent.parent


def _python() -> str:
    return sys.executable


def test_isolator_captures_raw_fd1_writes() -> None:
    """Simulate a native leak with os.write(1, ...) and confirm stdout is clean."""
    script = textwrap.dedent("""
        import sys, os
        sys.path.insert(0, __REPO__)
        from flexicorp.cli import _isolate_stdout_for_api
        with _isolate_stdout_for_api():
            # This is what a C++ binding would do: write directly to FD 1.
            os.write(1, b"NATIVE LIBRARY CHATTER\\n")
            os.write(1, b"WARNING: registry missing\\n")
        # After restore, write the real payload to stdout.
        sys.stdout.write('{"ok": true}\\n')
        sys.stdout.flush()
    """).replace("__REPO__", repr(str(REPO_ROOT)))
    proc = subprocess.run(
        [_python(), "-c", script], capture_output=True, text=True, cwd=str(REPO_ROOT)
    )
    assert proc.returncode == 0, f"subprocess failed: {proc.stderr!r}"
    # stdout must be valid JSON on its own.
    out = proc.stdout.strip()
    doc = json.loads(out)
    assert doc == {"ok": True}, f"unexpected stdout json: {doc!r}"
    # The chatter must have been routed to stderr with our diagnostic prefix.
    assert "captured stray stdout" in proc.stderr, f"stderr missing prefix: {proc.stderr!r}"
    assert "NATIVE LIBRARY CHATTER" in proc.stderr
    assert "registry missing" in proc.stderr


def test_isolator_is_noop_when_nothing_leaks() -> None:
    """Clean inner body → no stderr noise from the isolator."""
    script = textwrap.dedent("""
        import sys
        sys.path.insert(0, __REPO__)
        from flexicorp.cli import _isolate_stdout_for_api
        with _isolate_stdout_for_api():
            pass  # no leakage
        sys.stdout.write('{"ok": true}\\n')
    """).replace("__REPO__", repr(str(REPO_ROOT)))
    proc = subprocess.run(
        [_python(), "-c", script], capture_output=True, text=True, cwd=str(REPO_ROOT)
    )
    assert proc.returncode == 0, f"subprocess failed: {proc.stderr!r}"
    assert json.loads(proc.stdout) == {"ok": True}
    # No "captured stray stdout" banner when the inner body was clean.
    assert "captured stray stdout" not in proc.stderr, proc.stderr


def test_isolator_restores_fd1_on_exception() -> None:
    """Exception inside the `with` block must still restore FD 1."""
    script = textwrap.dedent("""
        import sys, os
        sys.path.insert(0, __REPO__)
        from flexicorp.cli import _isolate_stdout_for_api
        try:
            with _isolate_stdout_for_api():
                os.write(1, b"leak before crash\\n")
                raise RuntimeError("boom")
        except RuntimeError:
            pass
        # If FD 1 was restored, this write reaches the real stdout.
        sys.stdout.write('{"ok": true}\\n')
        sys.stdout.flush()
    """).replace("__REPO__", repr(str(REPO_ROOT)))
    proc = subprocess.run(
        [_python(), "-c", script], capture_output=True, text=True, cwd=str(REPO_ROOT)
    )
    assert proc.returncode == 0, f"subprocess failed: {proc.stderr!r}"
    assert json.loads(proc.stdout) == {"ok": True}
    # The pre-crash leak was still captured and routed to stderr.
    assert "leak before crash" in proc.stderr


def test_end_to_end_query_still_valid_json_with_simulated_native_leak() -> None:
    """
    Hook into handle_request to leak bytes on FD 1, then run the real CLI
    with --api. Stdout must still parse as a single JSON document.
    """
    # Monkey-patch a backend so that `info corpus` writes to FD 1 mid-request.
    # The simplest way to exercise the CLI's isolator is to import it and
    # invoke main() directly with a patched handle_request.
    patch_module = textwrap.dedent("""
        import json, os, sys
        sys.path.insert(0, __REPO__)
        import flexicorp.cli as cli

        real_handle = cli.handle_request

        def leaky_handle(req):
            # This is EXACTLY what a C++ binding like _manatee.so does.
            os.write(1, b"ManateeCorpus: loading registry...\\n")
            os.write(1, b"WARN: no positional attribute 'lc'\\n")
            return real_handle(req)

        cli.handle_request = leaky_handle

        sys.argv = [
            'flexicorp', 'info', 'corpus',
            '--api', '--backend', 'manatee',
            '--folder', '/tmp/flexicorp-tests-nonexistent',
            '--teitok', 'yes', '--query-language', 'manatee-cql',
            '--corpus-format', 'manatee',
        ]
        raise SystemExit(cli.main())
    """).replace("__REPO__", repr(str(REPO_ROOT)))
    proc = subprocess.run(
        [_python(), "-c", patch_module], capture_output=True, text=True, cwd=str(REPO_ROOT)
    )
    # CLI exits 0 even on backend errors, because --api produces an error envelope.
    assert proc.returncode == 0, f"CLI rc={proc.returncode}; stderr={proc.stderr!r}"
    # stdout must parse as a single JSON document — no prefix chatter.
    doc = json.loads(proc.stdout)
    assert doc.get("tool") == "flexicorp"
    # The simulated chatter must have landed on stderr with the banner.
    assert "captured stray stdout" in proc.stderr
    assert "ManateeCorpus: loading registry" in proc.stderr


TESTS = [
    test_isolator_captures_raw_fd1_writes,
    test_isolator_is_noop_when_nothing_leaks,
    test_isolator_restores_fd1_on_exception,
    test_end_to_end_query_still_valid_json_with_simulated_native_leak,
]


def _run() -> int:
    passed = failed = 0
    for t in TESTS:
        try:
            t()
        except Exception as e:
            print(f"[FAIL] {t.__name__}: {type(e).__name__}: {e}")
            failed += 1
        else:
            print(f"[ok]   {t.__name__}")
            passed += 1
    print(f"{passed}/{len(TESTS)} passed")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(_run())
