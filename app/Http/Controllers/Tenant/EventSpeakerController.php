<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\Events\SpeakerPortalInvitationMail;
use App\Models\Event;
use App\Models\EventSpeaker;
use App\Models\Speaker;
use App\Services\Tenancy\FeatureMeteringService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EventSpeakerController extends Controller
{
    public function store(Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $validated = $request->validate([
            'speaker_id' => ['required', Rule::exists('speakers', 'id')->where('tenant_id', $tenant->id)],
            'role' => ['nullable', 'string', 'max:50'],
        ]);

        if ($eventModel->speakers()->where('speakers.id', $validated['speaker_id'])->exists()) {
            return response()->json(['message' => 'Speaker is already added to this event.']);
        }

        $eventModel->speakers()->syncWithoutDetaching([
            $validated['speaker_id'] => [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'role' => $validated['role'] ?? 'speaker',
                'portal_token' => Str::random(40),
            ],
        ]);

        return response()->json(['message' => 'Speaker added to event.']);
    }

    public function destroy(string $subdomain, string $event, string $speaker): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $pivot = EventSpeaker::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('speaker_id', $speaker)
            ->first();

        if ($pivot?->slidesMaterial && $pivot->slidesMaterial->provenance === \App\Models\EventMaterial::PROVENANCE_SPEAKER) {
            \App\Libraries\Helper::deleteFile($pivot->slidesMaterial->file_path, config('app.env') === 'production' ? 's3' : 'public');
            $pivot->slidesMaterial->delete();
        }

        $eventModel->speakers()->detach($speaker);

        return response()->json(['message' => 'Speaker removed from event.']);
    }

    public function portalLink(string $subdomain, string $event, string $speaker): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();

        $pivot = EventSpeaker::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('speaker_id', $speaker)
            ->firstOrFail();

        if (! $pivot->portal_token) {
            $pivot->update(['portal_token' => Str::random(40)]);
        }

        return response()->json([
            'portal_url' => route('public.events.speaker-portal', [
                'subdomain' => $tenant->slug,
                'event' => $eventModel->slug,
                'token' => $pivot->portal_token,
            ]),
        ]);
    }

    public function invite(string $subdomain, string $event, string $speaker): JsonResponse
    {
        $this->authorize('update event');
        $tenant = $this->getTenant();
        $eventModel = Event::where('tenant_id', $tenant->id)->where('id', $event)->firstOrFail();
        $speakerModel = Speaker::where('tenant_id', $tenant->id)->where('id', $speaker)->firstOrFail();

        if ($speakerModel->needsEmail()) {
            return response()->json(['message' => 'Speaker does not have an email address.'], 422);
        }

        $metering = app(FeatureMeteringService::class);
        $emailLimit = $tenant->featureLimitValue('email_credits');
        if ($emailLimit !== null && ! $metering->canUse($tenant, 'email_credits')) {
            return response()->json(['message' => 'Monthly email credit limit reached.'], 422);
        }

        $pivot = EventSpeaker::where('tenant_id', $tenant->id)
            ->where('event_id', $eventModel->id)
            ->where('speaker_id', $speakerModel->id)
            ->firstOrFail();

        if (! $pivot->portal_token) {
            $pivot->portal_token = Str::random(40);
        }

        Mail::to($speakerModel->email)->send(
            new SpeakerPortalInvitationMail($eventModel, $speakerModel, $pivot)
        );

        $pivot->last_invited_at = now();
        $pivot->save();

        $metering->recordUsage($tenant, 'email_credits');

        return response()->json([
            'message' => "Invitation sent to {$speakerModel->email}.",
            'last_invited_at' => $pivot->last_invited_at->toIso8601String(),
        ]);
    }

    private function getTenant()
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant) {
            abort(403, 'Tenant context not resolved.');
        }

        return $tenant;
    }
}
