<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Libraries\Helper;
use App\Models\User;
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
            4 => 'causer_id',
            5 => 'created_at',
            6 => 'created_at',
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
            $search = (string) $request->input('search.value');

            /*
             * activity_log.causer_id is a string and users.id an integer, so a
             * whereHas('causer') join fails on PostgreSQL; match the users first.
             */
            $causerIds = User::query()
                ->where(fn ($query) => $query->where('first_name', 'ilike', "%{$search}%")->orWhere('last_name', 'ilike', "%{$search}%"))
                ->pluck('id')
                ->map(fn (int $id): string => (string) $id)
                ->all();

            $filter = function ($query) use ($search, $causerIds): void {
                $query->where('log_name', 'ilike', "%{$search}%")
                    ->orWhereRaw('CAST(id AS TEXT) LIKE ?', ["%{$search}%"])
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhereRaw('CAST(properties AS TEXT) ILIKE ?', ["%{$search}%"])
                    ->orWhereRaw('CAST(created_at AS TEXT) LIKE ?', ["%{$search}%"])
                    ->orWhere(fn ($causer) => $causer->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $causerIds));
            };

            $activityLogs = Activity::query()
                ->with(['causer'])
                ->where($filter)
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = Activity::query()->where($filter)->count();
        }

        $data = [];

        if ($activityLogs->isNotEmpty()) {
            foreach ($activityLogs as $log) {
                // The table renders HTML, and logged values (names, titles,
                // changed attributes) were typed by users, so all are escaped.
                $nestedData['id'] = $log->id;
                $nestedData['location'] = e((string) $log->log_name);
                $nestedData['event'] = e((string) $log->description);
                $nestedData['subject_type'] = e((string) $log->subject_type);
                $nestedData['causer'] = e((string) $log->causer?->displayName());
                $nestedData['properties'] = '<textarea rows="5" disabled>'.e((string) $log->properties).'</textarea>';
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
                                        <img src="'.e($userPhoto).'"
                                            alt="'.e($login->user->displayName()).'" class="w-100">
                                    </div>
                                </a>
                            </div>

                            <div class="d-flex flex-column">
                                <a href="javascript:void(0)"
                                    class="text-gray-800 text-hover-primary mb-1">'.e($login->user->displayName()).'</a>
                                <span>'.e($login->user->email).'</span>
                            </div>';

                // Device, platform and browser come from the visitor's
                // User-Agent header, which anyone can set; escape everything.
                $nestedData['id'] = $login->id;
                $nestedData['location'] = e((string) $login->location);
                $nestedData['client_device'] = e((string) $login->client_device);
                $nestedData['platform'] = e((string) $login->platform);
                $nestedData['ip_address'] = e((string) $login->ip_address);
                $nestedData['browser'] = e((string) $login->browser);
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
