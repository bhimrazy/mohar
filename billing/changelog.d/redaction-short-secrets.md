### Changed

- **A new integration secret must be at least 8 characters (ADR 0023 §2, #400).** The gateway, registrar and server
  forms refuse a new secret setting (API key, webhook secret, reseller password) shorter than 8 bytes. They also
  refuse a server's own password, API token or access hash under 8 bytes. The message reads "<label> must be at least
  8 characters" under the field, and the browser shows it when a settings field loses focus. A driver whose credential
  has a shorter known format declares it (`SettingField::$minLength`, at least 4), for example a 6-digit PIN. Leaving a
  secret blank still keeps the stored one. A short secret stored before this change still loads and works, and its
  form marks it as too short to keep out of logs. Product-level driver settings are not checked. The log registrar's
  demo connection failure is now triggered by the API key `invalid-key` (it was `invalid`, which is too short).
- **Upgrade: list stored secrets that are too short.** Run `php artisan integrations:short-secrets` after upgrading.
  It is read-only. It lists each gateway, registrar and server secret shorter than its minimum by integration and
  setting name, never by value, and each record it cannot decrypt. It exits 0 when it finds neither, 1 when it finds
  short secrets, and 2 when a record cannot be decrypted (the list may then be incomplete). Replace each listed secret
  on its settings form.
