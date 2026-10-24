<?php

use App\Contracts\Billing\ConnectionTester;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Integrations\ConnectionCheck;
use App\Enums\HostedSessionCancel;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Support\Integrations\RedactionScope;

require_once __DIR__.'/helpers.php';

/*
| #462 security re-check: resumeUrl(), cancelSession() and testConnection() reach the provider, so they run in
| GatewayManager::during() like every other gateway call (ADR 0023 §5). The driver records whether the gateway's
| configured secret was in the redaction scope while each ran.
*/

/** Sandbox driver that records, per call, whether its gateway's secret is masked by the scope. */
class ScopeRecordingGatewayDriver extends SandboxCardDriver implements ConnectionTester
{
    /** @var array<string, list<bool>> */
    public static array $scoped = [];

    public function cancelSession(GatewayConfig $gateway, PaymentAttemptRef $attempt): HostedSessionCancel
    {
        self::$scoped['cancelSession'][] = RedactionScope::current()->matches('ScopedSecret0462');

        return parent::cancelSession($gateway, $attempt);
    }

    public function resumeUrl(GatewayConfig $gateway, PaymentAttemptRef $attempt): ?string
    {
        self::$scoped['resumeUrl'][] = RedactionScope::current()->matches('ScopedSecret0462');

        return parent::resumeUrl($gateway, $attempt);
    }

    public function testConnection(GatewayConfig $gateway): ConnectionCheck
    {
        self::$scoped['testConnection'][] = RedactionScope::current()->matches('ScopedSecret0462');

        return new ConnectionCheck(true, 'ok');
    }
}

test('resumeUrl, cancelSession and testConnection run with the gateway\'s secrets in scope', function () {
    ScopeRecordingGatewayDriver::$scoped = [];
    app(GatewayManager::class)->register(new ScopeRecordingGatewayDriver);
    sandboxGateway('ScopedSecret0462');
    Gateway::query()->where('key', 'sandbox_card')->firstOrFail()->update(['config' => ['webhook_secret' => 'ScopedSecret0462', 'checkout_mode' => 'hosted']]);
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card'], ['X-Inertia' => 'true']);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    $attempt->forceFill(['resume_url' => 'https://pay.example.test/session/1'])->save();

    $this->get(route('client.invoices.attempts.resume', [$invoice->id, $attempt->reference]));
    $this->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]));
    billingStaff();
    $this->post(route('admin.billing.gateways.test', 'sandbox_card'));

    expect(ScopeRecordingGatewayDriver::$scoped)->toHaveKeys(['resumeUrl', 'cancelSession', 'testConnection'])
        ->and(array_merge(...array_values(ScopeRecordingGatewayDriver::$scoped)))->each->toBeTrue()
        ->and(RedactionScope::current()->isEmpty())->toBeTrue();
});
