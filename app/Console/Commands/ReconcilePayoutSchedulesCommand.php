<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EventPayoutSchedule;
use Illuminate\Console\Command;

final class ReconcilePayoutSchedulesCommand extends Command
{
    protected $signature = 'app:reconcile-payout-schedules {--event= : Specific event ID to preview}';

    protected $description = 'Preview legacy event schedules without transferring money or posting settlements';

    public function handle(): int
    {
        $this->warn('Legacy payout schedules are preview only. No payout, reserve or settlement is posted. Use an approved organisation schedule with human approval.');
        EventPayoutSchedule::query()->where('status', EventPayoutSchedule::STATUS_ACTIVE)
            ->when($this->option('event'), fn ($query, $id) => $query->where('event_id', $id))
            ->orderBy('id')->chunkById(100, function ($schedules): void {
                foreach ($schedules as $schedule) {
                    $this->line('Schedule '.$schedule->id.' requires review; existing history remains unchanged.');
                }
            });

        return self::SUCCESS;
    }
}
