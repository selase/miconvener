<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\GrantAddonRequest;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Services\Billing\TenantAddonService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An organisation's add-ons, and superadmin grants: credits or features
 * given without charge, each with who gave it and why.
 */
final class AddonGrantController extends Controller
{
    public function index(Tenant $tenant): View
    {
        $this->authorize('access-superadmin-dashboard');

        return view('admin.tenants.addons', [
            'tenant' => $tenant,
            'addons' => TenantAddon::query()->where('tenant_id', $tenant->id)->latest()->limit(50)->get(),
            'catalog' => TenantAddonService::CATALOG,
            'hasShop' => $tenant->shop()->exists(),
        ]);
    }

    public function store(GrantAddonRequest $request, Tenant $tenant, TenantAddonService $addons): RedirectResponse
    {
        $key = (string) $request->validated('addon_key');

        if (in_array(TenantAddonService::CATALOG[$key]['addon_type'], TenantAddon::SHOP_TYPES, true) && ! $tenant->shop()->exists()) {
            return back()->withInput()->with('error', "{$tenant->name} has no marketplace business, so a marketplace promotion would do nothing.");
        }

        $addon = $addons->grantAddon($tenant, $key, (int) $request->validated('packs'), $request->user(), (string) $request->validated('reason'));

        return back()->with('success', "Granted {$addon->name} (quantity {$addon->quantity}) to {$tenant->name}. No charge was recorded.");
    }

    public function destroy(Request $request, Tenant $tenant, TenantAddon $addon): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        if ($addon->tenant_id !== $tenant->id || ! isset($addon->meta['granted_by'])) {
            return back()->with('error', 'Only add-ons we granted can be withdrawn here. Paid add-ons are cancelled by the organisation.');
        }

        // Expired rather than cancelled: a cancelled add-on stays usable until
        // its period ends, and a withdrawal should take effect now.
        $addon->update(['status' => TenantAddon::STATUS_EXPIRED, 'period_end' => now()]);

        activity()
            ->causedBy($request->user())
            ->performedOn($tenant)
            ->withProperties(['addon' => $addon->name])
            ->log("Withdrew granted {$addon->name} from {$tenant->name}");

        return back()->with('success', "Withdrew {$addon->name}.");
    }
}
