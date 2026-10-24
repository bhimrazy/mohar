### Redactor node budget and tail pairs (#452)

- `App\Support\Integrations\Redactor` (`@api`): public method signatures unchanged. Each outermost `redact()`,
  `redactPayment()`, `maskValues()` or `maskThrowable()` call (per message) reads at most 50,000 values through objects
  (each unwrapped object and every entry under one) and masks the rest of them. The budget is shared by every
  `Redactor` instance while the outermost call runs, so a `jsonSerialize()` that calls `app(Redactor::class)` uses the
  same budget. Plain arrays and decoded JSON text are not counted.
- Narrowing callers can see: a large object payload is masked from the point where the budget runs out. A `Collection`
  of 5,000 rows of 12 fields passed to `redact()` is masked from index 3,846 (the 3,847th row) on. Convert it with `toArray()` first; a
  plain array is not counted.
- A secret value that runs on through a soft end into the next pair (`pw=ab;token: x`) now masks that pair's value
  too, when it is unquoted. Pair names that develop showed stay readable. `HtmlDocumentMarker` is new and `@internal`.
