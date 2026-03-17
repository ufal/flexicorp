# CQL → SQL translator for ClickQL.
# Logic ported from PHP (query-clickalign.php, translateCQLToSQL, translateMultiTokenCQL,
# translateDependencyCQL, parseAndTranslateCQL) and JS (clickfunctions.js) so that
# translation is identical. When cql2sql-peg-optimized.js is available, this module
# should be kept in sync with it for identical output.

from .cql2sql import cql_to_sql, cql_to_count_sql

__all__ = ["cql_to_sql", "cql_to_count_sql"]
