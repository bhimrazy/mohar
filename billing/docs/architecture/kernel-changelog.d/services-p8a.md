### `ProvisioningFailed::$outcomeUnknown` (services-p8a)

- `App\Events\Services\ProvisioningFailed` has a new last constructor parameter `bool $outcomeUnknown = false`. It is
  true when the command may have run on the provider (ADR 0026) and waits for a server check or staff. Additive:
  existing listeners keep working, but should not report such an event as a failure.
- Services sets it for a timed-out or unfinished attempt; Notifications sends `staff.provisioning_unknown` for it
  instead of `staff.provisioning_failed` (#147).
- `ModuleCommandFailed::$outcomeUnknown` (ADR 0009 §6) must carry the same meaning when it ships; until then,
  `ProvisioningFailed` carries it.
