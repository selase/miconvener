<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityAccessAudit;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityAccessAudit> */
final class CommunityAccessAuditFactory extends Factory
{
    protected $model = CommunityAccessAudit::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'action' => 'claim_requested', 'reason' => 'Fixture audit entry.'];
    }
}
