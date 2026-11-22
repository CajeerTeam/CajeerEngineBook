# Documentation analytics — Book 1.0.6

Book records **opt-in, per-Workspace aggregates** in a private file store or PostgreSQL. It includes a manager-only daily dashboard, popular published Reader pages, search query trends, named conversions and optional external analytics. All collection is **off by default**; no external SDK is fetched before the Reader explicitly accepts consent. These are application features with real runtime code and persistent counters, not a third-party API proxy or a dashboard mockup.

## Deploy / upgrade 1.0.5 → 1.0.6

1. Take tested backups of the Book installation and PostgreSQL database (if applicable), retain the previous application release, and stop all web, worker and custom-domain nodes using the shared database. Check that no other Book installation depends on the old SQL schema. Preserve file-mode `analytics/` directories separately (they are not part of portable Book backups).
2. Apply the changed-files archive at the **root of Book 1.0.5**; preserve the relative paths and rebuild/redeploy every application image and worker. Verify the `VERSION` file reads `1.0.6`.
3. **PostgreSQL only:** from the upgraded code, on the writable primary with migration credentials and all application nodes stopped, execute `php bin/migrations.php plan`, `php bin/migrations.php preflight`, `php bin/migrations.php apply --offline`, then `php bin/migrations.php verify`. This upgrades schema **4 → 5**. The new `cajeer_book.analytics_dimensions_daily` table shares the installation namespace and Workspace scope. Existing `analytics_daily` counters, schemas 1–4 and prior migration checksums are not altered. Schema 5 is mandatory for the new Book runtime; do not attempt a mixed-version rolling deployment. For a fresh SQL database the same offline migrator creates the full schema.
4. Run `php tests/analytics.php`, `php tests/analytics-106.php`, `node tests/analytics-106-browser.mjs`, `python3 tests/analytics-http.py`, `python3 tests/analytics-106-http.py`, `php tests/migrations-policy.php` and the full CI/security/SQL/Redis/backup gates relevant to the deployment. The native browser E2E needs a Chromium installation allowed to access loopback; the Node test also checks opt-in/opt-out without needing network access. Test actual provider dashboards with registered site IDs before trusting third-party delivery.
5. Re-enable traffic only after validating ready health, public documentation, Workspace permissions, a real search, a configured conversion and at least one full recovery drill.

## Workspace settings

Open **Workspaces → Analytics** (`/analytics?id=WORKSPACE_ID`) while signed in with `manage` permission. All settings are revision-checked, validated and CSRF-protected. A Workspace's external IDs and stored counters cannot be inspected or changed by a non-manager, and users cannot set the target Workspace in an anonymous event payload.

| Setting | What it does |
| --- | --- |
| Enable analytics | Allows first-party, anonymous page and event counts. Off by default. |
| Aggregate search phrases | Accepts bounded, normalized terms on completed public `/search?q=...` page views. Off by default. |
| Conversion goals | Comma-separated slugs like `signup,download,feedback`, max 12, no duplicates. Only configured goals can be counted. |
| GA4 / Metrica / Webvisor | Existing 1.0.5 integrations and Google basic Consent Mode v2. |
| PostHog project token / HTTPS API host | E.g. `phc_...` and `https://eu.i.posthog.com`. No implicit identify, autocapture or session recording. |
| Matomo HTTPS origin and site ID | E.g. `https://matomo.example.com`, `3`. Requires a Matomo tracker at `/matomo.js` and `/matomo.php`. Cookies are disabled by Book and consent is required. |
| Plausible site-specific script URL | Copy the **whole** `https://.../js/pa-....js` URL from the Plausible site installation settings (new October 2025 integration). Book disables automatic pageview capture and sends manual pageviews/events. |
| Umami HTTPS origin and website UUID | E.g. `https://umami.example.com`, `94db1cb1-...`. Loads `/script.js` with the website ID and sends manual events. |

Hosts must be plain HTTPS DNS origins with no credentials, ports, queries or fragments; a Plausible script must be an HTTPS `.js` path. Site IDs are syntactically validated. An arbitrary JavaScript snippet or executable code cannot be entered through the settings form. Only listed origins are added to the Reader's CSP when the associated Workspace is actively serving analytics. Administrators must trust operators of each external script; CSP and opt-in do not make malicious third-party scripts safe.

## Reader behavior and counting

A local Book script displays Accept / Reject and persistent Privacy preferences controls on public Reader pages. Consent is stored in `localStorage` for the current origin **and Workspace**; it is not shared across domains, devices or users. Global Privacy Control overrides a saved grant. Before acceptance, Book emits no first-party analytics POST and loads none of the external SDKs. On acceptance, the Reader emits one manual `page_view`, then search events when `/search?q=...` is opened, and optional user interaction events. On withdrawal, the page updates known vendor consent state (where supported) and reloads without loading SDKs. Browser blockers, network failures and user settings can still suppress tracking; reports show recorded events, not guaranteed unique visitors.

The endpoint is a same-origin `POST <workspace-prefix>/analytics/collect` taking JSON with `event`, `path`, `consent:true`, and optional `query` for a `/search` event or `goal` for a conversion event. It requires an exact Origin, limits size and request rate, rejects unknown fields and identifiers, validates page paths, and only accepts public, active Workspaces. Public no-cookie events are not tied to visitor identities.

Custom conversions can be triggered via the published browser API:

```js
window.CajeerBookAnalytics?.trackConversion('signup');
```

Or by adding `data-book-conversion="signup"` to a clickable element in a trusted, permitted Reader rendering path. `download` and `feedback` may be automatically counted as conversions if they are in the Workspace's configured goals. Setting a goal does not invent conversions; a real click or API call is necessary. Custom JavaScript cannot bypass consent: both the browser and server reject events without it. Conversions count **events**, not unique visitors or purchases, and the dashboard's ratio is *conversion events per 100 page views* (not a unique-user conversion rate).

Search terms are lowercased, whitespace-normalized and restricted to 3–80 Unicode letters/digits plus a small set of separators. Emails, URLs, long numeric strings, token-looking payloads and oversized data are rejected; terms displayed in the manager dashboard must be observed at least **three times** during its 30-day window. Rare terms are still temporarily held in aggregate storage. Search telemetry can contain sensitive text despite the filter; enable it only where appropriate and tell readers about search processing in your privacy policy. Terms are **not** explicitly passed as properties to third-party events, but external JavaScript providers have their own browser access and privacy policies.

## Report, storage and limitations

The server-rendered dashboard offers totals, per-day event chart, top documentation paths, most frequent search phrases, conversion counts by goal, and general events over the **last 30 UTC days**. Search-query dimensions are not visitor-specific. Daily file stores have an exclusive lock, quota and atomic replacement. Existing 1.0.5 daily JSON files (without `dimensions`) remain readable and are appended compatibly. PostgreSQL keeps existing `analytics_daily` counters unchanged and writes search/goal dimensions transactionally with their base event in `analytics_dimensions_daily` by installation, Workspace, day, kind, dimension. Failures return 503 for the beacon but do not prevent reading published docs.

The 30-day reporting window is **not a retention policy**: Book does not automatically delete historical aggregates. A Workspace manager may choose to disable search-term telemetry independently of ordinary counts. File-mode analytics directories are **not** included in portable Book backups and must be backed up separately; PostgreSQL/PITR backups include the SQL tables. Schema 5 rollback refuses to drop a nonempty dimensions table; to downgrade, export/preserve the analytics data and explicitly empty this table under an authorized offline migration procedure. Schema 4 rollback retains the historical nonempty-counter guard.

GA4, Yandex, PostHog, Matomo, Plausible and Umami may independently process IP addresses, browser metadata or identifiers once accepted. Book cannot guarantee vendor-side anonymity, compliance or successful delivery, and it cannot withdraw already transmitted records from a provider. Check consent requirements with the deployment's legal/privacy team and configure provider-side goals, domains and data retention. Test tags in actual vendor accounts; development tests use local fake IDs and may simulate or block external JavaScript.

## API documentation references

- [PostHog browser SDK and capture](https://posthog.com/docs/libraries/js)
- [Matomo JavaScript tracker and consent](https://developer.matomo.org/guides/tracking-consent)
- [Plausible site-specific tracking script](https://plausible.io/docs/script-update-guide)
- [Umami tracker configuration](https://docs.umami.is/docs/tracker-configuration)
