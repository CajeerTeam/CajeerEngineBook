# Secure Update Manager (0.8.1)

## GitHub release discovery added in 1.1.2

For enrolled hosts, `check --channel stable` queries public GitHub Releases, verifies a candidate's Ed25519-signed manifest against the key already pinned at enrollment, and reports a compatible explicit version **without** updating the installation. It uses a bounded private ETag cache and refuses unsigned/incomplete assets. Beta discovery is informative only; this updater does not install prerelease bundle versions. Discovery does not relax mandatory full signed bundle, OCI, backup, migration or recovery checks. See [1.1.2 discovery runbook](update-discovery-112.md) for exit codes, safe operator commands and limitations.

`bin/update.py` is a host-side, operator-run updater for a **single local Docker Compose project** deployed from the signed deployment ZIP introduced in 0.8.0. It is not a web endpoint, an automatic unattended upgrade service, a cluster orchestrator or a source-tree patcher. Keep the trusted updater and its siblings `release-package.py` and `qualification_policy.py` outside the managed release trees. Never execute a verifier obtained from an unverified download. Linux, Python 3.11+, OpenSSL Ed25519, Docker Engine with local Unix socket, Compose >= 2.24.4, and Cosign compatible with the signed-release pipeline are required. The CLI requires root on the Docker host (Docker administration and UID 33 private backup mount); use a trusted administrative environment and a root-owned PATH. Registry authentication is operator-managed.

## Upgrading host tools for 1.0.0

The 1.0.0 asset set contains four additional qualification reports. A 0.9.1 updater cannot accept the new layout. Before fetching 1.0.0, obtain its source ZIP from the official release and verify the detached Ed25519 signature using your existing independently pinned key and trusted OpenSSL, as in [signed releases](signed-releases.md). Extract into a new private directory; check the expected version and repository/commit using the verified code. Install the three reviewed host tools (`update.py`, `release-package.py`, `qualification_policy.py`) together in a root-owned directory outside the managed state and deployment trees. Preserve the existing state directory, environment file, public key, high-water version and backups. Do not re-enroll or change the running installation just to update the tools. The new tools continue to verify older signed assets, including rollback bundles. Run `status`, `fetch --version 1.0.0`, and `preflight --version 1.0.0` before fencing writers and applying. If a new PostgreSQL/PITR infrastructure digest is required, follow the separate operator procedure instead of bypassing topology checks.

## Trust and scope

- Use an independently obtained Ed25519 public PEM key and the exact GitHub `OWNER/REPO`. The key is pinned at enrollment. Do not replace it from downloaded assets. No automatic key rotation: a deliberate re-enrollment/trust migration must preserve the high-water version and existing backups.
- The downloader accepts an explicit version, never `latest`. Public GitHub release assets are downloaded over verified HTTPS from `github.com`, with redirects restricted to GitHub release asset hosts. It ignores ambient HTTP proxy variables. It verifies the signed manifest before requesting the payloads, enforces signed sizes and bounded ZIP expansion, then verifies every SHA-256, both ZIP signatures, gate evidence and SBOM/provenance metadata. OCI signatures are verified against the exact repository workflow/tag identity and GitHub OIDC issuer. Local `--bundle` skips downloading release assets, **not** Cosign verification. Private GitHub repositories require a complete separately downloaded bundle and registry credentials.
- Release directories are extracted only after verification. Each use re-verifies the signed bundle and byte-for-byte deployment files. No source builds, tag pulls or shell evaluation of manifest fields. Docker pulls the signed Book digest only if it is not already present; other infrastructure images are left at their existing references. All running containers are checked against expected image IDs, volumes, bind contents, commands and relevant environment. Compose topology, project name, environment file and volume mappings must remain stable. Active one-off tasks or orphan containers block enrollment/update.
- The automated path supports the supplied base/PostgreSQL/Redis/Sentinel/worker/proxy/backup/monitoring/security overlays where the resolved Compose topology is unchanged. It **does not** change infrastructure images or settings, PostgreSQL/PITR image versions, external shared storage across hosts, or custom overlays. A new PITR image digest therefore requires a separate operator-planned PostgreSQL upgrade, not an automatic Book update. The managed PostgreSQL service is the dedicated `book` database with `book_admin` and application migration privileges; external database administration and separately provisioned migration roles require manual procedures. Do not use the updater for remote Docker or multi-host deployment.
- A maintenance fence is mandatory: before `apply`, `rollback` or `recover`, block public ingress and all direct/local clients, stop external writers, scheduled PITR cycles and external workers sharing the database/storage. Keep the fence until the command succeeds and the operator checks the service. The updater stops the local edge and all local application/monitoring services, leaving PostgreSQL and Redis running; it cannot fence external writers. No mixed-version rolling upgrade.

## Enrollment and commands

Prepare an existing running installation from an official signed `deployment.zip`. Use the same Compose project and explicitly ordered overlays you will manage. Existing source-built installations must first be migrated by the operator to the signed deployment, with a verified backup; an unsigned checkout cannot be enrolled as a verified release. The new release manager can enroll a verified 0.8.0 deployment and apply a verified 0.8.1 release once both are actually published. If no official 0.8.0 package exists, deploy a verified 0.8.1 manually first and enroll it for later updates.

Use a private environment file (0600, absolute path), with absolute secret/bind paths. Protect and back up the state directory on an encrypted disk outside the application tree. It contains release packages, state, and sensitive **unencrypted** local pre-update backups, including native SQL dumps. Do not put it in a web root, Git checkout, public volume or shared writeable directory. Keep a separate off-site/physical backup per the existing recovery runbook. Example (replace placeholders with your actual repository, project, versions and paths):

```sh
sudo install -d -m 700 /var/lib/book-updater
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater enroll \
  --repository OWNER/REPO --project book --version 0.8.0 \
  --public-key /secure/book-release-public.pem --env-file /secure/book-runtime.env \
  --overlays postgres,redis,distributed
```

For offline release assets append `--bundle /secure/book-release-080` (all official assets must be present, no extra files). To pin the expected commit append `--commit FULL_GIT_SHA`. Omit overlays for file storage; use the actual ordered comma-separated overlays, not an invented order. The existing running installation is validated, not recreated on enrollment.

```sh
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater status
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater fetch --version 0.8.1
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater preflight --version 0.8.1
# After fencing traffic and outside writers:
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater apply --version 0.8.1 --offline
```

`fetch` stages verified files; `preflight` also validates the live image/volume inventory and candidate Compose configuration, and makes the verified Book image available locally. It does not stop traffic or execute SQL preflight. `apply` re-verifies the package (use `--bundle` pointing at the staged `releases/<version>-<commit>/bundle` to avoid re-downloading assets). It repeats the topology checks and performs the offline transaction below. The state records `current`, `previous`, `highest`, `pending` and `last_transaction` including the backup identifier. The active deployment tree is `releases/<current.id>/tree`; use its Compose files and the same `--project-name`/`--env-file` for subsequent operator commands. Never modify the tree.

The state pins the environment file contents. If an operator intentionally changes credentials/settings, first reconcile the actual running deployment using the current signed tree and then run `reconcile-env` (requires no pending update; checks actual live containers and runtime). For a pending update restore the exact original environment before recovering. Do not edit `state.json` by hand. Preserve it and the pinned public key independently to retain anti-downgrade protection across disk loss.

## Offline transaction and rollback

1. Stage and verify candidate ZIP and OCI; compare resolved Compose configuration (normalize release-relative paths and only the signed Book service image reference). Record running services and replica counts. Acquire exclusive flocks for the state directory and Compose project (`/run/lock/cajeerbook-update-<project>.lock`) and write the durable pending record before stopping anything. Use one state directory per project; do not run independent managers for the same project.
2. Stop proxy then all Book services, including workers, backup, domain-check and monitoring. Verify that only PostgreSQL/Redis containers remain running in the project. SQL schema advisory locks are independently enforced by the migration engine.
3. Verify the old version, dependencies, installation/media, SQL schema, search/audit and Redis as applicable. Create a portable backup in a unique private backup directory, restore it into an isolated portable drill directory, and for PostgreSQL create `pg_dump -Fc` plus `pg_restore --list` validation. The native dump preserves the SQL state; `--list` is not a full native SQL restore drill. Ensure sufficient disk and WAL capacity. Backups are never automatically deleted. No mutation of source data occurs through restoring the drill.
4. Check candidate image VERSION. With SQL, verify the candidate's actual migration status and required version against signed metadata, reject irreversible migrations, execute real `migrations.php preflight` (transaction rolled back), then `apply --offline`. Verify schema, installation, search, audit, runtime and release assets from the candidate container before starting it. 0.8.1 uses the same schema version 3 as 0.8.0. Future schema upgrades require explicit `--allow-schema-upgrade`, an enrolled >=0.8.1 release, and reversible migrations; future candidate code must preserve the CLI protocol and historical migration checksums. The old runtime accepts only fully matched apply/rollback pairs for unknown future history, never unknown active schema or ledger entries.
5. Record `activating` before starting the candidate. Recreate only stopped non-infrastructure services without deleting volumes. Start Book, verify health, restore previously running service counts, start proxy last. Verify resulting running image and mount inventory; commit current release and keep previous receipt for rollback. No `compose down -v`, no destructive database restore. Release the maintenance fence only after checking state and health.

On errors **before activation**, the updater attempts to stop any remaining tasks, reverse an applied reversible migration with the candidate migrator if necessary, verify the old schema/data, and recreate the old services. The original command returns failure even if rollback succeeded. If rollback fails, the pending journal is retained and the operator must keep traffic fenced. A crash or SIGKILL also leaves the journal. Run:

```sh
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater recover --offline
```

If activation began, candidate writers might already have run. The updater stops them where possible and does not automatically downgrade. Assess data compatibility; for the same schema, explicitly acknowledge it:

```sh
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater recover --offline --accept-data-compatibility
```

For a changed schema after activation, keep the system offline and use the saved native and portable backups or the PITR runbook with operator review; do not force a destructive rollback. A completed upgrade can be explicitly reverted to its retained previous **same-schema** release only after confirming new-format data are compatible:

```sh
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater rollback --offline --accept-data-compatibility
```

Rollback itself takes a fresh backup and rechecks the old signed OCI. It does not restore historical data or discard changes made after the upgrade. High-water version and exact manifest identity never decrease, even after rollback. The same highest release can be retried only with identical commit and manifest. An older version is otherwise refused. For lost/corrupted state, backup or failed SQL reversal, do not re-enroll simply to bypass the high-water mark: recover state and data from the operator's independently stored copies.

## Tests and release qualification

`python3 tests/update-manager.py` exercises real Ed25519/Git/ZIP releases and local HTTPS asset transfer, with a simulated Docker command adapter for fault-injection and journal tests. `tests/update-compose-gate.py` uses actual Docker, local registry digests, PostgreSQL, native dump, portable restore, and real HTTP publication before/after upgrade and rollback; it uses synthetic signed fixture versions and local registry trust (not production GitHub OIDC), is called from the Docker release gate, and requires a disposable local Docker host with root access. It must pass on real infrastructure before production use. Official release signatures and full infrastructure results must come from the existing signed-release pipeline, not fixture data.
