<?php

namespace App\Modules\Services\Actions;

use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Enums\BillingCycle;
use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Services\InvoiceLinks;
use App\Modules\Services\Support\Billing;
use App\Modules\Services\Support\BillingDates;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automation task (RENEWALS): issue renewal invoices for services and addons whose next
 * uninvoiced period starts within the lead time (services.md §4.3).
 *
 * Idempotent: the next_invoice_date pointer advances in the same transaction as the invoice is
 * issued, rows are locked, and Invoicer::hasInvoiceForPeriod() guards against re-issuing a period.
 * Addons sharing the parent's period start and currency go on the parent's invoice.
 */
class GenerateRenewals
{
    public function __construct(private Billing $billing, private ServiceSettings $settings, private InvoiceLinks $links) {}

    /**
     * @return int number of periods invoiced
     */
    public function handle(CarbonImmutable $today): int
    {
        if (! $this->billing->available()) {
            return 0;
        }

        $horizon = $today->addDays($this->settings->maxLeadDays());
        $invoiced = 0;

        Service::query()
            ->whereIn('status', array_map(fn (ServiceStatus $status): string => $status->value, ServiceStatus::billable()))
            ->whereIn('cycle', array_map(fn (BillingCycle $cycle): string => $cycle->value, BillingCycle::recurring()))
            ->where('do_not_renew', false)
            ->where('terminate_at_period_end', false)
            ->whereNotNull('next_invoice_date')
            ->whereDate('next_invoice_date', '<=', $horizon)
            ->orderBy('id')
            ->chunkById(200, function ($services) use ($today, &$invoiced): void {
                foreach ($services as $service) {
                    $invoiced += $this->renewService($service->id, $today);
                }
            });

        ServiceAddon::query()
            ->whereIn('status', array_map(fn (ServiceStatus $status): string => $status->value, ServiceStatus::billable()))
            ->whereIn('cycle', array_map(fn (BillingCycle $cycle): string => $cycle->value, BillingCycle::recurring()))
            ->whereNotNull('next_invoice_date')
            ->whereDate('next_invoice_date', '<=', $horizon)
            ->orderBy('id')
            ->chunkById(200, function ($addons) use ($today, &$invoiced): void {
                foreach ($addons as $addon) {
                    $invoiced += $this->renewStandaloneAddon($addon->id, $today);
                }
            });

        return $invoiced;
    }

    private function renewService(int $serviceId, CarbonImmutable $today): int
    {
        return DB::transaction(function () use ($serviceId, $today): int {
            $service = Service::query()->lockForUpdate()->find($serviceId);

            if ($service === null || ! $this->isDue($service, $today) || ! $this->withinCycleLimit($service)) {
                return 0;
            }

            if (! $this->settings->continuousRenewals() && $this->billing->invoicer()->hasOpenInvoiceFor(BillableType::Service, $service->id)) {
                return 0;
            }

            /** @var CarbonImmutable $start */
            $start = $service->next_invoice_date;
            $end = BillingDates::periodEnd($service->cycle, $start, $service->billing_anchor_day);

            if ($this->billing->invoicer()->hasInvoiceForPeriod(BillableType::Service, $service->id, $start)) {
                $service->update(['next_invoice_date' => $end]);

                return 0;
            }

            $addons = $service->addons()
                ->whereIn('status', array_map(fn (ServiceStatus $status): string => $status->value, ServiceStatus::billable()))
                ->where('currency', $service->currency)
                ->whereDate('next_invoice_date', $start)
                ->lockForUpdate()
                ->get()
                ->filter(fn (ServiceAddon $addon): bool => $addon->cycle->isRecurring()
                    && ! $this->billing->invoicer()->hasInvoiceForPeriod(BillableType::ServiceAddon, $addon->id, $start));

            $lines = [new InvoiceLineData(
                $this->describe($service->product_name ?? 'Service', $service->domain, $start, $end),
                $service->recurring_amount,
                1,
                true,
                BillableType::Service,
                $service->id,
                $start,
                $end,
            )];

            $addonPeriods = [];

            foreach ($addons as $addon) {
                $addonEnd = BillingDates::periodEnd($addon->cycle, $start, $addon->billing_anchor_day);
                $addonPeriods[$addon->id] = $addonEnd;
                $lines[] = new InvoiceLineData($this->describe($addon->name, $service->domain, $start, $addonEnd), $addon->recurring_amount, 1, true, BillableType::ServiceAddon, $addon->id, $start, $addonEnd);
            }

            $result = $this->billing->invoicer()->issue(new InvoiceDraft(
                clientId: $service->client_id,
                currency: $service->currency,
                lines: $lines,
                dueDate: $start,
                issueDate: $today,
                paymentGateway: $service->payment_gateway,
            ));

            $this->links->record($service, $result->invoiceId, InvoiceLinkKind::Renewal, $start, $end, $service->recurring_amount, $this->linkStatus($result->status));
            $service->update(['next_invoice_date' => $end, 'renewals_invoiced' => $service->renewals_invoiced + 1]);

            foreach ($addons as $addon) {
                $this->links->record($addon, $result->invoiceId, InvoiceLinkKind::Renewal, $start, $addonPeriods[$addon->id], $addon->recurring_amount, $this->linkStatus($result->status));
                $addon->update(['next_invoice_date' => $addonPeriods[$addon->id]]);
            }

            return 1 + $addons->count();
        });
    }

    private function renewStandaloneAddon(int $addonId, CarbonImmutable $today): int
    {
        return DB::transaction(function () use ($addonId, $today): int {
            $addon = ServiceAddon::query()->with('service')->lockForUpdate()->find($addonId);

            if ($addon === null || $addon->next_invoice_date === null) {
                return 0;
            }

            $service = $addon->service;

            if ($service->do_not_renew || $service->terminate_at_period_end
                || $addon->next_invoice_date->subDays($this->settings->leadDays($addon->cycle))->gt($today)) {
                return 0;
            }

            if (! $this->settings->continuousRenewals() && $this->billing->invoicer()->hasOpenInvoiceFor(BillableType::ServiceAddon, $addon->id)) {
                return 0;
            }

            $start = $addon->next_invoice_date;
            $end = BillingDates::periodEnd($addon->cycle, $start, $addon->billing_anchor_day);

            if ($this->billing->invoicer()->hasInvoiceForPeriod(BillableType::ServiceAddon, $addon->id, $start)) {
                $addon->update(['next_invoice_date' => $end]);

                return 0;
            }

            $result = $this->billing->invoicer()->issue(new InvoiceDraft(
                clientId: $service->client_id,
                currency: $addon->currency,
                lines: [new InvoiceLineData($this->describe($addon->name, $service->domain, $start, $end), $addon->recurring_amount, 1, true, BillableType::ServiceAddon, $addon->id, $start, $end)],
                dueDate: $start,
                issueDate: $today,
                paymentGateway: $service->payment_gateway,
            ));

            $this->links->record($addon, $result->invoiceId, InvoiceLinkKind::Renewal, $start, $end, $addon->recurring_amount, $this->linkStatus($result->status));
            $addon->update(['next_invoice_date' => $end]);

            return 1;
        });
    }

    private function isDue(Service $service, CarbonImmutable $today): bool
    {
        return in_array($service->status, ServiceStatus::billable(), true)
            && $service->cycle->isRecurring()
            && ! $service->do_not_renew
            && ! $service->terminate_at_period_end
            && $service->next_invoice_date !== null
            && $service->next_invoice_date->subDays($this->settings->leadDays($service->cycle))->lte($today);
    }

    private function withinCycleLimit(Service $service): bool
    {
        return $service->recurring_cycles_limit === 0 || $service->renewals_invoiced < $service->recurring_cycles_limit;
    }

    private function linkStatus(string $invoiceStatus): InvoiceLinkStatus
    {
        return $invoiceStatus === 'paid' ? InvoiceLinkStatus::Paid : InvoiceLinkStatus::Open;
    }

    private function describe(string $name, ?string $domain, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return trim($name.($domain ? " - {$domain}" : '')).' ('.$start->toDateString().' - '.$end->subDay()->toDateString().')';
    }
}
