### Fixed

- **A header whose value the secret mask had already shortened is masked once (#399).** `Authorization: Basic …` with
  a configured password reads `Authorization: [redacted]` instead of `Authorization: [redacted]]`, and the same holds
  for any unquoted pair value (`auth=…&id=5`). The value now ends right after that mask, so the next pair is still
  read as a pair.
- **An object that expands into copies of itself no longer exhausts memory while a log is masked (#399).** Each
  `redact()`, `redactPayment()`, `maskValues()` or `maskThrowable()` call reads at most 50,000 values through objects
  and masks the rest of them. Plain arrays and JSON text are not counted, so large provider bodies are masked as
  before. A large `Collection` or DTO list in a log payload may be masked from the point the budget runs out.
- **A secret pair followed by another pair without a space no longer leaves the second value readable (#452).** In
  `pw=ab;token: x`, the value after `token:` stayed readable since #252; it is now masked, also when it is on the next
  line or after `token =`.
- **A secret after an element whose name starts with `:` is masked (#311).** In text such as
  `<:x t="<html>"><input name="password">…`, the `<html>` inside the attribute was taken as an HTML document marker,
  so the text after the void-named element stayed readable. The HTML marker check and the XML container rule now
  read element names the same way.
