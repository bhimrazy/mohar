<?php

namespace App\Modules\PaymentsCybersource\Drivers;

use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\LooksUpRefunds;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\CallbackEvent;
use App\Data\Billing\CallbackRejection;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\RefundRequest;
use App\Data\Billing\ReturnHint;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\PaymentsCybersource\Support\CybersourceException;
use App\Modules\PaymentsCybersource\Support\CybersourceRest;
use App\Support\Billing\AbstractGatewayDriver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Cybersource Secure Acceptance Hosted Checkout, as NIC Asia Bank provisions it (payments-gateways-expansion.md §6.2).
 *
 * initiate() returns a form the browser posts to Cybersource's hosted page, HMAC-signed with the profile's secret key;
 * the customer types the card there, so no card data reaches us. The signed browser return and the signed notice
 * (the profile's merchant POST URL) are hints only: what settles is the REST lookup (transaction search by our
 * reference, then the transaction), authenticated with the REST key. The gateway cannot be activated without REST
 * keys, and refunds go through REST too.
 */
final class CybersourceDriver extends AbstractGatewayDriver implements ConnectionTester, HostedPaymentDriver, LooksUpRefunds
{
    public const KEY = 'cybersource';

    /** Fields we sign on the request, in this order; the form carries no unsigned field. */
    public const REQUEST_SIGNED_FIELDS = 'access_key,profile_id,transaction_uuid,signed_field_names,unsigned_field_names,signed_date_time,locale,transaction_type,reference_number,amount,currency,override_custom_receipt_page,override_custom_cancel_page';

    /** Fields a return or notice must sign for it to count. */
    private const RESPONSE_REQUIRED = ['decision', 'req_access_key', 'req_profile_id', 'req_reference_number', 'req_transaction_uuid', 'req_amount', 'req_currency', 'signed_field_names', 'signed_date_time'];

    /** Field names Cybersource may sign on a return; a signed name outside these makes the return invalid. */
    private const RESPONSE_SIGNABLE = '/^(req_[a-z0-9_]{1,60}|auth_[a-z0-9_]{1,60}|payer_authentication_[a-z0-9_]{1,60}|score_[a-z0-9_]{1,60}|decision(_[a-z0-9_]{1,60})?|bill_trans_ref_no|card_type_name|message|reason_code|transaction_id|request_token|invalid_fields|required_fields|utf8|signed_field_names|signed_date_time)$/';

    /** Transaction statuses that mean the payment is waiting for Decision Manager or the processor. */
    private const IN_REVIEW = ['PENDING_REVIEW', 'PENDING', 'REVIEW'];

    private const DECLINED = ['DECLINED', 'REJECTED', 'FAILED', 'CANCELLED'];

    /** Statuses of a payment taken back after it was made: staff look at it, whatever the application codes say. */
    private const TAKEN_BACK = ['VOIDED', 'REVERSED'];

    public function __construct(private CybersourceRest $rest) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Cards (Cybersource, NIC Asia)';
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP, self::WEBHOOK, self::REFUND, self::PARTIAL_REFUND];
    }

    public function currencies(): array
    {
        // NPR, plus USD only where the bank agreement allows it (owner decision Q4): CYBERSOURCE_CURRENCIES=NPR,USD.
        // Other invoice currencies need the gateway's "Convert to" set to one of these.
        $configured = config('services.cybersource.currencies', 'NPR');
        $configured = is_array($configured) ? $configured : explode(',', is_string($configured) ? $configured : 'NPR');
        $currencies = array_values(array_intersect(['NPR', 'USD'], array_map(fn (mixed $code): string => strtoupper(trim(is_string($code) ? $code : '')), $configured)));

        return $currencies === [] ? ['NPR'] : $currencies;
    }

    /**
     * @return list<SettingField>
     */
    public function settingsFields(): array
    {
        $rest = ' Required: card payments are confirmed only through the REST API, so the gateway can\'t be activated without it.';

        return [
            new SettingField(
                key: 'sandbox',
                label: 'Test mode',
                type: FieldType::Boolean,
                help: 'Uses Cybersource\'s test hosts (testsecureacceptance and apitest.cybersource.com); no real money moves. Not available in production.',
                default: true,
            ),
            new SettingField(
                key: 'merchant_id',
                label: 'Merchant ID',
                required: true,
                help: 'Your Cybersource organisation ID, given by NIC Asia Bank with the service agreement.',
            ),
            new SettingField(
                key: 'profile_id',
                label: 'Secure Acceptance profile ID',
                required: true,
                help: 'From the Business Center, Payment Configuration → Secure Acceptance. In the profile, set the customer response page to the return URL below and the merchant POST URL to the webhook URL below.',
            ),
            new SettingField(
                key: 'access_key',
                label: 'Profile access key',
                required: true,
                help: 'Created with the profile\'s security key. It is sent in the payment form, so it is not a secret.',
            ),
            new SettingField(
                key: 'secret_key',
                label: 'Profile secret key',
                type: FieldType::Secret,
                required: true,
                help: 'Created with the access key; signs the payment form and checks Cybersource\'s replies.',
            ),
            new SettingField(
                key: 'rest_key_id',
                label: 'REST key ID',
                required: true,
                help: 'Business Center, Key Management: a REST shared secret key.'.$rest,
            ),
            new SettingField(
                key: 'rest_secret',
                label: 'REST shared secret',
                type: FieldType::Secret,
                required: true,
                help: 'The base64 shared secret shown once when the REST key is created.'.$rest,
            ),
        ];
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        $min = config('payments_cybersource.min_amount', 100);

        return new AmountLimits(min: is_numeric($min) ? (int) $min : 100);
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        $minutes = config('payments_cybersource.attempt_ttl_minutes', 60);

        return CarbonInterval::minutes(is_numeric($minutes) ? max(15, (int) $minutes) : 60);
    }

    /**
     * A fresh random transaction_uuid per attempt is its provider reference; reference_number is our attempt
     * reference, which the REST search finds the payment by. Cancel posts back to the cancel URL.
     */
    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): FormPost
    {
        if (! in_array($intent->amount->currency, $this->currencies(), true)) {
            throw new CybersourceException('This Cybersource profile takes '.implode(', ', $this->currencies()).", not {$intent->amount->currency}.");
        }

        if (! $intent->amount->isPositive()) {
            throw new CybersourceException('Card payments need a positive amount.');
        }

        [$accessKey, $profileId, $secret] = $this->profile($gateway);
        // Checked here too, so no payment starts that the REST lookup could never confirm.
        CybersourceRest::credentials($gateway);
        $uuid = bin2hex(random_bytes(16));
        $fields = [
            'access_key' => $accessKey,
            'profile_id' => $profileId,
            'transaction_uuid' => $uuid,
            'signed_field_names' => self::REQUEST_SIGNED_FIELDS,
            'unsigned_field_names' => '',
            'signed_date_time' => gmdate('Y-m-d\TH:i:s\Z'),
            // Docs examples use en-us; to verify on the evaluation account (spec §15).
            'locale' => 'en',
            'transaction_type' => 'sale',
            'reference_number' => $intent->reference,
            'amount' => $intent->amount->toDecimal(),
            'currency' => $intent->amount->currency,
            'override_custom_receipt_page' => $intent->returnUrl,
            'override_custom_cancel_page' => $intent->cancelUrl,
        ];
        $fields['signature'] = self::signature($fields, self::REQUEST_SIGNED_FIELDS, $secret);

        return new FormPost(CybersourceRest::endpoint($gateway, 'pay')->baseUrl, $fields, providerRef: $uuid);
    }

    /**
     * The signed fields Cybersource posts back. A valid signature names the attempt; anything else is no hint
     * beyond the `ref` our own cancel URL carries. Card fields (masked as they are) are never kept.
     */
    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $fallback = $request->query('ref');
        $fallback = is_string($fallback) && self::isReference($fallback) ? $fallback : null;
        $fields = $this->verified($gateway, self::posted($request));

        if ($fields === null) {
            return new ReturnHint($fallback, raw: ['signature_valid' => false]);
        }

        $decision = strtoupper($fields['decision']);

        return new ReturnHint(
            reference: $fields['req_reference_number'],
            providerRef: $fields['req_transaction_uuid'],
            claimedStatus: $decision,
            signatureValid: true,
            raw: [...self::hintRaw($fields), 'signature_valid' => true],
            signedPaid: $decision === 'ACCEPT',
            signedCancel: $decision === 'CANCEL',
        );
    }

    /**
     * The notice to the merchant POST URL: the same signed fields. It only asks Billing to look the attempt up now.
     */
    public function parseCallback(GatewayConfig $gateway, Request $request): CallbackEvent|CallbackRejection
    {
        $fields = $this->verified($gateway, self::posted($request));

        if ($fields === null) {
            return new CallbackRejection(CallbackRejection::INVALID_SIGNATURE);
        }

        // Signed, so Billing's five-minute replay window applies to it.
        $sentAt = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $fields['signed_date_time']) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $fields['signed_date_time'], 'UTC')
            : null;

        return CallbackEvent::attemptUpdated($fields['req_reference_number'], $fields['transaction_id'] ?? $fields['req_transaction_uuid'], self::hintRaw($fields), $sentAt);
    }

    /**
     * Search by our reference, then read each payment found (only the newest while the customer waits, so a return
     * costs at most two short calls). Succeeded only for one authorised and captured sale under
     * our reference; Billing then checks its amount and currency against the attempt. REST has no record of a
     * session the customer cancelled, so "not found" ends the session early only when this lookup follows a return
     * carrying Cybersource's signed CANCEL for this reference (PaymentAttemptRef::$signedCancel, set by Billing).
     */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        try {
            $search = $this->search($gateway, 'clientReferenceInformation.code:'.$attempt->reference, $attempt->interactive);

            if (! $search->successful()) {
                return self::unavailable($search);
            }

            $details = [];

            $rows = self::rows($search, fn (array $row): bool => self::isPayment($row) && data_get($row, 'clientReferenceInformation.code') === $attempt->reference);

            // Several payments under one reference need every one read: left to the poller, not the waiting customer.
            if ($attempt->interactive && count($rows) > 1) {
                return new LookupResult(LookupResult::PENDING, raw: ['found' => count($rows)], retryable: true);
            }

            foreach (array_slice($rows, 0, 3) as $row) {
                $detail = $this->rest->send($gateway, 'GET', 'tss/v2/transactions/'.rawurlencode((string) $row['id']), interactive: $attempt->interactive, retry: true);

                if (! $detail->successful() || ! is_array($detail->json())) {
                    return self::unavailable($detail);
                }

                $details[] = $detail->json();
            }
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => 'Cybersource unreachable'], retryable: true);
        } catch (CybersourceException $exception) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => $exception->getMessage()], retryable: true);
        }

        $cancelled = $attempt->signedCancel;

        // The search may not have indexed a payment made seconds ago: while the customer waits, "nothing yet" is left
        // to the poller rather than read as not found (which would send a signed ACCEPT straight to staff review).
        if ($details === [] && $attempt->interactive && ! $cancelled) {
            return new LookupResult(LookupResult::PENDING, raw: ['found' => 0], retryable: true);
        }

        if ($details === []) {
            return new LookupResult(LookupResult::NOT_FOUND, raw: ['found' => 0], sessionEnded: $cancelled);
        }

        return self::classify($details, $attempt->reference);
    }

    /**
     * A follow-on refund of the captured payment, for all or part of it, carrying our refund reference so
     * lookupRefund() can find it. Cybersource takes no idempotency key, so this is never retried.
     */
    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        if (preg_match('/^\d{6,32}$/', $request->providerTxnId) !== 1 || ! $request->amount->isPositive()) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: 'Cybersource refunds need the payment\'s transaction id and a positive amount');
        }

        try {
            $response = $this->rest->send($gateway, 'POST', 'pts/v2/payments/'.$request->providerTxnId.'/refunds', [
                'clientReferenceInformation' => ['code' => self::refundReference($request)],
                'orderInformation' => ['amountDetails' => ['totalAmount' => $request->amount->toDecimal(), 'currency' => $request->amount->currency]],
            ]);
        } catch (ConnectionException) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'no answer from Cybersource; check the refund before trying again', raw: ['payment' => $request->providerTxnId]);
        } catch (CybersourceException $exception) {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: mb_substr($exception->getMessage(), 0, 150));
        }

        $id = $response->json('id');
        $status = is_string($response->json('status')) ? strtoupper($response->json('status')) : null;
        $raw = array_filter(['http_status' => $response->status(), 'refund' => is_string($id) ? $id : null, 'status' => $status, 'payment' => $request->providerTxnId,
            'error' => $response->successful() ? null : CybersourceRest::summary($response)], fn (mixed $value): bool => $value !== null);

        if ($response->serverError() || $response->status() === 429) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: CybersourceRest::summary($response), raw: $raw);
        }

        // Any other 2xx (an unknown status, or no refund id) stays pending: the refund check decides, so the amount is
        // never released while money may already be on its way back. Only an explicit refusal or a 4xx declines.
        return match (true) {
            $response->successful() && is_string($id) && in_array($status, ['PENDING', 'TRANSMITTED', 'COMPLETED', 'SUCCEEDED'], true) => new ChargeResult(ChargeResult::SUCCESS, $id, raw: $raw),
            $response->successful() && ! in_array($status, ['DECLINED', 'INVALID_REQUEST', 'FAILED'], true) => new ChargeResult(ChargeResult::ERROR, is_string($id) ? $id : null, declineReason: 'Cybersource answered with refund status '.($status ?? 'none').(is_string($id) ? '' : ' and no refund id').'; check the refund', raw: $raw),
            default => new ChargeResult(ChargeResult::DECLINED, is_string($id) ? $id : null, declineReason: mb_substr(CybersourceRest::summary($response), 0, 150), raw: $raw),
        };
    }

    /**
     * Find the refund by our refund reference under the payment. Not found is a definite no only once the search has
     * had refund_search_lag_minutes to index it.
     */
    public function lookupRefund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        if ($request->reference === null || preg_match('/^\d{6,32}$/', $request->providerTxnId) !== 1) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'Cybersource refund checks need the payment\'s transaction id and our refund reference');
        }

        try {
            $search = $this->search($gateway, 'clientReferenceInformation.code:'.self::refundReference($request));
        } catch (ConnectionException|CybersourceException) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: 'no answer from Cybersource');
        }

        if (! $search->successful()) {
            return new ChargeResult(ChargeResult::ERROR, declineReason: CybersourceRest::summary($search));
        }

        // The search is not trusted to be exact: the row must be under this payment and carry our own refund reference.
        $ours = fn (array $row): bool => ($row['rootId'] ?? null) === $request->providerTxnId && ($row['id'] ?? null) !== $request->providerTxnId
            && data_get($row, 'clientReferenceInformation.code') === self::refundReference($request);

        foreach (self::rows($search, $ours) as $row) {
            $raw = ['refund' => (string) $row['id'], 'status' => data_get($row, 'applicationInformation.status'), 'payment' => $request->providerTxnId];

            if (self::minor(data_get($row, 'orderInformation.amountDetails.totalAmount')) !== $request->amount->amount || data_get($row, 'orderInformation.amountDetails.currency') !== $request->amount->currency) {
                // No refund id: a refund that is not ours as asked must never be matched to our row (raw keeps it).
                return new ChargeResult(ChargeResult::ERROR, declineReason: 'the refund at Cybersource has another amount or currency', raw: $raw);
            }

            return in_array(strtoupper((string) $raw['status']), self::DECLINED, true)
                ? new ChargeResult(ChargeResult::DECLINED, (string) $row['id'], declineReason: 'Cybersource refund '.strtolower((string) $raw['status']), raw: $raw)
                : new ChargeResult(ChargeResult::SUCCESS, (string) $row['id'], raw: $raw);
        }

        $lag = config('payments_cybersource.refund_search_lag_minutes', 30);

        return $request->sentAt === null || $request->sentAt->gt(now()->subMinutes(is_numeric($lag) ? (int) $lag : 30))
            ? new ChargeResult(ChargeResult::ERROR, declineReason: 'Cybersource has no refund with this reference yet, but may still index it')
            : new ChargeResult(ChargeResult::DECLINED, declineReason: 'Cybersource has no refund with this reference', raw: ['payment' => $request->providerTxnId]);
    }

    /**
     * Reads a transaction id that cannot exist: 404 means the REST key was accepted, 401 that it was not. The profile
     * keys are checked by Cybersource on the first payment form. Moves no money.
     */
    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        try {
            $mode = CybersourceRest::mode($gateway);
            $this->profile($gateway);
            $response = $this->rest->send($gateway, 'GET', 'tss/v2/transactions/0000000000000000000000');
        } catch (CybersourceException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        } catch (ConnectionException) {
            return new ConnectionCheck(false, __('Couldn\'t reach Cybersource. Check the server\'s outbound connection.'));
        }

        return match (true) {
            $response->status() === 404 || $response->successful() => new ConnectionCheck(true, __('Cybersource accepted the merchant ID and REST key (:mode mode). The profile keys are checked on the first payment.', ['mode' => $mode])),
            in_array($response->status(), [401, 403], true) => new ConnectionCheck(false, __('Cybersource rejected the merchant ID or REST key (:mode mode).', ['mode' => $mode])),
            default => new ConnectionCheck(false, __('Cybersource answered with :summary. Try again later.', ['summary' => CybersourceRest::summary($response)])),
        };
    }

    /**
     * Base64 HMAC-SHA256 over "name=value" pairs joined by "," in the order of $signedFieldNames.
     *
     * @param  array<string, string>  $values
     */
    public static function signature(array $values, string $signedFieldNames, #[\SensitiveParameter] string $secret): string
    {
        $message = implode(',', array_map(fn (string $name): string => $name.'='.($values[$name] ?? ''), explode(',', $signedFieldNames)));

        return base64_encode(hash_hmac('sha256', $message, $secret, true));
    }

    /**
     * The posted fields when Cybersource signed them with our secret for our profile: every posted field but the
     * signature is signed, every signed field is posted, known and unique, and the required ones are there.
     *
     * @param  array<array-key, mixed>  $posted
     * @return array<string, string>|null
     */
    private function verified(GatewayConfig $gateway, array $posted): ?array
    {
        if (count($posted) > 200) {
            return null;
        }

        $fields = [];

        foreach ($posted as $name => $value) {
            if (! is_string($name) || ! is_string($value) || strlen($value) > 4096) {
                return null;
            }

            $fields[$name] = $value;
        }

        $signature = $fields['signature'] ?? '';
        $names = ($fields['signed_field_names'] ?? '') === '' ? [] : explode(',', $fields['signed_field_names']);
        unset($fields['signature']);

        if ($signature === '' || $names === [] || count($names) !== count(array_unique($names)) || array_diff(self::RESPONSE_REQUIRED, $names) !== []
            || array_diff(array_keys($fields), $names) !== [] || array_diff($names, array_keys($fields)) !== []) {
            return null;
        }

        try {
            [$accessKey, $profileId, $secret] = $this->profile($gateway);
        } catch (CybersourceException) {
            return null;
        }

        if (! hash_equals(self::signature($fields, $fields['signed_field_names'], $secret), $signature)) {
            return null;
        }

        $unknown = array_values(array_filter($names, fn (string $name): bool => preg_match(self::RESPONSE_SIGNABLE, $name) !== 1));

        if ($unknown !== []) {
            // Only for a valid signature, so no anonymous sender writes names to the log: a real profile signing a
            // field we don't list. Names only, never values.
            Log::warning('Cybersource reply refused: it signs fields outside the allowed list.', ['gateway' => self::KEY, 'fields' => array_slice(array_map(fn (string $name): string => mb_substr($name, 0, 64), $unknown), 0, 20)]);

            return null;
        }

        return $fields['req_access_key'] === $accessKey && $fields['req_profile_id'] === $profileId && self::isReference($fields['req_reference_number'])
            ? $fields : null;
    }

    /**
     * The posted fields exactly as sent: the raw form body, since the request middleware trims values and turns empty
     * ones into null, which would break a signature over exact values.
     *
     * @return array<array-key, mixed>
     */
    private static function posted(Request $request): array
    {
        $body = $request->getContent();

        if ($body === '' || strlen($body) > 65_536) {
            return array_map(fn (mixed $value): mixed => $value ?? '', $request->request->all());
        }

        parse_str($body, $fields);

        return $fields;
    }

    /**
     * @param  list<array<array-key, mixed>>  $details  transactions found under our reference
     */
    private static function classify(array $details, string $reference): LookupResult
    {
        $paid = $review = $uncaptured = $declined = $unknown = $takenBack = [];

        foreach ($details as $detail) {
            $status = strtoupper((string) data_get($detail, 'applicationInformation.status', ''));
            $applications = collect((array) data_get($detail, 'applicationInformation.applications', []))->filter(fn (mixed $app): bool => is_array($app))
                ->mapWithKeys(fn (array $app): array => [(string) ($app['name'] ?? '') => ($app['rCode'] ?? null) === '1' || strtoupper((string) ($app['status'] ?? '')) === 'SUCCESS']);

            match (true) {
                data_get($detail, 'clientReferenceInformation.code') !== $reference => $unknown[] = $detail,
                in_array($status, self::TAKEN_BACK, true) => $takenBack[] = $detail,
                ($applications['ics_auth'] ?? false) && ($applications['ics_bill'] ?? false) => $paid[] = $detail,
                in_array($status, self::IN_REVIEW, true) => $review[] = $detail,
                in_array($status, self::DECLINED, true) || ($applications->has('ics_auth') && ! $applications['ics_auth']) => $declined[] = $detail,
                $status === 'AUTHORIZED' => $uncaptured[] = $detail,
                default => $unknown[] = $detail,
            };
        }

        $first = $paid[0] ?? $takenBack[0] ?? $review[0] ?? $uncaptured[0] ?? $unknown[0] ?? $declined[0];
        $raw = self::lookupRaw($first);
        $amount = self::minor(data_get($first, 'orderInformation.amountDetails.totalAmount'));
        $currency = data_get($first, 'orderInformation.amountDetails.currency');
        $authorised = data_get($first, 'orderInformation.amountDetails.authorizedAmount');

        return match (true) {
            count($paid) > 1 => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Cybersource holds more than one captured payment for this reference']),
            $takenBack !== [] => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Cybersource shows the payment voided or reversed']),
            // A paid answer without both is never a success (Billing would hold it for review too).
            count($paid) === 1 && ($amount === null || ! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Cybersource answered without an amount or currency']),
            count($paid) === 1 && $authorised !== null && self::minor($authorised) !== $amount => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Cybersource authorised a different amount than the sale (partial authorisation)']),
            count($paid) === 1 => new LookupResult(LookupResult::SUCCEEDED, providerTxnId: is_string($first['id'] ?? null) ? $first['id'] : null, amount: $amount, currency: $currency, raw: $raw),
            $review !== [] => new LookupResult(LookupResult::PROCESSING, raw: $raw),
            $uncaptured !== [] => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Cybersource authorised the card but did not capture the sale']),
            $unknown !== [] => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'Cybersource answered with a transaction this driver cannot read']),
            default => new LookupResult(LookupResult::FAILED, raw: [...$raw, 'error' => 'Card declined (reason code '.($raw['reason_code'] ?? 'none').')']),
        };
    }

    /**
     * @throws ConnectionException
     */
    private function search(GatewayConfig $gateway, string $query, bool $interactive = false): Response
    {
        return $this->rest->send($gateway, 'POST', 'tss/v2/searches', [
            'save' => false, 'name' => 'payment lookup', 'timezone' => 'UTC', 'query' => $query, 'offset' => 0, 'limit' => 20, 'sort' => 'id:desc',
        ], $interactive, retry: true);
    }

    /**
     * @param  callable(array<array-key, mixed>): bool  $keep
     * @return list<array<array-key, mixed>>
     */
    private static function rows(Response $search, callable $keep): array
    {
        $rows = $search->json('_embedded.transactionSummaries');

        return is_array($rows) ? array_values(array_filter($rows, fn (mixed $row): bool => is_array($row) && is_string($row['id'] ?? null) && $keep($row))) : [];
    }

    /**
     * A payment, not a follow-on (refund, void) whose rootId names another transaction.
     *
     * @param  array<array-key, mixed>  $row
     */
    private static function isPayment(array $row): bool
    {
        return ! isset($row['rootId']) || $row['rootId'] === $row['id'];
    }

    private static function unavailable(Response $response): LookupResult
    {
        $raw = ['http_status' => $response->status(), 'error' => CybersourceRest::summary($response)];

        // Rejected credentials never fix themselves: not retried, so the attempt reaches staff review after the grace period.
        return in_array($response->status(), [401, 403], true)
            ? new LookupResult(LookupResult::PENDING, raw: [...$raw, 'error' => 'Cybersource refused the REST key ('.$raw['error'].')'])
            : new LookupResult(LookupResult::PENDING, raw: $raw, retryable: $response->serverError() || $response->status() === 429 || $response->successful());
    }

    /**
     * @param  array<array-key, mixed>  $detail
     * @return array<string, string>
     */
    private static function lookupRaw(array $detail): array
    {
        return array_filter([
            'transaction_id' => is_string($detail['id'] ?? null) ? $detail['id'] : null,
            'status' => is_string(data_get($detail, 'applicationInformation.status')) ? data_get($detail, 'applicationInformation.status') : null,
            'reason_code' => is_scalar(data_get($detail, 'applicationInformation.reasonCode')) ? (string) data_get($detail, 'applicationInformation.reasonCode') : null,
            'amount' => is_string(data_get($detail, 'orderInformation.amountDetails.totalAmount')) ? data_get($detail, 'orderInformation.amountDetails.totalAmount') : null,
            'currency' => is_string(data_get($detail, 'orderInformation.amountDetails.currency')) ? data_get($detail, 'orderInformation.amountDetails.currency') : null,
            'reconciliation_id' => is_string($detail['reconciliationId'] ?? null) ? $detail['reconciliationId'] : null,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * What a return or notice tells staff, without card fields.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private static function hintRaw(array $fields): array
    {
        return array_filter([
            'decision' => mb_substr($fields['decision'], 0, 20),
            'reason_code' => isset($fields['reason_code']) ? mb_substr($fields['reason_code'], 0, 10) : null,
            'transaction_id' => isset($fields['transaction_id']) ? mb_substr($fields['transaction_id'], 0, 40) : null,
            'reference' => $fields['req_reference_number'],
            'total_amount' => mb_substr($fields['req_amount'], 0, 20),
            'currency' => mb_substr($fields['req_currency'], 0, 3),
        ], fn (?string $value): bool => $value !== null);
    }

    /** "1130.50" → 113050; null unless an exact non-negative amount with at most two decimals. */
    private static function minor(mixed $decimal): ?int
    {
        if (! is_string($decimal) || preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $decimal, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    private static function refundReference(RefundRequest $request): string
    {
        $reference = preg_replace('/[^\x21-\x7e]/', '', (string) ($request->reference ?? ''));

        return mb_substr($reference === '' || $reference === null ? 'refund-'.$request->providerTxnId : $reference, 0, 50);
    }

    /**
     * @return array{0: string, 1: string, 2: string} access key, profile id and secret key
     */
    private function profile(GatewayConfig $gateway): array
    {
        $values = array_map(fn (string $key): string => is_string($gateway->setting($key)) ? trim($gateway->setting($key)) : '', ['access_key', 'profile_id', 'secret_key']);

        if (in_array('', $values, true)) {
            throw new CybersourceException(__('Enter and save the Secure Acceptance profile ID, access key and secret key first.'));
        }

        return $values;
    }

    private static function isReference(string $value): bool
    {
        return preg_match('/^[a-z0-9-]{3,40}$/', $value) === 1;
    }
}
