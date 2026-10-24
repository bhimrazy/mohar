### Fixed

- **A credit refund that the provider confirms late no longer fails with an error (#464).** The stored provider payload of such a refund is masked with its gateway's credentials again; the call had lost its gateway argument in a merge.
- **The test suite stays within its memory limit in a single run (#21).** One review-probe file left the architecture-rule cache loaded (about 290 MB); a guard now fails any test file outside `tests/Arch` that does the same.
- **Test hygiene (#54, #110, #459).** The expired-password scan now also covers routes without a name and routes that name `RequirePasswordUnlessExpired` directly; deleting an account with an expired one-time password is covered; the PostgreSQL install race test takes one lock observation instead of two and waits longer on a loaded machine; every test checks that `storage/framework/testing/.gitignore` is still there; a test pins the reset of trusted hosts and proxies between tests.
