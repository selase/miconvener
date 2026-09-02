<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WebhookCall;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookCall> */
final class WebhookCallFactory extends Factory
{
    protected $model = WebhookCall::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event' => 'invoice.issued',
            'payload' => ['data' => ['id' => $this->faker->uuid()]],
            'status' => 'pending',
            'attempts' => 0,
        ];
    }
}
