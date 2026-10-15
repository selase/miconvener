<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\PreviewMessageRequest;
use App\Http\Requests\Tenant\Community\StoreMessageRequest;
use App\Models\CommunityMessage;
use App\Models\Tenant;
use App\Services\Community\CommunityMessages;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunityMessageController extends Controller
{
    public function index(Request $request, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $service->authorize($tenant, $request->user());

        return response()->json(['messages' => CommunityMessage::query()->where('tenant_id', $tenant->id)->latest()->paginate(30)]);
    }

    public function show(Request $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $service->authorize($tenant, $request->user());
        $model = CommunityMessage::query()->where('tenant_id', $tenant->id)->findOrFail($message);

        return response()->json(['message' => $model, 'deliveries' => $model->deliveries()->select(['id', 'channel', 'status', 'reason', 'provider_reference'])->paginate(50)]);
    }

    public function preview(PreviewMessageRequest $request, CommunityMessages $service): JsonResponse
    {
        return response()->json($service->preview($this->tenant(), $request->user(), $request->validated()));
    }

    public function store(StoreMessageRequest $request, CommunityMessages $service): JsonResponse
    {
        $model = $service->queue($this->tenant(), $request->user(), $request->validated());

        return response()->json(['message' => $model], $model->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(Request $request, string $subdomain, string $message, CommunityMessages $service): JsonResponse
    {
        $tenant = $this->tenant();
        $model = CommunityMessage::query()->where('tenant_id', $tenant->id)->findOrFail($message);
        $service->cancel($tenant, $request->user(), $model);

        return response()->json(['message' => $model->fresh()]);
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(403);
    }
}
