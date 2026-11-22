# Configuration reference — 1.0.0

The authoritative configuration is the selected release's source and Compose files. All names below refer to current runtime/host variables, not values from an older version's example. Keep the env file outside the release tree, mode 0600, and never paste `docker compose config`, `docker inspect`, shell tracing or unredacted environment into support requests. Use `config --quiet` for validation. Environment variables inside a running container do not change when the host env file changes; recreate affected containers (managed deployments use `update.py reconcile-env` with its documented checks).

## Runtime and topology

| Name | Default / meaning | Rules |
| --- | --- | --- |
| `BOOK_DATA_DIR` | Native `<project>/var`; image `/var/lib/cajeerbook` | Private durable directory outside `public/`; same absolute path and actual volume on every replica. Do not use project root or `/`. |
| `BOOK_SECURE_COOKIE` | Base Compose `0`; image default `1` | Set `1` for HTTPS; `0` only isolated HTTP. Custom domains and OIDC require HTTPS. |
| `BOOK_ADMIN_HASH` | Unset | Legacy bootstrap only; prefer `identity.php init` via stdin. Do not replace an existing owner's password via this setting. |
| `BOOK_IMAGE_TARGET` | `production` | Source builds only; `test` only disposable CI. Signed deployment uses image digests. |
| `BOOK_SHARED_VOLUME` | Unset | Host-side external POSIX Docker volume name for `compose.shared-storage.yaml`. Verify locks and common visibility on all hosts. |

## PostgreSQL

| Name | Meaning / example |
| --- | --- |
| `BOOK_PG_DSN` | Native PDO `pgsql:host=db.internal;port=5432;dbname=book;connect_timeout=5`; Compose uses `postgres` service. Use a verified TLS DSN for remote connections. |
| `BOOK_PG_USER` | Application role, Compose `book`. |
| `BOOK_PG_PASSWORD` | Application password, required by SQL overlays; not admin password. |
| `BOOK_PG_ADMIN_PASSWORD` | Host-side Compose bootstrap password for `book_admin`, initial PGDATA only. |
| `BOOK_PG_NAMESPACE` | Permanent unique 32 lowercase hex digits. Generate once (`openssl rand -hex 16`). |
| `BOOK_MIGRATION_PG_USER`, `BOOK_MIGRATION_PG_PASSWORD` | Optional pair for the migration CLI, not ordinary app. Own relevant SQL objects/DDL and database CREATE where needed. |

Schema version 4 is shared across all namespaces in a database. PostgreSQL 16 is the tested major. The standard Compose app role owns the database but has no superuser/role/database creation privileges. Separate migration and least-privilege runtime roles require deliberate grants/ownership management; do not grant superuser to fix failed migration. Preserve SQL role credentials and namespace/binding markers together. App connections require writable primary. Do not connect with transaction-mode PgBouncer: session advisory locks must survive until connection close. Review [PostgreSQL migration](postgres-performance.md) and [distributed reliability](distributed-reliability.md).

## Redis / Sentinel

| Name | Meaning |
| --- | --- |
| `BOOK_REDIS_HOST`, `BOOK_REDIS_PORT` | Fixed primary endpoint, default port 6379. Set only with SQL; omit for file mode. |
| `BOOK_REDIS_PASSWORD`, `BOOK_REDIS_USER` | Redis authentication (user optional). Standalone Compose requires 64 lowercase hex password. |
| `BOOK_REDIS_SENTINELS` | Comma-separated three or five distinct host:port endpoints; not a URL. |
| `BOOK_REDIS_SENTINEL_MASTER` | Master name, default `book`. Use this exact variable name. |
| `BOOK_REDIS_SENTINEL_USER`, `BOOK_REDIS_SENTINEL_PASSWORD` | Separate Sentinel authentication. |
| `BOOK_REDIS_TLS`, `BOOK_REDIS_CA` | `1` enables TLS with peer verification; optional CA path. Mount CA into each relevant process. |

Sentinel configuration takes precedence over fixed-host discovery, with no fallback to a stale fixed primary. The overlay does not deploy Sentinel. For full enterprise monitoring the diagnostics service uses the same Sentinel values. Redis is ephemeral; no business data or permanent queue is sourced exclusively from Redis. Keep ports private; `compose.redis.yaml` has no host publication. Authentication limits fail closed where required, so Redis outage can affect API/search even though SQL remains healthy. See [failover](distributed-reliability.md).

## Secrets and external integrations

| Name | Meaning |
| --- | --- |
| `BOOK_GITHUB_KEY` / `BOOK_GITHUB_KEY_FILE` | Git credentials key (GitHub and GitLab); single 64 hex key or private file keyring. |
| `BOOK_AUTOMATION_KEY` / `BOOK_AUTOMATION_KEY_FILE` | Automation encryption key, independent from Git. |
| `BOOK_GITLAB_ORIGINS` | Operator-approved HTTPS GitLab origins. |
| `BOOK_WEBHOOK_ORIGINS` | Approved outbound webhook HTTPS origins. |
| `BOOK_PLAYGROUND_ORIGINS`, `BOOK_PLAYGROUND_PROXY_ORIGINS` | Approved browser/managed API destinations; check policy before adding private services. |
| `BOOK_SECURITY_SECRET_DIR`, `BOOK_OIDC_SECRET_DIR` | Host private directories for optional security overlay; file layout and permissions in [security lifecycle](security-lifecycle.md). |

Generate independent keys with `openssl rand -hex 32`; files must be absolute, regular, non-symlink, no group/other permissions, outside `public/`, readable by runtime UID (normally 33 in the image). Do not set both env and file forms. Keep keys separate from data backups; backup key rotation needs old keys until all corresponding archives expire. Do not allow arbitrary HTTP origins or disable certificate verification. Explicit trusted internal GitLab/OIDC origins require network egress policy.

## Public domains and OIDC

| Name | Meaning |
| --- | --- |
| `BOOK_PRIMARY_HOST` | Primary ASCII HTTPS host (no scheme/path). |
| `BOOK_ACME_EMAIL` | ACME operator contact in domains overlay. |
| `BOOK_DOMAIN_EDGE_SECRET` | Separate random 64 lowercase hex secret shared with trusted edge. Never accept this from public requests. |
| `BOOK_OIDC_CONFIG` | Legacy JSON configuration for provider `enterprise` and its managed organization; can coexist with distinct issuers. |
| `BOOK_OIDC_CLIENT_SECRET` / `BOOK_OIDC_CLIENT_SECRET_FILE` | Confidential client secret (file is a single value, not keyring). |
| `BOOK_OIDC_PROVIDERS` | JSON map of additional named providers. Per-provider secret `BOOK_OIDC_<NAME>_CLIENT_SECRET` or `_FILE`; names uppercased with `-` changed to `_`. See [multi-provider guide](skif4er-id.md). |
| `BOOK_OIDC_REQUIRED` | `1` requires SSO for non-owner local accounts; test emergency owner first. |
| `BOOK_SKIF4ER_CLIENT_ID` | Dedicated SkiF4er ID confidential client (`skif4er`); see [multi-provider configuration and account linking](skif4er-id.md). |

Example JSON (replace the organization ID and all issuer/client/host values; create the organization via the UI first):

```json
{
  "issuer": "https://idp.example.org",
  "client_id": "book",
  "redirect_uri": "https://book.example.org/auth/oidc/callback",
  "organization": "0123456789abcdef0123456789abcdef",
  "label": "Company SSO",
  "groups": {"book-viewers": "viewer", "book-editors": "editor"},
  "group_claim": "groups",
  "auth_method": "client_secret_basic",
  "backchannel_logout": true
}
```

Pass the compact JSON as the environment value; `BOOK_OIDC_CONFIG` is not a path. Exact issuer origin for discovery/token/JWKS, RS256, code + PKCE S256, valid TLS, and exact callback are required. Configure the IdP with the same redirect URI; for back-channel logout register `/auth/oidc/backchannel-logout` on the primary host. Use `php bin/oidc.php check` and a real login/logout before enabling required SSO. Review [security lifecycle](security-lifecycle.md). Custom domain: Workspace → Domains, add host, publish the displayed TXT proof, wait for verification and DNS, then ensure ACME and HTTPS succeed. Domain lease lasts seven days, rechecked hourly by domain-check; no authenticated access on custom domains. Never share internal ingress secret with a browser.

## Backups and PITR

| Name | Meaning |
| --- | --- |
| `BOOK_BACKUP_DIR` | Private backup spool (image backup service `/var/lib/cajeerbackup`), separate from data. |
| `BOOK_BACKUP_KEY_FILE` | Runtime keyring file for encrypted archives, required. |
| `BOOK_BACKUP_KEY_PATH` | Host path mounted by backup/PITR overlays, not the runtime variable. |
| `BOOK_BACKUP_INTERVAL` | Seconds; default 86400, 300–604800. |
| `BOOK_BACKUP_RETENTION_DAYS` | Local retention; default 30, 1–3650. Remote objects are not automatically deleted. |
| `BOOK_BACKUP_REMOTE_DIR` / `BOOK_BACKUP_S3_URI` | Independent native filesystem or S3 URI, choose one; enterprise requires S3. |
| `BOOK_BACKUP_S3_ENDPOINT` | Optional HTTPS S3-compatible endpoint. |
| `BOOK_BACKUP_PG_DSN` | Backup overlay source DSN when not using enterprise overlay. Must match actual source SQL installation. |
| `BOOK_BACKUP_DRILL_PG_DSN`, `BOOK_BACKUP_DRILL_PG_USER`, `BOOK_BACKUP_DRILL_PG_PASSWORD` | Separate drill database and role, never source DB. |
| `BOOK_BACKUP_MAX_AGE` | Expected maximum verified capture age, default 90000 seconds. |
| `BOOK_PITR_CONFIG_PATH`, `BOOK_PITR_REPOSITORY_PATH` | Host-side private pgBackRest config and independent physical repository mount. |
| `BOOK_PITR_PG_USER`, `BOOK_PITR_PG_PASSWORD` | Restricted control role for WAL restore point, not a web credential. |
| `BOOK_PITR_DIR` | Runtime private spool for encrypted application sidecars. |
| `BOOK_PITR_S3_URI` | Host-side sidecar repository/prefix distinct from ordinary backups; uses the shared `BOOK_BACKUP_S3_ENDPOINT` setting. |

AWS credentials (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, optional `AWS_SESSION_TOKEN`, `AWS_DEFAULT_REGION`) are operator-managed, only mount/pass to backup/PITR process; scope them to the intended prefix and protect independently. Use encrypted disk for staging. For PITR pair physical WAL and sidecar; a standalone pg_dump or arbitrary WAL timestamp does not capture media. See [disaster recovery](disaster-recovery.md) for role grants, scheduling and isolated replay. Retention must preserve complete physical chains, WAL, sidecars and historical keys. Test remote-only recovery, not just creation.

## Monitoring and release/update

| Name | Meaning |
| --- | --- |
| `BOOK_OBSERVABILITY` | `1` enables bounded shared-volume telemetry. |
| `BOOK_OBSERVABILITY_EXPORTER` | `1` only dedicated private diagnostics service. |
| `BOOK_METRICS_TOKEN_FILE` | Runtime private bearer token file (64 lowercase hex). |
| `BOOK_METRICS_TOKEN_PATH`, `BOOK_GRAFANA_PASSWORD_PATH`, `BOOK_ALERT_WEBHOOK_PATH` | Host-side private file paths for monitoring overlay. |
| `BOOK_MONITOR_WORKERS`, `BOOK_MONITOR_BACKUP`, `BOOK_MONITOR_DOMAINS`, `BOOK_MONITOR_TLS` | `1` enables expected-service probes. Enterprise overlay sets these expectations. |
| `BOOK_RELEASE_PRIVATE_KEY_B64`, `BOOK_RELEASE_PUBLIC_KEY_B64` | Protected GitHub environment secret/variable for release signing; not runtime settings. |

Use [observability](observability.md) and [signed releases](signed-releases.md). The operator updater is host-side and accepts explicit version/repository/key/environment/overlay configuration; see [secure updates](secure-updates.md). Do not mix updater state into application data or allow the web user to edit it. Keep its state backup (high-water mark) and release public key independently protected.
