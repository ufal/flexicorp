# Installing TEITOK with its query stack

Three scripts, all re-runnable:

| script | what it does |
|--------|--------------|
| `install-teitok.pl` | The one command for users (published on teitok.org). A fresh install sets up TEITOK (Apache or nginx + php-fpm), then runs `install-stack.pl`. On a machine where TEITOK is already installed, it upgrades: it hands over to `install-stack.pl`, which leaves TEITOK's configuration alone. |
| `install-stack.pl` | Upgrades TEITOK itself (pulls its checkout, rebuilds its tools when they changed) and installs or upgrades flexicorp (in TEITOK's venv), its TEITOK pages (in the shared project), flexencoder, Pando with `libflexicorp_pando`, and FQS (systemd, launchd, or a start script). It finds an existing TEITOK by itself (see below). |
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
flexicorp and pando are built from one git folder (`--git-folder`). Without it, that is the
folder of the flexicorp checkout the installer is run from (so `perl ~/Git/flexicorp/install/install-stack.pl`
builds `~/Git/flexicorp` and `~/Git/pando`); for a temporary copy (as `install-teitok.pl --upgrade`
makes) it is the folder the previous run used (`git_folder` in the manifest), else the folder
holding the TEITOK checkout. The installer warns when that differs from the previous run.
Missing checkouts are cloned there as the owner of that folder; existing ones are updated
with `git pull --ff-only`, run as the checkout's owner with that user's HOME (so their ssh
keys and git credentials are used; a key with a passphrase needs the agent passed along:
`sudo --preserve-env=SSH_AUTH_SOCK perl …/install-stack.pl`). The installer says what each
pull brought (the new commits), or why it did not pull: local changes, no upstream branch,
or the error git gave. `--no-pull` builds the checkouts as they are. When the pull changed
`install-stack.pl` itself, the installer restarts with the new version.
Builds happen in `/var/tmp/teitok-stack-build` and are installed into `--prefix`, so nothing
runs from the git folder afterwards.

TEITOK itself is pulled the same way (its checkout, as its owner; not when it has local
changes), before flexicorp and pando. Its pages are PHP, so a pull is in use right away; its
tool (`tt-xpath`) is rebuilt when `src/` changed. `--skip teitok` leaves TEITOK alone. smarty is not
updated (a new major version would break TEITOK's templates).

The question "Is this the installation to add the query stack to?" is asked once: the answer
is kept (`/usr/local/share/teitok-stack/confirmed`), and an installation the manifest already
names is not asked about either.

## Only what changed

An upgrade rebuilds a component only when its sources changed since it was last installed,
or when what it installs is missing. The manifest keeps, per component, a fingerprint of its
sources (the git trees plus any uncommitted changes and untracked files):

| component | sources |
|-----------|---------|
| teitok (`tt-xpath`, the TEITOK tool its pages still need with flexicorp; `tt-cwb-encode` and `tt-cwb-xidx` are replaced by flexencoder, `tt-cqp` is phased out, neotag is replaced by flexipipe's flexitag) | the TEITOK checkout's `src/` |
| pando (with `libflexicorp_pando`) | the pando checkout, `flexicorp_pando/` |
| flexencoder | `flexencoder/` |
| flexicorp | `flexicorp/`, `pyproject.toml` |
| pages | `teitok_teitok_ui/` |
| fqs | `fqs/` |

A new `libflexicorp_pando` restarts a running FQS (it loads the library), also when FQS
itself is unchanged. `--force` rebuilds everything, `--force pando,fqs` only those. System
packages are only installed on a first install or when a build tool is missing.

## Unattended updates (cron)

The first interactive run offers to set up a nightly update (default 02:00); the answer is
kept, so it is asked only once. `--auto-update [HH:MM]` sets it up (or moves it) without
asking, `--no-auto-update` removes it. It becomes a systemd timer
(`teitok-stack-update.timer`; output in `journalctl -u teitok-stack-update`), else
`/etc/cron.d/teitok-stack` (cron mails what it reports to root), on macOS a launchd job; each
runs the installer of the flexicorp checkout with `--cron` and the folders of the run that set
it up. By hand, the same is:

```
30 3 * * * root perl /home/you/Git/flexicorp/install/install-stack.pl --cron
```

- No questions, and no output and no log when nothing changed. When something was pulled or
  rebuilt, FQS restarted, a pull or a step failed, or a restart was postponed, it prints a short
  summary (which cron mails) and keeps the log.
- Only one run at a time (`/usr/local/share/teitok-stack/install.lock`): a cron run that finds
  another one busy exits quietly; a manual run says so and stops.
- FQS is not restarted while it runs a reindex job (a restart would cancel it): the restart is
  postponed (`/usr/local/share/teitok-stack/fqs-restart-pending`) and the next run does it once
  the jobs have finished. Manual runs ask instead (default: postpone).
- The pull runs as the checkouts' owner without an ssh agent: use https remotes for public
  repositories, or a read-only deploy key without a passphrase. Let the checkouts follow a
  stable branch: whatever is pushed there goes live.
- A rebuild of pando or FQS takes minutes and restarts FQS: a nightly time suits better than
  every few minutes.

## What an upgrade changes

- binaries in `/usr/local` (`--prefix`): `pando*`, `flexicorp-pando`, `flexencoder`, `fqs`,
  `lib/libflexicorp_pando`; each replaced atomically, from a clean build (only components
  whose sources changed, see above);
- flexicorp in the venv (a wheel built from a clean copy, installed as the web user; a venv
  with root-owned files is given back to the web user first);
- the pages in the shared project (`Sources/`, `Scripts/`, `Pages/`; locally changed files are
  kept as `.local-<time>`);
- FQS: `/etc/fqs/fqs.json` and `/etc/fqs/fqs.env` are kept; only missing keys are added
  (`PYTHON_BIN`, `FLEXICORP_PANDO_LIB`, `PATH`), duplicate `FQS_SECRET` lines are reduced to
  the one in use; the service runs as the web user;
- TEITOK settings: only `flexicorp/fqs_admin_users`, and only when it is not set and
  `--fqs-admin` is given (fresh installs pass the admin's email).

- other frontends' files that FQS's frontend modules edit (`fqs frontends paths`; for
  KonText: `corplist.xml`, kontext-pando's `pando_corpora.json`, the Manatee
  `registry`, `data` and `vert` folders): the FQS service user gets write access by an
  ACL (`setfacl`; owner, group and mode stay as they are; without `setfacl`, group
  write when the group is root), and their folders go in
  `/etc/systemd/system/fqs.service.d/frontends.conf` (`ReadWritePaths`, since the
  unit has `ProtectSystem=strict`). `--no-frontends` leaves them alone. KonText
  installed or redeployed later (a deployment that replaces `corplist.xml` drops the
  ACL): run `install-stack.pl --only fqs` again.
- restarts from fqsadmin (systemd): FQS runs as an unprivileged user with
  `NoNewPrivileges`, so it cannot run `systemctl restart` itself. For FQS, and for KonText
  when `kontext.service` is on the machine, the installer adds a root-owned path unit
  `fqs-restart-<unit>.path` that watches `/var/lib/fqs/restart/<unit>` (writable for the FQS
  user only) and runs `fqs-restart@<unit>.service` (`systemctl restart <unit>.service`) when
  FQS writes it. This gives fqsadmin a Restart button for FQS and KonText, also when KonText is
  not in `fqs.json`, and nothing more: which units can be restarted is decided here.
  Checked on every run; `--no-frontends` leaves out KonText.

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
