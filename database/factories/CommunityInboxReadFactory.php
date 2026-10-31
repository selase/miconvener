<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunityInboxItem;
use App\Models\CommunityInboxRead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommunityInboxRead> */
final class CommunityInboxReadFactory extends Factory
{
    protected $model = CommunityInboxRead::class;

    public function definition(): array
    {
        return ['item_id' => CommunityInboxItem::factory(), 'entry_key' => fn (array $a) => $a['item_id'], 'reader_key' => hash('sha256', (string) Str::uuid()), 'read_at' => now()];
    }
}
