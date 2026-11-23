# Production qualification — 0.9.0

This gate executes infrastructure and application operations; it does not certify an arbitrary existing production installation. A successful result means **lab-qualified** for the tested commit, images, profile and host. Keep the existing security, domain/TLS, distributed, physical recovery and updater gates. Independent security assessment, external storage/HA failure and production capacity acceptance remain operator responsibilities.

## Prerequisites and isolation

Use a dedicated disposable Linux amd64 host with local Docker Engine, Compose 2.24.4+, BuildKit, Git full history, PHP 8.4 with the runtime extensions, Composer, Node/npm, OpenSSL and Python 3.12+. Provide enough storage for two application images, the PostgreSQL/PITR image, a 100k-page corpus, encrypted physical backups and portable restore copies. Use a non-root CI account with local Docker access and passwordless sudo for the isolated lifecycle test only. Do not share this daemon with production or run simultaneous qualification jobs: the existing Compose edge network uses a fixed subnet. The test destroys its own randomly named Compose project and volumes. Never point it at a production database, remote Docker context or existing project. The coordinator pins the local Unix socket and strips inherited Book/database/Redis settings.

A full Git history containing the actual 1.1.7 release commit is required. `--previous-ref` may specify that trusted ancestor (prefer the actual release tag/commit). The target must be a clean committed version newer than 1.1.7. A source ZIP without Git history cannot qualify a historical upgrade. The old code is built from Git objects, not a new image with a changed VERSION. The rollback drill intentionally uses 1.1.7, the last schema-compatible release (PostgreSQL schema 7); migrations from earlier schema versions must be separately authorized and verified, and are not misrepresented as an automatic rollback. No production credentials or signing keys are needed for the lab; fixture keys and a local registry exist only for the isolated updater test.

## Run

```bash
composer install --no-interaction --prefer-dist
sh bin/install-mermaid.sh
python3 tests/production-qualification.py --mode smoke --report-dir /tmp/book-090-smoke
```

Smoke executes the actual historical lifecycle, including backup, restore and HTTP validation. It does not qualify a release. Run the full profile on the dedicated host with a new evidence directory:

```bash
python3 tests/production-qualification.py --mode full --report-dir /tmp/book-090-full
```

Allow more than 24 hours: corpus construction, ramp/stress, restore drill, maintenance and teardown add time. The report directory must not exist. Nonzero exit status means failed or incomplete; retain logs and reports, correct the problem, and run a fresh qualification. Do not edit the evidence to turn a failure into success.

## Historical lifecycle

1. Build actual 1.1.7 and current production images; build the pgBackRest image. Start disposable PostgreSQL, Redis, two app replicas, two workers and Nginx. Seed real documents, published/draft revisions, renamed pages, attachment and CI artifact using the cross-module HTTP gate.
2. Enroll the historical deployment using test-signed bundles. Execute the real updater including portable backup/restore drill, native SQL dump, preflight, upgrade, rollback and re-upgrade. Check the unchanged published snapshot, media bytes, search visibility and redirect after each transition; preserve the high-water mark.
3. Stop an actual app container, verify safe reads on the surviving replica, restart the replica and Redis, then verify deep installation integrity.
4. Take a physical backup and create a named WAL target with encrypted application/media sidecar. Verify the archived WAL, publish another marker after the target, archive later WAL. Remove the original Compose project and its data, PostgreSQL and sidecar volumes.
5. Restore only from the independent physical repository and remote sidecar to new volumes/network. Require exact replay LSN and a new timeline through the existing recovery manager. Start a fresh Apache app and verify the page/media hash, search, redirect, pre-target write, absence of post-target write, rejection of the old session and fresh login. Run deep integrity. Cleanup must succeed.

RPO is elapsed wall time from the authenticated paired point to simulated source destruction (maximum 900 seconds). RTO is elapsed monotonic time from source destruction start through physical restore, promotion, application restore and successful deep/HTTP checks (maximum 3600 seconds). These are measurements of this lab and corpus, not guarantees about production storage or operator response.

## Long load acceptance

The second stage uses a separate disposable SQL/Redis corpus: 100,000 published pages over 100 workspaces, four PHP CLI-server nodes (two request workers per node), 100 maximum in-flight requests, 20 arrivals/sec for steady/recovery/24h soak, stress up to 100 arrivals/sec, 64 source IPs and concurrent worker/portable backup/restore operations. It uses the existing open-loop runner, not mocked HTTP. The full soak must account for 1,728,000 arrivals and all 1,440 full one-minute windows. Strict phases require Reader/API/media/isolation p95 <=300 ms, search p95 <=200 ms, save/publish p95 <=500 ms, p99 <=1000 ms, errors <=0.1% per operation, zero integrity errors, bounded PHP RSS, PostgreSQL connections <=64, Redis memory <=2 GiB and keys <=1,000,000. Exploratory overload may exceed latency/error SLOs, but cannot violate integrity or prevent recovery. Reports include the actual failed stress SLO rather than hiding it. Final installation, search and audit integrity must pass.

`qualification.json` records status, Git revisions, source digest, measured recovery SLOs, aggregate load results and SHA-256 of the two underlying reports. The coordinator revalidates full evidence, including resource and maintenance results, before declaring `lab-qualified`. A smoke run is `smoke-passed`, never `lab-qualified`. No report supplied by the operator can substitute for running the tests.

## Protected release pipeline

Configure a protected `qualification` GitHub environment and a dedicated self-hosted Linux runner with label `book-load`. Only protected version-tag pushes can enter the long job; untrusted PRs never run there. The tag must be annotated and on protected main history. The enterprise gate requires success of all existing jobs plus the long qualification for tags; ordinary branch/PR runs still execute historical lifecycle on the hosted Docker gate. The candidate build downloads the full evidence from that run, repeats historical lifecycle on the exact pulled app and PITR candidates, validates source/digest and RPO/RTO, and binds evidence hashes in the signed `gate-evidence.json`. The signing verifier rejects a 0.9.0+ release with missing/invalid qualification metadata. Official Cosign and Ed25519 signatures are generated only by the protected release job, not the lab. Retain CI artifacts separately for audit. Rerun all jobs if the attempt's qualification artifacts are unavailable.

The 24h soak uses CLI-server nodes, not a 24h Apache/Caddy cluster. Multi-host PostgreSQL promotion, real Sentinel failover, S3 outage, external network partition, independent penetration testing and production-specific capacity/retention need their corresponding gates and environment-specific drills. The lifecycle uses a small dataset and independent local repositories; it does not establish RTO for 100k pages or geographically remote storage. See [load qualification](load-qualification.md), [disaster recovery](disaster-recovery.md), [secure updates](secure-updates.md) and [signed releases](signed-releases.md).
