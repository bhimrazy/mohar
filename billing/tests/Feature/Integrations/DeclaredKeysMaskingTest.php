<?php

/*
 * ADR 0023 §5: a driver that implements DeclaresSensitiveKeys gets those keys masked in the host's command log, and
 * their values masked in the text the host stores and shows (errors, activity, staff messages). A payload it cannot
 * read in full is withheld whole, with its text. The string-body, markup, attribute, throwing-driver, node-limit and
 * short-value cases were adopted from the #574 review and re-review probes.
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

test('a declared value inside CDATA or an element redact() masks whole is masked everywhere it is stored', function (string $body) {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.DECLARED_CODE, response: ['body' => $body]));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain(DECLARED_CODE)
        ->and(ModuleCommand::query()->value('error'))->toBe('Panel refused [redacted]');
})->with([
    'cdata' => ['<r><ticket><![CDATA['.DECLARED_CODE.']]></ticket></r>'],
    'epp root' => ['<epp><command><ticket>'.DECLARED_CODE.'</ticket></command></epp>'],
]);

test('a registrar message is masked when the declared value sits in an EPP XML answer', function () {
    $script = FakeRegistrar::install();
    app(RegistrarDriverRegistry::class)->register('fake', fn (): RegistrarDriver => new class($script, 'fake') extends FakeRegistrarDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }
    }, replace: true);

    $result = app(RegistrarGateway::class)->call('fake', 'info', RegistrarDriver::class, fn (): RegistrarResult => new RegistrarResult(message: 'Refused '.DECLARED_CODE, meta: ['raw' => '<epp><ticket>'.DECLARED_CODE.'</ticket></epp>']));

    expect($result->message)->toBe('Refused [redacted]')
        ->and(json_encode(RegistrarCommandLog::all()))->not->toContain(DECLARED_CODE);
});

test('a payload past the node limit is withheld whole with its text', function () {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.DECLARED_CODE, response: [
        'ticket' => DECLARED_CODE,
        'note' => 'refused '.DECLARED_CODE,
        'rows' => array_fill(0, 50_001, 'x'),
    ]));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain(DECLARED_CODE)
        ->and(ModuleCommand::query()->sole()->response)->toBe(['withheld' => '[redacted]'])
        ->and(ModuleCommand::query()->value('error'))->toBe('[redacted]');
});

test('a body that uses a declared key in a form not read is withheld whole with its text', function () {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.DECLARED_CODE, response: ['body' => '<item key="ticket">'.DECLARED_CODE.'</item>']));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain(DECLARED_CODE)
        ->and(ModuleCommand::query()->sole()->response)->toBe(['withheld' => '[redacted]']);
});

test('a short declared value echoed under another key is masked in the logged payload', function () {
    declaringLogDriver(['pin'], fn (): DriverResult => DriverResult::fail('PIN rejected', response: ['pin' => '4821', 'message' => 'PIN 4821 rejected']));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain('4821')
        ->and(ModuleCommand::query()->sole()->response)->toBe(['pin' => '[redacted]', 'message' => 'PIN [redacted] rejected']);
});

test('a percent-encoded declared value is masked where the driver echoes it decoded', function () {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused ab+cd/ef99812QWER==', response: ['body' => 'status=denied&ticket=ab%2Bcd%2Fef99812QWER%3D%3D&retry=0']));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain('ab+cd\\/ef99812QWER==')
        ->and(ModuleCommand::query()->value('error'))->toBe('Panel refused [redacted]');
});

test('a declared value in a quoted attribute or quoted pair is masked in every stored text and the outcome', function (string $body) {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.DECLARED_CODE, response: ['body' => $body]));

    $service = Service::factory()->onServer()->create();
    $outcome = app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    expect(storedModuleText())->not->toContain(DECLARED_CODE)
        ->and((string) $outcome->message)->toBe('Panel refused [redacted]');
})->with([
    'double-quoted attribute' => ['<item ticket="'.DECLARED_CODE.'"/>'],
    'single-quoted attribute' => ["<item ticket='".DECLARED_CODE."'/>"],
    'quoted pair in a log line' => ['refused ticket="'.DECLARED_CODE.'" retry=0'],
]);

test('a body the host cannot read as one value per declared key is withheld whole with its text', function (string $body) {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.DECLARED_CODE, response: ['body' => $body]));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain(DECLARED_CODE)
        ->and(ModuleCommand::query()->sole()->response)->toBe(['withheld' => '[redacted]'])
        ->and(ModuleCommand::query()->value('error'))->toBe('[redacted]');
})->with([
    'attribute on the declared element' => ['<ticket value="'.DECLARED_CODE.'"></ticket>'],
    'attribute on a filled declared element' => ['<ticket value="'.DECLARED_CODE.'">pending</ticket>'],
    'percent-encoded JSON in a form field' => ['payload='.rawurlencode((string) json_encode(['ticket' => DECLARED_CODE]))],
    'nested element' => ['<ticket><code>'.DECLARED_CODE.'</code></ticket>'],
    'empty pair' => ['ticket=&next='.DECLARED_CODE],
    'empty quoted pair' => ['ticket="" next="'.DECLARED_CODE.'"'],
    'self-closing element' => ['<ticket/>'.DECLARED_CODE],
]);

test('a declared element is read with its namespace prefix, entities and any case', function (string $body, string $message) {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.$message, response: ['body' => $body]));

    suspendWithDeclaringDriver();

    expect(ModuleCommand::query()->value('error'))->toBe('Panel refused [redacted]')
        ->and(storedModuleText())->not->toContain($message);
})->with([
    'namespace prefix' => ['<epp:ticket>'.DECLARED_CODE.'</epp:ticket>', DECLARED_CODE],
    'entity in the value' => ['<ticket>ab&amp;cd-99812</ticket>', 'ab&cd-99812'],
    'upper-case echo' => ['<ticket>zxcv-99812-qwer</ticket>', 'ZXCV-99812-QWER'],
]);

test('a declared value only in the driver message markup is masked', function () {
    declaringLogDriver(['ticket'], fn (): DriverResult => DriverResult::fail('Panel answered <ticket>'.DECLARED_CODE.'</ticket>, then refused '.DECLARED_CODE));

    suspendWithDeclaringDriver();

    expect(storedModuleText())->not->toContain(DECLARED_CODE);
});

test('a declared value under 4 bytes is masked by key only', function () {
    declaringLogDriver(['pin'], fn (): DriverResult => DriverResult::fail('PIN 482 rejected', request: ['pin' => '482'], response: ['echo' => 'PIN 482']));

    suspendWithDeclaringDriver();

    $log = ModuleCommand::query()->sole();
    expect($log->request)->toBe(['pin' => '[redacted]'])
        ->and($log->response)->toBe(['echo' => 'PIN 482'])
        ->and($log->error)->toBe('PIN 482 rejected');
});

test('declared values are matched by length: none under 4 bytes, whole tokens under 8, substrings from 8', function () {
    $redactor = app(Redactor::class);

    expect($redactor->valuesUnder(['pin'], ['pin' => '482']))->toBe([])
        ->and($redactor->valuesUnder(['pin'], ['pin' => '4821']))->toBe(['4821'])
        ->and($redactor->maskDeclared('x4821y and 4821', ['4821']))->toBe('x4821y and [redacted]')
        ->and($redactor->maskDeclared('xAB12CD3y and AB12CD3', ['AB12CD3']))->toBe('xAB12CD3y and [redacted]')
        ->and($redactor->maskDeclared('xAB12CD34y', ['AB12CD34']))->toBe('x[redacted]y')
        ->and($redactor->redactDeclared(['note' => 'x4821y'], ['4821'], [], []))->toBe(['note' => 'x[redacted]y']);
});
