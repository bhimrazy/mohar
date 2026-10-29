<?php

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Orders\Data\CartItem;
use App\Modules\Orders\Enums\CartItemKind;
use App\Modules\Orders\Pricing\CartPricer;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Domains\FakeRegistrar;

// PR #510: "one registrar account read per call" must also hold for a driver that needs no saved account.
test('a registrar call for a driver with no saved account reads registrar_accounts once', function () {
    FakeRegistrar::install();

    DB::enableQueryLog();
    app(RegistrarGateway::class)->call('fake', 'info', RegistrarDriver::class, fn () => 'ok');
    $reads = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'registrar_accounts') && str_starts_with($q['query'], 'select'));

    expect($reads)->toHaveCount(1);
});

// PR #510: a TLD offered for transfer only is not fixed by choosing another currency.
test('a transfer-only TLD ordered for registration does not tell the visitor to change currency', function () {
    Tld::factory()->extension('.io')->priced('NPR')->create(['registrar_key' => 'log', 'allow_register' => false]);
    $errors = app(CartPricer::class)
        ->price([new CartItem(key: 'k1', kind: CartItemKind::DomainRegister, domainName: 'asha.io', years: 1)], 'NPR')->items[0]->errors;

    expect($errors[0] ?? '')->not->toContain('Choose another currency');
});
