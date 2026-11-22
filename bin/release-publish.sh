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
readarray -t digests < <(python3 - "$evidence/gate-evidence.json" <<'PY'
import json,os,sys
v=json.load(open(sys.argv[1])); env=os.environ
if v['run_id']!=env['GITHUB_RUN_ID'] or v['run_attempt']!=env['GITHUB_RUN_ATTEMPT'] or v['commit']!=env['GITHUB_SHA']: raise SystemExit('Candidate evidence belongs to a different run')
for r in ('book','postgres-pitr'): print(v['images'][r].rsplit('@',1)[1])
PY
)
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
python3 bin/release-github.py absent --repository "$GITHUB_REPOSITORY" --version "$version"
for ref in "${refs[@]}"; do
  cosign sign --yes "$ref"
  cosign verify --certificate-identity "$identity" --certificate-oidc-issuer "$issuer" "$ref" >/dev/null
done
python3 bin/release-package.py verify --directory "$output" --public-key "$work/public.pem" --repository "$GITHUB_REPOSITORY" --version "$version" --commit "$GITHUB_SHA" --oci >/dev/null
check_tag
# Draft + readback protects against publishing an incomplete or corrupted asset set.
gh release create "$tag" "$output"/* --repo "$GITHUB_REPOSITORY" --verify-tag --draft --latest=false --title "CajeerEngine Book $version" --notes "Signed distribution. Verify release.json and ZIP signatures against the independently distributed Ed25519 public key. Deploy only verified OCI digests. See docs/admin/signed-releases.md."
python3 bin/release-github.py verify --repository "$GITHUB_REPOSITORY" --version "$version" --directory "$output" --state draft
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
# Audit the *published* release, not only the draft. Re-download the public
# assets into a clean directory and authenticate their bytes and OCI references.
# The remote asset inventory is checked before and after download so that
# missing, pending, duplicated and mutated assets fail the publication job.
check_tag
python3 bin/release-github.py verify --repository "$GITHUB_REPOSITORY" --version "$version" --directory "$output" --state published
mkdir "$work/published-readback"
gh release download "$tag" --repo "$GITHUB_REPOSITORY" --dir "$work/published-readback"
python3 bin/release-package.py verify --directory "$work/published-readback" --public-key "$work/public.pem" --repository "$GITHUB_REPOSITORY" --version "$version" --commit "$GITHUB_SHA" --oci >/dev/null
python3 bin/release-github.py verify --repository "$GITHUB_REPOSITORY" --version "$version" --directory "$work/published-readback" --state published
check_tag
