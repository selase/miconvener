<?php

declare(strict_types=1);

namespace App\Jobs\Notifications;

use App\Jobs\Middleware\TenantAwareJob;
use App\Models\EventNotificationLog;
use App\Services\Notifications\NotificationGatewayService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a group of claimed SMS deliveries in as few provider requests as
 * possible -- one per distinct text -- so a large announcement stays within
 * the provider's rate limit. When the provider asks us to slow down, the job
 * waits as long as it was told and sends whatever is still pending.
 */
final class SendSmsBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 3;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<string>  $deliveryIds
     */
    public function __construct(
        public string $tenantId,
        public array $deliveryIds,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(NotificationGatewayService $gateway): void
    {
        $deliveries = EventNotificationLog::query()
            ->whereIn('id', $this->deliveryIds)
            ->where('channel', EventNotificationLog::CHANNEL_SMS)
            ->get();

        if ($deliveries->isEmpty()) {
            return;
        }

        $retryAfter = $gateway->deliverSmsBatch($deliveries)['retry_after'];

        if ($retryAfter !== null) {
            $this->release($retryAfter);
        }
    }
}
