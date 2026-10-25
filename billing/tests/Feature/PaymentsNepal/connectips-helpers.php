<?php

/*
| Shared helpers for the connectIPS driver tests (require_once from each test file).
*/

use App\Data\Billing\GatewayConfig;
use App\Data\Integrations\CertificateInfo;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\PaymentsNepal\Drivers\ConnectipsDriver;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

const CIPS_PASSWORD = 'fake-connectips-app-password';
const CIPS_FORM = 'https://uat.connectips.com/connectipswebgw/loginpage';
const CIPS_VALIDATE = 'https://uat.connectips.com/connectipswebws/api/creditor/validatetxn';
const CIPS_DETAIL = 'https://uat.connectips.com/connectipswebws/api/creditor/gettxndetail';

/**
 * A merchant key and its self-signed certificate, made once (key generation is slow).
 *
 * @return array{key: string, certificate: string}
 */
function connectipsKey(int $days = 365): array
{
    static $made = [];

    return $made[$days] ??= (function () use ($days): array {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'CREDITOR'], $key), null, $key, $days, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $pem);
        openssl_x509_export($certificate, $certificatePem);

        return ['key' => $pem, 'certificate' => $certificatePem];
    })();
}

/**
 * The gateway settings as Billing stores them after a .pfx upload: the PEM key and the certificate's metadata.
 *
 * @return array<string, mixed>
 */
function connectipsSettings(?CarbonImmutable $notAfter = null): array
{
    $info = new CertificateInfo('CN=CREDITOR', 'CN=CREDITOR', '1', CarbonImmutable::now()->subDay(), $notAfter ?? CarbonImmutable::now()->addYear(), connectipsKey()['certificate']);

    return [
        'sandbox' => '1', 'merchant_id' => '550', 'app_id' => 'MER-550-APP-1', 'app_name' => 'Simulated Hosting',
        'app_password' => CIPS_PASSWORD, 'signing_key' => connectipsKey()['key'],
        CertificateInfo::SETTINGS_KEY => ['signing_key' => $info->toArray()],
    ];
}

function connectipsConfig(): GatewayConfig
{
    return new GatewayConfig(ConnectipsDriver::KEY, 'connectIPS', connectipsSettings(), sandbox: true, secretSettingKeys: ['app_password', 'signing_key']);
}

/**
 * Pay a fresh invoice with connectIPS and load the auto-posting checkout page.
 *
 * @return array{0: Invoice, 1: PaymentAttempt, 2: array<string, string>}
 */
function startConnectips(int $amount = 113_000): array
{
    [, $invoice] = nprInvoice([$amount]);
    $checkout = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => ConnectipsDriver::KEY])->assertRedirect()->headers->get('Location');
    $page = test()->get((string) $checkout)->assertOk();
    expect($page->inertiaProps('form.action'))->toBe(CIPS_FORM);

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole(), (array) $page->inertiaProps('form.fields')];
}

/**
 * NCHL's validatetxn answer for this attempt, echoing what we sent.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cipsValidate(PaymentAttempt $attempt, string $status, string $description, array $overrides = []): array
{
    return [...['merchantId' => '550', 'appId' => 'MER-550-APP-1', 'referenceId' => $attempt->provider_ref, 'txnAmt' => (string) $attempt->amount, 'token' => null, 'status' => $status, 'statusDesc' => $description], ...$overrides];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cipsDetail(PaymentAttempt $attempt, array $overrides = []): array
{
    return [...cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL', ['txnAmt' => number_format($attempt->amount / 100, 2, '.', '')]),
        'txnId' => '81234567890', 'debitBankCode' => '0401', 'chargeAmt' => '4.00', 'chargeLiability' => 'MN', 'creditStatus' => '000', ...$overrides];
}

function cipsReturn(PaymentAttempt $attempt): string
{
    return route('billing.gateways.return', [ConnectipsDriver::KEY, 'TXNID' => $attempt->provider_ref]);
}
