### New event `TaxNoteResendRequested` (#483)

- `App\Events\Billing\TaxNoteResendRequested(int $taxNoteId, int $invoiceId, int $clientId)`: staff asked for a credit or debit note to be emailed again. Notifications listens, reads the note's kind from the `tax_note` merge fields and sends `credit_note.issued` or `debit_note.issued` as a new message. It is not a webhook event. Additive.
- Dedupe is per occurrence: two quick clicks send two emails. The Billing route's limit of 10 resends per minute per staff member is the guard.
