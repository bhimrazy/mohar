### Added

- **Credit and debit notes as documents (VAT PR 5).** Each note has a PDF ("Credit Note" or "Debit Note", seller and
  buyer with PAN/VAT number, the note date and the referenced invoice's date in AD and BS, the reason and the lines),
  downloadable from the invoice's notes panel in admin (`billing.tax_notes.view`) and by the client.
- **Clients see their notes.** The client invoice page lists the invoice's notes, and Invoices → Credit notes (shown
  when the account has a note) lists all of them with their PDFs. A note of another account answers 404.
- **Note emails (#482).** "Credit note issued" and the new "Debit note issued" templates send the note to the client's
  billing contacts, with its PDF attached in the recipient's language while Settings → Billing → "Attach PDFs to invoice
  and note emails" is on (the default; the setting was "Attach PDFs to invoice emails"). Staff can untick "Send to
  client" when issuing. Notes from refunds, late fees and cancels are sent; the note from a fraud flag, the automatic
  cancel of a stale unpaid order or an expired unpaid plan change is not. Each send is in the activity log (`billing.tax_note.emailed`). A PDF over 5 MB
  is left off the mail, which links to it.
- **Webhooks `credit_note.issued` and `debit_note.issued`**, with the note (number, kind, amounts, invoice, reason) as
  the payload object. Merge fields `tax_note.*` for templates.
- **Print count.** While Nepal VAT mode is on, every PDF download of a note or of a numbered invoice is counted; the
  second and later copies say "Copy of original — print n". Each print is in the activity log
  (`billing.tax_note.printed`, `billing.invoice.printed`). Email attachments, HEAD requests and a copy that fails to
  render are not counted.

### Changed

- **Upgrade notes.** Migration `2026_11_01_300780` adds `invoices.print_count` (default 0). An unedited
  "Credit note issued" template is brought up to the new text on upgrade (`app:install`); an edited one keeps its text.
  Its old `credit_note.amount` field is now `tax_note.amount`.
