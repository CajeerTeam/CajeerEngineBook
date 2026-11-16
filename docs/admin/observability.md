# Observability and diagnostics (0.5.3)

## Deploy

Use the existing PostgreSQL/Redis/distributed/domains/backup/enterprise overlays. Append `compose.observability.yaml` to `COMPOSE_FILE`, after the other overlays. Stop and rebuild the existing Book services when updating; retain all volumes, credentials and namespace. The original 0.5.3 release used schema 2; current 0.8.2 requires schema 3. Follow the [current administrator handbook](README.md) for installation and upgrades. The overlay assumes the standard Compose database name/user `book`, PostgreSQL service `postgres`, Redis service `redis` and shared `book-data` volume. For an external database or Redis, override the diagnostics service connection settings to match the application. The exporter has no backup or OIDC client secrets; application readiness is still checked on the actual application nodes.

Create a protected directory on the host outside the source tree. Generate a random token and separate Grafana password; supply an HTTPS Alertmanager webhook URL at your organization (receiver must understand Alertmanager JSON). Never put these in source control.

```sh
umask 077
mkdir -p /secure/book-monitoring
openssl rand -hex 32 > /secure/book-monitoring/metrics-token
openssl rand -hex 32 > /secure/book-monitoring/grafana-password
# Write your actual HTTPS receiver URL to /secure/book-monitoring/alert-webhook.
```

Ensure the host directory is accessible only to the operator; bind-mounted files must be readable inside the relevant containers (Book www-data UID 33, Prometheus/Alertmanager image user, Grafana UID 472). Use ACLs or suitably restricted ownership; the token is shared by the exporter and Prometheus. If ACLs are unavailable, readable bind-mounted files within a root-only host directory are an alternative. Do not mount the whole secret directory into any container.

```dotenv
BOOK_METRICS_TOKEN_PATH=/secure/book-monitoring/metrics-token
BOOK_ALERT_WEBHOOK_PATH=/secure/book-monitoring/alert-webhook
BOOK_GRAFANA_PASSWORD_PATH=/secure/book-monitoring/grafana-password
BOOK_BACKUP_MAX_AGE=90000
BOOK_MONITOR_TLS=1
```

```sh
export COMPOSE_FILE=compose.yaml:compose.postgres.yaml:compose.redis.yaml:compose.distributed.yaml:compose.domains.yaml:compose.backup.yaml:compose.enterprise.yaml:compose.observability.yaml
docker compose --profile automation --profile backup config --quiet
docker compose --profile automation --profile backup build book worker domain-check backup diagnostics
docker compose --profile automation up -d --wait --scale book=2 --scale worker=2
docker compose --profile backup up -d backup
```

Grafana is on loopback port 3000 (user `admin`, configured password); Prometheus on loopback 9090. Use SSH tunneling or an authenticated administrative ingress; never expose unauthenticated Prometheus to the Internet. Alertmanager and diagnostics are internal services, not public edge routes. Grafana ships a provisioned Book Operations dashboard. Logs use Docker json-file rotation (20 MiB x 5 per container).

Native deployment: enable `BOOK_OBSERVABILITY=1` on all runtime processes using the same protected data directory; configure a separate PHP server with `BOOK_OBSERVABILITY_EXPORTER=1` and `BOOK_METRICS_TOKEN_FILE` (64 lowercase hex characters) behind a private network or trusted TLS. Set `BOOK_MONITOR_WORKERS`, `BOOK_MONITOR_BACKUP`, `BOOK_MONITOR_DOMAINS`, `BOOK_MONITOR_TLS` to `1` for the services you expect. Do not enable exporter mode on public app nodes. The exporter uses the same application autoloader and PHP extensions. No new PHP dependency is required.

## Inspect and troubleshoot

```sh
php bin/diagnostics.php status
php bin/diagnostics.php check
php bin/diagnostics.php metrics
# In Compose:
docker compose exec -T diagnostics php bin/diagnostics.php status
docker compose exec -T prometheus /bin/promtool check config /etc/prometheus/prometheus.yml
docker compose exec -T alertmanager /bin/amtool check-config /etc/alertmanager/alertmanager.yml
```

`check` returns nonzero when an expected dependency is unavailable. `/metrics` on the dedicated exporter requires a bearer token; it returns 200 with individual dependency gauges even during outages. `/diagnostics` requires the same token and returns 503 if any probe fails. Unauthorized requests return 404. Public `/health/live` and `/health/ready` retain the existing application contract. A healthy exporter is not proof that every app replica is ready. Scrape every replica's readiness separately with external monitoring if required; the included readiness alert observes actual readiness requests. Use `BOOK_MONITOR_TLS=0` only if primary TLS is monitored independently (e.g. private CA); the TLS probe verifies the configured primary host with the system trust store. Custom domain DNS leases are counted, not individually contacted by the exporter.

Metrics include HTTP request count/status class, duration histogram by bounded route, operation duration/outcome (SQL connection, lock acquisition, content/identity transactions and queries, Redis commands, worker, backup and DNS), queue state and overdue age, worker heartbeat, database connection/lock counts, Redis cache/rate/stream and memory, backup capture/verification/failure/pending timestamps, disk free, DNS and TLS expiry. Database activity counts are database-wide and visibility depends on SQL privileges. Request IDs are shared between HTTP header, structured logs and request audit context. Job logs include job ID and attempt. Do not treat them as an immutable audit record. Native web server and infrastructure logs may use different formats; protect their access and retention separately. No raw query string, SQL text, cookies, tokens, exception message or page text is included in application telemetry.

The 20 alert rules cover exporter/dependency failures, HTTP 5xx, Reader/Search p95, SQL waits, worker/queue, backup freshness, disk, domain verification, TLS and Redis. Alertmanager sends firing and resolved notifications to the configured webhook. Thresholds (300 ms Reader, 200 ms Search, 5 minute worker age, 1 hour backup scheduler heartbeat, 2 hour DNS heartbeat, 14 day TLS expiry, 1 GiB free disk) are starting operational values, not validated SLA. Adapt the rule thresholds and expected component configuration to workload and backup duration. Backup freshness is based on the last verified capture, not an upload attempt. Check `bin/recovery.php status` and scheduler logs for pending/failed backups; check `bin/worker.php --once` on a test environment for stalled work, and `bin/domains.php recheck` for DNS health. Repair PostgreSQL/schema/search/audit via their existing operator CLIs, not by editing metrics files. If Redis is unavailable, inspect Redis and reconnect; PostgreSQL health is reported independently. If the telemetry probe fails, check directory permissions, available space and file corruption. With writers stopped, move the observability directory aside to reset counters if necessary; preserve audit and data files. The next request creates fresh metrics. Do not restore telemetry as business data.

## Guarantees and limits

Shared-volume telemetry uses 16 fixed shards with atomic publication and bounded lock wait; updates may be dropped on contention or failure and are not exactly-once. There are no per-tenant/user/URL metric labels. Multiple physical hosts need the same protected filesystem with working POSIX locking/atomic rename; independently mounted local disks will not aggregate. Exporter probes are bounded by connection/statement timeouts, but aggregate probe latency can exceed scrape timeout during multiple failures. Prometheus records scrape failure in that case. Redis/SQL statistics do not replace database-native exporters. Metrics are operational, not billing or security evidence. Prometheus retains up to 30 days/5 GB, subject to its own disk capacity. The backup diagnostic state contains only timestamps and counts, not repository credentials. Alertmanager must have outbound connectivity to the approved webhook, and receiver availability must itself be monitored.

## Verify

`python3 tests/observability-http.py` exercises real PHP processes, aggregate counters, token checks, structured logs, worker and corrupted telemetry. `php tests/recovery.php --storage-only` verifies real encrypted backup/recovery and telemetry. The existing `tests/release-gate.py --suite all --report-dir /tmp/book-observability-gate` includes SQL/Redis and monitoring stack tests: Prometheus rules, Grafana provisioning, Alertmanager real webhook delivery, worker recovery and Redis stop/start. Run on an isolated full-infrastructure test host. Never run failure injection on production.
