#!/bin/bash
# Destructive only to randomly named disposable containers and a new private directory.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd); cd "$ROOT"
TMP=$(mktemp -d); chmod 700 "$TMP"
# Release qualification can test the exact candidate, not a second build.
CANDIDATE=${BOOK_RELEASE_PITR_IMAGE:-}
if [[ -n "$CANDIDATE" && ! "$CANDIDATE" =~ ^ghcr\.io/[a-z0-9_.-]+/[a-z0-9_.-]+@sha256:[0-9a-f]{64}$ ]]; then exit 2; fi
TAG=book-pitr-test-$(openssl rand -hex 6); SRC=$TAG-source; DST=$TAG-restore; REPO=$TAG-repo; DATA=$TAG-data; REST=$TAG-rest; IMAGE=$TAG-image
cleanup(){ docker logs "$SRC" 2>/dev/null | tail -80 || :; docker logs "$DST" 2>/dev/null | tail -80 || :; docker rm -fv "$SRC" "$DST" >/dev/null 2>&1 || :; docker volume rm "$REPO" "$DATA" "$REST" >/dev/null 2>&1 || :; docker image rm "$IMAGE" >/dev/null 2>&1 || :; rm -rf "$TMP"; }
trap cleanup EXIT
# No inherited application, AWS, PostgreSQL or Redis credentials.
for k in $(env | cut -d= -f1 | grep -E '^(BOOK_|AWS_|PG|REDIS_)' || :); do unset "$k"; done
mkdir -m 700 "$TMP/spool" "$TMP/remote"; cp -a tests/fixtures/upgrade-050 "$TMP/app"; openssl rand -hex 32 > "$TMP/key"; chmod 600 "$TMP/key"
cat > "$TMP/pgbackrest.conf" <<EOF
[global]
repo1-path=/repo
repo1-retention-full=2
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=$(openssl rand -hex 32)
log-level-console=warn
log-level-file=off
archive-async=n
[book]
pg1-path=/var/lib/postgresql/data
pg1-user=book_admin
EOF
chmod 644 "$TMP/pgbackrest.conf" # ephemeral test-only repository key, private parent
# Build the same database image as compose.pitr.yaml.
if [[ -n "$CANDIDATE" ]]; then
  docker pull "$CANDIDATE"
  docker tag "$CANDIDATE" "$IMAGE"
else
  docker build -f deploy/pitr/Dockerfile -t "$IMAGE" .
fi
docker volume create "$REPO" >/dev/null; docker volume create "$DATA" >/dev/null; docker volume create "$REST" >/dev/null
docker run --rm -v "$REPO:/repo" -v "$REST:/restore" --entrypoint sh "$IMAGE" -c 'chown postgres:postgres /repo /restore; chmod 700 /repo /restore'
mounts=(-v "$REPO:/repo" -v "$TMP/pgbackrest.conf:/etc/pgbackrest/pgbackrest.conf:ro")
docker run -d --name "$SRC" -p 127.0.0.1::5432 "${mounts[@]}" -v "$DATA:/var/lib/postgresql/data" -v "$ROOT/bin/postgres-init.sh:/docker-entrypoint-initdb.d/10-book.sh:ro" -e POSTGRES_DB=book -e POSTGRES_USER=book_admin -e POSTGRES_PASSWORD=admin_test_only -e BOOK_PG_PASSWORD=book_test_only "$IMAGE" postgres -c wal_level=replica -c archive_mode=on -c 'archive_command=pgbackrest --stanza=book archive-push %p' -c archive_timeout=60 >/dev/null
ready(){ local n=$1; for i in $(seq 1 120); do if docker exec "$n" pg_isready -h 127.0.0.1 -U book_admin -d book >/dev/null 2>&1; then return; fi; sleep 1; done; return 1; }
port(){ docker port "$1" 5432/tcp | sed 's/.*://'; }
ready "$SRC"
export BOOK_DATA_DIR="$TMP/app" BOOK_PG_NAMESPACE=$(openssl rand -hex 16) BOOK_PG_USER=book BOOK_PG_PASSWORD=book_test_only BOOK_PG_DSN="pgsql:host=127.0.0.1;port=$(port "$SRC");dbname=book;connect_timeout=5"
export BOOK_PITR_PG_USER=book_admin BOOK_PITR_PG_PASSWORD=admin_test_only BOOK_PITR_DIR="$TMP/spool" BOOK_BACKUP_KEY_FILE="$TMP/key" BOOK_BACKUP_REMOTE_DIR="$TMP/remote"
php tests/pitr-fixture.php seed > /dev/null
# Control role has only the privileges required by the paired capture.
docker exec "$SRC" psql -U book_admin -d book -v ON_ERROR_STOP=1 -c "CREATE ROLE book_pitr LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD 'pitr_test_only'; GRANT CONNECT ON DATABASE book TO book_pitr; GRANT USAGE ON SCHEMA cajeer_book TO book_pitr; GRANT SELECT ON cajeer_book.content_installation TO book_pitr; GRANT EXECUTE ON FUNCTION pg_catalog.pg_control_system(), pg_catalog.pg_create_restore_point(text), pg_catalog.pg_switch_wal() TO book_pitr"
export BOOK_PITR_PG_USER=book_pitr BOOK_PITR_PG_PASSWORD=pitr_test_only
docker exec -u postgres "$SRC" pgbackrest --stanza=book stanza-create
docker exec -u postgres "$SRC" pgbackrest --stanza=book check
docker exec -u postgres "$SRC" book-pitr-repo backup > "$TMP/backup.json"
LABEL=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["backup"])' "$TMP/backup.json")
php tests/pitr-fixture.php before
php bin/pitr.php capture "$LABEL" > "$TMP/point.json"
ID=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["id"])' "$TMP/point.json")
php bin/pitr.php inspect "$ID" > "$TMP/point.json"
docker exec -i -u postgres "$SRC" book-pitr-repo archive-check < "$TMP/point.json"
# Restore must never mutate the original database.
if php bin/pitr.php restore "$ID" "$TMP/unsafe" --isolated; then echo 'Source restore unexpectedly accepted' >&2; exit 1; fi
php tests/pitr-fixture.php after
# Force a later WAL archive so the restore must stop at the named point, not archive end.
LATER_WAL=$(docker exec "$SRC" psql -U book_admin -d book -Atc 'SELECT pg_walfile_name(pg_current_wal_insert_lsn())')
docker exec "$SRC" psql -U book_admin -d book -c 'SELECT pg_switch_wal()'
for i in $(seq 1 120); do if docker exec -u postgres "$SRC" pgbackrest --stanza=book archive-get "$LATER_WAL" /tmp/later-wal >/dev/null 2>&1; then break; fi; sleep 1; done
docker exec "$SRC" test -s /tmp/later-wal
# Destroy source database and app; use remote sidecar and physical repo only.
docker rm -fv "$SRC" >/dev/null; docker volume rm "$DATA" >/dev/null; rm -rf "$TMP/app"; rm "$TMP/spool/$ID.cbk"
php bin/pitr.php inspect "$ID" > "$TMP/point.json"
# Restore to separate volume. Repo mounted read-only during recovery.
docker run --rm -i --user postgres -v "$REPO:/repo:ro" -v "$REST:/restore" -v "$TMP/pgbackrest.conf:/etc/pgbackrest/pgbackrest.conf:ro" --entrypoint book-pitr-repo "$IMAGE" restore /restore/pgdata --isolated < "$TMP/point.json"
docker run -d --name "$DST" -p 127.0.0.1::5432 --user postgres -v "$REPO:/repo:ro" -v "$REST:/restore" -v "$TMP/pgbackrest.conf:/etc/pgbackrest/pgbackrest.conf:ro" --entrypoint postgres "$IMAGE" -D /restore/pgdata -c archive_mode=off -c listen_addresses='*' >/dev/null
ready "$DST"
# Wait for promotion at target, not merely hot standby availability.
for i in $(seq 1 120); do if [ "$(docker exec "$DST" psql -U book_admin -d book -Atc 'SELECT pg_is_in_recovery()' 2>/dev/null || :)" = f ]; then break; fi; sleep 1; done
export BOOK_PG_DSN="pgsql:host=127.0.0.1;port=$(port "$DST");dbname=book;connect_timeout=5"
php bin/pitr.php restore "$ID" "$TMP/recovered" --isolated
export BOOK_DATA_DIR="$TMP/recovered"
php tests/pitr-fixture.php verify
php bin/installation.php check
php bin/audit.php check
php bin/search.php check
echo 'PASS physical backup, WAL replay, source loss, isolated recovery and media/session integrity'
