<?php

namespace App\Modules\Platform\Actions\ClientGroups;

use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Validation\ValidationException;

class SaveClientGroup
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array{name: string, colour: ?string, discount_percent: string|float|int, exempt_from_suspension: bool, separate_invoices: bool}  $data
     */
    public function handle(?ClientGroup $group, array $data): ClientGroup
    {
        $group ??= new ClientGroup;
        $group->fill($data)->save();

        $this->activity->log(
            $group->wasRecentlyCreated ? "Client group {$group->name} created" : "Client group {$group->name} updated",
            $group,
            properties: ['discount_percent' => $group->discount_percent],
            event: $group->wasRecentlyCreated ? 'client_group.created' : 'client_group.updated',
        );

        return $group;
    }

    /**
     * @throws ValidationException when clients are still in the group
     */
    public function delete(ClientGroup $group): void
    {
        if ($group->clients()->exists()) {
            throw ValidationException::withMessages(['group' => __('Move the clients in this group to another group first.')]);
        }

        $group->delete();
        $this->activity->log("Client group {$group->name} deleted", event: 'client_group.deleted');
    }
}
