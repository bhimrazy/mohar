<?php

namespace Mohar\Simulators\Providers\Razorpay;

use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Razorpay's India API as one test-mode account sees it: orders, the payments paid against them (authorized, captured
 * or failed), refunds and payment links. Amounts are paise integers and the only currency is INR.
 *
 * An order moves created → attempted (any payment attempt) → paid (a captured payment covers it). A payment moves
 * authorized → captured → refunded (all of it refunded; part refunded keeps `captured` with refund_status `partial`),
 * or created → failed. Every change runs under the state lock, so a double click or a concurrent capture sees one
 * outcome, and every change is an event queued for the webhook URL.
 */
final class RazorpayApi
{
    /** Razorpay's documented minimum order: Rs 1. */
    public const MIN_AMOUNT = 100;

    /** The merchant fee this simulator charges, in percent of the amount, and the GST on that fee (Razorpay: 2% + 18%). */
    public const FEE_PERCENT = 2;

    public const FEE_GST_PERCENT = 18;

    /** A payment link must expire at least this long after it is created. */
    public const LINK_MIN_SECONDS = 900;

    /** Razorpay's documented test card that fails for insufficient funds. */
    public const INSUFFICIENT_FUNDS_CARD = '4100280000080001';

    public const ACCOUNT_ID = 'acc_SimulatorAccount';

    public function __construct(private readonly Scenario $scenario) {}

    // ---------------------------------------------------------------------------------------------------- orders

    /**
     * POST /orders.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function createOrder(array $input): Response
    {
        $error = $this->validateAmount($input) ?? $this->validateNotes($input) ?? $this->validateReceipt($input);

        if ($error !== null) {
            return $error;
        }

        $order = $this->newOrder((int) $input['amount'], isset($input['receipt']) ? (string) $input['receipt'] : null, (array) ($input['notes'] ?? []));
        $this->scenario->state()->put('orders', $order['id'], $order);

        return Response::json($order);
    }

    /** GET /orders/{id}. */
    public function fetchOrder(string $id): Response
    {
        $order = $this->order($id);

        return $order === null ? self::notFound() : Response::json($order);
    }

    /** GET /orders/{id}/payments: every attempt against the order, oldest first. */
    public function orderPayments(string $id): Response
    {
        if ($this->order($id) === null) {
            return self::notFound();
        }

        return self::collection(array_values(array_filter($this->payments(), fn (array $payment): bool => $payment['order_id'] === $id)));
    }

    /**
     * @param  array<array-key, mixed>  $notes
     * @return array<string, mixed>
     */
    private function newOrder(int $amount, ?string $receipt, array $notes): array
    {
        return [
            'id' => self::newId('order_'),
            'entity' => 'order',
            'amount' => $amount,
            'amount_paid' => 0,
            'amount_due' => $amount,
            'currency' => 'INR',
            'receipt' => $receipt,
            'offer_id' => null,
            'status' => 'created',
            'attempts' => 0,
            'notes' => $notes,
            'created_at' => time(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function order(string $id): ?array
    {
        $order = str_starts_with($id, 'order_') ? $this->scenario->state()->get('orders', $id) : null;

        return is_array($order) ? $order : null;
    }

    // -------------------------------------------------------------------------------------------------- payments

    /** GET /payments/{id}. */
    public function fetchPayment(string $id): Response
    {
        $payment = $this->payment($id);

        return $payment === null ? self::notFound() : Response::json($payment);
    }

    /**
     * POST /payments/{id}/capture: capture an authorized payment, for exactly its amount in INR.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function capture(string $id, array $input): Response
    {
        return $this->scenario->state()->transaction(function () use ($id, $input): Response {
            $payment = $this->payment($id);

            if ($payment === null) {
                return self::notFound();
            }

            if (! array_key_exists('amount', $input) || ! is_int($input['amount'])) {
                return self::error('The amount field is required.', field: 'amount');
            }

            if (($input['currency'] ?? null) !== 'INR') {
                return self::error('The currency field is required and must be INR.', field: 'currency');
            }

            if ($payment['status'] === 'captured' || $payment['status'] === 'refunded') {
                return self::error('This payment has already been captured.');
            }

            if ($payment['status'] !== 'authorized') {
                return self::error('Only an authorized payment can be captured.');
            }

            if ($input['amount'] !== $payment['amount']) {
                return self::error('The capture amount must equal the authorized amount.', field: 'amount');
            }

            return Response::json($this->captured($payment));
        });
    }

    /**
     * Pay an order the way the checkout does, or fail it. Success authorizes the payment and, when the account captures
     * automatically, captures it at once. Returns null when the order is unknown or already paid.
     *
     * @param  array{method?: string, vpa?: string, card?: string, email?: string, contact?: string, outcome?: string}  $options
     * @return array<string, mixed>|null the payment
     */
    public function attempt(string $orderId, array $options = []): ?array
    {
        return $this->scenario->state()->transaction(function () use ($orderId, $options): ?array {
            $order = $this->order($orderId);

            if ($order === null || $order['status'] === 'paid') {
                return null;
            }

            $method = $options['method'] ?? 'upi';
            $vpa = $method === 'upi' ? ($options['vpa'] ?? 'success@razorpay') : null;
            $insufficient = $method === 'card' && ($options['card'] ?? '') === self::INSUFFICIENT_FUNDS_CARD;
            $outcome = $options['outcome'] ?? ($vpa === 'failure@razorpay' || $insufficient ? 'failed' : 'success');
            $this->scenario->state()->put('orders', $orderId, [...$order, 'status' => 'attempted', 'attempts' => $order['attempts'] + 1]);

            $payment = [
                'id' => self::newId('pay_'),
                'entity' => 'payment',
                'amount' => $order['amount'],
                'currency' => 'INR',
                'status' => $outcome === 'failed' ? 'failed' : 'authorized',
                'order_id' => $orderId,
                'invoice_id' => null,
                'international' => false,
                'method' => $method,
                'amount_refunded' => 0,
                'refund_status' => null,
                'captured' => false,
                'description' => 'Test payment',
                'card_id' => $method === 'card' ? self::newId('card_') : null,
                'bank' => $method === 'netbanking' ? 'HDFC' : null,
                'wallet' => $method === 'wallet' ? 'freecharge' : null,
                'vpa' => $vpa,
                'email' => $options['email'] ?? 'payer@example.test',
                'contact' => $options['contact'] ?? '+919000090000',
                'notes' => $order['notes'],
                'fee' => null,
                'tax' => null,
                'error_code' => null,
                'error_description' => null,
                'error_source' => null,
                'error_step' => null,
                'error_reason' => null,
                'created_at' => time(),
            ];

            if ($outcome === 'failed') {
                $payment = [
                    ...$payment,
                    'error_code' => 'BAD_REQUEST_ERROR',
                    'error_description' => $insufficient ? 'The account has insufficient funds.' : 'The payment was declined by the bank.',
                    'error_source' => 'bank',
                    'error_step' => 'payment_authorization',
                    'error_reason' => $insufficient ? 'insufficient_funds' : 'payment_failed',
                ];
                $this->save($payment);
                $this->emit('payment.failed', ['payment' => $payment]);

                return $payment;
            }

            $this->save($payment);
            $this->emit('payment.authorized', ['payment' => $payment]);

            return $this->scenario->state()->get('config', 'capture') === 'manual' ? $payment : $this->captured($payment);
        });
    }

    /**
     * Capture an authorized payment: fee and GST are taken, the order is credited (and paid when covered), then
     * `payment.captured`, `order.paid` and, for a payment link, `payment_link.paid` are queued.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function captured(array $payment): array
    {
        $fee = intdiv($payment['amount'] * self::FEE_PERCENT + 50, 100);
        $payment = [...$payment, 'status' => 'captured', 'captured' => true, 'fee' => $fee, 'tax' => intdiv($fee * self::FEE_GST_PERCENT + 50, 100)];
        $this->save($payment);

        $order = (array) $this->order($payment['order_id']);
        $paid = $order['amount_paid'] + $payment['amount'];
        $order = [...$order, 'amount_paid' => $paid, 'amount_due' => max(0, $order['amount'] - $paid), 'status' => $paid >= $order['amount'] ? 'paid' : 'attempted'];
        $this->scenario->state()->put('orders', $order['id'], $order);

        $this->emit('payment.captured', ['payment' => $payment]);

        if ($order['status'] === 'paid') {
            $this->emit('order.paid', ['payment' => $payment, 'order' => $order]);
            $link = $this->linkForOrder($order['id']);

            if ($link !== null) {
                $link = [...$link, 'status' => 'paid', 'amount_paid' => $paid, 'updated_at' => time(), 'payments' => [['payment_id' => $payment['id'], 'amount' => $payment['amount'], 'status' => 'captured', 'method' => $payment['method'], 'created_at' => $payment['created_at']]]];
                $this->scenario->state()->put('links', $link['id'], $link);
                $this->emit('payment_link.paid', ['payment_link' => $link, 'order' => $order, 'payment' => $payment]);
            }
        }

        return $payment;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payment(string $id): ?array
    {
        $payment = str_starts_with($id, 'pay_') ? $this->scenario->state()->get('payments', $id) : null;

        return is_array($payment) ? $payment : null;
    }

    /**
     * @return list<array<string, mixed>> oldest first
     */
    public function payments(): array
    {
        $payments = array_values(array_filter($this->scenario->state()->all('payments'), 'is_array'));
        usort($payments, fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);

        return $payments;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function save(array $payment): void
    {
        $this->scenario->state()->put('payments', $payment['id'], $payment);
    }

    // --------------------------------------------------------------------------------------------------- refunds

    /**
     * POST /payments/{id}/refund: all of what is left, or `amount` of it, in paise.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function refund(string $paymentId, array $input): Response
    {
        return $this->scenario->state()->transaction(function () use ($paymentId, $input): Response {
            $payment = $this->payment($paymentId);

            if ($payment === null) {
                return self::notFound();
            }

            if (isset($input['speed']) && ! in_array($input['speed'], ['normal', 'optimum'], true)) {
                return self::error('The speed must be normal or optimum.', field: 'speed');
            }

            if (! in_array($payment['status'], ['captured', 'refunded'], true)) {
                return self::error('The payment has not been captured, so it cannot be refunded.');
            }

            $left = $payment['amount'] - $payment['amount_refunded'];

            if ($left <= 0) {
                return self::error('The payment has already been fully refunded.');
            }

            $amount = array_key_exists('amount', $input) ? $input['amount'] : $left;

            if (! is_int($amount) || $amount < 1) {
                return self::error('The refund amount must be a positive number of paise.', field: 'amount');
            }

            if ($amount > $left) {
                return self::error("The refund amount is greater than what is left to refund ({$left} paise).", field: 'amount');
            }

            $speed = (string) ($input['speed'] ?? 'normal');
            $refund = $this->refunded($payment, $amount, $speed === 'optimum' ? 'instant' : 'normal', $speed, isset($input['receipt']) ? (string) $input['receipt'] : null, (array) ($input['notes'] ?? []));

            return Response::json($refund);
        });
    }

    /**
     * Record a refund of $amount paise on a captured payment (the API, or one made in the dashboard), queue
     * `refund.created` and `refund.processed`.
     *
     * @param  array<string, mixed>  $payment
     * @param  array<array-key, mixed>  $notes
     * @return array<string, mixed>
     */
    public function refunded(array $payment, int $amount, string $speedProcessed = 'normal', string $speedRequested = 'normal', ?string $receipt = null, array $notes = []): array
    {
        $total = $payment['amount_refunded'] + $amount;
        $payment = [...$payment, 'amount_refunded' => $total, 'refund_status' => $total >= $payment['amount'] ? 'full' : 'partial', 'status' => $total >= $payment['amount'] ? 'refunded' : 'captured'];
        $refund = [
            'id' => self::newId('rfnd_'),
            'entity' => 'refund',
            'amount' => $amount,
            'currency' => 'INR',
            'payment_id' => $payment['id'],
            'notes' => $notes,
            'receipt' => $receipt,
            'acquirer_data' => ['rrn' => (string) random_int(100_000_000_000, 999_999_999_999)],
            'created_at' => time(),
            'batch_id' => null,
            'status' => 'processed',
            'speed_processed' => $speedProcessed,
            'speed_requested' => $speedRequested,
        ];
        $this->save($payment);
        $this->scenario->state()->put('refunds', $refund['id'], $refund);
        $this->emit('refund.created', ['refund' => $refund, 'payment' => $payment]);
        $this->emit('refund.processed', ['refund' => $refund, 'payment' => $payment]);

        return $refund;
    }

    /**
     * The bank rejects a refund after accepting it: the refund fails and the money is not refunded after all.
     *
     * @return array<string, mixed>
     */
    public function refundFailed(string $refundId): array
    {
        return $this->scenario->state()->transaction(function () use ($refundId): array {
            $refund = $this->findRefund($refundId);
            $payment = $refund === null ? null : $this->payment($refund['payment_id']);

            if ($refund === null || $payment === null || $refund['status'] !== 'processed') {
                throw new \InvalidArgumentException("Refund {$refundId} is not a processed refund.");
            }

            $total = $payment['amount_refunded'] - $refund['amount'];
            $payment = [...$payment, 'amount_refunded' => $total, 'refund_status' => $total <= 0 ? null : 'partial', 'status' => 'captured'];
            $refund = [...$refund, 'status' => 'failed'];
            $this->save($payment);
            $this->scenario->state()->put('refunds', $refundId, $refund);
            $this->emit('refund.failed', ['refund' => $refund, 'payment' => $payment]);

            return $refund;
        });
    }

    /** GET /refunds/{id}. */
    public function fetchRefund(string $id): Response
    {
        $refund = $this->findRefund($id);

        return $refund === null ? self::notFound() : Response::json($refund);
    }

    /** GET /payments/{id}/refunds. */
    public function paymentRefunds(string $paymentId): Response
    {
        if ($this->payment($paymentId) === null) {
            return self::notFound();
        }

        $refunds = array_values(array_filter($this->scenario->state()->all('refunds'), fn (mixed $refund): bool => is_array($refund) && $refund['payment_id'] === $paymentId));
        usort($refunds, fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);

        return self::collection($refunds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRefund(string $id): ?array
    {
        $refund = str_starts_with($id, 'rfnd_') ? $this->scenario->state()->get('refunds', $id) : null;

        return is_array($refund) ? $refund : null;
    }

    // ---------------------------------------------------------------------------------------------- payment links

    /**
     * POST /payment_links: opens a link backed by an order of the same amount; `reference_id` becomes the order's
     * receipt.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function createLink(array $input): Response
    {
        $error = $this->validateAmount($input) ?? $this->validateNotes($input);

        if ($error !== null) {
            return $error;
        }

        if (isset($input['reference_id']) && (! is_string($input['reference_id']) || strlen($input['reference_id']) > 40)) {
            return self::error('The reference_id may not be longer than 40 characters.', field: 'reference_id');
        }

        foreach (['callback_url'] as $field) {
            if (isset($input[$field]) && ! preg_match('#^https?://[^\s/]+#', (string) $input[$field])) {
                return self::error("The {$field} must be a valid URL.", field: $field);
            }
        }

        if (isset($input['expire_by']) && (! is_int($input['expire_by']) || $input['expire_by'] < time() + self::LINK_MIN_SECONDS)) {
            return self::error('The expire_by time must be at least 15 minutes from now.', field: 'expire_by');
        }

        $order = $this->newOrder((int) $input['amount'], isset($input['reference_id']) ? (string) $input['reference_id'] : null, (array) ($input['notes'] ?? []));
        $id = self::newId('plink_');
        $link = [
            'id' => $id,
            'accept_partial' => false,
            'amount' => $order['amount'],
            'amount_paid' => 0,
            'callback_method' => isset($input['callback_url']) ? 'get' : null,
            'callback_url' => $input['callback_url'] ?? null,
            'cancelled_at' => 0,
            'created_at' => time(),
            'currency' => 'INR',
            'customer' => (object) (array) ($input['customer'] ?? []),
            'description' => isset($input['description']) ? (string) $input['description'] : null,
            'expire_by' => (int) ($input['expire_by'] ?? 0),
            'expired_at' => 0,
            'first_min_partial_amount' => 0,
            'notes' => $order['notes'],
            'notify' => ['email' => (bool) ($input['notify']['email'] ?? false), 'sms' => (bool) ($input['notify']['sms'] ?? false)],
            'order_id' => $order['id'],
            'payments' => null,
            'reference_id' => $input['reference_id'] ?? '',
            'reminder_enable' => false,
            'reminders' => [],
            'short_url' => $this->scenario->apiUrl().'/l/'.$id,
            'status' => 'created',
            'updated_at' => time(),
            'upi_link' => false,
            'user_id' => '',
        ];
        $this->scenario->state()->transaction(function () use ($order, $link): void {
            $this->scenario->state()->put('orders', $order['id'], $order);
            $this->scenario->state()->put('links', $link['id'], $link);
        });

        return Response::json($link);
    }

    /** GET /payment_links/{id}. */
    public function fetchLink(string $id): Response
    {
        $link = $this->link($id);

        return $link === null ? self::notFound() : Response::json($link);
    }

    /** POST /payment_links/{id}/cancel: only a link that is still unpaid can be cancelled. */
    public function cancelLink(string $id): Response
    {
        return $this->scenario->state()->transaction(function () use ($id): Response {
            $link = $this->link($id);

            if ($link === null) {
                return self::notFound();
            }

            if ($link['status'] !== 'created') {
                return self::error("A payment link that is {$link['status']} cannot be cancelled.");
            }

            $link = [...$link, 'status' => 'cancelled', 'cancelled_at' => time(), 'updated_at' => time()];
            $this->scenario->state()->put('links', $id, $link);

            return Response::json($link);
        });
    }

    /**
     * The link, with an open one past its `expire_by` turned `expired`, as Razorpay does on its own.
     *
     * @return array<string, mixed>|null
     */
    public function link(string $id): ?array
    {
        $link = str_starts_with($id, 'plink_') ? $this->scenario->state()->get('links', $id) : null;

        if (! is_array($link)) {
            return null;
        }

        if ($link['status'] === 'created' && $link['expire_by'] > 0 && $link['expire_by'] <= time()) {
            $link = [...$link, 'status' => 'expired', 'expired_at' => time(), 'updated_at' => time()];
            $this->scenario->state()->put('links', $id, $link);
        }

        return $link;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function linkForOrder(string $orderId): ?array
    {
        foreach ($this->scenario->state()->all('links') as $link) {
            if (is_array($link) && $link['order_id'] === $orderId) {
                return $link;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------------------------------- signatures

    /** What the checkout sends back with a paid order: HMAC-SHA256 hex of `order_id|payment_id` under the key secret. */
    public static function checkoutSignature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, RazorpaySimulator::KEY_SECRET);
    }

    /** The same for a payment link's callback: `link_id|reference_id|status|payment_id`. */
    public static function linkSignature(string $linkId, string $referenceId, string $status, string $paymentId): string
    {
        return hash_hmac('sha256', implode('|', [$linkId, $referenceId, $status, $paymentId]), RazorpaySimulator::KEY_SECRET);
    }

    /**
     * What the checkout sends the merchant's callback_url (a POST) after the payer pays or fails.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, string>
     */
    public static function checkoutFields(array $payment): array
    {
        if ($payment['status'] === 'failed') {
            return [
                'error[code]' => (string) $payment['error_code'],
                'error[description]' => (string) $payment['error_description'],
                'error[source]' => (string) $payment['error_source'],
                'error[step]' => (string) $payment['error_step'],
                'error[reason]' => (string) $payment['error_reason'],
                'error[metadata]' => (string) json_encode(['order_id' => $payment['order_id'], 'payment_id' => $payment['id']]),
            ];
        }

        return [
            'razorpay_payment_id' => $payment['id'],
            'razorpay_order_id' => $payment['order_id'],
            'razorpay_signature' => self::checkoutSignature($payment['order_id'], $payment['id']),
        ];
    }

    /**
     * The query a payment link's callback_url gets (GET).
     *
     * @param  array<string, mixed>  $link
     * @param  array<string, mixed>  $payment
     * @return array<string, string>
     */
    public static function linkFields(array $link, array $payment): array
    {
        return [
            'razorpay_payment_id' => $payment['id'],
            'razorpay_payment_link_id' => $link['id'],
            'razorpay_payment_link_reference_id' => (string) $link['reference_id'],
            'razorpay_payment_link_status' => $link['status'],
            'razorpay_signature' => self::linkSignature($link['id'], (string) $link['reference_id'], $link['status'], $payment['id']),
        ];
    }

    // ----------------------------------------------------------------------------------------------------- events

    /**
     * Record an event and queue its delivery when the webhook is subscribed to it. The `X-Razorpay-Event-Id` header is
     * the same on every retry, which is what a receiver dedupes on. The signature is added at delivery.
     *
     * @param  array<string, array<string, mixed>>  $payload  entity name => entity, as the event carries them
     */
    public function emit(string $event, array $payload): void
    {
        $id = 'evt_'.self::randomId(14);
        $record = [
            'entity' => 'event',
            'account_id' => self::ACCOUNT_ID,
            'event' => $event,
            'contains' => array_keys($payload),
            'payload' => array_map(fn (array $entity): array => ['entity' => $entity], $payload),
            'created_at' => time(),
        ];
        $this->scenario->state()->put('events', $id, $record);
        $url = $this->scenario->state()->get('config', 'webhook_url');
        $enabled = $this->scenario->state()->get('config', 'enabled_events');

        if (is_string($url) && $url !== '' && (! is_array($enabled) || $enabled === [] || in_array($event, $enabled, true))) {
            $this->scenario->outbox()->enqueue($url, (string) json_encode($record, JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json', 'X-Razorpay-Event-Id' => $id]);
        }
    }

    // ------------------------------------------------------------------------------------------------- validation

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function validateAmount(array $input): ?Response
    {
        if (! isset($input['amount']) || ! is_int($input['amount'])) {
            return self::error('The amount must be a whole number of paise.', field: 'amount');
        }

        if ($input['amount'] < self::MIN_AMOUNT) {
            return self::error('The amount is less than the minimum allowed, '.self::MIN_AMOUNT.' paise (Rs 1).', field: 'amount');
        }

        if (($input['currency'] ?? null) !== 'INR') {
            return self::error(isset($input['currency']) ? 'Only INR is supported in this account.' : 'The currency field is required.', field: 'currency');
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function validateNotes(array $input): ?Response
    {
        if (isset($input['notes']) && (! is_array($input['notes']) || count($input['notes']) > 15)) {
            return self::error('Notes can hold at most 15 key-value pairs.', field: 'notes');
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function validateReceipt(array $input): ?Response
    {
        if (isset($input['receipt']) && (! is_string($input['receipt']) || strlen($input['receipt']) > 40)) {
            return self::error('The receipt may not be longer than 40 characters.', field: 'receipt');
        }

        return null;
    }

    // --------------------------------------------------------------------------------------------------- answers

    /**
     * Razorpay answers a bad request, an unknown id and a failed login with the same envelope. `field` is present only
     * when the error names a request field.
     */
    public static function error(string $description, int $status = 400, ?string $field = null): Response
    {
        return Response::json(['error' => array_filter([
            'code' => 'BAD_REQUEST_ERROR',
            'description' => $description,
            'source' => 'NA',
            'step' => 'NA',
            'reason' => 'input_validation_failed',
            'metadata' => (object) [],
            'field' => $field,
        ], fn (mixed $value): bool => $value !== null)], $status);
    }

    /** An unknown id is a 400, not a 404, as at Razorpay. */
    public static function notFound(): Response
    {
        return self::error('The id provided does not exist.');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function collection(array $items): Response
    {
        return Response::json(['entity' => 'collection', 'count' => count($items), 'items' => $items]);
    }

    public static function newId(string $prefix): string
    {
        return $prefix.self::randomId(14);
    }

    private static function randomId(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $id;
    }
}
