<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Services\Finance\PayoutRelease;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Payouts and refunds across every organisation: what is waiting for the
 * Paystack one-time code (which reaches MiConvener, not the organiser), what
 * failed or is stuck, what was paid, and what was refunded.
 */
final class PayoutOversightController extends Controller
{
    /**
     * A transfer still "processing" after this long has probably been lost
     * by the provider and needs a look.
     */
    private const int STUCK_AFTER_HOURS = 24;

    public function index(): View
    {
        $this->authorize('access-superadmin-dashboard');

        $base = fn () => EventPayout::query()->withoutGlobalScopes()->with(['tenant', 'event' => fn ($query) => $query->withoutGlobalScopes()]);

        $attention = $base()
            ->where(fn ($query) => $query
                ->whereIn('status', [EventPayout::STATUS_AWAITING_OTP, EventPayout::STATUS_FAILED])
                ->orWhere(fn ($stuck) => $stuck->where('status', EventPayout::STATUS_PROCESSING)->where('updated_at', '<', now()->subHours(self::STUCK_AFTER_HOURS))))
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [EventPayout::STATUS_AWAITING_OTP, EventPayout::STATUS_FAILED])
            ->latest('updated_at')
            ->limit(100)
            ->get();

        $since = now()->subDays(30);

        return view('admin.billing.payouts.index', [
            'attention' => $attention,
            'recent' => $base()->latest()->limit(30)->get(),
            'refunds' => EventLedgerEntry::query()->withoutGlobalScopes()
                ->with(['event' => fn ($query) => $query->withoutGlobalScopes()])
                ->where('type', EventLedgerEntry::TYPE_REFUND)
                ->latest()
                ->limit(30)
                ->get(),
            'paid30' => (int) EventPayout::query()->withoutGlobalScopes()->where('status', EventPayout::STATUS_PAID)->where('paid_at', '>=', $since)->sum('amount'),
            'refunded30' => abs((int) EventLedgerEntry::query()->withoutGlobalScopes()->where('type', EventLedgerEntry::TYPE_REFUND)->where('created_at', '>=', $since)->sum('gross_amount')),
            'stuckAfterHours' => self::STUCK_AFTER_HOURS,
        ]);
    }

    public function release(Request $request, string $payout, PayoutRelease $payoutRelease): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $validated = $request->validate(['otp' => ['required', 'string', 'max:32']], ['otp.required' => 'Enter the one-time code Paystack sent.']);
        $payoutModel = EventPayout::query()->withoutGlobalScopes()->findOrFail($payout);

        $result = $payoutRelease->release($payoutModel, $validated['otp']);

        activity()
            ->causedBy($request->user())
            ->performedOn($payoutModel)
            ->withProperties(['released' => $result['released'], 'message' => $result['message']])
            ->log($result['released'] ? 'Released payout with one-time code' : 'Payout release failed');

        return back()->with($result['released'] ? 'success' : 'error', $result['message']);
    }
}
