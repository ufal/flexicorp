# Test images for the TEITOK installer (see README.md and run-tests.sh).
#
#   BASE      the Linux image: debian:12, ubuntu:24.04, rockylinux:9, fedora:41, ...
#   SCENARIO  fresh    install-teitok.pl -q on a clean system (Apache)
#             nginx    the same, with --webserver nginx (php-fpm)
#             upgrade  first the published installer (teitok.org, TEITOK only),
#                      then install-teitok.pl -q, which finds it and adds the query stack
#
# flexicorp and pando come from the git bundles run-tests.sh makes of your local
# checkouts (uncommitted changes included); TEITOK from GitLab unless a bundle is given.
ARG BASE=debian:12
FROM ${BASE}
ARG SCENARIO=fresh
ARG INSTALLER_ARGS=""

# what every installer run needs before it starts: perl, and something to fetch with
RUN if command -v apt-get >/dev/null; then apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y -q perl git curl ca-certificates sudo procps; \
    elif command -v dnf >/dev/null; then dnf install -y -q perl git curl sudo procps-ng findutils which; \
    elif command -v zypper >/dev/null; then zypper --non-interactive install perl git curl sudo procps; \
    elif command -v apk >/dev/null; then apk add --no-cache perl git curl sudo bash procps; \
    elif command -v pacman >/dev/null; then pacman -Syu --noconfirm perl git curl sudo procps-ng; fi

COPY bundles/ /src/
# local sources: clone the bundles (branch teitok-test-snapshot); a git clone of a path
# owned by another user needs safe.directory
RUN git config --system --add safe.directory '*' \
 && for b in /src/*.bundle; do n=$(basename "$b" .bundle); git clone -q -b teitok-test-snapshot "$b" "/src/$n"; done \
 && chmod -R a+rX /src

ENV TEITOK_SRC_ARGS="--flexicorp-repo /src/flexicorp --flexicorp-ref teitok-test-snapshot --pando-repo /src/pando --pando-ref teitok-test-snapshot"

# scenario "upgrade", step 1: TEITOK as the published installer sets it up
RUN if [ "$SCENARIO" = upgrade ]; then \
      curl -fsSL -o /tmp/install-teitok-old.pl http://www.teitok.org/downloads/install-teitok.pl \
      && (cd /tmp && DEBIAN_FRONTEND=noninteractive perl install-teitok-old.pl -q) \
      ; echo "old installer finished (exit $?)"; fi

# the installer under test (fresh/nginx), or the upgrade on top of step 1;
# the checks run when the container starts (services are not running during a build)
RUN T=""; [ -d /src/TEITOK ] && T="--teitok-repo /src/TEITOK"; \
    W=""; [ "$SCENARIO" = nginx ] && W="--webserver nginx"; \
    M="--fresh"; [ "$SCENARIO" = upgrade ] && M="--upgrade"; \
    perl /src/flexicorp/install/install-teitok.pl -q $M $W $T $TEITOK_SRC_ARGS \
      --admin-email admin@example.org --admin-password teitok $INSTALLER_ARGS --no-check

COPY entrypoint.sh /usr/local/sbin/teitok-test-entrypoint
RUN chmod 755 /usr/local/sbin/teitok-test-entrypoint
EXPOSE 80
CMD ["/usr/local/sbin/teitok-test-entrypoint"]
