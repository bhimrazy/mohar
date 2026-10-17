<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\DeleteConfigOptionItem;
use App\Modules\Catalog\Actions\SaveConfigOptionValue;
use App\Modules\Catalog\Http\Requests\ConfigOptionValueRequest;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionValue;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ConfigOptionValueController extends Controller
{
    public function store(ConfigOptionValueRequest $request, ConfigOption $option, SaveConfigOptionValue $save): RedirectResponse
    {
        $value = $save->handle($option, $request->valueData());

        return $this->saved($option, __('Value :label added.', ['label' => $value->label]));
    }

    public function update(ConfigOptionValueRequest $request, ConfigOptionValue $value, SaveConfigOptionValue $save): RedirectResponse
    {
        $save->handle($value->option, $request->valueData(), $value);

        return $this->saved($value->option, __('Value :label saved.', ['label' => $value->label]));
    }

    public function destroy(ConfigOptionValue $value, DeleteConfigOptionItem $delete): RedirectResponse
    {
        $this->authorize('catalog.options.manage');

        $option = $value->option;
        $delete->handle($value);

        return $this->saved($option, __('Value :label deleted.', ['label' => $value->label]));
    }

    private function saved(ConfigOption $option, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.catalog.option-groups.edit', $option->config_option_group_id);
    }
}
