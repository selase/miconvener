<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Api\ApiKeyService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDatabaseManager;
use App\Services\Tenancy\TenantStorageManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateWithApiKey
{
    public function __construct(
        private ApiKeyService $apiKeyService,
        private TenantContext $context,
        private TenantDatabaseManager $dbManager,
        private TenantStorageManager $storageManager
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');

        if (! $key) {
            return response()->json(['message' => 'API Key required.'], 401);
        }

        $apiKey = $this->apiKeyService->authenticate($key);

        if (! $apiKey || ! $apiKey->isValid()) {
            return response()->json(['message' => 'Invalid or revoked API Key.'], 401);
        }

        // IP Restriction Check
        if ($apiKey->ip_restrictions && !empty($apiKey->ip_restrictions) && ! in_array($request->ip(), $apiKey->ip_restrictions)) {
            return response()->json(['message' => 'IP not allowed.'], 403);
        }

        $tenant = $apiKey->tenant;

        // Ensure tenant is active
        if ($tenant->status !== \App\Enum\TenantStatusEnum::ACTIVE) {
            return response()->json(['message' => 'Tenant account is not active.'], 403);
        }

        // Tenant-level IP Restriction Check
        if ($tenant->allowed_ips && !empty($tenant->allowed_ips) && ! in_array($request->ip(), $tenant->allowed_ips)) {
            return response()->json(['message' => 'Tenant IP restriction: IP not allowed.'], 403);
        }

        // Set Context
        $this->context->setTenant($tenant);

        // Configure DB & Storage
        if ($tenant->requiresDedicatedDb()) {
            $this->dbManager->configure($tenant);
        } else {
            $this->dbManager->configureShared();
        }
        $this->storageManager->configure($tenant);

        // Log context
        \Illuminate\Support\Facades\Log::withContext([
            'tenant_id' => $tenant->id,
            'api_key_id' => $apiKey->id,
        ]);

        // Attach user if present (stateless login)
        if ($apiKey->user_id) {
            $user = \App\Models\User::find($apiKey->user_id);
            if ($user) {
                auth()->setUser($user);
            }
        }

        // Attach key metadata to request attributes
        $request->attributes->set('api_key_id', $apiKey->id);
        $request->attributes->set('auth_type', 'api_key');

        return $next($request);
    }
}
