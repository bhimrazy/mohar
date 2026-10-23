### `CalendarDate` and `Automation` follow-ups (#426)

- `CalendarDate::format()` treats only ASCII letters as format characters, so a multibyte literal (Devanagari) passes
  through. It used `ctype_alpha()`, which follows the locale: where the locale makes bytes from 0x80 alphabetic (macOS,
  some non-UTF-8 locales) a Devanagari literal was refused; on glibc with the default C locale it was not. Time and zone
  characters still throw.
- `Automation::carbonDateTasks(): list<string>` is new and `@internal` (the arch baseline reads it, modules do not): the
  keys of registered tasks whose handler's first parameter is not a `CalendarDate`, read by reflection. Handlers passed
  as variables or callables, `CarbonInterface`, fully qualified Carbon types and package tasks are now caught, which the
  old source-text scan missed. Untyped and non-date first parameters count as legacy too. `CompanyClockScanTest` no
  longer scans source text for Carbon-typed tasks; the baseline is `tests/Feature/Core/CarbonRunDateTasksTest.php`,
  and a baselined task that is not registered (a module subset) is ignored.
