<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantApiKey;
use App\Services\Api\ApiKeyService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(string $subdomain): Response
    {
        $this->authorize('manage api keys');

        $tenant = $this->tenantContext->getTenant();
        $apiKeys = TenantApiKey::where('tenant_id', $tenant->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TenantApiKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'key_hint' => $key->key_hint,
                'created_by' => $key->user?->displayName(),
                'created_at' => $key->created_at->format('Y-m-d'),
                'last_used_at' => $key->last_used_at?->diffForHumans(),
                'revoked_at' => $key->revoked_at?->format('Y-m-d'),
            ]);

        return Inertia::render('Tenant/ApiKeys/Index', [
            'apiKeys' => $apiKeys,
        ]);
    }

    public function store(Request $request, string $subdomain): RedirectResponse
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

        return redirect()->route('tenant.api-keys.index', ['subdomain' => $subdomain])
            ->with('success', __('API Key generated successfully.'))
            ->with('plainKey', $result['key']);
    }

    public function destroy(string $subdomain, string $id): RedirectResponse
    {
        $this->authorize('manage api keys');

        $tenant = $this->tenantContext->getTenant();

        $apiKey = TenantApiKey::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $apiKey->update(['revoked_at' => now()]);

        return redirect()->route('tenant.api-keys.index', ['subdomain' => $subdomain])
            ->with('success', __('API Key revoked successfully.'));
    }
}
