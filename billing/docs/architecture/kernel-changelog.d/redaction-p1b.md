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
  `RedactingFailedJobProvider` (wraps the `queue.failer` binding). `tests/Arch/LogRedactionTest.php` fails a channel
  without the tap and any `Log::build()`, `error_log()`, `syslog()`, `openlog()`, `STDERR` or `php://stderr` in `app/`.
- Rule change: the tail-pair rule of #452 now reads the value after a swallowed secret label with the plain pair rules
  (and covers any secret pair inside that value); text joined to a mask, a labelled `content` attribute and a CDATA
  section a value runs into are masked. Masking only increases.
