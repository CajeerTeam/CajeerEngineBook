# Distributed Reliability — 0.6.1

## Upgrade and scope

Use a verified backup of the 0.6.0 installation and matching SQL/volume data and keys. Stop all app, worker, domain-check and backup processes before replacing files and rebuilding images. Version 0.6.1 uses **schema 3**, unchanged from 0.6.0. Run `php bin/migrations.php verify`, `php bin/postgres-content.php check`, `php bin/search.php check` and `php bin/audit.php check` before starting the new replicas. With Redis run `php bin/runtime.php check` as well. Do not mix versions while qualifying failover. PHP CLI workers require `pcntl` for `--loop`; the image now installs it. `--once` remains available and processes at most 16 jobs per tick. Existing data and media IDs are unchanged.

## Redis failover

Default `compose.redis.yaml` is still a single-host development/installation profile, not HA. For production use an operator-provisioned Redis primary/replicas and three or five independent Redis Sentinels. Configure each Sentinel to monitor the same master name with quorum 2 of 3 (or 3 of 5), separate Redis/Sentinel credentials, appropriate failover timeout, and reachable advertised primary endpoints. All nodes need stable private networking and authenticated replication; use network ACLs and TLS across untrusted networks. Redis replication is asynchronous: it is not a durable database or strict distributed security counter. Configure appropriate `min-replicas-to-write` / `min-replicas-max-lag` for the topology (with two replicas if writes must continue after a single primary loss); verify the availability tradeoff on the actual cluster. Sentinel itself does not eliminate split brain.

Native app and worker environment:

```dotenv
BOOK_REDIS_SENTINELS=sentinel-a.internal:26379,sentinel-b.internal:26379,sentinel-c.internal:26379
BOOK_REDIS_SENTINEL_MASTER=book
BOOK_REDIS_PASSWORD=<redis-primary-and-replica-password>
BOOK_REDIS_SENTINEL_PASSWORD=<sentinel-password>
# Optional ACL users:
BOOK_REDIS_USER=book
BOOK_REDIS_SENTINEL_USER=book-discovery
# For TLS-only endpoints with verified certificate names:
BOOK_REDIS_TLS=1
# Optional CA path (native or mounted into all relevant containers):
BOOK_REDIS_CA=/secure/redis-ca.pem
```

With Compose use `compose.redis-sentinel.yaml` **instead of** `compose.redis.yaml`, along with base + postgres + distributed overlays, then domains/backup/observability if needed. The Sentinel overlay does not provision the cluster. The exporter reads the same Sentinel environment through `compose.observability.yaml`. If using a private CA, mount it and set `BOOK_REDIS_CA` in an operator overlay for book, worker and diagnostics; certificates must cover the addresses advertised by Sentinel. The ACL user on Sentinel needs `SENTINEL get-master-addr-by-name`; Redis user needs existing cache/rate/stream commands, `ROLE`, and optional `INFO` for diagnostics. Do not publish Redis or Sentinel ports publicly. No credentials are included in this release.

The client queries configured Sentinels, requires a majority to agree on one exact endpoint, verifies `ROLE=master`, and refreshes every 5 seconds in long-lived processes (fresh PHP request processes discover on connection). Failed commands invalidate the connection and apply jittered backoff (1–2.5 seconds). No failed command is blindly replayed, and no fixed-host fallback is used when Sentinel is configured. Cache falls back to SQL computation, Redis wakeups are best effort, and the durable SQL queue is scanned independently. Public API/search/webhook/CI limits continue to fail closed on unavailable Redis. Existing durable authentication throttles remain authoritative. Redis counter/cache/stream state can be lost during promotion; do not use Redis alone for security-critical durable counters. `/health/ready` stays strict when Redis is unavailable; `/health/live` stays a process probe.

## Workers and PostgreSQL

Run at least two workers on independent hosts for host-level HA; `docker compose --profile automation up -d --scale worker=2` gives process-level redundancy on one host. Each worker holds a PostgreSQL advisory lock per workspace; one SQL job claim with a fresh 15-minute lease is committed before the external call. A worker uses the database clock and the *same lock-owning session* before local completion. A missing connection or expired/fenced lease prevents local completion; the next worker waits for lease expiry and then reclaims the job with a new token. File mode uses the shared file lock and can reclaim immediately once that lock is released. At most 16 jobs per tick, rotating across scopes; Redis hints cannot authorize work. Failures in the outer loop back off with jitter up to about 31 seconds. SIGTERM/SIGINT stop new claims and let the in-flight job finish; Compose permits 16 minutes for graceful stop. A force kill may leave an unexpired lease: do not manually reset live claims. Webhook receivers must deduplicate the signed `X-Cajeer-Delivery` ID: external delivery is at-least-once, not exactly-once.

Use a PostgreSQL HA provider or a fenced single-writer cluster and a writable-primary endpoint. Native libpq multi-host DSN may specify `host=db1,db2;port=5432;dbname=book;connect_timeout=5;target_session_attrs=read-write` with properly configured TLS, or use an HA proxy. The application refuses standby/read-only sessions before runtime schema use. It does not replay ambiguous SQL writes: failed HTTP operations return an error; verify the outcome before retrying a mutation. Workers reconnect on later ticks. Provider-specific promotion, synchronous replication and prevention of split brain are operator responsibilities, not implemented by the application. The default PostgreSQL Compose service is a single database. All nodes need the same namespace, binding markers, credentials, encryption keys and synchronized clocks.

## App replicas and edge

Existing Nginx and Caddy configs select dynamic Docker app addresses. Safe Reader, published API, search, media and health GET/HEAD routes have bounded connect-failure retries. Studio writes, CI mutations, account routes and OIDC callbacks are not replayed. A retry may consume another rate-limit slot. Responses already streamed to a client cannot be retried. Caddy passive health and dynamic discovery are not an active service registry; check every replica's readiness independently. Run redundant public ingress and use a proper multi-host service discovery/load balancer for host failure; the included Compose example is a single-host deployment. Keep certificate issuance and shared TLS storage consistent on redundant gateways.

## Shared media storage

All app, worker, backup and diagnostics processes must access the **same protected POSIX data root** with atomic rename, durable file/directory fsync and cross-host flock. A default local Docker named volume is not multi-host storage. Pre-create a shared volume backed by a reliable POSIX filesystem (for example a correctly configured highly available NFS service) on each host, with the same exported directory, UID/GID 33 permissions and matching mount options. Do not silently initialize a new empty local volume on mount failure. `compose.shared-storage.yaml` uses an existing external volume named by `BOOK_SHARED_VOLUME`. It does not provision the filesystem or replicate media.

Verify actual visibility and locking between replicas (use the equivalent commands on different physical hosts in production):

```bash
id=$(docker compose exec -T --index 1 -u www-data book php bin/storage-probe.php create)
docker compose exec -T --index 2 -u www-data book php bin/storage-probe.php verify "$id"
# Terminal 1, wait until LOCKED appears:
docker compose exec -T --index 1 -u www-data book php bin/storage-probe.php hold "$id" 30
# Terminal 2, while the first command is holding the lock:
docker compose exec -T --index 2 -u www-data book php bin/storage-probe.php busy "$id"
# After the holder exits:
docker compose exec -T --index 2 -u www-data book php bin/storage-probe.php remove "$id"
```

The probe uses a random private file and installation binding; verify fails if the other node does not see the same file. The lock test must observe exclusion, not merely file visibility. These are operator tests, not a substitute for storage vendor durability/HA qualification.

Media reads now authenticate the complete bounded blob against its authoritative SHA-256 and size while authorization and content locks are held, then stream a verified private buffer (up to 2 MiB in memory, larger data uses PHP's temporary directory). The original cannot change the response after verification. Same-size corruption and missing blobs return 503, never partial verified bytes. Derived thumbnail cache uses a source/content digest sidecar and regenerates corrupt previews. Original files remain immutable. Disk/temp capacity and hash cost must be included in load tests. `book_media_integrity_failures_total` and the media verification histogram are exported when observability is enabled; the alert detects recent failures.

Repair an original **only** from a verified backup with matching metadata and SHA-256. Restore the backup separately, find the exact media ID, then run as the installation operator (the scope is `legacy` or `ORG_ID/WORKSPACE_ID`):

```bash
php bin/media-repair.php ORG_ID/WORKSPACE_ID MEDIA_ID /secure/restored/media/MEDIA_ID.blob
```

Repair runs under the content mutation lock, verifies the source before atomic replacement, preserves metadata/ID/references, and invalidates the derived preview. Wrong bytes and unknown IDs are rejected. It cannot recreate missing metadata; use full recovery for that. Run `php bin/verify-data.php DATA_DIR` with the proper installation configuration afterward. Never copy unverified bytes over a live blob.

## Qualification and fault tests

Local (`php` with pcntl, Python 3, Nginx; no Docker required for these):

```bash
php tests/reliability.php
python3 tests/reliability-http.py
```

The first exercises real persistent jobs, process signals, lease fencing and POSIX lock visibility. The second starts actual PHP app replicas and Nginx, kills a replica, verifies 120 API/media responses, corrupts a same-size blob and repairs it, and separately checks proxy replay behavior with a transport-only abort fixture. For native Nginx testing only, the script substitutes static loopback upstreams and private log paths; Docker tests use the unmodified dynamic config.

Full infrastructure release gate (disposable Linux host, dependencies listed in README):

```bash
python3 tests/release-gate.py --suite all --report-dir /tmp/book-061-gate
```

The distributed gate stops a real app container, verifies reads and cross-container storage locks, then creates a real isolated Redis primary/replica and three Sentinels, kills the primary, observes promotion using a long-lived PHP client, loses the Sentinel majority and restores it. SQL integration kills a real worker after the sender begins, verifies the durable lease and reclaims it after simulated expiry. It also rejects a read-only PostgreSQL session. The domains gate tests Caddy/TLS with a stopped replica. These tests must pass on the actual infrastructure before declaring full HA qualification; they do not prove host-level PostgreSQL failover or storage replication. Use separate fault domains, backup/restore drills, long soak tests and measure RPO/RTO before production promotion.
