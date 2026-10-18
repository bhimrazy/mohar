<?php

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Core\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sandbox card tokens; pass a test card number to pick the outcome.
 *
 * @extends Factory<PayMethod>
 */
class PayMethodFactory extends Factory
{
    protected $model = PayMethod::class;

    public function definition(): array
    {
        return $this->card('4242424242424242');
    }

    public function withCard(string $number): static
    {
        return $this->state(fn (): array => $this->card($number));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $number): array
    {
        $token = (new SandboxCardDriver)->tokenize($number, 12, (int) now()->addYears(2)->format('Y'));

        return [
            'client_id' => Client::factory(),
            'gateway' => 'sandbox_card',
            'kind' => 'card',
            'token' => $token->token,
            'brand' => $token->brand,
            'last4' => $token->last4,
            'expiry_month' => $token->expiryMonth,
            'expiry_year' => $token->expiryYear,
            'is_default' => true,
        ];
    }
}
