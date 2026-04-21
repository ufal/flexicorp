# Flexicorp Search/Stats Integration (Without TEITOK Core Changes)

This project keeps TEITOK core (`$ttroot/common/Sources/*`) off-limits.
So Search/Stats behavior should be integrated in project-local code only.

## What Flexicorp Now Returns

For `operation="query"`, `result` includes policy metadata:

- `request_role`: `admin` or `visitor`
- `input_query_mode`: `search` or `aggregation`
- `query_mode`: effective mode after policy
- `query_sanitized`: boolean
- `sanitized_query`: sanitized query (or `null`)
- `suggested_tab`: `search` or `stats`
- `result_type`: usually `hits` or `table`

For backend capability discovery (`operation="info"`, `topic="backends"`),
each backend/combo now has normalized `stats_*` flags:

- `stats_freq_pattributes`
- `stats_freq_sattributes`
- `stats_relative_freq`
- `stats_collocations`
- `stats_dep_collocations`
- `stats_keyness`
- `stats_table_result`

## Local Flexicorp Files Added

PHP:

- `examples/test/Sources/flexicorp_ui.php`
  - Includes source/js resolver helpers, role policy, query routing helpers, and stats capability mapping.

JS:

- `examples/test/Scripts/flexicorp_stats.js`
  - Includes query routing + stats capability helpers.

This keeps Flexicorp code localized to a few files (instead of many micro-modules) and below your file-size guardrail.

## Debug Source Resolution

Use `getFlexicorpSourceDebug($phpname, $scriptname)` from `flexicorp_ui.php` to show
exactly where modules were loaded from (project/shared/teitok_core), including:

- `resolved` source for PHP and JS
- all attempted candidate paths/URLs
- existence flags per candidate

This is intended for the Debug tab so you can confirm whether Flexicorp code is
running from local project paths, shared paths, or TEITOK core paths.

## Default Behavior to Implement in Local UI

1. Determine role from existing `$user` semantics.
2. Submit query to flexicorp backend.
3. Read routing metadata from query response.
4. If `suggested_tab === "stats"` and a base query exists, switch to Stats.
5. For visitors, respect sanitization (`query_sanitized`, `sanitized_query`) and keep Search active.
6. Build visible Stats options from `stats_*` capabilities of selected backend combo.
7. Default Stats rendering to table; offer chart adapters on top of normalized table data.

## Recommended Local File Layout

- `flexicorp_ui.php` (Flexicorp-specific PHP helpers)
- `Scripts/flexicorp_stats.js` (Flexicorp-specific JS helpers)
- your existing project script(s) that call these helpers

If/when these get large, split into `flexicorp_*.php` and `flexicorp_*.js` files while keeping them localized and under ~2k lines each.

