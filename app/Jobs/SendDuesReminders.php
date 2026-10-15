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

final class SendDuesReminders implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $tenantId, public ?int $userId = null, public ?string $localDate = null) {}

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
            if ($this->userId !== null) {
                $reminders->remindArrears($tenant, $this->userId);
            } else {
                $today = CarbonImmutable::now(CommunitySettings::for($tenant)->timezone)->startOfDay();
                if ($this->localDate !== null && $this->localDate !== $today->toDateString()) {
                    return;
                }
                $reminders->sendDue($tenant, $today);
            }
        } finally {
            $previous === null ? $context->clear() : $context->setTenant($previous);
        }
    }
}
