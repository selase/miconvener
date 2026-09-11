<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPromoCode;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventPromoCodeController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $promoCodes = EventPromoCode::where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('event_id', $eventModel->id)->orWhereNull('event_id'))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'promo_codes' => $promoCodes,
            'ticket_types' => $eventModel->ticketTypes()->get(['id', 'name', 'price', 'access_code']),
        ]);
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('landlord.event_promo_codes', 'code')
                    ->where('tenant_id', $tenant->id)
                    ->where(fn ($q) => $q->where('event_id', $eventModel->id)->orWhereNull('event_id')),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(EventPromoCode::TYPES)],
            'discount_value' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_attendee' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'applicable_ticket_type_ids' => ['nullable', 'array'],
            'applicable_ticket_type_ids.*' => ['string', 'exists:landlord.event_ticket_types,id'],
            'is_active' => ['nullable', 'boolean'],
            'is_global' => ['nullable', 'boolean'],
        ]);

        $promoCode = EventPromoCode::create([
            'tenant_id' => $tenant->id,
            'event_id' => ! empty($validated['is_global']) ? null : $eventModel->id,
            'code' => mb_strtoupper($validated['code']),
            'description' => $validated['description'] ?? null,
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_type'] === EventPromoCode::TYPE_COMPLIMENTARY ? 0 : (int) $validated['discount_value'],
            'currency' => $validated['currency'] ?? ($eventModel->currency ?: 'GHS'),
            'max_redemptions' => $validated['max_redemptions'] ?? null,
            'max_per_attendee' => (int) ($validated['max_per_attendee'] ?? 1),
            'starts_at' => $validated['starts_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'applicable_ticket_type_ids' => $validated['applicable_ticket_type_ids'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Promo code created successfully.',
            'promo_code' => $promoCode,
        ], 201);
    }

    public function update(Request $request, string $subdomain, string $event, string $promoCode): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $promoCodeModel = EventPromoCode::where('tenant_id', $tenant->id)
            ->where('id', $promoCode)
            ->firstOrFail();

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('landlord.event_promo_codes', 'code')
                    ->where('tenant_id', $tenant->id)
                    ->where(fn ($q) => $q->where('event_id', $eventModel->id)->orWhereNull('event_id'))
                    ->ignore($promoCodeModel->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(EventPromoCode::TYPES)],
            'discount_value' => ['required', 'integer', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_attendee' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'applicable_ticket_type_ids' => ['nullable', 'array'],
            'applicable_ticket_type_ids.*' => ['string', 'exists:landlord.event_ticket_types,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        $promoCodeModel->update([
            'code' => mb_strtoupper($validated['code']),
            'description' => $validated['description'] ?? null,
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_type'] === EventPromoCode::TYPE_COMPLIMENTARY ? 0 : (int) $validated['discount_value'],
            'max_redemptions' => $validated['max_redemptions'] ?? null,
            'max_per_attendee' => (int) $validated['max_per_attendee'],
            'starts_at' => $validated['starts_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'applicable_ticket_type_ids' => $validated['applicable_ticket_type_ids'] ?? null,
            'is_active' => (bool) $validated['is_active'],
        ]);

        return response()->json([
            'message' => 'Promo code updated successfully.',
            'promo_code' => $promoCodeModel,
        ]);
    }

    public function destroy(string $subdomain, string $event, string $promoCode): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $this->findEvent($tenant->id, $event);

        $promoCodeModel = EventPromoCode::where('tenant_id', $tenant->id)
            ->where('id', $promoCode)
            ->firstOrFail();

        $promoCodeModel->delete();

        return response()->json(['message' => 'Promo code deleted successfully.']);
    }

    public function toggle(string $subdomain, string $event, string $promoCode): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $this->findEvent($tenant->id, $event);

        $promoCodeModel = EventPromoCode::where('tenant_id', $tenant->id)
            ->where('id', $promoCode)
            ->firstOrFail();

        $promoCodeModel->update(['is_active' => ! $promoCodeModel->is_active]);

        return response()->json([
            'message' => 'Promo code status updated.',
            'promo_code' => $promoCodeModel,
        ]);
    }

    private function findEvent(string $tenantId, string $eventId): Event
    {
        return Event::where('tenant_id', $tenantId)->where('id', $eventId)->firstOrFail();
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
