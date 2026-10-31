<?php

// Security re-check of #286 at e4418fb2: the per-IP kyc-upload limiter cannot be reset with X-Forwarded-For.

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;

beforeEach(function () {
    app(Settings::class)->set('kyc.enabled', true);
});

function ipProbeAccount(): User
{
    $client = Client::factory()->create();
    $user = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($user, ['is_owner' => true]);

    return $user;
}

test('probe: a client that rotates X-Forwarded-For without a trusted proxy is still limited by its address', function (array $proxies, string $remote, Closure $xff, string $limitedIp) {
    config(['trustedproxy.proxies' => $proxies]);
    $n = 0;
    foreach (range(1, 2) as $account) {
        $this->actingAs(ipProbeAccount());
        foreach (range(1, 10) as $attempt) {
            $n++;
            $this->withServerVariables(['REMOTE_ADDR' => $remote])->withHeader('X-Forwarded-For', $xff($n))
                ->post(route('client.kyc.documents.store'), [])->assertSessionHasErrors('front');
        }
    }
    $this->actingAs(ipProbeAccount());
    $this->withServerVariables(['REMOTE_ADDR' => $remote])->withHeader('X-Forwarded-For', $xff(99))
        ->post(route('client.kyc.documents.store'), [])->assertTooManyRequests();

    // Another real address still gets through (the limit is keyed per address, not global).
    $this->actingAs(ipProbeAccount());
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.77'])->withHeader('X-Forwarded-For', '192.0.2.77')
        ->post(route('client.kyc.documents.store'), [])->assertSessionHasErrors('front');
})->with([
    'no trusted proxy, spoofed header' => [[], '203.0.113.9', fn (int $n): string => "198.51.100.$n", '203.0.113.9'],
    'trusted proxy appends the real address' => [['172.30.10.0/24'], '172.30.10.2', fn (int $n): string => "198.51.100.$n, 203.0.113.9", '203.0.113.9'],
]);
