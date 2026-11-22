# Fumadocs / Content Collections import (Book 1.0.13)

Book imports a **static source export** of Fumadocs documentation using the existing **Studio → Import documentation** wizard. Import a ZIP or repository directory; choose destination Book, initial version, conflict strategy and **Preview** before **Apply as drafts**. Book creates unpublished drafts, never publishes or enables external access on its own. Original Fumadocs roles, React app, runtime integrations and authentication settings do not carry over.

## Accepted source layout

```text
repository/
  source.config.ts                 # optional marker, never executed
  content/docs/
    meta.json                      # pages/order and optional root settings
    index.mdx
    start.mdx
    start.ru.mdx                   # Russian localized page
    meta.ru.json                   # localized navigation
    v1/
      meta.json                    # {"root":"version", "title":"Version 1"}
      index.mdx
      guide.mdx
  public/
    images/logo.png
```

The importer also recognizes `content-collections.ts` projects. A collection must reside at `content/docs/` (possibly inside a single archive root folder); the importer does **not** execute TypeScript or infer arbitrary custom collection paths from code. For repositories without a root `meta.json`, supply a `source.config.ts` or `content-collections.ts` marker. Only one documentation collection is accepted per import; split a multi-collection project into separate ZIPs. Unsupported project files (including React `.tsx`, generated build output and packages) are not imported as media.

The supported static `meta.json` fields include `title`, `pages`, `root` and navigation group ordering. Page list entries, `...`, `z...a`, `!excluded`, nested folder entries and separators are handled; unspecified pages are still transferred as unpublished drafts, with warnings, to avoid losing source content. External navigation links are not imported as local pages. `root: "version"` folders become Book versions; a `root: true` folder stays a navigational category. File-backed page slugs, `index.mdx` and folder names are translated into Book page routes; grouping folders `(group)` do not contribute to the URL. Never reuse imported URLs without inspecting the preview, especially across versions.

For localization use the Fumadocs dot parser (`guide.ru.mdx`, `meta.ru.json`) or a locale directory (`content/docs/ru/guide.mdx`). Book supports its declared document locales (see `src/Locale.php`); `cn` maps to `zh-Hans`. Localized pages receive locale metadata, separate URLs such as `ru/guide`, and a translation link to their English source when both pages are in the imported bundle. If the source is absent, the localized draft remains independent with a warning. Fumadocs fallback text is **not** fabricated as a translated page.

## Static MDX and media

Static `<Callout type="warn">`, `<Tabs items={["Linux", "Windows"]}>` / `<Tab value="Linux">`, `<Steps>` / `<Step>`, `<Cards>` / `<Card>`, `<Accordions>` / `<Accordion>`, `<Image>` and standard Markdown become editable Book markdown and rich blocks. MDX imports/exports, expressions requiring execution, dynamic `staticParams` routes and props derived from runtime code must be **rendered to static source** before importing. Unknown custom components do not run and may remain escaped with preview warnings. Fenced JSX examples remain code, not live UI.

Supported local media formats include PNG, JPEG, GIF, WebP, PDF, ZIP, TXT and CSV, subject to the existing MediaStore MIME and size restrictions; images in `public/` can be referenced via `/images/logo.png`. External URLs are never downloaded. The importer rewrites recognized local Markdown links and media, including `/docs/v1/...` and `/docs/v2/...`, to Book routes. Review unresolved-link warnings and configure redirects on the original hosting system where necessary.

## Safe deployment and validation

1. Back up the target Book and media volume. Install the patch on **Book 1.0.12**, then deploy to a staging workspace.
2. Upload the Fumadocs ZIP in Studio with authorized import permissions. Review the page list, navigation order, locale, destination versions, warnings and media count in **Preview**.
3. Apply the signed preview while source files and workspace remain unchanged. Check the resulting **drafts** and media; no existing published snapshot is overwritten by the importer.
4. Verify links and roles manually. Publish through the normal Book review workflow. Re-import with **Update** to refresh draft pages only; unchanged revisions are not written.

```bash
php tests/fumadocs-113.php
php tests/fumadocs-113-integration.php
php tests/mintlify-109.php
php tests/readme-112.php
python3 tests/admin-docs.py
```

The autonomous test does not need third-party PHP packages. The full ZIP integration and production CI require Composer dependencies and `ext-zip`, `ext-mbstring` and `ext-fileinfo`. A successful static conversion test alone is not proof of a production deployment. No SQL migration is needed: current PostgreSQL schema **7**.
