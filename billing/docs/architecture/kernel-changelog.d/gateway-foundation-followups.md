### Drivers are validated on resolve; listings skip invalid ones (#490)

- `App\Support\Integrations\DriverRegistry::get()` calls a new protected hook `validate(IntegrationDriver $driver)`
  (default: nothing). `RegistrarDriverRegistry` and `ProvisioningDriverRegistry` move their certificate-setting refusal
  there, so `get()` throws for such a driver, not only `fields()`/`metadata()`.
- `DriverRegistry::keys()`, `all()` and `has()` now resolve each driver to check it and leave out, with `report()`, one
  that fails `get()`'s checks. Breaking under ADR 0010 (`keys()` and `has()` now construct drivers, and a
  misregistered driver is no longer listed); allowed pre-1.0. A failure of the family's checks is remembered for
  `DriverRegistry::FAILURE_TTL` (60) seconds; other failures are checked again on the next call.
- New `DriverRegistry::isRegistered(string $key): bool` (every registered key) and `unavailable(): array<string,
  string>` (hidden drivers with a reason). Additive.
