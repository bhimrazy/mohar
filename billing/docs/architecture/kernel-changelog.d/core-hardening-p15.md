### One-time password expiry and the step-up download key (#60, #305)

- `users.password_expires_at` (nullable timestamp) and `User::passwordExpired()`. The `User` saving hook clears it
  with `must_change_password` whenever the password changes. A password sign-in with an expired password is refused
  in the Fortify pipeline (`App\Actions\Fortify\RefuseExpiredPassword`, called from `RememberValidatedLogin` and
  `SignInValidatedUser`). Additive: existing rows are null and never expire.
- `RequireRecentPasswordConfirmation::DOWNLOAD_KEY` and `::confirmingDownload(Request)`. The session value is now the
  file URL, not `true`; `PasswordConfirmedResponse` and the confirm view read it through `confirmingDownload()`.
- `RunAutomation::inProgress()` reads the lock with `Lock::isLocked()` instead of acquiring it. Laravel's array lock
  ignores expiry in `isLocked()`, so a test that travels past a lock's TTL uses a file store of its own.
- `InstallCommand::LOCK` (`app:install`) is held from the pre-flight to the end of the run.
