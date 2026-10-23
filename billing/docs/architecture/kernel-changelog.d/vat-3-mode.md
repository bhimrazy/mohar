### Settings: a locked note on a definition (#432)

- `App\Support\Settings\SettingDefinition` has a new trailing constructor parameter, `?Closure $lockedNote = null`. When
  it returns text, `SettingsCatalog::fields()` puts that text before the field's help, so the generic settings form says
  why a value is fixed. Additive: existing definitions pass nothing and render as before.
