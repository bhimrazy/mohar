<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Services\Tax\TaxEngine;
use App\Modules\Billing\Services\Tax\TaxRates;
use App\Modules\Core\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Computes line amounts, tax and totals of a draft invoice from its items and the client's tax context.
 * Rates and names are snapshotted onto the invoice so later rule changes never alter issued documents.
 */
class InvoiceCalculator
{
    public function __construct(private TaxEngine $tax) {}

    /**
     * The two optional arguments save a query each when the caller has just read or written them. Both are checked
     * without a query, and a mismatch throws instead of producing wrong totals:
     *
     * @param  Client|null  $client  the invoice's client, read in the caller's transaction and unmodified (it must
     *                               match `client_id` and not be dirty)
     * @param  Collection<int, InvoiceItem>|null  $items  every open line of the invoice, in line order. Used only for an
     *                                                    invoice created in this request (`wasRecentlyCreated`), whose
     *                                                    lines only its creator wrote; for any other invoice the lines
     *                                                    are read. Lines of another invoice, or cancelled ones, throw.
     *
     * @throws LogicException when $client or $items does not belong to the invoice
     */
    public function recalculate(Invoice $invoice, ?Client $client = null, ?Collection $items = null): void
    {
        if ($invoice->exists && $invoice->getOriginal('status') !== InvoiceStatus::Draft) {
            throw ImmutableInvoiceException::make();
        }

        if ($client !== null && ($client->id !== $invoice->client_id || $client->isDirty())) {
            throw new LogicException("The client passed to recalculate invoice {$invoice->id} is not its unmodified client.");
        }

        if ($items !== null && ! $items->every(fn (InvoiceItem $item): bool => $item->invoice_id === $invoice->id && $item->cancelled_at === null)) {
            throw new LogicException("The lines passed to recalculate invoice {$invoice->id} are not all its open lines.");
        }

        $items = $items !== null && $invoice->wasRecentlyCreated ? $items : $invoice->items()->whereNull('cancelled_at')->get();
        $rates = $this->tax->ratesForClient($client ?? $invoice->client()->firstOrFail());

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
            'tax1_rule_id' => $rates->rule1,
            'tax2_rule_id' => $rates->rule2,
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
            rule1: $invoice->tax1_rule_id,
            rule2: $invoice->tax2_rule_id,
        );
    }
}
