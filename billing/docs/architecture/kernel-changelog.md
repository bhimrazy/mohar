# Kernel API changelog

Changes to the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, registry
methods in `app/Support`, `App\Support\Money`). Until `API_VERSION` reaches 1.0.0, a breaking change is allowed when
the same PR updates every in-repo implementer and gets the principal-architect gate; it is listed here.

## Unreleased (pre-1.0)

### Integrations area (ADR 0009 §1, PR A)

- Added `App\Enums\FieldType` and `App\Data\Integrations\SettingField`, the one settings-field schema. A secret is a
  field type. `SettingField::fromArray()` reads the gateway and the older provisioning array shapes. `optionsSource`
  (for example `remote:packages`) lets a select take its options from the remote system (cPanel spec K2).
- Added `App\Contracts\Integrations\IntegrationDriver` (`key()`, `name()`), the identity every driver family shares.
- Added `App\Support\Integrations\DriverRegistry`, the base of the kernel-owned driver registries (ADR 0012 shape).
- Added `App\Support\Integrations\Redactor`, the one redaction rule for the gateway log, the module log and the
  registrar command log (Services' redactor, the Domains registrar key lists and Billing's `GatewayLogger` rules,
  merged). Keys are matched by word (`api_key`, `ApiKey`, `X-Api-Key` alike; `author` and `country_code` stay
  visible); objects are read as arrays before matching; Luhn-valid card numbers and cPanel `cpsess…` tokens are
  masked in any string; a payload can name extra secret keys (`sensitiveKeys: ['url']` for SSO answers, `['code']`
  for EPP code answers); `excerpt()` makes raw driver output safe (redacted, 4 KB). The mask is `[redacted]`
  everywhere (Services logs used `***`), and `[redacted-pan]` for card numbers.
- Added `App\Support\Integrations\OutboundAddressGuard` with `App\Data\Integrations\ResolvedAddress` and
  `OutboundAddressRefused` (ADR 0015, proposed). `resolve(host, port)` applies the install-wide policy in
  `config/integrations.php`, refuses non-public addresses (IPv6 allow-listed, embedded IPv4 judged), and `pin()` binds
  the request to the checked IP, bypasses proxies and follows no redirects.
- **Breaking:** moved `App\Data\Billing\ConnectionCheck` to `App\Data\Integrations\ConnectionCheck`.
- **Breaking:** moved `App\Contracts\Billing\ProvidesLogo` to `App\Contracts\Integrations\ProvidesLogo`, and renamed
  `App\Data\Billing\GatewayLogo` to `App\Data\Integrations\IntegrationLogo`. Khalti and eSewa are updated.
