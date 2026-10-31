### `Redactor::declaredKeys()` and `Redactor::valuesUnder()` (audit-fixes-1)

- `App\Support\Integrations\Redactor` has two new public methods. `declaredKeys(?object $driver, string $operation)`
  returns the keys a driver declares through `DeclaresSensitiveKeys` (`[]` when it declares none).
  `valuesUnder(array $sensitiveKeys, array ...$payloads)` returns the scalar values under those keys, nested arrays
  included, for masking by value. Additive.
- `DeclaresSensitiveKeys` is now read by both hosts, `ExecuteModuleCommand` and `RegistrarGateway` (ADR 0023 §5).
  The interface is unchanged.
