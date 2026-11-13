# Changelog

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
