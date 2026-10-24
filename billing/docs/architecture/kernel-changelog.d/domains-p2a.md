### `DomainPricing::terms()` takes an optional client id (#449)

- `App\Contracts\Domains\DomainPricing::terms()` gains a fourth parameter, `?int $clientId = null`. When given, the
  terms come from the client's group price tier, the same way `price()` already resolves it. Callers that omit it keep
  the base-price terms. Additive for callers.
- **Implementers:** add the fourth parameter to your `terms()` (PHP refuses an implementation without it). Pass the id
  to your own tier lookup; ignoring it is allowed but lists base-price terms to group members.
- The Domains module keeps its group-id form as `DomainPricingService::termsForGroup()`; the Orders module now passes
  the signed-in client's id from the cart, the checkout pricer and the store domain search.
