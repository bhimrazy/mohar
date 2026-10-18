<?php

namespace App\Modules\Billing\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The sandbox gateway's own hosted payment page (stands in for the provider's website).
 */
class SandboxHostedController extends Controller
{
    public function show(string $reference, GatewayManager $gateways): View
    {
        $state = $this->state($reference, $gateways);

        return view('billing::sandbox.checkout', [
            'reference' => $reference,
            'amount' => app(MoneyFormatter::class)->format(Money::of($state['amount'], $state['currency'])),
            'description' => $state['description'],
        ]);
    }

    public function pay(Request $request, string $reference, GatewayManager $gateways): RedirectResponse
    {
        $this->state($reference, $gateways);
        $driver = $gateways->driver('sandbox_card');
        abort_unless($driver instanceof SandboxCardDriver, 404);

        // No validator here on purpose: a validation redirect would flash the card number into the session.
        $card = substr((string) preg_replace('/\D/', '', $request->string('card_number')->toString()), 0, 19);
        $target = $driver->completeHostedPayment($reference, $card, $request->boolean('cancel'));

        return redirect()->away((string) $target);
    }

    /**
     * @return array{pidx: string, amount: int, currency: string, return_url: string, cancel_url: string, description: string, status: string, txn_id: string|null}
     */
    private function state(string $reference, GatewayManager $gateways): array
    {
        abort_unless($gateways->isActive('sandbox_card'), 404);
        $state = SandboxCardDriver::providerState($reference);
        abort_if($state === null || $state['status'] !== 'initiated', 404);

        return $state;
    }
}
