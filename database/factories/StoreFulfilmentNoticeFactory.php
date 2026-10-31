<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreFulfilmentEvent;
use App\Models\StoreFulfilmentNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreFulfilmentNotice> */
final class StoreFulfilmentNoticeFactory extends Factory
{
    protected $model = StoreFulfilmentNotice::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['event_id' => StoreFulfilmentEvent::factory(),
            'order_id' => fn (array $a) => StoreFulfilmentEvent::findOrFail($a['event_id'])->order_id,
            'shop_id' => fn (array $a) => StoreFulfilmentEvent::findOrFail($a['event_id'])->shop_id,
            'tenant_id' => fn (array $a) => StoreFulfilmentEvent::findOrFail($a['event_id'])->tenant_id,
            'destination' => 'buyer@example.test', 'snapshot' => ['seller' => 'Association Store', 'method' => 'collection', 'instructions' => 'Collect at the office', 'support_contact' => 'support@example.test'], 'state' => 'queued'];
    }
}
