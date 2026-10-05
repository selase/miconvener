<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventStaffScope;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Event Staff who have been assigned to particular events away from
 * every other event: any console route naming an event ({event}, by id or
 * slug) answers 404 for an event they don't work. One check here covers
 * every event page and action, so no route can be missed.
 */
final class RestrictEventStaffToAssignedEvents
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->getTenant();
        $event = $request->route('event');

        if (! $user instanceof User || $tenant === null || $event === null) {
            return $next($request);
        }

        $allowed = EventStaffScope::restrictedEventIds($user, (string) $tenant->id);

        if ($allowed === null) {
            return $next($request);
        }

        $reference = $event instanceof Event ? (string) $event->id : (string) $event;

        $assigned = in_array($reference, $allowed, true)
            || Event::query()->whereIn('id', $allowed)->where('slug', $reference)->exists();

        abort_unless($assigned, 404);

        return $next($request);
    }
}
