<?php

declare(strict_types=1);

namespace App\Checks;

use App\Models\EventBlastRecipient;
use App\Models\EventNotificationLog;
use App\Models\Tenant;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Flags an organisation sending far more in the last hour than any real event
 * needs: a runaway loop, a misconfigured rule, or misuse. The ceilings are
 * deliberately high (health.message_alerts) so a large announcement does not
 * page anyone; anything above them is worth a look.
 */
final class MessageVolumeCheck extends Check
{
    public const int WINDOW_MINUTES = 60;

    public function run(): Result
    {
        $since = now()->subMinutes(self::WINDOW_MINUTES);
        $smsCeiling = (int) config('health.message_alerts.sms_per_hour', 1000);
        $emailCeiling = (int) config('health.message_alerts.email_per_hour', 5000);

        $sms = $this->countByTenant(EventNotificationLog::CHANNEL_SMS, $since);
        $email = $this->countByTenant(EventNotificationLog::CHANNEL_EMAIL, $since);

        foreach (EventBlastRecipient::withoutGlobalScopes()->where('created_at', '>=', $since)
            ->selectRaw('tenant_id, count(*) as total')->groupBy('tenant_id')->pluck('total', 'tenant_id') as $tenantId => $total) {
            $email[(string) $tenantId] = ($email[(string) $tenantId] ?? 0) + (int) $total;
        }

        $over = [];

        foreach ($sms as $tenantId => $total) {
            if ($total > $smsCeiling) {
                $over[] = sprintf('%s sent %d SMS (alert above %d)', $this->tenantName($tenantId), $total, $smsCeiling);
            }
        }

        foreach ($email as $tenantId => $total) {
            if ($total > $emailCeiling) {
                $over[] = sprintf('%s sent %d emails (alert above %d)', $this->tenantName($tenantId), $total, $emailCeiling);
            }
        }

        if ($over === []) {
            return Result::make()->ok()->shortSummary('Normal');
        }

        return Result::make()
            ->failed('Unusually high sending in the last hour: '.implode('; ', $over).'.')
            ->shortSummary(count($over).' organisation(s)');
    }

    /**
     * @return array<string, int>
     */
    private function countByTenant(string $channel, DateTimeInterface $since): array
    {
        /** @var Collection<string, int> $counts */
        $counts = EventNotificationLog::withoutGlobalScopes()
            ->where('channel', $channel)
            ->where('status', EventNotificationLog::STATUS_SENT)
            ->where('created_at', '>=', $since)
            ->selectRaw('tenant_id, count(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        return $counts->mapWithKeys(fn (mixed $total, mixed $tenantId): array => [(string) $tenantId => (int) $total])->all();
    }

    private function tenantName(string $tenantId): string
    {
        return (string) (Tenant::query()->whereKey($tenantId)->value('name') ?? $tenantId);
    }
}
