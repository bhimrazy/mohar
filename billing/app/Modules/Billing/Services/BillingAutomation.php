<?php

namespace App\Modules\Billing\Services;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Orders\PaymentGuard;
use App\Events\Billing\InvoiceOverdue;
use App\Events\Billing\InvoicePaymentReminder;
use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Actions\ManagePayMethods;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\InvoiceKind;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceFiscalReview;
use App\Modules\Billing\Support\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Daily billing tasks registered in the central automation pipeline (§7). Each is idempotent for its run date.
 */
class BillingAutomation
{
    public function __construct(
        private CompanyClock $clock,
        private BillingSettings $settings,
        private ApplyLateFee $lateFee,
        private CaptureInvoice $capture,
        private ManagePayMethods $payMethods,
        private GatewayManager $gateways,
        private CancelInvoice $cancel,
        private Quotes $quotes,
        private PaymentGuard $guard,
        private NoteRefusals $refusals,
    ) {}

    /**
     * 1. Late fees: once per invoice, days_overdue after the due date.
     */
    public function lateFees(CarbonImmutable $today): int
    {
        if (! ApplyLateFee::enabled($this->settings)) {
            return 0;
        }

        $cutoff = $today->subDays(max(0, $this->settings->int('late_fee.days_overdue', 7)));
        $count = 0;

        Invoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->where('kind', InvoiceKind::Standard)
            ->whereNull('late_fee_applied_at')
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<=', $cutoff->toDateString())
            ->chunkById(200, function (Collection $invoices) use (&$count): void {
                foreach ($invoices as $invoice) {
                    // One invoice that cannot take its late fee never stops the run: it is recorded for staff.
                    try {
                        $count += $this->lateFee->handle($invoice) ? 1 : 0;
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->refusals->record($invoice, InvoiceFiscalReview::LATE_FEE_REFUSED, $exception->getMessage());
                    }
                }
            });

        return $count;
    }

    /**
     * 2. Auto-capture with stored pay methods: days_before_due, retry_days after the first failure, one attempt a day.
     */
    public function autoCapture(CarbonImmutable $today): int
    {
        $window = $today->addDays(max(0, $this->settings->int('capture.days_before_due', 1)));
        $attempts = 0;

        Invoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<=', $window->toDateString())
            ->where(fn ($query) => $query->whereNull('last_capture_attempt_at')->orWhere('last_capture_attempt_at', '<', $this->clock->dayBounds($today)[0]))
            ->chunkById(200, function (Collection $invoices) use ($today, &$attempts): void {
                foreach ($invoices as $invoice) {
                    if (! $this->captureDue($invoice, $today) || $this->guard->refusalReason($invoice->id) !== null) {
                        continue;
                    }

                    $method = $this->payMethods->forCapture($invoice->client_id, $invoice->payment_gateway);

                    if ($method === null || ! $this->gateways->isActive($method->gateway)) {
                        continue;
                    }

                    try {
                        $this->capture->handle($invoice, $method->gateway, $method->token, $method, automated: true);
                        $attempts++;
                    } catch (BillingException $exception) {
                        report($exception);
                    }
                }
            });

        return $attempts;
    }

    /**
     * Retry schedule: first attempt any time in the window, then retry_days[n−1] days after the first failure.
     */
    public function captureDue(Invoice $invoice, CarbonImmutable $today): bool
    {
        if ($invoice->first_capture_failure_on === null) {
            return true;
        }

        $retryDays = $this->settings->intList('capture.retry_days');
        $failures = max(1, $invoice->capture_attempts);

        if ($failures > count($retryDays)) {
            return false;
        }

        return $invoice->first_capture_failure_on->addDays($retryDays[$failures - 1])->lte($today);
    }

    /**
     * 3. Overdue marking (InvoiceOverdue on the first overdue day) and reminders: before_due and overdue_1..n,
     * at most one per invoice per day, logged per rule so re-runs never double-send.
     */
    public function reminders(CarbonImmutable $today): int
    {
        $sent = 0;

        Invoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->whereNull('overdue_notified_at')
            ->whereDate('due_date', '<', $today->toDateString())
            ->chunkById(200, function (Collection $invoices) use ($today): void {
                foreach ($invoices as $invoice) {
                    $invoice->forceFill(['overdue_notified_at' => now()])->save();
                    InvoiceOverdue::dispatch($invoice->id, $invoice->client_id, $invoice->daysOverdue($today));
                }
            });

        $beforeDays = max(0, $this->settings->int('reminders.before_due_days', 7));
        $overdueDays = $this->settings->intList('reminders.overdue_days');
        sort($overdueDays);

        Invoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->where('balance', '>', 0)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->addDays($beforeDays)->toDateString())
            ->chunkById(200, function (Collection $invoices) use ($today, $beforeDays, $overdueDays, &$sent): void {
                foreach ($invoices as $invoice) {
                    $stage = $this->reminderStage($invoice, $today, $beforeDays, $overdueDays);

                    if ($stage === null) {
                        continue;
                    }

                    $invoice->reminder_log = [...($invoice->reminder_log ?? []), $stage => $today->toDateString()];
                    $invoice->save();
                    InvoicePaymentReminder::dispatch($invoice->id, $invoice->client_id, $stage);
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * @param  list<int>  $overdueDays
     */
    public function reminderStage(Invoice $invoice, CarbonImmutable $today, int $beforeDays, array $overdueDays): ?string
    {
        $log = $invoice->reminder_log ?? [];

        if (in_array($today->toDateString(), $log, true) || $invoice->due_date === null
            || ($invoice->last_capture_attempt_at !== null && $this->clock->localDate($invoice->last_capture_attempt_at) === $today->toDateString())) {
            return null;
        }

        if ($invoice->isOverdue($today)) {
            $days = $invoice->daysOverdue($today);
            $reached = array_keys(array_filter($overdueDays, fn (int $threshold): bool => $threshold > 0 && $threshold <= $days));

            if ($reached === []) {
                return null;
            }

            $stage = 'overdue_'.(max($reached) + 1);

            return isset($log[$stage]) ? null : $stage;
        }

        $untilDue = (int) $today->diffInDays($invoice->due_date, false);
        $remindOn = $invoice->due_date->subDays($beforeDays);
        $issuedBeforeWindow = $invoice->issue_date === null || $invoice->issue_date->lte($remindOn);

        if ($beforeDays > 0 && $untilDue <= $beforeDays && $issuedBeforeWindow && ! isset($log['before_due'])) {
            return 'before_due';
        }

        return null;
    }

    /**
     * 4. Auto-cancel overdue invoices that have no payments and no applied credit.
     */
    public function autoCancel(CarbonImmutable $today): int
    {
        $days = $this->settings->int('auto_cancel.days_overdue');

        if ($days <= 0) {
            return 0;
        }

        $count = 0;

        Invoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->where('amount_paid', 0)
            ->where('credit_applied', 0)
            ->whereDate('due_date', '<=', $today->subDays($days)->toDateString())
            ->chunkById(200, function (Collection $invoices) use ($days, &$count): void {
                foreach ($invoices as $invoice) {
                    if (! $invoice->transactions()->exists()) {
                        try {
                            $this->cancel->handle($invoice, note: __('Automatically cancelled after :days days overdue.', ['days' => $days]), strict: false);
                            $count++;
                        } catch (Throwable $exception) {
                            report($exception);
                            $this->refusals->record($invoice, InvoiceFiscalReview::CANCEL_REFUSED, $exception->getMessage());
                        }
                    }
                }
            });

        return $count;
    }

    /**
     * Housekeeping: expire quotes past their validity.
     */
    public function housekeeping(CarbonImmutable $today): int
    {
        $this->refusals->sweep();

        return $this->quotes->expire($today);
    }
}
