# Kernel API changelog

Changes to the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, registry
methods in `app/Support`, `App\Support\Money`). Until `API_VERSION` reaches 1.0.0, a breaking change is allowed when
the same PR updates every in-repo implementer and gets the principal-architect gate; it is listed here.

## Unreleased (pre-1.0)

### Integrations area (ADR 0009 §1, PR A)

- Added `App\Enums\FieldType` and `App\Data\Integrations\SettingField`, the one settings-field schema. A secret is a
  field type. `SettingField::fromArray()` reads the gateway and the older provisioning array shapes.
- Added `App\Contracts\Integrations\IntegrationDriver` (`key()`, `name()`), the identity every driver family shares.
- Added `App\Support\Integrations\DriverRegistry`, the base of the kernel-owned driver registries (ADR 0012 shape).
- Added `App\Support\Integrations\Redactor`. It merges Services' redactor with the Domains registrar key lists and
  adds `api-key`, `passwd` and `signature`. The mask is now `[redacted]` everywhere (Services logs used `***`).
- **Breaking:** moved `App\Data\Billing\ConnectionCheck` to `App\Data\Integrations\ConnectionCheck`.
- **Breaking:** moved `App\Contracts\Billing\ProvidesLogo` to `App\Contracts\Integrations\ProvidesLogo`, and renamed
  `App\Data\Billing\GatewayLogo` to `App\Data\Integrations\IntegrationLogo`. Khalti and eSewa are updated.
