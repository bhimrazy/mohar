### Gateway drivers register by key and class; family-wide gateway helpers (ADR 0030 M3)

- New `App\Support\Billing\GatewayDriverRegistry` (`@api`, extends `DriverRegistry<GatewayDriver>`), bound in
  `AppServiceProvider`, so a package registers in its own `boot()` whether or not Billing is loaded:
  `register('khalti', KhaltiDriver::class)`. Reserved keys (`import`) and duplicates throw (`replace: true` replaces).
  `fields(key)` and `fieldsOf(driver)` return `list<SettingField>`. Capability names: `hosted`, `test_connection`,
  `logo`, `refund_lookup`, `cancel_hosted`, `resume_hosted`, `manual_refund_note`, `validates_settings`. Additive.
- `DriverRegistry::enabled(string $key): bool`, the new-work gate of ADR 0030 A1; equal to `has()` until extensions
  can be disabled (M5a). Additive.
- `GatewayRegistry::register(GatewayDriver)` is deprecated. It keeps working as an adapter (the instance is reused and
  a key registered again is replaced), but needs Billing to be loaded.
- `GatewayDriver::settingsFields()` is documented as `list<SettingField>`. The array shape is deprecated and still read
  through `SettingField::fromArray()`, so existing drivers need no change.
- `App\Data\Billing\GatewayConfig` has a new last constructor parameter `int $httpTimeoutSeconds = 15` (the staff
  setting `billing.gateways.http_timeout_seconds`, at least 3). Additive.
- New `App\Support\Billing\GatewayHttp` (`@api`): `for('<extension>.http')`, then `request()`, `interactive()` and
  `lookup()`, plus `retryable()` and `endpoint()` for pinned https endpoints.
- New `App\Support\MinorUnits` (`@api`): `decimals()` through `CurrencyDirectory`, `rescale()`, `toDecimal()` and
  `fromDecimal()`, exact or refused, never rounded.
- New `App\Support\Billing\GatewayConformance::assertDriver($key)` and `GatewayConformanceFailure` (`@api`), the
  conformance kit for gateway drivers (ADR 0009 Amendment (#515)).
