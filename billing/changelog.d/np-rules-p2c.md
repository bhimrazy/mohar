### Added

- **A "how to register" link per extension.** Staff can set a guide URL on each TLD that clients register at the
  registry (.np and its second levels). Domain search and the client's domain page show it; an extension without one
  uses the default link in Domains settings. Only https links are accepted.
- **The TLD list marks extensions the client registers** with a "Client registers" badge.
- **Domains and registrar references follow a client merge.** A merged client's domains and their registrar customer
  and contact references move to the target client; where both have the same one, the target's is kept. The merge preview lists them. Upgrade note:
  the migration adds an index on `registrar_refs (local_type, local_id)` and a `how_to_url` column on `tlds`.

### Fixed

- **Staff orders now follow the same .np rule as client orders.** An existing domain such as `asha.np` (the registry
  issues names only under .com.np, .org.np and its other second levels) or an invalid name is refused when staff place
  an order, as it already was in the store. Staff and client orders also read a domain the same way: capitals and a trailing dot are
  dropped and a Unicode name is stored as punycode. The rule now lives in one place for orders, domain search and registrar
  actions.
