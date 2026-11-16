# Signed release pipeline (1.0.0)

## v1.0.0 hosted release assurance — important

The protected `v1.0.0` release runs **entirely on GitHub-hosted Ubuntu runners**. There is no `book-load` runner dependency in `.github/workflows/ci.yml`; ordinary gates, the exact-candidate OCI image scans, S3/SQL/Redis/HTTP tests, an actual historical upgrade/rollback and PITR lifecycle, Ed25519 signatures, and keyless Cosign OCI signatures remain mandatory. `release-candidate` also requires the same commit/run/attempt evidence and tests both candidate image digests.

**The independent 24-hour, 100,000-page production qualification is NOT RUN and the release is NOT `lab-qualified`.** Its previous mandatory self-hosted job is not replaced with artificial counters, fake summaries, synthetic 24-hour data, or a shorter test labelled as equivalent. The released signed `release.json` declares `release_profile: hosted-ci` and `production_qualification.status: not-run`. Its signed `gate-evidence.json` repeats the exact non-run disclosure (`soak_seconds: 0`, `soak_requests: 0`, `minute_windows: 0`) and binds the actual `qualification-candidate.json` from the tested OCI images. The validator rejects missing disclosure, forged success claims, tampered candidate output, changed image digests and failed SLOs. This hosted release profile is **explicitly restricted to version 1.0.0**; subsequent versions require an intentionally reviewed policy change.

A release can be officially signed and published as **CI-qualified** without claiming 24-hour production qualification. It must not be described as load-qualified or independently security-certified. The optional manual `.github/workflows/load-qualification.yml` still uses a dedicated self-hosted `book-load` host and does not influence this release pipeline; running it later does **not** retroactively upgrade the immutable v1.0.0 manifest.

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

## Cut a hosted CI-qualified v1.0.0 release

Merge the reviewed pipeline changes into protected `main` and wait for all six functional/security gates plus `enterprise-release` to pass **for the new release commit**. Set up the `release` environment (approved reviewers, Ed25519 secret and public variable), protect annotated `v*` tags, and grant GHCR write access. No `qualification` environment or self-hosted runner is required for this hosted profile. Create the protected annotated tag only after the exact release commit is approved:

```sh
git checkout main
git pull --ff-only origin main
test "$(cat VERSION)" = 1.0.0
git tag -a v1.0.0 -m 'CajeerEngineBook 1.0.0 — CI-qualified, 24h not run'
git push origin refs/tags/v1.0.0
```

A protected tag push runs `.github/workflows/ci.yml`. It requires six existing hosted gates and `enterprise-release`, all marked success. `release-candidate` builds/scan-tests the exact two OCI images with BuildKit SBOM/provenance and Trivy HIGH/CRITICAL enforcement, invokes real HTTP persistence/PITR and historical recovery on those pulled candidates, and uploads only real measured `qualification-candidate.json` with the fixed not-run disclosure. The separately approved `signed-release` job verifies the evidence and cryptographic signatures, creates a GitHub Release draft, reads back and verifies assets, promotes immutable digest-matched OCI images, and publishes it. A failed check stops publication. No auto-generated 24-hour evidence is accepted.

A draft left by an interrupted run must be investigated; no published release is overwritten. Re-run all jobs after a failed attempt to preserve run-attempt evidence consistency. GHCR publishing and GitHub publishing are not atomic; a candidate tag or signed image may temporarily exist without a release. Do not treat candidate tags as approved production artifacts.

## Stable 1.0.0 evidence

For the `hosted-ci` profile, the required signed payload is `qualification-candidate.json`: the **real** historical lifecycle of the exact built OCI candidates, including image identity, content/media checks, rollback and recovery RPO/RTO. `qualification.json`, `qualification-lifecycle.json` and `qualification-soak.json` **do not exist** in a hosted release and must not be fabricated. The 24h absence is stated in `release.json`, `gate-evidence.json`, the release title and the GitHub Release notes. Validators retain their strict `full-production` verification route for real evidence, but the current release workflow generates only `hosted-ci` for 1.0.0.

The release still validates the signed source transform, source fingerprint, schema, and exact OCI image digests. The six existing gates test Docker/SQL/Redis/S3/domains/security. The independent 24-hour workload and all 1,440 minute windows are not tested by the hosted release.

## Assets and verification

The `hosted-ci` asset set includes the real `qualification-candidate.json` but **not** the three missing full qualification reports. Other assets include `source.zip`, `deployment.zip`, detached `source.zip.sig`, `deployment.zip.sig`, `release.json` and `release.json.sig` (raw Ed25519 signatures), `SHA256SUMS`, both images' `*-sbom.spdx.json`, `*-provenance.json`, `*-build-metadata.json`, `*-scan.json`, `*-smoke.log`, and `gate-evidence.json`. The signed manifest binds every payload's SHA-256/length, the source commit, repository, schema version, release version and exact OCI digests. The build provenance is the actual BuildKit predicate, not a fabricated SLSA certification. The OCI index digest binds the embedded BuildKit attestations; Cosign signs that digest. The exported SBOM/provenance files are also bound by the Ed25519 manifest.

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

`python3 tests/release-signing.py` runs real Git, ZIP, OpenSSL Ed25519 signing/verification and tampering tests using disposable keys and synthetic gate metadata. These are test fixtures, **not** successful production CI evidence. `bin/release-build.sh` and `bin/release-publish.sh` are the executable hosted pipeline; they require the configured GitHub context and tools. Test registry publication with a disposable repository and distinct signing keys before a real release. No official signatures, package uploads or successful OCI build are implied by installing this code. Build base digests and Composer lock are fixed within a candidate; OS package downloads, Mermaid npm packaging and runner/action versions mean independent rebuilds are not guaranteed byte-identical. The pipeline verifies the single built digest instead of assuming a reproducible rebuild. The full CI, security scans and PITR drill remain mandatory. The independent 24-hour load qualification must still be executed and verified separately before claiming 24-hour/lab-qualified production readiness; it is never implied by a hosted release.
