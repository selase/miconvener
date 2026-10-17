<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Giving\ChangeFundStateRequest;
use App\Http\Requests\Tenant\Giving\SaveFundRequest;
use App\Models\GivingFund;
use App\Models\Tenant;
use App\Services\Giving\GivingFunds;
use App\Services\Giving\GivingRollout;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class GivingFundController extends Controller
{
    public function store(SaveFundRequest $request, GivingFunds $funds): JsonResponse
    {
        return response()->json(['fund' => $funds->save($this->tenant(), $request->user(), $request->validated())], 201);
    }

    public function update(SaveFundRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        return response()->json(['fund' => $funds->save($this->tenant(), $request->user(), $request->validated(), $this->find($fund))]);
    }

    public function close(ChangeFundStateRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        $model = $this->find($fund);
        $funds->close($model, $request->user(), $request->validated('reason'));

        return response()->json(['fund' => $model]);
    }

    public function reopen(ChangeFundStateRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        $model = $this->find($fund);
        $funds->reopen($model, $request->user(), $request->validated('reason'));

        return response()->json(['fund' => $model]);
    }

    public function archive(ChangeFundStateRequest $request, string $subdomain, string $fund, GivingFunds $funds): JsonResponse
    {
        $model = $this->find($fund);
        $funds->archive($model, $request->user(), $request->validated('reason'));

        return response()->json(['fund' => $model]);
    }

    private function find(string $id): GivingFund
    {
        $tenant = $this->tenant();

        return GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($id);
    }

    private function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant instanceof Tenant && app(GivingRollout::class)->enabled($tenant), 404);

        return $tenant;
    }
}
