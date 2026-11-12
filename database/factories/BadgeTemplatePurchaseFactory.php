<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BadgeTemplatePurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeTemplatePurchase> */
final class BadgeTemplatePurchaseFactory extends Factory
{
    protected $model = BadgeTemplatePurchase::class;

    public function definition(): array
    {
        return [
            'licence_id' => \App\Models\BadgeTemplateLicence::factory(),
            'order_id' => \App\Models\StoreOrder::factory()->badgeTemplate(),
            'buyer_tenant_id' => fn (array $attributes) => \App\Models\BadgeTemplateLicence::query()->whereKey($attributes['licence_id'])->firstOrFail()->buyer_tenant_id,
            'event_id' => fn (array $attributes) => \App\Models\BadgeTemplateLicence::query()->whereKey($attributes['licence_id'])->firstOrFail()->event_id,
            'family_id' => fn (array $attributes) => \App\Models\BadgeTemplateLicence::query()->whereKey($attributes['licence_id'])->firstOrFail()->family_id,
            'version_id' => fn (array $attributes) => \App\Models\BadgeLibraryDesign::query()->whereKey($attributes['family_id'])->firstOrFail()->versions()->create(['version' => 1, 'snapshot' => []])->id,
            'terms_snapshot' => ['total_pesewas' => 5000, 'currency' => 'GHS', 'refund_days' => 7],
            'state' => 'pending',
        ];
    }
}
