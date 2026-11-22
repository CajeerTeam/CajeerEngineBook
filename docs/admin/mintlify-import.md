# Mintlify (docs.json / mint.json) import — Book 1.0.9

## Import an existing documentation repository

1. Back up the installation and open **Studio → Import documentation** as a workspace editor or administrator. The current book content and its published snapshots remain untouched until the explicit *Apply as drafts* step; importing never publishes pages.
2. Upload a ZIP containing `docs.json` (modern Mintlify) or `mint.json` (legacy Mintlify), `.md`/`.mdx` pages and local images. A single enclosing repository folder is supported; the configuration must be in the effective root. Alternatively use a directory upload supported by the browser.
3. Select Book slug and version, then the `skip`, `update drafts` or `rename` collision strategy. Preview all destination keys, conversion warnings and imported assets before applying. Apply requires a new CSRF-protected, 15-minute preview token bound to the source byte hashes and current workspace content. Any changed input or concurrent content change requires a new preview.
4. Review imported drafts in Studio, fix unresolved references, then publish via the normal approval/publish workflow. Imported redirects become active for published pages only. Page visibility and workspace permissions are unchanged.

## Supported conversion

- **Navigation:** Both `mint.json` legacy `navigation` groups and the nested `docs.json` hierarchy (`pages`, `groups`, `tabs`, `anchors`, `dropdowns`, `languages`, `versions`, `products`, `menu`, `root`) are traversed; pages become ordered Book drafts with group labels in their sections. Hierarchical tabs and branding cannot be represented as identical Book UI layouts, so they are flattened to section labels and order. Pages not listed in navigation remain importable. Unresolved navigation references are reported.
- **Pages:** `.md`, `.markdown` and `.mdx` are imported. Basic Mintlify YAML front matter (`title`, `sidebarTitle`, `description`, `slug`, `order`, `section`, `locale`) is mapped to existing Book metadata. Other metadata is not executed. Existing Book drafts and public snapshots follow the selected conflict strategy.
- **Components:** Static `Note`, `Info`, `Tip`, `Warning`, `Danger`, `Check`, `Success`, `Tabs`/`Tab`, `Steps`/`Step`, `AccordionGroup`/`Accordion`, `CardGroup`/`Card` and `CodeGroup` are converted to validated native Book rich blocks. `Frame` is unwrapped; static `<img>` and `<a>` become standard Markdown. Nested component limits and Book's per-block constraints are enforced.
- **Images and files:** References to local PNG, JPEG, GIF, WebP, PDF, ZIP, TXT, CSV are rewritten to immutable `/media/{id}` links. Files stored under `public/images/` also resolve from `/images/` references. Media is deduplicated by hash and MIME. No external URLs are fetched. SVG (which may contain scripts), custom JSX components, compiled JS/CSS, fonts, YAML specs and unsupported file types are not imported as executable assets and are reported or retained as inert source.
- **Links and URL mapping:** Local Markdown and extensionless Mintlify routes are rewritten to `/docs/{book}/{version}/{page}`; anchors and query suffixes are preserved. `redirects` with a concrete local source and destination are recorded against a stable imported page ID; they resolve only after publication. Already-reserved or conflicting aliases are reported instead of overwriting another page.

## Limits and unsupported constructs

The normal import limits apply: up to **200 files**, **64 MiB expanded**, **20 MiB per file**, and **256 KiB per Markdown/MDX page** (plus existing database/media limits). For larger repositories split the bundle into multiple imports, using the same book/version and reviewing URL conflicts.

The importer **never evaluates JavaScript or JSX**. Dynamic MDX `import`/`export` and dynamic expressions require conversion to static Markdown first and are rejected. Unsupported uppercase custom components are left as escaped, inert Markdown/HTML source and flagged in the preview, not silently executed or rendered as React widgets. Mintlify layout/theme settings, live OpenAPI operations, global CSS/JS, remote images, version/language dropdowns and external `$ref` configuration files are not automatically migrated. Flattening navigation can change the original browsing experience; check the resulting Reader tree before publishing.

A preview is not a substitute for reviewing generated pages, media references and redirects in the target application. Access to the Studio import route is governed by existing workspace edit permissions; imported pages stay drafts and no publication or visibility grants are made automatically.

## Verification

Run `php tests/mintlify-109.php` for the parser and link-conversion policy tests, `php tests/import.php` and `python3 tests/import-http.py` with Composer dependencies and PHP ZIP extension installed, then test a real exported Mintlify repository on staging. Verify that existing public pages are unchanged after import, media is accessible only with appropriate book visibility, and configured URL aliases begin working only after publishing. PostgreSQL mode uses the existing BookStore transactions; **no schema migration** is needed in 1.0.9 (schema remains 7).
