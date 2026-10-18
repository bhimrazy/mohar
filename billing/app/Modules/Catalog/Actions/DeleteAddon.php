<?php

namespace App\Modules\Catalog\Actions;

use App\Contracts\Services\CatalogUsage;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteAddon
{
    public function __construct(private CatalogUsage $usage, private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when services use the addon (retire it instead)
     */
    public function handle(Addon $addon): void
    {
        if ($this->usage->addonInUse($addon->id)) {
            throw ValidationException::withMessages([
                'addon' => __(':addon is used by existing services, so it can\'t be deleted. Retire it instead.', ['addon' => $addon->name]),
            ]);
        }

        DB::transaction(function () use ($addon): void {
            $addon->prices()->delete();
            $addon->delete();
            $this->activity->log('Addon deleted', null, null, ['addon' => $addon->name]);
        });
    }
}
