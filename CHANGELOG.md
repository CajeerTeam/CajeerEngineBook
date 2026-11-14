# Changelog

## 0.1.4 — Media Library

- Add the authenticated Studio media library with multi-file upload, drag-and-drop, progress and partial failure reporting; search, folder/type filters, sorting, pagination, rename, move, download and delete.
- Store immutable private blobs with SHA-256 deduplication, file/MIME and image validation, bounded thumbnails, quotas and stable IDs. Media metadata and page references commit under the existing BookStore lock.
- Record canonical `/media/{id}` links and images from the CommonMark AST; Studio previews use authenticated URLs and Reader access requires a published reference. Protect referenced files from deletion and reject stale metadata revisions.
- Add complete consistent TAR backup, offline verified restore to a new directory, and explicit orphan cleanup. Keep the legacy JSON export as metadata-only.
- Add Docker GD/upload settings, PHP and HTTP/browser regression tests. Existing schema-1 data remains compatible; no destructive migration.

## 0.1.3 — Tables & Rich Text

- Edit existing and new GFM tables visually: cell-level formatting, add/remove and move body rows and columns, column alignment, keyboard navigation and source-preserving serialization.
- Paste spreadsheet TSV directly into cells or import quoted CSV/TSV with a preview-independent grid insertion flow. Grow tables within explicit limits; preserve surplus-cell and unsupported tables in Markdown mode.
- Edit GFM task lists and toggle completion in Studio; published Reader checkboxes remain read-only. Keep nested lists, inline code, links and existing rich formatting.
- Add safe local lexical highlighting for PHP, JavaScript/TypeScript, Python, Bash, SQL and JSON in Reader and live Preview, with copy-code controls.
- Extend server and browser regression tests. No runtime dependencies or data migration added.

## 0.1.2 — WYSIWYG Editor

- Add a real local Visual editor with editable text blocks, headings, lists, quotations, inline marks, links, code blocks, block insertion/removal, undo/redo and Markdown mode.
- Use CommonMark AST source ranges to preserve unchanged Markdown, reference definitions and unsupported GFM blocks; keep protected blocks editable in source mode.
- Add server-rendered unsaved Preview using the Reader renderer, CSRF-protected rendering and AJAX draft/publish requests with committed revisions.
- Add debounced autosave for existing drafts, dirty state, copy Markdown, and conflict handling that retains local edits and prevents stale writes.
- Add responsive Studio styles, editor PHP/HTTP/browser regression tests and Chromium CI. No npm dependency, no storage schema migration.

Scope: visual tables/images/task lists and advanced blocks remain later releases; Markdown remains fully supported.

## 0.1.1 — Markdown Engine

- Replaced the custom limited Markdown parser with League CommonMark 2.10.7 and GFM extensions. Core CommonMark syntax, tables, task lists, autolinks and strikethrough render through the same AST pipeline in Reader and Studio.
- Added deterministic Unicode heading slugs, duplicate handling, plain-text hierarchical TOC and aliases for 0.1.0 section-N bookmarks.
- Resolve relative Markdown links and images against the Reader page URL in both Reader and Studio; keep the published source unchanged in the JSON API.
- Escape raw HTML, reject unsafe schemes and protocol-relative destinations, disable data URLs, enforce document-size and parser complexity limits, and add browser-safe image attributes.
- Render responsive table containers and images; preserve the existing fenced-code language metadata and copy buttons.
- Add locked Composer dependencies, PHP mbstring, Docker dependency installation and CI dependency/security checks. Add Markdown unit, policy and HTTP regression coverage.
- No schema migration. Upgrade by overlaying the changed files and installing dependencies or rebuilding Docker. Back up existing data first.

Known scope: Markdown textarea editing remains. WYSIWYG, media upload/library, rich blocks and Mermaid are not part of 0.1.1. Full runtime and container validation must be performed with Composer dependencies installed.

## 0.1.0

Initial self-hosted Book Reader and Studio, draft/publish flow, local JSON storage, search, versions and published-page API.
