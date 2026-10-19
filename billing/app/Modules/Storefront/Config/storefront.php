<?php

/*
| Storefront defaults. Website content (headline, sections, SEO, legal links, price display) is edited in
| Admin → Website and stored as revisions, not here.
*/
return [
    // Public-site payloads (header groups, prices, payment marks, help content) are cached per currency for this many
    // seconds; publishing and catalog, gateway, settings and support events flush them sooner.
    'cache_seconds' => 600,

    // Signed preview links for the draft stay valid this many minutes.
    'preview_minutes' => 60,

    // The market each currency is priced for (ISO country): its tax statement and tax-inclusive prices use Billing's
    // tax rules for that country. Currencies not listed (USD, sold across markets) show the neutral statement.
    'tax_markets' => ['NPR' => 'NP', 'INR' => 'IN'],

    // Route names (or prefixes ending in ".") that render in the public layout and receive the shared "site" prop.
    'public_routes' => ['home', 'storefront.', 'store.', 'domains.', 'support.kb.', 'support.announcements.', 'support.network.', 'support.tickets.'],
];
