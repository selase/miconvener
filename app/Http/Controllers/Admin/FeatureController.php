<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class FeatureController extends Controller
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
                1 => 'slug',
                2 => 'type',
                3 => 'created_at',
                4 => 'action',
            ];

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')] ?? 'created_at';
            $dir = $request->input('order.0.dir') ?? 'desc';
            $searchValue = $request->input('search.value');

            $query = \App\Models\Feature::query();

            $totalData = $query->count();
            $totalFiltered = $totalData;

            if (! empty($searchValue)) {
                $query->where(function ($q) use ($searchValue): void {
                    $q->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('slug', 'like', "%{$searchValue}%");
                });
                $totalFiltered = $query->count();
            }

            $features = $query->when($limit !== null && $limit !== -1, fn ($q) => $q->limit($limit))
                ->when($start !== null, fn ($q) => $q->offset($start))
                ->orderBy($order, $dir)
                ->get();

            $data = [];
            foreach ($features as $feature) {
                // Actions
                $action = '<a href="'.route('features.edit', $feature->id).'" class="btn btn-icon btn-active-light-primary w-30px h-30px" title="Edit">
                                <i class="fas fa-edit fs-4"></i>
                           </a>
                           <a href="javascript:void(0)" onclick="deleteFeature(\''.$feature->id.'\')" class="btn btn-icon btn-active-light-danger w-30px h-30px" title="Delete">
                                <i class="fas fa-trash fs-4"></i>
                           </a>';

                // Badges for Type
                $typeBadge = match ($feature->type) {
                    'boolean' => '<span class="badge badge-light-success">Boolean</span>',
                    'limit' => '<span class="badge badge-light-warning">Limit</span>',
                    'metered' => '<span class="badge badge-light-info">Metered</span>',
                    default => '<span class="badge badge-light">Unknown</span>',
                };

                $nestedData['name'] = $feature->name;
                $nestedData['slug'] = $feature->slug;
                $nestedData['type'] = $typeBadge;
                $nestedData['created_at'] = $feature->created_at->format('Y-m-d H:i:s');
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

        return view('admin.features.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $permissions = \App\Models\Permission::all();

        return view('admin.features.create', ['permissions' => $permissions]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:features,slug',
            'type' => 'required|in:boolean,limit,metered',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $feature = \App\Models\Feature::create(\Illuminate\Support\Arr::except($validated, ['permissions']));

        if (! empty($validated['permissions'])) {
            $feature->permissions()->sync($validated['permissions']);
        }

        return redirect()->route('features.index')->with('success', 'Feature created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): void
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
    {
        $feature = \App\Models\Feature::with('permissions')->findOrFail($id);
        $permissions = \App\Models\Permission::all();

        return view('admin.features.edit', ['feature' => $feature, 'permissions' => $permissions]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $feature = \App\Models\Feature::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:features,slug,'.$feature->id,
            'type' => 'required|in:boolean,limit,metered',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $feature->update(\Illuminate\Support\Arr::except($validated, ['permissions']));

        if (isset($validated['permissions'])) {
            $feature->permissions()->sync($validated['permissions']);
        } else {
            $feature->permissions()->detach();
        }

        return redirect()->route('features.index')->with('success', 'Feature updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $feature = \App\Models\Feature::findOrFail($id);
        $feature->delete();

        return response()->json(['message' => 'Feature deleted successfully']);
    }
}
