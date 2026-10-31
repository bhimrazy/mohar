<?php

/*
 * Re-review probes for #574 (head e804e38f). Each test states the behaviour asked for; at that head all of them fail.
 * Copy into tests/Feature/Integrations/DeclaredKeysMaskingTest.php when adopting.
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
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Service;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;
use Tests\Fakes\Services\ServicesTestKit;

const MARKUP_CODE = 'ZXCV-99812-QWER';

/** Suspends a service through a log driver that declares $keys and answers with $answer; returns everything stored. */
function suspendStoring(array $keys, Closure $answer): string
{
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class($keys, $answer) extends LogDriver implements DeclaresSensitiveKeys
    {
        public function __construct(private array $keys, private Closure $answer) {}

        public function sensitiveKeys(string $operation): array
        {
            return $this->keys;
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return ($this->answer)();
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();
    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    return (string) json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]);
}

test('a declared value is masked in stored text when the body wraps it in CDATA or an element redact() masks whole', function (string $body) {
    $stored = suspendStoring(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.MARKUP_CODE, response: ['body' => $body]));

    // At e804e38f valuesUnder() collects the masked span ("<![CDATA[…]]>", "<ticket>…</ticket>"), not the value.
    expect($stored)->not->toContain(MARKUP_CODE);
})->with([
    'cdata' => ['<r><ticket><![CDATA['.MARKUP_CODE.']]></ticket></r>'],
    'epp root' => ['<epp><command><ticket>'.MARKUP_CODE.'</ticket></command></epp>'],
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

    $result = app(RegistrarGateway::class)->call('fake', 'info', RegistrarDriver::class, fn (): RegistrarResult => new RegistrarResult(message: 'Refused '.MARKUP_CODE, meta: ['raw' => '<epp><ticket>'.MARKUP_CODE.'</ticket></epp>']));

    expect($result->message)->toBe('Refused [redacted]')
        ->and(json_encode(RegistrarCommandLog::all()))->not->toContain(MARKUP_CODE);
});

test('past the node limit the logged payload does not keep an echo of a declared value', function () {
    $stored = suspendStoring(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused '.MARKUP_CODE, response: [
        'ticket' => MARKUP_CODE,
        'note' => 'refused '.MARKUP_CODE,
        'rows' => array_fill(0, 50_001, 'x'),
    ]));

    // At e804e38f the error is withheld but response.note keeps the value (dfd7e86d masked it).
    expect($stored)->not->toContain(MARKUP_CODE);
});

test('a short declared value echoed under another key is masked in the logged payload', function () {
    $stored = suspendStoring(['pin'], fn (): DriverResult => DriverResult::fail('PIN rejected', response: ['pin' => '4821', 'message' => 'PIN 4821 rejected']));

    // At e804e38f response.message keeps "PIN 4821 rejected" (dfd7e86d stored "PIN [redacted] rejected").
    expect($stored)->not->toContain('4821');
});

test('non-blocking: a percent-encoded declared value is masked where the driver echoes it decoded', function () {
    $stored = suspendStoring(['ticket'], fn (): DriverResult => DriverResult::fail('Panel refused ab+cd/ef99812QWER==', response: ['body' => 'status=denied&ticket=ab%2Bcd%2Fef99812QWER%3D%3D&retry=0']));

    expect($stored)->not->toContain('ab+cd\/ef99812QWER==');
});
