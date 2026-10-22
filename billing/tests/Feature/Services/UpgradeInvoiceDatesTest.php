<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Tests\Fakes\Services\ServicesTestKit;

/*
 * An upgrade invoice is dated and due on the company day (ADR 0013 Amendment 1): at 00:05 in Kathmandu on 1 January it
 * is dated 1 January and is not overdue, where the UTC date (31 December) would make it overdue on creation.
 */

beforeEach(function () {
    $this->kit = ServicesTestKit::bind();
    $this->kit->priceBook->addProduct(1, 'Starter');
    $this->kit->priceBook->addProduct(2, 'Business', ['monthly' => 2000]);
    $this->kit->priceBook->allowUpgrade(1, 2);
    inCompanyTimezone('Asia/Kathmandu');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 18:20:00', 'UTC'));
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('dates a client upgrade invoice on the company day', function () {
    [, $client] = actingAsClient();
    $service = Service::factory()->dueOn(CarbonImmutable::parse('2027-01-10'))->create(['client_id' => $client->id, 'product_id' => 1]);

    $this->post(route('client.services.change', $service->id), ['product_id' => 2, 'cycle' => 'monthly'])->assertRedirect();

    $draft = collect($this->kit->invoicer->invoices)->last()['draft'];
    expect($draft->issueDate->toDateString())->toBe('2027-01-01')->and($draft->dueDate->toDateString())->toBe('2027-01-01');
});

it('dates a staff upgrade invoice on the company day', function () {
    Currency::factory()->create();
    actingAsStaff(['*']);
    $service = Service::factory()->dueOn(CarbonImmutable::parse('2027-01-10'))->create(['client_id' => Client::factory()->create()->id, 'product_id' => 1]);

    $this->post(route('admin.services.services.change', $service), ['product_id' => 2, 'cycle' => 'monthly'])->assertRedirect();

    $draft = collect($this->kit->invoicer->invoices)->last()['draft'];
    expect($draft->issueDate->toDateString())->toBe('2027-01-01')->and($draft->dueDate->toDateString())->toBe('2027-01-01');
});
