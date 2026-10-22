<?php

use App\Events\Billing\InvoicePaid;
use App\Events\Services\ServiceActivated;
use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Jobs\DeliverWebhook;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Webhooks\HostResolver;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use App\Modules\Notifications\Webhooks\WebhookSender;
use App\Modules\Notifications\Webhooks\WebhookUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/**
 * Pretends every host resolves to the given addresses (tests never touch DNS).
 *
 * @param  list<string>  $addresses
 */
function resolveHostsTo(array $addresses): void
{
    app()->instance(HostResolver::class, new class($addresses) extends HostResolver
    {
        /** @param  list<string>  $addresses */
        public function __construct(private array $addresses) {}

        public function resolve(string $host): array
        {
            return $this->addresses;
        }
    });
}

beforeEach(function () {
    resolveHostsTo(['93.184.216.34']);
    fakeMergeFields([
        'invoice' => ['number' => 'INV-7', 'status' => 'Paid', 'total' => '$10.00', 'client_id' => 1],
        'service' => ['service_id' => 4, 'product_name' => 'Starter', 'username' => 'ann', 'password' => 'hunter2', 'server' => ['root_password' => 'x', 'hostname' => 'web01']],
    ]);
    $this->endpoint = WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/billing', 'events' => ['invoice.paid', 'service.activated'], 'secret' => 'whsec_test']);
});

it('posts a signed envelope that verifies with HMAC-SHA256 over "{timestamp}.{body}" (criterion 13)', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-Webhook-Timestamp')[0];
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_test');
        $payload = json_decode($request->body(), true);

        return $request->header('X-Webhook-Signature')[0] === $expected
            && $request->header('X-Webhook-Event')[0] === 'invoice.paid'
            && $payload['type'] === 'invoice.paid'
            && $payload['data']['object']['number'] === 'INV-7'
            && $payload['data']['ids'] === ['invoice_id' => 7, 'client_id' => 1]
            && $request->header('X-Webhook-Id')[0] === $payload['id'];
    });
    expect(WebhookDelivery::query()->sole())->status->toBe(WebhookDeliveryStatus::Succeeded)->attempt->toBe(1);
});

it('signs with both secrets while a rotation is in its grace period', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $this->endpoint->update(['previous_secret' => 'whsec_old', 'previous_secret_expires_at' => now()->addHours(24)]);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    Http::assertSent(fn (Request $request): bool => count(explode(',', $request->header('X-Webhook-Signature')[0])) === 2);
});

it('delivers a replayed event only once per endpoint', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $event = new InvoicePaid(7, 1, []);

    app(WebhookPublisher::class)->publishEvent($event);
    app(WebhookPublisher::class)->publishEvent($event);

    Http::assertSentCount(1);
});

it('delivers two separate events with identical payloads as two webhook events', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));
    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    Http::assertSentCount(2);
    expect(WebhookDelivery::query()->distinct()->count('event_id'))->toBe(2);
});

it('records a 500 and schedules the next attempt a minute later (criterion 14)', function () {
    Http::fake(['hooks.example.com/*' => Http::response('receiver exploded', 500)]);
    $this->freezeTime();

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    expect(WebhookDelivery::query()->sole())
        ->status->toBe(WebhookDeliveryStatus::Failed)
        ->attempt->toBe(1)
        ->response_status->toBe(500)
        ->response_body->toBe('receiver exploded')
        ->next_attempt_at->toEqual(now()->addMinute()->startOfSecond());
});

it('abandons a delivery after the eighth failed attempt (criterion 15)', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 503)]);
    $delivery = WebhookDelivery::query()->create(['event_id' => fake()->uuid(), 'webhook_endpoint_id' => $this->endpoint->id, 'event_type' => 'invoice.paid', 'payload' => ['id' => 'x'], 'attempt' => 7, 'status' => WebhookDeliveryStatus::Failed]);

    (new DeliverWebhook($delivery->id))->handle(app(WebhookSender::class));

    expect($delivery->fresh())->status->toBe(WebhookDeliveryStatus::Abandoned)->attempt->toBe(8)->next_attempt_at->toBeNull();
});

it('disables the endpoint after 20 consecutive failures and emails system staff (criterion 16)', function () {
    Mail::fake();
    seedEmailTemplates();
    fullAdministrator('admin@example.test');
    Http::fake(['hooks.example.com/*' => Http::response('', 500)]);
    $this->endpoint->update(['consecutive_failures' => 19]);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    expect($this->endpoint->fresh())->is_active->toBeFalse()->consecutive_failures->toBe(20)->disabled_reason->not->toBeNull()
        ->and(EmailMessage::query()->where('template_key', 'staff.webhook_endpoint_disabled')->pluck('recipient')->all())->toBe(['admin@example.test']);
});

it('refuses targets that resolve to private or metadata addresses (criterion 17)', function (string $address) {
    Http::fake();
    resolveHostsTo([$address]);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->sole())->status->toBe(WebhookDeliveryStatus::Failed)->error->toContain('Refused');
})->with(['metadata' => '169.254.169.254', 'private' => '10.0.0.5', 'loopback' => '127.0.0.1', 'mapped' => '::ffff:10.0.0.5', 'cgnat' => '100.100.100.200', 'ula' => 'fd00:ec2::254']);

it('security review L2: a delivery connects to the vetted address only, never through a proxy, without redirects', function () {
    $options = null;
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response('ok');
    });

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    expect($options['proxy'] ?? null)->toBe('')
        ->and($options['curl'][CURLOPT_CONNECT_TO] ?? null)->toBe(['::93.184.216.34:443'])
        ->and($options['allow_redirects'] ?? null)->toBeFalse()
        ->and(WebhookDelivery::query()->sole()->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

it('security review L2: a name with one public and one private address is refused, and the kernel rules apply too', function (array $addresses) {
    Http::fake();
    resolveHostsTo($addresses);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->sole()->error)->toContain('Refused');
})->with([
    'public and private' => [['93.184.216.34', '10.0.0.5']],
    'documentation range' => [['198.51.100.20']],
    'NAT64 of loopback' => [['64:ff9b::7f00:1']],
]);

it('rejects private, plain-http and credentialed URLs when an endpoint is saved', function (string $url) {
    expect(app(WebhookUrlGuard::class)->problemWith($url))->not->toBeNull();
})->with(['http://hooks.example.com/x', 'https://10.0.0.5/x', 'https://[::1]/x', 'https://localhost/x', 'https://user:pass@hooks.example.com/x']);

it('never puts secrets in payloads (criterion 18)', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);

    app(WebhookPublisher::class)->publishEvent(new ServiceActivated(4, 1));

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'hunter2')
        && ! str_contains($request->body(), 'password')
        && json_decode($request->body(), true)['data']['object']['username'] === 'ann');
});

it('treats a redirect as a failure and does not follow it (criterion 19)', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest'])]);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    Http::assertSentCount(1);
    expect(WebhookDelivery::query()->sole())->status->toBe(WebhookDeliveryStatus::Failed)->response_status->toBe(302);
});

it('dispatches each due retry exactly once', function () {
    Queue::fake();
    $due = WebhookDelivery::query()->create(['event_id' => fake()->uuid(), 'webhook_endpoint_id' => $this->endpoint->id, 'event_type' => 'invoice.paid', 'payload' => [], 'attempt' => 1, 'status' => WebhookDeliveryStatus::Failed, 'next_attempt_at' => now()->subSecond()]);
    WebhookDelivery::query()->create(['event_id' => fake()->uuid(), 'webhook_endpoint_id' => $this->endpoint->id, 'event_type' => 'invoice.paid', 'payload' => [], 'attempt' => 1, 'status' => WebhookDeliveryStatus::Failed, 'next_attempt_at' => now()->addMinutes(5)]);

    $this->artisan('notifications:retry-webhooks')->assertSuccessful();
    $this->artisan('notifications:retry-webhooks')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, 1);
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->deliveryId === $due->id);
});

it('stores the receiver answer and transport errors with secrets masked (#190)', function () {
    Http::fake(['hooks.example.com/*' => Http::response('{"error":"bad api_key","api_key":"K1"}', 500)]);
    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));
    expect(WebhookDelivery::query()->sole()->response_body)->not->toContain('K1')->toContain('bad api_key');

    WebhookDelivery::query()->delete();
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://hooks.example.com/billing?token=T1&id=5'));
    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(8, 1, []));
    expect(WebhookDelivery::query()->sole()->error)->toContain('token=[redacted]&id=5');
});

it('cuts a large receiver answer to its stored size before masking it (#252 B1)', function () {
    $body = '';

    for ($index = 0; strlen($body) < 1024 * 1024; $index++) {
        $body .= '<password'.$index.'>x';
    }

    Http::fake(['hooks.example.com/*' => Http::response($body, 500)]);
    $started = hrtime(true);

    app(WebhookPublisher::class)->publishEvent(new InvoicePaid(7, 1, []));

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and(strlen((string) WebhookDelivery::query()->sole()->response_body))->toBeLessThanOrEqual(2048)
        ->and(WebhookDelivery::query()->sole()->response_body)->toBe('<password0>[redacted]');
});
