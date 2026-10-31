<?php

/*
 * ADR 0023 §5: a driver that implements DeclaresSensitiveKeys gets those keys masked in the host's command log, and
 * their values masked in the text the host stores and shows (errors, activity, staff messages).
 */

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Integrations\DeclaresSensitiveKeys;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Service;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;
use Tests\Fakes\Services\ServicesTestKit;

const DECLARED_TICKET = 'tkt-7f3a9c2e51d4';

test('a provisioning driver’s declared key is masked in the module log, the failure item and the activity log', function () {
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class extends LogDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return $operation === 'suspend' ? ['ticket'] : [];
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::fail('Panel refused login ticket '.DECLARED_TICKET, request: ['login' => ['Ticket' => DECLARED_TICKET], 'reference' => 'R-1001'], response: ['ticket' => DECLARED_TICKET]);
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();

    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    $log = ModuleCommand::query()->where('service_id', $service->id)->sole();
    expect($service->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($log->request)->toMatchArray(['login' => ['Ticket' => '[redacted]'], 'reference' => 'R-1001'])
        ->and($log->response)->toBe(['ticket' => '[redacted]'])
        ->and($log->error)->toBe('Panel refused login ticket [redacted]')
        ->and(ActivityLog::query()->where('subject_id', $service->id)->pluck('description')->implode("\n"))->toContain('Panel refused login ticket [redacted]')
        ->and(json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]))->not->toContain(DECLARED_TICKET);
});

test('a registrar driver’s declared key is masked in the registrar log and in the message returned to the caller', function () {
    $script = FakeRegistrar::install();
    app(RegistrarDriverRegistry::class)->register('fake', fn (): RegistrarDriver => new class($script, 'fake') extends FakeRegistrarDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return $operation === 'info' ? ['ticket'] : [];
        }
    }, replace: true);

    $result = app(RegistrarGateway::class)->call('fake', 'info', RegistrarDriver::class, fn (): RegistrarResult => new RegistrarResult(message: 'Linked with ticket '.DECLARED_TICKET, meta: ['ticket' => DECLARED_TICKET, 'order' => 'O-77']), request: ['login' => ['TICKET' => DECLARED_TICKET]]);

    $log = RegistrarCommandLog::query()->sole();
    expect($result->message)->toBe('Linked with ticket [redacted]')
        ->and($log->request)->toBe(['login' => ['TICKET' => '[redacted]']])
        ->and($log->response['meta'])->toBe(['ticket' => '[redacted]', 'order' => 'O-77'])
        ->and(json_encode(RegistrarCommandLog::all()))->not->toContain(DECLARED_TICKET);
});

test('an undeclared operation keeps the key readable', function () {
    $script = FakeRegistrar::install();
    app(RegistrarDriverRegistry::class)->register('fake', fn (): RegistrarDriver => new class($script, 'fake') extends FakeRegistrarDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return $operation === 'epp' ? ['ticket'] : [];
        }
    }, replace: true);

    app(RegistrarGateway::class)->call('fake', 'info', RegistrarDriver::class, fn (): RegistrarResult => new RegistrarResult(meta: ['ticket' => 'T-1001']));

    expect(RegistrarCommandLog::query()->sole()->response['meta'])->toBe(['ticket' => 'T-1001']);
});
