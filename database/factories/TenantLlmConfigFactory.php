<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantLlmConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantLlmConfig> */
final class TenantLlmConfigFactory extends Factory
{
    protected $model = TenantLlmConfig::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'is_active' => true,
        ];
    }
}
