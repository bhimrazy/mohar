<?php

namespace Mohar\Simulators\Providers\Khalti;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Khalti's KPG-2 web checkout as one merchant account sees it: payment sessions (pidx) with a hosted page, the lookup
 * that is the only source of truth, and merchant refunds. Amounts are paisa integers throughout, as in Khalti's API.
 *
 * A session moves Initiated → Completed | Pending | User canceled | Expired; a completed one → Refunded or Partially
 * Refunded. Every change runs under the state lock, so a double click or a concurrent lookup sees one outcome.
 */
final class KhaltiApi
{
    /** Khalti's documented minimum: Rs 10. */
    public const MIN_AMOUNT = 1_000;

    /** How long a session stays payable; Khalti's docs show 30 minutes in their sandbox examples. */
    public const SESSION_SECONDS = 1_800;

    /** The merchant fee this simulator charges, in percent of the amount (Khalti publishes no API-level rate). */
    public const FEE_PERCENT = 3;

    public function __construct(private readonly Scenario $scenario) {}

    /**
     * POST /api/v2/epayment/initiate/: validate, open a session, answer its pidx and payment page.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function initiate(array $input): Response
    {
        $errors = [];

        foreach (['return_url', 'website_url', 'amount', 'purchase_order_id', 'purchase_order_name'] as $field) {
            if (! isset($input[$field]) || $input[$field] === '') {
                $errors[$field] = ['This field is required.'];
            }
        }

        foreach (['return_url', 'website_url'] as $field) {
            if (isset($input[$field]) && ! isset($errors[$field]) && ! preg_match('#^https?://[^\s/]+#', (string) $input[$field])) {
                $errors[$field] = ['Enter a valid URL.'];
            }
        }

        if (! isset($errors['amount']) && (! is_int($input['amount']) || $input['amount'] < self::MIN_AMOUNT)) {
            $errors['amount'] = ['The amount must be a whole number of paisa, at least '.self::MIN_AMOUNT.' (Rs 10).'];
        }

        if ($errors !== []) {
            return Response::json([...$errors, 'error_key' => 'validation_error'], 400);
        }

        $pidx = self::randomId(22);
        $expires = time() + self::SESSION_SECONDS;
        $this->scenario->state()->put('payments', $pidx, [
            'pidx' => $pidx,
            'amount' => $input['amount'],
            'purchase_order_id' => (string) $input['purchase_order_id'],
            'purchase_order_name' => (string) $input['purchase_order_name'],
            'return_url' => (string) $input['return_url'],
            'status' => 'Initiated',
            'transaction_id' => null,
            'fee' => 0,
            'refunded_amount' => 0,
            'expires_at' => $expires,
        ]);

        return Response::json([
            'pidx' => $pidx,
            'payment_url' => $this->scenario->apiUrl().'/?pidx='.$pidx,
            'expires_at' => self::nepalTime($expires),
            'expires_in' => self::SESSION_SECONDS,
        ]);
    }

    /**
     * POST /api/v2/epayment/lookup/. Khalti answers the end states Expired and User canceled with HTTP 400 and the same
     * body as any other state, and an unknown pidx with 404.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function lookup(array $input): Response
    {
        $payment = $this->payment((string) ($input['pidx'] ?? ''));

        if ($payment === null) {
            return Response::json(['detail' => 'No payment session with this pidx.', 'error_key' => 'validation_error'], 404);
        }

        $status = $payment['status'];

        return Response::json([
            'pidx' => $payment['pidx'],
            'total_amount' => (int) ($payment['total_amount'] ?? $payment['amount']),
            'status' => $status,
            'transaction_id' => $payment['transaction_id'],
            'fee' => (int) $payment['fee'],
            'refunded' => in_array($status, ['Refunded', 'Partially Refunded'], true),
        ], in_array($status, ['Expired', 'User canceled'], true) ? 400 : 200);
    }

    /**
     * POST /api/merchant-transaction/{transaction_id}/refund/: the whole payment with no amount, or part of it with
     * `amount` in paisa.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function refund(string $transactionId, array $input): Response
    {
        return $this->scenario->state()->transaction(function () use ($transactionId, $input): Response {
            $payment = null;

            foreach ($this->scenario->state()->all('payments') as $candidate) {
                if (($candidate['transaction_id'] ?? null) === $transactionId) {
                    $payment = $candidate;
                }
            }

            if ($payment === null) {
                return Response::json(['detail' => 'No transaction with this id.'], 404);
            }

            $remaining = self::paid($payment) - (int) $payment['refunded_amount'];
            $amount = array_key_exists('amount', $input) ? $input['amount'] : $remaining;

            if (! in_array($payment['status'], ['Completed', 'Partially Refunded'], true) || $remaining <= 0) {
                return Response::json(['detail' => 'This transaction cannot be refunded.'], 400);
            }

            if (! is_int($amount) || $amount <= 0 || $amount > $remaining) {
                return Response::json(['detail' => 'The refund amount must be a positive number of paisa, at most '.$remaining.'.'], 400);
            }

            $this->refunded($payment, $amount);

            return Response::json(['detail' => 'Refund accepted.']);
        });
    }

    /**
     * Record a refund of $amount paisa on a completed payment (the refund API, or one made in the merchant portal).
     *
     * @param  array<string, mixed>  $payment
     */
    public function refunded(array $payment, int $amount): void
    {
        $total = (int) $payment['refunded_amount'] + $amount;
        $this->save([...$payment, 'refunded_amount' => $total, 'status' => $total >= self::paid($payment) ? 'Refunded' : 'Partially Refunded']);
    }

    /**
     * What the payer paid, in paisa: the requested amount unless the payment completed for another one.
     *
     * @param  array<string, mixed>  $payment
     */
    public static function paid(array $payment): int
    {
        return (int) ($payment['total_amount'] ?? $payment['amount']);
    }

    /** GET /?pidx=: the hosted page, with the payer's three outcomes. */
    public function page(Request $request): Response
    {
        $payment = $this->payment((string) ($request->query['pidx'] ?? ''));

        if ($payment === null || $payment['status'] !== 'Initiated') {
            return self::html(410, '<p>This payment link has expired or was already used.</p>');
        }

        $buttons = '';

        foreach (['pay' => 'Pay', 'pending' => 'Pay (bank confirms later)', 'cancel' => 'Cancel'] as $choice => $label) {
            $buttons .= '<form method="post" action="/pay/'.rawurlencode($payment['pidx']).'/'.$choice.'"><button>'.$label.'</button></form>';
        }

        return self::html(200, '<h1>'.htmlspecialchars($payment['purchase_order_name']).'</h1><p>'.self::rupees((int) $payment['amount']).'</p>'.$buttons);
    }

    /**
     * The payer's choice on the hosted page, or the same outcome from the control plane: `pay` completes (optionally
     * for another `total_amount`, the amount-mismatch case), `pending` leaves it waiting on the bank, `cancel` ends it.
     * Returns where Khalti sends the browser: the merchant's return_url with the outcome in the query, which is not
     * signed. A second `pay` on a completed session charges nothing and returns the same redirect.
     *
     * @param  array<string, mixed>  $options  `mobile`, `total_amount`
     * @return array{redirect: string}|null null when the session is not open
     */
    public function choose(string $pidx, string $choice, array $options = []): ?array
    {
        return $this->scenario->state()->transaction(function () use ($pidx, $choice, $options): ?array {
            $payment = $this->payment($pidx);

            if ($payment !== null && $payment['status'] === 'Completed' && $choice === 'pay') {
                return ['redirect' => $this->returnUrl($payment, (string) ($options['mobile'] ?? '98XXXXX904'))];
            }

            if ($payment === null || $payment['status'] !== 'Initiated' || ! in_array($choice, ['pay', 'pending', 'cancel'], true)) {
                return null;
            }

            $payment = match ($choice) {
                'pay' => $this->completed($payment, isset($options['total_amount']) ? (int) $options['total_amount'] : null),
                'pending' => [...$payment, 'status' => 'Pending', 'transaction_id' => self::randomId(22)],
                'cancel' => [...$payment, 'status' => 'User canceled'],
            };
            $this->save($payment);

            return ['redirect' => $this->returnUrl($payment, (string) ($options['mobile'] ?? '98XXXXX904'))];
        });
    }

    /**
     * Move a session the bank left Pending (or an Initiated one) to Completed or Expired, as Khalti does later.
     */
    public function settle(string $pidx, string $outcome): string
    {
        return $this->scenario->state()->transaction(function () use ($pidx, $outcome): string {
            $payment = $this->payment($pidx);

            if ($payment === null || ! in_array($payment['status'], ['Initiated', 'Pending'], true)) {
                throw new \InvalidArgumentException("Session {$pidx} is not open.");
            }

            $payment = $outcome === 'Completed' ? $this->completed($payment, null) : [...$payment, 'status' => 'Expired'];
            $this->save($payment);

            return $payment['status'];
        });
    }

    /**
     * The session, with an open one past its expiry turned Expired, as Khalti does on its own.
     *
     * @return array<string, mixed>|null
     */
    public function payment(string $pidx): ?array
    {
        $payment = $pidx === '' ? null : $this->scenario->state()->get('payments', $pidx);

        if (! is_array($payment)) {
            return null;
        }

        if ($payment['status'] === 'Initiated' && (int) $payment['expires_at'] <= time()) {
            $payment = [...$payment, 'status' => 'Expired'];
            $this->save($payment);
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function completed(array $payment, ?int $totalAmount): array
    {
        $paid = $totalAmount ?? (int) $payment['amount'];

        return [
            ...$payment,
            'status' => 'Completed',
            'transaction_id' => $payment['transaction_id'] ?? self::randomId(22),
            'total_amount' => $paid,
            'fee' => intdiv($paid * self::FEE_PERCENT, 100),
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function returnUrl(array $payment, string $mobile): string
    {
        $completed = $payment['status'] === 'Completed';
        $amount = (string) ($payment['total_amount'] ?? $payment['amount']);
        $query = http_build_query([
            'pidx' => $payment['pidx'],
            'transaction_id' => $completed ? $payment['transaction_id'] : '',
            'tidx' => $completed ? $payment['transaction_id'] : '',
            'amount' => $amount,
            'total_amount' => $amount,
            'mobile' => $completed ? $mobile : '',
            'status' => $payment['status'],
            'purchase_order_id' => $payment['purchase_order_id'],
            'purchase_order_name' => $payment['purchase_order_name'],
        ], '', '&', PHP_QUERY_RFC3986);

        return $payment['return_url'].(str_contains($payment['return_url'], '?') ? '&' : '?').$query;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function save(array $payment): void
    {
        $this->scenario->state()->put('payments', $payment['pidx'], $payment);
    }

    private static function randomId(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $id;
    }

    /** Khalti states times in Nepal time (UTC+05:45). */
    private static function nepalTime(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Asia/Kathmandu'))->format('Y-m-d\TH:i:s.uP');
    }

    private static function rupees(int $paisa): string
    {
        return 'Rs '.number_format(intdiv($paisa, 100)).'.'.sprintf('%02d', $paisa % 100);
    }

    private static function html(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><title>Test payment</title>'.$body);
    }
}
