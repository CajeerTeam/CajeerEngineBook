# CajeerEngine Book 1.1.2 — GitHub Update Discovery

## Requirements and trust model

The existing `bin/update.py` manages **one locally enrolled Docker Compose installation**. It must be run on the trusted Linux Docker host, as root, with Python 3.11+, OpenSSL, a dedicated root-owned state directory (`0700`), and the public Ed25519 key pinned at `enroll`. The installed version in the private updater state is authoritative, **not** a public `main` branch, arbitrary tag, or GitHub `latest` alias.

Update discovery is read-only with respect to application containers, source files, data, images, and the installation receipt. It writes only `github-discovery-cache.json` (atomic, mode `0600`) to the updater's private state directory. This file holds public GitHub Releases response data and ETags, **not** authentication tokens or application secrets. The cache is bound to the enrolled repository and fails closed if corrupted, replaced by a symlink, or has unsafe permissions. No cron/timer or web endpoint is created in 1.1.2.

## Check GitHub releases

```sh
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater check --channel stable

# For a fresh API response rather than a conditional ETag request:
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater check --channel stable --refresh

# Include beta tags in discovery (not installable yet):
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater check --channel beta
```

Use the reviewed `update.py`, `update-discovery.py`, and `release-package.py` from the same authenticated tool version, located **outside** the mutable managed release tree. For existing installations, upgrade these three host tools using the independently verified signed source ZIP and the [secure updater procedure](secure-updates.md) before checking new releases. Never replace trusted host tools with scripts from an unverified asset.

The public repository does not require a token. Optionally pass `GITHUB_TOKEN` or `GH_TOKEN` via an operator-managed, short-lived secret environment (never in CLI args, logs or Git), for additional API quota. Only `api.github.com` receives the token. API redirects, ambient HTTP proxies, duplicate JSON fields and oversized responses are refused; GitHub 404 and rate-limit errors are **not** interpreted as 'no update'. The client uses the GitHub REST API endpoint `/repos/OWNER/REPO/releases?per_page=100&page=N`, with bounded pagination and `If-None-Match` conditional requests.

## Output and acceptance

JSON contains `status`, `installed`, `highest`, `channel`, `candidate`, `rejected`, and `checked_releases`. `candidate` includes version, Git commit, schema, changelog, publication date, signed manifest digest, and whether offline SQL preflight with explicit migration approval is required.

- `available`: a strictly newer stable release with a verified Ed25519 manifest signed by the enrollment key, matching GitHub asset names, upload states, sizes and SHA-256 values when supplied. It is **not yet safe to install** until the complete signed bundle and OCI identities have passed the existing updater's `fetch` and `preflight` checks.
- `up_to_date`: no newer stable release appears in the inspected pages.
- `unsupported_prerelease`: the beta channel shows a newer SemVer prerelease, but the signed bundle format and updater require three-component stable versions. The UI/CLI must not offer installation.
- `incomplete`: the bounded scan hit its three-page GitHub limit without an eligible signed candidate. Do not infer that the installation is current; narrow the release history or use explicit-version verification.
- `rejected`: newer releases exist but fail signature/inventory/schema/high-water checks. Treat as an error; do not bypass signatures or install a tag manually.
- `blocked`: there is a pending recovery journal or the pinned environment file changed. Resolve the issue before installing.

`rejected`, `blocked` and `incomplete` exit with status **2**; network, parsing, authentication, or local integrity errors exit with status **1**. `available`, `up_to_date`, and `unsupported_prerelease` exit with **0**. `--refresh` bypasses cached ETags but never disables signature verification.

The signed manifest is fetched over restricted HTTPS from `github.com` plus GitHub-owned redirect asset hosts, and authenticated with the enrollment key; asset ZIPs and Docker images are **not downloaded** by `check`. Full ZIP signatures, embedded migration compatibility, OCI Cosign identity, live environment compatibility, backups, deployment, and rollback remain the responsibility of existing `fetch`, `preflight`, `apply`, and `recover`. Do not treat this discovery result alone as a production qualification.

## Upgrade workflow

Once a verified GitHub Release with assets is actually published and `check` returns an appropriate stable candidate, perform the existing operator-controlled workflow:

```sh
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater fetch --version 1.1.2
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater preflight --version 1.1.2
# Block public ingress and all external writers, follow secure-updates.md:
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater apply --version 1.1.2 --offline
```

`1.1.2` above is illustrative: use the returned actual signed candidate version and approved maintenance procedure. If `requires_schema_upgrade` is true, review the migration and use the updater's explicit `--allow-schema-upgrade` policy only after the offline preflight and backup are verified. Never automatically pass the flag.

## Tests

`python3 tests/update-discovery-112.py` exercises actual Ed25519 signature verification against test-generated keys, GitHub API 200/304/403/404/rate limits, ETag reuse, malicious JSON, unsigned/incomplete assets, SemVer beta, schema and downgrade restrictions, and private cache handling. It is required by Composer, the release gate, and protected publication CI. Real API connectivity, a deployed Docker host, and an official signed v1.1.2 release have **not** been asserted by the local test suite.
