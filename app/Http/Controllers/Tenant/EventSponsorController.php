<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventSponsor;
use App\Models\EventSponsorDeliverable;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventSponsorController extends Controller
{
    public function index(string $subdomain, string $event): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $sponsors = $eventModel->sponsors()->with('deliverables')->get();

        return response()->json($sponsors->map(fn (EventSponsor $s): array => $this->payload($s))->values());
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tier' => ['required', Rule::in(EventSponsor::TIERS)],
            'booth' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $logoPath = $request->hasFile('logo')
            ? Helper::processUploadedFile($request, 'logo', 'sponsor_logo', 'event-sponsors', config('app.env') === 'production' ? 's3' : 'public')
            : null;

        $sponsor = $eventModel->sponsors()->create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'tier' => $validated['tier'],
            'booth' => $validated['booth'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'amount' => $validated['amount'] ?? 0,
            'currency' => $eventModel->currency,
            'logo_path' => $logoPath,
        ]);

        return response()->json($this->payload($sponsor->fresh('deliverables')), 201);
    }

    public function destroy(string $subdomain, string $event, string $sponsor): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $sponsorModel = $eventModel->sponsors()->where('id', $sponsor)->firstOrFail();
        if ($sponsorModel->logo_path) {
            Helper::deleteFile($sponsorModel->logo_path, config('app.env') === 'production' ? 's3' : 'public');
        }
        $sponsorModel->delete();

        return response()->json(['message' => 'Sponsor removed.']);
    }

    public function storeDeliverable(Request $request, string $subdomain, string $event, string $sponsor): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $sponsorModel = $eventModel->sponsors()->where('id', $sponsor)->firstOrFail();

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
        ]);

        $sponsorModel->deliverables()->create([
            'tenant_id' => $tenant->id,
            'description' => $validated['description'],
        ]);

        return response()->json($this->payload($sponsorModel->fresh('deliverables')), 201);
    }

    public function updateDeliverable(Request $request, string $subdomain, string $event, string $sponsor, string $deliverable): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $sponsorModel = $eventModel->sponsors()->where('id', $sponsor)->firstOrFail();

        $validated = $request->validate([
            'is_done' => ['required', 'boolean'],
        ]);

        $deliverableModel = $sponsorModel->deliverables()->where('id', $deliverable)->firstOrFail();
        $deliverableModel->update($validated);

        return response()->json($this->payload($sponsorModel->fresh('deliverables')));
    }

    public function destroyDeliverable(string $subdomain, string $event, string $sponsor, string $deliverable): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);
        $sponsorModel = $eventModel->sponsors()->where('id', $sponsor)->firstOrFail();

        $sponsorModel->deliverables()->where('id', $deliverable)->firstOrFail()->delete();

        return response()->json($this->payload($sponsorModel->fresh('deliverables')));
    }

    public function exportDeliverables(string $subdomain, string $event): StreamedResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = $this->findEvent($tenant->id, $event);

        $filename = "{$eventModel->slug}-sponsor-deliverables.csv";

        return response()->streamDownload(function () use ($eventModel): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Sponsor', 'Tier', 'Deliverable', 'Status']);

            $eventModel->sponsors()->with('deliverables')->orderBy('sort_order')->get()->each(function (EventSponsor $sponsor) use ($handle): void {
                foreach ($sponsor->deliverables as $deliverable) {
                    fputcsv($handle, [
                        $sponsor->name,
                        $sponsor->tier,
                        $deliverable->description,
                        $deliverable->is_done ? 'Done' : 'Outstanding',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EventSponsor $sponsor): array
    {
        return [
            'id' => $sponsor->id,
            'name' => $sponsor->name,
            'tier' => $sponsor->tier,
            'booth' => $sponsor->booth,
            'contact_name' => $sponsor->contact_name,
            'contact_email' => $sponsor->contact_email,
            'amount' => $sponsor->amount,
            'currency' => $sponsor->currency,
            'logo_url' => Helper::storageUrl($sponsor->logo_path),
            'deliverables' => $sponsor->deliverables->map(fn (EventSponsorDeliverable $d): array => [
                'id' => $d->id,
                'description' => $d->description,
                'is_done' => $d->is_done,
            ])->values(),
        ];
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
