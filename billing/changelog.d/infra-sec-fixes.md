### Security

- **The production database and object store run with the same container hardening as the app.** In
  `deploy/compose.prod.yaml`, `postgres` and `rustfs` now have a read-only root filesystem, `no-new-privileges` and
  `cap_drop: ALL`. PostgreSQL keeps only the five capabilities its entrypoint needs. RustFS logs to stdout (Docker's
  rotated log) instead of files inside the container. Upgrade: the next `deploy/bin/deploy` recreates both containers
  in its "database and object storage" step (`up -d --wait postgres rustfs`, before the new colour starts), so
  PostgreSQL and RustFS restart once, about as long as a normal restart. During that step the live colour has no
  database or object store, so requests fail until both are healthy again. Deploy at a quiet time. The data volumes
  are kept.
- **Set-password links no longer reach Caddy's access log.** `/settings/password/set/<token>` is now redacted like
  password-reset and invitation links. Upgrade: reload Caddy after pulling (`deploy/bin/deploy` does it).
- **Guidance for a database on another host: verify its certificate.** `DB_SSLMODE` defaults to `prefer`, which
  encrypts but never checks the server. `install.md` now asks a remote or managed PostgreSQL to use
  `DB_SSLMODE=verify-full` with the provider's CA in the new `DB_SSLROOTCERT` setting.
