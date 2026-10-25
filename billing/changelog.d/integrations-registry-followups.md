### Fixed

- **A provisioning or registrar driver that cannot be built is reported once per request or queued job (#PR).** It
  used to be rebuilt and logged several times on every page that lists drivers; the next request or job tries again.
- **Servers on an unavailable provisioning driver lose their secret settings when their address changes (#PR).** Every
  setting of a driver that failed the host's checks counts as secret, so a hostname change made outside the server
  form drops them, as the form does.
- **Service screens name an unavailable provisioning driver (#PR).** They show "<driver> (unavailable)" instead of
  presenting the service as manual setup.
