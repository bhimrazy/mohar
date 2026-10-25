### New event `TaxNoteResent` (#483)

- `App\Events\Billing\TaxNoteResent(int $taxNoteId, string $kind, int $invoiceId, int $clientId)`: staff asked for a credit or debit note to be emailed again. Notifications listens and sends `credit_note.issued` or `debit_note.issued` as a new message. It is not a webhook event. Additive.
