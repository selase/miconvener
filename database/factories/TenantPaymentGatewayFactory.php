<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantPaymentGateway> */
final class TenantPaymentGatewayFactory extends Factory
{
    protected $model = TenantPaymentGateway::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => $this->faker->randomElement(['stripe', 'paystack']),
            'api_key_encrypted' => 'sk_test_'.$this->faker->sha1(),
            'public_key_encrypted' => 'pk_test_'.$this->faker->sha1(),
            'webhook_secret_encrypted' => 'whsec_'.$this->faker->sha1(),
            'is_active' => true,
            'meta' => [],
        ];
    }
}
