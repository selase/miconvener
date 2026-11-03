<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\ModerateAttendeeProfileRequest;
use App\Http\Requests\Events\ToggleAttendeeProfilesRequest;
use App\Models\Event;
use App\Models\EventAttendeeProfile;
use App\Services\Events\Profiles\ProfileModeration;
use App\Services\Events\Profiles\ProfileRollout;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class EventAttendeeProfileController extends Controller
{
    public function index(string $subdomain, string $event, ProfileRollout $rollout): JsonResponse
    {
        $current = $this->event($event);
        abort_unless($rollout->generation($current->tenant_id) !== null, 404);
        $profiles = EventAttendeeProfile::withoutGlobalScopes()->where('event_id', $current->id)->where('tenant_id', $current->tenant_id)->whereIn('state', ['published', 'hidden'])->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['enabled' => $current->public_attendee_profiles, 'profiles' => $profiles->map(fn ($profile) => ['id' => $profile->id, 'display_name' => $profile->display_name, 'state' => $profile->state])])->header('Cache-Control', 'private, no-store');
    }

    public function toggle(ToggleAttendeeProfilesRequest $request, string $subdomain, string $event, ProfileRollout $rollout): JsonResponse
    {
        $current = $this->event($event);
        abort_unless($rollout->generation($current->tenant_id) !== null, 403);
        $current->forceFill(['public_attendee_profiles' => $request->boolean('enabled')])->save();

        return response()->json(['enabled' => $current->public_attendee_profiles])->header('Cache-Control', 'private, no-store');
    }

    public function hide(ModerateAttendeeProfileRequest $request, string $subdomain, string $event, string $profile, ProfileModeration $moderation): JsonResponse
    {
        $current = $this->event($event);
        $candidate = EventAttendeeProfile::withoutGlobalScopes()->where('tenant_id', $current->tenant_id)->where('event_id', $current->id)->findOrFail($profile);
        $moderation->change($candidate, $request->user(), $request->validated('reason'));

        return response()->json(['hidden' => true])->header('Cache-Control', 'private, no-store');
    }

    private function event(string $id): Event
    {
        Gate::authorize('manage public attendee profiles');
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant !== null, 404);

        return Event::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($id);
    }
}
