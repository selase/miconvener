<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
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

    public function store(Request $request, string $subdomain): JsonResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->getTenant();

        $validated = $request->validate([
            'type' => ['required', Rule::in([TenantPayoutAccount::TYPE_MOBILE_MONEY, TenantPayoutAccount::TYPE_BANK])],
            'label' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'min:4', 'max:64'],
        ]);

        $account = TenantPayoutAccount::create([
            'tenant_id' => $tenant->id,
            'type' => $validated['type'],
            'label' => $validated['label'],
            'account_name' => $validated['account_name'],
            'account_number_encrypted' => $validated['account_number'],
        ]);

        return response()->json($this->payload($account), 201);
    }

    public function destroy(string $subdomain, string $account): JsonResponse
    {
        $this->authorize('manage organization settings');
        $tenant = $this->getTenant();

        TenantPayoutAccount::where('tenant_id', $tenant->id)->where('id', $account)->firstOrFail()->delete();

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
