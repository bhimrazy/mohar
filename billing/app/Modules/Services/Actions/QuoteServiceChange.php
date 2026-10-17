<?php

namespace App\Modules\Services\Actions;

use App\Data\Catalog\ProductSelection;
use App\Enums\BillingCycle;
use App\Modules\Services\Enums\ChangeKind;
use App\Modules\Services\Enums\ChangeStatus;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Exceptions\ServiceActionException;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceChange;
use App\Modules\Services\Models\ServiceInvoice;
use App\Modules\Services\Support\BillingDates;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\ChangeQuote;
use App\Modules\Services\Support\Proration;
use Carbon\CarbonImmutable;

/**
 * Prices an upgrade/downgrade with the services.md §5 proration formula.
 */
class QuoteServiceChange
{
    public function __construct(private Catalog $catalog) {}

    /**
     * @throws ServiceActionException when the change is not allowed
     */
    public function handle(Service $service, int $toProductId, BillingCycle $toCycle, CarbonImmutable $today): ChangeQuote
    {
        $priceBook = $this->catalog->priceBook();

        if ($priceBook === null) {
            throw new ServiceActionException(__('Upgrades are not available right now.'));
        }

        $this->assertEligible($service, $today);

        $productChanges = $toProductId !== $service->product_id;
        $cycleChanges = $toCycle !== $service->cycle;

        if (! $productChanges && ! $cycleChanges) {
            throw new ServiceActionException(__('Choose a different product or billing cycle.'));
        }

        if ($productChanges && ! $priceBook->canUpgrade($service->product_id, $toProductId)) {
            throw new ServiceActionException(__('This service cannot be changed to that product.'));
        }

        $quote = $priceBook->quote(new ProductSelection($toProductId, $toCycle, $service->currency, array_map('intval', $service->config_options['selections'] ?? []), $service->client_id));

        if ($quote === null) {
            throw new ServiceActionException(__('That product is not offered in this billing cycle.'));
        }

        $name = $priceBook->product($toProductId)->name ?? "Product #{$toProductId}";
        $kind = $cycleChanges ? ChangeKind::Cycle : ChangeKind::Product;
        $free = ! $service->cycle->isRecurring() || $service->next_due_date === null;

        if ($free) {
            return new ChangeQuote($kind, $toProductId, $name, $toCycle, $quote->recurring, $service->currency, Proration::fullCharge($quote->recurring), $toCycle->advance($today, 1, $today->day), $today->day);
        }

        /** @var CarbonImmutable $periodEnd */
        $periodEnd = $service->next_due_date;
        $periodStart = BillingDates::periodStartBefore($service->cycle, $periodEnd, $service->billing_anchor_day);
        $old = Proration::calculate($this->currentPeriodAmount($service, $periodStart), $quote->recurring, $periodStart, $periodEnd, $today);

        if ($cycleChanges) {
            return new ChangeQuote($kind, $toProductId, $name, $toCycle, $quote->recurring, $service->currency, Proration::fullCharge($quote->recurring, $old->credit), $toCycle->advance($today, 1, $today->day), $today->day);
        }

        return new ChangeQuote($kind, $toProductId, $name, $toCycle, $quote->recurring, $service->currency, $old, $service->next_due_date, $service->billing_anchor_day);
    }

    /**
     * @throws ServiceActionException
     */
    public function assertEligible(Service $service, CarbonImmutable $today): void
    {
        if ($service->status !== ServiceStatus::Active) {
            throw new ServiceActionException(__('Only active services can be upgraded or downgraded.'));
        }

        if ($service->changes()->where('status', ChangeStatus::PendingPayment->value)->exists()) {
            throw new ServiceActionException(__('An upgrade for this service is already waiting for payment.'));
        }

        if ($service->next_due_date !== null && $service->next_due_date->lte($today)) {
            throw new ServiceActionException(__('Pay the outstanding invoice before changing this service.'));
        }
    }

    /**
     * What the client effectively pays for the current period, used for the credit side:
     * - the target price of the latest change already applied in this period (so downgrade →
     *   upgrade loops can never mint credit: each step is priced from the previous step), else
     * - the net amount (service line + its promotion lines) actually paid for the period, else
     * - the recurring amount less any active renewal discount.
     */
    public function currentPeriodAmount(Service $service, CarbonImmutable $periodStart): int
    {
        $applied = ServiceChange::query()
            ->where('service_id', $service->id)
            ->where('status', ChangeStatus::Applied->value)
            ->where('applied_at', '>=', $periodStart->startOfDay())
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->first();

        if ($applied !== null) {
            return (int) $applied->to_snapshot['recurring_amount'];
        }

        $link = ServiceInvoice::query()
            ->where('service_id', $service->id)
            ->where('status', InvoiceLinkStatus::Paid->value)
            ->whereDate('period_end', $service->next_due_date)
            ->orderByDesc('id')
            ->first();

        if ($link !== null) {
            return $link->amount;
        }

        $discount = $service->promotion_cycles_remaining === 0 ? 0 : $service->renewal_discount;

        return max(0, $service->recurring_amount - $discount);
    }
}
