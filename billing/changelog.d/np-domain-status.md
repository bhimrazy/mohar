### Fixed

- **A domain the client registered at the registry (such as a .np name) no longer reads "Active" with "After registration" (#420).** On the client and admin domain lists and detail pages it shows "Self-registered"; expiry reads "At the registry" (or the date, if staff recorded one); auto-renew is replaced by "At the registry", the auto-renew switch and the renew-now option are hidden. Domain email merge fields gain `domain.status_label`, `domain.registered_by_client` and `domain.expiry_label`. No schema change; the stored status stays `active`.
