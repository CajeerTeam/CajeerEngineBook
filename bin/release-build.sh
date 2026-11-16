#!/usr/bin/env bash
# Build candidate OCI digests, then qualify those exact bytes. Never publish a release here.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${GITHUB_SHA:?}" "${GITHUB_REPOSITORY:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${GITHUB_REF:?}" "${GITHUB_REF_PROTECTED:?}" "${GATE_RESULTS:?}"
report=${1:?new evidence directory}
qualification=${2:?full production qualification report}
version=$(cat VERSION)
[[ "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ && "$GITHUB_REF" == "refs/tags/v$version" && "$GITHUB_REF_PROTECTED" == true ]]
[[ $(git rev-parse HEAD) == "$GITHUB_SHA" && $(git cat-file -t "$GITHUB_REF") == tag && $(git rev-parse "$GITHUB_REF^{commit}") == "$GITHUB_SHA" ]]
git merge-base --is-ancestor "$GITHUB_SHA" refs/remotes/origin/main
git diff --quiet HEAD --
mkdir -m 700 "$report"; report=$(cd "$report" && pwd)
python3 - <<'PY'
import json,os
n=json.loads(os.environ['GATE_RESULTS']); expected={'test','postgres-content','docker-release','distributed-release','domains-edge','security-scan','enterprise-release','production-qualification'}
if set(n)!=expected or any(v['result']!='success' for v in n.values()): raise SystemExit('Release gates not passed')
PY
# No ambient runtime credentials in the disposable qualification processes.
for k in $(env | cut -d= -f1 | grep -E '^(BOOK_|AWS_|PG|REDIS_|COMPOSE_)' || :); do unset "$k"; done
export COMPOSE_DISABLE_ENV_FILE=1
unset PYTHONOPTIMIZE
repo="ghcr.io/${GITHUB_REPOSITORY,,}"
candidate="candidate-${GITHUB_SHA}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
work=$(mktemp -d); container="book-release-$(openssl rand -hex 8)"
cleanup(){ docker rm -fv "$container" >/dev/null 2>&1 || :; rm -rf "$work"; }
trap cleanup EXIT
trap 'exit 130' INT TERM
resolve(){ local tag=$1 d; d=$(docker buildx imagetools inspect "$tag" | awk '$1=="Digest:" && !seen++ {print $2}'); [[ "$d" =~ ^sha256:[0-9a-f]{64}$ ]]; printf '%s@%s' "$tag" "$d"; }
node=$(resolve node:22-bookworm-slim); composer=$(resolve composer:2); phpbase=$(resolve php:8.4-apache-bookworm); pg=$(resolve postgres:16-bookworm)
export BUILDX_GIT_LABELS=1 BUILDX_GIT_INFO=1 BUILDX_METADATA_PROVENANCE=max
build(){
  local role=$1 image=$2; shift 2
  docker buildx build --platform linux/amd64 --push --provenance=mode=max --sbom=true \
    --label "org.opencontainers.image.version=$version" --label "org.opencontainers.image.revision=$GITHUB_SHA" \
    --label "org.opencontainers.image.source=https://github.com/$GITHUB_REPOSITORY" \
    --metadata-file "$report/$role-build-metadata.json" --tag "$image:$candidate" "$@" .
  local digest ref
  digest=$(python3 -c 'import json,sys,re; d=json.load(open(sys.argv[1]))["containerimage.digest"]; assert re.fullmatch(r"sha256:[0-9a-f]{64}",d); print(d)' "$report/$role-build-metadata.json")
  ref="$image@$digest"
  docker buildx imagetools inspect "$ref" --format '{{ json .SBOM.SPDX }}' > "$report/$role-sbom.spdx.json"
  docker buildx imagetools inspect "$ref" --format '{{ json .Provenance.SLSA }}' > "$report/$role-provenance.json"
  docker pull --platform linux/amd64 "$ref"
  docker tag "$ref" "$image:$candidate"
  docker save -o "$work/image.tar" "$image:$candidate"
  docker run --rm -v "$work:/scan:ro" -v "$report:/report" aquasec/trivy:0.69.3 image --input /scan/image.tar --scanners vuln,secret --severity HIGH,CRITICAL --exit-code 1 --format json --output "/report/$role-scan.json"
  rm "$work/image.tar"
  printf '%s\n' "$ref" > "$work/$role.ref"
}
build book "$repo" --target production --build-arg "NODE_BASE=$node" --build-arg "COMPOSER_BASE=$composer" --build-arg "PHP_BASE=$phpbase"
build postgres-pitr "$repo-postgres-pitr" -f deploy/pitr/Dockerfile --build-arg "POSTGRES_BASE=$pg"
book=$(cat "$work/book.ref"); pitr=$(cat "$work/postgres-pitr.ref")
# Verify version, production-only filesystem, then the actual published candidate via HTTP.
{
  docker run --rm --entrypoint sh "$book" -ec 'test ! -e /opt/cajeerbook/tests; test "$(cat VERSION)" = "$1"; test -f vendor/autoload.php; test -s public/assets/mermaid/mermaid.min.js' sh "$version"
  hash=$(docker run --rm --entrypoint php "$book" -r 'echo password_hash("release-test-only-password", PASSWORD_DEFAULT);')
  docker run -d --name "$container" -p 127.0.0.1::80 -e BOOK_SECURE_COOKIE=0 -e BOOK_ADMIN_HASH="$hash" "$book"
  port=$(docker port "$container" 80/tcp | sed 's/.*://')
  export BOOK_TEST_URL="http://127.0.0.1:$port"
  for i in $(seq 1 120); do if curl -fsS "$BOOK_TEST_URL/health/ready" >/dev/null; then break; fi; sleep 1; done
  curl -fsS "$BOOK_TEST_URL/health/ready"
  python3 tests/release-http.py
  docker restart "$container"
  for i in $(seq 1 120); do if curl -fsS "$BOOK_TEST_URL/health/ready" >/dev/null; then break; fi; sleep 1; done
  python3 tests/release-http.py --check-persisted
  docker rm -fv "$container"
} > "$report/book-smoke.log" 2>&1
unset BOOK_TEST_URL
# The existing destructive source-loss gate must run with the exact PITR image.
BOOK_RELEASE_PITR_IMAGE="$pitr" bash tests/pitr-gate.sh > "$report/postgres-pitr-smoke.log" 2>&1 || { tail -100 "$report/postgres-pitr-smoke.log"; exit 1; }
# Test the exact pulled candidates across a historical upgrade and physical disaster recovery.
sudo -n python3 tests/production-lifecycle.py --report-dir "$work/candidate-lifecycle" \
  --candidate-book "$book" --candidate-pitr "$pitr" >> "$report/book-smoke.log" 2>&1 || { tail -60 "$report/book-smoke.log"; exit 1; }
cat "$work/candidate-lifecycle/lifecycle.json" >> "$report/book-smoke.log"
export RELEASE_QUALIFICATION="$qualification" RELEASE_CANDIDATE_LIFECYCLE="$work/candidate-lifecycle/lifecycle.json"
export RELEASE_BOOK_REF="$book" RELEASE_PITR_REF="$pitr" RELEASE_VERSION="$version" RELEASE_REPORT="$report"
python3 - <<'PY'
import os,json,pathlib,importlib.util
v=os.environ
spec=importlib.util.spec_from_file_location("qualification",pathlib.Path("tests/production-qualification.py")); q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
out={'version':v['RELEASE_VERSION'],'commit':v['GITHUB_SHA'],'repository':v['GITHUB_REPOSITORY'],'run_id':v['GITHUB_RUN_ID'],'run_attempt':v['GITHUB_RUN_ATTEMPT'],'gates':{k:r['result'] for k,r in json.loads(v['GATE_RESULTS']).items()},'images':{'book':v['RELEASE_BOOK_REF'],'postgres-pitr':v['RELEASE_PITR_REF']},'image_checks':{r:{'smoke':'success','scan':'success'} for r in ('book','postgres-pitr')}}
out['production_qualification']=q.release_evidence(v['RELEASE_QUALIFICATION'],v['RELEASE_CANDIDATE_LIFECYCLE'],out['images'],out['commit'],out['version'])
import shutil
report=pathlib.Path(v['RELEASE_REPORT']); summary=pathlib.Path(v['RELEASE_QUALIFICATION'])
for name,path in {'qualification.json':summary,'qualification-lifecycle.json':summary.parent/'lifecycle/lifecycle.json','qualification-soak.json':summary.parent/'load/qualification/report.json','qualification-candidate.json':pathlib.Path(v['RELEASE_CANDIDATE_LIFECYCLE'])}.items():
    shutil.copyfile(path,report/name)
(report/'gate-evidence.json').write_text(json.dumps(out,indent=2)+'\n')
PY
