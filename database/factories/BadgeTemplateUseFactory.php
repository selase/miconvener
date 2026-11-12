<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BadgeTemplateUse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeTemplateUse> */
final class BadgeTemplateUseFactory extends Factory
{
    protected $model = BadgeTemplateUse::class;

    public function definition(): array
    {
        return [
            'purchase_id' => \App\Models\BadgeTemplatePurchase::factory(),
            'licence_id' => fn (array $attributes) => \App\Models\BadgeTemplatePurchase::query()->whereKey($attributes['purchase_id'])->firstOrFail()->licence_id,
            'version_id' => fn (array $attributes) => \App\Models\BadgeTemplatePurchase::query()->whereKey($attributes['purchase_id'])->firstOrFail()->version_id,
            'actor_id' => \App\Models\User::factory(),
            'purpose' => 'badge_pdf',
            'output_key' => (string) \Illuminate\Support\Str::uuid(),
            'snapshot_hash' => hash('sha256', 'test-output'),
        ];
    }
}
