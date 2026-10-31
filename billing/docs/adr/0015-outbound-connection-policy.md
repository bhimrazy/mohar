# ADR 0015 — Outbound connection policy

- Status: Accepted · 2026-10-29 (#513) · Owner: Principal architect. Proposed 2026-10-18; Amendment 1 was accepted with
  ADR 0016 (CTO decision 2). Accepted after its first caller, the cPanel driver (#69), and later callers (Namecheap,
  ResellerClub, `Endpoints`, webhooks); see Amendment (#513) for what differs from the original text.
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

## Amendment 1 — 2026-10-18, with ADR 0016 (integration simulators)

- Loopback is reached only when the app runs as `local` or `testing`, whatever `OUTBOUND_ALLOW_LOOPBACK` says.
  Every other environment, including `production`, `staging`, an empty or an unknown name, refuses it, so only
  development and test runs can reach a local simulator. The setting still defaults to off except in `local`.
- `OutboundAddressGuard::pinOptions()` returns the options `pin()` applies, as an array, for callers that build their
  request options themselves (`App\Support\Integrations\Endpoints`, ADR 0016 §3). Both paths pin to the checked address
  and bypass proxies in the same way.

## Amendment (#513) — 2026-10-29, acceptance, the webhook profile and the DNS deadline

- Accepted as built. `OutboundAddressGuard` is the one guard. The cPanel, Namecheap and ResellerClub clients,
  `Endpoints` and the webhook sender resolve and pin through it.
- The `public: true` destination profile is not built. Webhooks are the only destination that third parties control
  today. `WebhookUrlGuard` judges the one DNS answer twice: with its own private, loopback and CGNAT list, then with
  the kernel guard, which pins the connection. So webhooks already get the profile's behaviour. The option goes into
  the kernel guard with the second such destination, for example a client-entered URL, and `WebhookUrlGuard` then
  drops its own list. Until then a parameter with no caller would be untested surface.
- DNS lookups get no deadline inside the process. This is a decision, not an oversight. PHP's resolver calls
  (`dns_get_record`, `gethostbynamel`) take no timeout. A resolver of our own would need TCP fallback, truncation,
  CNAMEs, search domains and `/etc/hosts`, which makes a large, security-sensitive surface. A child process per lookup
  is too costly under FrankenPHP workers for a rare failure. The deployment bounds the lookup instead.
  `deploy/compose.prod.yaml` sets `dns_opt: [timeout:2, attempts:2]` on the `x-app` services, and glibc and musl both
  honour it.
- Worst case for a synchronous caller, such as the cPanel "Test connection" button: one query to Docker's embedded
  resolver gives up after about 4 s. `resolve()` asks for A and AAAA, then makes one fallback lookup, so a host that
  never answers fails after about 12 s. Search domains in the host's `resolv.conf` multiply that.
  `docs/devops/production.md` (Troubleshooting) says so. Queued callers are bounded by their job timeouts as well
  (ADR 0017). Other installs set the same options in `resolv.conf`.

## Amendment (#532) — 2026-10-29, mail relay profile

- SMTP hosts that staff type in (Admin → Settings → Mail server) are judged by `OutboundAddressGuard` with
  `relay: true`. The profile accepts loopback and private ranges whatever the install policy, because Postfix on
  `127.0.0.1` or a relay on the LAN is normal, and the install policy refuses loopback outside `APP_ENV=local`.
- The always-refused set still applies: link-local (including cloud metadata), unspecified, multicast, reserved and
  documentation ranges, and non-canonical numeric hosts such as `0x7f.1`.
- The host is checked when settings are saved (a validation rule, only while SMTP is the chosen mailer) and before the
  test send. Queued mail does not re-check it.
- No pinning. Symfony Mailer's socket stream resolves the name again, so DNS rebinding between check and connect
  remains possible. Accepted: the setting needs a sensitive permission and a recent password, and a person who can
  change it can already redirect all mail. Ports are not restricted.

## Amendment (#555) — 2026-10-31, outbound calls made inside a user request (Accepted)

Status: Accepted 2026-10-31 (owner approved `client-import.md` Q13), with ADR 0018 Amendment (#555) and `client-import.md` §3.4. The licence check (Q13(c)) gates marketing, not the build. It comes from the #555 design review
(B4, M1). It is the first rule for a synchronous outbound call on a page that users wait on. Sign-in is already the
slowest such page (`sizing.md`).

- **No DNS in the request.**
    - A scheduled health probe resolves and vets the destination through `resolve()`, and caches the vetted address
      set for at most 5 minutes. The probe runs well inside that TTL (every minute for carry-over), so the cache does
      not lapse between refreshes.
    - The request connects to that set through `pinOptions()`.
    - A cache miss fails the call; it is never a lookup.
    - This keeps the unbounded resolver time noted in Amendment (#513) out of user requests.
- **A deadline covering the whole call.** `connect_timeout` of at most 2 s and a total of at most 5 s, covering
  connect, TLS and body. Because nothing is resolved, the total covers everything.
- **Concurrency from the thread count.**
    - At most `max(1, intdiv(php_threads, 4))` such calls run at once, install-wide, per kind of call.
    - `php_threads` follows FrankenPHP's `num_threads` as the deploy sets it. When unset it defaults to 4, the small
      profile, so it fails small.
    - Over the cap, the call is skipped and the caller gives its ordinary failure.
    - The same bound applies to requests held in a deliberate delay, such as a timing floor. A request takes its slot
      in the delay before any outbound call. With no slot free it makes no call, so timing cannot tell callers apart.
- **Transport.**
    - HTTPS only (`CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS`), TLS verification with the system CA bundle that
      cannot be configured off, no redirects and no proxy.
    - Responses are capped by size (64 KB unless the protocol needs more) and parsed fail-closed.
    - When the call carries a user's secret, the TLS key is recorded at setup (trust on first use) and enforced with
      `CURLOPT_PINNEDPUBLICKEY`, so a changed key aborts the connection before any request byte is sent.
- **Hosts Mohar serves.** A destination whose normalised hostname (IDNA to ASCII, lower case, no trailing dot)
  equals any host the install serves is refused when saved: `APP_URL` and the client, admin and storefront domains.
- **Load test.** Each new caller of this kind adds a `loadtest/` scenario on the small profile, and `sizing.md`
  records its budget.
- **First caller:** WHMCS sign-in carry-over (`client-import.md` §3.4). It uses the install policy, because staff
  enter the destination.
