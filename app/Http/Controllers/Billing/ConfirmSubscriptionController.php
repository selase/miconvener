<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ConfirmSubscriptionController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $slug = $request->query('plan', session('pending_plan', ''));
        $interval = in_array($request->query('interval'), ['month', 'year']) ? $request->query('interval') : 'month';

        $package = Package::where('slug', $slug)->first();

        if (! $package || $package->isFree()) {
            return redirect()->route('register');
        }

        return view('billing.confirm-subscription', ['package' => $package, 'interval' => $interval]);
    }
}
