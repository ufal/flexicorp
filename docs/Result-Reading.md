# How flexicorp reads search results

flexicorp's TEITOK pages get their results from pando, through FQS (`/query`, `/run`) or locally
(`flexicorp-pando`). One reader, `teitok_teitok_ui/fc_result.php`, turns every answer into a
**flexicorp result**, the shape the pages show. This page is the contract between the two.

- **What comes in:** pando's [result format](https://github.com/ufal/pando/blob/main/wiki/Result-Format.md),
  `format_version` 1, in FQS's or flexicorp-pando's envelope.
- **What goes out:** the flexicorp result below, and, for the Stats "Other" tab, the `other` state.

## The rules

- **Never an empty result for an answer that has data.** When the reader finds no body it knows,
  it reports the result as unknown, naming the answer's keys: "The search engine answered with a
  result this flexicorp version does not know how to show (fields: …)". A real empty result keeps
  its body (`hits: []`) and is shown as no matches.
- **Every route is read strictly:** FQS `/query`, FQS `/run` and the local route.
- **Additions only.** New keys may appear in the flexicorp result. A key is never removed or
  changed in meaning without a note here.

## The flexicorp result

`tt_fc_result_kind()` says what it is:

| kind | from (pando) | body |
|---|---|---|
| `hits` | a query, `sort`, an assignment; aligned pairs | `hits[]` or `pairs[]`, `page`, `groups[]`, … (as pando's) |
| `table` | `count`, `freq`, `stats`, `tabulate`, `describe`, `keyness` | `rows[]`, `fields[]`, and for some `columns[]` |
| `compare_table` | `freq A, B by …` | `compare_queries[]`, `rows[]` |
| `collocates` | `coll`, `dcoll` | `collocates[]`, `returned`, `total` |
| `empty` | `set`, `drop`, `show settings` | nothing to show |
| (reported) | `size`, `raw`, `show info`, `show attributes`, `show regions`, `show values`, `show named` | the unknown-result message |

### Rows

A row of a frequency-like table is `{key, count, pct, …}`. With several fields, `key` holds the
values joined by a tab, in the order of `fields[]`.

### Grouped counts: `tree`

`count by A, B, …` (several fields) answers a tree (pando's `hierarchy`, see "Grouped counts" in
the result format). The flexicorp result keeps it as **`tree`**, the result itself, and adds
the flat `rows` as another view of it.

`tree`:

| key | |
|---|---|
| `fields[]` | the fields, outermost first |
| `total_matches` | the hits counted |
| `groups` | distinct combinations of all fields |
| `top_groups` | distinct values of the first field |
| `groups_returned` | top-level nodes in `nodes` |
| `child_limit` | the most children pando gave a node (`0`: all) |
| `hidden` | top-level values left out by the cut (`top_groups - groups_returned`) |
| `nodes[]` | the top-level nodes |

A node:

| key | |
|---|---|
| `field`, `value` | the field and its value |
| `count` | hits with this value and the values above it |
| `pct` | of all hits |
| `pct_of_parent` | of the node above it; `null` on the top level |
| `groups` | distinct values of the next field under this node, before any cut; `null` on the last level |
| `hidden` | of those, how many the cut left out (`groups - children.length`), the ones with the lowest counts |
| `children[]` | the next level; `[]` on the last level |

The flat view of the same answer: `rows[]` has one row per node without children, as `freq by A, B, …`
gives it (`key` tab-joined), plus each field's value under the field's name. `columns` is `[A, B,
…, count, pct]`. Values a cut left out are not in `rows`; only `tree` says that they exist.

flexicorp asks for whole trees: a typed program sends `group_limit` and `child_limit` (1000 each).

## The Stats "Other" tab

Results without a panel of their own (`count`, `tabulate`, `stats`, `keyness`, `describe`, …)
come to the page as `state.other`:

| key | |
|---|---|
| `ran` | there is a result |
| `operation` | pando's operation (`count`, `tabulate`, …) |
| `columns[]` | the table's columns, when the result names them |
| `rows[]` | the table rows (objects, or lists for `tabulate`) |
| `total` | rows in all, when known |
| `tree` | a grouped count's tree (above), or `null` |
| `row_contexts[]` | `tabulate`: each row's example in its sentence |
| `response` | the raw answer (debugging) |

The views:
- **Tree** (the default when there is a `tree`): one row per top value, with its count, % of all
  hits and the number of values of the next field under it. A value opens to the values under it,
  each with its % of the parent. Where a cut left values out, a row says how many.
- **Table:** the flat rows.
- **H-bar, Line:** charts of the flat rows.

## Tests

- `tests/test_fc_result_contract.php`: every answer of pando's contract (`pando/contract/answers`)
  through the reader, each kind as above, the tree and its cuts.
- `tests/ui/fc_tree_test.js`: the tree's rows, open and closed, cut and whole, from the same answers.
- The stack release check: FQS's answers to pando's contract, read as the pages read them.
