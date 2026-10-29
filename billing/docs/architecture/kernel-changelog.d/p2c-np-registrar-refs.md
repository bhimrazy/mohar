### `DomainNameRules::registeredByClient` (#203)

- `App\Contracts\Domains\DomainNameRules::registeredByClient(string $tld): bool` is new: whether clients register the extension at the registry themselves. The Domains module answers from `tlds.registered_by_client`; Core's `NullDomainNameRules` returns false. Orders uses it to word the cart error for a TLD with no price in the order currency. Additive.
