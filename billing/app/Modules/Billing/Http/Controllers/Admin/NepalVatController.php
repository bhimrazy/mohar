<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\Admin\NepalVatRequest;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Settings → Nepal VAT: the mode and the tax rules it relies on (vat-register §3). */
class NepalVatController extends Controller
{
    public function edit(NepalVatMode $mode, BillingSettings $settings): Response
    {
        abort_unless(config('billing_features.nepal_vat_mode'), 404);
        $this->authorize('billing.tax.manage');

        $rules = TaxRule::query()->orderBy('level')->orderBy('country')->orderBy('id')->get(['id', 'name', 'level', 'country', 'rate']);
        $current = $mode->current();
        $nepal = strtoupper($settings->string('tax.home_country')) === 'NP';

        return Inertia::render('admin/billing/vat-mode/edit', [
            'mode' => $current,
            'started' => $mode->inEffect(now()),
            'months' => $mode->months(),
            'rules' => $rules->map(fn (TaxRule $rule): array => ['id' => $rule->id, 'name' => $rule->name, 'level' => $rule->level, 'country' => $rule->country, 'rate' => (float) $rule->rate])->all(),
            // Only a hint: the seller's country never switches the mode on (ADR 0024 §8).
            'suggested_vat_rules' => $nepal && $current['vat_rules'] === [] ? $rules->filter(fn (TaxRule $rule): bool => $rule->country === 'NP' && (float) $rule->rate > 0)->pluck('id')->values()->all() : [],
            'blockers' => $current['enabled'] ? [] : $mode->blockers(),
            'can_switch_off' => ! TaxNote::query()->exists(),
            'tax_rules_url' => route('admin.billing.tax-rules.index'),
            'settings_url' => Route::has('admin.platform.settings.edit') ? route('admin.platform.settings.edit', 'billing') : null,
        ]);
    }

    public function update(NepalVatRequest $request, NepalVatMode $mode): RedirectResponse
    {
        abort_unless(config('billing_features.nepal_vat_mode'), 404);

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
