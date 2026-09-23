<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformAttendeeAccessCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PlatformAttendeeAccessCode>
 */
final class PlatformAttendeeAccessCodeFactory extends Factory
{
    protected $model = PlatformAttendeeAccessCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_normalized' => mb_strtolower($this->faker->safeEmail()),
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(PlatformAttendeeAccessCode::TTL_MINUTES),
            'consumed_at' => null,
        ];
    }
}
