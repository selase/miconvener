<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Dues\DuesChargeNoteRequest;
use App\Http\Requests\Tenant\Dues\RecordOfflineDuesPaymentRequest;
use App\Http\Requests\Tenant\Dues\StoreDuesChargeRequest;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Dues\DuesCharges;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * Raising a member's charge and settling it by hand. A rule broken by the
 * service (an exited member, a charge that is no longer open) comes back as
 * a 422 with its message.
 */
final class DuesChargeController extends Controller
{
    public function store(StoreDuesChargeRequest $request, DuesCharges $duesCharges): JsonResponse
    {
        $member = Member::query()
            ->where('tenant_id', $this->getTenant()->id)
            ->with('membershipType')
            ->findOrFail($request->validated('member_id'));

        return $this->guard(function () use ($duesCharges, $member, $request): JsonResponse {
            $charge = $duesCharges->createFor($member, CarbonImmutable::parse((string) $request->validated('period_start')));

            return response()->json($this->payload($charge), $charge->wasRecentlyCreated ? 201 : 200);
        });
    }

    public function waive(DuesChargeNoteRequest $request, string $subdomain, string $charge, DuesCharges $duesCharges): JsonResponse
    {
        $model = $this->findCharge($charge);

        return $this->guard(fn (): JsonResponse => response()->json($this->payload($duesCharges->waive($model, $request->validated('note')))));
    }

    public function void(DuesChargeNoteRequest $request, string $subdomain, string $charge, DuesCharges $duesCharges): JsonResponse
    {
        $model = $this->findCharge($charge);

        return $this->guard(fn (): JsonResponse => response()->json($this->payload($duesCharges->void($model, $request->validated('note')))));
    }

    public function recordOffline(RecordOfflineDuesPaymentRequest $request, string $subdomain, string $charge, DuesCharges $duesCharges): JsonResponse
    {
        $model = $this->findCharge($charge);

        return $this->guard(fn (): JsonResponse => response()->json($this->payload(
            $duesCharges->recordOffline($model, (int) $request->user()?->id, $request->validated('note')),
        )));
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function guard(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DuesCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'member_id' => $charge->member_id,
            'status' => $charge->status->value,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
            'period_start' => $charge->period_start->toDateString(),
            'period_end' => $charge->period_end->toDateString(),
            'due_on' => $charge->due_on->toDateString(),
        ];
    }

    private function findCharge(string $id): DuesCharge
    {
        $this->authorize('manage members');

        return DuesCharge::query()->where('tenant_id', $this->getTenant()->id)->findOrFail($id);
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
