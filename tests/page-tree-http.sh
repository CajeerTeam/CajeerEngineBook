#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
work=$(mktemp -d)
export BOOK_DATA_DIR="$work/data" BOOK_SECURE_COOKIE=0
export BOOK_ADMIN_HASH=$(php -r 'echo password_hash("tree-test-password",PASSWORD_DEFAULT);')
# Seed realistic legacy entries without involving the Markdown parser: these HTTP
# checks exercise the real App, router, template engine and transaction store.
php -r 'require "core/Update/OperationFiles.php";require "src/BookStore.php"; $s=new CajeerBook\BookStore(getenv("BOOK_DATA_DIR"));$s->change(function(&$db){$p=$db["pages"]["handbook/1.0/welcome"]["published"];$p["title"]="Child";$p["order"]=11;$db["pages"]["handbook/1.0/welcome/child"]=["draft"=>$p,"published"=>$p,"revision"=>1];$p["title"]="Private";$db["pages"]["handbook/1.0/welcome/private"]=["draft"=>$p,"published"=>null,"revision"=>1];});'
port=${BOOK_TREE_TEST_PORT:-8106};base="http://127.0.0.1:$port"
php -S "127.0.0.1:$port" -t public public/router.php >"$work/server.log" 2>&1 &
pid=$!;trap 'kill "$pid" 2>/dev/null || true; rm -rf "$work"' EXIT
for i in {1..30}; do curl -fsS "$base/health/live" >/dev/null 2>&1 && break; sleep .1; done
status(){ curl -sS -D "$work/headers" -o "$work/body" -w '%{http_code}' "$@"; }
assert(){ if [[ "$1" != "$2" ]]; then cat "$work/headers" "$work/body" "$work/server.log"; exit 1; fi; echo "PASS $3"; }
auth=(-u admin:tree-test-password -b "$work/cookies" -c "$work/cookies")
refresh(){ assert "$(status "${auth[@]}" "$base/studio/tree?scope=handbook%2F1.0")" 200 'Studio tree renders'; csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' "$work/body" | head -1 | cut -d'"' -f4); token=$(grep -o 'name="token" value="[a-f0-9]*"' "$work/body" | head -1 | cut -d'"' -f4); }
assert "$(status "$base/studio/tree")" 401 'tree requires admin'
refresh; [[ ${#csrf} == 64 && ${#token} == 64 ]]
grep -q 'data-drag-key="handbook/1.0/welcome/child"' "$work/body"
assert "$(status "${auth[@]}" -d 'action=move' "$base/studio/tree")" 403 'tree rejects missing CSRF'
post(){ status "${auth[@]}" --data-urlencode "csrf=$csrf" --data-urlencode 'scope=handbook/1.0' --data-urlencode "token=$token" "$@" "$base/studio/tree"; }
assert "$(post -d 'action=move&source=handbook/1.0/welcome&target=handbook/1.0/welcome/child&position=inside')" 422 'HTTP descendant cycle rejected'
assert "$(post -d 'action=move&source=handbook/1.0/welcome&target=handbook/1.0/authoring&position=inside&slug=manual')" 303 'HTTP branch move committed'
assert "$(post -d 'action=publish')" 409 'HTTP stale tree token rejected'
assert "$(status "$base/api/v1/pages/handbook/1.0/welcome")" 200 'old public route still serves snapshot'
assert "$(status "$base/api/v1/pages/handbook/1.0/authoring/manual")" 404 'draft URL not public'
assert "$(status "${auth[@]}" "$base/studio?edit=handbook%2F1.0%2Fwelcome")" 303 'old editor URL redirects to draft location'
grep -qi 'location: /studio?edit=handbook%2F1.0%2Fauthoring%2Fmanual' "$work/headers"
refresh; grep -q 'data-tree-node="handbook/1.0/authoring/manual/child"' "$work/body"
assert "$(post -d 'action=publish')" 303 'HTTP publish tree committed'
assert "$(status "$base/docs/handbook/1.0/welcome?lang=en")" 308 'old Reader URL redirects'
grep -qi 'location: /docs/handbook/1.0/authoring/manual?lang=en' "$work/headers"
assert "$(status -I "$base/docs/handbook/1.0/welcome/child")" 308 'HEAD redirects for moved descendant'
assert "$(status "$base/api/v1/pages/handbook/1.0/welcome")" 308 'old API URL redirects'
grep -qi 'location: /api/v1/pages/handbook/1.0/authoring/manual' "$work/headers"
assert "$(status "$base/api/v1/pages/handbook/1.0/authoring/manual")" 200 'new public API location'
php -r '$p=json_decode(file_get_contents($argv[1]),true);if(!preg_match("/^[a-f0-9]{32}$/",$p["id"])||isset($p["link_base"]))exit(1);' "$work/body"
assert "$(status "$base/api/v1/pages/handbook/1.0/authoring/manual/private")" 404 'draft descendant remains private'
assert "$(status "$base/api/v1/pages/handbook/1.0/welcome/private")" 404 'private old path has no redirect'
assert "$(status "$base/api/v1/pages")" 200 'public catalog uses new hierarchy'
php -r '$p=json_decode(file_get_contents($argv[1]),true);if(array_keys($p)!==["handbook/1.0/getting-started","handbook/1.0/authoring","handbook/1.0/authoring/manual","handbook/1.0/authoring/manual/child"])exit(1);' "$work/body"
assert "$(status "${auth[@]}" "$base/studio?new_under=handbook%2F1.0%2Fauthoring%2Fmanual")" 200 'child creation form'
grep -q 'name="path" value="authoring/manual/new-page"' "$work/body"
assert "$(status "$base/assets/page-tree.js")" 200 'drag-and-drop asset served'
