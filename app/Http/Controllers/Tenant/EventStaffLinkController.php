<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreEventStaffLinkRequest;
use App\Http\Requests\Tenant\UpdateEventStaffLinkRequest;
use App\Models\Event;
use App\Models\EventStaffLink;
use App\Models\Tenant;
use App\Services\Events\QrCodeGenerator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The organizer's side of staff links: hand one to each usher, choose what it
 * may do, and switch it off when it is no longer needed.
 */
final class EventStaffLinkController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $eventModel = $this->findEvent($event);

        return response()->json([
            'links' => $eventModel->staffLinks()->with('event.tenant')->latest()->get()->map(fn (EventStaffLink $link): array => $this->payload($link)),
            'allowance' => $this->allowance($this->tenant()),
        ]);
    }

    public function store(StoreEventStaffLinkRequest $request, string $subdomain, string $event): JsonResponse
    {
        $tenant = $this->tenant();
        $eventModel = $this->findEvent($event);

        $allowance = $this->allowance($tenant);
        if ($allowance['limit'] !== null && $allowance['active'] >= $allowance['limit']) {
            return response()->json([
                'message' => "Your plan allows {$allowance['limit']} active staff link(s) at a time. Switch one off, add an usher pack, or upgrade your plan.",
            ], 422);
        }

        $validated = $request->validated();

        $link = new EventStaffLink([
            'tenant_id' => $tenant->id,
            'event_id' => $eventModel->id,
            'name' => $validated['name'],
            'token' => EventStaffLink::newToken(),
            'can_check_in' => $validated['can_check_in'],
            'can_handle_requests' => $validated['can_handle_requests'],
            'created_by' => $request->user()->id,
        ]);
        $link->setPin($validated['pin'] ?? null);
        $link->save();

        return response()->json($this->payload($link->load('event.tenant')), 201);
    }

    public function update(UpdateEventStaffLinkRequest $request, string $subdomain, string $event, string $staffLink): JsonResponse
    {
        $link = $this->findLink($event, $staffLink);
        $validated = $request->validated();

        $link->fill(collect($validated)->only(['name', 'can_check_in', 'can_handle_requests'])->all());

        if (! $link->can_check_in && ! $link->can_handle_requests) {
            return response()->json(['message' => 'A staff link must allow scanning, requests, or both.'], 422);
        }

        if ($request->boolean('remove_pin')) {
            $link->setPin(null);
        } elseif (filled($validated['pin'] ?? null)) {
            $link->setPin($validated['pin']);
        }

        $link->save();

        return response()->json($this->payload($link->load('event.tenant')));
    }

    public function destroy(string $subdomain, string $event, string $staffLink): JsonResponse
    {
        $this->authorize('update event');
        $link = $this->findLink($event, $staffLink);

        $link->update(['revoked_at' => now()]);

        return response()->json($this->payload($link->load('event.tenant')));
    }

    /**
     * @return array{active: int, limit: int|null}
     */
    private function allowance(Tenant $tenant): array
    {
        return [
            'active' => EventStaffLink::query()->where('tenant_id', $tenant->id)->active()->count(),
            'limit' => $tenant->featureLimitValue('staff_links'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EventStaffLink $link): array
    {
        return [
            'id' => $link->id,
            'name' => $link->name,
            'url' => $link->url(),
            // Shown on the organizer's screen for the usher to scan with
            // their own phone, which is quicker at the door than typing.
            'qr' => $link->isUsable() ? QrCodeGenerator::svgDataUri($link->url(), 200) : null,
            'has_pin' => $link->requiresPin(),
            'can_check_in' => $link->can_check_in,
            'can_handle_requests' => $link->can_handle_requests,
            'is_active' => $link->isUsable(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'last_used_at' => $link->last_used_at?->toIso8601String(),
        ];
    }

    private function findLink(string $eventId, string $linkId): EventStaffLink
    {
        return $this->findEvent($eventId)->staffLinks()->where('id', $linkId)->firstOrFail();
    }

    private function findEvent(string $eventId): Event
    {
        return Event::where('tenant_id', $this->tenant()->id)->where('id', $eventId)->firstOrFail();
    }

    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant instanceof Tenant, 403, 'Tenant context not resolved.');

        return $tenant;
    }
}
