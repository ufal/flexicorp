from flexicorp.clickhouse_errors import format_clickhouse_error_message


def test_connection_refused_long_urllib3_message():
    raw = (
        "HTTPConnectionPool(host='localhost', port=8123): Max retries exceeded with url: "
        "/?query_id=x (Caused by NewConnectionError(\"HTTPConnection(host='localhost', port=8123): "
        "Failed to establish a new connection: [Errno 61] Connection refused\")) "
        "executing HTTP request attempt 1 (http://localhost:8123)"
    )
    out = format_clickhouse_error_message(raw, host="localhost", port=8123, database="db1")
    assert "ClickHouse is not reachable" in out
    assert "localhost:8123" in out
    assert "HTTPConnectionPool" not in out


def test_unknown_table():
    out = format_clickhouse_error_message(
        "DB::Exception: Table `db`.`docs` doesn't exist",
        host="127.0.0.1",
        port=8123,
        database="db",
    )
    assert "indexed in ClickHouse" in out
