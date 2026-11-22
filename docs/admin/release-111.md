# 1.1.1 — GitHub Release Infrastructure

## Scope

The update is to the **existing executable** GitHub Actions → candidate OCI → Ed25519 release-package → GitHub Release chain. No parallel updater, app-level root access, auto-installation or web control panel is introduced. PostgreSQL remains at schema 7; embedded Engine stays 2.1.0.

## Release prerequisites

1. Merge and review version 1.1.1 on `main`. Configure branch/tag rules, annotated protected `v1.1.1` tag and protected `release` and `qualification` environments. Self-hosted 24-hour qualification must be trusted and isolated. Do not release from a pull request or unsigned checkout.
2. Configure `BOOK_RELEASE_PRIVATE_KEY_B64` (secret), `BOOK_RELEASE_PUBLIC_KEY_B64` (independently pinned variable), GitHub Actions `contents: write`, `packages: write`, `id-token: write`, GHCR authentication and Sigstore/Cosign. Sign only after regression, security, PostgreSQL, Docker, PITR, enterprise and full 24-hour qualification gates pass.
3. Keep private key and signing/publication permissions outside PR builds. Preserve and distribute the Ed25519 **public** key independently of GitHub Releases. Do not copy keys from a new/untrusted artifact.
4. Publish using the `signed-release` job of `.github/workflows/ci.yml`, not a manual ZIP upload. The job is tied to the exact protected annotated version tag and exact qualifying run/attempt.

## Real publication sequence

- `bin/release-build.sh` produces OCI images pinned by immutable SHA-256 digests, runs image/vulnerability checks, runtime HTTP tests, PITR and lifecycle verification, and writes signed-release candidate evidence.
- `bin/release-package.py assemble` builds deterministic ZIPs from Git **committed** blobs, validates qualification, checks Ed25519 private/public key matching, and signs the manifest, source ZIP and deployment ZIP.
- `bin/release-github.py absent --repository CajeerTeam/CajeerEngineBook --version 1.1.1` confirms with GitHub API that neither a draft nor a public release exists. An inaccessible repository is not misinterpreted as a missing release.
- `bin/release-publish.sh` signs/validates OCI digests with Cosign, uploads a **draft** release, checks all asset names/states/sizes and GitHub-supplied SHA-256s, downloads and verifies draft bytes, then promotes immutable OCI digest aliases without building new code.
- Only after draft validation and tag recheck does the job publish. It verifies published assets in GitHub REST metadata, downloads **fresh published copies** into a separate folder, verifies signatures, ZIP contents, qualification evidence and OCI identity, and validates the published inventory and protected tag again.
- Any failure causes the job to exit nonzero. The pipeline does **not** erase a partial draft/published release or replace a tag. Operator checks are required before a new version or reviewed recovery attempt.

## Operator verification after publishing

Download **all** GitHub assets, not a source archive alone, into a new private directory. With the independently trusted public key and matching reviewed source of the verifier:

```sh
export GH_TOKEN="<authorized-token>" # do not log or commit
mkdir -m 700 /tmp/book-111-verified
# A real v1.1.1 Release must exist for the following commands to succeed.
gh release download v1.1.1 --repo CajeerTeam/CajeerEngineBook --dir /tmp/book-111-verified
python3 bin/release-github.py verify --repository CajeerTeam/CajeerEngineBook --version 1.1.1 --directory /tmp/book-111-verified --state published
python3 bin/release-package.py verify --repository CajeerTeam/CajeerEngineBook --version 1.1.1 --directory /tmp/book-111-verified --public-key /secure/book-release-public.pem --oci
```

Use the existing `bin/update.py fetch --version 1.1.1`/`preflight`/`apply` runbook only after a valid signed package has been published and the host has been enrolled with independently trusted verification material. A bare GitHub tag, `main` HEAD or generated patch ZIP is **not** an installable signed release. Recovery and rollback remain governed by `secure-updates.md`.

## Tests and mandatory limits

- `python3 tests/release-111-github.py` runs fail-closed GitHub metadata, asset and publication-path tests with mocked API responses (never requires a live signing key).
- `python3 tests/release-signing.py` performs real OpenSSL Ed25519 and Git/ZIP integration with synthetic qualification fixtures. The native Actions gate and Composer suite run these tests, and the privileged release job repeats 1.1.1 policy tests.
- Real GHCR registry tagging, GitHub API uploads and post-publish reads, the dedicated load host and signing environment must pass on protected CI. Without that run, do not claim that v1.1.1 has been published or accepted for production.
