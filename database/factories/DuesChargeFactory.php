<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enum\DuesChargeStatusEnum;
use App\Models\DuesCharge;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DuesCharge> */
final class DuesChargeFactory extends Factory
{
    protected $model = DuesCharge::class;

    public function definition(): array
    {
        $member = Member::factory()->create();

        return [
            'tenant_id' => $member->tenant_id,
            'member_id' => $member->id,
            'dues_class_id' => $member->dues_class_id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_on' => now()->startOfMonth()->toDateString(),
            'amount' => 5000,
            'currency' => 'GHS',
            'status' => DuesChargeStatusEnum::Open,
        ];
    }
}
