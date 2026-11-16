# Administrator CLI reference — 0.9.1

All PHP commands run from the verified release root, as the same OS user/environment as the intended application service. `BOOK_DATA_DIR` selects installation unless a command takes an explicit directory. For SQL commands keep the matching DSN, role, namespace and binding markers. For backup/monitoring commands use the service with the correct separately mounted credentials. Paths in examples must be new or existing as stated; protect every generated file. Operator commands are not web endpoints. Use `admin.php report` when sharing diagnostics internally; other CLIs can disclose identifiers and exception details.

## Inspection (no requested business-data changes)

| Command | Meaning / qualification |
| --- | --- |
| `php bin/admin.php report` | Redacted JSON; 0 if report produced, not necessarily healthy. |
| `php bin/admin.php check --deep` | Fail on real configured dependency/integrity/credential/audit checks; may take locks and probe files. |
| `php bin/diagnostics.php status` | Detailed local metrics and dependency status. `check` fails on unavailable expected component; `metrics` produces Prometheus text. |
| `php bin/migrations.php status` | Actual SQL ledger/schema; `plan` gives pending migrations, `verify` requires compatible schema. |
| `php bin/installation.php check` | All registered stores, including archived, identity/media/reference consistency. |
| `php bin/postgres-content.php check` | Installation audit using PostgreSQL; fails without SQL. |
| `php bin/search.php check` | SQL search projection and index consistency. |
| `php bin/audit.php check` | Current SQL audit chain verification. |
| `php bin/workspaces.php list` | Operator-only registry with identifiers; keep private. |
| `php bin/runtime.php status` | SQL queue and Redis; `check` requires both. |
| `php bin/domains.php status` | Registered domains and verification expiry. Requires primary host. |
| `php bin/oidc.php check` | Contacts configured provider, checks organization, TLS discovery. |
| `php bin/security.php keys` | Configured key identifiers, never key material. |
| `php bin/security.php verify` | Authenticates live Git/automation credentials against active keys. |
| `php bin/recovery.php status` | Backup scheduler/pending state; may clean abandoned private staging. |
| `php bin/recovery.php health 90000` | Nonzero if last verified capture too old. |
| `python3 bin/pitr-cycle.py /secure/pitr-receipts --health 900` | Freshness of verified paired recovery point. |
| `python3 bin/update.py --state-dir /var/lib/book-updater status` | Managed release/update journal on Docker host. |

## Controlled changes

| Command | Preconditions / effect |
| --- | --- |
| `php bin/identity.php init USERNAME EMAIL 'Display name'` | New installation only, password from stdin. |
| `php bin/identity.php revoke-sessions USERNAME` | Revokes sessions and reset tokens; not account disable. |
| `php bin/identity.php reset USERNAME` | Emits secret reset token, local enabled non-federated account only. |
| `php bin/worker.php --loop` | Long-lived worker; real external side effects; SIGTERM drains. `--once` processes jobs, not a probe. |
| `php bin/domains.php recheck` | Contacts DNS and updates proof state; nonzero on failures. |
| `php bin/migrations.php preflight` | Offline real DDL rehearsal, rolled back; may consume time/WAL/disk. |
| `php bin/migrations.php apply --offline` | Stop all writers sharing DB; matching backup and version. |
| `php bin/migrations.php rollback TARGET --offline` | Only supported reversible versions, matching runtime after rollback. |
| `php bin/postgres-content.php migrate --offline` | Initial file-to-SQL import or documented older cutover, not ordinary schema upgrade. |
| `php bin/search.php reindex --offline` | Derived SQL search rebuild; stop writers. |
| `php bin/security.php rewrap --offline` | Reencrypt live credentials, atomic per scope; retain prior keys and backup. |
| `php bin/verify-data.php DATA_DIR` | Reports consistency, exit 2 on reference mismatches; explicit `--repair-media-references` mutates snapshots. |
| `php bin/media-repair.php legacy MEDIA_ID VERIFIED_SOURCE` | Exact-byte restore, or `ORG/WORKSPACE` scope; no metadata rewrite. |
| `php bin/media-backup.php gc DATA_DIR` | Removes orphan media; preserve backup, run offline in correct scope. |
| `php bin/storage-probe.php create` | Creates temporary shared probe ID; `verify ID`, `hold ID SECONDS`, `busy ID`, `remove ID`; follow two-node procedure. |

## Backups, export and recovery

| Command | Scope / constraint |
| --- | --- |
| `php bin/installation.php backup NEW_DIRECTORY` | Full portable installation, includes registered workspaces/identity/media; new private directory. |
| `BOOK_DATA_DIR=NEW_DIRECTORY php bin/installation.php restore BACKUP_DIRECTORY` | New offline destination, never overwrite source. |
| `php bin/identity.php backup NEW_FILE.json` | Identity-only private artifact; not full DR. Restore only to fresh file-mode identity; SQL needs full restore. |
| `php bin/workspaces.php backup WORKSPACE_ID NEW_FILE.tar` | Nonlegacy workspace content/media only; registry/identity not included. |
| `php bin/workspaces.php restore WORKSPACE_ID FILE.tar` | Offline empty file-mode workspace, no SQL in-place restore. |
| `php bin/media-backup.php backup DATA_DIR NEW_FILE.tar` | Single content/media scope; not full installation backup. |
| `php bin/media-backup.php restore FILE.tar NEW_DATA_DIR` | New offline directory. |
| `php bin/recovery.php run` | Capture or retry encrypted backup; `tick` respects schedule, `loop` daemon. |
| `php bin/recovery.php drill ID --remote` | Remote verified restore drill; dedicated SQL drill DB if configured. |
| `php bin/recovery.php restore ID NEW_DIRECTORY --remote` | Restore encrypted remote archive to new directory, see recovery guide. |
| `php bin/pitr.php inspect ID` | Authenticated remote sidecar metadata; `retry ID` retries publication. |
| `python3 bin/pitr-cycle.py /secure/pitr-receipts --type full` | Physical backup + paired sidecar/WAL capture; scheduled operator task. |
| `python3 bin/pitr-repo.py restore NEW_PGDATA --isolated` | Input authenticated point JSON; dedicated isolated PostgreSQL host. |
| `php bin/pitr.php restore ID NEW_DATA_DIR --isolated` | After exact replay and promotion, validate SQL/sidecar, revoke sessions. |
| `php bin/audit.php checkpoint NEW_JSON SEED_FILE` | Signed checkpoint; external independent key/storage. `check-checkpoint JSON PUBLIC_KEY_FILE` verifies. |
| `php bin/audit.php archive STREAM RETENTION_DAYS NEW_JSONL` | Changes retention anchor and prunes verified prefix; preserve archive independently. `verify-archive FILE` checks archive. |
| `php bin/audit.php snapshot NEW_JSONL` | Retained audit chain snapshot; `verify-snapshot FILE` validates. |
| `php bin/static-export.php NEW_FILE.zip --workspace=WORKSPACE_ID --scope=BOOK/VERSION` | Static published snapshot, omit workspace for legacy. `--include-private` creates sensitive artifact; never publish publicly. |

Use [incidents](incidents.md) for recovery order, [disaster recovery](disaster-recovery.md) for physical backup, [security lifecycle](security-lifecycle.md) for credentials and external checkpoints, and [distributed reliability](distributed-reliability.md) for storage probes/worker lease semantics. `bin/ci.py` is a client for scoped CI publication; supply `BOOK_CI_URL` and `BOOK_CI_TOKEN` privately, HTTPS required except explicit local test. Run `python3 bin/ci.py --help` for its actual subcommands. `bin/release-package.py`, `bin/release-build.sh`, `bin/release-publish.sh` and `bin/update.py` are trusted host/release tools, not ordinary container runtime commands; see [signed releases](signed-releases.md) and [secure updates](secure-updates.md). Do not run release tooling inside a production application container.
