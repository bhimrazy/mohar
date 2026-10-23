### Fixed

- **Namecheap dates follow the company calendar.** A registrant change now records the 60-day transfer lock from the
  company date (the driver reports `transfer_lock_days`, as ResellerClub does since #398; before, the lock date was
  dropped). A new registration is dated from the term on the company calendar until the next sync reads the real
  expiry. No schema change.
