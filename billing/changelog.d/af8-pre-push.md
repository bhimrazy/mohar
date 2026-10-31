### Changed

- **Worktrees get a pre-push gate.** `scripts/new-worktree.sh` installs `scripts/pre-push.sh` for the worktree, which runs
  Pint, `vp check`, the changelog check, PHPStan and `scripts/test.sh --changed` on what a push changes; bypass it with
  `git push --no-verify` (`devops/local-setup.md`). `composer setup` now runs `npm ci`, so it installs exactly what the
  lockfile pins.
