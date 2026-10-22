<?php

namespace App\Modules\Billing\Actions;

use App\Contracts\Billing\ResumesHostedPayment;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Billing\Support\PaymentInProgress;

/**
 * Where the customer can finish a hosted payment that is still open, e.g. after a declined card on Stripe Checkout
 * (#123). The provider is asked now; the URL is never stored or logged. Billing accepts only an https URL or one on
 * this site (the sandbox page), on top of the driver's own check of the provider's host.
 */
class ResumeHostedPayment
{
    public function __construct(private GatewayManager $gateways, private GatewayLogger $log) {}

    public function handle(PaymentAttempt $attempt): ?string
    {
        $driver = $this->gateways->has($attempt->gateway) ? $this->gateways->driver($attempt->gateway) : null;

        if (! PaymentInProgress::closable($attempt) || ! $driver instanceof ResumesHostedPayment) {
            return null;
        }

        $url = $driver->resumeUrl($this->gateways->config($attempt->gateway), $attempt->toRef(interactive: true));
        $url = $url !== null && self::acceptable($url) ? $url : null;
        $this->log->log($attempt->gateway, 'request', $url !== null ? 'hosted payment reopened' : 'hosted payment not reopened', ['reference' => $attempt->reference], $attempt->invoice_id);

        return $url;
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
