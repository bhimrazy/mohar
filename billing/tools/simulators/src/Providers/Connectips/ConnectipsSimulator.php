<?php

namespace Mohar\Simulators\Providers\Connectips;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\RedactsPayments;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * connectIPS (NCHL) creditor gateway for the future connectIPS driver (ADR 0016 §2), written from NCHL's public
 * connectIPS merchant documents, in our own words:
 *
 * - the login page, which takes the browser's form post signed with the merchant's RSA key (SHA-256, PKCS#1 v1.5);
 * - the hosted page (Pay, Pay with a deferred credit, Decline, Leave before the OTP, Cancel), which sends the browser
 *   to the return URL registered with NCHL, appending only TXNID;
 * - validatetxn and gettxndetail, with Basic auth and a signed token, the only calls that settle anything.
 *
 * connectIPS has no refund API, so there is none here. The merchant key is a throwaway `CREDITOR.pfx` written to the
 * scenario directory at seed, so a driver's certificate upload path can be exercised. Everything the public documents
 * leave open is an assumption and is listed in tools/simulators/README.md.
 */
final class ConnectipsSimulator implements HasControlActions, RedactsPayments, Simulator
{
    public const MERCHANT_ID = '550';

    public const APP_ID = 'MER-550-APP-1';

    /** A second app of the same merchant (one per settlement bank account or site), with its own password. */
    public const APP_ID_2 = 'MER-550-APP-2';

    public const APP_PASSWORD_2 = 'SimulatorLocalConnectipsPassword2';

    /** App id => password, as NCHL emails them. */
    public const APPS = [self::APP_ID => self::APP_PASSWORD, self::APP_ID_2 => self::APP_PASSWORD_2];

    public const APP_NAME = 'Simulated Hosting';

    public const APP_PASSWORD = 'SimulatorLocalConnectipsPassword';

    public const PFX_PASSPHRASE = 'simulator-local';

    /** @return string the throwaway merchant key and certificate, as NCHL ships them (PKCS#12) */
    public static function pfxPath(Scenario $scenario): string
    {
        return $scenario->directory.'/CREDITOR.pfx';
    }

    public function key(): string
    {
        return 'connectips';
    }

    public function reference(): array
    {
        return ['url' => 'https://nchl.com.np/connectips (merchant integration documents)', 'version' => 'connectIPS web gateway, creditor API'];
    }

    public function routes(): array
    {
        $api = fn (\Closure $call): \Closure => fn (Request $request, Scenario $scenario): Response => $call(new ConnectipsApi($scenario), $request);

        return [
            new Route('POST', '/connectipswebgw/loginpage', 'loginpage', $api(fn (ConnectipsApi $api, Request $request): Response => $api->loginPage($request->input()))),
            new Route('POST', '/connectipswebgw/choice/{id}/{choice}', 'payment_choice', function (Request $request, Scenario $scenario, array $parameters): Response {
                $outcome = (new ConnectipsApi($scenario))->choose($parameters['id'], $parameters['choice']);

                if ($outcome === null) {
                    return ConnectipsApi::html(410, '<p>This payment session has ended.</p>');
                }

                return isset($outcome['redirect'])
                    ? new Response(303, ['Location' => $outcome['redirect']])
                    : ConnectipsApi::html(403, '<p>Session expired.</p>');
            }),
            new Route('POST', '/connectipswebws/api/creditor/validatetxn', 'validatetxn', $api(fn (ConnectipsApi $api, Request $request): Response => $api->lookup($request, detail: false))),
            new Route('POST', '/connectipswebws/api/creditor/gettxndetail', 'gettxndetail', $api(fn (ConnectipsApi $api, Request $request): Response => $api->lookup($request, detail: true))),
        ];
    }

    public function operations(): array
    {
        return array_map(fn (Route $route): string => $route->operation, $this->routes());
    }

    public function seed(Scenario $scenario): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) ?: throw new \RuntimeException('Could not create the merchant key.');
        $config = $scenario->directory.'/connectips.cnf';
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");
        $csr = openssl_csr_new(['commonName' => 'Simulated connectIPS creditor'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $certificate = is_object($csr) ? openssl_csr_sign($csr, null, $key, 2, ['config' => $config, 'digest_alg' => 'sha256']) : false;

        if ($certificate === false || ! openssl_pkcs12_export_to_file($certificate, self::pfxPath($scenario), $key, self::PFX_PASSPHRASE)) {
            throw new \RuntimeException('Could not write the merchant .pfx.');
        }

        chmod(self::pfxPath($scenario), 0600);
        $scenario->state()->put('config', 'merchant', [
            'merchant_id' => self::MERCHANT_ID,
            'public_key' => (string) openssl_pkey_get_details($key)['key'],
            'return_urls' => null,
            'bad_token_reply' => 'error',
            'token_spacing' => 'none',
            'validate_amount_style' => 'paisa',
            'echo_variant' => 'none',
            'validation_algorithm' => 'sha256',
        ]);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        $api = $scenario->apiUrl();

        return [
            'env' => [
                // Key names are provisional until the connectIPS driver lands.
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['connectips.gateway' => $api.'/connectipswebgw', 'connectips.api' => $api.'/connectipswebws/api/creditor'], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => [
                'connectIPS gateway: merchant id '.self::MERCHANT_ID.', app ids '.self::APP_ID.' (password '.self::APP_PASSWORD.') and '.self::APP_ID_2.' (password '.self::APP_PASSWORD_2.'), app name "'.self::APP_NAME.'".',
                'Hosts are not in NCHL\'s public docs: the driver reads them from the endpoints above, never from code.',
                'Upload '.self::pfxPath($scenario).' (passphrase '.self::PFX_PASSPHRASE.') as the signing certificate.',
                'Register the return URLs first: POST /__sim/actions/register_return_urls {"success": …, "failure": …}.',
            ],
        ];
    }

    /**
     * - `register_return_urls` {success, failure}: the static URLs NCHL holds for the merchant; until set, every payer
     *   choice answers 403 "session expired";
     * - `pay`, `defer`, `decline`, `leave`, `cancel` {txn_id, app_id?, credit_status?, charge_liability?, debit_bank?}: the payer's
     *   choice (ConnectipsApi::choose()); they return `redirect`, the URL the browser follows into the app;
     * - `settle` {txn_id, app_id?, outcome, credit_status?, charge_liability?}: what NCHL later does (`success`, `failed`,
     *   `incomplete`, `not_found`, or `credit_status` alone on a paid one);
     * - `configure` {bad_token_reply, token_spacing, validate_amount_style, echo_variant, validation_algorithm}: the open assumptions.
     */
    public function actions(): array
    {
        $choice = fn (string $choice): \Closure => fn (array $input, Scenario $scenario): array => (new ConnectipsApi($scenario))->chooseByTxn((string) $input['txn_id'], $choice, $input)
            ?? throw new \InvalidArgumentException("Transaction {$input['txn_id']} is not open.");

        return [
            'register_return_urls' => function (array $input, Scenario $scenario): array {
                $urls = ['success' => (string) ($input['success'] ?? ''), 'failure' => (string) ($input['failure'] ?? '')];
                (new ConnectipsApi($scenario))->configure(['return_urls' => $urls]);

                return $urls;
            },
            'pay' => $choice('pay'),
            'defer' => $choice('defer'),
            'decline' => $choice('decline'),
            'leave' => $choice('leave'),
            'cancel' => $choice('cancel'),
            'settle' => fn (array $input, Scenario $scenario): array => (new ConnectipsApi($scenario))->settle((string) $input['txn_id'], $input),
            'configure' => fn (array $input, Scenario $scenario): array => (new ConnectipsApi($scenario))->configure(array_intersect_key($input, array_flip(['bad_token_reply', 'token_spacing', 'validate_amount_style', 'echo_variant', 'validation_algorithm']))),
        ];
    }
}
