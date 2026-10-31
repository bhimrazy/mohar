### Frontend translations: `Catalogue`, Inertia props, module `Resources/lang` (#606)

- New `App\Support\Localization\Catalogue`, internal to the app (not `@api`): read by `HandleInertiaRequests` and the two commands; modules do not call it.
- Inertia shared props gain `locale` and `translations` (a once prop keyed `i18n:{locale}:{version}`). Additive: pages that ignore them are unaffected.
- `ModuleServiceProvider::boot()` loads `<Module>/Resources/lang/*.json` with `loadJsonTranslationsFrom` when the folder exists. Additive.
- New commands `i18n:cache` and `i18n:clear`, run by `php artisan optimize` and `optimize:clear`.
