<?php

/**
 * Race helper for the PR #266 review probes: one "Use another method" (CloseHostedPayment) or one webhook-style
 * settlement (SettlePaymentAttempt) of a Stripe attempt, in its own process against the given database and cache
 * store, so that two real requests meet on the attempt.
 *
 *   php pr266-close-worker.php <label> <close|settle> <database> <attempt id> <cache store> <state file> <expire pause ms>
 *
 * Stripe is faked in-process; its session state lives in <state file> (open | paid | expired), shared by the workers.
 * Each expire call appends one line to <state file>.expires. Prints {label, status, started, ended} or {label, error}.
 */

use App\Modules\Billing\Actions\CloseHostedPayment;
use App\Modules\Billing\Actions\SettlePaymentAttempt;
use App\Modules\Billing\Models\PaymentAttempt;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

[, $label, $mode, $database, $attemptId, $store, $stateFile, $pause] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database, 'cache.default' => $store]);
DB::purge($connection);

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result]));
$fixture = fn (string $name): array => json_decode((string) file_get_contents($base.'/tests/Fixtures/Stripe/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);

$attempt = PaymentAttempt::query()->findOrFail((int) $attemptId);
$session = function () use ($fixture, $attempt, $stateFile): array {
    $state = trim((string) file_get_contents($stateFile));
    $metadata = ['mohar_reference' => $attempt->reference, 'mohar_invoice_id' => (string) $attempt->invoice_id];

    if ($state === 'paid') {
        return array_replace_recursive($fixture('checkout_session_paid'), ['client_reference_id' => $attempt->reference, 'metadata' => $metadata, 'payment_intent' => ['metadata' => $metadata]]);
    }

    return array_replace_recursive($fixture('checkout_session_created'), [
        'client_reference_id' => $attempt->reference, 'metadata' => $metadata, 'status' => $state,
        'expires_at' => now()->addMinutes(30)->getTimestamp(),
    ]);
};

Http::fake(function (Request $request) use ($session, $stateFile, $pause, $label) {
    if ($request->method() === 'POST' && str_ends_with(strtok($request->url(), '?'), '/expire')) {
        file_put_contents($stateFile.'.expires', $label."\n", FILE_APPEND | LOCK_EX);
        usleep((int) $pause * 1000);
        $state = trim((string) file_get_contents($stateFile));

        if ($state !== 'open') {
            return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'not open']], 400);
        }

        file_put_contents($stateFile, 'expired', LOCK_EX);

        return Http::response($session());
    }

    if ($request->method() === 'GET' && str_contains($request->url(), 'checkout/sessions/')) {
        return Http::response($session());
    }

    return Http::response(['error' => ['message' => 'unexpected '.$request->url()]], 500);
});

$started = microtime(true);

try {
    $status = $mode === 'close'
        ? app(CloseHostedPayment::class)->handle($attempt)
        : app(SettlePaymentAttempt::class)->handle($attempt);
    $print(['status' => $status->value, 'started' => $started, 'ended' => microtime(true)]);
} catch (Throwable $exception) {
    $print(['error' => $exception::class.': '.$exception->getMessage(), 'started' => $started, 'ended' => microtime(true)]);
}
