<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\EventSpeaker;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class MaterialReleasePolicy
{
    /**
     * Determines whether a material is released for attendee access.
     *
     * Invariants:
     * 1. Explicit release_at always wins.
     * 2. Undated organiser material is immediately released.
     * 3. Speaker policy supports before, during, and after:
     *    - 'before': released immediately upon upload.
     *    - 'during': released at or after the first linked session start.
     *    - 'after': released at or after the last linked session end.
     *    - A sessionless during/after deck stays withheld.
     */
    public function isReleased(EventMaterial $material, ?CarbonInterface $now = null): bool
    {
        $now = $now ?? now();

        if ($material->release_at !== null) {
            return $material->release_at->lessThanOrEqualTo($now);
        }

        if ($material->provenance !== EventMaterial::PROVENANCE_SPEAKER) {
            return true;
        }

        $event = $material->relationLoaded('event')
            ? $material->event
            : Event::withoutGlobalScopes()->find($material->event_id);

        $policy = $event instanceof Event
            ? $event->speaker_slide_policy
            : Event::SPEAKER_POLICY_AFTER;

        if ($policy === Event::SPEAKER_POLICY_BEFORE) {
            return true;
        }

        $sessions = $this->linkedSessionsForMaterial($material);
        if ($sessions->isEmpty()) {
            return false;
        }

        if ($policy === Event::SPEAKER_POLICY_DURING) {
            $firstStart = $sessions->min('starts_at');

            return $firstStart !== null && $now->greaterThanOrEqualTo($firstStart);
        }

        if ($policy === Event::SPEAKER_POLICY_AFTER) {
            $lastEnd = $sessions->max('ends_at');

            return $lastEnd !== null && $now->greaterThanOrEqualTo($lastEnd);
        }

        return false;
    }

    /**
     * Retrieves all sessions linked to this material.
     *
     * @return Collection<int, EventSession>
     */
    public function linkedSessionsForMaterial(EventMaterial $material): Collection
    {
        /** @var Collection<int, EventSession> $sessions */
        $sessions = collect();

        $eventSpeaker = $material->relationLoaded('eventSpeaker')
            ? $material->eventSpeaker
            : EventSpeaker::withoutGlobalScopes()->where('slides_material_id', $material->id)->first();

        if ($eventSpeaker instanceof EventSpeaker) {
            $speakerSessions = EventSession::withoutGlobalScopes()
                ->where('event_id', $material->event_id)
                ->whereHas('speakers', fn ($q) => $q->where('speakers.id', $eventSpeaker->speaker_id))
                ->get();

            $sessions = $sessions->concat($speakerSessions);
        }

        if ($material->session_id !== null) {
            $directSession = $material->relationLoaded('session')
                ? $material->session
                : EventSession::withoutGlobalScopes()->find($material->session_id);

            if ($directSession instanceof EventSession) {
                $sessions->push($directSession);
            }
        }

        return $sessions->unique('id')->values();
    }

    /**
     * Returns released organiser session files and speaker decks for a given session,
     * without duplicating a deck within one session.
     *
     * @return Collection<int, mixed>
     */
    public function releasedMaterialsForSession(
        EventSession $session,
        ?EventRegistration $registration = null,
        ?CarbonInterface $now = null
    ): Collection {
        $sessionMaterials = EventMaterial::withoutGlobalScopes()
            ->where('event_id', $session->event_id)
            ->where('session_id', $session->id)
            ->where('provenance', EventMaterial::PROVENANCE_ORGANIZER)
            ->get();

        $sessionSpeakerIds = $session->relationLoaded('speakers')
            ? $session->speakers->pluck('id')->all()
            : $session->speakers()->pluck('speakers.id')->all();

        /** @var Collection<int, EventMaterial> $speakerDecks */
        $speakerDecks = collect();
        if (! empty($sessionSpeakerIds)) {
            $eventSpeakers = EventSpeaker::withoutGlobalScopes()
                ->where('event_id', $session->event_id)
                ->whereIn('speaker_id', $sessionSpeakerIds)
                ->whereNotNull('slides_material_id')
                ->with(['slidesMaterial' => fn ($q) => $q->withoutGlobalScopes()])
                ->get();

            foreach ($eventSpeakers as $es) {
                if ($es->slidesMaterial instanceof EventMaterial) {
                    $speakerDecks->push($es->slidesMaterial);
                }
            }
        }

        $allMaterials = $sessionMaterials->concat($speakerDecks)->unique('id');

        $released = $allMaterials->filter(fn (EventMaterial $m): bool => $this->isReleased($m, $now))->values();

        if ($registration === null) {
            return $released;
        }

        return $released->map(fn (EventMaterial $m): array => [
            'id' => $m->id,
            'title' => $m->title,
            'provenance' => $m->provenance,
            'file_size' => $m->file_size,
            'mime_type' => $m->mime_type,
            'remaining_attempts' => $m->remainingAttemptsFor($registration->id),
            'download_url' => route('attendee.my.events.materials.download', [
                'registration' => $registration->id,
                'material' => $m->id,
            ]),
        ]);
    }
}
