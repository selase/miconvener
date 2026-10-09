<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreArtifactFontRequest;
use App\Models\ArtifactFont;
use App\Models\Event;
use App\Services\Design\ArtifactFontRegistry;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ArtifactFontController extends Controller
{
    public function __construct(private readonly ArtifactFontRegistry $fonts) {}

    public function index(string $subdomain, string $event): JsonResponse
    {
        $eventModel = $this->event($event);

        return response()->json(['fonts' => $this->fonts->catalog((string) $eventModel->tenant_id, (string) $eventModel->id)]);
    }

    public function store(StoreArtifactFontRequest $request, string $subdomain, string $event): JsonResponse
    {
        $eventModel = $this->event($event, true);
        $font = $request->file('font') !== null
            ? $this->fonts->upload($request->file('font'), (string) $eventModel->tenant_id, $request->validated('name'))
            : $this->fonts->import($request->validated('source'), (string) $eventModel->tenant_id);

        return response()->json(['font' => $this->fonts->describe($font->family, $font->name, array_map('intval', array_keys($font->facesSettings())), $event) + ['id' => $font->id]], 201);
    }

    public function face(string $subdomain, string $event, string $family, int $weight): Response
    {
        $eventModel = $this->event($event);
        $path = $this->fonts->path($family, $weight, (string) $eventModel->tenant_id);

        return response()->file($path, ['Content-Type' => 'font/ttf', 'Cache-Control' => 'private, max-age=31536000, immutable']);
    }

    public function destroy(string $subdomain, string $event, string $font): Response
    {
        $eventModel = $this->event($event, true);
        ArtifactFont::withoutGlobalScopes()->where('tenant_id', $eventModel->tenant_id)->whereKey($font)->firstOrFail()->update(['archived_at' => now()]);

        return response()->noContent();
    }

    private function event(string $id, bool $write = false): Event
    {
        abort_unless(Gate::any($write ? ['update badge-template', 'update certificate', 'create certificate'] : ['read badge-template', 'read certificate']), 403);
        $tenantId = app(TenantContext::class)->activeTenantId();
        abort_if($tenantId === null, 403);

        return Event::query()->where('tenant_id', $tenantId)->whereKey($id)->firstOrFail();
    }
}
