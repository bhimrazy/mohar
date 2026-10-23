<?php

use App\Models\User;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Services\SettingsCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/* Review probes for PR #432 (Nepal VAT mode). Same fixtures as NepalVatModeTest. */

beforeEach(function () {
    config(['features.billing.nepal_vat_mode' => true]);
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    $this->vat = TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
    $this->export = TaxRule::factory()->create(['name' => 'Export', 'level' => 1, 'country' => null, 'rate' => '0.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function vatProbeClient(string $country = 'NP', string $currency = 'NPR'): Client
{
    return Client::factory()->create(['country' => $country, 'currency' => $currency]);
}

test('probe A: apply() decides on the state read under its lock, not on values this request read earlier', function () {
    $mode = app(NepalVatMode::class);
    // A real admin request reads settings several times (EnforceStaffSecurity, Inertia) before the controller runs;
    // from the second read on they come from the request memo.
    expect($mode->enabled())->toBeFalse()->and($mode->enabled())->toBeFalse();

    // Another staff member's save commits first: the mode is on and started on 18 Oct.
    foreach (['billing.nepal_vat.enabled' => true, 'billing.nepal_vat.enabled_from' => '2026-10-18', 'billing.nepal_vat.vat_rules' => [$this->vat->id]] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
    Cache::store()->forget('core.settings'); // what their setMany did to the shared store; this request's memo is untouched

    $staff = User::factory()->create(['is_staff' => true]);
    $mode->apply($staff, ['enabled' => true, 'enabled_from' => '2026-11-17', 'vat_rules' => [$this->vat->id], 'export_rule' => null]);

    app(Settings::class)->forgetCached();
    expect(app(NepalVatMode::class)->enabledFrom())->toBe('2026-10-18'); // a started mode's start day is history
});

test('probe B: moving the start day earlier after enabling re-runs the old-style adjustment check', function () {
    $invoice = issueInvoice(vatProbeClient(), [10_000]);
    billingSettings(['nepal_vat.vat_rules' => [$this->vat->id]]);
    app(Kernel::class)->call('billing:fiscal-vat-split');
    actingAsStaff(['billing.tax.manage']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), ['enabled' => true, 'enabled_from' => '2026-11-17', 'vat_rules' => [$this->vat->id], 'export_rule' => null])->assertSessionHasNoErrors();

    CarbonImmutable::setTestNow('2026-10-21 06:00:00');
    DB::table('invoice_adjustments')->insert([
        'invoice_id' => $invoice->id, 'kind' => 'debit', 'reason' => 'late_fee', 'amount' => 500, 'taxable_amount' => 500, 'tax_amount' => 0,
        'description' => 'Late fee', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoices')->where('id', $invoice->id)->update(['debited_total' => 500]);

    // Enabling directly with 18 Oct is refused (NepalVatModeTest); moving to it after enabling must be too.
    $this->put(route('admin.billing.vat-mode.update'), ['enabled' => true, 'enabled_from' => '2026-10-18', 'vat_rules' => [$this->vat->id], 'export_rule' => null])->assertSessionHasErrors();

    expect(app(NepalVatMode::class)->enabledFrom())->toBe('2026-11-17');
});

test('probe C: the split uses the rule ids an invoice already stores before falling back to name and rate', function () {
    $invoice = issueInvoice(vatProbeClient(), [10_000])->fresh();
    expect($invoice->tax1_rule_id)->toBe($this->vat->id)->and($invoice->fiscal_tax1_is_vat)->toBeNull();

    $this->vat->update(['name' => 'VAT 13%']); // a rename between numbering and the split
    billingSettings(['nepal_vat.vat_rules' => [$this->vat->id]]);
    app(Kernel::class)->call('billing:fiscal-vat-split');

    expect($invoice->fresh())->fiscal_tax1_is_vat->toBeTrue()->supply_class->toBe('taxable');
});

test('probe D: with the feature off the save route 404s before validation and authorisation', function () {
    config(['features.billing.nepal_vat_mode' => false]);
    actingAsStaff(['billing.invoices.view']);
    confirmPassword();

    $this->put(route('admin.billing.vat-mode.update'), ['enabled' => 'x'])->assertNotFound();
});

test('probe E: the locked numbering fields save unchanged while the mode is on', function () {
    billingSettings(['nepal_vat.enabled' => true, 'nepal_vat.enabled_from' => '2026-10-18', 'nepal_vat.vat_rules' => [$this->vat->id]]);
    actingAsStaff(['*']);
    confirmPassword();

    $input = [];
    foreach (app(SettingsCatalog::class)->fields('billing') as $field) {
        if (in_array($field['key'], NepalVatMode::LOCKED, true)) {
            data_set($input, $field['key'], $field['value']);
        }
    }

    $this->put(route('admin.platform.settings.update', ['section' => 'billing']), $input)->assertSessionDoesntHaveErrors(NepalVatMode::LOCKED);
});
