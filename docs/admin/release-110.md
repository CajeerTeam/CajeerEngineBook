# Release acceptance — CajeerEngine Book 1.1.0

This is an **executable acceptance process**, not a claim that production certification has already passed. The 1.1.0 source patch changes the file-mode recovery implementation and the CI regression/security gates. It does not create a new PostgreSQL migration: the current schema is **7**, and the embedded CajeerEngine core remains **2.1.0**.

## What changes at the recovery boundary

- Portable per-workspace TAR backups now include anonymous, daily file-backed analytics aggregates (`analytics/YYYY-MM-DD.json`) when present. `MediaBackup::capture` snapshots the bytes under the same `analytics.lock` used by the writer, to avoid torn JSON during concurrent collection. The full portable installation archive and encrypted local/remote `RecoveryManager` recover these records alongside pages, revisions and media. Before 1.1.0, portable recovery silently omitted these file-mode counters.
- Each day file is validated for its date, allowed event/dimension names, paths, numeric counter values, JSON structure and size. The backup fails on unknown files, symlinks, malformed or oversized aggregates. The analytics component is bounded to 3,660 daily files, 2 MiB per day and 64 MiB total **per content store**; operators exceeding the limit must archive or otherwise retain historical aggregates before a backup. The backup is never reported as verified if a required aggregate fails validation.
- Restore validates each TAR member before publishing an isolated destination. The installation bundle additionally rejects unlisted top-level files, directories and symlinks. Previously generated TARs without analytics remain readable; the new directory is optional. New versions reject malformed data instead of discarding it. Backups must be generated with a version compatible with the restore target.
- PostgreSQL analytics are stored in SQL tables, not file sidecars. **A portable installation snapshot does not restore PostgreSQL daily counters.** Use the independently verified PostgreSQL physical/PITR repository (with its paired sidecar) to restore SQL data, and verify counters on the recovered cluster.
- Vector search indexes and quotas remain subject to their existing recovery/rebuild policies. A file-mode analytics restore is **not** evidence that PostgreSQL PITR, Redis, worker queues or external analytics vendors were recovered.

## Release gates and evidence

| Gate | Actual checks | Required evidence |
|---|---|---|
| Native regressions | Locked Composer install and audit; full `composer.json` test script; browser, import, auth, publication, migrations, SSO, OpenAI, review, search and standalone recovery tests | `native/report.json` and `junit.xml`, all steps successful |
| Independent security | Fresh Composer dependencies on a separate runner; auth, OIDC, HTTP, backup boundary tests; Semgrep, Trivy vulnerability/secret scan and `composer audit` | `security-scan` logs and scanner JSON with zero blocking findings |
| PostgreSQL/Redis | Separate disposable SQL instances, migrations, tenant isolation, transactions, Redis and encrypted source-to-drill recovery | `postgres/report.json`, SQL/Redis test output and physical PITR reports |
| Distributed, domains, Docker | Replicas, update/rollback, proxy, ingress, persistence, disaster lifecycle | Each suite's report/JUnit and exact commit |
| Load and recovery | Open-loop short load, resource/latency checks, full historical upgrade and **24-hour 100k-page** SQL/Redis soak, backup and restore while loaded | Complete minute windows, operation SLOs, RPO/RTO, integrity and source digest |
| Release provenance | Protected annotated `v1.1.0` tag and ancestor of `main`; exact candidate OCI digest, signed archives, source/evidence hashes | Candidate/qualification lifecycle reports and signed `gate-evidence.json` |

The release runner checks that mandatory test files are actually in the Composer regression list; deleting any of the 25 critical paths fails the native gate before the suite begins. The security runner now installs dependencies before its independent test execution. Tests deliberately verify that missing mandatory tests, malformed archives and incomplete restore targets fail closed. There is no option to count a skipped test or incomplete soak as a passed gate.

## Reproduce the file-mode release changes

On an isolated copy of the **full source** (PHP 8.4 and Python 3.12):

```bash
php tests/recovery-110.php
php tests/recovery.php --storage-only
python3 tests/release-110-policy.py
python3 tests/release-gate-policy.py
python3 tests/admin-docs.py
```

These commands run locally without a database but do **not** cover the complete runtime. For the full suite install the locked dependencies and all required PHP extensions on a disposable test host:

```bash
composer install --no-interaction --prefer-dist
sh bin/install-mermaid.sh
python3 tests/release-gate.py --suite all --report-dir /tmp/book-110-gate
```

The `--suite all` runner creates temporary Docker/PostgreSQL/Redis services; it will fail if required toolchains, Docker, extensions, browsers, downloads or permissions are missing. Inspect `summary.json` and every per-suite `report.json`/`junit.xml`. **Do not reuse an existing report directory or point the runner at production.** The protected CI also runs independent security scans and requires a separate long production qualification. To run the long qualification, follow [Production qualification](production-qualification.md). Short benchmarks and local standalone tests are not sufficient for an officially signed release.

## Install and rollback

1. Stop all Book web/worker/scheduler writers for the normal compatible update window. Preserve the current data directory, encryption keyring, env/secrets, and independent remote backup/PITR repository. For an SQL installation, keep schema **7** and verify migrations; do not downgrade database tables just because the Book version changed.
2. Apply the patch to **1.0.14** after backup and testing, then install Composer dependencies from `composer.lock` and run `php bin/migrations.php verify` (SQL installations). Run deep health and actual login, Reader, import, save/review/publish, media and authorized search checks.
3. Generate a **new** 1.1.0 recovery snapshot and drill to a separate data directory. Confirm the restored published content, media hashes, user/session reset and — in file mode — the analytics counters. Exercise a failed/modified backup and prove the intended destination was not created.
4. Execute the protected CI and full long qualification before making a production-ready claim. Promote only signed full artifacts from the gated pipeline; a changed-files ZIP is **not** a signed installable release bundle.
5. On failure, stop writers, retain logs/backup/evidence and restore the matching version/data backup on an isolated host. Do not overwrite an active directory or force a mixed-version rolling upgrade.

## What cannot be attested by a source-only patch

Without deployed SQL/Redis, a real HTTPS OIDC provider, actual OpenAI/vendor credentials, a Docker host, and a dedicated 24h runner, their integration gates and production SLOs **remain unverified**. Run them on the target infrastructure, record hashes and review findings before release promotion. Do not edit CI evidence or bypass branch protections to obtain green status.
