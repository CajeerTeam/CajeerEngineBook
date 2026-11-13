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
grep -q 'id="section-1"' "$work/response"
grep -q 'id="start-here"' "$work/response"
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

# End-to-end rendering through the existing editor and public Reader.
cat > "$work/rich.md" <<'MD'
## Rich Markdown

| Feature | Result |
| --- | --- |
| GFM | **working** |

- [x] task

[relative](guide) ![image](https://example.org/logo.png)

<script>alert(1)</script>

[bad](javascript:evil)
MD
rich=("${auth[@]}" --data-urlencode 'book=qa' --data-urlencode 'version=1.0' --data-urlencode 'path=rich' --data-urlencode 'title=Rich' --data-urlencode 'section=Start' --data-urlencode 'order=2' --data-urlencode "body@$work/rich.md")
assert "$(status "${rich[@]}" -d "action=save&revision=0&csrf=$csrf" "$base/studio")" 303 'rich Markdown draft saved'
assert "$(status "${auth[@]}" "$base/studio?edit=qa%2F1.0%2Frich")" 200 'rich draft preview'
grep -q '<table>' "$work/response"
grep -q 'href="/docs/qa/1.0/guide"' "$work/response"
grep -q 'type="checkbox"' "$work/response"
! grep -q '<script>alert(1)</script>' "$work/response"
assert "$(status "$base/docs/qa/1.0/rich")" 404 'rich draft private'
assert "$(status "${rich[@]}" -d "action=publish&revision=1&csrf=$csrf" "$base/studio")" 303 'rich Markdown published'
assert "$(status "$base/docs/qa/1.0/rich")" 200 'rich published Reader'
grep -q '<table>' "$work/response"
grep -q 'href="/docs/qa/1.0/guide"' "$work/response"
grep -q 'href="#rich-markdown"' "$work/response"
grep -q 'src="https://example.org/logo.png"' "$work/response"
! grep -q '<script>alert(1)</script>' "$work/response"
! grep -q 'href="javascript:' "$work/response"
assert "$(status "$base/api/v1/pages/qa/1.0/rich")" 200 'API returns source Markdown'
php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($p["body"]!==file_get_contents($argv[2]))exit(1);' "$work/response" "$work/rich.md"

# Studio visual model and live preview are authenticated, CSRF protected and non-mutating.
editor=("${auth[@]}" --data-urlencode "csrf=$csrf" --data-urlencode 'book=qa' --data-urlencode 'version=1.0' --data-urlencode 'path=rich' --data-urlencode "body@$work/rich.md")
assert "$(status --data-urlencode 'mode=visual' "$base/studio/editor/render")" 401 'editor render requires authentication'
assert "$(status "${auth[@]}" --data-urlencode 'mode=visual' "$base/studio/editor/render")" 403 'editor render rejects missing CSRF'
assert "$(status "${editor[@]}" -d 'mode=visual' "$base/studio/editor/render")" 200 'visual model renders'
php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(implode("",array_column($p["blocks"],"source"))!==file_get_contents($argv[2]))exit(1);if(!in_array("protected",array_column($p["blocks"],"kind"),true))exit(1);' "$work/response" "$work/rich.md"
assert "$(status "${editor[@]}" -d 'mode=preview' "$base/studio/editor/render")" 200 'unsaved preview renders'
php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(!str_contains($p["html"],"href=\"/docs/qa/1.0/guide\"")||str_contains($p["html"],"<script>alert(1)</script>"))exit(1);' "$work/response"
assert "$(status "${editor[@]}" -d 'mode=unknown' "$base/studio/editor/render")" 422 'invalid editor mode rejected'
assert "$(status "${rich[@]}" -H 'X-Book-Studio: editor' -d "action=save&revision=2&csrf=$csrf" "$base/studio")" 200 'AJAX draft saved'
php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($p["revision"]!==3||$p["published"]!==true)exit(1);' "$work/response"
assert "$(status "${rich[@]}" -H 'X-Book-Studio: editor' -d "action=save&revision=2&csrf=$csrf" "$base/studio")" 409 'stale AJAX revision rejected'
