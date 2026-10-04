#!/usr/bin/env bash
# Run the installer test matrix (docker-compose.yml) on your local checkouts.
#
#   install/docker/run-tests.sh [service ...]     default: all services
#
# Environment:
#   PANDO_SRC   your pando checkout   (default: ../pando next to flexicorp)
#   TEITOK_SRC  a TEITOK checkout to use instead of GitLab (optional)
#   KEEP=1      leave the containers running after the checks (to look around)
#
# flexicorp, pando (and TEITOK) go in as git bundles of the working tree,
# uncommitted and untracked (not ignored) files included, so you test what you
# have, without committing or pushing. Results: install/docker/results/<service>.log
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
FLEXI=$(cd "$HERE/../.." && pwd)
PANDO_SRC=${PANDO_SRC:-$FLEXI/../pando}
cd "$HERE"
mkdir -p bundles results
rm -f bundles/*.bundle

snapshot() {   # repo out: commit the working tree (in a temporary index) and bundle it as branch "snapshot"
	local repo=$1 out=$2 idx tree commit
	idx=$(mktemp)
	cp "$repo/.git/index" "$idx" 2>/dev/null || true
	GIT_INDEX_FILE=$idx git -C "$repo" add -A
	tree=$(GIT_INDEX_FILE=$idx git -C "$repo" write-tree)
	commit=$(git -C "$repo" commit-tree "$tree" -p HEAD -m "installer test snapshot")
	git -C "$repo" update-ref refs/heads/teitok-test-snapshot "$commit"
	git -C "$repo" bundle create -q "$out" teitok-test-snapshot
	git -C "$repo" update-ref -d refs/heads/teitok-test-snapshot
	rm -f "$idx"
	echo "  $(basename "$out"): $(git -C "$repo" rev-parse --short HEAD) + working tree"
}

echo "Bundling sources"
snapshot "$FLEXI" bundles/flexicorp.bundle
[ -d "$PANDO_SRC/.git" ] || { echo "pando checkout not found at $PANDO_SRC (set PANDO_SRC)"; exit 1; }
snapshot "$PANDO_SRC" bundles/pando.bundle
if [ -n "${TEITOK_SRC:-}" ]; then snapshot "$TEITOK_SRC" bundles/TEITOK.bundle; fi

# (bash 3.2 on macOS: no mapfile, no associative arrays)
if [ $# -gt 0 ]; then services="$*"; else services=$(docker compose config --services); fi
summary=""
for s in $services; do
	echo; echo "=== $s"
	log="results/$s.log"
	if ! docker compose build "$s" >"$log" 2>&1; then
		r="BUILD FAILED (see $log)"; tail -30 "$log"
	elif docker compose run --rm "$s" check >>"$log" 2>&1; then
		r="ok"
	else
		r="CHECK FAILED (see $log)"
	fi
	sed -n '/TEITOK stack check/,$p' "$log" | tail -40
	[ "${KEEP:-}" = 1 ] && docker compose up -d "$s"
	summary="$summary$(printf '  %-22s %s' "$s" "$r")
"
done
echo; echo "=== Summary"; printf '%s' "$summary"
