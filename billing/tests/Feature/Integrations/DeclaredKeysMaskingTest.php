<?php

/*
 * ADR 0023 §5: a driver that implements DeclaresSensitiveKeys gets those keys masked in the host's command log, and
 * their values masked in the text the host stores and shows (errors, activity, staff messages). The string-body,
 * throwing-driver and short-value cases were adopted from the #574 review probes.
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
use App\Support\Domains\RegistrarException;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;
use Tests\Fakes\Services\ServicesTestKit;

const DECLARED_TICKET = 'tkt-7f3a9c2e51d4';

const DECLARED_CODE = 'ZXCV-99812-QWER';

/**
 * Replaces the log driver with one that declares $keys for every command and answers suspend with $answer.
 *
 * @param  list<string>  $keys
 */
function declaringLogDriver(array $keys, ?Closure $answer = null): void
{
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class($keys, $answer) extends LogDriver implements DeclaresSensitiveKeys
    {
        /** @param  list<string>  $keys */
        public function __construct(private array $keys, private ?Closure $answer) {}

        public function sensitiveKeys(string $operation): array
        {
            return $this->keys;
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return $this->answer === null ? parent::suspend($context, $reason) : ($this->answer)();
        }
    }, replace: true);
}

function suspendWithDeclaringDriver(): Service
{
    $service = Service::factory()->onServer()->create();
    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    return $service;
}

function storedModuleText(): string
{
    return (string) json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]);
}

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

test('a declared value inside a JSON string body is masked in the error, the failure item and activity', function () {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused ticket '.DECLARED_CODE, response: ['body' => json_encode(['ticket' => DECLARED_CODE])]));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain(DECLARED_CODE)
        ->and(ModuleCommand::query()->value('error'))->toBe('Panel refused ticket [redacted]');
});

test('a declared value inside an XML or name=value string body is masked in the stored text', function (string $body) {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused ticket '.DECLARED_CODE, response: ['body' => $body]));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain(DECLARED_CODE)
        ->and(ModuleCommand::query()->value('error'))->toBe('Panel refused ticket [redacted]');
})->with([
    'xml element' => ['<r><ticket>'.DECLARED_CODE.'</ticket></r>'],
    'query string' => ['status=denied&ticket='.DECLARED_CODE.'&retry=0'],
]);

test('a driver whose sensitiveKeys() throws fails the create before the driver is called', function () {
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
    $driver = new class extends LogDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            throw new RuntimeException('bug in a package driver');
        }
    };
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => $driver, replace: true);
    $service = Service::factory()->pending()->onServer()->create();

    $outcome = app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Create);

    expect($outcome->succeededNow())->toBeFalse()
        ->and($driver->calls)->toBeEmpty()
        ->and(ModuleCommand::query()->where('service_id', $service->id)->pluck('status')->all())->toBe([ModuleCommand::FAILED])
        ->and(ModuleQueueItem::query()->where('subject_id', $service->id)->value('kind'))->toBe(ModuleQueueItem::KIND_FAILURE)
        ->and($service->refresh()->status)->toBe(ServiceStatus::Pending);
});

test('a registrar driver whose sensitiveKeys() throws gets a registrar error and is never called', function () {
    $script = FakeRegistrar::install();
    app(RegistrarDriverRegistry::class)->register('fake', fn (): RegistrarDriver => new class($script, 'fake') extends FakeRegistrarDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            throw new RuntimeException('bug in a package driver');
        }
    }, replace: true);
    $called = false;

    expect(fn () => app(RegistrarGateway::class)->call('fake', 'register', RegistrarDriver::class, function () use (&$called): RegistrarResult {
        $called = true;

        return new RegistrarResult;
    }))->toThrow(RegistrarException::class, 'could not name its sensitive keys; nothing was sent');

    expect($called)->toBeFalse()
        ->and(RegistrarCommandLog::query()->sole()->success)->toBeFalse();
});

test('a short declared value is masked only as a whole token', function () {
    declaringLogDriver(['pin'], fn (): DriverResult => DriverResult::fail('Connection to port 10000 refused after 1000 ms', request: ['pin' => '1000']));

    suspendWithDeclaringDriver();

    expect(ModuleCommand::query()->value('error'))->toBe('Connection to port 10000 refused after [redacted] ms');
});

test('declared values past the node limit withhold the text whole', function () {
    $redactor = app(Redactor::class);
    $payload = ['rows' => array_fill(0, 50_001, 'x'), 'ticket' => DECLARED_CODE];

    $values = $redactor->valuesUnder(['ticket'], $payload);

    expect($values)->toBeNull()
        ->and($redactor->maskDeclared('Panel refused ticket '.DECLARED_CODE, $values))->toBe(Redactor::MASK)
        ->and($redactor->valuesUnder(['ticket'], ['ticket' => DECLARED_CODE]))->toBe([DECLARED_CODE]);
});
