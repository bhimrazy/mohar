<?php

namespace App\Modules\PaymentsRazorpay\Drivers;

use App\Contracts\Billing\CancelsHostedPayment;
use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\LooksUpRefunds;
use App\Contracts\Billing\ResumesHostedPayment;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\RedirectInstruction;
use App\Data\Billing\RefundRequest;
use App\Data\Billing\ReturnHint;
use App\Data\Integrations\ConnectionCheck;
use App\Enums\HostedSessionCancel;
use App\Modules\PaymentsRazorpay\Support\RazorpayClient;
use App\Modules\PaymentsRazorpay\Support\RazorpayException;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Integrations\Endpoints;
use App\Support\Integrations\OutboundAddressRefused;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Throwable;

/**
 * Razorpay Payment Links for invoice payments in INR (docs/product/payments-gateways-expansion.md §8). A link is a
 * plain redirect, so no Razorpay script runs on our pages.
 *
 * initiate() creates a link carrying our reference and sends the customer to its short_url. The signed browser return
 * is a hint only: lookup() fetches the link stored on the attempt, then its captured payment, and Billing settles from
 * that against the attempt's amount and currency. Webhooks are verified over the raw body with the separate webhook
 * secret; link events become "look this attempt up", refund events are fetched from Razorpay before they are booked.
 * Refunds (full and partial) go through the Refunds API and are tagged so their own webhooks don't book them twice.
 */
final class RazorpayDriver extends AbstractGatewayDriver implements CancelsHostedPayment, ConnectionTester, HostedPaymentDriver, LooksUpRefunds, ResumesHostedPayment
{
    public const KEY = 'razorpay';

    /** notes.mohar_origin on refunds made from our admin, so their webhooks settle our own refund row. */
    public const REFUND_ORIGIN = 'admin';

    public const WEBHOOK_EVENTS = 'payment_link.paid, payment_link.partially_paid, payment_link.expired, payment_link.cancelled, refund.processed, refund.failed';

    /** Pages of the payment's refund list one refund check reads at most. */
    private const REFUND_PAGES = 10;

    /** A link webhook's attempt is looked up at most this long after it named it, with the quick (webhook) timeouts. */
    private const WEBHOOK_LOOKUP_SECONDS = 30;

    /**
     * The attempt a link webhook just named. Billing looks it up next in the same request, while Razorpay waits for
     * the answer, so that one lookup makes a single quick try and leaves anything slow to the poller.
     *
     * @var array{reference: string, at: float}|null
     */
    private ?array $webhookLookup = null;

    public function __construct(private RazorpayClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Razorpay';
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP, self::REFUND, self::PARTIAL_REFUND, self::WEBHOOK];
    }

    public function currencies(): array
    {
        return ['INR'];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'sandbox', 'label' => 'Test mode', 'type' => 'boolean', 'default' => true,
                'help' => 'Uses your Razorpay test mode keys (rzp_test_…); no real money moves. Not available in production.'],
            ['key' => 'key_id', 'label' => 'Key id', 'type' => 'string', 'required' => true,
                'help' => 'Razorpay Dashboard → Account & Settings → API keys. Test and live keys are separate.'],
            ['key' => 'key_secret', 'label' => 'Key secret', 'type' => 'secret', 'required' => true,
                'help' => 'Shown once when the key is generated. Payments must be captured automatically (the dashboard\'s payment capture setting).'],
            ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'type' => 'secret', 'required' => true,
                'help' => 'Add a webhook for the URL below with the events '.self::WEBHOOK_EVENTS.', and a secret of its own (not the key secret). Paste that secret here.'],
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: RazorpayClient::intConfig('min_amount', 100));
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(self::linkMinutes());
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction
    {
        if ($intent->amount->currency !== 'INR') {
            throw new RazorpayException("Razorpay only takes INR, not {$intent->amount->currency}.");
        }

        $expireBy = now()->addMinutes(self::linkMinutes())->getTimestamp();
        $customer = array_filter([
            'name' => $intent->customerName !== null ? mb_substr($intent->customerName, 0, 100) : null,
            'email' => $intent->customerEmail !== null && filter_var($intent->customerEmail, FILTER_VALIDATE_EMAIL) !== false ? $intent->customerEmail : null,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $response = $this->client->post($gateway, 'payment_links', array_filter([
            'amount' => $intent->amount->amount,
            'currency' => 'INR',
            'accept_partial' => false,
            'reference_id' => $intent->reference,
            'description' => mb_substr($intent->description, 0, 250) ?: $intent->reference,
            'customer' => $customer !== [] ? $customer : null,
            // We send the invoice and receipts ourselves.
            'notify' => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'notes' => array_filter(['mohar_reference' => $intent->reference, 'mohar_invoice_id' => $intent->invoiceId !== null ? (string) $intent->invoiceId : null]),
            'callback_url' => $intent->returnUrl,
            'callback_method' => 'get',
            'expire_by' => $expireBy,
        ], fn (mixed $value): bool => $value !== null));

        $id = $response->json('id');
        $url = $response->json('short_url');

        if (! $response->successful() || ! is_string($id) || ! self::isId('plink', $id) || ! is_string($url) || ! $this->isLinkUrl($url)
            || $response->json('reference_id') !== $intent->reference || $response->json('amount') !== $intent->amount->amount || $response->json('currency') !== 'INR') {
            throw new RazorpayException('Razorpay did not create the payment link ('.RazorpayClient::errorSummary($response).')');
        }

        $expires = $response->json('expire_by');

        return new RedirectInstruction($url, $id, CarbonImmutable::createFromTimestamp(is_int($expires) && $expires > 0 ? $expires : $expireBy));
    }

    /**
     * The link's callback: a GET with the link id, our reference, its status, the payment id and an HMAC-SHA256 over
     * `link_id|reference_id|status|payment_id` under the key secret. A hint only, valid signature or not.
     */
    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $fields = [];

        foreach (['link' => 'razorpay_payment_link_id', 'reference' => 'razorpay_payment_link_reference_id', 'status' => 'razorpay_payment_link_status', 'payment' => 'razorpay_payment_id', 'signature' => 'razorpay_signature'] as $name => $parameter) {
            $value = $request->query($parameter);
            $fields[$name] = is_string($value) && strlen($value) <= 128 ? $value : null;
        }

        $valid = false;

        if (! in_array(null, $fields, true)) {
            try {
                [, $secret] = RazorpayClient::credentials($gateway);
                $valid = hash_equals(hash_hmac('sha256', implode('|', [$fields['link'], $fields['reference'], $fields['status'], $fields['payment']]), $secret), strtolower((string) $fields['signature']));
            } catch (RazorpayException) {
                $valid = false;
            }
        }

        $reference = $fields['reference'] !== null && self::isReference($fields['reference']) ? $fields['reference'] : null;

        return new ReturnHint(
            reference: $reference,
            providerRef: $fields['link'] !== null && self::isId('plink', $fields['link']) ? $fields['link'] : null,
            claimedStatus: $fields['status'] !== null ? mb_substr($fields['status'], 0, 40) : null,
            signatureValid: $valid,
            raw: array_filter(['link' => $fields['link'], 'reference' => $reference, 'status' => $fields['status'], 'payment' => $fields['payment'], 'signature_valid' => $valid], fn (mixed $value): bool => $value !== null),
            signedPaid: $valid && $fields['status'] === 'paid',
        );
    }

    /**
     * Fetches the link stored on the attempt (never one named by the browser) and, once it is paid, its payment.
     */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        if ($attempt->providerRef === null || ! self::isId('plink', $attempt->providerRef)) {
            return new LookupResult(LookupResult::NOT_FOUND, raw: ['error' => 'no payment link stored for this attempt']);
        }

        $webhook = $this->webhookLookup;
        $this->webhookLookup = null;
        $quick = $attempt->interactive || ($webhook !== null && $webhook['reference'] === $attempt->reference && microtime(true) - $webhook['at'] < self::WEBHOOK_LOOKUP_SECONDS);

        try {
            $response = $this->client->get($gateway, 'payment_links/'.$attempt->providerRef, background: ! $quick, interactive: $quick);

            if (($failed = self::failedLookup($response)) !== null) {
                return $failed;
            }

            $link = (array) $response->json();
            $raw = self::compact(['link' => $link['id'] ?? null, 'status' => $link['status'] ?? null, 'amount' => $link['amount'] ?? null, 'amount_paid' => $link['amount_paid'] ?? null, 'currency' => $link['currency'] ?? null]);

            if (! self::linkBelongs($link, $attempt)) {
                return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'the payment link does not belong to this attempt']);
            }

            return match ($link['status'] ?? null) {
                'created' => new LookupResult(LookupResult::PENDING, raw: $raw),
                'expired' => new LookupResult(LookupResult::EXPIRED, raw: $raw),
                'cancelled' => new LookupResult(LookupResult::CANCELLED, raw: $raw),
                'paid' => $this->paidLink($gateway, $quick, $link, $raw),
                // partially_paid can't happen with accept_partial off: staff look at it.
                default => new LookupResult(LookupResult::AMBIGUOUS, raw: $raw),
            };
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => 'Razorpay unreachable'], retryable: true);
        } catch (RazorpayException $exception) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => $exception->getMessage()], retryable: true);
        }
    }

    /**
     * The link's own short_url, only while Razorpay reports it open for this attempt and the url is on the link
     * domain. One quick try: the customer is waiting.
     */
    public function resumeUrl(GatewayConfig $gateway, PaymentAttemptRef $attempt): ?string
    {
        if ($attempt->providerRef === null || ! self::isId('plink', $attempt->providerRef)) {
            return null;
        }

        try {
            $response = $this->client->get($gateway, 'payment_links/'.$attempt->providerRef, interactive: true);
        } catch (ConnectionException|RazorpayException) {
            return null;
        }

        $link = $response->successful() ? (array) $response->json() : [];
        $url = $link['short_url'] ?? null;
        $expireBy = $link['expire_by'] ?? null;

        return self::linkBelongs($link, $attempt) && ($link['status'] ?? null) === 'created' && (! is_int($expireBy) || $expireBy === 0 || $expireBy > now()->getTimestamp())
            && is_string($url) && $this->isLinkUrl($url) ? $url : null;
    }

    /** Cancels the stored link. Razorpay cancels only an unpaid open link and answers 400 for any other. */
    public function cancelSession(GatewayConfig $gateway, PaymentAttemptRef $attempt): HostedSessionCancel
    {
        if ($attempt->providerRef === null || ! self::isId('plink', $attempt->providerRef)) {
            return HostedSessionCancel::AlreadyFinal;
        }

        try {
            $response = $this->client->post($gateway, 'payment_links/'.$attempt->providerRef.'/cancel', [], interactive: true);
        } catch (Throwable $exception) {
            report($exception);

            return HostedSessionCancel::Unreachable;
        }

        return match (true) {
            $response->successful() && $response->json('status') === 'cancelled' => HostedSessionCancel::Closed,
            $response->status() === 400 => HostedSessionCancel::AlreadyFinal,
            default => HostedSessionCancel::Unreachable,
        };
    }

    /**
     * Refund through POST /payments/{id}/refund. The amount is always sent in paise (also for a full refund) and the
     * refund carries our refund reference in its notes.
     */
    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        if (! self::isId('pay', $request->providerTxnId) || $request->amount->currency !== 'INR' || $request->amount->amount <= 0) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: 'Razorpay refunds need a payment id and a positive INR amount');
        }

        try {
            $response = $this->client->post($gateway, 'payments/'.$request->providerTxnId.'/refund', [
                'amount' => $request->amount->amount,
                'speed' => 'normal',
                'notes' => array_filter(['mohar_origin' => self::REFUND_ORIGIN, 'mohar_refund' => $request->reference]),
            ]);
        } catch (ConnectionException) {
            // The refund may still have gone through: it stays pending until confirmed.
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'no answer from Razorpay; the refund stays pending until it is confirmed', raw: ['payment' => $request->providerTxnId]);
        } catch (RazorpayException $exception) {
            // Thrown before anything is sent (no key, wrong mode): a definite no.
            return new ChargeResult(ChargeResult::DECLINED, declineReason: mb_substr($exception->getMessage(), 0, 150));
        }

        $id = $response->json('id');
        $status = $response->json('status');
        $raw = self::compact(['http_status' => $response->status(), 'refund' => $id, 'status' => $status, 'amount' => $request->amount->amount, 'payment' => $request->providerTxnId,
            'error' => $response->successful() ? null : RazorpayClient::errorSummary($response)]);

        // A server error, timeout or rate limit says nothing about whether the refund was made.
        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: mb_substr(RazorpayClient::errorSummary($response), 0, 150), raw: $raw);
        }

        if (! $response->successful() || ! is_string($id) || ! self::isId('rfnd', $id)) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: mb_substr(RazorpayClient::errorSummary($response), 0, 150), raw: $raw);
        }

        return self::refundOutcome($id, $status, $raw);
    }

    /**
     * Finds the refund tagged with our reference in the payment's refunds; the request is never repeated (Razorpay's
     * refund API takes no idempotency key). Absence is a definite "no" only once refund_settle_minutes have passed.
     */
    public function lookupRefund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        if (! self::isId('pay', $request->providerTxnId) || $request->reference === null) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'Razorpay refund lookups need a payment id and our refund reference');
        }

        $items = [];
        $complete = false;

        // Pages of 100 (Razorpay's largest), at most REFUND_PAGES of them; a longer list is never called complete.
        for ($page = 0; $page < self::REFUND_PAGES && ! $complete; $page++) {
            try {
                $response = $this->client->get($gateway, 'payments/'.$request->providerTxnId.'/refunds', ['count' => '100', 'skip' => (string) ($page * 100)], background: true);
            } catch (ConnectionException|RazorpayException) {
                return new ChargeResult(ChargeResult::ERROR, declineReason: 'no answer from Razorpay');
            }

            $found = $response->json('items');

            if (! $response->successful() || ! is_array($found)) {
                return new ChargeResult(ChargeResult::ERROR, declineReason: mb_substr(RazorpayClient::errorSummary($response), 0, 150));
            }

            $items = [...$items, ...$found];
            $complete = count($found) < 100;
        }

        foreach ($items as $refund) {
            if (! is_array($refund) || ($refund['notes']['mohar_refund'] ?? null) !== $request->reference || ! is_string($refund['id'] ?? null)) {
                continue;
            }

            $raw = self::compact(['refund' => $refund['id'], 'status' => $refund['status'] ?? null, 'payment' => $request->providerTxnId]);

            if (($refund['amount'] ?? null) !== $request->amount->amount || ($refund['currency'] ?? null) !== $request->amount->currency) {
                // No refund id: a refund that is not ours as asked must never be matched to our row (raw keeps it).
                return new ChargeResult(ChargeResult::ERROR, declineReason: 'the refund at Razorpay has another amount or currency', raw: $raw);
            }

            return self::refundOutcome($refund['id'], $refund['status'] ?? null, $raw);
        }

        $settled = $request->sentAt !== null && $request->sentAt->lte(now()->subMinutes(RazorpayClient::intConfig('refund_settle_minutes', 15)));

        return $complete && $settled
            ? new ChargeResult(ChargeResult::DECLINED, declineReason: 'Razorpay has no refund with this reference', raw: ['payment' => $request->providerTxnId])
            : new ChargeResult(ChargeResult::ERROR, declineReason: 'Razorpay has no refund with this reference yet, but may still record it');
    }

    /**
     * Verify X-Razorpay-Signature (hex HMAC-SHA256 of the raw body under the webhook secret) before reading anything.
     * Razorpay signs no timestamp and its event id is not needed for dedupe: a link event only triggers a lookup, which is
     * idempotent (a redelivery books nothing twice), and Billing dedupes refunds by refund id, so a retry it asked for
     * (409) still lands. Fetches here make one quick try: Razorpay waits for the answer, and a non-2xx makes it retry.
     */
    public function parseCallback(GatewayConfig $gateway, Request $request): CallbackEvent|CallbackRejection
    {
        $secret = $gateway->setting('webhook_secret', '');
        $secret = is_string($secret) ? trim($secret) : '';
        $signature = strtolower(trim((string) $request->header('X-Razorpay-Signature', '')));

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return new CallbackRejection(CallbackRejection::INVALID_SIGNATURE);
        }

        $eventId = (string) $request->header('X-Razorpay-Event-Id', '');
        $body = json_decode($request->getContent(), true);
        $type = is_array($body) ? ($body['event'] ?? null) : null;

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $eventId) !== 1 || ! is_string($type) || ($body['entity'] ?? null) !== 'event' || ! is_array($body['payload'] ?? null)) {
            return new CallbackRejection('malformed event');
        }

        $raw = ['event' => $eventId, 'type' => mb_substr($type, 0, 60)];

        try {
            RazorpayClient::mode($gateway);

            return match ($type) {
                'payment_link.paid', 'payment_link.partially_paid', 'payment_link.expired', 'payment_link.cancelled' => $this->linkEvent($body['payload'], $raw),
                'refund.processed', 'refund.failed' => $this->refundEvent($gateway, $body['payload'], $raw),
                default => self::ignored($raw, 'not used'),
            };
        } catch (ConnectionException) {
            // A non-2xx answer makes Razorpay deliver the event again later.
            return new CallbackRejection('Razorpay unreachable; the event will be retried');
        } catch (RazorpayException $exception) {
            return new CallbackRejection(mb_substr($exception->getMessage(), 0, 150));
        }
    }

    /**
     * Fetches a made-up payment link: Razorpay answers 401 for rejected keys and "does not exist" for accepted ones.
     * Creates nothing.
     */
    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        try {
            $mode = RazorpayClient::mode($gateway);
            RazorpayClient::credentials($gateway);
            $response = $this->client->get($gateway, 'payment_links/plink_MoharConnectionTest');
        } catch (RazorpayException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        } catch (ConnectionException) {
            return new ConnectionCheck(false, __('Couldn\'t reach Razorpay. Check the server\'s outbound connection.'));
        }

        return match (true) {
            $response->successful() || RazorpayClient::isUnknownId($response) => new ConnectionCheck(true, __('Razorpay accepted the key (:mode mode).', ['mode' => $mode])),
            $response->status() === 401 => new ConnectionCheck(false, __('Razorpay rejected the key id or secret (:mode mode). Copy them again from the dashboard\'s API keys.', ['mode' => $mode])),
            default => new ConnectionCheck(false, __('Razorpay answered with HTTP :status. Try again later.', ['status' => $response->status()])),
        };
    }

    /**
     * @param  array<string, mixed>  $link
     * @param  array<string, scalar>  $raw
     */
    private function paidLink(GatewayConfig $gateway, bool $quick, array $link, array $raw): LookupResult
    {
        $payments = array_values(array_filter(is_array($link['payments'] ?? null) ? $link['payments'] : [], fn (mixed $payment): bool => is_array($payment) && ($payment['status'] ?? null) !== 'failed'));
        $paymentId = $payments[0]['payment_id'] ?? null;

        if (count($payments) !== 1 || ! is_string($paymentId) || ! self::isId('pay', $paymentId)) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'the paid link does not name exactly one payment']);
        }

        $response = $this->client->get($gateway, 'payments/'.$paymentId, background: ! $quick, interactive: $quick);

        if (! $response->successful()) {
            return $response->serverError() || $response->status() === 429
                ? new LookupResult(LookupResult::PENDING, raw: [...$raw, 'error' => RazorpayClient::errorSummary($response)], retryable: true)
                : new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'payment fetch failed: '.RazorpayClient::errorSummary($response)]);
        }

        $payment = (array) $response->json();
        $refunded = is_int($payment['amount_refunded'] ?? null) ? $payment['amount_refunded'] : 0;
        $raw = [...$raw, ...self::compact(['payment' => $payment['id'] ?? null, 'payment_status' => $payment['status'] ?? null, 'method' => $payment['method'] ?? null, 'amount_refunded' => $refunded])];

        if (($payment['id'] ?? null) !== $paymentId || (is_string($link['order_id'] ?? null) && ($payment['order_id'] ?? null) !== $link['order_id']) || ! is_int($payment['amount'] ?? null) || ! is_string($payment['currency'] ?? null)) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'the payment does not belong to this payment link']);
        }

        $status = match ($payment['status'] ?? null) {
            'captured' => $refunded > 0 ? LookupResult::PARTIALLY_REFUNDED : LookupResult::SUCCEEDED,
            'refunded' => LookupResult::REFUNDED,
            // Captured automatically by the account; Razorpay refunds a payment left authorized on its own.
            'authorized' => LookupResult::PROCESSING,
            default => LookupResult::AMBIGUOUS,
        };

        return new LookupResult(
            status: $status,
            providerTxnId: $paymentId,
            amount: $payment['amount'],
            currency: strtoupper($payment['currency']),
            // Razorpay's fee includes the GST on it.
            fee: in_array($status, [LookupResult::SUCCEEDED, LookupResult::PARTIALLY_REFUNDED], true) && is_int($payment['fee'] ?? null) ? max(0, $payment['fee']) : 0,
            raw: $raw,
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, string>  $raw
     */
    private function linkEvent(array $payload, array $raw): CallbackEvent
    {
        $link = $payload['payment_link']['entity'] ?? null;
        $id = is_array($link) ? ($link['id'] ?? null) : null;
        $reference = is_array($link) ? ($link['reference_id'] ?? null) : null;

        if (! is_string($id) || ! self::isId('plink', $id) || ! is_string($reference) || ! self::isReference($reference) || ($link['notes']['mohar_reference'] ?? null) !== $reference) {
            return self::ignored($raw, 'not a payment link from this site');
        }

        $this->webhookLookup = ['reference' => $reference, 'at' => microtime(true)];

        // Only names the attempt: Billing looks the stored link up, never using this body's figures.
        return CallbackEvent::attemptUpdated($reference, $id, [...$raw, 'link' => $id]);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, string>  $raw
     */
    private function refundEvent(GatewayConfig $gateway, array $payload, array $raw): CallbackEvent|CallbackRejection
    {
        $id = $payload['refund']['entity']['id'] ?? null;

        if (! is_string($id) || ! self::isId('rfnd', $id)) {
            return new CallbackRejection('malformed event');
        }

        $response = $this->client->get($gateway, 'refunds/'.$id, interactive: true);

        if (($unavailable = self::unavailable($response, $raw)) !== null) {
            return $unavailable;
        }

        $refund = (array) $response->json();
        $paymentId = $refund['payment_id'] ?? null;

        if (($refund['id'] ?? null) !== $id || ! is_string($paymentId) || ! self::isId('pay', $paymentId)) {
            return self::ignored($raw, 'refund without a payment');
        }

        $payment = $this->client->get($gateway, 'payments/'.$paymentId, interactive: true);

        if (($unavailable = self::unavailable($payment, $raw)) !== null) {
            return $unavailable;
        }

        // Another integration on the same Razorpay account: not ours to book, and retrying won't change that.
        if (! is_string($payment->json('notes.mohar_reference'))) {
            return self::ignored($raw, 'refund of a payment not made here');
        }

        $fromAdmin = ($refund['notes']['mohar_origin'] ?? null) === self::REFUND_ORIGIN;
        $status = is_string($refund['status'] ?? null) ? $refund['status'] : 'unknown';

        if ($status !== 'processed') {
            // A failed admin refund is still pending here (only `processed` books it): "Check refund" releases it.
            return self::ignored($raw, $status === 'failed' && $fromAdmin ? 'refund from the admin FAILED at Razorpay; Check refund releases it' : 'refund '.$status);
        }

        if (! is_int($refund['amount'] ?? null) || $refund['amount'] <= 0 || ! is_string($refund['currency'] ?? null)) {
            return self::ignored($raw, 'refund without an amount');
        }

        return new CallbackEvent(
            type: CallbackEvent::REFUND,
            invoiceId: null,
            txnId: $id,
            amount: $refund['amount'],
            currency: strtoupper($refund['currency']),
            parentTxnId: $paymentId,
            raw: [...$raw, 'refund' => $id, 'payment' => $paymentId, 'amount' => $refund['amount'], 'currency' => $refund['currency'], 'from_admin' => $fromAdmin],
            // Billing books an admin refund only when its own refund row didn't record it (e.g. the answer timed out).
            reference: $fromAdmin && is_string($refund['notes']['mohar_refund'] ?? null) ? $refund['notes']['mohar_refund'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private static function linkBelongs(array $link, PaymentAttemptRef $attempt): bool
    {
        return ($link['id'] ?? null) === $attempt->providerRef
            && ($link['reference_id'] ?? null) === $attempt->reference
            && ($link['notes']['mohar_reference'] ?? null) === $attempt->reference
            && ($link['currency'] ?? null) === $attempt->amount->currency
            && ($link['amount'] ?? null) === $attempt->amount->amount;
    }

    private static function failedLookup(Response $response): ?LookupResult
    {
        if ($response->successful()) {
            return null;
        }

        $raw = ['http_status' => $response->status(), 'error' => RazorpayClient::errorSummary($response)];

        return match (true) {
            RazorpayClient::isUnknownId($response) => new LookupResult(LookupResult::NOT_FOUND, raw: $raw),
            // The key was rejected (e.g. rotated after the payment started): staff must act. The attempt stays open and
            // is polled again, so it settles once the key is fixed.
            in_array($response->status(), [401, 403], true) => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Razorpay rejected the key (HTTP '.$response->status().'); check the gateway settings']),
            $response->serverError(), $response->status() === 429 => new LookupResult(LookupResult::PENDING, raw: $raw, retryable: true),
            default => new LookupResult(LookupResult::AMBIGUOUS, raw: $raw),
        };
    }

    /**
     * A failed fetch while handling a webhook: transient errors are rejected so Razorpay retries the delivery; an object
     * that doesn't exist (for this key) is acknowledged, because retrying won't change that.
     *
     * @param  array<string, string>  $raw
     */
    private static function unavailable(Response $response, array $raw): CallbackEvent|CallbackRejection|null
    {
        return match (true) {
            $response->successful() => null,
            RazorpayClient::isUnknownId($response) => self::ignored($raw, 'object not found at Razorpay'),
            default => new CallbackRejection('Razorpay fetch failed: '.mb_substr(RazorpayClient::errorSummary($response), 0, 120)),
        };
    }

    /**
     * Acknowledge without moving money. Billing logs the type, which carries the reason for staff.
     *
     * @param  array<string, string>  $raw
     */
    private static function ignored(array $raw, string $reason): CallbackEvent
    {
        return new CallbackEvent(type: mb_substr('razorpay '.$raw['type'].': '.$reason, 0, 90), invoiceId: null, txnId: $raw['event'], amount: 0, currency: '', raw: $raw);
    }

    /**
     * Only `processed` is a refund made. `pending` may still fail, so it is unknown: the refund row stays pending with
     * its amount held until refund.processed or "Check refund" settles it, and `failed` releases it.
     *
     * @param  array<string, scalar>  $raw
     */
    private static function refundOutcome(string $id, mixed $status, array $raw): ChargeResult
    {
        return match ($status) {
            'processed' => new ChargeResult(ChargeResult::SUCCESS, $id, raw: $raw),
            'failed' => new ChargeResult(ChargeResult::DECLINED, $id, declineReason: 'Razorpay refund failed', raw: $raw),
            // The refund id of an unknown answer is kept in raw only; the pending row is matched by our reference.
            default => new ChargeResult(ChargeResult::ERROR, declineReason: 'Razorpay refund '.(is_string($status) ? mb_substr($status, 0, 20) : 'unconfirmed').'; it stays pending until Razorpay confirms it', raw: $raw),
        };
    }

    /**
     * The redirect target must be https on rzp.io or a subdomain, or exactly the host and port of the
     * `razorpay.checkout` endpoint, with nothing a browser might parse differently.
     */
    private function isLinkUrl(string $url): bool
    {
        if (strlen($url) > 2048 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $domain = strtolower((string) config('payments_razorpay.link_domain', 'rzp.io'));

        try {
            $page = parse_url(Endpoints::for('razorpay.checkout', 'https://'.$domain)->baseUrl);
        } catch (OutboundAddressRefused) {
            $page = false;
        }

        if (is_array($page) && $host === strtolower((string) ($page['host'] ?? '')) && ($parts['port'] ?? null) === ($page['port'] ?? null)) {
            return true;
        }

        return ! isset($parts['port']) && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1
            && ($host === $domain || str_ends_with($host, '.'.$domain));
    }

    private static function isId(string $prefix, ?string $value): bool
    {
        return $value !== null && preg_match('/^'.$prefix.'_[A-Za-z0-9]{6,40}$/', $value) === 1;
    }

    private static function isReference(string $value): bool
    {
        return preg_match('/^[a-z0-9-]{3,40}$/', $value) === 1;
    }

    private static function linkMinutes(): int
    {
        return max(16, RazorpayClient::intConfig('link_minutes', 30));
    }

    /**
     * Keep scalar values only, dropping nulls (stored payloads hold ids, statuses and amounts).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, scalar>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => is_scalar($value));
    }
}
