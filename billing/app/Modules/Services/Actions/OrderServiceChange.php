<?php

namespace App\Modules\Services\Actions;

use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Support\Billing;
use App\Modules\Services\Support\ServiceSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orders an upgrade/downgrade. The quote is computed inside the transaction on the locked service
 * row, so concurrent submits are serialized and the second one sees the first change.
 * Amount due > 0 → upgrade invoice (applied when paid); ≤ 0 → applied immediately, and the
 * difference is credited (same transaction, once per change) only when credit_on_downgrade is on.
 */
class OrderServiceChange
{
    public function __construct(
        private QuoteServiceChange $quotes,
        private ApplyServiceChange $apply,
        private Billing $billing,
        private ServiceSettings $settings,
    ) {}

    public function handle(Service $service, int $toProductId, BillingCycle $toCycle, CarbonImmutable $today): ServiceChange
    {
        $change = DB::transaction(function () use ($service, $toProductId, $toCycle, $today): ServiceChange {
            /** @var Service $locked */
            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);
            $quote = $this->quotes->handle($locked, $toProductId, $toCycle, $today);

            $change = ServiceChange::query()->create([
                'service_id' => $locked->id,
                'kind' => $quote->kind,
                'from_snapshot' => $this->snapshot($locked->product_id, $locked->product_name, $locked->cycle->value, $locked->recurring_amount, $locked->next_due_date?->toDateString(), $locked->billing_anchor_day),
                'to_snapshot' => $this->snapshot($quote->toProductId, $quote->toProductName, $quote->toCycle->value, $quote->newRecurringAmount, $quote->nextDueDate?->toDateString(), $quote->anchorDay),
                'currency' => $quote->currency,
                'credit_amount' => $quote->proration->credit,
                'charge_amount' => $quote->proration->charge,
                'amount_due' => $quote->amountDue(),
                'promotion_id' => $locked->promotion_id,
                'status' => ChangeStatus::PendingPayment,
                'expires_at' => CarbonImmutable::now()->addDays($this->settings->unpaidChangeExpiryDays()),
            ]);

            if ($quote->amountDue() > 0) {
                $periodEnd = $quote->nextDueDate ?? $today;
                $result = $this->billing->invoicer()->issue(new InvoiceDraft(
                    clientId: $locked->client_id,
                    currency: $locked->currency,
                    lines: [new InvoiceLineData(
                        sprintf('Upgrade: %s → %s (%s - %s)', $locked->product_name ?? 'Service', $quote->toProductName, $today->toDateString(), $periodEnd->subDay()->toDateString()),
                        $quote->amountDue(),
                        1,
                        true,
                        BillableType::ServiceUpgrade,
                        $change->id,
                        $today,
                        $periodEnd,
                    )],
                    dueDate: $today,
                    issueDate: $today,
                    paymentGateway: $locked->payment_gateway,
                ));

                $change->update(['invoice_id' => $result->invoiceId]);

                return $change;
            }

            if ($quote->amountDue() < 0 && $this->settings->creditOnDowngrade()) {
                $this->billing->credits()->credit($locked->client_id, Money::of(-$quote->amountDue(), $quote->currency), "Downgrade credit for service #{$locked->id} (change #{$change->id})");
            }

            $this->apply->handle($change->id);

            return $change;
        });

        return $change->refresh();
    }

    /**
     * @return array{product_id: int, product_name: ?string, cycle: string, recurring_amount: int, next_due_date: ?string, billing_anchor_day: ?int}
     */
    private function snapshot(int $productId, ?string $productName, string $cycle, int $recurringAmount, ?string $nextDueDate, ?int $anchorDay): array
    {
        return ['product_id' => $productId, 'product_name' => $productName, 'cycle' => $cycle, 'recurring_amount' => $recurringAmount, 'next_due_date' => $nextDueDate, 'billing_anchor_day' => $anchorDay];
    }
}
