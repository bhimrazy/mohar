# Kernel API changelog

Changes to the public kernel surface (ADR 0010: `app/Contracts`, `app/Data`, `app/Events`, `app/Enums`, registry
methods in `app/Support`, `App\Support\Money`). Until `API_VERSION` reaches 1.0.0, a breaking change is allowed when
the same PR updates every in-repo implementer and gets the principal-architect gate; it is listed here.

## Unreleased (pre-1.0)

### Redaction rules, step 3a (ADR 0023 §2, §5; architecture review 2026-10-23, R3a)

- New `App\Support\Integrations\RedactionSet` (`@api`): `of(list<string> $values, list<string> $answers = [])`,
  `empty()`, `isEmpty()`, `mask(string, string $mask = Redactor::MASK)`, `matches(string)` and the static
  `normalise(string)`. It builds the §2 match forms once per set: credentials and request secrets as substrings on
  bytes (case-insensitive typed, URL-, JSON- and HTML-escaped, decoded entity and percent words; base64 and base64url
  at three alignments, cores of 8 characters or more; hex from 8 bytes; lines of a multi-line value from 16 bytes;
  truncated echoes from 16 bytes), and answers as regrouped `/iu` patterns (0 to 3 separators, `%XX`, Devanagari
  digits; anywhere from 8 letters and digits, otherwise not inside a longer run of the same kind). Nothing under 4
  bytes, or 4 letters and digits, is matched.
- `Redactor::redact()`, `maskValues()` and `excerpt()` take `RedactionSet|list<string>` (the list builds a set; it
  goes once the hosts pass sets, ADR 0010 pre-1.0). The private `variants()` is gone. Behaviour changes: the 3-byte
  minimum is now 4; integer and float leaves and keys (decoded JSON strings included) that hold a set value are
  withheld (`[redacted]`, `[redacted] 2`, ...); each object unwrap counts towards the depth cap of 32.
- New `Redactor::maskThrowable(Throwable, RedactionSet|list<string>|null): Throwable`: masks the message of the
  exception and of up to 16 behind it in place, keeping class, code, trace, `previous` and properties. Nothing calls
  it yet (steps 5 and 6).
- New capability `App\Contracts\Integrations\DeclaresSensitiveKeys` (`sensitiveKeys(string $operation): list<string>`,
  detected with `instanceof`). Additive; no driver implements it yet (step 5 replaces `RegistrarGateway::codeKeys()`).
- `App\Data\Billing\GatewayConfig`: new trailing `$secretSettingKeys` (filled by `GatewayManager::config()` from the
  driver's Secret and SecretText fields), new `secrets(): list<string>`, `$settings` marked `#[\SensitiveParameter]`.
  `secretValues()` (every setting of 8 characters or more, no caller) is removed. Billing masks with it in step 7.
- `SecretTemplatePolicy::normalise()` (Notifications) now delegates to `RedactionSet::normalise()`; same result.

### Linking an existing registrar order (ADR 0009 Amendment 4, #365)

- New capability interface `App\Contracts\Domains\Registrar\LinksExistingOrders` (detected with `instanceof`;
  registry capability name `link_order`, `RegistrarDriverRegistry::LINK_ORDER`): `lookupOrder(RegistrarConfig,
  DomainRef, string $orderId): LinkedOrder`. Read-only; throws a non-retryable `RegistrarException` with a plain
  message when the order does not qualify. New DTO `App\Data\Domains\Registrar\LinkedOrder` (`remote`, `status`
  `active|pending`, `expiryDate`, `toArray()`). Additive: drivers without the interface keep working and are not
  offered the staff action. The test fakes opt in (`LinkingRegistrar`); the log driver implements it.

### Report registry and export pipeline (ADR 0025, VAT PR 0c)

- New provider contract `App\Contracts\Reports\Report` (`key`, `label`, `category`, `filters`, `columns`, `rows`,
  `count`) with optional capabilities `ProvidesTotals`, `ProvidesHeaderBlock`, `ProvidesLayouts`, `ProvidesPdfView`,
  `ProvidesCsvOptions` (`csvBom()`) and the marker `HasSensitiveExport`, detected with `instanceof`. Rows are keyed by
  column key; a money cell is a `Money`.
- New DTOs `App\Data\Reports\ReportColumn`, `ReportFilter` (typed by `FilterType`; `loggable` says whether its
  value may be written to the activity log) and `ReportQuery` (validated filters, acting user id, and the period
  resolved with `CompanyClock::dayBounds()`), and enums `App\Enums\ColumnType` (closed: text, money, integer,
  decimal, date, instant), `ExportFormat` (open: csv, pdf) and `FilterType` (open: date, text, select, boolean).
- New `App\Support\Reports\ReportRegistry` (ADR 0012 shape: `register(string $key, class-string|Closure $report,
  bool $replace = false)`, `has`, `keys`, `get`; singleton in `AppServiceProvider`). Registration builds nothing; a
  duplicate key throws unless `replace`; it registers `reports.{key}.view` and `reports.{key}.export` with
  `PermissionRegistry` (the export is sensitive when the class implements `HasSensitiveExport`).
- `PermissionRegistry::register()` accepts a Closure as a label, called when `all()` is read (translated labels).
- New `App\Support\Reports\ReportExporter` (`query()` validates typed filters and refuses unknown keys, `export()`
  returns a response): permission check, step-up for sensitive exports, row limits (CSV `reports.sync_export_max_rows`,
  PDF `reports.sync_pdf_max_rows`) enforced on the stream as well as on `count()`, one `report.exported` entry (loggable
  filters only). Unknown report, layout or filter is a `ValidationException`. Writers implement `ExportWriter`; CSV
  and PDF ship. PDF totals are per currency and amounts use `MoneyFormatter`. New kernel view
  `resources/views/reports/pdf.blade.php`.
- New `App\Support\Csv\SafeCsv` and `Verbatim`: the formula guard moved here from Migration's `ErrorsCsv` (which now
  uses it) and also covers text after leading white space and the full-width `＝＋－＠`. The guard covers text cells
  only. An arch rule keeps `fputcsv` inside `App\Support\Csv`.
- Kernel `App\Support` services may use Core services (`docs/architecture.md`, dependency rules).
- New config `reports.csv_bom` (false), `reports.sync_export_max_rows` (5000) and `reports.sync_pdf_max_rows`
  (1000), exposed as Settings, Reports. Additive; no schema change.

### Credit and debit note events (ADR 0024 §6, VAT PR 0b)

- New `App\Events\Billing\CreditNoteIssued` and `DebitNoteIssued` (`taxNoteId`, `number`, `invoiceId`, `clientId`,
  `total` in minor units, `currency`, `automatic`), both `ShouldDispatchAfterCommit`. Additive. Nothing emits them
  yet: Billing fires them when notes are issued (VAT PR 1b onward). The webhook types `credit_note.issued` and
  `debit_note.issued` join `WebhookEventTypes::MAP` in VAT PR 5, together with the `tax_note` merge-field resolver, so
  the first published payload is the final snapshot (publishing earlier would send the bare event fields and change
  the payload later).

### Company clock contract (ADR 0013 Amendment 1, PR 0a)

- New `App\Contracts\Core\CompanyClock`: `timezone()`, `now()`, `today()`, `todayDate()` (the company date as 00:00 UTC, the run-date shape), `dayBounds(date)` (the `[start, end)`
  UTC instants of one company day), `localDate(instant)` and `isFallback()`. Core implements it (`CoreCompanyClock`, bound in
  `CoreServiceProvider`) on the setting `system.timezone`; an invalid stored zone falls back to UTC. Additive; no
  setting rename or migration.
- Removed, with every in-repo caller moved to the contract: Core's `BusinessCalendar` (now `CoreCompanyClock`, which
  keeps `sqlZoneArgument()` and `sqlLocalDate()` for the two dashboards that still bucket in SQL), Orders'
  `Services\CompanyClock`, and Notifications' `MailClock` (its formatting stays, as `Support\MailDates`). None was
  public kernel surface; `BusinessCalendar::startOfDayUtc()` is `dayBounds()[0]`.
- `automation:run` defaults its date to `CompanyClock::todayDate()`, and its schedule and the exchange-rate schedule use
  `->timezone($clock->timezone())`: `core.automation_time` and `platform.rates_update_time` are company time. The
  run date is passed to tasks as a date value (00:00 UTC of the company date); `today()` itself is 00:00 in the company
  zone. A run date can be skipped once on deploy (tasks select by `<=` windows and catch up).
- `isFallback()` is on the contract: true when the stored zone is not a valid time zone and dates
  use UTC (reported once; shown as a dashboard warning). Backward-compatible names such as `Asia/Katmandu` are accepted.
- Billing's `DocumentNumbers::next()` now requires the document date (module code, not kernel API).

### TLD attributes for registrar drivers (ADR 0009 Amendment 2)

- New capability `App\Contracts\Domains\Registrar\DeclaresTldAttributes` (detected with `instanceof`; registry
  capability name `tld_attributes`, `RegistrarDriverRegistry::TLD_ATTRIBUTES`): `tldAttributeFields(string $tld):
  list<SettingField>`, `$tld` with its leading dot, no network call. Fields are never secret and never
  `optionsSource: remote` (the host filters them out). Orders renders the fields (from `TldAttributes::fieldsByTld()`);
  Domains declares, validates (required, options, type) and stores the answers per domain. Optional refinement
  interface `ValidatesTldAttributes` (rules between fields, not a separate capability name).
- `App\Data\Domains\Registrar\RegistrationRequest` gains `array<string, string> $tldAttributes = []` (last
  parameter, `#[\SensitiveParameter]`): the answers keyed by the field keys the driver declared. `toArray()` lists the
  keys only, so the command log never holds the values. Existing drivers and callers are unchanged. The host fills it
  for a registration, not for a transfer.
- ADR 0009 Amendment 3 (so Orders can reach the feature without importing Domains): `App\Data\Domains\DomainDraft`
  gains `array<string, string> $tldAttributes = []` (last parameter, `#[\SensitiveParameter]`; `$eppCode` is now
  `#[\SensitiveParameter]` too), and a new Domains-implemented contract `App\Contracts\Domains\TldAttributes`
  (`fieldsByTld()`, `clean($tld, $input)` throwing `ValidationException` keyed `tld_attributes.<key>` or `tld_attributes`
  when the registrar account is disabled, `describe()` for display). `TldAttributeInput` in Orders resolves nothing
  from Domains until used, so without an implementation no extension has fields.
- The host masks the free-text answers (not selects or booleans) in the registrar errors of a registration, so a
  registry message that repeats a value never reaches `last_error`, the logs, activity or staff mail.
- Behaviour note: the test fakes' default capability set now includes `tld_attributes`; a registrar fake without it is
  `FullWithoutAttributesRegistrar`.

### Refund lookup for unconfirmed gateway refunds (#334)

- New provider contract `App\Contracts\Billing\LooksUpRefunds` (extends `GatewayDriver`, detected with
  `instanceof`; ADR 0010, ADR 0012): `lookupRefund(GatewayConfig, RefundRequest): ChargeResult`, called with the
  `RefundRequest` the refund was sent with (`reference` always set). It answers as `refund()` would have: SUCCESS
  (`txnId` = the provider's refund id), DECLINED (no such refund, or it failed; Billing releases the amount),
  PENDING (only where `refund()` would answer it), ERROR (unknown; nothing changes). The driver compares the amount and
  currency of a refund it finds and answers ERROR on a mismatch. It never creates a second refund.
- `App\Data\Billing\RefundRequest` gains `?CarbonImmutable $sentAt = null` (last parameter): when the refund was
  first sent, so a lookup can tell whether the provider's idempotency window has passed. Billing sets it from the
  refund row on every request it builds.
- `ChargeResult`'s docblock now covers a refund lookup (`fee` is 0). A driver without the interface is unchanged;
  Billing offers staff a hand resolution for it (provider reference required, step-up, audit).
- Meaning change for `refund()` results: only DECLINED releases the reserved amount. ERROR and PENDING keep the refund
  pending. A driver must answer DECLINED for anything refused or never sent, and ERROR for any answer that leaves it
  unknown (a timeout, a 5xx). `StripeDriver` implements `LooksUpRefunds`; Stripe and Khalti now answer ERROR for 5xx.

### Kept redirect URL for Khalti's Continue payment (#251)

- `App\Data\Billing\RedirectInstruction` gains `bool $keepForResume = false` (last parameter). A driver sets it when
  the provider serves the redirect URL again while its session is open but has no API that returns it later. Billing
  keeps the URL on the attempt, encrypted at rest, only for a driver that implements `ResumesHostedPayment`, and drops
  it once the attempt leaves Pending. Stripe leaves it false: its `resumeUrl()` reads the URL from the provider.
- `App\Data\Billing\PaymentAttemptRef` gains `?string $resumeUrl = null` (last parameter, `#[\SensitiveParameter]`):
  that kept URL, populated only on the ref passed to `ResumesHostedPayment::resumeUrl()`. Refs for `lookup()`,
  `cancelSession()` and every other driver call carry null (Billing's `PaymentAttempt::toRef(withResumeUrl: true)` is
  used by `ResumeHostedPayment` alone). The driver checks the URL and confirms with the provider before returning it.
- `ResumesHostedPayment`'s docblock no longer says the URL is never stored. It says Billing sends it to the browser
  only as the redirect target, and never logs it, puts it in a page prop or returns it in JSON. After a refused
  resume, Billing settles through `lookup()`, which a driver may answer from the call it just made. Additive: existing
  drivers and callers are unchanged. Used by `KhaltiDriver`.

### Redactor void containers (#278)

- `App\Support\Integrations\Redactor` (`@api`): public method signatures unchanged; only private methods were
  added. A secret-named void element (`<param name="password">`, `<input name="password">`) with no matching end tag
  now keeps the next-tag bound only when an HTML document marker (`<!doctype html` or an unprefixed `<html` start
  tag, outside comments, CDATA, processing instructions and quoted attribute values) comes earlier in the text.
  Otherwise a child element after it, a stray end tag then a child, or a `<!`/`<?` construct fails closed and masks to
  the end of the text. The marker search is a forward walk with constant extra memory, and the scan stays linear.
- Narrowing callers can see: a bare HTML fragment with no marker, such as `<input name="password"><p>Forgot…`, now masks
  everything after the input. Gateway, registrar and panel error pages that start with a doctype or `<html>` keep
  their text. A raw `<html>` inside a non-secret JSON string counts as a marker.

### Registrar refs store, ADR 0009 PR C3

- Domains binds `App\Contracts\Domains\Registrar\RegistrarRefs` to `EloquentRegistrarRefs` (table `registrar_refs`,
  unique per registrar, ref type, local record and payload hash; `secret` encrypted). A driver that injects
  `RegistrarRefs` resolves only while Domains is loaded. `remember()` refuses a value longer than its column
  (`InvalidArgumentException`; `payloadHash` is sha256 hex).
- Deleted: the module registrar SPI that PR C2 stopped using (`App\Modules\Domains\Drivers\Registrars\Registrar`,
  its `Data` DTOs and the module `RegistrarException`, `RegistrarTimeout` and `CapabilityNotSupported`).

### Domains on the kernel registrar contracts, ADR 0009 PR C2

Breaking, under the pre-1.0 window (ADR 0010): every in-repo implementer is updated in the same PR, and no package
used the module SPI.

- Removed: `RegistrarManager::extend()/has()/keys()/names()/fields()/driver()/capabilitiesOf()`. Unused from here on
  (an arch rule forbids them) and deleted in PR C3 (#265): `App\Modules\Domains\Drivers\Registrars\Registrar` (with
  its `CAPABILITIES`, `configure()`, `capabilities()` and array `settingsSchema()`), the `Registrars\Data` DTOs and
  `App\Modules\Domains\Exceptions\{RegistrarException,RegistrarTimeout,CapabilityNotSupported}`. Use the kernel types
  from PR C1 and `RegistrarDriverRegistry`.
- `RegistrarManager` moves to `App\Modules\Domains\Services` and keeps only account handling: `config(key):
  RegistrarConfig` and `isActive(key)`.
- `RegistrarGateway::call()` and `forDomain()` take the capability interface after the capability name, and the
  closure receives `(driver, RegistrarConfig)`. A trailing `$secrets` (the request's `secrets()`) joins the account's
  credentials in the mask, and every result, lookup, transfer-sync reason and connection check the gateway returns is
  masked (`withMessage()`, `withReason()`, `mapText()`). Exceptions keep their class, retry flag and `previous`
  chain with every message along it masked. `testConnection(key)` runs the connection test through the same path,
  also on a disabled account. Domains renders `RegistrarException` through a renderer it registers.
- `info` is core: the host no longer checks for it before using it to verify a lost submission.
- `tests/Support/Contracts/RegistrarDriverContract::verifyHost()` adds the Domains host redaction check; the
  temporary `ModuleRegistrarDriverContract` is removed.

### Registrar driver contracts, ADR 0009 PR C1

Additive; Domains moves onto these in PR C2, which removes its module SPI.

- `App\Contracts\Domains\Registrar\RegistrarDriver` (provider contract, extends `IntegrationDriver`):
  `settingsFields(): list<SettingField>`, `register(RegistrarConfig, RegistrationRequest)`, `renew(RegistrarConfig,
  DomainRef, int $years)`, `info(RegistrarConfig, DomainRef)`. Capability interfaces in the same namespace:
  `ChecksAvailability`, `SuggestsNames`, `TransfersDomains` (`transfer` and `transferSync`), `RestoresDomains`
  (`restore(config, domain, years)`, replacing `renew(restore: true)`), `ManagesNameservers`, `ManagesLock`,
  `ManagesContacts` (get and set), `ProvidesEppCode`, `ManagesIdProtection`, `SyncsDomains`, `ReconcilesSubmissions`
  (C2, `findSubmission(config, domain, command, submittedAt): SubmissionLookup`) and `TestsRegistrarConnection`
  (`testConnection(RegistrarConfig): ConnectionCheck`).
- `App\Contracts\Domains\Registrar\RegistrarRefs` (consumer contract, C4): `find(registrarKey, refType, localType,
  localId, payloadHash = '')` and `remember(RegistrarRef)`. Implemented by Domains in PR C3 (#265).
- `App\Data\Domains\Registrar`: `RegistrarConfig(key, settings, testMode, secretSettingKeys)` with `setting()` and
  `secrets()` (the name matches `ServerConnection::$secretSettingKeys`); `RegistrationRequest::secrets()` (the transfer
  code), which the host adds to its redaction set for the call; `ContactSet::ROLES` and `ContactSet::FIELDS`, the
  kernel contact shape (Domains' `DomainContact` constants now point at them; formats in the `ContactSet` docblock); `DomainRef` (the ADR 0009 §2 fields plus a trailing `periodYears`); `RegistrarRef`; and the result DTOs
  from the Domains module, unchanged apart from `RegistrationRequest::$domain` being a `DomainRef`, `eppCode` and
  `EppCodeResult::$code` marked `#[\SensitiveParameter]`, and `RegistrarResult::withMessage()` /
  `SubmissionLookup::withMessage()` (a copy with a new message, for host-side masking).
- `TransferSyncResult::withReason()` and `App\Data\Integrations\ConnectionCheck::mapText(Closure)` (additive): copies with
  replaced text, so the host masks what a driver says before showing it. `RegistrarTimeout` takes a trailing
  `?Throwable $previous`.
- `App\Support\Domains\RegistrarDriverRegistry` (final, singleton in `AppServiceProvider`): the `DriverRegistry`
  API plus `supports(key, capability)` and `fields(key)`, and one constant per capability name
  (`RegistrarDriverRegistry::AVAILABILITY` … `TEST_CONNECTION`). Capability names are the strings the admin already
  shows; `register`, `renew` and `info` map to the core interface, `transfer` and `transfer_sync` to
  `TransfersDomains`, and `test_connection` is new. `RegistrarException` (without HTTP rendering), `RegistrarTimeout` and
  `CapabilityNotSupported` in the same namespace.

### Client import invitations (importer B2a)

- New contract `App\Contracts\Auth\ClientLoginInvitations` (client-import spec C3; beside `AuthMailer`):
  `problem(): ?string` (why no invitation can be sent now, ADR 0012 addendum) and `invite(int $clientId, int $userId,
  InvitationPurpose $purpose = InvitationPurpose::LoginAdded, ?int $actorId = null): InvitationOutcome`. Core binds
  `NullClientLoginInvitations` ("not installed", always `Unavailable`); Platform binds `PlatformClientLoginInvitations`,
  which maps each purpose to its template and invites only a never-used login.
- New enum `App\Enums\InvitationPurpose` (`LoginAdded`, `Import`), `@open`.
- New enum `App\Enums\InvitationOutcome` (`Sent`, `Skipped`, `Refused`, `Held`, `Unavailable`), `@open`. `Held`:
  mail is off on purpose, no link was issued, the login can be invited later.
- `App\Enums\SystemMailOutcome::Suppressed` (new case): the template system logged the mail but did not send it on
  purpose (mail switched off, the template turned off, demo data). It was `Taken` before. Nothing else may send it, as
  with `Taken`; callers that compare with `Refused` or `Unavailable` are unchanged. `ManageClientLogins` puts back an
  invitation whose mail was suppressed.

### Key rotation (#25, ADR 0022)

- New registry `App\Support\Security\EncryptedStorage` (`final`, `@api`, singleton, ADR 0012 shape):
  `register(string $key, EncryptedModel|EncryptedColumn|EncryptedFiles $target, bool $replace = false)`, `has()`,
  `keys()` and `all()`. Keys match `[a-z0-9_]{1,50}` (`<module>` or `<module>_<part>`); a duplicate key throws unless
  `$replace`, and two keys naming the same storage throw `LogicException`. Modules declare there all data encrypted
  with `APP_KEY` that outlives a rotation, so `security:reencrypt` (Core) covers it without reading other modules'
  classes.
- New value objects (`@api`): `EncryptedModel(class-string<Model>)` (a model whose encrypted casts are read when the
  command runs), `EncryptedColumn` (table, column, key column, prefix, JSON string) and `EncryptedFiles` (path columns
  of a table, a disk, and `lockFirst` naming the parent row to lock before the row). Additive. Every module with an
  encrypted cast registers its models; Core also registers `core_settings`, the two Fortify columns and
  `core_install_secrets`; Kyc registers `kyc_documents_files`. With a module absent, its registrations are absent
  and a real run asks for `--partial`.
- Outside the kernel: `App\Providers\ModulesServiceProvider::availableModules()` (every module on disk) and
  `App\Modules\Core\Support\InstallSecrets` (per-install secrets that survive a rotation; Migration's email hash
  uses it).

### Step-up in the kernel (#81, ADR 0018 §4)

- `App\Http\Middleware\RequireRecentPasswordConfirmation` (moved from `App\Modules\Platform\Http\Middleware`): the
  `password.recent` middleware, aliased in `bootstrap/app.php`. Same contract: `ERROR_KEY`, `recentlyConfirmed()`,
  `timeout()`; 423 for JSON, a `password_confirmation` validation error for Inertia. The window is read from
  `auth.step_up_seconds` (900), which replaces `platform.password_confirmation_seconds`. Billing's
  `ConfirmPasswordForAction` copy and Migration's `RequirePassword` fallback are deleted; modules register no step-up
  alias of their own. `tests/Extensions/ModuleRoutesResolveTest.php` boots each module alone and fails when a route's
  middleware does not resolve.

### Client import opening balances and tax-number notes (importer B2b)

- New capability `App\Contracts\Migration\RevertsImport extends RecordImporter` with
  `revert(ImportRecord $record, ImportOutcome $applied, ImportContext $context): ImportOutcome` (ADR 0019 addendum).
  Billing's `credit_balance` importer implements it. `App\Enums\ImportAction::Reverted` (new case of the open enum).
- New contract `App\Contracts\Billing\TaxIdRules`: `normalize(string $country, string $taxId): string` and
  `notes(string $country, ?string $taxId, ?string $state, bool $business): list<TaxIdNote>`, with the new DTO
  `App\Data\Billing\TaxIdNote` (`severity` WARNING or INFO, `code`, `message`).
  Core binds `NullTaxIdRules`; Billing binds `BillingTaxIdRules` (Nepal PAN, Indian GSTIN, EU reverse charge).

### Payment follow-ups (#232, #221)

- New event `App\Events\Billing\PaymentReinstated(invoiceId, clientId, transactionId, chargebackTransactionId,
  amount, currency, reviewAmount = 0)` (compatible addition, #221): a won dispute undid a chargeback. The counterpart
  of `PaymentReversed`, one to one: Billing's `RecordDisputeWon` dispatches, after commit, one per chargeback row that
  fired `PaymentReversed`, with the same invoice and amount (one per invoice of a combined charge; none for a dispute
  that took back only credit), whether or not the invoice is settled again. `transactionId` is the win's
  `chargeback_reversal` row, `chargebackTransactionId` the chargeback row, `reviewAmount` the part held for staff
  review. Implements `DomainEvent` and
  `ShouldDispatchAfterCommit`. Notifications publishes it as the `payment.reinstated` webhook, and `PaymentReversed`
  as `payment.reversed`; with Notifications absent nothing listens.
- `App\Data\Billing\CallbackEvent` gains a trailing `bool $fundsReinstated = false` (compatible, #232): on
  `DISPUTE_WON`, the provider's record shows the disputed money withdrawn and reinstated, so a win with no booked
  chargeback is acknowledged (200, a zero-amount `chargeback_reversal` row as the won marker, no money moved) instead of
  refused with 409. Set by the Stripe driver from the
  dispute's balance transactions; other drivers keep the 409-and-retry behaviour.

### Resume or cancel a hosted payment (#123)

- New opt-in capability `App\Contracts\Billing\ResumesHostedPayment::resumeUrl(GatewayConfig, PaymentAttemptRef):
  ?string`: the provider page where the customer can finish a still-open attempt, read from the provider at request
  time and accepted only as https on the provider's own payment host. Billing never stores it or sends it as a prop.
- New opt-in capability `App\Contracts\Billing\CancelsHostedPayment::cancelSession(GatewayConfig, PaymentAttemptRef):
  HostedSessionCancel`: closes the provider session before Billing frees the invoice for another way to pay. Called
  outside any database transaction; the answer is logged only, and settlement still comes from `lookup()`.
- New closed enum `App\Enums\HostedSessionCancel` (`closed`, `already_final`, `unreachable`).
- Additive: `HostedPaymentDriver` is unchanged and drivers without the interfaces keep working. Implemented by
  `StripeDriver` and the sandbox card driver.

### Redactor gaps (#190, CTO-sanctioned kernel change)

- `App\Support\Integrations\Redactor` (`@api`): public method signatures unchanged. The string rules behind
  `redact()`, `maskValues()` and `excerpt()` now run as one linear left-to-right scan instead of the bounded
  `maskPairs()` recursion (the `NESTED_PAIR_*` limits are gone): secret `name=value`, `name: value`, `"name":"value"`
  and `'name' => 'value'` pairs (quoted values with escapes, JSON arrays and objects, unquoted values through `;` and
  `,` that continue the value), XML elements named like a secret or labelled by a secret `key`/`name` attribute (CDATA
  included), secret XML attributes, URL userinfo, percent-encoded pairs, `curl -u` credentials (quoted or attached), whole
  `Cookie:` and `Set-Cookie:` lines, and Stripe and Khalti key shapes. End tags are found through one index of `</`
  offsets read by a forward-only cursor, so the scan stays linear with many unclosed elements (#252 B1). A secret
  element with no end tag masks the rest of the text (fail closed); an element named like an HTML void element
  (`<input name="password">`, XML `<param>`) with no matching end tag later in the text masks the text up to the next
  tag or its CDATA section, so an HTML input masks nothing after it (narrowed by #278, see "Redactor void containers" above); with an end tag later it is masked to that end
  tag like any element, child elements and comments included. An unquoted name whose value would start on the next
  line takes that line unless it is header-shaped (a name, `:`, then a blank or the line end), so an empty `Cookie:`
  keeps the next header while `password:\nvalue` and `token:\nQWx…==` are masked. The word
  rules gain `pw` (EPP `newPW`) and the run-together `session` (`cpsession`, `whostmgrsession`).
- Masks widen, with two narrowings: `auth-userid` (ResellerClub's reseller id) is no longer secret, and after an
  unquoted `Session:` or `hash:` label in free text a value that is a known reference id (Stripe `cs_`, `pi_`, `ch_`,
  `evt_`, `cus_`, `in_`, `sub_`, `re_`, `py_`, `seti_` ids) stays readable when the id is the whole value, optionally ending a sentence with `.`. Any other value after those
  labels, `session=`, `hash=` and quoted keys stay secret.
- A string that is a whole JSON document and holds nothing secret is returned unchanged instead of re-encoded.
- `redactPayment()` also applies the string rules to text leaves (before Luhn scrubbing). Key rules unchanged.
- Behaviour change for callers comparing exact output: a quoted secret keeps its quotes (`"password":"[redacted]"`,
  `password="[redacted]"`); an unquoted one is replaced whole as before.

### Client currency lock (#218)

- `App\Support\Clients\ClientCurrencyUsage::sourcesFor(int $clientId): list<string>` (new method, additive): the keys
  of every source that holds amounts for one client, in registration order. Unlike `clientsInUse()` it asks every
  source, so Core's client edit can name what blocks a currency change. `register()` and `clientsInUse()` are unchanged.

### Client identity verification (#231)

- New event `App\Events\Kyc\DocumentDecided(clientId, documentId)` (compatible addition): staff approved or
  declined an identity document. Dispatched after commit by the Kyc module; details come from the `kyc_document`
  merge-field resolver (outcome, type, reason, link; never the number or images). Notifications maps it to the
  editable `kyc.document_decided` client email. With Notifications absent nothing listens.

### Billing go-live fixes (#229)

- `App\Data\Billing\CallbackEvent::DISPUTE_INQUIRY` (new constant): a dispute the provider has not withdrawn funds
  for (Stripe inquiry); Billing books a zero-amount `dispute_inquiry` transaction, no money moves. Additive; drivers
  that never send it are unaffected.

### ADR 0021 and the 0012/0018 addenda (#219)

- `App\Support\Clients\ClientCurrencyUsage::source(string $key, Closure)` is renamed
  `register(string $key, Closure $source, bool $replace = false)`, the aggregating-registry shape of the ADR 0012
  addendum. Keys must match `[a-z0-9_]{1,50}`; a duplicate key throws `LogicException` unless `$replace` is set, and a
  replacement is logged. Breaking, under the pre-1.0 window: the four in-repo callers (Billing, Services, Orders,
  Domains) are updated in the same PR, and no external package used the method.

### Client import, PR B1a (client-import spec §15, ADR 0019 accepted)

- Added `App\Events\Migration\MigrationRunCompleted(int $runId, string $sourceSystem, array $counts)`, `$counts` keyed
  by entity, each with `created`, `updated`, `unchanged`, `skipped` and `failed`; dispatched after commit, once per run,
  on the applying → completed transition. Added `MigrationRunPaused(int $runId, ImportStopReason $reasonCode)`,
  dispatched after commit when an import stops. Both implement `ShouldDispatchAfterCommit`.
- Added `App\Enums\ImportStopReason` (`@open`, ADR 0010/0011): `ApplyFailed`. `$sourceSystem` stays a string because it
  is a registry key.
- Added the registry `App\Support\Clients\ClientCurrencyUsage` (singleton): `source(string $key, Closure(list<int>):
  array<int|string>)` and `clientsInUse(list<int>): list<int>`, the clients some module holds amounts for, so an import never
  relabels their currency (spec §6.4). Billing (invoices, quotes, transactions, credit), Services, Orders and Domains
  each register a source; with none registered no client is in use.
- `App\Data\Migration\ImportOutcome` gains a trailing `?array $before = null`: the changed fields' previous values,
  read by the importer under its own lock (ADR 0019). Compatible (ADR 0010: trailing defaulted field).
- `App\Models\User`: saving a client login (`is_staff` false) with a new email clears `email_verified_at` unless the
  same save sets it. Staff logins are not affected: Platform's `UpdateStaff` keeps its rule (an admin-initiated change
  clears verification and sends the mail, a self-change keeps it). Query-builder `update()` bypasses the hook.
- Core registers the `client` and `user` importers in the kernel `ImportRegistry`; both honour dry runs, including
  client groups (looked up, never created) and links to clients the dry run did not create. Core's single dev worker
  (`composer dev`, #164) also reads `Queues::IMPORTS`, so the Migration module no longer registers its own.

### Registrar prerequisites (#164)

- `Redactor`: a secret `name=value` pair inside another pair's value (`Rejected: api-key=…`) is masked; it was kept
  whole. The search inside a value is bounded (2 levels, values up to 4 KB), so long provider text stays linear in
  time and memory. Signatures unchanged. Compatible: more is masked, nothing that was masked is shown.
- No other kernel change: `ReconcileStatus` and `Queues::REGISTRAR` are used as they are. The single dev queue worker
  (`composer dev`) moved to Core and is built from the `Queues` constants.

### Client import, PR A0 (client-import spec §16 C1, C2; ADR 0019 proposed)

- Added the provider contract `App\Contracts\Migration\RecordImporter` (with `key()`; a dry run's `upsert()` writes
  nothing; `rules()` must not query the database), `ImportIdResolver::targetId(string $entity, string $sourceId)`,
  the DTOs `App\Data\Migration\{ImportRecord, ImportContext, ImportOutcome, ImportIssue}` and the enums
  `App\Enums\{ImportAction (@open), ImportSeverity}`, as `migration.md` §12 with two trailing defaulted fields,
  `ImportOutcome::$changes` and `ImportContext::$actorId`. One vocabulary: `$entity` is the registry key and
  `$sourceSystem` the kind of source (`csv`, `whmcs_api`).
- Added the final registry `App\Support\Migration\ImportRegistry` (ADR 0012), bound in `AppServiceProvider`:
  `register(key, class-string|Closure, replace: false, dependsOn: [])` with keys matching `[a-z0-9_]{1,50}`,
  duplicates refused unless replaced (logged), `has()`, `get()` (checks the importer's `key()`), `keys()`, and
  `ordered(array $keys = [])`, which checks only the requested chains.
- Added `App\Support\Queues::IMPORTS` (`imports`), `IMPORT_JOB_TIMEOUT_SECONDS` (120), `DEFAULT_POOL_TIMEOUT_SECONDS`
  (150) and `DEFAULT_POOL_MEMORY_MB` (256). The default pool works `default,imports`; no new connection (ADR 0014
  amendment).
- Arch rule: every `App\Modules\*\Import` namespace that exists uses no `event()` or `dispatch()` helper, event
  dispatcher, `Event`, `Bus`, `Mail` or `Notification` facade, `SystemMailer`, `AuthMailer`, `App\Events` namespace
  other than `Migration`, or driver contract or registry; a probe fixture proves the rule fails.
- The run events (C4) are not in this PR; each lands with the code that dispatches it (ADR 0019).

### Storefront (public site, PR feature/storefront-home)

- Added `App\Contracts\Orders\ShopperSession` (`currency()`, `cartCount()`, `switchableCurrencies()`), implemented by
  Orders; Core binds `NullShopperSession` (default currency, empty cart).
- Added `App\Contracts\Support\PublicHelpDirectory` (`recentAnnouncements()`, `popularArticles()`,
  `networkStatus()`), implemented by Support; Core binds `NullPublicHelpDirectory`. Spec K9, without the sitemap part.
- Added `DomainPricing::catalog(string $currency, ?int $clientId = null)`: active TLDs with 1-year register, renew and
  transfer prices, spotlight flag and badge (spec K4, without `category` and the `TldPricingChanged` event). Service
  contract: `FakeDomains` is updated.
- Documented the marketing keys `PriceBook::storefront()` already returns (group `headline`, `tagline`; product
  `tagline`, `is_featured`) as optional keys of the array shape (spec K2, without `icon` and `comparison()`).
- Added `App\Support\Seo\SitemapRegistry` (ADR 0012 shape: `register(key, Closure, replace: false)` with keys
  `[a-z0-9_]{1,50}`, a duplicate key throws unless replaced, `has()`, `keys()`, `entries()`; a source that throws is
  reported and left out), bound as a singleton. Orders, Support and Storefront register their public URLs.
- Added `App\Events\Storefront\StorefrontPublished(revisionId, publishedBy, changedSections)`. Declared here with the
  other storefront kernel pieces; the publish flow that dispatches it is the stacked PR #99.
- `PriceBook::storefront()` products also document the optional `is_taxable` key (Catalog sends it).
- Interim gaps, each until its spec contract exists: K6 (`TaxDisplay`): Storefront derives the tax statement and
  inclusive amounts from a probe line through `TaxCalculator`, and reads `orders.domains_taxable` and
  `catalog.show_monthly_breakdown` through Core `Settings`. K7 (`GatewayDirectory::acceptanceMarks()`): Storefront
  reads logos from `GatewayRegistry` drivers that implement `ProvidesLogo`. K5 (`ProductGroupUpdated`): a group that is
  hidden or renamed can show in the header for up to `storefront.cache_seconds` (600 s).
- `App\Data\Branding\Brand` gains trailing `hasUploadedLogo` and `hasUploadedMark` (default false), also in
  `toArray()`. Customer surfaces show a logo or mark only when the host uploaded it, never the product's default.
- Interim, until the company profile contract (spec K1): the public footer and `Organization` JSON-LD read
  `core.company_*` and `billing.seller.*` through Core `Settings`.

### Integration simulators, S1 (ADR 0016 §1, §3)

- `Endpoints`: a key that does not match the key pattern is never repeated in logs, `app:install` or the dashboard;
  such keys are counted ("(2 malformed keys)"), since one may be a pasted URL with credentials.
- `Endpoints::configuredKeys()`: the keys set in `INTEGRATION_ENDPOINTS` whatever the environment (for the install
  check and the dashboard health check; drivers may not call it, Arch rule). Compatible.
- No other kernel change: the framework is the dev-only package `mohar/simulators` (`tools/simulators`, namespace
  `Mohar\Simulators\`), which `App\` never references.

### Integration simulators, S0 (ADR 0016 §3, ADR 0015 amendment 1)

- Added `App\Support\Integrations\Endpoints` (singleton, bound in `AppServiceProvider`) and the DTO
  `App\Data\Integrations\Endpoint` (`baseUrl`, `options`, `url(path)`; deliberately no "overridden" flag, so a
  driver has nothing to branch on). Drivers of fixed-host providers
  make one call, `Endpoints::for(key, default)`, which returns the URL together with its request options, so an
  overridden URL never comes without its pinning. Overrides come only from the environment (`INTEGRATION_ENDPOINTS`, a
  JSON object, and `INTEGRATION_CA_BUNDLE`) and are honoured only when the app runs as `local` or `testing`; every
  other environment, including an empty or unknown one, fails closed and logs them once as critical, keys only. Only
  https base URLs without credentials, query or fragment are used; malformed ones are logged once. An override is
  shaped like the default (trailing `/` kept or dropped to match), and `url(path)` joins with one `/`, so the request
  path is the same with and without it. Without one the base URL is the default and the options are `[]`. With one,
  TLS verification stays on (scenario CA) and the destination is resolved and pinned through `OutboundAddressGuard`.
  No driver uses it yet (S3, S5).
- `OutboundAddressGuard` reaches loopback only in `local` and `testing`, whatever `integrations.outbound.allow_loopback`
  says elsewhere, and gains `pinOptions(ResolvedAddress)`, the options `pin()` applies (compatible).
- Arch rules: nothing in `app/`, `bootstrap/`, `config/` or `routes/` names `Mohar\Simulators` (any case, group
  imports included), and no driver code mentions "simulat" or asks `Endpoints` whether an override is active
  (`activeKeys`, `developmentEnvironment`, `configuredKeys`). Driver code is every file under a module's `Drivers/`
  plus the module classes those files import (such as `PaymentsNepal\Support\ProviderHttp`); the three demo drivers
  (`Services/.../LogDriver`, `Domains/.../LogRegistrar`, `Billing/.../SandboxCardDriver`) are exempt by full path.

### Integrations area (ADR 0009 §1, PR A)

- Added `App\Enums\FieldType` and `App\Data\Integrations\SettingField`, the one settings-field schema. A secret is a
  field type. `SettingField::fromArray()` reads the gateway and the older provisioning array shapes. `optionsSource = 'remote'`
  lets a select take its options from the driver (cPanel spec K2). The constructor refuses a secret with a default and
  remote options on a non-select.
- Added `App\Contracts\Integrations\IntegrationDriver` (`key()`, `name()`), the identity every driver family shares.
- Added `App\Support\Integrations\DriverRegistry`, the base of the kernel-owned driver registries (ADR 0012 shape).
- Added `App\Support\Integrations\Redactor`, which holds every integration redaction rule:
    - `redact()` for the module and registrar logs merges Services' redactor with the Domains registrar key lists. Keys
      are matched by word, adjacent pair or run-together spelling (`api_key`, `ApiKey`, `eppcode`, `accesshash` alike),
      and every key develop masked is still masked. Two narrowings are deliberate (architect R2): `author` is no longer
      caught by `auth`, and `code` is secret only where the payload says so (`RegistrarGateway` passes it for `epp` and
      `transfer`). Objects are read as arrays, nesting is capped at 32 levels, and inside strings it masks known
      credential values, cPanel `cpsess…` tokens, JSON documents and secret `name=value` pairs (query strings, form
      bodies, `Authorization:` text). Registrar error messages go through it too.
    - `redactPayment()` for the gateway log and stored `raw` keeps develop's gateway rule exactly (every leaf under a
      payment-sensitive key masked, null included; card brand and last 4 stay visible; Luhn-valid card numbers masked as
      `[redacted-pan]`), plus `authorization`, `dv` and `dataValidation` from payments-nepal.md §4.
    - `excerpt()` makes raw driver output safe (redacted, 4 KB). The module-log mask is now `[redacted]` (it was `***`).
- Added `App\Support\Integrations\OutboundAddressGuard` with `App\Data\Integrations\ResolvedAddress` and
  `OutboundAddressRefused` (ADR 0015, proposed). `resolve(host, port)` applies the install-wide policy in
  `config/integrations.php`, refuses non-public addresses (IPv6 allow-listed, embedded IPv4 judged) and non-canonical
  numeric hosts, and `pin()` sends the request to the checked IP and port (`CURLOPT_CONNECT_TO`), bypasses proxies
  (`proxy: ''`) and follows no redirects.
- **Breaking:** moved `App\Data\Billing\ConnectionCheck` to `App\Data\Integrations\ConnectionCheck`.
- **Breaking:** moved `App\Contracts\Billing\ProvidesLogo` to `App\Contracts\Integrations\ProvidesLogo`, and renamed
  `App\Data\Billing\GatewayLogo` to `App\Data\Integrations\IntegrationLogo`. Khalti and eSewa are updated.

### Provisioning contracts (ADR 0009 §2–§5, PR B)

- Added `App\Contracts\Services\Provisioning\ProvisioningDriver` (extends `IntegrationDriver`) and the capability
  interfaces `ChangesPackage`, `ChangesPassword`, `ReportsUsage`, `TestsServerConnection`, `ProvidesServiceSso`
  (with an optional target), `ProvidesServerSso`, `ListsAccounts`, `RenewsAccounts`, `ProvidesFieldOptions` and
  `ReconcilesCommands`.
- Added the snapshot DTOs in `App\Data\Services\Provisioning`: `ProvisioningContext`, `ServiceSnapshot`,
  `ServerConnection` (with `apiToken`, `settings` and `secretSettingKeys`), `ClientSummary`, `DriverMetadata`,
  `DriverResult` (with `outcomeUnknown`, `unknown()`, `outcome()`), `PreviousPackage`, `UsageRow`, `SsoLink`,
  `RemoteAccount`, `CommandLookup` and `UsernamePolicy`. Also `App\Data\Services\RemoteOptions`.
- Added the enums `App\Enums\DriverOutcome` and `App\Enums\ReconcileStatus`.
- Added `App\Support\Services\ProvisioningDriverRegistry` (final, bound in `AppServiceProvider`) and
  `App\Support\Services\RetryableProvisioningException`.
- `App\Contracts\Services\ProvisioningDrivers` gains `remoteOptions()`. Its fields may carry `options_source`.
- `App\Contracts\Billing\GatewayDriver` now extends `IntegrationDriver` (same methods; compatible).
- `ProvidesServiceSso` has `ssoTargets(): array<target, label>` next to `serviceSsoLink(ctx, ?target)` (cPanel K7).
- Added `App\Events\Services\ServiceImported` (`serviceId`, `clientId`, `serverId`, `driver`, `source`; cPanel K8).
- `App\Data\Integrations\ConnectionCheck` gains trailing `steps` (`list<ConnectionCheckStep>`, with
  `App\Enums\CheckStatus`) and `details` (compatible).
- `App\Data\Services\ProvisionResult` gains a trailing `pending` flag (compatible): the create runs on the queue, so
  callers show "queued", never "failed".
- `ProvisioningDriver` docblock: any exception a driver throws during a call, other than
  `RetryableProvisioningException`, is an unknown outcome.
- `DriverRegistry::capabilities()` (PR A surface) is now public, so tests can check that every capability is
  covered.
- `ProvisioningContext::$configOptions` is empty in PR B; PR B2 fills it by module key from
  `PriceBook::moduleInputs()`. Install-specific option ids are never sent to drivers.
- **Breaking (module SPI removed):** `App\Modules\Services\Drivers\Provisioning\{ProvisioningDriver, ServiceContext,
  DriverMetadata, DriverResult, DriverRegistry, UsageRow}` and
  `App\Modules\Services\Exceptions\RetryableProvisioningException`. `LogDriver` and `NoneDriver` are updated, and an
  arch rule keeps the old names out.
- `DriverMetadata` gains `?int $commandTimeoutSeconds` and `?UsernamePolicy $usernamePolicy` (cPanel K9 and K6;
  compatible, trailing). The host caps the timeout at `services.module.max_job_timeout_seconds`.

### Module inputs, server settings and one field shape (ADR 0009 amendment 1, PR B2)

- `ServerConnection::$settings` is a `#[\SensitiveParameter]` (compatible).
- Added `App\Support\Integrations\KeepsSecretsOutOfOldInput`: form requests that carry integration secrets flash
  old input without them on a validation error, driven by `SettingField` secret flags (used by the server and gateway
  forms).
- Added `PriceBook::moduleInputs(productId, optionSelections, customFieldValues): App\Data\Catalog\ModuleInputs`.
  This is a service contract, so adding a method is compatible; the fakes are updated. It returns configurable options
  as module key => module value (or label, quantity, `1`/`0`), custom fields by module key, and the secret values. It
  includes hidden options, admin-only fields and retired products. `ProvisioningContext::$configOptions` and
  `$customFields` are now keyed by module key only; the old fallback to a slug of the field name is gone.
- Added `PriceBook::productsForDriver(driverKey): list<ProductSummary>` (cPanel K10): every non-retired product of a
  driver, hidden ones included, for import matching and package warnings.
- `ServerConnection::$settings` and `$secretSettingKeys` are filled from the new encrypted `servers.settings` column,
  which holds the driver's `serverFields`.
- `ProvisioningDrivers::all()` gains a `fields` key in the `SettingField::toArray()` shape next to `config_fields`,
  which keeps its shape and is **deprecated** (removed before kernel API 1.0.0, issue #68; read `fields`).
  Implementers MUST return both keys until then. Catalog reads `fields`. The gateway
  `fields` prop and the server form's `driverFields` use the same shape, rendered by one React component
  (`components/app/setting-field.tsx`).

### cPanel driver (#69)

- `DriverMetadata` gains trailing `refusedPorts: list<int>` (compatible): ports a server of the driver may not use.
  The host's server form refuses them, and the cPanel driver checks the same list before every call.
- `ProvisioningContext` gains trailing `?CarbonImmutable $deadline` (compatible): when the host stops waiting for the
  call. The host sets it once per job, from the command's timeout less 5 s, and keeps it across renamed attempts; a
  driver without one applies its own budget.
- `ProvisioningContext` gains trailing `?CarbonImmutable $unknownAttemptAt` (compatible): when an earlier attempt of the
  command, sent with the same username and domain, ended with an unknown outcome. The host records each create's
  target on its module command row before calling the driver. A driver may adopt only what that attempt created.
- `DriverResult::renameUsername(username, message)` (compatible): a retryable create that carries `data.username`,
  the free name to use when the assigned one is taken on the server. The host stores it and runs the create again at
  once (at most 5 times), so the name is recorded before it is ever sent.
- `Redactor` also masks `session=` credentials in URL query strings (cPanel login links), wherever they appear,
  percent-encoded ones included.
- `CommandLookup` gains trailing `array $data` (compatible, #73): for Executed, what the command would have reported
  (`DriverResult::$data` keys), so a command settled by a server check stores the same as a completed one.
- `DriverMetadata` gains trailing `?array $connectionFields` (compatible, #73): the host's shared server fields the
  driver reads (`username`, `password`, `api_token`, `access_hash`, `secure`, `port`), each with the driver settings it
  depends on. The server form shows only those; null keeps all of them.
- Test support: `ProvisioningDriverContract::verify()` gains a trailing `serverSettings` override (compatible; not ADR
  0010 public surface) for server settings whose placeholder would be invalid, such as a certificate pin.
