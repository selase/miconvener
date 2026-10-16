<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityWrongNumberReport;
use App\Models\Tenant;
use App\Services\Community\PhoneVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityWrongNumberReport> */
final class CommunityWrongNumberReportFactory extends Factory
{
    protected $model = CommunityWrongNumberReport::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'phone' => '+233241234567', 'phone_hash' => PhoneVerification::digest('+233241234567'), 'note' => 'Please check number ownership.', 'status' => 'pending'];
    }
}
