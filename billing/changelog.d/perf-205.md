### Fixed

- **The load test no longer starts every virtual user in the same second (#205).** All users of a level signed in at
  once, and that burst of password checks on the 2 vCPU host caused most of the multi-second p95 reported for the
  dashboards, the store and sign-in. Users now arrive over the first 20 seconds of each level
  (`loadtest/bin/run --start-spread`, 0 for the old behaviour). Measured locally at 40 users: client dashboard p95
  1.5–1.6 s → 82–159 ms, store 1.2–2.2 s → 64–140 ms, sign-in p50 1.3–1.6 s → 270–300 ms. Runner numbers in
  `docs/devops/sizing.md` predate the change.
