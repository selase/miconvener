<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\ModerateAttendeeProfileRequest;
use App\Models\EventAttendeeProfile;
use App\Models\Tenant;
use App\Services\Events\Profiles\ProfileModeration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class AttendeeProfileModerationController extends Controller
{
    public function index(Tenant $tenant): View
    {
        Gate::authorize('moderate-public-attendee-profiles');

        return view('admin.tenants.attendee-profiles', ['tenant' => $tenant, 'profiles' => EventAttendeeProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('moderated_at')->orderByDesc('moderated_at')->paginate(25)]);
    }

    public function release(ModerateAttendeeProfileRequest $request, Tenant $tenant, string $profile, ProfileModeration $moderation): RedirectResponse
    {
        Gate::authorize('moderate-public-attendee-profiles');
        $candidate = EventAttendeeProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($profile);
        $moderation->change($candidate, $request->user(), $request->validated('reason'), true);

        return back()->with('success', 'Moderation hold released. The attendee must consent again; nothing has been published.');
    }
}
