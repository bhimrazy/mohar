### Fixed

- **A service set up on order no longer stays Pending when its provisioning job finds the item busy.** A job that
  found another process (or a worker that died) holding the item's lock used to end as a success without running
  create. It now waits 60 seconds and tries again, up to 8 times; exceptions still fail it after 3. The job runs on
  the `provisioning` queue with a 180-second timeout, and the item lock lasts 200 seconds instead of 300, so a job
  redelivered after its worker died finds the lock already free. Create is still never sent twice: an earlier
  attempt that did not finish opens a "verify" item, as before. Upgrade note: on-order provisioning jobs now go to
  the `provisioning` queue, which the provider worker pool already works.

### Security

- **Keys a provisioning or registrar driver declares secret are now masked.** A driver that implements
  `DeclaresSensitiveKeys` has those keys masked in the module and registrar command logs, and their values masked
  in stored errors, failure items, activity entries and registrar messages. Built-in drivers were already covered.
