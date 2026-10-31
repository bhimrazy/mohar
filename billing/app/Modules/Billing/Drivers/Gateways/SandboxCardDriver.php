<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Contracts\Billing\CancelsHostedPayment;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\ResumesHostedPayment;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Data\Billing\CardToken;
use App\Data\Billing\ChargeRequest;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\QrPayload;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\RefundRequest;
use App\Data\Billing\ReturnHint;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Enums\HostedSessionCancel;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Billing\SandboxPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Simulated card gateway for development and tests — no network. Two flows:
 *
 *  - Merchant/token: the "vault" (remote input) turns card details into a signed, self-describing token; capture
 *    and refund are simulated. Webhooks are HMAC-SHA256 signed (header X-Sandbox-Signature).
 *  - Hosted (HostedPaymentDriver): initiate → simulated provider page → browser return (hint only) → lookup()
 *    against the simulated provider's state, exactly like Khalti/eSewa. An unpaid page can be reopened or closed
 *    (ResumesHostedPayment, CancelsHostedPayment), as at Stripe.
 *
 * Outcomes are deterministic by card number:
 *   4242 4242 4242 4242 success · 4000 0000 0000 0002 declined · 4000 0000 0000 0077 pending · 4000 0000 0000 0119 error
 */
class SandboxCardDriver extends AbstractGatewayDriver implements CancelsHostedPayment, HostedPaymentDriver, ResumesHostedPayment
{
    public const SIGNATURE_HEADER = 'X-Sandbox-Signature';

    public const TIMESTAMP_HEADER = 'X-Sandbox-Timestamp';

    public const OUTCOMES = [
        '4000000000000002' => ChargeResult::DECLINED,
        '4000000000000077' => ChargeResult::PENDING,
        '4000000000000119' => ChargeResult::ERROR,
    ];

    /** Cache prefix standing in for the provider's own database (hosted flow). */
    private const PROVIDER_STATE = 'billing.sandbox.provider.';

    public const KEY = 'sandbox_card';

    /**
     * The sandbox lets anyone "pay" without money moving, so it never exists in production unless explicitly
     * forced with BILLING_SANDBOX_ENABLED=true (driver, routes and activation are all gated by this).
     */
    public static function enabled(): bool
    {
        return SandboxPolicy::allowed();
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Card (sandbox)';
    }

    public function capabilities(): array
    {
        return [self::CAPTURE, self::TOKENIZE, self::REMOTE_INPUT, self::REFUND, self::PARTIAL_REFUND, self::WEBHOOK,
            self::PENDING_SETTLEMENT, self::REDIRECT, self::LOOKUP, self::STORED_METHODS];
    }

    public function currencies(): array
    {
        return ['USD', 'INR', 'NPR'];
    }

    /**
     * @return list<SettingField>
     */
    public function settingsFields(): array
    {
        return [
            new SettingField(
                key: 'checkout_mode',
                label: 'Client checkout',
                type: FieldType::Select,
                options: ['inline' => 'Card form on the invoice (tokenised)', 'hosted' => 'Hosted payment page (redirect + lookup)'],
                default: 'inline',
            ),
            new SettingField(
                key: 'webhook_secret',
                label: 'Webhook signing secret',
                type: FieldType::Secret,
                help: 'Used to verify X-Sandbox-Signature on callbacks.',
            ),
            new SettingField(
                key: 'fee_percent',
                label: 'Simulated fee (%)',
                help: 'Fee recorded on successful charges, e.g. 2.9',
            ),
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: 100);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(30);
    }

    /**
     * The vault: validate card details and return a token. Raw card data is never persisted or logged.
     */
    public function tokenize(string $cardNumber, int $expiryMonth, int $expiryYear): CardToken
    {
        $number = (string) preg_replace('/\D/', '', $cardNumber);
        $payload = [
            'o' => self::OUTCOMES[$number] ?? ChargeResult::SUCCESS,
            'b' => self::brand($number),
            'l' => substr($number, -4),
            'm' => $expiryMonth,
            'y' => $expiryYear,
            'n' => Str::random(12),
        ];
        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return new CardToken('sbx_'.$body.'.'.$this->sign($body), $payload['b'], $payload['l'], $expiryMonth, $expiryYear);
    }

    public function tokenDetails(GatewayConfig $gateway, string $token): ?CardToken
    {
        $payload = $this->decode($token);

        return $payload === null ? null : new CardToken($token, (string) $payload['b'], (string) $payload['l'], (int) $payload['m'], (int) $payload['y']);
    }

    public function capture(GatewayConfig $gateway, ChargeRequest $request): ChargeResult
    {
        $payload = $this->decode($request->token);

        if ($payload === null) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'invalid_token', raw: ['error' => 'invalid_token']);
        }

        $outcome = (string) $payload['o'];
        $txnId = 'sbx_ch_'.Str::lower(Str::random(20));
        $raw = ['id' => $txnId, 'outcome' => $outcome, 'amount' => $request->amount->amount, 'currency' => $request->amount->currency, 'reference' => $request->reference];

        return match ($outcome) {
            ChargeResult::SUCCESS => new ChargeResult(ChargeResult::SUCCESS, $txnId, $this->fee($gateway, $request->amount->amount), raw: $raw),
            ChargeResult::PENDING => new ChargeResult(ChargeResult::PENDING, $txnId, raw: $raw),
            ChargeResult::DECLINED => new ChargeResult(ChargeResult::DECLINED, $txnId, declineReason: 'card_declined', raw: $raw),
            default => new ChargeResult(ChargeResult::ERROR, $txnId, declineReason: 'processing_error', raw: $raw),
        };
    }

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        $txnId = 'sbx_re_'.Str::lower(Str::random(20));

        return new ChargeResult(ChargeResult::SUCCESS, $txnId, raw: ['id' => $txnId, 'charge' => $request->providerTxnId, 'amount' => $request->amount->amount]);
    }

    public function parseCallback(GatewayConfig $gateway, Request $request): CallbackEvent|CallbackRejection
    {
        $secret = (string) $gateway->setting('webhook_secret', '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');
        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');

        // The timestamp is part of the signed message, so a captured callback can't be re-dated.
        if ($secret === '' || ! ctype_digit($timestamp) || ! hash_equals(self::signature($request->getContent(), $secret, (int) $timestamp), $signature)) {
            return new CallbackRejection(CallbackRejection::INVALID_SIGNATURE);
        }

        $data = $request->json()->all();
        $type = match ($data['type'] ?? null) {
            'payment.succeeded' => CallbackEvent::PAYMENT_SUCCEEDED,
            'payment.failed' => CallbackEvent::PAYMENT_FAILED,
            'charge.refunded' => CallbackEvent::REFUND,
            'charge.dispute' => CallbackEvent::CHARGEBACK,
            'charge.dispute.won' => CallbackEvent::DISPUTE_WON,
            default => null,
        };

        if ($type === null) {
            return new CallbackRejection('unknown event type');
        }

        if (! is_string($data['txn_id'] ?? null) || ! is_numeric($data['amount'] ?? null) || ! is_string($data['currency'] ?? null)) {
            return new CallbackRejection('malformed payload');
        }

        return new CallbackEvent(
            type: $type,
            invoiceId: is_numeric($data['invoice_id'] ?? null) ? (int) $data['invoice_id'] : null,
            txnId: $data['txn_id'],
            amount: (int) $data['amount'],
            currency: strtoupper($data['currency']),
            parentTxnId: is_string($data['parent_txn_id'] ?? null) ? $data['parent_txn_id'] : null,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
            reference: is_string($data['reference'] ?? null) ? $data['reference'] : null,
            raw: $data,
            sentAt: CarbonImmutable::createFromTimestamp((int) $timestamp),
        );
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction|FormPost|QrPayload
    {
        $providerRef = 'sbx_pidx_'.Str::lower(Str::random(16));

        Cache::put(self::PROVIDER_STATE.$intent->reference, [
            'pidx' => $providerRef,
            'amount' => $intent->amount->amount,
            'currency' => $intent->amount->currency,
            'return_url' => $intent->returnUrl,
            'cancel_url' => $intent->cancelUrl,
            'description' => $intent->description,
            'status' => 'initiated',
            'txn_id' => null,
        ], now()->addDay());

        return new RedirectInstruction(route('billing.sandbox.checkout', ['reference' => $intent->reference]), $providerRef);
    }

    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $reference = $request->input('ref');

        return new ReturnHint(
            reference: is_string($reference) && preg_match('/^[a-z0-9-]{3,40}$/', $reference) === 1 ? $reference : null,
            providerRef: is_string($request->input('pidx')) ? $request->input('pidx') : null,
            claimedStatus: is_string($request->input('status')) ? $request->input('status') : null,
            raw: $request->only(['ref', 'pidx', 'status']),
        );
    }

    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        $state = self::providerState($attempt->reference);

        if ($state === null) {
            return new LookupResult(LookupResult::NOT_FOUND);
        }

        $status = match ($state['status']) {
            'completed' => LookupResult::SUCCEEDED,
            'pending' => LookupResult::PENDING,
            'ambiguous' => LookupResult::AMBIGUOUS,
            'declined' => LookupResult::FAILED,
            'cancelled' => LookupResult::CANCELLED,
            'refunded' => LookupResult::REFUNDED,
            default => LookupResult::PENDING,
        };

        return new LookupResult(
            status: $status,
            providerTxnId: is_string($state['txn_id']) ? $state['txn_id'] : null,
            amount: (int) $state['amount'],
            currency: (string) $state['currency'],
            fee: $status === LookupResult::SUCCEEDED ? $this->fee($gateway, (int) $state['amount']) : 0,
            raw: ['pidx' => $state['pidx'], 'status' => $state['status'], 'txn_id' => $state['txn_id'], 'amount' => $state['amount']],
        );
    }

    public function resumeUrl(GatewayConfig $gateway, PaymentAttemptRef $attempt): ?string
    {
        return (self::providerState($attempt->reference)['status'] ?? null) === 'initiated'
            ? route('billing.sandbox.checkout', ['reference' => $attempt->reference])
            : null;
    }

    public function cancelSession(GatewayConfig $gateway, PaymentAttemptRef $attempt): HostedSessionCancel
    {
        if ((self::providerState($attempt->reference)['status'] ?? null) !== 'initiated') {
            return HostedSessionCancel::AlreadyFinal;
        }

        self::simulate($attempt->reference, 'cancelled');

        return HostedSessionCancel::Closed;
    }

    /**
     * The simulated provider takes the customer's payment on its hosted page. Returns where to send the browser.
     */
    public function completeHostedPayment(string $reference, ?string $cardNumber, bool $cancelled = false): ?string
    {
        $state = self::providerState($reference);

        if ($state === null) {
            return null;
        }

        $outcome = $cancelled ? 'cancelled' : match (self::OUTCOMES[(string) preg_replace('/\D/', '', (string) $cardNumber)] ?? ChargeResult::SUCCESS) {
            ChargeResult::DECLINED, ChargeResult::ERROR => 'declined',
            ChargeResult::PENDING => 'pending',
            default => 'completed',
        };

        self::simulate($reference, $outcome);
        $target = $cancelled ? (string) $state['cancel_url'] : (string) $state['return_url'];

        return $target.(str_contains($target, '?') ? '&' : '?').http_build_query(['ref' => $reference, 'pidx' => $state['pidx'], 'status' => $outcome]);
    }

    /**
     * Change the simulated provider's status for an attempt (tests: customer paid but never came back, settlement).
     */
    public static function simulate(string $reference, string $status, ?int $amount = null): void
    {
        $state = self::providerState($reference);

        if ($state === null) {
            return;
        }

        $state['status'] = $status;
        $state['txn_id'] = $status === 'completed' || $status === 'refunded' ? ($state['txn_id'] ?? 'sbx_tx_'.Str::lower(Str::random(16))) : $state['txn_id'];
        $state['amount'] = $amount ?? $state['amount'];
        Cache::put(self::PROVIDER_STATE.$reference, $state, now()->addDay());
    }

    /**
     * @return array{pidx: string, amount: int, currency: string, return_url: string, cancel_url: string, description: string, status: string, txn_id: string|null}|null
     */
    public static function providerState(string $reference): ?array
    {
        $state = Cache::get(self::PROVIDER_STATE.$reference);

        if (! is_array($state)) {
            return null;
        }

        $string = fn (string $key): string => is_scalar($state[$key] ?? null) ? (string) $state[$key] : '';

        return [
            'pidx' => $string('pidx'),
            'amount' => (int) $string('amount'),
            'currency' => $string('currency'),
            'return_url' => $string('return_url'),
            'cancel_url' => $string('cancel_url'),
            'description' => $string('description'),
            'status' => $string('status'),
            'txn_id' => is_string($state['txn_id'] ?? null) ? $state['txn_id'] : null,
        ];
    }

    /**
     * Sign a webhook body the way the sandbox gateway does (used by tests and the dev simulator).
     */
    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function brand(string $number): string
    {
        return match (true) {
            str_starts_with($number, '4') => 'visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $number) => 'mastercard',
            (bool) preg_match('/^3[47]/', $number) => 'amex',
            default => 'card',
        };
    }

    public static function passesLuhn(string $number): bool
    {
        $digits = (string) preg_replace('/\D/', '', $number);

        if (strlen($digits) < 12 || strlen($digits) > 19) {
            return false;
        }

        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $index => $digit) {
            $value = (int) $digit * ($index % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $token): ?array
    {
        if (! str_starts_with($token, 'sbx_') || ! str_contains($token, '.')) {
            return null;
        }

        [$body, $signature] = explode('.', substr($token, 4), 2);

        if (! hash_equals($this->sign($body), $signature)) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);

        return is_array($payload) && isset($payload['o'], $payload['b'], $payload['l'], $payload['m'], $payload['y']) ? $payload : null;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, 'sandbox-vault|'.config('app.key'));
    }

    private function fee(GatewayConfig $gateway, int $amount): int
    {
        $percent = (string) $gateway->setting('fee_percent', '0');

        return is_numeric($percent) ? TaxMath::roundDiv($amount * (int) round((float) $percent * 1000), 100_000) : 0;
    }
}
