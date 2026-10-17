<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class SaveProductGroup
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @param  array{name: string, slug: string, headline?: ?string, tagline?: ?string, description?: ?string, is_hidden?: bool, allowed_gateways?: list<string>|null, order_form_template?: ?string}  $data
     */
    public function handle(array $data, ?ProductGroup $group = null): ProductGroup
    {
        return DB::transaction(function () use ($data, $group): ProductGroup {
            $group ??= new ProductGroup(['sort_order' => (int) ProductGroup::query()->max('sort_order') + 1]);
            $creating = ! $group->exists;
            $group->fill($data)->save();

            $this->activity->log($creating ? 'Product group created' : 'Product group updated', $group);

            return $group;
        });
    }
}
