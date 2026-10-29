### Fixed

- **A credit or debit note email no longer says the PDF is attached when it will not be (#506).** The email text now
  renders the note's PDF when it is not ready and checks the size cap, as invoice and quote emails already did; a failed
  render is reported and the text claims nothing.
- **Seeding the demo data no longer renders about 80 PDFs.** The Billing seeder turns "Attach PDFs to emails" off while it
  runs and restores the setting, which stopped the seed test from running out of memory.
