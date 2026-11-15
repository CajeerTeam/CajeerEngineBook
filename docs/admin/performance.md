# Performance benchmarking (0.5.4)

Use a **disposable test machine**, not a live installation. `tests/performance-benchmark.py` actually creates an installation, starts independent PHP HTTP processes, drives verified HTTP requests, measures elapsed time and collects evidence. There are no mocked application responses. No production URL is accepted. It does not change production quotas, disable authorization, clear Redis globally, or use production `BOOK_*` credentials. All generated data is synthetic.

## Corpus and limits

The generator uses `IdentityStore`, `BookStore::change`, `MediaStore::upload` and ordinary persisted page snapshots. Each workspace has a guide, multiple versions (alternating groups of 100), varied technical Markdown (headings, tables, JSON code, sections), one attachment, 10% linked Russian translations, and a real history change on its first page. The published snapshot stays independent of the draft. Private and draft controls are inserted when there is room, plus a separate legacy-scope control. A 100,000-page corpus means **100 workspaces × 1,000 public pages**, not a 100,000-page single book. The product's 100 workspace, 1,000 page and 32 MiB per-scope quotas remain in force. A 100,000-page run also has the two legacy control pages; these are not counted as public documents. Synthetic page bodies are reproducible by seed and page ordinal; session credentials, entity IDs, media IDs and timestamps are intentionally not byte-identical between installations.

| Public pages | `--per-workspace` | Workspaces |
| ---: | ---: | ---: |
| 1,000 | 500 | 2 |
| 10,000 | 1,000 | 10 |
| 50,000 | 1,000 | 50 |
| 100,000 | 1,000 | 100 |

## Minimal file-mode run

Linux, Python 3.10+, PHP 8.4.1+ with existing file-mode extensions (fileinfo, openssl, sodium, phar). The minimal run uses the real App through the test bootstrap; it does **not** emulate CommonMark, PostgreSQL or Redis. With Composer dependencies installed the same App is loaded through the production bootstrap.

```sh
python3 tests/performance-benchmark.py \
  --pages 10000 --per-workspace 1000 --requests 300 --concurrency 100 \
  --nodes 2 --telemetry --report-dir /tmp/book-performance-file
```

`--requests` is per measured read phase. The varied-key phase samples the corpus with a deterministic PRNG; the repeated-key phase warms and repeats the first page in up to two workspaces. Neither phase claims a cold PostgreSQL/OS buffer cache. The test is closed-loop: each client submits its next request after the previous one completes; `--rate` optionally caps aggregate launch frequency. It reports per-operation latency (including client/server queueing after request start), not coordinated-omission-corrected open-loop latency. A 404 expected by an isolation test is a success; any unexpected status, including 429, body mismatch or network error fails the gate. Latencies include only successful operations; errors and status counts are reported separately. Thresholds default to 5,000 ms smoke limits, **not** production SLOs.

Minimal read workload: individual published page API, actual attachment bytes, negative draft reads. Every workspace's visible page count is checked before measurement; private pages, draft pages and cross-workspace attachments are denied. Two nodes share data without relying on client-side fixtures.

## Full PostgreSQL / Redis run

Install the application's real Composer dependencies and Mermaid assets first (`composer install --no-interaction --prefer-dist`, `sh bin/install-mermaid.sh`); use PHP 8.4.1+ with `pdo_pgsql`, `mbstring`, `zip`, `gd`, `redis`, `sodium`, `openssl`, `fileinfo`, `phar`. Use PostgreSQL 16+ and a dedicated test Redis. Create a new database named `book_benchmark` or `book_benchmark_*`, owned by a **non-superuser test role** with permission to create the application schema and trusted extensions. The runner checks the actual connected database name and refuses a database containing an existing Book installation. It creates a new random installation namespace and a new private temporary data directory. It only deletes that exact namespace after checking its binding marker. Do not reuse a partially failed or concurrent benchmark database: recreate the disposable database if cleanup failed. Use a freshly started Redis to avoid unrelated statistics and remove it afterwards; no global flush is performed.

```sh
export BOOK_BENCHMARK_PG_DSN='pgsql:host=127.0.0.1;port=5432;dbname=book_benchmark;connect_timeout=5'
export BOOK_BENCHMARK_PG_USER=book_benchmark
export BOOK_BENCHMARK_PG_PASSWORD='test-only-password'
export BOOK_BENCHMARK_REDIS_HOST=127.0.0.1
export BOOK_BENCHMARK_REDIS_PORT=6379
# optional BOOK_BENCHMARK_REDIS_PASSWORD / USER / TLS / CA
python3 tests/performance-benchmark.py \
  --postgres --redis --full --telemetry --maintenance \
  --pages 1000 --per-workspace 500 --requests 120 --concurrency 8 \
  --report-dir /tmp/book-performance-sql
```

The SQL runner creates the current schema through the real migration engine; all subsequent seeding transactions use PostgreSQL and update the actual full-text index. It runs ANALYZE before load, verifies the expected public count in SQL and collects PostgreSQL buffer/transaction counters, relation sizes and real `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` plans for content scope loading and the actual Search count/rank/headline query path. Profiling is outside the timed phases. `PostgresSearch::explain` is CLI-only and requires a transaction; it executes the query for the plan and again for the normal result. Do not run it against sensitive production data indiscriminately. SQL plan output can contain literal values. The included reports use synthetic data only.

Full mode adds real Markdown Reader and exact/selective plus broad filtered ranked search (with result, locale and total-count assertions), then sequential authenticated Studio save/publish via HTTP with CSRF and optimistic revisions. Maintenance mode additionally times a real 100-page CI build and atomic publish via bearer API, a worker tick (idle scan, not throughput of external jobs), offline reindex (SQL only), portable backup, isolated restore and integrity check. These operations run outside the main read load and are not blended into Reader p95. An actual SQL drill into a separate database is covered by the existing recovery gate, not claimed by this portable restore timing.

**Rate limiting:** Redis uses the real per-IP 300 API requests/minute. With one loopback source the runner caps `--requests` at 120 per read phase when Redis is enabled and includes preflight/warmup traffic in the budget. For high-throughput SQL load without Redis rate limiting, omit `--redis` on the disposable database. Do not disable production limits or spoof forwarded IPs. Large sustained distributed Redis tests require a separate realistic multi-client network/load generator (roadmap 0.6.2).

## Evidence, baselines and regression checks

Each invocation requires a fresh `--report-dir`. It saves `report.json`, `junit.xml`, seed log and per-node server logs (plus operation logs for maintenance). Reports contain release, backend, profile, corpus size, CPU model/quota, memory quota, PHP/Python versions, extension list, per-class p50/p95/p99/max, status counts, bytes, throughput, PHP process CPU/RSS/I/O and telemetry deltas. `/proc` I/O is physical process I/O, not network bytes; RSS is sampled sum across PHP processes and can double-count shared pages. PostgreSQL stats are cumulative for the isolated database, with snapshots before/after the timed load; Redis stats/resources are scoped counters plus server resource totals. No raw session tokens or credentials enter the JSON report. A failed run retains evidence and exits nonzero.

Compare a **successful report from the same hardware/runtime, same backend, corpus and workload**:

```sh
python3 tests/performance-benchmark.py \
  --pages 1000 --per-workspace 500 --requests 120 --concurrency 8 \
  --nodes 2 --telemetry --baseline /tmp/previous/report.json \
  --max-regression-percent 20 --report-dir /tmp/book-performance-compare
```

The comparator rejects mismatched profiles/environment/corpus, unsuccessful baselines and missing phases; any per-operation p95 regression above the threshold fails. Repeat measurements on a quiet machine (prefer at least three runs) before adopting a release threshold. Set `--reader-p95-ms`, `--search-p95-ms`, `--write-p95-ms` to accepted SLOs only after obtaining a SQL baseline. For telemetry cost, run otherwise identical profiles with and without `--telemetry`; compare the reports manually because the profiles intentionally differ. The included file-mode baselines are evidence from one development environment, not targets for different hardware or PostgreSQL.

## 0.5.4 baseline evidence

See `docs/benchmarks/0.5.4-file-*.json` for actual minimal file-mode runs. The 1k/10k/50k/100k corpora and HTTP checks were executed (8 clients, 120 requests per read phase, two PHP processes, telemetry enabled). A separate 100k/100-client stress report is intentionally marked failed: all 600 requests returned the expected status/content, but varied-key API p95 reached 5,148.366 ms against the 5,000 ms smoke limit. Do not use that report as a passing baseline. The 1k comparison against the original run passed with a deliberately loose 100% regression allowance; that allowance is not a production acceptance threshold. They are not a SQL, Redis, Caddy, production Apache, Markdown rendering or 24-hour soak qualification. The complete release gate adds full file and real PostgreSQL/Redis benchmark scenarios. Infrastructure results must be collected by running those gates; no SQL timings are manufactured when dependencies are unavailable.

The next performance release should use the SQL profiles to measure whole-scope loading and identity locking before replacing hot-path algorithms. Preserve access-control and publication assertions during optimization.
