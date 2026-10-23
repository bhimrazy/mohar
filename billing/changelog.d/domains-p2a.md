### Changed

- **`DomainPricing::terms()` takes an optional client id (kernel contract).** The storefront and cart now list the
  registration and transfer terms of the signed-in client's price group, as the prices already did. Implementations of
  the contract must accept the new optional fourth argument.

### Fixed

- A sync that finds a renewal whose answer was lost (a timeout) at the registrar now clears the review flag and the
  renewal claim, so the next year's renewal is no longer held for staff (#13). A renewal that is only partly visible
  at the registrar, or a notify-only sync, leaves the review in place.
- "Renew now" no longer issues two invoices when the button is clicked twice at once; the second request is told the
  invoice is being created (#15).
- Converting TLD prices to another currency can no longer round a paid price down to 0 and make it free. A price staff
  set to 0 (a free transfer) stays 0 (#15).
- Two staff saving a TLD's prices for the first time at once no longer causes an error page; the second save is asked to
  reload and try again (#55).
