#!/usr/bin/env bash
# Only the protected release job receives signing material and publication permissions.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${GITHUB_SHA:?}" "${GITHUB_REPOSITORY:?}" "${GITHUB_REF:?}" "${GITHUB_REF_PROTECTED:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${BOOK_RELEASE_PRIVATE_KEY_B64:?}" "${BOOK_RELEASE_PUBLIC_KEY_B64:?}" "${GH_TOKEN:?}"
evidence=${1:?candidate evidence directory}; output=${2:?new output directory}
version=$(cat VERSION); tag="v$version"
[[ "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ && "$GITHUB_REF" == "refs/tags/$tag" && "$GITHUB_REF_PROTECTED" == true ]]
[[ $(git rev-parse HEAD) == "$GITHUB_SHA" && $(git cat-file -t "$GITHUB_REF") == tag && $(git rev-parse "$GITHUB_REF^{commit}") == "$GITHUB_SHA" ]]
git merge-base --is-ancestor "$GITHUB_SHA" refs/remotes/origin/main
umask 077
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
printf '%s' "$BOOK_RELEASE_PRIVATE_KEY_B64" | base64 -d > "$work/private.pem"
printf '%s' "$BOOK_RELEASE_PUBLIC_KEY_B64" | base64 -d > "$work/public.pem"
unset BOOK_RELEASE_PRIVATE_KEY_B64 BOOK_RELEASE_PUBLIC_KEY_B64
# Match the exact candidate evidence from this workflow invocation.
digest_file="$work/candidate-digests"
python3 - "$evidence/gate-evidence.json" > "$digest_file" <<'PY'
import importlib.util,os,pathlib,sys
spec=importlib.util.spec_from_file_location('release',pathlib.Path('bin/release-package.py')); r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
v=r.json_read(pathlib.Path(sys.argv[1])); env=os.environ
if v['run_id']!=env['GITHUB_RUN_ID'] or v['run_attempt']!=env['GITHUB_RUN_ATTEMPT'] or v['commit']!=env['GITHUB_SHA'] or v.get('release_profile')!=r.HOSTED_PROFILE:
    raise SystemExit('Candidate evidence is not this hosted release run')
if v.get('production_qualification')!=r.HOSTED_NO_24H:
    raise SystemExit('Missing honest 24-hour qualification disclosure')
for role in ('book','postgres-pitr'): print(v['images'][role].rsplit('@',1)[1])
PY
mapfile -t digests < "$digest_file"
[[ ${#digests[@]} == 2 ]]
python3 bin/release-package.py assemble --output "$output" --evidence "$evidence" --key "$work/private.pem" --public-key "$work/public.pem" --version "$version" --repository "$GITHUB_REPOSITORY" --commit "$GITHUB_SHA" --book-digest "${digests[0]}" --pitr-digest "${digests[1]}"
rm -f "$work/private.pem"
identity="https://github.com/$GITHUB_REPOSITORY/.github/workflows/ci.yml@refs/tags/$tag"
issuer=https://token.actions.githubusercontent.com
repo="ghcr.io/${GITHUB_REPOSITORY,,}"
refs=("$repo@${digests[0]}" "$repo-postgres-pitr@${digests[1]}")
check_tag(){
  local remote
  remote=$(gh api "repos/$GITHUB_REPOSITORY/git/ref/tags/$tag" --jq '.object.sha')
  [[ "$remote" == "$(git rev-parse "$GITHUB_REF")" ]]
}
check_tag
# Never replace an existing release. A draft left by a failed attempt requires operator review.
if gh release view "$tag" --repo "$GITHUB_REPOSITORY" >/dev/null 2>&1; then echo 'Release already exists; refusing to overwrite' >&2; exit 1; fi
for ref in "${refs[@]}"; do
  cosign sign --yes "$ref"
  cosign verify --certificate-identity "$identity" --certificate-oidc-issuer "$issuer" "$ref" >/dev/null
done
python3 bin/release-package.py verify --directory "$output" --public-key "$work/public.pem" --repository "$GITHUB_REPOSITORY" --version "$version" --commit "$GITHUB_SHA" --oci >/dev/null
check_tag
# Draft + readback protects against publishing an incomplete or corrupted asset set.
gh release create "$tag" "$output"/* --repo "$GITHUB_REPOSITORY" --verify-tag --draft --latest=false \
  --title "CajeerEngine Book $version (CI-qualified; 24h not run)" \
  --notes "**Release assurance: CI-qualified only.** The independent 24-hour, 100,000-page production qualification was **NOT RUN**; this release is **not lab-qualified**. All required hosted CI/security jobs passed; exact candidate OCI digests were scanned, smoke-tested and subjected to real historical upgrade/rollback, physical restore and recovery validation. Disclosures and measured lifecycle evidence are bound in the signed release.json, gate-evidence.json and qualification-candidate.json. Verify Ed25519 signatures against an independently trusted public key and Cosign signatures for the OCI digests. See docs/admin/signed-releases.md. Do not describe this as 24h-qualified or independently security-certified."
mkdir "$work/readback"
gh release download "$tag" --repo "$GITHUB_REPOSITORY" --dir "$work/readback"
python3 bin/release-package.py verify --directory "$work/readback" --public-key "$work/public.pem" --repository "$GITHUB_REPOSITORY" --version "$version" --commit "$GITHUB_SHA" --oci >/dev/null
for ref in "${refs[@]}"; do
  target="${ref%@*}:$tag"; expected=${ref##*@}
  # Never treat a network/authentication error as an absent tag.
  if previous=$(docker buildx imagetools inspect "$target" 2>"$work/tag-error"); then
    [[ $(awk '$1=="Digest:" && !seen++ {print $2}' <<< "$previous") == "$expected" ]]
  else
    grep -Eqi "manifest unknown|manifest_unknown|not found|404" "$work/tag-error" || { cat "$work/tag-error" >&2; exit 1; }
    docker buildx imagetools create --prefer-index=false -t "$target" "$ref"
  fi
  actual=$(docker buildx imagetools inspect "$target" | awk '$1=="Digest:" && !seen++ {print $2}')
  [[ "$actual" == "$expected" ]]
done
check_tag
gh release edit "$tag" --repo "$GITHUB_REPOSITORY" --draft=false --prerelease=false --latest=false
# Confirm the public release still identifies the exact verified assets and tag.
check_tag
gh release view "$tag" --repo "$GITHUB_REPOSITORY" --json tagName,isDraft,isPrerelease,assets > "$work/published.json"
python3 - "$work/published.json" "$output" "$tag" <<'PY'
import json, pathlib, sys
r=json.loads(pathlib.Path(sys.argv[1]).read_text()); out=pathlib.Path(sys.argv[2])
expected={p.name:p.stat().st_size for p in out.iterdir()}
assets=r['assets']; actual={a['name']:a['size'] for a in assets}
if r['tagName']!=sys.argv[3] or r['isDraft'] or r['isPrerelease'] or len(assets)!=len(expected) or actual!=expected:
    raise SystemExit('Published release metadata mismatch; operator investigation required')
PY
