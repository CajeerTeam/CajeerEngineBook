# CajeerEngine Book Edition

Self-hosted documentation and knowledge-base platform based on CajeerEngine 1.3.0 (source snapshot `8edfc810c0fa690118bb3a599b7e349e0847420c`). Book 0.1.2 is a separate, focused edition: it reuses the upstream CJR template engine, ServiceContainer and durable filesystem primitives, not the full CMS kernel or its database. Apache-2.0; see LICENSE and NOTICE.

## Features

- Multiple books and versions, hierarchical URL paths, section navigation, page order, previous/next links, headings table of contents, responsive layout and light/dark theme.
- Studio: visual block editing, Markdown source mode, server preview, autosave, create/edit/delete pages, explicit publishing and unpublishing, version cloning to drafts, optimistic revision checks.
- CommonMark 0.31.1 and GitHub-Flavored Markdown (GFM), powered by League CommonMark: nested lists, ATX/Setext headings, reference links, inline/fenced/indented code, blockquotes, tables, task lists, strikethrough, autolinks and images. Shared rendering in Reader and Studio with stable heading slugs and legacy anchors.
- Published-only search and JSON read API, health probes, authenticated JSON backup download.
- Local file persistence with locking and atomic replacement, no SaaS, database, Node, CDN or external API required at runtime. Composer installs PHP dependencies at build/install time.

## Start locally

Requirements: PHP 8.4.1+ with `mbstring`, JSON, ctype, PCRE and sessions; Composer 2 for dependency installation. Linux is the supported filesystem environment.

```sh
composer install --no-dev --prefer-dist --no-interaction
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

## Upgrade to 0.1.2

Back up `BOOK_DATA_DIR`, including `books.json`. Overlay the release files in the existing project directory (do not replace or delete `var/` or the persistent Docker volume). Install the locked dependencies using `composer install --no-dev --prefer-dist --no-interaction`, or rebuild the Docker image with `docker compose up -d --build`. The JSON schema remains version 1; existing draft/published Markdown is not rewritten, and no database migration is required. Recheck the public reader, Studio and health endpoint after the update.

## Studio visual editor (0.1.2)

Open `/studio` and authenticate. Visual mode is enabled by default when JavaScript and the browser editing APIs are available; Markdown remains usable without JavaScript or if conversion fails. All assets are served locally, and there is no npm/CDN dependency.

- **Visual:** click a text block and type directly. Select text to apply bold, italic, strike, inline code, links or remove links. Use Block style for paragraphs, H1–H6 and quotations. Bullets/numbered lists, list indent/outdent, undo/redo and native editing shortcuts are supported. Add a paragraph, heading, quotation, list, divider or language-labelled code block using Add block. Remove a supported block with confirmation. Pasting inserts plain text rather than untrusted HTML.
- **Markdown:** edit the canonical source. Switch from Visual to Markdown to inspect the generated source, or use Copy Markdown. The server still stores Markdown, not HTML or an editor-specific JSON format.
- **Preview:** renders the current unsaved document on the server with the same CommonMark/GFM engine and URL policy as Reader. Existing page paths resolve relative links and images against their published URL. No publishing is required to preview.
- **Save:** Save draft and Publish commit through the existing revision-checked store. Existing drafts autosave after approximately 1.8 seconds of idle editing; a new page needs an explicit first save or publication. Autosave never changes the published snapshot. Ctrl/Cmd+S saves the draft. A revision conflict stops writes, keeps the local text in Studio and asks the author to copy Markdown and reload/merge. Failed requests do not discard unsaved content. Refresh or leave Studio only after saving; the browser warns about unsaved changes.

Visual editing is intentionally source-preserving, not a wholesale HTML-to-Markdown rewrite: unchanged blocks and gaps (including reference definitions) retain their source. Modified supported blocks are serialized to canonical Markdown and separated safely; a modified reference link may become an equivalent inline link. Browsers normalize textarea line endings to LF. Existing tables, task lists, images, raw HTML, custom directives, nested code blocks and other unsupported AST structures remain visible but protected in Visual mode; edit them in Markdown mode. Visual table editing and the media library are planned for subsequent releases. The editor uses native contenteditable/selection and browser editing commands; Chromium is the browser tested in CI. Markdown mode is the reliable fallback for unsupported browser commands or complex formatting. No new database migration is required.

## Markdown authoring and rendering

The application uses [League CommonMark](https://commonmark.thephpleague.com/) with the CommonMark core and GFM extensions. Markdown source stays unchanged in the store and API. Reader and Studio share the parser, and the preview resolves relative links and images against the document's public URL, not the Studio route. The following works in Markdown mode and in Reader:

````markdown
## Installation

1. Install PHP and dependencies.
   - Enable `mbstring`.
2. Start the server.

- [x] Markdown engine
- [ ] Visual editor

| Package | Version |
| --- | ---: |
| PHP | 8.4 |

See [setup](getting-started#run-locally), https://example.org or ~~old~~ **new**.

![Diagram](/assets/example.png "Example title")

```sh
composer install --no-dev
```
````

Heading IDs use lowercase Unicode text with runs of punctuation/whitespace replaced by hyphens; duplicates gain `-2`, `-3`, etc. Empty titles and reserved IDs receive a safe alternative. Existing `#section-N` links from 0.1.0 remain aliases for their original ATX headings. These legacy numbers follow the old parser's counter, including its handling of triple-backtick fences. Headings newly recognized by CommonMark, such as Setext headings, do not consume legacy numbers. Slugs are stable across renders and unrelated heading edits; editing a heading or reordering identical headings can change its slug. Use the TOC link for the actual anchor. GFM itself does not specify heading IDs.

Security policy: all raw HTML is escaped (including HTML otherwise allowed by GFM), executable MDX is disabled, unsafe URL schemes and protocol-relative URLs are rejected, `mailto:` is allowed only for links, and HTTP(S)/same-origin paths are allowed for links and images. Unsafe images render as plain alt text; unsafe links are inert. Data URLs are disabled. External images are fetched by the reader's browser, not the PHP server; they may disclose the reader's IP address to the image host, so use trusted or local images. HTTPS pages may block insecure HTTP images. No upload manager is included in this release.

The parser limits Markdown to valid UTF-8 and 256 KiB, 64 levels of block nesting, 1000 delimiters per line and 10000 auto-completed table cells. These deliberate safety policies and heading attributes differ from unrestricted specification HTML examples. GFM means the standardized extensions (not all GitHub website features); footnotes, alerts/callouts, tabs, cards and Mermaid rendering remain later releases. Code fences for `mermaid` render as code for now.

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
composer install --prefer-dist --no-interaction
composer validate --strict
composer audit --locked
find src core data public tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -l bootstrap.php
composer test
# Browser E2E (requires Python 3 and Chromium):
python -m pip install playwright==1.57.0
python -m playwright install chromium
python tests/editor-browser.py
```

Architecture: `src/` Book logic, `core/` reused upstream components, `templates/book/` CJR layout, `public/` sole web root, `data/` example content. Source is independent of the CMS release/update pipeline.

## Roadmap (not implemented in 0.1.2)

Visual tables and images, media library, page tree drag-and-drop, callouts/cards/tabs/Mermaid, user/team RBAC and private books, revision history, Git sync, media management, OpenAPI interactive reference, static export, PostgreSQL/full-text indexing, locales, custom domains, SSO and production security/load certification.
