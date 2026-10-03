<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\Events\OccurrenceReminderMail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

final class SendOccurrenceRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:send-occurrence-reminders {--hours=24} {--event=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send automated pre-occurrence email reminders to confirmed attendees for recurring event sessions.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        if ($hours <= 0) {
            $hours = 24;
        }

        $now = CarbonImmutable::now();
        $cutoff = $now->addHours($hours);

        $eventId = $this->option('event');

        $query = EventSession::query()
            ->with(['event.tenant'])
            ->where('is_occurrence', true)
            ->where('occurrence_status', EventSession::STATUS_SCHEDULED)
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $cutoff);

        if ($eventId) {
            $query->where('event_id', $eventId);
        } else {
            $query->whereHas('event', function ($q): void {
                $q->where('status', Event::STATUS_PUBLISHED)
                    ->where('is_recurring', true);
            });
        }

        $sessions = $query->get();

        if ($sessions->isEmpty()) {
            $this->info('No upcoming occurrences requiring reminders found.');

            return self::SUCCESS;
        }

        $this->info("Found {$sessions->count()} occurrence(s) to process for reminders.");

        foreach ($sessions as $session) {
            $event = $session->event;
            if (! $event) {
                continue;
            }

            $registrations = EventRegistration::query()
                ->where('event_id', $session->event_id)
                ->where('status', EventRegistration::STATUS_CONFIRMED)
                ->whereNotNull('email')
                ->cursor();

            $recipientCount = 0;
            foreach ($registrations as $registration) {
                Mail::to($registration->email)->queue(
                    new OccurrenceReminderMail($event, $session, $registration)
                );
                if ($event->tenant) {
                    app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($event->tenant, 'email_credits');
                }
                $recipientCount++;
            }

            $session->update([
                'reminder_sent_at' => now(),
            ]);

            $this->info("Dispatched {$recipientCount} reminder(s) for occurrence: {$session->title} ({$session->starts_at?->toDateTimeString()})");
        }

        return self::SUCCESS;
    }
}
