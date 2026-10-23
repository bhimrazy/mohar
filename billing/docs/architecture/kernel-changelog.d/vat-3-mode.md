### `SettingLocks`, locked settings (#432)

- New kernel registry `App\Support\Settings\SettingLocks` (singleton): `lock(array $keys, Closure $reason, ?string $guard = null)`
  and `reason(string $key): ?string`. `$reason` returns text while the keys are fixed, null otherwise, and must read the
  database. `SettingsCatalog::fields()` has a new field `locked_reason`; the generic settings form shows such a field disabled
  with the reason. `UpdateSettings` refuses a changed value of a locked key with a `ValidationException`, under a shared lock on
  the `$guard` settings row (created as JSON null when missing), so a module that takes the row `FOR UPDATE` to switch the
  lock on cannot interleave with a save. Additive: `SettingDefinition` is unchanged.
- New middleware alias `feature` (`App\Http\Middleware\RequireFeature`): `feature:billing.nepal_vat_mode` answers 404 unless
  `config('features.billing.nepal_vat_mode')` is on. New `config/features.php` for unfinished work (architecture.md).
