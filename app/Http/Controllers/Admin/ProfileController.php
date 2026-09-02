<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserLoginHistory;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Spatie\Activitylog\Models\Activity;

final class ProfileController extends Controller
{
    public function index(User $user): Factory|View
    {
        $breadcrumbs = [
            ['link' => route('dashboard'), 'name' => __('Home')],
            ['name' => __('locale.labels.account')],
            ['name' => __('locale.labels.user')],
        ];

        $loginSessions = UserLoginHistory::query()->where('user_id', $user->id)->latest()->get();
        $activityLogs = Activity::query()->where('causer_id', $user->id)->latest()->get();

        return view('admin.profile.index', ['user' => $user, 'breadcrumbs' => $breadcrumbs, 'loginSessions' => $loginSessions, 'activityLogs' => $activityLogs]);
    }
}
