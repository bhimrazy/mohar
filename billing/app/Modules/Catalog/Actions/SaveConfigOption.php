<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class SaveConfigOption
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * Yes/no and quantity options get their single value row (the price of "yes" / of one unit) automatically.
     *
     * @param  array<string, mixed>  $data  validated ConfigOptionRequest data
     */
    public function handle(ConfigOptionGroup $group, array $data, ?ConfigOption $option = null): ConfigOption
    {
        return DB::transaction(function () use ($group, $data, $option): ConfigOption {
            $option ??= new ConfigOption([
                'config_option_group_id' => $group->id,
                'sort_order' => (int) $group->options()->max('sort_order') + 1,
            ]);
            $option->fill($data)->save();

            if ($option->type->hasSingleValue() && $option->values()->doesntExist()) {
                $option->values()->create([
                    'label' => $option->type->value === 'yes_no' ? 'Yes' : 'Unit',
                    'module_value' => $option->type->value === 'yes_no' ? '1' : null,
                ]);
            }

            $this->activity->log('Configurable option saved', $group, null, ['option' => $option->name]);

            return $option;
        });
    }
}
