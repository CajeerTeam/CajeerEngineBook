# Load & stress qualification (0.6.2)

This release extends the actual corpus and HTTP runner in `tests/performance-benchmark.py`. It does not certify a production deployment. The previous fixed-count benchmark remains available; `--qualify` uses independently scheduled arrivals instead of closed-loop clients. Only disposable installations are accepted. SQL mode requires a dedicated `book_benchmark` database with no existing installation, Composer dependencies, PHP 8.4 with `pdo_pgsql`, `redis`, `mbstring`, and `zip`, and Linux `/proc` for process resource accounting. Run on an isolated test host, not production. Schema 3 is unchanged.

## Workload and gates

| Phase | Offered load | Gate |
|---|---|---|
| Steady | configured RPS | strict per-operation SLO |
| Ramp | linear increase from 1× to 2× | exploratory overload, integrity and some successful service required |
| Spike | multiplier for half a phase | exploratory overload |
| Stress | multiplier for a full phase | exploratory overload |
| Recovery | original RPS | strict SLO after overload |
| Soak | original RPS for specified duration | strict SLO and full 60-second windows, PHP resource bound |

Every offered arrival is counted, including client-capacity drops and generator scheduling drops. No unbounded request queue: `--concurrency` is a hard upper bound on in-flight tasks, not a claim of that many active users. The arrival clock uses monotonic time; latency includes the wait from scheduled arrival to completed response, and service latency is recorded separately. Histograms use conservative upper bounds (1 ms to 1 s; 10 ms thereafter; overflow above 120 s). Expected 404 privacy checks are successes only if the body does not expose the hidden marker. Unexpected statuses and wrong content fail integrity, including during overload. HTTP 429/502/503/504 and transport failures are availability failures. Strict phases include all drops/errors in their denominator; at least `--min-samples` successful observations per operation are required. A full minute of a soak cannot be hidden by a favorable overall percentile. Short trailing windows are reported but not separately enforced. A stress phase can be safe while failing its SLO: `slo_passed:false` remains in JSON, and JUnit separately gates safety, not overload capacity.

Default strict SLO: Reader/API/media/privacy p95 <=300 ms, search <=200 ms, save/publish <=500 ms, p99 <=1000 ms, errors <=0.1% per operation, no integrity errors, PHP sampled RSS peak growth <=128 MiB. Thresholds are proposed engineering targets; adjust only with an explicit profile. Full workload includes actual HTML Reader, selective/broad search, authenticated Studio save and publish on a dedicated page, API, media, and negative access probes. Overload is read-only to prevent ambiguous mutation replay. The final publication and corpus counts are checked; full mode also checks installation integrity, SQL search and audit. SQL/Redis soak samples real connections, lock waiters, memory, keyspace counts and evictions. Optional resource caps fail the gate. `--background-maintenance` runs actual worker scans and portable backup/restore checks concurrently with soak; it does not imply a populated external webhook queue or remote S3 transfer. A maintenance cycle may drain after offered load ends, but the backup must start during the load. All commands have bounded timeouts and write individual logs.

Redis rate limiting is not disabled. The direct local test uses actual loopback socket source addresses (not forwarded headers); `--source-ips` spreads traffic across client addresses. Recovery waits 60 seconds after overload in Redis mode to allow actual quota expiration, and records this wait. The workload runs against PHP CLI-server replicas, not Apache/Caddy; full deployment qualification remains a separate release gate.

## Run

Short disposable smoke:

```sh
python3 tests/performance-benchmark.py --qualify --pages 1000 --per-workspace 500 \
  --concurrency 12 --nodes 2 --telemetry --phase-seconds 8 --soak-seconds 65 \
  --offered-rps 12 --stress-multiplier 12 --min-samples 10 --report-dir /tmp/book-qualify-smoke
```

Full PostgreSQL/Redis 24-hour test (Docker creates a new dedicated database/role and Redis with random names and ports; no external operator DSN):

```sh
composer install --no-interaction --prefer-dist
sh bin/install-mermaid.sh
sh tests/qualification-gate.sh /tmp/book-qualify-24h
```

This uses 100,000 published pages across 100 workspaces, four PHP nodes, up to 100 in-flight requests, 20 offered arrivals/s steady, 100/s stress, 24h soak, and concurrent maintenance. It sets caps of 64 SQL connections, 2 GiB Redis memory, and 1 million Redis keys, in addition to latency, availability and RSS gates. These are test limits, not verified capacity or hardware recommendations. Prepare sufficient disk for corpus, SQL, backup staging and evidence. `tests/qualification-gate.sh` removes its containers on exit. The manual `.github/workflows/load-qualification.yml` uses an isolated self-hosted Linux runner labeled `book-load`; ordinary hosted CI runs short tests because a 24h job exceeds hosted runner limits. Do not register a production host as this runner. The 24h workflow is not a substitute for the normal `enterprise-release` gate.

For custom SQL runs use the existing `BOOK_BENCHMARK_PG_*` and `BOOK_BENCHMARK_REDIS_*` variables described in [Performance](performance.md), `--postgres --redis --full --qualify`, and the duration/rate/resource flags above. No arbitrary URL or inherited production credentials are accepted. Optional `--baseline PATH --max-regression-percent 20` compares p95 and p99 only for comparable strict phases, with matching environment, corpus and workload; failed baselines are rejected. An overload-safe baseline does not certify the stress RPS.

## Evidence

JSON and JUnit contain actual measured results, per-operation statuses, dropped requests, error fractions, resource samples, full-minute windows, process health and phase outcomes. Reports include source digest, version and environment; the source digest covers VERSION and PHP/Python/SQL/shell files in src/sql/tests, not vendor packages or container images. Checkpoint JSON is marked failed until the entire run and final checks finish; interrupted tests cannot become passing evidence. Node logs are bounded to 8 MiB per process in qualification mode. Report directory must be new; temporary corpus and SQL namespace are cleaned up. Persist evidence off the test host.

`docs/benchmarks/0.6.2-file-1000-65s.json` and `0.6.2-file-10000-65s.json` preserve real 65-second soak development runs (before final additional resource/maintenance checks and source digest). Both demonstrate strict steady/recovery/soak success and exploratory stress saturation; they do not qualify PostgreSQL/Redis, Markdown, Studio or 24h. `0.6.2-file-final-smoke.json` is the final shorter file-mode run with the final runner. The final source and infrastructure must be tested before promotion; no production certification is inferred from file-mode reports.

## Live monitoring

Prometheus adds public-read availability and tail-latency recording/alert rules by route; normal 4xx responses are excluded from availability while 429 and 5xx count. These server-side counters cannot see client admission drops, network failures before the application, or end-to-end queuing; use the external load evidence for those. Redis diagnostics report aggregate key and expiry counts, without enumerating key names. Full Prometheus rule tests run in the existing monitoring gate.
