# Changelog

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
