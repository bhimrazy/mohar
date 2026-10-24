### `ProductSummary::$groupSlug`, store product slugs and dashboard list controls (P8b)

- `App\Data\Catalog\ProductSummary` has a new last constructor parameter `?string $groupSlug = null`, the slug of the
  product's group. Additive: existing implementers and callers need no change; a null value means the group is unknown.
- Each product in `PriceBook::storefront()` carries `slug` (string, the product's unique slug). The docblock shape is
  updated; Catalog already sent the key. Fakes that implement `PriceBook` should add it.
- `Dashboard` `list` widgets (docblock in `App\Support\Dashboard\Dashboard`), all optional: an item's `action` may add
  `method` (`'post'`) and `confirm` => `['title', 'description', 'confirmLabel', 'data'?]`; an item may add `toggle` =>
  `['label', 'text'?, 'href' (PUT), 'field', 'on']`; a list may add `actions` => `[['label', 'href', 'icon'?]]`. The
  admin and client dashboards render them; widgets that do not send them are unchanged.
