<?php

namespace Mohar\Simulators\Providers\Cybersource;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * The Cybersource REST calls the card driver makes: Transaction Search, Retrieve a Transaction and follow-on refunds.
 * Every call is authenticated with HTTP Signature (HmacSHA256 over host, date, request target, digest for a body, and the
 * merchant id), and a failure is a 401 whatever part was wrong. Amounts are decimal strings on the wire and minor units in
 * the state.
 */
final class CybersourceRest
{
    /** Seconds a request's Date may differ from the simulator's clock. */
    public const CLOCK_SKEW = 300;

    public function __construct(private readonly Scenario $scenario) {}

    /**
     * @return Response|null the 401 when the signature does not verify
     */
    public static function authenticate(Request $request): ?Response
    {
        $denied = Response::json(['response' => ['rmsg' => 'Authentication Failed']], 401);
        preg_match_all('/(\w+)="([^"]*)"/', (string) $request->header('Signature'), $found);
        $parts = array_combine($found[1], $found[2]);
        $names = explode(' ', $parts['headers'] ?? '');
        $required = ['host', 'date', '(request-target)', 'v-c-merchant-id', ...($request->method === 'POST' ? ['digest'] : [])];
        $date = strtotime((string) $request->header('Date'));

        if (($parts['keyid'] ?? '') !== CybersourceSimulator::REST_KEY_ID || ($parts['algorithm'] ?? '') !== 'HmacSHA256'
            || array_diff($required, $names) !== [] || $request->header('v-c-merchant-id') !== CybersourceSimulator::MERCHANT_ID
            || $date === false || abs(time() - $date) > self::CLOCK_SKEW) {
            return $denied;
        }

        if ($request->method === 'POST' && $request->header('Digest') !== 'SHA-256='.base64_encode(hash('sha256', $request->body, true))) {
            return $denied;
        }

        $lines = array_map(fn (string $name): string => $name.': '.($name === '(request-target)'
            ? strtolower($request->method).' '.$request->path.($request->rawQuery === '' ? '' : '?'.$request->rawQuery)
            : (string) $request->header($name)), $names);
        $expected = base64_encode(hash_hmac('sha256', implode("\n", $lines), (string) base64_decode(CybersourceSimulator::REST_SECRET, true), true));

        return hash_equals($expected, $parts['signature'] ?? '') ? null : $denied;
    }

    /**
     * POST /tss/v2/searches: one term, `clientReferenceInformation.code:<ref>`, `id:<id>` or `rootId:<id>`, newest first.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function search(array $input): Response
    {
        if (preg_match('/^(clientReferenceInformation\.code|id|rootId):"?([^"\s]+)"?$/', trim((string) ($input['query'] ?? '')), $term) !== 1) {
            return self::invalid('The query must be one of clientReferenceInformation.code, id or rootId.');
        }

        $rows = array_values(array_filter(
            array_map(fn (mixed $transaction): array => (array) $transaction, $this->scenario->state()->all('txns')),
            fn (array $transaction): bool => match ($term[1]) {
                'id' => $transaction['id'] === $term[2],
                'rootId' => ($transaction['root_id'] ?? null) === $term[2],
                default => $transaction['reference'] === $term[2],
            },
        ));
        usort($rows, fn (array $a, array $b): int => strcmp($b['id'], $a['id']));
        $rows = array_slice($rows, 0, max(1, min(2500, (int) ($input['limit'] ?? 20))));

        return Response::json([
            'searchId' => bin2hex(random_bytes(8)),
            'save' => false,
            'query' => $input['query'],
            'offset' => 0,
            'limit' => count($rows),
            'count' => count($rows),
            'totalCount' => count($rows),
            'submitTimeUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            '_embedded' => ['transactionSummaries' => array_map($this->summary(...), $rows)],
        ]);
    }

    /** GET /tss/v2/transactions/{id}. */
    public function transaction(string $id): Response
    {
        $transaction = $this->scenario->state()->get('txns', $id);

        return is_array($transaction)
            ? Response::json($this->detail($transaction))
            : Response::json(['errorInformation' => ['reason' => 'NOT_FOUND', 'message' => 'The transaction does not exist.']], 404);
    }

    /**
     * POST /pts/v2/payments/{id}/refunds: a follow-on refund of a captured payment, for the rest (the whole payment) or part.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function refund(string $id, array $input): Response
    {
        return $this->scenario->state()->transaction(function () use ($id, $input): Response {
            $payment = $this->scenario->state()->get('txns', $id);
            $details = (array) (((array) ($input['orderInformation'] ?? []))['amountDetails'] ?? []);
            $amount = self::minor((string) ($details['totalAmount'] ?? ''));

            if (! is_array($payment) || $payment['kind'] !== 'payment') {
                return self::invalid('The original transaction does not exist.', 'INVALID_REQUEST');
            }

            if ($amount === null || $amount <= 0 || ($details['currency'] ?? '') !== $payment['currency']) {
                return self::invalid('totalAmount must be a positive amount with the original currency.');
            }

            if (! $payment['captured']) {
                return self::invalid('The original transaction was not captured.', 'INVALID_REQUEST');
            }

            if ($amount > $payment['amount'] - $payment['refunded']) {
                return self::invalid('The refund exceeds the amount still refundable.', 'INVALID_REQUEST');
            }

            $refund = [
                'id' => (new CybersourceApi($this->scenario))->newId(), 'kind' => 'refund', 'root_id' => $id, 'reference' => (string) (((array) ($input['clientReferenceInformation'] ?? []))['code'] ?? $payment['reference']),
                'amount' => $amount, 'currency' => $payment['currency'], 'captured' => false, 'refunded' => 0, 'status' => 'PENDING', 'reason_code' => '100', 'created' => time(), 'card' => $payment['card'],
            ];
            $this->scenario->state()->put('txns', $refund['id'], $refund);
            $this->scenario->state()->put('txns', $id, [...$payment, 'refunded' => $payment['refunded'] + $amount]);

            return Response::json([
                'id' => $refund['id'],
                'submitTimeUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                'status' => 'PENDING',
                'reconciliationId' => substr($refund['id'], -12),
                'clientReferenceInformation' => ['code' => $refund['reference']],
                'refundAmountDetails' => ['currency' => $refund['currency'], 'refundAmount' => self::decimal($amount)],
                'orderInformation' => ['amountDetails' => ['currency' => $refund['currency']]],
            ], 201);
        });
    }

    /** "1130.55" → 113055, or null when it is not an exact two-decimal amount. */
    public static function minor(string $decimal): ?int
    {
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $decimal, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.sprintf('%02d', $minor % 100);
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    private function summary(array $transaction): array
    {
        return [
            'id' => $transaction['id'],
            'submitTimeUtc' => gmdate('Y-m-d\TH:i:s\Z', (int) $transaction['created']),
            'merchantId' => CybersourceSimulator::MERCHANT_ID,
            'applicationInformation' => ['status' => $transaction['status'], 'reasonCode' => $transaction['reason_code']],
            'clientReferenceInformation' => ['code' => $transaction['reference']],
            'orderInformation' => ['amountDetails' => ['totalAmount' => self::decimal((int) $transaction['amount']), 'currency' => $transaction['currency']]],
            'paymentInformation' => ['card' => $transaction['card']],
            ...($transaction['root_id'] === null ? [] : ['rootId' => $transaction['root_id']]),
        ];
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    private function detail(array $transaction): array
    {
        $ok = $transaction['reason_code'] === '100';
        $application = fn (string $name, string $reason, string $flag, string $message): array => [
            'name' => $name, 'status' => $ok ? 'SUCCESS' : 'FAILURE', 'reasonCode' => $reason, 'rCode' => $ok ? '1' : '0', 'rFlag' => $flag,
            'reconciliationId' => substr($transaction['id'], -12), 'rMessage' => $message, 'returnCode' => $ok ? '1010000' : '1010001',
        ];
        $applications = match ($transaction['kind']) {
            'refund' => [$application('ics_credit', '100', 'SOK', 'Request was processed successfully.')],
            default => [
                $application('ics_auth', $transaction['reason_code'], $ok ? 'SOK' : ($transaction['reason_code'] === '480' ? 'DREVIEW' : 'DCARDREFUSED'), $ok ? 'Request was processed successfully.' : 'The authorization did not succeed.'),
                ...($transaction['captured'] ? [$application('ics_bill', '100', 'SOK', 'Request was processed successfully.')] : []),
            ],
        };

        return [
            ...$this->summary($transaction),
            'rootId' => $transaction['root_id'] ?? $transaction['id'],
            'reconciliationId' => substr($transaction['id'], -12),
            'applicationInformation' => [
                'status' => $transaction['status'], 'reasonCode' => $transaction['reason_code'], 'rCode' => $ok ? '1' : '0', 'rFlag' => $ok ? 'SOK' : 'ESYSTEM',
                'applications' => $applications,
            ],
            'orderInformation' => ['amountDetails' => [
                'totalAmount' => self::decimal((int) $transaction['amount']), 'currency' => $transaction['currency'],
                ...($transaction['kind'] === 'payment' ? ['authorizedAmount' => self::decimal((int) $transaction['amount'])] : []),
            ]],
        ];
    }

    private static function invalid(string $message, string $reason = 'INVALID_DATA'): Response
    {
        return Response::json(['submitTimeUtc' => gmdate('Y-m-d\TH:i:s\Z'), 'status' => 'INVALID_REQUEST', 'reason' => $reason, 'message' => $message], 400);
    }
}
