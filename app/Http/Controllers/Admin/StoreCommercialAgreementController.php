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

final class StoreCommercialAgreementController extends Controller
{
    public function index(Tenant $tenant): View
    {
        $this->authorize('access-superadmin-dashboard');

        return view('admin.store-agreements', ['seller' => $tenant,
            'agreements' => StoreCommercialAgreement::query()->where('tenant_id', $tenant->id)->orderByDesc('version')->get()]);
    }

    public function store(StoreCommercialAgreementRequest $request, Tenant $tenant, StoreCommercialTerms $terms): RedirectResponse
    {
        $terms->approve($tenant, $request->user(), $request->validated());

        return back()->with('success', 'New commerce agreement approved. Existing quotes keep their original terms. Checkout rollout is unchanged.');
    }
}
