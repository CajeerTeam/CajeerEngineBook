# ReadMe.com migration — Book 1.0.12

Use **Studio → Import documentation** with a ReadMe ZIP export, or a zipped Git Sync repository. This is a one-time content migration, **not** a ReadMe API synchronizer and **not** two-way Git Sync. Book's existing Studio permissions, signed preview token, media validation, atomic import, revision history and conflict strategy remain in force. Everything imported is an **unpublished draft**, including content that was visible in ReadMe. Existing Book publications remain unchanged until a separate authorized publication.

## Source formats

ReadMe Git Sync or an exported ZIP ordinarily contains:

```text
repo/
  docs/                     # Guides
    _order.yaml              # Page/category order
    getting-started.md
    advanced/
      index.md
      _order.yaml
      page.mdx
  reference/                # API Reference
    _order.yaml
    birds.json              # OpenAPI 3.x, if provided
    birds/
      list-birds.md         # May contain api.file + api.operationId
  recipes/                  # Recipe Markdown/MDX
    _order.yaml
    tutorial.md
```

For a **single ReadMe branch**, enter the desired Book version in Studio. Exported `branches/v1/docs/...`, `versions/v2/docs/...` (or several version-named sibling directories) can be imported together, creating separate versions. The supplied Book slug remains the destination Book. A loose set of `.md` files without ReadMe section markers continues to use Book's ordinary Markdown import; other platform manifests still use their own importers.

## Steps

1. Back up the destination Book and its media. Export **each ReadMe version/branch you need**. ReadMe's ZIP export contains Markdown and bundled OpenAPI files, but normally **does not include the image binaries**; obtain those separately if they must be hosted locally.
2. Open **Studio → Import documentation**. Select ZIP or Directory, enter a Book slug, the fallback version and conflict strategy (**Skip**, **Update drafts**, or **Rename**).
3. Select **Preview import**. Check version/page mapping, unresolved `api.file` references, sidebar order, external images, and skipped components. Do not apply until all warnings have been reviewed.
4. Select **Apply as drafts**, then review pages, API endpoints, code examples, links, imported media and access roles. Publish through Book's normal review workflow. Confirm public URLs and deploy separate redirects on the old ReadMe domain as needed.
5. To host a referenced image inside Book, include the actual PNG/JPEG/GIF/WebP asset inside the uploaded ZIP at the original relative path (e.g. `images/logo.png`). The importer applies Book's existing MIME and size checks and rewrites supported local Markdown links. Remote `https://files.readme.io/...` URLs are **not** downloaded.

## What is transferred

- **Guides / Recipes / API Reference:** authored Markdown and MDX, selected frontmatter (`title`, `excerpt`, `slug`, `deprecated`) and nested `_order.yaml` order/category names. A ReadMe hidden page is imported **only as a draft**; original privacy rules are not replicated.
- **OpenAPI:** bundled OpenAPI 3.0/3.1 JSON/YAML, including local references found in the bundle, is validated by the same parser used by Book's OpenAPI Studio and generates ordinary editable API overview, operation, and schema draft pages. When the authored API Reference page declares `api.file` and `api.operationId`, the imported page links to its corresponding generated operation. The import does not enable live Try It! calls or register the original spec in Book's managed OpenAPI revision store; use the separate Studio OpenAPI workflow if you need managed specs, sync and playground history.
- **ReadMe formatting:** static `<Callout>`, Markdown admonition-style callouts, supported tabs/steps/cards/accordions are converted to Book rich blocks when representable; fenced code remains literal. Executable MDX/JSX is rejected rather than run. Unsupported ReadMe-specific custom components remain inert text/escaped HTML and are flagged.
- **Media:** only local files actually present in the upload with supported types PNG, JPEG, GIF, WebP, PDF, ZIP, TXT or CSV. Remote image links remain external and trigger warnings.

## Format limitations

ReadMe projects can have **custom blocks, custom pages, changelogs, image repositories, project settings, redirects, access policies and reusable content** that are not equivalent to a Guide/Recipe/OpenAPI page. This importer explicitly skips custom blocks/pages and changelogs with warnings. It does not migrate interactive API Explorer credentials, analytics, theme, domain, user accounts, private page ACLs, dynamic components, complex recipe layout, or platform-hosted images. Set up Book roles and any URL redirects independently. External/remote `$ref` specifications are **not fetched**; bundle local dependencies before importing. Unsupported OpenAPI documents (including Swagger 2.0) must be converted to OpenAPI 3.x separately. Dynamic JSX and JavaScript are never executed.

**Limits:** 200 source + generated files per import, 20 MiB ZIP, 64 MiB expanded, up to 256 KiB Markdown per page and 1 MiB of OpenAPI source per specification. Book-wide quotas and path restrictions also apply. Split large exports into smaller batches. The Preview token is valid for 15 minutes and binds the source bytes to the Book snapshot; re-preview after changing files or Workspace data. Import only into a staging Workspace first.

## Validation

```bash
php tests/readme-112.php
php tests/readme-112-integration.php
php tests/mintlify-109.php
php tests/docusaurus-110.php
php tests/gitbook-111.php
python3 tests/admin-docs.py
```

`readme-112.php` is an autonomous converter/policy test. The complete ZIP `preview → apply → repeat` test requires Composer dependencies and PHP extensions (`zip`, `mbstring`, `fileinfo`, etc.) as declared in `composer.json`; it runs in the Composer test suite. No PostgreSQL migration is required: schema remains **7**. Before production use, run full CI and verify backups, staging publication, roles, and infrastructure services.
