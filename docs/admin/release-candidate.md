# Release Candidate — 0.9.1

The 0.9.1 runtime changes address defects in signed update validation, OIDC and the HTTP security boundary. PostgreSQL schema remains 3. The separate security CI job runs the additional regression suite before source/dependency/image scans. This is an independent **execution** of tests on a separate runner, not a third-party penetration test or auditor attestation. No production qualification evidence is fabricated by this release.

## Retest scope and compatibility

| Boundary | Repaired behavior |
| --- | --- |
| Signed release and updater | Verify the signed manifest, actual size/hash and detached ZIP signatures before archive parsing. Enforce the same 10,000-entry / 512 MiB expanded limit, reject NUL/control names, case collisions and file/directory conflicts, allow only stored or deflated regular files. Bound VERSION and schema source reads. |
| Browser forms | Reject invalid authority syntax, empty or invalid port, malformed IPv6 and explicit HTTP/HTTPS scheme mismatch. Existing CSRF and primary-domain authenticated ingress remain in force. |
| OIDC | Enforce JSON object/array types and reject explicitly null security claims, unsuitable JWK metadata and unsupported JWT headers. A configured group claim, if present, must be an array, not an object or null. |
| Pinned HTTPS | Reject duplicate outbound header names before connection, deprecated 6to4 relay IPv4 range, and forbidden Content-Length/Transfer-Encoding on informational and 204 responses. No redirects or unpinned fallback. |

Providers and webhook/API endpoints that emit nonconforming HTTP 204 framing must be corrected to omit Content-Length and Transfer-Encoding, including Content-Length: 0. Behind a generic TLS reverse proxy, configure PHP to expose the actual HTTPS scheme (or use the authenticated primary-domain ingress). Do not trust arbitrary client-supplied forwarding headers. Existing valid signed historical ZIPs remain readable; previously accepted malformed archives are rejected.

## Reproduce on a disposable source checkout

```bash
python3 tests/security-retest.py
php tests/security-hardening.php --require-zip
python3 tests/security-http.py
python3 tests/security-transport.py
php tests/oidc-policy.php
python3 tests/oidc-http.py
php tests/security-lifecycle.php
python3 tests/security-lifecycle-http.py
python3 tests/release-signing.py
python3 tests/update-manager.py
```

The retest creates its own ephemeral RSA/Ed25519 keys, archives, private identities and loopback fixtures. It verifies actual production routines and never uses production credentials. The updater state-machine tests use a simulated Docker adapter; the real Compose lifecycle is covered separately by the existing lab gate. A full release requires the native, SQL, distributed, domain, Docker, scanner and production qualification gates in the protected workflow.

## External security acceptance

Before promoting to a production-certified release, give an independent reviewer the exact candidate commit, signed artifact digests, staging topology and isolated test accounts. Require review of anonymous/authenticated tenant boundaries, IDOR, CSRF, XSS, OIDC replay and key rotation, webhook/CI authorization, SSRF, signed update tampering, rollback and secret handling. Record reproduction, severity, affected commit, remediation and actual retest result. Resolve blocking findings and rerun the protected pipeline against the corrected candidate. The regression suite and CI evidence do not replace that review. See [production qualification](production-qualification.md) and [security hardening](security-hardening.md).

For upgrade instructions see [operations](operations.md). Do not install the changed-files patch with the signed updater: publish a full signed release first.
