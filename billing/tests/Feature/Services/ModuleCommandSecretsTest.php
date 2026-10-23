<?php

use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fakes\Services\ServicesTestKit;

/*
 * A driver that throws while changing a password must not put the new password in what is reported (#383).
 */

beforeEach(function () {
    app()->instance(LogDriver::class, new class extends LogDriver
    {
        public function changePassword(ProvisioningContext $context, #[SensitiveParameter] string $newPassword): DriverResult
        {
            throw new RuntimeException("panel refused password {$newPassword} for {$context->service->username}");
        }
    });
    ServicesTestKit::bind();
    [, $this->client] = actingAsClient();
    Exceptions::fake();
});

test('a driver exception during a password change is reported without the new password', function () {
    $service = Service::factory()->onServer()->create(['client_id' => $this->client->id]);

    $this->post(route('client.services.password', $service->id), ['password' => 'Str0ngPassword', 'password_confirmation' => 'Str0ngPassword']);

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'panel refused password'));
    expect(collect(Exceptions::reported())->map(fn (Throwable $e): string => (string) $e)->filter(fn (string $e): bool => str_contains($e, 'Str0ngPassword')))->toBeEmpty()
        ->and(ModuleCommand::query()->where('service_id', $service->id)->whereNotNull('error')->exists())->toBeTrue()
        ->and(ModuleCommand::query()->where('service_id', $service->id)->pluck('error')->filter(fn (?string $e): bool => str_contains((string) $e, 'Str0ngPassword')))->toBeEmpty();
});
