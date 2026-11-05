# FQS portal: one TEITOK project for many FQS corpora

An optional TEITOK module that lets one shallow TEITOK project search any corpus that an FQS
serves, for example all Manatee corpora of a KonText server. Without it, each corpus needs its
own mostly empty TEITOK project. It works like KonText's corpus selection: pick a corpus, then
search it with flexicorp.

It is not part of flexicorp's normal install, which puts flexicorp's pages into the shared
project. The portal goes into one project of its own (see Installing), never into the shared
project.

## How it works

TEITOK loads settings in this order (`common/Sources/settings.php`):

1. Read the project's `Resources/settings.xml`.
2. Run the `Sources/startup.php` of the shared folders (if any), then the project's own.
3. Fill in whatever is still missing from the shared project's settings.

The portal's `Sources/startup.php` uses step 2:

- It takes the corpus from `?corpus=` (and remembers it in the session) or from the session.
- It checks the corpus against the portal's selection.
- It merges a settings fragment for that corpus into `$settings`, for this request only.

flexicorp then sees an ordinary single-corpus project without XML files. TEITOK core and
flexicorp's search need no portal-specific change.

A `startup.php` in the shared project would run for every project, which is why the installer
refuses the shared project.

The fragment contains:

| Setting | Content |
|---|---|
| `flexicorp/fqs_corpus_id` | The FQS id. The search page and the `fqs_query` proxy use it, so a visitor cannot make the proxy sign a request for a corpus outside the selection. |
| `cqp/pattributes`, `cqp/sattributes` | The corpus's attributes and structures, with labels. Replaced as a whole and marked `noshare`, so the shared project's attribute lists don't get mixed in. |
| `xmlfile` | Emptied: there are no TEITOK XML files behind these corpora. |
| `defaults/flexicorp/backend` | The default engine (`pando`, `cqp` or `manatee`), from the catalogue row. |
| `menu/title`, `menu/link` | The corpus's label as the menu header, linked to the picker. The page title stays the portal's own. Not set when the project's menu has no header (`title="none"`). |
| `flexicorp/search_intro_html` | The description, plus a link back to the picker. |

The fragment is built from FQS's `GET /info?corpus=…` (every engine: Pando from its index, CWB
and Manatee from their registry), else from the catalogue row's `settings.corpus_info` (written
by the catalogue importer from the Manatee registry, with its `LABEL`s), else only `word`. It is
cached in `tmp/fqsportal/<id>.json` until the row's `updated_at` changes; a fragment built from a
fallback is rebuilt after `cache_ttl`, so `/info` is tried again.

`index.php?action=corpora` is the picker. It lists the corpora the caller may use, with a filter
box. Admins also see where each corpus's attributes came from, FQS's errors in full, and a
"reread from FQS" link.

## Files

| File | Installed as | Purpose |
|---|---|---|
| `startup.php` | `PROJECT/Sources/startup.php` | The hook TEITOK runs; it calls `fqp_startup()`. |
| `fqsportal.php` | `PROJECT/Sources/fqsportal.php` | The library: FQS calls, the selection, building and caching the fragment, the overlay. |
| `corpora.php` | `PROJECT/Sources/corpora.php` | The picker (`action=corpora`). |
| `install-portal.pl` | — | Installs, updates and removes the above in one project. |

## Installing

1. Create a shallow project, for example with `install/create-project.pl --name corpora`.
2. Install the portal into it, either with the stack installer (remembered: later runs, and the
   nightly `--cron` run, update it):
   ```bash
   sudo perl install/install-stack.pl --portal /var/www/html/teitok/corpora
   ```
   or on its own:
   ```bash
   sudo perl teitok_teitok_ui/portal/install-portal.pl --project /var/www/html/teitok/corpora
   ```
   flexicorp's pages must be in the shared project (install-stack does that).
3. Add to its `Resources/settings.xml` (the installer lists what is still missing):
   ```xml
   <fqsportal sources="non-teitok" cache_ttl="300"/>
   <flexicorp fqs_url="http://fqs.internal:8787"/>
   ```
   and a menu item: `<item key="corpora" display="Corpora"/>`.
4. Mark the corpora in FQS, if the portal shouldn't simply take all non-TEITOK corpora (or all
   Manatee ones, `engines="manatee"`). The catalogue importer can do it for KonText's corpora: a
   visible label with `--portal-label portal:lindat`, or a hidden key with `--portal lindat`, plus
   `--portal-url https://…/teitok/corpora`.

### An existing `startup.php`

A project that already has a `Sources/startup.php` keeps it:

- one the installer put there and nobody changed is updated;
- one it put there that was edited since is kept as `startup.php.local-TIME` and replaced;
- one that already calls `fqp_startup()` is left as it is;
- any other is left untouched. The installer then names the two lines to add to it, and exits
  with status 3 (install-stack shows a warning): the portal's files are installed, but the
  portal is off until the project's `startup.php` has them:
  ```php
  include_once( __DIR__ . '/fqsportal.php' );
  fqp_startup();
  ```
  They go inside its `<?php … ?>`, before anything is printed (the portal may send a redirect).

### Updating and removing

Re-running the installer updates the files in place. A manifest
(`PROJECT/Resources/fqsportal-install.json`) records their checksums; a file changed locally
since the last install is kept as `FILE.local-TIME` before it is replaced.

`install-portal.pl --project DIR --remove` removes the files that are unchanged since they were
installed (the project's own `startup.php` never), and leaves `settings.xml` as it is. For
install-stack, `--portal ''` forgets the remembered projects.

## Options of `<fqsportal>`

| Option | Meaning | Default |
|---|---|---|
| `tag` | Only rows with one of these labels (comma-separated) | All rows |
| `engines` | Only rows whose engine is one of these: `manatee`, `pando`, `cqp` (comma-separated). `engines="manatee"` gives the Manatee corpora, which have no TEITOK project of their own. | All engines |
| `portal` | Only rows whose `settings.portals` names this portal. Unlike a label, it doesn't show up in FQS's label browser or in corpus-list facets. | — |
| `sources` | `non-teitok` leaves out rows that are TEITOK projects (`source_kind` `teitok*`), since those have their own TEITOK search. `all` keeps them. | `non-teitok` |
| `<corpora><item key="id"/>…</corpora>` | Exactly these ids instead of all the filters above | — |
| `environment` | FQS environment (`/corpora?environment=`) | All |
| `param` | URL parameter for the corpus | `corpus` |
| `cache_ttl` | Lifetime of the cached corpus list, and of fallback fragments, in seconds | `300` |
| `needs` | Actions that need a corpus; without one, the visitor is sent to the picker. `fqs_query` gets a JSON 409 instead. | `flexicorp,fqs_query,advanced_` |

The options combine: a row must pass all of them. After changing them, an admin's "reread from
FQS" on the picker applies them at once.

FQS address and secret are found as for flexicorp: the address from `flexicorp/fqs_url`, else
`FQS_URL`; the secret from FQS's env file (`flexicorp/fqs_env_file`, `FQS_ENV_FILE`,
`/etc/fqs/fqs.env`), else `flexicorp/fqs_secret`, else `FQS_SECRET`. The portal reads the
catalogue and `/info` as admin, so it can check any id in its selection; FQS still checks each
search against the visitor's role.

## Known limits

- **One corpus per session.** Two browser tabs on different corpora switch each other, because
  flexicorp's AJAX calls don't carry `?corpus=`.
- **Shared state across corpora.** When the corpus changes, flexicorp's remembered query is
  cleared. Stored queries (`Resources/queries.xml`) are shared by all corpora of the portal.
- **Reserved names.** A structure attribute named `key`, `level` or `display` collides with the
  structure's own settings in TEITOK's settings format, and the structure's value wins.
- **Documents.** flexicorp treats only a structure called `text` as the document level.
  Manatee's `doc` counts as an ordinary region, so it offers no document facets. A corpus that
  exists only in the portal has no document view; a TEITOK corpus (with `sources="all"`) links
  its hits to its own project (the catalogue's `project_url`).
- **Value autosuggest.** It reads local lexicon files, so the query builder has no value lists
  for these corpora.
- **Indexing.** None from the portal: these corpora are built elsewhere.
- **Access.** The picker hides corpora whose `settings.access` (the KonText groups the importer
  kept) excludes `anonymous` from visitors; logged-in users see them all. A visitor who knows an
  id can still select it, because FQS answers `public_query` corpora for anyone. So for
  restricted corpora this is a convenience, not protection, until FQS checks groups.
