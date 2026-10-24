### `DomainNameRules` (#TBD)

- `App\Contracts\Domains\DomainNameRules::registryLevelError(string $domain): ?string` is new: the registry naming rule
  for a domain name (today the `.np` second levels), as a message or null. The Domains module implements it
  (`RegistryLevels`); Core binds `NullDomainNameRules` (no restriction) when Domains is absent. Orders uses it in
  `CartPricer`, so client and staff orders share one check. Additive.
- `App\Modules\Orders\Services\DomainGuard::registryLevelError()` and `::SECOND_LEVELS` are removed; they were not
  part of the kernel and had one caller each, now on the contract.
