<?php

declare(strict_types=1);

namespace App\Checks;

use App\Models\EventNotificationLog;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Raises an alert when most recent SMS were refused rather than accepted by
 * the provider: a broken token, an unapproved sender ID, or an empty provider
 * balance. Each refusal is otherwise only a failed row nobody is watching.
 * A handful of refusals among many sends (a mistyped number) is normal.
 */
final class SmsDeliveryFailuresCheck extends Check
{
    public const int WINDOW_MINUTES = 30;

    public const int MIN_FAILURES = 5;

    public function run(): Result
    {
        $recent = EventNotificationLog::withoutGlobalScopes()
            ->where('channel', EventNotificationLog::CHANNEL_SMS)
            ->whereIn('status', [EventNotificationLog::STATUS_SENT, EventNotificationLog::STATUS_FAILED])
            ->where('updated_at', '>=', now()->subMinutes(self::WINDOW_MINUTES));

        $total = (clone $recent)->count();
        $failed = (clone $recent)->where('status', EventNotificationLog::STATUS_FAILED)->count();

        if ($failed < self::MIN_FAILURES || $failed * 2 < $total) {
            return Result::make()->ok()->shortSummary("{$failed} of {$total} refused");
        }

        $latest = (clone $recent)->where('status', EventNotificationLog::STATUS_FAILED)->latest('updated_at')->first();
        $reason = mb_substr((string) ($latest?->metadata['error'] ?? 'no reason recorded'), 0, 200);

        return Result::make()
            ->failed("{$failed} of {$total} SMS in the last ".self::WINDOW_MINUTES." minutes were refused by the provider. Latest reason: {$reason}")
            ->shortSummary("{$failed} of {$total} refused");
    }
}
