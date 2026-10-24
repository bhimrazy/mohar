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
- **Client dashboard controls (#31).** The Domains panel has an auto-renew switch and, for domains due soon or
  expired, Renew now with a confirmation that states the term, the price and that an invoice is created. The Support
  panel has New ticket and Help articles buttons.

### Changed

- **Old product links keep working.** `/store/products/{id}` redirects permanently to the new link, with `?domain=` and
  `?item=` kept. A product opened under the wrong group redirects to its own group.
- **Reserved group slugs.** A product group can no longer use `cart`, `checkout`, `domains`, `currency`, `orders` or
  `products` as its slug, since those are store paths. Upgrade note: a group that already uses one of them keeps its
  products reachable through the numeric link, but its own page is not.
- **Change plan starts with nothing chosen.** Other plans and other billing cycles of the current plan are listed
  separately with their prices; what is charged or credited appears only once a choice is made.

### Fixed

- **A signed-in customer's cart explains the group discount.** The cart and checkout show the subtotal at list prices
  and a line such as "Client discount (Resellers, 10%)", so a total that dropped after sign-in adds up. Prices are unchanged.
- **New ticket: "Send request" says why it is disabled** until a department is chosen.
