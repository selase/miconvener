<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookEndpoint> */
final class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'url' => $this->faker->url(),
            'events' => ['invoice.issued', 'subscription.created'],
            'secret' => Str::random(32),
            'is_active' => true,
        ];
    }
}
