<?php

use App\Contracts\Services\ClientServiceDirectory;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Actions\OpenTicket;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Data\TicketDraft;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\TicketAccess;

/**
 * Shared helpers for the Support feature tests (required by each test file).
 */
if (! function_exists('supportStaff')) {
    /**
     * A signed-in staff member assigned to the given departments.
     *
     * @param  list<Department>  $departments
     * @param  list<string>  $permissions
     */
    function supportStaff(array $departments = [], array $permissions = ['support.tickets.view', 'support.tickets.reply', 'support.tickets.manage']): User
    {
        $user = User::factory()->staff($permissions)->create();

        foreach ($departments as $department) {
            $department->staff()->attach($user->id);
        }

        app(TicketAccess::class)->forget();
        test()->actingAs($user);

        return $user;
    }

    /**
     * Sign in as the owner of another client account (actingAsClient() creates a currency each call).
     */
    function actingAsOwnerOf(Client $client): User
    {
        $user = User::factory()->create(['email' => $client->email]);
        $client->users()->attach($user, ['is_owner' => true]);
        test()->actingAs($user);

        return $user;
    }

    /**
     * Bind a ClientServiceDirectory fake: client id => list of service ids.
     *
     * @param  array<int, list<int>>  $services
     */
    function fakeServiceDirectory(array $services): void
    {
        app()->instance(ClientServiceDirectory::class, new class($services) implements ClientServiceDirectory
        {
            /** @param  array<int, list<int>>  $services */
            public function __construct(private array $services) {}

            public function forClient(int $clientId): array
            {
                return array_map(fn (int $id): array => ['id' => $id, 'label' => "Web hosting #{$id}", 'status' => 'active'], $this->services[$clientId] ?? []);
            }

            public function belongsToClient(int $serviceId, int $clientId): bool
            {
                return in_array($serviceId, $this->services[$clientId] ?? [], true);
            }
        });
    }

    /**
     * Open a ticket the way a signed-in client does.
     */
    function clientTicket(Client $client, ?Department $department = null, string $body = 'My site is down.'): Ticket
    {
        $department ??= Department::factory()->create();
        $user = $client->users()->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $client->email]);
            $client->users()->attach($user, ['is_owner' => true]);
        }

        return app(OpenTicket::class)->handle(new TicketDraft(
            departmentId: $department->id,
            subject: 'Help needed',
            body: $body,
            author: TicketAuthor::client($user),
            requesterName: $user->name,
            requesterEmail: $user->email,
            clientId: $client->id,
            userId: $user->id,
        ));
    }

    /**
     * Open a guest ticket.
     */
    function guestTicket(?Department $department = null, string $email = 'guest@example.com'): Ticket
    {
        $department ??= Department::factory()->create();

        return app(OpenTicket::class)->handle(new TicketDraft(
            departmentId: $department->id,
            subject: 'Question before buying',
            body: 'Do you offer Windows hosting?',
            author: TicketAuthor::guest('Gina Guest', $email),
            requesterName: 'Gina Guest',
            requesterEmail: $email,
        ));
    }
}
