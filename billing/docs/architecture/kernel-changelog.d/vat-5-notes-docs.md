### Note email flag and `BillingDocuments` (VAT PR 5)

- `App\Events\Billing\CreditNoteIssued` and `DebitNoteIssued` have a new last constructor parameter
  `bool $notify = true`: false when staff chose not to send the note to the client. Additive.
- New contract `App\Contracts\Billing\BillingDocuments::emailAttachment(string $relatedType, int $relatedId): ?DocumentFile`
  and DTO `App\Data\Billing\DocumentFile` (disk, path, filename, mime). Billing binds it; Notifications calls it at
  send time with the message's related record and attaches the file. Only `tax_note` returns a file today; invoice and
  quote PDFs (P6b) can extend the same contract.
