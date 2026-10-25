<?php

namespace App\Modules\PaymentsNepal\Drivers;

use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\DescribesManualRefund;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Branding\BrandProvider;
use App\Contracts\Integrations\ProvidesLogo;
use App\Contracts\Integrations\ValidatesSettings;
use App\Data\Billing\AmountLimits;
use App\Data\Billing\FormPost;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\PaymentIntent;
use App\Data\Billing\ReturnHint;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\IntegrationLogo;
use App\Modules\PaymentsNepal\Support\NprAmount;
use App\Modules\PaymentsNepal\Support\ProviderException;
use App\Modules\PaymentsNepal\Support\ProviderHttp;
use App\Support\Billing\AbstractGatewayDriver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use OpenSSLAsymmetricKey;

/**
 * connectIPS (NCHL) creditor web gateway (docs/product/payments-gateways-expansion.md §4).
 *
 * initiate() returns a form the browser posts to NCHL's login page, signed with the merchant's RSA key (from the
 * uploaded .pfx). NCHL sends the customer back to the static return URL registered with it, appending only TXNID,
 * our short provider reference; the return carries no status. Only validatetxn (and gettxndetail for the fee, bank and
 * credit leg), called with the stored amount, settles. NCHL signs no answer, so every echoed field is compared with
 * the stored attempt. There is no refund API: the merchant refunds by bank transfer and records it.
 */
final class ConnectipsDriver extends AbstractGatewayDriver implements ConnectionTester, DescribesManualRefund, HostedPaymentDriver, ProvidesLogo, ValidatesSettings
{
    public const KEY = 'connectips';

    /** Form fields in token order, with NCHL's maximum lengths. */
    public const FORM_FIELDS = ['MERCHANTID' => 20, 'APPID' => 15, 'APPNAME' => 30, 'TXNID' => 20, 'TXNDATE' => 10, 'TXNCRNCY' => 3, 'TXNAMT' => 20, 'REFERENCEID' => 20, 'REMARKS' => 50, 'PARTICULARS' => 100];

    /**
     * Between the token's NAME=value pairs. NCHL's worked example has no space while its template shows ", "; we follow
     * the example until UAT proves it (spec §4.2, Q3), and this is the one place to change.
     */
    public const TOKEN_SEPARATOR = ',';

    /** creditStatus values that mean the credit leg to the merchant is done (DEFER: owed by NCHL's own rule). */
    public const CREDIT_DONE = ['000', '999', 'DEFER'];

    /** NCHL's date for TXNDATE, whatever the company time zone. */
    public const TIMEZONE = 'Asia/Kathmandu';

    private const TXNID_PATTERN = '/^[a-z2-7]{20}$/';

    public function __construct(private ProviderHttp $http, private BrandProvider $brand) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'connectIPS';
    }

    /** connectIPS's own logo from connectips.com, served from public/images/gateways; it reads on light and dark. */
    public function logo(): IntegrationLogo
    {
        return new IntegrationLogo(asset('images/gateways/connectips.png'), __('Pay with connectIPS'));
    }

    public function capabilities(): array
    {
        return [self::REDIRECT, self::LOOKUP];
    }

    public function currencies(): array
    {
        return ['NPR'];
    }

    public function manualRefundNote(): string
    {
        return __('connectIPS has no refund API and payments can\'t be reversed: refund the customer by bank transfer from your own account, then record the refund on the invoice.');
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'sandbox', 'label' => 'Sandbox mode', 'type' => 'boolean', 'default' => true,
                'help' => 'Uses connectIPS UAT; no real money moves. Not available in production. "Test connection" looks up a made-up transaction and moves no money.'],
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'string', 'required' => true,
                'help' => 'Emailed by NCHL after your bank enrols you as a creditor. Digits only.'],
            ['key' => 'app_id', 'label' => 'App ID', 'type' => 'string', 'required' => true,
                'help' => 'Issued by NCHL, one per settlement bank account (up to 15 characters).'],
            ['key' => 'app_name', 'label' => 'App name', 'type' => 'string', 'required' => true,
                'help' => 'Exactly as NCHL registered the app (up to 30 characters).'],
            ['key' => 'app_password', 'label' => 'App password', 'type' => 'secret', 'required' => true,
                'help' => 'Emailed by NCHL with the app ID; used to check payment status.'],
            ['key' => 'signing_key', 'label' => 'Signing certificate', 'type' => 'certificate', 'required' => true,
                'help' => 'The .pfx file NCHL issues (CREDITOR.pfx) and its passphrase. Only the private key is kept, encrypted.'],
        ];
    }

    /**
     * The formats NCHL's form takes: a digits-only merchant ID of up to 20, an app ID of up to 15 and an app name of up
     * to 30 characters. A comma would break the comma-joined token string.
     */
    public function validateSettings(array $settings): array
    {
        $errors = [];
        $merchantId = $settings['merchant_id'] ?? null;
        $appId = $settings['app_id'] ?? null;
        $appName = $settings['app_name'] ?? null;

        if ($merchantId !== null && preg_match('/^\d{1,20}$/', $merchantId) !== 1) {
            $errors['merchant_id'] = __('The connectIPS merchant ID is digits only, at most 20.');
        }

        if ($appId !== null && (strlen($appId) > 15 || str_contains($appId, ','))) {
            $errors['app_id'] = __('The connectIPS app ID is at most 15 characters, without commas.');
        }

        if ($appName !== null && (mb_strlen($appName) > 30 || str_contains($appName, ','))) {
            $errors['app_name'] = __('The connectIPS app name is at most 30 characters, without commas.');
        }

        return $errors;
    }

    public function limits(GatewayConfig $gateway): AmountLimits
    {
        return new AmountLimits(min: ProviderHttp::intConfig('connectips.min_amount', 100), max: ProviderHttp::intConfig('connectips.max_amount', 200_000_000));
    }

    public function attemptTtl(GatewayConfig $gateway): CarbonInterval
    {
        return CarbonInterval::minutes(max(15, ProviderHttp::intConfig('connectips.attempt_ttl_minutes', 30)));
    }

    /**
     * The signed form. TXNID is a fresh 20-character reference (Billing stores it as the provider reference), the
     * amount is paisa as NCHL wants it, and TXNDATE is today in Nepal time.
     */
    public function initiate(GatewayConfig $gateway, PaymentIntent $intent): FormPost
    {
        if ($intent->amount->currency !== 'NPR') {
            throw new ProviderException("connectIPS only takes NPR, not {$intent->amount->currency}.");
        }

        $credentials = $this->credentials($gateway);
        $expires = $gateway->certificate('signing_key')?->notAfter;

        if ($expires !== null && $expires->isPast()) {
            throw new ProviderException(__('The connectIPS signing certificate expired on :date. Upload the new .pfx from NCHL.', ['date' => $expires->toDateString()]));
        }

        $txnId = self::newTxnId();
        $fields = [
            'MERCHANTID' => $credentials['merchant_id'],
            'APPID' => $credentials['app_id'],
            'APPNAME' => $credentials['app_name'],
            'TXNID' => $txnId,
            'TXNDATE' => CarbonImmutable::now(self::TIMEZONE)->format('d-m-Y'),
            'TXNCRNCY' => 'NPR',
            'TXNAMT' => (string) $intent->amount->amount,
            // The invoice number is the description's last word ("Invoice INV-0042").
            'REFERENCEID' => self::clean((string) strrchr(' '.$intent->description, ' '), 20) ?: $txnId,
            'REMARKS' => self::clean($intent->description, 50) ?: 'Payment',
            'PARTICULARS' => self::clean($this->brand->brand()->name, 100) ?: 'Payment',
        ];
        $fields['TOKEN'] = self::sign(self::paymentMessage($fields), $credentials['key']);

        return new FormPost(ProviderHttp::endpoint($gateway, 'connectips.gateway')->url('loginpage'), $fields, providerRef: $txnId);
    }

    /**
     * NCHL appends only TXNID (success and failure alike). The invoice page's "check status" link carries our own
     * reference instead (?ref=). Either only names the attempt to look up.
     */
    public function parseReturn(GatewayConfig $gateway, Request $request): ReturnHint
    {
        $txnId = $request->input('TXNID');
        $txnId = is_string($txnId) && preg_match(self::TXNID_PATTERN, $txnId) === 1 ? $txnId : null;
        $reference = $request->query('ref');
        $reference = is_string($reference) && preg_match('/^[a-z0-9-]{3,40}$/', $reference) === 1 ? $reference : null;

        return new ReturnHint($reference, providerRef: $txnId, raw: ['txn_id' => $txnId ?? ($request->has('TXNID') ? 'unreadable' : 'none')]);
    }

    /**
     * validatetxn with the stored TXNID and amount; after a SUCCESS, gettxndetail for NCHL's transaction id, the payer's
     * bank, the fee and the credit leg.
     */
    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        if ($attempt->providerRef === null || $attempt->amount->currency !== 'NPR') {
            return new LookupResult(LookupResult::NOT_FOUND, raw: ['error' => 'no connectIPS TXNID for this attempt']);
        }

        try {
            $credentials = $this->credentials($gateway);
            $response = $this->call($gateway, $credentials, 'validatetxn', $attempt->providerRef, (string) $attempt->amount->amount, $attempt->interactive);
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => 'connectIPS unreachable'], retryable: true);
        } catch (ProviderException $exception) {
            return new LookupResult(LookupResult::PENDING, raw: ['error' => $exception->getMessage()], retryable: true);
        }

        $body = self::body($response);
        $status = self::status($body);
        $description = self::description($body);
        $raw = array_filter([
            'http_status' => $response->status(),
            'status' => $status,
            'status_desc' => $description,
            'txn_id' => is_string($body['referenceId'] ?? null) ? $body['referenceId'] : null,
            'txn_amt' => is_scalar($body['txnAmt'] ?? null) ? (string) $body['txnAmt'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ($response->status() === 401) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'connectIPS refused the app ID and password, or the token (HTTP 401): check the gateway settings']);
        }

        // A status only counts on a 2xx: a 5xx (whatever its body says) is left to the next lookup, any other refusal
        // or an answer without a status goes to staff review.
        if ($response->serverError() || ($status === null && $body === [] && $response->successful())) {
            return new LookupResult(LookupResult::PENDING, raw: [...$raw, 'error' => 'connectIPS answered HTTP '.$response->status()], retryable: true);
        }

        if ($status === null || ! $response->successful()) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'connectIPS answered HTTP '.$response->status().($status === null ? ' without a status' : ' '.$status)]);
        }

        $mismatch = self::echoMismatch($body, $credentials, $attempt, strict: $status === 'SUCCESS');

        if ($mismatch !== null) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'validatetxn answered for another '.$mismatch]);
        }

        return match (true) {
            $status === 'SUCCESS' => $this->detail($gateway, $credentials, $attempt, $raw),
            $status === 'FAILED' => new LookupResult(LookupResult::FAILED, raw: $raw),
            // The customer never paid (or cancelled on the login page): expires after the TTL and grace.
            $status === 'ERROR' && str_contains($description, 'NOT FOUND') => new LookupResult(LookupResult::NOT_FOUND, raw: $raw),
            // Left before the OTP: still payable until the attempt's own expiry.
            $status === 'ERROR' && str_contains($description, 'INCOMPLETE') => new LookupResult(
                $attempt->expiresAt !== null && $attempt->expiresAt->isPast() ? LookupResult::EXPIRED : LookupResult::PENDING,
                raw: $raw,
            ),
            default => new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'connectIPS answered '.$status.($description !== '' ? ' '.mb_substr($description, 0, 80) : '')]),
        };
    }

    /**
     * A made-up TXNID through validatetxn: 401 means a wrong app ID or password, "TRANSACTION NOT FOUND" means the
     * credentials and the token were accepted. NCHL's answer to a bad token is not documented, so anything else is
     * "could not confirm". Moves no money and books nothing.
     */
    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        try {
            $mode = ProviderHttp::mode($gateway);
            $credentials = $this->credentials($gateway);
            $response = $this->call($gateway, $credentials, 'validatetxn', self::newTxnId(), '100', interactive: false, single: true);
        } catch (ProviderException $exception) {
            return new ConnectionCheck(false, $exception->getMessage());
        } catch (ConnectionException) {
            return new ConnectionCheck(false, __('Couldn\'t reach connectIPS. Check the server\'s outbound connection.'));
        }

        $body = self::body($response);
        $description = self::description($body);

        return match (true) {
            $response->status() === 401 => new ConnectionCheck(false, __('connectIPS rejected the app ID or app password (:mode mode). If they are right, check the signing certificate.', ['mode' => $mode])),
            $response->successful() && self::status($body) === 'ERROR' && str_contains($description, 'NOT FOUND') => new ConnectionCheck(true, __('connectIPS accepted the app ID, password and signing key (:mode mode).', ['mode' => $mode])),
            default => new ConnectionCheck(false, __('connectIPS answered, but the credentials could not be confirmed (:mode mode): :reason', [
                'mode' => $mode,
                'reason' => $description !== '' ? mb_substr($description, 0, 80) : 'HTTP '.$response->status(),
            ])),
        };
    }

    /**
     * The payment token's text: the ten form fields as NAME=value in FORM_FIELDS order, then TOKEN=TOKEN.
     *
     * @param  array<string, string>  $fields
     */
    public static function paymentMessage(array $fields): string
    {
        $pairs = array_map(fn (string $name): string => $name.'='.($fields[$name] ?? ''), array_keys(self::FORM_FIELDS));

        return implode(self::TOKEN_SEPARATOR, [...$pairs, 'TOKEN=TOKEN']);
    }

    /** The validatetxn and gettxndetail token's text (signed like the payment token: spec §4.2, to prove in UAT). */
    public static function validationMessage(string $merchantId, string $appId, string $txnId, string $paisa): string
    {
        return "MERCHANTID={$merchantId},APPID={$appId},REFERENCEID={$txnId},TXNAMT={$paisa}";
    }

    /**
     * gettxndetail after a SUCCESS. The credit leg must be done, or deferred by NCHL's rule, for the payment to count;
     * the fee is ours only when the merchant bears it. Nothing settles without it (spec §4.1.5, amended in #491). During
     * an outage the payment is PROCESSING: validatetxn says the money left the customer, so the invoice stays locked,
     * the poller books it once the details answer, and staff review it after the grace period. Any other failure leaves
     * the credit leg unconfirmed for staff.
     *
     * @param  array{merchant_id: string, app_id: string, app_name: string, app_password: string, key: OpenSSLAsymmetricKey}  $credentials
     * @param  array<string, mixed>  $raw
     */
    private function detail(GatewayConfig $gateway, array $credentials, PaymentAttemptRef $attempt, array $raw): LookupResult
    {
        try {
            $response = $this->call($gateway, $credentials, 'gettxndetail', (string) $attempt->providerRef, (string) $attempt->amount->amount, $attempt->interactive);
        } catch (ConnectionException) {
            return new LookupResult(LookupResult::PROCESSING, raw: [...$raw, 'error' => 'gettxndetail unreachable']);
        }

        $body = self::body($response);

        if ($response->serverError()) {
            return new LookupResult(LookupResult::PROCESSING, raw: [...$raw, 'error' => 'gettxndetail answered HTTP '.$response->status()]);
        }

        if (! $response->successful() || self::status($body) === null) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, 'error' => 'credit leg unconfirmed: gettxndetail answered HTTP '.$response->status().(self::description($body) !== '' ? ' '.mb_substr(self::description($body), 0, 60) : '')]);
        }

        $creditStatus = is_scalar($body['creditStatus'] ?? null) ? strtoupper(trim((string) $body['creditStatus'])) : null;
        $liability = is_string($body['chargeLiability'] ?? null) ? strtoupper(trim($body['chargeLiability'])) : null;
        $extra = array_filter([
            'nchl_txn_id' => is_scalar($body['txnId'] ?? null) ? (string) $body['txnId'] : null,
            'batch_id' => is_scalar($body['batchId'] ?? null) ? (string) $body['batchId'] : null,
            'debit_bank_code' => is_scalar($body['debitBankCode'] ?? null) ? mb_substr((string) $body['debitBankCode'], 0, 20) : null,
            'charge_amt' => is_scalar($body['chargeAmt'] ?? null) ? (string) $body['chargeAmt'] : null,
            'charge_liability' => $liability,
            'credit_status' => $creditStatus,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $mismatch = self::status($body) !== 'SUCCESS' ? 'status ('.mb_substr((string) self::status($body), 0, 20).')' : self::echoMismatch($body, $credentials, $attempt, strict: true);

        if ($mismatch !== null) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, ...$extra, 'error' => 'gettxndetail disagrees with validatetxn: '.$mismatch]);
        }

        if (! in_array($creditStatus, self::CREDIT_DONE, true)) {
            return new LookupResult(LookupResult::AMBIGUOUS, raw: [...$raw, ...$extra, 'error' => 'paid, but the credit to the merchant is not done (creditStatus '.($creditStatus ?? 'missing').')']);
        }

        return new LookupResult(
            LookupResult::SUCCEEDED,
            providerTxnId: $extra['nchl_txn_id'] ?? (string) $attempt->providerRef,
            amount: $attempt->amount->amount,
            currency: 'NPR',
            fee: $liability === 'MN' ? (NprAmount::toPaisa($body['chargeAmt'] ?? null) ?? 0) : 0,
            payerRef: $extra['debit_bank_code'] ?? null,
            raw: [...$raw, ...$extra],
        );
    }

    /**
     * POST to validatetxn or gettxndetail: Basic auth with the app ID and password, and the signed JSON body.
     *
     * @param  array{merchant_id: string, app_id: string, app_name: string, app_password: string, key: OpenSSLAsymmetricKey}  $credentials
     *
     * @throws ConnectionException
     */
    private function call(GatewayConfig $gateway, array $credentials, string $operation, string $txnId, string $paisa, bool $interactive, bool $single = false): Response
    {
        $endpoint = ProviderHttp::endpoint($gateway, 'connectips.api');
        $request = $single ? $this->http->request() : $this->http->lookup($interactive);

        return $request->withOptions($endpoint->options)
            ->withBasicAuth($credentials['app_id'], $credentials['app_password'])
            ->asJson()
            ->post($endpoint->url($operation), [
                'merchantId' => $credentials['merchant_id'],
                'appId' => $credentials['app_id'],
                'referenceId' => $txnId,
                'txnAmt' => $paisa,
                'token' => self::sign(self::validationMessage($credentials['merchant_id'], $credentials['app_id'], $txnId, $paisa), $credentials['key']),
            ]);
    }

    /**
     * What an answer says it is about, against the stored attempt: null when it matches, else what differs. With
     * $strict (a SUCCESS), every field must be present. txnAmt is read by its form (NCHL's examples show both): bare
     * digits are paisa and a value with a decimal point is rupees, so "1130" never matches Rs 1,130.00.
     *
     * @param  array<string, mixed>  $body
     * @param  array{merchant_id: string, app_id: string}  $credentials
     */
    private static function echoMismatch(array $body, array $credentials, PaymentAttemptRef $attempt, bool $strict): ?string
    {
        $expected = ['referenceId' => (string) $attempt->providerRef, 'merchantId' => $credentials['merchant_id'], 'appId' => $credentials['app_id']];

        foreach ($expected as $name => $value) {
            $echoed = is_scalar($body[$name] ?? null) ? trim((string) $body[$name]) : null;

            if (($echoed === null && $strict) || ($echoed !== null && $echoed !== $value)) {
                return match ($name) {
                    'referenceId' => 'transaction',
                    'merchantId' => 'merchant',
                    default => 'app',
                };
            }
        }

        $amount = $body['txnAmt'] ?? null;

        if ($amount === null) {
            return $strict ? 'amount' : null;
        }

        return self::paisa($amount) === $attempt->amount->amount ? null : 'amount';
    }

    /**
     * An echoed txnAmt in paisa: an integer or bare digits are paisa, a float or a string with a decimal point is
     * rupees; anything else is null.
     */
    private static function paisa(mixed $amount): ?int
    {
        return match (true) {
            is_int($amount) => $amount,
            is_float($amount) => NprAmount::toPaisa($amount),
            is_string($amount) && preg_match('/^\d{1,15}$/', trim($amount)) === 1 => (int) trim($amount),
            is_string($amount) && preg_match('/^\d{1,13}\.\d{1,6}$/', trim($amount)) === 1 => NprAmount::toPaisa(trim($amount)),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function status(array $body): ?string
    {
        return is_string($body['status'] ?? null) && trim($body['status']) !== '' ? strtoupper(trim($body['status'])) : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function description(array $body): string
    {
        return is_string($body['statusDesc'] ?? null) ? strtoupper(trim($body['statusDesc'])) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * @return array{merchant_id: string, app_id: string, app_name: string, app_password: string, key: OpenSSLAsymmetricKey}
     */
    private function credentials(GatewayConfig $gateway): array
    {
        $value = fn (string $key): string => is_scalar($gateway->setting($key)) ? trim((string) $gateway->setting($key)) : '';
        $merchantId = $value('merchant_id');
        $appId = $value('app_id');
        $appName = $value('app_name');
        $password = $value('app_password');
        $pem = $value('signing_key');

        if ($merchantId === '' || $appId === '' || $appName === '' || $password === '' || $pem === '') {
            throw new ProviderException(__('Enter and save the connectIPS merchant ID, app ID, app name, app password and signing certificate first.'));
        }

        $errors = $this->validateSettings(['merchant_id' => $merchantId, 'app_id' => $appId, 'app_name' => $appName]);

        if ($errors !== []) {
            throw new ProviderException(implode(' ', $errors));
        }

        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new ProviderException(__('The saved connectIPS signing key can\'t be read. Upload the .pfx again.'));
        }

        return ['merchant_id' => $merchantId, 'app_id' => $appId, 'app_name' => $appName, 'app_password' => $password, 'key' => $key];
    }

    /** Base64 RSA PKCS#1 v1.5 signature over SHA-256. */
    private static function sign(string $message, OpenSSLAsymmetricKey $key): string
    {
        if (! openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ProviderException(__('The connectIPS signing key could not sign the request. Upload the .pfx again.'));
        }

        return base64_encode($signature);
    }

    /** 20 lowercase base32 characters (100 random bits): NCHL's TXNID limit, unique per gateway in Billing. */
    private static function newTxnId(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $txnId = '';

        for ($i = 0; $i < 20; $i++) {
            $txnId .= $alphabet[random_int(0, 31)];
        }

        return $txnId;
    }

    /**
     * A free-text value safe inside the comma-joined token and NCHL's form: letters, digits, space and . _ / - only,
     * spaces collapsed, cut to $max characters.
     */
    private static function clean(string $value, int $max): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9 ._\/-]+/', ' ', $value);

        return trim(substr(trim((string) preg_replace('/\s+/', ' ', $safe)), 0, $max));
    }
}
