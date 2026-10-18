<?php

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;

test('the activity log requires core.activity_log.view', function () {
    actingAsStaff(['core.clients.view']);

    $this->get(route('admin.platform.activity.index'))->assertForbidden();
});

test('the activity log lists entries newest first and filters them', function () {
    Currency::factory()->create();
    $client = Client::factory()->create();
    $other = User::factory()->staff()->create();
    ActivityLog::query()->create(['description' => 'Invoice paid', 'event' => 'invoice.paid', 'client_id' => $client->id, 'ip_address' => '198.51.100.7', 'user_id' => $other->id]);
    ActivityLog::query()->create(['description' => 'Settings saved', 'event' => 'settings.updated', 'ip_address' => '198.51.100.8']);
    $viewer = actingAsStaff(['core.activity_log.view']);

    $this->get(route('admin.platform.activity.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/platform/activity/index')
            ->has('entries.data', 2)
            ->where('entries.data.0.description', 'Settings saved')
            ->where('events', ['invoice.paid', 'settings.updated'])
            ->where('staff', fn ($staff) => collect($staff)->pluck('id')->contains($viewer->id)));

    $filters = [
        ['search' => 'paid'],
        ['event' => 'invoice.paid'],
        ['client' => $client->id],
        ['user' => $other->id],
        ['ip' => '198.51.100.7'],
    ];

    foreach ($filters as $filter) {
        $this->get(route('admin.platform.activity.index', $filter))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.description', 'Invoice paid')->where('entries.data.0.client.id', $client->id));
    }

    $this->get(route('admin.platform.activity.index', ['from' => now()->addDay()->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
});

test('activity filters are validated', function () {
    actingAsStaff(['core.activity_log.view']);

    $this->get(route('admin.platform.activity.index', ['ip' => 'nope', 'from' => '17/10/2026']))->assertSessionHasErrors(['ip', 'from']);
});
