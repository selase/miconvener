<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class PackageController extends Controller
{
    public function __construct()
    {
        $this->middleware(['can:access-superadmin-dashboard']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $columns = [
                0 => 'name',
                1 => 'price',
                2 => 'interval',
                3 => 'is_active',
                4 => 'created_at',
                5 => 'action',
            ];

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')] ?? 'created_at';
            $dir = $request->input('order.0.dir') ?? 'desc';
            $searchValue = $request->input('search.value');

            $query = \App\Models\Package::query();

            $totalData = $query->count();
            $totalFiltered = $totalData;

            if (! empty($searchValue)) {
                $query->where(function ($q) use ($searchValue): void {
                    $q->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('slug', 'like', "%{$searchValue}%");
                });
                $totalFiltered = $query->count();
            }

            $packages = $query->when($limit !== null && $limit !== -1, fn ($q) => $q->limit($limit))
                ->when($start !== null, fn ($q) => $q->offset($start))
                ->orderBy($order, $dir)
                ->get();

            $data = [];
            foreach ($packages as $package) {
                // Actions
                $action = '<a href="'.route('packages.edit', $package->id).'" class="btn btn-icon btn-active-light-primary w-30px h-30px" title="Edit">
                                <i class="fas fa-edit fs-4"></i>
                           </a>
                           <a href="javascript:void(0)" onclick="deletePackage(\''.$package->id.'\')" class="btn btn-icon btn-active-light-danger w-30px h-30px" title="Delete">
                                <i class="fas fa-trash fs-4"></i>
                           </a>';

                $statusBadge = $package->is_active
                    ? '<span class="badge badge-light-success">Active</span>'
                    : '<span class="badge badge-light-danger">Inactive</span>';

                $nestedData['name'] = $package->name;
                $nestedData['price'] = number_format((float) $package->price, 2);
                $nestedData['interval'] = ucfirst((string) $package->interval);
                $nestedData['is_active'] = $statusBadge;
                $nestedData['created_at'] = $package->created_at->format('Y-m-d H:i:s');
                $nestedData['action'] = $action;

                $data[] = $nestedData;
            }

            return response()->json([
                'draw' => (int) $request->input('draw'),
                'recordsTotal' => (int) $totalData,
                'recordsFiltered' => (int) $totalFiltered,
                'data' => $data,
            ]);
        }

        return view('admin.packages.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $features = \App\Models\Feature::all();
        $metrics = \App\Enum\UsageMetric::cases();

        return view('admin.packages.create', ['features' => $features, 'metrics' => $metrics]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:packages,slug',
            'price' => 'required|numeric|min:0',
            'interval' => 'required|in:month,year',
            'billing_model' => 'required|in:flat_rate,per_seat',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'markup_percentage' => 'nullable|numeric|min:0',
            'features' => 'nullable|array',
            'features.*.enabled' => 'nullable|string', // Checkbox 'on'
            'features.*.value' => 'nullable|string',
            'usage_prices' => 'nullable|array',
            'usage_prices.*.unit_price' => 'nullable|numeric|min:0',
            'usage_prices.*.unit_quantity' => 'nullable|numeric|min:0.0001',
        ]);

        $package = \App\Models\Package::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'price' => $validated['price'],
            'interval' => $validated['interval'],
            'billing_model' => $validated['billing_model'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->has('is_active'),
            'markup_percentage' => $validated['markup_percentage'] ?? 0,
        ]);

        // Sync Features
        if (! empty($request->input('features'))) {
            $syncData = [];
            foreach ($request->input('features') as $featureId => $data) {
                if (isset($data['enabled'])) {
                    $syncData[$featureId] = ['value' => $data['value'] ?? null];
                }
            }
            $package->features()->sync($syncData);
        }

        // Sync Usage Prices
        $this->syncUsagePrices($package, $request->input('usage_prices', []));

        return redirect()->route('packages.index')->with('success', 'Package created successfully');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $package = \App\Models\Package::with(['features', 'usagePrices'])->findOrFail($id);
        $features = \App\Models\Feature::all();
        $metrics = \App\Enum\UsageMetric::cases();

        return view('admin.packages.edit', ['package' => $package, 'features' => $features, 'metrics' => $metrics]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $package = \App\Models\Package::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:packages,slug,'.$package->id,
            'price' => 'required|numeric|min:0',
            'interval' => 'required|in:month,year',
            'billing_model' => 'required|in:flat_rate,per_seat',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'markup_percentage' => 'nullable|numeric|min:0',
            'features' => 'nullable|array',
            'features.*.enabled' => 'nullable|string',
            'features.*.value' => 'nullable|string',
            'usage_prices' => 'nullable|array',
            'usage_prices.*.unit_price' => 'nullable|numeric|min:0',
            'usage_prices.*.unit_quantity' => 'nullable|numeric|min:0.0001',
        ]);

        $package->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'price' => $validated['price'],
            'interval' => $validated['interval'],
            'billing_model' => $validated['billing_model'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->has('is_active'),
            'markup_percentage' => $validated['markup_percentage'] ?? 0,
        ]);

        // Sync Features
        $syncData = [];
        if (! empty($request->input('features'))) {
            foreach ($request->input('features') as $featureId => $data) {
                if (isset($data['enabled'])) {
                    $syncData[$featureId] = ['value' => $data['value'] ?? null];
                }
            }
        }
        $package->features()->sync($syncData);

        // Sync Usage Prices
        $this->syncUsagePrices($package, $request->input('usage_prices', []));

        return redirect()->route('packages.index')->with('success', 'Package updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $package = \App\Models\Package::findOrFail($id);
        $package->usagePrices()->delete();
        $package->delete();

        return response()->json(['message' => 'Package deleted successfully']);
    }

    private function syncUsagePrices($target, array $prices): void
    {
        foreach ($prices as $metricValue => $data) {
            if (empty($data['unit_price']) && empty($data['unit_quantity'])) {
                $target->usagePrices()->where('metric', $metricValue)->delete();

                continue;
            }

            $target->usagePrices()->updateOrCreate(
                ['metric' => $metricValue],
                [
                    'unit_price' => $data['unit_price'] ?? 0,
                    'unit_quantity' => $data['unit_quantity'] ?? 1,
                    'currency' => 'USD',
                ]
            );
        }
    }
}
