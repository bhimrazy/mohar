<?php

/*
 * Review-board probes for #574 (declared sensitive keys). Each test states the behaviour the review asks for; a failing
 * one reproduces a finding.
 */

use App\Contracts\Integrations\DeclaresSensitiveKeys;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Service;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fakes\Services\ServicesTestKit;

const PR574_CODE = 'ZXCV-99812-QWER';

beforeEach(function () {
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
});

test('probe A: a declared value that the driver logged inside a JSON string body is masked in the error text', function () {
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class extends LogDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            // The driver logs the raw HTTP body it got, a JSON document, and echoes the value in its message.
            return DriverResult::fail('Panel refused ticket '.PR574_CODE, response: ['body' => json_encode(['ticket' => PR574_CODE])]);
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();

    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    expect(json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]))->not->toContain(PR574_CODE);
});

test('probe B: a declared value inside an XML string body is masked in the error text', function () {
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class extends LogDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::fail('Panel refused ticket '.PR574_CODE, response: ['body' => '<r><ticket>'.PR574_CODE.'</ticket></r>']);
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();

    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    expect(json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]))->not->toContain(PR574_CODE);
});

test('probe C: a driver whose sensitiveKeys() throws does not leave a completed create running and unapplied', function () {
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class extends LogDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            throw new RuntimeException('bug in a package driver');
        }
    }, replace: true);
    $service = Service::factory()->pending()->onServer()->create();

    try {
        app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Create);
    } catch (Throwable) {
        // Recorded below.
    }

    // Either the call is refused before the driver runs, or the driver's answer is recorded: never a row left running.
    expect(ModuleCommand::query()->where('service_id', $service->id)->where('status', ModuleCommand::RUNNING)->count())->toBe(0);
});

test('probe D (non-blocking): a short declared value (4 digits) does not mask digits inside a longer number in the error text', function () {
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class extends LogDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return ['pin'];
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::fail('Connection to port 10000 refused after 1000 ms', request: ['pin' => '1000']);
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();

    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    // Today: "Connection to port [redacted]0 refused after [redacted] ms".
    expect(ModuleCommand::query()->where('service_id', $service->id)->value('error'))->toContain('port 10000');
});
