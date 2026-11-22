# Enterprise security lifecycle (0.7.1)

## Upgrade and secret placement

Stop all web nodes, workers, domain checks and backup schedulers. Create a verified backup with 0.7.0, retain the matching database/media/configuration and all decryption keys in separate protected storage. Overlay 0.7.1 and rebuild images. Schema 3 is unchanged. Do not mix old and new nodes during rotation or logout rollout.

`BOOK_GITHUB_KEY_FILE` and `BOOK_AUTOMATION_KEY_FILE` are absolute paths to regular private files, either a legacy 64-hex-character key or JSON keyring. The legacy `BOOK_GITHUB_KEY` and `BOOK_AUTOMATION_KEY` environment variables still work for a single key. Do not set both forms for the same purpose. `BOOK_BACKUP_KEY_FILE` accepts the same formats; backups still require a file. `BOOK_OIDC_CLIENT_SECRET_FILE` accepts a single client secret (not a keyring); do not set the legacy environment secret simultaneously. Files must have no group/other permissions and must not be symbolic links or under the public directory. Store them outside the application data and source trees, never in Git, logs or the backup payload. Use different random keys for each purpose.

Example keyring (replace both placeholders with distinct 64-character hex values generated independently with `openssl rand -hex 32`):

```json
{"active":"k2026","keys":{"k2026":"<new-64-hex>","k2025":"<previous-64-hex>"}}
```

Maximum eight distinct keys; IDs begin with a letter and contain at most 32 letters, digits, underscores or hyphens. The active key encrypts new data. Retained keys authenticate/decrypt existing data; no fallback to unauthenticated plaintext. Ciphertext formats are unchanged, so key selection tries the bounded ring rather than trusting an unauthenticated key ID.

Optional `compose.security.yaml` mounts two private host directories read-only. Set `BOOK_SECURITY_SECRET_DIR` (containing `git.json` and `automation.json`) and `BOOK_OIDC_SECRET_DIR` (containing `client-secret`); use it after existing overlays. This convenience overlay assumes OIDC is configured; use native environment/file settings for installations without it. The worker receives only Git/automation keys. Ensure the container runtime UID can read the files (normally www-data/33), directories are private, and all replicas receive the same keys. Backup keys are mounted separately by `compose.backup.yaml`; when replacing a single-file bind mount, recreate the backup container so it sees the new inode. Verify resolved Compose settings before starting.

## Rotate encryption keys for Git and automation

1. Keep a verified pre-rotation backup and old keys. Install the new release with the old keys and confirm normal operation. Stop writers/workers before the following maintenance.
2. Distribute identical keyrings with old key retained and new key active. Use atomic file replacement in private directories. Do not remove the previous keys yet.
3. Run on the intended installation (same `BOOK_DATA_DIR`, SQL namespace and binding), as its runtime user:

```sh
php bin/security.php keys
php bin/security.php rewrap --offline
php bin/security.php verify
```

`keys` shows only IDs; `verify` authenticates all live encrypted Git and automation credentials with active keys. `rewrap` processes legacy and every registered workspace (including archived); it is atomic per content scope and repeatable. If a later scope fails, earlier commits remain valid. Retain both keys, correct the failure and rerun. Rewrap updates Git credential revision to reject stale previews and preserves job IDs, queue state, repository configuration and webhook delivery identity. Run `verify` successfully before retiring old keys. After retirement run it again and restart all nodes. Do not discard old keys required by historical backups: restored old configuration may contain old ciphertext, even if the backup envelope uses a new key.

## Rotate CI and webhook signing credentials

Workspace Owner/Admin: Studio → CI/CD Publishing → Rotate. Choose 1–90 days and overlap 0–86400 seconds. The replacement keeps the original principal, scope, label and publish permission; the principal must still have permission. Old expiry is never extended. Zero overlap revokes the old token immediately. Copy the new token once into the CI secret store, test, then revoke the old token explicitly if overlap was chosen. No token is shown in the audit record. A compromised token should be revoked with zero overlap.

Studio → Webhooks & Automation → Rotate signing credentials. Enter a new secret (32–256 characters), select incoming/outgoing and overlap. Incoming accepts current and previous keys only until the chosen expiry (max 24h); `Retire previous` ends overlap immediately. Outgoing replaces its key immediately; configure the receiver to accept both during cutover, then retire the old receiver key. Existing jobs and delivery IDs survive rotation. A stale form is rejected; reload before retrying. Never use rotation to grant a wider role or scope.

## Backup key rotation

Set the new active key and retain the old key in `BOOK_BACKUP_KEY_FILE`. New encrypted snapshots use the active key. Old remote and local snapshots and pending uploads remain decryptable using retained keys. Test `php bin/recovery.php drill ID --remote` on a representative old snapshot and a new one; verify `health` and `status`. Do not remove old keys until all snapshots, archived copies and pending uploads requiring them have been expired or securely re-encrypted/replaced and independently restored. Store historical keys separately for legal/retention obligations. No automatic re-encryption of remote archives is performed. The old envelope format remains compatible with old single-key archives.

## Sessions and OIDC

Installation owner: Account → Users → Revoke all sessions. CLI for a local trusted operator (including emergency owner):

```sh
php bin/identity.php revoke-sessions USERNAME
```

Revokes sessions and password-reset tokens; federated revocation also advances a durable subject watermark and clears pending SSO flows. This is not account disablement: a user can sign in again with valid credentials. For incident containment disable the account as well. Change of OIDC client secret or security policy invalidates existing federated sessions/flows; stage the change with the provider and all nodes. Owner emergency login remains available under the existing policy.

Enable optional OIDC Back-Channel Logout with `"backchannel_logout": true` in the existing `BOOK_OIDC_CONFIG` JSON. Register `https://YOUR-PRIMARY-HOST/auth/oidc/backchannel-logout` with the provider. Endpoint accepts POST form `logout_token` with a signed RS256 JWT, pinned issuer/JWKS, audience, subject (`sub`), `iat`, `jti`, and `events: {"http://schemas.openid.net/event/backchannel-logout": {}}`; no nonce, optionally exp/nbf. Valid signed tokens return 200 even if the subject is unknown; invalid tokens return 400; disabled endpoint returns 404. Rate limited. No session cookie or browser CSRF token is used for this machine endpoint. The issuer must issue tokens within 300 seconds of current time, not in the future; synchronize clocks. Subject-wide logout revokes sessions with authentication time at or before the logout token time. Replayed/older tokens do not revoke newer sessions. New login requires a fresh ID-token iat later than the watermark. One configured provider/organization. **As of Book 1.0.3**, signed `sid` logout revokes the matching provider session and the SkiF4er ID configuration adds RP-initiated logout and encrypted provider token revocation; see [the current guide](skif4er-id.md). This is not automatic account linking or group synchronization.

## External audit checkpoints (PostgreSQL)

Use a dedicated offline-controlled Ed25519 seed, independent of other keys. Create 32 random bytes as hex in a private file and derive the public key using libsodium:

```sh
umask 077
openssl rand -hex 32 > /secure/audit-seed
php -r '$s=hex2bin(trim(file_get_contents($argv[1]))); echo bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($s))), PHP_EOL;' /secure/audit-seed > /secure/audit-public
php bin/audit.php checkpoint /secure/checkpoints/2026-11-16.json /secure/audit-seed
php bin/audit.php check-checkpoint /secure/checkpoints/2026-11-16.json /secure/audit-public
```

The checkpoint directory must already exist, mode 0700, outside the data directory. The command verifies the SQL retained chains and signs installation ID, timestamp, stream sequence/hash heads. It refuses to overwrite a checkpoint. Transfer the signed record and pin the public key in independently controlled immutable storage; do not keep the signing key on web/worker nodes. Verification compares the pinned signature with the retained chain (including anchor). If retention has passed a checkpoint, verification fails; retain and verify archived prefix evidence separately. A signer with a compromised signing key or already compromised database is not a trusted witness. This is an operator checkpoint workflow, not an automatic external notarization service.

## Validation and rollback

Run `php bin/security.php verify`, `php bin/migrations.php verify`, `php bin/audit.php check`, `php bin/runtime.php check` where enabled, and the OIDC readiness CLI. Test actual login/logout, one CI pipeline, webhook delivery and recovery of a pre-rotation snapshot. Use `php tests/security-lifecycle.php`, `python3 tests/security-lifecycle-http.py`, `python3 tests/oidc-http.py`, `php tests/recovery.php --storage-only` on disposable data, and the full release gate on infrastructure. Do not treat local storage-mode success as SQL qualification.

Rollback: old 0.7.0 understands only legacy single-key settings and does not enforce logout watermarks or accept the new incoming overlap fields. Do not downgrade against modified live state. Restore the matched pre-upgrade application, database/media/configuration and retained keys with writers stopped, then explicitly revoke sessions and rotate exposed credentials as necessary. Coordinate provider and CI/webhook settings too. Database/Redis/Sentinel/edge/S3 credentials remain operator-managed; use the infrastructure's rotation procedure. Full independent audit, SQL and Docker verification remain required.
