### Added

- **Extension authors can see the kernel API version and every public kernel signature (#513).**
  `App\Support\Kernel::API_VERSION` (now `0.1.0`) names the kernel API, and `docs/architecture/kernel-api.txt` lists
  every public contract, DTO, event, enum and `@api` support class. `php artisan kernel:api-snapshot` regenerates the
  file. CI fails when the file is stale, and when it changes without a kernel changelog entry.

### Changed

- **The provisioning driver list no longer carries `config_fields` (#513).** `ProvisioningDrivers::all()` returns
  only `fields`, which has been there since #65. Upgrade note: a package that read `config_fields` reads `fields`
  instead, where a secret is `type: secret` and an option list is keyed by value. Nothing in this repository read the
  old key.
