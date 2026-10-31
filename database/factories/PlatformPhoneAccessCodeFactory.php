<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformPhoneAccessCode;
use App\Services\Community\PhoneVerification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<PlatformPhoneAccessCode> */
final class PlatformPhoneAccessCodeFactory extends Factory
{
    protected $model = PlatformPhoneAccessCode::class;

    public function definition(): array
    {
        return ['phone' => '+233241234567', 'phone_hash' => PhoneVerification::digest('+233241234567'),
            'ip_hash' => PhoneVerification::digest('192.0.2.1'), 'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5)];
    }
}
