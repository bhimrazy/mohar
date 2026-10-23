### `CalendarDate` and `Automation` follow-ups (#426)

- `CalendarDate::format()` treats only ASCII letters as format characters, so a multibyte literal (Devanagari) passes
  through; before, `ctype_alpha()` refused it. Time and zone characters still throw. Additive.
- `Automation::carbonDateTasks(): list<string>` is new: the keys of registered tasks whose handler takes a run date that
  is not a `CalendarDate`, read by reflection (covers handlers passed as variables or callables,
  `CarbonInterface`, and package tasks). The arch test `CompanyClockScanTest` no longer scans source text for these; the
  baseline is `tests/Feature/Core/CarbonRunDateTasksTest.php`. Additive.
