### Changed

- **CI finishes in about half the time.** The one CI job is split: a checks job (policy, lint, types, static
  analysis) runs first, then SQLite in 2 shards, PostgreSQL in 3 shards and the build run in parallel. The required
  check keeps its name, `CI (policy, lint, types, static, tests, build)`, on a final job that passes only when every
  other job did. While the repository is private this costs more billable minutes per run than the one job did
  (see `billing/docs/devops/ci.md`).
- **`scripts/test.sh` runs the tests locally.** Whole suite, `--changed` against `origin/develop`, or `--pgsql`, with a
  worker count picked from the machine's load; on macOS the simulator tests run through `scripts/linux.sh`.
- Four slow tests are faster without proving less: InstallCommandTest no longer seeds the 730-day demo history to
  compare reference data, a deploy probe skips its 20 one-second polls, and a TLS pinning test waits 4 s, not 6 s.

### Fixed

- **Simulator scenario directories are removed on macOS.** The cleanup compared a resolved path with the unresolved
  temp directory (`/var` is a symlink to `/private/var`), so it never deleted anything there.
