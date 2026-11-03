<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAttendeeProfile;
use App\Models\EventAttendeeProfileImage;
use App\Services\Events\Profiles\ProfileEligibility;
use App\Services\Events\Profiles\ProfileRollout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class PublicAttendeeDirectoryController extends Controller
{
    public function index(Request $request, string $event, ProfileEligibility $eligibility, ProfileRollout $rollout): JsonResponse
    {
        $current = Event::withoutGlobalScopes()->findOrFail($event);
        abort_unless($current->visibility === Event::VISIBILITY_PUBLIC && $current->status === Event::STATUS_PUBLISHED && $rollout->allows($current), 404);
        $after = $request->query('after');
        abort_if($after !== null && (! is_string($after) || ! Str::isUuid($after)), 422);
        $limit = $request->boolean('expanded') ? 25 : 6;
        $profiles = [];
        $cursor = null;
        $more = false;
        $batch = EventAttendeeProfile::withoutGlobalScopes()->select('event_attendee_profiles.*')
            ->join('event_registrations as ticket', 'ticket.id', '=', 'event_attendee_profiles.registration_id')
            ->join('events as occasion', 'occasion.id', '=', 'event_attendee_profiles.event_id')
            ->join('tenants as organisation', 'organisation.id', '=', 'event_attendee_profiles.tenant_id')
            ->join('tenant_features as rollout', fn ($join) => $join->on('rollout.tenant_id', '=', 'event_attendee_profiles.tenant_id')->where('rollout.feature_key', 'public_attendee_profiles'))
            ->where('event_attendee_profiles.tenant_id', $current->tenant_id)->where('event_attendee_profiles.event_id', $current->id)
            ->where('event_attendee_profiles.state', 'published')->whereNull('event_attendee_profiles.moderated_at')->where('event_attendee_profiles.expires_at', '>', now())
            ->whereIn('ticket.status', ['confirmed', 'checked_in'])->whereNotNull('ticket.ticket_code')->whereNotNull('ticket.qr_token')
            ->whereColumn('ticket.attendee_profile_generation', 'event_attendee_profiles.registration_generation')
            ->whereColumn('occasion.attendee_profile_generation', 'event_attendee_profiles.event_generation')
            ->whereColumn('organisation.attendee_profile_generation', 'event_attendee_profiles.tenant_generation')
            ->where('rollout.enabled', true)->whereRaw("event_attendee_profiles.feature_generation::text = rollout.meta->>'profile_generation'")
            ->when($after !== null, fn ($query) => $query->where('event_attendee_profiles.id', '>', $after))->orderBy('event_attendee_profiles.id')->limit(100)->get();
        foreach ($batch as $profile) {
            $cursor = $profile->id;
            if (! $eligibility->visible($profile)) {
                continue;
            }
            if (count($profiles) === $limit) {
                $more = true;
                break;
            }
            $profiles[] = ['id' => $profile->id, 'revision' => $profile->revision, 'display_name' => $profile->display_name,
                'initials' => Str::of($profile->display_name ?? '')->squish()->explode(' ')->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode(''),
                'photo_url' => $profile->image_id === null ? null : route('attendee.profiles.photo', ['profile' => $profile->id, 'revision' => $profile->revision], false)];
            $last = $profile->id;
        }

        return response()->json(['profiles' => $profiles, 'next' => $more ? ($last ?? $cursor) : ($batch->count() === 100 ? $cursor : null)])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function photo(string $profile, string $revision, ProfileEligibility $eligibility): Response
    {
        $current = EventAttendeeProfile::withoutGlobalScopes()->findOrFail($profile);
        abort_unless($current->revision === $revision && $eligibility->visible($current), 404);
        $image = EventAttendeeProfileImage::withoutGlobalScopes()->whereKey($current->image_id)->where('profile_id', $current->id)
            ->where('tenant_id', $current->tenant_id)->where('event_id', $current->event_id)->where('registration_id', $current->registration_id)
            ->whereNull('retired_at')->whereNull('deleted_at')->whereNotNull('stored_at')->where('expires_at', '>', now())->firstOrFail();
        abort_unless(in_array($image->disk, ['s3', 'attendee-profiles-local'], true) && str_starts_with($image->path, 'attendee-profiles/'.$image->tenant_id.'/'), 404);
        $disk = Storage::disk($image->disk);
        abort_unless($disk->exists($image->path), 404);
        $bytes = $disk->get($image->path);
        abort_unless(EventAttendeeProfile::withoutGlobalScopes()->whereKey($current->id)->where('revision', $revision)->where('image_id', $image->id)->exists() && $eligibility->visible($current), 404);

        return response($bytes)->header('Content-Type', 'image/webp')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
