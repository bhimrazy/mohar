### Added

- **Translation mechanism for the web interface (ADR 0028).** Pages can call `t()`, `tChoice()` and `<Trans>`, which read
  the same `lang/<locale>.json` catalogue as the server's `__()`; a missing key shows the English text. The catalogue
  reaches the browser once per locale and version. Modules can ship `Resources/lang/*.json`. No page is converted yet and
  no translations ship (English only).
