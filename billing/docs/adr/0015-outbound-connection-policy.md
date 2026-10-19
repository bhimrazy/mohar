# ADR 0015 — Outbound connection policy

- Status: Proposed · 2026-10-18 · Owner: Principal architect
- Related: ADR 0009 (kernel extension contracts), ADR 0014 (observability), `docs/product/provisioning-cpanel.md`
  §9.2 (K11), the Notifications webhook guard in #28 (`WebhookUrlGuard`).

## Context

More and more of Mohar connects to hosts that people type in:

- control panel servers (cPanel, Plesk, Proxmox), whose hostnames staff enter;
- registrar and gateway endpoints, which are fixed today but configurable for sandbox or self-hosted variants;
- outgoing webhooks (#28), whose URLs staff enter on behalf of third parties.

Each such connection is a server-side request forgery (SSRF) path. An attacker who can set the host could reach the
cloud metadata service, the billing server's own loopback services, or the private network. A "Test connection"
button that shows the response turns that into a read primitive.

Two guards exist or are in flight. They block different things:

- `WebhookUrlGuard` (#28) refuses private ranges always.
- `OutboundAddressGuard` (#61) allows private ranges by default, because a panel on the billing server's LAN is
  normal.

Neither guard covers proxies. If `HTTPS_PROXY` is set, the proxy resolves the name again, and the pinned address is
ignored.

## Decision

### One guard, one install-wide policy

- `App\Support\Integrations\OutboundAddressGuard` is the only way to vet a destination that a person entered. Drivers
  and modules call `resolve($host, $port)` and connect through `pin()`. They never pick the policy.
- The policy lives in `config/integrations.php` under `outbound`:
    - `allow_private_addresses`: env `OUTBOUND_ALLOW_PRIVATE_ADDRESSES`, default `true`. It covers RFC 1918, CGNAT
      `100.64.0.0/10` and IPv6 ULA `fc00::/7`. A multi-tenant (SaaS) install sets it to `false`.
    - `allow_loopback`: env `OUTBOUND_ALLOW_LOOPBACK`, default `true` only when `APP_ENV=local`.
- The following are always refused, whatever the policy:
    - unspecified addresses;
    - link-local addresses, which include the `169.254.169.254` metadata service;
    - the other cloud metadata and host endpoints: `100.100.100.200`, `168.63.129.16` and `fd00:ec2::254`;
    - multicast, broadcast and reserved addresses: `240.0.0.0/4`, `192.0.0.0/24`, benchmark `198.18.0.0/15` and
      documentation `2001:db8::/32`;
    - Teredo and local-use NAT64.
- IPv6 is allow-listed. An address passes only if it is global unicast (`2000::/3`), or ULA or `::1` when the policy
  allows them. IPv4-mapped, IPv4-compatible, 6to4 and well-known NAT64 addresses are judged by the IPv4 address they
  carry.
- If any resolved address is refused, the whole host is refused. The connection is pinned to the checked address and
  port with a wildcard `CURLOPT_CONNECT_TO` entry (`::ip:port`), so neither a second DNS answer (rebinding) nor a URL
  that names another host or port can change the target. TLS still checks the URL's host name.
- Numeric hosts that are not canonical IP addresses (`2130706433`, `0177.1`, `0x7f.1`) are refused, because resolvers
  disagree on how to read them. The documentation, benchmark and TEST-NET ranges are refused as well.

### Destination profiles

The guard grows one compatible option, `resolve(..., public: true)`. It is for destinations that third parties or
clients control, such as webhooks and any future client-entered URL. With it, private and loopback addresses are
refused whatever the install policy, which is how `WebhookUrlGuard` behaves today. #28 then replaces its own range list
with the kernel guard. Infrastructure that staff set up uses the install policy.

### Proxies, redirects and responses

- `pin()` **bypasses** any process-wide proxy by setting Guzzle's `proxy` option to an empty string; Guzzle then
  disables the environment proxy (`http_proxy`, `https_proxy`) for that request. A proxy would defeat the pin.
  An install whose egress must go through a proxy has to allow these hosts directly. Refusing every pinned call there
  would break real installs silently, while bypassing fails loudly with a connection error.
- Redirects are never followed. A 3xx answer is a failure that the driver reports.
- Response bodies reach staff only as the driver's own message plus `Redactor::excerpt()` (redacted, at most 4 KB), so
  a test button cannot be used to read internal services.
- Port policy belongs to the protocol. The cPanel driver allows 2087, 443 and 1024–65535; webhooks allow 443. The guard
  checks only that the port is valid.

## Consequences

- One reviewed implementation of a security-critical check. Gateways, registrars, panels and webhooks get the same
  fixes.
- A SaaS operator flips one setting instead of auditing every driver.
- Installs behind a mandatory egress proxy cannot reach pinned destinations until a proxy-aware pin exists, for example
  a CONNECT to the checked IP with SNI. That is left for when an install needs it.
- The security reviewer attacks the guard in its first caller's PR (cPanel).
