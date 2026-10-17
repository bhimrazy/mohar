<?php

namespace App\Modules\Billing\Actions;

use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\InvoiceCalculator;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Models\Client;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Create an invoice from a draft DTO (Invoicer::issue, add funds, quote conversion): snapshot the client,
 * compute tax, publish (number + InvoiceCreated) and optionally apply account credit — in one transaction.
 */
class IssueInvoice
{
    public function __construct(
        private InvoiceCalculator $calculator,
        private PublishInvoice $publish,
        private ApplyCredit $applyCredit,
        private CreditLedgerService $credit,
        private BillingSettings $settings,
    ) {}

    public function handle(InvoiceDraft $draft, string $source = 'manual'): Invoice
    {
        if ($draft->lines === []) {
            throw new BillingException(__('An invoice needs at least one line.'));
        }

        try {
            return DB::transaction(function () use ($draft, $source): Invoice {
                $client = Client::query()->findOrFail($draft->clientId);
                $currency = strtoupper($draft->currency);

                if ($currency !== strtoupper($client->currency)) {
                    throw new BillingException(__('Invoices must be in the client currency (:currency).', ['currency' => $client->currency]));
                }

                $addFunds = collect($draft->lines)->every(fn (InvoiceLineData $line): bool => $line->billableType === BillableType::AddFunds);

                $invoice = Invoice::query()->create([
                    'client_id' => $client->id,
                    'status' => InvoiceStatus::Draft,
                    'kind' => $addFunds ? InvoiceKind::AddFunds : InvoiceKind::Standard,
                    'source' => $addFunds ? 'add_funds' : $source,
                    'currency' => $currency,
                    'due_date' => $draft->dueDate,
                    'payment_gateway' => $draft->paymentGateway,
                    'notes' => $draft->notes,
                    'client_snapshot' => self::snapshot($client),
                ]);

                foreach (array_values($draft->lines) as $index => $line) {
                    $type = $line->billableType ?? BillableType::Manual;
                    $invoice->items()->create([
                        'type' => $type,
                        'billable_id' => $line->billableId,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unit_amount' => $line->unitAmount,
                        'amount' => $line->total(),
                        'taxable' => $line->taxable,
                        'period_start' => $line->periodStart,
                        'period_end' => $line->periodEnd,
                        'sort_order' => $index,
                        'period_guard' => InvoiceItem::guardKey($type, $line->billableId, $line->periodStart),
                    ]);
                }

                $this->calculator->recalculate($invoice);

                if ($draft->status === 'draft') {
                    return $invoice;
                }

                $this->publish->handle($invoice, $draft->issueDate);
                $invoice->refresh();

                if ($invoice->kind === InvoiceKind::Standard && $invoice->balance > 0 && ($draft->applyCredit || $this->settings->bool('credit.auto_apply_on_generate'))) {
                    $available = $this->credit->balance($invoice->client_id, $invoice->currency)->amount;

                    if ($available > 0) {
                        $this->applyCredit->handle($invoice, min($available, $invoice->balance));
                    }
                }

                return $invoice->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new BillingException(__('One of these items is already invoiced for this period.'));
        }
    }

    /**
     * Customer details as they appear on the document; old invoices never change when the profile does.
     *
     * @return array<string, string|null>
     */
    public static function snapshot(Client $client): array
    {
        return [
            'name' => trim($client->first_name.' '.$client->last_name),
            'company' => $client->company_name,
            'email' => $client->email,
            'address_1' => $client->address_1,
            'address_2' => $client->address_2,
            'city' => $client->city,
            'state' => $client->state,
            'postcode' => $client->postcode,
            'country' => $client->country,
            'tax_id' => $client->tax_id,
        ];
    }
}
