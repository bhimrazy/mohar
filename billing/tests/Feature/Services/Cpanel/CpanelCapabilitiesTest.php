<?php

use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\UsageRow;
use App\Enums\DriverOutcome;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Modules\Services\Drivers\Provisioning\Cpanel\WhmFailure;
use Illuminate\Support\Facades\Http;
use Tests\Support\Cpanel\Whm;

beforeEach(function () {
    $this->driver = app(CpanelDriver::class);
});

test('change package sends the new package, and skips the call when the account already has it', function () {
    Whm::fake();
    $result = $this->driver->changePackage(Whm::context(['package' => 'root_pro']), new PreviousPackage);

    expect($result->success)->toBeTrue()
        ->and(Whm::sent('changepackage')[0]->data())->toEqual(['user' => 'example', 'pkg' => 'root_pro'])
        ->and($result->data['driver_data'])->toBe(['plan' => 'root_pro']);

    Whm::fake(['accountsummary' => Whm::fixture('accountsummary', ['data' => ['acct' => [0 => ['plan' => 'root_pro']]]])]);
    $same = $this->driver->changePackage(Whm::context(['package' => 'root_pro']), new PreviousPackage);

    expect($same->success)->toBeTrue()
        ->and($same->message)->toBe('The account already uses package root_pro.')
        ->and(Whm::sent('changepackage'))->toBeEmpty();
});

test('change password updates the database password too, and a weak password reads plainly', function () {
    Whm::fake();
    expect($this->driver->changePassword(Whm::context(), 'Str0ng-New-Pass!')->success)->toBeTrue()
        ->and(Whm::sent('passwd')[0]->data())->toEqual(['user' => 'example', 'password' => 'Str0ng-New-Pass!', 'db_pass_update' => 1]);

    Whm::fake(['passwd' => 'passwd_weak']);
    $weak = $this->driver->changePassword(Whm::context(), 'abc12345');

    expect($weak->outcome())->toBe(DriverOutcome::Rejected)
        ->and($weak->message)->toStartWith('The server rejected this password: too weak.');
});

test('usage maps disk in MiB and bandwidth in bytes, with unlimited as null, from two calls per server', function () {
    Whm::fake();

    $rows = $this->driver->usage(Whm::server());

    expect($rows['example'])->toEqual(new UsageRow(14, null, 10, null))
        ->and(Whm::params(Whm::sent('listaccts')[0]))->toMatchArray(['want' => 'user,diskused,disklimit', 'api.chunk.enable' => '1'])
        ->and(Whm::params(Whm::sent('showbw')[0]))->toMatchArray(['month' => (string) now()->month, 'year' => (string) now()->year])
        ->and(Http::recorded())->toHaveCount(2);
});

test('accounts are listed across pages, with suspension, owner and temporary domains as the server reports them', function () {
    Whm::fake(['listaccts' => Http::sequence()->push(Whm::fixture('listaccts_page1'))->push(Whm::fixture('listaccts_page2'))]);

    $accounts = $this->driver->listAccounts(Whm::server());

    expect(array_map(fn ($account) => $account->username, $accounts))->toBe(['alpha', 'bravo', 'charlie'])
        ->and($accounts[0]->email)->toBe('anna@alpha-books.com')
        ->and($accounts[0]->diskLimitMb)->toBe(1024)
        ->and($accounts[1])->toMatchObject(['owner' => 'reseller1', 'suspended' => true, 'suspendReason' => 'Abuse report', 'plan' => 'reseller1_starter'])
        ->and($accounts[2])->toMatchObject(['email' => null, 'suspendReason' => null, 'diskLimitMb' => null])
        ->and(Whm::params(Whm::sent('listaccts')[1]))->toMatchArray(['api.chunk.start' => '3']);
});

test('the package select lists what the connection can assign, with quotas in the label', function () {
    Whm::fake();

    expect($this->driver->fieldOptions(Whm::server(), 'package'))->toBe([
        'root_basic' => 'root_basic (10 GB disk, 100 GB bandwidth)',
        'root_pro' => 'root_pro (50 GB disk, unlimited bandwidth)',
        'reseller1_starter' => 'reseller1_starter (500 MB disk, 4.9 GB bandwidth)',
        'default' => 'default (unlimited disk, unlimited bandwidth)',
    ])->and(Whm::params(Whm::sent('listpkgs')[0]))->toMatchArray(['want' => 'creatable'])
        ->and($this->driver->fieldOptions(Whm::server(), 'cpmod'))->toBe([]);
});

test('client SSO opens cPanel for the account, optionally at a shortcut, and staff SSO opens WHM', function () {
    Whm::fake();

    $link = $this->driver->serviceSsoLink(Whm::context(), 'email');
    $this->driver->serviceSsoLink(Whm::context(), 'no-such-target');
    $this->driver->serverSsoLink(Whm::server());

    [$client, $plain, $staff] = Whm::sent('create_user_session');
    expect($link->url)->toStartWith('https://')
        ->and($link->expiresAt?->getTimestamp())->toBe(1893456000)
        ->and($client->data())->toBe(['user' => 'example', 'service' => 'cpaneld', 'preferred_domain' => Whm::HOST, 'app' => 'Email_Accounts'])
        ->and($plain->data())->not->toHaveKey('app')
        ->and($staff->data())->toBe(['user' => 'root', 'service' => 'whostmgrd'])
        ->and(array_keys($this->driver->ssoTargets()))->toBe(['email', 'files', 'databases', 'backups']);
});

test('SSO is refused when the server turns it off, without contacting WHM', function () {
    Whm::fake();

    expect(fn () => $this->driver->serverSsoLink(Whm::server(['sso_enabled' => false])))->toThrow(WhmFailure::class, 'Single sign-on is turned off');
    Http::assertNothingSent();
});
