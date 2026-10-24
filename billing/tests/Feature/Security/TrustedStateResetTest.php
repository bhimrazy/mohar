<?php

use Illuminate\Http\Request;

/* #110: Symfony keeps trusted hosts and proxies in statics; TestCase::setUp() must reset what an earlier test left. */
afterAll(function () {
    Request::setTrustedHosts([]);
    Request::setTrustedProxies([], -1);
});

test('a test that sets trusted hosts and proxies leaves them set for the process', function () {
    Request::setTrustedHosts(['^only\.example\.test$']);
    Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    expect(Request::getTrustedHosts())->not->toBe([]);
});

test('the next test starts from Symfony defaults and answers a foreign Host', function () {
    expect(Request::getTrustedHosts())->toBe([])->and(Request::getTrustedProxies())->toBe([]);

    $this->call('GET', '/up', server: ['HTTP_HOST' => 'forged.example.test'])->assertStatus(200);
})->depends('a test that sets trusted hosts and proxies leaves them set for the process');
