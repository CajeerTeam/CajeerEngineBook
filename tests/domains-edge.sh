#!/bin/sh
# Real PostgreSQL + Redis + two Apache replicas + Caddy HTTPS + DNS TXT.
# Local CA only in this disposable CI environment; production Caddyfile is validated separately.
set -eu
cd "$(dirname "$0")/.."
export BOOK_PRIMARY_HOST=book.example.com BOOK_ACME_EMAIL=ci@example.com
export BOOK_PG_NAMESPACE="$(openssl rand -hex 16)" BOOK_PG_PASSWORD="$(openssl rand -hex 24)" BOOK_PG_ADMIN_PASSWORD="$(openssl rand -hex 24)"
export BOOK_REDIS_PASSWORD="$(openssl rand -hex 32)" BOOK_DOMAIN_EDGE_SECRET="$(openssl rand -hex 32)"
export COMPOSE_PROJECT_NAME="${BOOK_GATE_PROJECT:-book-domain-$(openssl rand -hex 6)}"
export COMPOSE_FILE=compose.yaml:compose.postgres.yaml:compose.redis.yaml:compose.distributed.yaml:compose.domains.yaml:compose.backup.yaml:compose.enterprise.yaml:compose.observability.yaml:tests/compose.domains-test.yaml
export BOOK_TEST_DNS_DIR="$(mktemp -d)" BOOK_TEST_CADDYFILE
BOOK_TEST_CADDYFILE="$BOOK_TEST_DNS_DIR/Caddyfile"; chmod 755 "$BOOK_TEST_DNS_DIR"
printf '{}' > "$BOOK_TEST_DNS_DIR/records.json"
export BOOK_METRICS_TOKEN_PATH="$BOOK_TEST_DNS_DIR/metrics-token" BOOK_GRAFANA_PASSWORD_PATH="$BOOK_TEST_DNS_DIR/grafana-password" BOOK_ALERT_WEBHOOK_PATH="$BOOK_TEST_DNS_DIR/alert-webhook" BOOK_MONITOR_TLS=0
openssl rand -hex 32 > "$BOOK_METRICS_TOKEN_PATH"
openssl rand -hex 32 > "$BOOK_GRAFANA_PASSWORD_PATH"
printf 'http://monitoring-receiver:8080/alerts\n' > "$BOOK_ALERT_WEBHOOK_PATH"
chmod 644 "$BOOK_METRICS_TOKEN_PATH" "$BOOK_GRAFANA_PASSWORD_PATH" "$BOOK_ALERT_WEBHOOK_PATH"
mkdir "$BOOK_TEST_DNS_DIR/alerts"
# Production overlay is exercised with a separate filesystem repository in this
# gate; the independent recovery-s3.sh gate exercises real S3 over HTTPS.
export BOOK_BACKUP_KEY_PATH="$BOOK_TEST_DNS_DIR/backup.key"
export BOOK_BACKUP_S3_URI=s3://book-enterprise-test/fixture
export BOOK_BACKUP_DRILL_PG_DSN='pgsql:host=postgres;port=5432;dbname=book_drill;connect_timeout=5'
export BOOK_BACKUP_DRILL_PG_USER=book_drill BOOK_BACKUP_DRILL_PG_PASSWORD="$(openssl rand -hex 24)"
(umask 077; openssl rand -hex 32 > "$BOOK_BACKUP_KEY_PATH")
mkdir "$BOOK_TEST_DNS_DIR/remote"
# The production backup container runs as UID 33; the test directory is isolated.
as_root() { if [ "$(id -u)" = 0 ]; then "$@"; else sudo -n "$@"; fi; }
as_root chown 33:33 "$BOOK_BACKUP_KEY_PATH" "$BOOK_TEST_DNS_DIR/remote"
chmod 700 "$BOOK_TEST_DNS_DIR/remote" 2>/dev/null || as_root chmod 700 "$BOOK_TEST_DNS_DIR/remote"
sed '/^        on_demand$/a\        issuer internal' deploy/book-Caddyfile > "$BOOK_TEST_CADDYFILE"
cleanup() { rc=$?; if [ "$rc" -ne 0 ]; then docker compose logs --no-color || true; fi; docker compose --profile automation --profile backup down -v --remove-orphans || rc=1; as_root rm -rf "$BOOK_TEST_DNS_DIR" || rc=1; exit "$rc"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Validate the actual production configuration, not only the local-CA variant.
docker run --rm -e BOOK_ACME_EMAIL -e BOOK_DOMAIN_EDGE_SECRET -v "$PWD/deploy/book-Caddyfile:/etc/caddy/Caddyfile:ro" caddy:2.10.2-alpine caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker compose config --quiet
docker compose --profile automation --profile backup build book worker domain-check backup diagnostics
docker compose up -d --wait postgres redis dns-fixture
docker compose exec -T postgres psql -U book_admin -d book -v ON_ERROR_STOP=1 -c "CREATE ROLE book_drill LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '$BOOK_BACKUP_DRILL_PG_PASSWORD'" -c "CREATE DATABASE book_drill OWNER book_drill" -c "REVOKE CONNECT ON DATABASE book_drill FROM PUBLIC"
docker compose run --rm --no-deps --user www-data book php bin/postgres-content.php migrate --offline
docker compose up -d --wait --scale book=2 book proxy
state="$(docker compose exec -T -u www-data book php tests/domains-edge-seed.php)"
# Never print session or CSRF credentials to CI logs.
field() { printf '%s' "$state" | python3 -c "import json,sys; print(json.load(sys.stdin)['$1'])"; }
id="$(field id)"; org="$(field organization)"; token="$(field token)"; csrf="$(field csrf)"
# Bootstrap the test CA, then use its actual root to verify every TLS connection.
for n in $(seq 1 20); do
  if curl -kfsS --noproxy '*' --resolve book.example.com:443:127.0.0.1 --max-time 10 https://book.example.com/health/live >/dev/null; then break; fi
  sleep 1
done
docker compose exec -T proxy cat /data/caddy/pki/authorities/local/root.crt > "$BOOK_TEST_DNS_DIR/ca.crt"
https() { host="$1"; shift; curl --noproxy '*' --cacert "$BOOK_TEST_DNS_DIR/ca.crt" --resolve "$host:443:127.0.0.1" --max-time 30 "$@"; }
https book.example.com -fsS https://book.example.com/health/live >/dev/null
# Pending domain cannot get a certificate, even when pointed at the ingress.
if https docs.example.net -ksS https://docs.example.net/health/live >/dev/null 2>&1; then echo 'Pending domain unexpectedly negotiated TLS'; exit 1; fi
# Publish real DNS TXT proof in the controlled authoritative resolver. The actual
# production controller now calls PHP dns_get_record, not a test callback.
printf '%s' "$state" | python3 -c 'import json,sys; x=json.load(sys.stdin); print(json.dumps({x["dns"]:x["proof"]}))' > "$BOOK_TEST_DNS_DIR/records.new"
mv "$BOOK_TEST_DNS_DIR/records.new" "$BOOK_TEST_DNS_DIR/records.json"
code="$(https book.example.com -sS -o /dev/null -w '%{http_code}' -H "Cookie: __Host-book_session=$token" --data-urlencode "csrf=$csrf" --data-urlencode "id=$id" --data-urlencode 'host=docs.example.net' --data-urlencode 'action=verify' --data-urlencode 'revision=3' https://book.example.com/domains)"
[ "$code" = 303 ]
https docs.example.net -fsS https://docs.example.net/docs/guide/1/start | grep -q 'https://docs.example.net/docs/guide/1/start'
https docs.example.net -fsS https://docs.example.net/sitemap.xml | grep -q 'https://docs.example.net/docs/guide/1/start'
https docs.example.net -fsS https://docs.example.net/assets/book.css >/dev/null
[ "$(curl --noproxy '*' -sS -o /dev/null -w '%{http_code} %{redirect_url}' --resolve docs.example.net:80:127.0.0.1 http://docs.example.net/docs/guide/1/start)" = '308 https://docs.example.net/docs/guide/1/start' ]
[ "$(https docs.example.net -sS -o /dev/null -w '%{http_code}' -H 'Host: book.example.com' https://docs.example.net/)" = 421 ]
[ "$(https docs.example.net -sS -o /dev/null -w '%{http_code}' -H "Cookie: __Host-book_session=$token" https://docs.example.net/studio)" = 404 ]
[ "$(https docs.example.net -sS -o /dev/null -w '%{http_code}' https://docs.example.net/.well-known/book-domain-ask?domain=docs.example.net)" = 404 ]
# One installation now exercises real TLS, SQL publishing, Redis search, enterprise
# audit, scheduler, remote backup, PostgreSQL drill and remote-only recovery.
scope="/w/$org/$id"
code="$(https book.example.com -sS -o /dev/null -w '%{http_code}' -H "Cookie: __Host-book_session=$token" --data-urlencode "csrf=$csrf" --data-urlencode 'action=publish' --data-urlencode 'book=guide' --data-urlencode 'version=1' --data-urlencode 'path=integrated' --data-urlencode 'title=Enterprise integration' --data-urlencode 'section=Guide' --data-urlencode 'order=20' --data-urlencode 'revision=0' --data-urlencode 'body=Enterprise integration quasar' "https://book.example.com$scope/studio")"
[ "$code" = 303 ]
https docs.example.net -fsS 'https://docs.example.net/api/v1/search?q=quasar&locale=en' | python3 -c 'import json,sys; assert any(x["key"]=="guide/1/integrated" for x in json.load(sys.stdin))'
https docs.example.net -fsS 'https://docs.example.net/docs/guide/1/integrated' | grep -q 'Enterprise integration quasar'
https book.example.com -fsS -H "Cookie: __Host-book_session=$token" --data-urlencode "csrf=$csrf" --data-urlencode 'format=jsonl' --data-urlencode "workspace=$id" --data-urlencode 'action=page.publish' https://book.example.com/audit | python3 -c 'import json,sys; rows=[json.loads(x) for x in sys.stdin if x.strip()]; assert rows and any(x["event"]["action"]=="page.publish" for x in rows)'
docker compose --profile backup up -d --no-deps --wait --wait-timeout 240 backup
backup_id="$(docker compose exec -T backup php bin/recovery.php health 90000 | python3 -c 'import json,sys; x=json.load(sys.stdin); assert x["status"]=="ok"; print(x["last_id"])')"
# Delete only the local encrypted fixture: the remote copy must independently restore.
docker compose exec -T backup rm "/var/lib/cajeerbackup/$backup_id.cbk"
docker compose exec -T backup php bin/recovery.php restore "$backup_id" /tmp/enterprise-restored --remote
# Inspect the actual restored data, not the source installation. Sessions and DNS
# proof must be reset and the published document must be present.
docker compose exec -T -e BOOK_DATA_DIR=/tmp/enterprise-restored -e BOOK_PG_DSN= -e BOOK_PG_NAMESPACE= backup php tests/enterprise-restored.php "$org" "$id" "$token"
# The operator can switch to a new PostgreSQL namespace after remote-only restore.
restore_ns="$(openssl rand -hex 16)"
docker compose exec -T -e BOOK_DATA_DIR=/tmp/enterprise-restored -e BOOK_PG_DSN="$BOOK_BACKUP_DRILL_PG_DSN" -e BOOK_PG_USER="$BOOK_BACKUP_DRILL_PG_USER" -e BOOK_PG_PASSWORD="$BOOK_BACKUP_DRILL_PG_PASSWORD" -e BOOK_PG_NAMESPACE="$restore_ns" backup php bin/postgres-content.php migrate --offline
docker compose exec -T -e BOOK_DATA_DIR=/tmp/enterprise-restored -e BOOK_PG_DSN="$BOOK_BACKUP_DRILL_PG_DSN" -e BOOK_PG_USER="$BOOK_BACKUP_DRILL_PG_USER" -e BOOK_PG_PASSWORD="$BOOK_BACKUP_DRILL_PG_PASSWORD" -e BOOK_PG_NAMESPACE="$restore_ns" backup php tests/enterprise-restored.php "$org" "$id" "$token"
docker compose exec -T -e BOOK_DATA_DIR=/tmp/enterprise-restored -e BOOK_PG_DSN="$BOOK_BACKUP_DRILL_PG_DSN" -e BOOK_PG_USER="$BOOK_BACKUP_DRILL_PG_USER" -e BOOK_PG_PASSWORD="$BOOK_BACKUP_DRILL_PG_PASSWORD" -e BOOK_PG_NAMESPACE="$restore_ns" backup php bin/audit.php check
echo 'PASS integrated TLS publication, search, scoped audit, verified scheduler, remote-only recovery and new PostgreSQL namespace'

code="$(https book.example.com -sS -o /dev/null -w '%{http_code}' -H "Cookie: __Host-book_session=$token" --data-urlencode "csrf=$csrf" --data-urlencode "id=$id" --data-urlencode 'host=docs.example.net' --data-urlencode 'action=disable' --data-urlencode 'revision=4' https://book.example.com/domains)"
[ "$code" = 303 ]
# Existing TLS certificate must not bypass live routing checks, including assets.
[ "$(https docs.example.net -sS -o /dev/null -w '%{http_code}' https://docs.example.net/api/v1/pages)" = 421 ]
[ "$(https docs.example.net -sS -o /dev/null -w '%{http_code}' https://docs.example.net/assets/book.css)" = 421 ]
echo 'PASS real DNS TXT verification, local-CA TLS issuance, host isolation, HTTP redirect, SNI rejection and immediate domain disable'

# Real Prometheus scrape, Grafana provisioning and Alertmanager delivery from this deployment.
bash tests/monitoring-gate.sh
