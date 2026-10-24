<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\GatewayDriver;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Http\Requests\Admin\GatewayRequest;
use App\Modules\Billing\Services\GatewayLogger;
use App\Modules\Core\Models\Currency;
use App\Support\Integrations\StoredSecrets;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
        $fields = array_map(SettingField::fromArray(...), $driver->settingsFields());

        return Inertia::render('admin/billing/gateways/edit', [
            'gateway' => [
                ...$this->present($driver),
                // Secrets are never sent back to the browser; an empty field keeps the stored value.
                'settings' => collect($fields)->mapWithKeys(fn (SettingField $field): array => [
                    $field->key => $field->isSecret() ? '' : (string) $model->setting($field->key, $field->default ?? ''),
                ]),
                'secrets_set' => collect($fields)->filter(fn (SettingField $field): bool => $field->isSecret())
                    ->mapWithKeys(fn (SettingField $field): array => [$field->key => (string) $model->setting($field->key, '') !== '']),
                // Keys only: stored secrets saved before the minimum length, which staff should replace.
                'secrets_short' => StoredSecrets::tooShort($fields, $model->config ?? []),
            ],
            'fields' => array_map(fn (SettingField $field): array => $field->toArray(), $fields),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code'),
            'replacements' => array_values(array_map(fn (GatewayDriver $other): array => ['value' => $other->key(), 'label' => $this->gateways->gateway($other->key())->display_name], array_filter($this->gateways->drivers(), fn (GatewayDriver $other): bool => $other->key() !== $gateway))),
            'webhook_url' => $driver->supports(GatewayDriver::WEBHOOK) ? route('billing.webhooks', $gateway) : null,
            'can_test_connection' => $driver instanceof ConnectionTester,
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
        ], $settings, $request->filled('replacement') ? $request->string('replacement')->toString() : null, $request->user()?->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':gateway saved.', ['gateway' => $model->display_name])]);

        return to_route('admin.billing.gateways.index');
    }

    /**
     * Check the saved credentials with the provider (drivers implementing ConnectionTester). Moves no money and
     * changes nothing, so it needs no password step-up; it is throttled because it calls the provider.
     */
    public function test(string $gateway, GatewayLogger $log): RedirectResponse
    {
        $this->authorize('billing.gateways.manage');
        $driver = $this->gateways->driver($gateway);
        abort_unless($driver instanceof ConnectionTester, 404);

        try {
            $config = $this->gateways->config($gateway);
            $check = $this->gateways->during($config, fn (): ConnectionCheck => $driver->testConnection($config));
        } catch (Throwable $exception) {
            report($exception);
            $check = new ConnectionCheck(false, __('Couldn\'t reach the provider. Try again in a minute.'));
        }

        $log->log($gateway, 'request', $check->ok ? 'connection test ok' : 'connection test failed', ['message' => $check->message]);
        Inertia::flash('toast', ['type' => $check->ok ? 'success' : 'error', 'message' => $check->message]);

        return back();
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
            'sandbox' => $this->gateways->isSandbox($model),
            'logo' => $this->gateways->logo($driver->key()),
        ];
    }
}
