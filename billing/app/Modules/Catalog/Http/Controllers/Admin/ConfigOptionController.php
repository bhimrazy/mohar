<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Actions\DeleteConfigOptionItem;
use App\Modules\Catalog\Actions\SaveConfigOption;
use App\Modules\Catalog\Http\Requests\ConfigOptionRequest;
use App\Modules\Catalog\Models\ConfigOption;
use App\Modules\Catalog\Models\ConfigOptionGroup;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ConfigOptionController extends Controller
{
    public function store(ConfigOptionRequest $request, ConfigOptionGroup $optionGroup, SaveConfigOption $save): RedirectResponse
    {
        $option = $save->handle($optionGroup, $request->optionData());

        return $this->saved($optionGroup, __('Option :name added.', ['name' => $option->name]));
    }

    public function update(ConfigOptionRequest $request, ConfigOption $option, SaveConfigOption $save): RedirectResponse
    {
        $save->handle($option->group, $request->optionData(), $option);

        return $this->saved($option->group, __('Option :name saved.', ['name' => $option->name]));
    }

    public function destroy(ConfigOption $option, DeleteConfigOptionItem $delete): RedirectResponse
    {
        $this->authorize('catalog.options.manage');

        $group = $option->group;
        $delete->handle($option);

        return $this->saved($group, __('Option :name deleted.', ['name' => $option->name]));
    }

    private function saved(ConfigOptionGroup $group, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.catalog.option-groups.edit', $group);
    }
}
