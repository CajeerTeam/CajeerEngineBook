# Embedded CajeerEngine core — 2.1.0

Book is a separate application, currently **Book 1.0.0**. Its vendored CajeerEngine subset has been synchronized to the supplied **CajeerEngine 2.1.0** source. This is a code-level update of all upstream runtime classes that Book uses; **it is not an installation of the full CajeerEngine CMS**. In particular, the CMS database, middleware stack, Admin Update Center, GitHub release operator, backup/rollback handlers and their migrations are not integrated into Book. Book continues to use its own release and signed-update workflow.

The exact upstream files retained under `core/` are:

- `Runtime/ServiceContainer.php`
- `Template/CajeerTemplateEngine.php`
- `Template/TemplateCompiler.php`
- `Template/TemplateExpressionEvaluator.php`
- `Template/TemplateSandbox.php`
- `Update/OperationFiles.php`

All six now match the uploaded CajeerEngine 2.1.0 sources byte-for-byte. Only three differed from the original Book archive. `OperationFiles::dir()` gains 2.1.0's component-by-component directory creation that rejects symlinks in ancestor directories. The template changes align the expression evaluator and template namespace handling with 2.1.0. Book's application data, SQL schema, REST routes and own `VERSION` are unchanged.

## Verification

`php tests/engine-core.php` checks the pinned upstream SHA-256 hashes, class behavior, CJR HTML escaping and symlink-safe filesystem behavior. This test is part of `composer test` and the native release CI gate. The same pinning is checked by `ReleaseHealth::check()` on `/health/ready`. An incomplete deployment or modification of a pinned runtime class causes readiness to fail; it does **not** silently fall back to the previous core. The admin report now includes `engine_version` next to Book's own `version`.

## Deploying the changed-files patch

Apply paths from the patch archive to the **same directory tree** as the original Book 1.0.0 source and deploy the patch as part of a complete, externally verified release. Include the new `core/ENGINE_VERSION` and `src/EngineCore.php` files. If running the official signed-update process, build and sign a new full release using the project's existing release pipeline; a changed-files ZIP is **not** a signed upgrade artifact. Verify the normal health and CI tests before production rollout. This update does not require a database migration; keep a validated backup and the original files for rollback.

A local SHA-256 pin is a consistency check, **not** a substitute for trusted release signatures, upstream provenance verification, CI qualification or staging acceptance. No full production acceptance was performed solely by generating this patch.
