# CajeerEngine Book Edition

Self-hosted documentation and knowledge-base platform based on CajeerEngine 1.3.0 (source snapshot `8edfc810c0fa690118bb3a599b7e349e0847420c`). Book 0.1.0 is a separate, focused edition: it reuses the upstream CJR template engine, ServiceContainer and durable filesystem primitives, not the full CMS kernel or its database. Apache-2.0; see LICENSE and NOTICE.

## Features

- Multiple books and versions, hierarchical URL paths, section navigation, page order, previous/next links, headings table of contents, responsive layout and light/dark theme.
- Studio: create/edit/delete pages, draft preview, explicit publishing and unpublishing, version cloning to drafts, optimistic revision checks.
- Safe Markdown subset (headings, paragraphs, lists, emphasis, links, blockquotes, fenced code, copy button). Raw HTML and executable MDX are not supported; links allow absolute HTTP(S), site-root paths and anchors.
- Published-only search and JSON read API, health probes, authenticated JSON backup download.
- Local file persistence with locking and atomic replacement, no SaaS, database, Node, Composer or external assets required at runtime.

## Start locally

Requirements: PHP 8.4.1+ with standard extensions (JSON, ctype, PCRE, sessions); Linux is the tested filesystem environment.

```sh
# Read-only demo (Studio is disabled until configured)
php -S 127.0.0.1:8080 -t public public/router.php
```

Open http://127.0.0.1:8080/ and `/studio`. To enable Studio, generate a password hash without storing the plaintext in the repository:

```sh
read -rs -p 'Admin password: ' BOOK_PASSWORD; echo
export BOOK_ADMIN_HASH="$(printf '%s' "$BOOK_PASSWORD" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')"
unset BOOK_PASSWORD
php -S 127.0.0.1:8080 -t public public/router.php
```

Username: `admin`. The hash is a secret deployment setting. No preset password. Use HTTPS outside localhost; Basic authentication must not be sent over unencrypted public HTTP. Set `BOOK_SECURE_COOKIE=1` under HTTPS. Add reverse proxy rate limiting for `/studio`, and restrict it to a trusted network if possible. Sessions and CSRF protect write requests; there is one administrator, no multi-user roles yet.

## Docker Compose

Create `.env` with the generated hash in single quotes (protects `$` in hashes):

```dotenv
BOOK_ADMIN_HASH='$2y$...replace-with-generated-hash...'
BOOK_SECURE_COOKIE=0
```

```sh
docker compose up -d --build
```

Compose binds to 127.0.0.1:8080. Place an HTTPS reverse proxy in front of it, pass Authorization and Host headers, set `BOOK_SECURE_COOKIE=1` and recreate the container. Use the named volume `book-data` for persistence. PHP's development server is not a production server. Apache container build is provided; production TLS and Docker runtime have not been certified here.

## Content model and REST API

A page is identified by `book/version/path` (lowercase ASCII slugs, nested paths allowed). Each entry has a draft, published snapshot and revision counter. The public reader only reads published snapshots. Studio's Copy version action duplicates published pages as new drafts. Existing page identifiers are immutable; create a new page to change its path. Section and numeric order control navigation.

- `GET /api/v1/pages` — published page metadata keyed by page identifier.
- `GET /api/v1/pages/{book}/{version}/{path}` — published Markdown and metadata.
- `GET /api/v1/search?q=...` — up to 50 published matching pages.
- `GET /health/live`, `GET /health/ready` — JSON health endpoints.
- `GET /studio/export` — authenticated complete JSON backup, including drafts.

Use the Studio UI to author and publish. For direct links use `/docs/{book}/{version}/{path}`. Search is a local case-insensitive substring scan (not a full-text index).

## Persistence and restore

`BOOK_DATA_DIR` defaults to `var/` outside the public document root. First write creates `books.json`; until then seed data is used. The JSON store is limited to 1000 pages, 256 KiB Markdown per page; it is a single-node small-team implementation. Keep the data directory private and writable by the web process. Export from Studio or back up the file while the server is stopped. To restore: stop the server, preserve the existing data, replace `books.json` with a trusted export from the same schema, set owner to the web user and mode 0600, restart and verify pages. Do not expose `var/`, source, or data via a web server.

## Development

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/smoke.php
bash tests/http.sh
```

Architecture: `src/` Book logic, `core/` reused upstream components, `templates/book/` CJR layout, `public/` sole web root, `data/` example content. Source is independent of the CMS release/update pipeline.

## Roadmap (not implemented in 0.1.0)

Full CommonMark/GFM and advanced blocks, rich-text editor, user/team RBAC and private books, revision history, Git sync, media management, OpenAPI interactive reference, static export, PostgreSQL/full-text indexing, locales, custom domains, SSO and production security/load certification.
