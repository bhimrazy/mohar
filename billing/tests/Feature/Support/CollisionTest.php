<?php

use App\Modules\Core\Models\Client;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Models\Department;

require_once __DIR__.'/helpers.php';

test('a reply from a stale form gets 409 with the change summary; force posts it', function () {
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    $staffA = supportStaff([$department]);
    $loadedVersion = $ticket->fresh()->version;

    $staffB = supportStaff([$department]);
    app(PostReply::class)->handle($ticket, TicketAuthor::staff($staffB), 'B was faster.');
    $this->actingAs($staffA);

    $this->postJson(route('admin.support.tickets.replies.store', $ticket), ['body' => 'A reply', 'version' => $loadedVersion])
        ->assertStatus(409)
        ->assertJsonPath('version', $ticket->fresh()->version)
        ->assertJsonFragment(['changes' => ["{$staffB->name} replied.", "{$staffB->name} changed the status from open to answered."]]);
    expect($ticket->messages()->count())->toBe(2);

    $this->postJson(route('admin.support.tickets.replies.store', $ticket), ['body' => 'A reply', 'version' => $loadedVersion, 'force' => true])
        ->assertRedirect();
    expect($ticket->messages()->count())->toBe(3);
});

test('inertia forms receive the conflict as a version error', function () {
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    supportStaff([$department]);

    $this->from(route('admin.support.tickets.show', $ticket))
        ->post(route('admin.support.tickets.replies.store', $ticket), ['body' => 'Late', 'version' => 0])
        ->assertSessionHasErrors('version');
});

test('option updates check the version too', function () {
    $department = Department::factory()->create();
    $ticket = clientTicket(Client::factory()->create(), $department);
    supportStaff([$department]);
    $version = $ticket->fresh()->version;

    $this->patch(route('admin.support.tickets.update', $ticket), ['priority' => 'high', 'version' => $version])->assertRedirect();
    $this->patchJson(route('admin.support.tickets.update', $ticket), ['priority' => 'low', 'version' => $version])->assertStatus(409);

    expect($ticket->fresh()->priority->value)->toBe('high');
});
