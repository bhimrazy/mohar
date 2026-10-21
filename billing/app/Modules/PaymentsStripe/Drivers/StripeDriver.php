<?php

namespace App\Modules\PaymentsStripe\Drivers;

use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Integrations\ProvidesLogo;
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
use App\Data\Integrations\IntegrationLogo;
use App\Modules\PaymentsStripe\Support\StripeAmount;
use App\Modules\PaymentsStripe\Support\StripeClient;
use App\Modules\PaymentsStripe\Support\StripeException;
use App\Modules\PaymentsStripe\Support\WebhookContext;
use App\Modules\PaymentsStripe\Support\WebhookSignature;
use App\Support\Billing\AbstractGatewayDriver;
use App\Support\Integrations\Endpoints;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stripe Checkout for one-off invoice payments (docs/product/payments-stripe.md).
 *
 * initiate() creates a Checkout Session and sends the customer to its url. The browser return is a hint only:
 * lookup() retrieves the session stored on the attempt (with its PaymentIntent) and Billing settles from that.
 * Webhooks are verified, then the object they name is retrieved from Stripe; the posted body is never trusted.
 * Payment events name our attempt, which Billing settles by lookup against the attempt's stored amount and rate.
 * Refunds (full and partial) go through the Refunds API.
 */
final class StripeDriver extends AbstractGatewayDriver implements ConnectionTester, HostedPaymentDriver, ProvidesLogo
{
    public const KEY = 'stripe';

    /** metadata[mohar_origin] on refunds made from our admin, so their webhooks don't book them again. */
    public const REFUND_ORIGIN = 'admin';

    private const SESSION_CACHE = 'payments_stripe.session.';

    public function __construct(private StripeClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Stripe';
    }

    /** Stripe's official wordmark from its brand resources (spec §10), served from public/images/gateways. */
    public function logo(): IntegrationLogo
    {
        return new IntegrationLogo(asset('images/gateways/stripe.svg'), __('Pay with Stripe'), darkUrl: asset('images/gateways/stripe-on-dark.svg'));
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP, self::REFUND, self::PARTIAL_REFUND, self::WEBHOOK];
    }

    public function currencies(): array
    {
        return StripeAmount::CURRENCIES;
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'sandbox', 'label' => 'Test mode', 'type' => 'boolean', 'default' => true,
                'help' => 'Uses your Stripe sandbox with test keys (sk_test_…); no real money moves. Not available in production.'],
            ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'secret', 'required' => true,
                'help' => 'Stripe Workbench → API keys. A restricted key (rk_…) needs write access to Checkout Sessions and Refunds and read access to PaymentIntents, Refunds and Disputes.'],
            ['key' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'secret', 'required' => true,
                'help' => 'Create a snapshot event destination for the webhook URL below with: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, payment_intent.succeeded, refund.created, refund.updated, charge.dispute.created, charge.dispute.funds_withdrawn, charge.dispute.closed. Paste its whsec_… secret here.'],
            ['key' => 'publishable_key', 'label' => 'Publishable key', 'type' => 'string',
                'help' => 'Optional (pk_…). Checkout doesn\'t need it; "Test connection" checks that it belongs to the same mode as the secret key.'],
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: self::intConfig('min_amount', 50));
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(self::intConfig('session_minutes', 35));
    }

    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): RedirectInstruction
    {
        $amount = StripeAmount::toStripe($intent->amount)
            ?? throw new StripeException("Stripe can't take {$intent->amount->amount} minor units of {$intent->amount->currency} exactly.");
        $currency = strtolower($intent->amount->currency);
        $expiresAt = now()->addMinutes(self::intConfig('session_minutes', 35))->getTimestamp();
        $metadata = array_filter([
            'mohar_reference' => $intent->reference,
            'mohar_invoice_id' => $intent->invoiceId !== null ? (string) $intent->invoiceId : null,
            'mohar_amount' => (string) $amount,
            'mohar_currency' => $currency,
            'mohar_flow' => 'checkout',
        ], fn (?string $value): bool => $value !== null);
        $description = mb_substr($intent->description, 0, 250) ?: $intent->reference;

        $response = $this->client->post($gateway, 'checkout/sessions', array_filter([
            'mode' => 'payment',
            'success_url' => $intent->returnUrl.(str_contains($intent->returnUrl, '?') ? '&' : '?').'ref='.$intent->reference,
            'cancel_url' => $intent->cancelUrl,
            'client_reference_id' => $intent->reference,
            'customer_email' => $intent->customerEmail !== null && filter_var($intent->customerEmail, FILTER_VALIDATE_EMAIL) !== false ? $intent->customerEmail : null,
            'expires_at' => $expiresAt,
            'adaptive_pricing' => ['enabled' => 'false'],
            // Cards only (Apple Pay and Google Pay are cards): no delayed methods that settle days later (spec §1).
            'allowed_payment_method_types' => ['card'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => $currency, 'unit_amount' => $amount, 'product_data' => ['name' => $description]],
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['description' => $description, 'metadata' => $metadata],
        ], fn (mixed $value): bool => $value !== null), 'mohar-checkout-'.$intent->reference);

        $id = $response->json('id');
        $url = $response->json('url');

        if (! $response->successful() || ! is_string($id) || preg_match('/^cs_(test|live)_[A-Za-z0-9]{8,250}$/', $id) !== 1
            || ! is_string($url) || ! $this->isCheckoutUrl($url) || $response->json('livemode') !== (StripeClient::mode($gateway) === StripeClient::LIVE)) {
            throw new StripeException('Stripe did not create the Checkout Session ('.StripeClient::errorSummary($response).')');
        }

        $expires = $response->json('expires_at');
        $expires = CarbonImmutable::createFromTimestamp(is_int($expires) ? $expires : $expiresAt);
        // Lets a cancel return expire exactly this session (parseReturn); the browser never supplies the id.
        Cache::put(self::SESSION_CACHE.$intent->reference, $id, $expires->addHour());

        return new RedirectInstruction($url, $id, $expires);
    }

    /**
     * Success: ?ref=<reference>. Cancel: Billing's cancel URL with ?ref=&cancelled=1, after which the open session
     * is expired at Stripe so the lookup closes the attempt at once (expiring can't affect a paid session).
     */
    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $reference = $request->query('ref');
        $reference = is_string($reference) && self::isReference($reference) ? $reference : null;
        $cancelled = $request->boolean('cancelled');

        if ($cancelled && $reference !== null) {
            $this->expireSession($gateway, $reference);
        }

        return new ReturnHint(
            reference: $reference,
            claimedStatus: $cancelled ? 'cancelled' : 'returned',
            raw: ['ref' => $reference, 'cancelled' => $cancelled],
        );
    }

    /**
     * Retrieves the session stored on the attempt (never one named by the browser), with its PaymentIntent, charge
     * and balance transaction.
     */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        if ($attempt->providerRef === null || preg_match('/^cs_(test|live)_[A-Za-z0-9]{8,250}$/', $attempt->providerRef) !== 1) {
            return new LookupResult(LookupResult::NOT_FOUND, raw: ['error' => 'no Checkout Session stored for this attempt']);
        }

        try {
            $live = StripeClient::mode($gateway) === StripeClient::LIVE;
            $response = $this->client->get($gateway, 'checkout/sessions/'.$attempt->providerRef, ['payment_intent.latest_charge.balance_transaction'], background: ! $attempt->interactive, interactive: $attempt->interactive);
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => 'Stripe unreachable'], retryable: true);
        } catch (StripeException $exception) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => $exception->getMessage()], retryable: true);
        }

        return $this->sessionResult($response, $attempt, $live);
    }

    /**
     * Refund through the Refunds API. The amount is always sent in Stripe units (also for a full refund), and the
     * refund is tagged so its own webhooks are ignored.
     */
    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        $amount = StripeAmount::toStripe($request->amount);

        if (preg_match('/^pi_[A-Za-z0-9]{8,250}$/', $request->providerTxnId) !== 1 || $amount === null || $amount <= 0) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'Stripe refunds need a PaymentIntent and a positive amount');
        }

        try {
            $response = $this->client->post($gateway, 'refunds', [
                'payment_intent' => $request->providerTxnId,
                'amount' => $amount,
                'metadata' => array_filter(['mohar_origin' => self::REFUND_ORIGIN, 'mohar_refund' => $request->reference]),
            ], 'mohar-refund-'.($request->reference ?? Str::uuid()->toString()));
        } catch (ConnectionException) {
            // The refund may still have gone through: staff must check the Dashboard before retrying.
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'no answer from Stripe; check the Stripe Dashboard before retrying', raw: ['payment_intent' => $request->providerTxnId]);
        } catch (StripeException $exception) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: mb_substr($exception->getMessage(), 0, 150));
        }

        $status = $response->json('status');
        $id = $response->json('id');
        $raw = array_filter([
            'http_status' => $response->status(),
            'refund' => is_string($id) ? $id : null,
            'status' => is_string($status) ? $status : null,
            'amount' => $amount,
            'payment_intent' => $request->providerTxnId,
            'error' => $response->successful() ? null : StripeClient::errorSummary($response),
        ], fn (mixed $value): bool => $value !== null);

        if (! $response->successful() || ! is_string($id) || ! str_starts_with($id, 're_')) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: mb_substr(StripeClient::errorSummary($response), 0, 150), raw: $raw);
        }

        // pending and requires_action refunds exist at Stripe and complete on their own; failed/canceled don't.
        return in_array($status, ['succeeded', 'pending', 'requires_action'], true)
            ? new ChargeResult(ChargeResult::SUCCESS, $id, raw: $raw)
            : new ChargeResult(ChargeResult::DECLINED, $id, declineReason: 'Stripe refund '.(is_string($status) ? $status : 'not accepted'), raw: $raw);
    }

    /**
     * Verify the signature and timestamp before anything else, then retrieve the object the event names. Only the
     * event type and object id are read from the body.
     */
    public function parseCallback(GatewayConfig $gateway, Request $request): CallbackEvent|CallbackRejection
    {
        $secret = $gateway->setting('webhook_secret', '');
        [$outcome, $timestamp] = WebhookSignature::verify(
            (string) $request->header('Stripe-Signature', ''),
            $request->getContent(),
            is_string($secret) ? trim($secret) : '',
            self::intConfig('webhook_tolerance_seconds', 300),
        );

        if ($outcome === WebhookSignature::INVALID || $timestamp === null) {
            return new CallbackRejection(CallbackRejection::INVALID_SIGNATURE);
        }

        if ($outcome === WebhookSignature::STALE) {
            return new CallbackRejection('stale signature timestamp');
        }

        $event = json_decode($request->getContent(), true);
        $type = is_array($event) ? ($event['type'] ?? null) : null;
        $objectId = is_array($event) ? ($event['data']['object']['id'] ?? null) : null;

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! str_starts_with($event['id'], 'evt_') || ! is_string($type)
            || ! is_string($objectId) || preg_match('/^[a-z]{2,5}_[A-Za-z0-9_]{6,250}$/', $objectId) !== 1 || ! is_bool($event['livemode'] ?? null)) {
            return new CallbackRejection('malformed event');
        }

        try {
            $live = StripeClient::mode($gateway) === StripeClient::LIVE;
        } catch (StripeException $exception) {
            return new CallbackRejection($exception->getMessage());
        }

        if ($event['livemode'] !== $live) {
            return new CallbackRejection('event from the other Stripe mode');
        }

        $context = new WebhookContext($event['id'], $type, $objectId, CarbonImmutable::createFromTimestamp($timestamp));

        try {
            return match ($type) {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
                'checkout.session.expired' => $this->sessionEvent($gateway, $context),
                'payment_intent.succeeded' => $this->paymentIntentEvent($gateway, $context),
                'refund.created', 'refund.updated' => $this->refundEvent($gateway, $context),
                'charge.dispute.created', 'charge.dispute.funds_withdrawn' => $this->disputeEvent($gateway, $context),
                'charge.dispute.closed' => $this->disputeEvent($gateway, $context, closed: true),
                default => $context->ignored('not used'),
            };
        } catch (ConnectionException) {
            // A non-2xx answer makes Stripe deliver the event again later.
            return new CallbackRejection('Stripe unreachable; the event will be retried');
        } catch (StripeException $exception) {
            return new CallbackRejection(mb_substr($exception->getMessage(), 0, 150));
        }
    }

    /**
     * Retrieves the account the secret key belongs to. Creates nothing at Stripe.
     */
    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        try {
            $mode = StripeClient::mode($gateway);
            StripeClient::secretKey($gateway);
        } catch (StripeException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        }

        $publishable = trim((string) $gateway->setting('publishable_key', ''));

        if ($publishable !== '' && ! str_starts_with($publishable, 'pk_'.$mode.'_')) {
            return new ConnectionCheck(false, __('The publishable key isn\'t a :mode key (pk_:mode_…).', ['mode' => $mode]));
        }

        $webhookSecret = trim((string) $gateway->setting('webhook_secret', ''));

        if ($webhookSecret !== '' && ! str_starts_with($webhookSecret, 'whsec_')) {
            return new ConnectionCheck(false, __('The webhook signing secret should start with whsec_. Copy it from the event destination in Stripe.'));
        }

        try {
            $response = $this->client->get($gateway, 'account');
        } catch (ConnectionException) {
            return new ConnectionCheck(false, __('Couldn\'t reach Stripe. Check the server\'s outbound connection.'));
        }

        $name = $response->json('business_profile.name') ?: $response->json('settings.dashboard.display_name') ?: $response->json('id');

        return match (true) {
            $response->successful() => new ConnectionCheck(true, __('Stripe accepted the secret key (:mode mode) for :account.', ['mode' => $mode, 'account' => is_string($name) ? mb_substr($name, 0, 80) : 'your account'])),
            $response->status() === 401 => new ConnectionCheck(false, __('Stripe rejected the secret key (:mode mode). Copy it again from Stripe Workbench → API keys.', ['mode' => $mode])),
            // A restricted key may be allowed to take payments without reading account details.
            $response->status() === 403 => new ConnectionCheck(true, __('Stripe accepted the key (:mode mode) but it can\'t read account details. Check that it may write Checkout Sessions and Refunds.', ['mode' => $mode])),
            default => new ConnectionCheck(false, __('Stripe answered with HTTP :status. Try again later.', ['status' => $response->status()])),
        };
    }

    /**
     * @param  bool  $live  the gateway's mode, which the session must belong to
     */
    private function sessionResult(Response $response, PaymentAttemptRef $attempt, bool $live): LookupResult
    {
        if (! $response->successful()) {
            $raw = ['http_status' => $response->status(), 'error' => StripeClient::errorSummary($response)];

            return match (true) {
                $response->status() === 404 => new LookupResult(LookupResult::NOT_FOUND, raw: $raw),
                in_array($response->status(), [401, 403], true) => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Stripe rejected the secret key (HTTP '.$response->status().'); check the gateway settings']),
                $response->serverError(), $response->status() === 429 => new LookupResult(LookupResult::PENDING, raw: $raw, retryable: true),
                default => new LookupResult(LookupResult::AMBIGUOUS, raw: $raw),
            };
        }

        $session = $response->json();
        $session = is_array($session) ? $session : [];
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : [];
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $raw = self::compact([
            'http_status' => $response->status(),
            'session' => $session['id'] ?? null,
            'status' => $session['status'] ?? null,
            'payment_status' => $session['payment_status'] ?? null,
            'payment_intent' => $intent['id'] ?? (is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null),
            'intent_status' => $intent['status'] ?? null,
            'amount_received' => $intent['amount_received'] ?? null,
            'currency' => $intent['currency'] ?? ($session['currency'] ?? null),
            'charge' => $charge['id'] ?? null,
            'amount_refunded' => $charge['amount_refunded'] ?? null,
        ]);

        $belongs = ($session['id'] ?? null) === $attempt->providerRef
            && ($session['client_reference_id'] ?? null) === $attempt->reference
            && ($session['metadata']['mohar_reference'] ?? null) === $attempt->reference
            && ($session['mode'] ?? null) === 'payment'
            && ($session['livemode'] ?? null) === $live;

        if (! $belongs) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'the session does not belong to this attempt']);
        }

        return match ($session['status'] ?? null) {
            'open' => new LookupResult(LookupResult::PENDING, raw: $raw),
            'expired' => new LookupResult(LookupResult::EXPIRED, raw: $raw),
            'complete' => match ($session['payment_status'] ?? null) {
                'paid' => $this->paidResult($intent, $charge, $raw),
                'unpaid' => $this->unpaidResult($intent, $raw),
                default => new LookupResult(LookupResult::AMBIGUOUS, raw: $raw),
            },
            default => new LookupResult(LookupResult::AMBIGUOUS, raw: $raw),
        };
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>  $charge
     * @param  array<string, mixed>  $raw
     */
    private function paidResult(array $intent, array $charge, array $raw): LookupResult
    {
        if (($intent['status'] ?? null) !== 'succeeded' || ! is_string($intent['id'] ?? null) || ! is_string($intent['currency'] ?? null)) {
            return new LookupResult(($intent['status'] ?? null) === 'processing' ? LookupResult::PROCESSING : LookupResult::AMBIGUOUS, raw: $raw);
        }

        $currency = strtoupper($intent['currency']);
        $refunded = is_int($charge['amount_refunded'] ?? null) ? $charge['amount_refunded'] : 0;
        $status = match (true) {
            ($charge['refunded'] ?? false) === true => LookupResult::REFUNDED,
            $refunded > 0 => LookupResult::PARTIALLY_REFUNDED,
            default => LookupResult::SUCCEEDED,
        };

        return new LookupResult(
            status: $status,
            providerTxnId: $intent['id'],
            amount: StripeAmount::fromStripe($intent['amount_received'] ?? null, $currency),
            currency: $currency,
            fee: $status === LookupResult::SUCCEEDED ? self::fee($charge, $currency) : 0,
            raw: $raw,
        );
    }

    /**
     * A completed session whose payment hasn't arrived: still processing (keeps the invoice locked), failed (the
     * customer can pay again), or not started yet.
     *
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>  $raw
     */
    private function unpaidResult(array $intent, array $raw): LookupResult
    {
        $error = $intent['last_payment_error'] ?? null;
        $reason = is_array($error) ? implode(': ', array_filter([is_string($error['code'] ?? null) ? $error['code'] : null, is_string($error['message'] ?? null) ? mb_substr($error['message'], 0, 120) : null])) : '';

        return match ($intent['status'] ?? null) {
            'processing' => new LookupResult(LookupResult::PROCESSING, raw: $raw),
            'requires_payment_method', 'canceled' => new LookupResult(LookupResult::FAILED, raw: [...$raw, 'error' => $reason !== '' ? $reason : 'payment failed at Stripe']),
            default => new LookupResult(LookupResult::PENDING, raw: $raw),
        };
    }

    /**
     * Stripe's fee, only when its balance transaction is in the charge currency: a fee in the settlement currency
     * can't be booked against the invoice.
     *
     * @param  array<string, mixed>  $charge
     */
    private static function fee(array $charge, string $currency): int
    {
        $balance = is_array($charge['balance_transaction'] ?? null) ? $charge['balance_transaction'] : [];

        if (! is_string($balance['currency'] ?? null) || strtoupper($balance['currency']) !== $currency) {
            return 0;
        }

        return StripeAmount::fromStripe($balance['fee'] ?? null, $currency) ?? 0;
    }

    /**
     * A Checkout Session event names the attempt (our reference) to settle; Billing then looks it up and checks it
     * against the attempt's stored amount, currency and rate.
     */
    private function sessionEvent(GatewayConfig $gateway, WebhookContext $context): CallbackEvent|CallbackRejection
    {
        $response = $this->client->get($gateway, 'checkout/sessions/'.$context->objectId, background: true);

        if (($unavailable = $this->unavailable($response, $context)) !== null) {
            return $unavailable;
        }

        $session = (array) $response->json();
        $reference = $session['client_reference_id'] ?? null;

        if (($session['mode'] ?? null) !== 'payment' || ! is_string($reference) || ! self::isReference($reference)
            || ($session['metadata']['mohar_reference'] ?? null) !== $reference) {
            return $context->ignored('not a payment session from this site');
        }

        return CallbackEvent::attemptUpdated($reference, $context->objectId, [...$context->raw(), 'status' => $session['status'] ?? null], $context->sentAt);
    }

    private function paymentIntentEvent(GatewayConfig $gateway, WebhookContext $context): CallbackEvent|CallbackRejection
    {
        $response = $this->client->get($gateway, 'payment_intents/'.$context->objectId, background: true);

        if (($unavailable = $this->unavailable($response, $context)) !== null) {
            return $unavailable;
        }

        $intent = (array) $response->json();
        $reference = $intent['metadata']['mohar_reference'] ?? null;

        if (($intent['metadata']['mohar_flow'] ?? null) !== 'checkout' || ! is_string($reference) || ! self::isReference($reference)) {
            return $context->ignored('not a checkout payment from this site');
        }

        return CallbackEvent::attemptUpdated($reference, $context->objectId, [...$context->raw(), 'status' => $intent['status'] ?? null], $context->sentAt);
    }

    private function refundEvent(GatewayConfig $gateway, WebhookContext $context): CallbackEvent|CallbackRejection
    {
        $response = $this->client->get($gateway, 'refunds/'.$context->objectId, ['payment_intent'], background: true);

        if (($unavailable = $this->unavailable($response, $context)) !== null) {
            return $unavailable;
        }

        $refund = (array) $response->json();
        $status = is_string($refund['status'] ?? null) ? $refund['status'] : 'unknown';
        $intent = self::ourIntent($refund['payment_intent'] ?? null);

        if ($intent === null) {
            // Another integration on the same Stripe account: not ours to book, and retrying won't change that.
            return $context->ignored('refund of a payment not made here');
        }

        $fromAdmin = ($refund['metadata']['mohar_origin'] ?? null) === self::REFUND_ORIGIN;

        if ($fromAdmin && $status === 'failed') {
            return $context->ignored('refund from the admin FAILED at Stripe, check it');
        }

        if ($status !== 'succeeded') {
            return $context->ignored('refund '.$status);
        }

        $currency = is_string($refund['currency'] ?? null) ? $refund['currency'] : '';
        $amount = StripeAmount::fromStripe($refund['amount'] ?? null, $currency);

        if (! is_string($refund['id'] ?? null) || $amount === null) {
            return $context->ignored('refund without an amount');
        }

        return new CallbackEvent(
            type: CallbackEvent::REFUND,
            invoiceId: null,
            txnId: $refund['id'],
            amount: $amount,
            currency: strtoupper($currency),
            parentTxnId: $intent,
            reason: is_string($refund['reason'] ?? null) ? $refund['reason'] : null,
            // Billing books an admin refund only when its own refund row didn't record it (e.g. the answer timed out).
            reference: $fromAdmin && is_string($refund['metadata']['mohar_refund'] ?? null) ? $refund['metadata']['mohar_refund'] : null,
            raw: [...$context->raw(), 'refund' => $refund['id'], 'payment_intent' => $intent, 'amount' => $refund['amount'], 'currency' => $currency, 'from_admin' => $fromAdmin],
            sentAt: $context->sentAt,
        );
    }

    /**
     * A dispute opened, its funds withdrawn, or closed ($closed). Stripe sends `charge.dispute.created` for inquiries
     * too (status `warning_*`), where no money leaves the account (#212). The retrieved dispute's
     * `balance_transactions` list the funds withdrawn and reinstated, so a chargeback is passed on only once one of
     * them withdrew money; until then the dispute is an inquiry: a to-do without money movement. An inquiry that
     * escalates sends `charge.dispute.funds_withdrawn`, which books the chargeback then. A close with funds withdrawn
     * is passed on: `won` as the win, `lost` as the chargeback (the backstop for an install not subscribed to
     * funds_withdrawn; one chargeback per dispute id). Any other close (an inquiry closed as `warning_closed`) is
     * acknowledged and changes nothing.
     */
    private function disputeEvent(GatewayConfig $gateway, WebhookContext $context, bool $closed = false): CallbackEvent|CallbackRejection
    {
        $response = $this->client->get($gateway, 'disputes/'.$context->objectId, ['payment_intent'], background: true);

        if (($unavailable = $this->unavailable($response, $context)) !== null) {
            return $unavailable;
        }

        $dispute = (array) $response->json();
        $currency = is_string($dispute['currency'] ?? null) ? $dispute['currency'] : '';
        $amount = StripeAmount::fromStripe($dispute['amount'] ?? null, $currency);
        $intent = self::ourIntent($dispute['payment_intent'] ?? null);

        if ($intent === null) {
            return $context->ignored('dispute of a payment not made here');
        }

        if (! is_string($dispute['id'] ?? null) || $amount === null) {
            return $context->ignored('dispute without an amount');
        }

        $status = $dispute['status'] ?? null;
        $withdrawn = self::fundsWithdrawn($dispute);

        if ($closed && (! in_array($status, ['won', 'lost'], true) || ! $withdrawn)) {
            return $context->ignored($status === 'warning_closed' ? 'inquiry closed' : 'dispute closed with no funds withdrawn');
        }

        return new CallbackEvent(
            type: match (true) {
                // A lost dispute is passed on as its chargeback: booked here when `charge.dispute.funds_withdrawn`
                // never arrived (not subscribed), otherwise answered "duplicate" (one chargeback per dispute id).
                $closed && $status === 'lost' => CallbackEvent::CHARGEBACK,
                $closed => CallbackEvent::DISPUTE_WON,
                $withdrawn => CallbackEvent::CHARGEBACK,
                default => CallbackEvent::DISPUTE_INQUIRY,
            },
            invoiceId: null,
            txnId: $dispute['id'],
            amount: $amount,
            currency: strtoupper($currency),
            parentTxnId: $intent,
            reason: is_string($dispute['reason'] ?? null) ? 'dispute: '.$dispute['reason'] : 'dispute',
            raw: [...$context->raw(), 'dispute' => $dispute['id'], 'payment_intent' => $intent, 'amount' => $dispute['amount'], 'currency' => $currency, 'status' => $dispute['status'] ?? null],
            sentAt: $context->sentAt,
        );
    }

    /**
     * Whether one of the dispute's balance transactions took money out of the Stripe balance (a negative amount).
     *
     * @param  array<string, mixed>  $dispute
     */
    private static function fundsWithdrawn(array $dispute): bool
    {
        $transactions = is_array($dispute['balance_transactions'] ?? null) ? $dispute['balance_transactions'] : [];

        foreach ($transactions as $transaction) {
            if (is_array($transaction) && is_int($transaction['amount'] ?? null) && $transaction['amount'] < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A failed retrieve: transient errors are rejected so Stripe retries the delivery; an object that doesn't exist
     * (for this key) is acknowledged, because retrying won't change that.
     */
    private function unavailable(Response $response, WebhookContext $context): CallbackEvent|CallbackRejection|null
    {
        if ($response->successful()) {
            return null;
        }

        return $response->status() === 404
            ? $context->ignored('object not found at Stripe')
            : new CallbackRejection('Stripe retrieve failed: '.mb_substr(StripeClient::errorSummary($response), 0, 120));
    }

    /**
     * The id of an expanded PaymentIntent this site created (it carries our reference), else null.
     */
    private static function ourIntent(mixed $intent): ?string
    {
        return is_array($intent) && is_string($intent['id'] ?? null) && is_string($intent['metadata']['mohar_reference'] ?? null) ? $intent['id'] : null;
    }

    private function expireSession(GatewayConfig $gateway, string $reference): void
    {
        $session = Cache::pull(self::SESSION_CACHE.$reference);

        if (! is_string($session)) {
            return;
        }

        try {
            // Only an open session can be expired; Stripe refuses for a completed one, which is fine.
            $this->client->post($gateway, 'checkout/sessions/'.$session.'/expire', [], 'mohar-expire-'.$reference);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Checkout's url must be https on exactly the checkout host (checkout.stripe.com, or its `stripe.checkout`
     * endpoint override in development) and the same port (none by default), with nothing a browser might parse
     * differently.
     */
    private function isCheckoutUrl(string $url): bool
    {
        if (strlen($url) > 4096 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        $checkout = parse_url(Endpoints::for('stripe.checkout', 'https://'.config('payments_stripe.checkout_host', 'checkout.stripe.com'))->baseUrl);

        return $parts !== false && is_array($checkout) && ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass'])
            && ($parts['port'] ?? null) === ($checkout['port'] ?? null)
            && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($checkout['host'] ?? ''));
    }

    private static function isReference(string $value): bool
    {
        return preg_match('/^[a-z0-9-]{3,40}$/', $value) === 1;
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

    private static function intConfig(string $key, int $default): int
    {
        $value = config('payments_stripe.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
