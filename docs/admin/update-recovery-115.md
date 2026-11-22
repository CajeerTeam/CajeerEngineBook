# Backup, recovery and rollback — Book 1.1.5

**Scope:** an enrolled, **single-host Linux Docker Compose** installation managed by signed `bin/update.py`, with the independent root-owned broker introduced in 1.1.4. This is operational code, but it does not turn an unsigned checkout into a trusted release or replace separate PITR/off-site backup procedures. The database schema is still **7** and embedded CajeerEngine remains **2.1.0**.

## How a pre-update backup is verified

When `apply` or an operator-approved same-schema `rollback` is executed, the updater stops its local writers, validates the old runtime and creates a new private backup under `<state-dir>/backups/<backup-id>` before running migrations. The backup includes a portable Book snapshot and, for the supported managed PostgreSQL topology, a native custom-format `database.dump`.

1. The portable snapshot is **restored into an isolated drill directory** and `php bin/installation.php check` audits the restored content and media. The restore directory is not the active installation and is retained with the backup. A failed restore or audit blocks the update.
2. The native PostgreSQL dump is checked with `pg_restore --list` and a **full non-executing archive scan** (`pg_restore --file=/dev/null`). The second command reads/decompresses data entries; `--list` alone does not check payload bytes. **Neither is a full restore into a separate PostgreSQL instance.** The required full SQL/PITR recovery drill remains part of the release and disaster-recovery acceptance gates.
3. After successful capture, `update.py` calculates SHA-256 and size of **every** backup file. Its root-owned `0600` receipt lives in the private `backup-receipts` directory **outside the writable backup mount**. The receipt includes the exact directory and file inventory, mode (`postgresql` yes/no), creation time and byte totals. The root-only durable update journal pins the receipt's SHA-256. Unexpected files, deleted files, symlinks, missing SQL dumps or changed bytes fail verification.
4. Only after the receipt has been persisted and reverified does the updater mark `backup_complete` and proceed to the migration. The complete backup and its receipt are retained across successful upgrades and rollbacks; there is **no automatic pruning**.

The backup ID is available in `last_transaction.backup` or `pending.backup` from `status`. IDs are generated in UTC, for example `20261122T120000Z-aaaaaaaaaaaaaaaa`.

## Verifying a retained backup

Run these commands as root on the Docker host using the **trusted host tools** and the original enrolled state directory:

```sh
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater status
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater verify-backup \
  --id 20261122T120000Z-aaaaaaaaaaaaaaaa
```

Successful output includes `status: verified`, backup ID, file count, total bytes and receipt SHA-256. For the most recent/pending transaction it also returns `journal_bound: true`; retained older backups still use the independently stored root-owned receipt but no longer have a hash in the *current* journal. Preserve an off-host copy of the original receipt/manifest as independent evidence. A modified backup **must not** be used for recovery. Do not edit receipt JSON or `state.json` to make a failed verification succeed.

`Administration → System Updates` shows a redacted read-only recovery state (pending transaction, current/previous Book versions and whether a backup receipt was recorded). The web panel does not expose the backup files, host paths, or a recover/rollback action. These remain explicitly operator-controlled.

## Recovery after an interrupted upgrade

**Keep the maintenance fence active**: stop any external traffic, direct clients, independent writers, PITR cycles and out-of-project workers that can write to this database. Review both root-owned updater state and agent journal. Do not send unverified or root-owned files to the web UI.

```sh
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater status
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater verify-backup \
  --id 20261122T120000Z-aaaaaaaaaaaaaaaa
# For a pre-activation failure with compatible schema:
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater recover --offline
```

The updater automatically checks the transaction's attested backup **before** touching deployment containers during recovery if the backup had completed. A corrupt, removed or untrusted receipt leaves the durable pending transaction in place and fails closed. Restore state from independently trusted copies, or use the disaster-recovery/PITR procedure; do not bypass integrity checks.

If activation started and new code may have written data, assess application-level compatibility first. Only **after** that assessment may the operator use `recover --offline --accept-data-compatibility`. If SQL was upgraded and data/schema cannot safely be reversed, recovery refuses an automatic downgrade; retain the maintenance fence and perform a separate, audited native/PITR restore. The updater never assumes that a healthy old container makes old-format data safe.

Once the host updater has no pending transaction and the pinned environment is consistent, the root operator can reconcile the 1.1.4 agent journal:

```sh
sudo python3 /opt/trusted-book-tools/update-agent.py reconcile \
  --state-dir /var/lib/book-update-agent \
  --updater /opt/trusted-book-tools/update.py
```

## Intentional rollback of a completed update

A same-schema rollback is **explicit**. Check `previous`, compatibility of content written since the upgrade, and the pre-update backup. Verify all external writers are stopped, then run:

```sh
sudo python3 /opt/trusted-book-tools/update.py \
  --state-dir /var/lib/book-updater rollback --offline \
  --accept-data-compatibility
```

The rollback makes a **new** portable/SQL backup of the current installation, audits it, attests its contents and then reactivates the previously signed release. It does **not** revert data to the old backup; data rollback requires explicit restore with consistency review. The highest signed release watermark is retained. Schema-changing or unverified migrations require the separate SQL recovery procedure; this command will not force them.

After recovery/rollback check the live installation, login, published pages, media hashes, search, audit and PostgreSQL/Redis runtime as applicable. Keep the maintenance fence until those checks and the agent journal have been reconciled. Retain backups, receipts and logs according to an off-host retention policy.

## Acceptance and limits

```sh
python3 tests/update-backup-115.py
python3 tests/update-manager.py
sudo python3 tests/update-agent-114.py
php tests/update-center-114.php
```

Tests cover tampering, unexpected paths/symlinks, receipt mismatch, refusal to recover with altered backups, agent access, operator approval, upgrade/rollback fault injection and exact command sequencing. **The unit tests use simulated Docker and SQL**. A full recovery drill must still exercise signed assets on real Docker/PostgreSQL/Redis, separate native database restoration, PITR and external-writer fencing. Do not claim full production acceptance from source-only tests.
