### Changed

- **The sign-in, forgot-password, verify-email and profile status messages use the theme success colour**, so they stay readable in dark mode.
- **Card tokenising, the password confirmation dialog, "email me a set-password link" and the service password reveal now send their requests through Inertia's HTTP client** instead of hand-built `fetch` calls with a CSRF header. Behaviour and messages are unchanged.
- **A bundle size check, `npm run bundle:check`, reports the gzip size of the entry (budget 80 kB) and of every chunk (budget 150 kB).** CI runs it after the build and only warns for now; `--enforce` makes a breach fail.

### Fixed

- A card tokenising request that fails on the network now clears the processing state instead of leaving the form stuck.
