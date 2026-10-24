### Added

- **Accept the server's values on linked accounts (#139).** On Import accounts, a linked account whose domain, dedicated IP or suspension differs from its service shows one button per change ("Use domain …", "Use IP address …", "Suspend here too" / "Mark active here"). Each is bound to the two values shown and is refused if either side changed since; a status change follows the service state machine. Nothing is sent to the server. Needs `services.servers.import` and `services.services.manage`.
- **Ignore accounts you will never import (#140).** An Ignored tab and Ignore / Bring back buttons. Ignored accounts leave the Proposed and Unmatched counts and "Select all proposed", cannot be ticked, and stay ignored in later fetches (remembered per server and username).

### Changed

- **The billing-cycle choice lists only cycles the product has a price for (#143).** Once a product and the client's currency are known, the dialog offers the priced cycles; the bulk dialog on the review offers the cycles at least one reviewed account can use.
- **A service imported as suspended no longer tells the client to pay an invoice (#141).** The services list says to pay only for overdue suspensions and otherwise to contact support; the service page says the service was already suspended on the hosting server.
- **Match again no longer loads the whole list.** It reads and rewrites a chunk at a time and writes only accounts whose answer changed (about 7 MB above baseline at 20,000 accounts, from about 71 MB). Linking imported accounts is one update per chunk instead of one per account.
- **The daily prune of expired account lists is capped by rows as well as lists** (100 lists or about 50,000 accounts a run; the oldest list always goes).

### Fixed

- **A stored account list that cannot be read now says so.** It is dropped once, reported once, and shown as a failed fetch ("Fetch the accounts again") instead of an empty table reported on every view.

Upgrade notes: one new table, `server_import_ignores` (expand only, no existing data changes). The `services.services.manage` permission is now also required to accept values into a service.
