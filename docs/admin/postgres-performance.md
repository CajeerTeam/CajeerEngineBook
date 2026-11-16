# PostgreSQL performance — 0.6.0

## Upgrade from 0.5.4

Stop **all** application, worker, domain-check and backup processes using this database (including other installation namespaces). Take and verify a matched backup with 0.5.4, including PostgreSQL and media/binding files. Replace changed source files, rebuild deployment images if applicable, and keep the existing database and namespace. Run with the existing installation environment:

```sh
php bin/migrations.php plan
php bin/migrations.php preflight
php bin/migrations.php apply --offline
php bin/migrations.php verify
php bin/postgres-content.php check
php bin/search.php check
php bin/audit.php check
```

Run `php bin/runtime.php check` if Redis is configured. Restart only after verification succeeds. Migration 0003 builds three partial indexes on `cajeer_book.pages`: published key, stable ID, and published media references. Its immutable `reader_json(json)` helper supports valid legacy JSON containing escaped NUL; the original stored JSON is never rewritten. Runtime requires schema 3. Preflight actually builds the indexes in a transaction and rolls it back; budget disk, WAL and maintenance time for both builds. No full-text reindex or data import is required. File installations need no migration.

Rollback: stop all processes, `php bin/migrations.php rollback 2 --offline`, verify status, and restore the matching 0.5.4 application files. This removes only the indexes/helper, not content. 0.6.0 must not serve schema 2. Use the matched pre-upgrade backup if rollback fails. Reapply migration 0003 to resume 0.6.0.

## Read paths and limits

- `/api/v1/pages/<key>` reads one visible published snapshot by indexed published key. Redirects resolve by stable ID (legacy derived IDs have a scope-bounded fallback). No sibling bodies, drafts, history, review or CI records are materialized in PHP.
- Reader, language redirects, home, sitemap and page list read published navigation/list metadata; Reader additionally reads the selected body and OpenAPI source metadata where needed. List API preserves existing metadata. Rare records containing escaped U+0000 or the corresponding literal sequence are decoded from original JSON to preserve exact bytes.
- Media lookup tests published references in SQL with a GIN index and opens the authorized immutable blob before releasing the shared content lock. Existing book/workspace privacy and explicit member-role semantics remain in force. Anonymous identity lookup reads the selected registry skeleton under the existing shared identity lock. Authenticated requests retain the full identity/RBAC transaction.
- Bulk record/search changes use batches of up to 64 records and a 2 MiB target payload (a single larger existing record is allowed). Deletes are grouped into 128 keys. Original transaction and search revision update remain atomic. Full mutations, deep readiness, backup, audit and SDK/Playground still use the existing full-state validation paths. Navigation still enumerates published metadata within a workspace. No quota increase or PgBouncer transaction-pooling support is claimed; current advisory locks require session semantics.

## Benchmark and qualification

Use a disposable test database and Redis as described in [performance.md](performance.md). On the actual infrastructure run:

```sh
python3 tests/performance-benchmark.py --postgres --redis --full --telemetry --maintenance --pages 1000 --per-workspace 500 --requests 120 --concurrency 8 --nodes 2 --report-dir /tmp/book060-sql
```

The report includes `optimized_read_plans` from the actual application methods (page, navigation, media), with EXPLAIN ANALYZE/BUFFERS and serialized result bytes, in addition to the prior full-scope and search plans. Compare against an identical 0.5.4 SQL run, not file-mode results. Run the PostgreSQL release gate and larger 10k/50k/100k corpus profiles before claiming the roadmap latency targets. Existing file-mode benchmark results are not PostgreSQL production evidence.

`php tests/postgres-reader.php` exercises portable compatibility; `php tests/postgres-reader.php --postgres` uses a disposable namespace in the configured test database and tests real optimized SQL paths, exact metadata, NUL preservation, media privacy, indexed path lookup, batched search, and revocation. The migration gate also tests populated schema 2 → 3 → 2. Both are in the release runner; production deployment must pass them on PostgreSQL 16+.
