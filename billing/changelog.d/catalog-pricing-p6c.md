### Added

- **Tax market per currency (#118).** Admin → Website → Prices and tax now has a "Priced for" country for each currency, next to its tax note, replacing the `storefront.tax_markets` config edit. The config value stays the default until you choose one; "Several markets" shows prices without tax and the neutral note. It is part of the draft, so it is previewed and published like the rest of the page.
- **Unpriced products explain themselves (#247).** A product with no price in the visitor's currency stays out of the store; its direct page now says it is not sold in that currency (no totals, add-ons or Add to cart) and offers guests a one-click switch to a currency it is sold in; signed-in clients are told their account currency and linked to support. An empty store does the same, without the footer price note.
- **Dashboard warning for unpriced products (#247).** "Products with no NPR price" (the default currency) appears under Needs attention for staff who can view products.

### Changed

- **Price conversion dispatches `ProductPriceChanged` (#16).** Every row written or turned off by automatic or manual conversion now raises the event once the run commits, so caches, search and the storefront refresh like after a manual price edit.
- **One rounding rule for converted amounts (#127).** The demo catalog's NPR and INR setup fees and option prices, domain late-renewal and redemption fees, and converted promotion and minimum-cart amounts now use the currency's rounding rule (for example Rs 760 instead of Rs 759.70). Promotion values and minimum-cart amounts now also include the currency's price buffer, like converted prices (a 10.00 coupon with NPR at 151.94, 3% buffer and rounding to 10 is Rs 1,560, not Rs 1,519.40). Existing stored prices are not touched; re-run price conversion to refresh converted rows.

### Fixed

- **Adding an unpriced product says why (#247).** The cart refuses with "<product> is not sold in NPR." instead of "Choose one of the available billing cycles."
- **A new provisioning driver no longer inherits the old driver's secrets (#16).** Leaving a secret field blank keeps the stored value only while the driver is unchanged; after a change the field must be filled in.
