<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Data\Notifications\SensitiveValue;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\NetworkIncident;
use App\Support\Dashboard\Dashboard;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SettingsRegistry;

require_once __DIR__.'/helpers.php';

test('admin and client navigation include the Support group', function () {
    $admin = User::factory()->staff()->create();
    [$customer] = actingAsClient();

    $adminGroup = collect(app(Navigation::class)->forUser('admin', $admin))->firstWhere('title', 'Support');
    $clientGroup = collect(app(Navigation::class)->forUser('client', $customer))->firstWhere('title', 'Support');

    expect(collect($adminGroup['items'])->pluck('title')->all())->toBe(['Tickets', 'Departments', 'Canned replies', 'Knowledge base', 'Announcements', 'Network status', 'Email piping'])
        ->and(collect($clientGroup['items'])->pluck('title')->all())->toBe(['Tickets', 'Knowledge base', 'Announcements', 'Network status'])
        ->and(collect(app(Navigation::class)->clientLinks(7, $admin))->pluck('title')->all())->toContain('Tickets');
});

test('dashboard cards count tickets for staff and clients', function () {
    $department = Department::factory()->create();
    [, $client] = actingAsClient();
    clientTicket($client, $department);
    $staff = supportStaff([$department]);

    $admin = collect(app(Dashboard::class)->resolve('admin', $staff))->keyBy('key');
    $customer = collect(app(Dashboard::class)->resolve('client', null, $client))->keyBy('key');

    expect($admin['support.open']['value'])->toBe(1)
        ->and($admin['support.awaiting']['value'])->toBe(1)
        ->and($admin['support.mine']['value'])->toBe(0)
        ->and($customer['support.open']['value'])->toBe(1);
});

test('every checked permission and setting is registered', function () {
    $registry = app(PermissionRegistry::class);

    foreach (['support.tickets.view', 'support.tickets.reply', 'support.tickets.manage', 'support.tickets.access_all', 'support.tickets.edit_messages', 'support.tickets.merge_cross_client', 'support.tickets.delete', 'support.tickets.open', 'support.departments.manage', 'support.canned.manage', 'support.kb.manage', 'support.announcements.manage', 'support.network.manage', 'support.inbound.manage'] as $permission) {
        expect($registry->exists($permission))->toBeTrue($permission);
    }

    $keys = collect(app(SettingsRegistry::class)->sections()['support']['settings'])->pluck('key');
    expect($keys)->toContain('support.auto_close_hours', 'support.attachment_max_mb', 'support.inbound_secret', 'support.ticket_number_format');
});

test('merge fields resolve tickets and replies', function () {
    $ticket = guestTicket();
    $registry = app(MergeFieldRegistry::class);

    $fields = $registry->resolve('ticket', $ticket->id);
    $reply = $registry->resolve('ticket_reply', $ticket->messages()->firstOrFail()->id);

    expect($fields)->number->toBe($ticket->number)->client_id->toBeNull()->is_guest->toBeTrue()
        ->and($fields['url'])->toBeInstanceOf(SensitiveValue::class)   // the guest link carries the access key
        ->and($fields['url']->value)->toContain('key='.$ticket->access_key)
        ->and($fields['url']->allowedIn('ticket.reply'))->toBeTrue()
        ->and($fields['url']->allowedIn('custom.copy'))->toBeFalse()
        ->and($fields['email_subject'])->toBe("[#{$ticket->number}] {$ticket->subject}")
        ->and($reply)->ticket_id->toBe($ticket->id)->message_id->toStartWith('<');
    expect(Client::query()->count())->toBe(0);
});

test('dashboard widgets, todos, nav badge and quick-create are registered for support staff', function () {
    $department = Department::factory()->create();
    clientTicket(Client::factory()->create(), $department);
    clientTicket(Client::factory()->create(), Department::factory()->create());
    NetworkIncident::factory()->create();
    $staff = supportStaff([$department], ['support.tickets.view', 'support.tickets.open', 'support.network.manage']);

    $dashboard = app(Dashboard::class);
    $list = collect($dashboard->resolveWidgets('admin', $staff))->firstWhere('key', 'support.awaiting_list');
    $todos = collect($dashboard->resolveTodos('admin', $staff))->keyBy('key');
    $tickets = collect(collect(app(Navigation::class)->forUser('admin', $staff))->firstWhere('title', 'Support')['items'])->firstWhere('title', 'Tickets');

    expect($list['type'])->toBe('list')->and($list['items'])->toHaveCount(1)
        ->and($todos['support.awaiting']['count'])->toBe(1)
        ->and($todos['support.unassigned']['count'])->toBe(1)
        ->and($todos['support.incidents']['count'])->toBe(1)
        ->and($tickets['badge'])->toBe(1)
        ->and(collect(app(Navigation::class)->quickActions($staff))->pluck('title')->all())->toContain('New ticket');
});
