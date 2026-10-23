<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Core\CompanyClock;
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
    public function __construct(private Catalog $catalog, private CompanyClock $clock) {}

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

        $old = $this->prorateRemainingPaidTime($service, $quote->recurring, $today);

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
     * Prorate over all time the client has already paid for, from today to next_due_date — the
     * current period plus any period prepaid during the renewal lead time — segment by segment
     * (each segment uses its own calendar length). Credit = what was paid for the remaining days,
     * charge = what the new price costs for the same days.
     */
    public function prorateRemainingPaidTime(Service $service, int $newAmount, CarbonImmutable $today): Proration
    {
        /** @var CarbonImmutable $paidThrough */
        $paidThrough = $service->next_due_date;
        $segments = [];

        for ($end = $paidThrough, $guard = 0; $end->gt($today) && $guard < 120; $guard++) {
            $start = BillingDates::periodStartBefore($service->cycle, $end, $service->billing_anchor_day);
            array_unshift($segments, [$start, $end]);
            $end = $start;
        }

        if ($segments === []) {
            return new Proration(0, 0, 0, 0);
        }

        $windowStart = $segments[0][0];
        $credit = 0;
        $charge = 0;
        $remaining = 0;

        foreach ($segments as [$start, $end]) {
            $part = Proration::calculate($this->paidAmountFor($service, $windowStart, $end), $newAmount, $start, $end, $today);
            $credit += $part->credit;
            $charge += $part->charge;
            $remaining += $part->remainingDays;
        }

        return new Proration((int) $windowStart->diffInDays($paidThrough), $remaining, $credit, $charge);
    }

    /**
     * What the client effectively paid for the period ending on $end:
     * - the target price of the latest change applied since the start of the period containing
     *   today, or after that period was paid — a change re-prices all remaining paid time, so
     *   downgrade → upgrade loops are priced step by step and can never mint credit, prepaid
     *   periods included; else
     * - the net amount (service line + its promotion lines) actually paid for that period; else
     * - the recurring amount less any active renewal discount.
     */
    public function paidAmountFor(Service $service, CarbonImmutable $windowStart, CarbonImmutable $end): int
    {
        $link = ServiceInvoice::query()
            ->where('service_id', $service->id)
            ->where('status', InvoiceLinkStatus::Paid->value)
            ->whereDate('period_end', $end)
            ->orderByDesc('id')
            ->first();

        $applied = ServiceChange::query()
            ->where('service_id', $service->id)
            ->where('status', ChangeStatus::Applied->value)
            ->where(fn ($query) => $query
                ->where('applied_at', '>=', $this->clock->dayBounds($windowStart)[0])
                ->when($link?->updated_at !== null, fn ($query) => $query->orWhere('applied_at', '>=', $link?->updated_at)))
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->first();

        if ($applied !== null) {
            return (int) $applied->to_snapshot['recurring_amount'];
        }

        if ($link !== null) {
            return $link->amount;
        }

        $discount = $service->promotion_cycles_remaining === 0 ? 0 : $service->renewal_discount;

        return max(0, $service->recurring_amount - $discount);
    }
}
