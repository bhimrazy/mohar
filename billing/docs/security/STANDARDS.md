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
  `Strict-Transport-Security` on HTTPS.
- Production sets `SESSION_SECURE_COOKIE=true`.

Do not add third-party script or style origins. If a feature really needs one, change the policy in that middleware
and get the security reviewer's sign-off.

## 2. Sensitive-permission tier and step-up authentication

- Register high-risk permissions as sensitive:
  `PermissionRegistry::register($group, $permissions, sensitive: ['module.area.action', 'module.area.*'])`.
  Examples are impersonation, merges, closing accounts, staff and role management, security settings, refunds and
  gateway or server credentials.
- `Role::allows()` grants a sensitive permission only by its exact key or by `*` (the full-administrator grant).
  A `module.*` or `module.area.*` wildcard never covers it. So a permission added later never reaches existing roles
  silently.
- `PermissionGuard::canGrant()` applies the same rule. Staff can grant only what they hold, and sensitive permissions
  only if they hold them explicitly.
- Every route that mutates something behind a sensitive permission uses the `password.recent` middleware. The
  password must have been confirmed within `platform.password_confirmation_seconds` (default 900). Pages that edit
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

## 5. Secrets

- Settings marked `secret: true` are write-only. They are never in Inertia props or HTML, are masked in the audit
  diff, and a blank submission keeps the stored value.
- Module credentials (gateways, registrars, servers) are encrypted at rest with Laravel's encrypter, never cached in
  plaintext, and never sent to the browser. Tests assert that no secret value appears in any rendered page.
- Tokens in URLs (invitations, password resets) are hashed at rest, single-use, expiring, and served with
  `Referrer-Policy: no-referrer`.
