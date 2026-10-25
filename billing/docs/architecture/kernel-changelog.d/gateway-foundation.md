### Certificate settings and gateway credential expiry (#489)

- `App\Enums\FieldType` has a new case `Certificate` (`certificate`). Breaking under ADR 0010 (closed enum); allowed
  pre-1.0 because every in-repo consumer is updated: the gateway form, `RegistrarAccountRequest` and `ServerSettings`
  (which refuse it), and `RegistrarDriverRegistry::fields()` and `ProvisioningDriverRegistry::metadata()` (which throw
  for a driver declaring it). `isSecret()` is true for it, so it joins `GatewayConfig::$secretSettingKeys` and every
  form's secret input paths.
- `App\Data\Integrations\SettingField` refuses keys starting with `_` (reserved for host entries).
- New `App\Data\Integrations\CertificateInfo` (with `SETTINGS_KEY`), `App\Support\Integrations\CertificateBundle`
  (`MAX_BYTES` = 64 KB) and `CertificateRefused`; the last two are internal to hosts, not `@api`.
- `App\Data\Billing\GatewayConfig::certificate(string $key): ?CertificateInfo`. Additive.
- New events `App\Events\Billing\GatewayCredentialExpiring` and `GatewayCredentialExpired`, with UTC
  `CarbonImmutable` instants.
- `App\Contracts\Billing\GatewayDriver::settingsFields()` documents the `certificate` type.
- `App\Support\Dashboard\Dashboard::health()` resolvers may return null to leave the check out. Additive.
- `App\Http\Middleware\RequireRecentPasswordConfirmation` redirects a refused mutation back with its error and no old
  input (it threw a validation exception, which flashed the input).
