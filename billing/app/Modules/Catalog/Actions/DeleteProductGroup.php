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
     * @throws ValidationException when the group still contains products (deleted ones are kept for history)
     */
    public function handle(ProductGroup $group): void
    {
        if ($group->products()->exists()) {
            throw ValidationException::withMessages([
                'group' => __('Move or delete the products in :group before deleting it.', ['group' => $group->name]),
            ]);
        }

        if ($group->products()->onlyTrashed()->exists()) {
            throw ValidationException::withMessages([
                'group' => __(':group still holds deleted products that old orders and invoices refer to. Hide the group instead.', ['group' => $group->name]),
            ]);
        }

        DB::transaction(function () use ($group): void {
            $group->delete();
            $this->activity->log('Product group deleted', null, null, ['group' => $group->name]);
        });
    }
}
