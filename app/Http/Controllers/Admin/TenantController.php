<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enum\TenantStatusEnum;
use App\Enum\UsageMetric;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\CreateTenantRequest;
use App\Libraries\Helper;
use App\Models\Tenant;
use App\Models\UsageRollup;
use App\Providers\RouteServiceProvider;
use App\Services\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

final class TenantController extends Controller
{
    public function __construct(private readonly TenantProvisioner $provisioner) {}

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Factory|View
    {
        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
        ];

        $tenants = Tenant::query()->select('id', 'uuid', 'name', 'email', 'status', 'phone_number', 'slug', 'created_at')
            ->latest()
            ->paginate(25);

        return view('admin.tenants.index', ['breadcrumbs' => $breadcrumbs, 'tenants' => $tenants]);
    }

    public function getAllTenants(Request $request): JsonResponse
    {
        $this->authorize('read user');

        $columns = [
            0 => 'uuid',
            1 => 'name',
            2 => 'phone_number',
            3 => 'slug',
            4 => 'status',
            5 => 'created_at',
            6 => 'action',
        ];

        $totalData = Tenant::query()->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $tenants = Tenant::query()
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $tenants = Tenant::query()
                ->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('created_at', 'like', "%{$search}%");
                })
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = Tenant::query()
                ->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('created_at', 'like', "%{$search}%");
                })
                ->count();
        }

        $data = [];

        if ($tenants->isNotEmpty()) {
            foreach ($tenants as $tenant) {
                $action = '';

                if (Auth::user()->can('read tenant')) {
                    $action .= '<a href="'.route('tenants.show', $tenant->uuid).'"  class="btn btn-icon btn-active-light-primary w-30px h-30px me-3"
                                    data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="View Details">
                                    <i class="fas fa-eye fs-5"></i>
                                </a>';
                }

                if (Auth::user()->hasRole('Superadmin')) {
                    $action .= '<a href="'.route('tenants.change', $tenant->id).'" class="btn btn-icon btn-active-light-success w-30px h-30px me-3"
                                    data-toggle="tooltip" data-placement="top" title="Switch to Tenant">
                                    <i class="fas fa-random fs-5"></i>
                                </a>';
                }
                if (Auth::user()->can('update tenant')) {
                    $action .= '<a href="'.route('tenants.edit', $tenant->uuid).'"  class="btn btn-icon btn-active-light-info w-30px h-30px"
                                    data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="Edit Tenant">
                                    <i class="fas fa-edit fs-5"></i>
                                </a>';
                }
                if (Auth::user()->can('delete tenant')) {
                    $action .= '<a href="javascript:void(0)" onclick="deleteData(\''.$tenant->uuid.'\', \'/tenants/\')" class="btn btn-icon btn-active-light-danger w-30px h-30px"
                                    data-kt-permissions-table-filter="delete_row" data-toggle="tooltip" data-placement="top"  title="Delete Tenant">
                                    <i class="fas fa-trash fs-4"></i>
                                </a>';
                }

                $tenant->logo
                    ? $profile = Storage::url($tenant->logo)
                    : $profile = $tenant->gravatar;

                $tenantProfile = '<div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                                <a href="javascript:void(0)">
                                    <div class="symbol-label">
                                        <img src="'.$profile.'"
                                            alt="'.$tenant->name.'" class="w-100">
                                    </div>
                                </a>
                            </div>

                            <div class="d-flex flex-column">
                                <a href="javascript:void(0)"
                                    class="text-gray-800 text-hover-primary mb-1">'.$tenant->name.'</a>
                                <span>'.$tenant->email.'</span>
                            </div>';

                $nestedData['uuid'] = $tenant->uuid;
                $nestedData['phone'] = $tenant->phone_number;
                $nestedData['subdomain'] = $tenant->slug;
                $nestedData['status'] = $tenant->status?->label();
                $nestedData['created_at'] = $tenant->created_at->format('Y-m-d H:i:s');
                $nestedData['name'] = $tenantProfile;
                $nestedData['action'] = $action;

                $data[] = $nestedData;
            }
        }

        $json_data = [
            'draw' => (int) ($request->input('draw')),
            'recordsTotal' => (int) $totalData,
            'recordsFiltered' => (int) $totalFiltered,
            'data' => $data,
        ];

        return response()->json($json_data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $this->authorize('create tenant');

        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['link' => route('tenants.index'), 'name' => __('Tenants')],
        ];

        $statuses = TenantStatusEnum::cases();
        $packages = \App\Models\Package::where('is_active', true)->get();
        $availableModels = array_keys(config('llm.models', []));

        return view('admin.tenants.create', [
            'breadcrumbs' => $breadcrumbs,
            'statuses' => $statuses,
            'packages' => $packages,
            'availableModels' => $availableModels,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateTenantRequest $request): RedirectResponse
    {
        $validatedData = $request->validated();

        $tenant = new Tenant();

        $tenant->name = $validatedData['name'];
        $tenant->phone_number = $validatedData['phone_number'];
        $tenant->email = $validatedData['email'];
        $tenant->country = $validatedData['country'];
        $tenant->city = $validatedData['city'];
        $tenant->state = $validatedData['state_or_region'];
        $tenant->zipcode = $validatedData['zipcode'];
        $tenant->status = $validatedData['status'];
        $tenant->address = $validatedData['address'];
        $tenant->slug = mb_strtolower((string) $validatedData['subdomain']);
        $tenant->package_id = $validatedData['package_id'] ?? null;
        $tenant->isolation_mode = $validatedData['isolation_mode'] ?? 'shared';
        $tenant->db_driver = $validatedData['db_driver'] ?? 'pgsql';
        $tenant->db_secret_ref = $validatedData['db_secret_ref'] ?? null;
        $tenant->custom_llm_limit = $validatedData['custom_llm_limit'] ?? null;
        $tenant->llm_models_whitelist = $validatedData['llm_models_whitelist'] ?? null;

        if (! empty($validatedData['allowed_ips'])) {
            $tenant->allowed_ips = array_map(trim(...), explode(',', (string) $validatedData['allowed_ips']));
        }

        if ($request->hasFile('logo')) {
            config('app.env') === 'production'
                ? $disk = 's3'
                : $disk = 'public';

            $logoPath = Helper::processUploadedFile($request, 'logo', 'logo', 'tenant/logo', $disk);

            $tenant->logo = $logoPath;
        }

        $tenant->save();

        // Handle LLM BYOK Feature
        $this->updateFeature($tenant, 'llm_byok', (bool) $request->input('llm_byok'));

        $this->provisioner->provision($tenant);

        return to_route('tenants.index')->with([
            'status' => 'success',
            'message' => __('locale.messages.created', ['name' => 'Tenant']),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id): View
    {
        $this->authorize('read tenant');

        $tenant = Tenant::findByUuid($id);

        $days = $request->integer('days', 7);
        $startDateParam = $request->input('start_date');
        $endDateParam = $request->input('end_date');

        if ($startDateParam && $endDateParam) {
            $startDate = Carbon::parse($startDateParam)->startOfDay();
            $endDate = Carbon::parse($endDateParam)->endOfDay();
        } else {
            $startDate = Carbon::now()->subDays($days)->startOfDay();
            $endDate = Carbon::now()->endOfDay();
        }

        $usageTrendData = UsageRollup::query()
            ->where('tenant_id', $tenant->id)
            ->where('metric', UsageMetric::REQUEST_COUNT)
            ->where('period', 'hour')
            ->whereBetween('period_start', [$startDate, $endDate])
            ->orderBy('period_start')
            ->get();

        $storageTrendData = UsageRollup::query()
            ->where('tenant_id', $tenant->id)
            ->where('metric', UsageMetric::STORAGE_BYTES)
            ->where('period', 'day')
            ->whereBetween('period_start', [$startDate, $endDate])
            ->orderBy('period_start')
            ->get();

        $dbTrendData = UsageRollup::query()
            ->where('tenant_id', $tenant->id)
            ->where('metric', UsageMetric::DB_BYTES)
            ->where('period', 'day')
            ->whereBetween('period_start', [$startDate, $endDate])
            ->orderBy('period_start')
            ->get();

        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['link' => route('admin.billing.analytics.usage'), 'name' => __('Usage Analytics')],
            ['name' => 'Drill-down: '.$tenant->name],
        ];

        return view('admin.tenants.show', [
            'breadcrumbs' => $breadcrumbs,
            'tenant' => $tenant,
            'days' => $days,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'usageTrend' => [
                'labels' => $usageTrendData->pluck('period_start')->map(fn ($d) => $d->format('M d H:i'))->toArray(),
                'data' => $usageTrendData->pluck('value')->toArray(),
            ],
            'storageTrend' => [
                'labels' => $storageTrendData->pluck('period_start')->map(fn ($d) => $d->format('M d'))->toArray(),
                'data' => $storageTrendData->pluck('value')->map(fn ($v): float => round($v / 1024 / 1024, 2))->toArray(), // MB for individual tenant usually fits
            ],
            'dbTrend' => [
                'labels' => $dbTrendData->pluck('period_start')->map(fn ($d) => $d->format('M d'))->toArray(),
                'data' => $dbTrendData->pluck('value')->map(fn ($v): float => round($v / 1024 / 1024, 2))->toArray(), // MB
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): View
    {
        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['link' => route('tenants.index'), 'name' => __('Tenants')],
        ];

        $statuses = TenantStatusEnum::cases();
        $tenant = Tenant::with(['usagePrices'])->where('uuid', $id)->firstOrFail();
        $packages = \App\Models\Package::where('is_active', true)->get();
        $availableModels = array_keys(config('llm.models', []));
        $metrics = UsageMetric::cases();

        return view('admin.tenants.edit', [
            'breadcrumbs' => $breadcrumbs,
            'statuses' => $statuses,
            'tenant' => $tenant,
            'packages' => $packages,
            'availableModels' => $availableModels,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $tenant = Tenant::findByUuid($id);

        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:landlord.tenants,email,'.$tenant->id,
            'package_id' => 'nullable|exists:packages,id',
            'markup_percentage' => 'nullable|numeric|min:0',
            'usage_prices' => 'nullable|array',
            'usage_prices.*.unit_price' => 'nullable|numeric|min:0',
            'usage_prices.*.unit_quantity' => 'nullable|numeric|min:0.0001',
        ]);

        $tenant->name = $request->input('name');
        $tenant->email = $request->input('email');
        $tenant->phone_number = $request->input('phone_number');
        $tenant->address = $request->input('address');
        $tenant->country = $request->input('country');
        $tenant->city = $request->input('city');
        $tenant->state = $request->input('state_or_region');
        $tenant->zipcode = $request->input('zipcode');
        $tenant->status = $request->input('status');
        $tenant->package_id = $request->input('package_id');
        $tenant->slug = $request->input('subdomain');
        $tenant->isolation_mode = $request->input('isolation_mode');
        $tenant->db_driver = $request->input('db_driver');
        $tenant->db_secret_ref = $request->input('db_secret_ref');
        $tenant->llm_models_whitelist = $request->input('llm_models_whitelist') ?? [];
        $tenant->custom_llm_limit = $request->input('custom_llm_limit');
        $tenant->allowed_ips = array_map(trim(...), explode(',', $request->input('allowed_ips') ?? ''));
        $tenant->markup_percentage = $request->input('markup_percentage', 0);

        if ($request->hasFile('logo')) {
            config('app.env') === 'production'
                ? $disk = 's3'
                : $disk = 'public';

            if ($tenant->logo) {
                Helper::deleteFile($tenant->logo, $disk);
            }

            $logoPath = Helper::processUploadedFile($request, 'logo', 'logo', 'tenant/logo', $disk);

            $tenant->logo = $logoPath;
        }

        $tenant->save();

        // Handle LLM BYOK Feature
        $this->updateFeature($tenant, 'llm_byok', (bool) $request->input('llm_byok'));

        // Handle Usage Pricing Overrides
        $this->syncUsagePrices($tenant, $request->input('usage_prices', []));

        $this->provisioner->provision($tenant);

        return to_route('tenants.index')->with([
            'status' => 'success',
            'message' => __('locale.messages.updated', ['name' => 'Tenant']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $tenant = Tenant::findByUuid($id);

        if ($tenant->logo) {
            config('app.env') === 'production'
                ? $disk = 's3'
                : $disk = 'public';

            Helper::deleteFile($tenant->logo, $disk);
        }

        $tenant->delete();

        return response()->json([
            'status' => __('locale.messages.deleted', ['name' => 'Tenant']),
        ]);
    }

    public function changeTenant($tenantId)
    {
        $user = auth()->user();

        if ($user->hasRole('Superadmin')) {
            $tenant = Tenant::find($tenantId);
        } else {
            // Check if tenant exist
            $tenant = $user->tenants()->find($tenantId);
        }

        if (! $tenant) {
            abort(404);
        }

        // Change tenant
        $user->update(['tenant_id' => $tenantId]);

        if ($tenant && $tenant->slug !== null) {
            $tenantDomain = str_replace('://', '://'.$tenant->slug.'.', config('app.url'));

            session()->put('tenant_id', $tenant->id);

            return redirect()->intended($tenantDomain.RouteServiceProvider::HOME);
        }

        return back()->with([
            'status' => 'error',
            'message' => 'This action is not authorized',
        ]);
    }

    public function resetTenant()
    {
        $user = auth()->user();

        // Use the connection-agnostic superadmin check
        if (! $user->isGlobalSuperAdmin()) {
            abort(403, 'Unauthorized: user is not a global superadmin');
        }

        // Return to landlord context
        $user->update(['tenant_id' => null]);
        session()->forget('tenant_id');
        session()->forget('active_tenant_id');

        // Redirect to the central application URL explicitly
        $target = config('app.url');
        if (! str_ends_with((string) $target, '/')) {
            $target .= '/';
        }
        $target .= 'dashboard';

        return redirect()->to($target);
    }

    private function syncUsagePrices(?\App\Models\Tenant $target, array $prices): void
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

    private function updateFeature(Tenant $tenant, string $featureKey, bool $enabled): void
    {
        $tenant->features()->updateOrCreate(
            ['feature_key' => $featureKey],
            [
                'enabled' => $enabled,
                'meta' => ['source' => 'admin_override'],
            ]
        );
        \Illuminate\Support\Facades\Cache::forget("tenant_{$tenant->id}_feature_{$featureKey}");
    }
}
