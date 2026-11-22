# Administrator handbook — CajeerEngine Book 1.1.3

**Current PostgreSQL schema: 7.** This handbook is the entry point for deploying, operating, upgrading and recovering Book. It supersedes the version-specific installation snippets in the project README; the linked specialist guides retain their original release context. Do not repeat an old migration solely because its guide mentions it.

## Start here

| Task | Instructions |
| --- | --- |
| Admin Update Center 1.1.3 | [Owner-only GitHub update administration](update-center-113.md) |
| GitHub Release discovery 1.1.2 | [Secure version discovery via GitHub Releases](update-discovery-112.md) |
| GitHub Release Infrastructure 1.1.1 | [GitHub Release qualification and public asset verification](release-111.md) |
| Acceptance for 1.1.0: regression, security, recovery, load and rollback | [1.1.0 release acceptance](release-110.md) |
| Choose topology, install, initialize owner, validate deployment | [Installation and operations](operations.md) |
| Configure storage, PostgreSQL, Redis, secrets, TLS, SSO | [Configuration reference](configuration.md), [SkiF4er ID integration](skif4er-id.md) |
| Diagnose faults and run maintenance safely | [Incident runbook](incidents.md), [CLI reference](cli-reference.md) |
| Review access, rotate credentials and revoke sessions | [Security lifecycle](security-lifecycle.md), [security hardening](security-hardening.md), [release candidate retest](release-candidate.md) |
| Run replicas, Redis Sentinel and repair media | [Distributed reliability](distributed-reliability.md) |
| Monitor metrics, logs, alerts and RPO | [Observability](observability.md) |
| Physical backups, WAL, named restore points and total-loss recovery | [Disaster recovery](disaster-recovery.md) |
| Verify signed ZIP/OCI, publish a release | [Signed releases](signed-releases.md), [embedded engine compatibility](engine-core.md) |
| Install a signed release, recover interrupted upgrade or rollback | [Secure updates](secure-updates.md) |
| Historical SQL migration to schema 3 and performance | [PostgreSQL performance](postgres-performance.md) |
| Workspace analytics and schema 5 migration | [Analytics, dashboards and vendor integrations](analytics.md) |
| Documentation embeddings, hybrid search and schema 6 | [Semantic search and indexing](search-embeddings.md) |
| OpenAI documentation chat, SSE, sources and schema 7 quotas | [Documentation chat](documentation-chat.md) |
| Editor AI, coverage analysis, migration errors and review-bound fixes | [Editor AI assistant](editor-ai.md) |
| Import Mintlify docs.json/mint.json, MDX and media into drafts | [Mintlify migration guide](mintlify-import.md) |
| Import Docusaurus sidebars, versions, languages, admonitions, MDX and media | [Docusaurus migration guide](docusaurus-import.md) |
| Migrate GitBook Spaces, pages, Git Sync exports, API content, navigation and attachments | [GitBook migration guide](gitbook-import.md) |
| Import ReadMe Guides, API Reference, OpenAPI, Recipes, versions and attachments | [ReadMe migration guide](readme-import.md) |
| Import Fumadocs content collections, meta.json, locales and static MDX | [Fumadocs migration guide](fumadocs-import.md) |
| Measure capacity and qualify production SLOs | [Performance](performance.md), [load qualification](load-qualification.md) |
| Qualify historical upgrade, total-loss recovery and long load | [Production qualification](production-qualification.md) |
| Reproduce documentation checks | [Verification](verification.md) |

## Support boundaries

- File mode is for one host, with persistent private filesystem; SQL mode is PostgreSQL 16 plus the same private media volume. Distributed app replicas require PostgreSQL and shared POSIX storage. Redis is optional for single node and required for the documented distributed runtime. Sentinel is an externally operated cluster, not supplied by Book.
- The SQL runtime requires schema 7 and a writable primary. PostgreSQL HA promotion, shared storage replication and external secret-manager lifecycle belong to the infrastructure operator. Do not use transaction-mode connection pooling with session advisory locks.
- Production builds contain the CLI but not tests or this handbook; use the matching signed source ZIP for documentation. The deployment ZIP uses immutable own-image digests; do not rebuild it. Some optional services depend on upstream images; inspect and qualify those separately.
- A documentation smoke test is not production certification. Readiness is not a backup drill. Passing a file-mode benchmark does not demonstrate PostgreSQL throughput. Complete the release, security, PITR and load gates for your infrastructure before exposing production traffic.

Use an independently trusted release public key, maintain an offline backup of configuration/keys and keep a separate operator account. Never publish diagnostics containing identifiers, backup archives or credentials. The new `bin/admin.php report` gives a deliberately redacted local summary and `check` supplies a nonzero exit code for failed checks. See [operations](operations.md) for exact usage and limits.
