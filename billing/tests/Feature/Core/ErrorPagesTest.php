<?php

use Inertia\Testing\AssertableInertia as Assert;

test('a signed-in customer who opens a page that does not exist gets the branded error page', function () {
    actingAsClient();

    $this->get('/client/definitely-not-a-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('auth/error')->where('status', 404));
});

test('a customer who opens a staff page gets the branded forbidden page', function () {
    actingAsClient();

    $this->get(route('admin.dashboard'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('auth/error')->where('status', 403));
});

test('JSON callers keep the plain error body', function () {
    actingAsClient();

    $this->getJson('/client/definitely-not-a-page')->assertNotFound()->assertJsonMissingPath('component');
});

test('the country list is shared once so forms can offer names', function () {
    actingAsClient();

    $this->get(route('client.account.edit'))->assertOk()->assertInertia(
        fn (Assert $page) => $page->has('countries', fn (Assert $list) => $list->etc())
    );
});
