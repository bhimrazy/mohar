<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use App\Modules\Catalog\Models\ConfigOptionValue;
use App\Modules\Catalog\Models\Price;
use App\Modules\Catalog\Services\ServiceUsage;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a configurable option group, option or value. Values selected by a service can't be deleted
 * (hide them instead) so existing services keep their pricing and display.
 */
class DeleteConfigOptionItem
{
    public function __construct(private ServiceUsage $usage, private ActivityLogger $activity) {}

    /**
     * @throws ValidationException
     */
    public function handle(ConfigOptionGroup|ConfigOption|ConfigOptionValue $item): void
    {
        $values = match (true) {
            $item instanceof ConfigOptionValue => new Collection([$item->loadMissing('option')]),
            $item instanceof ConfigOption => $item->values()->with('option')->get(),
            default => ConfigOptionValue::query()->with('option')->whereIn('config_option_id', $item->options()->select('id'))->get(),
        };

        if ($item instanceof ConfigOptionValue && $item->option->type->hasSingleValue()) {
            throw ValidationException::withMessages(['value' => __('Yes/no and quantity options need their price row. Delete the option instead.')]);
        }

        foreach ($values as $value) {
            if ($this->usage->configValueInUse($value->config_option_id, $value->id)) {
                throw ValidationException::withMessages([
                    'value' => __(':value is selected by existing services, so it can\'t be deleted. Hide it instead.', ['value' => $value->option->name.' › '.$value->label]),
                ]);
            }
        }

        DB::transaction(function () use ($item, $values): void {
            Price::query()->where('priceable_type', (new ConfigOptionValue)->getMorphClass())->whereIn('priceable_id', $values->modelKeys())->delete();
            $item->delete();
            $this->activity->log('Configurable option item deleted', null, null, ['type' => class_basename($item), 'id' => $item->getKey()]);
        });
    }
}
