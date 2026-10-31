### Fixed

- **A service set up on order no longer stays Pending when its provisioning job finds the item busy.** A job that
  found another process (or a worker that died) holding the item's lock used to end as a success without running
  create. It now waits 60 seconds and tries again, up to 8 times; exceptions still fail it after 3. When the tries
  run out, the order gets an activity entry and staff get the provisioning-failed mail, and the item stays Pending for
  a manual create. The job runs on the `provisioning` queue with a 180-second timeout, and the item lock lasts 200
  seconds instead of 300, so a job redelivered after its worker died finds the lock already free. Create is still
  never sent twice: an earlier attempt that did not finish opens a "verify" item, as before. Upgrade note: new
  on-order provisioning jobs go to the `provisioning` queue, which the provider worker pool already works; jobs
  already queued on `default` drain there safely (that pool's 150-second timeout is below the 200-second lock).

### Security

- **Keys a provisioning or registrar driver declares secret are now masked.** A driver that implements
  `DeclaresSensitiveKeys` has those keys masked in the module and registrar command logs. Their values are masked in
  stored errors, failure items, activity entries and registrar messages, also when the driver returns them inside a
  JSON, XML or `name=value` string. A value under 8 bytes is masked only as a whole word. A driver whose
  `sensitiveKeys()` throws now fails the command before anything is sent. Built-in drivers were already covered.
