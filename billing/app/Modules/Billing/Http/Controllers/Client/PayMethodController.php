<?php

namespace App\Modules\Billing\Http\Controllers\Client;

use App\Contracts\Billing\GatewayDriver;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\ManagePayMethods;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\Client\StorePayMethodRequest;
use App\Modules\Billing\Http\Resources\PayMethodResource;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stored (tokenised) payment methods. Card data goes to the gateway's remote input, never to this controller.
 */
class PayMethodController extends Controller
{
    public function __construct(private ClientContext $context, private ManagePayMethods $payMethods) {}

    public function index(GatewayManager $gateways, BillingSettings $settings): Response
    {
        $this->context->authorize('billing');

        return Inertia::render('client/billing/pay-methods/index', [
            'pay_methods' => PayMethodResource::collection(PayMethod::query()->where('client_id', $this->context->id())->orderByDesc('is_default')->orderBy('id')->get()),
            'gateways' => array_values($gateways->clientGateways()
                ->filter(fn (Gateway $gateway): bool => $gateways->supports($gateway->key, GatewayDriver::TOKENIZE))
                ->map(fn (Gateway $gateway): array => ['key' => $gateway->key, 'name' => $gateway->display_name, 'vault_url' => route('billing.sandbox.tokens')])
                ->all()),
            'can_remove' => $settings->bool('allow_pay_method_removal'),
        ]);
    }

    public function store(StorePayMethodRequest $request): RedirectResponse
    {
        $this->context->authorize('billing');
        $this->payMethods->store($this->context->id(), $request->string('gateway')->toString(), $request->string('token')->toString(), $request->boolean('make_default'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Card saved.')]);

        return back();
    }

    public function makeDefault(int $payMethod): RedirectResponse
    {
        $this->context->authorize('billing');
        $this->payMethods->makeDefault($this->find($payMethod));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Default payment method updated.')]);

        return back();
    }

    public function destroy(int $payMethod, BillingSettings $settings): RedirectResponse
    {
        $this->context->authorize('billing');

        if (! $settings->bool('allow_pay_method_removal')) {
            throw new BillingException(__('Contact us to remove a saved payment method.'));
        }

        $this->payMethods->delete($this->find($payMethod));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment method removed.')]);

        return back();
    }

    private function find(int $id): PayMethod
    {
        return PayMethod::query()->where('client_id', $this->context->id())->findOrFail($id);
    }
}
