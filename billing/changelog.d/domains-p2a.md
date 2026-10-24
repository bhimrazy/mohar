### Changed

- **`DomainPricing::terms()` takes an optional client id (kernel contract).** The storefront and cart now list the
  registration and transfer terms of the signed-in client's price group, as the prices already did. Implementations of
  the contract must accept the new optional fourth argument.

### Fixed

- A sync that finds a renewal whose answer was lost (a timeout) at the registrar now confirms it like any other
  renewal: the domain returns to Active (also from Grace, Redemption or Expired), the review flag and the renewal claim
  are cleared, the client gets the "renewed" notice once, and next year's renewal is invoiced (#13). A renewal that is
  only partly visible at the registrar, a notify-only sync, or an older claim whose paid years cannot be found leaves
  the review in place.
- The daily renewal invoicing no longer fails the whole run when "Renew now" has just invoiced the same period (#15).
- "Renew now" no longer issues two invoices when the button is clicked twice at once; the second request is told the
  invoice is being created (#15).
- Converting TLD prices to another currency can no longer round a paid price down to 0 and make it free. A price staff
  set to 0 (a free transfer) stays 0 (#15).
- Two staff saving a TLD's prices for the first time at once no longer causes an error page; the second save is asked to
  reload and try again (#55).
