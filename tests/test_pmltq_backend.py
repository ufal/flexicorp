from __future__ import annotations

import json
import os
from pathlib import Path

import pytest

from flexicorp.backends.pmltq_backend import PmltqBackend


class _FakeResponse:
    def __init__(self, payload: object) -> None:
        self._raw = json.dumps(payload).encode("utf-8")

    def read(self) -> bytes:
        return self._raw

    def __enter__(self) -> "_FakeResponse":
        return self

    def __exit__(self, exc_type, exc, tb) -> bool:
        return False


def test_query_returns_normalized_result(monkeypatch: pytest.MonkeyPatch) -> None:
    backend = PmltqBackend()

    def _fake_urlopen(req, timeout=0):  # noqa: ANN001
        return _FakeResponse(
            {
                "nodes": [["", "a-node"]],
                "results": [["2/a-node@a-ln94210-2-p1s1w1"]],
            }
        )

    monkeypatch.setattr("flexicorp.backends.pmltq_backend.urlopen", _fake_urlopen)

    res = backend.query(
        {
            "project": {
                "pmltq_server": {
                    "url": "http://127.0.0.1:19100",
                    "treebank": "tt_infov_test",
                }
            },
            "params": {
                "query": "a-node []",
                "start": 0,
                "max": 10,
            },
        }
    )

    assert res["treebank"] == "tt_infov_test"
    assert res["query"] == "a-node []"
    assert res["returned"] == 1
    assert res["total"] is None
    assert res["total_exact"] is False
    assert isinstance(res["hits"], list)
    assert res["hits"][0]["toks"] == ["a-ln94210-2-p1s1w1"]
    assert res["hits"][0]["doc_id"] == "ln94210-2.xml"
    assert isinstance(res["results"], list)
    assert res["results"][0][0].startswith("2/a-node@")


def test_query_requires_treebank() -> None:
    backend = PmltqBackend()
    with pytest.raises(RuntimeError, match="requires a treebank id"):
        backend.query({"project": {"pmltq_server": {"url": "http://127.0.0.1:19100"}}, "params": {"query": "a-node []"}})


def test_reindex_runs_flexencoder_export(tmp_path: Path) -> None:
    root = tmp_path / "proj"
    scripts = root / "Scripts"
    scripts.mkdir(parents=True, exist_ok=True)
    resources = root / "Resources"
    resources.mkdir(parents=True, exist_ok=True)
    (resources / "settings.xml").write_text("<settings/>", encoding="utf-8")

    flexencoder = scripts / "flexencoder"
    flexencoder.write_text(
        "#!/bin/sh\n"
        "OUT=''\n"
        "while [ $# -gt 0 ]; do\n"
        "  if [ \"$1\" = \"--output-clickhouse\" ]; then OUT=\"$2\"; shift 2; continue; fi\n"
        "  shift\n"
        "done\n"
        "mkdir -p \"$OUT\"\n"
        "printf '{\"doc_id\":1}\\n' > \"$OUT/docs.jsonl\"\n",
        encoding="utf-8",
    )
    os.chmod(flexencoder, 0o755)

    backend = PmltqBackend()
    res = backend.reindex({"project": {"root": str(root), "pmltq_server": {"treebank": "tb"}}, "params": {}})

    assert res["backend"] == "pmltq"
    assert res["export"]["files"]["docs.jsonl"]["lines"] == 1

