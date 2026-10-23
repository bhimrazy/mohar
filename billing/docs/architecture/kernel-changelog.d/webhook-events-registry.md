### `WebhookEvents` registry for webhook types (#405)

- Added `App\Support\Events\WebhookEvents` (singleton in `AppServiceProvider`; event-keyed, see the ADR 0012 amendment): `register(event, type, entity, idProperty, replace)`, `has()`, `forEvent()`, `events()`, `types()`. Kernel-owned types cannot be replaced. Earlier entries that name `WebhookEventTypes::MAP` are history. Core, Orders, Billing, Services, Domains and Support register their own types; Notifications' `WebhookEventTypes` is removed and its sender, publisher, form and listeners read the registry. Packages register in `boot()`.
