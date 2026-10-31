### Changed

- **The IRD sales book CSV follows the current Schedule 9 of the VAT Rules (NV1).** Billing → VAT register → Export →
  CSV (IRD sales book) now writes 13 Schedule columns instead of 15: the goods, quantity and unit columns are gone
  (25th amendment, 2080), the export headings say "निर्यात" instead of "निकासी" (26th amendment, 2081), and a new last
  Schedule column "कुल जम्मा" holds total sales plus VAT. Our own columns (AD date, document, reference, currency,
  amount, rate) still follow. Operator note: accountants who import this CSV into a spreadsheet or tool by column
  position must remap it; anything keyed on the old Nepali headings must use the new ones. The detail CSV keeps the
  goods, quantity and unit columns and gains a "Total" column before the currency columns.
- **The VAT register and the note register show the period's counts for the monthly return.** The header block (screen,
  CSV and PDF) adds "Tax invoices issued", "Credit notes issued" and "Debit notes issued" (the note register shows the
  note counts only). Every document in the period counts, also one left out of the sums for a missing exchange rate.
  The CSV header rows grow from four to seven (six on the note register), so the column headings start lower.
- **"Copy of original" on reprints is now a requirement in the spec, tested on every path.** No behaviour change: in
  Nepal VAT mode the first download of a tax invoice, credit note or debit note is the original and each later one, by
  staff or client, says "Copy of original — print n". Emailed and resent PDFs still carry the unmarked original and do
  not count as prints, pending the accountant's answer on whether an email is a print.
