# Scheduled GitHub updates and notifications — Book 1.1.6

## Scope and trust

The unprivileged Book scheduler performs real signed GitHub Release checks. It invokes the same `UpdateCenter` cryptographic validation as the owner-only Administration → System Updates page (`/admin/updates`). It does **not** execute a shell or Docker itself. `download_only` and `manual_approval` use the existing allowlisted `UpdateAgentClient` Unix-socket RPC; the agent and host updater revalidate the exact signed release before downloading or preflighting it. `apply`, `rollback`, `recover`, key rotation and infrastructure changes remain **outside** scheduled RPC. Automated installation does not occur in 1.1.6: it requires an independent root-operator maintenance permit, a verified external-writer fence and explicit owner confirmation.

Before enabling, install a trusted Ed25519 public key in the Book application environment (`BOOK_UPDATE_PUBLIC_KEY_FILE`, readable by UID 33). Deploy the root-owned host-agent and approved `compose.update-agent.yaml` overlay only if scheduled staging is wanted. No Github token or Docker socket is passed to PHP. The scheduler runs using the deployment's `BOOK_DATA_DIR`, with the same private persistent volume as the application. Do not place state under a web root or in an unshared container filesystem.

## Enable the timer on the Docker host

The signed source includes a dedicated host launcher, `deploy/update-scheduler-host.sh`, and systemd unit/timer in `deploy/systemd/`. Install **reviewed** files outside the writable Book release tree, root-owned and not writable by the application. The host must run the supported local Docker Engine and have exactly one running `book` container for the chosen Compose project; the launcher exits unsuccessfully if zero or more than one matches. Other layouts should use an operator-managed cron invoking `php bin/update-scheduler.php tick` as UID 33 in a single Book container with the shared data directory. Do not run the PHP scheduler as root.

```bash
sudo install -o root -g root -m 0755 deploy/update-scheduler-host.sh /opt/trusted-book-tools/update-scheduler-host.sh
sudo install -o root -g root -m 0644 deploy/systemd/cajeerbook-update-check.service /etc/systemd/system/cajeerbook-update-check.service
sudo install -o root -g root -m 0644 deploy/systemd/cajeerbook-update-check.timer /etc/systemd/system/cajeerbook-update-check.timer
sudo systemctl daemon-reload
sudo systemctl enable --now cajeerbook-update-check.timer
sudo systemctl list-timers cajeerbook-update-check.timer
```

The service's `BOOK_COMPOSE_PROJECT=book` value must match the **actual Docker Compose project**. Edit the unit with root privileges if needed, then run `systemctl daemon-reload`. The timer calls the host launcher every 15 minutes with a small jitter; the application enforces its configured interval and persistent next-due timestamp. The launcher selects one running container by Docker Compose labels and performs only a fixed `docker exec --user 33 ... php bin/update-scheduler.php tick` command. Neither the web app nor the timer passes arbitrary commands, a release version, or a root permission to the container.

To run a check on demand from the host, use `sudo systemctl start cajeerbook-update-check.service`. To disable entirely: `sudo systemctl disable --now cajeerbook-update-check.timer`, then disable checks in the owner panel. During upgrades the timer may fail because the Book container is stopped; its next invocation will resume without bypassing the stored due time.

## Configure from the owner panel

Open `/admin/updates` as the **installation owner**, not a Workspace administrator. In **Scheduled checks & notifications**, enable the scheduler and select a mode, channel, interval (15, 60, 360, 720 or 1440 minutes) and optional recipient email. Settings are CSRF protected, session checked and audited. The optional recipient is stored only in the private `update-center/schedule-settings.json` file; in-app notifications use `schedule-state.json` (0600). The directory is private (0700), state updates are atomic and protected by a cross-process flock, and only a bounded history is retained.

| Mode | Actual scheduled behavior |
|---|---|
| `notify_only` | Verify signed release metadata and add in-app/optional email alert; never ask host-agent to stage |
| `download_only` | Verify and pin a stable release, ask the configured agent to `fetch` its exact signed package; never preflight or activate |
| `manual_approval` | Verify and pin a stable release, ask agent to `fetch`, then after successful fetch ask agent to `preflight` at a subsequent timer tick; wait for explicit operator approval and owner action before `apply` |

The beta channel can only use `notify_only` because the current signed installer accepts stable version identifiers. A prerelease is reported as unsupported, never staged. Invalid signatures, incomplete GitHub inventories and API failures generate warning notifications rather than false claims that Book is up-to-date. Release alerts are deduplicated by version and signed manifest digest; failed email delivery is retried no faster than the configured interval. An email marked accepted by the MTA is not a delivery guarantee.

### Optional email delivery

In-app notifications function without any SMTP or email infrastructure. For outbound email, configure a trusted local `sendmail`-compatible MTA in the Book application runtime, make PHP `mail()` functional, and then set a recipient in the panel. The standard Book Docker image **does not ship a configured MTA**: without one, outbound email is unavailable and pending notification messages remain visible in the panel. Do not put API tokens or secrets into recipient fields or release notes. A customized mail-enabled image needs a signed release and full production qualification.

## Operational verification and diagnostics

Use the following command inside the already-running Book container, as its unprivileged application user:

```bash
php bin/update-scheduler.php status
php bin/update-scheduler.php tick
```

The first command shows redacted scheduler state; the second performs at most one due check, records persistent notifications and optionally queues the appropriate host-agent action. Neither performs `apply`. The owner page shows last check, next check, status, saved settings and recent alerts. For root updater errors inspect the existing trusted host agent and `bin/update.py status` as described in [secure execution](update-execution-114.md) and [backup recovery](update-recovery-115.md).

If the operator changes the trusted release public key or rollback state, do not silently reuse old staged candidates. Follow the established re-enrollment/key migration procedure. Reconcile a failed host update before any subsequent activation. This timer is not a substitute for backup validation, PostgreSQL/PITR testing or a maintenance fence.

## Tests

```bash
php tests/update-scheduler-116.php
php tests/update-scheduler-controller-116.php
python3 tests/release-110-policy.py
```

These tests cover real Ed25519 release metadata, notification deduplication, scheduler persistence and disabled-by-default behavior, host-agent staging, prohibition of unattended activation and owner/CSRF authorization. The full Docker timer, live GitHub signed release availability and configured MTA delivery require acceptance testing on a real host.
