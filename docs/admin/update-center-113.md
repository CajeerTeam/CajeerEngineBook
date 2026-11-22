# Admin Update Center — CajeerEngine Book 1.1.3

## Scope

`/admin/updates` is a **real, read-only discovery and authenticated candidate-metadata preparation UI**. It is reachable only by the installation owner, not an organization/workspace Admin. It uses the same local owner session, same-origin form checks, explicit CSRF token, per-owner durable rate limits and identity activity journal as the rest of the control plane. Only signed, published GitHub Releases from **CajeerTeam/CajeerEngineBook** are considered stable update candidates. Source code on `main` and unsigned Git tags are not installable releases.

**No installation is performed from the PHP process in 1.1.3.** This is intentional: `bin/update.py` is root-owned, holds Docker privileges, requires offline maintenance fencing and validates entire ZIPs, OCI image signatures, runtime topology and backups. The future host update agent (1.1.4) must expose a deliberately constrained local control channel. It is unsafe to grant the Book application access to Docker socket, host root or arbitrary commands.

## Deployment setup

1. Verify the release publisher's Ed25519 public key through an independent, trusted channel. **Do not obtain or replace it automatically from a GitHub Release.** Keep the trusted original for the host updater.
2. Mount a readable **public-only** PEM key into the Book container or host PHP runtime; the private signing key must never be on the application server. For example, from the Docker host add a bind mount to `/run/book-trust/release-public.pem` and set `BOOK_UPDATE_PUBLIC_KEY_FILE=/run/book-trust/release-public.pem` in the operator-controlled environment. Ensure the key exists in each Book replica, is a regular file and is not a symlink. Changes to environment/Compose topology require the existing signed-updater reconciliation procedure before the next upgrade.
3. Verify PHP has `ext-sodium` enabled. The public signing key must be Ed25519 SubjectPublicKeyInfo PEM. Keep `BOOK_DATA_DIR` private, writable only by the Book runtime identity, and backed up according to the installation policy.
4. Sign in as the installation owner, open **Studio → System Updates** or visit `/admin/updates`, and choose **Check GitHub releases**. To pin the result, select **Pin verified candidate for host preparation**. The pin stores **metadata only**, not source/deployment archives, images or a trusted host-side authorization token.

All data is written atomically under `$BOOK_DATA_DIR/update-center/`, separated from Workspace state. `latest.json` records the last check and `prepared.json` the last candidate pin. Both are advisory app-owned JSON metadata; the host updater must not trust them as executable package authorization.

## Trust and verification

The UI requests only fixed URLs from `api.github.com` and `github.com` over HTTPS; GitHub API redirects are refused; release asset redirects are followed only to allowlisted GitHub-owned HTTPS hosts. HTTP errors, missing trust key, invalid Ed25519 signature, wrong repository/version/commit/fingerprint, absent/unready assets, bad signed sizes or checksums, invalid OCI references or a schema downgrade fail closed. It checks the **first 100 published releases**; a full page without a suitable candidate is marked incomplete, never “up to date.” The `beta` option displays prerelease tags but cannot prepare them because `release-package.py`/`update.py` support stable numbered packages only.

The GitHub API may omit asset SHA-256 digests; such digests are committed in the authenticated `release.json` and checked against the downloaded bytes **later by `update.py fetch`**. Candidate inspection does not download large ZIPs and therefore cannot attest to their actual bytes, their ZIP signatures, Cosign OCI signatures or successful production gates beyond what the signed manifest asserts. Do not confuse a pinned candidate with a staged or installed release.

## Completing the upgrade

On the trusted Docker host, using the **existing enrolled** updater state and outside the application/web process:

```bash
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater status
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater check --channel stable
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater fetch --version 1.1.3
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater preflight --version 1.1.3
# After placing the site into maintenance mode and fencing all external writers:
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater apply --version 1.1.3 --offline
```

Replace the example version with the **actually published, signed** candidate. An uploaded code patch is not a signed release. `fetch`, `preflight` and `apply` reverify all signed package and OCI assets, database migration compatibility, image/volume inventory and backups. The owner must use [secure updates](secure-updates.md) and the [disaster recovery](disaster-recovery.md) procedure, especially for failed activation or irreversible schema changes.

## Checks

```bash
php tests/update-center-113.php
php tests/update-center-access-113.php
php tests/update-center-controller-113.php
python3 tests/admin-docs.py
```

These standalone tests use real Ed25519 keys with a fake bounded HTTP transport; they do not require GitHub credentials or install releases. Live GitHub connectivity, a real enrolled Docker host, complete signed release qualification and full production CI remain separate mandatory acceptance gates.
