<?php

/*
 * Re-review probes for #574 (head 1f686152). A declared key used as an attribute or quoted `key="…"` pair is masked by
 * key in the payload, but readMarkup() counts it as a read `key=` pair with an empty value, so the value is not
 * collected and the echo in the error, failure item, activity and outcome stays in clear. The first three passed at
 * e804e38f. Adopt into tests/Feature/Integrations/DeclaredKeysMaskingTest.php.
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

const ATTRIBUTE_CODE = 'ZXCV-99812-QWER';

/** @return array{0: string, 1: ?string} everything stored, and the outcome message */
function suspendWithBody(string $body): array
{
    Currency::factory()->create(['code' => 'NPR']);
    ServicesTestKit::bind();
    app(ProvisioningDriverRegistry::class)->register('log', fn (): LogDriver => new class($body) extends LogDriver implements DeclaresSensitiveKeys
    {
        public function __construct(private string $body) {}

        public function sensitiveKeys(string $operation): array
        {
            return ['ticket'];
        }

        public function suspend(ProvisioningContext $context, string $reason): DriverResult
        {
            return DriverResult::fail('Panel refused '.ATTRIBUTE_CODE, response: ['body' => $this->body]);
        }
    }, replace: true);
    $service = Service::factory()->onServer()->create();
    $outcome = app(ExecuteModuleCommand::class)->handle($service->id, ModuleCommandName::Suspend, ['reason' => 'overdue']);

    return [(string) json_encode([ModuleCommand::all(), ModuleQueueItem::all(), ActivityLog::all()]), $outcome->message];
}

test('a declared value in a quoted attribute or key="…" pair is masked or withheld in every stored text', function (string $body) {
    [$stored, $message] = suspendWithBody($body);

    // At 1f686152: error, last_error, activity and outcome are "Panel refused ZXCV-99812-QWER".
    expect($stored)->not->toContain(ATTRIBUTE_CODE)
        ->and((string) $message)->not->toContain(ATTRIBUTE_CODE);
})->with([
    'double-quoted attribute' => ['<item ticket="'.ATTRIBUTE_CODE.'"/>'],
    'single-quoted attribute' => ["<item ticket='".ATTRIBUTE_CODE."'/>"],
    'quoted pair in a log line' => ['refused ticket="'.ATTRIBUTE_CODE.'" retry=0'],
]);

test('non-blocking: a declared value in an attribute of the declared element is masked or withheld', function () {
    [$stored] = suspendWithBody('<ticket value="'.ATTRIBUTE_CODE.'"></ticket>');

    // At 1f686152 the payload, error and activity all keep the value (also at e804e38f).
    expect($stored)->not->toContain(ATTRIBUTE_CODE);
});

test('non-blocking: a declared key inside percent-encoded JSON in a form field is masked or withheld', function () {
    [$stored] = suspendWithBody('payload='.rawurlencode(json_encode(['ticket' => ATTRIBUTE_CODE])));

    expect($stored)->not->toContain(ATTRIBUTE_CODE);
});
