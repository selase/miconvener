<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enum\DuesChargeStatusEnum;
use App\Enum\MemberStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Dues\StoreMemberRequest;
use App\Http\Requests\Tenant\Dues\UpdateMemberRequest;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's members and their dues. The member payload never
 * includes the raw pay token, only the finished pay link.
 */
final class MemberController extends Controller
{
    private const PER_PAGE = 25;

    /** Charges listed in a member's drawer, newest first. */
    private const CHARGES_SHOWN = 12;

    public function index(): Response
    {
        $this->authorize('manage members');
        $tenant = $this->getTenant();

        $members = Member::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'membershipType',
                'charges' => fn ($query) => $query->orderByDesc('period_start')->limit(self::CHARGES_SHOWN),
            ])
            ->withSum(['charges as open_total' => fn ($query) => $query->open()], 'amount')
            ->withExists(['charges as overdue' => fn ($query) => $query->overdue()])
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->through(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status->value,
                'class_name' => $member->membershipType->name,
                'effective_amount' => $member->effectiveAmount(),
                'currency' => $member->membershipType->currency,
                'amount_override' => $member->amount_override,
                'membership_type_id' => $member->membership_type_id,
                'membership_type_active' => $member->membershipType->is_active,
                'joined_on' => $member->joined_on?->toDateString(),
                'notes' => $member->notes,
                'pay_url' => route('public.dues.show', ['subdomain' => $tenant->slug, 'token' => $member->pay_token]),
                'charges' => $member->charges->map(fn (DuesCharge $charge): array => $this->chargePayload($charge))->values(),
                'open_total' => (int) $member->getAttribute('open_total'),
                'overdue' => (bool) $member->getAttribute('overdue'),
            ]);

        return Inertia::render('Tenant/Community/People', [
            'members' => $members,
            'membershipTypes' => MembershipType::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (MembershipType $class): array => [
                    'id' => $class->id,
                    'name' => $class->name,
                    'amount' => $class->amount,
                    'currency' => $class->currency,
                    'period' => $class->period?->value,
                ])->values(),
        ]);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $member = Member::query()->create([
            ...$request->validated(),
            'tenant_id' => $this->getTenant()->id,
            'status' => MemberStatusEnum::Active,
        ]);

        return response()->json(['id' => $member->id], 201);
    }

    public function update(UpdateMemberRequest $request, string $subdomain, string $member): JsonResponse
    {
        $model = $this->findMember($member);
        DB::connection('landlord')->transaction(fn (): bool => $model->update($request->validated()));

        return response()->json(['id' => $model->id]);
    }

    /**
     * Marks the member as having left. Past and paid charges are retained;
     * unpaid advance periods are voided when eligibility ends.
     */
    public function exit(string $subdomain, string $member): JsonResponse
    {
        $this->authorize('manage members');
        $model = $this->findMember($member);
        if ($model->status !== MemberStatusEnum::Exited) {
            DB::connection('landlord')->transaction(fn (): bool => $model->update(['status' => MemberStatusEnum::Exited]));
        }

        return response()->json(['id' => $model->id, 'status' => MemberStatusEnum::Exited->value]);
    }

    public function rejoin(string $subdomain, string $member): JsonResponse
    {
        $this->authorize('manage members');
        $model = $this->findMember($member);
        if ($model->status !== MemberStatusEnum::Exited) {
            return response()->json(['message' => 'Only an exited member can rejoin.'], 422);
        }
        $date = CarbonImmutable::now(CommunitySettings::for($this->getTenant())->timezone)->toDateString();
        DB::connection('landlord')->transaction(fn (): bool => $model->update(['status' => MemberStatusEnum::Active, 'rejoined_on' => $date, 'exited_on' => null]));

        return response()->json(['id' => $model->id, 'status' => MemberStatusEnum::Active->value]);
    }

    /**
     * Retires the member's current payment link and issues a new one.
     */
    public function rotateLink(string $subdomain, string $member): JsonResponse
    {
        $this->authorize('manage members');
        $this->findMember($member)->rotatePayToken();

        return response()->json(['rotated' => true]);
    }

    private function findMember(string $id): Member
    {
        return Member::query()->where('tenant_id', $this->getTenant()->id)->findOrFail($id);
    }

    private function getTenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();

        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(DuesCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'status' => $charge->status->value,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'period_start' => $charge->period_start->toDateString(),
            'period_end' => $charge->period_end->toDateString(),
            'due_on' => $charge->due_on->toDateString(),
            'overdue' => $charge->status === DuesChargeStatusEnum::Open && $charge->due_on->lt(today()),
            'payment_method' => $charge->payment_method,
            'paid_at' => $charge->paid_at?->toIso8601String(),
        ];
    }
}
