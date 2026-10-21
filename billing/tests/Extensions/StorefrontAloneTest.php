<?php

use App\Modules\Core\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * The Storefront module with only Core (no Catalog, Orders, Domains, Support or Billing): every sibling it reads is
 * optional, so the public pages render with empty products, domains and help instead of failing.
 */

uses(RefreshDatabase::class);

// This file migrates its own in-memory database with only Core and Storefront tables; the next file must not reuse it.
$freshDatabase = function (): void {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
};

beforeAll(function () use ($freshDatabase): void {
    $freshDatabase();
    TestCase::$onlyModules = 'Storefront';
});

afterAll(function () use ($freshDatabase): void {
    $freshDatabase();
    TestCase::$onlyModules = null;
});

beforeEach(fn () => Currency::factory()->create());

test('the home page, sitemap and public 404 work without the sibling modules', function () {
    expect(app()->providerIsLoaded('App\Modules\Catalog\CatalogServiceProvider'))->toBeFalse();

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/storefront/home')
        ->where('site.products.groups', [])
        ->where('featured_plans', [])
        ->where('domains.featured', []));

    $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>'.url('/').'</loc>', false);
    $this->get('/domains/pricing')->assertOk();
    $this->get('/no-such-page')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('public/storefront/not-found'));
});
