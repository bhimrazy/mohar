### `SettingDefinition`: `multiselect` and `money` types, options from a closure (P7a)

- `App\Support\Settings\SettingDefinition::$type` also accepts `multiselect` (stores a list of option values, in
  option order) and `money` (entered in currency units, stores integer minor units).
- `$options` accepts `Closure(): array<int|string, string>` as well as an array, so a module can list database rows
  (TLDs, departments, registrars). Read the choices with the new `options()` method; reading `$options` directly
  can now return a closure. Additive for definitions; a reader of `$options` should switch to `options()`.
