<?php

use App\Contracts\Billing\GatewayRegistry;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\PaymentAttempt;

require_once __DIR__.'/helpers.php';

test('the sandbox gateway is disabled in production unless explicitly forced', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(SandboxCardDriver::enabled())->toBeFalse();

    config(['billing.sandbox_enabled' => true]);
    expect(SandboxCardDriver::enabled())->toBeTrue();

    app()->detectEnvironment(fn () => 'testing');
    config(['billing.sandbox_enabled' => null]);
    expect(SandboxCardDriver::enabled())->toBeTrue();
});

test('a disabled sandbox is not registered and cannot be activated', function () {
    config(['billing.sandbox_enabled' => false]);
    app()->forgetInstance(GatewayManager::class);
    app()->forgetInstance(GatewayRegistry::class);

    expect(app(GatewayRegistry::class)->has(SandboxCardDriver::KEY))->toBeFalse()
        ->and(fn () => app(ConfigureGateway::class)->handle(SandboxCardDriver::KEY, [
            'display_name' => 'Card', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null,
        ], []))->toThrow(BillingException::class, 'Sandbox gateways can\'t be activated in production.');
});

test('the seeder never creates the sandbox gateway outside local and testing', function () {
    app()->detectEnvironment(fn () => 'staging');

    $this->seed(BillingSeeder::class);

    expect(Gateway::query()->where('key', SandboxCardDriver::KEY)->exists())->toBeFalse()
        ->and(Gateway::query()->where('key', 'bank_transfer')->exists())->toBeTrue();
});

test('payment attempt references are random and unguessable', function () {
    $references = collect(range(1, 50))->map(fn () => PaymentAttempt::newReference());

    expect($references->unique())->toHaveCount(50)
        ->and($references->every(fn (string $reference) => preg_match('/^[a-z2-7]{26}$/', $reference) === 1))->toBeTrue();
});
