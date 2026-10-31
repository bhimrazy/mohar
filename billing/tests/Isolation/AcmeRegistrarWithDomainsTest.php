<?php

use App\Modules\Domains\Models\RegistrarAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelServiceProvider;
use Tests\TestCase;

/*
 * The Domains module (with Core only) and a package's registrar driver (ADR 0009 "Verification"): the driver is listed
 * in the registrar admin and runs through the host with its configured account.
 */

uses(RefreshDatabase::class);

// This file migrates its own in-memory database with only Core and Domains tables; the next file must not reuse it.
$freshDatabase = function (): void {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
};

beforeAll(function () use ($freshDatabase): void {
    $freshDatabase();
    // The password.recent step-up the registrar routes use is in the kernel since #268, so Platform is not needed.
    TestCase::$onlyModules = 'Domains';
});

afterAll(function () use ($freshDatabase): void {
    $freshDatabase();
    TestCase::$onlyModules = null;
});

beforeEach(fn () => $this->app->register(AcmePanelServiceProvider::class));

test('the package registrar is listed in the registrar admin next to the built-in one', function () {
    actingAsStaff(['domains.registrars.manage']);

    $this->get(route('admin.domains.registrars.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/domains/registrars/index')
        ->where('registrars.0.key', 'log')
        ->where('registrars.1.key', 'resellerclub')
        ->where('registrars.2.key', 'namecheap')
        ->where('registrars.3.key', 'acme_registrar')
        ->where('registrars.3.name', 'Acme Registrar')
        ->where('registrars.3.capabilities', ['availability', 'register', 'renew', 'info', 'nameservers', 'test_connection'])
        ->where('registrars.3.schema.1.type', 'secret')
        ->where('registrars.3.secrets_set', ['api_key' => false, 'sandbox_api_key' => false]));
});

test('the connection test runs the package driver against the configured account, in test mode on the sandbox key', function () {
    Http::preventStrayRequests();
    Http::fake(['sandbox.acme-registrar.test/v1/account' => Http::response(['balance' => '120.00'])]);
    RegistrarAccount::query()->create(['key' => 'acme_registrar', 'is_active' => true, 'test_mode' => true, 'settings' => ['reseller_id' => 'R-9', 'api_key' => 'live-key-1', 'sandbox_api_key' => 'sandbox-key-2']]);
    actingAsStaff(['domains.registrars.manage']);
    confirmPassword();

    $this->post(route('admin.domains.registrars.test', 'acme_registrar'))
        ->assertRedirect(route('admin.domains.registrars.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'Acme answered for reseller R-9; balance 120.00.']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Acme-Key', 'sandbox-key-2') && $request->hasHeader('X-Acme-Reseller', 'R-9'));
});
