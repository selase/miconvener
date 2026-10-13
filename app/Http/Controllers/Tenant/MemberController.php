<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enum\MemberStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Dues\StoreMemberRequest;
use App\Http\Requests\Tenant\Dues\UpdateMemberRequest;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's members and their dues. The member payload never
 * includes the pay token; the payment link is exposed separately.
 */
final class MemberController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): Response
    {
        $this->authorize('manage members');
        $tenant = $this->getTenant();

        $members = Member::query()
            ->where('tenant_id', $tenant->id)
            ->with('duesClass')
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
                'class_name' => $member->duesClass->name,
                'effective_amount' => $member->effectiveAmount(),
                'currency' => $member->duesClass->currency,
                'amount_override' => $member->amount_override,
                'open_total' => (int) $member->getAttribute('open_total'),
                'overdue' => (bool) $member->getAttribute('overdue'),
            ]);

        return Inertia::render('Tenant/Dues/Members', [
            'members' => $members,
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
        $model->update($request->validated());

        return response()->json(['id' => $model->id]);
    }

    /**
     * Marks the member as having left. Their charges are kept: open ones can
     * still be waived, voided or recorded.
     */
    public function exit(string $subdomain, string $member): JsonResponse
    {
        $this->authorize('manage members');
        $model = $this->findMember($member);
        if ($model->status !== MemberStatusEnum::Exited) {
            $model->update(['status' => MemberStatusEnum::Exited, 'exited_on' => today()]);
        }

        return response()->json(['id' => $model->id, 'status' => MemberStatusEnum::Exited->value]);
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
}
