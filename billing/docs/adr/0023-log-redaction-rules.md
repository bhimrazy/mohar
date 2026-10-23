# ADR 0023 — Log redaction rules

- Status: Proposed · 2026-10-23 · Owner: Principal architect.
- Amends: ADR 0009 §1 (the `Redactor`) and ADR 0014 "Logs".
- Related: issues #254, #311, #309, #356; PRs #252, #264, #294, #345, #368 (open); ADR 0009 Amendments 2, 3 and 5
  (#368); ADR 0010 (contract evolution), ADR 0012 (capabilities); CTO review 2026-10-22, recommendation 3;
  `billing/docs/security/standards.md` §4 and §6.

## Context

- **Masking is done in three places, with three rule sets.**
    - `App\Support\Integrations\Redactor` (995 lines) masks by key name, by value shape (provider key prefixes,
      bearer tokens, URL userinfo, `curl -u`, `name=value`, JSON and XML pairs, in one linear text pass) and by exact
      value. Exact values come from the caller as `list<string> $secrets`. Each is matched as typed, URL-encoded and
      base64, case-sensitively, from 3 characters. Nesting deeper than 32 is masked. Objects are read as arrays.
    - `TldAttributeService` masks a client's free-text TLD answers: `maskable()` gives exact variants (separators
      removed, upper and lower case) from 4 characters, and `guard()` runs regrouped, case-insensitive `/iu` patterns
      over the exception chain and the result message, on an `mb_scrub`bed copy, withholding the text if a pattern
      fails. Only `register` goes through `guard()`. #368 adds `meta`, through `RegistrarResult::mapText()`, with
      string and integer keys, depth 8 and objects withheld. `remote` is not masked, so an id is never changed.
    - `GatewayConfig::secretValues()` returns every setting of 8 characters or more, secret or not, and nothing calls
      it. `GatewayLogger` and the transaction `raw` columns use `redactPayment()`, which masks by key and card number
      only, so configured gateway credentials are never masked by value.
- **Exceptions are masked two ways.** `RegistrarGateway::maskChain()` and `guard()` rewrite the message of each
  exception in the chain in place. Five Services call sites (`ExecuteModuleCommand`, `SyncUsage`,
  `VerifyUnknownOutcomes`, `ServerController`, `RegistryProvisioningDrivers`) report a new `RuntimeException` with
  the masked message, which loses the class and the cause. `ExecuteModuleCommand` reports without the new password
  of a password change in its mask set, although it adds it for the error it stores.
- **The application log has no backstop.** `config/logging.php` is Laravel's default. The only processor is
  `PsrLogMessageProcessor`, on `papertrail` and `stderr`. Production runs `LOG_CHANNEL=stderr` with
  `JsonFormatter` (`deploy/Dockerfile`), not the `json_stderr` stack ADR 0014 names. Laravel applies a channel's
  `processors` only for the `monolog` driver; other drivers need a `tap`. Whatever `report()` or `Log::` receives is
  written as given unless the caller masked it.
- **Measured gaps** (develop at 445a08b3): a secret `hunter22x` is not masked in `Hunter22x`, nor in its HTML-escaped
  form. A 3-character secret `abc` masks every `abc` in the text, which shows staff what the secret is. A
  `JsonSerializable` whose `jsonSerialize()` returns itself exhausts memory in `Redactor::unwrap()` (the #63 item).
- **Fix rounds do not converge.** The `Redactor` has had 11 review rounds across #252 and #294. Each found a new
  shape, and each shape got its own probe file: 12 files, 540 lines. No invariant, input space or budget was
  written down, so a reviewer could not tell a deliberate bound from a leak.

## Decision

### 1. Where masking happens

1. **At the source, by the host (primary).** Provider text is masked where the host first stores, logs, raises or
   sends it on. The host is the code that called the driver: `RegistrarGateway`, Services' module-command and
   server actions, Billing's gateway actions and `GatewayLogger`, `WebhookSender`, `RunAutomation`. Only that code
   knows the call's credentials, request secrets and client answers, so only it can mask by value. Everything
   downstream (`last_error`, the activity log, events, emails, flash messages) takes text that is already masked.
   Drivers never mask and never call the `Redactor` (unchanged).
2. **A log processor as a backstop.** `App\Support\Logging\RedactingProcessor` (kernel, `@internal`) runs on every
   log channel, applied by the tap `App\Support\Logging\RedactLogs`. It masks the record's message, the string
   leaves and keys of `context` and `extra` (depth 8), and the message chain of a `Throwable` in `context`. It uses
   the shape rules plus the values in the current `RedactionScope` (§5). It never throws: if masking fails, the
   message becomes `[redacted]` and `extra.redaction_failed` is set. A test proves every source path masks with the
   processor removed, so the processor never hides a missing source mask.
3. **One exception helper.** `Redactor::maskThrowable(Throwable, RedactionSet): Throwable` masks the message of the
   exception and of up to 16 exceptions behind it, in place. It keeps the class, code, flags, trace and `previous`.
   It replaces `maskChain()`, `guard()`'s loop and the five `RuntimeException` wrappers, which report the original
   exception instead.
4. **Traces.** A parameter that carries a secret has `#[\SensitiveParameter]`. The production image keeps
   `zend.exception_ignore_args = On` (from `php.ini-production`), so trace arguments are not logged. The processor
   does not parse traces.
5. **What a driver logs.** A driver's own log line is a fixed sentence with ids, keys, lengths and classes in its
   context, never provider text (as `CpanelDriver` and `LogRegistrar` do now).

### 2. What is masked, and how

| Class                   | Source                                                                                                                                   | Matched by                                                                                                                  |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Configured credentials  | settings typed `Secret` or `SecretText`: `RegistrarConfig::secrets()`, `ProvisioningContext::secrets()`, `GatewayConfig::secrets()` (§5) | value: exact, case-insensitive, encoded forms                                                                               |
| Request secrets         | the request DTO's `secrets()` (EPP code, a new password)                                                                                 | value: as credentials                                                                                                       |
| Secret names and shapes | the `Redactor`'s key rule and value patterns, plus a driver's declared keys (§5)                                                         | name or shape, no value needed                                                                                              |
| Client answers (PII)    | free-text TLD answers (ADR 0009 Amendment 3); select and boolean answers are not masked                                                  | value: regrouped, case-insensitive, at word edges                                                                           |
| Provider bodies         | responses and error text                                                                                                                 | all of the above, then cut (4 KB excerpt, 16 KB command-log response; a gateway payload over 8 KB keeps only a fingerprint) |

Matching rules:

- **Credentials and request secrets** match as substrings in these forms: as typed, `urlencode`, `rawurlencode`,
  JSON-escaped (`\/`, `\uXXXX`) and HTML-escaped (`&amp;`, `&lt;`, `&gt;`, `&quot;`, `&#039;`), all
  case-insensitively, and base64 with and without padding, exactly. They are not regrouped: a provider does not
  regroup a random token, and regrouping short values would mask ordinary text. Longest form first, as now.
- **Client answers** match their letters and digits in order, with any run of space, `-`, `.`, `/` or `_` between
  them, case-insensitively (Unicode), plus the URL-encoded form. A match must not extend a run of the same kind: a
  digit answer inside a longer number, or a letter answer inside a longer word, is left alone. `2026` is still
  masked as a year on its own (#368 documents this), and no longer inside `20261`.
- **Minimum length: 4 characters** for every value match (separators removed for answers). A shorter value is
  masked only by its key or label. Masking a short value everywhere hides ordinary words and shows staff which word
  the secret is. The `Redactor`'s 3-character minimum becomes 4, and a secret setting refuses a value shorter than
  4 characters when it is saved, so no configured credential relies on value matching it cannot get.
- **Names and shapes** keep today's rules (words, adjacent pairs, run-together spellings, provider prefixes, the one
  linear text pass). A new rule is added with a corpus case (§5), not a probe file.
- **Readable on purpose.** `REFERENCE_KEYS`, `REFERENCE_ID_PATTERN`, `PAYMENT_REFERENCES` and `remote` (§3) are the
  only exceptions to the rules above. Each has a test that names why it stays readable. Adding one needs the
  architect's review.
- **Fail closed.** An unterminated quote, element or CDATA section masks to the end of the text. A pattern that
  fails (`preg_*` returns `null`) withholds the whole string. A branch past the depth cap is masked.
- **Budget.** Every rule is linear in the input: 1 MB of text in under 2 seconds (the existing timing test) and
  memory proportional to its length (tested from step 2 of §6). Value matching runs before any cut, so a cut never
  leaves part of a secret readable. Text that is masked by shape only may be cut first, as `WebhookSender` does at
  2 KB.

### 3. Registrar results and command-log copies

- `RegistrarGateway::call()` masks once, with one `RedactionSet` built from the account's credentials, the request's
  secrets and the request's client answers. `TldAttributeService` supplies the answers (`maskable()` becomes the
  list of answer values) and no longer wraps the call, so every command, not only `register`, masks them.
- **State copy**, returned to the caller and stored (`domains.last_error`, `registrar_meta`, the activity log,
  `RegistrarActionFailed` and the staff email it triggers): the message and `meta` are masked through `mapText()`
  and `withMessage()`, with keys and depth as #368 settled. `remote` is unchanged: it holds the ids later calls and
  proof of ownership need, and a mask would change one.
- **Log copy**, written to `registrar_command_log`: built from the state copy, then `redact()` with the same set and
  the driver's declared keys (§5), with `remote` masked as well, since the log is not read back as state. Then it is
  cut to 16 KB. `request` keeps listing TLD attribute keys only (#345).
- Transfer reasons, lookup messages and connection checks (`ConnectionCheck::mapText()`) get the same set.

### 4. Invalid UTF-8, depth and objects

- The key rule, the text pass and credential matching work on bytes, so invalid UTF-8 never switches them off.
- Answer patterns (`/iu`) run on an `mb_scrub`bed copy, and that copy is what is kept. If a pattern still fails, the
  string is withheld (`[redacted]`), as `guard()` does now.
- Depth caps: provider payloads 32 (`redact()`, `redactPayment()`), result `meta` 8 (#368), log context 8. A deeper
  branch is replaced by `[redacted]`.
- Keys: string keys and integer keys (as their digits) are masked. A key the mask changes is replaced by
  `[redacted]` (numbered if it repeats) and its value is withheld, as #368 settled.
- Objects: `redact()` keeps reading `Arrayable`, `JsonSerializable`, enums, dates and public properties as arrays.
  Each unwrap counts towards the depth cap, so an object that returns itself ends at the cap instead of exhausting
  memory. `mapText()` withholds objects (#368). The processor unwraps like `redact()`.

### 5. How sensitive values are declared, and the test contract

- **Credentials are declared by type**, nothing else: a `SettingField` of type `Secret` or `SecretText`. Every config
  DTO has the same method, `secrets(): list<string>`, and the same `secretSettingKeys`. `GatewayConfig` gains both,
  limited to secret-typed fields, and `secretValues()` is removed (it has no caller).
- **Request secrets**: a request DTO that carries a secret implements `secrets(): list<string>`, as
  `RegistrationRequest` does. A secret the host passes to a driver as an argument (the new password of
  `ChangesPassword::changePassword()`) goes into the call's set.
- **Extra keys**: the capability `App\Contracts\Integrations\DeclaresSensitiveKeys` (ADR 0012) with
  `sensitiveKeys(string $operation): list<string>` names exact keys that are secret in one operation's payloads
  (`code` in an EPP code answer, `url` in a single sign-on answer). It replaces `RegistrarGateway::codeKeys()`. There
  is no global key registry.
- **Client answers** are not declared by drivers. The host decides that free-text answers are personal data.
- **`RedactionSet`** (kernel value object) carries the three value lists for one call and builds their match forms
  once. It replaces the `list<string> $secrets` parameter of the `Redactor`. Until the hosts have moved, the
  `Redactor` also accepts the list (ADR 0010, pre-1.0: every in-repo caller moves in the same series, then the list
  form is removed).
- **`RedactionScope`** (scoped binding, reset per request and per queued job): the host runs the driver call inside
  `RedactionScope::during($set, $call)`, so a log line written during the call, by the driver or by `report()`, is
  masked by value too. It holds only the sets of calls in progress and drops each one in a `finally`.
- **Test contract** `tests/Support/Contracts/RedactionContract.php`, used by the registrar, provisioning and (new)
  gateway driver contracts:
    1. every setting whose key reads like a credential is typed secret;
    2. no text the driver composes (messages, reasons, connection-check text, exception messages) contains a
       configured secret or request secret, checked raw, before host masking;
    3. on the host path, with a provider answer echoing every secret in each form of §2 (as typed, upper case,
       URL-encoded, base64, JSON- and HTML-escaped, as a labelled pair) and each client answer regrouped, none
       reaches the stored rows, `last_error`, the activity log, event payloads, mail or a log record captured with
       the processor removed.
- **Corpus test** (CTO recommendation 3): a slow, plain reference masker (test-only) and a generated corpus of
  shapes, encodings, separators and positions. Every token the reference masks must be masked by the `Redactor`,
  within the time and memory budget of §2. It replaces the 12 probe files. `RedactorParityTest` and its frozen
  develop copy stay until the corpus covers their cases, then go.

### 6. Migration, in order

Each step is one PR under 2,000 changed lines. Kernel steps are made by the CTO session (repository rule).

1. **This ADR**, and a pointer to it in `architecture.md` (docs only).
2. **Tests first**: reference masker, generated corpus and budgets; the probe files folded into
   `RedactorShapesTest` groups with helpers in `tests/Support` (#311). Rules unchanged, so it passes on develop.
3. **Kernel rules**: `RedactionSet` with the §2 forms and the 4-character minimum, answer patterns moved from
   `TldAttributeService` into the kernel, `maskThrowable()`, the self-returning object fix, #309, and the HTML marker
   walk split out of `Redactor` (#311). The corpus from step 2 proves nothing became readable.
4. **Domains host**: `RegistrarGateway` builds the set and masks state and log copies as in §3; `guard()` and
   `maskChain()` removed; `DeclaresSensitiveKeys` replaces `codeKeys()`. After #368 merges.
5. **Services and Core hosts**: the five `RuntimeException` wrappers become `maskThrowable()`, the module-command
   set includes the new password, `WebhookSender` and `RunAutomation` pass a set.
6. **Billing**: `GatewayConfig::secrets()`, credentials masked by value in `gateway_logs` and transaction and refund
   `raw`, and the 4-character minimum on secret settings.
7. **Backstop**: `RedactingProcessor`, `RedactLogs` on every leaf channel, `RedactionScope` around driver calls in
   the three gateways, and an Arch test that fails on a channel in `config/logging.php` without the tap (the `stack`
   and `null` channels excepted). The ADR 0014 log channel name is reconciled with `deploy/Dockerfile` here.
8. **Contracts**: `RedactionContract` in the registrar and provisioning contracts, and a first gateway driver
   contract (eSewa, Khalti, Stripe).

## Consequences

- One rule set, one value object and one exception helper for every driver family. A package driver declares
  secrets by field type and, when needed, `DeclaresSensitiveKeys`, and passes one contract.
- The processor catches leaks of known shapes in lines nobody masked. It cannot see database rows, so source masking
  stays mandatory and tested without it.
- A reviewer can tell a leak from a bound: a leak is a corpus token the reference masks and the `Redactor` does not;
  anything else is a rule change and needs an ADR amendment.
- Behaviour changes staff will notice: a 3-character credential is no longer masked by value (and can no longer be
  saved), case variants are masked, `2026` inside a longer number stays readable, and the command log masks
  `remote` while the domain keeps it.
- The processor costs nothing on a request that writes no log line. Expected outcomes are not logged (ADR 0014), so
  the hot paths in `billing/docs/devops/sizing.md` are not affected.
- The `emergency` logger, which Laravel uses only when logging itself fails, bypasses the processor.

## Alternatives rejected

- **The processor as the primary mask.** It does not know a call's credentials or a client's answers, and most
  provider text goes to database rows, events and mail, which it never sees.
- **Drivers mask their own output.** Every package would carry its own copy of the rules, and ADR 0009 already makes
  the host responsible.
- **A registry of every configured secret for the processor.** It would decrypt every credential to write one log
  line and keep them all in memory. `RedactionScope` holds only the current call's.
- **Masking `remote` in the state copy.** It would change registrar ids that later calls and ownership proofs need.
- **A shorter minimum, or masking answers inside longer runs.** Both hide ordinary words and reveal which word was
  the secret.
- **Re-wrapping exceptions in `RuntimeException`.** It loses the class, the retry flag and the cause.
- **A third-party redaction package.** It would need approval and the 7-day cooldown (ADR 0007), and our shapes (XML
  items, eSewa values with `&`, regrouped answers) would still need our own rules.
