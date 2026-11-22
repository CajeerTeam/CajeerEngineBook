# Update History & Operations — CajeerEngine Book 1.1.7

## Scope and security

`/admin/updates` now displays the **durable history** of operations executed by the existing trusted host-side `bin/update-agent.py`, with a separate bounded history of scheduled checks. Only the **installation owner** can view the operations or export diagnostics; a Workspace editor or administrator is not granted these permissions. Reading the history does not invoke `fetch`, `preflight`, `apply`, Docker, root shell, or a network download.

The root host-agent stores up to **60 sanitized events** in `/var/lib/book-update-agent/history.json` (actual location depends on the configured agent `--state-dir`), protected by the same exclusive flock as the operation journal. Events are written atomically, fsynced and have mode `0600`. The agent exposes only the latest 24 via read-only Unix RPC `history`, and PHP independently validates the event shape before rendering. Fields are restricted to operation ID, UTC event time, action (`fetch`, `preflight`, `apply`, `reconcile`), state, signed release version, sanitized result code and elapsed seconds. **No raw stdout, stderr, stack traces, private files, email addresses or credentials are stored in this history or sent to PHP.** Keep the agent state directory root-owned `0700`, outside the application tree and its writable volumes. Preserve it through upgrades and include it in protected host-state backups.

The scheduler stores at most 60 recent check/configuration/staging events in its existing private `schedule-state.json`. This log is not a root update journal and is not proof that a Docker update was applied. Scheduled operation history is displayed separately from the authoritative host-agent history. A 1.1.6 installation starts with empty history; it does not invent past events from prior releases.

## Operations and safe retries

Open **Administration → System Updates** (`/admin/updates`) as the installation owner. The page shows the current operation state and **Update history & operations** table: UTC time, action, release version, outcome code, state and elapsed time. Refresh the page to see later transitions. The existing signed candidate, pinned manifest SHA-256, verified host stage and authorized manual controls are unchanged.

- **Failed fetch**: inspect the non-sensitive result code and host-only logs; resolve network/verification issues; manually retry `Fetch signed update` for the same verified candidate. Do not change the trust key to bypass signature failures.
- **Failed preflight**: inspect migrations, storage and host readiness; rerun `Preflight signed update` only after the exact signed fetch and candidate verification. A previous successful fetch is retained in the agent journal.
- **Failed apply or interrupted activation**: **do not retry through the web interface**. Keep traffic and external writers fenced, inspect the root updater state and backup receipts, follow [recovery](update-recovery-115.md) and [secure execution](update-execution-114.md). An unresolved `pending` journal must be recovered by the host operator; never reset the agent journal to bypass it.

The agent's `history` RPC is read-only. `apply`, `rollback` and `recover` are not introduced as unattended scheduler actions. Offline maintenance approval, complete signed artifact verification, backup and rollback constraints remain mandatory.

## Sanitized diagnostics export

The installation owner can select **Export sanitized update diagnostics (JSON)** or navigate to `/admin/updates?download=diagnostics`. It returns no-store JSON with the installed version, last known discovery status, scheduler enabled/mode/channel and recent events, verified host job summary, limited recovery flags and operation history. The export uses explicit field allowlists and excludes private notification destinations, raw provider output, Docker socket paths, environment files, signing keys, session tokens, dump paths, and backup content. Do not treat even sanitized diagnostics as public; store and share it with support only after reviewing it for operational metadata.

The browser response is `application/json`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and cache disabled. Unknown or damaged history is rejected or shown as unavailable, rather than parsed into executable content. A failed agent connection leaves the site running; it does not report a completed update. There is no new PostgreSQL migration (schema remains **7**), and the embedded CajeerEngine Core remains **2.1.0**.

## Deployment

1. Before updating from 1.1.6, retain backups of Book and of the separate root-owned host updater/agent state.
2. Install the version 1.1.7 PHP sources in the Book application and update the independently trusted **host-side** `bin/update-agent.py` outside the managed release tree. **Do not run a host updater copied directly from an unsigned ZIP as root.** Follow [signed releases](signed-releases.md) to verify the official signed source package and pinned Ed25519 key before installing it on the Docker host.
3. Restart the trusted host-agent service after checking its UID/GID and root-only state directory; preserve `operation.json`, `updater-state-path` and existing receipts.
4. Verify the page and `history` read-only RPC with an installation owner, then try JSON diagnostics export. Confirm it contains no email or sensitive host paths.
5. Run `php tests/update-operations-117.php` and `sudo python3 tests/update-history-117.py` in a disposable environment; complete the existing [real Docker upgrade and rollback gate](secure-updates.md) before using these capabilities in production.

This release does **not** claim successful end-to-end deployment until the real Docker Compose/PostgreSQL/Redis/security qualification is completed. Future retention/archival of older host events must be managed by the operator; the bounded live history is not an immutable external audit log.
