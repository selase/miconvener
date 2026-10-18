<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityLibraryItem;
use App\Models\CommunityLibraryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunityLibraryVersion> */
final class CommunityLibraryVersionFactory extends Factory
{
    protected $model = CommunityLibraryVersion::class;

    public function definition(): array
    {
        return ['item_id' => CommunityLibraryItem::factory(), 'tenant_id' => fn (array $attributes) => CommunityLibraryItem::withoutGlobalScopes()->findOrFail($attributes['item_id'])->tenant_id, 'edition' => '2026 edition', 'disk' => 'community-library-local', 'path' => 'community-library/'.fake()->uuid().'.pdf', 'mime' => 'application/pdf', 'size' => 20, 'checksum' => hash('sha256', 'test file')];
    }
}
