### Gateway drivers register by key and class; family-wide gateway helpers (ADR 0030 M3, #590)

- **Breaking.** A driver key must start with a lowercase letter: `DriverRegistry::KEY` is `[a-z][a-z0-9_]{0,49}`
  (it was `[a-z0-9_]{1,50}`). It is public, and Billing's gateway webhook, return and admin routes use it, so every key
  the registry accepts can be routed. No bundled or known driver key is affected.
- New `App\Support\Billing\GatewayDriverRegistry` (`@api`, extends `DriverRegistry<GatewayDriver>`), bound in
  `AppServiceProvider`, so a package registers in its own `boot()` whether or not Billing is loaded:
  `register('khalti', KhaltiDriver::class)`. Reserved keys (`import`) and duplicates throw (`replace: true` replaces).
  `fields(key)` and `fieldsOf(driver)` return `list<SettingField>`. Capability names: `hosted`, `test_connection`,
  `logo`, `refund_lookup`, `cancel_hosted`, `resume_hosted`, `manual_refund_note`, `validates_settings`. Additive.
- `DriverRegistry::enabled(string $key): bool`, the new-work gate of ADR 0030 A1; equal to `has()` until extensions
  can be disabled (M5a). Additive.
- `GatewayRegistry::register(GatewayDriver)` is deprecated since 0.2.0 and removed in 1.0.0. It keeps working as an
  adapter: the instance is reused, and a key registered again is replaced (from M4, only by the same owner). It needs
  Billing to be loaded, and it raises one `E_USER_DEPRECATED` per process.
- `GatewayDriver::settingsFields()` returns `SettingField` objects. The array shape is deprecated since 0.2.0 and
  removed in 1.0.0. It is still read through `SettingField::fromArray()`, with one `E_USER_DEPRECATED` per driver key
  and process.
- `App\Data\Billing\GatewayConfig` has a new last constructor parameter `int $httpTimeoutSeconds = 15` (the staff
  setting `billing.gateways.http_timeout_seconds`, at least 3). Additive.
- New `App\Support\Billing\GatewayHttp` (`@api`): `for('<extension>.http')`, then `request()`, `interactive()` and
  `lookup()`, plus `retryable()` and `endpoint()` for pinned https endpoints.
- New `App\Support\MinorUnits` (`@api`): `decimals()` through `CurrencyDirectory`, `rescale()`, `toDecimal()` and
  `fromDecimal()`, with `MAX_DECIMALS = 18`. Amounts are exact or refused, never rounded. `rescale()` and `fromDecimal()`
  return null for a negative amount or on overflow.
- New `App\Support\Billing\GatewayConformance::assertDriver($key)` and `GatewayConformanceFailure` (`@api`), the
  conformance kit for gateway drivers (ADR 0009 Amendment (#515)).
