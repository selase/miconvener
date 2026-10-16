<?php

declare(strict_types=1);

namespace App\Console\Commands\Community;

use App\Jobs\DeliverCommunityMessage;
use App\Jobs\DispatchCommunityMessage;
use App\Models\CommunityMessage;
use Illuminate\Console\Command;

final class DispatchMessagesCommand extends Command
{
    protected $signature = 'community:messages-dispatch';

    protected $description = 'Dispatch due messages and recover queued delivery units without replaying submissions.';

    public function handle(): int
    {
        app(\App\Services\Community\CommunityMessages::class)->recoverInterruptedClaims();
        CommunityMessage::withoutGlobalScopes()->whereIn('status', ['queued', 'scheduled', 'sending'])
            ->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->lazyById(100)->each(function (CommunityMessage $message): void {
                if (! $message->selection_complete) {
                    DispatchCommunityMessage::dispatch($message->id);
                }
                foreach (['email', 'sms'] as $channel) {
                    $ids = $message->deliveries()->where('channel', $channel)->where('status', 'queued')->orderBy('id')->limit(100)->pluck('id')->all();
                    foreach (array_chunk($ids, $channel === 'email' ? 1 : 50) as $batch) {
                        DeliverCommunityMessage::dispatch($message->id, $batch, $channel);
                    }
                }
                app(\App\Services\Community\CommunityMessages::class)->finishProcessing($message);
            });
        $this->info('Due messages and queued delivery units dispatched.');

        return self::SUCCESS;
    }
}
