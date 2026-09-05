<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventMaterial;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventMaterialController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public static function toPayload(EventMaterial $material): array
    {
        return [
            'id' => $material->id,
            'title' => $material->title,
            'session_id' => $material->session_id,
            'file_size' => $material->file_size,
            'download_limit' => $material->download_limit,
            'release_at' => $material->release_at?->toIso8601String(),
            'is_released' => $material->isReleased(),
            'downloads_count' => $material->downloads()->count(),
        ];
    }

    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'session_id' => ['nullable', 'exists:event_sessions,id'],
            'download_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'release_at' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $path = Helper::processUploadedFile($request, 'file', 'material', 'event-materials', config('app.env') === 'production' ? 's3' : 'public');

        $material = $eventModel->materials()->create([
            'tenant_id' => $tenant->id,
            'session_id' => $validated['session_id'] ?? null,
            'title' => $validated['title'],
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'download_limit' => $validated['download_limit'],
            'release_at' => $validated['release_at'] ?? null,
        ]);

        return response()->json($this->toPayload($material));
    }

    public function destroy(string $subdomain, string $event, string $material): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $materialModel = $eventModel->materials()->where('id', $material)->firstOrFail();
        Helper::deleteFile($materialModel->file_path, config('app.env') === 'production' ? 's3' : 'public');
        $materialModel->delete();

        return response()->json(['message' => 'Material deleted.']);
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
