### Short stored secrets: `SettingField::$minLength` and `StoredSecrets` (ADR 0023 step 3c, #400)

- `App\Data\Integrations\SettingField` has a new last constructor parameter `?int $minLength = null` (array key
  `min_length`). It is allowed only on a secret field and only from 4 (`MIN_SECRET_FLOOR`); otherwise the constructor
  throws. New `minimumSecretLength()` returns the declared value, or `MIN_SECRET_BYTES` (8). New `isTooShort(mixed)` is
  true for a non-empty trimmed secret under the minimum. `toArray()` gains `min_length`: the effective minimum for a
  secret, null otherwise. `ProvisioningDrivers::all()` documents the key in `fields`. Additive: existing fields and
  drivers need no change.
- New rule `App\Support\Integrations\SecretMinimumLength`, a `ValidationRule` over one `SettingField`. An empty value
  passes, since it keeps the stored one.
- New registry `App\Support\Integrations\StoredSecrets` (singleton in `AppServiceProvider`):
    - `register(string $key, Closure $source)`: the key matches `[a-z0-9_]{1,50}` and is registered once;
    - `check()`, which returns `short` findings and the `unreadable` records;
    - the static `tooShort(array $fields, array $values)`.

    A source yields `owner`, `fields`, and `values` as a closure that decrypts. A `DecryptException` from that closure
    marks the record unreadable, and the check goes on. Findings carry names and the minimum, never values. Billing
    (`gateways`), Domains (`registrars`) and Services (`servers`, settings plus the row's credentials) register
    sources. `integrations:short-secrets` (Core) prints them.
