### Added

- **Gateway certificates and expiry warnings (#489).** A payment gateway can take a signing certificate as a .pfx or
  .p12 upload with its passphrase. Only the private key (encrypted) and the certificate's details are kept; the form
  shows the expiry date and serial, never the key. Staff with `billing.gateways.manage` get an email 30, 7 and 1 days
  before an active gateway's certificate expires and when it has expired (setting: Billing → "Gateway certificates:
  warn this many days before expiry"), and the dashboard health card shows it amber, then red. New daily automation
  task "Check gateway certificates". No shipped gateway uses a certificate yet; connectIPS will.
- **Return address on hosted gateway forms (#489).** Every gateway that sends customers to a provider page shows the
  address customers return to, with a copy button, for providers that need it registered.

### Changed

- **Provider references are unique per gateway (#489).** A return that carries only the provider's reference finds
  its payment attempt. Upgrade: the migration first resolves any attempts that share a provider reference on one
  gateway (the paid or oldest keeps it; an open one goes to staff review) and then builds a unique index online. None
  are expected, since shipped gateways use provider session ids.
- **Errors after the password step-up show on the gateway form (#489).** A save repeated after confirming the password
  now shows its validation errors next to the fields.
