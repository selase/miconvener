<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SpeakerPortalController extends Controller
{
    public function show(string $subdomain, string $event, string $token): Response
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $pivot = $this->findPivot($tenant->id, $eventModel->id, $token);

        $sessions = EventSession::where('event_id', $eventModel->id)
            ->whereHas('speakers', fn ($q) => $q->where('speakers.id', $pivot->speaker_id))
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('Public/Events/SpeakerPortal', [
            'event' => ['slug' => $eventModel->slug, 'name' => $eventModel->name],
            'token' => $token,
            'speaker' => [
                'name' => $pivot->speaker->name,
                'is_confirmed' => $pivot->is_confirmed,
            ],
            'sessions' => $sessions->map(fn (EventSession $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'location' => $s->location,
            ])->values(),
            'slides' => $pivot->slidesMaterial ? [
                'title' => $pivot->slidesMaterial->title,
                'file_size' => $pivot->slidesMaterial->file_size,
            ] : null,
        ]);
    }

    public function confirm(Request $request, string $subdomain, string $event, string $token): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $pivot = $this->findPivot($tenant->id, $eventModel->id, $token);

        $validated = $request->validate([
            'attending' => ['required', 'boolean'],
        ]);

        $pivot->update(['is_confirmed' => $validated['attending']]);

        return response()->json(['is_confirmed' => $validated['attending']]);
    }

    public function uploadSlides(Request $request, string $subdomain, string $event, string $token): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $pivot = $this->findPivot($tenant->id, $eventModel->id, $token);

        $request->validate([
            'slides' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('slides');
        $path = Helper::processUploadedFile($request, 'slides', 'speaker_slides', 'event-materials', config('app.env') === 'production' ? 's3' : 'public');

        if ($pivot->slidesMaterial) {
            Helper::deleteFile($pivot->slidesMaterial->file_path, config('app.env') === 'production' ? 's3' : 'public');
            $pivot->slidesMaterial->update([
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]);
        } else {
            $material = EventMaterial::create([
                'tenant_id' => $tenant->id,
                'event_id' => $eventModel->id,
                'title' => "{$pivot->speaker->name} — Slides",
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'download_limit' => 999,
            ]);
            $pivot->update(['slides_material_id' => $material->id]);
        }

        return response()->json(['message' => 'Slides uploaded.']);
    }

    private function findPivot(string $tenantId, string $eventId, string $token): EventSpeaker
    {
        return EventSpeaker::where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('portal_token', $token)
            ->with(['speaker', 'slidesMaterial'])
            ->firstOrFail();
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
