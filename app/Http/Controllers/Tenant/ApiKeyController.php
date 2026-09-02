<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantApiKey;
use App\Services\Api\ApiKeyService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Display a listing of API keys.
     */
    public function index(string $subdomain): View
    {
        $this->authorize('manage api keys');

        $tenant = $this->tenantContext->getTenant();
        $apiKeys = TenantApiKey::where('tenant_id', $tenant->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        $breadcrumbs = [
            ['link' => route('tenant.dashboard', ['subdomain' => $tenant->slug]), 'name' => __('Home')],
            ['name' => __('API Keys')],
        ];

        return view('admin.settings.api-keys.index', ['apiKeys' => $apiKeys, 'breadcrumbs' => $breadcrumbs]);
    }

    /**
     * Store a newly created API key in storage.
     */
    public function store(Request $request, string $subdomain): JsonResponse
    {
        $this->authorize('manage api keys');

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $tenant = $this->tenantContext->getTenant();

        $result = $this->apiKeyService->generate(
            $tenant->id,
            auth()->id(),
            $request->name
        );

        return response()->json([
            'status' => 'success',
            'message' => __('API Key generated successfully.'),
            'key' => $result['key'], // Plain key to be shown once in the UI
        ]);
    }

    /**
     * Remove the specified API key from storage (Revoke).
     */
    public function destroy(string $subdomain, string $id): JsonResponse
    {
        $this->authorize('manage api keys');

        $tenant = $this->tenantContext->getTenant();

        // Manual lookup because of UUID and connection weirdness sometimes with default implicit binding
        $apiKey = TenantApiKey::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $apiKey->update(['revoked_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => __('API Key revoked successfully.'),
        ]);
    }
}
