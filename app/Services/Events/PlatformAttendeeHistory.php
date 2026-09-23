<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enum\TenantStatusEnum;
use App\Models\EventAbstract;
use App\Models\EventAbstractAuthor;
use App\Models\EventCertificate;
use App\Models\EventMaterial;
use App\Models\EventRegistration;
use App\Models\EventRegistrationTransfer;
use App\Models\EventSessionAttendance;
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

        // Check for pending transfers where attendee is the recipient
        $pendingTransfers = EventRegistrationTransfer::withoutGlobalScopes()
            ->whereRaw('lower(to_email) = ?', [$email])
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', EventRegistrationTransfer::MAX_ATTEMPTS)
            ->whereHas('tenant', fn (Builder $q) => $q->withoutGlobalScopes()->where('status', '!=', TenantStatusEnum::BANNED))
            ->when($organiserSlug !== null, fn (Builder $q) => $q->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('slug', $organiserSlug)))
            ->with([
                'registration' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'event' => fn ($e) => $e->withoutGlobalScopes(),
                    'tenant' => fn ($t) => $t->withoutGlobalScopes(),
                ]),
            ])
            ->get();

        foreach ($pendingTransfers as $transfer) {
            $reg = $transfer->registration;
            if ($reg !== null && $reg->event !== null && $reg->tenant !== null) {
                $needsAttention[] = [
                    'registration_id' => $reg->id,
                    'reason' => 'Ticket transfer awaiting your confirmation',
                    'action' => [
                        'label' => 'Review & accept transfer',
                        'url' => url("/my/events/{$reg->id}"),
                    ],
                    'organiser' => [
                        'id' => $reg->tenant->id,
                        'name' => $reg->tenant->name,
                        'slug' => $reg->tenant->slug,
                    ],
                    'event' => [
                        'name' => $reg->event->name,
                        'slug' => $reg->event->slug,
                        'starts_at' => $reg->event->starts_at?->toIso8601String(),
                        'ends_at' => $reg->event->ends_at?->toIso8601String(),
                    ],
                ];
            }
        }

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
                        'starts_at' => $event->starts_at?->toIso8601String(),
                        'ends_at' => $event->ends_at?->toIso8601String(),
                    ],
                ];
            }

            $isLive = ($event->starts_at && $event->starts_at->isPast() && ($event->ends_at === null || $event->ends_at->isFuture()))
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
                        'starts_at' => $event->starts_at?->toIso8601String(),
                        'ends_at' => $event->ends_at?->toIso8601String(),
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
                        'seat' => $reg->seatAssignment?->seat_number,
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
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'location' => $event->address,
                    'format' => $event->location_type,
                ],
                'event_workspace_url' => url("/my/events/{$reg->id}"),
                'ticket' => [
                    'code' => $reg->ticket_code ?? $reg->id,
                    'type' => $reg->ticketType?->name,
                    'status' => $reg->status,
                    'seat' => $reg->seatAssignment?->seat_number,
                ],
                'available_actions' => $this->availableActionsFor($reg),
                'released_materials' => $this->releasedMaterialsFor($reg),
            ];

            $hasEnded = $event->ends_at !== null && $event->ends_at->isPast();

            if ($hasEnded) {
                $organisersMap[$tenant->id]['past'][] = $entry;
            } else {
                $organisersMap[$tenant->id]['upcoming'][] = $entry;
            }
        }

        // Sort upcoming ascending (starts_at ASC, id ASC) and past descending (starts_at DESC, id DESC)
        $organisers = array_values(array_map(function (array $org): array {
            usort($org['upcoming'], function (array $a, array $b): int {
                $timeA = $a['event']['starts_at'] ?? '';
                $timeB = $b['event']['starts_at'] ?? '';

                return $timeA <=> $timeB ?: strcmp($a['registration_id'], $b['registration_id']);
            });

            usort($org['past'], function (array $a, array $b): int {
                $timeA = $a['event']['starts_at'] ?? '';
                $timeB = $b['event']['starts_at'] ?? '';

                return $timeB <=> $timeA ?: strcmp($b['registration_id'], $a['registration_id']);
            });

            return $org;
        }, $organisersMap));

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

        return EventCertificate::withoutGlobalScopes()
            ->where(function (Builder $query) use ($email): void {
                $query->whereRaw('lower(recipient_email) = ?', [$email])
                    ->orWhereHas('registration', fn (Builder $r) => $r->withoutGlobalScopes()->forEmail($email));
            })
            ->whereHas('tenant', fn (Builder $q) => $q->withoutGlobalScopes()->where('status', '!=', TenantStatusEnum::BANNED))
            ->when($organiserSlug !== null, fn (Builder $q) => $q->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('slug', $organiserSlug)))
            ->with([
                'event' => fn ($q) => $q->withoutGlobalScopes(),
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->latest('issued_at')
            ->get()
            ->unique('uuid')
            ->map(fn (EventCertificate $cert): array => [
                'id' => $cert->id,
                'uuid' => $cert->uuid,
                'event_name' => $cert->event?->name,
                'organiser_name' => $cert->tenant?->name,
                'recipient_name' => $cert->recipient_name,
                'role' => $cert->role,
                'cpd_hours' => $cert->cpd_hours,
                'issued_at' => $cert->issued_at?->toIso8601String(),
                'verification_url' => $cert->verificationUrl(),
                'download_url' => url("/verify/cert/{$cert->uuid}/download"),
            ])
            ->values()
            ->all();
    }

    /**
     * Retrieve abstract submissions and co-authorships across organisers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAbstracts(string $emailNormalized, ?string $organiserSlug = null): array
    {
        $email = PlatformAttendeeVerification::normalise($emailNormalized);

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

        return $authors->map(fn (EventAbstractAuthor $author) => $author->abstract)
            ->filter()
            ->unique('id')
            ->map(fn (EventAbstract $abstract): array => [
                'id' => $abstract->id,
                'code' => $abstract->code,
                'title' => $abstract->title,
                'event_name' => $abstract->event?->name,
                'organiser_name' => $abstract->tenant?->name,
                'status' => $abstract->status,
                'presentation_preference' => $abstract->presentation_preference,
                'created_at' => $abstract->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Retrieve session attendance records across organisers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttendance(string $emailNormalized, ?string $organiserSlug = null): array
    {
        $email = PlatformAttendeeVerification::normalise($emailNormalized);

        return EventSessionAttendance::withoutGlobalScopes()
            ->whereHas('registration', fn (Builder $r) => $r->withoutGlobalScopes()->forEmail($email))
            ->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('status', '!=', TenantStatusEnum::BANNED))
            ->when($organiserSlug !== null, fn (Builder $q) => $q->whereHas('tenant', fn (Builder $t) => $t->withoutGlobalScopes()->where('slug', $organiserSlug)))
            ->with([
                'session' => fn ($s) => $s->withoutGlobalScopes(),
                'event' => fn ($e) => $e->withoutGlobalScopes(),
                'tenant' => fn ($t) => $t->withoutGlobalScopes(),
            ])
            ->latest('checked_in_at')
            ->get()
            ->map(fn (EventSessionAttendance $att): array => [
                'id' => $att->id,
                'session_title' => $att->session?->title,
                'event_name' => $att->event?->name,
                'organiser_name' => $att->tenant?->name,
                'checked_in_at' => $att->checked_in_at?->toIso8601String(),
                'checked_out_at' => $att->checked_out_at?->toIso8601String(),
            ])
            ->values()
            ->all();
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

        return $registration->event->materials
            ->filter(fn (EventMaterial $m) => $m->isReleased())
            ->map(fn (EventMaterial $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'file_size' => $m->file_size,
                'mime_type' => $m->mime_type,
            ])
            ->values()
            ->all();
    }
}
