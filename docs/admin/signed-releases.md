# Signed release pipeline (1.0.0)

## Trust and release prerequisites

The Book project publishes two Linux/amd64 images in GHCR: `ghcr.io/<owner>/<repo>` and `ghcr.io/<owner>/<repo>-postgres-pitr`. The second image contains PostgreSQL 16 and pgBackRest. The pipeline uses the exact candidate digests for the release; the other images referenced in Compose (Redis, nginx, Caddy, Grafana, etc.) remain upstream dependencies and are not signed by Book. The source distribution is a reproducible ZIP of tracked Git blobs; the deployment ZIP includes the application sources/configuration without tests or build instructions in Compose, and pins both Book-owned images by digest. Native installation still requires Composer and the Mermaid asset installation as before. Neither ZIP contains installed Composer dependencies or a database.

Repository administrators must configure, **before creating a release tag**:

1. A protected `main` branch with required review and passing `enterprise-release`; protect the `v*` tag pattern with a ruleset limiting creation, deletion and updates to release maintainers. A tag must be annotated, point to a commit on `main`, and match `VERSION` exactly. The workflow checks `GITHUB_REF_PROTECTED`, the local tag object and its commit, and checks the remote tag again before publication. A Git tag signature is optional; the release signatures are mandatory.
2. A GitHub Actions environment named `release`, requiring trusted reviewer approval, disallowing self-review where available, and allowing only protected version tags. Set GHCR package permissions for this repository, allow its `GITHUB_TOKEN` to publish both packages, and make the packages public if the release is public. Enable immutable GitHub Releases if available. Restrict other principals from rewriting version tags or packages. Candidate tags are not releases and may be visible before approval.
3. An independently controlled Ed25519 release signing key. Generate it on a trusted workstation, not in the checkout:

```sh
umask 077
openssl genpkey -algorithm ED25519 -out /secure/book-release-private.pem
openssl pkey -in /secure/book-release-private.pem -pubout -out /secure/book-release-public.pem
openssl pkey -pubin -in /secure/book-release-public.pem -outform DER | openssl dgst -sha256
```

   Set environment secret `BOOK_RELEASE_PRIVATE_KEY_B64` to the base64-encoded PEM private key (for example `base64 -w0 /secure/book-release-private.pem` on Linux); set environment variable `BOOK_RELEASE_PUBLIC_KEY_B64` to the base64-encoded PEM public key. Use the GitHub UI or a trusted secret manager, not a shell command containing the secret value. The key must not be committed or supplied to build jobs. Distribute the public key and fingerprint independently of release assets, for example through your trusted organization's security documentation and established administrator channels. The release verifies against this pinned public key, not against a key supplied by the download. Keep the private key encrypted at rest offline, with a protected recovery copy; revoke/replace the environment secret when compromised.
4. Install/allow GitHub OIDC and Sigstore Fulcio/Rekor connectivity for keyless Cosign OCI signing. The signing certificate must have exact identity `https://github.com/<Owner>/<Repo>/.github/workflows/ci.yml@refs/tags/v<version>` and issuer `https://token.actions.githubusercontent.com`. Never use a wildcard identity or skip transparency-log verification. Repository name case must match the GitHub workflow identity.

## Cut a release

Merge the reviewed version bump into `main`. Check the required CI and security gates, and create a protected annotated tag (example):

```sh
git checkout main
git pull --ff-only
test "$(cat VERSION)" = 1.0.0
git tag -a v1.0.0 -m 'CajeerEngine Book 1.0.0'
git push origin refs/tags/v1.0.0
```

The tag push runs `.github/workflows/ci.yml`. Ordinary branch/PR runs do not publish. The candidate job depends on all six ordinary gates, the full `production-qualification` job and the aggregate `enterprise-release`; every result must be `success`. The candidate job has no signing key or release-publication permission. It resolves the four Docker base tags to digest references and builds production and PITR candidates with BuildKit SPDX SBOM and maximal SLSA v0.2 provenance. Only nonsecret build arguments are used. It pulls each exact digest, scans the actual runtime layers with Trivy, checks the application using the real release HTTP scenario (including persistence after restart), and runs the full destructive PITR drill against the exact PITR image. A candidate failure stops publication. The original enterprise gate additionally tests PostgreSQL/Redis, OIDC, Caddy, S3 and the other subsystems; the image qualification is not a substitute for those gates.

The separately approved signing job checks that evidence came from the same commit/run/attempt, packages the committed blobs, signs and verifies the manifest and ZIPs, signs both OCI digests with Cosign and verifies each signature. It creates a **draft** GitHub Release, downloads the assets and verifies them again, promotes the existing OCI manifests to `v<version>` without rebuilding, checks the resulting digest and remote Git tag, and then publishes the draft. It does not move a `latest` alias. Rerun all jobs after a failed attempt, not just the signing job (the run-attempt evidence must match). A pre-existing draft/release is not overwritten. If failure leaves a draft or candidate tag, inspect the logs and verify the assets/digests before deleting only the failed draft or retrying with a new reviewed version. Never overwrite an already published release. Registry and GitHub publication are not one distributed transaction; a signed image tag can exist before the GitHub release is published. The signed manifest and verified digest are the deployment authority, not the tag.

## Stable 1.0.0 evidence

The four additional signed payloads are `qualification.json`, `qualification-lifecycle.json`, `qualification-soak.json`, and `qualification-candidate.json`. They are the actual lab summary, historical lifecycle, full 24-hour load report, and exact published-candidate lifecycle, not generated summaries or fixture data. Their bytes are bound by SHA-256 in both the gate evidence and signed manifest. The verifier checks every 60-second window, operation totals and SLOs, resource and maintenance evidence, recovery time/data-loss limits, candidate refs, and the fingerprint of the signed source tree's qualification inputs. It also compares the deployment ZIP with the source transform using the signed OCI digests and checks schema metadata. The 24-hour workload is a source-based PHP CLI server lab run; the separately tested OCI candidates undergo HTTP, scanning and lifecycle/PITR checks, not a second 24-hour load test. Lab reports can reveal host characteristics and disposable local identifiers; use an isolated non-production host. These checks establish the release qualification policy, not independent auditor certification or a guarantee for every production environment.

The 1.0.0 verifier accepts historical package layouts. The older 0.9.1 verifier/updater rejects the new artifact set; install trusted 1.0.0 host tools as described in [secure updates](secure-updates.md) before downloading a stable release. No schema or updater-state migration is required.

## Assets and verification

The asset set includes `source.zip`, `deployment.zip`, detached `source.zip.sig`, `deployment.zip.sig`, `release.json` and `release.json.sig` (raw Ed25519 signatures), `SHA256SUMS`, both images' `*-sbom.spdx.json`, `*-provenance.json`, `*-build-metadata.json`, `*-scan.json`, `*-smoke.log`, and `gate-evidence.json`. The signed manifest binds every payload's SHA-256/length, the source commit, repository, schema version, release version and exact OCI digests. The build provenance is the actual BuildKit predicate, not a fabricated SLSA certification. The OCI index digest binds the embedded BuildKit attestations; Cosign signs that digest. The exported SBOM/provenance files are also bound by the Ed25519 manifest.

On a trusted workstation, fetch the assets into a new directory using the official release URL or `gh release download v1.0.0 --repo OWNER/REPO --dir /tmp/book-release`. The public key and expected repository/version must come from independent trusted configuration. With a previously trusted copy of the verification CLI:

```sh
python3 bin/release-package.py verify \
  --directory /tmp/book-release \
  --public-key /secure/book-release-public.pem \
  --repository OWNER/REPO --version 1.0.0 --oci
```

`--oci` requires Cosign and access to the registry and Sigstore verification material; without it, verification is offline and checks ZIPs, signed manifest, evidence and checksums only, not the live OCI signature. An optional `--commit <40-hex-sha>` pins the expected commit as well. No artifact is extracted by the verifier. Any failed signature, missing artifact, incorrect size/hash, wrong repository/version/commit, inconsistent evidence, or invalid image reference fails nonzero.

If bootstrapping the verifier, first verify the downloaded source ZIP directly using a trusted OpenSSL installation and your independently obtained public key:

```sh
openssl pkeyutl -verify -rawin -pubin \
  -inkey /secure/book-release-public.pem \
  -in /tmp/book-release/source.zip \
  -sigfile /tmp/book-release/source.zip.sig
```

Only after successful verification extract to a new private directory, run its verifier as above, and confirm the expected version/repository. Do not execute an unverified downloaded verifier or trust a public key included with a release. To verify a single image directly use `cosign verify --certificate-identity 'https://github.com/OWNER/REPO/.github/workflows/ci.yml@refs/tags/v1.0.0' --certificate-oidc-issuer 'https://token.actions.githubusercontent.com' 'ghcr.io/owner/repo@sha256:<digest-from-verified-manifest>'`.

After both checks succeed, unpack `deployment.zip` into a new release directory, use the usual Compose overlays and existing protected environment/data volumes. Run `docker compose config --quiet`, `docker compose pull`, and the documented pre-upgrade/health checks. Use `docker compose up -d --no-build` (not `--build`). The deployment Compose files refer to the verified digests for Book services and PITR PostgreSQL; a private registry requires login. Maintain the previous release/data backup for rollback. Database schema remains 3. Use the [secure updater](secure-updates.md) for managed deployments and replay/downgrade protection. Never delete the existing PostgreSQL/media volumes.

## Key rotation and incident response

Generate a new Ed25519 pair on a trusted host, distribute the new public key/fingerprint independently before switching the protected environment secret and variable. Publish a new reviewed release using the new key. Historical releases must retain their original independently trusted public key; do not silently replace historical keys or re-sign historical releases with a new key. If the old key is compromised, explicitly communicate revocation and affected versions, disable publishing, rotate credentials and review GitHub/registry audit logs. Do not accept a key solely because it appears in the release manifest. OIDC OCI signatures use a short-lived identity certificate and transparency log rather than the ZIP key; a compromised workflow/repository requires reviewing protected rules and trust policy, not merely rotating Ed25519.

## Local validation and limits

`python3 tests/release-signing.py` runs real Git, ZIP, OpenSSL Ed25519 signing/verification and tampering tests using disposable keys and synthetic gate metadata. These are test fixtures, **not** successful production CI evidence. `bin/release-build.sh` and `bin/release-publish.sh` are the executable hosted pipeline; they require the configured GitHub context and tools. Test registry publication with a disposable repository and distinct signing keys before a real release. No official signatures, package uploads or successful OCI build are implied by installing this code. Build base digests and Composer lock are fixed within a candidate; OS package downloads, Mermaid npm packaging and runner/action versions mean independent rebuilds are not guaranteed byte-identical. The pipeline verifies the single built digest instead of assuming a reproducible rebuild. The full CI, security scans, PITR drill and the separate 24-hour load qualification must still be successfully executed before claiming production readiness.
