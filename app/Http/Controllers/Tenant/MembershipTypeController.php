<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Dues\StoreMembershipTypeRequest;
use App\Http\Requests\Tenant\Dues\UpdateMembershipTypeRequest;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's dues classes: what a member pays and how often. Classes
 * are deactivated, never deleted, because members and past charges use them.
 */
final class MembershipTypeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage members');
        $tenant = $this->getTenant();

        $classes = MembershipType::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('members')
            ->withExists('charges')
            ->orderBy('name')
            ->get();

        return Inertia::render('Tenant/Community/MoneyDues', [
            'canUseGiving' => auth()->user()->can('manage giving') && app(\App\Services\Giving\GivingRollout::class)->enabled($tenant),
            'canRemindArrears' => auth()->user()->can('read finance'),
            'membershipTypes' => $classes->map(fn (MembershipType $class): array => $this->payload($class))->values(),
        ]);
    }

    public function store(StoreMembershipTypeRequest $request): JsonResponse
    {
        $tenant = $this->getTenant();

        $class = MembershipType::query()->create([
            ...$request->validated(),
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        return response()->json($this->payload($class->loadCount('members')->loadExists('charges')), 201);
    }

    public function update(UpdateMembershipTypeRequest $request, string $subdomain, string $type): JsonResponse
    {
        $model = MembershipType::query()->where('tenant_id', $this->getTenant()->id)->findOrFail($type);
        $model->update($request->validated());

        return response()->json($this->payload($model->loadCount('members')->loadExists('charges')));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MembershipType $class): array
    {
        return [
            'id' => $class->id,
            'name' => $class->name,
            'amount' => $class->amount,
            'currency' => $class->currency,
            'period' => $class->period?->value,
            'is_active' => $class->is_active,
            'member_count' => (int) $class->members_count,
            'has_charges' => (bool) $class->charges_exists,
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
