### Fixed

- **A provisioning or registrar driver that cannot be built is reported once per request or queued job (#495).** It
  used to be rebuilt and logged several times on every page that lists drivers; it is left out until the next request
  or job starts, which tries again.
- **Servers on an unavailable provisioning driver lose their secret settings when their address changes (#495).** Every
  setting of a driver that failed the host's checks counts as secret, so a hostname change made outside the server
  form drops them, as the form does. Its non-secret settings (a region, say) are dropped too: while the driver is
  unavailable none can be told apart from a secret.
- **Service screens name an unavailable provisioning driver (#495).** They show "<driver> (unavailable)" instead of
  presenting the service as manual setup.
