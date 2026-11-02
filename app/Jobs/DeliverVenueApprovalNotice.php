<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\Marketplace\VenueApprovalNotice;
use App\Models\VenueBookingAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one decision notice. The action row is claimed before sending and the
 * job never retries: a claimed send whose outcome is unknown stays `uncertain`
 * for staff to see rather than risking a second email.
 */
final class DeliverVenueApprovalNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $actionId) {}

    public function handle(): void
    {
        $claimed = VenueBookingAction::query()
            ->whereKey($this->actionId)
            ->where('delivery_state', VenueBookingAction::DELIVERY_QUEUED)
            ->update([
                'delivery_state' => VenueBookingAction::DELIVERY_SENDING,
                'delivery_attempts' => DB::raw('delivery_attempts + 1'),
                'delivery_claimed_at' => now(),
            ]);
        if ($claimed !== 1) {
            return;
        }

        $action = VenueBookingAction::query()->with('booking.listing', 'booking.shop')->findOrFail($this->actionId);

        try {
            $mail = new VenueApprovalNotice($action, route('marketplace.bookings.show', $action->booking->booking_reference));
            $mail->render();
        } catch (Throwable $e) {
            $this->finish($action, VenueBookingAction::DELIVERY_FAILED, $e);

            return;
        }

        try {
            Mail::to($action->booking->planner_email)->send($mail);
        } catch (Throwable $e) {
            $this->finish($action, VenueBookingAction::DELIVERY_UNCERTAIN, $e);

            return;
        }

        $this->finish($action, VenueBookingAction::DELIVERY_SENT);
    }

    private function finish(VenueBookingAction $action, string $state, ?Throwable $e = null): void
    {
        if ($e !== null) {
            Log::warning('Venue approval notice not confirmed as sent', ['action_id' => $action->id, 'state' => $state, 'error' => $e->getMessage()]);
        }

        VenueBookingAction::query()->whereKey($action->id)->update([
            'delivery_state' => $state,
            'delivery_completed_at' => now(),
            'delivery_error' => $e !== null ? mb_substr($e->getMessage(), 0, 500) : null,
        ]);
    }
}
