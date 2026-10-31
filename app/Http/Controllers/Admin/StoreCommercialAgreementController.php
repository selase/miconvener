<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCommercialAgreementRequest;
use App\Models\StoreCommercialAgreement;
use App\Models\Tenant;
use App\Services\Marketplace\StoreCommercialTerms;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

final class StoreCommercialAgreementController extends Controller
{
    public function index(Tenant $tenant): View
    {
        $this->authorize('access-superadmin-dashboard');

        $agreements = StoreCommercialAgreement::query()->where('tenant_id', $tenant->id)->orderByDesc('version')->get()->groupBy('offering_scope');

        return view('admin.store-agreements', ['seller' => $tenant,
            'agreements' => $agreements->get(StoreCommercialAgreement::SCOPE_PURCHASE, collect()),
            'reservationAgreements' => $agreements->get(StoreCommercialAgreement::SCOPE_RESERVATION, collect())]);
    }

    public function store(StoreCommercialAgreementRequest $request, Tenant $tenant, StoreCommercialTerms $terms): RedirectResponse
    {
        $scope = (string) $request->validated('offering_scope', StoreCommercialAgreement::SCOPE_PURCHASE);
        $terms->approve($tenant, $request->user(), Arr::except($request->validated(), 'offering_scope'), $scope);

        return back()->with('success', $scope === StoreCommercialAgreement::SCOPE_RESERVATION
            ? 'New venue agreement approved. Existing quotes keep their original terms. Venue payments stay off until they are enabled for this organisation.'
            : 'New commerce agreement approved. Existing quotes keep their original terms. Checkout rollout is unchanged.');
    }
}
