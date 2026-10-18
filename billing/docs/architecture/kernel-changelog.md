# Kernel API changelog

Changes to the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, registry
methods in `app/Support`, `App\Support\Money`). Until `API_VERSION` reaches 1.0.0, a breaking change is allowed when
the same PR updates every in-repo implementer and gets the principal-architect gate; it is listed here.

## Unreleased (pre-1.0)

### Integrations area (ADR 0009 §1, PR A)

- Added `App\Enums\FieldType` and `App\Data\Integrations\SettingField`, the one settings-field schema. A secret is a
  field type. `SettingField::fromArray()` reads the gateway and the older provisioning array shapes. `optionsSource = 'remote'`
  lets a select take its options from the driver (cPanel spec K2). The constructor refuses a secret with a default and
  remote options on a non-select.
- Added `App\Contracts\Integrations\IntegrationDriver` (`key()`, `name()`), the identity every driver family shares.
- Added `App\Support\Integrations\DriverRegistry`, the base of the kernel-owned driver registries (ADR 0012 shape).
- Added `App\Support\Integrations\Redactor`, which holds every integration redaction rule:
    - `redact()` for the module and registrar logs merges Services' redactor with the Domains registrar key lists. Keys
      are matched by word, adjacent pair or run-together spelling (`api_key`, `ApiKey`, `eppcode`, `accesshash` alike),
      and every key develop masked is still masked. Two narrowings are deliberate (architect R2): `author` is no longer
      caught by `auth`, and `code` is secret only where the payload says so (`RegistrarGateway` passes it for `epp` and
      `transfer`). Objects are read as arrays, nesting is capped at 32 levels, and inside strings it masks known
      credential values, cPanel `cpsess…` tokens, JSON documents and secret `name=value` pairs (query strings, form
      bodies, `Authorization:` text). Registrar error messages go through it too.
    - `redactPayment()` for the gateway log and stored `raw` keeps develop's gateway rule exactly (every leaf under a
      payment-sensitive key masked, null included; card brand and last 4 stay visible; Luhn-valid card numbers masked as
      `[redacted-pan]`), plus `authorization`, `dv` and `dataValidation` from payments-nepal.md §4.
    - `excerpt()` makes raw driver output safe (redacted, 4 KB). The module-log mask is now `[redacted]` (it was `***`).
- Added `App\Support\Integrations\OutboundAddressGuard` with `App\Data\Integrations\ResolvedAddress` and
  `OutboundAddressRefused` (ADR 0015, proposed). `resolve(host, port)` applies the install-wide policy in
  `config/integrations.php`, refuses non-public addresses (IPv6 allow-listed, embedded IPv4 judged) and non-canonical
  numeric hosts, and `pin()` sends the request to the checked IP and port (`CURLOPT_CONNECT_TO`), bypasses proxies
  (`proxy: ''`) and follows no redirects.
- **Breaking:** moved `App\Data\Billing\ConnectionCheck` to `App\Data\Integrations\ConnectionCheck`.
- **Breaking:** moved `App\Contracts\Billing\ProvidesLogo` to `App\Contracts\Integrations\ProvidesLogo`, and renamed
  `App\Data\Billing\GatewayLogo` to `App\Data\Integrations\IntegrationLogo`. Khalti and eSewa are updated.
