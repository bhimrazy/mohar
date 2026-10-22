<?php

namespace App\Modules\Billing\Actions;

use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Billing\QuoteAccepted;
use App\Events\Billing\QuoteDeclined;
use App\Events\Billing\QuoteSent;
use App\Modules\Billing\Enums\QuoteStage;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\QuoteItem;
use App\Modules\Billing\Services\DocumentNumbers;
use App\Modules\Billing\Services\MoneyFormatter;
use App\Modules\Billing\Services\Tax\TaxEngine;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Modules\Billing\Services\Tax\TaxRates;
use App\Modules\Billing\Support\AfterCommit;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quote lifecycle (§8): draft → delivered → accepted | declined | on_hold; delivered → expired after valid_until.
 */
class Quotes
{
    public function __construct(
        private TaxEngine $tax,
        private DocumentNumbers $numbers,
        private IssueInvoice $issue,
        private BillingSettings $settings,
        private MoneyFormatter $money,
    ) {}

    /**
     * @param  array{client_id: int, subject: string, valid_until?: string|null, proposal_text?: string|null, customer_notes?: string|null, admin_notes?: string|null, items: list<array{description: string, quantity: int, unit_amount: int, discount_percent?: float|int|string|null, taxable?: bool}>}  $data
     */
    public function save(array $data, ?Quote $quote = null): Quote
    {
        $this->assertEditable($quote);

        return DB::transaction(function () use ($data, $quote): Quote {
            // Lock order quote, then client, as convert() and accept() take it (ADR 0021). The stage is checked again
            // under the quote lock, so an edit cannot overwrite a quote accepted or converted in the meantime.
            if ($quote !== null) {
                $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);
                $this->assertEditable($quote);
            }

            // A share lock on the client, so its currency cannot change before this quote is saved in it (#218).
            $client = Client::query()->sharedLock()->findOrFail($data['client_id']);
            $quote ??= new Quote(['stage' => QuoteStage::Draft]);
            $quote->fill([
                'client_id' => $client->id,
                'subject' => $data['subject'],
                'currency' => $client->currency,
                'valid_until' => isset($data['valid_until']) ? CarbonImmutable::parse($data['valid_until']) : CarbonImmutable::today()->addDays($this->settings->int('quotes.valid_days', 30)),
                'proposal_text' => $data['proposal_text'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
                'admin_notes' => $data['admin_notes'] ?? null,
            ]);
            $quote->save();
            $quote->items()->delete();

            foreach ($data['items'] as $index => $item) {
                $discount = (float) ($item['discount_percent'] ?? 0);
                $quote->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_amount' => $item['unit_amount'],
                    'discount_percent' => $discount,
                    'amount' => self::lineAmount($item['quantity'], $item['unit_amount'], $discount),
                    'taxable' => $item['taxable'] ?? true,
                    'sort_order' => $index,
                ]);
            }

            $this->recalculate($quote, $client);

            return $quote;
        });
    }

    private function assertEditable(?Quote $quote): void
    {
        if ($quote !== null && ! in_array($quote->stage, [QuoteStage::Draft, QuoteStage::Delivered, QuoteStage::OnHold], true)) {
            throw new BillingException(__('Accepted, declined or expired quotes can\'t be edited.'));
        }
    }

    /**
     * qty × unit × (1 − discount%), rounded per line.
     */
    public static function lineAmount(int $quantity, int $unitAmount, float $discountPercent): int
    {
        return TaxMath::roundDiv($quantity * $unitAmount * (100_000 - (int) round($discountPercent * 1000)), 100_000);
    }

    public function deliver(Quote $quote): Quote
    {
        if (! in_array($quote->stage, [QuoteStage::Draft, QuoteStage::OnHold, QuoteStage::Delivered], true)) {
            throw new BillingException(__('This quote can no longer be sent.'));
        }

        DB::transaction(function () use ($quote): void {
            $quote->number ??= $this->numbers->next(DocumentNumbers::QUOTE);
            $quote->stage = QuoteStage::Delivered;
            $quote->delivered_at = now();
            $quote->save();
        });

        AfterCommit::dispatch(new QuoteSent($quote->id, $quote->client_id));

        return $quote;
    }

    public function accept(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);

            if (! $quote->stage->isOpen() || ($quote->valid_until !== null && $quote->valid_until->lt(CarbonImmutable::today()))) {
                throw new BillingException(__('This quote can no longer be accepted.'));
            }

            $quote->update(['stage' => QuoteStage::Accepted, 'accepted_at' => now()]);

            if ($this->settings->bool('quotes.auto_invoice_on_accept')) {
                $this->convert($quote);
                $quote->refresh();
            }

            AfterCommit::dispatch(new QuoteAccepted($quote->id, $quote->client_id, $quote->invoice_id));

            return $quote;
        });
    }

    public function decline(Quote $quote): Quote
    {
        if (! $quote->stage->isOpen()) {
            throw new BillingException(__('This quote can no longer be declined.'));
        }

        $quote->update(['stage' => QuoteStage::Declined, 'declined_at' => now()]);
        AfterCommit::dispatch(new QuoteDeclined($quote->id, $quote->client_id));

        return $quote;
    }

    /**
     * Create the unpaid invoice of manual lines. A second conversion is refused.
     */
    public function convert(Quote $quote): Invoice
    {
        return DB::transaction(function () use ($quote): Invoice {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);

            if ($quote->invoice_id !== null) {
                throw new BillingException(__('This quote has already been converted to an invoice.'));
            }

            // Each invoice line carries the quote line's stored net amount, so tax is computed on exactly the same
            // amounts and the invoice total equals the quote total. Quantity is kept when the net divides evenly;
            // otherwise the line is one unit with the quantity, price and discount spelled out in its description.
            $lines = array_values($quote->items->map(fn (QuoteItem $item): InvoiceLineData => $this->invoiceLine($item, $quote->currency))->all());

            $invoice = $this->issue->handle(new InvoiceDraft(
                clientId: $quote->client_id,
                currency: $quote->currency,
                lines: $lines,
                notes: $quote->customer_notes,
                reason: 'manual',
            ), 'quote');

            $quote->update(['invoice_id' => $invoice->id, 'stage' => QuoteStage::Accepted, 'accepted_at' => $quote->accepted_at ?? now()]);

            return $invoice;
        });
    }

    private function invoiceLine(QuoteItem $item, string $currency): InvoiceLineData
    {
        if ($item->quantity > 0 && $item->amount % $item->quantity === 0) {
            $description = (float) $item->discount_percent > 0
                ? __(':line (:percent% discount)', ['line' => $item->description, 'percent' => self::percent($item)])
                : $item->description;

            return new InvoiceLineData(mb_substr($description, 0, 255), intdiv($item->amount, $item->quantity), $item->quantity, $item->taxable, BillableType::Manual);
        }

        $description = __(':line (:quantity × :price, :percent% discount)', [
            'line' => $item->description,
            'quantity' => $item->quantity,
            'price' => $this->money->format($item->unit_amount, $currency),
            'percent' => self::percent($item),
        ]);

        return new InvoiceLineData(mb_substr($description, 0, 255), $item->amount, 1, $item->taxable, BillableType::Manual);
    }

    private static function percent(QuoteItem $item): string
    {
        return rtrim(rtrim((string) $item->discount_percent, '0'), '.');
    }

    /**
     * Expire delivered quotes past valid_until. Idempotent.
     */
    public function expire(CarbonImmutable $today): int
    {
        return Quote::query()
            ->whereIn('stage', [QuoteStage::Delivered, QuoteStage::OnHold])
            ->whereDate('valid_until', '<', $today->toDateString())
            ->update(['stage' => QuoteStage::Expired, 'updated_at' => now()]);
    }

    private function recalculate(Quote $quote, Client $client): void
    {
        $items = $quote->items()->get();
        $rates = $this->tax->ratesForClient($client);
        $result = $this->tax->compute(array_values($items->map(fn (QuoteItem $item): array => [
            'amount' => $item->amount, 'taxable' => $item->taxable, 'type' => BillableType::Manual,
        ])->all()), $rates);

        $quote->update([
            'subtotal' => $result->subtotal,
            'tax1_name' => $rates->rate1 > 0 ? $rates->name1 : null,
            'tax1_rate' => TaxRates::toPercent($rates->rate1),
            'tax1_amount' => $result->tax1,
            'tax2_name' => $rates->rate2 > 0 ? $rates->name2 : null,
            'tax2_rate' => TaxRates::toPercent($rates->rate2),
            'tax2_amount' => $result->tax2,
            'total' => $result->total,
        ]);
    }
}
