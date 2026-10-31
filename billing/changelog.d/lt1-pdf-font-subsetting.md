### Fixed

- **An invoice PDF is about 23 KB instead of 858 KB (#566).** PDFs (invoices, quotes, notes and reports) now embed only
  the font glyphs they use. An email carrying an invoice is that much smaller and took about a third of the queue
  worker's CPU to send in a local test, and object storage grows more slowly. PDFs stored before the change keep
  their size.
