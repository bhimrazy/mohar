### Added

- **Signed-in customers shop without leaving the client area (#37).** The store, product pages, cart, checkout and
  domain search open inside the client area for a signed-in customer, with the cart and the account currency in the
  header. Guests keep the public site.
- **One domain search page (#37).** The store's domain search now shows whether each name is available, taken (offer a
  transfer) or registered by the customer at the registry (`.np`, with "How to register" and "I already have it"), and
  lists available suggestions. `/domains/search` redirects to it when the store is installed. The extensions priced
  next to a name are the ones the host spotlights.
- **Store product groups and products have readable links.** A group opens at `/store/{group}` and a product at
  `/store/{group}/{product}`. The header's group links, the plan buttons, the cart's Edit link and the sitemap use them.
- **Client dashboard controls (#31).** The Domains panel has an auto-renew switch and, for domains due within 30 days
  or in grace or redemption, Renew now. Its confirmation states the term and the invoice amount before tax, including
  the grace or redemption fee, and says tax is added where it applies. A domain whose renewal invoice is already open
  shows Pay invoice instead. A refused auto-renew change shows the reason. Renew now accepts 10 requests a minute per
  client. The Support panel has New ticket and Help articles buttons.
- **"Register a domain" opens the store's domain search** when the store is installed, in the client sidebar and the
  dashboard's empty Domains panel. `/domains/search` stays as the search when it is not.

### Changed

- **Old product links keep working.** `/store/products/{id}` redirects permanently to the new link, with `?domain=` and
  `?item=` kept. A product opened under the wrong group redirects to its own group.
- **Reserved group slugs.** A product group can no longer use `cart`, `checkout`, `domains`, `currency`, `orders` or
  `products` as its slug, since those are store paths. Upgrade note: a migration renames a group that already uses one
  to `<slug>-plans` (or `<slug>-plans-2` and so on when that is taken) and records each rename in the activity log
  (event `product_group.slug_renamed`). Running it again changes nothing. Links to the old `/store/<slug>` group page
  stop working; they were never product links.
- **Change plan starts with nothing chosen.** Other plans and other billing cycles of the current plan are listed
  separately with their prices; what is charged or credited appears only once a choice is made.

### Fixed

- **A signed-in customer's cart explains the group discount.** The cart and checkout show the subtotal at list prices
  and a line such as "Client discount (Resellers, 10%)", so a total that dropped after sign-in adds up. Prices are unchanged.
- **New ticket: "Send request" says why it is disabled** until a department is chosen.
- **Domain search prices for guests are in the currency they chose.** The availability lookup now uses the store's
  currency, so suggested names and `.np` rows are no longer shown in another currency's amounts.
