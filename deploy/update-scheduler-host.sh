#!/bin/sh
# Trusted, root-owned systemd oneshot. Executes the scheduler AS www-data in one Book container.
set -eu
umask 077
case "${BOOK_COMPOSE_PROJECT:-}" in
    ''|*[!a-zA-Z0-9_-]*) echo 'Invalid Compose project' >&2; exit 2 ;;
esac
# No shell evaluation of container labels, image metadata, or environment variables.
ids=$(/usr/bin/docker --host unix:///var/run/docker.sock ps -q \
    --filter "label=com.docker.compose.project=${BOOK_COMPOSE_PROJECT}" \
    --filter 'label=com.docker.compose.service=book' \
    --filter 'status=running')
set -- $ids
if [ "$#" -ne 1 ]; then
    echo 'Expected exactly one running Book container; no scheduled check performed' >&2
    exit 1
fi
case "$1" in *[!a-f0-9]*|'') echo 'Invalid Docker ID' >&2; exit 1;; esac
exec /usr/bin/docker --host unix:///var/run/docker.sock exec --user 33 "$1" \
    php /opt/cajeerbook/bin/update-scheduler.php tick
