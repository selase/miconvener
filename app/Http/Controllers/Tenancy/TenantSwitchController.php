<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenancy;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TenantSwitchController extends Controller
{
    /**
     * List all tenants for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenants = $user->tenants()->get()->filter(fn (Tenant $tenant): bool => app(\App\Services\Tenancy\OrganisationMemberships::class)->isActive($user, $tenant));

        return response()->json($tenants->map(fn (Tenant $tenant): array => $this->workspace($tenant))->values());
    }

    /**
     * Switch the active tenant context.
     */
    public function switch(Request $request): JsonResponse
    {
        abort_if(Session::has('original_user'), 403, 'Stop impersonation before switching organisations.');

        $request->validate([
            'tenant_id' => 'required|uuid|exists:tenants,id',
        ]);

        $tenant = Tenant::findOrFail($request->tenant_id);
        $user = $request->user();

        if (! app(\App\Services\Tenancy\OrganisationMemberships::class)->isActive($user, $tenant) || $tenant->status !== \App\Enum\TenantStatusEnum::ACTIVE) {
            throw new HttpException(403, 'User does not belong to this tenant');
        }

        $fromTenantId = Session::get('active_tenant_id');
        Session::put('active_tenant_id', $tenant->id);

        $this->auditSwitch((string) $user->id, $fromTenantId ? (string) $fromTenantId : null, (string) $tenant->id, $request);

        return response()->json([
            'message' => 'Tenant switched successfully',
            'tenant' => $this->workspace($tenant),
            'redirect_url' => $tenant->url(\App\Providers\RouteServiceProvider::HOME),
        ]);
    }

    /** @return array{id: string, name: string, slug: string} */
    private function workspace(Tenant $tenant): array
    {
        return ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug];
    }

    /**
     * Log the tenant switch action.
     */
    private function auditSwitch(int|string $userId, ?string $fromTenantId, string $toTenantId, Request $request): void
    {
        DB::connection('landlord')->table('tenant_switch_audit')->insert([
            'user_id' => $userId,
            'from_tenant_id' => $fromTenantId,
            'to_tenant_id' => $toTenantId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
