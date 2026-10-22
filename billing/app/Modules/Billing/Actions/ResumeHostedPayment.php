<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\ResumesHostedPayment;
use App\Data\Billing\LookupResult;
use App\Modules\Billing\Data\ResumeOutcome;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Support\PaymentInProgress;

/**
 * "Continue payment": back to the provider page of a hosted payment that is still open, e.g. after a declined card on
 * Stripe Checkout (#123) or a closed Khalti tab (#251). The driver confirms with the provider now. A URL the driver
 * asked Billing to keep (Khalti) is decrypted for this call only; no URL is ever logged. Billing accepts only an https
 * URL or one on this site (the sandbox page), on top of the driver's own check of the provider's host, and only while
 * the invoice still has something to pay.
 *
 * When the page is not reopened, the attempt is settled from one lookup and the customer is told what happens next.
 * A driver may answer that lookup from the one it just made in resumeUrl() (Khalti does), so a click costs one
 * provider call.
 */
class ResumeHostedPayment
{
    public function __construct(
        private GatewayManager $gateways,
        private GatewayLogger $log,
        private SettlePaymentAttempt $settle,
        private CloseHostedPayment $close,
    ) {}

    public function handle(PaymentAttempt $attempt): ResumeOutcome
    {
        $driver = $this->gateways->has($attempt->gateway) ? $this->gateways->driver($attempt->gateway) : null;
        $url = null;

        if (self::payable($attempt) && PaymentInProgress::closable($attempt) && $driver instanceof ResumesHostedPayment) {
            $url = $driver->resumeUrl($this->gateways->config($attempt->gateway), $attempt->toRef(interactive: true, withResumeUrl: true));
            // Re-checked after the provider call: staff may have cancelled the invoice, or another payment cleared it.
            $url = $url !== null && self::acceptable($url) && self::payable($attempt) ? $url : null;
        }

        if ($url !== null) {
            $this->log->log($attempt->gateway, 'request', 'hosted payment reopened', ['reference' => $attempt->reference], $attempt->invoice_id);

            return new ResumeOutcome($attempt->status, url: $url);
        }

        $status = PaymentInProgress::closable($attempt) ? $this->settle->handle($attempt, interactive: true) : $attempt->status;
        [$reason, $error] = $status->isOpen() ? $this->stillOpen($attempt, $status) : ['closed', null];
        $this->log->log($attempt->gateway, 'request', 'hosted payment not reopened', ['reference' => $attempt->reference, 'reason' => $reason, 'status' => $status->value], $attempt->invoice_id);

        return new ResumeOutcome($status, error: $error);
    }

    /**
     * Why an attempt that is still open wasn't reopened, from the lookup just made, and the next step in plain words.
     * "Use another method" is offered only where that button exists (CloseHostedPayment::supports()).
     *
     * @return array{0: string, 1: string}
     */
    private function stillOpen(PaymentAttempt $attempt, AttemptStatus $status): array
    {
        $answer = $this->settle->lastResult;

        return match (true) {
            ! self::payable($attempt) => ['invoice_not_payable', __('This invoice has nothing left to pay, so the payment page wasn\'t reopened. If you were charged, contact us.')],
            $answer === null || $answer->retryable => ['unreachable', $this->close->supports($attempt)
                ? __('We couldn\'t reach the payment provider. Try again in a few minutes, or use another method.')
                : __('We couldn\'t reach the payment provider. Try again in a few minutes.')],
            $status === AttemptStatus::Ambiguous => ['review', __('This payment hasn\'t been confirmed yet. If you were charged, you don\'t need to pay again.')],
            $answer->status === LookupResult::PENDING, $answer->status === LookupResult::PROCESSING => ['confirming', __('Your payment is being confirmed. This invoice updates once it is, so you don\'t need to pay again.')],
            default => ['not_reopened', __('This payment can\'t be reopened. Check its status in a few minutes.')],
        };
    }

    /**
     * The attempt's invoice, read again now, still has a balance to pay.
     *
     * @phpstan-impure
     */
    private static function payable(PaymentAttempt $attempt): bool
    {
        if ($attempt->invoice_id === null) {
            return true;
        }

        $invoice = Invoice::query()->find($attempt->invoice_id);

        return $invoice !== null && $invoice->status->isPayable() && $invoice->balance > 0;
    }

    private static function acceptable(string $url): bool
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        $site = parse_url((string) config('app.url'));

        if ($parts === false || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return ($parts['scheme'] ?? null) === 'https'
            || (is_array($site) && ($parts['scheme'] ?? null) === ($site['scheme'] ?? null) && strtolower($parts['host']) === strtolower((string) ($site['host'] ?? '')) && ($parts['port'] ?? null) === ($site['port'] ?? null));
    }
}
