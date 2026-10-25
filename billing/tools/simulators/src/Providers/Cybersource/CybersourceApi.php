<?php

namespace Mohar\Simulators\Providers\Cybersource;

use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Secure Acceptance Hosted Checkout as one merchant profile sees it: the signed form post that opens a session, the
 * hosted page, the signed browser return and the signed server notice. Amounts are decimal strings on the wire and
 * minor units in the state, so every comparison is exact.
 */
final class CybersourceApi
{
    /** Minutes a session stays payable after the form post. */
    public const SESSION_MINUTES = 30;

    /** Fields every form must sign. */
    public const REQUIRED_SIGNED = ['access_key', 'amount', 'currency', 'locale', 'profile_id', 'reference_number', 'signed_date_time', 'signed_field_names', 'transaction_type', 'transaction_uuid'];

    /** What each payer choice becomes: decision, reason code, message. */
    private const OUTCOMES = [
        'accept' => ['ACCEPT', '100', 'Request was processed successfully.'],
        'decline' => ['DECLINE', '203', 'General decline of the card.'],
        'review' => ['REVIEW', '480', 'The order is marked for review by Decision Manager.'],
        'error' => ['ERROR', '150', 'General system failure.'],
        'cancel' => ['CANCEL', null, 'The customer cancelled the payment.'],
    ];

    public function __construct(private readonly Scenario $scenario) {}

    /**
     * POST /pay: check the fields and the signature, open a session and send the browser to the payment page. A refusal
     * is an HTML error page, as the hosted page shows one; it never says which check failed beyond a short reason.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function book(array $input): Response
    {
        $fields = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $input);

        foreach (['access_key', 'signed_field_names', 'signature'] as $name) {
            if (($fields[$name] ?? '') === '') {
                return self::error(400, "The {$name} field is required.");
            }
        }

        $names = explode(',', $fields['signed_field_names']);

        if ($fields['access_key'] !== CybersourceSimulator::ACCESS_KEY
            || ! hash_equals(self::signature($fields, $fields['signed_field_names'], CybersourceSimulator::SECRET_KEY), $fields['signature'])) {
            return self::error(403, 'Access denied: the request signature does not match.');
        }

        // The signature covers every request field except the card fields (typed on the hosted page) and any the form lists as unsigned.
        $exempt = ['signature', 'card_number', 'card_cvn', ...explode(',', $fields['unsigned_field_names'] ?? '')];

        if (array_diff(self::REQUIRED_SIGNED, $names) !== [] || array_diff($names, array_keys($fields)) !== [] || array_diff(array_keys($fields), $names, $exempt) !== []) {
            return self::error(403, 'Access denied: a required field is not signed or not sent, or a sent field is not signed.');
        }

        $merchant = (array) $this->scenario->state()->get('config', 'merchant');
        $amount = CybersourceRest::minor($fields['amount']);
        $type = $fields['transaction_type'];

        $problem = match (true) {
            $fields['profile_id'] !== $merchant['profile_id'] => 'Unknown profile.',
            ! in_array($type, ['sale', 'authorization'], true) => 'Unsupported transaction type.',
            ! in_array($fields['currency'], $merchant['currencies'], true) => 'The currency is not enabled for this profile.',
            $amount === null || $amount <= 0 => 'The amount must be a positive decimal with at most two places.',
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $fields['signed_date_time']) !== 1 => 'signed_date_time must be UTC ISO 8601.',
            preg_match('/^[A-Za-z0-9-]{1,50}$/', $fields['transaction_uuid']) !== 1 => 'transaction_uuid may hold only letters, digits and hyphens.',
            preg_match('/^[\x20-\x7e]{1,50}$/', $fields['reference_number']) !== 1 => 'reference_number is 1 to 50 printable characters.',
            default => null,
        };

        $receipt = $fields['override_custom_receipt_page'] ?? $this->scenario->state()->get('config', 'receipt_url');
        $problem ??= is_string($receipt) && preg_match('#^https?://#', $receipt) === 1 ? null : 'The profile has no customer response page.';

        if ($problem !== null) {
            return self::error(400, $problem);
        }

        return $this->scenario->state()->transaction(function () use ($fields, $amount, $type, $receipt): Response {
            if ($this->scenario->state()->get('sessions', $fields['transaction_uuid']) !== null) {
                return self::error(400, 'This transaction_uuid was already used.');
            }

            $id = bin2hex(random_bytes(8));
            $this->scenario->state()->put('sessions', $fields['transaction_uuid'], [
                'id' => $id, 'fields' => $fields, 'type' => $type, 'amount' => $amount, 'currency' => $fields['currency'], 'receipt_url' => $receipt,
                'notice_url' => $fields['override_backoffice_post_url'] ?? null, 'status' => 'OPEN', 'choice' => null, 'txn_id' => null, 'expires_at' => time() + 60 * self::SESSION_MINUTES,
            ]);
            $this->scenario->state()->put('session_ids', $id, $fields['transaction_uuid']);

            return new Response(303, ['Location' => $this->scenario->apiUrl().'/pay/session/'.$id]);
        });
    }

    /** GET /pay/session/{id}: the payment page. It holds no card inputs; the buttons are the five outcomes. */
    public function page(string $id): Response
    {
        $session = $this->sessionById($id);

        if ($session === null || $session['status'] !== 'OPEN') {
            return self::html(410, '<p>This payment session has ended.</p>');
        }

        $buttons = '';

        foreach (array_keys(self::OUTCOMES) as $choice) {
            $buttons .= '<form method="post" action="/pay/session/'.$id.'/'.$choice.'"><button>'.ucfirst($choice).'</button></form>';
        }

        return self::html(200, '<p>'.htmlspecialchars($session['currency'].' '.number_format($session['amount'] / 100, 2)).'</p><p>'.htmlspecialchars($session['fields']['reference_number']).'</p>'.$buttons);
    }

    /**
     * The payer's choice, from the page or the control plane. The first choice decides the session; choosing the same
     * again returns the same signed fields (a browser refresh re-posting the return) and books nothing twice.
     *
     * Options: `settled_amount` (decimal) and `settled_currency` make the transaction the REST API reports differ from the
     * one the return claims; `variant` forges the return (`wrong_secret`, `tampered`, `unsigned`, `extra_field`, `missing_field`).
     *
     * @param  array<string, mixed>  $options
     * @return array{post: array{url: string, fields: array<string, string>}}|null null when the session is not open to that choice
     */
    public function choose(string $id, string $choice, array $options = []): ?array
    {
        return $this->scenario->state()->transaction(function () use ($id, $choice, $options): ?array {
            $session = $this->sessionById($id);

            if ($session === null || ! isset(self::OUTCOMES[$choice]) || ($session['status'] === 'DONE' && $session['choice'] !== $choice) || ! in_array($session['status'], ['OPEN', 'DONE'], true)) {
                return null;
            }

            if ($session['status'] === 'OPEN') {
                $session = $this->decide($session, $choice, $options);
            }

            return ['post' => ['url' => $session['receipt_url'], 'fields' => $this->returnFields($session, (string) ($options['variant'] ?? ''))]];
        });
    }

    /**
     * Decision Manager's later verdict on a REVIEW: `accept` captures a sale, `reject` declines it. Both send a notice.
     *
     * @return array{decision: string, reason_code: string}
     */
    public function resolveReview(string $uuid, string $outcome): array
    {
        return $this->scenario->state()->transaction(function () use ($uuid, $outcome): array {
            $session = $this->session($uuid);
            $transaction = $session === null ? null : $this->scenario->state()->get('txns', (string) $session['txn_id']);

            if (! is_array($transaction) || $transaction['status'] !== 'PENDING_REVIEW' || ! in_array($outcome, ['accept', 'reject'], true)) {
                throw new \InvalidArgumentException("Session {$uuid} has no review to resolve as {$outcome}.");
            }

            $accepted = $outcome === 'accept';
            $this->scenario->state()->put('txns', $transaction['id'], [
                ...$transaction,
                'status' => $accepted ? ($session['type'] === 'sale' ? 'TRANSMITTED' : 'AUTHORIZED') : 'REJECTED',
                'reason_code' => $accepted ? '100' : '481',
                'captured' => $accepted && $session['type'] === 'sale',
            ]);
            $session = [...$session, 'decision' => $accepted ? 'ACCEPT' : 'DECLINE', 'reason_code' => $accepted ? '100' : '481', 'message' => $accepted ? self::OUTCOMES['accept'][2] : 'The order was rejected by Decision Manager.'];
            $this->scenario->state()->put('sessions', $uuid, $session);
            $this->notify($session);

            return ['decision' => $session['decision'], 'reason_code' => $session['reason_code']];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function session(string $uuid): ?array
    {
        $session = $this->scenario->state()->get('sessions', $uuid);

        if (! is_array($session)) {
            return null;
        }

        if ($session['status'] === 'OPEN' && (int) $session['expires_at'] <= time()) {
            $session = [...$session, 'status' => 'EXPIRED'];
            $this->scenario->state()->put('sessions', $uuid, $session);
        }

        return $session;
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

    /** A 22-digit transaction id that sorts by creation time. */
    public static function newId(): string
    {
        return sprintf('%010d', time()).sprintf('%012d', random_int(0, 999_999_999_999));
    }

    /**
     * The browser's hop back to the merchant: a page that posts the signed fields to the customer response URL.
     *
     * @param  array{post: array{url: string, fields: array<string, string>}}  $outcome
     */
    public static function returnPage(array $outcome): Response
    {
        $inputs = '';

        foreach ($outcome['post']['fields'] as $name => $value) {
            $inputs .= '<input type="hidden" name="'.htmlspecialchars((string) $name).'" value="'.htmlspecialchars($value).'">';
        }

        return self::html(200, '<form id="return" method="post" action="'.htmlspecialchars($outcome['post']['url']).'">'.$inputs.'<button>Continue</button></form><script>document.getElementById("return").submit()</script>');
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function decide(array $session, string $choice, array $options): array
    {
        [$decision, $reason, $message] = self::OUTCOMES[$choice];
        $transaction = null;

        if (in_array($choice, ['accept', 'decline', 'review'], true)) {
            $settled = isset($options['settled_amount']) ? CybersourceRest::minor((string) $options['settled_amount']) : null;
            $transaction = [
                'id' => self::newId(), 'kind' => 'payment', 'root_id' => null, 'reference' => $session['fields']['reference_number'],
                'amount' => $settled ?? $session['amount'], 'currency' => (string) ($options['settled_currency'] ?? $session['currency']),
                'captured' => $choice === 'accept' && $session['type'] === 'sale', 'refunded' => 0, 'reason_code' => $reason, 'created' => time(),
                'status' => match ($choice) {
                    'accept' => $session['type'] === 'sale' ? 'TRANSMITTED' : 'AUTHORIZED',
                    'decline' => 'DECLINED',
                    default => 'PENDING_REVIEW',
                },
                'card' => ['prefix' => '411111', 'suffix' => '1111', 'type' => '001'],
            ];
            $this->scenario->state()->put('txns', $transaction['id'], $transaction);
        }

        $session = [...$session, 'status' => 'DONE', 'choice' => $choice, 'decision' => $decision, 'reason_code' => $reason, 'message' => $message, 'txn_id' => $transaction['id'] ?? null, 'decided_at' => time()];
        $this->scenario->state()->put('sessions', $session['fields']['transaction_uuid'], $session);
        $this->notify($session);

        return $session;
    }

    /**
     * The signed fields the customer response URL receives: every request field under `req_`, the decision, and a
     * signature over all of them.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, string>
     */
    private function returnFields(array $session, string $variant): array
    {
        $fields = ['decision' => $session['decision'], 'message' => $session['message']];

        if ($session['reason_code'] !== null) {
            $fields['reason_code'] = $session['reason_code'];
        }

        if ($session['txn_id'] !== null) {
            $fields += [
                'transaction_id' => $session['txn_id'], 'auth_code' => '831000', 'auth_amount' => CybersourceRest::decimal((int) $session['amount']), 'auth_response' => '00',
                'auth_time' => gmdate('Y-m-d\TH:i:s\Z', (int) $session['decided_at']), 'card_type_name' => 'Visa', 'req_card_number' => 'xxxxxxxxxxxx1111', 'req_card_type' => '001', 'req_card_expiry_date' => '12-2030',
            ];
        }

        foreach ($session['fields'] as $name => $value) {
            if ($name !== 'signature') {
                $fields['req_'.$name] = $value;
            }
        }

        $fields['signed_date_time'] = gmdate('Y-m-d\TH:i:s\Z', (int) $session['decided_at']);
        $fields['signed_field_names'] = implode(',', [...array_keys($fields), 'signed_field_names']);
        $secret = $variant === 'wrong_secret' ? 'not-the-profile-secret' : CybersourceSimulator::SECRET_KEY;
        $fields['signature'] = self::signature($fields, $fields['signed_field_names'], $secret);

        match ($variant) {
            'tampered' => $fields['req_amount'] = CybersourceRest::decimal((int) $session['amount'] * 10),
            'unsigned' => $fields = array_diff_key($fields, ['signature' => 1]),
            'extra_field' => $fields['injected'] = '1',
            'missing_field' => $fields = array_diff_key($fields, ['req_amount' => 1]),
            default => null,
        };

        return $fields;
    }

    /** Queue the signed server notice for the merchant notification URL, when there is one. */
    private function notify(array $session): void
    {
        $url = $session['notice_url'] ?? $this->scenario->state()->get('config', 'notice_url');

        if (is_string($url) && $url !== '') {
            $this->scenario->outbox()->enqueue($url, http_build_query($this->returnFields($session, '')), ['Content-Type' => 'application/x-www-form-urlencoded']);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sessionById(string $id): ?array
    {
        $uuid = $this->scenario->state()->get('session_ids', $id);

        return is_string($uuid) ? $this->session($uuid) : null;
    }

    private static function error(int $status, string $reason): Response
    {
        return self::html($status, '<p>'.htmlspecialchars($reason).'</p>');
    }

    private static function html(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><title>Test payment</title>'.$body);
    }
}
