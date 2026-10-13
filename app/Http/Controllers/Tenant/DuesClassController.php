<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Dues\StoreDuesClassRequest;
use App\Http\Requests\Tenant\Dues\UpdateDuesClassRequest;
use App\Models\DuesClass;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's dues classes: what a member pays and how often. Classes
 * are deactivated, never deleted, because members and past charges use them.
 */
final class DuesClassController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage members');
        $tenant = $this->getTenant();

        $classes = DuesClass::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return Inertia::render('Tenant/Dues/Classes', [
            'classes' => $classes->map(fn (DuesClass $class): array => $this->payload($class))->values(),
        ]);
    }

    public function store(StoreDuesClassRequest $request): JsonResponse
    {
        $tenant = $this->getTenant();

        $class = DuesClass::query()->create([
            ...$request->validated(),
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        return response()->json($this->payload($class->loadCount('members')), 201);
    }

    public function update(UpdateDuesClassRequest $request, string $subdomain, string $class): JsonResponse
    {
        $model = DuesClass::query()->where('tenant_id', $this->getTenant()->id)->findOrFail($class);
        $model->update($request->validated());

        return response()->json($this->payload($model->loadCount('members')));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DuesClass $class): array
    {
        return [
            'id' => $class->id,
            'name' => $class->name,
            'amount' => $class->amount,
            'currency' => $class->currency,
            'period' => $class->period->value,
            'is_active' => $class->is_active,
            'member_count' => (int) $class->members_count,
        ];
    }

    private function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
