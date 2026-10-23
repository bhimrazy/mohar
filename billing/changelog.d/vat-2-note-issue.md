### Added

- **Staff issue, settle and reverse credit and debit notes on a tax invoice (VAT PR 2, ADR 0024).**
  - **Notes panel.** The invoice page gets a "Credit and debit notes" panel. It lists each note with its number, AD and
    BS date, reason and amount, the account credit the note posted or took back, and its reversal. It also shows what
    can still be credited. The page header labels the invoice "Credited" or "Partly credited", and the balance card
    names each note row by its number.
  - **Issuing.** Staff issue a credit note by amount (spread over the lines) or by line, or a debit note. They preview
    the note first and confirm against that preview ("The invoice changed; review the note again."). An action that
    can't be used is disabled, with the reason and what to do instead; for example, an invoice with a discount line is
    corrected by cancelling it and raising a new one.
  - **Settlement to account credit.** A credit note first lowers the open balance. Any part above the balance goes to
    the client's account credit, as a `credit_note_to_credit` adjustment and a `credit_note` ledger row, both carrying
    the note's id. A paid invoice therefore stays paid, with a zero balance.
  - **Reversal.** A credit note is reversed by a debit note that repeats its lines and tax and takes back the account
    credit it posted, even below zero. The preview shows the client's credit after the reversal. A reversing note
    can't itself be reversed, a credit note can be reversed only once, and a cancelled or refunded invoice takes no
    debit note or reversal.
  - **Paid invoices owing again.** A debit note or reversal that leaves a paid invoice owing makes it unpaid. It gets a
    new due date from today on the normal invoice terms (`invoice_days_before_due`), and its overdue notice, reminders
    and auto-capture retries start again, so the next automation run charges no late fee and doesn't charge the card.
    A late fee already charged on the invoice is not charged again. The preview and the activity entry show the new
    due date.
  - **Debit cap.** Debit notes and late fees after numbering together add at most the invoice's value; reversals
    don't count toward this.
  - **Activity log.** Each note writes one activity entry, `billing.tax_note.issued`, in the issuing transaction. It
    holds the ids, number, kind, amount, currency, settlement, reason code, any reversed note and any new due date,
    but no free text.
  - **Number padding.** A first install in Nepal pads note numbers like invoice numbers (`CN-00001`, `DN-00001`).
  - **Upgrade notes:**
    - New permissions: `billing.tax_notes.view` to see notes, and `billing.tax_notes.issue` (sensitive, step-up) to
      issue, settle and reverse them. The seeded Billing role gets both; other roles need them granted.
    - The actions show only while `billing.nepal_vat.enabled` is on. That setting has no definition on the settings
      page until VAT PR 3, so nothing changes for users yet.
    - Migration `300720` adds `credit_ledger.tax_note_id` and `tax_notes.reverses_tax_note_id` (nullable, expand
      only). Its rollback, and that of `300710`, is refused while a note exists.
    - Re-opening a paid invoice clears `paid_at` until it is paid again. Refund settlement of a note comes with VAT
      PR 4.

### Fixed

- **Note numbering and checks from the VAT PR 1b review (#396).**
  - Credit and debit note formats that could ever produce the same number are refused by the settings form, which
    also compares with the stored format when a save leaves it out. Examples are `A{NUMBER}` and `A1{NUMBER}`, or one
    format equal to the other's default. A colliding pair stored another way falls back to `CN-`/`DN-`.
  - The allocator refuses a line whose gross is not net plus tax.
  - The architecture scan for note writes no longer flags plain reads.
  - Amounts in note refusals use the currency's display, for example "Rs 7,345.00".
