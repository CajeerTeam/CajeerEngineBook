# CajeerEngine Book Edition

Self-hosted documentation and knowledge-base platform based on CajeerEngine 1.3.0 (source snapshot `8edfc810c0fa690118bb3a599b7e349e0847420c`). Book 0.4.3 is a separate, focused edition: it reuses the upstream CJR template engine, ServiceContainer and durable filesystem primitives, not the full CMS kernel or its database. Apache-2.0; see LICENSE and NOTICE.

## 0.4.3 — Full-Text Search

The Reader and existing `GET /api/v1/search` now use a PostgreSQL published-document index in SQL mode. Weighted title, section, normalized path and rendered Markdown body are indexed by GIN (`simple`, `english`, `russian`); queries support multiple required terms, prefixes, quoted phrases and English/Russian stemming. Ranking uses `ts_rank_cd`; single unquoted terms with no exact/full-text matches can use indexed `pg_trgm` title/path similarity. Snippets are plain text, with bracket match markers, and escaped by the Reader. Workspace scope and current access rules restrict documents **before** ranking, counting and snippet generation. Drafts never enter this index. Publish, unpublish, rename, book visibility changes and CI publishing update the projection in the same transaction as the content revision. A stale/missing index is an error, not a fallback to old files.

Search accepts `q`, optional `book` and `version` slugs, `limit` (1–50) and `offset` (0–1000). The API retains its JSON array and adds `snippet` and `score` per hit; `X-Total-Count`, `X-Search-Engine`, and `X-Search-Match` report authorized count, backend and match mode. The Reader offers filters and pagination. Owners/admins can use **Studio → Search inspector** to inspect counts, revision and query ranking with public/member visibility. File-backed installations have a bounded in-process lexical search with the same access rules and API; PostgreSQL-only stemming, trigram matching and persistent indexes require migration. Static Export's offline search is unchanged.

### Upgrade an existing PostgreSQL 0.4.2 installation

1. Stop all application and worker writers. Make a complete backup using the **old 0.4.2 code** (`php bin/installation.php backup /secure/book-042-backup`). Keep the same database, namespace, credentials and data directory.
2. Overlay the 0.4.3 files, rebuild the app image if applicable, and install Composer dependencies. PostgreSQL 16 is the target. The application database role must be able to create the trusted `pg_trgm` extension (database CREATE privilege), or an administrator must preinstall it in a schema usable by the application role. The schema migration respects an existing extension's schema.
3. With the normal PostgreSQL environment and data-directory permissions, run:

   ```sh
   php bin/search.php reindex --offline
   php bin/search.php check
   ```

   With Compose, use `docker compose -f compose.yaml -f compose.postgres.yaml run --rm --no-deps --user www-data book php bin/search.php reindex --offline` and repeat with `check`. Reindex creates the schema and reconstructs every registered scope (including archived workspaces) from **current SQL** in a single transaction; it does not reimport JSON. The check reports indexed document counts per scope and verifies revision, counts and index validity. Do not start the new application until it passes. Fresh and direct file migrations build the index as part of `bin/postgres-content.php migrate --offline`; for an upgrade from 0.4.1 follow the identity upgrade below, then run the search check/reindex.
4. Start services and check `/health/ready`, Reader search, authenticated workspace search and Studio. Use the same offline reindex procedure to recover the derived index; portable backups do not need to include it. Current content/identity quotas remain in place. The index avoids loading all page bodies per query; no large-installation performance SLA is asserted.

## 0.4.2 — PostgreSQL Identity & Migration

PostgreSQL now stores the installation's identity as well as content. `IdentityStore` uses the live SQL backend for login, logout, password reset, session expiry/revocation, account management, invitations, organizations, workspace registry, membership, RBAC and identity activity. Users, sessions, reset digests, rate limits, organizations, invitations, workspaces and activity have individual records. Organization membership and role assignments and workspace overrides are independent relational records with installation-scoped foreign keys; workspace records also reference the exact SQL content scope. Unique username/email/slug constraints, owner validation and the original permission ceilings remain in force. Passwords retain their existing hashes; bearer credentials are not stored in plaintext. JSON record payloads preserve legacy data and key order. The SQL identity format is version 1; the portable identity format remains schema 4.

All authorization and identity mutations acquire the installation identity row lock before any workspace/content lock. A new workspace's registry, activity and empty content scope commit on **one SQL transaction**; a failed insert rolls them all back. Media directories for new empty workspaces are created lazily, so an aborted transaction leaves no directory to reconcile. The existing permission checks apply to HTTP, Studio, API, workers and CLI. PostgreSQL outage, missing identity state, wrong binding or removed DSN fail closed; there is no fallback to stale identity files. Existing `identity.required` anchors are verified, and SQL also stores the immutable installation owner anchor. The durable `postgres.identity.required` cutover marker distinguishes a genuine 0.4.1 installation from a damaged 0.4.2 database; missing SQL identity after cutover requires recovery, never reimport of stale JSON. Existing file-mode installations remain supported until explicitly migrated. Identity quotas (500 users / 4 MiB serialized state) and the installation-wide authorization lock are retained; this release does not claim distributed/high-throughput runtime or Redis.

### Upgrade an installation already using PostgreSQL 0.4.1

1. **Before replacing 0.4.1 code**, stop all web/worker/standalone writers. With the original PostgreSQL configuration and original `BOOK_DATA_DIR`, use 0.4.1 to run `php bin/installation.php check` and `php bin/installation.php backup /secure/book-041-backup`. Retain the original directory, database backup (if available), namespace, `postgres.required`, media and protected integration keys. Repair any reported registry/media mismatches before proceeding.
2. Overlay the 0.4.2 files and rebuild the app image if using Docker. Keep the **same** PostgreSQL database, credentials, namespace and data directory; do not create a new namespace or unset `BOOK_PG_DSN` for this upgrade.
3. Run `php bin/postgres-content.php migrate --offline` as the application OS user, then `php bin/postgres-content.php check`. This branch creates the identity schema and imports the validated file identity in a SQL transaction. It checks the current SQL content scopes against the registry and audits actual SQL content/media, never stale `books.json`. It preserves user/workspace IDs, memberships, revisions, passwords, activity, current sessions, pending invitations, reset hashes and rate limits. Existing PostgreSQL content is not rewritten. The identity cutover marker is written after SQL commit; if that write fails, keep writers stopped and repeat the migration with the same configuration. Existing SQL records must match the original source exactly before the marker can be completed. A completed migration cannot be rerun to overwrite live identity.
4. Start web/worker and check `/health/ready`, login, a private workspace, RBAC, publication and export. The file `identity/identity.json` is now a historical source only: secure it and never use it as an operational backup. Do not downgrade only the PHP code or remove markers. To roll back, restore the matched pre-upgrade backup to an isolated installation (changes after the backup will be lost).

For the optional Compose deployment use the existing overlay with the same project/volumes:

```sh
docker compose -f compose.yaml -f compose.postgres.yaml stop book worker
docker compose -f compose.yaml -f compose.postgres.yaml build book
docker compose -f compose.yaml -f compose.postgres.yaml up -d --wait postgres
docker compose -f compose.yaml -f compose.postgres.yaml run --rm --no-deps --user www-data book php bin/postgres-content.php migrate --offline
docker compose -f compose.yaml -f compose.postgres.yaml run --rm --no-deps --user www-data book php bin/postgres-content.php check
docker compose -f compose.yaml -f compose.postgres.yaml up -d --wait book
# If used: docker compose -f compose.yaml -f compose.postgres.yaml --profile automation up -d worker
```

If the installation is still file-backed, use the PostgreSQL deployment procedure below. A direct migration now transfers identity and content together in one SQL transaction, including all archived workspaces. Identity schemas 1–4 and content schemas 1–6 are supported. A fresh installation initializes an empty SQL identity (use `bin/identity.php init` or the existing first-admin flow). No post-cutover `identity.json` is created by the application.

## PostgreSQL deployment — content and identity (0.4.1–0.4.3)

PostgreSQL is an opt-in **active content backend**, not a mirror or search-only database. In this mode every BookStore read and mutation (Reader, Studio, API, page tree, history, review, media metadata, Git/OpenAPI import, CI build/publish, automation and Static Export) uses PostgreSQL. Each workspace has an installation-scoped transaction row; shared locks pin reads/exports, exclusive locks serialize writes and media lifecycle changes. Changed records are inserted/updated/deleted in a single transaction, including history, review state, CI and activity. Existing page revision and review checks remain in force. The `cajeer_book` schema stores individual pages (draft and published snapshots), books, media metadata, revisions, reviews, CI builds/tokens, automation jobs/receipts and other content records in partitioned relational tables. JSON values retain the original PHP key order and snapshot/digest semantics; this is not a whole-workspace JSON document in one SQL row. Existing page quotas and the portable 32 MiB metadata limit remain. The public API and URLs do not change.

**Scope:** PostgreSQL 16 is the deployment/test target. In 0.4.2 both identity and content metadata are SQL-authoritative; immutable media bytes, binding markers and integration keys remain in the protected `BOOK_DATA_DIR` and must be preserved. File-mode installs continue to work without PostgreSQL until explicitly migrated. Full-text indexing is available in 0.4.3; Redis and distributed workers are subsequent releases. Use the preceding upgrade instructions if already on PostgreSQL 0.4.1; the following procedure is for file-backed or fresh installations.

### Migrate a file-backed or fresh native installation

1. Install PHP `pdo_pgsql` and the existing locked Composer/Mermaid dependencies. Provision a dedicated PostgreSQL database and a non-superuser role that owns it (or an equivalent role with permission to create/use the `cajeer_book` schema and its objects). Use UTF-8, persistent storage and trusted database TLS (`sslmode=verify-full` plus the correct CA for remote deployments).
2. **Stop all web processes, workers and standalone writers**. With the original data directory selected and `BOOK_PG_DSN` unset, run the full portable installation backup before enabling PostgreSQL. Verify and retain it outside the data directory. Use `php bin/installation.php check` to audit the source; repair any reported legacy media reference indexes with the documented `verify-data.php --repair-media-references` before migration. For old adopted seed-only installs, use the existing explicit legacy seed materialization command if appropriate.
3. Configure the following variables for the same restricted OS user that owns the data directory. Generate a new random namespace once per installation and keep it permanently in protected deployment configuration. Do not put secrets in a DSN, source control or logs.

```sh
export BOOK_DATA_DIR=/var/lib/cajeerbook
# Run before setting BOOK_PG_DSN; destination must be new and outside BOOK_DATA_DIR.
php bin/installation.php backup /secure/book-040-backup
export BOOK_PG_NAMESPACE="$(php -r 'echo bin2hex(random_bytes(16));')"
export BOOK_PG_DSN='pgsql:host=127.0.0.1;port=5432;dbname=book;connect_timeout=5'
export BOOK_PG_USER=book
# Supply BOOK_PG_PASSWORD from your protected deployment configuration.
php bin/postgres-content.php migrate --offline
php bin/postgres-content.php check
```

4. Start web and worker with the same `BOOK_DATA_DIR`, `BOOK_PG_DSN`, `BOOK_PG_USER`, `BOOK_PG_PASSWORD`, `BOOK_PG_NAMESPACE` and existing integration keys. Check `/health/ready`, login, Reader, Studio, a private workspace, media and CI publication. `migrate --offline` also initializes a fresh installation (the normal demo seed unless a portable store is present). It creates no new `books.json` or `identity.json` after cutover, including new workspaces and sessions.

Migration audits every registered workspace (including archived ones) and the legacy root, verifies media integrity and the portable metadata quota, inserts all content and the full validated identity state in one SQL transaction, and checks exact round-trip of the source arrays. `postgres.migrating` is a durable migration binding; `postgres.identity.required` and `postgres.required` are written only after SQL commit. They prevent accidentally attaching another installation and prevent file-mode fallback after migration starts. If migration fails, **keep services stopped**, fix the source or connectivity and rerun with the same namespace and pending marker. If SQL committed but the final marker could not be written, the rerun accepts existing rows only when every record and scope matches exactly. Do not delete markers to switch backends. The pre-cutover JSON files are retained as historical source files; after cutover they are stale and must not be used for reads, writes or backup. A database outage/missing scope or a registry mismatch fails closed. Never downgrade only the application or restore a stale JSON directory after SQL writes. For rollback restore the matched pre-upgrade installation backup, accepting loss of changes made after it, or export a fresh portable backup while PostgreSQL is healthy and restore it offline to a separate location.

### Docker Compose (optional PostgreSQL mode)

The original `compose.yaml` remains file-mode compatible. The `compose.postgres.yaml` overlay adds a private PostgreSQL 16 service and persistent `book-postgres` volume. Its first initialization creates a non-superuser `book` application role owning the dedicated `book` database; the separate admin password is used only by PostgreSQL. For existing installs, first export a full portable backup outside the book volume and stop all writers. Preserve the current `book-data` volume and Compose project name. Set `BOOK_PG_NAMESPACE`, `BOOK_PG_PASSWORD` and a separate `BOOK_PG_ADMIN_PASSWORD` in protected environment/0600 `.env` (generate passwords with a secure random generator). Retain the namespace and passwords across restarts; changing initialization variables does not rotate credentials in an already initialized PostgreSQL volume.

```sh
docker compose stop book worker
docker compose -f compose.yaml -f compose.postgres.yaml build book
docker compose -f compose.yaml -f compose.postgres.yaml up -d --wait postgres
docker compose -f compose.yaml -f compose.postgres.yaml run --rm --no-deps --user www-data book php bin/postgres-content.php migrate --offline
docker compose -f compose.yaml -f compose.postgres.yaml up -d --wait book
# Optional automation worker:
docker compose -f compose.yaml -f compose.postgres.yaml --profile automation up -d worker
```

The app and worker share the same `book-data` volume and PostgreSQL configuration. No database port is published. Do not use `down -v` for a real installation. Always use the overlay for app/worker operations after switching.

### Backup, recovery and tests

`php bin/installation.php backup NEW_DIRECTORY` reads the live PostgreSQL content through the workspace snapshot transactions and includes identity, media, history, CI and all registered scopes. The portable TAR/installation formats remain compatible. Stop writers for a complete cross-workspace recovery point; identity is read from PostgreSQL, but original media bytes are not in PostgreSQL. Keep integration encryption keys separately, preserve both PostgreSQL binding markers for database-native backups, and never rely on copying `books.json` or `pg_dump` alone. To restore a portable installation backup, restore to a **new offline directory**, set that directory as `BOOK_DATA_DIR`, use a **new** namespace, and run `postgres-content.php migrate --offline` and `check` before switching traffic. Restored sessions are intentionally invalidated by the existing portable backup format; rotate restored CI/integration credentials as needed. Direct identity-only or workspace-only restore into a live PostgreSQL installation is refused in this release; use the full portable recovery path. Workspace creation now commits registry and content together. Pre-existing orphan scopes/directories from 0.4.1 still cause readiness, migration and backup to refuse registry mismatches rather than silently omit content. Preserve and inspect such data before operator recovery. Do not manually delete SQL rows to repair a live installation.

- File backend regression suite: `env -u BOOK_PG_DSN composer test`.
- Real PostgreSQL tests (disposable database only): set `BOOK_PG_DSN`, `BOOK_PG_USER`, `BOOK_PG_PASSWORD`, then run `php tests/postgres-content.php` and `php tests/postgres-identity.php`. The identity suite upgrades a 0.4.1 SQL-content/file-identity fixture, verifies migrated credentials and role rows, injects a database failure after scope creation, checks concurrent revocation and restores a new namespace.
- `php tests/postgres-identity-policy.php` checks serialization, legacy schema upgrades and fail-closed behavior without PostgreSQL; it does not substitute for the real SQL suite.
- Real HTTP CI integration with PostgreSQL: `python3 tests/ci-http.py --postgres`. It runs Markdown/media import, RBAC, review, atomic publication, rollback and static artifact export on actual PostgreSQL.
- GitHub CI has a dedicated PostgreSQL 16 service job; existing file-mode and Docker release tests remain. `php tests/postgres-policy.php` tests serialization and fail-closed configuration without a DB; it is not a database integration test.

## 0.4.0 — Developer Documentation Stable

The developer documentation release path connects Markdown/Git import, checked CI builds, independent review, atomic publication and standalone static delivery. A public CI release can now be exported as a ZIP with `release.json` identifying its exact build, digest, scope, commit and publication timestamp. The server checks the current published page identities, paths, snapshots and referenced media against the retained published bundle under the same store snapshot lock as ZIP generation. New drafts do not change the artifact; changed publication, changed public tree, missing or corrupt media, or a removed build prevent export. The current renderer, assets, link aliases and public redirects are used, so a repeated export need not have identical ZIP bytes. The build digest identifies the checked bundle, not the ZIP.

```sh
# After publishing the exact approved bundle, with BOOK_CI_URL and BOOK_CI_TOKEN set:
python3 bin/ci.py export "$id" "$digest" site.zip
```

The command uses the existing POST CI endpoint (`{"action":"export","id":"...","digest":"..."}`). A publish-capable token scoped to that book/version is required; build-only tokens, expired/revoked tokens and tokens whose issuer lost access are denied. Only public workspaces and public-capable books can be exported by CI. Studio's explicitly confirmed private export remains separate. The ZIP response uses `application/zip`, `Cache-Control: private, no-store`, and `X-Book-Artifact-SHA256`. The client verifies content length, SHA-256, archive paths and release identity, then installs the ZIP without overwriting any existing file; interrupted or invalid responses leave no partial file. The hash is a transfer-integrity check over HTTPS, not a digital signature. A 409 indicates the requested publication no longer matches; rebuild/review/publish instead of silently exporting a different release. A 403 indicates authorization failure. Do not publish private artifacts to public hosting. The example GitHub/GitLab pipelines opt into static artifact delivery with `BOOK_EXPORT_STATIC=1` (public content only).

### Release operation

- Existing Schema 6 data remains compatible; back up the installation with `php bin/installation.php backup /safe/new-backup-directory` before upgrading, and retain the 0.3.9 release and its backup for rollback.
- Install locked Composer dependencies, `sh bin/install-mermaid.sh`, and required PHP extensions. `/health/ready` checks required browser/SDK assets and store readiness; `/health/live` is only a process liveness probe. Readiness does not substitute for the full test suite.
- `composer test` runs server, HTTP and CLI integration checks. CI also runs the real Chromium reader/editor/export suites and a Docker/Apache release gate, including the CI artifact path and restart persistence. Use a disposable data directory and test credentials. The local release gate is `python3 tests/release-http.py`; set `BOOK_TEST_URL` and `BOOK_TEST_PASSWORD` only for a disposable deployment.
- Production: terminate HTTPS at a trusted reverse proxy, set `BOOK_SECURE_COOKIE=1`, use a private persistent `BOOK_DATA_DIR`, keep Composer/Mermaid assets installed, and configure scoped integration credentials and origins. Never expose the project root as the web document root; serve `public/` only. Run the automation worker when using Git webhooks.

## 0.3.9 — Static Export

Open **Studio → Static Export** as Owner/Admin. Select all books or one book/version, choose public published content (default) or explicitly confirm the export of private published pages, then download the ZIP. Extract it and open `index.html` directly in a browser, or deploy the complete directory to a static web host at any URL prefix. No PHP, database, CDN or network is needed for exported content. The export is an immutable snapshot; regenerate it after publishing changes.

- Published page tree, previous/next navigation, version switching, heading anchors, moved-page redirects and rich Markdown blocks; local styles, theme switching, tabs, code and image viewers.
- `search.html` searches a local JavaScript index of rendered page text, titles and paths. It ranks titles above body matches and displays safe text snippets. Search works on `file://` without fetch; requires JavaScript.
- Referenced uploaded images and attachments are copied byte-for-byte after MIME/size/SHA-256 verification. Mermaid diagrams use the existing strict opaque-origin sandbox and locally installed pinned Mermaid library. Run `sh bin/install-mermaid.sh` before exporting diagrams (already installed in Docker). No remote image requests are made; remote images become their alt text, unavailable internal destinations are inert, and external hyperlinks remain external. API Playground, SDK generation, Studio and other dynamic actions are not exported.
- Only the selected workspace's published snapshots are read. Public exports exclude private books and private workspaces, even for an administrator. Explicit private exports have **no authentication or revocation**; treat the ZIP and extracted files as confidential. Drafts, revision history, review content, credentials, integration state and unused media are not exported. The archive is a site, not a restorable backup.

CLI (trusted installation operator; existing destination is never overwritten):

```sh
php bin/static-export.php /tmp/docs.zip
php bin/static-export.php /tmp/guide.zip --scope=handbook/1.0
php bin/static-export.php /tmp/team.zip --workspace=WORKSPACE_ID --include-private
```

`BOOK_DATA_DIR` selects the installation; omit `--workspace` for legacy content. The CLI emits counts for pages, media, redirects and omitted references. `README.txt` in each site describes deployment and privacy. Page directories use `p-` prefixes to avoid filesystem name collisions, with relative links and explicit `index.html` files. Export generation is capped at 256 MiB of generated HTML, 64 MiB of search index, existing 512 MiB media quota and 10,000 redirects; split a large installation by book/version. The shared store lock protects the snapshot and media until ZIP close; a failed build never publishes a partial ZIP.

## 0.3.8 — CI/CD Publishing

Studio → **CI/CD Publishing** supports checked, immutable releases of an entire book version. Owner/Admin creates a scoped service token (1–90 days, build-only or build + publish); copy the secret once. Only its SHA-256 hash is persisted. Revocation, expiry, issuer role and workspace state are checked on every API call. Use separate build and deployment credentials and protect the deployment environment. Never put credentials in source or artifacts.

Set `BOOK_CI_URL=https://book.example.org/w/ORG/WORKSPACE/api/v1/ci` (legacy: `/api/v1/ci`) and `BOOK_CI_TOKEN` in protected environment variables. Copy `bin/ci.py` into the documentation repository. The Python 3 client uses HTTPS, no redirects, and outputs JSON. Example GitHub Actions and GitLab CI pipelines are in `examples/ci/`. Configure Git webhooks and the existing automation worker before using the Git pipeline.

```sh
python3 bin/ci.py state > state.json
expected=$(python3 -c 'import json; print(json.load(open("state.json"))["fingerprint"])')
python3 bin/ci.py build --request release-1 --expected "$expected" > build.json
# If review is required, a different authorized human approves this exact build in Studio.
id=$(python3 -c 'import json; print(json.load(open("build.json"))["id"])')
digest=$(python3 -c 'import json; print(json.load(open("build.json"))["digest"])')
python3 bin/ci.py publish "$id" "$digest"
```

For Git releases, use `wait-sync --commit SHA` to wait for the cursor, then build with `--commit SHA` and the returned fingerprint. Git-labelled builds require every page in the book/version to match its synchronized source; untracked pages and local changes are rejected. For mixed manual/generated content build without `--commit`. Alternatively `import DIRECTORY --expected FINGERPRINT` imports Markdown and attachments into drafts; `--allow-update` explicitly permits overwriting existing drafts. Absent files are not deleted. Use the returned fingerprint for the build. `status ID` retrieves current release state. Idempotency keys bind the requester, source and expected fingerprint; repeat publication of the same digest does not advance revisions again.

Builds validate Markdown rendering, OpenAPI storage integrity, internal documentation/media links and binary media hashes. External URLs and anchor existence are not checked. The exact draft and previous publication snapshots, source commit, policy and hashes are retained. Studio shows the captured source and diff. Mandatory review requires a human Admin/Owner independent of the requester and known draft contributors. CI cannot approve. Any scope change, publication-policy change or changed Git mapping invalidates an outstanding build; the publisher and approver must still have current rights. Publishing commits all pages in one transaction, including history and outbox events. Existing public paths/order remain governed by the separate tree workflow. No implicit deletion or unpublish. Restore a published release as drafts (same page IDs/paths required), then build and approve again. Rollback does not bypass review.

Builds execute synchronously under existing authorization and BookStore locks; Git fetch remains in the automation worker. Limits: 200 pages, 4 MiB snapshot, 20 retained builds, 20 credentials, 8 MiB CI metadata per workspace, subject to overall BookStore limits. CLI import accepts 200 files/8 MiB and a 12 MiB JSON request. Prune terminal builds in Studio to free space. Build events `build.ready`, `build.approved`, `build.published` can be delivered through the existing signed outbox. Retained build snapshots protect referenced media from deletion.

Back up the complete installation before upgrading. BookStore schema 6 is activated by CI configuration/builds; schemas 1–5 continue to work. Backups include bundles and hashed credentials; restoring an old backup may restore previously revoked credentials, so revoke/rotate them after restore. Older releases cannot open schema 6; rollback requires the pre-upgrade backup. No new runtime dependencies. Test `php tests/ci-publishing.php` and `python3 tests/ci-http.py`; `--policy-only` checks storage/auth/publication with prepared validated fixtures without CommonMark. Full tests validate and import real Markdown and restore TAR.

## 0.3.7 — Webhooks & Automation

In Studio → **Automation**, an Owner/Admin can configure one inbound Git webhook and one outgoing HTTPS destination per workspace. Configure Git first for inbound delivery. Automation imports drafts only; it never publishes. Incoming events synchronize the current configured branch HEAD at a verified immutable commit, not an old event's commit. Conflicts stop for manual Studio resolution.

### Deployment

```sh
export BOOK_AUTOMATION_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export BOOK_WEBHOOK_ORIGINS="https://receiver.example.org"
docker compose --profile automation up -d --build
```

Keep the key permanently in protected deployment configuration; both web and worker need it. Use separate random 32–128 printable non-space ASCII inbound/outbound secrets (`openssl rand -hex 32`). Git still uses `BOOK_GITHUB_KEY` and `BOOK_GITLAB_ORIGINS`. Without Docker run `BOOK_DATA_DIR=/var/lib/cajeerbook php bin/worker.php --loop` as the same restricted user as the web server under a supervisor. `--once` processes at most one due job per workspace. Single-node only: an installation-wide flock serializes workers and permits immediate abandoned-claim recovery. Network operations release Identity/BookStore locks and reauthorize before applying changes.

Copy the URL displayed in Automation to the provider. GitHub: JSON payload and push/pull request events, configured secret. GitLab: push/merge request events, configured secret token. Scoped URLs `/w/{org}/{workspace}/webhooks/github` and `/w/{org}/{workspace}/webhooks/gitlab`; legacy `/webhooks/github` and `/webhooks/gitlab`. Serve HTTPS externally. GitHub validates `X-Hub-Signature-256` over the raw body; GitLab validates `X-Gitlab-Token`. Only configured repository/project and branch, push and merged PR/MR trigger synchronization. Deduplication uses delivery ID and raw body within the receipt window; same ID with different body is rejected. HTTP 202 means durably queued, 200 ignored/duplicate, not sync success. Check jobs in Studio.

### Outgoing delivery

Select events and an exact HTTPS destination in `BOOK_WEBHOOK_ORIGINS`; no query, credentials, fragments, traversal or redirects. Events: `document.changed`, `document.published` (public snapshot change including unpublish), `review.requested`, `review.approved`, `git.sync.completed` (actual Git sync change), `git.sync.failed`, `openapi.updated`. Event JSON is `{"id":"...","event":"document.changed","at":1760000000}`; no Markdown, private paths, review comments or credentials. Event enqueue and content mutation commit together; multiple changes in a transaction are coalesced by type. Headers: `X-Cajeer-Delivery`, `X-Cajeer-Timestamp` (Unix seconds), `X-Cajeer-Signature: sha256=<hex>`. Verify HMAC-SHA256 using outgoing secret over `timestamp + "." + raw_body` before parsing; validate timestamp freshness (e.g. five minutes) and deduplicate delivery ID in receiver's own transaction. Retries preserve ID and body with fresh timestamp/signature. HTTPS uses pinned globally routable IPv4, certificate/hostname validation, 15-second wire deadline and bounded response; private destinations are not supported. Use trusted DNS and egress policy.

Jobs persist with queued/running/succeeded/dead/cancelled, attempts, due time and lease fencing. HTTP 2xx completes; 408/425/429/5xx and network/provider failures retry after 30/60/120/240 seconds, at most five attempts; other 4xx/3xx or conflicts stop. Dead jobs can be retried in Studio. Delivery is **at least once**, not exactly once: a lost response or crash after receiver action can repeat the request. Completed Git import and job completion commit atomically. Reconfiguration (including rotation) cancels active jobs; rotate provider secret too. Revoked administrator rights or replaced Git connection prevent applying fetched changes; transmitted external actions cannot be recalled.

Limits per workspace: 512 retained jobs, 448 pending (64 sync), 2048 receipt keys (two per event), seven-day receipt TTL; unexpired receipts are never evicted (new events rejected at quota), oldest terminal jobs are evicted, never pending work. Body limit 1 MiB. Full outbox rejects initiating mutation instead of losing events: drain/retry or disable explicitly. The configuring administrator must remain enabled with effective Admin/Owner access; reconfigure if that account changes. Archived workspaces are not processed. SDK downloads are not persisted events; automatic OpenAPI fetch, SDK and static builds are outside this release.

Back up entire data directory and both encryption keys separately. BookStore schema 5 on automation configuration; TAR/installation backup includes queue, receipts, encrypted secrets and configuration. Stop original worker before restoring/migrating; restored jobs may redeliver, receivers must deduplicate. Older versions cannot read schema 5; rollback requires pre-upgrade backup. No new runtime dependencies. Test: `php tests/automation.php`, `python3 tests/automation-http.py`, `python3 tests/automation-https.py`; `--policy-only` for first two without CommonMark. TLS test uses a local trusted fixture through private wire primitive; production rejects local addresses.

## 0.3.6 — SDK & Code Examples

Open a supported published OpenAPI operation in Reader or Playground and choose **SDK & Code Examples** (`/sdk?key=...`, scoped to the current workspace). Select cURL, TypeScript/JavaScript, Python or PHP, an available server and authentication alternative. Examples use placeholder values and credentials; replace them and populate the request body according to the Reference before sending. No request is executed by the example page. The operation's SDK includes every supported currently visible published operation of the same Reference. Unpublished, unsupported or inaccessible operations are excluded.

### Download and use

Download a TypeScript, Python or PHP ZIP. Each contains `README.md`, `operations.json`, `publication.json`, license/notice, client implementation and a runnable example. The generated version (`0.3.6+<digest-prefix>`) identifies the exact set of published operation bindings and runtime code. Operation methods have a readable method/path prefix and a stable hash suffix; the operation page and README list their exact names and parameter order. Select the server and security alternative by zero-based index, supply scalar parameter values as strings in declared order (null/None for optional omissions), auth credentials in the selected alternative's order, and content type/body when required. The client returns HTTP status, headers and bytes, with JSON/text helpers; non-2xx status codes remain responses, while validation and transport errors throw.

- TypeScript/JS: Node.js 22+ or a modern browser supporting Fetch; `client.mts`, declarations and ready-to-run `client.mjs`/`runtime.mjs`. Edit `example.mjs`, run `node example.mjs`. Optional local `npm install ./extracted-sdk` and import the package name from its `package.json`. Browser CORS applies; cookie parameters/auth require Node.js. TypeScript declarations and source can be checked with `tsc --strict --module nodenext --target es2022 --noEmit example.mts`.
- Python 3.10+: `cajeer_sdk` uses stdlib urllib; run `python3 example.py` or install with `pip install .` (setuptools build backend). No runtime dependencies.
- PHP 8.1+: `Client.php` and `Runtime.php` use HTTPS streams, OpenSSL and `allow_url_fopen`; run `php example.php` or load the client via Composer. No runtime library dependencies.

Generated SDKs make direct HTTPS requests from the caller's environment (not via Book or its managed proxy). TLS verification is enabled, redirects are not followed, no automatic retry, 256 KiB request-body / 1 MiB response caps. JavaScript has a 15-second abort deadline; Python and PHP use 15-second socket timeouts, not an overall wall-clock deadline. Only the published server list and supported operations are used, but the caller is responsible for API credentials, network access, API authorization and any side effects. The Book Playground origin allowlist controls Book's interactive transports, not downloaded code. SDKs implement the same executable subset as Playground: scalar parameters, Bearer/Basic/API Key alternatives, JSON/form/text bodies. Full schema model generation, OAuth flows, multipart, package registry publishing and multi-language dependency resolution are not supported. The Python module and PHP namespace are fixed; keep different generated versions in separate projects.

### Access, versioning and upgrade

The SDK uses Reader visibility and published snapshot binding, not the latest imported source. A download URL contains the publication digest; if an operation is changed, published or removed, reload the page to generate the current version. A reference may have independently approved operations from different source revisions; the digest identifies that exact visible publication set, not an atomic source revision. A downloaded archive is a standalone copy and cannot be revoked remotely. Do not distribute private SDKs outside their authorized audience. No secrets from Playground inputs, session tokens, Git credentials, identity data or draft source bundles are included. Published OpenAPI descriptions/servers/enum values should themselves be reviewed for confidentiality.

Generation runs locally from bounded validated definitions, with no external network, arbitrary template execution or external generator dependency. ZIPs are built in a private temporary directory and removed after reading; no persisted artifact or new schema. Existing backups preserve the published bindings required to regenerate packages. Upgrade from 0.3.5 by backing up the installation and rebuilding/redeploying the changed files; no migration or new Composer dependencies.

Tests: `php tests/sdk.php`, `python3 tests/sdk-clients.py` (Node 22, npm, TypeScript compiler 5.8.3, Python, setuptools/wheel, PHP, OpenSSL), and `python3 tests/playground-http.py` (or `--policy-only` without CommonMark). The SDK test generates real ZIPs from approved pages, builds and installs local npm/Python packages, compiles TypeScript and invokes all three clients against a local trusted HTTPS fixture. CI runs these plus existing regressions.

## 0.3.5 — API Playground

Open a published OpenAPI operation in Reader and choose **API Playground**. An operation must have a supported request definition and an enabled HTTPS server. Select the server, enter parameters, authentication and a request body where applicable, then Send. Non-read-only methods require explicit confirmation. The result shows HTTP status, duration, readable headers and text/JSON or Base64 for binary data. Copy/download the response or generate a cURL example (authentication and custom header values redacted). Clear removes local request fields and results. No tokens, bodies or API responses are persisted by Book; avoid putting secrets into ordinary path/query parameters.

### Enable API servers

Both modes are disabled by default. Set exact comma-separated HTTPS origins in deployment configuration (no paths, wildcards or credentials). The OpenAPI server's base path is retained; server variables use their specified defaults. For example:

```sh
export BOOK_PLAYGROUND_ORIGINS=https://api.example.org
# Optional: enable managed server proxy to the same approved origin
export BOOK_PLAYGROUND_PROXY_ORIGINS=https://api.example.org
docker compose up -d --build
```

Browser mode performs a real fetch with CORS, omitted ambient credentials, no redirects and no referrer. Configure the API's CORS policy to allow the documentation origin, methods and required headers. It supports only browser-permitted headers; cookie parameters/authentication require the managed proxy. A public operation can be used anonymously in browser mode, subject to Reader visibility and the target API's own authorization. Private APIs accessed from the browser are subject to its network policies.

Managed proxy is separately opt-in, requires an authenticated user with current workspace read access and CSRF, and is restricted to the separately configured proxy origin allowlist and exact published operation. It resolves the approved DNS hostname to global IPv4 addresses only, connects to a validated IP with TLS hostname/SNI verification, refuses redirects, and never forwards the Book session cookie. No private/loopback/link-local targets or arbitrary destination URLs. The proxy uses bounded HTTP/1.1 parsing, 5-second connect and 15-second network deadlines (DNS resolution is governed by the OS), a 256 KiB request and 1 MiB response limit, 12 requests/minute per user and 60/minute per installation. Browser fetch has a 15-second abort deadline and 1 MiB response cap. Current access and publication binding are checked before and after proxy execution; revocation cannot undo an external side effect already performed. Use non-production API environments for mutation tests. Target API permissions remain separate from Book roles.

### Publication and supported formats

OpenAPI imports now store a bounded executable definition in each supported operation's draft snapshot, with a content binding. Publishing/review retains that exact definition, including server, method, parameters and authentication alternatives. Unpublished source updates cannot change a published operation. Historical restore and backups preserve the snapshot. For 0.3.4 publications, the system can recover the definition only from an exact, unambiguous retained source history; otherwise reimport and publish the updated reference using normal approval. Editing generated Markdown manually disables execution for that changed snapshot until a valid generated snapshot is restored or regenerated. No new schema or dependencies; keep a pre-upgrade backup for rollback.

Supported methods GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS; scalar string/integer/number/boolean path/query/header/cookie parameters and supported default serialization; Bearer, Basic and API Key alternatives (including combinations); application/json, structured +json, application/x-www-form-urlencoded and text/plain bodies. Unsupported complex parameter serialization, relative HTTP servers, OAuth/OIDC flows, multipart and other body types remain readable Reference operations but are not executed through Playground. The API server is responsible for application-level validation and authorization; this is not a full JSON Schema validator. No general-purpose proxy, persisted request collection or automatic retry.

### Tests

Run `php tests/playground.php`, `python3 tests/playground-https.py`, `python3 tests/playground-http.py` and `python3 tests/playground-http.py --browser` (Playwright/Chromium). The TLS test exercises actual PHP HTTP framing and JS fetch against a controlled local HTTPS API; the production proxy separately rejects loopback. The HTTP tests use real Book sessions, publication, RBAC and privacy. `--policy-only` permits storage/HTTP checks without CommonMark; the complete suite is in `composer test` and CI. The transient private `playground-rate.json` is not part of backups; it contains counters, not credentials.

## 0.3.4 — OpenAPI Reference

Open **Studio → OpenAPI Reference** (`/studio/openapi` within the selected workspace). Editor/Admin/Owner can create and update a reference; Viewer cannot read sources or drafts. Choose book slug, book version, and a reference slug. Paste JSON/YAML, upload files, select a directory, or upload a ZIP containing only specification files; specify the exact root filename relative to the bundle. The import accepts OpenAPI 3.0.x and 3.1.x with `info.title`, `info.version` and `paths`. A downloaded normalized JSON source bundle can also be uploaded again using its filename as root. For a configured GitHub/GitLab connection, choose a folder relative to the documentation root and a root filename; the server reads JSON/YAML at one immutable commit, validates blob hashes, then reauthorizes the session and connection. No remote write or Git Sync cursor changes occur.

Preview lists new, unchanged, updated, retired and conflicted pages and bounded diffs. Apply commits the signed snapshot as drafts, preserving stable page IDs and existing publications. The token expires after 15 minutes and is bound to the session, scope, source, commit and current BookStore. Generated pages use `reference/<slug>/index`, `operations/<method>-<path hash>` and `schemas/schema-<name hash>`. The overview links operations and models; operation pages include HTTP method/path, tags, effective parameters, server definitions, authentication, request/response content and examples; schema pages include property summaries and expanded schema JSON. Descriptions are escaped as literal text, not rendered as source Markdown. JSON examples are inert code blocks. The standard Reader, search, API, editor, history, visibility, and review apply. No live requests or SDK generation in this release.

Source versions are independent of the book version. Reimport the same book/version/slug to update a reference, or choose another book version to keep a separate API edition. The source history stores normalized bundles, SHA-256, actor, timestamp and Git commit if applicable. Restore a retained source through Preview this revision, then Apply; this creates a new source revision and drafts only. Unchanged imports are idempotent. Removed operations/models become retirement drafts; old publications remain until an authorized publish/unpublish. Manual modifications, moves, deletion or path collisions block the whole update; restore the last generated draft at its original path or import into a new version. Do not place hand-authored text in generated pages if automatic updates are required; use separate manual pages.

Only local JSON Pointer `$ref` values resolve within the uploaded bundle, including sibling files and bounded parent paths. Recursive schemas retain a reference marker. No external URL/file fetch, dynamic anchors, `$id`, arbitrary YAML tags or aliases. Structural validation covers the generated reference surface, not full official OpenAPI/JSON Schema conformance; callbacks, webhooks, advanced schema semantics and executable examples are not generated as independent operations. Source files and examples may contain secrets: do not import confidential values into documents intended to be public. Source downloads are Studio-only; published pages follow normal book/workspace visibility. Limits: 32 source files, 1 MiB input/normalized content, 40,000 nodes, depth 48, 200 retained generated pages and 4 MiB generated Markdown per reference; 16 references, 5 retained revisions each, 4 MiB aggregate normalized source history per workspace, subject to existing 32 MiB BookStore quota. Older non-current revisions are evicted to fit quota; current sources are retained or import fails.

Upgrade: back up the complete installation, keep the existing Git master key, run `composer install --no-dev --prefer-dist --no-interaction` or rebuild Compose. Adds locked Symfony YAML 7.4.23 and its ctype polyfill; existing PHP extension requirements remain. First successful import promotes that workspace BookStore to schema 4; existing schemas 1–3 still load, Identity schema remains 4. TAR/installation backup includes normalized source revisions and mappings, and full audit validates them. Restore the pre-upgrade backup for rollback; older code does not support schema 4. Run `php tests/openapi.php`, `python3 tests/openapi-http.py`, `composer test`; policy-only modes are available without the YAML/CommonMark dependencies. Full CI additionally tests actual YAML, Markdown rendering and TAR restore inside the Docker image.

## 0.3.3 — GitLab Sync / unified Git Sync

Open **Studio → Git Sync** (`/studio/git`; existing `/studio/github` bookmarks remain valid). Select GitHub or GitLab, repository namespace/project, branch, docs directory and target book/version. One connection per workspace; different workspaces can use different providers simultaneously. GitLab supports nested groups (`group/subgroup/project`), public repositories and private Project Access Tokens or PATs. Use the exact project path. For GitLab pull, prefer a project-scoped token with `read_api`; for commit/MR proposals use `api` with a role allowed to create branches and merge requests. Use the minimum necessary permissions and a dedicated test repository for acceptance. GitLab.com is enabled by default.

Self-managed GitLab: the deployment operator sets `BOOK_GITLAB_ORIGINS` to comma-separated exact HTTPS origins, e.g. `https://gitlab.example.org,https://gitlab.internal:8443`. Compose passes this variable into the service. No HTTP, URL credentials, query strings, path prefixes, arbitrary redirects or wildcard origins. Install a trusted CA chain in the PHP environment (or extend the Docker image's CA trust store) for private CAs. This is an operator trust boundary: allowlisted hosts may resolve to private networks; only allow trusted GitLab servers and enforce outbound network policy and trusted DNS. Removing an origin disables subsequent remote requests; existing local documents and backups remain readable. The GitLab instance must support API v4 at `/api/v4` on the origin and accept encoded project paths. The first connection resolves the exact namespace to a stable numeric project ID; every operation verifies that identity before using numeric API routes, so a reused namespace cannot replace the connected project.

Reuse the existing persistent `BOOK_GITHUB_KEY` (32-byte hex master key) for both providers; old GitHub ciphertext is unchanged. GitLab AES-256-GCM AAD binds connection ID, origin, namespace and numeric project ID. Never regenerate the master key on restart or store it in content backups. Rotate access tokens via Connection settings. Changing provider, origin, repository, branch, root, book or version requires deliberate disconnect/reconnect; documents are retained, source mappings reset. The cache is disposable and SHA-verified.

### Synchronization and proposals

Preview/Apply is the same workflow described below for GitHub. GitLab resolves one branch commit, verifies the commit identity, enumerates its recursive tree in pages of 100, reads exact raw Git blobs and checks their object hashes before staging. Limits: 400 tree pages / 40,000 entries, 200 imported files, 64 MiB expanded bytes, 20 MiB per file, 8 MiB Markdown, 256 KiB per document, 650 API requests and a 55-second request budget. Unsupported regular files are ignored; symlinks, submodules and unsafe paths inside the documentation root are rejected. A missing documentation root produces a removed-source plan, never a silent deletion. Large repositories may need a smaller repository. Preview signs current BookStore state, commit and connection; Apply reauthorizes the user and checks the same snapshot. Fetching runs outside the identity lock, with permissions checked again before displaying or writing results. All imports are drafts; unchanged files use the shared verified blob cache. Base/local/remote comparison, manual resolution, stable-ID unambiguous renames, safe detach, media rewriting and history work identically across providers.

Admin/Owner can enable proposals; Editor/Admin/Owner must explicitly confirm disclosure to the remote repository. GitLab proposals check the current branch SHA, fetch exact-commit file metadata, create/update Markdown in one commit batch with `start_sha` on a new `cajeerbook/docs-*` branch, then open a Merge Request to the configured branch. New pages require opt-in; new local attachments must already exist in the remote source. The base branch is never written directly or force-pushed, and neither GitLab merge nor Book publication is performed automatically. Merge on GitLab and sync again to converge. If commit/MR confirmation fails, a branch may already exist: check the branch identified in the safe error before retrying. Connection/rotation/proposal are synchronous; preview and apply downloads release the identity lock. No background jobs or webhooks until a later release.

### Upgrade, backup and tests

Backup the installation before updating. GitHub-only data keeps schemas 1/2 and existing credential format; enabling GitLab promotes that store to schema 3, including after disconnect. Review operations preserve schema 3. This intentionally prevents 0.3.2 from misinterpreting a GitLab integration as GitHub. The existing `github` record key is retained for backward compatibility, now with `provider`, `origin` and (for GitLab) `project_id`. JSON/TAR and installation backups include encrypted credentials and mappings; restore master key separately and re-enable approved origins. No new PHP extension or Composer dependency. To roll back use the pre-update backup, not just older code.

Run `php tests/gitlab.php`, `python3 tests/github-http.py --gitlab`, `python3 tests/gitlab-https.py` and `composer test`. The HTTPS test uses the actual production PHP transport against a controlled local TLS server, validates certificate rejection, token headers, blob bytes, new commit/MR and no redirects. PHP/HTTP tests use controlled API responses and real storage/sessions; full mode requires CommonMark. For opt-in live read-only acceptance, set `BOOK_TEST_GITLAB_REPO`, `BOOK_TEST_GITLAB_BRANCH`, optional `BOOK_TEST_GITLAB_ROOT`, `BOOK_TEST_GITLAB_ORIGIN` and `BOOK_TEST_GITLAB_TOKEN`, then run `php tests/gitlab-live.php`. Self-managed test origins must also be in `BOOK_GITLAB_ORIGINS`. A real writable MR acceptance requires a disposable project and explicit UI confirmation. No live credentials in CI logs.

## 0.3.2 — GitHub Sync

Open **Studio → GitHub Sync** in a workspace. Owner/Admin connects one repository per workspace (`owner/repository`, branch, optional documentation directory, target book and version). Public repositories can be read without a token; private repositories and proposals require a fine-grained GitHub token restricted to the selected repository. Pull uses Contents: read; PR proposals require Contents: read/write and Pull requests: read/write. GitHub metadata access is implicit. Do not use broad personal tokens if a scoped token suffices. Set the server's `BOOK_GITHUB_KEY` to a persistent random 32-byte hexadecimal key, e.g. `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`; export it before Compose or configure the PHP/Apache service environment. Keep it outside the repository, data directory, logs and backup bundle. `BOOK_GITHUB_KEY` is passed into the Compose service. Tokens are encrypted at rest using AES-256-GCM and the connection ID/repository as authenticated context. Public pull-only connections need no key. If the key is lost, rotate/re-enter the token; rotation preserves the cursor. Never expose the master key or token to users of Studio. OpenSSL and a trusted CA store are required; Docker installs CA certificates. `allow_url_fopen` must be enabled for the fixed HTTPS GitHub REST transport. Use HTTPS for the Book application in production.

### Pull and conflicts

1. Connect, then choose **Preview sync**. The server resolves the branch to one immutable commit SHA and downloads regular supported Markdown/asset blobs from the documentation directory. It never clones or executes code, follows no redirects, and contacts only `api.github.com`. The Git tree must be complete; unsupported file types are counted/ignored, symlinks/submodules in the documentation directory are rejected. Set a narrow directory to avoid importing unrelated files. Limits: 200 files, 64 MiB total, 20 MiB/file, 8 MiB Markdown, 256 KiB/document; GitHub API rate limits and the bounded 55-second network budget also apply. Large repositories with truncated recursive trees must use a smaller repository/tree or wait for a later provider extension.
2. Preview displays source → page mappings, normalized Markdown comparison (first 20 changed/conflicting pages), unresolved links and commit author name as **unverified Git metadata**, not a Book identity. The Book audit author is the authenticated user who applies the sync. Source-to-page ID and normalized base hashes persist in the BookStore. Unchanged blob bytes are cached in the private workspace `github-cache` directory (one selected tree, at most 64 MiB, verified on every use). This is disposable cache, not part of backups; purge it with the application stopped if desired. The next download prunes unused blobs.
3. Remote-only edits update drafts; local-only edits remain intact; converged identical content is adopted. Both changed / occupied paths require explicit conflict choices: safe (stop), use GitHub, keep local, or manual per-source. Keep-local records the incoming remote base while retaining the local draft, so subsequent pulls continue to detect divergence. No speculative automatic text merge. Removed sources require confirmation, then detach while retaining the local page and any publication. An unambiguous identical-blob leaf rename with unchanged local content preserves the stable ID and old public path; changed/ambiguous renames require explicit resolution. A locally moved/deleted tracked page or changed source slug blocks automatic application; restore its original path or deliberately disconnect/reconnect. No silent deletes, publication or overwrite of reserved paths.
4. **Apply sync as drafts** checks the signed 15-minute preview, exact commit/branch head, workspace and session; stale plans and branch changes require a new preview. Read-only downloads happen outside the installation identity lock. The server rechecks current session, effective role, workspace availability and connection before preview/apply, then atomically commits pages, media, history and cursor. Publication still uses existing RBAC and mandatory review. A branch may advance after the last head check; the imported snapshot remains the explicitly previewed immutable commit.

### Pull Requests

Only Admin/Owner can enable PRs; enabling allows Editor/Admin/Owner to send drafts to the chosen GitHub repository, which may have different visibility. Explicitly review the candidate list and confirm disclosure. By default only changed tracked Markdown is sent; opt in to new local pages in the target book/version. The proposal checks the displayed workspace state, connection revision and current GitHub branch SHA, reverses tracked `/docs` and `/media` URLs to repository-relative paths, and writes a new branch `cajeerbook/docs-<random>` with a Git tree/commit and Pull Request. New pages use the page path plus `.md` (root `index` → `README.md`). New local attachments must be added to GitHub and synchronized first; untracked local media references block export. Local deletions are not exported, and moved tracked pages must be resolved first. Merge on GitHub, then Preview/Apply again to converge the base. The configured branch is never force-pushed or updated directly. If PR creation fails after branch creation, the error identifies the branch for manual PR creation. The operation does not publish the Book Reader. GitHub PR creation requires outbound HTTPS and an existing branch with a commit. Connection validation, rotation and explicit remote PR writes are synchronous and retain the identity authorization lock; read-only pulls release it. Background jobs/webhooks are planned for 0.3.7.

### Backup, upgrade and tests

The `github` BookStore record contains encrypted credentials, source bindings, last commit and up to 30 sync/PR runs and safe provider errors; Team Studio records connection/rotation/disconnect/sync/proposal actions without secrets or raw Markdown. Content and installation backups retain this encrypted record; restore the master key separately or rotate credentials after restore. Cache is regenerated. No data migration is required; schema 1/2 is unchanged. Back up before upgrading; older 0.3.1 code does not understand new Git activity types, so use the pre-upgrade backup to roll back.

Run `php tests/github.php` and `python3 tests/github-http.py`, then `composer test`. These use a controlled GitHub REST transport with real PHP stores and HTTP sessions; full mode uses the locked CommonMark parser. `--policy-only` exercises the provider protocol, hashing, encryption, decision matrix, real detach transaction and HTTP authorization without CommonMark. For a live read-only acceptance check, use `php tests/github-live.php` with `BOOK_TEST_GITHUB_REPO`, `BOOK_TEST_GITHUB_BRANCH`, optional `BOOK_TEST_GITHUB_ROOT` and optional `BOOK_TEST_GITHUB_TOKEN` set for an explicitly selected test repository. It makes actual GitHub requests and does not mutate the repository. Live PR acceptance requires a disposable writable repository and explicit UI confirmation; never use production credentials in CI logs. No GitHub App/OAuth installation, auto-merge or webhook background execution in this release.

## 0.3.1 — Markdown Import

Open **Studio → Import Markdown** in the intended workspace. Owner, Admin and Editor can import; Viewer cannot. Choose a book slug (an existing or new book), version, conflict strategy and source: Markdown/attachments, a browser directory, or one ZIP. Click **Preview import**, inspect the source-to-target mapping, missing local links and asset counts, then **Apply as drafts**. Confirmed imports never publish, even when direct publication is allowed. Existing published snapshots remain intact; imported changes participate in normal history, contributor tracking, review and activity. The selected files remain in the browser and are uploaded again on Apply. A signed preview is valid for 15 minutes and bound to the current session, workspace, input file hashes, options and complete BookStore state; changes require a new preview. A private server-generated signing key (`identity/import-preview.key`) binds the plan to the session; it is regenerated after backup restoration, invalidating outstanding previews. No permanent upload staging is added to backups.

### Source format and mapping

- UTF-8 `.md`/`.markdown`, optional UTF-8 BOM. Root README/index becomes `index`; nested README/index becomes the directory landing page. Other filenames are normalized to stable ASCII slugs; non-ASCII-only segments use a deterministic hash suffix. Collisions between two source files mapping to one page are rejected rather than silently overwriting. Root `SUMMARY.md` links supply navigation order (explicit `order` wins); the summary remains an imported page.
- Browser directory selection strips the selected outer folder; ZIP paths are relative to archive root and preserved. ZIPs should contain the documentation subtree, not a whole repository with unrelated executable/config files. Supported scalar front matter: `title`, `description` (inserted as escaped introductory Markdown), `slug`, `section`, `order`. Quoted and plain scalars only; unsupported keys, sequences, aliases, tags and multiline YAML are rejected. Ordinary thematic breaks remain Markdown.
- Relative Markdown/image/attachment destinations are resolved from the source file, including reference definitions, percent-encoded filenames, local anchors/query suffixes and nested image links. Supported rich-block bodies and card links are rewritten; code, HTML and Mermaid source remain protected. Existing canonical `/docs`, `/media`, `/studio`, `/api` and `/w` links and external URLs are left alone. Unresolved relative links are listed and left unchanged; fix them before publication. No external resource is fetched. This is not a generic HTML, MDX, Docusaurus or arbitrary YAML converter.
- **Skip** keeps existing pages; **Update** replaces only the draft, retaining page ID and public snapshot; unchanged drafts are not revised; **Rename** allocates a free suffix and rewrites imported links to it. Retired paths require Rename. Assets are validated by the existing MediaStore, copied privately and deduplicated by content within the workspace. Media references are validated by CommonMark before the transaction. Existing media/history references and mandatory review remain enforced.
- Limits: up to 200 regular files (400 ZIP entries including directories), 20 MiB ZIP or individual file, 64 MiB expanded input, 8 MiB Markdown total, 256 KiB per Markdown document, source path depth 24, asset folder depth 8. Existing workspace quotas also apply. ZIP file names never become extraction paths; entries are streamed into random private temporary files with checked size and CRC. Symlinks, special files, encrypted entries, traversal, duplicate paths, unsupported MIME and file/directory collisions are rejected. Cleanly rejected transactions leave the previous store and remove uncommitted blobs. A process crash can leave an orphan blob for existing orphan GC; imported metadata is atomic.

### Install / verify

Requires PHP `ext-zip` (libzip), in addition to existing extensions and locked CommonMark. Docker installs zip and sets `upload_max_filesize=20M`, `post_max_size=72M`, `max_file_uploads=200`, `memory_limit=256M`; configure equivalent reverse-proxy/PHP limits for non-Docker deployments. Back up the existing data before updating, rebuild the image or install the extension and run `composer install --no-dev --prefer-dist`. No content/identity schema migration. Existing JSON/TAR and full installation backups already include imported content and assets.

```sh
php tests/import.php
python3 tests/import-http.py
python3 tests/import-browser.py
composer test
```

`php tests/import.php --policy-only` and `python3 tests/import-http.py --policy-only` run the dependency-independent validation/storage and HTTP denial checks. The default tests exercise actual ZIP, CommonMark, history, review, private media, backup and browser directory import; CI runs both PHP/HTTP and Chromium tests and the image runs the ZIP integration test.

## 0.3.0 — Workspaces & Teams Stable

The integrated team release combines individual accounts, organizations, isolated workspaces, fixed Owner/Admin/Editor/Viewer roles, public/private books, Advanced Editor, revision history, independent review and approval, Team Studio, notifications and installation backup/restore. There are no new runtime dependencies or data schema changes. The application remains a single-node PHP installation with bounded JSON stores and an installation-wide identity lock; it is not a distributed or real-time collaborative editor.

### Upgrade and release verification

1. Stop web and standalone CLI writers; retain a trusted copy of the entire `BOOK_DATA_DIR` (including identity, workspace stores and media).
2. Check existing data with `php bin/installation.php check` and create a full backup using `php bin/installation.php backup /secure/backups/book-before-030` (a new private directory).
3. Apply the release files and rebuild (`docker compose up -d --build`), or install the locked Composer dependencies and local Mermaid assets (`sh bin/install-mermaid.sh`). Use HTTPS and `BOOK_SECURE_COOKIE=1` in production.
4. Run `php bin/installation.php check` and confirm `/health/ready` returns 200. Sign in with the existing owner and verify a private workspace with an Editor and Viewer. A successful readiness response is not a substitute for a full integrity audit or restore rehearsal.

The identity store now writes a private root-level `identity.required` anchor containing the installation owner's immutable ID. This file is automatically created when an existing valid identity is first accessed and when a new owner is initialized. Keep it in offline volume copies. Full installation restore reconstructs it from the validated identity backup. Losing `identity.json`, the whole identity directory, or replacing identity with a different owner no longer makes an initialized installation appear new. Content and authentication fail closed, including old legacy URLs; `/health/live` remains a process liveness endpoint, while `/health/ready` reports failure. Do not delete the marker or reinitialize an owner to recover missing identity: restore the matched installation backup into a new directory and switch the deployment after verification. A legacy-only installation that already lost identity before upgrading, without a marker or workspace directories, cannot be reliably distinguished from a never-initialized installation; verify its identity backup before starting the upgrade.

Readiness validates the registered workspace directories, legacy store and scoped stores under a consistent identity lock. Session persistence is completed before content operations can send a response, with the lock retained for authorization through the operation. Unexpected application errors produce a generic 503 without private details or appended diagnostic text in streamed files.

### Team acceptance workflow

Create an organization, invite users, assign an Admin, Editor and Viewer, then create a private workspace. Give the Editor a book and save a draft; enable required review as Owner, select an independent Admin, request changes and resubmit, approve and publish the exact saved revision. Viewer can read the publication and its linked media but not Studio, drafts, history or review. A user from another organization cannot access these resources. Restore an earlier revision as a new draft; the approved publication stays unchanged. Use Team Studio to follow personal review tasks and notifications. Use a full installation backup for transfer, not a content-only export; restored sessions must sign in again.

### Release tests

`composer test` exercises the server, storage, HTTP and migration suites. After installing Playwright/Chromium, `python3 tests/installation-http.py --browser` runs the integrated multi-user workflow with real Markdown editing, review, full installation backup/restore, Reader and Mermaid rendering, revision recovery and access revocation. The Compose release gate additionally runs `tests/team-release-http.py` against a disposable Apache deployment before and after restart. Never point this test at production: it creates test users, organizations and documents. The `--storage-only` option for installation tests is a diagnostic without parser-dependent content, not full release certification. All dependency-backed tests and Docker gates must pass before production deployment.

## 0.2.9 — Security & Migration

### Full installation backup, verification and migration

Stop web writers and background/CLI writers before upgrading or moving an installation. Keep the original data directory and an independent backup. Run these commands with the same filesystem user as the application (or restore ownership and restrictive permissions before starting it). The parent directories must already exist; targets must be new, private directories outside `public` and outside the source. Allow sufficient free disk for staging and the complete restored data.

```sh
export BOOK_DATA_DIR=/var/lib/cajeerbook
php bin/installation.php check
php bin/installation.php backup /secure/backups/book-029
BOOK_DATA_DIR=/var/lib/cajeerbook-restored php bin/installation.php restore /secure/backups/book-029
BOOK_DATA_DIR=/var/lib/cajeerbook-restored php bin/installation.php check
```

For a copy-based migration in one command:

```sh
BOOK_DATA_DIR=/var/lib/cajeerbook php bin/installation.php migrate /var/lib/cajeerbook-restored /secure/backups/book-migration
```

Switch `BOOK_DATA_DIR` only after verification; keep the source for rollback. The operator-only CLI creates a directory bundle (`manifest.json`, `identity.json`, `legacy.tar`, one TAR per nonlegacy workspace including archived spaces). It holds the identity lock during capture; offline maintenance also excludes old standalone content CLI writers. Each component is checked by SHA-256 and size, the registry must match all components, and restored stores are audited before a staged directory is atomically renamed into place. The manifest checks integrity, not authenticity: restore only trusted backups. No HTTP endpoint exposes this full backup. Identity hashes are sensitive; keep backups private. Sessions, password reset and invitation tokens, throttling state, disposable thumbnails and unreferenced media blobs are not restored. Users must sign in again and invitations must be reissued. Existing separate identity and workspace TAR commands remain supported.

The migration reads identity schemas 1–4 and normalizes to 4 on the destination; pre-identity content is supported without inventing accounts. Set up the first installation owner using the documented legacy login or CLI. BookStore schemas 1/2, stable page IDs, legacy URLs, history, reviews, visibility and journal data are preserved. The source data is not rewritten by migration (lock files and necessary directories may be created). `check` validates all registered spaces, including archived ones; missing or corrupt stores and orphan workspace directories fail rather than silently dropping content. Fix media reference mismatches with the existing explicit audit/repair command before taking a full backup. A pre-0.2.9 installation that adopted only the untouched built-in demo seed, but never wrote `books.json`, may require `php bin/installation.php materialize-legacy-seed --confirm-seed-only` **only after verifying that no real content was lost**. Never use it to replace missing real data. New legacy adoptions materialize the seed immediately.

### Security fixes

Persisted page paths, public paths and snapshots are validated before Reader/API projection. Adopted legacy stores fail closed when metadata is missing; media blobs without metadata cannot cause a new seed to appear. Workspace journal events must match the registered organization. Duplicate branches preserve known unpublished source contributors, and new revision snapshots retain contributor provenance for draft restoration. An original author cannot approve a duplicate or restored draft merely because another user copied/restored it. Earlier retained history is used as a conservative fallback; already pruned or unrecorded historical authors cannot be reconstructed.

Full release checks: `composer test` includes the installation PHP and HTTP backup/restore tests. The Docker release gate also runs the installation integration test inside the image. `php tests/installation.php --storage-only` and `python3 tests/installation-http.py --storage-only` exercise filesystem and HTTP infrastructure without parser-dependent content. Production deployments require installed Composer dependencies, local Mermaid assets and HTTPS (`BOOK_SECURE_COOKIE=1`).

## 0.2.8 — Team Studio & Activity

Open **Team** in the top navigation (`/team`) for your organizations and accessible workspaces. The page lists the effective role, Reader, Studio and team dashboard links, and (for authoring workspaces) current counts of unread review notifications, personal review tasks and drafts. Workspace cards are paginated in groups of 10; a missing registered content store is reported as unavailable, never silently seeded. Archived spaces are labelled and cannot be opened for authoring. Installation Owner does not gain access to another organization's workspace simply by owning the installation.

### Team dashboard

Open **Studio → Team dashboard** (`/studio/team`, or the equivalent `/w/{organization-id}/{workspace-id}/studio/team`). Owner, Admin and Editor with current effective workspace authoring access can use it. Viewer and outsiders cannot see drafts, reviews, activity or review notification receipts. The dashboard contains:

- My drafts, review tasks and unread review notification counters derived from the current stored pages and reviews. A pending review assignment or approved request awaiting publication appears for its reviewer; changes requested, stale or approved requests appear for the requester. Only the latest request per target is treated as a current task. Older requests remain in Reviews.
- Enabled workspace members and effective roles; members denied access to the workspace are excluded.
- Recently updated documents with title/path, current draft/publication state, last known author and UTC timestamp. Filter by title/path, book, status (All, My drafts, Unpublished changes, Published), last author or UTC date interval. My drafts uses recorded unpublished contributors, with a retained-history fallback for pre-0.2.7 data. Unknown/expired author metadata is not fabricated.
- Personal notification inbox, workspace activity and, for effective Admin/Owner, a filtered publication/visibility audit. Lists are server-paginated. Activity/audit can be filtered by event type and actor username. Direct links open the corresponding review, page history or management page.

The page tree's Studio list now displays last known author and pending unpublished changes. On an editor conflict or session/access failure, autosave remains stopped; use **Download Markdown** or **Copy Markdown**, and open the current server version in a separate tab to compare. Download is an explicit local file operation; nothing is uploaded or stored in browser localStorage. No CRDT or automatic merge is introduced.

### Notifications and audit

Notifications are persistent in-app events, not email or external push. Existing-account invitations notify the intended user without recording or delivering the invitation secret; the Owner still shares the one-time token securely. The pending invitation notification disappears when accepted, revoked, expired or replaced. New-account invitations are still delivered via the existing copied link. Accepted invitations notify the organization's Owner; role and workspace assignments notify their recipients when they retain access. Review submission notifies the assigned reviewer; comments, decisions, stale transitions and publication notify the requester/reviewer as appropriate, excluding the initiating actor. Read receipts are independent per recipient. Mark read / Mark all read use CSRF-protected POST; an event ID cannot mark another user's inbox. Counts and the current tasks are refreshed on navigation/reload; there is no polling, email or push service.

Identity/organization/workspace audit records successful sign-in/out, account creation/enable/disable, password change/recovery, session revocation, organization lifecycle, invitations, membership/role changes, ownership transfer and workspace lifecycle/visibility/access. Installation Owner sees installation-level identity events; organization Admin/Owner sees their current administrative organization scope, with effective workspace restrictions applied. Workspace activity records substantive saves, publication, unpublication, delete, move, duplicate, copy, tree publication, restore, book/media changes and review state/discussion events. Administrative changes and content events commit in the same atomic file transaction as the corresponding mutation, so rejected writes do not produce successful events. Identical autosaves do not produce page activity. Multi-page operations produce one event with an affected-page count and a representative page ID/path.

Events store safe metadata only: event ID/type, UTC timestamp, actor ID/username, target/scope, a short subject/detail and explicit notification recipients/read receipts. They do not contain Markdown bodies, review comment text, password hashes, tokens, emails, IP addresses or raw requests. The existing authentication throttling is unchanged; failed sign-in attempts are not recorded in this successful-action journal. Access to the journal and inbox is checked against current membership/roles at every request. Public Reader, API and media routes do not expose activity. Previously downloaded data cannot be revoked. This bounded operational journal is not a tamper-proof or regulatory audit archive.

### Storage, retention and upgrade

Activity is an optional `activity` map in the existing identity file and in each independent BookStore. Each journal retains up to 1,000 events and 512 KiB (serialized size), within the existing identity 4 MiB and BookStore 32 MiB total limits. Oldest events expire first, including unread events, and fewer may be retained under total-store pressure. Current review tasks remain available from review state even after their notification expires. No backfill of historical events or old invitation notifications is performed. Identity backups preserve audit and read receipts but still exclude active sessions, reset tokens, invitation credentials and rate limits; restored pending-invitation notifications remain hidden until a new invitation is issued. Scoped JSON/TAR backups preserve content activity and receipts. Restore the matching identity/content backups together; `bin/verify-data.php` and store loading validate journal records. Keep backups private.

Before upgrading, stop writes and back up all of `BOOK_DATA_DIR`, including identity, legacy content and every workspace. Overlay the 0.2.8 files, rebuild/restart and check `/health/ready`. Existing identity schema 4 and BookStore schemas 1/2 remain supported; no migration command or new runtime dependency. Old stores gain journal records on new successful operations. Do not downgrade only code if the new journal must be retained; restore a matched pre-upgrade backup for rollback. Use `composer test`, `python3 tests/team-browser.py` and the existing Docker release gate. `python3 tests/team-http.py --storage-only` exercises real HTTP transactions with stored snapshots when CommonMark is unavailable. `BOOK_TEST_BROWSER_TRANSPORT=1` enables the restricted-browser form bridge; CI uses normal navigation and real Markdown saves.

## 0.2.7 — Review & Approval

Open **Studio → Reviews** in the desired workspace, or use **Review saved draft** on a saved page and **Review tree changes** in Page Tree. Review requests are private authoring resources; Viewer, anonymous readers and outsiders cannot read them. The queue filters by status or by the current user's submissions/assignments. Every request contains a permanent ID, immutable snapshot and SHA-256, requester, assigned reviewer, recorded contributors, decision history, timestamps and optimistic revision.

### Publication policy and workflow

Existing and new stores default to direct publication allowed; Owner/Admin retain their previous direct publishing permissions. To require independent approval, the **organization Owner** opens Reviews → Publication policy → Require review for pages and tree → Save review policy. It is workspace-wide (including the adopted legacy workspace). At least two active users with authoring access are required to enable it. At least one eligible independent Admin/Owner is required for each request; an Owner's own changes require another eligible reviewer. Before adoption a single installation Owner cannot enable required review. Admin cannot change the policy. An explicit Owner policy change invalidates open requests; this trusted management action is separate from review. Archived organizations/workspaces deny authoring access.

1. Save the draft, then open Review saved draft, check the frozen Markdown and published-to-submitted diff, choose an independent effective Admin/Owner and submit. Unsaved editor content is not included. A second open review of the same target is rejected.
2. The assigned reviewer opens the request, compares the source and optionally renders its private preview. The requester and effective Admin/Owner can comment while open. Only the assigned eligible reviewer can Approve, Request changes, or Reject. Changes/rejection require a reason and close the request; the author may revise and submit again. The requester or an effective Admin/Owner may cancel an open request.
3. Approval alone does not publish. Effective Admin/Owner chooses **Publish approved revision**. The server rechecks the current assigned reviewer's permission, the policy generation, the request revision and the exact saved page fingerprint. It uses the submitted snapshot, increments the current page revision, and records the publication and review ID in Revision History in the same atomic transaction. A concurrent edit, restore, move, deletion or other change to the reviewed page makes the request stale. Even a revision-only save invalidates it; submit the new saved state. Publication cannot be repeated. For old pages, contributor information is inferred from retained history; from 0.2.7 all known contributors to unpublished content/navigation changes are retained on the page even when history expires. The requester and these contributors cannot be selected as reviewer. Unknown imported/expired authors cannot be reconstructed.
4. For a structure review, choose a book/version. The request contains old/new public paths and orders for already published pages. Approval and publication use the existing global tree token; any page revision change in the workspace invalidates the tree approval conservatively. Publishing a reviewed tree does not publish draft content or draft-only pages. Existing redirects are maintained by PageTree. Page review updates the submitted content at its existing public path/order; new pages use the submitted path/order. Separate tree approval applies pending changes to existing public navigation.

Required mode blocks direct Publish and Publish tree on the server under the BookStore lock, not merely in HTML. Saving, copying, restoring to draft and moving branches remain available to Editor. Emergency unpublish/delete, book/workspace visibility and other administrative actions continue to use their existing distinct RBAC permissions; they are not review publication operations. Direct mode is an explicit policy choice and can coexist with optional review requests. A reviewer who loses effective publication permission cannot authorize publication while that permission is missing; cancel the old request and assign another reviewer when necessary. Role/identity and content locks enclose the complete request. There is one assigned reviewer per request, no inline anchored comments or multi-approval quorum in this release.

### Storage, backup and upgrade

Reviews are immutable snapshot copies in each workspace's existing `books.json`, independent of the history retention window. The snapshot is not a second editable draft; review status and discussion are mutable only through checked state transitions. Up to 100 retained requests and 8 MiB of review metadata per workspace; new requests/discussion reserve space for final decisions (512 KiB). Each request allows up to 50 messages, with reserved decision slots, and notes up to 2,000 UTF-8 bytes. Owner may remove closed requests via the detail screen after export/backup; active reviews are never automatically pruned. The 32 MiB complete BookStore limit still applies. Review snapshots retain referenced media against deletion even after unpublish, but never grant public file access. The existing JSON/TAR backups include review policy, snapshots and discussion; restore and `bin/verify-data.php` validate the snapshot hashes, state transitions and CommonMark media indexes. Backups contain confidential drafts and comments. Restore matched identity and content backups from the same installation; current reviewer permissions are checked on publication.

Stop writes and back up all of `BOOK_DATA_DIR` before overlaying the 0.2.7 files. Rebuild/restart, check `/health/ready`, and inspect Reviews. Existing content stores with schema 1 remain readable and are not rewritten by migration alone. The first real review submission or policy change writes **BookStore schema 2** atomically, while preserving pages, history, media, IDs, visibility and redirects. New workspaces start on schema 1 until review is used. Identity remains schema 4. **Do not roll back only code** after review use: 0.2.6 rejects schema 2 and cannot enforce approval. Restore the matched pre-upgrade data backup for rollback. No new runtime packages or lockfile changes.

### Tests

```sh
composer test
python3 tests/reviews-browser.py
```

`php tests/reviews.php` checks state transitions, concurrency, snapshot tampering, exact publication, author independence, retention and TAR restore. `python3 tests/reviews-http.py` exercises the real app with independent sessions, real Markdown saving, review decisions, private media and tree redirects. Both support `--storage-only` to run workflow against genuine stored page snapshots without parser-dependent operations. `BOOK_TEST_BROWSER_TRANSPORT=1` enables the browser-form bridge in restricted local environments; normal navigation and real parser are used in CI. Full CommonMark/Mermaid and Docker Release Gate remain required before production rollout.

## 0.2.6 — Revision History

Open **Studio → Revision history** for all current and retained deleted pages, or **Page history** on an existing page. The same routes are available under each workspace prefix. Choose a historical revision, Draft or Published snapshot, and compare with the current draft or another retained revision. The view shows the author username, action, recorded UTC time, revision number, historical path, title, section, order, optional note, escaped line diff and original Markdown. **Render historical preview** uses the shared Markdown engine with private Studio media URLs. Use the optional revision note on manual Save draft / Publish or Restore as draft (500 UTF-8 bytes, single line). Autosaves record changed content, not identical snapshots; they do not consume a pending manual note.

History is keyed by stable page ID, not path. Page creation/saving, publication/unpublication, tree move/reordering/duplication/publication, copying versions and deletion are captured inside the same exclusive BookStore transaction. Existing pages receive an initial baseline before their first tracked change; historical authors from releases before 0.2.6 are unknown and labeled System / imported. The event timestamp for a baseline is its recording time, not an invented edit time. CLI calls without an authenticated actor are system events. Deletion removes the live page and publication, but retained history is recoverable. Restore as draft checks the selected revision ID, latest history head and current optimistic revision; it restores title, section, order, Markdown and historical relative-link base, retains the latest current path, and **never publishes or rewrites the public URL**. A deleted page is recreated at its last retained path with the same page ID and a higher revision, provided the path remains available. Restoration records its source revision ID. Owner/Admin/Editor with effective workspace authoring access can view and restore history; Viewer, public Reader and outsiders cannot. Restoring is separate from publishing, which continues to require Admin/Owner. The existing RBAC/identity lock encloses the entire content transaction.

### Retention and media

History is a bounded `history` map in each existing JSON BookStore, committed atomically with the current pages. No content migration command or new runtime dependencies are needed. It retains up to 50 events per page, 2,000 events and 8 MiB of history per workspace; the actual retained amount can be smaller to keep the complete database within the 32 MiB portable-backup limit. Oldest events are removed first, including deleted-page history. History is not a permanent audit archive. Large branch operations and near-full stores may retain fewer events; use regular full backups for longer retention. Unchanged autosaves do not add snapshots. Each event stores both draft and published state (when available) to allow exact comparison and restoration; large diffs use a bounded remove/add fallback rather than an unbounded LCS matrix.

Retained historical media references block deletion, including after unpublication/deletion. They **never grant public access**: the Reader/media policy still depends only on accessible current published snapshots. Original binary files remain in the same media store. A file becomes removable once no current or retained historical references remain; there is no manual history purge UI in this release. Media labels include historical uses. Content JSON/TAR backups contain history; TAR restore and `bin/verify-data.php` validate historical snapshots and their CommonMark media indexes. A backup is sensitive and includes unpublished historical text, so keep it private. Before upgrading, stop writes, create a complete backup of `BOOK_DATA_DIR`, and run the existing data audit/reindex if needed to correct legacy reference indexes. Identity schema 4 and BookStore schema 1 remain unchanged; the history map is optional for old data. **Do not downgrade code alone**: old code does not protect historical references from deletion. Restore the matched pre-upgrade backup for rollback.

### Tests

Run `composer test` with installed dependencies. The new `php tests/history.php` and `python3 tests/history-http.py` suites cover stable IDs, authors, notes, revision conflicts, deleted-page restoration, history media retention, backup/restore, scoped role access, diff escaping and retention. Both have `--storage-only` modes for local environments without CommonMark; those modes deliberately skip parser-dependent success paths. The full Composer suite runs without that flag.

## 0.2.5 — Private Documentation

### Configure visibility

- **Workspaces → Workspace visibility → Save visibility** (Owner/Admin): `private` requires a current organization member with effective workspace read access; `public` allows anonymous Reader access to published books that are not private. New scoped workspaces and existing scoped workspaces from 0.2.4 default to private. Archived workspaces/organizations are never readable.
- **Studio → Books → Book visibility → Save visibility** (Owner/Admin): `inherit`, `private`, or `public`. An inherited book follows the workspace. A private book is readable only by authorized workspace members. Explicit public cannot override a private workspace. Existing books inherit by default. All versions, paths, and published snapshots of the book follow its current visibility; drafts remain Studio-only. Visibility changes are immediate, revision-checked, and do not publish drafts.
- Existing root/legacy Reader remains public by default. Before adoption, Installation Owner can restrict individual legacy books. After adoption, the legacy workspace can also be made private via Workspaces; old `/docs/`, `/api/v1/`, `/search`, and `/media/` URLs follow the same policy. Login and account management remain reachable. Archiving the owning organization hides its legacy content as well.

| Workspace | Book | Anonymous / nonmember | Member with read access |
|---|---|---|---|
| Public | Inherit/Public | Published only | Published only |
| Public | Private | No | Published only |
| Private | Any | No | Published only |
| Archived | Any | No | No |

The explicit workspace `none` assignment still denies a signed-in member. Public content, by definition, is accessible anonymously; denial is not a way to keep public material secret. Use private workspace/book visibility for confidential content. Book-level assignments independent of workspace membership are not included. Editor and Viewer cannot change visibility. Public Reader access never grants authoring, preview, export, or private media privileges.

### Privacy enforcement

Catalog, sidebar, version selector, previous/next links, Reader, API and search share a visibility-filtered published list. Redirects and internal link rewriting cannot disclose a hidden target. An anonymous request for a private workspace receives 401; a signed-in user without access gets 404. A hidden book/page inside a public workspace returns 404 without its title/body/redirect. Dynamic Reader/API/media responses use `Cache-Control: private, no-store`; Reader responses vary on Cookie, and protected/denied responses carry `X-Robots-Tag: noindex`. Configure reverse proxies/CDNs to respect these headers and never cache authenticated responses.

Media originals, download and thumbnail routes check published AST media references in the same workspace and in a book accessible to that requester. A draft-only file requires Studio authorization; a Viewer cannot use Media Picker or `/studio/media/file/`. **A file referenced by any public published book is public**, even if also used in a private book; remove all public references or make those books private to revoke anonymous delivery. Studio labels files as Public reference, Restricted published reference or Studio only. Previously downloaded copies cannot be recalled. Review public Markdown before publication; the policy does not redact secrets intentionally written into public documents or their links.

### Upgrade from 0.2.4

Stop writes and back up the complete `BOOK_DATA_DIR` (identity, legacy content, every workspace and media). Overlay the changed files, rebuild/restart and check `/health/ready`. Identity automatically migrates schema 1/2/3 to 4, preserving users, sessions, organization roles, workspace restrictions and stable IDs. Workspace visibility defaults to private for nonlegacy and public for legacy. BookStore remains schema 1 with optional book visibility and visibility-revision fields. No new runtime dependencies. Existing Markdown, files, drafts and published URLs are not rewritten.

Identity CLI backups now use schema 4 and preserve workspace visibility; restore supports schemas 1–4. Content TAR/JSON preserve book visibility. For recovery use a matched identity backup and all content backups from the same stopped installation; an older registry backup cannot recover newer privacy choices. **Do not downgrade code alone**: old readers can ignore book restrictions. Restore the full pre-upgrade snapshot for rollback. Keep backups private.

### Privacy tests

```sh
php tests/privacy.php
python3 tests/privacy-http.py
python3 tests/workspaces-browser.py
```

The default HTTP test creates and publishes actual Markdown pages using CommonMark, uploads files, checks private Reader/Preview and tests redirect/media boundaries. `--storage-only` tests the same authorization and HTTP paths with genuine stored published snapshots, omitting parser-dependent rendering. The browser suite verifies real visibility forms and uses normal navigation in CI; `BOOK_TEST_BROWSER_TRANSPORT=1` is a form-transport fallback for loopback-restricted local environments. Full Composer and Docker Release Gate remain required before deployment.

## 0.2.4 — Roles & Permissions

Organization roles are `owner`, `admin`, `editor`, `viewer`. Every organization still has exactly one Owner. Existing Member accounts migrate to Viewer without losing their previous Reader access. New invitations grant Viewer; an authorized manager can change the role after acceptance. Installation Owner is not an organizational superuser.

Use **Organizations → Members → Change role** to assign roles. Owner can appoint Admins; Admin can manage Editor/Viewer and issue/revoke invitations, but cannot change another Admin, appoint an Owner/Admin, transfer ownership, or change organization settings/lifecycle. Owner transfer demotes the old Owner to Viewer. Use **Workspaces → Workspace access → Save access** to set per-user `inherit`, `none`, or an explicit role not exceeding the organization role. Owner always has access; only Owner can restrict an Admin. An organization Admin retains organization-level member management and workspace creation even when their content access is narrowed in a particular space. Explicit workspace restrictions survive organization promotion/demotion (a role above the new ceiling is reduced); removal/leave deletes grants so reinvitations cannot resurrect them.

| Operation | Owner | Admin | Editor | Viewer |
|---|---|---|---|---|
| Published Reader, API/search, referenced media | yes | yes | yes | yes |
| Studio, drafts/preview, private media | yes | yes | yes | no |
| Save drafts, create books/pages, copy versions, move/duplicate tree, upload/update media | yes | yes | yes | no |
| Publish/unpublish page or tree, permanently delete page/media, JSON/TAR export | yes | yes | no | no |
| Manage workspace settings/access | yes | yes | no | no |
| Manage Editor/Viewer organization membership, invitations | yes | yes | no | no |
| Appoint Admin, transfer Owner, organization settings/lifecycle | yes | no | no | no |

The table describes effective content roles for a workspace and organization roles for organization operations. Server-side deny-by-default Studio action policy runs under the identity lock, before any content lock; changes to membership or roles cannot interleave with authorized content writes. CSRF and optimistic revisions are retained. Viewer/Editor cannot bypass publication/export restrictions by direct POST, AJAX, or alternate legacy Studio routes. A `none` assignment excludes the workspace from the listing and denies scoped Reader/API/media as well as Studio. As of 0.2.5, legacy Reader and scoped visibility follow the policies above. Before legacy adoption only Installation Owner can use root Studio; after adoption it follows the effective role of the legacy workspace.

### Upgrade from 0.2.3

Back up the entire `BOOK_DATA_DIR` with the application stopped. Replace changed source files and rebuild/restart. Identity automatically migrates schema 1/2 to schema 3 under its existing lock, retaining users, sessions, organizations and workspaces. The content BookStore stays schema 1. No new runtime dependencies. Identity CLI backup now writes schema 3 including roles and workspace restrictions; restore supports schemas 1/2/3, excluding live sessions and invitation/reset tokens. Back up each content store as before. **Do not downgrade code only:** 0.2.3 does not enforce these roles; restore the pre-upgrade data snapshot for rollback.

### RBAC tests

```sh
php tests/permissions.php
python3 tests/permissions-http.py
python3 tests/permissions-browser.py
```

The default HTTP test includes real CommonMark draft saving, publication and Reader. `--storage-only` exercises the remaining HTTP operations with a real stored page fixture when Composer dependencies are unavailable; it is not a substitute for the default release test. The browser test uses normal navigation in CI. In loopback-restricted test environments, `BOOK_TEST_BROWSER_TRANSPORT=1` submits actual browser-generated form data through Playwright's HTTP client. CI runs the complete tests with installed dependencies.

## 0.2.3 — Workspaces

Open `/workspaces` after signing in. Create an organization first, then use **Create workspace** in that organization. Workspace names/slugs can be changed without changing the permanent IDs or URLs. Each organization owner can create, rename, archive and restore spaces; organization members can read published content in its active spaces. Nonmembers (including the installation owner of another organization) cannot access the content. Organization owners alone can edit/publish, upload media and export their spaces until the full RBAC release. New spaces are private to their organization; legacy published content stays public. Archiving a space or its organization disables access to scoped content. An organization containing spaces cannot be permanently deleted in this release.

Each space has its own BookStore, page tree, revisions, redirects, search, API, media library and TAR export. New spaces start empty; use **Books** in its Studio to create a book, then create pages. Book records have stable IDs; the same book slug and page path may exist in different spaces. IDs, not editable slugs, identify the route:

```
/w/{organization-id}/{workspace-id}/
/w/{organization-id}/{workspace-id}/studio
/w/{organization-id}/{workspace-id}/studio/books
/w/{organization-id}/{workspace-id}/docs/{book}/{version}/{path}
/w/{organization-id}/{workspace-id}/api/v1/pages
/w/{organization-id}/{workspace-id}/api/v1/search?q=example
/w/{organization-id}/{workspace-id}/media/{media-id}
```

Markdown remains portable: `/docs/...` and `/media/{id}` in a scoped document are resolved within that space, including nested components and private previews. Copy the Markdown URL from its media library; do not paste a full scoped media route as a local attachment reference. Relative links use the existing page-link context. Reader/API/search and media never fall back to another space. Scoped routes require a valid cookie and current membership; Studio additionally requires the current organization owner. The identity lock is held through scoped content operations (identity before content), preventing concurrent membership revocation or ownership transfer from interleaving with authorized writes. This serializes requests across spaces and is intended for bounded self-hosted installations, not high-concurrency multi-server operation.

### Upgrade from 0.2.2 / legacy adoption

Stop writes and back up the complete `BOOK_DATA_DIR` before upgrading. Existing root documentation and URLs (`/docs/...`, `/api/v1/...`, `/media/...`, `/studio`) are unchanged. After upgrade, the installation owner can create or choose an organization they own, open `/workspaces`, and select **Adopt existing documentation (once)**. This creates a legacy workspace registry entry without moving or rewriting content. Its Studio continues at `/studio`, now governed by the owning organization's current owner and archive status. Transfer ownership only if you intend to transfer control of all legacy content. The legacy Reader remains public and cannot be archived separately. New workspaces do not inherit any of the legacy content.

Identity schema 2 receives an additive workspace registry; content schema 1 receives additive book records. No new Composer/npm dependencies. Do not downgrade only the code after workspace creation/adoption: older releases do not enforce the new legacy ownership boundary or understand scoped stores. Restore the pre-upgrade backup to roll back.

### Storage, backups and recovery

```
BOOK_DATA_DIR/
  books.json                   # legacy content (may be seed until first write)
  media/                       # legacy media
  identity/identity.json       # users, organizations, workspace registry
  workspaces/{org-id}/{workspace-id}/
    books.json                 # books, pages, drafts, publications, redirects, media metadata
    books.lock
    media/                     # original blobs and generated thumbnails
```

Each new workspace is initialized with a durable empty database before its registry entry commits. Missing registered content fails closed (503/readiness failure), not a seed fallback. Failed creation may leave an unreferenced directory; an operator may remove it offline after checking the registry and backups. Limits: 100 spaces per installation, 1000 books and 1000 pages per space, 32 MiB metadata per space, 1000 media files / 512 MiB originals per space. Total disk consumption is the sum of all spaces; monitor the volume.

For a full offline backup, stop writes and save the entire data directory, or collect the private identity backup, legacy content TAR and one TAR per nonlegacy space. The browser TAR export only contains the current space (not credentials, other spaces or the registry). Operator commands use `BOOK_DATA_DIR`:

```sh
php bin/identity.php backup /secure/identity.json
php bin/media-backup.php backup "$BOOK_DATA_DIR" /secure/legacy.tar
php bin/workspaces.php list
php bin/workspaces.php backup WORKSPACE_ID /secure/workspace.tar
```

Restore the legacy TAR into a new data directory first, restore identity into that same directory, then restore **every** nonlegacy workspace TAR by its original ID before starting the server. The workspace restore refuses to overwrite an existing directory and validates content/media through the existing TAR restore implementation.

```sh
php bin/media-backup.php restore /secure/legacy.tar /private/restored
export BOOK_DATA_DIR=/private/restored
php bin/identity.php restore /secure/identity.json
php bin/workspaces.php restore WORKSPACE_ID /secure/workspace.tar
```

Check `/health/ready` after startup; all registered stores must be present. Identity backup does not restore sessions or invitation tokens. RBAC and workspace assignments were implemented in 0.2.4; configurable visibility is implemented in 0.2.5. Cross-workspace book moves and permanent workspace deletion are intentionally not exposed in 0.2.3; archive preserves data.

### Workspace tests

`php tests/workspaces.php` exercises the real registry, storage and authorization without the Markdown parser. `python3 tests/workspaces-http.py` runs real multi-user HTTP, including CommonMark publication and restore. `python3 tests/workspaces-browser.py` exercises the UI and scoped AJAX editor with Chromium. Install dependencies first. The HTTP test also offers `--storage-only` for environments without CommonMark (published fixtures, no render/restore), and the browser test supports `BOOK_TEST_BROWSER_TRANSPORT=1` for loopback-restricted environments (form bridge, no AJAX render/publish).

## Features

- Individual user accounts, password sign-in, personal profiles, server-side revocable sessions, operator recovery tokens and installation-owner user management. Studio is owner-only in each organization workspace; ordinary members can read its published documentation.

- Multiple books and versions, hierarchical URL paths, section navigation, page order, previous/next links, headings table of contents, responsive layout and light/dark theme.
- Studio page tree: nested page/folder navigation, native drag-and-drop, keyboard/touch move form, slug rename, branch duplication, stable IDs and published redirects. Draft structure and public navigation are separate.
- Studio: visual block editing, Markdown source mode, server preview, autosave, create/edit/delete pages, explicit publishing and unpublishing, version cloning to drafts, optimistic revision checks.
- CommonMark 0.31.1 and GitHub-Flavored Markdown (GFM), powered by League CommonMark: nested lists, ATX/Setext headings, reference links, inline/fenced/indented code, blockquotes, tables, task lists, strikethrough, autolinks and images. Shared rendering in Reader and Studio with stable heading slugs and legacy anchors.
- Published-only search and JSON read API, health probes, authenticated JSON metadata export and complete media backup.
- Media library: drag-and-drop uploads, folders, search/filter, immutable files, SHA-256 deduplication, private previews, safe published access, thumbnails and full TAR backup/restore.
- Images and attachments: insert or upload directly in Studio, edit alternative text, captions, image width/alignment, replace media, insert download blocks, and open images at full size in Reader/Preview.
- Mermaid diagrams: standard fenced Markdown, live Studio preview, sandboxed Reader SVG, zoom/full screen, PNG export, source fallback and theme redraw. Block drag handles and keyboard reorder controls.
- Local file persistence with locking and atomic replacement, no SaaS, database, Node, CDN or external API required at runtime. Composer installs PHP dependencies at build/install time.

- Rich documentation components: six callout types, responsive card grids with links, icons and media; structured Visual editing, nested Markdown and shared preview.

## Start locally

Requirements: PHP 8.4.1+ with `mbstring`, `fileinfo`, `phar`, JSON, ctype, PCRE and sessions (GD with JPEG/PNG/WebP support is recommended and included in Docker); Composer 2 for dependency installation. Linux is the supported filesystem environment.

```sh
composer install --no-dev --prefer-dist --no-interaction
sh bin/install-mermaid.sh # Node 22/npm; install the local diagram library
# Read-only demo (Studio is disabled until configured)
php -d upload_max_filesize=20M -d post_max_size=72M -d max_file_uploads=200 -d memory_limit=256M -S 127.0.0.1:8080 -t public public/router.php
```

Open http://127.0.0.1:8080/ and `/login`. Public documentation is readable without initialization. To enable Studio on a fresh installation, initialize the owner from the trusted local CLI (run as the same OS user that owns the data directory):

```sh
export BOOK_DATA_DIR="$PWD/var"
export BOOK_SECURE_COOKIE=0 # local HTTP only; use 1 on HTTPS
read -rs -p 'Owner password (12–72 bytes): ' BOOK_PASSWORD; echo
printf '%s' "$BOOK_PASSWORD" | php bin/identity.php init admin admin@example.com 'Administrator'
unset BOOK_PASSWORD
php -d upload_max_filesize=20M -d post_max_size=72M -d max_file_uploads=200 -d memory_limit=256M -S 127.0.0.1:8080 -t public public/router.php
```

No default password, open public registration or Basic Auth fallback. Invitation-only registration is available in 0.2.2. Sign in via `/login` and use `/account` to manage profile, password and sessions; `/account/users` is owner-only. Use HTTPS and `BOOK_SECURE_COOKIE=1` outside localhost. Secure cookies have the `__Host-` prefix, HttpOnly, SameSite=Strict and path `/`; do not use HTTP for real credentials. Reverse proxies should rate-limit `/login`, `/account/reset` and Studio; rate limiting inside the application uses `REMOTE_ADDR`, not client-supplied forwarded headers.

## Upgrade to 0.2.2 — Organizations

Stop writes and make a complete backup of `BOOK_DATA_DIR` before applying the changed files. Rebuild the Docker image or reuse the existing locked Composer and Mermaid dependencies. Existing users, sessions, documentation, media and URLs are preserved. The first identity transaction atomically upgrades `identity/identity.json` from schema 1 to schema 2, adding organization and invitation collections. No separate migration command is needed; `/health/ready` also checks identity integrity. Do not downgrade the code alone: restore a pre-upgrade data backup for rollback to 0.2.1, which cannot read schema 2.

Open `/organizations` after signing in. Any enabled account can create an organization; each organization has exactly one enabled owner, a stable ID and a unique case-insensitive slug (3–64 ASCII letters/digits/hyphens). Choose an organization to manage its settings, members and invitations. Owners can rename, archive, restore, transfer ownership to an enabled member, remove members and revoke invitations. Members may leave, but an owner must transfer ownership before leaving. An installation operator cannot disable an account that owns an organization until ownership is transferred or the organization is deleted. Archiving revokes outstanding invitations; archived organizations remain visible to members but cannot accept invitations or be renamed. An owner can delete an archived organization only by confirming its slug. Take a private identity backup before permanent deletion; deletion removes organization metadata and memberships, not individual accounts. Organization changes are checked against a revision under the same identity lock, so stale forms are rejected and changes to membership take effect on the next request.

### Invitations

1. An organization owner chooses an existing username or a username for a new account, and creates an invitation. The one-time token is shown once, with a copyable link `/organizations/join#TOKEN`. Share it securely; the token is a bearer secret for joining the specified organization. It is not placed in HTTP query strings or logged by normal URL access logs. The browser copies the fragment into the form and removes it from the address; without JavaScript paste the token manually.
2. Existing users must sign in to the invited account and open `/organizations/join` (paste the token or reopen the original invitation link). New users can enter a display name, email and password on that page; the account is created with the invited username. Email is contact data, not a verified identity or a delivery channel. A token for an existing account is also bound to its immutable ID.
3. The server verifies the token, expiry, account and membership under the identity lock. Registration and membership commit atomically. A used, replaced, revoked or expired token cannot be redeemed. Invitations expire in seven days; creating another for the same username replaces the previous one. Rate limits are five attempts per token and 30 per IP per 15 minutes, independent of login limits. Only SHA-256 token digests are stored. The invitation form is protected by the existing pre-authentication or session CSRF token.

Limits: 100 organizations per installation, 100 outstanding invitations per organization, 500 users and the existing 4 MiB identity state quota. Organization members can see the names, usernames, membership roles and status of other members of that organization; only its owner sees invitation metadata. Organization ownership does **not** grant global Studio access or access to other organizations. The installation owner continues to manage the existing global documentation and accounts; ordinary users can manage their own organizations but cannot enter Studio. Workspaces, scoped content, Admin/Editor/Viewer RBAC and private documentation arrive in subsequent releases. No email delivery, public registration, or externally verified identity is introduced.

### Backup, restore and tests

`php bin/identity.php backup /secure/backups/identity-0.2.2.json` now saves users, organizations and memberships in schema 2; it excludes sessions, reset tokens, invitations and rate limits. `restore` accepts both schema 1 and 2 backups into an uninitialized identity store. Reissue invitations after restore. Keep the backup private (password hashes are included). Content-only TAR/JSON exports still exclude identity and organizations. Back up both components while writes are stopped.

```sh
php tests/organizations.php
python3 tests/organizations-http.py
python3 tests/organizations-browser.py
composer test
```

The PHP tests include migration, cross-organization authorization, ownership invariants, expiry, restore and concurrent redemption by two real PHP processes. HTTP tests use independent cookie jars; browser tests use the normal application and invitation fragment navigation in CI.

## Upgrade to 0.2.1 — Identity & Authentication

1. Stop writes and back up the complete `BOOK_DATA_DIR` before overlaying the release. Keep the existing `BOOK_ADMIN_HASH` only for the initial migration.
2. Rebuild the container or install existing locked dependencies and local Mermaid assets as before. Open `/login`, enter username `admin` and the previous password. Successful authentication atomically creates the owner with the existing password hash (rehashes if required). Failed login cannot initialize an owner. If the previous password is unavailable, run the trusted CLI `init` command before the first successful login instead. Existing passwords exceeding 72 bytes require CLI initialization/recovery rather than accepting bcrypt-truncated passwords.
3. In `/account` set the owner's email and change the password if needed. Remove `BOOK_ADMIN_HASH` from deployment configuration after initialization and recreate the container. Once an owner exists, that hash is never consulted to authenticate any account, even if left configured. Existing Basic Auth credentials and old PHP session cookies do not authorize Studio.
4. Create individual users under `/account/users`; ordinary users can sign in, manage their own profile and sessions, but cannot enter any Studio route. Owner is an installation-level bootstrap privilege, not yet an organization role. No organizations, private books, email verification, SMTP, public signup or RBAC beyond this owner gate in this release.
5. Verify `/health/ready`, public docs, Studio, account and backup. Content `books.json` schema and Markdown are unchanged; identity uses a separate schema-1 file under `BOOK_DATA_DIR/identity/identity.json` with a dedicated lock. This avoids including credentials in content backups. Do not remove that directory to reset a password.

### Identity and session policy

Usernames are case-insensitive ASCII (3–40 characters), email addresses unique case-insensitively (contact data, not login identifiers or verified recovery channels). Passwords are hashed with PHP `PASSWORD_DEFAULT`; new passwords must be 12–72 bytes. Sessions store only a SHA-256 digest of the random 256-bit bearer token; CSRF tokens are bound to each session. Login and reset forms use a separate strict, HttpOnly double-submit cookie. Session lifetime is seven days, idle expiry eight hours, with activity refreshed at most every five minutes; maximum ten sessions per account. Password change and recovery revoke all previous sessions and recovery tokens. Disabling a user revokes sessions immediately for subsequent requests; requests already authorized and executing may finish. Login limits: five failed attempts per account and 30 total attempts per source IP per 15-minute window (successful login clears the account counter, not the IP counter). Recovery and current-password verification have separate rate buckets. No trust in X-Forwarded-For; behind a reverse proxy, configure infrastructure rate limiting and consider the shared IP limit. Maximum 500 users, 4 MiB identity metadata. Account management cannot disable the only installation owner. Session expiry/denial stops Studio autosave; copy local Markdown, sign in again in another tab, then reload before retrying.

### Recover access and back up identity

Only a trusted operator with filesystem access may issue a recovery token. Tokens expire after 30 minutes, are single-use, are stored only as SHA-256 digests, and a new token invalidates the previous one. Pass the token securely to the user; enter it in `/account/reset` (not in a URL). Resetting the password revokes all existing sessions. Do not write tokens to public logs or send them over unencrypted channels.

```sh
# Run as the data directory owner, with BOOK_DATA_DIR set to the installation.
php bin/identity.php reset admin
php bin/identity.php backup /secure/backups/identity-0.2.1.json
# Restore only to an installation with no initialized identity state.
BOOK_DATA_DIR=/private/restored-book php bin/identity.php restore /secure/backups/identity-0.2.1.json
```

The identity backup is a mode-0600 JSON file containing user records and password hashes (sensitive). It excludes sessions, reset tokens and throttling state. It cannot overwrite an existing backup or initialized identity; stop the service, restore documentation TAR into a new data directory, restore the matching identity backup, fix ownership, then point the service at that directory. A content TAR or `/studio/export` remains a **content-only** export (pages, redirects and media; no credentials); for a complete installation backup take both during a maintenance window or copy the entire stopped volume. A raw volume snapshot may contain live session/token hashes; prefer the identity CLI backup when migrating. Do not restore the raw identity state to an internet-facing instance without revoking sessions. Rollback to 0.2.0 requires the old deployment hash and pre-upgrade backup: older code ignores identity users and permissions.

Docker fresh setup (the volume must be writable by `www-data`):

```sh
docker compose up -d --build
read -rs -p 'Owner password: ' BOOK_PASSWORD; echo
printf '%s' "$BOOK_PASSWORD" | docker compose exec -T -u www-data book php bin/identity.php init admin admin@example.com 'Administrator'
unset BOOK_PASSWORD
# Recovery (keep output private):
docker compose exec -T -u www-data book php bin/identity.php reset admin
```

### Tests

`php tests/identity.php` exercises the real durable identity store without Composer; `python3 tests/identity-http.py` exercises the real PHP application with independent cookie jars; `python3 tests/identity-browser.py` exercises interactive form login, management and revocation. The complete `composer test` and CI browser/Docker gates use the installed CommonMark and Mermaid dependencies and cookie-authenticated Studio. `BOOK_SECURE_COOKIE=0` is used only on disposable loopback tests.

## Upgrade to 0.2.0

Back up the entire `BOOK_DATA_DIR`, including `books.json` and any media files. Overlay the release files in the existing project directory (do not replace or delete `var/` or the persistent Docker volume). Install the locked dependencies using `composer install --no-dev --prefer-dist --no-interaction`, and install the local Mermaid asset with `sh bin/install-mermaid.sh`, or rebuild the Docker image with `docker compose up -d --build`. The JSON schema remains version 1; media metadata and page references are optional additive fields. Existing draft/published Markdown is not rewritten, and no database migration is required. Version 0.1.6 lazily adds stable page IDs, a published path, historical path reservations and optional relative-link context. Existing paths are not changed until a tree operation is committed. Do not roll back code to 0.1.5 after moving pages without restoring a pre-upgrade backup: older readers ignore the new published-path metadata. Recheck the public reader, Studio and health endpoint after the update.

### 0.2.0 release checks and upgrade from 0.1.9

This release preserves the schema-1 format and all existing Markdown sources. There is no destructive migration, but perform the following before exposing the upgraded service:

1. Stop authoring writes and back up the complete data directory, including the media blobs. Keep this copy outside `public/`. If the legacy JSON is larger than 32 MiB, use an offline filesystem copy rather than the TAR exporter.
2. Overlay the changed files; install the Composer lock and the pinned local Mermaid bundle (or rebuild the image). Keep the existing data volume and admin hash.
3. Run the data audit as the same operating-system user as the application (or with permissions to read the private data). For a Docker installation use `docker compose exec -u www-data book php bin/verify-data.php /var/lib/cajeerbook`.

```sh
php bin/verify-data.php /var/lib/cajeerbook
# If exit status is 2, review the reference mismatch count and, after backup:
php bin/verify-data.php /var/lib/cajeerbook --repair-media-references
php bin/verify-data.php /var/lib/cajeerbook
```

The audit validates page identities/paths, stored snapshots, actual media MIME/size/SHA-256 and AST-derived media references. Exit 0 means consistent, 2 means the stored reference index differs from the renderer, 1 means invalid data or a missing/corrupt file. The explicit repair recomputes only reference lists, in one locked atomic write, and invalidates affected page revisions. It does not publish a draft or rewrite Markdown. Previously, canonical media URLs with a query or fragment could fail to grant public access even when explicitly linked in published content. The repaired index makes those published links available and keeps draft-only references private. If an old link points to a nonexistent file, correct the link or restore the missing file; repair fails rather than silently dropping it.

New metadata snapshots are capped at **32 MiB** (matching the portable backup format). The 256 KiB per-page and 512 MiB binary-media quotas remain. Legacy oversized stores can still read and commit strictly size-reducing changes until below the cap; other writes are rejected before replacement. Readiness reports 503 for an oversized legacy store. Subdivide or remove content after an offline backup. Existing valid stores below the limit need no format conversion.

`/health/live` checks that the PHP application starts; `/health/ready` also checks the schema, page/path consistency, private storage writeability, media blob existence/size and required PHP/Mermaid assets. Docker uses the readiness endpoint for its health check. A failed readiness response is deliberately generic; see server logs for the cause. Dynamic responses use `Cache-Control: no-store` to avoid serving stale private/public state after unpublishing. The data directory and backup/restore destinations may not be inside or routed through `public/`. `BOOK_DATA_DIR` must not contain `..` segments. Apache disables symlink traversal in the document root. Continue to use HTTPS with `BOOK_SECURE_COOKIE=1`, rate limiting and a private data directory on production deployments.

Studio requests now have a 30-second deadline. On timeout the editor retains local Markdown and re-enables the controls; an uncertain save may have reached the server, so retry uses the existing revision check and a conflict must be resolved by copying/merging the local text. This is not a guarantee of automatic rollback after a network interruption.

### Release gate

Run on a disposable installation with PHP 8.4.1+, installed Composer dependencies, the pinned Mermaid assets, and Python 3.12:

```sh
composer validate --strict
composer audit --locked
composer test
python3 -m pip install playwright==1.57.0
python3 -m playwright install chromium
python3 tests/editor-network-browser.py
python3 tests/editor-browser.py
python3 tests/media-browser.py
python3 tests/images-browser.py
python3 tests/page-tree-browser.py
python3 tests/rich-blocks-browser.py
python3 tests/interactive-browser.py
python3 tests/mermaid-browser.py
```

`tests/release.php` builds one mixed document containing GFM, media, callouts, cards, nested tabs/accordion/steps, code tabs and Mermaid; verifies lossless editor segmentation, draft/public isolation, reference reindexing, tree publication and full backup/restore. `tests/release-http.py` verifies the actual HTTP application with an upload, rendering, publication, conflicts, redirects and TAR export. It creates a temporary store by default; `BOOK_TEST_URL` selects a disposable external instance (never use production data). CI runs the complete suite, then builds the Docker Compose deployment, executes the integration tests over Apache and verifies persistence after restarting the container. The narrow network-recovery browser test uses injected responses to test timeout handling without waiting 30 seconds; the full browser suites use real PHP and CommonMark. A passing CI and Docker release gate are required before declaring the build production-verified.

## Studio visual editor (0.1.6)

Open `/studio` and authenticate. Visual mode is enabled by default when JavaScript and the browser editing APIs are available; Markdown remains usable without JavaScript or if conversion fails. All assets are served locally, and there is no npm/CDN dependency.

- **Visual:** click a text block and type directly. Select text to apply bold, italic, strike, inline code, links or remove links. Use Block style for paragraphs, H1–H6 and quotations. Bullets/numbered lists, list indent/outdent, undo/redo and native editing shortcuts are supported. Add a paragraph, heading, quotation, list, divider or language-labelled code block using Add block. Remove a supported block with confirmation. Pasting inserts plain text rather than untrusted HTML.
- **Markdown:** edit the canonical source. Switch from Visual to Markdown to inspect the generated source, or use Copy Markdown. The server still stores Markdown, not HTML or an editor-specific JSON format.
- **Preview:** renders the current unsaved document on the server with the same CommonMark/GFM engine and URL policy as Reader. Existing page paths resolve relative links and images against their saved link context (the original path for moved/duplicated pages). No publishing is required to preview.
- **Save:** Save draft and Publish commit through the existing revision-checked store. Existing drafts autosave after approximately 1.8 seconds of idle editing; a new page needs an explicit first save or publication. Autosave never changes the published snapshot. Ctrl/Cmd+S saves the draft. A revision conflict stops writes, keeps the local text in Studio and asks the author to copy Markdown and reload/merge. Failed requests do not discard unsaved content. Refresh or leave Studio only after saving; the browser warns about unsaved changes.

Visual editing is intentionally source-preserving, not a wholesale HTML-to-Markdown rewrite: unchanged blocks and gaps (including reference definitions) retain their source. Modified supported blocks are serialized to canonical Markdown and separated safely; a modified reference link may become an equivalent inline link. Browsers normalize textarea line endings to LF. GFM tables and task lists are editable. Standalone images and local attachment blocks are editable. Mixed inline images, complex attachment labels, raw HTML, custom directives, nested code blocks and other unsupported AST structures remain visible but protected in Visual mode; edit them in Markdown mode. Tables that contain unsupported inline content, surplus source cells, or exceed visual limits are also protected. The editor uses native contenteditable/selection and browser editing commands; Chromium is the browser tested in CI. Markdown mode is the reliable fallback for unsupported browser commands or complex formatting. No new database migration is required.

### Page tree (0.1.6)

Open **Studio → Page tree** (`/studio/tree`). Choose a book/version. Existing slash-separated paths become a nested tree automatically, including implicit folder nodes for paths with no parent page. Expand or collapse child branches. The Reader sidebar and previous/next navigation use the published hierarchy in depth-first order; Studio uses drafts.

- Drag a branch's handle onto **Before**, **After**, **Inside**, or the version-root drop area. The entire branch (including private descendants) moves as one transaction. Alternatively use the Source branch, Destination and Position selects and click **Move branch**; these controls also work by keyboard, touch, or without JavaScript.
- Set **New slug** to rename a branch while moving it. To rename in place, choose before/after a sibling or inside its parent; a root can use the root position. The `+ Child` link opens the existing page editor with a child path prefilled. Page titles are edited in Studio, not used as route slugs.
- **Duplicate branch as drafts** copies all pages below the source, including draft content, with fresh stable IDs and no public snapshots. Choose a non-conflicting slug/destination. Existing media references are retained; immutable files are not duplicated.
- **Publish tree** applies draft paths and navigation order to pages that already have public snapshots. It never publishes draft-only pages or modified body/title/section content. An ordinary page Publish updates content at its current public path; new or previously unpublished pages publish at their draft path. Use Publish tree to apply reordered or moved published pages. Manual Order is also draft navigation order.
- Old published Reader and API URLs return HTTP 308 to the current public path, preserving query strings; redirects follow the stable identity directly after repeated moves. Unpublished or deleted pages do not redirect publicly. Old draft-only paths are reserved privately (and old Studio edit links redirect to their current draft). To avoid stale editor writes and hijacked links, retired paths cannot be reassigned to another page; the same identity may return to its own former path.
- Moves preserve Markdown and its relative-link context instead of rewriting the source. Reader and Preview resolve old internal page references to current published targets, including relative dot segments and fragment/query suffixes. For newly authored links in moved pages, use absolute `/docs/...` URLs if the intended destination differs from the original relative context. Raw source returned by the API is not rewritten. Version copies retain relevant published aliases and rebase relative-link context to the copied version; absolute cross-version links remain as authored.

A tree transaction checks the entire page revision token and runs under the same exclusive BookStore lock as page writes. Stale tabs receive HTTP 409 and must reload; invalid moves return HTTP 422, occupied paths return HTTP 409. Cycles, cross-book/version moves, collisions and unsafe paths are rejected. New paths/moves support up to 32 nested slug segments, 250 path bytes and the existing 1000-page quota. Historical redirects and retired draft paths are capped at 10,000 entries each. Existing legacy paths remain readable; folders are implicit and disappear when their last descendant is removed. No separate empty folder objects, cross-version drag/drop, collaborative editing, or external dependencies are introduced. Backups preserve tree identities, paths and aliases; restore validates the additional metadata.

### Tables & Rich Text

Choose **Table** in Add block or open an existing GFM table. Select a cell to edit its text, then use the main formatting toolbar for bold, italic, strike, inline code and links. Tab/Shift+Tab moves between cells; Enter is disabled because GFM cells are single-line. The first row is always the header. Add/delete body rows, move body rows up/down, add/delete/move columns and choose column alignment using the table controls. The header and final column cannot be deleted; remove the entire block instead. Undo/Redo and Ctrl/Cmd+Z support recent cell and structural edits (up to 25 bounded table snapshots per editing session). Structural edits are saved immediately in the local Markdown source; Save draft/Publish uses the existing revision checks.

Paste tab-separated spreadsheet data directly into a selected cell, or open **Import CSV / TSV**, choose the delimiter, paste text and select Insert data. The grid expands as needed and replaces the destination rectangle (existing cells outside the rectangle are untouched); quoted fields and doubled quotes are supported. Embedded newlines in fields become spaces. CSV uses comma delimiters; use TSV for spreadsheet clipboard data. Import is limited to 200 rows, 50 columns, 2500 total cells (including header) and the existing 256 KiB document limit. Unsupported or oversized existing tables are kept as source-protected blocks. GFM has no merged cells or multiline block content in cells. Structural edits normalize the changed table to GFM, including escaped pipe characters and alignment markers; unchanged tables retain their original Markdown.

Choose **Task list** in Add block or use Task list in the formatting toolbar to toggle a selected list item's task marker. Checkboxes in Studio update the Markdown `[x]` / `[ ]` state; Reader checkboxes remain disabled. The existing code-block language field accepts a language identifier. Reader and Preview highlight common lexical tokens locally for PHP, JavaScript/TypeScript, Python, Bash, SQL and JSON (plus common aliases); other languages and code over 64 KiB remain plain. Copy code copies the original text, not highlighted HTML. The highlighter is intentionally lightweight, not a full grammar parser.

## Markdown authoring and rendering

The application uses [League CommonMark](https://commonmark.thephpleague.com/) with the CommonMark core and GFM extensions. Markdown source stays unchanged in the store and API. Reader and Studio share the parser, and the preview resolves relative links and images against the document's saved link context, not the Studio route. The following works in Markdown mode and in Reader:

````markdown
## Installation

1. Install PHP and dependencies.
   - Enable `mbstring`.
2. Start the server.

- [x] Markdown engine
- [ ] Visual editor

| Package | Version |
| --- | ---: |
| PHP | 8.4 |

See [setup](getting-started#run-locally), https://example.org or ~~old~~ **new**.

![Diagram](/assets/example.png "Example title")

```sh
composer install --no-dev
```
````

Heading IDs use lowercase Unicode text with runs of punctuation/whitespace replaced by hyphens; duplicates gain `-2`, `-3`, etc. Empty titles and reserved IDs receive a safe alternative. Existing `#section-N` links from 0.1.0 remain aliases for their original ATX headings. These legacy numbers follow the old parser's counter, including its handling of triple-backtick fences. Headings newly recognized by CommonMark, such as Setext headings, do not consume legacy numbers. Slugs are stable across renders and unrelated heading edits; editing a heading or reordering identical headings can change its slug. Use the TOC link for the actual anchor. GFM itself does not specify heading IDs.

Security policy: all raw HTML is escaped (including HTML otherwise allowed by GFM), executable MDX is disabled, unsafe URL schemes and protocol-relative URLs are rejected, `mailto:` is allowed only for links, and HTTP(S)/same-origin paths are allowed for links and images. Unsafe images render as plain alt text; unsafe links are inert. Data URLs are disabled. External images are fetched by the reader's browser, not the PHP server; they may disclose the reader's IP address to the image host, so use trusted or local images. HTTPS pages may block insecure HTTP images. No upload manager is included in this release.

The parser limits Markdown to valid UTF-8 and 256 KiB, 64 levels of block nesting, 1000 delimiters per line and 10000 auto-completed table cells. These deliberate safety policies and heading attributes differ from unrestricted specification HTML examples. GFM means the standardized extensions (not all GitHub website features); footnotes, alerts/callouts, tabs, cards and Mermaid rendering remain later releases. Code fences for `mermaid` render as code for now.

## Docker Compose

For a fresh local deployment, create `.env` with loopback cookie settings (no preset password; initialize using the CLI above). Only for a 0.2.0 migration, retain the previous `BOOK_ADMIN_HASH` in single quotes until the first successful form login.

```dotenv
BOOK_SECURE_COOKIE=0
```

```sh
docker compose up -d --build
```

Compose binds to 127.0.0.1:8080. Place an HTTPS reverse proxy in front of it, pass Host and configure HTTPS consistently, set `BOOK_SECURE_COOKIE=1` and recreate the container. Use the named volume `book-data` for persistence. PHP's development server is not a production server. Apache container build is provided; production TLS and Docker runtime have not been certified here.

## Content model and REST API

A page is identified by `book/version/path` (lowercase ASCII slugs, nested paths allowed). Each entry has a draft, published snapshot and revision counter. The public reader only reads published snapshots. Studio's Copy version action duplicates published pages as new drafts. Stable page IDs are immutable; Studio Page Tree can change draft paths and publish structural changes with redirects, subject to RBAC and review policy. Section and numeric order control navigation.

- `GET /api/v1/pages` — published page metadata keyed by page identifier.
- `GET /api/v1/pages/{book}/{version}/{path}` — published Markdown and metadata.
- `GET /api/v1/search?q=...` — up to 50 published matching pages.
- `GET /health/live`, `GET /health/ready` — JSON health endpoints.
- `GET /studio/export` — authenticated JSON metadata export, including drafts (no binary media).
- `GET /studio/media` — authenticated media manager; POST accepts CSRF-protected uploads and changes.
- `GET /media/{id}` — public only while referenced by a published page.
- `GET /studio/media/backup` — authenticated full TAR backup.

Use the Studio UI to author and publish. For direct links use `/docs/{book}/{version}/{path}`. Search is a local case-insensitive substring scan (not a full-text index).

## Media library (0.1.5)

Open **Studio → Media library** (`/studio/media`). Select or drop files, optionally enter a folder such as `guides/images`, and upload. With JavaScript, multiple files upload sequentially (up to 50 per batch), each with its own progress and result; successful files are retained even if others fail. The HTML form works for single files without JavaScript. Search by filename, folder or SHA-256; filter by exact folder or type, sort, and use pagination. Open a file, download it, copy its permanent URL, or expand Manage file to rename/move/delete it. Folders are logical metadata, not filesystem paths. Renaming must preserve file type; it does not change the file ID or links. Identical SHA-256/MIME content reuses the original ID and metadata; rename/move the existing item if needed.

Supported formats: PNG, JPEG, GIF, WebP, PDF, ZIP and UTF-8 TXT/MD/CSV. The server checks actual MIME using `fileinfo` plus an extension allowlist, dimensions for raster images, and full raster decoding when GD is installed. SVG, HTML, executables and unrecognized formats are rejected. ZIP/PDF/text are delivered as attachments, never executed or extracted. This is not a malware scanner: use trusted administrator uploads and scan untrusted documents separately. Limits are 20 MiB per file, 1000 distinct files, 512 MiB original media total, and 4096 px per image side / 12 megapixels. Cached previews (320 px maximum, PNG) are generated on demand with GD; without GD the original image is shown. Keep enough disk for the originals, derived thumbnails and temporary backups. Set PHP `upload_max_filesize=20M`, `post_max_size=72M`, `memory_limit=256M`, and reverse proxy upload limits accordingly. The Docker image configures these settings and GD.

### Using uploaded files in Markdown

Copy the URL from a media card and use it in Markdown (replace the example ID with the real one):

```markdown
![Screenshot](/media/0123456789abcdef0123456789abcdef)

[Download the guide](/media/0123456789abcdef0123456789abcdef)
```

Canonical local URLs are exactly `/media/{32-lowercase-hex-id}`. References in actual CommonMark links/images, including reference definitions used by a link, are recorded when saving or publishing. A file is private until at least one published page references it. Studio previews rewrite local media URLs to authenticated `/studio/media/file/{id}`; public Reader retains the canonical URL. Saving a draft never changes public access. Unpublishing or deleting the last referring published page revokes access. A literal URL in code or prose does not grant public access; deletion conservatively also protects literal references in drafts and publications. Unknown canonical media IDs are rejected when saving. Removing a reference from both snapshots (or deleting the page) makes the file eligible for deletion. Previously downloaded public copies cannot be revoked. Do not publish private content inadvertently. Use the visual tools below to insert media without typing these URLs.

### Images & Attachments (0.1.5)

In Studio, use **Insert image** or **Insert attachment** (also available in Add block). The authenticated media picker searches names/folders, shows image thumbnails, and pages through the library. Select a file or upload a new one with the existing MIME checks, size/quota limits and progress indicator. For an image, paste a clipboard raster image or drop a file on the Visual panel, then confirm Upload and insert. A dropped file opens the appropriate picker; insert one file at a time. Uploads are retained in the library even if you discard the page changes.

In Visual mode the new block is inserted before the active block, or at the start if no block is selected. This also avoids appending to an unclosed code fence. In Markdown/Preview mode the tool prepends a standalone Markdown block and selects it in Markdown so you can move it elsewhere. Edit image URL, alternative text (important for accessibility), caption, width (25/50/75/100% of the content area) and alignment (left/center/right). Width controls the maximum displayed figure width, not the stored image or its original dimensions. Replace from media library keeps image properties; attachments have an editable download label. Remove block removes the reference from the draft, not the underlying file. The existing Save draft, Publish and revision handling remain authoritative.

Reader and Preview render standalone images as responsive figures. A plain existing Markdown image title is displayed as its caption; editing an image normalizes its presentation metadata but untouched Markdown stays unchanged. **View full size** opens a keyboard-accessible modal with fit-to-screen/actual-size modes, an original-file link and local image download. Attachment blocks render a labeled download link; the server adds the download query only to rendered links, not the canonical source URL. PDF, ZIP and text files remain forced downloads; images can also be inserted as downloadable attachments. Mixed inline images and complex attachment labels remain editable in Markdown mode, not rewritten visually.

#### Portable Markdown syntax

The stored document is still standard Markdown. Rich image layout uses the optional title `book-image:v1:<percent-encoded JSON>` with exactly the supported presentation fields `width`, `align` and `caption`. Captions are plain UTF-8 text (up to 2000 bytes); only fixed width/alignment values are accepted. No author-supplied HTML or CSS is evaluated. An attachment uses the title `book-attachment:v1` on a standalone canonical local link. Other Markdown engines can still display the image/link; they do not interpret Book-specific titles as presentation metadata.

```markdown
![System architecture](</media/0123456789abcdef0123456789abcdef> "book-image:v1:%7B%22width%22%3A50%2C%22align%22%3A%22center%22%2C%22caption%22%3A%22System%20architecture%22%7D")

[Download the guide](</media/fedcba9876543210fedcba9876543210> "book-attachment:v1")
```

Replace the sample IDs with actual media IDs. The normal `/media/{id}` reference parser, private preview rewrite and publication access rules apply unchanged. Do not paste `/studio/media/file/...` private URLs into published Markdown. Existing ordinary image/link syntax remains supported, with no storage migration or new runtime dependencies.

### Persistence and full backup/restore

`BOOK_DATA_DIR` defaults to `var/` outside `public/`. Keep it private and writable by the web process. The first write creates `books.json`; until then seed pages are used. Metadata is committed atomically under the existing `books.lock`. Immutable originals are `media/{id}.blob` (0600) and thumbnails are derived files; filenames never become filesystem paths. The store is a single-node implementation for up to 1000 pages, with 256 KiB per Markdown document. Do not share the volume among multiple hosts or expose it through the web server. Docker uses the existing persistent volume for both documents and files.

- `/studio/export` retains the legacy authenticated JSON metadata export (not a complete file backup).
- `/studio/media/backup` downloads a consistent uncompressed TAR of `books.json` and all originals, including private files. The backup checks every blob's size and SHA-256 while holding a shared lock. Store this archive securely; it includes drafts. Thumbnails are regenerated, not backed up. The portable archive supports up to 32 MiB JSON plus the media quota. Larger legacy databases need an offline filesystem backup or subdivision; backup refuses to emit an archive that it cannot restore.
- CLI backup and restore (run as the service user or correct ownership after restoring):

```sh
php bin/media-backup.php backup /var/lib/cajeerbook /secure/backups/book.tar
php bin/media-backup.php restore /secure/backups/book.tar /var/lib/cajeerbook-restored
```

The backup target must be a new `.tar` file. Restore into a **new, non-existing directory**, with an existing parent directory, after stopping the target installation. Restore validates metadata, page keys, file sizes, MIME and SHA-256, and reconstructs media references; it writes a staging directory and only publishes it after validation. It does not extract arbitrary archive paths or overwrite existing data. Point `BOOK_DATA_DIR` to the restored directory (or move it into place with the service stopped), set the web user's ownership and private permissions, then start and verify. For Docker, use a trusted maintenance container with the volume mounted and an accessible backup path, or restore offline and replace the volume contents while the app is stopped. Never replace only `books.json` on a media-enabled installation. Content backups contain documentation and files, not identity records or deployment secrets such as `BOOK_ADMIN_HASH` or TLS certificates. Use the separate identity backup CLI for user records.

If a crash leaves an uncommitted blob, use `php bin/media-backup.php gc /var/lib/cajeerbook` to remove only orphan blobs/thumbnails and temporary media files under the same store lock; live originals are preserved. Run backups regularly. Files are not scanned for malware or replicated to another host.

## Development

```sh
composer install --prefer-dist --no-interaction
composer validate --strict
composer audit --locked
find src core data public tests bin -name '*.php' -print0 | xargs -0 -n1 php -l
php -l bootstrap.php
composer test
# Browser E2E (requires Python 3 and Chromium):
python -m pip install playwright==1.57.0
python -m playwright install chromium
python tests/editor-browser.py
python tests/media-browser.py
python tests/images-browser.py
python tests/page-tree-browser.py
```

Architecture: `src/` Book logic, `core/` reused upstream components, `templates/book/` CJR layout, `public/` sole web root, `data/` example content. Source is independent of the CMS release/update pipeline.

## Roadmap (not implemented in 0.1.6)

Callouts/cards/tabs/Mermaid, user/team RBAC and private books, revision history, Git sync, OpenAPI interactive reference, PostgreSQL/full-text indexing, locales, custom domains, SSO and production security/load certification.


## Callouts and cards (0.1.7)

In Studio Visual mode, select **Callout** or **Cards** in Add block. Edit the type, title, body and card properties. A card group supports 1–12 cards, 1–4 responsive columns, reorder/remove operations, predefined icons, optional links and an optional image (choose/upload via the existing media picker). Each body supports Visual text for ordinary paragraphs, headings, lists, links and inline marks, or Markdown for the complete CommonMark/GFM syntax. Use Refresh component preview or the global Preview to see the real server output. Unchanged blocks retain their original source.

The versioned Book extension uses reserved top-level CommonMark fenced blocks with inert JSON metadata and Markdown strings. No MDX, raw executable HTML, or CDN. Example (the `body` field is Markdown, with JSON newline escapes):

~~~~markdown
```book-callout
{"v":1,"type":"warning","title":"Before updating","body":"**Back up your data.**\n\n- Stop writes\n- Copy the data directory"}
```

```book-cards
{
  "v": 1,
  "columns": 2,
  "cards": [
    {"title":"Installation","body":"Read the **installation guide**.","href":"getting-started","icon":"book","image":"","alt":""},
    {"title":"API","body":"Explore the REST API.","href":"/docs/handbook/1.0/api","icon":"code","image":"","alt":""}
  ]
}
```
~~~~

Callout types: `note`, `info`, `tip`, `warning`, `danger`, `success`. Icons: empty, `book`, `code`, `link`, `download`, `rocket`, `check`, `info`. All properties are required in the version 1 JSON schema; empty optional strings are permitted. Card titles cannot be blank. Body size is limited to 64 KiB each, page size to 256 KiB, titles/alt to 512 UTF-8 bytes and URLs to 2048 bytes. A page expands up to 96 callout/card bodies; later components and malformed/unknown-version payloads display as ordinary protected code, not executable content. Ordinary fenced code is unchanged. Nested Markdown can contain tables, code, links, lists and images; nested Book component fences are intentionally rendered as ordinary code in this release. Reference definitions are local to each body; inner heading IDs are scoped to the component and excluded from the page TOC.

Card links use the same relative URL and moved-page link map as the rest of the document. Canonical `/media/<id>` links/images in cards and nested Markdown participate in existing reference tracking; private draft previews use authenticated media URLs, published snapshots use public URLs. To download an attachment use its canonical media link. Card images accept HTTP(S) or relative URLs, but not data/javascript or Studio URLs.

Upgrade from 0.1.6 by overlaying the changed files and restarting/rebuilding. No migration or new dependencies. Back up `BOOK_DATA_DIR` first. Old versions render these blocks as code; if rolling back after authoring components, keep a pre-upgrade backup because older media-reference logic does not recognize component references.

Tests: `composer test` includes `tests/rich-blocks.php`; `python tests/rich-blocks-browser.py` runs full Studio/Reader E2E with Composer dependencies and Playwright Chromium. `php tests/rich-blocks.php --policy-only` exercises the standalone validation and HTML generation without Composer.


## Tabs and interactive blocks (0.1.8)

In Studio, select Visual → Add block → **Tabs**, **Code tabs**, **Accordion** or **Steps**. Add, rename, reorder and remove individual items. Tabs have stable keys (lowercase ASCII letters, digits and hyphens) and an optional synchronization group. Groups with the same nonempty name select the matching key, even when tab ordering differs; groups without that key keep their current selection. State is local to the current Reader or page Preview, not persisted between visits. Component previews are isolated from each other. Arrow Left/Right, Home and End navigate tabs, and Tab enters the selected panel. Without JavaScript, all tab headings and content remain visible. Accordion works natively with or without JavaScript and supports an initially expanded flag per item. Steps are ordered automatically.

The existing versioned fenced JSON format keeps documents portable. Use ordinary Markdown in each body (including GFM tables, links, images and code). Code tabs store code as literal text and render it with escaped HTML, optional syntax highlighting and copy controls. Language labels accept ASCII letters, digits, `_`, `+`, `.`, `-`; unsupported languages are displayed without highlighting. Example:

````markdown
```book-tabs
{"v":1,"kind":"tabs","sync":"platform","items":[{"key":"linux","title":"Linux","body":"**Install** with your package manager."},{"key":"windows","title":"Windows","body":"Download the installer."}]}
```

```book-code-tabs
{"v":1,"kind":"code-tabs","sync":"platform","items":[{"key":"linux","title":"Bash","language":"bash","code":"docker compose up -d"},{"key":"windows","title":"PowerShell","language":"powershell","code":"Get-ChildItem"}]}
```

```book-accordion
{"v":1,"kind":"accordion","items":[{"title":"Troubleshooting","body":"Check the logs.","open":false}]}
```

```book-steps
{"v":1,"kind":"steps","items":[{"title":"Install","body":"Run Composer."},{"title":"Start","body":"Start the server."}]}
```
````

The `kind` must match the fence name (without `book-`). Each block has 1–12 items, titles up to 512 UTF-8 bytes, and each Markdown body or code field up to 64 KiB. Tab keys must be unique within a group; sync/key length is at most 64 characters. Page Markdown remains limited to 256 KiB. Nested rich components inside tabs, accordions and steps render to a maximum of three component levels with a shared 96-body page budget; malformed or excess components fall back to inert code. Existing callouts/cards retain the previous non-recursive body policy. Simple nested body text supports Visual text; complex content (including nested components) is edited in the body Markdown mode. The editor preserves untouched source; use Refresh component preview to see changes. Internal heading anchors are isolated per body, and links to hidden headings reveal the containing tab or accordion. Nested media references participate in the existing draft/public access and deletion protection. No database migration or new runtime package is needed. Back up `BOOK_DATA_DIR` before upgrading; older releases do not render or track references in the new blocks.

Tests: `composer test`, `python tests/interactive-browser.py --reader-only`, `python tests/interactive-browser.py` (requires Playwright Chromium and installed PHP dependencies for the full Studio test).

## Mermaid diagrams (0.1.9)

Docker builds install the official standalone Mermaid **11.17.2** bundle in a separate Node build stage; no Node or CDN is needed at runtime. For a non-Docker installation run `sh bin/install-mermaid.sh` (Node 22/npm and registry access required at installation time) from the project root, then run the usual Composer/PHP setup. The installer uses `npm pack` for the exact version, extracts the standalone distribution and its MIT license into `public/assets/mermaid/`, and does not execute npm lifecycle scripts or install transitive dependencies. Keep these generated assets in the deployed application; do not publish a partial installation.

In Studio choose **Add block → Mermaid diagram**, edit its source and view the automatically updated preview. Alternatively enter a standard fenced block in Markdown:

````markdown
```mermaid
flowchart LR
  Studio --> BookStore
  BookStore --> Reader
```
````

Supported declarations include flowchart/graph, sequenceDiagram, classDiagram, stateDiagram, erDiagram, gantt, and the additional types listed in `public/assets/diagram-frame.js`. Reader loads the local library only when a diagram is visible or explicitly rendered. Source is preserved, including on errors or when JS is disabled. Zoom, Fit, Expand/Full screen and PNG export are available. PNG export is bounded to 4096 pixels per side and 8 megapixels; diagrams that require HTML foreignObject labels may not export as PNG. No raw SVG is downloaded or inserted into the application DOM.

**Security:** each diagram renders in a separate opaque-origin iframe (`sandbox allow-scripts` without `allow-same-origin`). The frame has its own CSP blocking connections, remote images/fonts, forms and nested frames. Mermaid uses strict security, no HTML labels or bound callbacks; configuration directives and front matter are rejected. Only the actual frame window and a per-render random ticket are accepted in messages. Limit: 32 KiB source, 500 edges (Mermaid config), 6 active frames per page, 20 second response timeout. This is not a CPU-level isolation guarantee: exceptionally complex diagrams can still consume browser resources. The source remains available and additional diagrams can be opened after closing one.

Use the block grip to drag blocks before/after other blocks or the Move block up/down buttons; Alt+Shift+Up/Down moves the focused block. Moves keep original source bytes and add separators where needed; crossing protected Markdown or moving an unclosed fence is refused.

Upgrade from 0.1.8: back up `BOOK_DATA_DIR`, overlay the changed files and rebuild Docker (or install the local Mermaid asset with the shell command above). No schema migration. Test with `composer test` and `python tests/mermaid-browser.py` after installing Playwright Chromium and the pinned asset.
