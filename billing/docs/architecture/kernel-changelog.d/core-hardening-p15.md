### One-time password expiry and the step-up download URL (#459; #60, #305)

- `users.password_expires_at` (nullable timestamp) and `User::passwordExpired()`. The `User` saving hook clears it
  with `must_change_password` whenever the password changes, unless the same save sets a new expiry. Additive:
  existing rows are null and never expire.
- `App\Actions\Fortify\RefuseExpiredPassword::check($user, $stage, ?$field, ?$redirectTo)` refuses an expired
  one-time password. It is called from `RememberValidatedLogin` and `SignInValidatedUser`, from the confirm-password
  callback and from a two-factor completion listener (both in `FortifyServiceProvider`).
  `RequireRecentPasswordConfirmation::recentlyConfirmed()` is false for such a login, and
  `App\Http\Middleware\RequirePasswordUnlessExpired` is bound in place of Laravel's `RequirePassword`
  (`password.confirm`) with the same rule. `currentPasswordRules()` refuses it as the current password. Rules: ADR
  0018 amendment #459.
- `RequireRecentPasswordConfirmation::DOWNLOAD_KEY` and `::confirmingDownload(Request)`. The session value changes
  from `true` to the file URL; `PasswordConfirmedResponse` and the confirm view read it through
  `confirmingDownload()`.
