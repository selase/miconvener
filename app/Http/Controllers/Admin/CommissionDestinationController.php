<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionDestination;
use App\Models\TenantPaymentGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the platform's commission is paid on organisers' own Paystack
 * accounts. Every organiser's subaccount points at one of these, so an account
 * is retired gradually: it stays accepted until a date while organisers move to
 * the new one. Open to the superadmin and platform finance staff.
 */
final class CommissionDestinationController extends Controller
{
    private const int DEFAULT_RETIRE_DAYS = 30;

    public function index(): View
    {
        $this->authorize('manage commission destinations');

        return view('admin.billing.commission-destinations.index', [
            'destinations' => CommissionDestination::query()->orderBy('currency')->orderBy('label')->get(),
            'organisersOnSplit' => $this->organisersOnSplit(),
            'retireDays' => self::DEFAULT_RETIRE_DAYS,
            'usingEnvironment' => ! CommissionDestination::query()->exists() && filled(config('services.commission_split.account_number')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage commission destinations');

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3'],
            'business_name' => ['required', 'string', 'max:120'],
            'settlement_bank' => ['nullable', 'string', 'max:40'],
            'account_number' => ['required', 'string', 'max:40', 'regex:/^[0-9 \-]+$/'],
        ], [
            'account_number.regex' => 'An account number has digits only (spaces and dashes are fine).',
        ]);

        $destination = CommissionDestination::query()->create([
            ...$validated,
            'currency' => mb_strtoupper($validated['currency']),
            'settlement_bank' => filled($validated['settlement_bank'] ?? null) ? $validated['settlement_bank'] : null,
        ]);

        $this->log($request, $destination, 'Added a commission destination');

        return back()->with('success', "{$destination->label} added. Organisers now see it when they set up their commission split.");
    }

    public function retire(Request $request, CommissionDestination $destination): RedirectResponse
    {
        $this->authorize('manage commission destinations');

        $validated = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);

        $stillCurrent = CommissionDestination::query()->current()->whereKeyNot($destination->getKey())->exists();

        if (! $stillCurrent) {
            return back()->with('error', 'Add the account that replaces this one first. Retiring the last current account would leave organisers nowhere to point their subaccount.');
        }

        $destination->update(['accepted_until' => now()->addDays((int) $validated['days'])]);
        $this->log($request, $destination, 'Retired a commission destination', ['days' => (int) $validated['days']]);

        return back()->with('success', sprintf('%s is still accepted for %d days, then organisers pointing at it will be flagged to move. %d organiser(s) are on the commission split now.', $destination->label, $validated['days'], $this->organisersOnSplit()));
    }

    public function restore(Request $request, CommissionDestination $destination): RedirectResponse
    {
        $this->authorize('manage commission destinations');

        $destination->update(['is_active' => true, 'accepted_until' => null]);
        $this->log($request, $destination, 'Restored a commission destination');

        return back()->with('success', "{$destination->label} is current again.");
    }

    public function destroy(Request $request, CommissionDestination $destination): RedirectResponse
    {
        $this->authorize('manage commission destinations');

        if ($destination->is_active && ($destination->accepted_until === null || $destination->accepted_until->isFuture())) {
            return back()->with('error', 'Retire this account and let its date pass before removing it.');
        }

        $this->log($request, $destination, 'Removed a commission destination');
        $destination->delete();

        return back()->with('success', 'Removed.');
    }

    private function organisersOnSplit(): int
    {
        return TenantPaymentGateway::query()
            ->withoutGlobalScopes()
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->whereRaw("(meta->'commission_split'->>'ok') = 'true'")
            ->count();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(Request $request, CommissionDestination $destination, string $message, array $extra = []): void
    {
        activity()
            ->causedBy($request->user())
            ->performedOn($destination)
            ->withProperties(['label' => $destination->label, 'currency' => $destination->currency] + $extra)
            ->log($message);
    }
}
