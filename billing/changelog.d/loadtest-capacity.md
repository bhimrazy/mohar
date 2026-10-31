### Added

- **The load test finds where a host saturates (#543).** A ramp now stops one level after the first level past the
  comfortable limit (p95 of all requests 1 s or more, or 1% errors or more), and the summary names the last
  comfortable and the first failing level, with per-page latency and CPU at both. `loadtest/bin/run --think "10 30"`
  models people who read each page. The `load-test` pull-request label runs the profiles, levels and think times
  listed in `loadtest/label-runs.json`. The sizing guide gives the measured capacity of the small and medium profiles.
