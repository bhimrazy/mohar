### Added

- **Extension authors can see the kernel API version and every public kernel signature (#513).**
  `App\Support\Kernel::API_VERSION` (now `0.1.0`) names the kernel API, and `docs/architecture/kernel-api.txt` lists
  every public contract, DTO, event, enum and `@api` support class, with their docblock types.
  `php artisan kernel:api-snapshot` regenerates the file. CI fails when the file is stale, and when it changes without
  a kernel changelog entry.

### Changed

- **Production DNS lookups from the app give up sooner (#513).** `deploy/compose.prod.yaml` sets
  `dns_opt: timeout:2, attempts:2` on the app services. A host that never answers now fails a "Test connection" after
  about 12 s. Upgrade note: takes effect on the next deploy; nothing to configure.
- **The provisioning driver list no longer carries `config_fields` (#513).** `ProvisioningDrivers::all()` returns
  only `fields`, which has been there since #65. Upgrade note: a package that read `config_fields` reads `fields`
  instead, where a secret is `type: secret` and an option list is keyed by value. Nothing in this repository read the
  old key.
