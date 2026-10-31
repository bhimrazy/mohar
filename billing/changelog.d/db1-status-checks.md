### Fixed

- **The database refuses a service, service addon or domain status outside its list (PostgreSQL, #559, #560).**
  `services.status` and `service_addons.status` accept only `pending`, `active`, `suspended`, `terminated`,
  `cancelled`, `fraud` and `completed`; `domains.status` only `pending`, `pending_registration`, `pending_transfer`,
  `active`, `grace`, `redemption`, `expired`, `transferred_away`, `cancelled` and `fraud`. A script or `psql` session
  that writes `Active` or a registrar's own state now fails instead of hiding the row from every list. Nothing changes
  for staff or clients: the application already writes only these values. Upgrade note: the migration checks existing
  rows table by table; if any have another status, it removes that table's new check again, stops and names the
  table and up to 20 row ids, so the deploy halts before the new release starts. Tables checked before it keep their
  checks. Find such rows before upgrading with the queries in `docs/devops/production.md` ("Rows a new check
  refuses"), correct them and deploy again. On SQLite these checks are not added.
