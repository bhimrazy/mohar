<?php

use App\Events\Billing\CreditNoteIssued;
use App\Events\Billing\DebitNoteIssued;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

test('note events wait for the commit and carry the note', function (string $event) {
    $issued = new $event(7, 'CN-2082-0001', 41, 12, 113_000, 'NPR', true);

    expect($issued)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and([$issued->taxNoteId, $issued->number, $issued->invoiceId, $issued->clientId, $issued->total, $issued->currency, $issued->automatic])
        ->toBe([7, 'CN-2082-0001', 41, 12, 113_000, 'NPR', true]);
})->with([CreditNoteIssued::class, DebitNoteIssued::class]);
