### Log backstop and gateway credentials in the Redactor (#462)

- New `App\Support\Integrations\RedactionScope` (`@api`): `during(RedactionSet $set, Closure $call): mixed` runs a
  driver call with its set active and attaches the set to a `Throwable` that leaves it (a `WeakMap`, merged when calls
  nest); `current(?Throwable $exception = null): RedactionSet` returns the sets of the calls in progress and of the
  exception's chain (16 deep).
- New `RedactionSet::merge(RedactionSet ...$sets): RedactionSet`. Additive.
- `Redactor::redactPayment()` takes a new trailing `RedactionSet|array $secrets = []`: values masked in text and
  numbers. Additive.
- `Redactor::maskThrowable()` also masks with `RedactionScope::current($exception)`. Narrowing callers can see: inside
  a scope, or on an exception that left one, more of the message is masked.
- New `@internal` `App\Support\Logging\RedactingProcessor`, `RedactLogs` (the tap every channel lists) and
  `RedactingFailedJobProvider` (wraps the `queue.failer` binding through `wrap()`, countable or prunable only when the
  wrapped provider is). `tests/Arch/LogRedactionTest.php` fails a channel without the tap and any `Log::build()`,
  `Log::listen()`, `app('log')->build()` or `->listen()`, `->build(['driver' …])`, `MessageLogged`, `error_log()`,
  `syslog()`, `openlog()`, `STDERR`, `STDOUT`, `php://stderr` or `php://stdout` in `app/`.
- The `log` binding is `@internal` `App\Support\Logging\RedactingLogManager` (extends `Illuminate\Log\LogManager`):
  it taps every channel it creates, `Log::stack()` and the emergency logger with `RedactLogs`, and gives each logger
  `@internal` `RedactingLogEvents`, which masks `MessageLogged` before listeners see it. `RedactLogs` keeps one
  processor on a handler tapped twice and skips `NullHandler`.
- New `@internal` `RedactionScope::reset(): void`, called on the queue worker's `Looping` event and after the
  response.
- `RedactionSet::merge()` counts a set given twice once and each value once. No signature change.
- `RedactingProcessor` masks a `Throwable` nested in `context` or `extra` (depth 8) and logs it as `exception`,
  `message` and `at`.
- Rule change: the tail-pair rule of #452 now reads the value after a swallowed secret label with the plain pair rules
  (and covers any secret pair inside that value); a mask inside a value counts as value characters, and a labelled
  `content` attribute and a CDATA section a value runs into are masked. Masking only increases (differential fuzz
  against develop, ADR 0023 amendment #462, review round). A pair inside a value holding a mask extends it only from
  where the value ended before #462, and a tail value may start with `//`. Inner pairs and CDATA ends are read in
  linear time.
