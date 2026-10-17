<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ProductGroup;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteProductGroup
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when the group still contains products
     */
    public function handle(ProductGroup $group): void
    {
        if ($group->products()->exists()) {
            throw ValidationException::withMessages([
                'group' => __('Move or delete the products in :group before deleting it.', ['group' => $group->name]),
            ]);
        }

        DB::transaction(function () use ($group): void {
            $group->products()->onlyTrashed()->forceDelete();
            $group->delete();
            $this->activity->log('Product group deleted', null, null, ['group' => $group->name]);
        });
    }
}
