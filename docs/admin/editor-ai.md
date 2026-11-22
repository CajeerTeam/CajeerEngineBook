# Editor AI Assistant (1.0.14)

The editor assistant is part of the existing Studio, not a new content store. It supports three active workflows: documentation coverage analysis, migration/import error explanations, and proposed Markdown corrections submitted to the existing independent review process.

## Configuration

Set the following **server-side** environment variables and restart PHP workers:

```bash
BOOK_EDITOR_AI_ENABLED=1
BOOK_CHAT_API_KEY=your-server-side-openai-key
BOOK_CHAT_MODEL=gpt-4.1-mini
```

Use `BOOK_EDITOR_AI_ENABLED=0` (default) to disable requests. The assistant reuses the existing Responses API client (streaming response consumption, `store=false`, verified TLS, no redirects, fixed OpenAI HTTPS host) and the existing server-side chat quota ledger (schema **7** for PostgreSQL, private atomic files in portable mode). Each AI request pre-reserves a conservative charge to the current Workspace and signed-in editor; `BOOK_CHAT_*_DAILY_*` limits cover both Reader chat and editor assistance. Requests may consume quota even when upstream fails. Production must **not** enable `BOOK_CHAT_TEST_LOOPBACK=1`.

No new database migration is required from 1.0.13, and the core remains CajeerEngine 2.1.0. No API key or prompt is persisted in BookStore, review records or the activity journal. Provider-side retention policies are external to Book.

## Editor workflow

1. Sign in as an **Editor, Admin or Owner**, open **Studio → AI Assistant** (or `.../studio/assistant` inside a Workspace).
2. **Coverage analysis** scans the selected book/version, reports concrete issues (unpublished pages, short pages, missing headings, unresolved TODOs, pending drafts), then asks OpenAI to prioritize probable missing prerequisites, inconsistencies and upgrade/rollback instructions using a limited number of page excerpts. A report is advisory; it does not modify anything. Coverage is bounded to the first 20 sampled pages and 60 flagged records; the rest must be checked separately.
3. **Migration/import diagnosis** accepts up to 4,000 bytes of a pasted error. Remove credentials, internal addresses and personal data before submitting. The assistant provides possible causes and read-only verification / recovery steps; it does not run SQL, import commands, or backups. The server also redacts common `password=`, `token=`, bearer and PostgreSQL URI credential patterns, which is not a comprehensive DLP filter.
4. **Page correction** accepts an existing page and editor instruction. The current draft (up to 14 KiB) is sent to OpenAI. The UI shows a diff and the exact proposed Markdown. No record is changed during generation. Review all output, including URLs, attachments, code examples, and security-sensitive instructions.
5. Choose a different active **Admin/Owner**. **Save and submit to review** commits the replacement draft and creates its immutable review snapshot within the *same* BookStore transaction. It fails without changing the page if the reviewer is ineligible, a review is already open, the draft was edited, or the preview is stale.
6. The reviewer uses **Studio → Reviews** to approve/request changes/reject. Publication occurs **only** through an independently approved review. A page with an AI-assisted pending revision cannot be directly published, even when the normal Workspace review policy is optional. Publishing through CI is also denied while that flag is set. A successful reviewed publication clears the flag.

The signed proposal expires after 10 minutes, is bound to the current editor and session, and cannot be reused after the page revision advances. Any later edits invalidate an existing review and require a fresh submission. No automatic publication or background edit generation is performed.

## Data disclosure, authorization and deployment limits

- AI assistance is explicitly opt-in because **unpublished drafts and pasted migration errors can be sent to OpenAI**. Do not enable it if private source content may not leave your infrastructure. There is no per-Workspace provider override in 1.0.14; operators decide whether to enable it for the installation.
- Workspace sessions and roles are checked before reading content or invoking OpenAI. Requests for other Workspaces are not accepted, and browser POSTs require both session CSRF and same-origin protections.
- Long OpenAI requests run **outside** the identity registry lock; draft publication and review submission still use BookStore's atomic transactions and revision guards.
- Model input/output is size-limited and model output is HTML-escaped in Studio; executable Markdown/JS is never evaluated by the assistant itself. Output can nevertheless be wrong and must be reviewed by a human.
- Live provider, PostgreSQL/Redis, multi-node and browser integration tests require the deployment's own credentials and services. CI includes a deterministic local mock transport and a review-workflow regression. Do not treat a passing mock as an OpenAI production acceptance test.

## Verification

```bash
php tests/editor-ai-114.php
python3 tests/editor-ai-114-http.py
php tests/chat-108.php
php tests/reviews.php
python3 tests/admin-docs.py
```

A fully installed Composer `vendor/` directory is required to exercise real Markdown parsing and the full HTTP/UI suite. For production staging, verify that a protected Workspace refuses an unauthorized editor, a stale proposal cannot be submitted, an independent reviewer can publish an approved revision and a reverted/failed model request never changes the public page.
