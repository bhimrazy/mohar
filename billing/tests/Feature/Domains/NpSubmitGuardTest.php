<?php

use App\Contracts\Domains\DomainRegistry;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Domains\FakeRegistrar;

/**
 * The submit path refuses domains that are never sent to a registrar (#280; registrars.md §9), whoever created them.
 */
beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function npDomain(Tld $tld, string $name, array $attributes = []): Domain
{
    [$sld] = explode('.', $name, 2);

    return Domain::factory()->pending($attributes['type'] ?? 'register')->create([
        'tld_id' => $tld->id, 'name' => $name, 'name_unicode' => $name, 'sld' => $sld, 'tld' => ltrim($tld->extension, '.'),
        'registrar_key' => 'fake', 'currency' => 'NPR', ...$attributes,
    ]);
}

test('a staff-created pending .com.np domain with a registrar key is never submitted and is left for staff', function (string $type) {
    Event::fake([RegistrarActionFailed::class]);
    $tld = Tld::factory()->extension('.com.np')->create(['registrar_key' => 'fake']);
    $domain = npDomain($tld, 'asha.com.np', ['type' => $type]);

    $result = app(DomainRegistry::class)->process($domain->id);

    $domain->refresh();
    expect($tld->registered_by_client)->toBeTrue()
        ->and($result->success)->toBeFalse()
        ->and($result->message)->toContain('.com.np domains are registered by the client at the registry')
        ->and($this->registrar->commands())->toBe([])
        ->and($domain->status)->toBe(DomainStatus::Pending)
        ->and($domain->last_error)->toBe($result->message)
        ->and($domain->registrar_meta)->toBeNull()
        ->and(Domain::query()->needingAttention()->pluck('id')->all())->toBe([$domain->id]);
    Event::assertDispatched(RegistrarActionFailed::class, fn (RegistrarActionFailed $event) => $event->domainId === $domain->id && $event->action === $type);
    $this->assertDatabaseHas('activity_log', ['description' => "Staff task: {$type} of asha.com.np failed"]);
})->with(['register', 'transfer']);

test('the staff register command on such a domain sends nothing and shows the reason', function () {
    actingAsStaff(['domains.*']);
    $tld = Tld::factory()->extension('.org.np')->create(['registrar_key' => null]);
    $domain = npDomain($tld, 'sathi.org.np');

    $this->post(route('admin.domains.command', [$domain, 'register']))->assertRedirect();

    expect($this->registrar->commands())->toBe([])
        ->and($domain->fresh()->last_error)->toContain('sathi.org.np was not sent to a registrar');
});

test('a .np name the registry cannot issue is refused even when staff cleared the TLD flag', function () {
    $tld = Tld::factory()->extension('.np')->create(['registrar_key' => 'fake', 'registered_by_client' => false]);
    $domain = npDomain($tld, 'asha.np');

    $result = app(SubmitToRegistrar::class)->handle($domain);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('only under .com.np')
        ->and($this->registrar->commands())->toBe([])
        ->and($domain->fresh())->status->toBe(DomainStatus::Pending)->last_error->toBe($result->message);
});

test('a stored .np name with a trailing dot is refused the same way', function () {
    $tld = Tld::factory()->extension('.np')->create(['registrar_key' => 'fake', 'registered_by_client' => false]);
    $domain = npDomain($tld, 'ASHA.NP.');

    expect(app(SubmitToRegistrar::class)->handle($domain)->success)->toBeFalse()
        ->and($this->registrar->commands())->toBe([]);
});

test('a .com.np TLD that staff sell through a registrar is submitted, as the cart and DomainRegistry allow', function () {
    $tld = Tld::factory()->extension('.com.np')->create(['registrar_key' => 'fake', 'registered_by_client' => false]);
    $domain = npDomain($tld, 'asha.com.np');

    expect(app(SubmitToRegistrar::class)->handle($domain)->success)->toBeTrue()
        ->and($this->registrar->commands())->toContain('register');
});
