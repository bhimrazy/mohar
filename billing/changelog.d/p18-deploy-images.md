### Added

- **`deploy/bin/deploy` removes old app images after a successful deploy (#158).** Images of earlier releases are
  removed; the live and previous colours' images stay for a rollback, and an image still in use is skipped with a
  warning. Set `DEPLOY_PRUNE_IMAGES=0` to keep them all. Build cache is not touched.

### Changed

- **The runtime image is based on Debian `trixie-20261020` (#244).** Same glibc as the FrankenPHP stage; `liblzma5`
  moves to 5.8.1-1+deb13u2. Rebuild with `deploy/bin/deploy`.
