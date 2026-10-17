<?php

namespace App\Modules\Domains\Database\Factories;

use App\Modules\Core\Models\Client;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $sld = fake()->unique()->domainWord().fake()->numberBetween(10, 99999);
        $expiry = CarbonImmutable::today()->addMonths(6);

        return [
            'client_id' => Client::factory(),
            'tld_id' => fn (): int => (Tld::query()->where('extension', '.com')->first() ?? Tld::factory()->extension('.com')->create())->id,
            'name' => fn (array $attributes): string => $sld.self::extensionOf($attributes['tld_id']),
            'name_unicode' => fn (array $attributes): string => $attributes['name'],
            'sld' => $sld,
            'tld' => fn (array $attributes): string => self::extensionOf($attributes['tld_id']),
            'type' => 'register',
            'registrar_key' => 'log',
            'status' => DomainStatus::Active,
            'registration_date' => $expiry->subYear(),
            'expiry_date' => $expiry,
            'next_due_date' => $expiry,
            'next_invoice_date' => $expiry->subDays(30),
            'billing_anchor_day' => $expiry->day,
            'period_years' => 1,
            'first_payment_amount' => 1200,
            'recurring_amount' => 1400,
            'currency' => 'USD',
            'auto_renew' => true,
            'nameservers' => ['ns1.example-host.com', 'ns2.example-host.com'],
            'registrar_lock' => true,
        ];
    }

    public function status(DomainStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function pending(string $type = 'register'): static
    {
        return $this->state([
            'status' => DomainStatus::Pending,
            'type' => $type,
            'registration_date' => null,
            'expiry_date' => null,
            'next_due_date' => CarbonImmutable::today(),
            'next_invoice_date' => CarbonImmutable::today(),
            'registrar_lock' => null,
            'epp_code' => $type === 'transfer' ? 'EPP-SECRET-1' : null,
        ]);
    }

    /**
     * Expiry (and the derived due/invoice dates with a 30-day lead).
     */
    public function expiring(string $date, int $leadDays = 30): static
    {
        $expiry = CarbonImmutable::parse($date);

        return $this->state([
            'expiry_date' => $expiry,
            'next_due_date' => $expiry,
            'next_invoice_date' => $expiry->subDays($leadDays),
            'billing_anchor_day' => $expiry->day,
            'registration_date' => $expiry->subYear(),
        ]);
    }

    public function named(string $sld, Tld $tld): static
    {
        return $this->state(['tld_id' => $tld->id, 'sld' => $sld, 'tld' => $tld->extension, 'name' => $sld.$tld->extension, 'name_unicode' => $sld.$tld->extension]);
    }

    private static function extensionOf(mixed $tldId): string
    {
        return (string) Tld::query()->whereKey($tldId)->value('extension');
    }

    public function manual(): static
    {
        return $this->state(['registrar_key' => null]);
    }
}
