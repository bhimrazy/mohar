<?php

use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use Illuminate\Testing\TestResponse;

/**
 * POST a signed sandbox webhook (secret "whsec_test", as created by sandboxGateway()).
 *
 * @param  array<string, mixed>  $payload
 */
function sandboxWebhook(array $payload, string $secret = 'whsec_test'): TestResponse
{
    $body = (string) json_encode($payload);

    return test()->call('POST', route('billing.webhooks', 'sandbox_card'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SANDBOX_SIGNATURE' => SandboxCardDriver::signature($body, $secret),
    ], $body);
}
