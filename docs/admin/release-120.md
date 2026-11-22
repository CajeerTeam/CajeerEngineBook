# CajeerEngine Book 1.2.0 — Production Auto Update Release

This is the final integration release for the **signed GitHub → discovery → installation owner → trusted host-agent → Docker updater** workflow. It contains executable hardening and regression tests, not a replacement installer. PostgreSQL schema remains **7**, and the embedded engine remains **2.1.0**. A source patch ZIP is **not** a signed production distribution.

## Required qualification and deployment admission

Do **not** promote 1.2.0 to production until the protected `v1.2.0` tag has passed all mandatory jobs from that *same* CI run: native regressions (including access, imports, review, analytics, search and AI), independent security scan, PostgreSQL/Redis, Docker upgrade/rollback, distributed/edge, full physical-loss/PITR recovery and the dedicated 24-hour load qualification. The signing job must verify those results, candidate-image identity and full qualification before signing. Confirm the published GitHub Release has all signed assets, SHA-256 inventory, complete qualification evidence and Cosign-verifiable OCI digests.

The qualification source SHA-256 now includes **every tracked shipping file**—including Compose, Dockerfile, PHP, updater, frontend, workflow, dependencies and test code—rather than only a subset of PHP/SQL/tests. A change in any tracked file invalidates previous evidence. Full qualification evidence is marked `full_qualification_verified` only after the lifecycle and full 24-hour load reports pass. `production_certified` intentionally remains false: a successful disposable lab run is **not** certification of the operator's live infrastructure. Re-run the full qualification for this exact commit and version; old signed assets/reports cannot be reused.

Host-agent output is bounded to 256 KiB stdout and 1 MiB stderr per operation. On overflow or timeout it terminates the updater process group and fails the operation. The last eight root-private diagnostic jobs are retained; the durable sanitized operation history remains separate. This prevents a malfunctioning updater from consuming unbounded host disk space.

## Update procedure

1. Preserve **independent** backups of Book data, PostgreSQL data/WAL/PITR repository, environment/secret material, installer state (including the anti-downgrade high-water version), release verification key and root-agent state. Confirm restore capability on a disposable host. Review [recovery](update-recovery-115.md) and [disaster recovery](disaster-recovery.md).
2. Obtain the official **signed** v1.2.0 release and verify it against the independently pinned Ed25519 public key; do not run privileged tools from an unverified patch/source ZIP. Review/sign-off the new host-agent and updater scripts, install them together outside the deployed tree in a root-owned directory, and restart the agent only after checking ownership and socket permissions.
3. Check the owner-only `/admin/updates` page, installed version, trusted release identity, changelog, schema and signed manifest. Run **Fetch** and **Preflight**. Confirm the host-agent's stage and operator logs; a UI green badge alone is not proof of readiness.
4. Fence public ingress, direct clients and *all* external writers/schedulers sharing the data, including remote workers and external PITR cycles. Confirm no active Compose one-off tasks or orphan containers. Issue the short-lived, candidate-bound host permit as documented in [secure execution](update-execution-114.md). The permit does not itself fence traffic.
5. Trigger **Apply** and monitor `/admin/updates`. The trusted host updater revalidates the package, creates and validates backups, checks the SQL migration contract, activates only signed images, verifies health and records the receipt. Keep maintenance fencing until the operator verifies the result. For a 1.1.7 → 1.2.0 upgrade the DB schema is unchanged (7); **do not** run an unrelated migration.
6. Verify the installed Book version `1.2.0`, readiness, owner login, OIDC, an existing published page, search, media integrity, background worker, Redis runtime, and update history/export. Verify `/admin/updates` and the scheduler still respect owner roles. Only then reopen ingress and external writers.

## Failure handling

- **Fetch/preflight failed:** keep the running release; inspect the root-only error log, certificate chain, signature/key and compatibility. Correct the root cause and retry the same signed candidate if safe.
- **Apply failed or was interrupted:** do **not** retry from the web or reset the agent journal. Keep traffic fenced. Read `update.py status`, verify the exact backup with `verify-backup --id`, and follow `update.py recover --offline` with the compatibility acknowledgements appropriate to the pending phase. An interrupted migration or activation may require manual data recovery.
- **Rollback:** requires a retained signed previous release and compatibility of all data written since activation. Rollback is host-operator-only, makes its own backup and does not restore historical data; never force an incompatible database downgrade. Use the PITR/physical restore runbook for incompatible or corrupted SQL state.
- **Unavailable external GitHub or providers:** signed-update checks may report errors or incomplete results; they must never silently claim that the installation is current. Continue operating the existing version until trusted dependencies recover.

## Verification commands and limitations

```sh
python3 tests/production-update-120.py
python3 tests/production-qualification-policy.py
python3 tests/release-signing.py
python3 tests/release-110-policy.py
sudo python3 tests/update-agent-114.py
python3 tests/update-manager.py
python3 tests/admin-docs.py
```

All code/security tests run on disposable data. Use the existing `tests/release-gate.py` suites with Composer, Docker and PostgreSQL/Redis available; do not mark missing tooling or timed-out tests as passing. The GitHub production qualification requires an isolated trusted self-hosted runner, a full 24-hour SQL/Redis workload and physical recovery evidence. No one can claim deployment-ready 1.2.0 without those signed protected-CI results and a host-specific restore/traffic-fencing drill.

Operator action remains required for **Apply**, recovery, rollback and schema/topology changes. `notify_only`, `download_only` and `manual_approval` scheduling do not install automatically and are intentionally preserved to enforce the maintenance fence.
