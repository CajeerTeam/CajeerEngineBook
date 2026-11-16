# Incident response and maintenance runbook — 0.8.2

Record incident start UTC, release/digests, topology, affected operations and request IDs. Avoid exposing raw logs, cookies, tokens, database DSNs, users and workspace names. Freeze writes for suspected data loss, unauthorized access, identity binding mismatch, SQL primary ambiguity or media corruption. Take a protected snapshot of evidence before repair. Never edit SQL migration ledger, identity anchors, audit hashes, job lease state or storage bindings to silence an error. Never use `down -v`, `docker volume prune`, `FLUSHALL`, `pg_resetwal` or overwrite a live data directory as a generic recovery step.

## First five minutes

Run on each affected app replica as its runtime user, with actual environment:

```bash
php bin/admin.php report
php bin/admin.php check
php bin/diagnostics.php status
```

The redacted report names failed probes and next actions. `report` exit 0 means JSON was generated, not that the application is healthy. `diagnostics.php status` is richer telemetry; keep it private. Verify edge `/health/live` and `/health/ready` on the real primary HTTPS host. A 421 on an unknown host is expected when custom domains are configured. A 503 readiness response is not a reason to restart all nodes simultaneously. Check per-replica readiness, runtime logs, failed dependencies, disk and memory. Keep one known-good node serving safe reads if appropriate. For Compose use the exact project/overlay/env selection from [operations](operations.md); do not issue bare Compose commands in an unrelated checkout.

| Symptom | First action | Recovery / acceptance |
| --- | --- | --- |
| `/health/live` fails | Check process, proxy, port and TLS; compare other replicas | Restart only failed instance; verify live and ready through edge |
| Live OK, ready 503 | `admin.php report`, configuration/storage/binding/identity/schema | Fix specific failed dependency; ready 200 on each replica |
| Unknown host 421, domain 404 | `domains.php status`, DNS TXT/A/AAAA, Caddy cert/edge configuration | Recheck proof, public workspace and TLS; do not bypass host gate |
| SQL schema incompatible | `migrations.php status`, matched release/markers | Fence all writers; verified backup and documented offline migration, then verify |
| SQL standby/read-only or failover | Confirm primary/fencing with DB operator | Route to one writable primary; never replay ambiguous mutation without outcome check |
| Redis unavailable | `runtime.php check`, Sentinel quorum/ROLE, CA and ACL | Restore majority/writable primary; SQL jobs remain durable; API/search limits may fail closed |
| Queue delayed/dead | `runtime.php status`, worker heartbeat and logs | Restore worker connectivity; inspect job outcome before retry; do not reset lease in SQL |
| Media HTTP 503 | `admin.php check --deep`, check storage mount and media hash | Restore exact original with `media-repair.php`; verify public/private access and hash |
| SSO failure | `oidc.php check`, clocks, issuer/JWKS, callback and groups | Emergency owner, correct provider config; test login/logout before required SSO |
| Stale backup | `recovery.php status`, remote access, pending state and disk | Retry same pending archive; verified remote drill before declaring recovery |
| PITR gap | `pitr-cycle.py --health`, archiver and repo logs | Reestablish WAL/sidecar coverage; isolated restore and measured RPO |
| Failed update | `update.py status`, pending journal | Follow `recover`/`rollback` procedure; do not edit state or volumes |

## Database and search

On current SQL installation, in maintenance window if expensive:

```bash
php bin/migrations.php verify
php bin/postgres-content.php check
php bin/search.php check
php bin/audit.php check
```

Check actual `BOOK_PG_NAMESPACE`, data volume and matching binding markers without publishing them. Missing marker is not an invitation to import stale JSON. If search is stale, preserve verified backup, stop all app/worker writers, and run:

```bash
php bin/search.php reindex --offline
php bin/search.php check
php bin/admin.php check --deep
```

Resume only after matching content/search/audit checks. Reindex rebuilds derived search, not content, schema or audit chain. If audit chain fails, isolate evidence, compare external signed checkpoints and archived prefixes, restore matching trusted database/backup with operator approval; never regenerate hashes to hide a gap. PostgreSQL role and primary troubleshooting is infrastructure-specific; no automatic HA promotion in Book.

## Queue and app replica failure

Observe Redis/SQL runtime status and worker logs, queue due count, dead-letter, attempt and delivery ID. Graceful stop via SIGTERM drains the current job; allow configured 16-minute grace. Abrupt loss may leave a SQL lease for up to 15 minutes. A new worker will reclaim after expiry; external webhook delivery is at-least-once and receiver must deduplicate delivery ID. On a disposable replica/test installation `php bin/worker.php --once` executes real jobs; do not use it as a read-only diagnosis against production. For a failed HTTP mutation, inspect actual state/idempotency before retry. Proxy only retries safe reads, not OIDC callback or POST. [Distributed reliability](distributed-reliability.md) contains failover and shared-volume probes.

## Identity and credential compromise

Disable compromised non-owner account through Account → Users and revoke sessions. Transfer ownership before disabling an organization owner. Local operator can revoke without web access:

```bash
php bin/identity.php revoke-sessions USERNAME
```

If local password reset is required, `php bin/identity.php reset USERNAME` emits a sensitive one-time token; transmit privately and use `/account/reset`, do not log it. SSO accounts require provider-side recovery, not local password reset. Revoke compromised CI tokens with zero overlap; retire prior webhook key, coordinate receiver rotation, retain necessary historical encryption keys for backup. See [security lifecycle](security-lifecycle.md). Preserve external audit checkpoints and compare before trusting recovered identity. An account session revoke does not disable re-login.

## Media corruption and portable restore

Run integrity check privately (it reads documents and media). For a single known bad blob use [distributed reliability](distributed-reliability.md) exact-byte repair from a verified backup. Do not replace metadata/hash to make corrupt bytes pass. If references disagree, preserve snapshot then use `verify-data.php DATA_DIR --repair-media-references` only after diagnosing the correct scope and stopping writers. Never do broad repairs against uncertain data.

Portable backup/restore from an offline **native** installation (or equivalent commands inside the correct container with a separately mounted private backup directory):

```bash
# Stop writers; verify original configuration and available backup capacity first.
php bin/installation.php check
php bin/installation.php backup /secure/new-portable-backup
# Isolated destination, parent exists; do not point at production.
(
  unset BOOK_PG_DSN BOOK_PG_NAMESPACE BOOK_PG_USER BOOK_PG_PASSWORD BOOK_REDIS_HOST BOOK_REDIS_SENTINELS
  BOOK_DATA_DIR=/secure/new-restored-book php bin/installation.php restore /secure/new-portable-backup
)
```

For file-mode restoration ensure all SQL/Redis environment is removed; for portable restoration of SQL source also remove SQL/Redis settings before checking restored directory. The portable restore creates a new directory, invalidates sessions and resets domain proof; it does not rebuild an existing SQL database. For SQL cutover use **new** database/namespace and `postgres-content.php migrate --offline`, then verify SQL/search/audit. Preserve historical audit archives and all keys; portable SQL restore is not physical PITR. Full physical recovery uses [Disaster Recovery](disaster-recovery.md) with paired WAL and sidecar and isolation. Old primary must remain fenced until operator cutover.

For automatic encrypted backups, operate in configured backup service:

```bash
php bin/recovery.php status
php bin/recovery.php health 90000
php bin/recovery.php drill BACKUP_ID --remote
```

Health uses last verified capture time, not last attempt. Drill requires remote credentials and retained keyring; use separate drill DB for SQL. Do not expose backup artifacts or decrypted staging. Verify recovered media/pages, users and permissions before cutover; rotate historical external credentials, revoke sessions, recheck domains and resume workers last. RPO/RTO are measured from evidence, not a guarantee of default schedule.

## Update failure or disaster declaration

If signed updater has a pending journal, run `status` from the independently trusted host-side updater. It may have stopped the old release before migration or already activated candidate. Follow [secure updates](secure-updates.md); do not start both versions or manually erase pending state. Before accepting rollback after activation, verify data compatibility and actual schema; otherwise restore pre-upgrade backup in isolation. For complete host loss use independent backups and trusted release, rebuild infrastructure separately and measure elapsed recovery time. Never let restored and old PostgreSQL primaries accept writes simultaneously. Close an incident only after documented checks, representative login/publish/read/media/search, queue recovery, monitoring and independent backup evidence pass.
