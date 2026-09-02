<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserLoginHistory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserLoginHistory> */
final class UserLoginHistoryFactory extends Factory
{
    protected $model = UserLoginHistory::class;

    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'ip_address' => $this->faker->ipv4(),
            'login_at' => now(),
            'logout_at' => null,
            'session_id' => Str::random(40),
            'client_device' => $this->faker->randomElement(['Desktop', 'Mobile', 'Tablet']),
            'platform' => $this->faker->randomElement(['Windows', 'macOS', 'Linux', 'iOS', 'Android']),
            'browser' => $this->faker->randomElement(['Chrome', 'Firefox', 'Safari', 'Edge']),
            'location' => $this->faker->city().', '.$this->faker->country(),
        ];
    }
}
