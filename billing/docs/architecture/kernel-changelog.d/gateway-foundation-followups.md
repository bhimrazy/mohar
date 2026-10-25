### Drivers are validated on resolve; listings skip invalid ones (#490)

- `App\Support\Integrations\DriverRegistry::get()` calls a new protected hook `validate(IntegrationDriver $driver)`
  (default: nothing). `RegistrarDriverRegistry` and `ProvisioningDriverRegistry` move their certificate-setting refusal
  there, so `get()` throws for such a driver, not only `fields()`/`metadata()`.
- `DriverRegistry::keys()` (and so `all()`) now resolves each driver once per process and leaves out, with `report()`,
  one that fails `get()`'s checks. Behaviour change: a misregistered driver no longer appears in listings; `has()` still
  reports every registered key.
