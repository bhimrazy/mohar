# ADR 0012 — Capability interfaces vs flags, and one extension-registry pattern

- Status: Accepted · 2026-10-18 (CTO) · Owner: Principal architect

## Context

**Capabilities are declared two ways at once.** `GatewayDriver` has eleven capability constants. Billing checks
`supports()` for `CAPTURE`, `TOKENIZE`, `REMOTE_INPUT`, `REFUND`, `PARTIAL_REFUND` and `WEBHOOK`, but detects hosted
checkout with `instanceof HostedPaymentDriver` (`GatewayManager.php:47`). Five constants are never read anywhere:
`REDIRECT`, `LOOKUP`, `QR`, `STORED_METHODS` and `PENDING_SETTLEMENT`. Khalti and eSewa still declare
`REDIRECT`/`LOOKUP`, so a flag and an interface can disagree. `ConnectionTester` and `ProvidesLogo` (#26) are
interfaces. Provisioning and registrar drivers use only flags, and registrar methods outside the flags throw.

**Registries have five registration shapes:**

| Registry                        | Where                      | Registration                                 | Resolution                                   |
| ------------------------------- | -------------------------- | -------------------------------------------- | -------------------------------------------- |
| `GatewayRegistry`               | contract, bound by Billing | `register(GatewayDriver $instance)`          | eager instances                              |
| `DriverRegistry` (provisioning) | Services module class      | `register(string $key, class-string)`        | lazy                                         |
| `RegistrarManager`              | Domains module class       | `extend(string $key, class-string\|Closure)` | lazy, then mutated by `configure()`          |
| `ExchangeRateSources` (#43)     | `app/Support`              | `register(class-string)`                     | eager: `app($class)` at boot to read the key |
| `PriceConverters` (#43)         | `app/Support`              | `register(class-string)`, keyed by class     | lazy, but `get($key)` resolves all           |

Because `GatewayRegistry` is bound by Billing, a package that calls `register()` directly while Billing is absent
gets a binding error. PaymentsNepal avoids this with `callAfterResolving()`, but the convention is not written down
anywhere.

## Decision

### Capabilities

1. If a capability adds methods, it is an interface, and consumers detect it with `instanceof`. Examples:
   `HostedPaymentDriver`, `ConnectionTester`, `ProvidesLogo`, and the ADR 0009 interfaces.
2. A flag is allowed only for whether a capability the driver has is enabled under a given configuration, for
   example `PARTIAL_REFUND` when `payments_nepal.khalti.partial_refunds` is off. A flag is never a second declaration
   of something an interface already declares.
3. A string list for the UI (badges, props) is computed by the registry from the interfaces and enabled flags, never
   declared by hand.
4. For gateways, deprecate `REDIRECT`, `LOOKUP`, `QR`, `STORED_METHODS` and `PENDING_SETTLEMENT` (ADR 0010). Keep
   `CAPTURE`/`REFUND`/`TOKENIZE`/`WEBHOOK`/`REMOTE_INPUT` as flags for now: their methods sit on the base interface
   with `AbstractGatewayDriver` defaults. Moving them to interfaces is a 2.0 change. Until the deprecated flags are
   removed, a test asserts that `supports(REDIRECT)` holds exactly when the driver is an `instanceof
HostedPaymentDriver`, for every registered driver.

### Registries

Every registry that extensions write to follows one shape:

- It is a concrete class in `app/Support/<Area>/`, bound as a singleton in `AppServiceProvider`, never by a feature
  module. It exists whatever `MODULES=` contains, so registration cannot fail because the consuming module is absent.
- `register(string $key, class-string<T>|Closure(Container): T $item, bool $replace = false): void`. Keys match
  `[a-z0-9_]{1,50}`. A duplicate key throws unless `$replace` is set, and a replacement is logged.
- Resolution is lazy, through the container. `register()` does no I/O and resolves nothing. Listing metadata may
  instantiate items, so constructors must be cheap: no HTTP, no queries, no decryption.
- Reads are `has(key)`, `get(key): T` (throws for an unknown key), `keys()`, and a typed `all()` or `metadata()`.
- The registry checks on first resolve that the item's own `key()` equals the registered key.
- A registry holds no request or account state. Per-account configuration is passed per call (`GatewayConfig`,
  `RegistrarConfig`).

UI-contribution registries (`Navigation`, `Dashboard`, `SettingsRegistry`, `PermissionRegistry`, `GlobalSearch`,
`ThemeRegistry`) keep their own methods. They are already kernel-owned, and the "Registries" rules apply to new
methods: options with defaults come after the third parameter and are documented with named arguments, and payload
shapes are documented next to the method (ADR 0010).

### Alignment of existing registries

- `ExchangeRateSources` and `PriceConverters` (#43): change to `register(key, class)`. Not blocking for #43. Once
  merged, the change is compatible: the old one-argument form keeps working, deprecated.
- `GatewayRegistry`: add a kernel `App\Support\Billing\GatewayDriverRegistry` with the shape above. Billing's
  `GatewayManager` reads it. `GatewayRegistry::register(instance)` stays as a deprecated adapter, so packages stop
  needing `callAfterResolving()`.
- Provisioning and registrar: ADR 0009.

## Consequences

- One way to declare a capability, and one way to register an extension. Packages register in `boot()` without
  knowing which modules are installed.
- Five dead gateway constants are deprecated rather than deleted. Removal waits for 2.0, or happens sooner within the
  pre-1.0 window if no external gateway exists by then.

## Addendum A — Saved payment methods (proposed, 2026-10-18)

Status: Proposed (module engineer, from the architecture review of #58; revised after the re-check, N1–N3). Applies to every gateway that stores a
payment method for later charges: Stripe now; Razorpay (cards, UPI Autopay) and PayPal (vault) later. Design and
field lists: `docs/product/payments-stripe.md` §7.

### Rules

1. **Hosted setup, authoritative completion.** A method is saved on the provider's own page through the opt-in
   `HostedPayMethodSetup` interface (`setupTtl`, `beginSetup`, `completeSetup`, `cancelSetup`). Only
   `completeSetup()` decides the result, and it retrieves the setup server-side. Browser parameters are hints.
   Card, wallet, UPI or bank data never reach our servers.
2. **State lives in a table.** Each setup is a `pay_method_setups` row with an explicit provider expiry, completed
   once under a row lock (`pending → completed`). The browser return, the reconcile poller and staff can each
   complete it. The row is the consent record (who, when, which session, the wording), and it outlives removal of
   the method. The cache is never the only copy of payment state.
3. **Opaque tokens, stored only by Billing.** A driver returns an opaque token plus display fields (`kind`, brand,
   last 4, expiry, label; `PayMethodKind` is an open set). Billing encrypts and stores it and never parses it. A charge uses only a stored token,
   selected by a client-scoped `pay_method_id`, never a token from a request.
4. **One provider customer per client, gateway and mode** (`gateway_customers`, unique on those three). The driver
   receives the ref and may return a replacement at begin, when the provider lost it. Billing stores the ref each
   setup used on the setup row. It replaces the client's ref only at begin, with a compare-and-set against the old
   value. A short lock covers only a client's first setup.
5. **Charges are recorded before the call.** A charge of a stored method creates a `payment_attempts` row first
   and passes its reference (`ChargeRequest::$attemptReference`). The driver derives its idempotency key from that
   reference. Settlement uses the attempt's stored amount, currency and rate (`CallbackEvent::ATTEMPT_UPDATED`).
    - **What every gateway guarantees:**
        - replaying the identical request inside its `chargeReplayWindow()` never charges twice;
        - unknown outcomes (timeout, 5xx, 409, 400 on mismatched idempotent parameters) return `ERROR`, never a
          decline;
        - `lookup()` answers "not found" only once the provider's index is consistent for our reference.
    - An unknown outcome is resolved by replaying the identical request, rebuilt only from the attempt row and
      inside the window counted from the first send. With a `null` window it is resolved by `lookup()` by our
      reference. A fresh charge is never used.
    - A webhook carrying the reference ends replays.
    - Until it is resolved, the attempt blocks new charges for that invoice and pay method, whatever its expiry.
6. **Every charge says who started it** (`ChargeRequest::$merchantInitiated`). Customer-initiated charges that need
   authentication fall back to the hosted payment page. Merchant-initiated ones stop retrying and raise
   `PaymentAuthenticationRequired`. Billing decides what follows; Notifications only renders the message.
7. **Revocation is its own capability** (`RevokesStoredTokens`), called best effort from a queued job when a method
   is removed or a client is erased.
8. **Consistency checks.** A test asserts that every `HostedPayMethodSetup` driver also declares `CAPTURE` and is a
   `HostedPaymentDriver`, the fallback for customer-initiated charges that need authentication.
   Billing offers "Add a method" only when both hold. Kernel additions are listed in the kernel changelog and the
   API snapshot (ADR 0010).

### Consequences

- Razorpay and PayPal map onto begin → redirect → authoritative complete without contract changes. RBI e-mandates
  add a trailing `?Money $maxAmount` to `PayMethodSetupRequest` when Razorpay lands.
- Two Billing tables and three columns on `payment_attempts`. One more poller pass (setups) in
  `billing:reconcile-payments`.
