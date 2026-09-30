<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Jobs\Middleware\TenantAwareJob;
use App\Models\EventNotificationLog;
use App\Services\Notifications\NotificationGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SendEventNotificationDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $deliveryId,
        public string $tenantId,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new TenantAwareJob,
            (new WithoutOverlapping("event-notification:{$this->tenantId}:{$this->deliveryId}"))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(NotificationGatewayService $gateway): void
    {
        $delivery = EventNotificationLog::query()->find($this->deliveryId);

        if (! $delivery instanceof EventNotificationLog) {
            return;
        }

        $gateway->deliver($delivery);
    }
}
