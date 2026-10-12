<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Models\EventNotificationLog;
use App\Support\PhoneNumber;

/**
 * Learns what became of each SMS after the provider accepted it.
 *
 * "Sent" only ever meant "accepted". The provider (mNotify, through
 * Omnichannel) has no delivery webhook, so the outcome is asked for: a few
 * minutes after sending, when most texts have landed, then less and less
 * often for two days, since a phone that is off can take hours.
 *
 * The outcome is kept in the log's metadata (`delivery`: delivered or
 * undelivered, `delivery_detail`: the provider's word, e.g. "rejected"). The
 * log's status stays "sent" and no credit is returned: the provider charges
 * for the attempt whether or not it lands. Rows are saved without touching
 * updated_at, which SmsDeliveryFailuresCheck reads as "when it was sent".
 */
final class SmsDeliverySync
{
    /**
     * Minutes after sending from which a message's delivery is asked for.
     * The command runs on the app's fifteen-minute wake, so each point is
     * honoured at the first wake after it.
     *
     * @var list<int>
     */
    public const array CHECK_AFTER_MINUTES = [5, 30, 120, 480, 1440, 2880];

    /**
     * Provider references asked about in one run. The provider allows 60 API
     * requests a minute; this stays under it and leaves room for sending.
     */
    public const int REFERENCES_PER_RUN = 50;

    public function __construct(private readonly SmsGateway $gateway) {}

    /**
     * @return array{references: int, delivered: int, undelivered: int, pending: int}
     */
    public function run(): array
    {
        $summary = ['references' => 0, 'delivered' => 0, 'undelivered' => 0, 'pending' => 0];

        if (! $this->gateway->isConfigured()) {
            return $summary;
        }

        foreach ($this->dueGroups() as $reference => $logs) {
            $report = $this->gateway->deliveryReport((string) $reference);

            if ($report === null) {
                // Busy or failing: leave everything as it is and ask again on
                // the next run rather than hammer the provider now.
                break;
            }

            $summary['references']++;

            foreach ($logs as $log) {
                $outcome = $report[(string) PhoneNumber::toInternationalDigits($log->recipient_phone)] ?? null;
                $status = $outcome['status'] ?? 'pending';
                $summary[$status]++;

                $this->record($log, $status, $outcome['detail'] ?? null);
            }
        }

        return $summary;
    }

    /**
     * Accepted messages whose outcome is not yet known and whose next check
     * has come, grouped by the provider reference they were sent under.
     *
     * @return array<string, list<EventNotificationLog>>
     */
    private function dueGroups(): array
    {
        $window = self::CHECK_AFTER_MINUTES[count(self::CHECK_AFTER_MINUTES) - 1];

        $logs = EventNotificationLog::withoutGlobalScopes()
            ->where('channel', EventNotificationLog::CHANNEL_SMS)
            ->where('status', EventNotificationLog::STATUS_SENT)
            ->where('sent_at', '>=', now()->subMinutes($window + 60))
            ->whereNotNull('metadata->provider_reference')
            ->whereNull('metadata->delivery')
            ->oldest('sent_at')
            ->get();

        $groups = [];

        foreach ($logs as $log) {
            if ((int) ($log->metadata['delivery_checks'] ?? 0) >= $this->checksDue($log)) {
                continue;
            }

            $reference = (string) $log->metadata['provider_reference'];

            if (! isset($groups[$reference]) && count($groups) >= self::REFERENCES_PER_RUN) {
                continue;
            }

            $groups[$reference][] = $log;
        }

        return $groups;
    }

    /**
     * How many checks this message should have had by now.
     */
    private function checksDue(EventNotificationLog $log): int
    {
        $minutes = $log->sent_at?->diffInMinutes(now()) ?? 0;

        return count(array_filter(self::CHECK_AFTER_MINUTES, fn (int $after): bool => $after <= $minutes));
    }

    private function record(EventNotificationLog $log, string $status, ?string $detail): void
    {
        $metadata = $log->metadata ?? [];
        // Catch up to the schedule, so an outage is followed by one check
        // rather than a burst of the ones that were missed.
        $metadata['delivery_checks'] = $this->checksDue($log);

        if ($status !== 'pending') {
            $metadata['delivery'] = $status;
            $metadata['delivery_detail'] = $detail;
            $metadata['delivery_known_at'] = now()->toIso8601String();
        }

        $log->timestamps = false;
        $log->forceFill(['metadata' => $metadata])->save();
    }
}
