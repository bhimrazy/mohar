# Application key: storage and rotation

`APP_KEY` encrypts every secret the app stores (AES-256-CBC with an HMAC, Laravel's encrypter). This page covers where
the key may live in production, the warnings `app:install` gives about it, and how to rotate it. Install steps are in
[install.md](install.md) (a server) and [production.md](production.md) (the Docker stack).

## What the key protects

**Stored secrets, which `php artisan security:reencrypt` re-encrypts.** Each module registers its encrypted data
in `App\Support\Security\EncryptedStorage` (ADR 0022): its models with encrypted casts, other encrypted columns,
and encrypted files. The dry run lists the targets. At the time of writing there are 28:

| Area          | Targets                                                                                                                                                                                   |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Billing       | `gateways.config`, `pay_methods.token`                                                                                                                                                    |
| Services      | `servers.password`, `servers.api_token`, `servers.access_hash`, `servers.settings`, `services.password`, `services.custom_fields`, `module_queue.args`, `server_account_imports.accounts` |
| Catalog       | `products.driver_config`, `addons.driver_config`                                                                                                                                          |
| Domains       | `registrar_accounts.settings`, `domains.epp_code`, `registrar_refs.secret`                                                                                                                |
| Notifications | `webhook_endpoints.secret`, `webhook_endpoints.previous_secret`                                                                                                                           |
| Identity      | `kyc_documents.number`, `kyc_documents.files` (the images on the objects disk)                                                                                                            |
| Import        | `migration_runs.header`, `migration_runs.found_values`, `migration_records.raw`, `migration_records.normalized`, `migration_records.before`                                               |
| Core          | `settings.value` (secret settings, `enc:v1:`), `users.two_factor_secret`, `users.two_factor_recovery_codes`, `install_secrets.value`                                                      |

**Short-lived data, which expires by itself.** After a rotation these stay readable while the old key is in
`APP_PREVIOUS_KEYS`. When the old key is removed, whatever is left of them can no longer be read:

- queued jobs that carry encrypted payloads (mail, module commands, imports) and failed jobs waiting for a retry;
- sessions (`SESSION_ENCRYPT=true`, `SESSION_LIFETIME`, 120 minutes by default) and cookies, the "remember me" cookie
  included (people sign in again);
- EPP codes in shopping carts (`orders.cart_ttl_days`, 7 days; the client enters the code again);
- import uploads, deleted once they are staged.

Signed links (password resets, verification, KYC image links, previews) are checked against the previous keys as
well, so they keep working until they expire.

**Keyed hashes.** The import's email hashes (duplicate checks and the email-keyed id map) use an install secret
(`install_secrets`), not `APP_KEY`, so a rotation does not change them. On an install upgraded from an earlier
version, `php artisan migrate` pins the secret, and `security:reencrypt` pins it at its start if it is still missing.
Both first check which configured key reproduces a stored hash, from a staged email or the email of an imported
client. When several keys are configured and no hash can be checked (the staged rows were purged and the imported
clients changed their emails), both stop with a message instead of guessing (migrate fails, `security:reencrypt`
exits 2). The message says what to do next: restore the key the hashes were made with (the `APP_KEY` in use before the
rotation) as `APP_KEY`, or remove the stale key from `APP_PREVIOUS_KEYS`, then run `migrate` again. A key listed twice
(for example `APP_KEY` also in `APP_PREVIOUS_KEYS`) counts once, and so do a `base64:` key and its decoded form, so
one distinct key never makes a refusal. Upgrade and run `migrate` under the key in use first, and rotate in a later
restart. Key order in `APP_PREVIOUS_KEYS` does not matter for this, and neither does the number of rotations. Passkeys: Fortify derives the passkey user handle from
`APP_KEY` unless `PASSKEYS_USER_HANDLE_SECRET` is set. Existing passkeys keep working after a rotation, but set that
variable to a fixed random value before rotating, so new registrations keep the same handle.

## Where the key lives

Never in the image, the repository or a backup of the code. Pick one of these:

1. **Environment file, outside the code.** The Docker stack reads `deploy/.env` (`chmod 600`), which is git-ignored
   and not copied into the image (`deploy/Dockerfile.dockerignore`). A server install reads `billing/.env`
   (`chmod 600`, owned by the PHP user). systemd's `EnvironmentFile=` works the same way.
2. **Environment injection.** Real environment variables win over `.env`. A secret manager that starts the process
   with `APP_KEY` set (`EnvironmentFile=` written by the manager's agent, or a wrapper such as
   `APP_KEY="$(<manager> read …)" php artisan …`) needs nothing in `.env`.
3. **A file named by `APP_KEY_FILE`** (and `APP_PREVIOUS_KEYS_FILE`, keys separated by commas or new lines). Use an
   absolute path; a relative one is taken from the application directory. The app reads the file once, when it loads
   its configuration. A non-empty `APP_KEY` wins over the file, so leave it empty: `app:install` fails when both are
   set to different keys.
    - Docker: copy `deploy/compose.secrets.example.yaml` to `deploy/compose.secrets.yaml`, put the keys in files
      outside the repository (the example uses `/etc/mohar/secrets`, owned by UID 10001, mode 0400), and set
      `DEPLOY_COMPOSE_OVERRIDE=deploy/compose.secrets.yaml` for `deploy/bin/deploy`. Compose mounts them at
      `/run/secrets`. Manual `docker compose` commands then take `-f deploy/compose.secrets.yaml` as well.
    - systemd: `LoadCredential=app_key:/etc/mohar/app_key` with `Environment=APP_KEY_FILE=%d/app_key`.
    - Kubernetes: a secret mounted as a volume, with `APP_KEY_FILE` pointing at it.

The configuration cache embeds the key: `php artisan optimize` writes it to `bootstrap/cache/config.php`. In the Docker
stack that directory is a tmpfs per container. On a server, keep `bootstrap/cache` readable by the PHP user only and
out of backups.

`php artisan app:install` (every deploy runs it) checks the key. It fails when the key is missing, when `APP_KEY_FILE`
cannot be read, when the key or a previous key does not fit the cipher, when `APP_KEY` and `APP_KEY_FILE` (or
`APP_PREVIOUS_KEYS` and its file) hold different keys, and when the key file is under `public/`. In production it
warns when the key is a published one (the image build's placeholder, or the key in an example file), is not random,
sits in a key file inside the application directory, or is readable by other users in `.env` or in the cached
configuration (`bootstrap/cache/config.php`, which holds the key in plain text). It also warns when
`APP_PREVIOUS_KEYS` repeats the current key.

## Rotating the key

Rotate on a schedule, when someone who had the key leaves, and at once after a leak. A rotation is rolling. The new
key encrypts everything written from the restart on, the old key stays in `APP_PREVIOUS_KEYS` for reading,
`security:reencrypt` moves the stored values to the new key, and only then is the old key removed.

1. **Prepare.** Back up the database (production.md, Backups). Run `php artisan security:reencrypt --dry-run`: it
   must exit 0. Values that no key decrypts (exit code 1, listed by id) are already lost. Clear them or find their
   key before you start.
2. **Generate the new key** and store it in your password manager or secret store:
   `echo "base64:$(openssl rand -base64 32)"`.
3. **Change the settings.** `APP_KEY=<new key>`. Put the old key first in `APP_PREVIOUS_KEYS` and keep any older keys
   after it, comma-separated (the same goes for the files when you use `APP_KEY_FILE`).
4. **Restart every PHP process**, so no process encrypts with the old key any more:
    - Docker: `deploy/bin/deploy`. The new colour starts with the new settings, and the entrypoint caches the
      configuration again. Then run `deploy/bin/deploy retire`. The previous colour still has the old key as its
      current key and cannot read values encrypted with the new one, so a rollback after this point is not safe.
    - A server: `php artisan optimize` (the cached configuration holds the key), restart php-fpm, then
      `php artisan queue:restart` and any `schedule:work` process. The `schedule:run` cron job picks up the change by
      itself.
5. **Re-encrypt.** Docker: `docker compose -f deploy/compose.prod.yaml --profile <live colour> exec -T app-<live colour>
   php artisan security:reencrypt`. A server: `php artisan security:reencrypt`. The command reads each table in chunks
   (`--chunk`, 500 by default) and saves a checkpoint after each one. If it is interrupted, run it again and it
   continues; `--restart` starts over. `--limit=<n>` stops after n values, which splits a large install into several
   runs. `--only` re-encrypts just the named targets; the other targets are not checked, so it ends with exit 3
   and its audit entry is not marked complete. Lower `--chunk` for large `longText` tables (`server_account_imports.accounts`, `migration_records.raw`):
   one chunk is held in memory. A value the app writes during the run is left as the app wrote it. An identity image
   is written under a new name, recorded in the checkpoint, and the old file is deleted once the row points at the new
   one; a run killed in between finishes that on the next run. A real run needs every module loaded, so no module's
   data stays on the old key: it refuses to start (exit 2) when `MODULES` leaves modules out, unless you pass
   `--partial`, which names the skipped modules in the summary and the audit entry and ends with exit 3. The command
   writes one audit-log entry ("Stored secrets were re-encrypted …", event `security.reencrypted`) with the counts
   per target, never values.

    | Exit code | Meaning                                                                                                                                              |
    | --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
    | 0         | every stored value is on the current key                                                                                                             |
    | 1         | some values no configured key decrypts, or a write failed its check; they are unchanged and listed by id                                             |
    | 2         | invalid options, no `APP_KEY`, or modules not loaded (without `--partial`)                                                                           |
    | 3         | not finished: `--only` was given, stopped at `--limit`, the app wrote values during the run, modules were skipped, or (dry run) values on an old key |
    | 4         | another `security:reencrypt` run holds the lock; wait for it                                                                                         |

6. **Verify.** With every module loaded, `php artisan security:reencrypt --dry-run` exits 0. Sign in as staff with two-factor authentication,
   test one gateway connection (Admin → Setup → Gateways) and one server connection (Admin → Services → Servers), and open a KYC document if you use
   identity checks.
7. **Wait for the short-lived data** listed above: the queues drained and no failed jobs you still want to retry,
   one session lifetime, and the cart lifetime if you sell domain transfers.
8. **Remove the old key** from `APP_PREVIOUS_KEYS` (keep it in the password manager for as long as you keep backups
   taken before the rotation, see below) and restart again as in step 4. `app:install` stops reminding you about the
   previous keys.

**After a leak**, also change the credentials themselves (gateway API keys, server passwords and tokens, registrar
credentials, webhook signing secrets, `support.inbound_secret`), because database dumps taken before the rotation
can still be decrypted with the leaked key.

## Backups and old keys

Backups of the database hold ciphertext. Backups of the key are kept apart from them, and backups of the code or the
host never include the key:

- Exclude `.env` (`deploy/.env`, `billing/.env`) and `bootstrap/cache/config.php` from file and host backups (a
  snapshot of `/srv/billing`, a cPanel account backup, rsync of the app directory). Keep the key in a password
  manager or secret store instead.
- A dump taken before a rotation needs the key it was written with. Restore it with that key in `APP_PREVIOUS_KEYS`,
  then run `security:reencrypt`. Keep each old key for as long as you keep dumps taken under it.

## Later: envelope encryption

Every secret is encrypted directly with `APP_KEY`, so whoever has the key and a dump has the secrets. Not built yet:
envelope encryption for the most sensitive columns (gateway credentials, pay-method tokens), where each value is
encrypted with a data key that a key-encryption key in a KMS (AWS KMS, GCP KMS, HashiCorp Vault Transit) wraps. The
KMS key never leaves the KMS, access is audited there, and a rotation re-wraps the data keys without touching the
values. The target registry that `security:reencrypt` uses is where such columns would be listed.
