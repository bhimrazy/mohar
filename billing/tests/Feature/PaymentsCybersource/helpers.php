<?php

/*
| Shared helpers for the Cybersource driver tests (require_once from each test file). The REST API is faked with
| Http::fake over a list of transactions held on the test case, in the shapes the public REST reference shows.
*/

use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\PaymentsCybersource\Drivers\CybersourceDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__.'/../PaymentsNepal/helpers.php';

const CS_SECRET = 'cs-profile-secret-for-tests-0001';
const CS_REST_SECRET = 'Q3liZXJzb3VyY2VSZXN0U2VjcmV0Rm9yVGVzdHMwMQ==';
const CS_ACCESS = 'cs-access-key-0001';
const CS_PROFILE = 'cs-profile-0001';
const CS_REST = 'https://apitest.cybersource.com';

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function cybersourceSettings(array $overrides = []): array
{
    return ['sandbox' => '1', 'merchant_id' => 'nicasia_test', 'profile_id' => CS_PROFILE, 'access_key' => CS_ACCESS, 'secret_key' => CS_SECRET,
        'rest_key_id' => 'cs-rest-key-0001', 'rest_secret' => CS_REST_SECRET, ...$overrides];
}

function cybersourceGateway(array $overrides = []): Gateway
{
    return nepalGateway(CybersourceDriver::KEY, cybersourceSettings($overrides));
}

/**
 * Start a card payment for a new NPR invoice and load the auto-posting checkout page.
 *
 * @return array{0: Invoice, 1: PaymentAttempt, 2: TestResponse<Response>}
 */
function startCard(int $amount = 113_000): array
{
    [, $invoice] = nprInvoice([$amount]);
    $checkout = test()->post(route('client.invoices.pay', $invoice->id), ['gateway' => CybersourceDriver::KEY])->assertRedirect()->headers->get('Location');

    return [$invoice, PaymentAttempt::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail(), test()->get((string) $checkout)->assertOk()];
}

/**
 * The fields Cybersource posts back for an attempt, signed over every field.
 *
 * @param  array<string, string>  $extra  added before signing
 * @return array<string, string>
 */
function cardReturn(PaymentAttempt $attempt, string $decision = 'ACCEPT', array $extra = [], string $secret = CS_SECRET): array
{
    $fields = [
        'decision' => $decision, 'reason_code' => $decision === 'ACCEPT' ? '100' : '203', 'transaction_id' => '7290000000000000000001', 'message' => 'Request was processed successfully.',
        'req_access_key' => CS_ACCESS, 'req_profile_id' => CS_PROFILE, 'req_reference_number' => $attempt->reference, 'req_transaction_uuid' => (string) $attempt->provider_ref,
        'req_amount' => '1130.00', 'req_currency' => 'NPR', 'req_card_number' => 'xxxxxxxxxxxx1111', 'signed_date_time' => gmdate('Y-m-d\TH:i:s\Z'), ...$extra,
    ];
    $fields['signed_field_names'] = implode(',', [...array_keys($fields), 'signed_field_names']);
    $fields['signature'] = CybersourceDriver::signature($fields, $fields['signed_field_names'], $secret);

    return $fields;
}

/**
 * A transaction Cybersource holds. `captured` adds a successful ics_bill.
 *
 * @return array<string, mixed>
 */
function cardTxn(string $reference, string $status = 'TRANSMITTED', string $amount = '1130.00', string $currency = 'NPR', string $id = '7290000000000000000001', bool $captured = true, ?string $rootId = null): array
{
    $ok = ! in_array($status, ['DECLINED', 'REJECTED'], true);

    return ['id' => $id, 'rootId' => $rootId, 'clientReferenceInformation' => ['code' => $reference], 'reconciliationId' => substr($id, -12),
        'applicationInformation' => ['status' => $status, 'reasonCode' => $ok ? '100' : '203', 'applications' => [
            ['name' => $rootId === null ? 'ics_auth' : 'ics_credit', 'rCode' => $ok ? '1' : '0'],
            ...($captured && $status === 'TRANSMITTED' ? [['name' => 'ics_bill', 'rCode' => '1']] : []),
        ]],
        'orderInformation' => ['amountDetails' => ['totalAmount' => $amount, 'currency' => $currency]]];
}

/**
 * Fake the REST API over $transactions: search by clientReferenceInformation.code, retrieve by id, and refunds
 * answered with $refund (status, body).
 *
 * @param  list<array<string, mixed>>  $transactions
 * @param  array{0: int, 1: array<string, mixed>}|Closure|null  $refund
 */
function fakeCardRest(array $transactions, array|Closure|null $refund = null): void
{
    // Http::fake() keeps the first stub that answers, so a later call replaces the data, not the stub.
    $GLOBALS['cybersourceRest'] = [$transactions, $refund];

    Http::fake(function (Request $request) {
        [$transactions, $refund] = $GLOBALS['cybersourceRest'];

        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/tss/v2/searches') {
            $code = substr((string) $request['query'], strlen('clientReferenceInformation.code:'));
            $rows = array_values(array_filter($transactions, fn (array $txn): bool => $txn['clientReferenceInformation']['code'] === $code));

            return Http::response(['count' => count($rows), '_embedded' => ['transactionSummaries' => array_map(fn (array $row): array => array_filter($row, fn ($value) => $value !== null), $rows)]]);
        }

        if (preg_match('#^/tss/v2/transactions/(\d+)$#', $path, $match) === 1) {
            $found = collect($transactions)->firstWhere('id', $match[1]);

            return $found === null ? Http::response(['errorInformation' => ['reason' => 'NOT_FOUND']], 404) : Http::response(array_filter($found, fn ($value) => $value !== null));
        }

        if (preg_match('#^/pts/v2/payments/(\d+)/refunds$#', $path) === 1 && $refund !== null) {
            return $refund instanceof Closure ? $refund($request) : Http::response($refund[1], $refund[0]);
        }

        return Http::response(['message' => 'unexpected '.$path], 500);
    });
}

/** Post the return the way the customer's browser does. */
function postCardReturn(array $fields, bool $cancel = false, ?PaymentAttempt $attempt = null): TestResponse
{
    $url = $cancel ? route('billing.gateways.return', [CybersourceDriver::KEY, 'ref' => $attempt?->reference, 'cancelled' => 1]) : route('billing.gateways.return', CybersourceDriver::KEY);

    return test()->post($url, $fields);
}
