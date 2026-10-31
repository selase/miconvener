<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityLibraryItem;
use App\Models\CommunityLibraryWrite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityLibraryWrite> */
final class CommunityLibraryWriteFactory extends Factory
{
    protected $model = CommunityLibraryWrite::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['item_id' => CommunityLibraryItem::factory(), 'tenant_id' => fn (array $a) => CommunityLibraryItem::withoutGlobalScopes()->whereKey($a['item_id'])->firstOrFail()->tenant_id, 'actor_id' => User::factory(), 'write_key' => fake()->uuid(), 'fingerprint' => hash('sha256', fake()->sentence())];
    }
}
