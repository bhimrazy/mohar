<?php

use App\Events\Billing\CreditNoteIssued;
use App\Events\Billing\DebitNoteIssued;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

test('note events wait for the commit and carry the note', function (CreditNoteIssued|DebitNoteIssued $issued) {
    expect($issued)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and([$issued->taxNoteId, $issued->number, $issued->invoiceId, $issued->clientId, $issued->amount, $issued->currency, $issued->automatic])
        ->toBe([7, 'CN-2082-0001', 41, 12, 113_000, 'NPR', true]);
})->with([
    'credit note' => fn (): CreditNoteIssued => new CreditNoteIssued(7, 'CN-2082-0001', 41, 12, 113_000, 'NPR', true),
    'debit note' => fn (): DebitNoteIssued => new DebitNoteIssued(7, 'CN-2082-0001', 41, 12, 113_000, 'NPR', true),
]);
