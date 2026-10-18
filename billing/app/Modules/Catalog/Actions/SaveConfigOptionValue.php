<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use Illuminate\Support\Facades\DB;

class SaveConfigOptionValue
{
    public function __construct(private SyncPrices $prices) {}

    /**
     * @param  array{label: string, module_value?: ?string, is_hidden?: bool, sort_order?: int, prices?: list<array{currency: string, cycle: string, enabled?: bool, amount: int, setup_fee?: int}>}  $data
     */
    public function handle(ConfigOption $option, array $data, ?ConfigOptionValue $value = null): ConfigOptionValue
    {
        return DB::transaction(function () use ($option, $data, $value): ConfigOptionValue {
            $value ??= new ConfigOptionValue([
                'config_option_id' => $option->id,
                'sort_order' => (int) $option->values()->max('sort_order') + 1,
            ]);
            $prices = $data['prices'] ?? null;
            unset($data['prices']);
            $value->fill($data)->save();

            if ($prices !== null) {
                $this->prices->handle($value, $prices);
            }

            return $value;
        });
    }
}
