<?php

use App\Data\Billing\ChargeRequest;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Modules\Billing\Actions\CaptureInvoice;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Transaction;

require_once __DIR__.'/helpers.php';

/** A card driver whose capture answer carries a token and a quoted secret, as a provider's raw object can. */
class TokenEchoingSandboxDriver extends SandboxCardDriver
{
    public function capture(GatewayConfig $gateway, ChargeRequest $request): ChargeResult
    {
        $result = parent::capture($gateway, $request);

        return new ChargeResult($result->status, $result->txnId, $result->fee, raw: [...$result->raw, 'payment_method_token' => 'tok_secret', 'note' => 'api-key=K1']);
    }
}

test('a captured charge stores its raw provider answer redacted on the transaction (#190)', function () {
    app(GatewayManager::class)->register(new TokenEchoingSandboxDriver);
    sandboxGateway();
    $client = billingClient();
    $invoice = issueInvoice($client, [5_000]);
    $card = PayMethod::factory()->create(['client_id' => $client->id]);

    app(CaptureInvoice::class)->handle($invoice, 'sandbox_card', $card->token, $card);

    $raw = Transaction::query()->where('invoice_id', $invoice->id)->firstOrFail()->raw;
    expect($raw)->toMatchArray(['payment_method_token' => '[redacted]', 'note' => 'api-key=[redacted]', 'amount' => 5_000])
        ->and($raw['id'])->toStartWith('sbx_ch_');
});
