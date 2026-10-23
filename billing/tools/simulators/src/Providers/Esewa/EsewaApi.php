<?php

namespace Mohar\Simulators\Providers\Esewa;

use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * eSewa ePay v2 as one merchant (product code) sees it: the signed form post that books a session, the hosted page,
 * the signed base64 success return, and the status check. Amounts are rupee strings on the wire, as eSewa sends them,
 * and paisa integers in the state, so every comparison is exact.
 *
 * Bookings follow the UAT captures of 2026-10-21 (tests/Fixtures/Esewa): payable for 50 minutes after the post; a booked,
 * unpaid session answers PENDING; a cancelled or expired one, or a status check for another amount, answers NOT_FOUND.
 */
final class EsewaApi
{
    /** Minutes a booking stays payable after the form post (bookingExpiryTime in the UAT captures). */
    public const BOOKING_MINUTES = 50;

    /** The fields eSewa signs on the success return, in its order. */
    public const RETURN_SIGNED_FIELDS = 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names';

    public function __construct(private readonly Scenario $scenario) {}

    /**
     * POST /api/epay/main/v2/form: check the fields and the signature, book the session, and send the browser to the
     * payment page. Refusals are 400s in eSewa's two error shapes.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function book(array $input): Response
    {
        $fields = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $input);

        foreach (['amount', 'tax_amount', 'product_service_charge', 'product_delivery_charge', 'total_amount', 'transaction_uuid', 'product_code', 'success_url', 'failure_url', 'signed_field_names', 'signature'] as $name) {
            if (($fields[$name] ?? '') === '') {
                return Response::json(['code' => 0, 'error_message' => "The {$name} field is required."], 400);
            }
        }

        $secret = EsewaSimulator::MERCHANTS[$fields['product_code']] ?? null;
        $names = explode(',', $fields['signed_field_names']);

        if ($secret === null || array_diff(['total_amount', 'transaction_uuid', 'product_code'], $names) !== []
            || ! hash_equals(self::signature($fields, $fields['signed_field_names'], $secret), $fields['signature'])) {
            return Response::json(['code' => 'ES104', 'message' => 'The payload signature does not match.'], 400);
        }

        $parts = array_map(self::paisa(...), [$fields['amount'], $fields['tax_amount'], $fields['product_service_charge'], $fields['product_delivery_charge']]);
        $total = self::paisa($fields['total_amount']);

        if (in_array(null, $parts, true) || $total === null || $total <= 0 || array_sum($parts) !== $total) {
            return Response::json(['code' => 0, 'error_message' => 'total_amount must be the sum of amount, tax and charges.'], 400);
        }

        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $fields['transaction_uuid']) !== 1) {
            return Response::json(['code' => 0, 'error_message' => 'transaction_uuid may hold only letters, digits and hyphens.'], 400);
        }

        return $this->scenario->state()->transaction(function () use ($fields, $total): Response {
            $key = $fields['product_code'].':'.$fields['transaction_uuid'];

            if ($this->scenario->state()->get('bookings', $key) !== null) {
                return Response::json(['error_message' => 'This transaction UUID was already used.', 'code' => 0], 400);
            }

            $id = bin2hex(random_bytes(8));
            $this->scenario->state()->put('bookings', $key, [
                'id' => $id,
                'product_code' => $fields['product_code'],
                'transaction_uuid' => $fields['transaction_uuid'],
                'total' => $total,
                'success_url' => $fields['success_url'],
                'failure_url' => $fields['failure_url'],
                'status' => 'PENDING',
                'ref_id' => null,
                'expires_at' => time() + 60 * self::BOOKING_MINUTES,
            ]);
            $this->scenario->state()->put('booking_ids', $id, $key);

            return new Response(302, ['Location' => $this->scenario->apiUrl().'/epay/booking/'.$id]);
        });
    }

    /**
     * GET /api/epay/transaction/status/: matched on product code, uuid and amount (the settled amount once paid);
     * anything else is NOT_FOUND, echoing what was asked. So a payment settled for another amount answers NOT_FOUND to
     * a check for the booked amount, and COMPLETE only to one for the settled amount. total_amount is a JSON number,
     * as captured.
     *
     * @param  array<array-key, mixed>  $query
     */
    public function status(array $query): Response
    {
        $productCode = (string) ($query['product_code'] ?? '');
        $uuid = (string) ($query['transaction_uuid'] ?? '');
        $asked = self::paisa((string) ($query['total_amount'] ?? ''));

        if ($productCode === '' || $uuid === '' || $asked === null) {
            return Response::json(['code' => 0, 'error_message' => 'product_code, total_amount and transaction_uuid are required.'], 400);
        }

        $booking = $this->booking($productCode.':'.$uuid);
        $found = $booking !== null && (int) ($booking['settled'] ?? $booking['total']) === $asked && ! in_array($booking['status'], ['CANCELED', 'EXPIRED', 'NOT_FOUND'], true);

        // A JSON number that keeps its ".0", as eSewa's answers do (100.0).
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'product_code' => $productCode,
            'transaction_uuid' => $uuid,
            'total_amount' => (float) ($asked / 100),
            'status' => $found ? $booking['status'] : 'NOT_FOUND',
            'ref_id' => $found ? $booking['ref_id'] : null,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** GET /epay/booking/{id}: the payment page, with the payer's three outcomes. */
    public function page(string $id): Response
    {
        $booking = $this->bookingById($id);

        if ($booking === null || $booking['status'] !== 'PENDING') {
            return self::html(410, '<p>This payment session has ended.</p>');
        }

        $buttons = '';

        foreach (['pay' => 'Pay', 'leave' => 'Go back without paying', 'cancel' => 'Cancel payment'] as $choice => $label) {
            $buttons .= '<form method="post" action="/epay/booking/'.$id.'/'.$choice.'"><button>'.$label.'</button></form>';
        }

        return self::html(200, '<p>Rs '.number_format((int) $booking['total'] / 100, 2).'</p>'.$buttons);
    }

    /**
     * The payer's choice, from the page or the control plane. `pay` completes and returns the signed success URL;
     * `leave` returns to the failure URL with the booking still PENDING (payable until it expires); `cancel` ends it
     * and returns to the failure URL, unchanged, as UAT does. A second `pay` returns the same success URL.
     *
     * Options for `pay`: `settled_amount` (rupees: the payment settles for another amount, which the status check then matches instead of the booked one), `variant` (`wrong_secret`, `tampered`
     * after signing, `unsigned`) for a forged return.
     *
     * @param  array<string, mixed>  $options
     * @return array{redirect: string}|null null when the booking is not open
     */
    public function choose(string $id, string $choice, array $options = []): ?array
    {
        return $this->scenario->state()->transaction(function () use ($id, $choice, $options): ?array {
            $booking = $this->bookingById($id);

            if ($booking !== null && $booking['status'] === 'COMPLETE' && $choice === 'pay') {
                return ['redirect' => $this->successUrl($booking, (string) ($options['variant'] ?? ''))];
            }

            if ($booking === null || $booking['status'] !== 'PENDING' || ! in_array($choice, ['pay', 'leave', 'cancel'], true)) {
                return null;
            }

            if ($choice === 'leave') {
                return ['redirect' => $booking['failure_url']];
            }

            if ($choice === 'cancel') {
                $this->save([...$booking, 'status' => 'CANCELED']);

                return ['redirect' => $booking['failure_url']];
            }

            $settled = isset($options['settled_amount']) ? self::paisa((string) $options['settled_amount']) : null;
            $booking = $this->completed($booking, $settled);

            return ['redirect' => $this->successUrl($booking, (string) ($options['variant'] ?? ''))];
        });
    }

    /**
     * What eSewa later does to a booking: `COMPLETE` (paid after the customer left the page), `EXPIRED` (the booking
     * timed out), `AMBIGUOUS` (halted), `NOT_FOUND` (the status check stops finding the booking, whatever it showed before:
     * a paid one it no longer matches, or one that was halted), `FULL_REFUND` or `PARTIAL_REFUND` (made in the merchant portal).
     */
    public function settle(string $productCode, string $uuid, string $status): string
    {
        return $this->scenario->state()->transaction(function () use ($productCode, $uuid, $status): string {
            $booking = $this->booking($productCode.':'.$uuid) ?? throw new \InvalidArgumentException("No booking {$uuid}.");
            $allowed = match ($status) {
                'COMPLETE', 'EXPIRED', 'AMBIGUOUS' => in_array($booking['status'], ['PENDING', 'AMBIGUOUS'], true),
                'NOT_FOUND' => in_array($booking['status'], ['PENDING', 'AMBIGUOUS', 'COMPLETE'], true),
                'FULL_REFUND', 'PARTIAL_REFUND' => in_array($booking['status'], ['COMPLETE', 'PARTIAL_REFUND'], true),
                default => false,
            };

            if (! $allowed) {
                throw new \InvalidArgumentException("A {$booking['status']} booking can't become {$status}.");
            }

            $booking = $status === 'COMPLETE' ? $this->completed($booking, null) : [...$booking, 'status' => $status];
            $this->save($booking);

            return $booking['status'];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function booking(string $key): ?array
    {
        $booking = $this->scenario->state()->get('bookings', $key);

        if (! is_array($booking)) {
            return null;
        }

        if ($booking['status'] === 'PENDING' && (int) $booking['expires_at'] <= time()) {
            $booking = [...$booking, 'status' => 'EXPIRED'];
            $this->save($booking);
        }

        return $booking;
    }

    /**
     * Base64 HMAC-SHA256 over "name=value" pairs joined by commas, in the order of the signed field names.
     *
     * @param  array<string, string>  $values
     */
    public static function signature(array $values, string $signedFieldNames, string $secret): string
    {
        $message = implode(',', array_map(fn (string $name): string => $name.'='.($values[$name] ?? ''), explode(',', $signedFieldNames)));

        return base64_encode(hash_hmac('sha256', $message, $secret, true));
    }

    /**
     * Rupee text ("1,130.5", "100", "100.0") → paisa, or null when it is not an exact amount.
     */
    public static function paisa(string $rupees): ?int
    {
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', str_replace(',', '', trim($rupees)), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    /**
     * Paisa → eSewa's return text: thousands separated, at least one decimal ("1,500.0", "1,130.5", "1,130.55").
     */
    public static function rupees(int $paisa): string
    {
        $text = number_format(intdiv($paisa, 100)).'.'.sprintf('%02d', $paisa % 100);

        return str_ends_with($text, '0') ? substr($text, 0, -1) : $text;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bookingById(string $id): ?array
    {
        $key = $this->scenario->state()->get('booking_ids', $id);

        return is_string($key) ? $this->booking($key) : null;
    }

    /**
     * @param  array<string, mixed>  $booking
     * @return array<string, mixed>
     */
    private function completed(array $booking, ?int $settled): array
    {
        $booking = [...$booking, 'status' => 'COMPLETE', 'ref_id' => strtoupper(substr(bin2hex(random_bytes(4)), 0, 7)), 'settled' => $settled ?? (int) $booking['total']];
        $this->save($booking);

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $booking
     */
    private function successUrl(array $booking, string $variant): string
    {
        $data = [
            'transaction_code' => $booking['ref_id'],
            'status' => 'COMPLETE',
            'total_amount' => self::rupees((int) $booking['settled']),
            'transaction_uuid' => $booking['transaction_uuid'],
            'product_code' => $booking['product_code'],
            'signed_field_names' => self::RETURN_SIGNED_FIELDS,
        ];
        $secret = $variant === 'wrong_secret' ? 'not-the-merchant-secret' : EsewaSimulator::MERCHANTS[$booking['product_code']];
        $data['signature'] = self::signature($data, self::RETURN_SIGNED_FIELDS, $secret);

        if ($variant === 'tampered') {
            $data['total_amount'] = self::rupees((int) $booking['settled'] * 10);
        } elseif ($variant === 'unsigned') {
            unset($data['signature']);
        }

        $url = (string) $booking['success_url'];

        return $url.(str_contains($url, '?') ? '&' : '?').'data='.rawurlencode(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)));
    }

    /**
     * @param  array<string, mixed>  $booking
     */
    private function save(array $booking): void
    {
        $this->scenario->state()->put('bookings', $booking['product_code'].':'.$booking['transaction_uuid'], $booking);
    }

    private static function html(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><title>Test payment</title>'.$body);
    }
}
