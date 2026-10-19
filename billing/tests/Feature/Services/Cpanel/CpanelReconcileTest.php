<?php

use App\Enums\ReconcileStatus;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Cpanel\Whm;

test('a create is executed only when the account with the sent username and domain was made after the attempt', function (Closure $listaccts, int $minutesAgo, ?string $domain, ReconcileStatus $status) {
    $attempt = CarbonImmutable::now()->subMinutes($minutesAgo);
    Whm::fake(['listaccts' => fn (Request $request) => Http::response($listaccts($attempt, Whm::params($request)))]);

    $lookup = app(CpanelDriver::class)->reconcile(Whm::context(domain: $domain), 'create', $attempt);

    expect($lookup->status)->toBe($status);
})->with([
    'made by the attempt' => [fn ($at) => Whm::accounts(['unix_startdate' => $at->addMinute()->getTimestamp()]), 15, 'example-shop.com', ReconcileStatus::Executed],
    'within the clock skew' => [fn ($at) => Whm::accounts(['unix_startdate' => $at->subMinutes(4)->getTimestamp()]), 15, 'example-shop.com', ReconcileStatus::Executed],
    'made before the attempt' => [fn ($at) => Whm::accounts(['unix_startdate' => $at->subDay()->getTimestamp()]), 15, 'example-shop.com', ReconcileStatus::Conflict],
    'another domain' => [fn ($at) => Whm::accounts(['unix_startdate' => $at->addMinute()->getTimestamp(), 'domain' => 'other-site.net']), 15, 'example-shop.com', ReconcileStatus::Conflict],
    'another owner' => [fn ($at) => Whm::accounts(['unix_startdate' => $at->addMinute()->getTimestamp(), 'owner' => 'reseller9']), 15, 'example-shop.com', ReconcileStatus::Conflict],
    'no domain on the service' => [fn ($at) => Whm::accounts(['unix_startdate' => $at->addMinute()->getTimestamp()]), 15, null, ReconcileStatus::Conflict],
    'domain held by another username' => [fn ($at, $params) => ($params['searchtype'] ?? null) === 'domain' ? Whm::accounts(['user' => 'example1', 'unix_startdate' => $at->addMinute()->getTimestamp()]) : Whm::fixture('listaccts_empty'), 15, 'example-shop.com', ReconcileStatus::Conflict],
    'absent, just after the attempt' => [fn () => Whm::fixture('listaccts_empty'), 3, 'example-shop.com', ReconcileStatus::InProgress],
    'absent, settled' => [fn () => Whm::fixture('listaccts_empty'), 11, 'example-shop.com', ReconcileStatus::NotExecuted],
]);

test('reconcile looks the account up by the username the attempt sent', function () {
    Whm::fake(['listaccts' => Whm::fixture('listaccts_empty')]);

    app(CpanelDriver::class)->reconcile(Whm::context(username: 'example1'), 'create', CarbonImmutable::now()->subMinutes(15));

    expect(Whm::params(Whm::sent('listaccts')[0]))->toMatchArray(['searchtype' => 'user', 'search' => 'example1']);
});

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
