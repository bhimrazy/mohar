### Fixed

- A registrar that needs credentials (ResellerClub) is no longer treated as active before its account is saved under Domains, Registrars; calls and TLD attribute forms for it wait until then. Upgrade note: installs that relied on an unsaved account being active must save the account.
- Two workers creating the same registrar customer or contact at once now keep the first one recorded instead of overwriting it.
