#!/bin/sh
# Real PostgreSQL + Redis + two Apache replicas + Caddy HTTPS + DNS TXT.
# Local CA only in this disposable CI environment; production Caddyfile is validated separately.
set -eu
cd "$(dirname "$0")/.."
export BOOK_PRIMARY_HOST=book.example.com BOOK_ACME_EMAIL=ci@example.com
export BOOK_PG_NAMESPACE="$(openssl rand -hex 16)" BOOK_PG_PASSWORD="$(openssl rand -hex 24)" BOOK_PG_ADMIN_PASSWORD="$(openssl rand -hex 24)"
export BOOK_REDIS_PASSWORD="$(openssl rand -hex 32)" BOOK_DOMAIN_EDGE_SECRET="$(openssl rand -hex 32)"
export COMPOSE_PROJECT_NAME=book-domain-test
export COMPOSE_FILE=compose.yaml:compose.postgres.yaml:compose.redis.yaml:compose.distributed.yaml:compose.domains.yaml:tests/compose.domains-test.yaml
export BOOK_TEST_DNS_DIR="$(mktemp -d)" BOOK_TEST_CADDYFILE
BOOK_TEST_CADDYFILE="$BOOK_TEST_DNS_DIR/Caddyfile"; chmod 755 "$BOOK_TEST_DNS_DIR"
printf '{}' > "$BOOK_TEST_DNS_DIR/records.json"
sed '/^        on_demand$/a\        issuer internal' deploy/book-Caddyfile > "$BOOK_TEST_CADDYFILE"
cleanup() { rc=$?; if [ "$rc" -ne 0 ]; then docker compose logs --no-color || true; fi; docker compose down -v --remove-orphans || true; rm -rf "$BOOK_TEST_DNS_DIR"; exit "$rc"; }
trap cleanup EXIT
# Validate the actual production configuration, not only the local-CA variant.
docker run --rm -e BOOK_ACME_EMAIL -e BOOK_DOMAIN_EDGE_SECRET -v "$PWD/deploy/book-Caddyfile:/etc/caddy/Caddyfile:ro" caddy:2.10.2-alpine caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker compose config --quiet
docker compose build book
docker compose up -d --wait postgres redis dns-fixture
docker compose run --rm --no-deps --user www-data book php bin/postgres-content.php migrate --offline
docker compose up -d --wait --scale book=2 book proxy
state="$(docker compose exec -T -u www-data book php tests/domains-edge-seed.php)"
# Never print session or CSRF credentials to CI logs.
field() { printf '%s' "$state" | python3 -c "import json,sys; print(json.load(sys.stdin)['$1'])"; }
id="$(field id)"; token="$(field token)"; csrf="$(field csrf)"
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
code="$(https book.example.com -sS -o /dev/null -w '%{http_code}' -H "Cookie: __Host-book_session=$token" --data-urlencode "csrf=$csrf" --data-urlencode "id=$id" --data-urlencode 'host=docs.example.net' --data-urlencode 'action=disable' --data-urlencode 'revision=4' https://book.example.com/domains)"
[ "$code" = 303 ]
# Existing TLS certificate must not bypass live routing checks, including assets.
[ "$(https docs.example.net -sS -o /dev/null -w '%{http_code}' https://docs.example.net/api/v1/pages)" = 421 ]
[ "$(https docs.example.net -sS -o /dev/null -w '%{http_code}' https://docs.example.net/assets/book.css)" = 421 ]
echo 'PASS real DNS TXT verification, local-CA TLS issuance, host isolation, HTTP redirect, SNI rejection and immediate domain disable'
