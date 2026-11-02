<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Venue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Venue\CompleteVenueBookingRequest;
use App\Http\Requests\Tenant\Venue\IssueVenueFinancialQuoteRequest;
use App\Http\Requests\Tenant\Venue\WithdrawVenueFinancialQuoteRequest;
use App\Models\Shop;
use App\Models\VenueBooking;
use App\Models\VenueFinancialQuote;
use App\Services\Marketplace\VenueCommercialQuotes;
use App\Services\Marketplace\VenueSettlementRelease;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The host's side of the final quote: issue or withdraw it, and record that the
 * finished event is complete. Every action is scoped to the organisation's own
 * bookings and carries an operation key, so a retry never repeats a decision.
 */
final class VenueFinancialQuoteController extends Controller
{
    public function issue(IssueVenueFinancialQuoteRequest $request, string $subdomain, string $inquiry, VenueCommercialQuotes $quotes): RedirectResponse
    {
        return $this->act(function () use ($request, $inquiry, $quotes): string {
            $quote = $quotes->issue($this->booking($inquiry), $request->user(), $request->safe()->except('operation_key'), (string) $request->validated('operation_key'));

            return "Final quote version {$quote->version} sent. The buyer can now review and accept it.";
        });
    }

    public function withdraw(WithdrawVenueFinancialQuoteRequest $request, string $subdomain, string $quote, VenueCommercialQuotes $quotes): RedirectResponse
    {
        return $this->act(function () use ($request, $quote, $quotes): string {
            $owned = VenueFinancialQuote::query()->where('shop_id', $this->shop()->id)->findOrFail($quote);
            $quotes->withdraw($owned, $request->user(), (string) $request->validated('reason'), (string) $request->validated('operation_key'));

            return 'Quote withdrawn.';
        });
    }

    public function complete(CompleteVenueBookingRequest $request, string $subdomain, string $inquiry, VenueSettlementRelease $release): RedirectResponse
    {
        return $this->act(function () use ($request, $inquiry, $release): string {
            $release->complete($this->booking($inquiry), $request->user(), (string) $request->validated('reason'), (string) $request->validated('operation_key'));

            return 'Booking marked complete. Its earnings are now available for payout.';
        });
    }

    /**
     * @param  Closure(): string  $action
     */
    private function act(Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->back()->with('success', $message);
    }

    private function shop(): Shop
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404, 'Tenant not found');

        return Shop::query()->where('tenant_id', $tenant->id)->firstOrFail();
    }

    private function booking(string $inquiry): VenueBooking
    {
        return VenueBooking::query()->where('shop_id', $this->shop()->id)->findOrFail($inquiry);
    }
}
