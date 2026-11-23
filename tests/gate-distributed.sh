#!/usr/bin/env bash
export BOOK_IMAGE_TARGET=test
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BOOK_GATE_PROJECT:?Use release-gate.py}"
export COMPOSE_PROJECT_NAME="$BOOK_GATE_PROJECT"
export BOOK_TEST_URL=http://127.0.0.1:8080
export BOOK_SECURE_COOKIE=0
export BOOK_ADMIN_HASH="$(php -r 'echo password_hash("release-test-only-password", PASSWORD_DEFAULT);')"
export BOOK_PG_NAMESPACE="$(openssl rand -hex 16)" BOOK_PG_PASSWORD="$(openssl rand -hex 24)" BOOK_PG_ADMIN_PASSWORD="$(openssl rand -hex 24)" BOOK_REDIS_PASSWORD="$(openssl rand -hex 32)"
export COMPOSE_FILE=compose.yaml:compose.postgres.yaml:compose.redis.yaml:compose.distributed.yaml
cleanup() { rc=$?; if [ "$rc" -ne 0 ]; then docker compose --profile automation logs --no-color || true; fi; docker compose --profile automation down -v --remove-orphans || rc=1; exit "$rc"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker compose config --quiet
docker compose --profile automation build book worker
docker compose up -d --wait postgres redis
docker compose run --rm --no-deps --user www-data book php bin/postgres-content.php migrate --offline
docker compose run --rm --no-deps --user www-data book php bin/runtime.php check
docker compose --profile automation up -d --wait --wait-timeout 180 --scale book=2 --scale worker=2
curl --fail --retry 10 --retry-connrefused http://127.0.0.1:8080/health/ready
python3 tests/release-http.py
python3 tests/enterprise-load.py --url "$BOOK_TEST_URL" | tee "$BOOK_GATE_REPORT/enterprise-load.json"
docker compose exec -T --index 1 book php bin/runtime.php status
docker compose exec -T --index 2 book php bin/runtime.php status
docker compose restart book
python3 tests/release-http.py --check-persisted
# Redis restart drops ephemeral data; the SQL publication and both app instances survive.
docker compose restart redis
docker compose up -d --wait --wait-timeout 120 redis
python3 tests/release-http.py --check-persisted


# Kill one actual app replica and exercise the existing persistent page via Nginx.
replica="$(docker compose ps -q book | head -1)"
docker stop -t 1 "$replica"
# An abrupt container stop may briefly leave a withdrawn IP in Docker DNS and
# Nginx's resolver cache. Require failover within a bounded recovery window;
# only after discovery converges do the 30 strict no-retry requests begin.
# This does not weaken or skip the sustained-load SLO verified above.
failover_page="$BOOK_TEST_URL/api/v1/pages/release/1.0/renamed"
failover_deadline=$((SECONDS + 20))
failover_successes=0
while (( SECONDS < failover_deadline )); do
  if curl --fail --silent --show-error --connect-timeout 2 --max-time 10 "$failover_page" | grep -q 'Mixed release content'; then
    failover_successes=$((failover_successes + 1))
    if (( failover_successes >= 3 )); then break; fi
  else
    failover_successes=0
  fi
  sleep 0.5
done
if (( failover_successes < 3 || SECONDS > failover_deadline )); then
  echo 'Nginx did not recover three consecutive reads within the 20s failover RTO' >&2
  exit 1
fi
for n in $(seq 1 30); do
  curl --fail --silent --show-error --connect-timeout 2 --max-time 10 "$failover_page" | grep -q 'Mixed release content'
done
echo 'PASS failover: recovered within 20s and served 30 consecutive persistent reads'
docker start "$replica"
docker compose up -d --wait --wait-timeout 180 --scale book=2 book
# Both replicas must see the same data and lock namespace.
probe="$(docker compose exec -T --index 1 -u www-data book php bin/storage-probe.php create)"
docker compose exec -T --index 2 -u www-data book php bin/storage-probe.php verify "$probe"
lock_log="$(mktemp)"
docker compose exec -T --index 1 -u www-data book php bin/storage-probe.php hold "$probe" 10 >"$lock_log" &
lock_pid=$!
for n in $(seq 1 50); do if grep -q LOCKED "$lock_log"; then break; fi; sleep 0.1; done
grep -q LOCKED "$lock_log"
docker compose exec -T --index 2 -u www-data book php bin/storage-probe.php busy "$probe"
wait "$lock_pid"; rm -f "$lock_log"
docker compose exec -T --index 2 -u www-data book php bin/storage-probe.php remove "$probe"
bash tests/redis-sentinel-gate.sh
