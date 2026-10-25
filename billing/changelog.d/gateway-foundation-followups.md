### Fixed

- **One broken registrar or provisioning driver no longer breaks the pages that list every driver (#490).** A driver
  that fails the host's checks (for example one declaring a certificate setting, which only gateways support) is
  reported, left out of the registrars page, the server and product forms and other listings, and named on the admin
  dashboard's health card ("Integration drivers"). Nothing new can be bound to it; a product already bound to it keeps
  its stored settings, which stay hidden from the browser.
- **Gateway certificate warnings keep working when another gateway driver is broken (#490).** The daily certificate
  check and the dashboard health line skip a gateway driver whose settings cannot be read, and the health line counts
  only certificate settings a driver still declares.

### Security

- **Secret platform settings are never stored in the session (#490).** For example the inbound support email secret:
  a save refused for the password step-up is sent back with the "confirm your password" error only, and a save that
  fails validation keeps the other values typed but not the secret.
