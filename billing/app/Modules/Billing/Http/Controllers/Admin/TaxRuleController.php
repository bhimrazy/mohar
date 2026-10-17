<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\Admin\TaxRuleRequest;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Support\BillingSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TaxRuleController extends Controller
{
    public function index(BillingSettings $settings): Response
    {
        $this->authorize('billing.tax.manage');

        return Inertia::render('admin/billing/tax-rules/index', [
            'rules' => TaxRule::query()->orderBy('level')->orderBy('country')->orderBy('state')->orderByDesc('priority')->orderBy('id')->get()
                ->map(fn (TaxRule $rule): array => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'level' => $rule->level,
                    'country' => $rule->country,
                    'state' => $rule->state,
                    'rate' => (float) $rule->rate,
                    'priority' => $rule->priority,
                    'effective_from' => $rule->effective_from?->toDateString(),
                ]),
            'settings' => [
                'enabled' => $settings->bool('tax.enabled'),
                'mode' => $settings->string('tax.mode', 'exclusive'),
                'compound' => $settings->bool('tax.compound'),
                'calculation' => $settings->string('tax.calculation', 'per_line'),
                'vat_enabled' => $settings->bool('vat.enabled'),
                'vat_home_country' => $settings->string('vat.home_country'),
            ],
        ]);
    }

    public function store(TaxRuleRequest $request): RedirectResponse
    {
        TaxRule::query()->create([...$request->validated(), 'priority' => $request->integer('priority')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax rule added.')]);

        return back();
    }

    public function update(TaxRuleRequest $request, TaxRule $taxRule): RedirectResponse
    {
        $taxRule->update([...$request->validated(), 'priority' => $request->integer('priority')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax rule saved. Issued invoices keep their original tax.')]);

        return back();
    }

    public function destroy(TaxRule $taxRule): RedirectResponse
    {
        $this->authorize('billing.tax.manage');
        $taxRule->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax rule deleted.')]);

        return back();
    }
}
