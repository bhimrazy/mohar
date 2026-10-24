### Fixed

- **A Basic header whose password is a configured secret is masked once (#399).** Log lines read
  `Authorization: [redacted]` instead of `Authorization: [redacted]]`; the same applies to any unquoted pair value the
  secret mask had already shortened (`auth=…&id=5`). Nothing was readable before; only the stray `]` is gone.
- **An object that expands into copies of itself no longer exhausts memory while a log is masked (#399).** Each
  `redact()` and `redactPayment()` call reads at most 50,000 values through objects and masks the rest of them. Plain
  arrays and JSON text are not counted, so large provider bodies are masked as before.
- **A secret after an element whose name starts with `:` is masked (#311).** In text such as
  `<:x t="<html>"><input name="password">…`, the `<html>` inside the attribute was taken as an HTML document marker,
  so the text after the void-named element stayed readable. The HTML marker check and the XML container rule now
  read element names the same way.
