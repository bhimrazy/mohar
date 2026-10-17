<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Contracts\Billing\GatewayDriver;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Http\Requests\Admin\GatewayRequest;
use App\Modules\Core\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GatewayController extends Controller
{
    public function __construct(private GatewayManager $gateways) {}

    public function index(): Response
    {
        $this->authorize('billing.gateways.manage');

        return Inertia::render('admin/billing/gateways/index', [
            'gateways' => array_values(array_map(fn (GatewayDriver $driver): array => $this->present($driver), $this->gateways->drivers())),
        ]);
    }

    public function edit(string $gateway): Response
    {
        $this->authorize('billing.gateways.manage');
        $driver = $this->gateways->driver($gateway);
        $model = $this->gateways->gateway($gateway);

        return Inertia::render('admin/billing/gateways/edit', [
            'gateway' => [
                ...$this->present($driver),
                // Secrets are never sent back to the browser; an empty field keeps the stored value.
                'settings' => collect($driver->settingsFields())->mapWithKeys(fn (array $field): array => [
                    $field['key'] => in_array($field['type'], ['secret', 'secret_text'], true) ? '' : (string) $model->setting($field['key'], $field['default'] ?? ''),
                ]),
                'secrets_set' => collect($driver->settingsFields())->whereIn('type', ['secret', 'secret_text'])
                    ->mapWithKeys(fn (array $field): array => [$field['key'] => (string) $model->setting($field['key'], '') !== '']),
            ],
            'fields' => $driver->settingsFields(),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code'),
            'replacements' => array_values(array_map(fn (GatewayDriver $other): array => ['value' => $other->key(), 'label' => $this->gateways->gateway($other->key())->display_name], array_filter($this->gateways->drivers(), fn (GatewayDriver $other): bool => $other->key() !== $gateway))),
            'webhook_url' => $driver->supports(GatewayDriver::WEBHOOK) ? route('billing.webhooks', $gateway) : null,
        ]);
    }

    public function update(GatewayRequest $request, string $gateway, ConfigureGateway $configure): RedirectResponse
    {
        /** @var array<string, string|null> $settings */
        $settings = $request->validated('settings', []);

        $model = $configure->handle($gateway, [
            'display_name' => $request->string('display_name')->toString(),
            'is_active' => $request->boolean('is_active'),
            'show_on_order_form' => $request->boolean('show_on_order_form'),
            'sort_order' => $request->integer('sort_order'),
            'convert_to_currency' => $request->validated('convert_to_currency'),
        ], $settings, $request->filled('replacement') ? $request->string('replacement')->toString() : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':gateway saved.', ['gateway' => $model->display_name])]);

        return to_route('admin.billing.gateways.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GatewayDriver $driver): array
    {
        $model = $this->gateways->gateway($driver->key());

        return [
            'key' => $driver->key(),
            'driver_name' => $driver->name(),
            'display_name' => $model->display_name,
            'is_active' => $model->exists && $model->is_active,
            'configured' => $model->exists,
            'show_on_order_form' => $model->show_on_order_form,
            'sort_order' => $model->sort_order,
            'convert_to_currency' => $model->convert_to_currency,
            'capabilities' => $driver->capabilities(),
        ];
    }
}
