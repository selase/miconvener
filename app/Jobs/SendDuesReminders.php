<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunitySettings;
use App\Models\Tenant;
use App\Services\Dues\DuesReminders;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Dispatch only: transport work and later pages are independent queued jobs. */
final class SendDuesReminders implements ShouldQueue
{
    use Queueable;

    public const int PAGE_SIZE = 100;

    public int $tries = 3;

    public int $timeout = 30;

    public int $backoff = 10;

    public function __construct(public string $tenantId, public ?int $userId = null, public ?string $localDate = null, public ?string $afterMemberId = null)
    {
        $this->localDate ??= CarbonImmutable::now(CommunitySettings::for(Tenant::query()->findOrFail($tenantId))->timezone)->toDateString();
    }

    public function handle(DuesReminders $reminders): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $localDate = $this->localDate;
        $ids = $reminders->queuedRecipientIds($tenant, $this->userId, $localDate, $this->afterMemberId, self::PAGE_SIZE);
        foreach (array_chunk($ids, DuesReminders::SMS_BATCH_SIZE) as $batch) {
            DeliverDuesReminders::dispatch($this->tenantId, $batch, 'sms', $this->userId, $localDate)->afterCommit();
        }
        foreach ($ids as $id) {
            DeliverDuesReminders::dispatch($this->tenantId, [$id], 'email', $this->userId, $localDate)->afterCommit();
        }
        if (count($ids) === self::PAGE_SIZE) {
            self::dispatch($this->tenantId, $this->userId, $localDate, $ids[array_key_last($ids)])->afterCommit();
        }
    }
}
