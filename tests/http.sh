#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
work=$(mktemp -d)
export BOOK_DATA_DIR="$work/data"
export BOOK_ADMIN_HASH=$(php -r 'echo password_hash("test-only-password",PASSWORD_DEFAULT);')
port=${BOOK_TEST_PORT:-8099}
base="http://127.0.0.1:$port"
php -S "127.0.0.1:$port" -t public public/router.php >"$work/server.log" 2>&1 &
pid=$!
trap 'kill "$pid" 2>/dev/null || true; rm -rf "$work"' EXIT
for i in {1..20}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
status(){ curl -sS -o "$work/response" -w '%{http_code}' "$@"; }
assert(){ if [[ "$1" != "$2" ]]; then cat "$work/response" "$work/server.log"; exit 1; fi; echo "PASS $3"; }
assert "$(status "$base/docs/handbook/1.0/welcome")" 200 'public document'
assert "$(status "$base/api/v1/pages/handbook/1.0/welcome")" 200 'page API'
assert "$(status "$base/studio")" 401 'Studio unauthorized'
auth=(-u admin:test-only-password -b "$work/cookies" -c "$work/cookies")
assert "$(status "${auth[@]}" "$base/studio")" 200 'Studio authenticated'
csrf=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$work/response" | head -1)
[[ ${#csrf} == 64 ]]
post=("${auth[@]}" --data-urlencode 'book=qa' --data-urlencode 'version=1.0' --data-urlencode 'path=start' --data-urlencode 'title=QA' --data-urlencode 'section=Start' --data-urlencode 'order=1' --data-urlencode 'body=secret-draft-needle')
assert "$(status "${post[@]}" -d 'action=save&revision=0' "$base/studio")" 403 'CSRF rejection'
assert "$(status "${post[@]}" -d "action=save&revision=0&csrf=$csrf" "$base/studio")" 303 'draft saved'
assert "$(status "$base/docs/qa/1.0/start")" 404 'draft not public'
assert "$(status "$base/api/v1/search?q=secret-draft-needle")" 200 'search responds'
[[ $(cat "$work/response") == '[]' ]]
assert "$(status "${post[@]}" -d "action=publish&revision=1&csrf=$csrf" "$base/studio")" 303 'publish'
assert "$(status "$base/docs/qa/1.0/start")" 200 'published page public'
assert "$(status "$base/api/v1/search?q=secret-draft-needle")" 200 'search published'
grep -q 'qa/1.0/start' "$work/response"
assert "$(status "${auth[@]}" "$base/studio/export")" 200 'backup'
grep -q 'qa/1.0/start' "$work/response"
assert "$(status "$base/var/books.json")" 404 'private store unreachable'
numeric=("${auth[@]}" --data-urlencode 'book=qa' --data-urlencode 'version=2' --data-urlencode 'path=start' --data-urlencode 'title=Version 2' --data-urlencode 'section=Start' --data-urlencode 'order=1' --data-urlencode 'body=Numeric version')
assert "$(status "${numeric[@]}" -d "action=publish&revision=0&csrf=$csrf" "$base/studio")" 303 'numeric version publish'
assert "$(status "$base/docs/qa/2/start")" 200 'numeric version reader'
grep -q '/docs/qa/1.0/start' "$work/response"
