#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BOOK_GATE_PROJECT:?Use release-gate.py}"
export COMPOSE_PROJECT_NAME="$BOOK_GATE_PROJECT"
export BOOK_TEST_URL=http://127.0.0.1:8080
export BOOK_SECURE_COOKIE=0
export BOOK_ADMIN_HASH="$(php -r 'echo password_hash("release-test-only-password", PASSWORD_DEFAULT);')"
export COMPOSE_FILE=compose.yaml
cleanup() { rc=$?; if [ "$rc" -ne 0 ]; then docker compose --profile automation logs --no-color || true; fi; docker compose --profile automation down -v --remove-orphans || rc=1; exit "$rc"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker compose up -d --build --wait --wait-timeout 120

docker compose exec -T book php tests/multilingual.php
docker compose exec -T book php tests/static-export.php
docker compose exec -T book php tests/ci-publishing.php
docker compose exec -T book php tests/automation.php
docker compose exec -T book php tests/sdk.php
docker compose exec -T book php tests/playground.php
docker compose exec -T book php tests/openapi.php
docker compose exec -T book php tests/github.php
docker compose exec -T book php tests/gitlab.php
docker compose exec -T book php tests/import.php
docker compose exec -T book php tests/release.php
docker compose exec -T book php tests/installation.php
python3 tests/release-http.py
python3 tests/team-release-http.py --state "$BOOK_GATE_TMP/team-release.json"
docker compose exec -T -u www-data book php bin/worker.php --once
docker compose restart book
python3 tests/release-http.py --check-persisted
python3 tests/team-release-http.py --state "$BOOK_GATE_TMP/team-release.json" --check-persisted

