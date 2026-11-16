#!/usr/bin/env bash
# Real Redis 7.4 master/replica + three Sentinels; no published ports or production credentials.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BOOK_GATE_PROJECT:?Run only via disposable release gate}"
name="${BOOK_GATE_PROJECT}-sentinel"; net="$name-net"; pw="$(openssl rand -hex 32)"
containers=("$name-primary" "$name-replica" "$name-s1" "$name-s2" "$name-s3" "$name-client")
cleanup(){ rc=$?; if [ "$rc" -ne 0 ]; then for c in "${containers[@]}"; do docker logs "$c" 2>/dev/null || true; done; fi; docker rm -f "${containers[@]}" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; exit "$rc"; }
trap cleanup EXIT; trap 'exit 130' INT; trap 'exit 143' TERM
docker network create "$net" >/dev/null
image="$(docker compose images -q book | head -1)"; test -n "$image"
docker run -d --name "$name-primary" --network "$net" redis:7.4-alpine redis-server --requirepass "$pw" --masterauth "$pw" --save '' --appendonly no >/dev/null
ip(){ docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$1"; }
primary="$(ip "$name-primary")"
docker run -d --name "$name-replica" --network "$net" redis:7.4-alpine redis-server --requirepass "$pw" --masterauth "$pw" --replicaof "$primary" 6379 --save '' --appendonly no >/dev/null
replica="$(ip "$name-replica")"
for i in 1 2 3; do
  docker run -d --name "$name-s$i" --network "$net" -e PRIMARY="$primary" -e REDIS_TEST_PASSWORD="$pw" redis:7.4-alpine sh -ec 'printf "port 26379\nbind 0.0.0.0\nprotected-mode no\nsentinel monitor book %s 6379 2\nsentinel auth-pass book %s\nsentinel down-after-milliseconds book 1500\nsentinel failover-timeout book 10000\nsentinel parallel-syncs book 1\n" "$PRIMARY" "$REDIS_TEST_PASSWORD" > /tmp/sentinel.conf; exec redis-server /tmp/sentinel.conf --sentinel' >/dev/null
done
# Wait for actual replica synchronization and all Sentinels to discover peers before killing primary.
for _ in $(seq 1 60); do
  if docker exec -e REDISCLI_AUTH="$pw" "$name-replica" redis-cli INFO replication | grep -q 'master_link_status:up' && docker exec "$name-s1" redis-cli -p 26379 SENTINEL CKQUORUM book | grep -q '^OK'; then break; fi
  sleep 1
done
docker exec -e REDISCLI_AUTH="$pw" "$name-replica" redis-cli INFO replication | grep -q 'master_link_status:up'
docker exec "$name-s1" redis-cli -p 26379 SENTINEL CKQUORUM book | grep -q '^OK'
docker run -d --name "$name-client" --network "$net" -e BOOK_REDIS_SENTINELS="$name-s1:26379,$name-s2:26379,$name-s3:26379" -e BOOK_REDIS_PASSWORD="$pw" -e BOOK_TEST_PRIMARY="$primary" -e BOOK_TEST_REPLICA="$replica" "$image" php tests/redis-sentinel.php >/dev/null
wait_marker(){ for _ in $(seq 1 100); do if docker logs "$name-client" 2>&1 | grep -q "$1"; then return; fi; test "$(docker inspect -f '{{.State.Running}}' "$name-client")" = true || return 1; sleep 1; done; return 1; }
wait_marker READY_INITIAL
docker stop -t 1 "$name-primary" >/dev/null
wait_marker READY_PROMOTED
docker stop -t 1 "$name-s2" "$name-s3" >/dev/null
wait_marker READY_NO_QUORUM
docker start "$name-s2" "$name-s3" >/dev/null
code="$(docker wait "$name-client")"; docker logs "$name-client"; test "$code" = 0
