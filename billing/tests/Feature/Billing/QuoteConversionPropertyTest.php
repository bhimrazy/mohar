<?php

use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Models\TaxRule;

require_once __DIR__.'/helpers.php';

/**
 * Issue #20: a converted quote's invoice must match the quote to the cent, whatever the quantities, prices,
 * discounts and tax mode.
 */
test('converted invoices match the quote total exactly', function (array $tax) {
    billingSettings(['tax.enabled' => true, 'credit.auto_apply_on_generate' => false, ...$tax]);
    TaxRule::factory()->create(['name' => 'GST', 'level' => 1, 'rate' => '18.000']);
    TaxRule::factory()->create(['name' => 'Cess', 'level' => 2, 'rate' => '1.500']);
    $client = billingClient();
    $quotes = app(Quotes::class);
    mt_srand(20);

    for ($run = 0; $run < 40; $run++) {
        $items = [];

        foreach (range(1, mt_rand(1, 5)) as $line) {
            $items[] = [
                'description' => "Line {$line}",
                'quantity' => mt_rand(1, 37),
                'unit_amount' => mt_rand(1, 250_000),
                'discount_percent' => mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 9_999) / 100,
                'taxable' => mt_rand(0, 4) > 0,
            ];
        }

        $quote = $quotes->save(['client_id' => $client->id, 'subject' => "Property {$run}", 'items' => $items]);
        $invoice = $quotes->convert($quote);

        expect($invoice->total)->toBe($quote->fresh()->total, "run {$run}")
            ->and($invoice->subtotal)->toBe($quote->fresh()->subtotal, "run {$run}")
            ->and($invoice->tax1_amount + $invoice->tax2_amount)->toBe($quote->fresh()->tax1_amount + $quote->fresh()->tax2_amount, "run {$run}");
    }
})->with([
    'exclusive, per line' => [['tax.mode' => 'exclusive', 'tax.calculation' => 'per_line']],
    'exclusive, per invoice' => [['tax.mode' => 'exclusive', 'tax.calculation' => 'per_invoice']],
    'inclusive, compound' => [['tax.mode' => 'inclusive', 'tax.compound' => true]],
]);
