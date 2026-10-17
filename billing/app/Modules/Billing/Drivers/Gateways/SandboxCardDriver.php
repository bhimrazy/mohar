<?php

namespace App\Modules\Billing\Drivers\Gateways;

use App\Modules\Billing\Drivers\Gateways\Data\CallbackEvent;
use App\Modules\Billing\Drivers\Gateways\Data\CardToken;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeRequest;
use App\Modules\Billing\Drivers\Gateways\Data\ChargeResult;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\Tax\TaxMath;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Simulated card gateway for development and tests. Its "vault" (remote input endpoint) turns card details into
 * a signed, self-describing token; the card number is discarded immediately. Outcomes are deterministic:
 *
 *   4242 4242 4242 4242  success            4000 0000 0000 0002  declined
 *   4000 0000 0000 0077  pending (settles via webhook)   4000 0000 0000 0119  processing error
 *
 * Webhooks are signed with HMAC-SHA256 of the raw body using the configured webhook secret
 * (header X-Sandbox-Signature).
 */
class SandboxCardDriver extends AbstractGatewayDriver
{
    public const SIGNATURE_HEADER = 'X-Sandbox-Signature';

    public const OUTCOMES = [
        '4000000000000002' => ChargeResult::DECLINED,
        '4000000000000077' => ChargeResult::PENDING,
        '4000000000000119' => ChargeResult::ERROR,
    ];

    public function key(): string
    {
        return 'sandbox_card';
    }

    public function name(): string
    {
        return 'Card (sandbox)';
    }

    public function capabilities(): array
    {
        return [self::CAPTURE, self::TOKENIZE, self::REMOTE_INPUT, self::REFUND, self::WEBHOOK, self::PENDING_SETTLEMENT];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'secret', 'help' => 'Used to verify X-Sandbox-Signature on callbacks.'],
            ['key' => 'fee_percent', 'label' => 'Simulated fee (%)', 'type' => 'string', 'help' => 'Fee recorded on successful charges, e.g. 2.9'],
        ];
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

    public function tokenDetails(Gateway $gateway, string $token): ?CardToken
    {
        $payload = $this->decode($token);

        return $payload === null ? null : new CardToken($token, (string) $payload['b'], (string) $payload['l'], (int) $payload['m'], (int) $payload['y']);
    }

    public function capture(Gateway $gateway, ChargeRequest $request): ChargeResult
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

    public function refund(Gateway $gateway, Transaction $payment, Money $amount): ChargeResult
    {
        $txnId = 'sbx_re_'.Str::lower(Str::random(20));

        return new ChargeResult(ChargeResult::SUCCESS, $txnId, raw: ['id' => $txnId, 'charge' => $payment->gateway_txn_id, 'amount' => $amount->amount]);
    }

    public function parseCallback(Gateway $gateway, Request $request): CallbackEvent
    {
        $secret = (string) $gateway->setting('webhook_secret', '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            throw new InvalidCallbackException('invalid signature');
        }

        $data = $request->json()->all();
        $type = match ($data['type'] ?? null) {
            'payment.succeeded' => CallbackEvent::PAYMENT_SUCCEEDED,
            'payment.failed' => CallbackEvent::PAYMENT_FAILED,
            'charge.refunded' => CallbackEvent::REFUND,
            'charge.dispute' => CallbackEvent::CHARGEBACK,
            default => throw new InvalidCallbackException('unknown event type'),
        };

        if (! is_string($data['txn_id'] ?? null) || ! is_numeric($data['amount'] ?? null) || ! is_string($data['currency'] ?? null)) {
            throw new InvalidCallbackException('malformed payload');
        }

        return new CallbackEvent(
            type: $type,
            invoiceId: is_numeric($data['invoice_id'] ?? null) ? (int) $data['invoice_id'] : null,
            txnId: $data['txn_id'],
            amount: (int) $data['amount'],
            currency: strtoupper($data['currency']),
            parentTxnId: is_string($data['parent_txn_id'] ?? null) ? $data['parent_txn_id'] : null,
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : null,
            raw: $data,
        );
    }

    /**
     * Sign a webhook body the way the sandbox gateway does (used by tests and the dev simulator).
     */
    public static function signature(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
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

    private function fee(Gateway $gateway, int $amount): int
    {
        $percent = (string) $gateway->setting('fee_percent', '0');

        return is_numeric($percent) ? TaxMath::roundDiv($amount * (int) round((float) $percent * 1000), 100_000) : 0;
    }
}
