<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'name' => 'Premium Plan',
            'slug' => 'premium-plan-'.Str::random(5),
            'price' => 29.99,
            'interval' => 'month',
            'billing_model' => Package::BILLING_MODEL_FLAT_RATE,
            'description' => 'A premium plan.',
            'is_active' => true,
        ];
    }
}
