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
        $model = \Illuminate\Support\Facades\DB::connection('landlord')->transaction(function () use ($request, $type): MembershipType {
            $model = MembershipType::query()->where('tenant_id', $this->getTenant()->id)->lockForUpdate()->findOrFail($type);
            $data = $request->validated();
            if (! $model->hasDues() && isset($data['amount'])) {
                $today = \Carbon\CarbonImmutable::now(\App\Models\CommunitySettings::for($this->getTenant())->timezone)->toDateString();
                if (empty($data['dues_start_on']) || $data['dues_start_on'] < $today) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['dues_start_on' => 'Choose the date from which members should start paying dues.']);
                }
            } elseif (array_key_exists('dues_start_on', $data) && $data['dues_start_on'] !== $model->dues_start_on?->toDateString()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['dues_start_on' => 'The dues start date is fixed.']);
            }
            if ($model->charges()->exists()) {
                if ($data['currency'] !== $model->currency) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['currency' => 'This class already has charges, so its currency cannot change.']);
                }
                if (($data['period'] ?? null) !== $model->period?->value) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['period' => 'This class already has charges, so its billing period cannot change.']);
                }
            }
            $model->update($data);

            return $model;
        });

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
            'dues_start_on' => $class->dues_start_on?->toDateString(),
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
