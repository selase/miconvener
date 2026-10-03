<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use App\Models\Speaker;
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

        /** @var Speaker $speaker */
        $speaker = $pivot->speaker;

        $sessions = EventSession::where('event_id', $eventModel->id)
            ->whereHas('speakers', fn ($q) => $q->where('speakers.id', $pivot->speaker_id))
            ->with(['speakers'])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('Public/Events/SpeakerPortal', [
            'event' => [
                'slug' => $eventModel->slug,
                'name' => $eventModel->name,
                'starts_at' => $eventModel->starts_at->toIso8601String(),
                'ends_at' => $eventModel->ends_at->toIso8601String(),
                'location_type' => $eventModel->location_type,
                'address' => $eventModel->address,
            ],
            'token' => $token,
            'speaker' => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'email' => $speaker->email,
                'title' => $speaker->title,
                'organization' => $speaker->organization,
                'bio' => $speaker->bio,
                'photo_url' => Helper::storageUrl($speaker->photo_path),
                'website_url' => $speaker->website_url,
                'linkedin_url' => $speaker->linkedin_url,
                'twitter_url' => $speaker->twitter_url,
                'role' => $pivot->role,
                'is_confirmed' => $pivot->is_confirmed,
                'confirmed_at' => $pivot->confirmed_at?->toIso8601String(),
                'last_invited_at' => $pivot->last_invited_at?->toIso8601String(),
            ],
            'sessions' => $sessions->map(function (EventSession $s) use ($pivot): array {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Speaker> $speakers */
                $speakers = $s->speakers;

                return [
                    'id' => $s->id,
                    'title' => $s->title,
                    'description' => $s->description,
                    'starts_at' => $s->starts_at->toIso8601String(),
                    'ends_at' => $s->ends_at->toIso8601String(),
                    'location' => $s->location,
                    'co_speakers' => $speakers->where('id', '!=', $pivot->speaker_id)->map(fn (Speaker $cs): array => [
                        'id' => $cs->id,
                        'name' => $cs->name,
                        'title' => $cs->title,
                        'photo_url' => Helper::storageUrl($cs->photo_path),
                    ])->values()->all(),
                ];
            })->all(),
            'slides' => $pivot->slidesMaterial ? [
                'id' => $pivot->slidesMaterial->id,
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

        $request->validate([
            'attending' => ['required', 'boolean'],
        ]);

        $attending = $request->boolean('attending');

        $pivot->update([
            'is_confirmed' => $attending,
            'confirmed_at' => $attending ? now() : null,
        ]);

        return response()->json([
            'is_confirmed' => $attending,
            'confirmed_at' => $pivot->confirmed_at?->toIso8601String(),
        ]);

    }

    public function updateProfile(Request $request, string $subdomain, string $event, string $token): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $pivot = $this->findPivot($tenant->id, $eventModel->id, $token);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
        ]);

        $pivot->speaker->update($validated);

        return response()->json([
            'message' => 'Profile updated.',
            'speaker' => [
                'id' => $pivot->speaker->id,
                'name' => $pivot->speaker->name,
                'title' => $pivot->speaker->title,
                'organization' => $pivot->speaker->organization,
                'bio' => $pivot->speaker->bio,
                'website_url' => $pivot->speaker->website_url,
                'linkedin_url' => $pivot->speaker->linkedin_url,
                'twitter_url' => $pivot->speaker->twitter_url,
            ],
        ]);
    }

    public function uploadPhoto(Request $request, string $subdomain, string $event, string $token): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $pivot = $this->findPivot($tenant->id, $eventModel->id, $token);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $speaker = $pivot->speaker;
        if ($speaker->photo_path) {
            Helper::deleteFile($speaker->photo_path, config('app.env') === 'production' ? 's3' : 'public');
        }

        $path = Helper::processUploadedFile($request, 'photo', 'speaker', 'speakers', config('app.env') === 'production' ? 's3' : 'public');
        $speaker->update(['photo_path' => $path]);

        return response()->json([
            'message' => 'Photo uploaded.',
            'photo_url' => Helper::storageUrl($path),
        ]);
    }

    public function uploadSlides(Request $request, string $subdomain, string $event, string $token): JsonResponse
    {
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('slug', $event)->published()->firstOrFail();
        $pivot = $this->findPivot($tenant->id, $eventModel->id, $token);

        $request->validate([
            'slides' => ['required', 'file', 'max:20480', 'mimes:pdf,ppt,pptx,key,zip'],
        ]);

        $file = $request->file('slides');
        $path = Helper::processUploadedFile($request, 'slides', 'speaker_slides', 'event-materials', config('app.env') === 'production' ? 's3' : 'public');

        if ($pivot->slidesMaterial) {
            Helper::deleteFile($pivot->slidesMaterial->file_path, config('app.env') === 'production' ? 's3' : 'public');
            $pivot->slidesMaterial->update([
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'provenance' => EventMaterial::PROVENANCE_SPEAKER,
            ]);
            $material = $pivot->slidesMaterial;
        } else {
            $material = EventMaterial::create([
                'tenant_id' => $tenant->id,
                'event_id' => $eventModel->id,
                'title' => "{$pivot->speaker->name} — Slides",
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'download_limit' => 999,
                'provenance' => EventMaterial::PROVENANCE_SPEAKER,
            ]);
            $pivot->update(['slides_material_id' => $material->id]);
            app(\App\Services\Notifications\EventRuleTriggerService::class)->materialPublished($material);
        }

        return response()->json([
            'message' => 'Slides uploaded.',
            'slides' => [
                'id' => $material->id,
                'title' => $material->title,
                'file_size' => $material->file_size,
            ],
        ]);

    }

    private function findPivot(string $tenantId, string $eventId, string $token): EventSpeaker
    {
        return EventSpeaker::where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('portal_token', $token)
            ->with(['speaker', 'slidesMaterial'])
            ->firstOrFail();
    }

    private function getTenant(): \App\Models\Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }
}
