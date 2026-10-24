### Security

- **Every log line is masked before it is written (#254).** Every log channel (`single`, `daily`, `stderr`, `syslog`,
  `errorlog`, `slack`, `papertrail`, `monthly`, and any channel added later or built at runtime) masks the message,
  the context and the extra data of every record by secret name and shape, after placeholders are filled in. So does
  the emergency log Laravel writes to when a channel cannot be built (an undefined `LOG_CHANNEL`, a broken custom
  channel), and the log event that log listeners receive. During a payment gateway call, the gateway's own
  credentials are masked by value too, also in an exception reported after the call or stored as a failed job. An
  exception nested inside log context is logged as its class, masked message and file and line. A record that cannot
  be masked is written as `[redacted]` with `extra.redaction_failed`. Upgrade note: list
  `'tap' => [App\Support\Logging\RedactLogs::class]` on a custom channel in `config/logging.php`; a test fails a
  channel without it, and the channel is masked even without it.
- **A failed queued job's exception is masked before it is stored in `failed_jobs` (#254).**
- **Activity log properties and fraud check answers are masked (#254).** Text in activity properties (a provider
  message, for one) is masked by shape and by the values of the call in progress; property names such as `key` stay
  readable, and a property named `password`, `secret`, `api_key`, `client_secret` or `private_key` is stored as
  `[redacted]`. A fraud provider's stored answer (`fraud_checks.raw`) and its reasons are redacted by name and shape.
- **A payment gateway's own credentials are masked by value in its logs and stored payloads (#383).** Settings typed
  Secret on the gateway are masked in `gateway_logs` and in the `raw` of payments, refunds, chargebacks and payment
  attempts, wherever the provider echoes them.

### Fixed

- **The value after a secret label that an earlier value swallowed is masked in every shape (#452 review).** In
  `pw=ab;token: …` the value after `token:` is now masked when it is quoted, base64 with padding, holds `=` or `:`,
  follows `Bearer`, is bracketed or a JSON object, or follows `=>`. Before, only an unquoted value without `=` or `:`
  was masked.
- **A configured secret masked inside a pair's value no longer cuts the value short.** `password=[redacted]PRE4`, and
  `pw=ab…;cd…` when a configured secret was masked inside the value, are masked whole, as they are without the
  secret configured.
- **Masking a log line, activity property or gateway payload stays fast on any text (#462 review).** Text with many
  already-masked values in a row (`token=[redacted],` repeated) took seconds per 100 KB; 4 MB now takes under a
  second. Nothing that was masked before this release is shown now, including `pw=ab;token: //…` and a secret label
  tag after a masked value.
- **The message a payment provider gives when a hosted payment cannot start is masked before it is cut to 500
  characters,** so a credential split at the cut is still masked.
- **`<meta name="api_key" content="…">` masks `content`,** as `value` already was. A CDATA section that a secret
  pair's value runs into (`token: x<![CDATA[…]]>`) is masked with the value; an unterminated one masks to the end.
