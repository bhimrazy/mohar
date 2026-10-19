# Security standards

Binding for every module. They come from the platform-admin security review (PR #3) and apply to all new code.
Each rule has an enforcement point; reviews check for it.

## 1. Security headers on every web response

`App\Modules\Core\Http\Middleware\SecurityHeaders` (web group) sends:

- `Content-Security-Policy`: `script-src 'self' 'nonce-…'` (the Vite nonce from `Vite::useCspNonce()`), no inline
  scripts without `nonce="{{ Vite::cspNonce() }}"`, `frame-ancestors 'none'`, `object-src 'none'`,
  `base-uri 'self'`, `form-action 'self'`. The Vite dev-server origin is added only while `public/hot` exists.
- `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`
  (`no-referrer` on pages with a token in the URL), a minimal `Permissions-Policy`, and
  `Strict-Transport-Security` on HTTPS. It is global middleware, so 404s, error pages and auth redirects carry the
  headers too.
- Production sets `SESSION_SECURE_COOKIE=true`.

Do not add third-party script or style origins. If a feature really needs one, change the policy in that middleware
and get the security reviewer's sign-off.

## 2. Sensitive-permission tier and step-up authentication

- Register high-risk permissions as sensitive:
  `PermissionRegistry::register($group, $permissions, sensitive: ['module.area.action', 'module.area.*'])`.
  Examples are impersonation, merges, closing accounts, client-login management (adding logins, changing the owner,
  2FA resets), staff and role management, security settings, refunds and gateway or server credentials.
- `Role::allows()` grants a sensitive permission only by its exact key or by `*` (the full-administrator grant).
  A `module.*` or `module.area.*` wildcard never covers it. So a permission added later never reaches existing roles
  silently.
- `PermissionGuard::canGrant()` applies the same rule. Staff can grant only what they hold, and sensitive permissions
  only if they hold them explicitly.
- Every route that mutates something behind a sensitive permission uses the `password.recent` middleware. The
  password must have been confirmed within `auth.password_timeout` (`AUTH_PASSWORD_TIMEOUT`, default 900). Pages that edit
  sensitive data use `password.confirm`. The UI wraps sensitive buttons in `useSensitiveAction()`, which asks for the
  password and retries.
- Seeded roles list their permissions explicitly. Seeders update existing system roles; they don't only
  `firstOrCreate`.

## 3. Security-event notifications

Email the affected person, and the full administrators for platform-wide changes, with
`App\Modules\Platform\Notifications\SecurityNotice`, when:

- a staff member's sign-in email changes (to the old address; an admin-initiated change also clears verification),
- any 2FA is reset by an administrator (to the user),
- a security setting changes (2FA policy, lockout, impersonation limits) (to every active full administrator).

Dispatch the matching domain event as well (`TwoFactorReset`, `SettingsUpdated`, `StaffDisabled`, ...).

## 4. Robust, append-only audit log

- Write through `ActivityLogger::log()` only. It strips control characters, truncates `description` (255),
  `event` (100), `user_agent` (255) and string properties (1000) multibyte-safely.
- Descriptions are fixed sentences. Put user-supplied values (emails, names, search terms) in `$properties`. Never
  interpolate unbounded input, and never log secrets.
- `ActivityLog` models cannot be updated or deleted. Retention pruning uses a query-builder delete in the
  housekeeping task.
- The request user and IP are recorded only for real HTTP requests (a matched route). Console and queued work is
  recorded as `system`.

## 5. Search input

- User search terms are literal. Build every LIKE through `App\Modules\Core\Support\Like` (`contains()` /
  `startsWith()` + `Like::where()`). It escapes `%` and `_` with `ESCAPE '!'` (portable to SQLite, PostgreSQL and
  MySQL) and compares case-insensitively.

## 6. Secrets

- Settings marked `secret: true` are encrypted at rest by `Settings` (`enc:v1:` + `Crypt::encryptString` of
  `{key, value}`, so ciphertext is bound to its key). Only registered secrets are ever decrypted. The cache
  holds only the ciphertext, and only `get()` decrypts. They are write-only: never in Inertia props or HTML, masked in
  the audit diff, and a blank submission keeps the stored value.
- Module credentials (gateways, registrars, servers) are encrypted at rest with Laravel's encrypter, never cached in
  plaintext, and never sent to the browser. Tests assert that no secret value appears in any rendered page.
- Secret fields are never written to the session: they are excluded from flashed old input on validation
  errors, driven by the `SettingField` secret flag rather than a list of names. (Added 2026-10-18 from the #65
  review.)
- Tokens in URLs (invitations, password resets) are hashed at rest, single-use, expiring, and served with
  `Referrer-Policy: no-referrer`.

## 7. External accounts and resources

- A driver adopts an existing remote resource (hosting account, domain, mailbox) only with proof that this service
  created it: an earlier attempt whose outcome is unknown, with the exact identifiers stored before sending, and a
  remote creation time at or after that attempt. A matching name, domain or owner alone is never proof; anything
  else is a conflict for staff. (Added 2026-10-18 from the #69 security review.)

## Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a fresh `APP_KEY` (and `APP_PREVIOUS_KEYS` when rotating, so
      encrypted settings stay readable).
- [ ] Installed with `php artisan app:install` (`docs/devops/install.md`), never with demo seeders: no
      `admin@example.com` or other `password` accounts exist.
- [ ] **Trusted proxies** configured for the load balancer (`$middleware->trustProxies(at: [...])`, see
      `docs/devops/local-setup.md`). Without it, the login lockout keys on the proxy IP, the audit log records the
      proxy, and HSTS is never sent.
- [ ] HTTPS only. `SESSION_SECURE_COOKIE=true`. Check that `Strict-Transport-Security` and
      `Content-Security-Policy` headers are present on `/login`, on a 404 and on `/admin` as a guest.
- [ ] `security.staff_2fa_required` is on (the demo seeder turns it off locally only) and every full administrator
      has 2FA.
- [ ] The scheduler and a queue worker are running (the dashboard flags automation that has not run for 26 h).
- [ ] Mail is configured, because security notices (email changes, 2FA resets, ownership transfers) depend on it.
- [ ] The `dev/components` showcase is not reachable (registered only in `local`).
