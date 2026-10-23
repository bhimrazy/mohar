### `SettingDefinition` type `country` (#440)

- `App\Support\Settings\SettingDefinition::$type` accepts `'country'`: the settings form shows a searchable list of
  country names and stores the ISO 3166-1 alpha-2 code (upper-case, `UK` and `EL` mapped to `GB` and `GR`). `$rules` is
  now documented as `list<mixed>` (rule objects and closures were already accepted). Additive.
- `App\Support\Countries` gains `EU` and `normalize()`.
