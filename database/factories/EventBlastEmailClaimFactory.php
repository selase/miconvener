<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventBlast;
use App\Models\EventBlastEmailClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventBlastEmailClaim> */
final class EventBlastEmailClaimFactory extends Factory
{
    protected $model = EventBlastEmailClaim::class;

    public function definition(): array
    {
        return ['blast_id' => EventBlast::factory(), 'tenant_id' => fn (array $attributes) => EventBlast::withoutGlobalScopes()->findOrFail($attributes['blast_id'])->tenant_id, 'address_hash' => hash('sha256', fake()->uuid()), 'status' => 'unknown', 'claimed_at' => now()];
    }
}
