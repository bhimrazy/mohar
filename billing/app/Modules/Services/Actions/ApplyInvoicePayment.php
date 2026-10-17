<?php

namespace App\Modules\Services\Actions;

use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Services\ServiceRenewed;
use App\Modules\Services\Enums\InvoiceLinkKind;
use App\Modules\Services\Enums\InvoiceLinkStatus;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceAddon;
use App\Modules\Services\Models\ServiceInvoice;
use App\Modules\Services\Services\AddonLifecycle;
use App\Modules\Services\Services\InvoiceLinks;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Services\OverduePolicy;
use App\Modules\Services\Support\BillingDates;
use App\Modules\Services\Support\Catalog;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reacts to a paid invoice (services.md §4.4): marks the billed periods paid, recomputes
 * next_due_date (idempotent on replay), provisions pending services per the product's auto-setup
 * policy, lifts overdue suspensions and applies paid upgrades. ServiceSetup lines are ignored.
 */
class ApplyInvoicePayment
{
    /** Suspension reasons that a payment may lift automatically. */
    public const PAYMENT_REASONS = ['overdue', 'addon_overdue'];

    public function __construct(
        private InvoiceLinks $links,
        private ModuleCommandDispatcher $commands,
        private OverduePolicy $overdue,
        private ServiceSettings $settings,
        private Catalog $catalog,
        private AddonLifecycle $addons,
        private ApplyServiceChange $applyChange,
    ) {}

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    public function handle(int $invoiceId, array $lines): void
    {
        foreach ($this->netAmounts($lines) as $index => $net) {
            $line = $lines[$index];

            if ($line->billableId === null) {
                continue;
            }

            match ($line->billableType) {
                BillableType::Service => $this->applyServiceLine($invoiceId, $line, $line->billableId, $net),
                BillableType::ServiceAddon => $this->applyAddonLine($invoiceId, $line, $line->billableId, $net),
                BillableType::ServiceUpgrade => $this->applyChange->handle($line->billableId),
                default => null,
            };
        }
    }

    /**
     * Net amount per billable line: Promotion lines belong to the closest preceding Service or
     * ServiceAddon line (Orders and renewals emit item → setup? → promotion), so the stored period
     * amount is what the client actually paid (used for downgrade credits).
     *
     * @param  list<InvoiceLineData>  $lines
     * @return array<int, int> line index => net amount
     */
    private function netAmounts(array $lines): array
    {
        $net = [];
        $owner = null;

        foreach ($lines as $index => $line) {
            if (in_array($line->billableType, [BillableType::Service, BillableType::ServiceAddon], true)) {
                $owner = $index;
                $net[$index] = $line->total();
            } elseif ($line->billableType === BillableType::Promotion && $owner !== null) {
                $net[$owner] = max(0, $net[$owner] + $line->total());
            } elseif ($line->billableType === BillableType::ServiceUpgrade) {
                $net[$index] = $line->total();
            }
        }

        return $net;
    }

    /**
     * Whether this invoice was already applied to the item (replays of lines without a period must
     * not re-derive a period from the moved due date).
     */
    private function alreadyApplied(Service|ServiceAddon $item, int $invoiceId, InvoiceLineData $line): bool
    {
        return $line->periodStart === null && ServiceInvoice::query()
            ->where($item instanceof Service ? 'service_id' : 'service_addon_id', $item->id)
            ->where('invoice_id', $invoiceId)
            ->where('status', InvoiceLinkStatus::Paid->value)
            ->exists();
    }

    private function applyServiceLine(int $invoiceId, InvoiceLineData $line, int $serviceId, int $net): void
    {
        $result = DB::transaction(function () use ($invoiceId, $line, $serviceId, $net): ?array {
            $service = Service::query()->lockForUpdate()->find($serviceId);

            if ($service === null || $this->alreadyApplied($service, $invoiceId, $line)) {
                return null;
            }

            $wasPending = $service->status === ServiceStatus::Pending;
            [$start, $end] = $this->period($service, $line, $wasPending);
            $this->links->record($service, $invoiceId, $wasPending ? InvoiceLinkKind::Order : InvoiceLinkKind::Renewal, $start, $end, $net, InvoiceLinkStatus::Paid);

            $renewed = false;

            if ($service->cycle->isRecurring() && $service->next_due_date !== null) {
                $paidThrough = BillingDates::paidThrough($service->next_due_date, ServiceInvoice::query()->where('service_id', $service->id)->get());

                if ($paidThrough->gt($service->next_due_date)) {
                    $service->next_due_date = $paidThrough;
                    if ($service->next_invoice_date === null || $service->next_invoice_date->lt($paidThrough)) {
                        $service->next_invoice_date = $paidThrough;
                    }
                    $service->save();
                    $renewed = ! $wasPending;
                }
            }

            return ['service' => $service, 'pending' => $wasPending, 'renewed' => $renewed];
        });

        if ($result === null) {
            return;
        }

        /** @var Service $service */
        $service = $result['service'];

        if ($result['renewed'] && $service->next_due_date !== null) {
            ServiceRenewed::dispatch($service->id, $service->client_id, $service->next_due_date->toDateString());
        }

        if ($result['pending'] && $this->catalog->autoSetup($service->product_id) === 'payment') {
            $this->commands->queue($service, ModuleCommandName::Create);
        }

        $this->liftPaymentSuspension($service);
    }

    private function applyAddonLine(int $invoiceId, InvoiceLineData $line, int $addonId, int $net): void
    {
        $addon = DB::transaction(function () use ($invoiceId, $line, $addonId, $net): ?ServiceAddon {
            $addon = ServiceAddon::query()->lockForUpdate()->find($addonId);

            if ($addon === null || $this->alreadyApplied($addon, $invoiceId, $line)) {
                return null;
            }

            $wasPending = $addon->status === ServiceStatus::Pending;
            [$start, $end] = $this->period($addon, $line, $wasPending);
            $this->links->record($addon, $invoiceId, $wasPending ? InvoiceLinkKind::Order : InvoiceLinkKind::Renewal, $start, $end, $net, InvoiceLinkStatus::Paid);

            if ($addon->cycle->isRecurring() && $addon->next_due_date !== null) {
                $paidThrough = BillingDates::paidThrough($addon->next_due_date, ServiceInvoice::query()->where('service_addon_id', $addon->id)->get());

                if ($paidThrough->gt($addon->next_due_date)) {
                    $addon->next_due_date = $paidThrough;
                    if ($addon->next_invoice_date === null || $addon->next_invoice_date->lt($paidThrough)) {
                        $addon->next_invoice_date = $paidThrough;
                    }
                    $addon->save();
                }
            }

            if ($wasPending) {
                $this->addons->transition($addon, ServiceStatus::Active);
            }

            if ($addon->status === ServiceStatus::Suspended && in_array($addon->suspension_reason, self::PAYMENT_REASONS, true)
                && $this->settings->unsuspendOnPayment() && $this->overdue->daysOverdue($addon, CarbonImmutable::today()) === null) {
                $this->addons->transition($addon, ServiceStatus::Active);
            }

            return $addon;
        });

        if ($addon !== null) {
            $this->liftPaymentSuspension($addon->service->refresh());
        }
    }

    /**
     * Unsuspend a service suspended for non-payment once it no longer matches the suspension rule.
     * Staff suspensions are never lifted automatically.
     */
    private function liftPaymentSuspension(Service $service): void
    {
        if ($service->status !== ServiceStatus::Suspended
            || ! in_array($service->suspension_reason, self::PAYMENT_REASONS, true)
            || ! $this->settings->unsuspendOnPayment()) {
            return;
        }

        $today = CarbonImmutable::today();

        if ($this->overdue->daysOverdue($service, $today) !== null) {
            return;
        }

        $overdueAddon = $service->addons()
            ->where('suspend_parent_on_overdue', true)
            ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
            ->get()
            ->contains(fn (ServiceAddon $addon): bool => $this->overdue->daysOverdue($addon, $today) !== null);

        if (! $overdueAddon) {
            $this->commands->queue($service, ModuleCommandName::Unsuspend);
        }
    }

    /**
     * Billed period of the line; lines without a period default to the item's first unpaid period.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function period(Service|ServiceAddon $item, InvoiceLineData $line, bool $initial): array
    {
        if ($line->periodStart !== null) {
            return [$line->periodStart, $line->periodEnd ?? ($item->cycle->isRecurring() ? BillingDates::periodEnd($item->cycle, $line->periodStart, $item->billing_anchor_day) : null)];
        }

        if ($item->next_due_date === null) {
            return [null, null];
        }

        $start = $initial ? $item->registration_date : $item->next_due_date;
        $end = $initial && $item->next_invoice_date !== null ? $item->next_invoice_date : BillingDates::periodEnd($item->cycle, $start, $item->billing_anchor_day);

        return [$start, $end];
    }
}
