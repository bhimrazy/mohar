### Changed

- **Domain search and ordering wording (#203).** A `.np` search result now reads "Registered at the registry, not sold here", says registration is free for eligible applicants (not only Nepali citizens), and tells the client to set the nameservers shown on their hosting service. A TLD that is sold but has no price in the visitor's currency now says it cannot be ordered in that currency, instead of "not sold here".

### Fixed

- **A registrar call reads the registrar account once (#267).** Previously each call loaded the account twice.
