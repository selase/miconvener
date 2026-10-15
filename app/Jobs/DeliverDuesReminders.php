<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunitySettings;
use App\Models\Tenant;
use App\Services\Dues\DuesReminders;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Retries can claim untouched recipients only; persisted daily claims never replay. */
final class DeliverDuesReminders implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 10;

    /** @param list<string> $memberIds */
    public function __construct(public string $tenantId, public array $memberIds, public string $channel, public ?int $userId = null, public ?string $localDate = null)
    {
        $this->localDate ??= CarbonImmutable::now(CommunitySettings::for(Tenant::query()->findOrFail($tenantId))->timezone)->toDateString();
    }

    public function handle(DuesReminders $reminders): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context = app(TenantContext::class);
        $previous = $context->getTenant();
        try {
            $context->setTenant($tenant);
            $reminders->deliverUnit($tenant, $this->memberIds, $this->channel, $this->userId, $this->localDate);
        } finally {
            $previous === null ? $context->clear() : $context->setTenant($previous);
        }
    }
}
