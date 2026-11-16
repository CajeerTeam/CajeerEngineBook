# Disaster recovery and PostgreSQL PITR (0.7.2)

This optional deployment adds physical PostgreSQL 16 backup and WAL replay via pgBackRest. Portable `bin/recovery.php` backups remain supported. **Database schema remains 3.** A Book recovery point is a named WAL record paired with an authenticated, encrypted installation sidecar containing the exact media bytes, portable content, identity and retained audit evidence. PostgreSQL alone cannot reconstruct the filesystem at an arbitrary time. For application recovery choose a verified paired point, not an arbitrary timestamp.

## Prerequisites and trust boundaries

- Dedicated PostgreSQL 16 cluster, one Book installation/database, no additional user databases (apart from `postgres`) or custom tablespaces. Use a supported PostgreSQL minor version and pgBackRest >= 2.59.3, matching server major, extensions and platform on the restore host. See [pgBackRest guide](https://pgbackrest.org/user-guide.html) and [PostgreSQL continuous archiving](https://www.postgresql.org/docs/16/continuous-archiving.html).
- Separate encrypted pgBackRest repository on independent storage (remote POSIX or supported object storage) and a distinct independent repository/prefix for encrypted sidecars. A local disk on the database host alone is **not** disaster recovery. Keep both repository credentials and cipher/keyring material in an independent secret store. Do not share sidecar IDs/prefix with an unrelated installation. Protect backups against deletion using repository access policy, independent retention/replication and supported immutable storage policies.
- Private writable spool (`BOOK_PITR_DIR`, 0700), outside application data and public paths. Allow enough room for plaintext staging, encrypted archive and remote readback. Use an encrypted filesystem; interruption can leave private plaintext staging until the next operation. Never make it a public mount.
- Disable unrelated schema changes, audit pruning, manual database mutations and storage repairs during capture. The application holds identity and all content locks for the portable snapshot and named point, so writes may wait. Regular SQL-only activity outside those locks is not a supported capture pattern.

## Configure the PostgreSQL repository

Example private pgBackRest config, owned by and readable only by the PostgreSQL service account. Replace the cipher placeholder with a securely generated secret (e.g. 32 random bytes in hex); never commit it. The Compose image uses PostgreSQL 16 and installs a patched pgBackRest package (build fails if below the minimum). The repository mount must point to independent storage and be writable by PostgreSQL, not web processes.

```ini
[global]
repo1-path=/repo
repo1-retention-full=3
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=REPLACE_WITH_PRIVATE_RANDOM_SECRET
archive-async=n
log-level-console=warn
[book]
pg1-path=/var/lib/postgresql/data
pg1-user=book_admin
```

Use repository-specific pgBackRest settings for S3 if desired; the wrapper requires repository 1, stanza `book` by default, and encryption. Configure local Unix socket authentication for the pgBackRest role per its guide; do not expose superuser credentials to the web application. Create the restricted SQL checkpoint role as a database administrator (use psql `\password book_pitr` to set its password without a SQL literal in history):

```sql
CREATE ROLE book_pitr LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT ON DATABASE book TO book_pitr;
GRANT USAGE ON SCHEMA cajeer_book TO book_pitr;
GRANT SELECT ON cajeer_book.content_installation TO book_pitr;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_control_system(),
  pg_catalog.pg_create_restore_point(text), pg_catalog.pg_switch_wal() TO book_pitr;
```

Use the existing app runtime role for content/identity/audit snapshot reads. Set `BOOK_PITR_PG_USER=book_pitr` and `BOOK_PITR_PG_PASSWORD` privately. This role can create WAL restore points and force segment switches; never expose it to clients. Native deployment requires `wal_level=replica` (or logical), `archive_mode=on`, `archive_command='pgbackrest --stanza=book archive-push %p'`, and appropriate `archive_timeout` (example 60s). Reload/restart PostgreSQL as required, then `pgbackrest --stanza=book stanza-create` and `check` as PostgreSQL operator.

## Compose integration and paired captures

Keep existing Compose files and namespace. Append `compose.pitr.yaml` after the PostgreSQL overlay. Set `BOOK_PITR_CONFIG_PATH`, `BOOK_PITR_REPOSITORY_PATH`, `BOOK_PITR_PG_USER`, `BOOK_PITR_PG_PASSWORD`, `BOOK_PITR_S3_URI` (separate sidecar prefix), existing `BOOK_BACKUP_KEY_PATH` and S3 credentials. `pitr-sidecar` is a run-only service, **not** a daemon: use `run --rm --no-deps -T`. For native installations `BOOK_BACKUP_REMOTE_DIR` is also supported with an independent mounted filesystem. Do not run this on a shared PostgreSQL cluster. Preserve existing database volume; never use `down -v` on production.

```sh
docker compose --profile pitr config --quiet
docker compose --profile pitr build postgres pitr-sidecar
# Schedule a maintenance window for PostgreSQL restart; preserve the volume.
docker compose up -d postgres
docker compose exec -T -u postgres postgres pgbackrest --stanza=book stanza-create
docker compose exec -T -u postgres postgres pgbackrest --stanza=book check
install -d -m 700 /secure/pitr-receipts
python3 bin/pitr-cycle.py /secure/pitr-receipts --type full
```

The cycle takes a completed full/diff/incr backup, captures a named WAL point under the application snapshot locks, uploads the encrypted sidecar and downloads it back, then reauthenticates the remote envelope and retrieves the actual point WAL using `archive-get`. The receipt identifies the backup label, WAL segment, point, capture time and archive verification time. A failed cycle does not update `latest.json`. A pending receipt is retried on the next invocation. If upload failed before a receipt could be written, the error contains the immutable local ID; retry explicitly:

```sh
python3 bin/pitr-cycle.py /secure/pitr-receipts --retry-id BACKUP_ID
python3 bin/pitr-cycle.py /secure/pitr-receipts --health 900
```

Use a trusted scheduler (e.g. systemd timer/cron on the Compose host, with the same Compose project/environment and non-overlapping runs). Schedule full/diff/incr physical backups according to repository capacity; between physical backups use `--backup-label COMPLETED_LABEL` to create additional paired points. Only a completed retained backup from the same system ID/timeline and preceding the target is accepted. Never run expiry concurrently with capture or recovery; automatic expiry is disabled by the wrapper. Retain every required full/diff/incr chain and all WAL needed by the retained sidecars. Run pgBackRest retention/expire only after checking dependency coverage and successfully drilling the retained recovery points. Sidecar retention is independent; do not delete old keyring keys while any retained sidecar requires them. Keep the latest verified receipt and recovery IDs outside the failed host.

`--health` checks the age of the last archived paired point; it is **not** proof of current remote accessibility or a successful full recovery. Alert on a nonzero exit, monitor `pg_stat_archiver`, pg_wal disk usage, repository availability and physical backup age. A 15-minute RPO requires a measured paired capture+WAL publication cadence under 15 minutes, not merely archive_timeout=60. Large sidecars can make that impossible; adjust the target to actual evidence. No 15-minute RPO or 60-minute RTO is promised.

## Recover from total source loss (isolated host)

1. Fence the failed primary, web/worker/scheduler/domain-check nodes and all writers. Do not let the old and recovered primary accept writes together. Prepare a new isolated host/volume and trusted config, the repository **read-only**, original namespace, app and checkpoint role credentials, backup keyring, and independent sidecar access. Keep Redis disabled and do not start app/worker traffic. Use the original release's schema and compatible PostgreSQL major/runtime.
2. With `BOOK_PITR_DIR`, `BOOK_BACKUP_KEY_FILE`, remote sidecar settings and the former `BOOK_DATA_DIR` configured (may be absent), obtain authenticated point metadata. On the isolated PostgreSQL host, with the pgBackRest config and binary, restore to a new path. Never supply metadata from an unauthenticated source or point the wrapper at existing PGDATA.

```sh
php bin/pitr.php inspect BACKUP_ID > /secure/point.json
python3 bin/pitr-repo.py --config /etc/pgbackrest/pgbackrest.conf \
  restore /secure/new-pgdata --isolated < /secure/point.json
# As PostgreSQL service user, isolated network/port; repository still read-only:
pg_ctl -D /secure/new-pgdata -l /secure/recovery.log \
  -o '-c archive_mode=off -c listen_addresses=127.0.0.1 -p 55432' -w start
```

Wait for promotion (`SELECT pg_is_in_recovery()` returns false); PostgreSQL must reach the named target. Do not override the target or promote early. Configure `BOOK_PG_DSN` to the isolated recovered server, not the source; keep original namespace and control credentials. The next command checks the physical system identifier/database/binding, exact replay LSN and new timeline, requires archive_mode=off, restores exact media into private staging, compares SQL identity/content and retained audit heads against the authenticated sidecar, checks search and installation, revokes sessions/resets/invitations and resets custom-domain proofs, then publishes a new app data directory. It never reimports SQL or overwrites a live directory.

```sh
php bin/pitr.php restore BACKUP_ID /secure/recovered-book --isolated
export BOOK_DATA_DIR=/secure/recovered-book
php bin/migrations.php verify
php bin/installation.php check
php bin/search.php check
php bin/audit.php check
```

If any check fails, stop the isolated server and investigate; for a new attempt restore a fresh physical PGDATA and new data destination. Do not force a mismatched SQL/media combination. The returned `app_restore_seconds` measures the application stage only; measure actual total RTO from source loss through cutover separately. The capture age is a point age, not proof of the actual disaster-time RPO.

3. Before cutover, compare independently stored signed audit checkpoints and confirm security state. PITR may restore previously revoked CI/webhook credentials, group memberships or jobs. Rotate compromised/revoked credentials and review pending jobs/delivery deduplication; do not start workers before reconciliation. Configure new empty Redis, reverify custom domains, verify keys/SSO and restore infrastructure secrets independently. Create a fresh pgBackRest repository/stanza for the recovered timeline, enable archiving there and take a verified new full backup. Never write a drill's divergent WAL to the source repository. Explicitly switch traffic only after fencing the old primary and completing checks. Cutover is operator-controlled, not automatic.

## Qualification and rollback

`bash tests/pitr-gate.sh` uses disposable containers/volumes, creates a physical backup, commits a change, captures a paired point, commits a later change, archives both, destroys source database/app/local sidecar, restores from independent repository and verifies pre/post point semantics, original media, audit and session revocation. It is part of the PostgreSQL release gate. Local policy and encrypted-sidecar tests: `php tests/pitr-policy.php`, `python3 tests/pitr-policy.py`. Full physical qualification must pass before relying on this recovery route. The app recovery stage requires normal Composer dependencies. No new SQL schema is required. To revert the optional deployment, stop PITR scheduling, preserve all repositories and keys, and change archive settings only in a controlled PostgreSQL maintenance window after confirming another backup policy is active. Restored security state is intentionally changed; do not roll back by overwriting a recovered live directory.
