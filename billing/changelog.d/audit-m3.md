### Fixed

- **A service set up on order no longer stays Pending when its provisioning job finds the item busy.** The job used
  to end as a success, without running create, when another process held the item's lock. That process could also
  be a worker that had died. Now the job waits 60 seconds and tries again, up to 8 times. Exceptions still fail it
  after 3 tries. When the tries run out, the order gets an activity entry and staff get the provisioning-failed mail.
  The item stays Pending so staff can run create by hand. The job runs on the `provisioning` queue with a 180-second
  timeout. The item lock now lasts 200 seconds instead of 300, so a job redelivered after its worker died finds the
  lock already free. Create is still never sent twice: an earlier attempt that did not finish opens a "verify" item,
  as before.
- Upgrade note: new on-order provisioning jobs go to the `provisioning` queue, which the provider worker pool
  already works. Jobs already queued on `default` drain there safely: that pool's 150-second timeout is below the
  200-second lock. On the `sync` queue a busy item is still skipped.
