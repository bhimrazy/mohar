### Certificate settings and gateway credential expiry (#489)

- `App\Enums\FieldType` has a new case `Certificate` (`certificate`); `isSecret()` is true for it. Closed enum, added
  before 1.0 with every in-repo renderer updated (ADR 0009 Amendment (#489)).
- New `App\Data\Integrations\CertificateInfo` and `App\Support\Integrations\CertificateBundle` (with
  `CertificateRefused`): read a PKCS#12 upload in memory into a PEM key and its metadata.
- `App\Data\Billing\GatewayConfig` gains `CERTIFICATES` and `certificate(string $key): ?CertificateInfo`. Additive.
- New event `App\Events\Billing\GatewayCredentialExpiring`.
- `App\Contracts\Billing\GatewayDriver::settingsFields()` documents the `certificate` type.
- `App\Support\Dashboard\Dashboard::health()` resolvers may return null to leave the check out. Additive: existing
  resolvers return arrays as before.
