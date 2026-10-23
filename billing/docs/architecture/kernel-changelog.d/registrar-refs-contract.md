### `RegistrarRefs::remember()` is first-writer-wins (#408)

- `App\Contracts\Domains\Registrar\RegistrarRefs::remember(RegistrarRef): RegistrarRef` now returns the stored ref
  instead of `void`, and no longer replaces an existing one (remote id and secret are kept). Two callers that
  remember the same key, even at once, both get the first ref. The contract docblock states the lock rule: remember()
  needs no lock; a driver that must not create a duplicate remote object locks the ref key around find, create and
  remember. In-repo implementers updated (Domains `EloquentRegistrarRefs`, the test fake); the ResellerClub driver
  uses the returned ref. A driver that re-recorded a ref to change its remote id or erase its secret must now be
  written for a store that keeps the first one.
- Core binds a null `RegistrarRefs` (`bindIf`): installs without Domains resolve it; `find()` returns null and
  `remember()` returns the ref unstored.
- New marker interface `App\Contracts\Domains\Registrar\StandaloneRegistrar` and
  `RegistrarDriverRegistry::needsAccount(string $key)`: a driver with settings that does not implement the marker is
  not active until staff save its account. Before, a registered driver without an account counted as active. The log
  driver and the test fakes implement the marker. Recorded in ADR 0009 (amendment #408).
