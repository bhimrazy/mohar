<?php

namespace App\Modules\PaymentsNepal\Drivers;

use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\ProvidesLogo;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\ConnectionCheck;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\GatewayLogo;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\RefundRequest;
use App\Data\Billing\ReturnHint;
use App\Modules\PaymentsNepal\Support\ProviderException;
use App\Modules\PaymentsNepal\Support\ProviderHttp;
use App\Support\Billing\AbstractGatewayDriver;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Khalti KPG-2 web checkout (docs/product/payments-nepal.md §2.1, docs.khalti.com/khalti-epayment).
 *
 * initiate() asks Khalti for a payment session (pidx) and sends the customer to its payment_url. Khalti's
 * browser return is unsigned, so it is a hint only: lookup() of the pidx stored on the attempt is the only
 * source of truth, and only "Completed" counts as paid. Refunds (full or partial) go through Khalti's API.
 */
final class KhaltiDriver extends AbstractGatewayDriver implements ConnectionTester, HostedPaymentDriver, ProvidesLogo
{
    public const KEY = 'khalti';

    public function __construct(private ProviderHttp $http) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Khalti';
    }

    /** Khalti's official docs logo (docs/product/payments-nepal.md §8.6), served from public/images/gateways. */
    public function logo(): GatewayLogo
    {
        return new GatewayLogo(asset('images/gateways/khalti.svg'), __('Pay with Khalti'));
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP, self::REFUND, self::PARTIAL_REFUND];
    }

    public function currencies(): array
    {
        return ['NPR'];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'sandbox', 'label' => 'Sandbox mode', 'type' => 'boolean', 'default' => true,
                'help' => 'Uses dev.khalti.com with the key from test-admin.khalti.com; no real money moves. Not available in production.'],
            ['key' => 'secret_key', 'label' => 'Live secret key', 'type' => 'secret', 'required' => true,
                'help' => 'Merchant dashboard → Keys → "Live secret key" (admin.khalti.com, or test-admin.khalti.com in sandbox mode).'],
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: self::intConfig('khalti.min_amount', 1000));
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(self::intConfig('khalti.attempt_ttl_minutes', 60));
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction
    {
        if ($intent->amount->currency !== 'NPR') {
            throw new ProviderException("Khalti only takes NPR, not {$intent->amount->currency}.");
        }

        $customer = array_filter([
            'name' => $intent->customerName !== null ? mb_substr($intent->customerName, 0, 100) : null,
            'email' => $intent->customerEmail,
            'phone' => $intent->customerPhone,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $response = $this->client($gateway)->post(ProviderHttp::endpoint($gateway, 'khalti.api').'epayment/initiate/', array_filter([
            'return_url' => $intent->returnUrl,
            'website_url' => (string) config('app.url'),
            'amount' => $intent->amount->amount,
            'purchase_order_id' => $intent->reference,
            'purchase_order_name' => mb_substr($intent->description, 0, 100) ?: $intent->reference,
            'customer_info' => $customer !== [] ? $customer : null,
        ], fn (mixed $value): bool => $value !== null));

        $pidx = $response->json('pidx');
        $paymentUrl = $response->json('payment_url');

        if (! $response->successful() || ! is_string($pidx) || ! self::isProviderRef($pidx) || ! is_string($paymentUrl) || ! $this->isKhaltiUrl($paymentUrl)) {
            throw new ProviderException('Khalti initiate failed (HTTP '.$response->status().') '.ProviderHttp::summary($response->json()));
        }

        return new RedirectInstruction($paymentUrl, $pidx);
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $reference = $request->query('purchase_order_id', $request->query('ref'));
        $pidx = $request->query('pidx');
        $status = $request->query('status');

        return new ReturnHint(
            reference: is_string($reference) && preg_match('/^[a-z0-9-]{3,40}$/', $reference) === 1 ? $reference : null,
            providerRef: is_string($pidx) && self::isProviderRef($pidx) ? $pidx : null,
            claimedStatus: is_string($status) ? mb_substr($status, 0, 40) : null,
            signatureValid: false,
            raw: array_filter([
                'pidx' => is_string($pidx) ? mb_substr($pidx, 0, 64) : null,
                'status' => is_string($status) ? mb_substr($status, 0, 40) : null,
                'transaction_id' => is_string($request->query('transaction_id')) ? mb_substr((string) $request->query('transaction_id'), 0, 64) : null,
                'total_amount' => is_string($request->query('total_amount')) ? mb_substr((string) $request->query('total_amount'), 0, 20) : null,
                'mobile' => ProviderHttp::maskMobile($request->query('mobile')),
            ], fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * Looks up the pidx stored on the attempt (never one from the browser). Expired and User canceled come back
     * as HTTP 400 with a normal body, so the body is read before the status code.
     */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        if ($attempt->providerRef === null || ! self::isProviderRef($attempt->providerRef)) {
            return new LookupResult(LookupResult::NOT_FOUND, raw: ['error' => 'no pidx stored for this attempt']);
        }

        try {
            $response = $this->http->lookup()
                ->withHeaders(['Authorization' => 'Key '.self::secret($gateway)])
                ->asJson()
                ->post(ProviderHttp::endpoint($gateway, 'khalti.api').'epayment/lookup/', ['pidx' => $attempt->providerRef]);
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => 'Khalti unreachable'], retryable: true);
        } catch (ProviderException $exception) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => $exception->getMessage()], retryable: true);
        }

        return $this->lookupResult($response, $attempt->providerRef);
    }

    /**
     * Full refund with an empty body, partial with the amount in paisa. Khalti returns no refund id, so the
     * refund transaction keeps no gateway reference. Bank-funded payments also need the payer's mobile number,
     * which we never store in full: those refunds fail here and are made in the Khalti portal, then recorded.
     */
    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        if ($request->amount->currency !== 'NPR' || $request->amount->amount <= 0) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'Khalti refunds must be a positive NPR amount');
        }

        $url = ProviderHttp::endpoint($gateway, 'khalti.refund').rawurlencode($request->providerTxnId).'/refund/';

        try {
            $response = $this->client($gateway)->post($url, $request->isPartial() ? ['amount' => $request->amount->amount] : []);
        } catch (ConnectionException) {
            // The refund may still have gone through: staff must check the portal before retrying.
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'no answer from Khalti; check the Khalti portal before retrying', raw: ['transaction_id' => $request->providerTxnId]);
        }

        $raw = ['transaction_id' => $request->providerTxnId, 'amount' => $request->amount->amount, 'partial' => $request->isPartial(), 'http_status' => $response->status(), 'detail' => ProviderHttp::summary($response->json())];

        if (! $response->successful()) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: mb_substr(ProviderHttp::summary($response->json()) ?: 'HTTP '.$response->status(), 0, 150), raw: $raw);
        }

        return new ChargeResult(ChargeResult::SUCCESS, raw: $raw);
    }

    /**
     * A lookup of a made-up pidx: Khalti answers 401 for a rejected key and "Not found." for an accepted one.
     */
    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        try {
            $mode = ProviderHttp::mode($gateway);
        } catch (ProviderException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        }

        if (self::secret($gateway) === '') {
            return new ConnectionCheck(false, __('Enter and save the secret key first.'));
        }

        try {
            $response = $this->client($gateway)->post(ProviderHttp::endpoint($gateway, 'khalti.api').'epayment/lookup/', ['pidx' => 'MoharConnectionTest']);
        } catch (ConnectionException) {
            return new ConnectionCheck(false, __('Couldn\'t reach Khalti (:mode). Check the server\'s outbound connection.', ['mode' => $mode]));
        }

        return match (true) {
            $response->status() === 401 || $response->status() === 403 => new ConnectionCheck(false, __('Khalti rejected the secret key (:mode mode). Use the key for this mode.', ['mode' => $mode])),
            in_array($response->status(), [200, 400, 404], true) => new ConnectionCheck(true, __('Khalti accepted the secret key (:mode mode).', ['mode' => $mode])),
            default => new ConnectionCheck(false, __('Khalti answered with HTTP :status. Try again later.', ['status' => $response->status()])),
        };
    }

    private function lookupResult(Response $response, string $pidx): LookupResult
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = is_string($body['status'] ?? null) ? $body['status'] : null;
        $raw = array_filter([
            'http_status' => $response->status(),
            'pidx' => is_string($body['pidx'] ?? null) ? $body['pidx'] : null,
            'status' => $status,
            'total_amount' => is_int($body['total_amount'] ?? null) ? $body['total_amount'] : null,
            'transaction_id' => is_string($body['transaction_id'] ?? null) ? $body['transaction_id'] : null,
            'fee' => is_int($body['fee'] ?? null) ? $body['fee'] : null,
            'refunded' => is_bool($body['refunded'] ?? null) ? $body['refunded'] : null,
            'error' => $status === null ? ProviderHttp::summary($body) : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ($status === null) {
            return match (true) {
                $response->status() === 404 => new LookupResult(LookupResult::NOT_FOUND, raw: $raw),
                // Server trouble, or a key problem staff must fix: keep the attempt open and try again later.
                $response->serverError(), in_array($response->status(), [401, 403, 429], true) => new LookupResult(LookupResult::PENDING, raw: $raw, retryable: true),
                default => new LookupResult(LookupResult::AMBIGUOUS, raw: $raw),
            };
        }

        if (isset($raw['pidx']) && $raw['pidx'] !== $pidx) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'lookup answered for another pidx']);
        }

        $mapped = match (mb_strtolower(trim($status))) {
            'completed' => LookupResult::SUCCEEDED,
            'pending', 'initiated' => LookupResult::PENDING,
            'expired' => LookupResult::EXPIRED,
            'user canceled', 'user cancelled', 'canceled', 'cancelled', 'failed' => LookupResult::CANCELLED,
            'refunded' => LookupResult::REFUNDED,
            'partially refunded' => LookupResult::PARTIALLY_REFUNDED,
            // Khalti: "for any other status, hold the transaction and contact Khalti".
            default => LookupResult::AMBIGUOUS,
        };

        return new LookupResult(
            status: $mapped,
            providerTxnId: $raw['transaction_id'] ?? null,
            amount: $raw['total_amount'] ?? null,
            currency: 'NPR',
            fee: $mapped === LookupResult::SUCCEEDED ? max(0, $raw['fee'] ?? 0) : 0,
            raw: $raw,
        );
    }

    private function client(GatewayConfig $gateway): PendingRequest
    {
        return $this->http->request()->asJson()->withHeaders(['Authorization' => 'Key '.self::secret($gateway)]);
    }

    private function isKhaltiUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $domain = (string) config('payments_nepal.khalti.payment_domain', 'khalti.com');

        return ($parts['scheme'] ?? null) === 'https' && ($host === $domain || str_ends_with($host, '.'.$domain));
    }

    private static function isProviderRef(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1;
    }

    private static function secret(GatewayConfig $gateway): string
    {
        $secret = $gateway->setting('secret_key', '');

        return is_string($secret) ? trim($secret) : '';
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config('payments_nepal.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
