<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTicketType;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventTicketTypeController extends Controller
{
    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $this->validateTicketType($request);

        if ($validated['price'] > 0 && $eventModel->isPublished() && ! $tenant->hasActivePaymentGateway()) {
            return response()->json(['message' => 'Connect a payment gateway under Settings → Payments before adding a paid ticket type to a published event.'], 422);
        }

        $ticketType = $eventModel->ticketTypes()->create([
            ...$validated,
            'tenant_id' => $tenant->id,
        ]);

        return response()->json($ticketType);
    }

    public function update(Request $request, string $subdomain, string $event, string $ticketType): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $ticketTypeModel = $eventModel->ticketTypes()->where('id', $ticketType)->firstOrFail();

        $validated = $this->validateTicketType($request);

        if ($validated['price'] > 0 && $eventModel->isPublished() && ! $tenant->hasActivePaymentGateway()) {
            return response()->json(['message' => 'Connect a payment gateway under Settings → Payments before making this ticket type paid.'], 422);
        }

        $ticketTypeModel->update($validated);

        return response()->json($ticketTypeModel);
    }

    public function updateBadgeTier(Request $request, string $subdomain, string $event, string $ticketType): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $ticketTypeModel = $eventModel->ticketTypes()->where('id', $ticketType)->firstOrFail();

        $validated = $request->validate([
            'badge_tier' => ['required', Rule::in(EventTicketType::TIERS)],
        ]);

        $ticketTypeModel->update($validated);

        return response()->json($ticketTypeModel);
    }

    public function destroy(string $subdomain, string $event, string $ticketType): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $ticketTypeModel = $eventModel->ticketTypes()->where('id', $ticketType)->firstOrFail();

        if ($ticketTypeModel->registrations()->exists()) {
            return response()->json(['message' => 'This ticket type has registrations and cannot be deleted. Deactivate it instead.'], 422);
        }

        $ticketTypeModel->delete();

        return response()->json(['message' => 'Ticket type deleted.']);
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTicketType(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'badge_tier' => ['sometimes', Rule::in(EventTicketType::TIERS)],
            'price' => ['required', 'integer', 'min:0'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer'],
        ]);
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
