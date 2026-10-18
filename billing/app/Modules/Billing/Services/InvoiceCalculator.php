<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Services\Tax\TaxEngine;
use App\Modules\Billing\Services\Tax\TaxRates;

/**
 * Computes line amounts, tax and totals of a draft invoice from its items and the client's tax context.
 * Rates and names are snapshotted onto the invoice so later rule changes never alter issued documents.
 */
class InvoiceCalculator
{
    public function __construct(private TaxEngine $tax) {}

    public function recalculate(Invoice $invoice): void
    {
        if ($invoice->exists && $invoice->getOriginal('status') !== InvoiceStatus::Draft) {
            throw ImmutableInvoiceException::make();
        }

        $items = $invoice->items()->whereNull('cancelled_at')->get();
        $rates = $this->tax->ratesForClient($invoice->client()->firstOrFail());

        $result = $this->tax->compute(array_values($items->map(fn (InvoiceItem $item): array => [
            'amount' => $item->unit_amount * $item->quantity,
            'taxable' => $item->taxable,
            'type' => $item->type,
        ])->all()), $rates);

        foreach ($items->values() as $index => $item) {
            $item->amount = $item->unit_amount * $item->quantity;
            $item->tax_amount = $result->lineTaxes[$index] ?? 0;
            $item->save();
        }

        $invoice->fill([
            'subtotal' => $result->subtotal,
            'discount_total' => (int) -$items->where('amount', '<', 0)->sum('amount'),
            'tax1_name' => $rates->rate1 > 0 ? $rates->name1 : null,
            'tax1_rate' => TaxRates::toPercent($rates->rate1),
            'tax1_amount' => $result->tax1,
            'tax2_name' => $rates->rate2 > 0 ? $rates->name2 : null,
            'tax2_rate' => TaxRates::toPercent($rates->rate2),
            'tax2_amount' => $result->tax2,
            'tax_compound' => $rates->compound,
            'tax_inclusive' => $rates->inclusive,
            'tax_exempt_reason' => $rates->exemptReason,
            'total' => $result->total,
        ]);
        $invoice->recalculateBalance();
        $invoice->save();
    }

    /**
     * The tax context snapshotted on an issued invoice (used for late fees and other taxable adjustments).
     */
    public function snapshotRates(Invoice $invoice): TaxRates
    {
        return new TaxRates(
            name1: $invoice->tax1_name,
            rate1: TaxRates::toMilli($invoice->tax1_rate),
            name2: $invoice->tax2_name,
            rate2: TaxRates::toMilli($invoice->tax2_rate),
            compound: $invoice->tax_compound,
            inclusive: $invoice->tax_inclusive,
        );
    }
}
