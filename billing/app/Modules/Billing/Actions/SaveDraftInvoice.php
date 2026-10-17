<?php

namespace App\Modules\Billing\Actions;

use App\Enums\BillableType;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\ImmutableInvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a staff draft invoice and its manual lines. Only drafts are editable.
 */
class SaveDraftInvoice
{
    public function __construct(private InvoiceCalculator $calculator) {}

    /**
     * @param  array{client_id: int, due_date?: string|null, payment_gateway?: string|null, notes?: string|null, admin_notes?: string|null, items: list<array{description: string, quantity: int, unit_amount: int, taxable?: bool}>}  $data
     */
    public function handle(array $data, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($data, $invoice): Invoice {
            if ($invoice !== null) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

                if ($invoice->status !== InvoiceStatus::Draft) {
                    throw ImmutableInvoiceException::make();
                }
            }

            $client = Client::query()->findOrFail($data['client_id']);
            $invoice ??= new Invoice(['status' => InvoiceStatus::Draft, 'source' => 'manual']);
            $invoice->fill([
                'client_id' => $client->id,
                'currency' => $client->currency,
                'due_date' => isset($data['due_date']) ? CarbonImmutable::parse($data['due_date']) : null,
                'payment_gateway' => $data['payment_gateway'] ?? null,
                'notes' => $data['notes'] ?? null,
                'admin_notes' => $data['admin_notes'] ?? null,
                'client_snapshot' => IssueInvoice::snapshot($client),
            ]);
            $invoice->save();

            $invoice->items()->delete();

            foreach ($data['items'] as $index => $item) {
                $invoice->items()->create([
                    'type' => BillableType::Manual,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_amount' => $item['unit_amount'],
                    'amount' => $item['quantity'] * $item['unit_amount'],
                    'taxable' => $item['taxable'] ?? true,
                    'sort_order' => $index,
                ]);
            }

            $this->calculator->recalculate($invoice);

            return $invoice;
        });
    }
}
