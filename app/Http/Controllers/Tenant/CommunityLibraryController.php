<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\StoreLibraryItemRequest;
use App\Http\Requests\Tenant\Community\UpdateLibraryItemRequest;
use App\Models\CommunityGroup;
use App\Models\CommunityLibraryItem;
use App\Models\Tenant;
use App\Services\Community\CommunityContentStorage;
use App\Services\Community\CommunityLibrary;
use App\Services\Community\CommunityLibraryRollout;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunityLibraryController extends Controller
{
    public function index(\Illuminate\Http\Request $request, CommunityLibrary $library, CommunityLibraryRollout $rollout): Response
    {
        $this->authorize('manage community library');
        $tenant = $this->tenant();
        $eventId = $request->query('event');
        $sessionId = $request->query('service');
        $initialService = is_string($eventId) && is_string($sessionId) && \Illuminate\Support\Str::isUuid($eventId) && \Illuminate\Support\Str::isUuid($sessionId) ? ['event_id' => $eventId, 'session_id' => $sessionId] : null;
        $items = CommunityLibraryItem::query()->where('tenant_id', $tenant->id)->with(['currentVersion', 'tenant'])->latest('updated_at')->paginate(25);

        return Inertia::render('Tenant/Community/Library', ['items' => $items->through(fn ($item) => $library->payload($item, true)), 'canPublish' => $rollout->canPublish($tenant), 'initialService' => $initialService, 'groups' => CommunityGroup::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(StoreLibraryItemRequest $request, CommunityLibrary $library): JsonResponse
    {
        $item = $library->save($this->tenant(), $request->validated(), $request->file('file'), null, (int) $request->user()->id);

        return response()->json(['item' => $library->payload($item, true)], 201);
    }

    public function update(UpdateLibraryItemRequest $request, string $subdomain, string $item, CommunityLibrary $library): JsonResponse
    {
        $saved = $library->save($this->tenant(), $request->validated(), $request->file('file'), $this->item($item), (int) $request->user()->id);

        return response()->json(['item' => $library->payload($saved, true)]);
    }

    public function withdraw(string $subdomain, string $item): JsonResponse
    {
        $this->authorize('manage community library');
        $this->item($item)->update(['state' => 'withdrawn']);

        return response()->json(['withdrawn' => true]);
    }

    public function history(string $subdomain, string $item): JsonResponse
    {
        $this->authorize('manage community library');
        $model = $this->item($item);
        $versions = $model->versions()->where('tenant_id', $model->tenant_id)->latest()->paginate(20);

        return response()->json(['versions' => $versions->through(fn ($version) => ['id' => $version->id, 'edition' => $version->edition, 'created_at' => $version->created_at->toIso8601String(), 'url' => route('tenant.community.library.version', ['subdomain' => $this->tenant()->slug, 'item' => $model->id, 'version' => $version->id])])])->header('Cache-Control', 'private, no-store');
    }

    public function version(string $subdomain, string $item, string $version, CommunityContentStorage $storage): StreamedResponse
    {
        $this->authorize('manage community library');
        $model = $this->item($item);

        return $storage->stream($model->versions()->where('tenant_id', $model->tenant_id)->findOrFail($version));
    }

    private function item(string $id): CommunityLibraryItem
    {
        return CommunityLibraryItem::query()->where('tenant_id', $this->tenant()->id)->findOrFail($id);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(404);
    }
}
