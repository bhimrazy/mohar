<?php

use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\BillingSequence;
use App\Modules\Billing\Services\CurrencyConverter;
use App\Modules\Billing\Services\DocumentNumbers;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
| Per-sequence numbering for fiscal documents (ADR 0024 §1, vat-register §4.5) and the reporting rate as a decimal string
| (§11). The numbering race tests start real processes against the test database, so they need PostgreSQL.
*/

function nextNumber(string $sequence, string $date = '2026-10-20'): string
{
    return app(DocumentNumbers::class)->next($sequence, CarbonImmutable::parse($date));
}

test('credit and debit notes have their own gap-free sequences and default formats', function () {
    expect([nextNumber(DocumentNumbers::CREDIT_NOTE), nextNumber(DocumentNumbers::CREDIT_NOTE), nextNumber(DocumentNumbers::DEBIT_NOTE), nextNumber(DocumentNumbers::INVOICE)])
        ->toBe(['CN-1', 'CN-2', 'DN-1', '1']);
});

test('a note sequence reads its own format, padding and reset, not the invoice settings', function () {
    billingSettings(['number_format' => 'INV-{NUMBER}', 'number_padding' => 6, 'number_reset' => 'monthly', 'credit_note_format' => 'CN/{YEAR}/{NUMBER}', 'credit_note_padding' => 3, 'credit_note_reset' => 'yearly']);

    expect([nextNumber(DocumentNumbers::CREDIT_NOTE, '2026-10-20'), nextNumber(DocumentNumbers::CREDIT_NOTE, '2026-11-02'), nextNumber(DocumentNumbers::CREDIT_NOTE, '2027-01-05')])
        ->toBe(['CN/2026/001', 'CN/2026/002', 'CN/2027/001'])
        // The invoice sequence keeps its own settings, and the debit note its own defaults.
        ->and([nextNumber(DocumentNumbers::INVOICE, '2026-10-20'), nextNumber(DocumentNumbers::INVOICE, '2026-11-02'), nextNumber(DocumentNumbers::DEBIT_NOTE)])
        ->toBe(['INV-000001', 'INV-000001', 'DN-1']);
});

test('the increment setting never applies to a note sequence', function () {
    billingSettings(['number_increment' => 5]);

    expect([nextNumber(DocumentNumbers::INVOICE), nextNumber(DocumentNumbers::INVOICE), nextNumber(DocumentNumbers::CREDIT_NOTE), nextNumber(DocumentNumbers::CREDIT_NOTE)])
        ->toBe(['1', '6', 'CN-1', 'CN-2']);
});

test('a note number taken in a transaction that rolls back is not consumed', function () {
    try {
        DB::transaction(function () {
            expect(nextNumber(DocumentNumbers::DEBIT_NOTE))->toBe('DN-1');

            throw new RuntimeException('note failed after numbering');
        });
    } catch (RuntimeException) {
    }

    expect(nextNumber(DocumentNumbers::DEBIT_NOTE))->toBe('DN-1')->and(BillingSequence::query()->where('sequence', 'debit_note')->value('next_number'))->toBe(2);
});

test('an unknown reset value falls back to never instead of resetting by accident', function () {
    billingSettings(['credit_note_reset' => 'weekly']);

    expect([nextNumber(DocumentNumbers::CREDIT_NOTE, '2026-10-20'), nextNumber(DocumentNumbers::CREDIT_NOTE, '2027-10-20')])->toBe(['CN-1', 'CN-2']);
});

/**
 * Starts number-worker.php processes against this test worker's database (the helper of #189, own copy).
 *
 * @param  list<array{int, int, string}>  $workers  [delay ms, hold ms, commit|rollback]
 * @return list<array{number: int, taken: float, ended: float}>
 */
function raceSequence(string $sequence, array $workers): array
{
    $script = __DIR__.'/Support/number-worker.php';
    $database = DB::connection()->getDatabaseName();
    $processes = [];

    foreach ($workers as [$delay, $hold, $mode]) {
        $processes[] = [proc_open([PHP_BINARY, $script, $database, $sequence, (string) $delay, (string) $hold, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }

    $results = [];

    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        expect($out)->not->toStartWith('error', $out.$err);
        [$number, $taken, $ended] = explode(' ', trim($out)) + [null, null, null];
        $results[] = ['number' => (int) $number, 'taken' => (float) $taken, 'ended' => (float) $ended];
    }

    return $results;
}

function forgetSequence(string $sequence): void
{
    proc_close(proc_open([PHP_BINARY, __DIR__.'/Support/number-worker.php', DB::connection()->getDatabaseName(), $sequence, '0', '0', 'cleanup'], [], $pipes));
}

describe('note numbers under real concurrent transactions (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    afterEach(function () {
        if (DB::connection()->getDriverName() === 'pgsql') {
            array_map(forgetSequence(...), [DocumentNumbers::CREDIT_NOTE, DocumentNumbers::DEBIT_NOTE]);
        }
    });

    test('two notes of one kind are numbered one after the other: no duplicate, no gap, even after a rollback', function () {
        [$first, $second, $third] = raceSequence(DocumentNumbers::CREDIT_NOTE, [[0, 1_500, 'rollback'], [300, 0, 'commit'], [600, 0, 'commit']]);

        expect([$first['number'], $second['number'], $third['number']])->toBe([1, 1, 2])
            ->and($second['taken'])->toBeGreaterThanOrEqual($first['ended'] - 0.05);
    });

    test('a credit note does not wait for a debit note: they hold different sequence rows', function () {
        // A debit note worker holds its sequence row for 1.5 s; a credit note is numbered meanwhile.
        $holding = proc_open([PHP_BINARY, __DIR__.'/Support/number-worker.php', DB::connection()->getDatabaseName(), DocumentNumbers::DEBIT_NOTE, '0', '1500', 'commit'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holder);
        usleep(300_000);
        $started = microtime(true);
        $other = raceSequence(DocumentNumbers::CREDIT_NOTE, [[0, 0, 'commit']])[0];
        $waited = microtime(true) - $started;
        stream_get_contents($holder[1]);
        proc_close($holding);

        expect($other['number'])->toBe(1)->and($waited)->toBeLessThan(1.0);
    });

    test('a burst of notes is unique and gapless', function () {
        $numbers = array_column(raceSequence(DocumentNumbers::DEBIT_NOTE, array_fill(0, 6, [0, 150, 'commit'])), 'number');
        sort($numbers);

        expect($numbers)->toBe([1, 2, 3, 4, 5, 6]);
    });
});

test('the reporting rate is a decimal string from the stored rates and never a float', function () {
    Http::preventStrayRequests();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    Currency::factory()->inr()->create();
    $converter = app(CurrencyConverter::class);

    expect($converter->rateDecimal('USD', 'NPR'))->toBe('134.400000000000')
        ->and($converter->rateDecimal('NPR', 'NPR'))->toBe('1.000000000000')
        ->and($converter->rateDecimal('npr', 'usd'))->toBe('0.007440476190')
        // Cross rate through the default currency: INR 84 and NPR 134.4 per USD.
        ->and($converter->rateDecimal('INR', 'NPR'))->toBe('1.600000000000')
        ->and($converter->rate('USD', 'NPR'))->toBe(134.4)
        ->and($converter->rate('NPR', 'NPR'))->toBe(1.0);
});

test('a currency without a stored rate refuses a reporting rate', function () {
    Currency::factory()->create();

    expect(fn () => app(CurrencyConverter::class)->rateDecimal('USD', 'NPR'))->toThrow(BillingException::class);
});
