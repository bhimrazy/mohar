<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\Admin\NepalVatRequest;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\NepalVatMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Setup → Nepal VAT: the mode and the tax rules it relies on (vat-register §3). */
class NepalVatController extends Controller
{
    public function edit(NepalVatMode $mode): Response
    {
        $this->authorize('billing.tax.manage');
        $mode->ensureRow();

        $rules = TaxRule::query()->orderBy('level')->orderBy('country')->orderBy('id')->get(['id', 'name', 'level', 'country', 'rate']);
        $current = $mode->current();

        return Inertia::render('admin/billing/vat-mode/edit', [
            'mode' => $current,
            'started' => $mode->inEffect(now()),
            'months' => $mode->months(),
            'rules' => $rules->map(fn (TaxRule $rule): array => ['id' => $rule->id, 'name' => $rule->name, 'level' => $rule->level, 'country' => $rule->country, 'rate' => (float) $rule->rate])->all(),
            'blockers' => $current['enabled'] ? [] : $mode->blockers(),
            'can_switch_off' => ! TaxNote::query()->exists() && ! $mode->reservationsPending(),
            'tax_rules_url' => route('admin.billing.tax-rules.index'),
            'settings_url' => Route::has('admin.platform.settings.edit') ? route('admin.platform.settings.edit', 'billing') : null,
        ]);
    }

    public function update(NepalVatRequest $request, NepalVatMode $mode): RedirectResponse
    {
        $input = $request->validated();
        $changed = $mode->apply($request->user(), [
            'enabled' => (bool) $input['enabled'],
            'enabled_from' => isset($input['enabled_from']) ? (string) $input['enabled_from'] : null,
            'vat_rules' => array_values(array_map('intval', $input['vat_rules'])),
            'export_rule' => isset($input['export_rule']) ? (int) $input['export_rule'] : null,
        ]);

        Inertia::flash('toast', $changed ? ['type' => 'success', 'message' => __('Nepal VAT settings saved.')] : ['type' => 'info', 'message' => __('No changes to save.')]);

        return to_route('admin.billing.vat-mode.edit');
    }
}
