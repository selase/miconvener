<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreGoodwillMessageRequest;
use App\Models\Event;
use App\Models\EventContribution;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Lets a guest leave a free message on an event's wall. It is stored as a
 * contribution with no money in it, waiting for the organiser to approve it.
 */
final class EventGoodwillMessageController extends Controller
{
    public function store(StoreGoodwillMessageRequest $request, string $subdomain, string $event): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getTenant() ?? abort(404);

        $eventModel = Event::where('tenant_id', $tenant->id)
            ->where('slug', $event)
            ->published()
            ->firstOrFail();

        $back = redirect()->route('public.events.show', [
            'subdomain' => $tenant->slug,
            'event' => $eventModel->slug,
        ]);

        if (! $eventModel->allowsGoodwillMessages()) {
            return $back->with('error', 'This event is not taking messages right now.');
        }

        $thanks = 'Thank you. Your message will appear on the wall once it has been approved.';

        if (filled($request->input('website'))) {
            return $back->with('success', $thanks);
        }

        EventContribution::create([
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'contributor_name' => mb_trim((string) $request->input('contributor_name')),
            'amount' => 0,
            'currency' => $eventModel->currency ?: 'GHS',
            'status' => EventContribution::STATUS_MESSAGE,
            'payment_reference' => 'MSG-'.mb_strtoupper(Str::random(16)),
            'provider' => 'none',
            'tribute_message' => mb_trim((string) $request->input('tribute_message')),
            'is_anonymous' => $request->boolean('is_anonymous'),
            'is_approved' => false,
        ]);

        return $back->with('success', $thanks);
    }
}
