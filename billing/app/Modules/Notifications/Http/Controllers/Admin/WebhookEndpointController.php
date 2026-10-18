<?php

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Notifications\Enums\WebhookDeliveryStatus;
use App\Modules\Notifications\Http\Requests\WebhookEndpointRequest;
use App\Modules\Notifications\Jobs\DeliverWebhook;
use App\Modules\Notifications\Models\WebhookDelivery;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Webhooks\WebhookEventTypes;
use App\Modules\Notifications\Webhooks\WebhookPublisher;
use App\Modules\Notifications\Webhooks\WebhookUrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webhook endpoints (spec §9): CRUD, reveal-once secret, rotation with a 24 h grace period, test ping,
 * delivery log with request/response and resend. Every route needs notifications.webhooks.manage
 * (sensitive) and mutations a recent password confirmation.
 */
class WebhookEndpointController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('notifications.webhooks.manage');

        return Inertia::render('admin/notifications/webhooks/index', [
            'endpoints' => WebhookEndpoint::query()->orderBy('id')->get()->map(fn (WebhookEndpoint $endpoint): array => [
                ...$this->summary($endpoint),
                'last_delivery' => WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->orderByDesc('id')->first(['status', 'updated_at'])?->only(['status', 'updated_at']),
            ])->values(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('notifications.webhooks.manage');

        return Inertia::render('admin/notifications/webhooks/form', ['endpoint' => null, 'eventTypes' => WebhookEventTypes::types()]);
    }

    public function store(WebhookEndpointRequest $request): RedirectResponse
    {
        $secret = $this->newSecret();
        $endpoint = WebhookEndpoint::query()->create([
            'url' => $request->string('url')->toString(),
            'description' => $request->input('description'),
            'events' => $this->events($request),
            'is_active' => $request->boolean('is_active', true),
            'secret' => $secret,
            'created_by' => $request->user()?->id,
        ]);
        $this->activity->log('Webhook endpoint created', $endpoint, properties: ['url' => WebhookUrlGuard::forLog($endpoint->url)]);
        Inertia::flash('webhookSecret', $secret);

        return to_route('admin.notifications.webhooks.show', $endpoint);
    }

    public function show(Request $request, WebhookEndpoint $endpoint): Response
    {
        $this->authorize('notifications.webhooks.manage');

        $deliveries = $endpoint->deliveries()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('admin/notifications/webhooks/show', [
            'endpoint' => $this->summary($endpoint),
            'deliveries' => $deliveries->through(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'event_id' => $delivery->event_id,
                'event_type' => $delivery->event_type,
                'status' => $delivery->status->value,
                'attempt' => $delivery->attempt,
                'response_status' => $delivery->response_status,
                'response_body' => $delivery->response_body,
                'error' => $delivery->error,
                'duration_ms' => $delivery->duration_ms,
                'payload' => json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
                'created_at' => $delivery->created_at?->toIso8601String(),
                'updated_at' => $delivery->updated_at?->toIso8601String(),
            ]),
            'filters' => $request->only(['status']),
        ]);
    }

    public function edit(WebhookEndpoint $endpoint): Response
    {
        $this->authorize('notifications.webhooks.manage');

        return Inertia::render('admin/notifications/webhooks/form', ['endpoint' => $this->summary($endpoint), 'eventTypes' => WebhookEventTypes::types()]);
    }

    public function update(WebhookEndpointRequest $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $active = $request->boolean('is_active', true);
        $endpoint->update([
            'url' => $request->string('url')->toString(),
            'description' => $request->input('description'),
            'events' => $this->events($request),
            'is_active' => $active,
            ...($active && ! $endpoint->is_active ? ['consecutive_failures' => 0, 'disabled_reason' => null] : []),
        ]);
        $this->activity->log('Webhook endpoint updated', $endpoint, properties: ['url' => WebhookUrlGuard::forLog($endpoint->url), 'active' => $active]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook endpoint saved.')]);

        return to_route('admin.notifications.webhooks.show', $endpoint);
    }

    public function destroy(WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorize('notifications.webhooks.manage');
        $endpoint->delete();
        $this->activity->log('Webhook endpoint deleted', properties: ['url' => WebhookUrlGuard::forLog($endpoint->url)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook endpoint deleted.')]);

        return to_route('admin.notifications.webhooks.index');
    }

    /**
     * New secret, shown once; the previous one keeps verifying for the grace period (spec §8).
     */
    public function rotate(WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->authorize('notifications.webhooks.manage');
        $secret = $this->newSecret();
        $endpoint->update([
            'previous_secret' => $endpoint->secret,
            'previous_secret_expires_at' => now()->addHours((int) config('notifications.webhooks_secret_grace_hours', 24)),
            'secret' => $secret,
        ]);
        $this->activity->log('Webhook signing secret rotated', $endpoint, properties: ['url' => WebhookUrlGuard::forLog($endpoint->url)]);
        Inertia::flash('webhookSecret', $secret);

        return back();
    }

    public function ping(WebhookEndpoint $endpoint, WebhookPublisher $publisher): RedirectResponse
    {
        $this->authorize('notifications.webhooks.manage');
        $publisher->publish(WebhookEventTypes::PING, ['message' => 'Test event from the admin area.', 'endpoint_id' => $endpoint->id], ['endpoint_id' => $endpoint->id], 'ping|'.Str::uuid(), [$endpoint->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test event sent. Its result appears in the delivery log.')]);

        return back();
    }

    /**
     * A new attempt with the same event id (spec §8: consumers dedupe by id).
     */
    public function resend(WebhookEndpoint $endpoint, WebhookDelivery $delivery): RedirectResponse
    {
        $this->authorize('notifications.webhooks.manage');
        abort_unless($delivery->webhook_endpoint_id === $endpoint->id, 404);

        $delivery->update(['status' => WebhookDeliveryStatus::Pending, 'next_attempt_at' => WebhookPublisher::lease(), 'attempt' => 0]);
        DeliverWebhook::dispatch($delivery->id);
        $this->activity->log('Webhook delivery resent', $endpoint, properties: ['event_id' => $delivery->event_id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery queued again.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => WebhookUrlGuard::forLog($endpoint->url),
            'description' => $endpoint->description,
            'events' => $endpoint->events,
            'is_active' => $endpoint->is_active,
            'consecutive_failures' => $endpoint->consecutive_failures,
            'disabled_reason' => $endpoint->disabled_reason,
            'last_success_at' => $endpoint->last_success_at?->toIso8601String(),
            'rotating_until' => $endpoint->previous_secret_expires_at?->isFuture() ? $endpoint->previous_secret_expires_at->toIso8601String() : null,
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function events(WebhookEndpointRequest $request): array
    {
        $events = array_values(array_unique(array_map('strval', $request->array('events'))));

        return in_array('*', $events, true) ? ['*'] : $events;
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
