### `WebhookEvents` registry for webhook types (#PR)

Added `App\Support\Events\WebhookEvents` (singleton in `AppServiceProvider`, ADR 0011 and 0012 shape): `register(event, type, entity, idProperty, replace)`, `has()`, `forEvent()`, `events()`, `types()`, `keys()`. Core, Orders, Billing, Services, Domains and Support register their own types; Notifications' `WebhookEventTypes` is removed and its sender, publisher, form and listeners read the registry. Packages register in `boot()`.
