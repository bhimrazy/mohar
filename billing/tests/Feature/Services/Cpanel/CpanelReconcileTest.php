<?php

use App\Enums\ReconcileStatus;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\Cpanel\Whm;

test('the driver tells executed, conflicting, in-progress and absent creates apart', function (string|array $listaccts, int $minutesAgo, ReconcileStatus $status) {
    Whm::fake(['listaccts' => $listaccts]);

    $lookup = app(CpanelDriver::class)->reconcile(Whm::context(), 'create', CarbonImmutable::now()->subMinutes($minutesAgo));

    expect($lookup->status)->toBe($status);
})->with([
    'ours' => ['listaccts_example', 15, ReconcileStatus::Executed],
    'another owner' => ['listaccts_foreign', 15, ReconcileStatus::Conflict],
    'absent, just after the attempt' => ['listaccts_empty', 3, ReconcileStatus::InProgress],
    'absent, settled' => ['listaccts_empty', 11, ReconcileStatus::NotExecuted],
]);

test('the driver checks what suspend, terminate and change package leave behind, and reports a failed lookup as unknown', function () {
    $driver = app(CpanelDriver::class);
    $at = CarbonImmutable::now()->subMinutes(20);

    Whm::fake(['accountsummary' => 'accountsummary_suspended', 'listaccts' => 'listaccts_empty']);
    expect($driver->reconcile(Whm::context(), 'suspend', $at)->status)->toBe(ReconcileStatus::Executed)
        ->and($driver->reconcile(Whm::context(), 'unsuspend', $at)->status)->toBe(ReconcileStatus::NotExecuted)
        ->and($driver->reconcile(Whm::context(), 'terminate', $at)->status)->toBe(ReconcileStatus::Executed)
        ->and($driver->reconcile(Whm::context(['package' => 'root_basic']), 'change_package', $at)->status)->toBe(ReconcileStatus::Executed)
        ->and($driver->reconcile(Whm::context(), 'change_password', $at)->status)->toBe(ReconcileStatus::Unknown);

    Whm::fake(['listaccts' => Http::response('busy', 503)]);
    expect($driver->reconcile(Whm::context(), 'create', $at)->status)->toBe(ReconcileStatus::Unknown);
});
