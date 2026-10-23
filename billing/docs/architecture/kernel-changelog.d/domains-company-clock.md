### ManagesContacts reports the transfer lock in days (#398)

- `RegistrarResult::$meta` from `setContacts()` carries `transfer_lock_days` (an int) instead of a dated
  `transfer_lock_until`; the host dates the lock from the company calendar, so a driver reads no settings.
- A package driver that still returns `transfer_lock_until` has it dropped: return `transfer_lock_days`.
