#!/bin/sh
# Dedicated disposable PostgreSQL/Redis 24h run. Never points at an operator database.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
[ "$#" -eq 1 ] || { echo "Usage: $0 NEW_REPORT_DIRECTORY" >&2; exit 2; }
REPORT=$1
# Keep evidence on the host, never inside a temporary container.
case "$REPORT" in /*) ;; *) REPORT="$PWD/$REPORT";; esac
[ ! -e "$REPORT" ] || { echo 'Report directory already exists' >&2; exit 2; }
mkdir -m 700 -p "$REPORT"
for tool in docker php python3 composer openssl; do command -v "$tool" >/dev/null || { echo "Missing $tool" >&2; exit 1; }; done
php -r 'foreach(["pdo_pgsql","redis","mbstring","zip"] as $m) if(!extension_loaded($m)) {fwrite(STDERR,"Missing PHP extension $m\n");exit(1);}'
[ -f vendor/autoload.php ] || { echo 'Run composer install and bin/install-mermaid.sh first' >&2; exit 1; }
# Random names and dynamic loopback ports. No external DSN or credentials are accepted.
ID=$(openssl rand -hex 8); PG=book-qualify-$ID-pg; REDIS=book-qualify-$ID-redis
PASS=$(openssl rand -hex 24); ADMIN=$(openssl rand -hex 24)
cleanup() {
    docker logs "$PG" >"$REPORT/postgres.log" 2>&1 || :
    docker logs "$REDIS" >"$REPORT/redis.log" 2>&1 || :
    docker rm -fv "$PG" "$REDIS" >/dev/null 2>&1 || :
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Do not inherit Docker Compose project settings; Docker context is the selected test daemon.
docker run -d --name "$PG" -e POSTGRES_DB=book_benchmark -e POSTGRES_USER=book_admin -e "POSTGRES_PASSWORD=$ADMIN" -p 127.0.0.1::5432 postgres:16-bookworm >/dev/null
docker run -d --name "$REDIS" -p 127.0.0.1::6379 redis:7.4-alpine >/dev/null
n=0; until docker exec "$PG" pg_isready -h 127.0.0.1 -U book_admin -d book_benchmark >/dev/null 2>&1; do n=$((n+1)); [ "$n" -lt 90 ] || exit 1; sleep 1; done
n=0; until docker exec "$REDIS" redis-cli ping | grep -qx PONG; do n=$((n+1)); [ "$n" -lt 90 ] || exit 1; sleep 1; done
docker exec "$PG" psql -U book_admin -d book_benchmark -v ON_ERROR_STOP=1 -c "CREATE ROLE book_benchmark LOGIN NOSUPERUSER NOCREATEROLE NOCREATEDB PASSWORD '$PASS'" -c 'ALTER DATABASE book_benchmark OWNER TO book_benchmark' -c 'REVOKE CONNECT ON DATABASE book_benchmark FROM PUBLIC' >/dev/null
PGPORT=$(docker port "$PG" 5432/tcp | sed 's/.*://')
RPORT=$(docker port "$REDIS" 6379/tcp | sed 's/.*://')
export BOOK_BENCHMARK_PG_DSN="pgsql:host=127.0.0.1;port=$PGPORT;dbname=book_benchmark;connect_timeout=5"
export BOOK_BENCHMARK_PG_USER=book_benchmark BOOK_BENCHMARK_PG_PASSWORD="$PASS"
export BOOK_BENCHMARK_REDIS_HOST=127.0.0.1 BOOK_BENCHMARK_REDIS_PORT="$RPORT"
# Real rate limiter remains active. Fixed workload target 100k across 100 scopes,
# 100 max in-flight requests, 20 arrivals/s steady; 100 arrivals/s stress.
python3 tests/performance-benchmark.py --qualify --postgres --redis --full --telemetry \
  --pages 100000 --per-workspace 1000 --requests 1000 --nodes 4 --concurrency 100 --source-ips 64 \
  --phase-seconds 120 --soak-seconds 86400 --offered-rps 20 --stress-multiplier 5 \
  --min-samples 20 --sample-seconds 60 --background-maintenance --pg-max-connections 64 --redis-max-memory-mib 2048 --redis-max-keys 1000000 --report-dir "$REPORT/qualification"
