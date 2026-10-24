<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\ApplyExpiryLifecycle;
use App\Modules\Domains\Actions\IssueRenewalInvoices;
use App\Modules\Domains\Models\Domain;
use App\Support\CalendarDate;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeInvoicer;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    $this->invoicer = FakeInvoicer::bind();
});

test('grace swap when Billing refuses to cancel the renewal invoice: only one payable renewal invoice', function () {
    Currency::factory()->euro()->create();
    $this->com->update(['grace_days' => 30, 'grace_fee' => 1000]);
    Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'currency' => 'EUR', 'recurring_amount' => 1300]);
    app(IssueRenewalInvoices::class)->handle(CalendarDate::parse('2027-01-05'));
    $this->invoicer->refuseCancel = true;

    app(ApplyExpiryLifecycle::class)->handle(CalendarDate::parse('2027-02-01'));

    $unpaid = array_keys(array_filter($this->invoicer->invoices, fn ($i) => $i['status'] === 'unpaid'));
    // The renewal invoice stays (Billing kept it payable) and no grace fee invoice is issued beside it.
    expect($unpaid)->toHaveCount(1)->and(DB::table('domain_invoices')->where('kind', 'grace')->count())->toBe(0);
});
