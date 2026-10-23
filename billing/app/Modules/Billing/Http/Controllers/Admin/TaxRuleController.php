<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\Admin\TaxRuleRequest;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Core\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
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
                    'scope' => $rule->scope,
                    'rate' => (float) $rule->rate,
                    'priority' => $rule->priority,
                    'effective_from' => $rule->effective_from?->toDateString(),
                ]),
            // Billing settings are edited on the platform settings page (Billing section) when it is installed.
            'settings_url' => Route::has('admin.platform.settings.edit') ? route('admin.platform.settings.edit', 'billing') : null,
            'settings' => [
                'enabled' => $settings->bool('tax.enabled'),
                'mode' => $settings->string('tax.mode', 'exclusive'),
                'compound' => $settings->bool('tax.compound'),
                'calculation' => $settings->string('tax.calculation', 'per_line'),
                'vat_enabled' => $settings->bool('vat.enabled'),
                'vat_home_country' => $settings->string('vat.home_country'),
                'home_country' => $settings->string('tax.home_country'),
                'home_state' => $settings->string('tax.home_state'),
            ],
        ]);
    }

    public function __construct(private ActivityLogger $activity) {}

    public function store(TaxRuleRequest $request): RedirectResponse
    {
        $rule = TaxRule::query()->create([...$request->validated(), 'priority' => $request->integer('priority')]);
        $this->activity->log('Tax rule created', $rule, properties: ['rule' => $rule->only(['name', 'level', 'country', 'state', 'scope', 'rate', 'effective_from'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax rule added.')]);

        return back();
    }

    public function update(TaxRuleRequest $request, TaxRule $taxRule): RedirectResponse
    {
        $mode = app(NepalVatMode::class);

        if (in_array($taxRule->id, [...$mode->vatRules(), $mode->exportRule()], true) && $this->changesWhatItCovers($request->validated(), $taxRule)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This rule is used by Nepal VAT mode, so its rate, level, country and scope cannot change. Add a new rule and choose it there.')]);

            return back();
        }

        $before = $taxRule->only(['name', 'level', 'country', 'state', 'scope', 'rate', 'priority', 'effective_from']);
        $taxRule->update([...$request->validated(), 'priority' => $request->integer('priority')]);
        $this->activity->log('Tax rule updated', $taxRule, properties: ['from' => $before, 'to' => $taxRule->only(array_keys($before))]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax rule saved. Issued invoices keep their original tax.')]);

        return back();
    }

    public function destroy(TaxRule $taxRule): RedirectResponse
    {
        $this->authorize('billing.tax.manage');

        $mode = app(NepalVatMode::class);

        if (in_array($taxRule->id, [...$mode->vatRules(), $mode->exportRule()], true)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This rule is used by Nepal VAT mode. Choose another rule there first.')]);

            return back();
        }

        $this->activity->log('Tax rule deleted', $taxRule, properties: ['rule' => $taxRule->only(['name', 'level', 'country', 'state', 'scope', 'rate'])]);
        $taxRule->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax rule deleted.')]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function changesWhatItCovers(array $input, TaxRule $rule): bool
    {
        return (float) $input['rate'] !== (float) $rule->rate || (int) $input['level'] !== $rule->level
            || ($input['country'] ?? null) !== $rule->country || ($input['scope'] ?? null) !== $rule->scope;
    }
}
