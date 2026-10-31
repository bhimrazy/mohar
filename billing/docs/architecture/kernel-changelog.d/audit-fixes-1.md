### Declared-key masking on `Redactor` (audit-fixes-1)

- `App\Support\Integrations\Redactor` has four new public methods. Additive.
    - `declaredKeys(?object $driver, string $operation)`: the keys a driver declares through `DeclaresSensitiveKeys`
      (`[]` when it declares none).
    - `valuesUnder(array $sensitiveKeys, array ...$payloads): ?array`: the values under those keys. They are read from
      arrays, JSON string leaves, and `<key>…</key>` elements or `key=…` pairs in other strings, with decoded forms.
      Values under 4 bytes are left out. Returns `null` when a payload cannot be read in full (node limit, or a key
      used as a name in any other form): the host withholds the payload and its text.
    - `maskDeclared(string $text, ?array $values, array $secrets = [])`: `maskValues()` plus every occurrence of a
      declared value. A value under 8 bytes is matched only as a whole token. With `null` values it returns
      `[redacted]`.
    - `redactDeclared(array $payload, ?array $values, array $secrets, array $sensitiveKeys)`: `redact()` with the
      declared keys, then every occurrence of a declared value replaced in every string. With `null` values it
      returns `['withheld' => '[redacted]']`.
- `DeclaresSensitiveKeys` is now read by both hosts, `ExecuteModuleCommand` and `RegistrarGateway` (ADR 0023 §5),
  before the driver is called. The interface is unchanged.
