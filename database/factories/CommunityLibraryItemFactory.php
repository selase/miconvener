<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityLibraryItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityLibraryItem> */
final class CommunityLibraryItemFactory extends Factory
{
    protected $model = CommunityLibraryItem::class;

    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'kind' => 'document', 'title' => fake()->sentence(3), 'plain_text' => fake()->paragraph(), 'audience' => ['kind' => 'all_members'], 'state' => 'published'];
    }
}
