<?php

namespace App\Modules\PaymentsNepal\Drivers;

use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\ProvidesLogo;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\ConnectionCheck;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\GatewayLogo;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\ReturnHint;
use App\Modules\PaymentsNepal\Support\NprAmount;
use App\Modules\PaymentsNepal\Support\ProviderException;
use App\Modules\PaymentsNepal\Support\ProviderHttp;
use App\Support\Billing\AbstractGatewayDriver;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * eSewa ePay v2 (docs/product/payments-nepal.md §2.2, developer.esewa.com.np/pages/Epay).
 *
 * initiate() returns a form the customer's browser posts to eSewa, signed with HMAC-SHA256 over
 * total_amount,transaction_uuid,product_code. The success return carries base64 JSON signed over its own
 * signed_field_names; a valid signature only makes it a trustworthy hint. The status-check API, queried with our
 * stored amount, is what settles. eSewa has no refund API: refunds are made in the merchant portal and recorded.
 */
final class EsewaDriver extends AbstractGatewayDriver implements ConnectionTester, HostedPaymentDriver, ProvidesLogo
{
    public const KEY = 'esewa';

    /** Fields we sign on the request, in eSewa's mandatory order. */
    public const REQUEST_SIGNED_FIELDS = 'total_amount,transaction_uuid,product_code';

    /** Fields eSewa may sign on the success return; anything else in signed_field_names rejects the response. */
    private const RESPONSE_SIGNABLE = ['transaction_code', 'status', 'total_amount', 'transaction_uuid', 'product_code', 'signed_field_names'];

    /** Fields that must be covered by the response signature for it to count. */
    private const RESPONSE_REQUIRED = ['status', 'total_amount', 'transaction_uuid', 'product_code'];

    public function __construct(private ProviderHttp $http) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'eSewa';
    }

    /** eSewa's official developer-site logos (docs/product/payments-nepal.md §8.6), served from public/images/gateways. */
    public function logo(): GatewayLogo
    {
        return new GatewayLogo(asset('images/gateways/esewa.png'), __('Pay with eSewa'), darkUrl: asset('images/gateways/esewa-on-dark.png'));
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP];
    }

    public function currencies(): array
    {
        return ['NPR'];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'sandbox', 'label' => 'Sandbox mode', 'type' => 'boolean', 'default' => true,
                'help' => 'Uses eSewa UAT (rc-epay.esewa.com.np); no real money moves. Not available in production. "Test connection" books an unpaid Rs 10 eSewa session (in live mode too), which expires on its own.'],
            ['key' => 'product_code', 'label' => 'Merchant (product) code', 'type' => 'string', 'required' => true, 'default' => 'EPAYTEST',
                'help' => 'Provided by eSewa. UAT: EPAYTEST.'],
            ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'secret', 'required' => true,
                'help' => 'Provided by eSewa for signing. UAT: the public test key from eSewa\'s developer docs.'],
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        $min = config('payments_nepal.esewa.min_amount', 100);

        return new AmountLimits(min: is_numeric($min) ? (int) $min : 100);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        $minutes = config('payments_nepal.esewa.attempt_ttl_minutes', 15);

        return CarbonInterval::minutes(is_numeric($minutes) ? (int) $minutes : 15);
    }

    /**
     * The whole amount goes in total_amount = amount (tax and charges 0): tax is already part of our invoice, and
     * splitting it invites sum mismatches at eSewa.
     */
    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): FormPost
    {
        if ($intent->amount->currency !== 'NPR') {
            throw new ProviderException("eSewa only takes NPR, not {$intent->amount->currency}.");
        }

        [$productCode, $secret] = $this->credentials($gateway);
        $total = NprAmount::toRupees($intent->amount->amount);
        $fields = [
            'amount' => $total,
            'tax_amount' => '0',
            'product_service_charge' => '0',
            'product_delivery_charge' => '0',
            'total_amount' => $total,
            'transaction_uuid' => $intent->reference,
            'product_code' => $productCode,
            'success_url' => $intent->returnUrl,
            'failure_url' => $intent->cancelUrl,
            'signed_field_names' => self::REQUEST_SIGNED_FIELDS,
        ];
        $fields['signature'] = self::signature($fields, self::REQUEST_SIGNED_FIELDS, $secret);

        return new FormPost(ProviderHttp::endpoint($gateway, 'esewa.form'), $fields);
    }

    /**
     * Success: ?data=<base64 JSON>. Failure (also used for PENDING): our failure_url with ?ref=. Either way only a
     * hint; the return route always settles through lookup().
     */
    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $fallback = $request->query('ref');
        $fallback = is_string($fallback) && self::isReference($fallback) ? $fallback : null;
        $payload = self::decodeData($request->query('data'));

        if ($payload === null) {
            return new ReturnHint($fallback, raw: ['data' => $request->has('data') ? 'unreadable' : 'none']);
        }

        $valid = $this->responseSignatureValid($gateway, $payload);
        $uuid = is_string($payload['transaction_uuid'] ?? null) && self::isReference($payload['transaction_uuid']) ? $payload['transaction_uuid'] : null;
        $raw = array_intersect_key($payload, array_flip(['transaction_code', 'status', 'total_amount', 'transaction_uuid', 'product_code']));

        return new ReturnHint(
            reference: $valid ? $uuid : ($fallback ?? $uuid),
            providerRef: is_string($payload['transaction_code'] ?? null) ? mb_substr($payload['transaction_code'], 0, 64) : null,
            claimedStatus: is_string($payload['status'] ?? null) ? mb_substr($payload['status'], 0, 40) : null,
            signatureValid: $valid,
            raw: [...$raw, 'signature_valid' => $valid],
        );
    }

    /**
     * Status check with our stored amount. eSewa matches on the amount too (a different amount answers
     * NOT_FOUND) and echoes it back, so the echoed amount and identifiers are still compared.
     */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        try {
            [$productCode] = $this->credentials($gateway);
            $response = $this->http->lookup($attempt->interactive)->get(ProviderHttp::endpoint($gateway, 'esewa.status'), [
                'product_code' => $productCode,
                'total_amount' => NprAmount::toRupees($attempt->amount->amount),
                'transaction_uuid' => $attempt->reference,
            ]);
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => 'eSewa unreachable'], retryable: true);
        } catch (ProviderException $exception) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => $exception->getMessage()], retryable: true);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = is_string($body['status'] ?? null) ? strtoupper(trim($body['status'])) : null;
        $amount = NprAmount::toPaisa($body['total_amount'] ?? null);
        $raw = array_filter([
            'http_status' => $response->status(),
            'status' => $status,
            'total_amount' => is_scalar($body['total_amount'] ?? null) ? $body['total_amount'] : null,
            'transaction_uuid' => is_string($body['transaction_uuid'] ?? null) ? $body['transaction_uuid'] : null,
            'product_code' => is_string($body['product_code'] ?? null) ? $body['product_code'] : null,
            'ref_id' => is_string($body['ref_id'] ?? null) ? $body['ref_id'] : null,
            'error' => $status === null ? ProviderHttp::summary($body) : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        // eSewa's status API is unauthenticated, so a refusal means it no longer accepts our requests: staff review.
        if ($status === null && in_array($response->status(), [401, 403], true)) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'eSewa refused the status check (HTTP '.$response->status().')']);
        }

        // {"code": 0, "error_message": "Service is currently unavailable"}, 5xx or an unreadable body: transient.
        if ($status === null) {
            return new LookupResult(LookupResult::PENDING, raw: $raw, retryable: $response->serverError() || array_key_exists('code', $body) || $body === []);
        }

        if (($raw['transaction_uuid'] ?? null) !== $attempt->reference || ($raw['product_code'] ?? null) !== $productCode) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'status answered for another transaction']);
        }

        $mapped = match ($status) {
            'COMPLETE' => LookupResult::SUCCEEDED,
            'PENDING' => LookupResult::PENDING,
            // "Payment is at halt state": never auto-failed; Billing keeps polling and alerts staff.
            'AMBIGUOUS' => LookupResult::AMBIGUOUS,
            // Session expired, or never booked: Billing expires it after the attempt TTL plus grace.
            'NOT_FOUND' => LookupResult::NOT_FOUND,
            'CANCELED', 'CANCELLED' => LookupResult::CANCELLED,
            'FULL_REFUND' => LookupResult::REFUNDED,
            'PARTIAL_REFUND' => LookupResult::PARTIALLY_REFUNDED,
            default => LookupResult::AMBIGUOUS,
        };

        return new LookupResult(
            status: $mapped,
            providerTxnId: $raw['ref_id'] ?? null,
            amount: $amount,
            currency: 'NPR',
            raw: $raw,
        );
    }

    /**
     * eSewa's status API is unauthenticated and answers NOT_FOUND even for unknown merchant codes, so the check
     * books (does not pay) a signed test session: eSewa answers ES104 "Invalid payload signature" when the secret
     * or product code is wrong, and redirects to its login page when both are right. The booking simply expires.
     */
    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        try {
            $mode = ProviderHttp::mode($gateway);
            [$productCode, $secret] = $this->credentials($gateway);
            $action = ProviderHttp::endpoint($gateway, 'esewa.form');
        } catch (ProviderException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        }

        $fields = [
            'amount' => '10', 'tax_amount' => '0', 'product_service_charge' => '0', 'product_delivery_charge' => '0',
            'total_amount' => '10', 'transaction_uuid' => 'mohar-test-'.Str::lower(Str::random(12)), 'product_code' => $productCode,
            'success_url' => (string) config('app.url'), 'failure_url' => (string) config('app.url'),
            'signed_field_names' => self::REQUEST_SIGNED_FIELDS,
        ];
        $fields['signature'] = self::signature($fields, self::REQUEST_SIGNED_FIELDS, $secret);

        try {
            $response = $this->http->request()->asForm()->withoutRedirecting()->post($action, $fields);
        } catch (ConnectionException) {
            return new ConnectionCheck(false, __('Couldn\'t reach eSewa (:mode). Check the server\'s outbound connection.', ['mode' => $mode]));
        }

        $location = (string) $response->header('Location');

        return match (true) {
            $response->redirect() && str_contains((string) parse_url($location, PHP_URL_HOST), 'esewa.com.np') => new ConnectionCheck(true, __('eSewa accepted the product code and secret key (:mode mode).', ['mode' => $mode])),
            $response->status() === 400 => new ConnectionCheck(false, __('eSewa rejected the product code or secret key (:mode mode): :reason', ['mode' => $mode, 'reason' => ProviderHttp::summary($response->json()) ?: 'HTTP 400'])),
            default => new ConnectionCheck(false, __('eSewa answered with HTTP :status. Try again later.', ['status' => $response->status()])),
        };
    }

    /**
     * Base64 HMAC-SHA256 over "name=value" pairs joined by "," in the order of $signedFieldNames, using the
     * values exactly as sent or received (e.g. "1,130.0" with its separator).
     *
     * @param  array<string, string>  $values
     */
    public static function signature(array $values, string $signedFieldNames, string $secret): string
    {
        $message = implode(',', array_map(fn (string $name): string => $name.'='.($values[$name] ?? ''), explode(',', $signedFieldNames)));

        return base64_encode(hash_hmac('sha256', $message, $secret, true));
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function responseSignatureValid(GatewayConfig $gateway, array $payload): bool
    {
        $names = $payload['signed_field_names'] ?? '';
        $signature = $payload['signature'] ?? '';
        $fields = $names === '' ? [] : explode(',', $names);

        if ($fields === [] || $signature === '' || count($fields) !== count(array_unique($fields))
            || array_diff($fields, self::RESPONSE_SIGNABLE) !== [] || array_diff(self::RESPONSE_REQUIRED, $fields) !== []) {
            return false;
        }

        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload)) {
                return false;
            }
        }

        try {
            [$productCode, $secret] = $this->credentials($gateway);
        } catch (ProviderException) {
            return false;
        }

        return hash_equals(self::signature($payload, $names, $secret), $signature) && $payload['product_code'] === $productCode;
    }

    /**
     * eSewa's base64 JSON. Query strings may turn "+" into spaces, which is undone first. Only string values are
     * kept (they are signed exactly as received).
     *
     * @return array<string, string>|null
     */
    private static function decodeData(mixed $data): ?array
    {
        if (! is_string($data) || $data === '' || strlen($data) > 4096) {
            return null;
        }

        $json = base64_decode(strtr(str_replace(' ', '+', $data), '-_', '+/'), true);
        $payload = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($payload)) {
            return null;
        }

        return array_filter($payload, fn (mixed $value, int|string $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return array{0: string, 1: string} product code and secret key
     */
    private function credentials(GatewayConfig $gateway): array
    {
        $productCode = $gateway->setting('product_code', '');
        $secret = $gateway->setting('secret_key', '');

        if (! is_string($productCode) || trim($productCode) === '' || ! is_string($secret) || trim($secret) === '') {
            throw new ProviderException(__('Enter and save the eSewa product code and secret key first.'));
        }

        return [trim($productCode), trim($secret)];
    }

    private static function isReference(string $value): bool
    {
        return preg_match('/^[a-z0-9-]{3,40}$/', $value) === 1;
    }
}
