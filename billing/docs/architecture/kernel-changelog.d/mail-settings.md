### `OutboundAddressGuard` mail relay profile (#532)

- `OutboundAddressGuard::resolve()` and `refusal()` take a new last parameter `bool $relay = false`. With `relay: true`
  loopback and private addresses are accepted whatever the install policy; every other refusal (link-local, metadata,
  unspecified, multicast, reserved, documentation, numeric obfuscation) still applies. Additive: existing callers are
  unchanged. First caller: the Platform mail server settings (ADR 0015 amendment #532).
