<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class SaveConfigOptionGroup
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array{name: string, description?: ?string, product_ids?: list<int>}  $data
     */
    public function handle(array $data, ?ConfigOptionGroup $group = null): ConfigOptionGroup
    {
        return DB::transaction(function () use ($data, $group): ConfigOptionGroup {
            $group ??= new ConfigOptionGroup;
            $creating = ! $group->exists;
            $group->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();

            if (array_key_exists('product_ids', $data)) {
                $group->products()->sync($data['product_ids']);
            }

            $this->activity->log($creating ? 'Configurable option group created' : 'Configurable option group updated', $group);

            return $group;
        });
    }
}
