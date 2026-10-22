<?php

use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Review board probes for PR #259 (importer B2b, opening credit balances). Failing tests are findings; passing ones
 * pin what the review verified.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

const PR259_PROBE_HEADER = ['First Name', 'Last Name', 'Email Address', 'Country', 'Credit'];

/**
 * @return array{credit: int|null, codes: list<string>}
 */
function pr259ProbeCredit(string $cell): array
{
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com', 'NP', $cell]], PR259_PROBE_HEADER)));
    $record = $run->records()->sole();

    return ['credit' => $record->normalized['extra']['credit_balance'] ?? null, 'codes' => array_column($record->problems ?? [], 'code')];
}

// B1: a comma followed by two digits is a decimal comma in many exports ("250,50" = Rs 250.50), and loose grouping
// ("1,2,3") is not a number anyone writes. Stripping every comma reads them as 25050.00 and 123.00: a silent 100x credit.
test('B1: an amount with a decimal comma or irregular grouping is a format error, not a larger amount', function (string $cell) {
    expect(pr259ProbeCredit($cell))->toBe(['credit' => null, 'codes' => ['credit_format']]);
})->with(['250,50', '1,2,3', '12,34', '1,00,00', '1,,000']);

// Verified good: what the parser already reads and refuses correctly.
test('verified: grouped and plain amounts are read exactly', function (string $cell, int $minor) {
    expect(pr259ProbeCredit($cell))->toBe(['credit' => $minor, 'codes' => []]);
})->with([
    ['1,25,000.50', 12500050], ['1,250.50', 125050], ['125000', 12500000], ['0', 0], ['0.5', 50],
    ['999,999,999,999.99', 99999999999999],
]);

test('verified: currency symbols, European format, non-ASCII digits, signs and overlong amounts are refused', function (string $cell, string $code) {
    expect(pr259ProbeCredit($cell)['codes'])->toBe([$code]);
})->with([
    ['Rs 500', 'credit_format'], ['1.250,50', 'credit_format'], ['१२५०', 'credit_format'], ['12.345', 'credit_format'],
    ["1\u{00A0}250", 'credit_format'], ['1234567890123', 'credit_format'], ['+5', 'credit_format'],
    ['-5', 'credit_negative'], ['-0', 'credit_negative'],
]);

// B2: spec §6.12 posts an opening balance on create, and on a re-run only while the client has had no other ledger
// activity. A client that already exists here, with its own credit history, and is matched by email for the first time
// gets the file's whole amount added on top, with no decision and no note.
test('B2: a first import into an existing client that already has credit history does not add the file amount silently', function () {
    $client = Client::factory()->create(['email' => 'asha@example.com', 'first_name' => 'Asha', 'last_name' => 'Rai', 'country' => 'NP', 'currency' => 'NPR']);
    app(CreditLedgerService::class)->post($client->id, Money::of(50000, 'NPR'), CreditReason::Manual, description: 'Entered by hand before the import');

    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com', 'NP', '500.00']], PR259_PROBE_HEADER)), ['on_match' => 'update']);
    $record = $run->records()->sole();
    $posted = $record->decision === 'update';

    if ($posted) {
        $record = ClientCsv::apply($run)->records()->sole();
    }

    expect(CreditLedgerEntry::query()->where('client_id', $client->id)->where('reason', CreditReason::Import)->count())->toBe(0)
        ->and(app(CreditLedgerService::class)->balance($client->id, 'NPR')->amount)->toBe(50000)
        ->and(array_intersect(['credit_kept', 'credit_existing'], array_column($record->problems ?? [], 'code')) !== [] || $record->decision === 'needs_decision')->toBeTrue();
});

// N1: spec §17 and AC 25: the same file run again comes out unchanged. Keyed by email (no External ID), every row with
// a credit cell is now reviewed as an update listing "Opening credit balance", although nothing will change.
test('N1: the same email-keyed file again is reviewed as unchanged, not as a credit update', function () {
    ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com', 'NP', '1,000.00']], PR259_PROBE_HEADER)), ['on_match' => 'update']));
    $again = ClientCsv::check(ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com', 'NP', '1,000.00']], PR259_PROBE_HEADER)), ['on_match' => 'update']);
    $record = $again->records()->sole();

    expect($record->decision)->toBe('unchanged')->and($record->changes)->toBe([]);
});
