<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MessageOutcomeResolution;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MessageOutcomeResolution> */
final class MessageOutcomeResolutionFactory extends Factory
{
    protected $model = MessageOutcomeResolution::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'resolved_by' => User::factory(), 'operator_reference' => fn (array $attributes) => 'user:'.$attributes['resolved_by'], 'source_kind' => 'sms_reservation', 'source_id' => Str::uuid()->toString(), 'outcome' => 'confirmed_accepted', 'evidence' => 'Provider account confirms acceptance.', 'reason' => 'Manual reconciliation with provider evidence.', 'old_state' => ['status' => 'reserved'], 'new_state' => ['status' => 'accepted']];
    }
}
