<?php

/*
 * Re-review probes for #574 (head 70e6b77b). A declared value that reaches the host only in exception text, or twice
 * under one JSON key, is stored or reported in clear. Adopt into tests/Feature/Integrations/DeclaredKeysMaskingTest.php.
 */

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Integrations\DeclaresSensitiveKeys;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Service;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;
use Tests\Fakes\Services\ServicesTestKit;

const EXCEPTION_CODE = 'ZXCV-99812-QWER';

function suspendWithAnswer(Closure $answer): void
{
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class($answer) extends LogDriver implements DeclaresSensitiveKeys
    {
        public function __construct(private Closure $answer) {}

        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return ($this->answer)();
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();
    app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);
}

test('a declared value a driver throws is not reported to the application log in clear', function (string $message) {
    Exceptions::fake();

    suspendWithAnswer(fn () => throw new RuntimeException($message));

    // At 70e6b77b the stored rows are masked, but callDriver() reports the message masked by secrets only.
    expect(json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]))->not->toContain(EXCEPTION_CODE);
    expect(json_encode(array_map(fn (Throwable $e): string => $e->getMessage(), Exceptions::reported())))->not->toContain(EXCEPTION_CODE);
})->with([
    'element' => ['Panel answered <ticket>'.EXCEPTION_CODE.'</ticket>'],
    'unreadable form (fail closed)' => ['Panel answered ticket: '.EXCEPTION_CODE],
]);

test('a declared value only in a previous exception of a registrar throw is masked in the thrown chain', function () {
    $script = FakeRegistrar::install();
    app(RegistrarDriverRegistry::class)->register('fake', fn (): RegistrarDriver => new class($script, 'fake') extends FakeRegistrarDriver implements DeclaresSensitiveKeys
    {
        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }
    }, replace: true);

    $chain = '';

    try {
        app(RegistrarGateway::class)->call('fake', 'info', RegistrarDriver::class, function (): never {
            throw new RuntimeException('call failed', previous: new RuntimeException('<ticket>'.EXCEPTION_CODE.'</ticket> refused '.EXCEPTION_CODE));
        });
    } catch (Throwable $exception) {
        for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
            $chain .= $link->getMessage()."\n";
        }
    }

    // At 70e6b77b withValuesIn() reads only the top message: the third link keeps the value twice.
    expect($chain)->toContain('call failed')->not->toContain(EXCEPTION_CODE);
});

test('a declared key repeated in a JSON body is masked or withheld for every value', function () {
    suspendWithAnswer(fn (): DriverResult => DriverResult::fail('Panel refused '.EXCEPTION_CODE, response: ['body' => '{"ticket":"'.EXCEPTION_CODE.'","ticket":"BBBB-2222-yyyy"}']));

    // At 70e6b77b json_decode() keeps the last value only, so the first is not collected: error = "Panel refused ZXCV-99812-QWER".
    expect(json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]))->not->toContain(EXCEPTION_CODE);
});

test('non-blocking: a declared pair percent-encoded twice is masked or withheld', function () {
    suspendWithAnswer(fn (): DriverResult => DriverResult::fail('Panel refused '.EXCEPTION_CODE, response: ['body' => 'next=%2Fcb%253Fticket%253D'.EXCEPTION_CODE]));

    expect(json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]))->not->toContain(EXCEPTION_CODE);
});
