<?php

namespace App\Modules\Services\Database\Factories;

use App\Modules\Services\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        $name = 'web'.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => ucfirst($name),
            'hostname' => "{$name}.example.net",
            'ip_address' => fake()->ipv4(),
            'driver' => 'log',
            'username' => 'root',
            'password' => 'server-'.fake()->lexify('????????'),
            'api_token' => 'tok-'.fake()->lexify('????????????'),
            'secure' => true,
            'max_accounts' => 100,
            'is_active' => true,
            'is_default' => false,
            'nameservers' => ["ns1.{$name}.example.net", "ns2.{$name}.example.net"],
            'monthly_cost' => 5000,
            'currency' => 'USD',
            'datacenter' => fake()->randomElement(['Frankfurt', 'Ashburn', 'Singapore']),
        ];
    }
}
