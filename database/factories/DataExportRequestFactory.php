<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\DataExportStatus;
use App\Models\DataExportRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExportRequest>
 */
final class DataExportRequestFactory extends Factory
{
    protected $model = DataExportRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'requested_by' => User::factory(),
            'type' => 'full_tenant',
            'status' => DataExportStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => DataExportStatus::Completed,
            'file_path' => 'exports/'.fake()->uuid().'.zip',
            'file_size_bytes' => fake()->numberBetween(1024, 10485760),
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => DataExportStatus::Expired,
            'file_path' => 'exports/'.fake()->uuid().'.zip',
            'completed_at' => now()->subDays(10),
            'expires_at' => now()->subDays(3),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => DataExportStatus::Failed,
            'error_message' => fake()->sentence(),
        ]);
    }
}
