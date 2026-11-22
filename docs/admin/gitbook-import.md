# GitBook Spaces migration — Book 1.0.11

Book migrates **GitBook Spaces** into Books, their document pages into unpublished drafts, `SUMMARY.md` sidebars into Book navigation order/sections, and supported attachments into immutable media. This is a one-way migration; it does not enable ongoing two-way GitBook synchronization. Import runs inside the existing Workspace and enforces its Studio Editor/Admin permissions. Publishing and review remain separate steps.

## Git Sync repository or export

1. Back up the destination Workspace and its media storage. Export the GitBook Space via Git Sync (or obtain a Markdown ZIP) that contains `.gitbook.yaml`, `README.md`, `SUMMARY.md` and `.gitbook/assets/`.
2. To migrate several Spaces together, put each Space in a separate subdirectory with its own `.gitbook.yaml`, e.g. `spaces/product/.gitbook.yaml` and `spaces/api/.gitbook.yaml`. Each becomes a separate Book (`product`, `api`). When importing a single Space, the Book field in Studio defines its destination name.
3. Open **Studio → Import documentation**, choose **ZIP archive** (or **Directory**), set the target version and conflict strategy, then **Preview import**. Inspect page mapping, unresolved links, unsupported assets and redirect warnings before applying.
4. Choose **Apply as drafts**. BookStore and MediaStore apply the validated plan atomically under the existing lock/transaction. No public page is updated until separately published; redirects are effective only after their target is published.
5. In Studio, inspect the sidebar and source links, review each draft and publish in the normal workflow. Verify public paths after publishing.

The Git Sync import supports `root` and `structure.readme` / `structure.summary` from static `.gitbook.yaml`, sidebar groups and nested Markdown links from `SUMMARY.md`, page titles/descriptions, Markdown, hints, tabs, steppers, HTML `<details>`/`<summary>` blocks, content references, GitBook reusable includes in `.gitbook/includes/`, local links and downloadable files. Embedded SVG, arbitrary JS/CSS, dynamic expressions, iframe widgets and custom executable blocks are excluded. Unsupported attachments generate warnings; conditional/adaptive visibility (`if`, `visibility`) raises a hard error to avoid making restricted content publishable by accident. Hidden sidebar state is not automatically transferred; verify drafts before publication.

Media types: PNG, JPEG, GIF, WebP, PDF, ZIP, TXT and CSV. All file contents are MIME-validated. The importer does not fetch external images or execute code from the source archive. Cross-Space links of the form `https://app.gitbook.com/s/SPACE/page` are rewritten when both Spaces and targets are in the same import bundle.

## GitBook REST API export

Use a GitBook personal/API access token with read access to the selected Spaces. Supply it **only** through an environment variable; never commit it or place it in a Workspace setting.

```bash
export GITBOOK_API_TOKEN='replace-with-your-token'
php bin/gitbook-import.php --spaces=space-id-one,space-id-two --output=/secure/path/gitbook-export.zip
```

Or export up to 20 Spaces from one organization:

```bash
export GITBOOK_API_TOKEN='replace-with-your-token'
php bin/gitbook-import.php --organization=organization-id --output=/secure/path/gitbook-export.zip
```

The exporter uses the authenticated GitBook REST API to fetch the organization Space list (when requested), page tree, each page's Markdown and its file list; downloads supported attachments from allowlisted HTTPS hosts, and produces a Git Sync-shaped ZIP for the same **Studio → Import documentation** workflow. It never writes directly to a live published Page and does not store the API token in that archive. Existing external links to unsupported downloads remain external.

**Limits:** max 20 Spaces per API export, 200 files per Studio import, 20 MiB input ZIP, 64 MiB expanded data, 20 MiB per file, 256 KiB Markdown per page and 1000 total Book pages. Split large sources into several imports. The API exporter refuses existing output files to avoid accidental overwrite. GitBook can expose computed/OpenAPI virtual pages without standalone Markdown; unsupported virtual content must be migrated separately. Original GitBook collection permissions, visitor authentication, adaptive audience rules, site configuration and active two-way Git Sync are not copied to Book. Configure Book access and domains independently.

## Validation

```bash
php tests/gitbook-111.php
php tests/gitbook-111-api.php
php tests/gitbook-111-integration.php
python3 tests/admin-docs.py
```

The full integration test requires Composer and PHP extensions from `composer.json` (`ext-zip`, `ext-mbstring`, etc.). After applying to staging, verify external API credentials, HTTPS availability, publication permissions, redirects, media, existing pages and backups. No PostgreSQL migration is required; schema remains 7.
