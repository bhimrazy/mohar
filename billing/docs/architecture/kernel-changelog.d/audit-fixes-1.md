### Declared-key masking on `Redactor` (audit-fixes-1)

- `App\Support\Integrations\Redactor` has four new public methods. Additive.
    - `declaredKeys(?object $driver, string $operation)`: the keys a driver declares through `DeclaresSensitiveKeys`
      (`[]` when it declares none).
    - `valuesUnder(array $sensitiveKeys, array ...$payloads): ?array`: the values under those keys, in nested arrays,
      JSON string leaves and what `redact()` masks by key in XML and `name=value` text. `null` when the payloads
      cannot be read in full (node limit): the host withholds the text.
    - `maskDeclared(string $text, ?array $values, array $secrets = [])`: `maskValues()` plus declared values; a value
      under 8 bytes is matched only as a whole token; `null` values return `[redacted]`.
    - `redactDeclared(array $payload, ?array $values, array $secrets, array $sensitiveKeys)`: `redact()` with the
      declared keys and the declared values long enough to match as substrings.
- `DeclaresSensitiveKeys` is now read by both hosts, `ExecuteModuleCommand` and `RegistrarGateway` (ADR 0023 §5),
  before the driver is called. The interface is unchanged.
