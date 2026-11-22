# Documentation chat — 1.0.8

Version 1.0.8 introduces an integrated Reader chat. It uses the server-side **OpenAI Responses API**, with streamed `response.output_text.delta` events, while keeping credentials on the server. Its answer panel displays the validated published-document sources used in the request; the model is also instructed to cite numbered excerpts. Generated answers may be mistaken and should be checked against the linked pages.

## Deployment

1. Back up Book 1.0.7 and your PostgreSQL database, if enabled. Deploy the changed 1.0.8 files to all nodes together.
2. PostgreSQL deployments: stop application nodes/workers and run `php bin/migrations.php preflight --offline`, `php bin/migrations.php apply --offline`, `php bin/migrations.php verify`; the shared schema moves from **6 to 7** and adds `cajeer_book.chat_usage` (installation/scope/day/principal, request count, reserved token budget). File-mode deployments need no SQL migration. A PostgreSQL restore must include the new table to retain quota accounting.
3. Configure the **server** environment, not browser JavaScript:

   ```env
   BOOK_CHAT_ENABLED=1
   BOOK_CHAT_API_KEY=your-server-side-api-key
   BOOK_CHAT_MODEL=gpt-4.1-mini
   BOOK_CHAT_MAX_OUTPUT_TOKENS=700
   BOOK_CHAT_WORKSPACE_DAILY_REQUESTS=500
   BOOK_CHAT_USER_DAILY_REQUESTS=20
   BOOK_CHAT_WORKSPACE_DAILY_TOKENS=8000000
   BOOK_CHAT_USER_DAILY_TOKENS=500000
   BOOK_CHAT_INCLUDE_PRIVATE=0
   ```

4. Start Book normally over HTTPS. Open a published page and ask a question in the Reader chat. Check the incremental answer and the *Documentation sources* section. In a browser, inspect the POST `/api/v1/chat` (or workspace-prefixed equivalent): response `Content-Type: text/event-stream`, with `sources`, `delta`, and `done` events. The API key is never included in the browser request. On a custom domain the route is `/api/v1/chat`.

Chat is **disabled by default** and does not make API calls until a visitor submits a message. The visitor's question, recent conversation history (up to four previous user/assistant exchanges) and up to six relevant published excerpts are sent to OpenAI. History is held only in the active browser page, not in a chat database. Responses API calls set `store=false`. The externally hosted service's applicable data-handling policies still apply. **Never include secrets in questions or documentation submitted to the service.**

Private documentation **cannot** be passed to the chat model by default, even if a signed-in member can read it. `BOOK_CHAT_INCLUDE_PRIVATE=1` permits authorized private pages to be used as context; enable only when the deployment explicitly accepts this disclosure. The actual published-page and workspace permissions are checked on every turn; drafts and unpublished pages are excluded.

## Sources, latency and accuracy

The active page is placed first. The existing hybrid/lexical search finds additional relevant pages; the server retrieves them again through the Reader's authorized published-page interface. Each excerpt is truncated and a maximum 18 KiB is sent across all sources. Sources are server-generated relative links, not model-provided hyperlinks. The UI uses text nodes to render generated text, not untrusted HTML or Markdown. When the embeddings backend is unavailable, the existing lexical fallback remains available.

Upstream API requests use a fixed `https://api.openai.com/v1/responses` endpoint, enforce TLS, disable redirects and cap the connection, response body, and answer size. The optional loopback API fixture route exists only with `BOOK_CHAT_TEST_LOOPBACK=1`, for local automated tests; never enable it in production. Responses are streamed and cancelled if the visitor disconnects. The server emits a generic error event if an upstream stream is interrupted.

## Limits and retention

The server **reserves** a conservative maximum input+output budget *before* search and OpenAI calls, so retries, transport failures, and search misses can consume daily quota. This prevents bypassing the quota with interrupted requests. The configured daily limits apply independently to a Workspace aggregate and to a visitor. Signed-in visitors are keyed by user ID; anonymous visitors are keyed using a server-side HMAC of their connection IP. Raw IPs, prompts and generated answers are not stored in the quota ledger. The HMAC key is derived from `BOOK_CHAT_API_KEY`; changing the API key within a day resets per-anonymous-visitor accounting. Keep the same API key across replicas.

File deployments write a lock and daily JSON counters under `BOOK_DATA_DIR/chat-quota/`. Protect this directory and include it in operational backups; prune older day files according to your retention policy. PostgreSQL deployments store quotas in `cajeer_book.chat_usage`; enforce retention, for example via an operator-scheduled task after confirming backup policy:

```sql
DELETE FROM cajeer_book.chat_usage WHERE day < CURRENT_DATE - 35;
```

No nightly purge is installed automatically. Browser conversations disappear on page reload. If multiple application nodes use file mode, they **must** share the same reliable filesystem for quota enforcement; PostgreSQL mode is recommended for multi-node deployments.

## Test coverage and limitations

Run `php tests/chat-108.php`, `php tests/chat-108-access.php`, `python3 tests/chat-108-http.py`, `php tests/migrations-policy.php`, and the rest of the Composer test suite (`composer test`). The chat test runs against a local HTTP fixture that verifies request shape, streaming chunks, upstream failure and authorization, quotas, and unsafe input rejection. PostgreSQL table migration, permissions and multi-node traffic must additionally be checked on a deployed disposable instance. An end-to-end call to a genuine OpenAI account is not performed by the automated fixture. No actual API key is included in the repository.
