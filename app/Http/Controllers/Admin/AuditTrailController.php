<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\UserLoginHistory;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

final class AuditTrailController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function activityLogIndex(Request $request): View
    {
        $this->authorize('read audit-trail');

        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['name' => __('locale.menu.audit_trail')],
        ];

        return view('admin.audit-trail.index', ['breadcrumbs' => $breadcrumbs]);
    }

    public function getAllActivityLogs(Request $request): JsonResponse
    {
        $this->authorize('read audit-trail');

        $columns = [
            0 => 'id',
            1 => 'log_name',
            2 => 'description',
            3 => 'subject_type',
            4 => 'causer',
            5 => 'created_at',
            6 => 'properties',
        ];

        $totalData = Activity::query()->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $activityLogs = Activity::query()
                ->with(['causer'])
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $activityLogs = Activity::query()
                ->with(['causer'])
                ->where(function ($query) use ($search): void {
                    $query->where('log_name', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('properties', 'like', "%{$search}%")
                        ->orWhere('created_at', 'like', "%{$search}%");
                })
                ->orWhereHas('causer', function ($query) use ($search): void {
                    $query->where(function ($builder) use ($search): void {
                        $builder->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
                })
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = Activity::query()
                ->with(['causer'])
                ->where(function ($query) use ($search): void {
                    $query->where('log_name', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('properties', 'like', "%{$search}%")
                        ->orWhere('created_at', 'like', "%{$search}%");
                })
                ->orWhereHas('causer', function ($query) use ($search): void {
                    $query->where(function ($builder) use ($search): void {
                        $builder->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
                })
                ->count();
        }

        $data = [];

        if ($activityLogs->isNotEmpty()) {
            foreach ($activityLogs as $log) {
                $nestedData['id'] = $log->id;
                $nestedData['location'] = $log->log_name;
                $nestedData['event'] = $log->description;
                $nestedData['subject_type'] = $log->subject_type;
                $nestedData['causer'] = $log->causer?->displayName();
                $nestedData['properties'] = '<textarea rows="5" disabled>'.$log->properties.'</textarea>';
                $nestedData['created_at'] = $log->created_at->format('Y-m-d H:i:s');

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

    public function loginHistoryIndex(): Factory|\Illuminate\Contracts\View\View
    {
        $this->authorize('read audit-trail');

        $loginHistory = UserLoginHistory::query()->latest()->get();

        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['name' => __('locale.menu.audit_trail')],
        ];

        $loggedInBrowserChartForCurrentMonth = Helper::getLoggedInBrowserCountForCurrentMonth();
        $loggedInLocationChartForCurrentMonth = Helper::getLoggedInLocationCountForCurrentMonth();
        $loggedInPlatformChartForCurrentMonth = Helper::getLoggedInPlatformCountForCurrentMonth();
        $loggedInClientDeviceChartForCurrentMonth = Helper::getLoggedInClientDeviceCountForCurrentMonth();

        return view('admin.audit-trail.login-history', ['loginHistory' => $loginHistory, 'breadcrumbs' => $breadcrumbs, 'loggedInBrowserChartForCurrentMonth' => $loggedInBrowserChartForCurrentMonth, 'loggedInLocationChartForCurrentMonth' => $loggedInLocationChartForCurrentMonth, 'loggedInPlatformChartForCurrentMonth' => $loggedInPlatformChartForCurrentMonth, 'loggedInClientDeviceChartForCurrentMonth' => $loggedInClientDeviceChartForCurrentMonth]);
    }

    public function getAllLoginHistories(Request $request): JsonResponse
    {
        $this->authorize('read audit-trail');

        $columns = [
            0 => 'id',
            1 => 'user_id',
            2 => 'location',
            3 => 'client_device',
            4 => 'platform',
            5 => 'ip_address',
            6 => 'login_at',
            7 => 'logout_at',
        ];

        $totalData = UserLoginHistory::query()->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $loginHistories = UserLoginHistory::query()
                ->with(['user'])
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $loginHistories = UserLoginHistory::query()
                ->with(['user'])
                ->where(function ($query) use ($search): void {
                    $query->where('location', 'like', "%{$search}%")
                        ->orWhere('client_device', 'like', "%{$search}%")
                        ->orWhere('platform', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('login_at', 'like', "%{$search}%")
                        ->orWhere('logout_at', 'like', "%{$search}%");
                })
                ->orWhereHas('user', function ($query) use ($search): void {
                    $query->where(function ($builder) use ($search): void {
                        $builder->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = UserLoginHistory::query()
                ->with(['user'])
                ->where(function ($query) use ($search): void {
                    $query->where('location', 'like', "%{$search}%")
                        ->orWhere('client_device', 'like', "%{$search}%")
                        ->orWhere('platform', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('login_at', 'like', "%{$search}%")
                        ->orWhere('logout_at', 'like', "%{$search}%");
                })
                ->orWhereHas('user', function ($query) use ($search): void {
                    $query->where(function ($builder) use ($search): void {
                        $builder->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->count();
        }

        $data = [];

        if ($loginHistories->isNotEmpty()) {
            foreach ($loginHistories as $login) {
                $login->user->photo
                    ? $userPhoto = Storage::url($login->user->photo)
                    : $userPhoto = $login->user->gravatar;

                $user = '<div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                                <a href="javascript:void(0)">
                                    <div class="symbol-label">
                                        <img src="'.$userPhoto.'"
                                            alt="'.$login->user->displayName().'" class="w-100">
                                    </div>
                                </a>
                            </div>

                            <div class="d-flex flex-column">
                                <a href="javascript:void(0)"
                                    class="text-gray-800 text-hover-primary mb-1">'.$login->user->displayName().'</a>
                                <span>'.$login->user->email.'</span>
                            </div>';

                $nestedData['id'] = $login->id;
                $nestedData['location'] = $login->location;
                $nestedData['client_device'] = $login->client_device;
                $nestedData['platform'] = $login->platform;
                $nestedData['ip_address'] = $login->ip_address;
                $nestedData['browser'] = $login->browser;
                $nestedData['login_at'] = $login->login_at->format('Y-m-d H:i:s');
                $nestedData['logout_at'] = $login->logout_at?->format('Y-m-d H:i:s');
                $nestedData['user'] = $user;

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

    public function exportActivityLogs(Request $request)
    {
        $this->authorize('read audit-trail');

        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        $tenantId = $tenant?->id;

        return new \App\Exports\ActivityLogExport($tenantId)->download('activity-logs-'.now()->format('Y-m-d').'.csv');
    }
}
