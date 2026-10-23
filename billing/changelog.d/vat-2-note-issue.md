### Added

- **Staff issue, settle and reverse credit and debit notes on a tax invoice (VAT PR 2, ADR 0024).** The invoice page gets
  a "Credit and debit notes" panel: each note with its number, AD and BS date, reason, amount, the account credit it
  posted or took back and its reversal, plus what can still be credited. Staff with the new permission issue a credit
  note by amount (spread over the lines) or by line, or a debit note, from a server preview confirmed against that
  preview ("The invoice changed; review the note again."). A credit note first lowers the open balance; the part above
  it goes to the client's account credit (a `credit_note_to_credit` adjustment and a `credit_note` ledger row, both with
  the note's id), so a paid invoice stays paid with a zero balance. A credit note is reversed by a debit note that
  repeats its lines and tax and takes back the account credit it posted, even below zero; a reversing note cannot be
  reversed, a credit note is reversed once, and a credit note that cancelled its invoice is not reversed. Debit notes
  together add at most the invoice's value (a reversal does not count). Each note writes one activity entry,
  `billing.tax_note.issued` (ids, number, kind, amount, currency, settlement, reason code; no free text), in the issuing
  transaction. Upgrade notes: new permission `billing.notes.issue` (sensitive, step-up) for issuing, settling and
  reversing; the seeded Billing role gets it, other roles need it granted. Viewing notes needs `billing.invoices.view`.
  The actions show only while `billing.nepal_vat.enabled` is on, which has no setting on the page until VAT PR 3, so
  nothing changes for users yet. Migration `300720` adds `credit_ledger.tax_note_id` and
  `tax_notes.reverses_tax_note_id` (nullable, expand only); its rollback, and that of `300710`, is refused while a note
  exists. Refund settlement of a note comes with VAT PR 4.

### Fixed

- **Note numbering and checks from the VAT PR 1b review (#396).** Credit and debit note formats that could ever give
  the same number (`A{NUMBER}` and `A1{NUMBER}`, or one format equal to the other's default) are refused by the settings
  form and fall back to `CN-`/`DN-` when stored another way; the allocator refuses a line whose gross is not net plus
  tax; the architecture scan for note writes no longer flags plain reads.
