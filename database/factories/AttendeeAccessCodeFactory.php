<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendeeAccessCode;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AttendeeAccessCode>
 */
final class AttendeeAccessCodeFactory extends Factory
{
    protected $model = AttendeeAccessCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => mb_strtolower($this->faker->safeEmail()),
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(AttendeeAccessCode::TTL_MINUTES),
        ];
    }
}
