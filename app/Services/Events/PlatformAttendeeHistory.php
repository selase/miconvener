<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enum\TenantStatusEnum;
use App\Models\Event;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventRegistration;
use App\Models\EventSessionAttendance;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

final class PlatformAttendeeHistory
{
    /**
     * Retrieve the attendee's complete multi-tenant event history grouped by lifecycle urgency.
     *
     * @return array{
     *     needs_attention: array<int, array<string, mixed>>,
     *     live_now: array<int, array<string, mixed>>,
     *     organisers: array<int, array<string, mixed>>
     * }
     */
    public function getHistory(string $emailNormalized, ?string $organiserSlug = null): array
    {
        $email = PlatformAttendeeVerification::normalise($emailNormalized);

        $registrations = EventRegistration::withoutGlobalScopes()
            ->forEmail($email)
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED])
            ->whereHas('tenant', fn (Builder $q) => $q->withoutGlobalScopes()->where('status', '!=', TenantStatusEnum::BANNED))
            ->when($organiserSlug !== null, fn (Builder $q) => $q->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('slug', $organiserSlug)))
            ->with([
                'event' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'materials' => fn ($m) => $m->withoutGlobalScopes(),
                ]),
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
                'ticketType' => fn ($q) => $q->withoutGlobalScopes(),
                'seatAssignment' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get();

        $needsAttention = [];
        $liveNow = [];
        $organisersMap = [];

        // A transfer addressed to this person is deliberately absent from
        // "needs your attention". The confirmation code goes to the current
        // holder, who completes the handover; the recipient has nothing to do
        // until the ticket is theirs, at which point it appears in the ordinary
        // organiser grouping below. Listing it as an action offered a card that
        // refused the only person it invited.

        foreach ($registrations as $reg) {
            $event = $reg->event;
            $tenant = $reg->tenant;

            if ($event === null || $tenant === null) {
                continue;
            }

            // Check if action required by the attendee
            if ($reg->status === EventRegistration::STATUS_PENDING_PAYMENT) {
                $needsAttention[] = [
                    'registration_id' => $reg->id,
                    'reason' => 'Payment required to secure your ticket',
                    'action' => [
                        'label' => 'Complete payment',
                        'url' => url("/my/events/{$reg->id}"),
                    ],
                    'organiser' => [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                    ],
                    'event' => [
                        'name' => $event->name,
                        'slug' => $event->slug,
                        'starts_at' => $event->starts_at->toIso8601String(),
                        'ends_at' => $event->ends_at->toIso8601String(),
                    ],
                ];
            }

            $isLive = ($event->starts_at->isPast() && $event->ends_at->isFuture())
                || $reg->checked_in_at !== null
                || $reg->status === EventRegistration::STATUS_CHECKED_IN;

            if ($isLive) {
                $liveNow[] = [
                    'registration_id' => $reg->id,
                    'registration_status' => $reg->status,
                    'event_workspace_url' => url("/my/events/{$reg->id}"),
                    'event' => [
                        'name' => $event->name,
                        'slug' => $event->slug,
                        'starts_at' => $event->starts_at->toIso8601String(),
                        'ends_at' => $event->ends_at->toIso8601String(),
                        'location' => $event->address,
                        'format' => $event->location_type,
                    ],
                    'organiser' => [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                    ],
                    'ticket' => [
                        'code' => $reg->ticket_code ?? $reg->id,
                        'type' => $reg->ticketType?->name,
                        'status' => $reg->status,
                        'seat' => $reg->seatAssignment?->seat_label,
                    ],
                    'available_actions' => $this->availableActionsFor($reg),
                ];
            }

            if (! isset($organisersMap[$tenant->id])) {
                $organisersMap[$tenant->id] = [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'upcoming' => [],
                    'past' => [],
                ];
            }

            $entry = [
                'registration_id' => $reg->id,
                'registration_status' => $reg->status,
                'event' => [
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'ends_at' => $event->ends_at->toIso8601String(),
                    'location' => $event->address,
                    'format' => $event->location_type,
                ],
                'event_workspace_url' => url("/my/events/{$reg->id}"),
                'ticket' => [
                    'code' => $reg->ticket_code ?? $reg->id,
                    'type' => $reg->ticketType?->name,
                    'status' => $reg->status,
                    'seat' => $reg->seatAssignment?->seat_label,
                ],
                'available_actions' => $this->availableActionsFor($reg),
                'released_materials' => $this->releasedMaterialsFor($reg),
            ];

            $hasEnded = $event->ends_at->isPast();

            if ($hasEnded) {
                $organisersMap[$tenant->id]['past'][] = $entry;
            } else {
                $organisersMap[$tenant->id]['upcoming'][] = $entry;
            }
        }

        // Sort upcoming ascending (starts_at ASC, id ASC) and past descending (starts_at DESC, id DESC)
        $organisers = [];
        foreach ($organisersMap as $org) {
            $upcoming = $org['upcoming'];
            usort($upcoming, function (array $a, array $b): int {
                return $a['event']['starts_at'] <=> $b['event']['starts_at']
                    ?: strcmp($a['registration_id'], $b['registration_id']);
            });
            $org['upcoming'] = $upcoming;

            $past = $org['past'];
            usort($past, function (array $a, array $b): int {
                return $b['event']['starts_at'] <=> $a['event']['starts_at']
                    ?: strcmp($b['registration_id'], $a['registration_id']);
            });
            $org['past'] = $past;

            $organisers[] = $org;
        }

        return [
            'needs_attention' => $needsAttention,
            'live_now' => $liveNow,
            'organisers' => $organisers,
        ];
    }

    /**
     * Retrieve certificates earned across organisers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCertificates(string $emailNormalized, ?string $organiserSlug = null): array
    {
        $email = PlatformAttendeeVerification::normalise($emailNormalized);

        /** @var \Illuminate\Database\Eloquent\Collection<int, EventCertificate> $certificates */
        $certificates = EventCertificate::withoutGlobalScopes()
            // A certificate names its own recipient, so that address always
            // reaches it. The ticket reaches it too -- an organiser who
            // corrects a misspelt address should not cut the holder off from a
            // certificate already issued -- but only while the ticket has not
            // changed hands since. Otherwise a transfer would hand the previous
            // holder's certificate, bearing their name, to the new one.
            ->where(function (Builder $query) use ($email): void {
                $query->whereRaw('lower(recipient_email) = ?', [$email])
                    ->orWhere(function (Builder $viaTicket) use ($email): void {
                        $viaTicket
                            ->whereHas('registration', fn (Builder $r) => $r->withoutGlobalScopes()->whereRaw('lower(email) = ?', [$email]))
                            ->whereDoesntHave('registration.transfers', fn (Builder $t) => $t->withoutGlobalScopes()
                                ->whereNotNull('consumed_at')
                                ->whereColumn('event_registration_transfers.consumed_at', '>', 'event_certificates.issued_at'));
                    });
            })
            ->whereHas('tenant', fn (Builder $q) => $q->withoutGlobalScopes()->where('status', '!=', TenantStatusEnum::BANNED))
            ->when($organiserSlug !== null, fn (Builder $q) => $q->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('slug', $organiserSlug)))
            ->with([
                'event' => fn ($q) => $q->withoutGlobalScopes(),
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->latest('issued_at')
            ->get();

        $results = [];
        foreach ($certificates->unique('uuid') as $cert) {
            $event = $cert->event;
            $tenant = $cert->tenant;

            $results[] = [
                'id' => $cert->id,
                'uuid' => $cert->uuid,
                'event_name' => $event?->name,
                'organiser_name' => $tenant?->name,
                'recipient_name' => $cert->recipient_name,
                'role' => $cert->role,
                'cpd_hours' => $cert->cpd_hours,
                'issued_at' => $cert->issued_at?->toIso8601String(),
                'verification_url' => $cert->verificationUrl(),
                'download_url' => url("/verify/cert/{$cert->uuid}/download"),
            ];
        }

        return $results;
    }

    /**
     * Retrieve abstract submissions and co-authorships across organisers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAbstracts(string $emailNormalized, ?string $organiserSlug = null): array
    {
        $email = PlatformAttendeeVerification::normalise($emailNormalized);

        /** @var \Illuminate\Database\Eloquent\Collection<int, EventAbstractAuthor> $authors */
        $authors = EventAbstractAuthor::withoutGlobalScopes()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereHas('abstract', fn (Builder $q) => $q->withoutGlobalScopes()->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('status', '!=', TenantStatusEnum::BANNED)))
            ->when($organiserSlug !== null, fn (Builder $q) => $q->whereHas('abstract.tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('slug', $organiserSlug)))
            ->with([
                'abstract' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'event' => fn ($e) => $e->withoutGlobalScopes(),
                    'tenant' => fn ($t) => $t->withoutGlobalScopes(),
                ]),
            ])
            ->get();

        $abstracts = [];
        foreach ($authors as $author) {
            $abstract = $author->abstract;
            if ($abstract !== null && ! isset($abstracts[$abstract->id])) {
                $abstracts[$abstract->id] = [
                    'id' => $abstract->id,
                    'code' => $abstract->code,
                    'title' => $abstract->title,
                    'event_name' => $abstract->event?->name,
                    'organiser_name' => $abstract->tenant?->name,
                    'status' => $abstract->status,
                    'presentation_preference' => $abstract->presentation_preference,
                    'created_at' => $abstract->created_at?->toIso8601String(),
                ];
            }
        }

        return array_values($abstracts);
    }

    /**
     * Retrieve session attendance records across organisers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttendance(string $emailNormalized, ?string $organiserSlug = null): array
    {
        $email = PlatformAttendeeVerification::normalise($emailNormalized);

        /** @var \Illuminate\Database\Eloquent\Collection<int, EventSessionAttendance> $attendances */
        $attendances = EventSessionAttendance::withoutGlobalScopes()
            ->whereHas('registration', fn (Builder $r) => $r->withoutGlobalScopes()->whereRaw('lower(email) = ?', [$email]))
            // Being scanned into a room is something a particular person did.
            // When a ticket changes hands mid-event, the check-ins that predate
            // the handover stay with whoever was in the room, not with whoever
            // holds the ticket afterwards.
            ->whereDoesntHave('registration.transfers', fn (Builder $t) => $t->withoutGlobalScopes()
                ->whereNotNull('consumed_at')
                ->whereColumn('event_registration_transfers.consumed_at', '>', 'event_session_attendances.checked_in_at'))
            ->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('status', '!=', TenantStatusEnum::BANNED))
            ->when($organiserSlug !== null, fn (Builder $q) => $q->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('slug', $organiserSlug)))
            ->with([
                'session' => fn ($s) => $s->withoutGlobalScopes(),
                'event' => fn ($e) => $e->withoutGlobalScopes(),
                'tenant' => fn ($t) => $t->withoutGlobalScopes(),
            ])
            ->latest('checked_in_at')
            ->get();

        $result = [];
        foreach ($attendances as $att) {
            $result[] = [
                'id' => $att->id,
                'session_title' => $att->session?->title,
                'event_name' => $att->event?->name,
                'organiser_name' => $att->tenant?->name,
                'checked_in_at' => $att->checked_in_at->toIso8601String(),
                'checked_out_at' => $att->checked_out_at?->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function availableActionsFor(EventRegistration $registration): array
    {
        $actions = [];

        if ($registration->status === EventRegistration::STATUS_PENDING_PAYMENT) {
            $actions[] = 'pay';
        }

        if ($registration->isConfirmed()) {
            $actions[] = 'view_ticket';
            $actions[] = 'transfer';
            $actions[] = 'download_badge';
        }

        return $actions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function releasedMaterialsFor(EventRegistration $registration): array
    {
        if (! $registration->isConfirmed() || $registration->event === null) {
            return [];
        }

        $materials = [];
        foreach ($registration->event->materials as $m) {
            if ($m->isReleased()) {
                $materials[] = [
                    'id' => $m->id,
                    'title' => $m->title,
                    'file_size' => $m->file_size,
                    'mime_type' => $m->mime_type,
                ];
            }
        }

        return $materials;
    }
}
