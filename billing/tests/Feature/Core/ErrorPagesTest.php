<?php

use App\Http\Errors\BrandedErrorPage;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

const HTML = ['Accept' => 'text/html,application/xhtml+xml'];

test('a signed-in customer who opens a page that does not exist gets the branded error page', function () {
    actingAsClient();

    $this->get('/client/definitely-not-a-page', HTML)
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('auth/error')->where('status', 404)->where('home', route('client.dashboard', absolute: false)));
});

test('a customer who opens a staff page gets the branded forbidden page that leads back to their area', function () {
    actingAsClient();

    $this->get(route('admin.dashboard'), HTML)
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('auth/error')->where('status', 403)->where('home', route('client.dashboard', absolute: false)));
});

test('staff paths lead home to the admin dashboard', function () {
    actingAsStaff();

    $this->get('/admin/definitely-not-a-page', HTML)->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->where('home', route('admin.dashboard', absolute: false)));
});

test('an expired page (419) is branded', function () {
    Route::post('/client/_419', fn () => abort(419))->middleware('web');
    actingAsClient();

    $this->post('/client/_419', [], HTML)->assertStatus(419)
        ->assertInertia(fn (Assert $page) => $page->component('auth/error')->where('status', 419));
});

test('a throttled page keeps Retry-After and the rate-limit headers', function () {
    Route::get('/client/_429', fn () => throw new TooManyRequestsHttpException(42))->middleware('web');
    actingAsClient();

    $this->get('/client/_429', HTML)->assertStatus(429)->assertHeader('Retry-After', '42')
        ->assertInertia(fn (Assert $page) => $page->component('auth/error')->where('status', 429));
});

test('a 500 shows no exception text or SQL, with debug on or off', function (bool $debug) {
    config(['app.debug' => $debug]);
    Route::get('/client/_500', fn () => throw new RuntimeException('select * from secrets'))->middleware('web');
    actingAsClient();

    $response = $this->get('/client/_500', HTML)->assertStatus(500);

    if ($debug) {
        $response->assertSee('select * from secrets', false);
    } else {
        $response->assertInertia(fn (Assert $page) => $page->component('auth/error')->where('status', 500));
        expect($response->getContent())->not->toContain('select * from secrets');
    }
})->with([true, false]);

test('an Inertia partial request gets the branded page too, without the exception text', function () {
    config(['app.debug' => false]);
    Route::get('/client/_500x', fn () => throw new RuntimeException('select * from secrets'))->middleware('web');
    actingAsClient();
    // The version a browser holds comes from a page it loaded; computed outside a request it can differ (CI got 409).
    $version = (string) ($this->get(route('client.dashboard'), HTML)->viewData('page')['version'] ?? '');

    $response = $this->get('/client/_500x', [...HTML, 'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'client/dashboard', 'X-Inertia-Partial-Data' => 'client'])->assertStatus(500);

    expect($response->getContent())->toContain('auth\/error')->not->toContain('select * from secrets');
});

test('requests that do not ask for HTML keep the framework response', function (array $headers) {
    actingAsClient();

    $response = $this->get('/client/definitely-not-a-page', $headers)->assertNotFound();

    expect($response->getContent())->not->toContain('auth\/error')->not->toContain('auth/error');
})->with([
    'an image' => [['Accept' => 'image/avif,image/webp,*/*']],
    'JSON' => [['Accept' => 'application/json']],
]);

test('an image or attachment route keeps the framework response even for a browser', function () {
    Route::get('/admin/_img', fn () => abort(403))->middleware('web')->name('admin.kyc.image');
    actingAsStaff();

    $response = $this->get('/admin/_img', HTML)->assertForbidden();

    expect($response->getContent())->not->toContain('auth\/error');
});

test('a failed form submit keeps the framework response and its Retry-After', function () {
    Route::post('/client/_429p', fn () => throw new TooManyRequestsHttpException(12))->middleware('web');
    actingAsClient();

    $response = $this->post('/client/_429p', [], HTML)->assertStatus(429)->assertHeader('Retry-After', '12');

    expect($response->getContent())->not->toContain('auth\/error');
});

test('a maintenance 503 is untouched', function () {
    Route::get('/client/_503', fn () => abort(503))->middleware('web');
    actingAsClient();

    $response = $this->get('/client/_503', HTML)->assertStatus(503);

    expect($response->getContent())->not->toContain('auth\/error');
});

test('a throttled webhook POST keeps a plain body and Retry-After', function () {
    Route::post('/_machine', fn () => throw new TooManyRequestsHttpException(30))
        ->middleware('web')->name('billing.webhooks');

    $response = $this->post('/_machine', [], HTML)->assertStatus(429)->assertHeader('Retry-After', '30');

    expect($response->getContent())->not->toContain('auth\/error');
});

test('every machine-facing route named in the error page exclusion list exists', function () {
    foreach (BrandedErrorPage::MACHINE_ROUTES as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }
});

test('the country list is shared once so forms can offer names', function () {
    actingAsClient();

    $this->get(route('client.account.edit'))->assertOk()->assertInertia(
        fn (Assert $page) => $page->has('countries', fn (Assert $list) => $list->etc())
    );
});
