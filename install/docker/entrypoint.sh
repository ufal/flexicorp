#!/bin/sh
# Test container start: run TEITOK's services (no systemd in a container), check
# the installation, then stay up so you can open http://localhost:<port>/teitok/
#
#   docker compose run --rm <service> check     only the check, exit with its status
stack=/home/git/flexicorp/install/install-stack.pl
if [ "${TEITOK_REFRESH_ON_START:-}" = 1 ] && [ -f "$stack" ]; then
	# projects are in a volume: bring flexicorp (venv) and its pages up to this image
	perl "$stack" -q --only flexicorp,pages --no-pull --no-deps --no-check >/var/log/teitok-refresh.log 2>&1 \
		|| echo "refreshing flexicorp in the volume failed: see /var/log/teitok-refresh.log"
fi
if [ -x /usr/local/sbin/teitok-services ]; then
	/usr/local/sbin/teitok-services
else
	# installed by the published installer only (no services script): Apache in the foreground later
	apachectl start 2>/dev/null || httpd -k start 2>/dev/null
	[ -x /usr/local/sbin/teitok-fqs ] && /usr/local/sbin/teitok-fqs start
fi
sleep 3
check=/home/git/flexicorp/install/check-stack.pl
[ -f "$check" ] || check=$(ls /home/*/flexicorp/install/check-stack.pl /src/flexicorp/install/check-stack.pl 2>/dev/null | head -1)
perl "$check"
status=$?
echo "check-stack.pl exit status: $status"
[ "$1" = check ] && exit $status
echo "TEITOK is running; logs in /var/log/teitok-install, /var/log/fqs, /var/log/apache2 or /var/log/nginx"
exec tail -F /var/log/fqs/fqs.log 2>/dev/null
