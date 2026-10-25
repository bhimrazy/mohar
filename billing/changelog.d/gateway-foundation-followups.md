### Fixed

- **One broken registrar or provisioning driver no longer breaks the pages that list every driver (#490).** A driver
  that fails the host's checks (for example one declaring a certificate setting, which only gateways support) is
  reported and left out of the registrars page, the server and product forms and other listings.
- **Gateway certificate warnings keep working when another gateway driver is broken (#490).** The daily certificate
  check and the dashboard health line skip a gateway driver whose settings cannot be read, and the health line counts
  only certificate settings a driver still declares.

### Security

- **Security settings refused for the password step-up no longer store what was typed in the session (#490).** For
  example the inbound support email secret: the page is sent back with the "confirm your password" error only.
