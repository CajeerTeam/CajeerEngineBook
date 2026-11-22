# Installation and day-to-day operations

Release 1.0.6; schema 5. Start with [configuration](configuration.md). Commands are run from the verified project/deployment root on Linux unless explicitly marked inside a container. Replace example paths, domain names and account names with installation-specific values. Do not run fresh-install commands on an existing installation. Keep a record of release commit/digests, Compose project, overlay order, namespace, volume names, service counts, backup location and the last successful drill (not secret values).

## 1. Select a deployment

| Mode | Components | Suitable for |
| --- | --- | --- |
| File | One Book service and persistent volume | Small single-host installation; no SQL migration or Redis |
| PostgreSQL | Book + PostgreSQL 16 + private media volume | SQL content, identity, search and audit |
| Distributed | PostgreSQL + Redis + multiple app/worker replicas + edge | Horizontal app operation on one host or a separately operated shared POSIX backend |
| Enterprise | Distributed + Caddy domains + verified off-site backup + monitoring | Public HTTPS, SSO, RPO monitoring, operator recovery |

Native runtime: PHP >=8.4.1 and <9, Composer 2 locked packages, fileinfo, mbstring, openssl, Phar, zip; GD for image processing, sodium for encrypted backup, PDO PostgreSQL for SQL, ext-redis for Redis, pcntl for graceful CLI workers. Install Node/npm to obtain pinned Mermaid assets. Production PHP/Apache Docker image includes required runtime dependencies. Host Docker Engine and Compose >=2.24.4 are required for the overlays; use Linux for the secure updater. Python 3, OpenSSL, curl, Git and Cosign are needed by release/update tooling, not by an ordinary web request. Read [signed releases](signed-releases.md) for trust bootstrap and [secure updates](secure-updates.md) for root-owned host tooling.

## 2. Verify release and prepare environment

Obtain the official release and independent Ed25519 public key, verify the ZIP signature before extracting or executing its scripts. Verify the complete bundle with the trusted verifier as described in [signed releases](signed-releases.md), including OCI signatures for deployment. An incremental development patch is not a signed official release. Never use `latest` or a key from an untrusted download as the trust anchor.

Create a private runtime env file, unique Compose project and external backup location. For example, create the file with `umask 077` and edit it using a private editor. Do not echo secrets, paste them into logs or commit `.env`. `docker compose config` without `--quiet` may print secrets. Use explicit project and env file on every invocation. A shell helper for a **fresh local file-mode** installation:

```bash
export BOOK_ROOT=/srv/book-release
export BOOK_ENV=/secure/book-runtime.env
export BOOK_PROJECT=book
cd "$BOOK_ROOT"
dc() { docker compose --project-name "$BOOK_PROJECT" --env-file "$BOOK_ENV" -f compose.yaml "$@"; }
dc config --quiet
```

Set `BOOK_SECURE_COOKIE=1` for HTTPS. Use `BOOK_SECURE_COOKIE=0` only for isolated loopback HTTP testing; the default base Compose value is 0. Do not set `BOOK_ADMIN_HASH` for new installations initialized with the CLI. For production use verified deployment images and `pull`/`--no-build`. For an independently trusted source checkout, build instead (`dc build book worker`); this is not an official signed image.

## 3. Fresh file-mode installation

The image stores data at `/var/lib/cajeerbook` and serves only `public/`. The base service binds `127.0.0.1:8080`, not a public interface. For a verified deployment ZIP:

```bash
dc pull book
# Create the persistent volume/one-off container and initialize the owner before exposure.
read -r -s -p 'Initial owner password: ' owner_password
printf '\n'
printf '%s\n' "$owner_password" | dc run --rm --no-deps -T --user www-data book php bin/identity.php init admin admin@example.org 'Installation owner'
unset owner_password
dc run --rm --no-deps -T --user www-data book php bin/admin.php check --deep
dc up -d --no-build --wait book
curl --fail --silent --show-error http://127.0.0.1:8080/health/live
curl --fail --silent --show-error http://127.0.0.1:8080/health/ready
```

Use a real owner email and a strong password (12–72 bytes; never on the command line). The owner initialization is one-time; do not rerun on initialized identity. Existing seed documents are demo content; review/delete through Studio before exposing the site. Configure HTTPS ingress, then sign in through `/login`, create an organization and a workspace, choose visibility explicitly and assign only the required roles. Do not assume all legacy pages are private. Verify one public document and a private page from an anonymous browser, and verify Studio access as a permitted and an unpermitted user. Use browser-based CSRF flows, not direct SQL edits.

Native setup (trusted source, fresh installation only): install dependencies and assets, create a directory outside `public/` owned by the runtime user, set environment, run the same owner and report CLIs. Run PHP/Apache with document root `public/`; the built-in PHP server is for local testing only. Run all CLI operations under the same runtime account, not root, to avoid root-owned lock/media files.

```bash
composer install --no-dev --prefer-dist --no-interaction
sh bin/install-mermaid.sh
export BOOK_DATA_DIR=/var/lib/cajeerbook
# Provision BOOK_DATA_DIR with the runtime UID and mode 0700 before this command.
php bin/admin.php report
```

## 4. Fresh PostgreSQL or file-to-SQL conversion

Add `compose.postgres.yaml` after the base; configure distinct admin/app passwords and one stable random 32-hex namespace (`openssl rand -hex 16`). See [configuration](configuration.md). The app role is not a superuser; the PostgreSQL initialization script creates it when PGDATA is empty. Do not change a password variable and assume it changes an existing role. Do not expose PostgreSQL ports.

For the following shell block use the PostgreSQL helper instead of the file-only helper. It assumes a new verified deployment ZIP. If converting an existing file installation, stop **all** writers and proxy, create and restore-test a portable backup using the old file configuration, retain the same app volume, and only then add SQL settings. Never use `migrate` on an already migrated SQL installation.

```bash
dc() { docker compose --project-name "$BOOK_PROJECT" --env-file "$BOOK_ENV" -f compose.yaml -f compose.postgres.yaml "$@"; }
dc config --quiet
dc pull postgres book
dc up -d --wait postgres
dc run --rm --no-deps -T --user www-data book php bin/postgres-content.php migrate --offline
```

For a new installation only, initialize owner in SQL:

```bash
read -r -s -p 'Initial owner password: ' owner_password
printf '\n'
printf '%s\n' "$owner_password" | dc run --rm --no-deps -T --user www-data book php bin/identity.php init admin admin@example.org 'Installation owner'
unset owner_password
dc run --rm --no-deps -T --user www-data book php bin/migrations.php verify
dc run --rm --no-deps -T --user www-data book php bin/admin.php check --deep
dc up -d --no-build --wait book
```

Conversion preserves existing users: **skip** the owner initialization in that case, but run the checks. Preserve `postgres.required`, `postgres.identity.required`, media and identity anchor. Pending `postgres.migrating` means incomplete migration: stop and inspect before rerun using the same namespace/binding. Never delete the markers to force file fallback. For existing SQL installations `migrations.php verify` checks current schema; use `plan`, `preflight`, and `apply --offline` only for planned schema upgrades with matching source/target and backup. Preflight executes and rolls back real DDL, so schedule downtime and disk/WAL capacity. [PostgreSQL performance](postgres-performance.md) documents schema 3 migration and its safe reverse operation. No SQL migration from 0.8.2 to 0.9.0.

## 5. Distributed/enterprise rollout

Append overlays in order: `compose.redis.yaml` (or `compose.redis-sentinel.yaml`, never both), `compose.distributed.yaml`, `compose.domains.yaml` for managed TLS, `compose.backup.yaml`, `compose.enterprise.yaml`, `compose.observability.yaml` for the documented full enterprise setup. Append optional `compose.shared-storage.yaml` after volume definitions, `compose.security.yaml` for file-based keys, and `compose.pitr.yaml` for physical backup. Review [configuration](configuration.md) for restrictions. Do not start all profiles blindly: PITR sidecar is a one-off CLI service, not an idle daemon.

After SQL migration and owner initialization, with all required settings and paths configured, a source deployment uses `build` before startup; a signed deployment uses `pull` and `--no-build`. For the default full enterprise topology (standalone Redis, Caddy, backup, diagnostics), define:

```bash
dc() { docker compose --project-name "$BOOK_PROJECT" --env-file "$BOOK_ENV" -f compose.yaml -f compose.postgres.yaml -f compose.redis.yaml -f compose.distributed.yaml -f compose.domains.yaml -f compose.backup.yaml -f compose.enterprise.yaml -f compose.observability.yaml "$@"; }
dc --profile automation --profile backup config --quiet
dc --profile automation --profile backup pull
dc up -d --wait postgres redis
dc run --rm --no-deps -T --user www-data book php bin/runtime.php check
dc --profile automation up -d --no-build --wait --scale book=2 --scale worker=2 book worker domain-check proxy diagnostics prometheus alertmanager grafana
dc --profile backup up -d --no-build backup
dc exec -T --index 1 --user www-data book php bin/admin.php check
dc exec -T --index 2 --user www-data book php bin/admin.php check
```

Do not run this example with missing domain/backup settings. Start with basic SQL first; do not require backup health before the first verified snapshot exists. For Redis Sentinel use external endpoints and replace the Redis overlay and `up postgres redis` with `up postgres`; configure the same Sentinel settings on diagnostics as described in [distributed reliability](distributed-reliability.md). Both app replicas must mount the **same** actual media volume. Check remote POSIX locking with storage probes on each node. Caddy publishes 80/443, requires DNS pointing to the edge and ACME contact; verify primary TLS and custom-domain TXT proof before enabling public hosts. Internal Nginx proxy is loopback HTTP, not a TLS terminator. Do not expose direct app, SQL, Redis, exporter, Prometheus or Alertmanager to the Internet.

## 6. Verification and redacted operator report

```bash
php bin/admin.php report
php bin/admin.php check
php bin/admin.php check --deep
php bin/admin.php check --oidc
php bin/admin.php check --monitoring
```

`report` emits JSON and exits 0 when a report was produced, even if it contains failures; `check` exits 1 for any failed probe, 2 for invalid CLI arguments. The report gives version, required schema, configured backend, checks, elapsed milliseconds and fixed remediation hints. It contains no paths, raw exception messages, key values, DSN, tenant IDs, user records or document content. `skipped` is explicit for unconfigured optional services or failed prerequisites. It is not a statement that every integration works. `--deep` runs the existing full installation integrity, live credential verification and SQL audit-chain checks; use during a maintenance window (large stores and hashes may block writers). `--oidc` contacts the configured provider and verifies organization/discovery, not a user login. `--monitoring` invokes the diagnostics dependency checks, including configured heartbeat/RPO/TLS expectations; run in the diagnostics service with its actual environment. Ordinary checks use real store readiness, may create lock/probe files and materialize existing identity anchors, but do not repair business records, install dependencies, rotate keys or import SQL. Run with the runtime UID and intended environment; the operator report cannot certify other nodes or another service's secrets. It has no HTTP route.

For Compose `dc exec -T --user www-data book php bin/admin.php check --deep` checks one replica. Use `--index N` for all replicas. `/health/live` only indicates process response, `/health/ready` checks actual runtime and storage, and the exporter reports independent dependencies. Require HTTP 200 through the real public HTTPS edge as well as each replica. Check OIDC login/logout, private/public workspace, search, real media download, CI token scope, one worker delivery and a backup drill before production. For `--monitoring`, wait until required worker/backup/DNS jobs have run; do not disable expected checks to make a red report green.

## 7. Daily/weekly/monthly operator routine

Daily: check edge and replica readiness, failed alerts, queue due/dead count, backup verified capture age, PostgreSQL disk/WAL and connection/lock pressure, Redis memory/key growth. Review audit events for failed logins and unexpected administration. Use [incidents](incidents.md) to decide when to isolate traffic. Weekly: run deep check, review role assignments, validate backup repository and perform a representative restore drill; independently retain signed audit checkpoints. Monthly and before a release: rotate expiring credentials, verify old backup keys are retained, rehearse recovery on a separate host, record measured RPO/RTO, review patch/security reports and run load qualification on a disposable stand. Do not run corpus generators or destructive qualification gates against production.

## 8. Upgrade 0.9.1 → 1.0.0

Schema stays 3; no migration is needed for a healthy 0.9.1 installation. For a managed signed installation use [Secure Update Manager](secure-updates.md) with the official verified bundle for 1.0.0. For a manually managed source deployment stop every app/writer, create a tested pre-upgrade backup and keep the current code/digests, configuration, media, SQL backup and secrets. Apply the verified source changes, rebuild source images or switch to verified OCI digests, run `migrations.php verify` (SQL) and `admin.php check --deep`, then bring up the previous replica counts and proxy last. Do not change volumes, regenerate namespace or replace historical keys. Re-enable traffic after public/privileged smoke tests. A signed updater cannot install a changed-files patch; use the official release pipeline to publish a complete signed release. For rollback, follow the update journal and data compatibility checks; never overwrite a live database or automatically reverse irreversible migrations.
