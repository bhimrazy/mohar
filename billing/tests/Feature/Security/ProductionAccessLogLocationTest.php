<?php

/*
 * Review board probe for PR #80, round 4 (b0ecc4ed). deploy/caddy/Caddyfile redacts request>uri and drops the
 * Referer, but Caddy's access log also records resp_headers, and a failed form post on a token page redirects back
 * to that page: the Location header then carries the token in clear (reproduced against the pinned Caddy 2.11.7).
 * The first test shows the app emits such a Location; the second fails until the log filter also covers it. The
 * third: a 502 (app restarting during a deploy) is logged by http.log.error.log0 on Caddy's default logger, which
 * has no filter, with request.uri in clear (also reproduced against the pinned Caddy).
 */

use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Platform\Actions\Clients\ManageClientLogins;
use Illuminate\Support\Facades\Mail;

test('a failed account-invitation post redirects back with the token in Location', function () {
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
    $client = Client::factory()->create();
    app(ManageClientLogins::class)->invite($client, 'helper@example.test', 'Hari Helper', ['billing']);

    $token = null;
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$token): bool {
        preg_match('#/account-invitation/([A-Za-z0-9]+)#', $mail->plainText, $match) && $token = $match[1];

        return true;
    });

    $page = route('platform.client-invitations.accept', $token);
    $this->get($page)->assertOk();
    $response = $this->from($page)->post($page, ['password' => 'short', 'password_confirmation' => 'other']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($token);
});

test('the edge access log filter also redacts or drops the Location response header', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 4).'/deploy/caddy/Caddyfile');

    expect($caddyfile)->toMatch('/resp_headers>Location\s+(delete|regexp)/');
});

test('the default logger, which carries http.log.error, filters request.uri too', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 4).'/deploy/caddy/Caddyfile');

    expect($caddyfile)->toMatch('/log default \{[^}]*format filter \{[^}]*request>uri regexp/s');
});
