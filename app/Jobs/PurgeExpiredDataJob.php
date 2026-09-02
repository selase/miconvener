<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RetentionPolicy;
use App\Services\Compliance\LegalHoldService;
use App\Services\Compliance\RetentionPolicyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PurgeExpiredDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly RetentionPolicy $policy,
    ) {}

    public function handle(
        RetentionPolicyService $retentionService,
        LegalHoldService $holdService,
    ): void {
        if (! $this->policy->is_active || $this->policy->isUnlimited()) {
            return;
        }

        $query = $retentionService->getExpiredItemsQuery(
            $this->policy->tenant_id,
            $this->policy->content_type,
            $this->policy->retention_days,
            $this->policy->grace_period_days,
        );

        if (! $query) {
            return;
        }

        $purgedCount = 0;

        $query->chunkById(100, function ($items) use ($holdService, &$purgedCount): void {
            foreach ($items as $item) {
                if ($holdService->isUnderHold($item, $this->policy->tenant_id)) {
                    Log::info('Skipping purge for item under legal hold', [
                        'model' => $item::class,
                        'id' => $item->getKey(),
                        'tenant_id' => $this->policy->tenant_id,
                    ]);

                    continue;
                }

                $item->delete();
                $purgedCount++;
            }
        });

        Log::info('Retention purge completed', [
            'tenant_id' => $this->policy->tenant_id,
            'content_type' => $this->policy->content_type->value,
            'purged_count' => $purgedCount,
        ]);
    }
}
