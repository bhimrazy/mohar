### Note email flag, `BillingDocuments`, `Invoicer::cancel` notify flag, `EmailSent` related record (#482)

- `App\Events\Billing\CreditNoteIssued` and `DebitNoteIssued` have a new last constructor parameter
  `bool $notify = true`: false when the note is not emailed to the client. Additive (ADR 0024 Amendment (#482)).
- `App\Contracts\Billing\Invoicer::cancel()` has a new last parameter `bool $notify = true`. Implementers add
  the parameter; callers need no change. Orders passes false for fraud and stale-order cancels, Services for an expired unpaid plan change.
- New contract `App\Contracts\Billing\BillingDocuments::document(string $type, int $id, ?string $locale = null): ?DocumentFile`
  (constant `TAX_NOTE`) and DTO `App\Data\Billing\DocumentFile` (disk, path, filename, mime). Billing binds it;
  Notifications attaches the file to templates flagged `attaches_document`. P6b adds `invoice` and `quote`.
- `App\Events\Notifications\EmailSent` has two new last parameters, `?string $relatedType = null` and
  `?int $relatedId = null`, the record the message is about. Additive.
- `App\Support\Events\IdentifiesOccurrence::__unserialize` fills promoted constructor parameters missing from an old
  payload with their defaults (ADR 0010 Amendment (#482)).
