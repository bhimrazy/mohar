### Security

- **Every log line is masked before it is written (#254).** Each log channel (`single`, `daily`, `stderr`, `syslog`,
  `errorlog`, `slack`, `papertrail`, `monthly`) now masks the message, the context and the extra data of every record
  by secret name and shape, after placeholders are filled in. While a driver call runs inside the new redaction
  scope, its credentials and request secrets are masked by value too, also in an exception reported after the call.
  A record that cannot be masked is written as `[redacted]` with `extra.redaction_failed`. Upgrade note: a custom log
  channel added to `config/logging.php` must list `'tap' => [App\Support\Logging\RedactLogs::class]`; a test fails a
  channel without it.
- **A failed queued job's exception is masked before it is stored in `failed_jobs` (#254).**
- **Activity log properties and fraud check answers are masked (#254).** Text in activity properties (a provider
  message, for one) is masked by shape and by the values of the call in progress; property names such as `key` stay
  readable. A fraud provider's stored answer (`fraud_checks.raw`) and its reasons are redacted by name and shape.
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
- **`<meta name="api_key" content="…">` masks `content`,** as `value` already was. A CDATA section that a secret
  pair's value runs into (`token: x<![CDATA[…]]>`) is masked with the value; an unterminated one masks to the end.
