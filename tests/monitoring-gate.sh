#!/usr/bin/env bash
# Called only by the disposable enterprise DNS/TLS gate.
set -euo pipefail
: "${BOOK_TEST_DNS_DIR:?Run domains-edge.sh}"
: "${COMPOSE_PROJECT_NAME:?Run domains-edge.sh}"
docker compose up -d --wait --wait-timeout 120 diagnostics monitoring-receiver prometheus alertmanager grafana
docker compose exec -T prometheus /bin/promtool check config /etc/prometheus/prometheus.yml
docker compose exec -T alertmanager /bin/amtool check-config /etc/alertmanager/alertmanager.yml
docker compose exec -T -e BOOK_TEST_VERSION="$(cat VERSION)" monitoring-receiver python /fixture/monitoring-check.py initial
# No worker was started by the earlier domains E2E; trigger a real healthy tick now.
docker compose run --rm --no-deps --user www-data worker php bin/worker.php --once
docker compose run --rm --no-deps --user www-data domain-check php bin/domains.php recheck
docker compose exec -T monitoring-receiver python /fixture/monitoring-check.py worker
docker compose stop redis
docker compose exec -T monitoring-receiver python /fixture/monitoring-check.py redis-down
docker compose up -d --wait --wait-timeout 120 redis
docker compose exec -T monitoring-receiver python /fixture/monitoring-check.py redis-up
