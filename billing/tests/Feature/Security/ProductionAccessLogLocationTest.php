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

/*
 * Infra security review 2026-10-31, L3: GET /settings/password/set/{token} was missing from the filter. The Caddyfile
 * repeats its token-path regex three times (default logger, request URI, Location); this keeps the copies equal and
 * derives the paths from the routes, so a new route with a token in its path fails here until the filter covers it.
 * PCRE and Caddy's Go RE2 read this regex the same way, and both leave an unmatched group empty in the replacement.
 */
test('the edge log filter redacts the token of every route that carries one in its path', function () {
    $caddyfile = file_get_contents(dirname(__DIR__, 4).'/deploy/caddy/Caddyfile');
    preg_match_all('/(?:request>uri|resp_headers>Location) regexp "([^"]+)" "\$\{1\}\$\{2\}REDACTED"/', $caddyfile, $m);

    expect($m[1])->toHaveCount(3);
    $uriRegex = $m[1][0];
    // The Location copy is the same pattern without the start anchor (Location holds an absolute URL).
    expect($m[1][1])->toBe($uriRegex)->and($m[1][2])->toBe(substr($uriRegex, 1));

    // Any parameter whose name looks secret (tokenId, verify_code, apiKey, ...), so a new name is covered by default.
    // A route listed here was checked by hand: its parameter only looks secret.
    $looksSecret = fn (string $name) => preg_match('/token|hash|signature|code|key|secret/i', $name) === 1;
    $notSecret = ['admin/appearance/themes/{key}/export' => 'a theme slug, [a-z][a-z0-9-]{1,47}'];
    $checked = [];
    foreach (app('router')->getRoutes() as $route) {
        $parameters = $route->parameterNames();
        if (! in_array('GET', $route->methods(), true) || array_filter($parameters, $looksSecret) === []
            || isset($notSecret[$route->uri()])) {
            continue;
        }
        $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', 'SECRETVALUE', $route->uri()), '/');
        $logged = preg_replace('#'.str_replace('#', '\#', $uriRegex).'#', '${1}${2}REDACTED', $uri);

        // str_contains, not ->not->toContain(): Pest reads a second argument as another needle, not a message.
        expect(str_contains($logged, 'SECRETVALUE'))->toBeFalse("{$route->uri()} reaches the access log with its token");
        $checked[] = $route->uri();
    }

    expect($checked)->toContain('settings/password/set/{token}')->toContain('reset-password/{token}');
});
