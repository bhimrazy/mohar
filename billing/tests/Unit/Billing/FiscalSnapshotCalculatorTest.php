<?php

use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Support\FiscalSnapshotCalculator;
use Tests\TestCase;

// The old cancellation text is translated, so matching needs the translator.
uses(TestCase::class);

/*
| The tax-invoice snapshot rule (ADR 0024 §2) on plain rows: no database, no clock.
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array{total: int, subtotal: int, tax1_amount: int, tax2_amount: int, tax_inclusive: bool}
 */
function snapshotInvoice(array $overrides = []): array
{
    return $overrides + ['total' => 11_300, 'subtotal' => 10_000, 'tax1_amount' => 1_300, 'tax2_amount' => 0, 'tax_inclusive' => false];
}

/** @return array{id: int, description: string, amount: int, tax_amount: int, taxable: bool, cancelled: bool} */
function snapshotLine(int $id, int $amount, int $tax, string $description = 'Hosting', bool $cancelled = false): array
{
    return ['id' => $id, 'description' => $description, 'amount' => $amount, 'tax_amount' => $tax, 'taxable' => $tax !== 0, 'cancelled' => $cancelled];
}

/** @return array{id: int, kind: string, reason: string, amount: int, taxable_amount: int, tax_amount: int, description: string|null} */
function snapshotRow(int $id, string $kind, string $reason, int $amount, int $taxable = 0, int $tax = 0, ?string $description = null): array
{
    return compact('id', 'kind', 'reason', 'amount') + ['taxable_amount' => $taxable, 'tax_amount' => $tax, 'description' => $description];
}

test('the parts always add up to the total, whatever the rows', function (array $rows) {
    $snapshot = (new FiscalSnapshotCalculator)->compute(
        snapshotInvoice(['total' => 13_300, 'subtotal' => 12_000]),
        [snapshotLine(1, 10_000, 1_300), snapshotLine(2, 2_000, 0, 'Domain')],
        $rows,
    );

    expect($snapshot->taxable + $snapshot->exempt + $snapshot->tax1 + $snapshot->tax2)->toBe($snapshot->total);
})->with([
    'none' => [[]],
    'a prorated cancellation' => [[snapshotRow(1, 'credit', 'cancellation', 777)]],
    'prorated rows of both directions' => [[snapshotRow(1, 'credit', 'refund', 1_001), snapshotRow(2, 'debit', 'manual', 333), snapshotRow(3, 'credit', 'cancellation', 5)]],
    'a late fee' => [[snapshotRow(1, 'debit', 'late_fee', 565, 500, 65)]],
    'a matched exempt line' => [[snapshotRow(1, 'credit', 'item_cancellation', 2_000, 2_000, 0, 'Cancelled line: Domain')]],
]);

test('a prorated row follows the mix of the snapshot and sends the rounding remainder to the largest part', function () {
    $snapshot = (new FiscalSnapshotCalculator)->compute(
        snapshotInvoice(['total' => 13_300, 'subtotal' => 12_000]),
        [snapshotLine(1, 10_000, 1_300), snapshotLine(2, 2_000, 0, 'Domain')],
        [snapshotRow(1, 'credit', 'refund', 1_330)],
    );

    // Mix 10,000 : 2,000 : 1,300 of 13,300; 1,330 is exactly a tenth of the invoice.
    expect([$snapshot->taxable, $snapshot->exempt, $snapshot->tax1, $snapshot->total])->toBe([9_000, 1_800, 1_170, 11_970])
        ->and(array_column($snapshot->reviews, 'reason'))->toBe([InvoiceFiscalReview::LINE_LESS_ROW]);
});

test('with no tax on the invoice every row is exempt and the list stays empty', function () {
    $snapshot = (new FiscalSnapshotCalculator)->compute(
        snapshotInvoice(['total' => 5_000, 'subtotal' => 5_000, 'tax1_amount' => 0]),
        [snapshotLine(1, 5_000, 0)],
        [snapshotRow(1, 'credit', 'cancellation', 700)],
        [snapshotRow(2, 'credit', 'item_cancellation', 300, 300, 0, 'Cancelled line: other')],
    );

    expect([$snapshot->taxable, $snapshot->exempt, $snapshot->total, $snapshot->reviews, $snapshot->supplyClass()])->toBe([0, 4_300, 4_300, [], 'exempt']);
});

test('lines that do not add up to the stored totals are estimated from the totals and listed', function () {
    $snapshot = (new FiscalSnapshotCalculator)->compute(snapshotInvoice(), []);

    expect([$snapshot->taxable, $snapshot->exempt, $snapshot->tax1, $snapshot->total])->toBe([10_000, 0, 1_300, 11_300])
        ->and(array_column($snapshot->reviews, 'reason'))->toBe([InvoiceFiscalReview::LINES_MISMATCH]);
});

test('a late fee tax is split between two levels in the proportion of the invoice taxes', function () {
    $snapshot = (new FiscalSnapshotCalculator)->compute(
        snapshotInvoice(['total' => 11_800, 'subtotal' => 10_000, 'tax1_amount' => 900, 'tax2_amount' => 900]),
        [snapshotLine(1, 10_000, 1_800)],
        [snapshotRow(1, 'debit', 'late_fee', 118, 100, 18)],
    );

    expect([$snapshot->taxable, $snapshot->tax1, $snapshot->tax2, $snapshot->total])->toBe([10_100, 909, 909, 11_918]);
});

test('two cancellations of equal lines match one line each', function () {
    $snapshot = (new FiscalSnapshotCalculator)->compute(
        snapshotInvoice(['total' => 22_600, 'subtotal' => 20_000, 'tax1_amount' => 2_600]),
        [snapshotLine(1, 10_000, 1_300, 'Plan', true), snapshotLine(2, 10_000, 1_300, 'Plan', true)],
        [
            snapshotRow(1, 'credit', 'item_cancellation', 11_300, 10_000, 1_300, 'Cancelled line: Plan'),
            snapshotRow(2, 'credit', 'item_cancellation', 11_300, 10_000, 1_300, 'Cancelled line: Plan'),
        ],
    );

    expect([$snapshot->taxable, $snapshot->tax1, $snapshot->total, $snapshot->reviews])->toBe([0, 0, 0, []]);
});
