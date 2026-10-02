<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MarketplaceVerificationController extends Controller
{
    public function index(Request $request): Factory|View
    {
        $this->authorize('access-superadmin-dashboard');

        $status = $request->query('status', 'all');

        $shops = Shop::query()
            ->with(['tenant', 'verifiedBy'])
            ->withCount('listings')
            ->when($status !== 'all', function ($q) use ($status): void {
                $q->where('verification_status', $status);
            })
            ->orderByRaw("CASE WHEN verification_status = 'pending' THEN 0 WHEN verification_status = 'unverified' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.marketplace.verifications.index', [
            'shops' => $shops,
            'currentStatus' => $status,
        ]);
    }

    public function show(Shop $shop): Factory|View
    {
        $this->authorize('access-superadmin-dashboard');

        $shop->load(['tenant', 'listings.primaryMedia', 'verifiedBy']);

        return view('admin.marketplace.verifications.show', [
            'shop' => $shop,
        ]);
    }

    public function approve(Request $request, Shop $shop): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $shop->update([
            'verification_status' => Shop::VERIFICATION_VERIFIED,
            'verified_at' => now(),
            'verified_by_user_id' => $request->user()?->id,
            'rejection_reason' => null,
        ]);

        return redirect()->route('admin.marketplace-verifications.index')
            ->with('success', "Merchant '{$shop->name}' has been approved and verified.");
    }

    public function reject(Request $request, Shop $shop): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $shop->update([
            'verification_status' => Shop::VERIFICATION_REJECTED,
            'verified_at' => null,
            'verified_by_user_id' => $request->user()?->id,
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        return redirect()->route('admin.marketplace-verifications.index')
            ->with('success', "Merchant '{$shop->name}' verification was rejected.");
    }
}
