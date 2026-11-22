# Changelog

## 1.0.5 — Working Workspace analytics and consent

- Persist and enforce GA4, Yandex Metrica and Webvisor settings independently for every Workspace, with server-side role checks, CSRF protection, optimistic revisions and an administrative management/report page.
- Collect anonymous public Reader page views, searches, link clicks, downloads and opt-in feedback through a same-origin, opt-in JSON endpoint. Aggregate in private, locked, atomically written per-workspace daily files, or a PostgreSQL upsert counter keyed by installation and workspace. Validate consent, Origin, event types and paths, disallow identifiers and query strings.
- Add an opt-in banner, per-workspace preference storage and withdrawal UI. Load external tags only after acceptance. GA4 uses Consent Mode v2 (ad signals denied) with manual page views; Metrica uses defer/hit/reachGoal with optional Webvisor.
- Add real database-wide schema migration 4 with guarded rollback, browser assets, regression tests, HTTP tests and deployment documentation. Existing PostgreSQL deployments must migrate offline; no mixed-version rolling upgrades. External providers and production infrastructure need live verification.

## 1.0.4 — Linked accounts, multiple OIDC providers, bounded roles

- Allow explicit, local-password-confirmed link/unlink of SkiF4er ID and other separately configured OIDC issuers from an existing non-owner account. Verify signed issuer, audience, nonce, state and PKCE before persisting an issuer/subject link. No automatic merges by email.
- Bind one-time link attempts to an existing server session, reject account/subject collisions, preserve local password login, and keep original owner and organization role boundaries. Multiple issuer-specific links are stored atomically in identity state and persisted in file/PostgreSQL modes without SQL schema change.
- Maintain issuer-scoped federated sessions, encrypted provider tokens, targeted back-channel logout and unlink-time session revocation. Reject provider assertions that would escalate existing local accounts to administrator or exceed their pre-existing membership.
- Add HTTPS HTTP integration covering two independent providers, browser/callback binding, linking, provider logout, unlinking, and cross-provider isolation; add file-store regression coverage and OIDC checks to the Composer test script.
- Schema 3 unchanged. Production readiness requires actual registered clients, TLS, PostgreSQL/Redis deployment testing and full release gates.

## 1.0.3 — SkiF4er ID sign-in and session revocation

- Add directly configurable confidential SkiF4er ID client with the pinned Keycloak issuer, exact HTTPS callback, Authorization Code + PKCE S256, nonce/state validation and RS256 identity verification; no PHP SDK is published by the provider, so Book uses its existing native PHP OIDC client.
- Provision authenticated Book user sessions from `iss` + `sub`, with explicit viewer onboarding or claim-based group access. No implicit elevated roles and no email-based account linking.
- Complete POST-only CSRF-protected browser logout with local session invalidation, best-effort OAuth access/refresh-token revocation and RP-initiated logout through the discovered provider endpoint. Encrypt short-lived provider tokens at rest in session state; exclude them from the user session list and backups.
- Handle signed back-channel logout by provider session (`sid`) or by subject (`sub`), while retaining the existing operator account disable/revoke controls.
- Add configuration guide, unit/regression tests and two-node HTTPS fixture coverage for callback, logout, token revocation and provider session invalidation. Existing identity storage schema is unchanged; live provider authentication needs operator-registered client credentials.

## 1.0.2 — Regression repairs (from supplied 1.0.0 source)

- Local login rate limiting now charges only failed credentials; valid logins on shared IP addresses no longer exhaust the 15-minute IP budget. Invalid attempts still enforce per-account and per-IP limits.
- Markdown import installs immutable media with verified staging and atomic no-overwrite publication, preventing concurrent blob replacement.
- PostgreSQL content transactions discard a failed PDO connection for the next request without replaying an ambiguous write.
- Recovery restores and audits into a private directory on the destination filesystem, then atomically publishes the verified result.
- TAR restore rejects any unexpected or missing members before extracting media.
- Regression tests cover these changes using available offline paths; PostgreSQL, Redis and Docker integration require their respective services.


## Embedded core synchronization — CajeerEngine 2.1.0 (Book 1.0.0 patch)

- Align all six embedded CajeerEngine runtime classes with the supplied upstream 2.1.0 source. Three files changed: the CJR template engine, expression evaluator and durable filesystem primitive.
- Apply 2.1.0 ancestor-symlink rejection to directory creation and file writes used throughout Book. Preserve Book routing, database schema, content storage and signed-update implementation.
- Pin the embedded core revision and SHA-256 checks during readiness; expose the distinct `engine_version` in the operator report and run offline runtime, template and filesystem regressions in CI.
- No import of CajeerEngine's full CMS, database, extensions, Admin Update Center or update operator. No Book version increment or database migration. See `docs/admin/engine-core.md`.

## 1.0.0 — Production Stable

- Require the full production qualification job explicitly in stable release evidence; sign all four qualification reports with the release manifest and checksums.
- Independently validate complete load windows, lifecycle, candidate image identities and source fingerprint at packaging and verification; reject mismatched signed deployment files and schema.
- Secure updater handles the versioned evidence format, preserving verification of 0.8.x–0.9.x packages and existing state.
- Confirm published GitHub release metadata after draft readback and digest promotion. Schema 3 unchanged. Official stable publication requires real protected CI, operator approval and the established release key; synthetic regression evidence is not production qualification.

## 0.9.1 — Release Candidate

- Authenticate signed update artifacts before ZIP parsing; share bounded archive validation with the release verifier, reject ambiguous/colliding names and unsupported compression, and bound version/schema reads.
- Reject malformed Origin authorities, invalid IPv6 and explicit HTTP/HTTPS mismatches; preserve the authenticated HTTPS domain ingress.
- Reject invalid OIDC JSON claim types, null security-sensitive claims/headers/JWK fields and unsupported JWT encoding extensions.
- Validate outbound TLS request headers before opening the socket, reject case-insensitive duplicates, retired 6to4 relay addresses and forbidden framing on informational/204 responses.
- Add independent regression scenarios to the security CI job. Schema remains 3. External auditor sign-off and production lab qualification are not claimed.

## 0.9.0 — Production Qualification

- Real historical 0.8.2-to-current OCI build, signed updater enrollment, upgrade/rollback/re-upgrade, two app/worker replicas, Redis restart, physical backup/WAL, deletion of source volumes and isolated recovery with public HTTP and deep integrity verification.
- Mandatory RPO/RTO measurement, exact published page/media fingerprints, pre/post-WAL marker and session-revocation verification; evidence bound to historical/current Git revisions and tested image identities.
- Production coordinator requires a complete 24h/100k SQL/Redis load report, all 1440 minute windows, per-operation SLOs, maintenance and dependency resources. Smoke is explicitly not qualification.
- Protected version tags require the full dedicated lab gate; candidate publication repeats lifecycle against exact candidate images and signs qualification evidence hashes. Existing gates remain mandatory. Schema 3 unchanged.
- Large corpus seeding refreshes the actual authentication session; resource sampling and disposable infrastructure cleanup fail closed.

## 0.8.2 — Administrator Documentation

- Current administrator handbook with deployment/owner initialization, topology, settings, maintenance, incident response, recovery and update procedures; indexes all existing specialist guides.
- New operator CLI `bin/admin.php report|check` performs real runtime, configuration, storage, identity, SQL binding/schema, workspace, Redis and key checks; optional deep integrity/credential/audit, OIDC discovery and monitoring. Redacted fixed remediation hints and nonzero health exit status.
- Executable documentation/reference checks and isolated administrator CLI runbook added to the existing release gate. PostgreSQL workflow exercised in dedicated disposable CI database. No SQL schema change (3).
- Historical guide version contexts clarified; no certification claimed without a successful infrastructure gate.

## 0.8.1 — Secure Update Manager

- Verified GitHub HTTPS asset downloads, pinned Ed25519 trust and Cosign verification; bounded ZIP extraction and immutable staged deployment trees.
- Local Docker Compose enrollment, image/volume inventory, high-water anti-downgrade state, durable transaction journal and exclusive lock.
- Offline backup and portable restore drill, native SQL dump, migration preflight/apply, candidate checks, service-count preservation and controlled rollback/recovery.
- Rollback-aware migration history validation and dynamic rollback target; no SQL schema change (version 3).
- Real signed-release and HTTPS tests, simulated fault-injection tests, and a separate real Docker/PostgreSQL/HTTP integration gate.


## 0.8.0 — Signed Release Pipeline

- Build and qualify immutable application and PostgreSQL/PITR OCI candidates after every mandatory enterprise/security gate passes; base images resolved by digest, actual SPDX SBOM and BuildKit provenance exported.
- Separate protected signing/publishing job; Ed25519 detached signatures for deterministic committed-source and digest-pinned deployment ZIPs plus complete signed release manifest, Cosign GitHub OIDC signatures for both OCI digests, verified draft readback and digest-preserving promotion.
- Offline verification CLI, exact version/repository/commit checks, signed scan/smoke evidence, negative cryptographic tests and administrator release runbook. Schema remains 3. No official release or registry signature is claimed until hosted pipeline succeeds.

## 0.7.2 — Disaster Recovery & PITR

- PostgreSQL 16 pgBackRest physical full/differential/incremental backups, encrypted repository, WAL archiving, named restore-point recovery and actual archive-get verification.
- Authenticated encrypted remote sidecars pair media, identity, documents and audit evidence with the WAL point under installation snapshot locks; retryable remote publication and private staging.
- Isolated promoted-cluster validation (system identifier, namespace, binding, replay LSN, timeline), content/audit comparison, session invalidation and atomic publication of a recovered data directory. No SQL reimport.
- Compose integration, cycle receipts and age health check, administrator runbook, policy tests and disposable source-loss PITR gate. Schema remains 3; physical qualification requires a successful PostgreSQL/pgBackRest run.

## 0.7.1 — Enterprise Security Lifecycle

- Private file-based Git/automation/backup keyrings, bounded retained keys, active-key verification and offline credential rewrap across registered workspaces without changing durable jobs.
- CI token rotation preserving scope/principal/publish permission; incoming webhook overlap with explicit retirement and stale-form protection; outgoing signing rotation preserves delivery identity.
- Owner/operator session revocation, OIDC secret files, optional signed subject-wide back-channel logout and replay-resistant logout watermarks.
- External Ed25519 audit checkpoints verify retained PostgreSQL chain continuity against independently pinned public keys.
- Two-node HTTP and HTTPS OIDC regressions, old encrypted backup restoration after key rotation, PostgreSQL lifecycle gate. Schema remains 3; mixed-version rollback and independent infrastructure qualification require operator attention.

## 0.7.0 — Security Audit & Hardening

- Shared pinned HTTPS transport with monotonic deadlines, strict framing/trailer validation and bounded responses for Git/OIDC/Playground/webhooks; retain explicit operator-trusted private GitLab/OIDC origins.
- Reject ambiguous request targets for all deployments, supplement CSRF with browser-origin checks, tighten JWT audience/JWK usage and sensitive referrer policy, preserve machine authentication.
- Bound upload staging and rollback partial ZIP imports; production Docker target excludes test fixtures; Apache TRACE and disclosure hardening.
- Real HTTP/TLS regression tests and mandatory dependency/source/image security scanning in CI. Schema remains 3; independent audit and infrastructure qualification are not claimed.

## 0.6.2 — Load & Stress Qualification

- Real timed open-loop arrival scheduling, bounded in-flight work, counted capacity/scheduler drops, per-operation latency/error SLOs and minute-window soak checks. Stress safety and stress SLO are reported separately; strict recovery is required.
- Full mixed Reader/search/Studio/API/media/privacy workload, actual post-load integrity checks, optional concurrent worker scans and portable backup/restore, and sampled PHP/PostgreSQL/Redis resource limits.
- Redis keyspace counters, public-read availability and p99 alerts, CI smoke and isolated 24-hour PostgreSQL/Redis test workflow. Actual local file-mode reports preserve saturation findings. Schema remains 3; full SQL/Redis 24h qualification is not yet verified.

## 0.6.1 — Distributed Reliability

- Optional Redis Sentinel majority discovery with separate credentials, TLS peer verification, primary role validation, monotonic reconnect backoff and no automatic replay of ambiguous Redis commands. SQL queue reconciliation and fail-closed rate limiting retained.
- Worker SIGTERM/SIGINT drain, bounded rotating scope batches, jittered retry, database clock and connection fencing for claims/completions; expired leases cannot finish. Stable delivery IDs preserve at-least-once delivery semantics.
- Nginx/Caddy bounded failover for explicitly read-only routes; no replay of Studio writes or OIDC callbacks. PostgreSQL runtime refuses read-only/standby sessions.
- SHA-256 authenticated media streaming before headers, checked preview cache, exact-byte repair, cross-node volume/lock probes and external shared POSIX storage overlay; media integrity metrics and alert.
- Real Nginx/PHP replica kill, corruption/repair and proxy replay tests, worker SIGTERM/SIGKILL tests, mandatory Redis Sentinel promotion/quorum-loss Docker gate, PostgreSQL worker crash/read-only tests and Caddy replica fault injection. Schema remains 3; full infrastructure qualification remains required.

## 0.6.0 — PostgreSQL Performance Stable

- Indexed point reads for published REST pages and stable-ID redirects; compact Reader navigation and list projections, with draft/history/CI bodies excluded. SQL filters workspace and book visibility before fetching rows.
- Published media reference lookup through GIN, with immutable file opening under the content scope lock. Anonymous workspace/legacy/domain routing reads only selected identity registry records under the shared identity lock.
- Bounded multi-row content/search writes and batched deletes preserve ordering, JSON snapshots and transactional search/audit behavior.
- Database schema 3: reversible published path, stable ID and media indexes; exact JSON retained for unusual escaped NUL values. Existing PostgreSQL installations must apply offline migration 0003.
- Actual optimized read query EXPLAIN ANALYZE/BUFFERS in benchmark output and regression coverage of privacy, redirects, media, language, batching, upgrade and rollback. PostgreSQL infrastructure qualification and large-load SLO remain required; existing quotas are unchanged.

## 0.5.4 — Performance Benchmarking

- Real quota-bounded synthetic corpora through identity/content/media transactions, linked translations, versions, draft/publication controls and history; 100k public pages across 100 workspaces.
- Concurrent verified HTTP varied/repeated-key workloads, per-operation latency/throughput/error evidence, PHP process resources, telemetry deltas and compatible-baseline regression gate.
- Full PostgreSQL/Redis workload, exact Search EXPLAIN ANALYZE/BUFFERS plans, SQL stats, real Studio writes, CI batch publishing and optional maintenance timings; dedicated disposable database guard and release-gate integration.
- Actual file-mode baseline reports for 1k/10k/50k/100k and a failed 100-client stress report; SQL infrastructure qualification remains separate. No schema change from 0.5.3.

## 0.5.3 — Observability & Diagnostics

- Bounded shared-volume HTTP and operation histograms, counters, structured request/job logs and request correlation. No raw credentials, SQL text or user-controlled labels in telemetry.
- Independent authenticated exporter and CLI for storage, PostgreSQL, identity, search, audit, queues, Redis, worker, DNS, backup RPO and primary TLS diagnostics. Existing public health contracts unchanged.
- Prometheus, Grafana dashboard, Alertmanager webhook notifications and operational alert rules with log rotation.
- Multi-process HTTP telemetry and real recovery regressions; monitoring stack, alert delivery and Redis failure/recovery integration tests in release gate.
- No schema change from 0.5.2.

## 0.5.2 — Database Migration Engine

- Database-wide versioned PostgreSQL SQL migrations, exact ordered SQL checksums, current ledger and durable apply/rollback history.
- Strict 0.5.1 baseline adoption using a transactional reference schema and PostgreSQL catalog verification, without importing stale file data or modifying existing content.
- Actual audit outcome cursor index, transactional upgrade and index-only safe rollback; irreversible baseline cannot be removed.
- CLI status/plan/preflight/apply/verify/rollback, full transactional dry-run, binding checks, migration credentials, bounded lock/statement timeouts.
- Shared schema guards in PostgreSQL runtime connections; offline exclusive schema changes fail while current runtime sessions are active. Search repair and audit initialization no longer reapply historical schema DDL.
- Fresh import and recovery drill use the same migration engine. PostgreSQL installations must run `bin/migrations.php apply --offline` before 0.5.2 starts; file-only mode is unchanged.
- Dedicated PostgreSQL migration integration gate covering fresh and populated schema, drift, rollback, concurrency, injected transaction failure and actual data/identity/audit preservation.

## 0.5.1 — Release Gate Hardening

- Fail closed on invalid enterprise security configuration on actual application routes; readiness retains its JSON contract.
- One local/CI release runner executes the full native, PostgreSQL/Redis, Docker/Apache, distributed and DNS/TLS/recovery gates with per-step logs, JSON and JUnit evidence. Missing tools, test failures and timeouts cannot pass.
- Isolated test namespaces, disposable databases/Redis containers, distinct non-superuser source and drill roles, random Compose projects, explicit Redis restart readiness and cleanup.
- Upgrade regression fixture generated by the original 0.5.0 code, covering existing users, private content, published/draft snapshots and media, new writes and full backup/restore in dependency-backed gates.
- No database schema change from 0.5.0. Production qualification requires a successful full enterprise-release run.

## 0.5.0 — Enterprise Documentation Stable

- Concurrent anonymous PostgreSQL Reader and domain resolution using shared identity locks; authorization and visibility mutations retain exclusive locks.
- Readiness rejects incompatible Redis, HTTPS and required OIDC settings. Docker build context excludes local secrets and backups.
- Enterprise Compose overlay aligns backup source identity with the application, requires remote storage and a separate SQL restore drill; backup health measures verified capture age without blocking the scheduler.
- Restore drill rejects the source installation even through alternate DSNs.
- Integrated TLS/DNS, publication/search, audit, scheduled backup and remote-only SQL recovery gate; real concurrent HTTP benchmark and mandatory aggregate CI gate.

## 0.4.9 — Automated Backup & Recovery

- Installation-wide consistent portable snapshots: identity lock and shared content-scope locks, reused SQL read connection, verified media and retained audit history.
- Streaming authenticated encrypted recovery envelopes (XChaCha20-Poly1305), persistent scheduler, exclusive lock, interval/retry state, verified local retention.
- Optional S3-compatible HTTPS repository via AWS CLI or independent mounted filesystem repository; read-back SHA-256 and actual isolated restore before marking a backup successful.
- Offline recovery CLI, remote-only restore, optional isolated PostgreSQL migration/search/audit drill, backup audit outcomes and separate Compose scheduler service.
- Recovery tests for corruption, wrong keys, remote failure/retry, concurrency, retention and recovery; PostgreSQL and real HTTPS S3 gates in CI.

## 0.4.8 — Enterprise Audit Log

- PostgreSQL audit streams with transactional identity/content event capture before ActivityJournal trimming, allowlisted metadata, request correlation, SHA-256 chains and SQL immutability checks.
- Main-host Audit Explorer, live organization/workspace authorization, indexed filters, cursor pagination, CSRF-protected CSV/JSONL export and export events.
- Offline migration, chain verification, verified prefix archival and retention, portable historical snapshots and backup integration. Existing file-mode installs retain ActivityJournal.
- Local policy tests and real PostgreSQL integration gate for isolation, rollback, retention and durable history.

## 0.4.7 — Custom Domains

- Transactional workspace domain registration, exact DNS TXT ownership proof, renewable seven-day leases, daily recheck, global hostname uniqueness, canonical primary/aliases, disable/rotate/remove and activity events. Restore rotates ownership challenges and clears verified state.
- Main-host-only control plane, strict Host routing before auth and CI, anonymous public Reader/API/search/media on custom hosts, live visibility checks, secure host-only authentication cookies and ingress-authenticated HTTPS. Unknown, inactive and expired hosts fail closed; no cross-workspace URL or cookie bypass.
- Production Caddy ACME ingress, on-demand issuance permission gate, SNI/Host enforcement, HTTPS redirects, shared TLS volumes, dynamic load balancing and automated DNS checker. Canonical/hreflang, sitemap/robots and optional public static source-origin metadata.
- Domain policy, HTTP, PostgreSQL and disposable Docker/Caddy TLS/DNS integration release gates.

## 0.4.6 — Multilingual Documentation

- English/Russian document locales, independent translation drafts and published snapshots, stable source identity, exact source-change tracking, duplicate-language validation, retagging standalone originals, history/review/CI preservation and version-copy remapping.
- Translation Studio, persisted interface locale, localized core Reader/editor/auth UI, language-filtered navigation, canonical and alternate links, and authorized language aliases with explicit fallback.
- PostgreSQL locale-filtered GIN search and additive reindex migration, portable search and API locale metadata, offline search filter and language links in Static Export. Markdown/Git front matter locale support; imported edits retain existing groups.
- Storage and HTTP tests for independent publication, source changes, rename/copy, privacy and language routing, with full renderer/ZIP and PostgreSQL integration in CI.

## 0.4.5 — Enterprise SSO / OIDC

- Corporate OIDC sign-in: pinned HTTPS issuer discovery, authorization code + PKCE S256, browser-bound one-time state and nonce, RS256 JWKS signature and issuer/audience/time validation. No provider tokens stored.
- Transactional JIT identities keyed by issuer and subject, explicit viewer/editor group mapping, organization membership synchronization, preserved workspace restrictions, and local account disable/revocation. No email-based account linking or local password bypass for federated identities.
- Optional installation-wide SSO enforcement with local emergency owner access; short-lived federated sessions and policy-change invalidation. Deployment-controlled provider configuration, owner status view and CLI discovery check.
- Shared authorization flow state and sessions use existing PostgreSQL identity transactions; portable backups preserve identity links but exclude temporary flows. Redis ingress limits and query-free access logging for callback confidentiality. Signed-token tests and two-node HTTP/HTTPS provider integration gate, including PostgreSQL CI.

## 0.4.4 — Redis & Distributed Runtime

- Optional authenticated Redis 7 runtime for PostgreSQL deployments: HMAC-bound, installation-scoped caches of public Markdown renders and search responses, revision-based invalidation and bounded TTL; identity and private content remain SQL-authoritative.
- Atomic Lua ingress rate limits shared by web nodes for authentication, Reader search, API, CI and webhooks; existing durable SQL authentication limits remain enforced. Redis outage falls back to authoritative rendering and SQL authentication, rejects rate-limited API/CI/webhook/search requests and marks readiness unavailable.
- PostgreSQL per-workspace advisory worker locks, durable SQL queue reconciliation, existing fenced leases/retry/dead-letter; Redis Streams consumer-group wakeups emitted after commit, with no job loss on stream deletion or Redis restart.
- `bin/runtime.php check|status`, runtime metrics, Redis Compose overlay, two-or-more-node Compose deployment behind a trusted Nginx proxy and shared media volume. Real Redis/PostgreSQL, multi-node HTTP and Compose release gates.

## 0.4.3 — Full-Text Search

- Transactionally maintained PostgreSQL published-document index with weighted GIN vectors, English/Russian stemming, phrase/prefix matching, relevance, safe snippets and indexed `pg_trgm` title/path typo fallback.
- Reader/API book/version filters and pagination, access-scoped totals, admin Search Inspector and offline reindex/check CLI. Existing file deployments retain bounded lexical search; Static Export stays independent.
- Atomic index upgrade/rebuild for all registered scopes and readiness checks; SQL and HTTP regression scenarios for isolation, ranking, publication, visibility, rollback and recovery.

## 0.4.2 — PostgreSQL Identity & Migration

- SQL-authoritative users, sessions, reset digests, rate limits, organizations, invitations, workspaces, memberships, organization roles, workspace overrides and identity activity; existing login and permission flows use the new backend. Installation-scoped constraints and immutable owner binding preserve isolation.
- Installation row locking serializes authorization, role changes and identity mutations. New workspace registry and content scope commit together; SQL failures leave neither orphan scope nor filesystem directory.
- Offline upgrade of existing 0.4.1 PostgreSQL installations without rewriting content; direct migration of file installations transfers identity schemas 1–4 and content schemas 1–6 in one transaction with exact round-trip checks. Existing credentials and IDs survive migration; portable restore invalidates transient credentials. No stale file fallback.
- PostgreSQL-aware full backup/restore, readiness and existing operator CLI. New regression/real SQL integration tests cover upgrade, RBAC, session revocation, failure rollback, concurrency and restore; HTTP CI now reads current SQL identity.

## 0.4.1 — PostgreSQL Content Storage

- Optional PostgreSQL 16 content backend used by the existing Reader, Studio, API, review, CI, Git/OpenAPI, automation, media and Static Export workflows. Page records, book registry, media metadata, history, reviews, CI builds/tokens, activity and automation jobs are independently stored in scoped relational rows; JSON values preserve exact snapshot ordering and digest compatibility.
- Transactional shared/exclusive workspace row locks, incremental record writes, rollback, optimistic revision checks and installation binding. No fallback to stale JSON after migration or an incomplete cutover; database outages fail closed.
- Offline all-workspace migration of schemas 1–6, including archived workspaces, with full legacy audit, media hash verification and exact round-trip before switching. Existing media bytes and identity schema 4 remain on disk; portable backup/restore includes PostgreSQL content.
- Optional Compose PostgreSQL deployment with non-superuser application role, persistent database volume and PDO PostgreSQL driver. Real PostgreSQL migration/concurrency/backup tests and full HTTP CI import/review/publish/export gate; legacy mode remains supported.

## 0.4.0 — Developer Documentation Stable

- Connect CI publication to public static delivery: `ci.py export ID DIGEST NEW.zip` validates a retained published bundle against the current public snapshots and media under the export lock, with explicit release metadata in the archive.
- Enforce scoped publish credentials, workspace/book visibility, current issuer authorization and exact build digest. Reject stale publications rather than exporting an unrelated snapshot; release the store and identity locks before HTTP streaming.
- Verify the download length, SHA-256, ZIP entry safety and release identity before atomic no-overwrite installation; no partial artifacts on transfer errors. Add opt-in public static artifacts to example pipelines.
- Readiness checks all required browser assets, including static export; handle malformed request targets and UTF-8 search input safely. CI bearer errors advertise authentication and vary by authorization.
- Extend real PHP/HTTP/Chromium and Docker/Apache gates through CI publication to artifact delivery; add independent CLI transport/integrity failure tests. No schema migration.

## 0.3.9 — Static Export

- Export published books and versions as portable ZIP HTML sites, including tree navigation, version switching, heading anchors, redirects and rich Markdown blocks.
- Local offline full-text search, verified referenced media, pinned sandboxed Mermaid rendering and no CDN or PHP requirement.
- Public-only by default; explicit private export confirmation, Owner/Admin authorization and CSRF. CLI supports scoped builds.
- Atomic archive publication, resource limits, no drafts, history, credentials or unreferenced media.

## 0.3.8 — CI/CD Publishing

- Scoped expiring hashed CI tokens, bearer API and Python CLI; protected GitHub Actions/GitLab CI examples.
- Checked immutable whole-version bundles, exact Git source matching, human independent approval, stale detection and atomic idempotent publication.
- Studio build history, exact preview/diff, cancellation/pruning, restore published release to drafts, transactional build events and media retention.
- Schema 6, backup/audit compatibility, PHP and HTTP integration tests.

## 0.3.7 — Webhooks & Automation

- Verify GitHub HMAC and GitLab secret-token webhooks, repository/project and branch, push and merged PR/MR; deduplicate raw bodies and delivery IDs.
- Transactional inbox/outbox, signed HTTPS metadata delivery, persistent queue, exponential retries, dead-letter/manual retry, fenced leases and crash recovery.
- Worker imports Git drafts through existing sync, reauthorizes and commits completion atomically; no publication bypass.
- Admin Automation Studio, Team activity, encrypted secrets, outbound origins, pinned TLS, optional Compose worker and schema 5 backup compatibility.
- PHP, HTTP and actual TLS integration tests; no new runtime dependencies.

## 0.3.6 — SDK & Code Examples

- Add Reader SDK & Code Examples for published OpenAPI operations: cURL, TypeScript/JavaScript, Python and PHP with selectable server/auth alternative and placeholder credentials.
- Generate downloadable versioned SDK ZIPs with real HTTP client methods, TypeScript source/types and ready-to-run JS, Python package and PHP Composer library; include published operation metadata, README and examples. No external generator, runtime package install or network call on the Book server.
- Build only from currently visible published snapshots in one reference; publication digest binds operation definitions and runtime version, stale downloads reject, no source bundles/drafts/secrets or cross-workspace data. No schema migration or persisted artifacts.
- Exercise generated clients over real TLS, parameter/auth/body serialization, response limits, redirect rejection, TypeScript compilation, local npm and Python package installation, and HTTP access/revocation.

## 0.3.5 — API Playground

- Add interactive requests from published OpenAPI operations: HTTPS server selection, scalar parameters, JSON/form/text bodies, Bearer/Basic/API Key, real browser CORS fetch and optional authenticated server proxy. Display status, headers, duration, text/JSON or binary Base64; download/copy response and generate redacted cURL.
- Bind executable definitions to reviewed page snapshots, retain in history and backup, resolve old publications only when their retained source is unambiguous. No draft specification can change a published target.
- Explicit operator origin allowlists (disabled by default), pinned public IPv4 TLS proxy, no redirects, bounded time/response, CSRF, rate limits and reauthorization after network execution. Never persist request credentials or responses.
- Add storage, HTTP, actual TLS wire and browser integration tests; no new runtime dependencies or schema migration.

## 0.3.4 — OpenAPI Reference

- Import OpenAPI 3.0/3.1 JSON and YAML from pasted source, uploaded files, directory, ZIP or connected GitHub/GitLab at a verified immutable commit. Bounded local multi-file JSON Pointer resolution, recursive schema handling and no remote reference fetching.
- Generate ordinary draft pages for overview, operations and schemas with stable URLs, parameters, security, request/response examples and model properties; use existing Reader, search, Editor, RBAC, privacy, review and history.
- Signed preview, diff, atomic update and retirement drafts; reject manual-edit/path conflicts, retain published snapshots and stable IDs. Version normalized sources with actor, checksum and commit; download or restore retained revisions.
- Store schema 4 only on OpenAPI import; compatible with Git/Review, backup and audit. Add Symfony YAML 7.4.23, readiness check and PHP/HTTP acceptance tests in CI.

## 0.3.3 — GitLab Sync

- Unified Git Sync in Studio (`/studio/git`, legacy `/studio/github` retained), with GitHub and GitLab provider selection and the same transactional import, conflicts, rename, history, media and review workflow.
- GitLab.com and explicitly enabled self-managed HTTPS origins; nested project paths, stable numeric project identity, immutable commit tree pagination and verified raw blobs with incremental cache.
- GitLab commit batches and Merge Requests on new branches, exact parent and create/update checks, explicit draft disclosure, no direct base writes or automatic merge.
- AES-256-GCM credentials bound to GitLab origin, project and connection; existing GitHub encrypted connections unchanged. GitLab-enabled stores use schema 3, compatible with review and TAR restore, to prevent unsafe downgrade.
- Real PHP/RBAC integration tests, local TLS transport tests and opt-in live GitLab read acceptance.

## 0.3.2 — GitHub Sync

- Add scoped Studio connection to public/private GitHub repositories using branch, documentation directory, book/version, optional encrypted token and explicit PR permission. Connect/rotate/disconnect are Admin/Owner operations.
- Fetch immutable Git commits/trees/blobs over the fixed HTTPS GitHub API; verify sizes and Git SHA-1, bound downloads, reject unsafe paths, symlinks/submodules and truncated trees, and reuse verified workspace-private cached blobs. Release the identity lock during read-only network work and reauthorize before showing/applying the plan.
- Track source path, stable page ID, Git blob and normalized imported base; classify local/remote edits, explicit per-source conflict resolution and removed sources. Preserve exact unambiguous leaf renames and existing publications. Signed plans and atomic MarkdownImport commits update the cursor and drafts together, respecting history, review, media and privacy.
- Propose changed tracked Markdown (optional new local pages) through a new Git branch and Pull Request, with exact base commit, reverse link mapping, explicit confirmation and workspace state check. No direct push to the base branch. Add Git run history and Team Studio activity, backup validation and controlled REST/PHP/HTTP tests.

## 0.3.1 — Markdown Import

- Add scoped Studio import of Markdown files, browser directories and ZIP archives, including safe scalar front matter, README/index landing pages, SUMMARY navigation order and source-to-page mapping.
- Preview Create/Update/Skip/Rename/Unchanged actions and unresolved links; sign confirmation to exact file hashes, session, workspace and current store. Apply drafts and media atomically with existing history, review provenance and activity, leaving published snapshots untouched.
- Rewrite local document and media links, reference definitions and supported rich blocks while preserving protected code/HTML/Mermaid source. Validate real media MIME, deduplicate within the workspace and preserve canonical URLs.
- Bound ZIP extraction and reject unsafe paths, duplicate entries, symlinks, encrypted entries, invalid checksums and oversized files. Add PHP ext-zip to Composer/Docker/readiness, update upload limits, and add PHP, multipart HTTP and Chromium regression tests.

## 0.3.0 — Workspaces & Teams Stable

- Bind initialized installations to a durable private root identity anchor. Fail closed after identity loss or owner mismatch, including legacy Reader/API/media; regenerate the anchor on trusted full restore. Reject orphan workspace directories during readiness.
- Persist identity/session updates before scoped content dispatch while retaining the authorization lock; check all registered stores under a consistent identity lock. Return generic no-store/noindex errors without appending diagnostics to streamed responses.
- Add integrated multi-user installation backup/restore and recovery tests covering all four roles, private documents, exact approved publication, review notifications, rich Markdown/Mermaid and historical draft recovery. Add a real browser scenario and a disposable Apache/Compose restart gate; correct the Reader cache-header regression assertion.
- Document upgrade, identity-loss recovery, team acceptance and release checks. Keep Identity schema 4 and BookStore schemas 1/2; no new runtime dependencies.

## 0.2.9 — Security & Migration

- Add operator-only full installation check, backup, staged restore and copy-based migration; verify registry, component hashes, permissions and all content stores before atomic directory publication. Preserve archived spaces, history, reviews and journals; revoke restored sessions and one-time credentials.
- Fail closed on missing adopted legacy metadata and orphan media; validate persisted page/public paths and snapshots before projection. Materialize legacy seed at adoption; add explicit seed-only recovery for older installations.
- Preserve known source contributors on draft duplication and historical restoration; validate historical provenance and reject cross-organization journal scope mismatches.
- Add full installation PHP/HTTP integration tests, extend approval regressions and Docker release gate. No new runtime dependencies.

## 0.2.8 — Team Studio & Activity

- Add global Team Studio workspace selector and scoped dashboard with personal review tasks, draft/notification counts, effective members, recent document filters, history links and author/publication state in Studio navigation.
- Persist invitation, membership, role and review notifications with independent read receipts, CSRF-protected read actions and live RBAC filtering. Add scoped activity and administrative audit for successful identity, organization, workspace, content, media and review operations; record no credentials or document/comment bodies.
- Commit journal events atomically with their state changes, bound each journal to 1,000 events/512 KiB within existing store limits, validate and preserve events in identity/content backups. No new schema migration or runtime dependencies.
- Add explicit local Markdown download and separate current-version link for editor conflict/access recovery. Add PHP, multi-user HTTP, Chromium team and recovery regressions to CI.

## 0.2.7 — Review & Approval

- Add scoped review queue, immutable saved-page and navigation snapshots, independent assigned reviewers, escaped discussion, request changes/reject/cancel, approval and atomic publication of exactly the approved state. Track known unpublished contributors and link publication history to review IDs.
- Add Owner-only workspace review policy; required mode blocks direct page and tree publication under the content lock. Recheck reviewer permissions under the identity lock, reject stale request revisions and automatically invalidate requests when their saved state or policy changes.
- Retain review media without granting public access, validate review state and snapshots on restore/audit, include reviews in existing scoped backups, enforce limits and allow Owner to prune closed requests. Schema 1 remains readable; first review or policy change upgrades that content store to schema 2.
- Add PHP, real multi-user HTTP and Chromium review tests; fix shared HTTP test helper collision with page path form fields. No new runtime dependencies.

## 0.2.6 — Revision History

- Record immutable, attributed draft/publication snapshots atomically with page saves, publication, tree changes, copies, unpublishing, deletion and restoration. Preserve stable IDs and initial legacy snapshots; skip identical autosaves, support optional revision notes.
- Add scoped Studio history, filtered page listing, arbitrary historical snapshot comparison, escaped line diff, historical Markdown preview and restore-as-draft (including deleted pages). Restore validates current revision, history head, media references and RBAC; it never publishes.
- Retain historical media without granting public access, include history in JSON/TAR backups, validate snapshots on restore and audit, and bound history by page, workspace and portable store limits. Add PHP and multi-user HTTP tests. No new runtime dependencies.

## 0.2.5 — Private Documentation

- Add workspace Public/Private visibility and book Inherit/Public/Private controls for Owner/Admin, with private-workspace ceiling and optimistic revisions. Existing scoped spaces stay private; legacy stays public by default. Identity schema 4 preserves visibility in backups and migrates older schemas under the existing lock.
- Apply one published-content visibility projection to catalogs, navigation, Reader, search, API, and redirects (including legacy paths and archived organizations). Public Reader access never grants Studio privileges. Restrict media originals, downloads and thumbnails to references in accessible published snapshots; keep draft-only media protected by Studio RBAC.
- Add private/no-store and cookie-varying Reader responses, noindex for protected/denied responses, visibility labels and confirmation controls. Preserve canonical Markdown and existing content stores. Add PHP, real multi-user HTTP and Chromium privacy regressions; no new runtime dependencies.

## 0.2.4 — Roles & Permissions

- Implement Owner/Admin/Editor/Viewer with an organization role ceiling and explicit workspace restrictions, including deny and inherited access. Existing Member accounts become Viewer; new invitations default to Viewer.
- Add server-rendered role and scope management, transactional authorization, revision checks, and no self-promotion or administrator escalation. Preserve restrictions through role changes; remove grants on membership removal and ownership transfer.
- Enforce a deny-by-default Studio operation policy for page edits, publication, tree, media, exports and adopted legacy content; update controls and AJAX page actions to reflect permissions.
- Migrate identity schema to 3, preserve role assignments in backups, support older backup schemas, and add PHP concurrency, HTTP and Chromium multi-user tests. No new runtime dependencies.

## 0.2.3 — Workspaces

- Add real organization workspace management, stable IDs, scoped book registry, empty independent stores, archive/restore and explicit legacy adoption preserving existing URLs.
- Scope Studio, Reader, API/search, page tree, media, editor AJAX and backups; require current organization membership and owner authorization for writes, with identity-before-content locking. Prevent organization deletion while spaces exist and fail closed when a registered store is missing.
- Add per-workspace TAR backup/restore CLI, registry backup compatibility, readiness checks, canonical Markdown URL resolution and multi-user storage/HTTP/Chromium regression tests. No new runtime dependencies.

## 0.2.2 — Organizations

- Add persistent organizations with stable IDs, unique case-insensitive slugs, member lists, owner-only settings, transfer of ownership, archive/restore and confirmed deletion. Organization membership is independent of installation-owner privileges; organization owners cannot be disabled until ownership is transferred or the organization is deleted.
- Add CSRF-protected, expiring, single-use username-bound invitations for existing and new accounts, hashed tokens, replacement/revocation, copyable fragment links and manual token entry. Accepting an invitation and creating a new account commit in the same identity transaction.
- Migrate identity schema 1 to 2 under the existing lock; keep existing accounts, sessions and content unchanged. Include organizations/memberships in private identity backups, exclude active invitations and sessions, and support restoring schema-1 backups.
- Enforce organization access and revisions server-side; add real storage/concurrency, HTTP and browser tests to CI. Content remains installation-scoped until Workspaces and RBAC; no new runtime dependencies.

## 0.2.1 — Identity & Authentication

- Replace Studio Basic Auth with individual username/password accounts, server-side hashed session tokens, CSRF-protected sign-in/sign-out and account pages. Installation owner alone can access Studio and manage users; ordinary accounts have personal profiles only until organizational authorization arrives.
- Support trusted CLI first-owner initialization, or migrate the previous `admin` account on a successful form login using `BOOK_ADMIN_HASH` only before initialization. Once initialized the environment hash is ignored. No changes to documentation data or URLs.
- Persist private versioned identity state under `BOOK_DATA_DIR/identity`, with atomic writes, account/IP rate limits, idle and absolute session expiry, per-account session limits, revocation and password rotation.
- Add one-time operator-issued recovery tokens (30 minutes), password reset, separate user backup/restore without sessions or recovery tokens, and readiness checks. Preserve content-only TAR and JSON exports without credential hashes.
- Stop Studio autosave on expired/denied sessions while retaining local Markdown. Convert existing integration tests to cookie login; add identity storage, HTTP and browser tests.
- No new runtime dependencies; content schema remains 1.

## 0.2.0 — Advanced Editor Stable

- Harden private data directory resolution (including missing descendants, symlink aliases and parent traversal), CLI backup/restore destinations and Apache document-root symlink policy.
- Make readiness validate the store, write a durable probe and require the installed PHP and pinned Mermaid runtime; add Docker health checks. Reject unsupported schemas and enforce the portable 32 MiB metadata limit before atomic replacement, allowing size-reducing legacy writes.
- Correct media references with query/fragment suffixes in Reader and Studio, with a read-only consistency audit and explicit transactional reference reindex for old stores. Preserve draft/public isolation and revision checks.
- Add Studio request deadlines and recovery without losing source, strict destructive-action revisions, no-store dynamic responses and correct conflict status codes.
- Add mixed-document AST/storage/backup integration, real HTTP release gate, timeout browser tests and a Docker Compose CI gate including persistence after restart. Preserve all existing editor, media, tree, rich-block and Mermaid features.
- Keep schema 1, the existing Composer lock and Mermaid 11.17.2; no new runtime dependencies.

## 0.1.9 — Mermaid & Editor Polish

- Render standard Mermaid fences in sandboxed opaque-origin frames using a pinned, locally installed Mermaid 11.17.2 browser distribution. Lazy rendering, strict configuration, source fallback, theme redraw, zoom, expansion/full screen and bounded PNG export.
- Add a Mermaid block in Studio with debounced live preview and lossless Markdown storage. Add block drag handles, move controls and keyboard shortcuts; preserve protected source and code fence boundaries.
- Add local asset installation, Docker build stage, PHP checks and full Chromium E2E in CI. No storage migration.

## 0.1.8 — Tabs & Interactive Blocks

- Add Tabs, Code Tabs, native Accordion and ordered Steps with structured Studio editing, item creation, reordering, removal and shared Markdown preview.
- Enhance Reader and Preview with accessible tabs, keyboard navigation, synchronized tab keys, readable no-JavaScript fallback and automatic disclosure of hidden anchor targets. Code tabs render inert escaped code with local syntax highlighting and copy controls.
- Support bounded nested components inside tabs, accordions and steps; share the expansion budget and track nested media references through publication. Preserve existing callout/card nesting policy and source for unsupported blocks.
- Add PHP validation, CommonMark/storage integration and Chromium Reader/Studio tests. No new runtime dependencies or schema migration.

## 0.1.7 — Callouts & Cards

- Add six callout variants and responsive card groups, with local images, links, icons and nested CommonMark/GFM bodies.
- Edit component properties, simple visual text and complete Markdown in Studio; add, reorder and remove cards and preview through the shared renderer. Preserve untouched source and protect malformed or unsupported components.
- Resolve card and nested body URLs using the existing Markdown policy, keep draft media private and include rich component references in publication/deletion checks. Bound component expansion and isolate nested heading anchors.
- Add PHP policy/renderer/storage tests and browser E2E. No new runtime dependencies or storage migration.

## 0.1.6 — Page Tree & Drag-and-Drop

- Add a nested Studio page tree with branch drag-and-drop, keyboard/touch form alternative, before/after/inside/root operations, slug renaming, child creation and draft-only branch duplication.
- Persist stable page identities, transactional draft paths and independent published paths/order. Validate cycles, collisions, scope, limits and a revision token under the existing BookStore lock.
- Publish navigation independently from page content, preserve old public URLs with identity-based 308 redirects, reserve retired paths, and retain relative Markdown link context after moves. Reader navigation, pagination, search, API and version copying use the published hierarchy.
- Extend backup/restore validation for stable IDs, paths and redirect history. No new runtime dependencies or destructive migration.
- Add storage, HTTP, CommonMark/backup integration and Chromium drag/drop tests.

## 0.1.5 — Images & Attachments

- Add an authenticated searchable, paginated media picker in Studio with existing-file selection and direct uploads, clipboard images, drag-and-drop and upload progress.
- Edit standalone images with alternative text, captions, fixed responsive widths and alignment; replace images from the library and insert labeled downloadable attachment blocks. Preserve unsupported inline media and unchanged Markdown.
- Store presentation metadata in standard Markdown titles, render responsive figures and attachment downloads through CommonMark, and add a local accessible fit/actual-size viewer in Reader and Preview.
- Reuse canonical media IDs, private Studio URLs and publication reference tracking; keep existing file validation, revisions, backup and deletion protections. No new runtime dependencies or storage migration.
- Add PHP metadata/renderer tests and full-stack browser E2E in CI.

## 0.1.4 — Media Library

- Add the authenticated Studio media library with multi-file upload, drag-and-drop, progress and partial failure reporting; search, folder/type filters, sorting, pagination, rename, move, download and delete.
- Store immutable private blobs with SHA-256 deduplication, file/MIME and image validation, bounded thumbnails, quotas and stable IDs. Media metadata and page references commit under the existing BookStore lock.
- Record canonical `/media/{id}` links and images from the CommonMark AST; Studio previews use authenticated URLs and Reader access requires a published reference. Protect referenced files from deletion and reject stale metadata revisions.
- Add complete consistent TAR backup, offline verified restore to a new directory, and explicit orphan cleanup. Keep the legacy JSON export as metadata-only.
- Add Docker GD/upload settings, PHP and HTTP/browser regression tests. Existing schema-1 data remains compatible; no destructive migration.

## 0.1.3 — Tables & Rich Text

- Edit existing and new GFM tables visually: cell-level formatting, add/remove and move body rows and columns, column alignment, keyboard navigation and source-preserving serialization.
- Paste spreadsheet TSV directly into cells or import quoted CSV/TSV with a preview-independent grid insertion flow. Grow tables within explicit limits; preserve surplus-cell and unsupported tables in Markdown mode.
- Edit GFM task lists and toggle completion in Studio; published Reader checkboxes remain read-only. Keep nested lists, inline code, links and existing rich formatting.
- Add safe local lexical highlighting for PHP, JavaScript/TypeScript, Python, Bash, SQL and JSON in Reader and live Preview, with copy-code controls.
- Extend server and browser regression tests. No runtime dependencies or data migration added.

## 0.1.2 — WYSIWYG Editor

- Add a real local Visual editor with editable text blocks, headings, lists, quotations, inline marks, links, code blocks, block insertion/removal, undo/redo and Markdown mode.
- Use CommonMark AST source ranges to preserve unchanged Markdown, reference definitions and unsupported GFM blocks; keep protected blocks editable in source mode.
- Add server-rendered unsaved Preview using the Reader renderer, CSRF-protected rendering and AJAX draft/publish requests with committed revisions.
- Add debounced autosave for existing drafts, dirty state, copy Markdown, and conflict handling that retains local edits and prevents stale writes.
- Add responsive Studio styles, editor PHP/HTTP/browser regression tests and Chromium CI. No npm dependency, no storage schema migration.

Scope: visual tables/images/task lists and advanced blocks remain later releases; Markdown remains fully supported.

## 0.1.1 — Markdown Engine

- Replaced the custom limited Markdown parser with League CommonMark 2.10.7 and GFM extensions. Core CommonMark syntax, tables, task lists, autolinks and strikethrough render through the same AST pipeline in Reader and Studio.
- Added deterministic Unicode heading slugs, duplicate handling, plain-text hierarchical TOC and aliases for 0.1.0 section-N bookmarks.
- Resolve relative Markdown links and images against the Reader page URL in both Reader and Studio; keep the published source unchanged in the JSON API.
- Escape raw HTML, reject unsafe schemes and protocol-relative destinations, disable data URLs, enforce document-size and parser complexity limits, and add browser-safe image attributes.
- Render responsive table containers and images; preserve the existing fenced-code language metadata and copy buttons.
- Add locked Composer dependencies, PHP mbstring, Docker dependency installation and CI dependency/security checks. Add Markdown unit, policy and HTTP regression coverage.
- No schema migration. Upgrade by overlaying the changed files and installing dependencies or rebuilding Docker. Back up existing data first.

Known scope: Markdown textarea editing remains. WYSIWYG, media upload/library, rich blocks and Mermaid are not part of 0.1.1. Full runtime and container validation must be performed with Composer dependencies installed.

## 0.1.0

Initial self-hosted Book Reader and Studio, draft/publish flow, local JSON storage, search, versions and published-page API.
