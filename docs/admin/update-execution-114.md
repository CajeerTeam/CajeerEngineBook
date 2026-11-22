# Secure Update Execution — Book 1.1.4

The installation owner can execute signed GitHub Release `fetch`, `preflight` and `apply` from **Administration → System Updates**, using the actual existing host-side `bin/update.py`. This release introduces a root-owned, Linux-only Unix-socket agent (`bin/update-agent.py`) and an opt-in, signed Compose overlay. No PHP handler runs a shell, receives Docker credentials, reads the root updater journal or receives a root-owned token. **Only a verified signed release** from the enrolled repository can be installed. The hosted code patch itself is not a signed/installable release.

## Security and deployment restrictions

- Supported: **one local Linux Docker Compose project**, already enrolled with the signed Book updater. The application runtime runs as UID/GID **33:33** (check on your host; adjust the systemd unit and allowlist together if your installation uses another identity). Do not forward the agent socket, expose it over TCP, share it between tenants, or attach it to untrusted containers.
- A root-owned host toolchain, obtained from a separately verified **signed** source ZIP, lives outside the release tree (`/opt/trusted-book-tools`). Install the matching `update.py`, `release-package.py`, `qualification_policy.py`, `update-discovery.py` and `update-agent.py` there. Never copy an unverified updater from a GitHub API response or execute code from the application-writable data volume as root. Keep root ownership, non-writable scripts and directory permissions; the agent checks the path ancestry at startup.
- Enrolled updater state (`/var/lib/book-updater`) and agent state (`/var/lib/book-update-agent`) are root-owned, **0700**, independent, backed up separately, and never mounted into Book. The agent does not accept a caller-supplied state path, key, repository, executable, arbitrary flags or version aliases. The root-owned `updater-state-path` file pins its real updater state location.
- The agent listens on `/run/cajeerbook/agent.sock` (root:33, mode 0660) and checks Linux `SO_PEERCRED` UID. The Book container receives only a **read-only bind mount of the socket directory**, not `/var/run/docker.sock` or host state.
- The agent reuses `update.py` to verify the full Ed25519/OCI release and signed payload hashes, enforce high-water version, offline schema/Compose checks and take backups. `fetch` and `preflight` do not stop the service. `apply` is **operator-confirmed**, not unattended. Schema upgrades requiring `--allow-schema-upgrade`, infrastructure topology changes, private GitHub access without a pre-staged bundle, remote Docker and multi-host writers are not automated by this stage.
- A maintenance permit is **not a replacement for physically fencing traffic and external writers**. The operator must isolate direct and public ingress, external workers, PITR cycles and all out-of-project DB writers before approving apply. A 15-minute-maximum, root-only, single-use permit bound to the exact version + commit + signed manifest SHA-256 is required. If the site cannot be fenced, do not authorize the operation.

## Host installation

From an independently verified official signed source release, as root on the Docker host:

```bash
sudo install -d -o root -g root -m 0755 /opt/trusted-book-tools
sudo install -o root -g root -m 0555 bin/update.py bin/release-package.py bin/qualification_policy.py \
  bin/update-discovery.py bin/update-agent.py /opt/trusted-book-tools/
sudo install -d -o root -g root -m 0700 /var/lib/book-update-agent /etc/cajeerbook
printf '%s\n' /var/lib/book-updater | sudo tee /var/lib/book-update-agent/updater-state-path >/dev/null
sudo chown root:root /var/lib/book-update-agent/updater-state-path
sudo chmod 0600 /var/lib/book-update-agent/updater-state-path
sudo install -o root -g root -m 0644 deploy/systemd/cajeerbook-update-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now cajeerbook-update-agent.service
sudo systemctl status cajeerbook-update-agent.service
```

`/var/lib/book-updater` must be the *already enrolled* updater state, not a fresh enrollment created to bypass highest-version protection. Use the complete host-side signed update runbook for first enrollment.

The signed `compose.update-agent.yaml` adds only `BOOK_UPDATE_AGENT_SOCKET=/run/book-update/agent.sock` and the read-only host mount `/run/cajeerbook → /run/book-update` to the **book** service. It is opt-in; simply installing 1.1.4 does not open a privileged socket. For a fresh enrolled deployment, include this overlay last in the explicit `--overlays` list. The host systemd service must be running before Docker creates the bind mount.

For an existing 1.1.3-managed deployment upgraded to a **signed 1.1.4** release, perform an operator-controlled topology adoption, under maintenance, without editing any signed release files. Recreate **only** the Book container using the same signed tree, pinned Compose project, environment, existing ordered overlays and final `-f compose.update-agent.yaml`, then verify it is healthy. Finally run:

```bash
sudo python3 /opt/trusted-book-tools/update.py --state-dir /var/lib/book-updater enable-agent-overlay
```

The action rejects any added service, port, image, volume or runtime setting other than the exact approved socket bind and environment variable; it checks the actual live containers and health before committing the pinned overlay. If a source-built/unsigned deployment or altered topology cannot meet these conditions, stop and follow the manual signed-deployment migration procedure instead. Never edit `state.json` by hand or re-enroll solely to change overlays. Keep backups and the version high-water mark intact.

## Using the admin center

1. Deploy the public **Ed25519 key** into the app and set `BOOK_UPDATE_PUBLIC_KEY_FILE` as described in [Admin Update Center 1.1.3](update-center-113.md). Set `BOOK_UPDATE_AGENT_SOCKET=/run/book-update/agent.sock` through the signed overlay. Ensure `www-data` can connect to the mount but cannot inspect host state.
2. Sign in as the **installation owner**, visit `/admin/updates`, choose **Check GitHub releases**, and **Pin verified candidate**. A published GitHub Release must contain the complete signed assets.
3. Choose **Fetch signed update**. Refresh the page until the host reports `succeeded / fetched`. `update.py` verifies the complete signed ZIPs and OCI signatures; failures leave a root-only log.
4. Choose **Preflight signed update**; require `succeeded / preflight`. This verifies the Compose candidate, media/schema compatibility, current topology and availability of the signed image. It is not equivalent to an offline SQL migration dry run; actual `apply --offline` performs the existing offline checks and backup.
5. Arrange a real **maintenance fence**; coordinate external database writers and direct clients. On the Docker host, issue approval *only after that fence is active*:

```bash
sudo python3 /opt/trusted-book-tools/update-agent.py approve-apply \
  --state-dir /var/lib/book-update-agent \
  --updater /opt/trusted-book-tools/update.py \
  --permit-file /etc/cajeerbook/update-permit.json --confirm-fenced
```

6. Back in `/admin/updates`, explicitly confirm maintenance and click **Apply signed update**. The agent consumes approval, launches the durable offline updater, and the Book process may stop/restart before the browser reloads. Inspect agent status after Book comes back and run the normal host-side upgrade/health checks. Keep the external fence until verified. A failed/missing permit prevents activation.

The broker's job record is in the root-owned agent directory; private `output-<job>.json` and `error-<job>.log` files are **not** sent to the browser and may contain sensitive infrastructure diagnostics. Preserve them for incident review; set host log retention according to your privacy policy.

## Interrupted or failed operations

- A queued/running job has a changing heartbeat. A stale heartbeat is reported as `interrupted`; the agent rejects overlapping work rather than assuming a crash succeeded. The client only receives a redacted state and never raw Docker/Compose output.
- For failed/partially activated transactions **keep the site fenced**. Run `update.py status`; if there is `pending`, use `update.py recover --offline` and the recovery runbook, or restore the saved backup/PITR with operator review. Never force a schema downgrade. An agent or systemd restart may terminate the worker; the root updater's separate durable journal remains authoritative.
- After the host updater is clean, with no pending transaction and environment fingerprint intact, an operator can reconcile the agent journal:

```bash
sudo python3 /opt/trusted-book-tools/update-agent.py reconcile \
  --state-dir /var/lib/book-update-agent --updater /opt/trusted-book-tools/update.py
```

`reconcile` cannot be called over the PHP socket and refuses a live heartbeat or pending SQL migration. Successful staging and activation are never inferred from unsigned Book application files.

## Tests / acceptance

```bash
php tests/update-center-114.php
python3 tests/update-manager-114-overlay.py
sudo python3 tests/update-agent-114.py
php tests/update-center-113.php
python3 tests/update-discovery-112.py
```

The privileged sandbox test uses a **mock** updater binary to test the real Unix peer-credential boundary, job journal, approval and state machine. Signed ZIP/OCI testing stays in `tests/update-manager.py`, and actual Docker, backup and upgrade/recovery must be qualified separately in `tests/update-compose-gate.py`. **No live Docker upgrade, signed official v1.1.4 artifact, PostgreSQL/Redis or external-writer fence was tested by merely running these local unit tests.** A production rollout additionally requires the protected CI and the real infrastructure acceptance gate.
