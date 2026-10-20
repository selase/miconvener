<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePricingRegionRequest;
use App\Models\Tenant;
use App\Services\Pricing\GhanaEligibility;
use Illuminate\Http\RedirectResponse;

/**
 * Platform staff granting or removing an organisation's Ghana price when the
 * evidence is not enough on its own. Same permission as ticket terms.
 */
final class PricingRegionController extends Controller
{
    public function update(UpdatePricingRegionRequest $request, Tenant $tenant, GhanaEligibility $eligibility): RedirectResponse
    {
        $reason = (string) $request->validated('reason');

        if ($request->validated('action') === UpdatePricingRegionRequest::REMOVE) {
            $eligibility->revoke($tenant, $request->user(), $reason);

            return back()->with('success', 'The Ghana price was removed. This organisation pays the USD price from now on.');
        }

        if (! $eligibility->grant($tenant, GhanaEligibility::SOURCE_SUPERADMIN, ['reason' => $reason], $request->user())) {
            return back()->with('info', 'This organisation already has the Ghana price.');
        }

        return back()->with('success', 'The Ghana price was granted.');
    }
}
