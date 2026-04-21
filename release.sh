#!/usr/bin/env bash
#
# flexiCorp release helper: snapshot git-ignored work (TEITOK overlays, local dev/, etc.)
# before running release steps, so local-only state is hard to lose accidentally.
#
# Usage:
#   ./release.sh                  # backup only (default: ~/.flexicorp-release-backups)
#   ./release.sh --no-backup      # skip backup (e.g. when repeating a failed step)
#   ./release.sh -- ./your-step.sh arg   # backup then run your command(s)
#
# Override backup root (recommended: a path outside the clone, e.g. external disk):
#   FLEXICORP_RELEASE_BACKUP_DIR=/path/to/backups ./release.sh
#
# By default, ignored paths under git/ (third-party clones; often huge) are NOT archived.
# To include them:
#   FLEXICORP_RELEASE_BACKUP_INCLUDE_GIT=1 ./release.sh
#
# Troubleshooting (also applies to a local dev/release.sh if you use one; dev/ is gitignored):
#   "Permission denied" when running ./dev/release.sh — mark executable once: chmod +x dev/release.sh
#   Or run: bash dev/release.sh 'message'   (avoid plain sh: echo -e may print a literal "-e" on macOS)
#   Main push OK but tag push failed (e.g. SSL_ERROR_SYSCALL to github.com) — retry only the tag:
#     git push origin v0.1.3
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$REPO_ROOT"

BACKUP_ROOT="${FLEXICORP_RELEASE_BACKUP_DIR:-${HOME}/.flexicorp-release-backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
ARCHIVE="${BACKUP_ROOT}/flexicorp-ignored-${STAMP}.tar.gz"
META="${BACKUP_ROOT}/flexicorp-ignored-${STAMP}.meta.txt"
LATEST_LINK="${BACKUP_ROOT}/flexicorp-ignored-latest"

DO_BACKUP=1
CMD=()
RELEASE_NOTE=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--no-backup)
			DO_BACKUP=0
			shift
			;;
		--)
			shift
			CMD=("$@")
			break
			;;
		*)
			# Backward-compatible mode:
			#   ./release.sh "improved reindexing"
			# treats the single argument as a release note, not a command.
			if [[ $# -eq 1 && "$1" == *" "* ]]; then
				RELEASE_NOTE="$1"
				shift
				break
			fi
			CMD=("$@")
			break
			;;
	esac
done

if [[ ! -d "$REPO_ROOT/.git" ]]; then
	echo "release.sh: expected a git checkout at ${REPO_ROOT}" >&2
	exit 1
fi

backup_ignored() {
	mkdir -p "${BACKUP_ROOT}"
	chmod 700 "${BACKUP_ROOT}" 2>/dev/null || true

	local raw filtered
	raw="$(mktemp "${TMPDIR:-/tmp}/flexicorp-ignored-raw.XXXXXX")"
	filtered="$(mktemp "${TMPDIR:-/tmp}/flexicorp-ignored-filtered.XXXXXX")"

	git -c core.quotePath=false ls-files -o -i --exclude-standard -z >"${raw}"

	local raw_bytes
	raw_bytes="$(wc -c <"${raw}" | tr -d ' \t')"

	local include_git="${FLEXICORP_RELEASE_BACKUP_INCLUDE_GIT:-0}"
	if [[ "${include_git}" == "1" ]]; then
		mv "${raw}" "${filtered}"
	else
		: >"${filtered}"
		while IFS= read -r -d '' f; do
			if [[ "$f" == "git" || "$f" =~ ^git/ ]]; then
				continue
			fi
			printf '%s\0' "$f" >>"${filtered}"
		done <"${raw}"
		rm -f "${raw}"
		echo "release.sh: excluding ignored paths under git/ (set FLEXICORP_RELEASE_BACKUP_INCLUDE_GIT=1 to include third-party clones)."
	fi

	local nbytes
	nbytes="$(wc -c <"${filtered}" | tr -d ' \t')"

	{
		echo "created_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
		echo "repo_root=${REPO_ROOT}"
		echo "git_head=$(git rev-parse HEAD 2>/dev/null || echo unknown)"
		echo "git_branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
		if [[ -n "${RELEASE_NOTE}" ]]; then
			echo "release_note=${RELEASE_NOTE}"
		fi
		echo "ignored_list_bytes_raw=${raw_bytes}"
		echo "excluded_git_dir=$([[ "${include_git}" != "1" ]] && echo true || echo false)"
		echo "ignored_list_bytes=${nbytes}"
	} >"${META}"

	if [[ "${nbytes}" -eq 0 ]]; then
		rm -f "${filtered}"
		echo "release.sh: no git-ignored untracked files under ${REPO_ROOT} after exclusions (nothing to archive)."
		echo "release.sh: metadata written to ${META}"
		return 0
	fi

	tar -czf "${ARCHIVE}" --null -T "${filtered}" -C "${REPO_ROOT}"
	rm -f "${filtered}"

	echo "release.sh: archived git-ignored paths to:"
	echo "  ${ARCHIVE}"
	ls -lh "${ARCHIVE}"
	ln -sfn "${ARCHIVE}" "${LATEST_LINK}"
	echo "release.sh: latest backup symlink: ${LATEST_LINK} -> ${ARCHIVE}"
}

if [[ "${DO_BACKUP}" -eq 1 ]]; then
	echo "release.sh: backing up git-ignored files under ${BACKUP_ROOT} ..."
	backup_ignored
else
	echo "release.sh: skipping backup (--no-backup)."
fi

if [[ ${#CMD[@]} -gt 0 ]]; then
	echo "release.sh: running: ${CMD[*]}"
	exec "${CMD[@]}"
fi

if [[ -n "${RELEASE_NOTE}" ]]; then
	echo "release.sh: release note recorded: ${RELEASE_NOTE}"
fi

echo "release.sh: done (no command after backup; use -- ./script to run release steps)."
