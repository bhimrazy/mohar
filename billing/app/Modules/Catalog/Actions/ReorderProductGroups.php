<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ProductGroup;
use Illuminate\Support\Facades\DB;

class ReorderProductGroups
{
    /**
     * @param  list<int>  $orderedIds  group ids in display order; the first becomes the default store landing group
     */
    public function handle(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $position => $id) {
                ProductGroup::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }
}
