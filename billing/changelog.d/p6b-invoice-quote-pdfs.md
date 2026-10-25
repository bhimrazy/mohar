### Added

- **Invoice and quote emails carry the PDF (#29).** New, reminder, overdue, updated and receipt emails attach the invoice as
  it stands when sent (the tax invoice once paid; a resend attaches the current version), and the quote email attaches the
  quote. The text says "attached" only when the file is attached. The existing setting, Billing → "Attach PDFs to invoice,
  quote and note emails" (default on), now covers all of them; a file over the Notifications size cap is left off.

### Fixed

- **An invoice PDF attached or rendered for another language was drawn in the current language (#29).** It is now
  rendered in the message's language.
- **A long quote line keeps its quantity, price and discount note on the invoice (#23).** The item's own text is cut
  instead of the note.
