<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\LookupResult;
use App\Data\Billing\PaymentAttemptRef;
use App\Enums\HostedSessionCancel;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;

require_once __DIR__.'/helpers.php';

/*
| #251 (architecture review of #328): a kept provider URL reaches a driver only on the resumeUrl() call. lookup() and
| cancelSession() refs never carry it, whatever the gateway.
*/

/** Sandbox driver that records the resumeUrl each call's ref carried. */
class ResumeRefRecordingDriver extends SandboxCardDriver
{
    /** @var array<string, list<string|null>> */
    public static array $seen = [];

    public function lookup(GatewayConfig $gateway, PaymentAttemptRef $attempt): LookupResult
    {
        self::$seen['lookup'][] = $attempt->resumeUrl;

        return parent::lookup($gateway, $attempt);
    }

    public function cancelSession(GatewayConfig $gateway, PaymentAttemptRef $attempt): HostedSessionCancel
    {
        self::$seen['cancelSession'][] = $attempt->resumeUrl;

        return parent::cancelSession($gateway, $attempt);
    }

    public function resumeUrl(GatewayConfig $gateway, PaymentAttemptRef $attempt): ?string
    {
        self::$seen['resumeUrl'][] = $attempt->resumeUrl;

        return parent::resumeUrl($gateway, $attempt);
    }
}

test('only the resumeUrl() ref carries the kept URL; lookup and cancelSession refs never do', function () {
    ResumeRefRecordingDriver::$seen = [];
    app(GatewayManager::class)->register(new ResumeRefRecordingDriver);
    sandboxGateway();
    Gateway::query()->where('key', 'sandbox_card')->firstOrFail()->update(['config' => ['webhook_secret' => 'whsec_test', 'checkout_mode' => 'hosted']]);
    [, $client] = actingAsClient();
    $invoice = issueInvoice($client, [5_000]);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => 'sandbox_card'], ['X-Inertia' => 'true']);
    $attempt = PaymentAttempt::query()->where('invoice_id', $invoice->id)->sole();
    $attempt->forceFill(['resume_url' => 'https://pay.example.test/session/1'])->save();

    $this->get(route('client.invoices.attempts.resume', [$invoice->id, $attempt->reference]));
    $this->get(route('billing.gateways.return', ['sandbox_card', 'ref' => $attempt->reference]));
    $this->post(route('client.invoices.attempts.close', [$invoice->id, $attempt->reference]));

    expect(ResumeRefRecordingDriver::$seen['resumeUrl'])->toBe(['https://pay.example.test/session/1'])
        ->and(ResumeRefRecordingDriver::$seen['lookup'] ?? [])->not->toBeEmpty()->each->toBeNull()
        ->and(ResumeRefRecordingDriver::$seen['cancelSession'] ?? [])->not->toBeEmpty()->each->toBeNull();
});
