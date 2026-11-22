# Semantic search and indexing — Book 1.0.7

Search combines the existing published-document lexical engine (portable full-text search or PostgreSQL FTS) with cosine similarity of **actual model-generated embeddings**. It does not require an embedding service to keep lexical search working. It never uses a draft, and it authorizes vector candidates before returning any hit. The same Workspace selection, role, book visibility, language, book and version filters apply to both ranking paths.

## Runtime configuration

Set the following on the **application and worker**, in the same private runtime environment used for other Book secrets:

| Variable | Meaning |
| --- | --- |
| `BOOK_EMBEDDING_URL` | Full URL of an explicitly trusted, OpenAI-compatible embeddings endpoint (`POST`, JSON with `model` and `input`). Empty/absent disables embeddings. Must use HTTPS, or HTTP loopback for a local service. Redirects and insecure remote HTTP are rejected. |
| `BOOK_EMBEDDING_MODEL` | Model ID used by the provider, e.g. `text-embedding-3-small`, or your compatible locally hosted model name. Required when enabled. Changing the model requeues all cached vectors. |
| `BOOK_EMBEDDING_API_KEY` | Optional provider bearer token; store outside repository and backups that are not encrypted. |

No analytics consent is involved: embeddings are server-to-server data processing initiated by the operator, not visitor tracking. **All published documentation, including private books and private Workspaces, may be sent to the configured embedding provider.** Only set the URL after reviewing your provider's data retention, geographic processing and security policy. A local embedding server under your control is preferable for confidential material. Never put credentials in a browser/Workspace setting.

The provider must respond with `data: [{index: 0, embedding: [number, ...]}, ...]`. It must return finite, nonzero vectors of consistent dimensions (2–4096). One request processes at most 16 documents with an eight-second timeout; only the first 6 KiB of each rendered published document (title, section, then body) are submitted. Very long pages continue to be fully searched by the existing lexical engine; semantic recall for their later sections is limited. The whole input is hashed, and a changed title, section, or indexed body invalidates the old vector. Changing model **and/or dimensions** requires using a different model ID.

## Upgrade and backfill

1. Back up the installation, keys, media and SQL data; stop all application/worker replicas before an SQL schema change.
2. Deploy exactly the 1.0.7 files to **all** replicas. For PostgreSQL run `php bin/migrations.php preflight --offline`, then `php bin/migrations.php apply --offline` and `php bin/migrations.php verify` before restarting. The schema moves from 5 to 6; no in-place migration of old release binaries.
3. Configure the embedding service securely on all replicas and workers. Use matching model IDs and API credentials; confirm HTTPS endpoint connectivity from the actual runtime environment.
4. Run `php bin/search.php embeddings --offline` to build all published vectors across all registered Workspaces. It exits nonzero if a provider fails; retry after resolving the issue. The existing `php bin/search.php reindex --offline` remains PostgreSQL FTS rebuild and removes derived vectors, so run the embeddings backfill **after** it.
5. Run `php bin/worker.php --loop` to refresh pending vectors during normal operation. Each successful publish triggers a bounded immediate refresh outside the content transaction, with failures recorded as `search.indexing_pending`; the worker retries. File mode uses an atomic `search-embeddings.json` sidecar under the protected Workspace directory, never under `public/`. PostgreSQL uses derived `cajeer_book.search_embedding` rows constrained by the corresponding published search document.
6. Verify as an authorized member and a guest: semantic-only matching, private book isolation, publishing an edit, unpublish/delete, Workspace visibility and explicit role denial. Run `php tests/search-107.php`, `php tests/search-policy.php` and `php tests/migrations-policy.php` from the corresponding source checkout.

## Failure modes and recovery

- **Provider unavailable / no vectors:** query or indexing failures do not prevent publication. Reader search falls back to lexical ranking and never displays stale semantic candidates.
- **Content changed while inference is running:** compare-and-swap checks the currently published representation before storing any newly generated vector. In PostgreSQL, page rows are locked while committing; deleting or unpublishing cascades to embeddings. On file storage, every candidate is rechecked against the current source digest before use.
- **Model ID changed:** file vectors with other model IDs are ignored; PostgreSQL vectors with another model ID are pending for replacement.
- **Backup/restore:** vector data is derived and can be regenerated after restoring content. Do not treat the sidecar as the source of truth. Existing portable backups do not need to include the sidecar. After a PostgreSQL restore, run the index status and reindex procedures as appropriate.
- **Security:** never permit visitors to supply the provider URL or its credentials; do not accept direct embedding vectors from clients. The database uses installation and Workspace scope keys; retrieval applies authorization before cosine ranking. Rotating OIDC or permission roles does not require reembedding, because permission checks occur at query time.

The hybrid path uses a bounded 1,000-page per-Workspace search projection, 100 top semantic candidates and reciprocal-rank fusion. It is intentionally not a distributed ANN/vector-database implementation. Higher-scale installations need dedicated capacity and latency qualification before production exposure.
