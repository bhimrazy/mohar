# ADR 0022 — Key management and rotation

- Status: Proposed · 2026-10-22 · Owner: Principal architect.
- Related: issue #25, PR #253, ADR 0012 (registries), ADR 0021 §3 (lock order), `docs/devops/key-rotation.md` (the
  runbook), `docs/security/standards.md` §6.

## Context

- Every secret the app stores is encrypted with `APP_KEY` (Laravel's encrypter): module credentials, pay-method
  tokens, secret settings, two-factor secrets, identity images on the objects disk. `APP_PREVIOUS_KEYS` lets old
  ciphertext be read after a key change, but nothing moved stored values to the new key, so an old key could never be
  retired.
- Modules and packages keep encrypted data in their own tables and files. Core cannot know about it without being
  told, and it must not reach into other modules' classes to find out.
- The key itself is read from the environment. Operators also need it from a secret file (Docker secrets, systemd
  credentials), and it must never end up in the image, the repository or a backup of the code.

## Decision

1. **Durable encrypted data is declared.** Data encrypted with `APP_KEY` that outlives a rotation is registered in
   `App\Support\Security\EncryptedStorage` by the module that owns it: a model with encrypted casts
   (`EncryptedModel`), another encrypted column (`EncryptedColumn`), or encrypted files referenced by path columns
   (`EncryptedFiles`). Keys follow ADR 0012 (`<module>` or `<module>_<part>`), and two registrations never name the
   same storage. Short-lived data (rule 4) is listed with its lifetime in the runbook instead. A value that is
   _derived_ from the key and stored (a keyed hash) uses an install secret (`Core\Support\InstallSecrets`), not
   `APP_KEY`. `tests/Feature/Security/EncryptedStorageCoverageTest` reads the source and fails on an undeclared
   encrypted cast, a direct use of the encrypter, or a new reader of `app.key` that is not listed with its reason.
2. **Key sources and precedence.** `APP_KEY`, else the file `APP_KEY_FILE` names (relative paths are taken from the
   application directory); the same for `APP_PREVIOUS_KEYS` / `APP_PREVIOUS_KEYS_FILE` (commas or new lines). A
   non-empty variable wins, and `app:install` fails when a variable and its file disagree. The key is never written
   to the image, the repository, an example file or a backup of the code or host; the cached configuration holds it
   and is kept readable by the PHP user only.
3. **Rotation invariant.** An old key is removed from `APP_PREVIOUS_KEYS` only after a complete
   `php artisan security:reencrypt` (exit 0) run with every module loaded, after the short-lived data has expired, and
   once no backup taken under it is kept (the old key is stored with those backups' retention, apart from them).
   `security:reencrypt` refuses a real run while modules are missing unless `--partial` is given.
4. **Short-lived data.** Signed URLs (verified against the previous keys too), sessions and cookies, queued and failed
   job payloads, cart EPP codes and import uploads are not re-encrypted. They stay readable while the old key is a
   previous key, and the runbook's wait step covers them.
5. **Envelope encryption is deferred.** Values are encrypted directly with `APP_KEY`. Envelope encryption with a
   KMS-held key-encryption key for the most sensitive columns (gateway credentials, pay-method tokens) is revisited
   when one of these happens: a customer or auditor requires keys held in a KMS or HSM, the install runs on more than
   one host that shares a database, or a key leak forces a rotation whose re-encryption window is too long to accept.
   The `EncryptedStorage` registry is where such columns would be marked.

## Consequences

- A module that adds an encrypted cast registers its model in the same PR, or the coverage test fails. A package
  registers its storage the same way; Core reads only the registry.
- `security:reencrypt` (Core) owns the checkpoint table, the per-row compare-and-set and the file switch. A file
  switch locks the parent row named in `EncryptedFiles::$lockFirst`, then the row, the same order the owning module
  uses (ADR 0021 §3: client, then KYC document), and modules that delete such files re-read the paths under that lock.
- Operators follow `docs/devops/key-rotation.md`. A rollback to a blue-green colour started before the rotation is not
  safe once values are on the new key.
