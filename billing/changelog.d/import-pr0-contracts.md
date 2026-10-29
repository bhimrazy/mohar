### Added

- **Groundwork for importing unpaid invoices and domains (#527).** The import pipeline can now run an importer after
  another one only when that one is installed, look up many imported records at once, and ask the module that owns an
  entity for matches and mapping choices. No new import is available yet and the client import works as before.
