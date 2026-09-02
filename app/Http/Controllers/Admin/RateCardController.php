<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enum\UsageMetric;
use App\Http\Controllers\Controller;
use App\Models\Tax;
use App\Models\UsagePrice;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class RateCardController extends Controller
{
    public function index(): View
    {
        $this->authorize('access-superadmin-dashboard');

        $globalPrices = UsagePrice::whereNull('target_type')->get();
        $metrics = UsageMetric::cases();
        $taxes = Tax::orderBy('priority')->get();

        return view('admin.billing.rate_cards.index', [
            'globalPrices' => $globalPrices,
            'metrics' => $metrics,
            'taxes' => $taxes,
            'breadcrumbs' => [
                ['link' => route('dashboard'), 'name' => __('Home')],
                ['name' => 'Rate Cards & Taxes'],
            ],
        ]);
    }

    public function updatePrices(Request $request)
    {
        $this->authorize('access-superadmin-dashboard');

        $request->validate([
            'usage_prices' => 'nullable|array',
            'usage_prices.*.unit_price' => 'nullable|numeric|min:0',
            'usage_prices.*.unit_quantity' => 'nullable|numeric|min:0.0001',
        ]);

        foreach ($request->input('usage_prices', []) as $metricValue => $data) {
            if (empty($data['unit_price']) && empty($data['unit_quantity'])) {
                UsagePrice::whereNull('target_type')->where('metric', $metricValue)->delete();

                continue;
            }

            UsagePrice::updateOrCreate(
                ['target_type' => null, 'target_id' => null, 'metric' => $metricValue],
                [
                    'unit_price' => $data['unit_price'] ?? 0,
                    'unit_quantity' => $data['unit_quantity'] ?? 1,
                    'currency' => 'USD',
                ]
            );
        }

        return back()->with('success', 'Global unit prices updated successfully');
    }

    public function storeTax(Request $request)
    {
        $this->authorize('access-superadmin-dashboard');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|min:0',
            'priority' => 'required|integer|min:1',
            'is_compound' => 'boolean',
        ]);

        Tax::create([
            'name' => $validated['name'],
            'rate' => $validated['rate'],
            'priority' => $validated['priority'],
            'is_compound' => $request->has('is_compound'),
            'is_active' => true,
        ]);

        return back()->with('success', 'Tax rule created successfully');
    }

    public function updateTax(Request $request, Tax $tax)
    {
        $this->authorize('access-superadmin-dashboard');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|min:0',
            'priority' => 'required|integer|min:1',
            'is_compound' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $tax->update([
            'name' => $validated['name'],
            'rate' => $validated['rate'],
            'priority' => $validated['priority'],
            'is_compound' => $request->has('is_compound'),
            'is_active' => $request->has('is_active'),
        ]);

        return back()->with('success', 'Tax rule updated successfully');
    }

    public function destroyTax(Tax $tax)
    {
        $this->authorize('access-superadmin-dashboard');

        $tax->delete();

        return back()->with('success', 'Tax rule deleted successfully');
    }
}
