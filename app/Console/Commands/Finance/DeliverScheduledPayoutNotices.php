<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Jobs\DeliverScheduledPayoutNotice;
use App\Models\ScheduledPayoutNotice;
use Illuminate\Console\Command;

final class DeliverScheduledPayoutNotices extends Command
{
    protected $signature = 'payouts:deliver-notices';

    protected $description = 'Recover durable payout notices; never submit transfers';

    public function handle(): int
    {
        ScheduledPayoutNotice::query()->whereIn('status', ['queued', 'retry', 'sending'])->where('attempts', '<', 5)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($query) => $query->where('status', '<>', 'sending')->orWhere('claimed_at', '<=', now()->subMinutes(10)))
            ->orderBy('id')->limit(100)->get()->each(fn (ScheduledPayoutNotice $notice) => DeliverScheduledPayoutNotice::dispatch($notice->id));
        ScheduledPayoutNotice::query()->where('status', 'sent')->where('delivered_at', '<', now()->subDays(90))->whereNotNull('recipient_email')->update(['recipient_email' => null]);

        return self::SUCCESS;
    }
}
