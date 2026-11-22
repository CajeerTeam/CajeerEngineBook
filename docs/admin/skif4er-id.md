# SkiF4er ID authentication — CajeerEngine Book 1.0.4

Book implements the **server-side OIDC Authorization Code flow with PKCE S256** in PHP. No PHP provider SDK is published at <https://id.skif4er.ru/sdk/>. Book uses the provider's standard discovery endpoint and its existing signed-JWT implementation. A configured, registered confidential OIDC client is required: simply adding environment variables cannot create a Keycloak client.

## 1. Register the Book client in SkiF4er ID

Have the SkiF4er ID operator create a **confidential** OpenID Connect client in the `skif4er` realm (Standard Flow enabled, implicit and direct grants disabled, Client Authentication enabled, PKCE S256). Register these exact URLs, replacing the example hostname with the actual Book primary HTTPS host:

| Setting | Exact value |
| --- | --- |
| Issuer | `https://id.skif4er.ru/realms/skif4er` |
| Discovery | `https://id.skif4er.ru/realms/skif4er/.well-known/openid-configuration` |
| Valid redirect URI | `https://book.example.org/auth/oidc/callback` |
| Valid post logout redirect URI | `https://book.example.org/login` |
| Back-channel logout URL | `https://book.example.org/auth/oidc/backchannel-logout?provider=skif4er` (single-provider installations also accept the bare path) |
| Requested scopes | `openid profile email` |

Enable **Backchannel Logout Session Required** for the Book client. All endpoints must be served through trusted HTTPS. Configure inbound TLS and disable logging of authorization code callback query strings. The back-channel URL must accept form-encoded, server-to-server POST requests from the provider; do not put it behind browser CSRF challenges (the signed logout token is verified instead).

## 2. Configure the Book server

Initialize Book and create an organization as the local installation owner. Copy its 32-character lowercase hex ID. Set the following environment variables on **every** Book web node (or supply them through your secret/orchestration system):

```sh
BOOK_SECURE_COOKIE=1
BOOK_PRIMARY_HOST=book.example.org
BOOK_SKIF4ER_CLIENT_ID=cajeer-book
BOOK_SKIF4ER_CLIENT_SECRET_FILE=/run/secrets/skif4er-id-secret
BOOK_SKIF4ER_REDIRECT_URI=https://book.example.org/auth/oidc/callback
BOOK_SKIF4ER_ORGANIZATION=0123456789abcdef0123456789abcdef
BOOK_SKIF4ER_DEFAULT_ROLE=viewer
BOOK_OIDC_REQUIRED=0
```

The example `client_id` and organization ID are **placeholders**. The client secret file must be a regular non-symlink, absolute, private (mode `0600`) file outside `public/`. Use `BOOK_SKIF4ER_CLIENT_SECRET` *instead of* `_FILE` only for controlled secret-injection environments. You may also configure a distinct enterprise provider or additional named providers, but each must use its own issuer and its own confidential secret.

**Access policy is explicit.** `BOOK_SKIF4ER_DEFAULT_ROLE=viewer` deliberately grants a viewer membership in the configured organization to *any successfully authenticated SkiF4er ID user* who completes the login flow. For restricted entry, leave `BOOK_SKIF4ER_DEFAULT_ROLE` unset and configure approved provider groups instead:

```sh
BOOK_SKIF4ER_GROUP_CLAIM=groups
BOOK_SKIF4ER_GROUPS='{"book-readers":"viewer","book-editors":"editor"}'
```

Group values above must actually be emitted **in the ID token** by the provider and mapped to the corresponding roles by the operator. If no approved group is present and default role is `none`, access is rejected. With the default viewer role, mapped `editor` groups elevate only users in those groups. Book does not give federated users installation-owner rights. A user is linked by verified `issuer + subject`, never username or email; conflicting email accounts are not automatically merged.

## 3. Enable and test

```sh
php bin/oidc.php check
php tests/skif4er-id.php
php tests/oidc-policy.php
python3 tests/oidc-http.py
```

The configuration check validates the configured organization, issuer, TLS discovery, and provider feature support. The local regression suite uses an independent HTTPS signed-token fixture; it does **not** simulate possession of a real registered provider client. Then test a genuine login against the registered client: open `/login`, choose **Sign in with SkiF4er ID**, complete the provider login, and verify `/account` and the workspace's viewer/editor restrictions. Confirm invalid code/state/callback requests fail. Only after a successful staging test should you consider `BOOK_OIDC_REQUIRED=1`; the installation owner's local login remains available for emergency access.

## 4. Logout, revocation, and security

- **User sign out:** `POST /logout` with session CSRF immediately deletes the local opaque server session and clears the secure HttpOnly cookie. The backend attempts OAuth access/refresh token revocation, then returns a redirect to the provider's registered RP logout endpoint using an ID token hint. Remote unavailability cannot restore the deleted local session. The provider may present its own logout confirmation depending on its policy.
- **Provider sign out:** `POST /auth/oidc/backchannel-logout` accepts only a signed RS256 logout token with the expected issuer and client audience. A `sid` invalidates just the matching local provider session; a subject-only event revokes associated local sessions with a monotonic logout timestamp. Missing, tampered, or expired claims fail closed.
- **Operator revocation:** installation owner can disable a user in **Account → Users** or revoke their local sessions. Disabling blocks subsequent federated sign-ins; revoking sessions alone does not permanently prevent a fresh provider login. For provider-wide bans, also disable/deactivate the user in SkiF4er ID.
- **Token privacy:** Book encrypts provider tokens with a key derived from the confidential client secret and stores them only in its private identity session store (file or PostgreSQL). Browser cookies contain only opaque Book session IDs. Backup snapshots exclude login sessions, pending flows, and provider tokens. Rotate the secret on all web nodes together; rotating it invalidates the existing federated sessions.

Provider back-channel logout relies on the provider successfully delivering the configured webhook; it is not push-enabled browser session monitoring. There is no automatic access-token introspection on every Book request. If you require immediate provider-driven revocation under arbitrary webhook delivery failures, do not treat this integration as that guarantee; enforce additional external session policy. Use separate live Keycloak, PostgreSQL/Redis and ingress staging before declaring production readiness.

## 4. CajeerEngine Book 1.0.4: additional OIDC providers

Each provider is pinned by a server-configured name, unique HTTPS issuer, fixed `client_id`, exact Book callback URI and an existing organization. Configure a JSON **object** in `BOOK_OIDC_PROVIDERS` (maximum eight extra clients). `BOOK_OIDC_CONFIG` continues as provider `enterprise`; dedicated SkiF4er ID settings continue as provider `skif4er`. The same issuer may not appear twice. Do not place secrets in JSON. Extra provider names match `[a-z][a-z0-9_-]{1,31}` and secrets are resolved from `BOOK_OIDC_<UPPERCASE_NAME>_CLIENT_SECRET` (or `_FILE`).

```sh
BOOK_OIDC_PROVIDERS='{"work":{"issuer":"https://id.company.example/realms/staff","client_id":"book-work","redirect_uri":"https://book.example.org/auth/oidc/callback","organization":"0123456789abcdef0123456789abcdef","label":"Work ID","groups":{"writers":"editor","readers":"viewer"},"default_role":"none","backchannel_logout":true}}'
BOOK_OIDC_WORK_CLIENT_SECRET_FILE=/run/secrets/work-id-secret
```

The local Book session cookie remains `SameSite=Strict`. During the cross-site OAuth callback it may not be sent; Book verifies the previously confirmed live session by its server-side hash and returns a success page with an explicit account link, without loosening the cookie policy.

The additional issuer URL and group names above are examples and need real registration. All Book nodes must receive the exact same environment variables and secret mounts. Register the same **exact** `https://book.example.org/auth/oidc/callback` for each client. With multiple providers, register a distinct HTTPS back-channel URL per client: `/auth/oidc/backchannel-logout?provider=skif4er`, `/auth/oidc/backchannel-logout?provider=enterprise`, or `/auth/oidc/backchannel-logout?provider=work`. Signed issuer, audience and JWKS verification is mandatory before a logout is accepted. `php bin/oidc.php check` tests the discovery and organization of **every** configured provider.

## 5. Account linking, unlinking and role boundaries

1. Sign in using the **local username/password** of an existing, enabled, non-owner account. This account must already be a viewer or editor of the provider's configured Book organization.
2. Open `/account`, choose **Link SkiF4er ID** or another listed provider and confirm the local password. A browser-bound, single-use PKCE+nonce transaction is started. Finish the actual IdP login in the same browser within five minutes. A verified issuer+subject is linked to the current local account only if unclaimed by every other user. Matching usernames/emails are never sufficient.
3. The account shows connected providers. To remove a connection choose **Unlink**, reconfirm the local password and submit the form. Only the named association and its provider-authenticated sessions are removed; the local password, unrelated provider links and local sessions remain valid.

Safety policy: installation owners cannot link externally. Accounts with owner or administrator organization/workspace access cannot link, and accounts with existing links cannot be promoted to administrator or own new organizations until unlinked. Linked logins never grant new organization memberships, and provider role claims never elevate existing local roles; when a provider's role is lower than a local role, federated login is denied without changing the manual membership. The provider's `sub` must be unique per issuer. A locally created account can still change/reset its local password with links attached; a JIT-only federated account keeps its original managed identity behavior.

Unlink only disables the **Book association**. It does not delete the IdP's account or remotely revoke long-lived provider credentials previously issued elsewhere. Book still revokes its local provider sessions; use IdP administration to terminate other client sessions. When `BOOK_OIDC_REQUIRED=1`, non-owner local sessions are unavailable, so perform local account linking/unlinking before enabling mandatory SSO, or temporarily restore locally permitted sign-in under an approved maintenance procedure. Test all routes with actual registered clients, a staging account and TLS before rollout.

Verification:

```sh
php tests/oidc-linking.php
python3 tests/oidc-linking-http.py
php tests/oidc-policy.php
python3 tests/oidc-http.py
```
