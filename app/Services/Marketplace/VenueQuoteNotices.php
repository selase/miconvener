<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Jobs\DeliverVenueQuoteNotice;
use App\Mail\Marketplace\VenueQuoteNoticeMail;
use App\Models\VenueFinancialQuote;
use App\Models\VenueQuoteNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Durable, retryable buyer emails for a quote being issued or withdrawn. The
 * record is written with the decision, sending claims it first, a failed send
 * is retried with a growing delay, and the host can see where it stands.
 */
final class VenueQuoteNotices
{
    /** @var list<int> Minutes to wait after the first, second and third failed attempt. */
    private const BACKOFF = [5, 30, 180];

    public function queue(VenueFinancialQuote $quote, string $kind): void
    {
        $notice = VenueQuoteNotice::query()->firstOrCreate(
            ['quote_id' => $quote->id, 'kind' => $kind],
            ['tenant_id' => $quote->tenant_id, 'shop_id' => $quote->shop_id, 'booking_id' => $quote->booking_id, 'state' => VenueQuoteNotice::QUEUED],
        );
        if ($notice->wasRecentlyCreated) {
            DB::connection('landlord')->afterCommit(fn () => DeliverVenueQuoteNotice::dispatch($notice->id));
        }
    }

    public function deliver(string $noticeId): void
    {
        $claimed = VenueQuoteNotice::query()->whereKey($noticeId)
            ->where(fn ($query) => $query->where('state', VenueQuoteNotice::QUEUED)->orWhere(fn ($due) => $due->where('state', VenueQuoteNotice::RETRY)->where('next_attempt_at', '<=', now())))
            ->update(['state' => VenueQuoteNotice::SENDING, 'attempts' => DB::raw('attempts + 1'), 'claimed_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $notice = VenueQuoteNotice::query()->with('quote.booking.listing', 'quote.booking.shop')->findOrFail($noticeId);
        try {
            $mail = new VenueQuoteNoticeMail($notice, route('marketplace.bookings.show', $notice->quote->booking->booking_reference));
            $mail->render();
            Mail::to($notice->quote->booking->planner_email)->send($mail);
        } catch (Throwable $e) {
            $this->failed($notice, $e);

            return;
        }

        $notice->update(['state' => VenueQuoteNotice::SENT, 'completed_at' => now(), 'error' => null, 'next_attempt_at' => null]);
    }

    /**
     * Queue every notice whose retry is due, and recover sends that never reported back.
     */
    public function retryDue(): int
    {
        VenueQuoteNotice::query()->where('state', VenueQuoteNotice::SENDING)->where('claimed_at', '<=', now()->subMinutes(15))
            ->update(['state' => VenueQuoteNotice::RETRY, 'next_attempt_at' => now(), 'error' => 'The send did not report back.']);
        $due = VenueQuoteNotice::query()->where('state', VenueQuoteNotice::RETRY)->where('next_attempt_at', '<=', now())->pluck('id');
        $due->each(fn (string $id) => DeliverVenueQuoteNotice::dispatch($id));

        return $due->count();
    }

    private function failed(VenueQuoteNotice $notice, Throwable $e): void
    {
        Log::warning('Venue quote notice not sent', ['notice_id' => $notice->id, 'attempt' => $notice->attempts, 'error' => $e->getMessage()]);
        $final = $notice->attempts >= VenueQuoteNotice::MAX_ATTEMPTS;
        $notice->update([
            'state' => $final ? VenueQuoteNotice::FAILED : VenueQuoteNotice::RETRY,
            'next_attempt_at' => $final ? null : now()->addMinutes(self::BACKOFF[min($notice->attempts, count(self::BACKOFF)) - 1]),
            'completed_at' => $final ? now() : null,
            'error' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
