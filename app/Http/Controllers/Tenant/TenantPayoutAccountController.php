<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TenantPayoutAccountController extends Controller
{
    public function index(string $subdomain): JsonResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->getTenant();

        $accounts = TenantPayoutAccount::where('tenant_id', $tenant->id)->orderByDesc('created_at')->get();

        return response()->json($accounts->map(fn (TenantPayoutAccount $a): array => $this->payload($a))->values());
    }

    public function store(Request $request, string $subdomain, SettlementGateway $settlementGateway): JsonResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->getTenant();

        $validated = $request->validate([
            'type' => ['required', Rule::in([TenantPayoutAccount::TYPE_MOBILE_MONEY, TenantPayoutAccount::TYPE_BANK])],
            'label' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'min:4', 'max:64'],
            'bank_code' => ['required', 'string', 'max:16'],
        ]);

        try {
            $resolved = $settlementGateway->resolveAccount($validated['bank_code'], $validated['account_number']);
        } catch (PaymentFailedException $e) {
            return response()->json(['message' => 'Could not verify this account: '.$e->getMessage()], 422);
        }

        $account = TenantPayoutAccount::create([
            'tenant_id' => $tenant->id,
            'type' => $validated['type'],
            'label' => $validated['label'],
            'account_name' => $validated['account_name'],
            'account_number_encrypted' => $validated['account_number'],
            'bank_code' => $validated['bank_code'],
            'resolved_account_name' => $resolved['account_name'],
            'is_verified' => true,
        ]);

        return response()->json($this->payload($account), 201);
    }

    public function banks(string $subdomain, SettlementGateway $settlementGateway): JsonResponse
    {
        $this->authorize('manage organization settings');

        return response()->json($settlementGateway->listBanks());
    }

    public function destroy(string $subdomain, string $account): JsonResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->getTenant();

        $accountModel = TenantPayoutAccount::where('tenant_id', $tenant->id)->where('id', $account)->firstOrFail();

        $hasActivePayouts = $accountModel->payouts()
            ->whereIn('status', [EventPayout::STATUS_SCHEDULED, EventPayout::STATUS_PROCESSING, EventPayout::STATUS_PAID])
            ->exists();

        if ($hasActivePayouts) {
            return response()->json(['message' => 'Cannot remove this account: it has scheduled, in-flight, or completed payouts. Those records must be preserved.'], 422);
        }

        $accountModel->delete();

        return response()->json(['message' => 'Payout account removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TenantPayoutAccount $account): array
    {
        return [
            'id' => $account->id,
            'type' => $account->type,
            'label' => $account->label,
            'account_name' => $account->account_name,
            'masked_account_number' => $account->maskedAccountNumber(),
            'bank_code' => $account->bank_code,
            'resolved_account_name' => $account->resolved_account_name,
            'is_verified' => $account->is_verified,
        ];
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
