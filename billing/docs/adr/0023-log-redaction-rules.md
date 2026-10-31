# ADR 0023 — Log redaction rules

- Status: Accepted · 2026-10-23 (CTO), after two security reviews of #382 · Owner: Principal architect.
- Amends: ADR 0009 §1 (the `Redactor`) and ADR 0014 "Logs".
- Related: issues #254, #311, #309, #356, #383; PRs #252, #264, #294, #345, #368 (open), #386 (hot-fix); ADR 0009
  Amendments 2, 3 and 5 (#368); ADR 0010 (contract evolution), ADR 0012 (capabilities); CTO review 2026-10-22,
  recommendation 3; `billing/docs/security/standards.md` §4 and §6.

## Context

- **Masking is done in four places, with four rule sets.**
    - `App\Support\Integrations\Redactor` (995 lines) masks by key name, by value shape (provider key prefixes,
      bearer tokens, URL userinfo, `curl -u`, `name=value`, JSON and XML pairs, in one linear text pass) and by exact
      value. Exact values come from the caller as `list<string> $secrets`. Each is matched as typed, URL-encoded and
      base64, case-sensitively, from 3 characters. Nesting deeper than 32 is masked. Objects are read as arrays.
      Integer and float leaves are kept as they are, and the keys of a decoded JSON string are not compared with
      the values.
    - `TldAttributeService` masks a client's free-text TLD answers: `maskable()` gives exact variants (separators
      removed, upper and lower case) from 4 characters, and `guard()` runs regrouped, case-insensitive `/iu` patterns
      over the exception chain and the result message, on an `mb_scrub`bed copy, withholding the text if a pattern
      fails. A match may sit anywhere, inside a longer number too. Only `register` goes through `guard()`. #368 adds
      `meta`, through `RegistrarResult::mapText()`, with string and integer keys, depth 8 and objects withheld.
      `remote` is not masked, so an id is never changed.
    - `GatewayConfig::secretValues()` returns every setting of 8 characters or more, secret or not, and nothing calls
      it. `GatewayLogger` and the transaction `raw` columns use `redactPayment()`, which masks by key and card number
      only, so configured gateway credentials are never masked by value.
    - `Notifications\Services\SecretTemplatePolicy::maskValues()` masks one-time secrets (access keys, tokens) in
      mail. It decodes HTML entities and percent-encoding until the text is stable (`normalise()`) and replaces any
      word whose decoded form holds a secret with `••••`.
- **Exceptions are masked two ways.** `RegistrarGateway::maskChain()` and `guard()` rewrite the message of each
  exception in the chain in place. Seven Services call sites report a new `RuntimeException` with the masked
  message, which loses the class and the cause: `ExecuteModuleCommand` (twice: the command's `catch` and
  `callDriver()`), `SyncUsage`, `FetchServerAccounts`, `VerifyUnknownOutcomes`, `ServerController` and
  `RegistryProvisioningDrivers`. Both `ExecuteModuleCommand` sites leave the new password of a password change out of
  their mask set; the CTO fixes that separately (#383, hot-fix #386). `CpanelSmokeCommand` prints exception messages
  unmasked, and the throwaway password it generates is in no mask set.
- **The application log has no backstop.** `config/logging.php` is Laravel's default. Its only processor is
  `PsrLogMessageProcessor`: listed on `papertrail` and `stderr`, and added by `replace_placeholders` on the file,
  Slack, syslog and errorlog channels. Production runs `LOG_CHANNEL=stderr` with `JsonFormatter`
  (`deploy/Dockerfile`), not the `json_stderr` stack ADR 0014 names. Laravel applies a channel's `processors` only
  for the `monolog` driver; other drivers need a `tap`. Whatever `report()` or `Log::` receives is written as given
  unless the caller masked it. A failed queued job's exception goes to `failed_jobs.exception` as
  `(string) $exception`, through the queue failer, not through Monolog.
- **Measured gaps** (develop at 445a08b3, and the #382 security review): a secret `hunter22x` is not masked in
  `Hunter22x`, nor in its HTML-escaped, hex, base64url or offset base64 forms (`base64('user:'.$secret)`). A
  3-character secret `abc` masks every `abc` in the text, which shows staff what the secret is. Answers regrouped
  as `(981) 234-5678`, `518,247,535,56` or `518%20247%20535%2056`, or written in Devanagari digits, stay readable. A
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
   downstream (`last_error`, the activity log, events, emails, flash messages, Inertia props) takes text that is
   already masked. Drivers never mask and never call the `Redactor` (unchanged).
2. **A log processor as a backstop.** `App\Support\Logging\RedactingProcessor` (kernel, `@internal`) runs on every
   log channel, added by the tap `App\Support\Logging\RedactLogs`.
    - It masks the record's message, the string and numeric leaves and the keys of `context` and `extra` (depth
      8), and the message chain of any `Throwable` in `context`. It uses the shape rules plus the values of every
      `RedactionSet` it can find: the sets of calls in progress and the sets attached to a `Throwable` in the record
      (§5).
    - **It runs last.** The tap pushes it onto each channel's handlers, not onto the logger. Monolog runs logger
      processors, `PsrLogMessageProcessor` included, before a handler's, so placeholders are filled in before the
      record is masked. A test pins the order with `Log::warning('token={t}', ['t' => $value])`. A handler that
      cannot take processors fails the tap at boot.
    - **It never throws, and it does not re-enter.** If masking fails, or a context object logs from inside
      `toArray()` or `jsonSerialize()` while the processor runs, the message becomes `[redacted]`, the context is
      dropped and `extra.redaction_failed` is set.
    - A test proves every source path masks with the processor removed, so the processor never hides a missing
      source mask.
3. **Other sinks.** `failed_jobs.exception` is a sink: the kernel decorates the `queue.failer` binding so that the
   exception is passed through `maskThrowable()` (shape rules and any attached set) before it is stored. An
   error-tracking service, if one is added, takes exceptions only after the same call. Code outside tests never
   writes to `error_log()`, `syslog()`, `openlog()`, `STDERR` or `php://stderr`, and never builds a logger with `Log::build()`
   (§6, step 4).
4. **One exception helper.** `Redactor::maskThrowable(Throwable, ?RedactionSet): Throwable` masks the message of the
   exception and of up to 16 exceptions behind it, in place. It keeps the class, code, flags, trace and `previous`.
   It replaces `maskChain()`, `guard()`'s loop and the seven `RuntimeException` wrappers, which report the original
   exception instead.
5. **Traces.** A parameter that carries a secret has `#[\SensitiveParameter]`. The production image keeps
   `zend.exception_ignore_args = On` (from `php.ini-production`), so trace arguments are not logged. The processor
   does not parse traces.
6. **What a driver logs.** A driver's own log line is a fixed sentence with ids, keys, lengths and classes in its
   context, never provider text (as `CpanelDriver` and `LogRegistrar` do now).
7. **Log channel.** ADR 0014's `LOG_STACK=json_stderr` is replaced by what production runs: `LOG_CHANNEL=stderr` with
   `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`. A one-channel stack adds nothing, and the image and
   `docs/devops/container-image.md` already set `stderr`. cPanel installs log to `single`, which gets the same tap.

### 2. What is masked, and how

| Class                   | Source                                                                                                                                   | Matched by                                                                                                                  |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Configured credentials  | settings typed `Secret` or `SecretText`: `RegistrarConfig::secrets()`, `ProvisioningContext::secrets()`, `GatewayConfig::secrets()` (§5) | value: substring, case-insensitive, encoded forms                                                                           |
| Request secrets         | the request DTO's `secrets()` (EPP code), and secrets the host passes as arguments (a new password)                                      | value: as credentials                                                                                                       |
| Secret names and shapes | the `Redactor`'s key rule and value patterns, plus a driver's declared keys (§5)                                                         | name or shape, no value needed                                                                                              |
| Client answers (PII)    | free-text TLD answers (ADR 0009 Amendment 3); select and boolean answers are not masked                                                  | value: regrouped, case-insensitive                                                                                          |
| Provider bodies         | responses and error text                                                                                                                 | all of the above, then cut (4 KB excerpt, 16 KB command-log response; a gateway payload over 8 KB keeps only a fingerprint) |

Matching rules:

- **Values are trimmed** before their match forms are built. Forms are built once per set, longest first.
- **Credentials and request secrets** match as substrings, in these forms:
    - as typed, `urlencode` and `rawurlencode`, JSON-escaped (`\/`, `\uXXXX`), all case-insensitively;
    - HTML-escaped, in every entity spelling (`&#39;`, `&#039;`, `&#x27;`, `&apos;`, `&#x2F;` and the rest). These are
      found as `SecretTemplatePolicy::normalise()` does: a word that holds `&` or `%` is decoded until it is stable
      (at most 6 passes), and the whole word is masked when its decoded form holds a value. `normalise()` moves into
      the kernel, and Notifications uses it from there;
    - base64 and base64url, with and without padding, matched exactly (base64 is case-sensitive). The value is
      encoded after 0 prefix bytes, and also after 1 and 2 prefix bytes with the edge characters that depend on the
      neighbouring bytes dropped. This covers `user:secret` in a Basic header or a dump. The offset forms are built
      only when what is left is 8 characters or more (values of about 7 bytes or more): a shorter core is drawn from
      the letter alphabet and would match inside ordinary words;
    - hex in either case, for values of 8 bytes or more;
    - for `SecretText` (a PEM or SSH key), each line of 16 characters or more also on its own, so CRLF line endings
      and re-wrapped keys are caught;
    - **truncated echoes**: for values of 16 bytes or more, a run of token characters (letters, digits, `-`, `_`,
      `+`, `/`, `=`) that starts with the value's first 8 bytes or ends with its last 8 bytes is masked whole. An
      anchor is built only from token characters: an anchor that holds any other character (`:`, a space) is not
      built, and neither is one that equals a known shape prefix (`sk_live_`, `rk_live_`), which the shape rules
      already mask. A truncated echo shorter than 8 bytes stays readable; that is the bound.
    - They are not regrouped: a provider does not regroup a random token, and regrouping short values would mask
      ordinary text.
- **Client answers** match their letters and digits in order, case-insensitively (Unicode).
    - Between two characters, 0 to 3 separators are allowed, matched possessively. A separator is any character that
      is not a letter or digit (space, `-`, `.`, `/`, `_`, `,`, `(`, `)`, `+` and so on), or a percent-encoded byte
      (`%20`). So `51824753556`, `(981) 234-5678`, `518,247,535,56` and `518%20247%20535%2056` match. Separators are
      never letters or digits, so nothing backtracks more than a constant per position.
    - Each digit in the pattern matches its ASCII form or its Devanagari form (U+0966 to U+096F), so no copy is
      folded and offsets stay as they are.
    - An answer of 8 letters or digits or more matches anywhere, as `guard()` does now: `9812345678` is masked in
      `+9779812345678` and in `009812345678`. A shorter answer must not extend a run of the same kind: a 4-digit
      answer inside a longer number, or a 4-letter answer inside a longer word, is left alone. `2026` is still masked
      as a year on its own (#368 documents this), and no longer inside `20261`.
- **Minimum length for a match: 4** bytes for credentials and request secrets (they are matched on bytes), 4
  letters or digits for answers. A shorter value is masked only by its key or label. Masking a short value
  everywhere hides ordinary words and shows staff, by elimination, which word the secret is.
- **Minimum length for a new secret setting: 8 bytes.** Saving a `Secret` or `SecretText` value shorter than 8 bytes
  is refused, unless the field declares a shorter known format through a new trailing, defaulted
  `SettingField::$minLength` (a 6-digit PIN declares 6). A field that declares a `minLength` under 4 is refused when
  it is built, since such a value could never be masked by value. Values already stored are not broken: a read-only
  check finds every stored secret below its minimum and names it by setting key, never by value. Its field is marked
  on its settings form ("too short, replace it"), and a read-only artisan command lists them all. The check is also
  shown in Health once the ADR 0014 `HealthChecks` registry exists.
- **Names and shapes** keep today's rules (words, adjacent pairs, run-together spellings, provider prefixes, the one
  linear text pass). A new rule is added with a corpus case (§5), not a probe file.
- **Readable on purpose.** `REFERENCE_KEYS`, `REFERENCE_ID_PATTERN`, `PAYMENT_REFERENCES` and `remote` (§3) are the
  only exceptions to the rules above. Each has a test that names why it stays readable. Adding one needs the
  architect's review.
- **Fail closed.** An unterminated quote, element or CDATA section masks to the end of the text. A pattern that
  fails (`preg_*` returns `null`) withholds the whole string. A branch past the depth cap is masked.
- **Budget.** Every rule is linear in the input: 1 MB of text in under 2 seconds (the existing timing test) and
  memory proportional to its length (tested from step 2 of §6). Each value adds a fixed number of forms (about
  20), so the cost is the text's length times the number of forms, never the input squared. Forms are built once
  per set and reused across records. The budget test runs with a realistic maximum set (16 values, `SecretText`
  among them) through the processor path as well as through `redact()`. Value matching runs before any cut, so a cut
  never leaves part of a secret readable. Text that is masked by shape only may be cut first, as `WebhookSender`
  does at 2 KB.

### 3. Registrar results and command-log copies

- `RegistrarGateway::call()` masks once, with one `RedactionSet` built from the account's credentials, the request's
  secrets and the request's client answers. `TldAttributeService` supplies the answers (`maskable()` becomes the
  list of answer values) and no longer wraps the call, so every command, not only `register`, masks them.
- **State copy**, returned to the caller and stored (`domains.last_error`, `registrar_meta`, the activity log,
  `RegistrarActionFailed` and the staff email it triggers): the message and `meta` are masked through `mapText()`
  and `withMessage()`, with keys and depth as #368 settled.
- **`remote` in the state copy** is never partly masked: it holds the ids later calls and proof of ownership need,
  and a mask would change one. It is not compared with the substring rules of §2, which would hit a numeric order id
  that happens to contain a short credential or a phone number. A leaf is a hit only when it equals one of a value's
  match forms, or, for values of 8 bytes or more, contains one at token boundaries (not preceded or followed by a
  letter or digit). On a hit the host withholds the whole leaf (`[redacted]`), sets the domain's `needs_review`, and
  logs a fixed warning naming the registrar and the key, so no renew or transfer runs on a lost id until staff
  check it. The admin page's Inertia prop (`DomainActionController`) and `DomainResource` read `registrar_meta`, so
  neither holds the value.
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
- Keys: in `redact()` (decoded JSON strings included), `mapText()` and the processor, string keys and integer keys
  (as their digits) are compared with the set's values as well as the key rule. A key the mask changes is replaced
  by `[redacted]` (numbered if it repeats) and its value is withheld, as #368 settled.
- Numbers: an integer or float leaf is compared, as its digits, with the set's values. One that holds a value is
  replaced by `[redacted]` (`{"citizenship":51824753556}`, as a leaf or inside a decoded JSON string). Booleans and
  null are kept.
- Objects: `redact()` keeps reading `Arrayable`, `JsonSerializable`, enums, dates and public properties as arrays.
  Each unwrap counts towards the depth cap, so an object that returns itself ends at the cap instead of exhausting
  memory. `mapText()` withholds objects (#368). The processor unwraps like `redact()`, under its re-entry guard.

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
- **`RedactionScope`**: the host runs the driver call inside `RedactionScope::during($set, $call)`.
    - While the call runs, the processor masks with the set, so a line the driver writes is masked by value too.
    - When a `Throwable` leaves `during()`, the set is attached to it in a process-wide
      `WeakMap<Throwable, RedactionSet>` before it is re-thrown. The processor, `maskThrowable()` and the failer
      decorator look up every exception along the `previous` chain, so a `report()` in the host's `catch`, a log
      line the queue worker writes after the job, and `failed_jobs.exception` are masked by value after the scope
      has ended. When `during()` calls nest, the sets are merged onto the exception, never replaced. An entry goes
      when its exception is freed. The processor uses only the sets of calls in progress and those attached to the
      exceptions in the record at hand, so one job never masks with another job's set.
    - The scope holds only the sets of calls in progress and drops each one in a `finally`.
- **Test contract** `tests/Support/Contracts/RedactionContract.php`, used by the registrar, provisioning and (new)
  gateway driver contracts:
    1. every setting whose key reads like a credential is typed secret;
    2. no text the driver composes (messages, reasons, connection-check text, exception messages) contains a
       configured secret or request secret, checked raw, before host masking;
    3. on the host path, with a provider answer echoing every secret in each form of §2 and each client answer in
       each regrouping of §2, none reaches the stored rows, `last_error`, `remote` as stored, the activity log,
       event payloads, mail, Inertia props or a log record captured with the processor removed. It includes a
       `remote` leaf equal to a secret (withheld, `needs_review` set) and a `remote` id that contains a short
       credential by coincidence (kept);
    4. the same host path inside a queued job that fails: none reaches `failed_jobs.exception` or the worker's log
       record.
- **Corpus test** (CTO recommendation 3): a slow, plain reference masker (test-only) and a generated corpus of
  shapes, every encoding, alignment and regrouping of §2, separators, line endings and positions. Every token the
  reference masks must be masked by the `Redactor`, within the time and memory budget of §2. Contract item 3 draws
  its forms from the same generator, so a form added to §2 is tested everywhere at once. The corpus replaces the 12
  probe files. `RedactorParityTest` and its frozen develop copy stay until the corpus covers their cases, then go.

### 6. Migration, in order

Each step is one PR under 2,000 changed lines. Kernel steps are made by the CTO session (repository rule). The
missing new-password mask in `ExecuteModuleCommand` is fixed now, outside this series (#383, #386).

1. **This ADR**, and a pointer to it in `architecture.md` (docs only).
2. **Tests first**: reference masker, generated corpus and budgets; the probe files folded into
   `RedactorShapesTest` groups with helpers in `tests/Support` (#311). Rules unchanged, so it passes on develop.
3. **Kernel rules**: `RedactionSet` with the §2 forms and the 4-byte match minimum, answer patterns moved from
   `TldAttributeService` into the kernel, numeric leaves and JSON keys, `normalise()` moved into the kernel,
   `maskThrowable()`, the self-returning object fix, #309, and the HTML marker walk split out of `Redactor` (#311).
   In the same PR, the 8-byte refusal at save (`SettingField::$minLength`, at least 4), and the check of stored short
   secrets shown on each settings form and as a read-only artisan command, so no stored value loses its mask
   without staff being told. The corpus from step 2 proves nothing else
   became readable.
4. **Backstop**: `RedactingProcessor` on every channel's handlers, `RedactLogs`, `RedactionScope` with its
   `WeakMap`, the `queue.failer` decorator, the re-entry guard, the order test, and the `stderr` channel (§1.7). An
   Arch test fails on a channel in `config/logging.php` without the tap (`stack` and `null` excepted), and on
   `Log::build()`, `LogManager::build()`, `error_log()`, `syslog()`, `openlog()`, `STDERR` or `php://stderr` in
   `app/`. It comes before any
   host step, because steps 5 and 6 report the original exception with its full `previous` chain, which today's
   wrappers drop.
5. **Domains host**: `RegistrarGateway` builds the set, runs the call in `RedactionScope::during()`, and masks state
   and log copies and withholds `remote` leaves as in §3; `guard()` and `maskChain()` removed;
   `DeclaresSensitiveKeys` replaces `codeKeys()`. After #368 merges.
6. **Services and Core hosts**: the seven `RuntimeException` wrappers become `maskThrowable()`, driver calls run in
   `RedactionScope::during()`, `WebhookSender` and `RunAutomation` pass a set, and `CpanelSmokeCommand` masks the
   messages it prints with a set that includes its throwaway password.
7. **Billing**: `GatewayConfig::secrets()`, gateway calls in `RedactionScope::during()`, and credentials masked by
   value in `gateway_logs` and transaction and refund `raw`.
8. **Contracts**: `RedactionContract` in the registrar and provisioning contracts, and a first gateway driver
   contract (eSewa, Khalti, Stripe).

## Consequences

- One rule set, one value object and one exception helper for every driver family. A package driver declares
  secrets by field type and, when needed, `DeclaresSensitiveKeys`, and passes one contract.
- The processor catches leaks of known shapes in lines nobody masked, and, through the `WeakMap`, values in
  exceptions that left a driver call. It cannot see database rows other than `failed_jobs`, so source masking stays
  mandatory and tested without it.
- A reviewer can tell a leak from a bound: a leak is a corpus token the reference masks and the `Redactor` does not;
  anything else is a rule change and needs an ADR amendment. The bounds are written down: values under 4 bytes,
  truncated echoes under 8 bytes, offset base64 forms for values under about 7 bytes, short answers inside longer
  runs, and `remote` leaves that hold a value other than at token boundaries.
- Behaviour changes staff will notice: a new secret under 8 bytes cannot be saved without a declared format; a stored
  short secret is listed for replacement and, under 4 bytes, is masked only by key; case, entity and encoding
  variants are masked; `2026` inside a longer number stays readable; a `remote` leaf holding a set value is
  withheld and the domain goes to review; and the command log masks `remote` while the domain keeps it.
- The processor costs nothing on a request that writes no log line. Expected outcomes are not logged (ADR 0014), so
  the hot paths in `billing/docs/devops/sizing.md` are not affected.
- The `emergency` logger, which Laravel uses only when logging itself fails, bypasses the processor.

## Alternatives rejected

- **The processor as the primary mask.** It does not know a call's credentials or a client's answers, and most
  provider text goes to database rows, events and mail, which it never sees.
- **Drivers mask their own output.** Every package would carry its own copy of the rules, and ADR 0009 already makes
  the host responsible.
- **A registry of every configured secret for the processor.** It would decrypt every credential to write one log
  line and keep them all in memory. `RedactionScope` and the `WeakMap` hold only the sets of calls in progress and
  of exceptions still alive.
- **Masking `remote` in the state copy.** It would change registrar ids that later calls and ownership proofs need.
  Withholding a whole leaf only on equality or a token-boundary match, with the domain sent to review, keeps a
  real id that contains a short value by coincidence.
- **A shorter match minimum, or the word-edge rule for long answers.** The first hides ordinary words and reveals
  which word was the secret. The second would leave `9812345678` readable in `+9779812345678`.
- **Re-wrapping exceptions in `RuntimeException`.** It loses the class, the retry flag and the cause.
- **A third-party redaction package.** It would need approval and the 7-day cooldown (ADR 0007), and our shapes (XML
  items, eSewa values with `&`, regrouped answers) would still need our own rules.

## Amendment (#452) — 2026-10-24, node budget

- **Bound.** Each outermost `redact()`, `redactPayment()`, `maskValues()` or `maskThrowable()` call (per message) reads
  at most 50,000 values through objects: each object it unwraps, and every entry under one. Past that, those values
  are masked (`[redacted]`), keys still checked. Plain arrays and decoded JSON text are not counted, since they are
  bounded by the input (the §2 budget test keeps a 1 MB JSON body intact). The budget belongs to the outermost call,
  not to an instance: a call made from inside `jsonSerialize()` or `toArray()`, on the same instance or on a new
  `app(Redactor::class)`, shares it. §4 "Objects" now has this bound as well as the depth cap.
- **Why.** A `JsonSerializable` that returns two new copies of itself stays under the depth cap of 32 and builds 2^32
  nodes, which exhausts memory (#399). With the budget it peaks at about 4 MB.
- **Known gap.** The budget counts values, not bytes. An object that returns the same large string again and again
  costs one text pass per copy, up to the budget. Only code (a driver or package object) can do this, not provider
  input, so it is a bound, not a leak.
- **Tail pairs** (rule change, with corpus cases). An unquoted secret value runs on through a soft end with more text
  after it (`passwd=ab;cd`, since #252), so it can take in the next pair's name (`pw=ab;token: x`) and leave that
  pair's value readable. When a masked value ends in a secret label, the value after it is masked as that pair's
  value, if it is unquoted, up to any pair or tag the text pass would find in it. It only adds masks, and the scan
  stays linear. The parity guard also runs with a secret spliced next to each masked value, and with a soft end and a
  following pair in its generated shapes. A pair name develop showed may stay readable when its value is masked
  (`RedactorParity::namesMaskedValue`).

## Amendment (#462) — 2026-10-24, log backstop (step 4) and the first host steps

- **Step 4 landed as §1.2, §1.3 and §5 describe**, with these choices:
    - `RedactingProcessor` masks `context` and `extra` through `redact()`: keys by name and by the set, values by name,
      shape and set, objects unwrapped with the depth cap of 32 and the node budget, not a separate depth-8 walk. A
      `Throwable` in `context` stays an object for the formatter, its message chain masked in place
      (`maskThrowable()`). A withheld record drops `extra` as well as `context`, keeping only `redaction_failed`.
    - `RedactLogs` puts the processor last on each handler by taking the handler's own processors off and putting them
      back after it. Every channel in `config/logging.php` lists the tap except `stack` (its channels carry it) and
      `null`.
    - `RedactionScope::during()` and `current()` are in `App\Support\Integrations` (`@api`), beside `RedactionSet`,
      which gains `merge()`. `maskThrowable()` reads the scope's sets as well as the one passed in.
    - `queue.failer` is wrapped by `RedactingFailedJobProvider`, which masks the exception before the wrapped
      provider stores it.
    - The tap and the processor are tested through a channel built like the configured ones; the Arch test of step 4
      is `tests/Arch/LogRedactionTest.php`.
- **Host steps in this PR** (rest of #254 and item 4 of #383):
    - `ActivityLogger` masks text in `$properties` by the value rules and the scope's sets, but does not read property
      names as secret names: audit properties such as a template's `key` are references, and the key rule would
      withhold them. No caller passes a credential as a property: the only secret-looking names in `app/` are `key`
      (a reference) and `one_time_password` (a boolean flag).
    - `FraudScreening::record()` stores `fraud_checks.raw` through `redact()` and the reasons through `maskValues()`,
      with the scope's sets.
    - Billing (step 7, first half): `GatewayLogger::redact($payload, $gateway)` masks the gateway's
      `GatewayConfig::secrets()` and the scope's sets by value, through a new `$secrets` argument of
      `redactPayment()`. `gateway_logs` and every stored `raw` (payments, refunds, chargebacks, attempts) pass the
      gateway key. Gateway calls run in `RedactionScope::during()` since the review round (amendment below).
- **Tail pairs, replacing the #452 rule.** The value after a swallowed secret label is read with the plain pair rules
  (`valueEnd()`: quotes, brackets, an authorization scheme, `=` or `:` inside) and masked whole, and the chain goes
  on while each masked value ends in a secret label. The label is the last name start in the span's trailing name, or
  the first (the whole run, as #452 read it); it must end at the span's end, before a final `=` or `:` and blanks.
  The label stays inside the first mask. Two shapes are left to the scan as before, since they may be the next
  pair's name or span markup: a quoted or bracketed value a separator follows (`"password":`), and a quoted one that
  holds `<`. Any secret pair inside the masked value that reaches past it (`1&hash:Bearer x`) extends the mask to its
  own end (bounded in the review round, amendment below).
- **A mask inside a value counts as value characters** (the set's, or one a provider wrote). The value ends where it
  would without the set: `password=[redacted]PRE4` and `pw=ab[redacted];cd` are masked whole, as `password=xPRE4` and
  `pw=abx;cd` are. A secret pair inside such a value that the scan would have read after the mask (`[redacted];
  token=[a b]`) extends the mask to its own end, as for tail values.
- **`content`** is masked like `value` on an element labelled secret by `key` or `name` (`<meta name="api_key"
  content="…">`). It does not make the label readable.
- **CDATA in a value.** An unquoted value that reaches `<![CDATA[` takes the section. It ends after `]]>`, or at the
  end of the text when unterminated. A section that holds a quote, bracket, `&`, `\`, `<` or `cookie` could start a
  pair the scan reads past `]]>`, so the value ends before such a section, as before.
- **Proof.** Differential fuzz of the head against develop `891c2035`, comparing raw strings on five surfaces with six
  secret sets, finds no token develop masked and the head shows. Comparing develop's `redactPayment()` without a set
  against the head's with the gateway's set shows one kind of difference: a configured value that is, or sits inside,
  a label or name (`PARSECRET1]: …`, `reg_passwSECRETord`) no longer labels the text after it once it is masked. The
  security review found two shapes this proof missed and a quadratic case; the amendment below fixes them and redoes
  the proof.
- **Known gaps.**
    - A Fiber suspended inside an unwrap keeps the outermost call open, so unrelated calls made meanwhile share its
      node budget. This over-masks and never leaks, and nothing in the app suspends a fiber inside an unwrap.
    - A CDATA section of the excluded kind after a value stays as readable as before.
    - The `emergency` logger is not tapped (as Consequences says). Fixed in the review round (amendment below).

## Amendment (#462) — 2026-10-24, review round: linear inner pairs, every logger masked, gateway calls in scope

- **Inner pairs are linear (security review B1).** A value holding a mask read each secret pair inside it to the end of
  the text, so `token=[redacted],` repeated took 13 s at 128 KB. During one scan, `valueEnd()` now remembers the last
  unquoted run it read and `pairValueEnd()` the last whole-line value: whether an unquoted run stops at an index
  depends on that index only, so a start inside the run ends where the run does. Quoted and bracketed inner values are
  counted, and past 4 times the span plus 64 KB the rest of the text is masked (fail closed). The review's timing
  harness also found `cdataEnd()` quadratic at 4 MB (`token: a<![CDATA["x ` repeated, 54 s): each value searched the
  rest of the text for `]]>` and copied the section. It now remembers the `]]>` it found last during a scan and reads
  the content only up to its first excluded character. Measured locally at 4 MB, the review's shapes and its 12
  harness shapes take at most 1.5 s (`pw=a;token: ` repeated; develop 0.87 s), 4 times their 1 MB time.
  `RedactorShapesBudgetTest` holds the review's shapes and the CDATA one under 0.5 s at 256 KB, under 2 s at 1 MB, and
  linear from 512 KB to 4 MB.
- **Masking no longer decreases against develop (B2).**
    - For a value holding a mask, only the pairs from `maskBoundEnd()` on extend it: where the value ended before #462
      (the `]` of a mask followed by a value end, a mask at the start, or a CDATA section). A pair before that point
      was inside develop's value, which develop never read, and extending through it hid a label tag that develop's
      scan read (`Session:s://@pass=[<t name="pass">]…`). A tail value still extends over every pair in it, because
      develop's scan read each of them after it cut the tail value. Either way the search goes on where develop's
      value for each inner pair ended (its `maskBoundEnd()`), as develop's scan did: a pair inside that was never read.
    - The `<` guard of `trailingPair()` was tried for inner pairs and rejected: it left a quoted value with a CDATA
      section after the mask readable that develop masked (`…]:token:Bearer "<![CDATA[…]]>…"`).
    - The pair that ends a masked span takes a value starting with `//` (`pw=ab;token: //x`), as develop's tail rule
      did. Only a pair the scan finds excludes `//`, which keeps URL schemes from reading as pairs. `token: //x` on its
      own stays readable, as on develop.
- **Proof, redone** against develop `a1973324` with the security review's harness (raw strings, no `json_encode`):
  8 seeds of 48,719 inputs over 13 secret sets, and 2 HTML seeds of 108,719 inputs. No token that develop masks is
  readable at the head on `maskValues`, `redact`, `redactPayment`, `maskThrowable` and the `JsonSerializable`
  surface. The extra surface (develop without a set, the head with the gateway's set) differs in 3,414 rows, all of
  the class above: a set value that is, or sits inside, a label or name (`token` as a set value,
  `api_key=abcFZSECRETpw77]`).
- **Every logger masks.** `log` is bound to `Support\Logging\RedactingLogManager`, which taps every channel it creates
  (a channel an operator adds to `config/logging.php`, `Log::build()`, `Log::stack()`) and the emergency logger that a
  channel failing to build falls back to (an undefined `LOG_CHANNEL`, a broken driver, a handler `RedactLogs`
  refuses). The `tap` entries in the config and their Arch test stay as a second line. `RedactLogs` keeps one
  processor on a handler tapped twice and leaves a `NullHandler` alone. Each logger's `MessageLogged` event carries
  the masked message and context (`RedactingLogEvents`, which masks only when a listener exists). The Arch ban adds
  `MessageLogged`, `Log::listen`, `app('log')->build` or `->listen`, `->build(['driver' …])`, `STDOUT` and
  `php://stdout`; a bare `->build(` would match every builder in `app/`. Monolog is for the backstop only: any
  `Monolog\` reference, `->pushHandler(`, `->setHandlers(`, `->getLogger(` and `new StreamHandler` (or another
  writing handler) fail outside `Support/Logging/`, since a handler pushed after the tap writes unmasked.
- **A Throwable nested in `context` or `extra`** (to depth 8) is masked by `maskThrowable()` with the sets attached
  to it, and logged as `exception` (class), `message` and `at` (file:line). Before, it was logged as `[]`.
- **Gateway calls run in the scope** (step 7, first half, finished). `GatewayManager::during(GatewayConfig, Closure)`
  runs a driver call in `RedactionScope::during()` with `GatewayConfig::secrets()`. Every Billing call to a gateway
  driver method that reaches the provider uses it: capture, refund, refund lookup, attempt lookup, card token details,
  callback parsing, hosted payment start, return parsing, resume, session close and the connection test. The Stripe
  smoke command runs its checks in one `RedactionScope::during()` with its test key. An Arch test
  (`tests/Arch/LogRedactionTest.php`) fails a call to one of those contract methods in Billing or a `Payments*`
  module, outside the drivers, that is not on a line with `during(`. The methods come from the gateway contracts by
  reflection, less the ones that read only the driver (`key`, `limits`, …), so a new contract method is checked
  too. `StartHostedPayment` masks the provider's message before it cuts it to 500
  characters. `GatewayLogger::redact()` requires the gateway key.
- **Host pattern for steps 5 and 6**, as Billing now does it:
    1. Build the call's set from the config's `secrets()` and the request's `secrets()`.
    2. Run the driver call in `RedactionScope::during($set, fn () => …)`, one helper per host
       (`GatewayManager::during()` is the model), never inside the driver.
    3. Redact what the call returns with the same set before it is stored or logged.
    4. Report or rethrow the original exception after the call. Its set stays attached for `report()`, the worker's
       log line and `failed_jobs.exception`.
    5. Mask provider text before cutting it, so a value split at the cut is still matched.
- **Smaller changes.**
    - `RedactionScope::reset()` (`@internal`) runs on the worker's `Looping` event and after the response, where no
      call is in progress, so a set a call failed to drop cannot outlive it.
    - `ActivityLogger` withholds the value of a property named exactly `password`, `secret`, `api_key`,
      `client_secret` or `private_key` (any case), and reads at most 4,000 characters of a text property before it
      masks and cuts it.
    - `RedactionSet::merge()` counts a set given twice once, and each value once.
    - `RedactingFailedJobProvider::wrap()` is countable or prunable only when the wrapped provider is, so
      `queue:prune-failed` still says when pruning is not supported.
- **Known gaps** (replacing the list above). A suspended Fiber shares the outer node budget (fails closed). A CDATA
  section of the excluded kind after a value is as readable as before. `token: //x` read by the scan stays readable,
  as on develop. Consequences' last bullet (the emergency logger) no longer holds.

## Amendment (#574) — 2026-10-31, declared keys read by both hosts

- `ExecuteModuleCommand` and `RegistrarGateway` read `DeclaresSensitiveKeys` for the command or capability they run
  (`Redactor::declaredKeys()`), before the driver is called. A driver whose `sensitiveKeys()` throws fails the command
  then, with nothing sent.
- The keys are masked by key in the command log.
- The values under them are collected from structure only (`Redactor::valuesUnder()`). That means arrays, JSON string
  leaves, and in other strings, also after percent-decoding, two forms. One is a `<key>…</key>` element without
  attributes, with CDATA unwrapped. The other is a `key=…` pair, unquoted or in single or double quotes, which covers
  attributes. Entity-decoded and percent-decoded forms of each value are added. The host also reads the driver's
  message this way.
- Every occurrence of a collected value is masked:
    - in each stored payload string, by plain substring (`redactDeclared()`);
    - in the call's text (`maskDeclared()`): errors, failure items, activity and registrar messages.
- A value of 4 to 7 bytes is matched only as a whole token in text, and always in payloads. A value under 4 bytes is
  covered by key masking only.
- Fail closed: when a payload cannot be read in full, the payloads are stored as `{"withheld": "[redacted]"}` and the
  text as `[redacted]`. Rule: when in doubt, withhold; over-withholding a log line is acceptable, a leak is not. That
  happens:
    - past the node or depth limit;
    - when a string uses a declared key as a name in any other form (`ticket: X`, `<item key="ticket">`, JSON inside a
      form field);
    - when a capture is empty, nested, holds markup, a document or quotes, or sits in a declared element that carries
      attributes.
- Out of scope: base64 of a whole body. Neither this collection nor `redact()` can see a key inside it. Base64 of a
  collected value in text is masked through `RedactionSet`.
- `RegistrarGateway::codeKeys()` stays as the host's built-in key for `epp` and `transfer`, in addition to declared
  keys, not replaced by them. Its values are not masked in text, so an EPP result code under `code` stays readable in
  messages.
- Gateway payloads (`GatewayLogger`) do not read the capability yet (#582).
