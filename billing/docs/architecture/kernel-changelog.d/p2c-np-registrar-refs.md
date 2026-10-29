### `DomainNameRules::registeredByClient` (#203)

- `App\Contracts\Domains\DomainNameRules::registeredByClient(string $tld): bool` is new: whether clients register the extension at the registry themselves. The Domains module answers from the cached TLD catalog (`tlds.registered_by_client`); Core's `NullDomainNameRules` returns false. Orders uses it to word the cart error for a TLD with no terms. **Breaking for outside implementers:** a class implementing `DomainNameRules` outside the Domains and Core modules must add the method.
