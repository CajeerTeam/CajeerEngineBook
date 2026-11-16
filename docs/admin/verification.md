# Documentation verification — 0.8.2

The handbook is tested against the actual checkout. A syntax/link check is not proof that a deployment or restore works. Use the following tiers and record the release commit, UTC time, environment, command, exit code and report path. A skipped tier is **not** a passed tier.

## Tier 1: reference integrity

From the source root (Python 3 and Bash):

```bash
python3 tests/admin-docs.py
```

Checks all local guide links, CLI file references, current VERSION/schema, documented runtime setting names, current handbook shell syntax and JSON examples. Shell snippets are parsed, never executed from Markdown. The handbook index must include every specialist guide. Correct a stale reference rather than disabling its check. Historical guides retain their original upgrade starting versions; current procedures are in [operations](operations.md).

## Tier 2: executable administrator workflow

On a disposable checkout/test host with the required PHP extensions, locked Composer dependencies and pinned Mermaid installed:

```bash
composer install --no-interaction --prefer-dist
sh bin/install-mermaid.sh
python3 tests/admin-runbook.py
```

Creates a fresh private temporary data directory, initializes an actual owner through stdin, runs `admin.php report/check`, checks configuration and missing dependencies, detects damaged anchor and SQL marker, verifies private key permissions and redaction, runs deep integrity and credential checks, creates a real portable backup, restores it into a new directory, verifies restored identity/data and checks that a second restore cannot overwrite it. All source test data is removed on exit. It does not read your production env or databases. The runtime test is mandatory in the normal Composer/native release gate.

If Composer/runtime dependencies are unavailable, `python3 tests/admin-runbook.py --minimal` exercises only the storage/configuration/identity/key/report paths. This mode expects missing runtime dependencies to remain a failed check; it explicitly skips the complete CLI backup/restore and deep check. Existing `php tests/installation.php --storage-only` and `php tests/recovery.php --storage-only` independently exercise actual portable storage and encrypted recovery on isolated fixtures; they are not substitutes for full document rendering and SQL validation.

## Tier 3: infrastructure and failure drills

On a **disposable** Linux test host with Docker/Compose, PHP drivers, Composer, Node/npm, browser/test dependencies, AWS CLI and required tools, use the existing release runner. See README's release gate prerequisites.

```bash
python3 tests/release-gate.py --suite all --report-dir /tmp/book082-gate
```

The directory must not already exist. Native includes this handbook and executable CLI workflow; PostgreSQL includes fresh SQL migration/owner/backup/restore and redacted admin reports; distributed/Docker gates cover replica startup, readiness, Redis/Sentinel failover, shared volume probes, signed updates and domain ingress. PITR gate destroys a disposable cluster and recovers from physical backup/WAL and remote sidecar. Security scanner must pass separately in the protected CI. Inspect actual JSON/JUnit and logs; failure or missing dependencies are blockers, not success. For production latency and 24-hour load testing follow [load qualification](load-qualification.md), not a short smoke test. For a signed release follow [signed releases](signed-releases.md); test keys/fixtures do not constitute official release evidence.

## Acceptance on your actual installation

- Confirm signed release/digests, expected schema, stable namespace and matching data volume; inspect every app replica and its edge health.
- Verify an authorized owner, unprivileged/anonymous isolation, OIDC login/logout if configured, actual document publication/search, media SHA-256 read and one valid CI/worker operation in a designated test workspace.
- Confirm actual metrics collection, alert delivery (firing and resolved), worker heartbeat, backup freshness and remote-only restore drill; test a paired physical recovery if PITR is enabled. Record measured RPO/RTO and keep independent evidence.
- Verify current keys and retain historical backup keys, confirm audit chain/checkpoints, test recovery procedure on a separate isolated host; never expose a restored cluster concurrently with the original primary.
- Stop rollout if any required check fails. Preserve source data, backups, logs and update journal; consult [incidents](incidents.md).

Run the short report after configuration changes; run deep checks during maintenance. The JSON report is redacted but still internal operational information. Do not publish raw diagnostics, Docker config, backup output or sensitive operator reset tokens.
