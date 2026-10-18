<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Models\ServerGroup;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a server group and its ordered server list.
 */
class SaveServerGroup
{
    /**
     * @param  array{name: string, fill_type: string}  $attributes
     * @param  array<int, int|string>  $serverIds  in fill order
     */
    public function handle(?ServerGroup $group, array $attributes, array $serverIds): ServerGroup
    {
        return DB::transaction(function () use ($group, $attributes, $serverIds): ServerGroup {
            $group ??= new ServerGroup;
            $group->fill($attributes)->save();
            $group->servers()->sync(collect(array_values($serverIds))->mapWithKeys(fn ($id, int $index): array => [(int) $id => ['sort_order' => $index]])->all());

            return $group;
        });
    }
}
