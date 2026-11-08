<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventOrganiserAccess;
use App\Services\Events\EventStaffScope;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies event assignments to pages, actions and nested bound resources.
 * Scoped organisers additionally have only explicit nonfinancial event actions.
 */
final class RestrictEventStaffToAssignedEvents
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->getTenant();
        $event = $request->route('event');

        if (! $user instanceof User || $tenant === null) {
            return $next($request);
        }

        $organiser = EventStaffScope::isEventOrganiser($user, (string) $tenant->id);
        if ($organiser && $request->routeIs('tenant.dashboard')) {
            return redirect()->route('tenant.events.index', ['subdomain' => $tenant->slug]);
        }
        if ($event === null) {
            if ($organiser && ! $request->routeIs('tenant.account', 'tenant.account.*')) {
                abort_unless($request->routeIs('tenant.events.index'), 403);
            }

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
        $eventId = in_array($reference, $allowed, true) ? $reference : (string) Event::query()
            ->whereIn('id', $allowed)->where('slug', $reference)->value('id');
        foreach ($request->route()->parameters() as $parameter) {
            if ($parameter instanceof Model && array_key_exists('event_id', $parameter->getAttributes())) {
                abort_unless((string) $parameter->getAttribute('event_id') === $eventId, 404);
            }
            if ($parameter instanceof \App\Models\EventAbstractReview) {
                $abstract = $request->route('abstract');
                abort_unless($abstract instanceof \App\Models\EventAbstract && (string) $parameter->abstract_id === (string) $abstract->id, 404);
            }
        }
        if ($organiser) {
            abort_unless(EventOrganiserAccess::allows($request->route()), 403);
        }

        return $next($request);
    }
}
