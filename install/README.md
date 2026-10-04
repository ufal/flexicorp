# Installing TEITOK with its query stack

Three scripts, all re-runnable:

| script | what it does |
|--------|--------------|
| `install-teitok.pl` | The one command for users (published on teitok.org). A fresh install sets up TEITOK (Apache or nginx + php-fpm), then runs `install-stack.pl`. On a machine where TEITOK is already installed, it upgrades: it hands over to `install-stack.pl`, which leaves TEITOK's configuration alone. |
| `install-stack.pl` | Installs or upgrades flexicorp (in TEITOK's venv), its TEITOK pages (in the shared project), flexencoder, Pando with `libflexicorp_pando`, and FQS (systemd, launchd, or a start script). It finds an existing TEITOK by itself (see below). |
| `create-project.pl` | Creates a TEITOK project the way TEITOK's shared admin does: empty, or with `--demo` a sample of a UD treebank (default: 500 sentences of UD English EWT, CC BY-SA 4.0) converted to TEITOK XML and indexed for Pando. A fresh install ends with one of the two (asked; `-q` makes the demo), so that real data does not go into the shared project. |
| `create-project.pl --site` | The public start page: a non-corpus project `site` that opens on the corpus list (fqs.php) and has an About page, with `<teitok>/index.php` forwarding to it, so visitors never land in the shared project (which stays for server-wide settings, creating projects and other admin tools). Visitors get no corpus functions there: only the corpus list, the project's own pages (About, …), login and logout (`<site nocorpus="1" allow="…"/>` in its settings, enforced by its `Sources/startup.php`; logged-in users are not restricted). Fresh installs set this up (asked; `--no-site`); an existing `index.php` in the TEITOK root is never overwritten. |
| `check-stack.pl` | Checks an installation the way TEITOK uses it: as the web user, with FQS's PATH, plus a smoke test (encode a small document, run a dependency query). Runs at the end of every install. |

```
sudo perl install-teitok.pl                  # new machine: install; TEITOK found: upgrade
sudo perl install-teitok.pl -q               # the same, no questions
sudo perl install-teitok.pl --webserver nginx
sudo perl /home/git/flexicorp/install/install-stack.pl          # upgrade the stack only
sudo perl /home/git/flexicorp/install/install-stack.pl --detect # show what it finds
sudo perl /home/git/flexicorp/install/check-stack.pl
sudo perl /home/git/flexicorp/install/create-project.pl --name mycorpus      # another (empty) project
sudo perl /home/git/flexicorp/install/create-project.pl --demo --conllu URL  # a demo from another UD treebank
```

KonText is not part of this; `kontext-pando` has its own installer that adds KonText to a
TEITOK installation with Pando.

## How an existing installation is found

`install-stack.pl --detect` prints it. In this order:

- **TT_SHARED / TT_ROOT**: `teitok/.htaccess` (what install-teitok.pl writes), `SetEnv` in the
  Apache configuration, `fastcgi_param` in `nginx -T`, `env[...]` in php-fpm pools;
- **the shared project**: `<webroot>/teitok/shared`, else the project whose userlist has an admin
  for all projects;
- **the TEITOK checkout**: TT_ROOT, the `teitok/Scripts` symlink, `$ttroot` in index.php, the
  usual places (`/home/git/TEITOK`, `~/git/TEITOK`, ...);
- **the web user**: the owner of the shared project's `Resources/`, else the web server's user;
- **the venv**: `defaults/base/venv` in the shared settings, else `shared/Resources/venv`.

Any of it can be given instead: `--teitok-root`, `--shared`, `--web-user`, `--git-folder`.
flexicorp and pando are checked out next to the TEITOK checkout (`--git-folder`), as the
owner of that folder; existing checkouts are updated with `git pull --ff-only` (not at all
when they have local changes, or with `--no-pull`).

## What an upgrade changes

- binaries in `/usr/local` (`--prefix`): `pando*`, `flexicorp-pando`, `flexencoder`, `fqs`,
  `lib/libflexicorp_pando`; each replaced atomically, from a clean build;
- flexicorp in the venv (a wheel built from a clean copy, installed as the web user; a venv
  with root-owned files is given back to the web user first);
- the pages in the shared project (`Sources/`, `Scripts/`, `Pages/`; locally changed files are
  kept as `.local-<time>`);
- FQS: `/etc/fqs/fqs.json` and `/etc/fqs/fqs.env` are kept; only missing keys are added
  (`PYTHON_BIN`, `FLEXICORP_PANDO_LIB`, `PATH`), duplicate `FQS_SECRET` lines are reduced to
  the one in use; the service runs as the web user;
- TEITOK settings: only `flexicorp/fqs_admin_users`, and only when it is not set and
  `--fqs-admin` is given (fresh installs pass the admin's email).

Versions and commits go to `/usr/local/share/teitok-stack/manifest.json`; logs to
`/var/log/teitok-install/`.

## Platforms

Tested here: Ubuntu 24.04 without systemd (as in a container) — fresh install with Apache,
fresh install with nginx + php-fpm, and an upgrade of an installation made by the published
installer. `docker/` runs the same on Debian 12, Ubuntu 22.04/24.04, Rocky 9 and Fedora 41.

macOS: written for (Homebrew, `_www`, launchd for FQS), not yet tested. Windows: use Docker
(`docker/docker-compose.yml`, or the image on Docker Hub).

## Testing with Docker

```
install/docker/run-tests.sh                     # every scenario in docker-compose.yml
install/docker/run-tests.sh upgrade-debian12    # one
KEEP=1 install/docker/run-tests.sh nginx-debian12   # and leave it running (port 8086)
```

It bundles your flexicorp and pando working trees (uncommitted changes included) into the
images, so nothing has to be pushed; TEITOK comes from GitLab (or `TEITOK_SRC=...`).
Scenarios: `fresh-*` (Apache), `nginx-*`, `upgrade-*` (the published installer from teitok.org
first, then this one). Results in `install/docker/results/`.
