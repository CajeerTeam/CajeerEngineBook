# SkiF4er ID authentication — CajeerEngine Book 1.0.3

Book implements the **server-side OIDC Authorization Code flow with PKCE S256** in PHP. No PHP provider SDK is published at <https://id.skif4er.ru/sdk/>. Book uses the provider's standard discovery endpoint and its existing signed-JWT implementation. A configured, registered confidential OIDC client is required: simply adding environment variables cannot create a Keycloak client.

## 1. Register the Book client in SkiF4er ID

Have the SkiF4er ID operator create a **confidential** OpenID Connect client in the `skif4er` realm (Standard Flow enabled, implicit and direct grants disabled, Client Authentication enabled, PKCE S256). Register these exact URLs, replacing the example hostname with the actual Book primary HTTPS host:

| Setting | Exact value |
| --- | --- |
| Issuer | `https://id.skif4er.ru/realms/skif4er` |
| Discovery | `https://id.skif4er.ru/realms/skif4er/.well-known/openid-configuration` |
| Valid redirect URI | `https://book.example.org/auth/oidc/callback` |
| Valid post logout redirect URI | `https://book.example.org/login` |
| Back-channel logout URL | `https://book.example.org/auth/oidc/backchannel-logout` |
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

The example `client_id` and organization ID are **placeholders**. The client secret file must be a regular non-symlink, absolute, private (mode `0600`) file outside `public/`. Use `BOOK_SKIF4ER_CLIENT_SECRET` *instead of* `_FILE` only for controlled secret-injection environments. Do **not** set `BOOK_OIDC_CONFIG` or `BOOK_OIDC_CLIENT_SECRET` for this provider at the same time.

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
