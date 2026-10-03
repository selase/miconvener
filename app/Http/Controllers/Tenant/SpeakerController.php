<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Speaker;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SpeakerController extends Controller
{
    public function index(string $subdomain): JsonResponse
    {
        $this->authorize('read event');
        $tenant = $this->getTenant();

        $speakers = Speaker::where('tenant_id', $tenant->id)->orderBy('name')->get();

        return response()->json($speakers->map(fn (Speaker $speaker): array => $this->toPayload($speaker)));
    }

    public function store(Request $request, string $subdomain): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();

        $validated = $this->validateSpeaker($request);

        if ($request->hasFile('photo')) {
            $validated['photo_path'] = Helper::processUploadedFile($request, 'photo', 'speaker', 'speakers', config('app.env') === 'production' ? 's3' : 'public');
        }

        $speaker = Speaker::create([...$validated, 'tenant_id' => $tenant->id]);

        return response()->json($this->toPayload($speaker));
    }

    public function update(Request $request, string $subdomain, string $speaker): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();

        $speakerModel = Speaker::where('tenant_id', $tenant->id)->where('id', $speaker)->firstOrFail();

        $validated = $this->validateSpeaker($request);

        if ($request->hasFile('photo')) {
            if ($speakerModel->photo_path) {
                Helper::deleteFile($speakerModel->photo_path, config('app.env') === 'production' ? 's3' : 'public');
            }
            $validated['photo_path'] = Helper::processUploadedFile($request, 'photo', 'speaker', 'speakers', config('app.env') === 'production' ? 's3' : 'public');
        }

        $speakerModel->update($validated);

        return response()->json($this->toPayload($speakerModel));
    }

    public function destroy(string $subdomain, string $speaker): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();

        $speakerModel = Speaker::where('tenant_id', $tenant->id)->where('id', $speaker)->firstOrFail();

        if ($speakerModel->photo_path) {
            Helper::deleteFile($speakerModel->photo_path, config('app.env') === 'production' ? 's3' : 'public');
        }

        foreach ($speakerModel->eventSpeakers as $eventSpeaker) {
            if ($eventSpeaker->slidesMaterial && $eventSpeaker->slidesMaterial->provenance === \App\Models\EventMaterial::PROVENANCE_SPEAKER) {
                Helper::deleteFile($eventSpeaker->slidesMaterial->file_path, config('app.env') === 'production' ? 's3' : 'public');
                $eventSpeaker->slidesMaterial->delete();
            }
        }

        $speakerModel->delete();

        return response()->json(['message' => 'Speaker deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSpeaker(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ]);

        unset($validated['photo']);

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(Speaker $speaker): array
    {
        return [
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
        ];
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
