<?php

namespace Mohar\Simulators\Providers\Connectips;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * connectIPS as one merchant app sees it. Amounts are paisa integers in the state; the wire forms (paisa digits in the
 * form and in validatetxn, rupee decimals in gettxndetail) are produced at the edge. A transaction is `open` (form
 * accepted, payer not yet at the bank login), `incomplete` (reached the login, left before the OTP), `success`,
 * `failed` or `not_found` (NCHL has no record).
 */
final class ConnectipsApi
{
    /** Minutes the hosted page stays usable after the form post. */
    public const SESSION_MINUTES = 15;

    /** NCHL's per-transaction limit, in paisa. */
    public const MAX_PAISA = 200_000_000;

    /** Credit-leg codes that mean the credit is done (or deferred and owed by NCHL's rule). */
    public const CREDIT_OK = ['000', '999', 'DEFER'];

    /** A credit code outside the documented ones, for the "paid but credit failed" case (our choice). */
    public const CREDIT_FAILED = 'F01';

    /** Form fields with their maximum lengths, in token order. */
    private const FIELDS = ['MERCHANTID' => 20, 'APPID' => 15, 'APPNAME' => 30, 'TXNID' => 20, 'TXNDATE' => 10, 'TXNCRNCY' => 3, 'TXNAMT' => 20, 'REFERENCEID' => 20, 'REMARKS' => 50, 'PARTICULARS' => 100];

    public function __construct(private readonly Scenario $scenario) {}

    /**
     * POST /connectipswebgw/loginpage: check the fields, the date, the amount and the token, book the transaction and
     * answer the hosted page. A reused TXNID is a 403, as at NCHL; other refusals are 400 pages.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function loginPage(array $input): Response
    {
        $fields = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $input);
        $merchant = $this->merchant();

        foreach ([...array_keys(self::FIELDS), 'TOKEN'] as $name) {
            if (($fields[$name] ?? '') === '') {
                return self::html(400, '<p>'.$name.' is required.</p>');
            }
        }

        foreach ([...self::FIELDS, 'TOKEN' => 512] as $name => $max) {
            if (strlen($fields[$name]) > $max) {
                return self::html(400, '<p>'.$name.' is longer than '.$max.' characters.</p>');
            }
        }

        if ($fields['MERCHANTID'] !== $merchant['merchant_id'] || ! isset(ConnectipsSimulator::APPS[$fields['APPID']])) {
            return self::html(400, '<p>Unknown merchant or app.</p>');
        }

        if ($fields['TXNCRNCY'] !== 'NPR' || preg_match('/^\d{1,12}$/', $fields['TXNAMT']) !== 1 || (int) $fields['TXNAMT'] < 1 || (int) $fields['TXNAMT'] > self::MAX_PAISA) {
            return self::html(400, '<p>Currency must be NPR and the amount 1 to '.self::MAX_PAISA.' paisa.</p>');
        }

        if ($fields['TXNDATE'] !== (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kathmandu')))->format('d-m-Y')) {
            return self::html(400, '<p>TXNDATE must be today in Nepal time, DD-MM-YYYY.</p>');
        }

        if (! $this->verify($merchant, self::paymentString($fields, $merchant['token_spacing']), $fields['TOKEN'])) {
            return self::html(400, '<p>Invalid token.</p>');
        }

        return $this->scenario->state()->transaction(function () use ($fields): Response {
            $key = $fields['APPID'].':'.$fields['TXNID'];

            if ($this->scenario->state()->get('txns', $key) !== null) {
                return self::html(403, '<p>This TXNID was already used for this app.</p>');
            }

            $id = bin2hex(random_bytes(8));
            $this->scenario->state()->put('txns', $key, [
                'id' => $id,
                'app_id' => $fields['APPID'],
                'txn_id' => $fields['TXNID'],
                'amount' => (int) $fields['TXNAMT'],
                'status' => 'open',
                'credit_status' => null,
                'charge_liability' => 'MN',
                'debit_bank' => null,
                'nchl_txn_id' => null,
                'expires_at' => time() + 60 * self::SESSION_MINUTES,
            ]);
            $this->scenario->state()->put('txn_ids', $id, $key);

            return self::html(200, '<p>NPR '.number_format((int) $fields['TXNAMT'] / 100, 2).' to '.htmlspecialchars($fields['APPNAME']).'</p>'.$this->buttons($id));
        });
    }

    /**
     * The payer's choice on the hosted page, from the browser or the control plane:
     * - `pay`: the bank login and OTP succeed; options `credit_status` (000, 999, DEFER or a failing code),
     *   `charge_liability` (MN merchant, CG customer) and `debit_bank`;
     * - `defer`: the same with `DEFER`, the credit leg still to settle;
     * - `decline`: the bank or OTP refuses; NCHL records FAILED and the browser goes to the failure URL;
     * - `leave`: reached the bank login and left before the OTP (INCOMPLETE), to the failure URL;
     * - `cancel`: cancelled on the connectIPS login page (NCHL keeps no record), to the failure URL.
     * A second `pay` or `defer` on a paid transaction returns the same success URL. No registered return URL, or an
     * expired session, gives an empty result (the 403 "session expired" page).
     *
     * @param  array<string, mixed>  $options
     * @return array{redirect?: string}|null null when the transaction is not open
     */
    public function choose(string $id, string $choice, array $options = []): ?array
    {
        return $this->scenario->state()->transaction(function () use ($id, $choice, $options): ?array {
            $key = $this->scenario->state()->get('txn_ids', $id);
            $txn = is_string($key) ? $this->scenario->state()->get('txns', $key) : null;

            if (! is_array($txn) || ! in_array($choice, ['pay', 'defer', 'decline', 'leave', 'cancel'], true)) {
                return null;
            }

            $urls = $this->merchant()['return_urls'];
            $again = $txn['status'] === 'success' && in_array($choice, ['pay', 'defer'], true);

            if ($txn['status'] !== 'open' && ! $again) {
                return null;
            }

            if ($urls === null || ($txn['status'] === 'open' && (int) $txn['expires_at'] <= time())) {
                return [];
            }

            if ($again) {
                return ['redirect' => self::returnUrl($urls['success'], $txn['txn_id'])];
            }

            $txn = match ($choice) {
                'pay', 'defer' => $this->paid($txn, $choice === 'defer' ? 'DEFER' : (string) ($options['credit_status'] ?? '000'), $options),
                'decline' => [...$txn, 'status' => 'failed'],
                'leave' => [...$txn, 'status' => 'incomplete'],
                'cancel' => $txn,
            };
            $this->save($txn);

            return ['redirect' => self::returnUrl($txn['status'] === 'success' ? $urls['success'] : $urls['failure'], $txn['txn_id'])];
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{redirect?: string}|null
     */
    public function chooseByTxn(string $txnId, string $choice, array $options): ?array
    {
        $appId = (string) ($options['app_id'] ?? ConnectipsSimulator::APP_ID);
        $txn = $this->scenario->state()->get('txns', $appId.':'.$txnId);

        return is_array($txn) ? $this->choose((string) $txn['id'], $choice, $options) : null;
    }

    /**
     * What NCHL later does to a transaction: `outcome` `success` (paid after the payer left), `failed`, `incomplete` or
     * `not_found`; or only `credit_status` on a paid one (the credit leg changed state).
     *
     * @param  array<string, mixed>  $input
     * @return array{status: string, credit_status: string|null}
     */
    public function settle(string $txnId, array $input): array
    {
        $appId = (string) ($input['app_id'] ?? ConnectipsSimulator::APP_ID);

        return $this->scenario->state()->transaction(function () use ($txnId, $input, $appId): array {
            $txn = $this->scenario->state()->get('txns', $appId.':'.$txnId) ?? throw new \InvalidArgumentException("No transaction {$txnId}.");
            $outcome = (string) ($input['outcome'] ?? $txn['status']);

            if (! in_array($outcome, ['success', 'failed', 'incomplete', 'not_found'], true)) {
                throw new \InvalidArgumentException("Unknown outcome [{$outcome}].");
            }

            if ($txn['status'] === 'success' && $outcome !== 'success') {
                throw new \InvalidArgumentException("A successful payment cannot become {$outcome}.");
            }

            if ($txn['status'] !== 'success' && $outcome === 'success') {
                $txn = $this->paid($txn, (string) ($input['credit_status'] ?? '000'), $input);
            } elseif ($txn['status'] === 'success') {
                $txn = [...$txn, 'credit_status' => (string) ($input['credit_status'] ?? $txn['credit_status'])];
            } else {
                $txn = [...$txn, 'status' => $outcome];
            }

            $this->save($txn);

            return ['status' => $txn['status'], 'credit_status' => $txn['credit_status']];
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> the merchant record after the change
     */
    public function configure(array $changes): array
    {
        $allowed = [
            'bad_token_reply' => ['error', 'http_400', 'http_401'],
            'token_spacing' => ['none', 'space'],
            'validate_amount_style' => ['paisa', 'rupees'],
            'validation_algorithm' => ['sha256', 'sha1'],
            'echo_variant' => ['none', 'wrong_amount', 'wrong_reference', 'wrong_merchant', 'wrong_app'],
        ];

        foreach ($changes as $name => $value) {
            if ($name !== 'return_urls' && ! in_array($value, $allowed[$name] ?? [], true)) {
                throw new \InvalidArgumentException("Bad value for [{$name}].");
            }
        }

        $merchant = [...$this->merchant(), ...$changes];
        $this->scenario->state()->put('config', 'merchant', $merchant);

        return array_diff_key($merchant, ['public_key' => 1]);
    }

    /**
     * POST validatetxn or gettxndetail: Basic auth (app id and password), a JSON body of merchantId, appId, referenceId,
     * txnAmt (paisa digits) and the signed token. NCHL does not sign answers, so `token` is null.
     */
    public function lookup(Request $request, bool $detail): Response
    {
        $merchant = $this->merchant();

        $credentials = str_starts_with((string) $request->header('Authorization'), 'Basic ') ? base64_decode(substr((string) $request->header('Authorization'), 6), true) : false;
        [$user, $password] = array_pad(explode(':', is_string($credentials) ? $credentials : '', 2), 2, '');

        if (! isset(ConnectipsSimulator::APPS[$user]) || ! hash_equals(ConnectipsSimulator::APPS[$user], $password)) {
            return Response::json(['error' => 'Unauthorized', 'status' => 401], 401, ['WWW-Authenticate' => 'Basic realm="connectips"']);
        }

        $body = json_decode($request->body, true);
        $body = is_array($body) ? array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $body) : [];

        foreach (['merchantId', 'appId', 'referenceId', 'txnAmt', 'token'] as $name) {
            if (($body[$name] ?? '') === '') {
                return Response::json(['error' => 'Bad Request', 'message' => $name.' is required', 'status' => 400], 400);
            }
        }

        $signed = 'MERCHANTID='.$body['merchantId'].',APPID='.$body['appId'].',REFERENCEID='.$body['referenceId'].',TXNAMT='.$body['txnAmt'];

        if ($body['merchantId'] !== $merchant['merchant_id'] || $body['appId'] !== $user || ! $this->verify($merchant, $signed, $body['token'], $merchant['validation_algorithm'])) {
            return match ($merchant['bad_token_reply']) {
                'http_400' => Response::json(['error' => 'Bad Request', 'message' => 'Invalid token', 'status' => 400], 400),
                'http_401' => Response::json(['error' => 'Unauthorized', 'status' => 401], 401),
                default => Response::json($this->answer($body, 'ERROR', 'INVALID TOKEN', $merchant)),
            };
        }

        $txn = $this->scenario->state()->get('txns', $body['appId'].':'.$body['referenceId']);

        if (! is_array($txn) || preg_match('/^\d+$/', $body['txnAmt']) !== 1 || (int) $body['txnAmt'] !== $txn['amount']) {
            return Response::json($this->answer($body, 'ERROR', 'TRANSACTION NOT FOUND', $merchant));
        }

        [$status, $description] = match ($txn['status']) {
            'success' => ['SUCCESS', 'TRANSACTION SUCCESSFUL'],
            'failed' => ['FAILED', 'TRANSACTION FAILED'],
            'incomplete' => ['ERROR', 'TRANSACTION INCOMPLETE'],
            default => ['ERROR', 'TRANSACTION NOT FOUND'],
        };

        return Response::json($this->answer($body, $status, $description, $merchant, $detail ? $txn : null));
    }

    /**
     * The NCHL fee for a payment, in paisa: NPR 2 up to 500, NPR 4 up to 5,000, NPR 8 above.
     */
    public static function fee(int $paisa): int
    {
        return $paisa <= 50_000 ? 200 : ($paisa <= 500_000 ? 400 : 800);
    }

    /**
     * The payment token string: ten NAME=value pairs and TOKEN=TOKEN, comma-joined. `space` joins with ", " (the docs'
     * template) instead of "," (the docs' worked example); the scenario accepts only the one it is configured for.
     *
     * @param  array<string, string>  $fields
     */
    public static function paymentString(array $fields, string $spacing = 'none'): string
    {
        $pairs = array_map(fn (string $name): string => $name.'='.($fields[$name] ?? ''), array_keys(self::FIELDS));

        return implode($spacing === 'space' ? ', ' : ',', [...$pairs, 'TOKEN=TOKEN']);
    }

    /**
     * @param  array<string, mixed>  $txn
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function paid(array $txn, string $creditStatus, array $options): array
    {
        $liability = (string) ($options['charge_liability'] ?? 'MN');

        if (! in_array($liability, ['MN', 'CG'], true)) {
            throw new \InvalidArgumentException('charge_liability is MN or CG.');
        }

        return [
            ...$txn,
            'status' => 'success',
            'credit_status' => $creditStatus,
            'charge_liability' => $liability,
            'debit_bank' => (string) ($options['debit_bank'] ?? '0110'),
            'nchl_txn_id' => (string) random_int(10_000_000_000, 99_999_999_999),
        ];
    }

    /**
     * @param  array<string, string>  $body
     * @param  array<string, mixed>  $merchant
     * @param  array<string, mixed>|null  $detail  the transaction, for gettxndetail
     * @return array<string, mixed>
     */
    private function answer(array $body, string $status, string $description, array $merchant, ?array $detail = null): array
    {
        $amount = (int) $body['txnAmt'];
        $rupees = $detail !== null || $merchant['validate_amount_style'] === 'rupees';
        $answer = [
            'merchantId' => $body['merchantId'],
            'appId' => $body['appId'],
            'referenceId' => $body['referenceId'],
            'txnAmt' => $rupees ? number_format($amount / 100, 2, '.', '') : (string) $amount,
            'token' => null,
            'status' => $status,
            'statusDesc' => $description,
        ];

        if ($detail !== null && $status === 'SUCCESS') {
            $answer += [
                'txnId' => $detail['nchl_txn_id'],
                'debitBankCode' => $detail['debit_bank'],
                'chargeAmt' => number_format(self::fee($detail['amount']) / 100, 2, '.', ''),
                'chargeLiability' => $detail['charge_liability'],
                'creditStatus' => $detail['credit_status'],
            ];
        }

        // An answer about another transaction, so a driver that trusts the status alone is caught.
        return match ($merchant['echo_variant']) {
            'wrong_amount' => [...$answer, 'txnAmt' => $rupees ? number_format(($amount + 100) / 100, 2, '.', '') : (string) ($amount + 100)],
            'wrong_reference' => [...$answer, 'referenceId' => 'OTHER-'.$body['referenceId']],
            'wrong_merchant' => [...$answer, 'merchantId' => '9999'],
            'wrong_app' => [...$answer, 'appId' => 'OTHER-APP'],
            default => $answer,
        };
    }

    /**
     * @param  array<string, mixed>  $merchant
     */
    private function verify(array $merchant, string $message, string $token, string $algorithm = 'sha256'): bool
    {
        $signature = base64_decode($token, true);

        return $signature !== false && openssl_verify($message, $signature, (string) $merchant['public_key'], $algorithm === 'sha1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function merchant(): array
    {
        return (array) $this->scenario->state()->get('config', 'merchant');
    }

    /**
     * @param  array<string, mixed>  $txn
     */
    private function save(array $txn): void
    {
        $this->scenario->state()->put('txns', $txn['app_id'].':'.$txn['txn_id'], $txn);
    }

    private function buttons(string $id): string
    {
        $buttons = '';

        foreach (['pay' => 'Pay', 'defer' => 'Pay (credit to the merchant is deferred)', 'decline' => 'Decline (bank refuses)', 'leave' => 'Leave before the OTP', 'cancel' => 'Cancel'] as $choice => $label) {
            $buttons .= '<form method="post" action="/connectipswebgw/choice/'.$id.'/'.$choice.'"><button>'.$label.'</button></form>';
        }

        return $buttons;
    }

    private static function returnUrl(string $url, string $txnId): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'TXNID='.rawurlencode($txnId);
    }

    public static function html(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><title>connectIPS test</title>'.$body);
    }
}
